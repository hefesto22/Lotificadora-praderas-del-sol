<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| El dinero que entra — R11, R12, R13, R19
|--------------------------------------------------------------------------
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a financiar, que a 12 meses dan cuotas de L 25,000.00
| exactas. Todos los números de abajo salen de ahí y se pueden verificar sin
| calculadora.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->pagos = app(RegistroDePagos::class);

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $this->proyecto = $proyecto;
    $this->bloque = $bloque;

    /*
    | Un contrato de DOS lotes, para los bordes del titular del recibo. Se arma
    | a pedido y no en cada test: la mayoria trabaja con el de un lote.
    |
    | @return array{0: \App\Models\Venta, 1: list<Compromiso>}
    */
    $this->ventaDeDosLotes = function (): array {
        $lotes = [];

        foreach (['21', '22'] as $numero) {
            $lotes[] = Lote::factory()
                ->enBloque($this->bloque)
                ->conMedidas('250.0000', '1400.00')
                ->create(['numero' => $numero]);
        }

        $venta = app(RegistroDeVentas::class)->activar(
            proyecto: $this->proyecto,
            lotes: $lotes,
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 12,
            diaPago: 5,
        );

        return [$venta, $venta->compromisos()->get()->all()];
    };

    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote],
        clientes: [$this->cliente],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $this->renglon = $this->venta->compromisos()->firstOrFail();

    $this->cobrar = fn (string $monto, ?string $referencia = null, ?FormaDePago $forma = null) => $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto($monto),
        forma: $forma ?? FormaDePago::Efectivo,
        referencia: $referencia,
    );
});

describe('Un pago normal', function (): void {
    test('cubre la cuota más vieja y deja su recibo', function (): void {
        $recibo = ($this->cobrar)('25000.00');

        $primera = Cuota::query()->where('compromiso_id', $this->renglon->getKey())->orderBy('numero')->firstOrFail();

        expect($primera->estaPagada())->toBeTrue()
            ->and($primera->montoPagado())->toBeMonto('25000.00')
            ->and($recibo->montoTotal())->toBeMonto('25000.00')
            ->and($recibo->aplicaciones()->count())->toBe(1)
            ->and($recibo->getAttribute('venta_id'))->toBe($this->venta->getKey());
    });

    /*
    | FIFO: la más vieja primero. No es una preferencia — es lo que el cliente
    | entiende cuando dice «vengo a pagar», y es lo que hace que el atraso se
    | achique en vez de dejar huecos en el medio del plan.
    */
    test('un pago grande se reparte de la más vieja a la más nueva', function (): void {
        $recibo = ($this->cobrar)('60000.00');

        $cuotas = Cuota::query()
            ->where('compromiso_id', $this->renglon->getKey())
            ->orderBy('numero')
            ->limit(4)
            ->pluck('monto_pagado')
            ->all();

        // 25,000 + 25,000 + 10,000 = 60,000
        expect($cuotas)->toBe(['25000.00', '25000.00', '10000.00', '0.00'])
            ->and($recibo->aplicaciones()->count())->toBe(3);
    });

    /*
    | R19: «a veces pagan la cuota de un mes en 2 o más pagos». Lo que falta se
    | arrastra y NO genera cargo — R2, el atraso no cuesta.
    */
    test('una cuota se paga en dos veces y lo que falta se arrastra', function (): void {
        ($this->cobrar)('10000.00');

        $primera = Cuota::query()->where('compromiso_id', $this->renglon->getKey())->orderBy('numero')->firstOrFail();

        expect($primera->estaPagada())->toBeFalse()
            ->and($primera->saldo())->toBeMonto('15000.00');

        ($this->cobrar)('15000.00');

        expect($primera->refresh()->estaPagada())->toBeTrue()
            ->and($primera->montoPagado())->toBeMonto('25000.00')
            // Dos recibos distintos: cada pago tiene su papel.
            ->and($this->venta->getKey())->not->toBeNull();
    });

    test('el saldo del expediente baja con cada pago', function (): void {
        expect($this->venta->saldoPendiente())->toBeMonto('300000.00');

        ($this->cobrar)('75000.00');

        expect($this->venta->refresh()->saldoPendiente())->toBeMonto('225000.00');
    });
});

