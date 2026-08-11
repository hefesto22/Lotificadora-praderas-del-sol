<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Widgets\ComoVaElNegocio;
use App\Filament\Widgets\CorteDeCajaDeHoy;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Gasto;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Support\Roles;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El Escritorio
|--------------------------------------------------------------------------
| Hasta el 8-ago-2026 tenía la bienvenida de Filament y un medidor de disco.
| Estos tests cuidan las dos cosas que un tablero de dinero puede decir mal y
| que nadie notaría: contar un recibo ANULADO, y mostrarle a un receptor el
| arqueo de otro.
|
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a financiar, que a 12 meses dan cuotas de L 25,000.00.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->pagos = app(RegistroDePagos::class);

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
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

    $this->cobrar = fn (string $monto) => $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto($monto),
        forma: FormaDePago::Efectivo,
    );
});

describe('Cómo va el negocio', function (): void {
    test('muestra lo cobrado, lo pendiente y el inventario', function (): void {
        ($this->cobrar)('25000.00');

        Livewire::test(ComoVaElNegocio::class)
            ->assertSee('Cobrado este mes')
            // La prima (L 50,000.00) más la cuota que se acaba de cobrar.
            ->assertSee('L. 75,000.00')
            ->assertSee('Por cobrar')
            ->assertSee('L. 275,000.00')
            ->assertSee('Lotes disponibles');
    });

    /*
    | El número que más fácil miente. Un recibo anulado conserva su fila y su
    | número —la serie no puede tener huecos— pero su dinero volvió a deberse:
    | sumarlo diría que entró plata que no entró.
    */
    test('un recibo anulado deja de contar como cobrado', function (): void {
        $recibo = ($this->cobrar)('25000.00');

        $this->pagos->anular($recibo, 'Se tecleó de más');

        Livewire::test(ComoVaElNegocio::class)
            ->assertSee('L. 50,000.00')      // queda solo la prima
            ->assertDontSee('L. 75,000.00');
    });

    /*
    | R2: el atraso no genera cargo, pero sí se muestra. Las cuotas se atrasan
    | a mano —es determinista y no depende del reloj—.
    */
    test('lo vencido sale de las cuotas con fecha pasada', function (): void {
        /*
         * `whereIn('numero', ...)` y NO `->limit(2)->update(...)`: Postgres
         * no acepta LIMIT en un UPDATE y Laravel no lo traduce.
         *
         * Tres cuotas de L 25,000.00 = L 75,000.00 vencidos, un número que no
         * se confunde con la prima (L 50,000.00) ni con el saldo total.
         */
        Cuota::query()
            ->where('compromiso_id', $this->renglon->getKey())
            ->whereIn('numero', [1, 2, 3])
            ->update(['fecha_vencimiento' => today()->subMonth()]);

        Livewire::test(ComoVaElNegocio::class)
            ->assertSee('Vencido a hoy')
            ->assertSee('L. 75,000.00')
            ->assertSee('en 1 expediente');
    });
});

describe('Corte de caja de hoy', function (): void {
    test('la administradora ve el total del día y quién lo cobró', function (): void {
        ($this->cobrar)('25000.00');

        Livewire::test(CorteDeCajaDeHoy::class)
            ->assertSee('Cobrado hoy')
            ->assertSee('En efectivo')
            // La prima también entró hoy y también fue en efectivo.
            ->assertSee('L. 75,000.00');
    });

    /*
    | `Roles::RECEPTOR` promete que «NO ve el arqueo de otro receptor». Hasta
    | el 8-ago no lo cumplía nada.
    */
    test('un receptor ve solo lo que cobró él', function (): void {
        $cobradoPorOtro = ($this->cobrar)('25000.00');

        $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR));

        Livewire::test(CorteDeCajaDeHoy::class)
            ->assertSee('Cobrado por vos hoy')
            ->assertSee('Todavía no se ha cobrado nada hoy')
            ->assertDontSee('L. 75,000.00');

        expect($cobradoPorOtro->getAttribute('created_by'))->not->toBeNull();
    });

    /*
    | ═══ LO QUE SALIO TAMBIEN CUENTA (11-ago-2026) ═══
    |
    | Antes del modulo de gastos el widget sumaba solo ingresos, y la frase
    | «es lo que tiene que estar en la caja» era falsa cualquier dia que se
    | pagara algo en efectivo. Se cobran L 75,000.00 —prima mas cuota— y salen
    | L 12,000.00 de planilla: en la caja tienen que quedar L 63,000.00.
    */
    test('un gasto en efectivo baja lo que tiene que estar en la caja', function (): void {
        ($this->cobrar)('25000.00');

        Gasto::factory()->de('12000.00')->create();

        Livewire::test(CorteDeCajaDeHoy::class)
            ->assertSee('Salió de la caja hoy')
            ->assertSee('L. 12,000.00')
            ->assertSee('en la caja tienen que quedar L. 63,000.00');
    });

    /*
    | Un gasto por transferencia no toca el efectivo: ese dinero nunca estuvo
    | en la gaveta. Si lo restara, quien cuenta los billetes encontraria de mas
    | y buscaria un error que no existe.
    */
    test('un gasto por transferencia no toca el efectivo de la caja', function (): void {
        ($this->cobrar)('25000.00');

        Gasto::factory()->porTransferencia()->de('12000.00')->create();

        Livewire::test(CorteDeCajaDeHoy::class)
            ->assertSee('Es lo que tiene que estar en la caja al cerrar')
            ->assertDontSee('Salió de la caja hoy');
    });

    /*
    | El receptor no registra gastos ni ve la pestaña, y su cuadro tampoco los
    | resta: el numero que el ve es lo que cobro el, no la caja de la
    | administracion.
    */
    test('al receptor no se le restan los gastos de la administración', function (): void {
        Gasto::factory()->de('12000.00')->create();

        $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR));

        Livewire::test(CorteDeCajaDeHoy::class)
            ->assertDontSee('Salió de la caja hoy')
            ->assertDontSee('L. 12,000.00');
    });
});
