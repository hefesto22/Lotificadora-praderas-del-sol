<?php

declare(strict_types=1);

use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\PlanDelContrato;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Un plazo por lote, dentro de un mismo contrato — 5-ago-2026
|--------------------------------------------------------------------------
| «1 lote a 12 meses, segundo lote a 24 y tercer lote a 48, todo en un mismo
| contrato y cuotas individuales por lote, y cuánto será el pago mensual que
| estaría haciendo» — Mauricio.
|
| No es presentación: desde que el precio de la vara² depende del plazo,
| obligar a los tres lotes al mismo plazo era obligarlos al mismo precio.
|
| Los números están elegidos para que TODO cierre exacto y se pueda leer sin
| calculadora: tres lotes de 250 vr² a L 1,400.00 son L 350,000.00 cada uno;
| con L 50,000.00 de prima cada uno quedan L 300,000.00 a financiar, que a
| 12, 24 y 48 meses dan L 25,000.00, L 12,500.00 y L 6,250.00.
*/

beforeEach(function (): void {
    $this->registro = app(RegistroDeVentas::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->lote = fn (string $numero): Lote => Lote::factory()
        ->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => $numero]);
});

describe('Tres lotes, tres plazos, un solo contrato', function (): void {
    beforeEach(function (): void {
        $this->uno = ($this->lote)('1');
        $this->dos = ($this->lote)('2');
        $this->tres = ($this->lote)('3');

        $condiciones = static fn (Lote $lote, int $meses): PrecioPactado => new PrecioPactado(
            loteId: (int) $lote->getKey(),
            precioVara: new Monto('1400.00'),
            plazoMeses: $meses,
            prima: new Monto('50000.00'),
        );

        $this->venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->uno, $this->dos, $this->tres],
            clientes: [$this->cliente],
            prima: new Monto('150000.00'),
            plazoMeses: 12,
            diaPago: 5,
            precios: [
                $condiciones($this->uno, 12),
                $condiciones($this->dos, 24),
                $condiciones($this->tres, 48),
            ],
        );
    });

    test('cada lote guarda su propio plazo y su propia prima', function (): void {
        $plazos = $this->venta->compromisos()
            ->orderBy('lote_id')
            ->pluck('plazo_meses')
            ->all();

        expect($plazos)->toBe([12, 24, 48])
            ->and($this->venta->compromisos()->pluck('prima')->all())
            ->toBe(['50000.00', '50000.00', '50000.00']);
    });

    /*
    | «cuotas individuales por lote». Cada cuota apunta a SU compromiso: sin
    | eso no se puede decir cuánto falta de un lote, ni rescindir uno solo
    | sin tocar los otros dos.
    */
    test('las cuotas son de cada lote, no del contrato', function (): void {
        $porLote = $this->venta->compromisos()
            ->orderBy('lote_id')
            ->get()
            ->map(fn ($compromiso): int => $compromiso->cuotas()->count())
            ->all();

        expect($porLote)->toBe([12, 24, 48])
            ->and(Cuota::query()->count())->toBe(84)
            ->and(Cuota::query()->whereNull('compromiso_id')->count())->toBe(0);
    });

    test('la cuota de cada lote sale de su propio plazo', function (): void {
        $primeras = $this->venta->compromisos()
            ->orderBy('lote_id')
            ->get()
            ->map(fn ($compromiso): ?string => $compromiso->cuotas()->value('monto'))
            ->all();

        expect($primeras)->toBe(['25000.00', '12500.00', '6250.00']);
    });

    /*
    | El resumen de la venta cambia de SIGNIFICADO, no de tipo: `plazo_meses`
    | es el horizonte del contrato y `cuota_mensual` es lo que se paga el
    | primer mes, que es el número más alto.
    */
    test('la venta guarda el horizonte y el primer mes', function (): void {
        expect($this->venta->getAttribute('plazo_meses'))->toBe(48)
            ->and($this->venta->montoCuotaMensual())->toBeMonto('43750.00')
            ->and($this->venta->montoValorTotal())->toBeMonto('1050000.00')
            ->and($this->venta->montoSaldoFinanciar())->toBeMonto('900000.00');
    });

    /*
    | Y esta es la pregunta que hace el cliente parado en el mostrador. La
    | respuesta no es un número: son tres, porque la cuota BAJA dos veces.
    */
    test('el pago mensual baja cada vez que un lote se termina', function (): void {
        $mes = fn (int $n): string => Cuota::query()
            ->where('venta_id', $this->venta->getKey())
            ->where('numero', $n)
            ->get()
            ->reduce(
                static fn (Monto $suma, Cuota $cuota): Monto => $suma->sumar($cuota->montoTotal()),
                Monto::cero()
            )->redondeado();

        expect($mes(1))->toBe('43750.00')     // los tres vivos
            ->and($mes(12))->toBe('43750.00')  // el ultimo mes del primero
            ->and($mes(13))->toBe('18750.00')  // se cayo el de 12
            ->and($mes(24))->toBe('18750.00')
            ->and($mes(25))->toBe('6250.00')   // queda solo el de 48
            ->and($mes(48))->toBe('6250.00');
    });
});

