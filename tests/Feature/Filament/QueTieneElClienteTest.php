<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Apartados\Pages\ListApartados;
use App\Filament\Resources\Clientes\ClienteResource;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Filament\Resources\Clientes\Pages\ViewCliente;
use App\Filament\Resources\Clientes\RelationManagers\ApartadosRelationManager;
use App\Filament\Resources\Clientes\RelationManagers\RecibosRelationManager;
use App\Filament\Resources\Clientes\RelationManagers\VentasRelationManager;
use App\Filament\Resources\Recibos\Pages\ListRecibos;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Filament\Support\ListadoDelCliente;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Qué tiene este cliente
|--------------------------------------------------------------------------
| La ficha del cliente decía quién es y no qué compró. Ahora cada contador
| del listado abre SU pantalla ya filtrada por ese cliente.
|
| Lo que se prueba acá NO es que el link exista: es que FILTRA. Un link que
| abre el listado entero se ve idéntico y contesta cualquier cosa, y el
| nombre del filtro ('cliente') es una cadena de texto que vive en dos
| archivos distintos — renombrarla en uno solo no rompe nada visible.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $lote = static fn (string $numero): Lote => Lote::factory()->enBloque($bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => $numero]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);
    $this->otro = Cliente::factory()->create(['nombre' => 'Ramon Ordonez']);

    $ventas = app(RegistroDeVentas::class);
    $compromisos = app(RegistroDeCompromisos::class);
    $pagos = app(RegistroDePagos::class);

    $this->venta = $ventas->activar(
        proyecto: $proyecto,
        lotes: [$lote('1')],
        clientes: [$this->cliente],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $this->ventaDelOtro = $ventas->activar(
        proyecto: $proyecto,
        lotes: [$lote('2')],
        clientes: [$this->otro],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $compromisos->apartar($lote('3'), $this->cliente, venceEl: today()->addDays(10)->toDateString());
    $compromisos->apartar($lote('4'), $this->otro, venceEl: today()->addDays(10)->toDateString());

    $this->recibo = $pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->venta->compromisos()->firstOrFail(),
        cliente: $this->cliente,
        monto: new Monto('5000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->reciboDelOtro = $pagos->cobrarCuotas(
        venta: $this->ventaDelOtro,
        lote: $this->ventaDelOtro->compromisos()->firstOrFail(),
        cliente: $this->otro,
        monto: new Monto('5000.00'),
        forma: FormaDePago::Efectivo,
    );
});

describe('El link abre el listado ya filtrado', function (): void {
    test('las ventas de ese cliente, y solo las de ese cliente', function (): void {
        $this->get(ListadoDelCliente::ventas($this->cliente))
            ->assertOk()
            ->assertSee((string) $this->venta->getAttribute('numero_contrato'))
            ->assertDontSee((string) $this->ventaDelOtro->getAttribute('numero_contrato'));
    });

    /*
    | 🔴 EL QUE ATA EL LINK CON LA PESTAÑA POR DEFECTO
    |
    | Desde el 22-ago Ventas abre en «Vigente». El contador de la ficha
    | cuenta TODOS los estados, así que sin el `activeTab` del link este
    | cliente vería «Ventas 1» y una pantalla vacía. Es el mismo contador
    | que miente del §9.E6, del lado del listado.
    */
    test('también la que ya se liquidó, que es la que el contador incluye', function (): void {
        $this->venta->update(['estado' => EstadoVenta::Liquidada, 'cerrada_el' => today()]);

        $this->get(ListadoDelCliente::ventas($this->cliente))
            ->assertOk()
            ->assertSee((string) $this->venta->getAttribute('numero_contrato'));
    });

    test('sus apartados', function (): void {
        $this->get(ListadoDelCliente::apartados($this->cliente))
            ->assertOk()
            ->assertDontSee('RAMON ORDONEZ');
    });

    test('sus recibos', function (): void {
        $this->get(ListadoDelCliente::recibos($this->cliente))
            ->assertOk()
            ->assertSee($this->recibo->folio())
            ->assertDontSee($this->reciboDelOtro->folio());
    });
});

