<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\FormaDePago;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\Pages\ViewProyecto;
use App\Filament\Resources\Proyectos\RelationManagers\GastosRelationManager;
use App\Filament\Resources\Proyectos\Schemas\GastoForm;
use App\Filament\Resources\Proyectos\Tables\ProyectosTable;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Socio;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Los botones de la lista de proyectos — 8-oct-2026
|--------------------------------------------------------------------------
| Lo pidió el cliente:
|
|  1. «Un botón para registrar gastos en cada uno de los proyectos, para
|     registrarlo más rápido.» El formulario es el MISMO de la pestaña Gastos
|     (`GastoForm`) y el alta pasa por `RegistroDeGastos`.
|  2. «Uno para compartir el link del proyecto si está activo el plano; si no
|     está activo, no se muestra.»
|  3. «Lo más importante: al registrar un gasto, registrar a quién de los
|     socios se le carga.»
*/

beforeEach(function (): void {
    Storage::fake('local');

    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS', 'nombre' => 'PRADERAS DEL SOL']);

    $this->elder = Socio::factory()->delProyecto($this->proyecto)->conParte('66.67')->create(['nombre' => 'ELDER DIONEL']);
    Socio::factory()->delProyecto($this->proyecto)->conParte('33.33')->create(['nombre' => 'EDWIN ADONAY']);

    $this->datos = fn (array $extra = []): array => array_merge([
        'socio_id'    => GastoForm::AL_PROYECTO,
        'fecha'       => today()->toDateString(),
        'categoria'   => CategoriaDeGasto::Comisiones->value,
        'descripcion' => 'Comisión de venta del lote B-12',
        'monto'       => '25000.00',
        'forma_pago'  => FormaDePago::Efectivo->value,
    ], $extra);

    $this->enLaFila = fn (string $accion): TestAction => TestAction::make($accion)->table($this->proyecto);
});

