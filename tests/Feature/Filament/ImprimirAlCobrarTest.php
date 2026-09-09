<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El diálogo de impresión sale solo al cobrar — 9-sep-2026
|--------------------------------------------------------------------------
| «Al pagar debería de abrirse de una la ventana para imprimir los recibos»
| — Mauricio.
|
| 🔴 LO QUE ESTOS TESTS CUIDAN es que sea UNA sola llamada con TODOS los
| recibos. `window.olympoImprimir()` tiene un solo iframe y lo reemplaza en
| cada llamada: llamarla una vez por recibo no imprime dos papeles, imprime el
| último —o una hoja en blanco—. Es un error que en pantalla se ve como «a
| veces sale un papel solo», que es la peor forma de descubrirlo.
|
| Dos lotes de 250 vr² a L 1,400.00 con titulares de recibo DISTINTOS: así el
| cobro sale en dos papeles, que es el caso del pedido.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $uno = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
    $dos = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '2']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'MARIA EVELINA CABALLERO']);

    $condicion = static fn (Lote $l): PrecioPactado => new PrecioPactado(
        loteId: (int) $l->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: 12,
        prima: new Monto('50000.00'),
    );

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$uno, $dos],
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [$condicion($uno), $condicion($dos)],
    );

    $renglon = fn (Lote $lote): Compromiso => $this->venta
        ->compromisos()
        ->where('lote_id', '=', $lote->getKey())
        ->firstOrFail();

    $this->primerLote = $renglon($uno);
    $this->segundoLote = $renglon($dos);

    // Dos titulares distintos: dos papeles de un solo cobro.
    $this->primerLote->update(['titular_recibo' => 'JOSE ANTONIO MEJIA']);
    $this->segundoLote->update(['titular_recibo' => 'ROSA ELENA MEJIA']);

    $this->cobro = fn (): array => [
        'cobrar_'.$this->primerLote->getKey()  => true,
        'monto_'.$this->primerLote->getKey()   => '25000.00',
        'cobrar_'.$this->segundoLote->getKey() => true,
        'monto_'.$this->segundoLote->getKey()  => '25000.00',
        'forma_pago'                           => FormaDePago::Efectivo->value,
        'fecha'                                => today()->toDateString(),
    ];

    /*
     * Los recibos que NACIERON de este cobro. Por diferencia y no por un
     * filtro: `activar()` ya emitió el de la prima, y cualquier condición que
     * se invente para dejarlo afuera es una regla de más que puede cambiar.
     */
    $this->reciboNuevos = function (callable $cobrar): array {
        $antes = Recibo::query()->pluck('id')->all();

        $cobrar();

        return Recibo::query()
            ->whereNotIn('id', $antes)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    };

    // La llamada que tiene que salir, armada igual que `AbrirLaImpresion`.
    $this->llamada = static fn (string $ids): string => sprintf(
        'window.olympoImprimir && window.olympoImprimir(%s)',
        json_encode(route('documentos.recibos', ['recibos' => $ids]), JSON_THROW_ON_ERROR),
    );
});

/*
| 🔴 ESTE TEST ES EL QUE CUIDA LA LLAMADA UNICA, y no hace falta otro.
|
| Asierta la llamada EXACTA, con los dos ids adentro. Si alguien vuelve a
| mandar a imprimir de a un recibo por vez, esa llamada deja de existir y esto
| falla — sin necesidad de un test que pruebe la negación.
|
| ⚠️ Se intentó escribir ese segundo test invirtiendo `assertJs` dentro de un
| `toThrow`, y no funciona: un fallo de aserción de PHPUnit no es una excepción
| del código bajo prueba, y Pest lo deja pasar de largo. Quedaba un test que
| fallaba diciendo la verdad sobre un comportamiento correcto. No volver a
| intentarlo por ese camino.
*/
test('al cobrar se manda a imprimir sin apretar nada, y en una sola llamada', function (): void {
    $pantalla = null;

    $delCobro = ($this->reciboNuevos)(function () use (&$pantalla): void {
        $pantalla = Livewire::test(ViewVenta::class, ['record' => $this->venta->getKey()])
            ->callAction('cobrar', ($this->cobro)())
            ->assertHasNoActionErrors();
    });

    // Dos titulares, dos papeles: es el caso del pedido.
    expect($delCobro)->toHaveCount(2);

    $pantalla?->assertJs(($this->llamada)(implode(',', $delCobro)));
});
