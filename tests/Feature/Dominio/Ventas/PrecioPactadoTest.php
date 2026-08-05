<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Exceptions\CompromisoInvalidoException;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| El precio pactado — R4
|--------------------------------------------------------------------------
| «Se negocia caso por caso», contesto la contratante. Lo que aporta el
| sistema no es impedir el descuento: es que despues se pueda saber quien
| autorizo que, y cuanto.
|
| Se congelan DOS precios por lote. El de LISTA, que es lo que el lote valia
| ese dia, y el PACTADO, que es lo que se firmo. Sin los dos, medir el
| descuento un mes despues es adivinar: el precio de lista del lote cambia.
*/

beforeEach(function (): void {
    $this->registro = app(RegistroDeVentas::class);
    $this->compromisos = app(RegistroDeCompromisos::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    // 250 vr² x L 1,400.00 = L 350,000.00.
    $this->lote = Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => '1']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);
});

describe('Sin negociar', function (): void {
    test('la venta se congela al precio de lista', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 60,
            diaPago: 5,
        );

        $compromiso = Compromiso::query()
            ->where('venta_id', $venta->getKey())
            ->firstOrFail();

        expect($venta->getAttribute('valor_total'))->toBe('350000.00')
            ->and($compromiso->getAttribute('precio_vara'))->toBe('1400.00')
            ->and($compromiso->getAttribute('precio_vara_lista'))->toBe('1400.00')
            ->and($compromiso->getAttribute('motivo_descuento'))->toBeNull();
    });

    /*
    | Mandar el precio de lista explicitamente es lo que hace el formulario
    | en cada venta: no filtra, manda siempre lo que se tecleo. Tiene que
    | dar exactamente lo mismo que no mandar nada.
    */
    test('mandar el mismo precio de lista no cambia nada ni pide motivo', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 60,
            diaPago: 5,
            precios: [new PrecioPactado((int) $this->lote->getKey(), new Monto('1400.00'))],
        );

        expect($venta->getAttribute('valor_total'))->toBe('350000.00');
    });
});