describe('Los tramos que se le muestran al cliente', function (): void {
    test('agrupa los meses consecutivos que se pagan igual', function (): void {
        $plan = static fn (string $valor, string $prima, int $meses): PlanDeCuotas => PlanDeCuotas::nuevo(
            new Monto($valor),
            new Monto($prima),
            $meses,
            5,
            CarbonImmutable::parse('2026-08-05'),
        );

        $contrato = new PlanDelContrato([
            ['etiqueta' => 'RPS-A-001', 'plan' => $plan('350000.00', '50000.00', 12)],
            ['etiqueta' => 'RPS-A-002', 'plan' => $plan('350000.00', '50000.00', 24)],
            ['etiqueta' => 'RPS-A-003', 'plan' => $plan('350000.00', '50000.00', 48)],
        ]);

        $tramos = array_map(
            static fn (array $tramo): string => sprintf(
                '%d-%d: %s',
                $tramo['desde'],
                $tramo['hasta'],
                $tramo['monto']->redondeado()
            ),
            $contrato->tramos(),
        );

        expect($tramos)->toBe([
            '1-12: 43750.00',
            '13-24: 18750.00',
            '25-48: 6250.00',
        ])
            ->and($contrato->plazoMaximo())->toBe(48)
            ->and($contrato->primeraCuota()?->redondeado())->toBe('43750.00')
            ->and($contrato->tienePlazosMezclados())->toBeTrue();
    });

    /*
    | Con todos los lotes al mismo plazo hay UN tramo: el contrato se lee
    | como se leia antes de que existieran los plazos por lote.
    */
    test('con un solo plazo hay un solo tramo', function (): void {
        $plan = PlanDeCuotas::nuevo(
            new Monto('350000.00'),
            new Monto('50000.00'),
            12,
            5,
            CarbonImmutable::parse('2026-08-05'),
        );

        $contrato = new PlanDelContrato([
            ['etiqueta' => 'RPS-A-001', 'plan' => $plan],
            ['etiqueta' => 'RPS-A-002', 'plan' => $plan],
        ]);

        expect($contrato->tramos())->toHaveCount(1)
            ->and($contrato->tienePlazosMezclados())->toBeFalse()
            ->and($contrato->primeraCuota()?->redondeado())->toBe('50000.00');
    });

    test('de contado no hay tramos ni cuota', function (): void {
        $contrato = new PlanDelContrato([
            ['etiqueta' => 'RPS-A-001', 'plan' => PlanDeCuotas::nuevo(
                new Monto('350000.00'),
                new Monto('350000.00'),
                0,
                5,
                CarbonImmutable::parse('2026-08-05'),
            )],
        ]);

        expect($contrato->tramos())->toBe([])
            ->and($contrato->esDeContado())->toBeTrue()
            ->and($contrato->primeraCuota())->toBeNull();
    });
});