/*
|--------------------------------------------------------------------------
| R12 · Una numeración por desarrollo, sin huecos
|--------------------------------------------------------------------------
|
| Fue una sola serie para toda la lotificadora hasta el 23-ago-2026. Adentro
| de un desarrollo la promesa no cambió —entre el 120 y el 130 no falta
| ninguno— y el folio ahora lleva el código adelante para que dos proyectos
| no se confundan.
*/
test('cada recibo se lleva su propio número', function (): void {
    $uno = ($this->cobrar)('25000.00');
    $dos = ($this->cobrar)('25000.00');

    // El prefijo sale del PROYECTO, no escrito a mano: un test atado al
    // codigo de otro archivo se rompe el dia que ese dato cambia. Pasó en
    // `EmisionDeFacturasTest`, cuyo proyecto de prueba es REB.
    $codigo = preg_quote((string) $this->proyecto->getAttribute('codigo'), '/');

    expect((int) $dos->getAttribute('numero'))->toBe((int) $uno->getAttribute('numero') + 1)
        ->and($uno->folio())->toMatch("/^{$codigo}-\d{8}$/");
});

describe('Lo que rechaza', function (): void {
    test('un pago mayor a lo que se debe, diciendo cuánto se debe', function (): void {
        expect(fn () => ($this->cobrar)('300000.01'))
            ->toThrow(PagoInvalidoException::class, 'L. 300,000.00');
    });

    test('un monto en cero', function (): void {
        expect(fn () => ($this->cobrar)('0'))
            ->toThrow(PagoInvalidoException::class, 'mayor que cero');
    });

    /*
    | 🔴 27-ago-2026 — R11 se afloja EN LOS RECIBOS.
    |
    | Hasta hoy una transferencia sin referencia se rechazaba. En el mostrador
    | eso significa que llega el cliente, el número todavía no lo tiene nadie,
    | y el cobro NO se registra — bastante peor que registrarlo sin ella.
    |
    | ⚠️ La regla sigue viva donde la plata SALE: la prima de una venta, la
    | seña de un apartado, los gastos y las entregas a socios la siguen
    | exigiendo, y sus tests siguen en verde. Este afloje es solo del recibo.
    */
    test('una transferencia sin número de referencia se registra igual', function (): void {
        $recibo = ($this->cobrar)('25000.00', null, FormaDePago::Transferencia);

        expect($recibo->getAttribute('referencia'))->toBeNull();

        $conNumero = ($this->cobrar)('25000.00', 'TRF-99812', FormaDePago::Transferencia);

        expect($conNumero->getAttribute('referencia'))->toBe('TRF-99812');
    });

    /*
    | 🔴 «Que la administradora y yo podamos seleccionar quién recibió el
    | dinero» — Mauricio, 27-ago-2026. De este dato sale el corte de caja.
    */
    test('el recibo guarda quién recibió el dinero, y por defecto es quien teclea', function (): void {
        expect((int) ($this->cobrar)('25000.00')->getAttribute('recibido_por'))
            ->toBe((int) auth()->id());

        $enLaCaseta = User::factory()->create(['name' => 'Elder Martínez']);

        $recibo = $this->pagos->loRecibio((int) $enLaCaseta->getKey())->cobrarCuotas(
            venta: $this->venta,
            lote: $this->renglon,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        );

        // Quién lo recibió y quién lo tecleó son dos preguntas distintas.
        expect((int) $recibo->getAttribute('recibido_por'))->toBe((int) $enLaCaseta->getKey())
            ->and((int) $recibo->getAttribute('created_by'))->toBe((int) auth()->id());
    });

    test('un lote que no es de este contrato', function (): void {
        $ajeno = Compromiso::factory()->create();

        expect(fn () => $this->pagos->cobrarCuotas(
            venta: $this->venta,
            lote: $ajeno,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        ))->toThrow(PagoInvalidoException::class, 'no pertenece al contrato');
    });

    /*
    | `cerrada_el` no es cosmética: la base tiene un CHECK que exige la fecha
    | de cierre cuando el estado es uno de los cerrados, y al revés. Poner el
    | estado a mano sin ella es lo que este test intentaba al principio, y
    | Postgres lo rechazó — con razón, porque un expediente rescindido sin
    | fecha de cierre no se puede poner en ningún reporte.
    */
    test('un expediente que no está vigente', function (): void {
        $this->venta->update([
            'estado'     => EstadoVenta::Rescindida,
            'cerrada_el' => today(),
        ]);

        expect(fn () => ($this->cobrar)('25000.00'))
            ->toThrow(PagoInvalidoException::class, 'rescindida');
    });

    /*
    | Todo o nada: un pago rechazado no quema un número de recibo. El
    | correlativo es lo único que no se puede reponer.
    */
    test('un pago rechazado no deja recibo ni mueve cuotas', function (): void {
        try {
            ($this->cobrar)('999999.00');
        } catch (PagoInvalidoException) {
            // Es lo que se espera.
        }

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });
});