describe('Vendiendo mas barato', function (): void {
    test('con motivo se graba, y el valor de la venta baja con el', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 60,
            diaPago: 5,
            precios: [new PrecioPactado(
                loteId: (int) $this->lote->getKey(),
                precioVara: new Monto('1250.00'),
                motivo: 'Compra los dos lotes de la esquina, autorizado por dona Rosa Elena.',
            )],
        );

        $compromiso = Compromiso::query()
            ->where('venta_id', $venta->getKey())
            ->firstOrFail();

        // 250 x 1250 = 312,500.00, no los 350,000.00 de la lista.
        expect($venta->getAttribute('valor_total'))->toBe('312500.00')
            ->and($venta->getAttribute('saldo_financiar'))->toBe('262500.00')
            ->and($compromiso->getAttribute('precio_vara'))->toBe('1250.00')
            ->and($compromiso->getAttribute('precio_vara_lista'))->toBe('1400.00')
            ->and($compromiso->getAttribute('valor'))->toBe('312500.00')
            ->and($compromiso->getAttribute('motivo_descuento'))->toContain('Rosa Elena');
    });

    /*
    | El lote NO se toca. Su precio de lista sigue siendo el de lista: si la
    | venta se rescinde manana, el lote vuelve a la vitrina a L 1,400.00 y no
    | al precio que se le hizo a un cliente puntual.
    */
    test('el precio de lista del lote queda intacto', function (): void {
        $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 60,
            diaPago: 5,
            precios: [new PrecioPactado(
                (int) $this->lote->getKey(),
                new Monto('1250.00'),
                'Descuento autorizado.',
            )],
        );

        expect($this->lote->fresh()?->getAttribute('precio_vara'))->toBe('1400.00');
    });

    test('sin motivo no se registra nada', function (): void {
        expect(fn () => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 60,
            diaPago: 5,
            precios: [new PrecioPactado((int) $this->lote->getKey(), new Monto('1250.00'))],
        ))->toThrow(VentaInvalidaException::class);
    });

    test('un motivo de puros espacios no es un motivo', function (): void {
        expect(fn () => $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 60,
            diaPago: 5,
            precios: [new PrecioPactado((int) $this->lote->getKey(), new Monto('1250.00'), '   ')],
        ))->toThrow(VentaInvalidaException::class);
    });

    /*
    | Lo importante de rechazar TEMPRANO: el correlativo no se quema. Un
    | numero de contrato consumido por una venta que no se concreto es un
    | hueco en la serie que despues hay que explicarle a alguien.
    */
    test('el correlativo no se consume cuando el descuento se rechaza', function (): void {
        try {
            $this->registro->activar(
                proyecto: $this->proyecto,
                lotes: [$this->lote],
                clientes: [$this->cliente],
                prima: new Monto('50000.00'),
                plazoMeses: 60,
                diaPago: 5,
                precios: [new PrecioPactado((int) $this->lote->getKey(), new Monto('1250.00'))],
            );
        } catch (VentaInvalidaException) {
            // Es lo esperado; lo que importa es lo que quedo en la base.
        }

        expect(DB::table('correlativos')->count())->toBe(0)
            ->and($this->lote->fresh()?->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    test('vender MAS caro que la lista no pide motivo', function (): void {
        $venta = $this->registro->activar(
            proyecto: $this->proyecto,
            lotes: [$this->lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 60,
            diaPago: 5,
            precios: [new PrecioPactado((int) $this->lote->getKey(), new Monto('1600.00'))],
        );

        expect($venta->getAttribute('valor_total'))->toBe('400000.00');
    });
});

describe('Apartar', function (): void {
    test('un apartado congela los dos precios iguales', function (): void {
        $compromiso = $this->compromisos->apartar($this->lote, $this->cliente, montoSenia: '5000.00');

        expect($compromiso->getAttribute('tipo'))->toBe(TipoCompromiso::Apartado)
            ->and($compromiso->getAttribute('precio_vara'))->toBe('1400.00')
            ->and($compromiso->getAttribute('precio_vara_lista'))->toBe('1400.00');
    });
});

describe('Vendiendo un lote suelto, sin expediente', function (): void {
    test('el descuento sin motivo tambien se rechaza', function (): void {
        expect(fn () => $this->compromisos->vender(
            $this->lote,
            $this->cliente,
            precioVara: new Monto('900.00'),
        ))->toThrow(CompromisoInvalidoException::class);
    });
});

/*
|--------------------------------------------------------------------------
| La base, sin pasar por el dominio
|--------------------------------------------------------------------------
| Los CHECK no son un adorno del Service: cubren el import, la consola y las
| dos pestanas abiertas. Se prueban salteando Eloquent a proposito.
*/
describe('Los CHECK de Postgres', function (): void {
    test('rechazan un precio menor al de lista sin motivo', function (): void {
        expect(fn () => DB::table('compromisos')->insert(filaCruda($this, [
            'precio_vara' => '1000.00',
            'valor'       => '250000.00',
        ])))->toThrow(QueryException::class);
    });

    test('rechazan un valor que no es su propia area por su propio precio', function (): void {
        expect(fn () => DB::table('compromisos')->insert(filaCruda($this, [
            'valor' => '999999.00',
        ])))->toThrow(QueryException::class);
    });

    test('aceptan el descuento cuando trae motivo', function (): void {
        DB::table('compromisos')->insert(filaCruda($this, [
            'precio_vara'      => '1000.00',
            'valor'            => '250000.00',
            'motivo_descuento' => 'Pago de contado, autorizado.',
        ]));

        expect(Compromiso::query()->count())->toBe(1);
    });
});

/**
 * Una fila de compromiso valida, para pisarle solo lo que cada test prueba.
 *
 * @param array<string, mixed> $cambios
 *
 * @return array<string, mixed>
 */
function filaCruda(object $contexto, array $cambios = []): array
{
    return array_merge([
        'proyecto_id'       => $contexto->proyecto->getKey(),
        'lote_id'           => $contexto->lote->getKey(),
        'cliente_id'        => $contexto->cliente->getKey(),
        'tipo'              => 'venta',
        'estado'            => 'vigente',
        'area_varas'        => '250.0000',
        'precio_vara'       => '1400.00',
        'precio_vara_lista' => '1400.00',
        'valor'             => '350000.00',
        'fecha'             => today()->toDateString(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ], $cambios);
}
