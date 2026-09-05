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
        'Recibo', 'Documento', 'Vendedor',
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
        $this->soloDelSuperAdmin();

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('✓ Roles listos: '.implode(', ', Roles::operativos()));
    }

    /**
     * 🔴 Permisos que EXISTEN y no son de nadie todavía — 23-ago-2026.
     *
     * Pedido de Mauricio: «esos botones de importar y acomodar deben tener su
     * permiso personalizado en shield para otorgarlos a quien yo quiera; por
     * el momento solo super admin debe poder verlos ya que solo yo cargaré
     * proyectos».
     *
     * ═══ POR QUE HACE FALTA CREARLOS SI NO SE ASIGNAN ═══
     *
     * `Permission::findOrCreate()` es lo único que hace nacer la fila. Un
     * permiso que ningún rol reclama **no existe en la base**, y entonces no
     * aparece en la pantalla de Roles: no habría dónde otorgarlo el día que
     * él quiera dárselo a alguien. Por eso se nombran acá aunque la lista de
     * la administradora y la del receptor no los toquen.
     *
     * super_admin los recibe igual: `shield:super-admin` —y
     * `olympo:sembrar-permisos`— le sincronizan TODOS los que haya en la base.
     *
     * ⚠️ NO van en `RECURSOS`: ese cruce le da al receptor `ViewAny` y `View`
     * de todo lo que lista, y acá el punto es exactamente el contrario.
     *
     * 🔴 Y como todo permiso nombrado: `composer ci` solo lo siembra en la
     * base de TESTS. En la máquina de Mauricio hay que correr
     * `php artisan olympo:sembrar-permisos` o los botones no van a
     * desaparecer — ni a poder darse. Ver [[un-permiso-nuevo-no-aparece-solo]].
     */
    private function soloDelSuperAdmin(): void
    {
        /*
         * Importar un DXF reescribe la geometría del desarrollo entero y
         * acomodar lo redibuja de cero. Ninguna de las dos toca plata ni
         * estados —los lotes vendidos siguen vendidos—, pero las dos cambian
         * el mapa que el vendedor le muestra al cliente, y eso se hace una vez
         * por proyecto y con el plano del topógrafo al lado.
         */
        $this->permisos(['ImportarPlano', 'AcomodarPlano'], ['Proyecto']);
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
         * Pasarle el expediente a otra persona (cesión de derechos, 22-ago-2026).
         * Se nombra solo por lo mismo que `Reprogramar`: `Update:Venta` es
         * corregir un dato, y esto cambia de quién es el contrato. El receptor
         * cobra y no decide a nombre de quién queda un expediente firmado.
         */
        $permisos = [...$permisos, ...$this->permisos(['CambiarTitular'], ['Venta'])];

        /*
         * Saldar un lote perdonando parte del saldo: el pronto pago
         * (23-ago-2026). Aparte de `Reprogramar` a propósito: reprogramar
         * reparte la misma deuda distinto y no le cuesta nada a la
         * lotificadora; esto perdona plata, sin tope. El receptor cobra lo que
         * el contrato dice — no decide cuánto se deja de cobrar.
         */
        $permisos = [...$permisos, ...$this->permisos(['ProntoPago'], ['Venta'])];

        /*
         * R14: prorrogar un apartado y marcar la devolucion de su seña. Los
         * dos se nombran solos y NO van a RECURSOS ni a ACCIONES: ver un
         * apartado y estirarlo son cosas distintas, y el receptor hace lo
         * primero todo el dia. Meterlos en el cruce inventaria un
         * `Prorrogar:Cliente` que ninguna politica conoce.
         */
        $permisos = [...$permisos, ...$this->permisos(['Prorrogar', 'DevolverSenia'], ['Compromiso'])];

        /*
         * Anular un recibo NO va en el cruce y NO lo tiene el receptor: quien
         * cobra no debería poder borrar su propio cobro del estado de cuenta.
         * Un monto mal tecleado lo corrige la administradora, con motivo.
         *
         * Condonar la mora va al lado y por la misma razón: perdonarle el
         * atraso a un cliente es plata que la lotificadora deja de cobrar, y
         * quien está en la ventanilla con el cliente enfrente es exactamente
         * quien no debería poder decidirlo solo. Va con motivo escrito, y
         * queda en el recibo con el nombre de quien lo autorizó.
         */
        /*
         * `Corregir` entra al lado de los otros dos el 4-sep-2026, y NO es un
         * `Update` con otro nombre: abre cuatro campos que no mueven dinero
         * —quién recibió, forma de pago, referencia, observaciones— mientras
         * `ReciboPolicy::update()` sigue devolviendo false para el monto, el
         * concepto y la fecha. Lo pidió Mauricio después de arreglar a mano
         * por SSH un recibo que salió sin «recibido_por». La lista de campos
         * es `CorreccionDeRecibo::CAMPOS`.
         *
         * Tampoco lo hereda el receptor, por la misma razón que Anular: a
         * nombre de quién quedó un cobro es lo que quien cobró no debería
         * poder cambiar solo.
         */
        $permisos = [...$permisos, ...$this->permisos(['Anular', 'CondonarMora', 'Corregir'], ['Recibo'])];

        /*
         * Los prospectos del plano público: nombre y teléfono de gente que
         * NO es cliente. No van al cruce y el receptor no los ve — son datos
         * personales de terceros, y quien atiende el mostrador no necesita
         * la lista de a quién está por llamar la administración.
         *
         * `Create` y `Delete` no existen a propósito: un prospecto nace en el
         * formulario público y borrarlo falsearía la única medida que dice si
         * el plano público sirve. Lo dice también `ProspectoPolicy`.
         */
        $permisos = [...$permisos, ...$this->permisos(['ViewAny', 'View', 'Update'], ['Prospecto'])];

        /*
         * Los gastos del desarrollo (11-ago-2026). NO van al cruce de
         * RECURSOS, y esa es toda la decision: RECURSOS se le reparte tambien
         * al receptor con ViewAny y View, y lo que la lotificadora gasta no es
         * asunto de quien atiende el mostrador. Nombrados aca, el receptor no
         * ve ni la pestaña.
         *
         * Con Update y Delete, a diferencia de las devoluciones: un gasto es
         * un asiento interno cuyo respaldo es la factura del proveedor, no un
         * papel que el cliente se llevo firmado. Lo que lo mantiene auditable
         * es la bitacora, que `Gasto` escribe en cada cambio.
         */
        $permisos = [...$permisos, ...$this->permisos(['ViewAny', 'View', 'Create', 'Update', 'Delete'], ['Gasto'])];

        /*
         * La pantalla «Por cobrar hoy» y el marcar que ya se llamó
         * (23-ago-2026). Se nombra sola porque NO es un Resource: es una
         * página, y `shield:generate` solo mira Resources.
         *
         * La tiene también el receptor —ver `receptor()`—: es la única
         * pantalla que los dos comparten como trabajo, no como consulta.
         */
        $permisos = [...$permisos, ...$this->permisos(['Cobranza'], ['Venta'])];

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

        /*
         * Llamar a cobrar es su trabajo tanto como recibir el dinero: el
         * receptor es quien tiene el teléfono y quien atiende al cliente que
         * llega. `Cobranza:Venta` abre la lista del día y deja registrar la
         * llamada — no crea ni cambia nada del contrato.
         */
        $permisos = [...$permisos, ...$this->permisos(['Cobranza'], ['Venta'])];

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
