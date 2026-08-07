<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Filament\Resources\Apartados\ApartadoResource;
use App\Filament\Resources\Apartados\Pages\ListApartados;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| La pantalla de apartados — R14
|--------------------------------------------------------------------------
| Existe porque hasta el 6-ago-2026 el sistema guardaba `vence_el` y nadie lo
| miraba nunca: un apartado al que se le pasaba la fecha dejaba el lote
| reservado para siempre. Con 301 lotes eso es plata parada que no se ve.
|
| Lo que se prueba acá es lo que la lista tiene que contestar y quién puede
| tocar qué. Las reglas de la prórroga y la devolución viven en
| ProrrogaDelApartadoTest, sobre el Service.
*/

beforeEach(function (): void {
    $this->registro = app(RegistroDeCompromisos::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
    $this->lote = fn (string $numero): Lote => Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1200.00')
        ->create(['numero' => $numero]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Rosa Elena Fuentes']);

    // Mismo motivo que en el test del dominio: el CHECK de la base no admite
    // un apartado que venza antes de haberse creado.
    $this->vencidoHace = function (Lote $lote, int $dias): Compromiso {
        $this->travelTo(today()->subDays($dias + 15));

        $apartado = $this->registro->apartar(
            $lote,
            $this->cliente,
            venceEl: today()->addDays(15)->toDateString(),
        );

        $this->travelBack();

        return $apartado->refresh();
    };

    $this->entrarComo = function (array $permisos): User {
        /*
         * RoleSeeder es quien crea TODOS los permisos con nombre propio
         * (§9.E3). Sin el, `syncPermissions` revienta con
         * PermissionDoesNotExist y `can()` no tiene contra que evaluar.
         */
        $this->seed(RoleSeeder::class);

        $rolDePrueba = rol('rol_de_prueba_apartados');
        $rolDePrueba->syncPermissions($permisos);

        /** @var User $user */
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($rolDePrueba);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        return $user;
    };
});

describe('Lo que la lista contesta', function (): void {
    test('muestra los apartados y no las ventas', function (): void {
        $apartado = ($this->lote)('1');
        $vendido = ($this->lote)('2');

        $this->registro->apartar($apartado, $this->cliente, venceEl: today()->addDays(10)->toDateString());
        $this->registro->vender($vendido, $this->cliente);

        ($this->entrarComo)(['ViewAny:Compromiso', 'View:Compromiso']);

        Livewire::test(ListApartados::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Compromiso::query()->apartados()->get())
            ->assertCountTableRecords(1);
    });

    /*
    | El apartado convertido en venta sigue en la lista: sin el no se puede
    | contestar «¿a este lote ya se le cayo un apartado antes?», que es lo
    | que uno quiere saber antes de dar una prorroga.
    */
    test('los apartados cerrados siguen estando', function (): void {
        $lote = ($this->lote)('1');

        $this->registro->apartar($lote, $this->cliente, venceEl: today()->addDays(10)->toDateString());
        $this->registro->liberar($lote->refresh(), 'Se vencio el plazo.');

        ($this->entrarComo)(['ViewAny:Compromiso', 'View:Compromiso']);

        Livewire::test(ListApartados::class)
            ->assertSuccessful()
            ->assertCountTableRecords(1);
    });

    test('el filtro de vencidos deja solo los que se pasaron de fecha', function (): void {
        $vencido = ($this->lote)('1');
        $vigente = ($this->lote)('2');

        ($this->vencidoHace)($vencido, 1);
        $this->registro->apartar($vigente, $this->cliente, venceEl: today()->addDays(10)->toDateString());

        ($this->entrarComo)(['ViewAny:Compromiso', 'View:Compromiso']);

        Livewire::test(ListApartados::class)
            ->assertSuccessful()
            ->assertCountTableRecords(2)
            ->filterTable('vencidos')
            ->assertCountTableRecords(1);
    });

    test('el filtro de seña por devolver deja solo lo que se le debe a alguien', function (): void {
        $conSenia = ($this->lote)('1');
        $sinSenia = ($this->lote)('2');

        $this->registro->apartar($conSenia, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);
        $this->registro->apartar($sinSenia, $this->cliente);

        $this->registro->liberar($conSenia->refresh(), 'Se vencio.');
        $this->registro->liberar($sinSenia->refresh(), 'Se vencio.');

        ($this->entrarComo)(['ViewAny:Compromiso', 'View:Compromiso']);

        Livewire::test(ListApartados::class)
            ->assertSuccessful()
            ->filterTable('senia_por_devolver')
            ->assertCountTableRecords(1);
    });
});

describe('El contador del menú', function (): void {
    /*
    | Null y no «0» cuando no hay nada: un cero en rojo permanente se vuelve
    | parte del decorado y en un mes ya nadie lo ve.
    */
    test('sin vencidos no hay badge', function (): void {
        $this->registro->apartar(($this->lote)('1'), $this->cliente, venceEl: today()->addDays(10)->toDateString());

        expect(ApartadoResource::getNavigationBadge())->toBeNull();
    });

    test('cuenta los vencidos que todavia ocupan su lote', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');
        $liberado = ($this->lote)('3');

        ($this->vencidoHace)($uno, 1);
        ($this->vencidoHace)($dos, 4);
        ($this->vencidoHace)($liberado, 30);
        $this->registro->liberar($liberado->refresh(), 'Ya se solto.');

        expect(ApartadoResource::getNavigationBadge())->toBe('2');
    });
});

describe('Quién entra y quién toca', function (): void {
    test('quien no tiene permiso de compromisos no entra', function (): void {
        ($this->entrarComo)(['ViewAny:Venta', 'View:Venta']);

        Livewire::test(ListApartados::class)->assertForbidden();
    });

    /*
    | §9.E3: los permisos se nombran uno por uno. `Prorrogar:Compromiso` y
    | `DevolverSenia:Compromiso` NO salen del cruce acciones x recursos —
    | verlos y estirarlos son cosas distintas, y el receptor hace lo primero
    | y no lo segundo.
    */
    test('ver un apartado no autoriza a prorrogarlo', function (): void {
        $user = ($this->entrarComo)(['ViewAny:Compromiso', 'View:Compromiso']);

        expect($user->can('ViewAny:Compromiso'))->toBeTrue()
            ->and($user->can('Prorrogar:Compromiso'))->toBeFalse()
            ->and($user->can('DevolverSenia:Compromiso'))->toBeFalse();
    });

    test('la administradora si puede prorrogar y devolver', function (): void {
        $this->seed(RoleSeeder::class);

        $user = crearUsuarioConRol(Roles::ADMINISTRADORA);

        expect($user->can('Prorrogar:Compromiso'))->toBeTrue()
            ->and($user->can('DevolverSenia:Compromiso'))->toBeTrue();
    });

    test('el receptor mira pero no estira', function (): void {
        $this->seed(RoleSeeder::class);

        $user = crearUsuarioConRol(Roles::RECEPTOR);

        expect($user->can('ViewAny:Compromiso'))->toBeTrue()
            ->and($user->can('Prorrogar:Compromiso'))->toBeFalse()
            ->and($user->can('DevolverSenia:Compromiso'))->toBeFalse();
    });
});