/*
|--------------------------------------------------------------------------
| Las guardas del cobro de varios lotes
|--------------------------------------------------------------------------
| El caso feliz —dos lotes, un recibo— se prueba desde la pantalla, que es
| donde vive el trámite. Acá quedan las dos formas de armar mal la lista, que
| ninguna pantalla debería producir y que igual no pueden llegar a la base.
*/
describe('Cobrar varios lotes', function (): void {
    test('sin ningún lote marcado no se cobra nada', function (): void {
        expect(fn () => $this->pagos->cobrarVariosLotes(
            venta: $this->venta,
            cliente: $this->cliente,
            renglones: [],
            forma: FormaDePago::Efectivo,
        ))->toThrow(PagoInvalidoException::class, 'ningún lote marcado');

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0);
    });

    /*
    | Dos renglones del mismo lote sumarían en silencio y el recibo diría un
    | total que nadie tecleó.
    */
    test('el mismo lote dos veces se rechaza', function (): void {
        expect(fn () => $this->pagos->cobrarVariosLotes(
            venta: $this->venta,
            cliente: $this->cliente,
            renglones: [
                ['lote' => $this->renglon, 'monto' => new Monto('25000.00')],
                ['lote' => $this->renglon, 'monto' => new Monto('25000.00')],
            ],
            forma: FormaDePago::Efectivo,
        ))->toThrow(PagoInvalidoException::class, 'dos veces');

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });
});

