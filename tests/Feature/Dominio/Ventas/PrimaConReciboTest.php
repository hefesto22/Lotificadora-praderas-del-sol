<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Venta;

/*
|--------------------------------------------------------------------------
| La prima sale con recibo, y la seña se descuenta — R5 + R14 + R11
|--------------------------------------------------------------------------
| La otra mitad del agujero que cerramos con la seña: hasta ahora firmar un
| contrato movia L 100,000.00 de prima y no dejaba ni un papel. El cliente
| entregaba el dinero mas grande de toda la relacion y se iba con el
| contrato pero sin comprobante de haberlo pagado.
|
| Y lo que se cobra HOY no es la prima entera: R14 dice que la seña del
| apartado cuenta como parte de ella. Si el cliente ya dejo L 5,000.00 para
| reservar, hoy pone L 95,000.00 — y el papel tiene que decir esa cifra, no
| la del contrato, o la misma plata quedaria contada dos veces entre los dos
| recibos.
*/

beforeEach(function (): void {
    $this->registro = app(RegistroDeVentas::class);
    $this->compromisos = app(RegistroDeCompromisos::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    // 250 vr² x L 1,400.00 = L 350,000.00 cada uno.
    $this->lote = fn (string $numero): Lote => Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => $numero]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->firmar = function (array $lotes, string $prima, ?FormaDePago $forma = null, ?string $referencia = null): Venta {
        // Reindexado y declarado: `activar()` pide una `list<Lote>` y desde
        // un parametro `array` el analisis no puede saber que lo es.
        /** @var list<Lote> $enOrden */
        $enOrden = array_values($lotes);

        return $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: $enOrden,
            clientes: [$this->cliente],
            prima: new Monto($prima),
            plazoMeses: 12,
            diaPago: 5,
            formaPrima: $forma ?? FormaDePago::Efectivo,
            referenciaPrima: $referencia,
        );
    };

    $this->deConcepto = fn (ConceptoDeRecibo $concepto): ?Recibo => Recibo::query()
        ->where('concepto', $concepto)
        ->first();
});

