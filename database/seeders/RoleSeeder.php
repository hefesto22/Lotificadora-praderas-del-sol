<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\Contracts\Permission as PermisoContrato;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Los roles del panel y qué puede hacer cada uno.
 *
 * §9.E7: **este seeder ES la matriz de verdad**. Por eso usa
 * `syncPermissions` y no `givePermissionTo`: si alguien le agrega un permiso
 * a un rol desde la pantalla de Shield y no lo escribe acá, la próxima
 * corrida lo quita. Es incómodo a propósito — un permiso que solo vive en la
 * base es un permiso que nadie puede auditar contra el contrato.
 *
 * Los permisos los genera `shield:generate` leyendo los Resources reales;
 * acá solo se reparten. `findOrCreate` es la red por si el seeder corre
 * antes que Shield (§9.E2, punto 3).
 *
 * ═══ EL REPARTO, Y DE DÓNDE SALE ═══
 *
 * **Administradora** (doña Rosa Elena): opera todo el negocio. No administra
 * usuarios ni roles del sistema —eso es super_admin— pero sí ve la bitácora,
 * porque es la dueña de la operación y necesita saber quién tocó qué.
 *
 * **Receptor** (don Elder, don Edwin): **cobra y registra el cobro, y del
 * resto solo mira** (decidido con Mauricio el 4-ago-2026). Puede abrir un
 * expediente para saber cuánto debe un cliente que llegó a pagar, pero no
 * firma ventas: consumir un correlativo y congelar un plan de cuotas es de
 * la administradora.
 *
 * El receptor ya tiene `Create:Recibo`, que es su trabajo real. Lo que NO
 * tiene es `Reprogramar:Venta`: un abono a capital emite un recibo igual que
 * un cobro, pero además reescribe el plan de cuotas del lote (R21). Cobrar y
 * reescribir un contrato firmado son dos cosas distintas, y por eso son dos
 * permisos distintos.
 */
class RoleSeeder extends Seeder
{
    /**
     * Los Resources del negocio. `User`, `Role` y `Activity` no están:
     * los dos primeros son de super_admin y la bitácora se reparte aparte.
     *
     * `PlanDePago` NO es un Resource —se administra como pestaña del
     * proyecto—, así que `shield:generate` no lo ve y sus permisos nacen
     * acá. Sin esto, el precio de lista de todo el proyecto queda editable
     * por cualquiera: Filament permite lo que no tiene política.
     *
     * Es PUBLICA a proposito: `tests/Pest.php` la lee para sembrar los
     * mismos permisos. Tenerla copiada alla fue exactamente el error que
     * dejo el boton "Nuevo plan" invisible en los tests — la copia no se
     * entero de que existia PlanDePago.
     *
     * @var list<string>
     */
    public const array RECURSOS = [
        'Proyecto', 'Bloque', 'Calle', 'Lote', 'Cliente', 'Compromiso', 'Venta', 'PlanDePago',
        'Recibo', 'Documento',
    ];