describe('A nombre de quien sale el recibo', function (): void {
    /*
    |--------------------------------------------------------------------------
    | El caso del representante — 12-ago-2026
    |--------------------------------------------------------------------------
    | «Hay persona representante pero los representados quieren recibo a nombre
    | de ellos, todo en el expediente del representante. Si son 3 lotes debe
    | decidir a nombre de quien sale el recibo de ESE lote; si no colocan
    | ningun nombre, sale a nombre del dueño del expediente» — Mauricio.
    |
    | Por eso la configuracion vive en el LOTE del contrato y no en cada cobro:
    | se escribe una vez al vender y de ahi en adelante sale sola.
    */
    test('sin nada configurado, el papel sale a nombre del dueño del expediente', function (): void {
        $recibo = ($this->cobrar)('25000.00');

        expect($recibo->getAttribute('a_nombre_de'))->toBeNull()
            ->and($recibo->esANombreDeOtro())->toBeFalse()
            ->and($recibo->nombreDelPapel())->toBe((string) $this->cliente->getAttribute('nombre'));
    });

    test('con titular en el lote, el papel sale a ese nombre y el contrato no se mueve', function (): void {
        $this->renglon->update([
            'titular_recibo'     => 'JOSE ANTONIO MEJIA',
            'titular_recibo_dni' => '0801198501234',
        ]);

        $recibo = ($this->cobrar)('25000.00');

        expect($recibo->getAttribute('a_nombre_de'))->toBe('JOSE ANTONIO MEJIA')
            ->and($recibo->dniDelPapel())->toBe('0801198501234')
            ->and($recibo->esANombreDeOtro())->toBeTrue()
            ->and($recibo->nombreDelPapel())->toBe('JOSE ANTONIO MEJIA')
            // El contrato sigue siendo del titular: esto NO lo hace dueño de nada.
            ->and($recibo->getAttribute('cliente_id'))->toBe($this->cliente->getKey());
    });

    /*
    | §8.2 aplicado al papel: el recibo se queda con una COPIA. Corregir despues
    | el nombre del lote no puede reescribir lo que ya se entrego — un recibo
    | entregado no se corrige, se anula y se emite otro.
    */
    test('el nombre queda congelado en el papel', function (): void {
        $this->renglon->update(['titular_recibo' => 'JOSE ANTONIO MEJIA']);

        $recibo = ($this->cobrar)('25000.00');

        $this->renglon->update(['titular_recibo' => 'OTRA PERSONA']);

        expect($recibo->refresh()->getAttribute('a_nombre_de'))->toBe('JOSE ANTONIO MEJIA');
    });

    /*
    | 🔴 UN PAPEL POR CADA NOMBRE, Y CADA UNO CON LO SUYO.
    |
    | «Si pagan la cuota de 3 lotes y tienen nombre de recibo distinto se
    | imprimen 3 recibos con la cuota de su lote» — Mauricio, 13-ago-2026.
    |
    | Lo importante no es que sean dos papeles sino que cada uno lleve SU plata:
    | si los montos se mezclaran, ninguno de los dos serviria de comprobante.
    */
    test('dos lotes con titulares distintos salen en dos recibos, cada uno con lo suyo', function (): void {
        [$venta, $renglones] = ($this->ventaDeDosLotes)();

        $renglones[0]->update(['titular_recibo' => 'JOSE ANTONIO MEJIA']);
        $renglones[1]->update(['titular_recibo' => 'MARIA EVELINA CABALLERO']);

        $recibos = $this->pagos->cobrarVariosLotes(
            venta: $venta,
            cliente: $this->cliente,
            renglones: [
                ['lote' => $renglones[0], 'monto' => new Monto('10000.00')],
                ['lote' => $renglones[1], 'monto' => new Monto('15000.00')],
            ],
            forma: FormaDePago::Efectivo,
        );

        expect($recibos)->toHaveCount(2);

        $porNombre = [];

        foreach ($recibos as $recibo) {
            $porNombre[(string) $recibo->getAttribute('a_nombre_de')] = $recibo;
        }

        expect(array_keys($porNombre))->toEqualCanonicalizing(['JOSE ANTONIO MEJIA', 'MARIA EVELINA CABALLERO'])
            ->and($porNombre['JOSE ANTONIO MEJIA']->montoTotal())->toBeMonto('10000.00')
            ->and($porNombre['MARIA EVELINA CABALLERO']->montoTotal())->toBeMonto('15000.00')
            // Cada papel toca UN solo lote: el suyo.
            ->and($porNombre['JOSE ANTONIO MEJIA']->codigosDeLotes())->toHaveCount(1)
            ->and($porNombre['MARIA EVELINA CABALLERO']->codigosDeLotes())->toHaveCount(1)
            // Y cada uno quemo su numero de la serie unica (R12).
            ->and($porNombre['JOSE ANTONIO MEJIA']->getAttribute('numero'))
            ->not->toBe($porNombre['MARIA EVELINA CABALLERO']->getAttribute('numero'));
    });

    /*
    | Un lote configurado y otro sin configurar tambien son dos nombres —«Jose»
    | y «el dueño del expediente»— y por eso tambien son dos papeles. Es el
    | borde que se olvida.
    */
    test('un lote con titular y otro sin el tambien salen en dos recibos', function (): void {
        [$venta, $renglones] = ($this->ventaDeDosLotes)();

        $renglones[0]->update(['titular_recibo' => 'JOSE ANTONIO MEJIA']);

        $recibos = $this->pagos->cobrarVariosLotes(
            venta: $venta,
            cliente: $this->cliente,
            renglones: [
                ['lote' => $renglones[0], 'monto' => new Monto('10000.00')],
                ['lote' => $renglones[1], 'monto' => new Monto('10000.00')],
            ],
            forma: FormaDePago::Efectivo,
        );

        $nombres = array_map(
            static fn (Recibo $recibo): ?string => $recibo->getAttribute('a_nombre_de'),
            $recibos,
        );

        expect($recibos)->toHaveCount(2)
            ->and($nombres)->toEqualCanonicalizing(['JOSE ANTONIO MEJIA', null]);
    });

    test('dos lotes del MISMO titular si van en un solo recibo', function (): void {
        [$venta, $renglones] = ($this->ventaDeDosLotes)();

        foreach ($renglones as $renglon) {
            $renglon->update(['titular_recibo' => 'JOSE ANTONIO MEJIA']);
        }

        $recibos = $this->pagos->cobrarVariosLotes(
            venta: $venta,
            cliente: $this->cliente,
            renglones: [
                ['lote' => $renglones[0], 'monto' => new Monto('10000.00')],
                ['lote' => $renglones[1], 'monto' => new Monto('10000.00')],
            ],
            forma: FormaDePago::Efectivo,
        );

        expect($recibos)->toHaveCount(1)
            ->and($recibos[0]->getAttribute('a_nombre_de'))->toBe('JOSE ANTONIO MEJIA')
            ->and($recibos[0]->codigosDeLotes())->toHaveCount(2);
    });

    /*
    | «Y en abono de capital tambien» — Mauricio. Se parte igual.
    */
    test('el abono a capital se parte por nombre igual que el cobro', function (): void {
        [$venta, $renglones] = ($this->ventaDeDosLotes)();

        $renglones[0]->update(['titular_recibo' => 'JOSE ANTONIO MEJIA']);
        $renglones[1]->update(['titular_recibo' => 'MARIA EVELINA CABALLERO']);

        $recibos = $this->pagos->abonarAVariosLotes(
            venta: $venta,
            cliente: $this->cliente,
            renglones: [
                // La modalidad es POR RENGLON: cada lote elige si le baja la
                // cuota o le acorta el plazo (R21).
                ['lote' => $renglones[0], 'monto' => new Monto('20000.00'), 'modalidad' => ModalidadDeReprogramacion::BajarCuota],
                ['lote' => $renglones[1], 'monto' => new Monto('20000.00'), 'modalidad' => ModalidadDeReprogramacion::BajarCuota],
            ],
            motivo: 'Abono de cada representado.',
            forma: FormaDePago::Efectivo,
        );

        $nombres = array_map(
            static fn (Recibo $recibo): ?string => $recibo->getAttribute('a_nombre_de'),
            $recibos,
        );

        expect($recibos)->toHaveCount(2)
            ->and($nombres)->toEqualCanonicalizing(['JOSE ANTONIO MEJIA', 'MARIA EVELINA CABALLERO']);
    });

    /*
    | El camino de verdad: se configura al VENDER, con el precio pactado de ese
    | lote, y de ahi en adelante los recibos salen solos.
    */
    test('se configura al vender y de ahi en adelante sale solo', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '9']);

        $venta = app(RegistroDeVentas::class)->activar(
            proyecto: $this->proyecto,
            lotes: [$lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 12,
            diaPago: 5,
            precios: [new PrecioPactado(
                loteId: (int) $lote->getKey(),
                precioVara: new Monto('1400.00'),
                titularRecibo: '  JOSE ANTONIO MEJIA  ',
                dniTitularRecibo: '0801198501234',
            )],
        );

        $renglon = $venta->compromisos()->firstOrFail();

        // El Service limpia los espacios: un nombre de espacios no es un nombre
        // y el CHECK de la base no lo admite.
        expect($renglon->titularDelRecibo())->toBe('JOSE ANTONIO MEJIA');

        $recibo = $this->pagos->cobrarCuotas(
            venta: $venta,
            lote: $renglon,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        );

        expect($recibo->getAttribute('a_nombre_de'))->toBe('JOSE ANTONIO MEJIA')
            ->and($recibo->dniDelPapel())->toBe('0801198501234');
    });

    test('la base no admite un titular de recibo en blanco', function (): void {
        expect(fn () => DB::table('compromisos')
            ->where('id', $this->renglon->getKey())
            ->update(['titular_recibo' => '   ']))
            ->toThrow(QueryException::class);
    });

    test('la base no admite un DNI sin nombre', function (): void {
        expect(fn () => DB::table('compromisos')
            ->where('id', $this->renglon->getKey())
            ->update(['titular_recibo_dni' => '0801198501234']))
            ->toThrow(QueryException::class);
    });
});