describe('El recibo de la prima', function (): void {
    test('firmar sin apartado previo cobra la prima entera', function (): void {
        $venta = ($this->firmar)([($this->lote)('1')], '100000.00');

        $recibo = ($this->deConcepto)(ConceptoDeRecibo::Prima);

        expect($recibo)->not->toBeNull()
            ->and($recibo?->montoTotal())->toBeMonto('100000.00')
            ->and($recibo?->getAttribute('venta_id'))->toBe($venta->getKey())
            // La prima es del CONTRATO, no de un lote: se pacta una sola vez
            // aunque el expediente lleve tres. R13 se conforma con la venta.
            ->and($recibo?->getAttribute('compromiso_id'))->toBeNull()
            ->and($recibo?->getAttribute('cliente_id'))->toBe($this->cliente->getKey())
            ->and($recibo?->getAttribute('forma_pago'))->toBe(FormaDePago::Efectivo)
            // Sin señas no hay resta que explicar.
            ->and($recibo?->getAttribute('observaciones'))->toBeNull();
    });

    /*
    | El corazon de R14. Si esto cobrara la prima entera, entre el recibo de
    | la seña y el de la prima el cliente tendria papeles por L 105,000.00
    | habiendo entregado L 100,000.00.
    */
    test('con seña previa, cobra la prima MENOS la seña', function (): void {
        $lote = ($this->lote)('1');

        $this->compromisos->apartar($lote, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        ($this->firmar)([$lote->refresh()], '100000.00');

        $prima = ($this->deConcepto)(ConceptoDeRecibo::Prima);

        expect($prima?->montoTotal())->toBeMonto('95000.00')
            // El papel explica la resta: quien lo lee tiene que poder atar
            // los dos recibos sin preguntarle a nadie.
            ->and($prima?->getAttribute('observaciones'))->toContain('5,000.00')
            ->and($prima?->getAttribute('observaciones'))->toContain('100,000.00');
    });

    /*
    | El papel de la seña sigue siendo del apartado —ahi nacio y ahi se
    | devuelve si el trato se cae—, pero el expediente ya lo encuentra.
    */
    test('la seña queda ligada al expediente sin dejar de ser del apartado', function (): void {
        $lote = ($this->lote)('1');

        $apartado = $this->compromisos->apartar($lote, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        $venta = ($this->firmar)([$lote->refresh()], '100000.00');

        $senia = ($this->deConcepto)(ConceptoDeRecibo::Senia);

        expect($senia?->getAttribute('venta_id'))->toBe($venta->getKey())
            ->and($senia?->getAttribute('compromiso_id'))->toBe($apartado->getKey());
    });

    /*
    | Tres lotes apartados son tres señas de L 5,000.00 —la seña es por lote—
    | y las tres se descuentan de la prima del contrato.
    */
    test('tres apartados descuentan las tres señas', function (): void {
        $lotes = [($this->lote)('1'), ($this->lote)('2'), ($this->lote)('3')];

        $this->compromisos->apartarVarios($lotes, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        ($this->firmar)(array_map(static fn (Lote $lote): Lote => $lote->refresh(), $lotes), '100000.00');

        expect(($this->deConcepto)(ConceptoDeRecibo::Prima)?->montoTotal())->toBeMonto('85000.00')
            ->and(Recibo::query()->where('concepto', ConceptoDeRecibo::Senia)->count())->toBe(3);
    });

    /*
    | R12: la serie es una sola. La seña saco el 1 al apartar y la prima saca
    | el 2 al firmar — no hay series por concepto ni por receptor.
    */
    test('la prima sigue la numeracion de la seña', function (): void {
        $lote = ($this->lote)('1');

        $this->compromisos->apartar($lote, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);
        ($this->firmar)([$lote->refresh()], '100000.00');

        expect((int) ($this->deConcepto)(ConceptoDeRecibo::Senia)?->getAttribute('numero'))->toBe(1)
            ->and((int) ($this->deConcepto)(ConceptoDeRecibo::Prima)?->getAttribute('numero'))->toBe(2);
    });
});

describe('Cuando no hay nada que cobrar hoy', function (): void {
    /*
    | La seña cubre la prima exacta: el cliente no pone un lempira mas al
    | firmar, y un recibo de L 0.00 no le sirve a nadie —ademas el CHECK
    | `recibos_monto_positivo_chk` no lo admite—. Pero la seña SI se liga al
    | expediente: esa plata es de este contrato.
    */
    test('si la seña cubre la prima exacta, no hay recibo de prima', function (): void {
        $lote = ($this->lote)('1');

        $this->compromisos->apartar($lote, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        $venta = ($this->firmar)([$lote->refresh()], '5000.00');

        expect(($this->deConcepto)(ConceptoDeRecibo::Prima))->toBeNull()
            ->and(Recibo::query()->count())->toBe(1)
            ->and(($this->deConcepto)(ConceptoDeRecibo::Senia)?->getAttribute('venta_id'))
            ->toBe($venta->getKey());
    });

    test('una venta con prima en cero tampoco emite papel', function (): void {
        ($this->firmar)([($this->lote)('1')], '0.00');

        expect(Recibo::query()->count())->toBe(0)
            ->and(Venta::query()->count())->toBe(1);
    });
});

describe('Lo que se rechaza', function (): void {
    /*
    | La seña no puede ser mas que la prima: eso significaria que el cliente
    | entrego mas de lo que el contrato declara como prima, y aceptarlo
    | obligaria a que el saldo financiado dejara de ser valor - prima. Esa
    | conversacion es de la contratante, no del sistema.
    */
    test('una seña mayor que la prima no se firma', function (): void {
        $lote = ($this->lote)('1');

        $this->compromisos->apartar($lote, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        expect(fn () => ($this->firmar)([$lote->refresh()], '1000.00'))
            ->toThrow(VentaInvalidaException::class, 'en señas');

        // Y no queda media venta: el lote sigue apartado, como estaba.
        expect(Venta::query()->count())->toBe(0)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado);
    });

    test('prima por transferencia sin referencia no se firma', function (): void {
        expect(fn () => ($this->firmar)([($this->lote)('1')], '100000.00', FormaDePago::Transferencia))
            ->toThrow(VentaInvalidaException::class, 'falta el numero de referencia');

        expect(Venta::query()->count())->toBe(0)
            ->and(Recibo::query()->count())->toBe(0);
    });

    test('con referencia, la transferencia de la prima queda cruzable', function (): void {
        ($this->firmar)([($this->lote)('1')], '100000.00', FormaDePago::Transferencia, '  TRF-9080  ');

        $prima = ($this->deConcepto)(ConceptoDeRecibo::Prima);

        expect($prima?->getAttribute('forma_pago'))->toBe(FormaDePago::Transferencia)
            ->and($prima?->getAttribute('referencia'))->toBe('TRF-9080');
    });
});

/*
|--------------------------------------------------------------------------
| 🔴 Un papel por titular de recibo — 21-sep-2026
|--------------------------------------------------------------------------
| «Los recibos deben salir a estos nombres» — Mauricio, con un expediente de
| dos lotes, cada uno con su titular de recibo, y UNA prima sin nombre que
| hubo que partir a mano. Las cuotas y los abonos ya salían en un papel por
| nombre desde el 13-ago; la prima era la única que no.
|
| Lo de arriba no cambia: con un solo nombre —o ninguno— la prima sigue
| saliendo en UN papel que cuelga de la venta.
*/
describe('Un papel por titular de recibo', function (): void {
    beforeEach(function (): void {
        /*
         * `$nombres` va en el orden de los lotes; null es «sin titular de
         * recibo». `$primas`, igual, es la prima pactada de ese lote; null es
         * «repartime la del contrato».
         */
        $this->firmarANombreDe = function (array $lotes, array $nombres, string $prima, array $primas = []): Venta {
            /** @var list<Lote> $enOrden */
            $enOrden = array_values($lotes);

            $precios = [];

            foreach ($enOrden as $indice => $lote) {
                $precios[] = new PrecioPactado(
                    loteId: (int) $lote->getKey(),
                    precioVara: new Monto('1400.00'),
                    prima: isset($primas[$indice]) ? new Monto($primas[$indice]) : null,
                    titularRecibo: $nombres[$indice] ?? null,
                );
            }

            return $this->registro->activar(
                proyecto: $this->proyecto,
                lotes: $enOrden,
                clientes: [$this->cliente],
                prima: new Monto($prima),
                plazoMeses: 12,
                diaPago: 5,
                precios: $precios,
            );
        };

        $this->primas = fn () => Recibo::query()
            ->where('concepto', ConceptoDeRecibo::Prima)
            ->orderBy('id')
            ->get();
    });

    test('dos lotes con nombres distintos: dos recibos, cada uno de su lote y a su nombre', function (): void {
        $venta = ($this->firmarANombreDe)(
            [($this->lote)('1'), ($this->lote)('2')],
            ['MELVIN RODRIGUEZ LOTE UNO', 'MELVIN RODRIGUEZ LOTE DOS'],
            '100000.00',
        );

        [$uno, $dos] = $venta->compromisos()->orderBy('id')->get()->all();
        $primas = ($this->primas)();

        expect($primas)->toHaveCount(2)
            ->and($primas[0]->montoTotal())->toBeMonto('50000.00')
            ->and($primas[0]->getAttribute('compromiso_id'))->toBe($uno->getKey())
            ->and($primas[0]->getAttribute('a_nombre_de'))->toBe('MELVIN RODRIGUEZ LOTE UNO')
            ->and($primas[1]->montoTotal())->toBeMonto('50000.00')
            ->and($primas[1]->getAttribute('compromiso_id'))->toBe($dos->getKey())
            ->and($primas[1]->getAttribute('a_nombre_de'))->toBe('MELVIN RODRIGUEZ LOTE DOS')
            // R12: dos papeles, dos números seguidos de la misma serie.
            ->and((int) $primas[1]->getAttribute('numero'))->toBe((int) $primas[0]->getAttribute('numero') + 1);
    });

    test('con el MISMO nombre en todos los lotes sigue saliendo un solo papel, y lleva ese nombre', function (): void {
        ($this->firmarANombreDe)(
            [($this->lote)('1'), ($this->lote)('2')],
            ['INVERSIONES DEL NORTE', 'INVERSIONES DEL NORTE'],
            '100000.00',
        );

        $primas = ($this->primas)();

        expect($primas)->toHaveCount(1)
            ->and($primas[0]->montoTotal())->toBeMonto('100000.00')
            ->and($primas[0]->getAttribute('compromiso_id'))->toBeNull()
            ->and($primas[0]->getAttribute('a_nombre_de'))->toBe('INVERSIONES DEL NORTE');
    });

    /*
    | Tres lotes de igual valor y L 90,000.00 de prima: L 30,000.00 cada uno.
    | Dos comparten nombre, así que su papel es de L 60,000.00, queda en la
    | venta —no es de UN lote— y dice SUS dos lotes, no los tres del contrato.
    */
    test('los lotes que comparten nombre van en un mismo papel, y el papel dice cuáles son', function (): void {
        $venta = ($this->firmarANombreDe)(
            [($this->lote)('1'), ($this->lote)('2'), ($this->lote)('3')],
            ['LOS HERMANOS', 'LOS HERMANOS', 'LA TIA'],
            '90000.00',
        );

        $tercero = $venta->compromisos()->orderByDesc('id')->firstOrFail();
        $primas = ($this->primas)();

        expect($primas)->toHaveCount(2)
            ->and($primas[0]->getAttribute('a_nombre_de'))->toBe('LOS HERMANOS')
            ->and($primas[0]->montoTotal())->toBeMonto('60000.00')
            ->and($primas[0]->getAttribute('compromiso_id'))->toBeNull()
            ->and($primas[0]->compromisosDelPapel())->toHaveCount(2)
            ->and($primas[1]->getAttribute('a_nombre_de'))->toBe('LA TIA')
            ->and($primas[1]->montoTotal())->toBeMonto('30000.00')
            ->and($primas[1]->getAttribute('compromiso_id'))->toBe($tercero->getKey());
    });

    test('cada papel descuenta SUS señas y lo explica', function (): void {
        $lotes = [($this->lote)('1'), ($this->lote)('2')];

        $this->compromisos->apartarVarios($lotes, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        ($this->firmarANombreDe)(
            array_map(static fn (Lote $lote): Lote => $lote->refresh(), $lotes),
            ['MELVIN RODRIGUEZ LOTE UNO', 'MELVIN RODRIGUEZ LOTE DOS'],
            '100000.00',
        );

        $primas = ($this->primas)();

        // 50,000 de prima por lote menos los 5,000 de SU seña.
        expect($primas)->toHaveCount(2)
            ->and($primas[0]->montoTotal())->toBeMonto('45000.00')
            ->and($primas[1]->montoTotal())->toBeMonto('45000.00')
            ->and($primas[0]->getAttribute('observaciones'))->toContain('50,000.00')
            ->and($primas[0]->getAttribute('observaciones'))->toContain('5,000.00');
    });

    /*
    | 🔴 El caso que NO se parte. El primer lote pactó L 2,000.00 de prima y ya
    | había dejado L 5,000.00 de seña: la cuenta del contrato cierra (100,000
    | menos 10,000), pero la de ESE papel daría negativa. Sale un solo recibo
    | por la diferencia entera, como se hizo siempre, y sin nombre: es de los dos.
    */
    test('si a un nombre sus señas le superan su parte, la prima sale en un solo papel', function (): void {
        $lotes = [($this->lote)('1'), ($this->lote)('2')];

        $this->compromisos->apartarVarios($lotes, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        ($this->firmarANombreDe)(
            array_map(static fn (Lote $lote): Lote => $lote->refresh(), $lotes),
            ['MELVIN RODRIGUEZ LOTE UNO', 'MELVIN RODRIGUEZ LOTE DOS'],
            '100000.00',
            ['2000.00', '98000.00'],
        );

        $primas = ($this->primas)();

        expect($primas)->toHaveCount(1)
            ->and($primas[0]->montoTotal())->toBeMonto('90000.00')
            ->and($primas[0]->getAttribute('compromiso_id'))->toBeNull()
            ->and($primas[0]->getAttribute('a_nombre_de'))->toBeNull();
    });

    // 🔴 Ni un centavo de más ni de menos entre todos los papeles.
    test('los papeles suman exactamente lo que el cliente entregó', function (): void {
        ($this->firmarANombreDe)(
            [($this->lote)('1'), ($this->lote)('2'), ($this->lote)('3')],
            ['UNO', 'DOS', 'TRES'],
            '100000.00',
        );

        $total = Monto::cero();

        foreach (($this->primas)() as $papel) {
            $total = $total->sumar($papel->montoTotal());
        }

        // 100,000 entre tres no es exacto: el centavo que sobra va al último.
        expect(($this->primas)())->toHaveCount(3)
            ->and($total)->toBeMonto('100000.00');
    });
});
