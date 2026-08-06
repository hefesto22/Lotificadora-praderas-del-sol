<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDeImpresiones;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\ImpresionDeRecibo;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Cada salida impresa queda anotada — R12
|--------------------------------------------------------------------------
| Un recibo no se edita: se anula y se emite otro. Pero reimprimirlo es
| legítimo, y el problema es que dos papeles con el mismo número pueden
| hacerse pasar por dos cobros. El original sale limpio, lo demás dice COPIA,
| y de las dos queda quién y cuándo.
*/

beforeEach(function (): void {
    $this->usuario = actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
    $cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote],
        clientes: [$cliente],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $this->recibo = app(RegistroDePagos::class)->cobrarCuotas(
        venta: $venta,
        lote: $venta->compromisos()->firstOrFail(),
        cliente: $cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->impresiones = app(RegistroDeImpresiones::class);
});

test('la primera impresión es el original', function (): void {
    $impresion = $this->impresiones->registrar($this->recibo);

    expect((int) $impresion->getAttribute('numero_de_impresion'))->toBe(1)
        ->and($impresion->esCopia())->toBeFalse()
        ->and($this->recibo->refresh()->yaSeImprimio())->toBeTrue();
});

test('de la segunda en adelante son copias, numeradas', function (): void {
    $this->impresiones->registrar($this->recibo);
    $segunda = $this->impresiones->registrar($this->recibo);
    $tercera = $this->impresiones->registrar($this->recibo);

    expect($segunda->esCopia())->toBeTrue()
        ->and((int) $segunda->getAttribute('numero_de_impresion'))->toBe(2)
        ->and((int) $tercera->getAttribute('numero_de_impresion'))->toBe(3)
        ->and($this->recibo->refresh()->vecesImpreso())->toBe(3);
});

/*
| El quién y el cuándo son la mitad del punto: con dos papeles sobre un
| mostrador, la historia completa es el desempate.
*/
test('queda quién imprimió cada una', function (): void {
    $impresion = $this->impresiones->registrar($this->recibo);

    expect((int) $impresion->getAttribute('created_by'))->toBe((int) $this->usuario->getKey())
        ->and($impresion->getAttribute('created_at'))->not->toBeNull();
});

test('cada recibo cuenta sus impresiones por separado', function (): void {
    $otro = app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->recibo->venta()->firstOrFail(),
        lote: $this->recibo->compromiso()->firstOrFail(),
        cliente: $this->recibo->cliente()->firstOrFail(),
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->impresiones->registrar($this->recibo);
    $this->impresiones->registrar($this->recibo);
    $primeraDelOtro = $this->impresiones->registrar($otro);

    // El segundo recibo arranca de cero: su original es su original.
    expect((int) $primeraDelOtro->getAttribute('numero_de_impresion'))->toBe(1)
        ->and($primeraDelOtro->esCopia())->toBeFalse()
        ->and(ImpresionDeRecibo::query()->count())->toBe(3);
});

/*
| El índice único `(recibo_id, numero_de_impresion)` es la red por si el
| bloqueo del Service alguna vez no alcanza: dos filas no pueden decir las dos
| que son el original.
*/
test('la base no admite dos originales del mismo recibo', function (): void {
    $this->impresiones->registrar($this->recibo);

    expect(fn () => ImpresionDeRecibo::query()->create([
        'recibo_id'           => $this->recibo->getKey(),
        'numero_de_impresion' => 1,
    ]))->toThrow(QueryException::class);
});

/*
| `User` usa SoftDeletes, así que dar de baja a alguien NO dispara el
| `nullOnDelete` de la clave: la fila del usuario sigue ahí. Lo que sí pasa es
| que la relación deja de resolverlo —Eloquent excluye los borrados— y la
| pantalla muestra «usuario dado de baja» en vez de un nombre.
|
| Este test existía al revés y falló: daba por hecho que `delete()` borra.
*/
test('dar de baja a un usuario no borra el rastro de lo que imprimió', function (): void {
    $impresion = $this->impresiones->registrar($this->recibo);

    $this->usuario->delete();

    $impresion->refresh();

    expect((int) $impresion->getAttribute('created_by'))->toBe((int) $this->usuario->getKey())
        ->and($impresion->createdBy()->first())->toBeNull()
        ->and(ImpresionDeRecibo::query()->count())->toBe(1);
});

/*
| Y si algún día se borra de verdad, la impresión queda igual: el papel no
| deja de haber existido porque el usuario se haya ido. Eso es el
| `nullOnDelete`, y la alternativa —borrar la impresión en cascada— dejaría un
| recibo con dos copias circulando y sin historia que lo explique.
*/
test('borrar un usuario de verdad tampoco borra la impresión', function (): void {
    $impresion = $this->impresiones->registrar($this->recibo);

    $this->usuario->forceDelete();

    expect($impresion->refresh()->getAttribute('created_by'))->toBeNull()
        ->and(ImpresionDeRecibo::query()->count())->toBe(1);
});