describe('Registrar un gasto desde la lista', function (): void {
    test('se registra sin entrar al proyecto, con su número de comprobante', function (): void {
        Livewire::test(ListProyectos::class)
            ->callAction(($this->enLaFila)('gasto'), ($this->datos)())
            ->assertHasNoActionErrors();

        $gasto = Gasto::query()->sole();

        expect($gasto->getAttribute('proyecto_id'))->toBe($this->proyecto->getKey())
            ->and($gasto->folio())->toBe('G-000001')
            ->and($gasto->monto())->toBeMonto('25000.00')
            // «Al proyecto» se guarda como NULL: lo de siempre.
            ->and($gasto->getAttribute('socio_id'))->toBeNull()
            ->and($gasto->esDeUnSocio())->toBeFalse();
    });

    test('y se le puede cargar a un socio', function (): void {
        Livewire::test(ListProyectos::class)
            ->callAction(($this->enLaFila)('gasto'), ($this->datos)(['socio_id' => (string) $this->elder->getKey()]))
            ->assertHasNoActionErrors();

        $gasto = Gasto::query()->sole();

        expect($gasto->getAttribute('socio_id'))->toBe($this->elder->getKey())
            ->and($gasto->esDeUnSocio())->toBeTrue();
    });

    test('quien no puede registrar gastos no ve el botón', function (): void {
        $soloMira = rol('solo_mira');
        $soloMira->syncPermissions(['ViewAny:Proyecto', 'View:Proyecto', 'ViewAny:Gasto', 'View:Gasto']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($soloMira);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        Livewire::test(ListProyectos::class)->assertActionHidden(($this->enLaFila)('gasto'));
    });
});

describe('A quién se le carga, desde la pestaña Gastos', function (): void {
    test('la pestaña usa el mismo formulario y también carga a un socio', function (): void {
        Livewire::test(GastosRelationManager::class, [
            'ownerRecord' => $this->proyecto,
            'pageClass'   => ViewProyecto::class,
        ])
            ->callAction(TestAction::make('create')->table(), ($this->datos)(['socio_id' => (string) $this->elder->getKey()]))
            ->assertHasNoActionErrors();

        expect(Gasto::query()->sole()->getAttribute('socio_id'))->toBe($this->elder->getKey());
    });

    // Una tarjeta por opción: el nombre arriba, su parte del reparto abajo.
    test('las opciones son el proyecto y cada socio con su parte', function (): void {
        expect(array_values(GastoForm::aQuienSeLeCarga($this->proyecto)))->toBe([
            'Al proyecto',
            'ELDER DIONEL',
            'EDWIN ADONAY',
        ])
            ->and(array_values(GastoForm::comoSeReparte($this->proyecto)))->toBe([
                'Lo pagan todos, según su parte',
                '66.67 % del reparto',
                '33.33 % del reparto',
            ]);
    });

    /*
    | Sin socios no hay a quién cargarle nada, pero la pregunta se ve igual
    | (8-oct-2026: escondida, nadie sabía que existía). Ofrece solo «Al
    | proyecto», y el gasto se registra como siempre.
    */
    test('un proyecto sin socios solo ofrece «Al proyecto» y registra igual', function (): void {
        $solo = Proyecto::factory()->create(['codigo' => 'RRB']);

        expect(GastoForm::aQuienSeLeCarga($solo))->toBe([GastoForm::AL_PROYECTO => 'Al proyecto']);

        // Y dice dónde se cargan: la pestaña «Socios» de ese proyecto.
        expect(GastoForm::dondeSeCarganLosSocios($solo))
            ->toContain(sprintf('/proyectos/%d/edit', $solo->getKey()))
            ->toContain('tab=socios');

        Livewire::test(ListProyectos::class)
            ->callAction(TestAction::make('gasto')->table($solo), ($this->datos)())
            ->assertHasNoActionErrors();

        expect(Gasto::query()->sole()->getAttribute('socio_id'))->toBeNull();
    });

    /*
    | Al editar un gasto cargado a un socio que después salió, el botón tiene
    | que seguir teniendo qué marcar. Si no, guardar el formulario le cambiaría
    | en silencio a quién se le carga.
    */
    test('al editar, el socio que ya salió sigue entre las opciones', function (): void {
        $gasto = Gasto::factory()->cargadoA($this->elder)->create();
        $this->elder->update(['activo' => false]);

        $opciones = GastoForm::aQuienSeLeCarga($this->proyecto, $gasto);

        expect($opciones[(int) $this->elder->getKey()] ?? null)->toBe('ELDER DIONEL')
            ->and(GastoForm::comoSeReparte($this->proyecto, $gasto)[(int) $this->elder->getKey()] ?? null)->toBe('Ya no participa del reparto')
            // Para un gasto NUEVO, el que salió ya no se ofrece.
            ->and(array_key_exists((int) $this->elder->getKey(), GastoForm::aQuienSeLeCarga($this->proyecto)))->toBeFalse();
    });
});

describe('Compartir el plano', function (): void {
    test('no se muestra si el plano no está publicado', function (): void {
        $this->proyecto->update(['plano_publico' => false]);

        Livewire::test(ListProyectos::class)->assertActionHidden(($this->enLaFila)('compartir'));
    });

    test('se muestra cuando el plano está publicado y el proyecto activo', function (): void {
        $this->proyecto->update(['plano_publico' => true]);

        Livewire::test(ListProyectos::class)->assertActionVisible(($this->enLaFila)('compartir'));
    });

    // El controlador da 404 a un proyecto inactivo: compartir ese enlace sería
    // mandarle al cliente una página de error.
    test('no se muestra si el proyecto está inactivo, aunque el plano esté publicado', function (): void {
        $this->proyecto->update(['plano_publico' => true, 'activo' => false]);

        expect($this->proyecto->refresh()->enlaceDelPlanoPublico())->toBeNull();

        Livewire::test(ListProyectos::class)->assertActionHidden(($this->enLaFila)('compartir'));
    });

    test('el enlace es el del plano público, y WhatsApp lo lleva escrito', function (): void {
        $this->proyecto->update(['plano_publico' => true]);
        $proyecto = $this->proyecto->refresh();

        $enlace = route('plano.publico', ['slug' => $proyecto->getAttribute('slug')]);

        expect($proyecto->enlaceDelPlanoPublico())->toBe($enlace)
            ->and(ProyectosTable::enlaceDeWhatsapp($proyecto))->toStartWith('https://wa.me/?text=')
            ->and(ProyectosTable::enlaceDeWhatsapp($proyecto))->toContain(rawurlencode($enlace));
    });
});