describe('La prima del contrato, repartida', function (): void {
    /*
    | Sin prima por lote, se reparte en proporcion al valor: es la unica
    | regla que no le cobra a un lote la prima de otro.
    */
    test('sin prima por lote, se reparte segun el valor de cada uno', function (): void {
        $chico = Lote::factory()->enBloque($this->bloque)
            ->conMedidas('100.0000', '1400.00')->create(['numero' => '1']);   // 140,000
        $grande = Lote::factory()->enBloque($this->bloque)
            ->conMedidas('300.0000', '1400.00')->create(['numero' => '2']);   // 420,000

        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$chico, $grande],
            clientes: [$this->cliente],
            prima: new Monto('56000.00'),   // el 10% de 560,000
            plazoMeses: 12,
            diaPago: 5,
        );

        expect($venta->compromisos()->orderBy('lote_id')->pluck('prima')->all())
            ->toBe(['14000.00', '42000.00']);
    });

    /*
    | Y la suma tiene que dar EXACTAMENTE la del contrato, aunque el reparto
    | no sea redondo. 1,000.00 entre tres lotes iguales son 333.33, 333.33 y
    | 333.34: el ultimo se lleva el residuo para que no falte un centavo.
    */
    test('el reparto no pierde ni un centavo', function (): void {
        $lotes = [($this->lote)('1'), ($this->lote)('2'), ($this->lote)('3')];

        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: $lotes,
            clientes: [$this->cliente],
            prima: new Monto('1000.00'),
            plazoMeses: 12,
            diaPago: 5,
        );

        $primas = $venta->compromisos()->orderBy('lote_id')->pluck('prima')->all();

        expect($primas)->toBe(['333.33', '333.33', '333.34'])
            ->and($venta->montoPrima())->toBeMonto('1000.00');
    });

    /*
    | 🔴🔴 Y a QUIEN le toca ese centavo no lo puede decidir Postgres.
    |
    | 27-ago-2026: el test de arriba pasaba en la Mac y fallaba en el CI —el
    | residuo caia en otro lote—. `bloquearYVerificar()` leia los lotes con un
    | `FOR UPDATE` SIN `orderBy`, asi que el orden de los renglones del
    | contrato lo elegia el plan de la consulta, que no es el mismo en dos
    | versiones de Postgres.
    |
    | Este test lo fija desde el otro lado: los lotes entran AL REVES, como si
    | la pantalla los hubiera marcado 3, 2, 1. El reparto tiene que dar lo
    | mismo, porque el orden lo pone el dominio y no quien llama.
    */
    test('a quién le toca el centavo no depende del orden en que se eligieron', function (): void {
        $lotes = [($this->lote)('1'), ($this->lote)('2'), ($this->lote)('3')];

        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: array_reverse($lotes),
            clientes: [$this->cliente],
            prima: new Monto('1000.00'),
            plazoMeses: 12,
            diaPago: 5,
        );

        expect($venta->compromisos()->orderBy('lote_id')->pluck('prima')->all())
            ->toBe(['333.33', '333.33', '333.34'])
            ->and($venta->montoPrima())->toBeMonto('1000.00');
    });

    test('primas por lote que no dan la del contrato se rechazan', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');

        expect(fn () => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$uno, $dos],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 12,
            diaPago: 5,
            precios: [
                new PrecioPactado((int) $uno->getKey(), new Monto('1400.00'), prima: new Monto('40000.00')),
                new PrecioPactado((int) $dos->getKey(), new Monto('1400.00'), prima: new Monto('40000.00')),
            ],
        ))->toThrow(VentaInvalidaException::class, 'suman');
    });
});

/*
| Con tres plazos distintos, «el saldo es demasiado chico para 600 meses»
| obliga a adivinar cual de los tres es. El mensaje del dominio se conserva
| y se le antepone el codigo.
*/
test('cuando el plan de un lote no se puede armar, dice cual lote es', function (): void {
    $uno = ($this->lote)('1');
    $dos = ($this->lote)('2');

    expect(fn () => $this->registro->activar(
        proyecto: $this->proyecto,
        lotes: [$uno, $dos],
        clientes: [$this->cliente],
        prima: new Monto('0'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [
            new PrecioPactado((int) $dos->getKey(), new Monto('1400.00'), plazoMeses: 601),
        ],
    ))->toThrow(VentaInvalidaException::class, (string) $dos->refresh()->getAttribute('codigo'));
});
