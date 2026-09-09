<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\ImpresionDeRecibo;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Varios recibos, una sola pasada de impresora — 9-sep-2026
|--------------------------------------------------------------------------
| «Al pagar debería de abrirse de una la ventana para imprimir los recibos»
| — Mauricio, mirando cuatro notificaciones apiladas después de UN cobro.
|
| Un cobro sale en un recibo POR TITULAR, así que un contrato con varios
| representados emite varios papeles de un solo pago. Con la ruta de a uno eso
| eran cuatro clics y cuatro diálogos, con el cliente enfrente.
|
| Dos lotes de 250 vr² a L 1,400.00: L 350,000.00 cada uno, L 50,000.00 de
| prima, 12 meses. Se cobran por separado para tener DOS recibos —a la ruta le
| da igual si salieron del mismo cobro; lo que prueba es que apila lo que le
| pidan y que cada hoja cuenta como un papel.
*/

beforeEach(function (): void {
    $this->usuario = actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $uno = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
    $dos = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '2']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$uno, $dos],
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $cobrar = fn (Lote $lote): object => app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->venta->compromisos()->where('lote_id', '=', $lote->getKey())->firstOrFail(),
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->primero = $cobrar($uno);
    $this->segundo = $cobrar($dos);

    $this->papeles = fn (string $ids): object => $this->get(
        route('documentos.recibos', ['recibos' => $ids]),
    );

    $this->losDos = fn (): string => $this->primero->getKey().','.$this->segundo->getKey();
});

test('los dos recibos salen en un solo documento', function (): void {
    ($this->papeles)(($this->losDos)())
        ->assertOk()
        ->assertSee($this->primero->folio())
        ->assertSee($this->segundo->folio());
});

/*
| 🔴 UNA IMPRESION POR PAPEL, no una por pantalla.
|
| Salieron dos papeles, así que se pidieron dos. La pregunta que contesta
| `impresiones_de_recibo` es cuántas veces se pidió CADA recibo —es la que
| importa cuando aparecen dos con el mismo número—, y contarla por visita
| dejaría el segundo papel sin registrar para siempre.
*/
test('anota una impresión por cada recibo', function (): void {
    ($this->papeles)(($this->losDos)())->assertOk();

    expect(ImpresionDeRecibo::query()->where('recibo_id', $this->primero->getKey())->count())->toBe(1)
        ->and(ImpresionDeRecibo::query()->where('recibo_id', $this->segundo->getKey())->count())->toBe(1);
});

/*
| Sirve para uno solo, y eso es a propósito: quien dispara la impresión
| después de cobrar usa siempre esta ruta y no decide nada según cuántos
| papeles salieron.
*/
test('con un solo recibo sale una sola hoja', function (): void {
    ($this->papeles)((string) $this->primero->getKey())
        ->assertOk()
        ->assertSee($this->primero->folio())
        ->assertDontSee($this->segundo->folio());
});

test('sin recibos válidos no hay documento', function (): void {
    ($this->papeles)('')->assertNotFound();
    ($this->papeles)('abc, ,0')->assertNotFound();
});

/*
| 🔴🔴 LA INVARIANTE CARA: el permiso se pregunta por TODOS antes de preparar
| ninguno.
|
| Autorizar sobre la marcha dejaría anotada la impresión de los primeros y
| recién ahí cortaría — filas escritas por un documento que nunca se entregó, y
| un papel que a partir de entonces dice COPIA sin que nadie lo haya impreso.
| Un id ajeno metido a mano en la barra de direcciones no imprime nada.
*/
test('un solo recibo sin permiso tumba el documento entero y no anota nada', function (): void {
    $sinPermiso = rol('sin_recibos');
    $sinPermiso->syncPermissions(['ViewAny:Venta', 'View:Venta']);

    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($sinPermiso);

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user);

    ($this->papeles)(($this->losDos)())->assertForbidden();

    expect(ImpresionDeRecibo::query()->count())->toBe(0);
});

/*
| El orden es el de los correlativos y no el del parámetro: así se archivan y
| así se revisan. El orden de algo que alguien escribió a mano no dice nada.
*/
test('las hojas salen en el orden en que se emitieron', function (): void {
    ($this->papeles)($this->segundo->getKey().','.$this->primero->getKey())
        ->assertOk()
        ->assertSeeInOrder([$this->primero->folio(), $this->segundo->folio()]);
});
