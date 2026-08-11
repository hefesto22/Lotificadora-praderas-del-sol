<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\FormaDePago;
use App\Domain\Gastos\RegistroDeGastos;
use App\Filament\Resources\Proyectos\Pages\ViewProyecto;
use App\Filament\Resources\Proyectos\RelationManagers\GastosRelationManager;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Los gastos del proyecto
|--------------------------------------------------------------------------
| Lo pidió Mauricio el 11-ago-2026: «que ahí donde está bloques, lotes y
| planes de pago haya uno que sea gastos de proyecto, y ahí se puedan ir
| registrando los gastos, los totales y el motivo de en qué se gastó».
|
| Tres cosas cuidan estos tests, y ninguna es que el formulario abra:
|
|  1. Que el RECEPTOR no vea la pestaña. Filament permite lo que no tiene
|     política, así que el día que alguien borre `GastoPolicy` esto se pone
|     rojo antes de que quien atiende el mostrador vea los márgenes.
|  2. Que el MONTO no pase por un float (§8.3.1). El error es de un centavo:
|     invisible en pantalla y acumulativo en el total.
|  3. Que el NUMERO de comprobante no se repita ni deje huecos.
*/

beforeEach(function (): void {
    Storage::fake('local');

    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS', 'nombre' => 'PRADERAS DEL SOL']);

    $this->pestania = fn (): object => Livewire::test(GastosRelationManager::class, [
        'ownerRecord' => $this->proyecto,
        'pageClass'   => ViewProyecto::class,
    ]);
});

test('se registra un gasto y sale con su número de comprobante', function (): void {
    ($this->pestania)()
        ->callAction(TestAction::make('create')->table(), [
            'fecha'        => today()->toDateString(),
            'categoria'    => CategoriaDeGasto::Terraceria->value,
            'descripcion'  => 'Terracería de la calle principal, primera etapa',
            'beneficiario' => 'Constructora Mejía',
            'monto'        => '48500.75',
            'forma_pago'   => FormaDePago::Efectivo->value,
        ])
        ->assertHasNoActionErrors();

    $gasto = Gasto::query()->firstOrFail();

    expect($gasto->getAttribute('proyecto_id'))->toBe($this->proyecto->getKey())
        ->and($gasto->getAttribute('categoria'))->toBe(CategoriaDeGasto::Terraceria)
        ->and($gasto->folio())->toBe('G-000001')
        // El nombre va en MAYUSCULAS por el dehydrate de MayusculasField.
        ->and($gasto->getAttribute('beneficiario'))->toBe('CONSTRUCTORA MEJÍA');
});

/*
| ═══ EL TEST DEL CENTAVO ═══
|
| §8.3.1. Si alguien le pone `->numeric()` o `->money()` al campo o a la
| columna, el estado pasa por float y `48500.75` puede volver `48500.74`.
*/
test('el monto se guarda exacto, sin pasar por float', function (): void {
    ($this->pestania)()
        ->callAction(TestAction::make('create')->table(), [
            'fecha'       => today()->toDateString(),
            'categoria'   => CategoriaDeGasto::Materiales->value,
            'descripcion' => 'Cemento y varilla',
            'monto'       => '48500.75',
            'forma_pago'  => FormaDePago::Efectivo->value,
        ])
        ->assertHasNoActionErrors();

    expect(Gasto::query()->firstOrFail()->monto())->toBeMonto('48500.75');
});

test('dos gastos consumen números consecutivos', function (): void {
    $servicio = resolve(RegistroDeGastos::class);

    $datos = [
        'categoria'   => CategoriaDeGasto::ManoDeObra->value,
        'descripcion' => 'Planilla de la semana',
        'monto'       => '12000.00',
        'forma_pago'  => FormaDePago::Efectivo->value,
        'fecha'       => today(),
    ];

    $primero = $servicio->registrar($this->proyecto, $datos);
    $segundo = $servicio->registrar($this->proyecto, $datos);

    expect($primero->folio())->toBe('G-000001')
        ->and($segundo->folio())->toBe('G-000002');
});

/*
| El número no lo elige quien registra, y el proyecto tampoco: es el de la
| ficha donde se está parado. Si el formulario mandara otro, el Service lo pisa.
*/
test('el número y el proyecto que vengan del formulario se ignoran', function (): void {
    $gasto = resolve(RegistroDeGastos::class)->registrar($this->proyecto, [
        'numero'      => 900,
        'proyecto_id' => 12345,
        'categoria'   => CategoriaDeGasto::Otro->value,
        'descripcion' => 'Lo que sea',
        'monto'       => '100.00',
        'forma_pago'  => FormaDePago::Efectivo->value,
        'fecha'       => today(),
    ]);

    expect($gasto->getAttribute('numero'))->toBe(1)
        ->and($gasto->getAttribute('proyecto_id'))->toBe($this->proyecto->getKey());
});

