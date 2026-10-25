<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * «¿Quién recibió el dinero?», una sola vez para las tres pantallas.
 *
 * ═══ POR QUE VIVE ACA Y NO EN CADA MODAL ═══
 *
 * Nació el 27-ago-2026 adentro del modal de cobro: «que la administradora y yo
 * podamos seleccionar quién recibió el dinero, y también los receptores»
 * — Mauricio. La administradora registra un pago que recibió don Elder en la
 * caseta: el billete lo tiene él, y el arqueo del día es de quien lo tiene en
 * la mano.
 *
 * Pero el dinero entra por TRES puertas, no por una: la cuota, la **seña de un
 * apartado** y la **prima de una venta**. Las otras dos no preguntaban, y el
 * corte de caja las sumaba bajo «Sin usuario» (31-ago-2026). Preguntar lo
 * mismo en tres lugares con tres redacciones es cómo se vuelven tres reglas
 * distintas — así que la pregunta, la lista y el default se escriben una vez.
 *
 * ═══ 🔴 ES OBLIGATORIO — 31-ago-2026 ═══
 *
 * «Quién recibió el dinero que sea obligatorio» — Mauricio, mirando el modal
 * de la venta con el campo vacío. Y estaba vacío por una buena razón: **él es
 * el super-admin, la única cuenta que la lista no ofrece**, así que a él no se
 * le puede preseleccionar a nadie. Sin exigirlo, ese recibo salía a nombre
 * suyo —lo escribe `Recibo::booted()`—, que es exactamente lo que pidió evitar
 * el 27-ago: «Mauricio Cruz no debe de aparecer ahí».
 *
 * ⚠️ **Obligatorio cuando hay a quién elegir.** Una instalación recién montada
 * —o una lotificadora que todavía no le dio `Create:Recibo` a nadie— tiene la
 * lista vacía, y un `Select` requerido sin una sola opción es un formulario
 * que nadie puede mandar: trabaría la venta sin ofrecer ninguna salida. Ahí el
 * papel sigue cayendo en quien teclea, que es lo que el sistema hizo siempre.
 *
 * (Esto reemplaza la nota del 27-ago, que decía que el campo NO llevaba
 * `required()`. Entonces era cierto y costó 38 tests en rojo: el valor lo
 * ponía un `default()` que `fillForm` no aplica. Hoy el valor va adentro del
 * `fillForm` de cada acción, así que quien puede cobrar lo recibe lleno.)
 *
 * ═══ 🔴 POR PROYECTO, Y LO DECIDE UN INTERRUPTOR — 24-oct-2026 ═══
 *
 * «Hay que hacer un toggle para marcar si ese usuario aparece como persona que
 * recibe dinero o no, y si no está marcado como permitido en X proyecto,
 * entonces no aparece en ese proyecto» — Mauricio, viendo a la administradora
 * en la lista de un cobro.
 *
 * Hasta ese día la lista salía del permiso `Create:Recibo` (menos el
 * super-admin) y era la misma en todos los proyectos. Desde ese día sale de
 * `User::proyectosDondeRecibe()`: el interruptor «Recibe dinero» de la ficha
 * del usuario, con sus proyectos marcados. **Lo que se marca es lo que
 * aparece** — ni el permiso ni el rol meten a nadie por su cuenta. La
 * migración sembró a los de antes en todos los proyectos para que el día del
 * despliegue la lista fuera la misma.
 *
 * Por eso cada pantalla le dice a la lista DE QUÉ PROYECTO es el dinero: el
 * cobro por la venta, apartar y vender por el plano, «Corregir» por el
 * recibo.
 */
final class QuienRecibeElDinero
{
    public const string CAMPO = 'recibido_por';