/*
| §9.E6: un contador que no comparte el scoping de su listado miente. Acá se
| cuenta de dos maneras distintas —`withCount` sobre la relación del modelo y
| el `whereHas` del filtro— y se exige que den el mismo número. El día que
| alguien le agregue una condición a uno de los dos lados, este test cae.
*/
test('el contador dice lo mismo que la pantalla que abre', function (): void {
    /** @var Cliente $cliente */
    $cliente = Cliente::query()
        ->withCount(['ventas', 'apartados', 'recibos'])
        ->whereKey($this->cliente->getKey())
        ->firstOrFail();

    $ventas = (int) $cliente->getAttribute('ventas_count');
    $apartados = (int) $cliente->getAttribute('apartados_count');
    $recibos = (int) $cliente->getAttribute('recibos_count');

    // Sin esto, tres ceros contra tres listas vacías pasarían el test.
    expect([$ventas, $apartados, $recibos])->each->toBeGreaterThan(0);

    // Parado en «Todas», igual que el link real: `ListadoDelCliente::ventas()`
    // manda `activeTab` justamente porque el contador cuenta todos los
    // estados y la pantalla abre en «Vigente».
    Livewire::test(ListVentas::class)
        ->set('activeTab', ListVentas::TODAS)
        ->filterTable('cliente', $this->cliente->getKey())
        ->assertCountTableRecords($ventas);

    Livewire::test(ListApartados::class)
        ->filterTable('cliente', $this->cliente->getKey())
        ->assertCountTableRecords($apartados);

    Livewire::test(ListRecibos::class)
        ->filterTable('cliente', $this->cliente->getKey())
        ->assertCountTableRecords($recibos);
});

/*
| Un cliente archivado sigue teniendo sus ventas: el expediente no se archiva
| con él. Sin el `withoutGlobalScopes` del filtro, el contador diría «1» y la
| pantalla que abre mostraría cero.
*/
test('un cliente archivado no pierde lo suyo', function (): void {
    $this->cliente->delete();

    $this->get(ListadoDelCliente::ventas($this->cliente))
        ->assertOk()
        ->assertSee((string) $this->venta->getAttribute('numero_contrato'));
});

test('la ficha del cliente lleva los tres atajos', function (): void {
    $this->get(ClienteResource::getUrl('view', ['record' => $this->cliente]))
        ->assertOk()
        ->assertSee(ListadoDelCliente::ventas($this->cliente))
        ->assertSee(ListadoDelCliente::apartados($this->cliente))
        ->assertSee(ListadoDelCliente::recibos($this->cliente));
});

/*
| §13.5: quien no puede entrar a Ventas tampoco tiene por qué enterarse de
| cuántas hay. El contador y el atajo desaparecen juntos porque la condición
| está escrita una sola vez, en ListadoDelCliente.
*/
test('el atajo aparece solo con el permiso', function (): void {
    Livewire::test(ListClientes::class)
        ->assertSuccessful()
        ->assertSee(ListadoDelCliente::ventas($this->cliente));

    $soloClientes = rol('rol_de_prueba_solo_clientes');
    $soloClientes->syncPermissions(['ViewAny:Cliente', 'View:Cliente']);

    /** @var User $user */
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($soloClientes);

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user);

    Livewire::test(ListClientes::class)
        ->assertSuccessful()
        ->assertDontSee(ListadoDelCliente::ventas($this->cliente));
});

/*
|--------------------------------------------------------------------------
| Cuánto debe
|--------------------------------------------------------------------------
| Los contadores dicen cuántos papeles hay; el saldo dice la plata. Sale de
| la misma subconsulta en los dos lados —la columna del listado y la ficha—
| y con la misma regla que el «Por cobrar» del Escritorio.
|
| Los dos clientes compraron exactamente lo mismo a propósito: si la
| subconsulta se olvidara de correlacionar contra el cliente, el número
| saldría el doble, y ese es el error más caro de toda esta tanda.
*/
test('el saldo suma lo de ese cliente y nada mas', function (): void {
    // 250 vr² a L 1,400.00 son L 350,000.00; menos L 50,000.00 de prima y
    // menos los L 5,000.00 que ya pagó.
    expect($this->cliente->saldoPendiente())->toBeMonto('295000.00');
});

test('un expediente rescindido deja de sumar', function (): void {
    $this->venta->update([
        'estado'     => EstadoVenta::Rescindida,
        'cerrada_el' => today(),
        'motivo'     => 'Prueba del saldo',
    ]);

    expect($this->cliente->saldoPendiente())->toBeMonto('0.00');
});

/*
| El listado no llama a `saldoPendiente()` fila por fila —eso sería un N+1—,
| sino que mete la misma subconsulta en el `addSelect`. Este test existe para
| que los dos caminos no se separen: si alguno cambia, el número de la
| pantalla deja de coincidir con el de la ficha.
*/
test('la columna Debe muestra el mismo numero que la ficha', function (): void {
    Livewire::test(ListClientes::class)
        ->assertSuccessful()
        ->assertSee($this->cliente->saldoPendiente()->formateado());
});