describe('Lo que la base no deja pasar', function (): void {
    test('un gasto de cero se rechaza', function (): void {
        expect(fn (): Gasto => Gasto::factory()->delProyecto($this->proyecto)->de('0.00')->create())
            ->toThrow(QueryException::class);
    });

    test('un gasto sin detalle se rechaza', function (): void {
        expect(fn (): Gasto => Gasto::factory()->delProyecto($this->proyecto)->create(['descripcion' => '   ']))
            ->toThrow(QueryException::class);
    });

    /*
    | R11: en todo lo que no es efectivo la referencia es lo único que después
    | permite cruzar la salida contra el estado de cuenta del banco.
    */
    test('una transferencia sin referencia se rechaza', function (): void {
        expect(fn (): Gasto => Gasto::factory()->delProyecto($this->proyecto)->create([
            'forma_pago' => FormaDePago::Transferencia->value,
            'referencia' => null,
        ]))->toThrow(QueryException::class);
    });

    test('dos gastos no pueden llevar el mismo número', function (): void {
        Gasto::factory()->delProyecto($this->proyecto)->create(['numero' => 7]);

        expect(fn (): Gasto => Gasto::factory()->delProyecto($this->proyecto)->create(['numero' => 7]))
            ->toThrow(QueryException::class);
    });
});

describe('Quién ve lo que la lotificadora gasta', function (): void {
    test('la administradora ve la pestaña y el botón', function (): void {
        expect(GastosRelationManager::canViewForRecord($this->proyecto, ViewProyecto::class))->toBeTrue();

        ($this->pestania)()->assertActionVisible(TestAction::make('create')->table());
    });

    /*
    | ═══ EL TEST QUE IMPORTA ═══
    |
    | El receptor tiene ViewAny y View sobre TODOS los recursos del cruce del
    | RoleSeeder. `Gasto` está fuera de ese cruce a propósito, y esto es lo que
    | lo verifica: el día que alguien lo agregue a RECURSOS «para que sea
    | consistente», quien está en la ventanilla pasa a ver los márgenes del
    | dueño y esta línea se pone roja.
    */
    test('el receptor NO ve la pestaña de gastos', function (): void {
        $this->seed(RoleSeeder::class);

        $receptor = crearUsuarioConRol(Roles::RECEPTOR);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($receptor);

        expect($receptor->can('ViewAny:Gasto'))->toBeFalse()
            ->and(GastosRelationManager::canViewForRecord($this->proyecto, ViewProyecto::class))->toBeFalse();
    });

    test('quien solo mira no puede registrar un gasto', function (): void {
        $soloMira = rol('solo_mira');
        $soloMira->syncPermissions(['ViewAny:Proyecto', 'View:Proyecto', 'ViewAny:Gasto', 'View:Gasto']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($soloMira);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->pestania)()->assertActionHidden(TestAction::make('create')->table());
    });
});

