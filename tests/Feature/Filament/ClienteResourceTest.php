<?php

declare(strict_types=1);

use App\Filament\Resources\Clientes\ClienteResource;
use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Models\Cliente;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Estos tests existen sobre todo para EJECUTAR el Resource.
|
| PHPStan verifica tipos, no firmas de runtime: si ->searchable(query:),
| TrashedFilter o Placeholder::content() cambiaron en Filament v5, el error
| aparece recién al renderizar. Montar cada page es lo único que lo detecta
| antes de que lo vea la contratante.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('ClienteResource — las paginas renderizan', function (): void {
    test('el listado carga', function (): void {
        actingAsAdmin();
        Cliente::factory()->count(3)->create();

        $this->get(ClienteResource::getUrl('index'))->assertOk();
    });

    test('la pagina de creacion carga', function (): void {
        actingAsAdmin();

        $this->get(ClienteResource::getUrl('create'))->assertOk();
    });

    test('la pagina de edicion carga', function (): void {
        actingAsAdmin();
        $cliente = Cliente::factory()->create();

        $this->get(ClienteResource::getUrl('edit', ['record' => $cliente]))->assertOk();
    });

    test('la vista de detalle carga', function (): void {
        actingAsAdmin();
        $cliente = Cliente::factory()->create();

        $this->get(ClienteResource::getUrl('view', ['record' => $cliente]))->assertOk();
    });

    test('la tabla lista los clientes', function (): void {
        actingAsAdmin();
        $clientes = Cliente::factory()->count(3)->create();

        Livewire::test(ListClientes::class)
            ->assertCanSeeTableRecords($clientes)
            ->assertOk();
    });

    /*
    | El buscador limpia los guiones antes de consultar: la gente teclea el
    | DNI como lo lee del carnet, pero en la base vive sin separadores.
    */
    test('se puede buscar por DNI con guiones', function (): void {
        actingAsAdmin();
        $buscado = Cliente::factory()->create(['dni' => '0801198501234', 'rtn' => null]);
        $otro = Cliente::factory()->create(['dni' => '0501199907777', 'rtn' => null]);

        Livewire::test(ListClientes::class)
            ->searchTable('0801-1985-01234')
            ->assertCanSeeTableRecords([$buscado])
            ->assertCanNotSeeTableRecords([$otro]);
    });
});

describe('ClienteResource — formulario', function (): void {
    test('crea un cliente desde el panel', function (): void {
        actingAsAdmin();

        Livewire::test(CreateCliente::class)
            ->fillForm([
                'nombre'   => 'Rosa Elena Fuentes',
                'dni'      => '0801198501234',
                'telefono' => '99887766',
                'correo'   => 'rosa@ejemplo.com',
                'activo'   => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Cliente::query()->where('dni', '0801198501234')->exists())->toBeTrue();
    });

    test('crea un cliente sin DNI ni RTN', function (): void {
        actingAsAdmin();

        Livewire::test(CreateCliente::class)
            ->fillForm([
                'nombre'   => 'Cliente De Apartado',
                'telefono' => '33445566',
                'activo'   => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Cliente::query()->where('nombre', 'Cliente De Apartado')->exists())->toBeTrue();
    });

    test('rechaza un DNI de menos de 13 digitos', function (): void {
        actingAsAdmin();

        Livewire::test(CreateCliente::class)
            ->fillForm(['nombre' => 'Prueba', 'dni' => '08011985'])
            ->call('create')
            ->assertHasFormErrors(['dni']);
    });

    test('rechaza un DNI duplicado', function (): void {
        actingAsAdmin();
        Cliente::factory()->create(['dni' => '0801198501234', 'rtn' => null]);

        Livewire::test(CreateCliente::class)
            ->fillForm(['nombre' => 'Otra Persona', 'dni' => '0801198501234'])
            ->call('create')
            ->assertHasFormErrors(['dni']);
    });

    /*
    | La regla unique de Laravel no sabe de soft deletes: sin el
    | whereNull('deleted_at') del ClienteForm, el formulario diría "ya
    | existe" por un cliente archivado que la persona no puede ver, mientras
    | la base lo habría aceptado. Validación y base tienen que decir lo mismo.
    */
    test('acepta el DNI de un cliente archivado', function (): void {
        actingAsAdmin();
        $archivado = Cliente::factory()->create(['dni' => '0801198501234', 'rtn' => null]);
        $archivado->delete();

        Livewire::test(CreateCliente::class)
            ->fillForm(['nombre' => 'La Misma Persona De Nuevo', 'dni' => '0801198501234'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Cliente::query()->where('dni', '0801198501234')->count())->toBe(1);
    });

    test('edita un cliente existente', function (): void {
        actingAsAdmin();
        $cliente = Cliente::factory()->create(['telefono' => '99887766']);

        Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
            ->fillForm(['telefono' => '33445566'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($cliente->fresh()?->getAttribute('telefono'))->toBe('33445566');
    });

    test('el correo se normaliza a minusculas al guardar desde el panel', function (): void {
        actingAsAdmin();

        Livewire::test(CreateCliente::class)
            ->fillForm([
                'nombre' => 'Mayusculas Correo',
                'correo' => 'Rosa.ELENA@Gmail.COM',
                'activo' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Cliente::query()->where('nombre', 'Mayusculas Correo')->value('correo'))
            ->toBe('rosa.elena@gmail.com');
    });
});

describe('ClienteResource — permisos (§9.E.1)', function (): void {
    test('un panel_user sin permisos no ve el listado', function (): void {
        actingAsPanelUser();

        $this->get(ClienteResource::getUrl('index'))->assertForbidden();
    });

    test('un panel_user sin permisos no puede abrir la ficha de un cliente', function (): void {
        actingAsPanelUser();
        $cliente = Cliente::factory()->create();

        $this->get(ClienteResource::getUrl('view', ['record' => $cliente]))->assertForbidden();
    });
});
