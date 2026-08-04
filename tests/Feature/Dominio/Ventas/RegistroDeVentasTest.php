<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Activacion de una venta — R5, R7, R8, R9
|--------------------------------------------------------------------------
| Es la transaccion mas cara del sistema: consume un correlativo que no se
| puede repetir, congela un plan de pagos y mueve lotes. O pasa entera o no
| pasa nada.
*/

beforeEach(function (): void {
    $this->registro = app(RegistroDeVentas::class);
    $this->compromisos = app(RegistroDeCompromisos::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    // 250 vr² x L 1,400.00 = L 350,000.00, el lote del golden test §9.C9.
    $this->lote = Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => '1']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);
    $this->esposo = Cliente::factory()->create(['nombre' => 'Carlos Medina']);
    $this->otro = Cliente::factory()->create(['nombre' => 'Marta Fuentes']);
});

describe('La venta que nace', function (): void {
    test('queda vigente, numerada y con su expediente', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        expect($venta->getAttribute('estado'))->toBe(EstadoVenta::Vigente)
            ->and($venta->getAttribute('numero_expediente'))->toBe(1)
            ->and($venta->getAttribute('numero_contrato'))->toBe('RPS-'.today()->year.'-0001')
            ->and($venta->getAttribute('fecha_contrato'))->not->toBeNull();
    });

    /*
    | El §8.2: el valor que vale para una venta es el congelado. Se suma lo
    | que dice cada lote HOY, no lo que diga el precio de lista manana.
    */
    test('congela area y valor sumando los lotes', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        expect($venta->getAttribute('area_total'))->toBe('250.0000')
            ->and($venta->montoValorTotal())->toBeMonto('350000.00')
            ->and($venta->montoPrima())->toBeMonto('100000.00')
            ->and($venta->montoSaldoFinanciar())->toBeMonto('250000.00');
    });

    /*
    | El golden test del §9.C9, ahora de punta a punta contra la base.
    */
    test('escribe el plan de cuotas completo y cerrado al centimo', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        $cuotas = $venta->cuotas()->get();

        expect($cuotas)->toHaveCount(72)
            ->and($venta->montoCuotaMensual())->toBeMonto('3472.22')
            ->and($cuotas->first()?->getAttribute('monto'))->toBe('3472.22')
            ->and($cuotas->last()?->getAttribute('monto'))->toBe('3472.38')
            ->and($venta->saldoPendiente())->toBeMonto('250000.00');
    });

    test('la primera cuota vence el dia de pago del mes siguiente', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 12,
            diaPago: 15,
        );

        $primera = $venta->cuotas()->first();
        $esperada = today()->addMonthNoOverflow()->startOfMonth()->day(15);

        expect($primera?->getAttribute('fecha_vencimiento')->toDateString())
            ->toBe($esperada->toDateString());
    });

    test('marca el lote como vendido y le liga su expediente', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        $compromiso = Compromiso::query()->where('lote_id', $this->lote->getKey())->vigentes()->first();

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido)
            ->and($compromiso?->getAttribute('tipo'))->toBe(TipoCompromiso::Venta)
            ->and($compromiso?->getAttribute('venta_id'))->toBe($venta->getKey())
            ->and($compromiso?->getAttribute('valor'))->toBe('350000.00')
            ->and($venta->compromisos()->count())->toBe(1);
    });
});

describe('Copropietarios y varios lotes', function (): void {
    /*
    | R8: marido y mujer o socios van los dos en el contrato, y uno es el
    | titular. La base impide que haya dos titulares; que haya al menos uno
    | lo impone este Service.
    */
    test('el primer cliente queda como titular y el resto no', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente, $this->esposo],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        expect($venta->clientes()->count())->toBe(2)
            ->and($venta->titular()?->getKey())->toBe($this->cliente->getKey());

        $titulares = DB::table('venta_cliente')
            ->where('venta_id', $venta->getKey())
            ->where('titular', true)
            ->count();

        expect($titulares)->toBe(1);
    });

    /*
    | R9: un cliente puede comprar dos o tres lotes juntos en un solo
    | contrato. El valor de la venta es la suma de los valores congelados.
    */
    test('un solo contrato puede llevar varios lotes', function (): void {
        $segundo = Lote::factory()->enBloque($this->bloque)
            ->conMedidas('250.0000', '1400.00')
            ->create(['numero' => '2']);

        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote, $segundo],
            clientes: [$this->cliente],
            prima: new Monto('200000.00'),
            plazoMeses: 60,
            diaPago: 5,
        );

        expect($venta->montoValorTotal())->toBeMonto('700000.00')
            ->and($venta->getAttribute('area_total'))->toBe('500.0000')
            ->and($venta->compromisos()->count())->toBe(2)
            ->and($segundo->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido)
            ->and($venta->saldoPendiente())->toBeMonto('500000.00');
    });
});