describe('El comprobante escaneado', function (): void {
    test('la factura se guarda en el disco privado', function (): void {
        ($this->pestania)()
            ->callAction(TestAction::make('create')->table(), [
                'fecha'       => today()->toDateString(),
                'categoria'   => CategoriaDeGasto::Legal->value,
                'descripcion' => 'Honorarios del notario',
                'monto'       => '9500.00',
                'forma_pago'  => FormaDePago::Efectivo->value,
                'archivo'     => UploadedFile::fake()->create('factura.pdf', 300, 'application/pdf'),
            ])
            ->assertHasNoActionErrors();

        $gasto = Gasto::query()->firstOrFail();

        expect($gasto->tieneComprobante())->toBeTrue()
            ->and(Storage::disk('local')->exists((string) $gasto->getAttribute('archivo')))->toBeTrue();
    });

    /*
    | ═══ LA CONVERSION A WEBP (11-ago-2026) ═══
    |
    | Una foto de factura llega en 2–5 MB con 4000 px de lado. Se guarda en
    | WebP con el lado largo topado en 2,400: entre seis y diez veces menos,
    | sin perder una letra. Lo que este test cuida es que la conversión pase de
    | verdad y que la columna del peso diga lo que pesa EL ARCHIVO GUARDADO, no
    | el que salió del navegador.
    |
    | Se saltea donde GD no traiga WebP compilado: es una instalación distinta,
    | no un error, y el sistema en ese caso guarda el original a propósito.
    */
    test('la foto se guarda convertida a WebP y achicada', function (): void {
        ($this->pestania)()
            ->callAction(TestAction::make('create')->table(), [
                'fecha'       => today()->toDateString(),
                'categoria'   => CategoriaDeGasto::Maquinaria->value,
                'descripcion' => 'Combustible de la retroexcavadora',
                'monto'       => '3200.00',
                'forma_pago'  => FormaDePago::Efectivo->value,
                'archivo'     => UploadedFile::fake()->image('factura.jpg', 3000, 2000),
            ])
            ->assertHasNoActionErrors();

        $gasto = Gasto::query()->firstOrFail();
        $guardado = (string) $gasto->getAttribute('archivo');

        expect($guardado)->toEndWith('.webp');

        /*
         * `getimagesizefromstring` devuelve `array|false`, y una expectativa
         * de Pest no le estrecha el tipo a PHPStan. Se resuelve acá, en el
         * borde: si dio false, el archivo guardado no es una imagen y las dos
         * medidas quedan en cero, que es lo que hace fallar al test.
         */
        $medidas = getimagesizefromstring((string) Storage::disk('local')->get($guardado));

        // Sin `(int)`: `getimagesizefromstring` ya devuelve enteros en 0 y 1,
        // y un cast redundante lo quita Rector (`RecastingRemovalRector`).
        $ancho = is_array($medidas) ? $medidas[0] : 0;
        $alto = is_array($medidas) ? $medidas[1] : 0;

        // 3000 × 2000 entra topado en 2,400 de lado largo, con proporción.
        expect($ancho)->toBe(2400)
            ->and($alto)->toBe(1600)
            // Y el peso de la columna es el del WebP guardado, no el del
            // archivo que salió del navegador.
            ->and((int) $gasto->getAttribute('archivo_bytes'))
            ->toBe(Storage::disk('local')->size($guardado));
    })->skip(
        fn (): bool => ! function_exists('imagewebp'),
        'Este PHP no trae GD con WebP: el sistema guarda el original, que es el comportamiento previsto.',
    );

    /*
    | El PDF NO es una imagen y no se toca. Es la mitad de lo que entra al
    | expediente, y el día que alguien intente «optimizarlo» esto se pone rojo.
    */
    test('el PDF se guarda intacto', function (): void {
        ($this->pestania)()
            ->callAction(TestAction::make('create')->table(), [
                'fecha'       => today()->toDateString(),
                'categoria'   => CategoriaDeGasto::Legal->value,
                'descripcion' => 'Escritura pública',
                'monto'       => '9500.00',
                'forma_pago'  => FormaDePago::Efectivo->value,
                'archivo'     => UploadedFile::fake()->create('escritura.pdf', 300, 'application/pdf'),
            ])
            ->assertHasNoActionErrors();

        expect((string) Gasto::query()->firstOrFail()->getAttribute('archivo'))->toEndWith('.pdf');
    });

    test('se descarga con un nombre que se entiende', function (): void {
        $gasto = new Gasto([
            'numero'      => 12,
            'descripcion' => 'Honorarios del notario por las escrituras',
            'archivo'     => 'gastos/01J9-uuid-ilegible.pdf',
        ]);

        expect($gasto->nombreDeDescarga())->toStartWith('G-000012-')
            ->and($gasto->nombreDeDescarga())->toEndWith('.pdf');
    });
});

/*
| El cuadro de arriba es media función: sin él la pestaña es una lista de
| filas y la pregunta que Mauricio hizo —«los totales y el motivo de en qué se
| gastó»— sigue sin respuesta.
*/
test('el resumen suma por categoría y da el total', function (): void {
    Gasto::factory()->delProyecto($this->proyecto)->enCategoria(CategoriaDeGasto::Terraceria)->de('30000.00')->create();
    Gasto::factory()->delProyecto($this->proyecto)->enCategoria(CategoriaDeGasto::Terraceria)->de('20000.00')->create();
    Gasto::factory()->delProyecto($this->proyecto)->enCategoria(CategoriaDeGasto::Publicidad)->de('5000.50')->create();

    ($this->pestania)()
        ->assertSee('Terracería y movimiento de tierra')
        ->assertSee('L. 50,000.00')
        ->assertSee('L. 5,000.50')
        ->assertSee('L. 55,000.50');
});

test('un gasto de otro proyecto no entra en el total', function (): void {
    $otro = Proyecto::factory()->create(['codigo' => 'OTR']);

    Gasto::factory()->delProyecto($this->proyecto)->de('1000.00')->create();
    Gasto::factory()->delProyecto($otro)->de('999999.00')->create();

    ($this->pestania)()->assertDontSee('L. 999,999.00');
});