/*
|--------------------------------------------------------------------------
| Las tres tablas adentro de la ficha
|--------------------------------------------------------------------------
| Ninguna redefine columnas: cada relation manager declara su
| `$relatedResource` y Filament aplica la tabla de esa pantalla tal cual.
|
| Estos tests existen sobre todo para EJECUTAR esa maquinaria. PHPStan no ve
| nada de eso —es resolución en tiempo de ejecución adentro de Filament—, así
| que si una versión cambia cómo trata `$relatedResource`, la ficha se rompe
| en silencio y esto es lo único que lo dice antes que la contratante.
*/
describe('Las pestañas del expediente', function (): void {
    $enLaFicha = static fn (string $manager, Cliente $cliente): mixed => Livewire::test($manager, [
        'ownerRecord' => $cliente,
        'pageClass'   => ViewCliente::class,
    ]);

    test('cada una muestra lo de este cliente y nada del otro', function () use ($enLaFicha): void {
        $enLaFicha(VentasRelationManager::class, $this->cliente)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->venta])
            ->assertCanNotSeeTableRecords([$this->ventaDelOtro]);

        $enLaFicha(RecibosRelationManager::class, $this->cliente)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->recibo])
            ->assertCanNotSeeTableRecords([$this->reciboDelOtro]);
    });

    /*
    | El cliente tiene UN apartado y UN lote vendido, y los dos son
    | compromisos. Si la relación se olvidara de filtrar por tipo, acá
    | saldrían dos filas y la pestaña diría que tiene reservado un lote que ya
    | pagó.
    */
    test('apartados nunca muestra un lote ya vendido', function () use ($enLaFicha): void {
        $enLaFicha(ApartadosRelationManager::class, $this->cliente)
            ->assertSuccessful()
            ->assertCountTableRecords(1);
    });

    /*
    | El numero de la solapa y las filas de su tabla salen de dos caminos
    | distintos: un `->count()` sobre la relacion del modelo, y la consulta
    | que arma Filament a partir del `$relatedResource`. Este test los cruza
    | en vez de compararlos contra un numero escrito a mano.
    |
    | La primera version SI llevaba el numero a mano y decia «1 recibo». Son
    | DOS: `RegistroDeVentas::activar()` emite su propio recibo por la prima,
    | ademas del que sale al cobrar una cuota. Un numero esperado escrito a
    | mano en un test es una suposicion escrita a mano.
    */
    test('el numero de la solapa dice lo mismo que su tabla', function () use ($enLaFicha): void {
        $ventas = VentasRelationManager::getBadge($this->cliente, ViewCliente::class);
        $apartados = ApartadosRelationManager::getBadge($this->cliente, ViewCliente::class);
        $recibos = RecibosRelationManager::getBadge($this->cliente, ViewCliente::class);

        // Sin esto, tres solapas peladas contra tres tablas vacias pasarian.
        expect($ventas)->not->toBeNull()
            ->and($apartados)->not->toBeNull()
            ->and($recibos)->not->toBeNull();

        $enLaFicha(VentasRelationManager::class, $this->cliente)->assertCountTableRecords((int) $ventas);
        $enLaFicha(ApartadosRelationManager::class, $this->cliente)->assertCountTableRecords((int) $apartados);
        $enLaFicha(RecibosRelationManager::class, $this->cliente)->assertCountTableRecords((int) $recibos);
    });

    // Un cero permanente se vuelve parte del decorado: la solapa va pelada.
    test('la solapa de un cliente sin nada no muestra un cero', function (): void {
        $reciente = Cliente::factory()->create(['nombre' => 'Recien Registrada']);

        expect(VentasRelationManager::getBadge($reciente, ViewCliente::class))->toBeNull()
            ->and(ApartadosRelationManager::getBadge($reciente, ViewCliente::class))->toBeNull()
            ->and(RecibosRelationManager::getBadge($reciente, ViewCliente::class))->toBeNull();
    });

    // Tres tablas debajo del formulario donde se corrige un teléfono son ruido.
    test('no cuelgan del formulario de edicion', function (): void {
        expect(VentasRelationManager::canViewForRecord($this->cliente, ViewCliente::class))->toBeTrue()
            ->and(VentasRelationManager::canViewForRecord($this->cliente, EditCliente::class))->toBeFalse()
            ->and(ApartadosRelationManager::canViewForRecord($this->cliente, EditCliente::class))->toBeFalse()
            ->and(RecibosRelationManager::canViewForRecord($this->cliente, EditCliente::class))->toBeFalse();
    });
});