describe('Apartados', function (): void {
    /*
    | R14: el apartado del mismo cliente se convierte, y su monto cuenta
    | como parte de la prima. Aca se prueba la conversion; la aplicacion del
    | monto a la prima es del modulo de pagos.
    */
    test('convierte el apartado del mismo cliente', function (): void {
        $apartado = $this->compromisos->apartar($this->lote, $this->cliente, montoSenia: '5000.00');

        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote->refresh()],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        expect($apartado->refresh()->getAttribute('estado'))->toBe(EstadoCompromiso::Convertido)
            ->and($apartado->getAttribute('venta_id'))->toBeNull()
            ->and(Compromiso::query()->vigentes()->count())->toBe(1)
            ->and($venta->compromisos()->count())->toBe(1);
    });

    test('no le vende por encima del apartado de otra persona', function (): void {
        $this->compromisos->apartar($this->lote, $this->otro);

        expect(fn (): Venta => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote->refresh()],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        ))->toThrow(VentaInvalidaException::class);

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado);
    });
});

describe('Lo que rechaza', function (): void {
    test('una venta sin lotes', function (): void {
        expect(fn (): Venta => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [],
            clientes: [$this->cliente],
            prima: Monto::cero(),
            plazoMeses: 12,
            diaPago: 5,
        ))->toThrow(VentaInvalidaException::class, 'no tiene lotes');
    });

    test('una venta sin clientes', function (): void {
        expect(fn (): Venta => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [],
            prima: Monto::cero(),
            plazoMeses: 12,
            diaPago: 5,
        ))->toThrow(VentaInvalidaException::class, 'a nombre de quien');
    });

    test('el mismo lote dos veces en la misma venta', function (): void {
        expect(fn (): Venta => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote, $this->lote],
            clientes: [$this->cliente],
            prima: Monto::cero(),
            plazoMeses: 12,
            diaPago: 5,
        ))->toThrow(VentaInvalidaException::class, 'dos veces');
    });

    /*
    | El numero de contrato sale del codigo del proyecto, asi que una venta
    | no puede mezclar lotes de dos desarrollos.
    */
    test('un lote de otro proyecto', function (): void {
        $otroProyecto = Proyecto::factory()->create(['codigo' => 'VVE']);
        $otroBloque = Bloque::factory()->create([
            'proyecto_id' => $otroProyecto->getKey(),
            'nombre'      => 'A',
        ]);
        $ajeno = Lote::factory()->enBloque($otroBloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

        expect(fn (): Venta => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote, $ajeno],
            clientes: [$this->cliente],
            prima: Monto::cero(),
            plazoMeses: 12,
            diaPago: 5,
        ))->toThrow(VentaInvalidaException::class, 'otro proyecto');
    });

    test('una prima mayor que el valor de los lotes', function (): void {
        expect(fn (): Venta => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('400000.00'),
            plazoMeses: 12,
            diaPago: 5,
        ))->toThrow(VentaInvalidaException::class);
    });

    test('un lote ya vendido', function (): void {
        $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        expect(fn (): Venta => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote->refresh()],
            clientes: [$this->otro],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        ))->toThrow(VentaInvalidaException::class);
    });
});

describe('Todo o nada', function (): void {
    /*
    | El correlativo se consume DESPUES de validar y de armar el plan. Una
    | venta rechazada no deja un hueco en la serie que despues haya que
    | explicarle a alguien.
    */
    test('una venta rechazada no quema el numero de contrato', function (): void {
        $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        $segundo = Lote::factory()->enBloque($this->bloque)
            ->conMedidas('250.0000', '1400.00')
            ->create(['numero' => '2']);

        // Prima imposible: revienta antes de tocar el correlativo.
        try {
            $this->registro->activar(
                proyecto: $this->proyecto,
                lotes: [$segundo],
                clientes: [$this->otro],
                prima: new Monto('900000.00'),
                plazoMeses: 72,
                diaPago: 5,
            );
        } catch (VentaInvalidaException) {
            // Esperado.
        }

        $tercera = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$segundo->refresh()],
            clientes: [$this->otro],
            prima: new Monto('100000.00'),
            plazoMeses: 72,
            diaPago: 5,
        );

        expect($tercera->getAttribute('numero_expediente'))->toBe(2)
            ->and($tercera->getAttribute('numero_contrato'))->toBe('RPS-'.today()->year.'-0002');
    });

    test('una venta rechazada no deja lotes movidos ni cuotas sueltas', function (): void {
        try {
            $this->registro->activar(
                proyecto: $this->proyecto,
                lotes: [$this->lote],
                clientes: [$this->cliente],
                prima: new Monto('900000.00'),
                plazoMeses: 72,
                diaPago: 5,
            );
        } catch (VentaInvalidaException) {
            // Esperado.
        }

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and(Venta::query()->count())->toBe(0)
            ->and(Cuota::query()->count())->toBe(0)
            ->and(Compromiso::query()->count())->toBe(0);
    });
});

describe('Venta de contado', function (): void {
    test('no genera cuotas cuando la prima cubre el valor', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('350000.00'),
            plazoMeses: 0,
            diaPago: 5,
        );

        expect($venta->cuotas()->count())->toBe(0)
            ->and($venta->getAttribute('cuota_mensual'))->toBeNull()
            ->and($venta->esDeContado())->toBeTrue()
            ->and($venta->montoSaldoFinanciar())->toBeMonto('0.00')
            ->and($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });
});