    /**
     * El campo, listo para meter en el schema de cualquier modal.
     *
     * La ayuda cambia según la puerta —una cuota, una seña, una prima— porque
     * lo que hay que aclarar es de qué dinero se está hablando. La lista
     * cambia según el proyecto.
     *
     * `$proyecto` e `$incluir` pueden ser un número o un closure: el closure
     * se evalúa con la inyección de Filament (`$record`, `$get`…), así que una
     * acción de tabla puede sacar el proyecto de la fila.
     *
     * 🔴 EL VALOR INICIAL NO VA ACA. Las tres acciones llenan su formulario
     * con `fillForm()`, y ese arreglo ES el estado inicial: los `default()` de
     * cada campo **no se aplican**. Quien monte este campo en un modal nuevo
     * tiene que agregar `'recibido_por' => QuienRecibeElDinero::porDefecto(…)`
     * a su `fillForm`, o el campo sale vacío con un `default()` perfectamente
     * escrito. Ya costó verlo en pantalla una vez.
     *
     * @param Closure|int|null $proyecto de qué proyecto es el dinero
     * @param Closure|int|null $incluir alguien que tiene que estar aunque ya no reciba dinero ahí: el que hoy figura en el recibo que se corrige
     */
    public static function campo(?string $ayuda = null, Closure|int|null $proyecto = null, Closure|int|null $incluir = null): Select
    {
        return Select::make(self::CAMPO)
            ->label('¿Quién recibió el dinero?')
            ->options(static fn (Select $component): array => self::lista(
                self::entero($component->evaluate($proyecto)),
                self::entero($component->evaluate($incluir)),
            ))
            // Obligatorio solo si hay a quién elegir: ver el docblock de la clase.
            ->required(static fn (Select $component): bool => self::lista(self::entero($component->evaluate($proyecto))) !== [])
            ->native(false)
            ->helperText($ayuda ?? 'Viene marcado con tu nombre; cambialo si el dinero lo recibió otra persona. De acá sale el corte de caja del día.');
    }

    /**
     * Quiénes pueden figurar como que recibieron dinero de ese proyecto.
     *
     * Los que tienen el interruptor «Recibe dinero» con ESE proyecto marcado,
     * y la cuenta activa. Sin proyecto —una pantalla que no sabe de cuál es el
     * dinero— entra quien recibe dinero en alguno.
     *
     * `$incluir` se agrega aunque no cumpla: al corregir un recibo viejo, la
     * persona que hoy figura en el papel tiene que poder seguir figurando. Si
     * no estuviera en las opciones, el `Select` mostraría el campo vacío y la
     * regla `in` de Filament rechazaría guardar el recibo como estaba.
     *
     * @return array<int, string>
     */
    public static function lista(?int $proyecto = null, ?int $incluir = null): array
    {
        /** @var array<int, string> $gente */
        $gente = User::query()
            ->where('is_active', true)
            ->whereHas('proyectosDondeRecibe', static function (Builder $donde) use ($proyecto): void {
                if ($proyecto !== null) {
                    $donde->whereKey($proyecto);
                }
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        if ($incluir !== null && ! array_key_exists($incluir, $gente)) {
            $nombre = User::withTrashed()->whereKey($incluir)->value('name');

            if (is_string($nombre)) {
                $gente[$incluir] = $nombre;
            }
        }

        return $gente;
    }

    /**
     * Quien está tecleando, para dejar el campo marcado.
     *
     * ⚠️ Solo si figura en la lista de ESE proyecto. Si el que abre el modal no
     * recibe dinero ahí —el super-admin, o la administradora que solo
     * registra—, el campo sale vacío y hay que elegir: es mejor que ponerle el
     * nombre de quien no estuvo en la caja.
     */
    public static function porDefecto(?int $proyecto = null): ?int
    {
        $yo = auth()->id();

        return is_numeric($yo) && array_key_exists((int) $yo, self::lista($proyecto))
            ? (int) $yo
            : null;
    }

    /**
     * Lo que el formulario mandó, si mandó algo usable.
     *
     * @param array<string, mixed> $data
     */
    public static function elegido(array $data): ?int
    {
        $elegido = $data[self::CAMPO] ?? null;

        return is_numeric($elegido) ? (int) $elegido : null;
    }

    private static function entero(mixed $valor): ?int
    {
        return is_numeric($valor) ? (int) $valor : null;
    }
}