    /**
     * Todo salvo el borrado definitivo: `ForceDelete` destruye la fila y no
     * deja rastro, así que queda solo para super_admin.
     *
     * @var list<string>
     */
    private const array ACCIONES_ADMINISTRADORA = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore', 'RestoreAny', 'Replicate', 'Reorder',
    ];

    /**
     * @var list<string>
     */
    private const array ACCIONES_RECEPTOR = ['ViewAny', 'View'];

    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        // Acceso total. shield:super-admin le sincroniza todos los
        // permisos generados, desde AdminUserSeeder.
        Role::query()->firstOrCreate(['name' => Utils::getSuperAdminName()], ['guard_name' => 'web']);

        // Acceso al panel sin permisos de Resource. Es la base contra la que
        // se prueban las restricciones (§5: todo módulo se prueba con un rol
        // que NO sea admin).
        Role::query()->firstOrCreate(['name' => Utils::getPanelUserRoleName()], ['guard_name' => 'web']);

        $this->administradora();
        $this->receptor();

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('✓ Roles listos: '.implode(', ', Roles::operativos()));
    }

    private function administradora(): void
    {
        $permisos = $this->permisos(self::ACCIONES_ADMINISTRADORA, self::RECURSOS);

        // La bitácora, solo de lectura: quién tocó qué, sin poder borrarlo.
        $permisos = [...$permisos, ...$this->permisos(['ViewAny', 'View'], ['Activity'])];

        /*
         * Reescribir el plan de un lote por un abono a capital (R21). Se nombra
         * solo, y NO se agrega a RECURSOS, porque `ACCIONES_ADMINISTRADORA` le
         * daría también Create, Update y Delete sobre las constancias — y una
         * constancia de reprogramación no se crea a mano ni se corrige: si una
         * se hizo mal, la corrección es otra reprogramación con su motivo.
         */
        $permisos = [...$permisos, ...$this->permisos(['Reprogramar'], ['Venta'])];
        $permisos = [...$permisos, ...$this->permisos(['ViewAny', 'View'], ['Reprogramacion'])];

        /*
         * R14: prorrogar un apartado y marcar la devolucion de su seña. Los
         * dos se nombran solos y NO van a RECURSOS ni a ACCIONES: ver un
         * apartado y estirarlo son cosas distintas, y el receptor hace lo
         * primero todo el dia. Meterlos en el cruce inventaria un
         * `Prorrogar:Cliente` que ninguna politica conoce.
         */
        $permisos = [...$permisos, ...$this->permisos(['Prorrogar', 'DevolverSenia'], ['Compromiso'])];

        $this->rol(Roles::ADMINISTRADORA)->syncPermissions($permisos);
    }

    private function receptor(): void
    {
        $permisos = $this->permisos(self::ACCIONES_RECEPTOR, self::RECURSOS);

        /*
         * ═══ EL RECEPTOR COBRA. ES SU TRABAJO ═══
         *
         * Es la única escritura que tiene, y se nombra sola —no se agrega a
         * ACCIONES_RECEPTOR— porque eso le daría `Create` sobre TODOS los
         * recursos: podría crear proyectos, lotes y ventas. §9.E3: uno por
         * uno, nunca por patrón.
         *
         * Crear, y nada más. Un recibo entregado no se edita ni se borra: se
         * anula y se emite otro, y eso será su propia acción con motivo.
         */
        $permisos = [...$permisos, ...$this->permisos(['Create'], ['Recibo'])];

        /*
         * Y ve por qué el plan cambió, sin poder cambiarlo. Es exactamente el
         * caso que tiene enfrente: el cliente llega a pagar, la cuota no es la
         * del mes pasado, y quien atiende necesita poder explicarlo.
         */
        $permisos = [...$permisos, ...$this->permisos(['ViewAny', 'View'], ['Reprogramacion'])];

        $this->rol(Roles::RECEPTOR)->syncPermissions($permisos);
    }

    private function rol(string $nombre): Role
    {
        /** @var Role $rol */
        $rol = Role::query()->firstOrCreate(['name' => $nombre], ['guard_name' => 'web']);

        return $rol;
    }

    /**
     * §9.E3: los permisos se nombran uno por uno, NUNCA por patrón.
     *
     * Un `LIKE '%:Venta'` parece más corto y es justo como se fugó
     * `Anular:Compra` a recepción en MAYAP: el día que aparezca una acción
     * nueva, el patrón se la regala a quien no debía tenerla.
     *
     * Devuelve el CONTRATO y no el modelo: `Permission::findOrCreate()` está
     * tipado contra `Spatie\Permission\Contracts\Permission`, porque la clase
     * del modelo es configurable en `config/permission.php`. Anotar el modelo
     * concreto sería prometer algo que el paquete no garantiza.
     *
     * @param list<string> $acciones
     * @param list<string> $recursos
     *
     * @return list<PermisoContrato>
     */
    private function permisos(array $acciones, array $recursos): array
    {
        $permisos = [];

        foreach ($recursos as $recurso) {
            foreach ($acciones as $accion) {
                $permisos[] = Permission::findOrCreate("{$accion}:{$recurso}", 'web');
            }
        }

        return $permisos;
    }
}
