<?php

declare(strict_types=1);

use App\Domain\Enums\TipoDeDocumento;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\RelationManagers\DocumentosRelationManager;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Documento;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| La carpeta del expediente
|--------------------------------------------------------------------------
| «Para guardar la promesa de venta, debe poder guardarse en el expediente de
| la venta» — reunión del 6-ago-2026.
|
| Lo que estos tests cuidan de verdad es UNA cosa: que el archivo no termine
| en un lugar público. Una promesa firmada y una copia de identidad llevan
| datos personales, y el día que alguien cambie `local` por `public` para
| «arreglar la previsualización», este archivo se pone rojo.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote],
        clientes: [Cliente::factory()->create(['nombre' => 'Leticia Romero'])],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [new PrecioPactado(
            loteId: (int) $lote->getKey(),
            precioVara: new Monto('1400.00'),
            plazoMeses: 12,
            prima: new Monto('50000.00'),
        )],
    );

    $this->carpeta = fn (): object => Livewire::test(DocumentosRelationManager::class, [
        'ownerRecord' => $this->venta,
        'pageClass'   => ViewVenta::class,
    ]);
});

test('la promesa firmada se guarda en el expediente', function (): void {
    ($this->carpeta)()
        ->callAction(TestAction::make('create')->table(), [
            'tipo'    => TipoDeDocumento::PromesaDeVenta->value,
            'nombre'  => 'Promesa firmada — 06/08/2026',
            'archivo' => UploadedFile::fake()->create('promesa.pdf', 420, 'application/pdf'),
        ])
        ->assertHasNoActionErrors();

    $documento = Documento::query()->firstOrFail();

    expect($documento->getAttribute('venta_id'))->toBe($this->venta->getKey())
        ->and($documento->getAttribute('tipo'))->toBe(TipoDeDocumento::PromesaDeVenta)
        ->and(Storage::disk('local')->exists((string) $documento->getAttribute('archivo')))->toBeTrue();
});

/*
| ═══ EL TEST QUE IMPORTA ═══
|
| Si algún día alguien cambia el disco a `public` para que ande la
| previsualización, esto se pone rojo. Una URL pública se filtra sola: se pega
| en un chat, queda en el historial, viaja en una captura.
*/
test('el archivo NO queda en el disco público', function (): void {
    ($this->carpeta)()
        ->callAction(TestAction::make('create')->table(), [
            'tipo'    => TipoDeDocumento::Identidad->value,
            'nombre'  => 'DNI de la titular',
            'archivo' => UploadedFile::fake()->image('dni.jpg'),
        ])
        ->assertHasNoActionErrors();

    $archivo = (string) Documento::query()->firstOrFail()->getAttribute('archivo');

    expect(Storage::disk('public')->exists($archivo))->toBeFalse()
        ->and(Storage::disk('local')->exists($archivo))->toBeTrue();
});

test('la carpeta lista lo que ya está guardado', function (): void {
    $papel = Documento::query()->create([
        'venta_id' => $this->venta->getKey(),
        'tipo'     => TipoDeDocumento::Contrato,
        'nombre'   => 'Contrato firmado',
        'archivo'  => 'documentos/lo-que-sea.pdf',
        'bytes'    => 2048,
    ]);

    ($this->carpeta)()->assertCanSeeTableRecords([$papel]);
});

describe('Quién puede tocar la carpeta', function (): void {
    test('quien puede guardar ve el botón', function (): void {
        ($this->carpeta)()->assertActionVisible(TestAction::make('create')->table());
    });

    /*
    | §9.E9: `Documento` no es un Resource, así que `shield:generate` no le
    | genera permisos solo. Si el RoleSeeder no los nombra uno por uno,
    | Filament —que permite lo que no tiene política— deja a cualquiera
    | descargar una copia de identidad.
    */
    test('quien ve el expediente pero no la carpeta, no puede guardar', function (): void {
        $soloMira = rol('solo_mira');
        $soloMira->syncPermissions(['ViewAny:Venta', 'View:Venta']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($soloMira);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->carpeta)()->assertActionHidden(TestAction::make('create')->table());
    });
});

/*
| El nombre con el que baja el archivo: el que se lee en la carpeta de
| Descargas. El uuid interno no le sirve a nadie.
*/
test('se descarga con un nombre que se entiende', function (): void {
    $papel = new Documento([
        'nombre'  => 'Promesa firmada — 06/08/2026',
        'archivo' => 'documentos/01J9-uuid-ilegible.pdf',
    ]);

    expect($papel->nombreDeDescarga())->toContain('promesa-firmada')
        ->and($papel->nombreDeDescarga())->toEndWith('.pdf');
});
