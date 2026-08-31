<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as Shield;
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
 */
final class QuienRecibeElDinero
{
    public const string CAMPO = 'recibido_por';

    /**
     * El campo, listo para meter en el schema de cualquier modal.
     *
     * La ayuda cambia según la puerta —una cuota, una seña, una prima— porque
     * lo que hay que aclarar es de qué dinero se está hablando. Lo que no
     * cambia es la lista.
     *
     * 🔴 EL VALOR INICIAL NO VA ACA. Las tres acciones llenan su formulario
     * con `fillForm()`, y ese arreglo ES el estado inicial: los `default()` de
     * cada campo **no se aplican**. Quien monte este campo en un modal nuevo
     * tiene que agregar `'recibido_por' => QuienRecibeElDinero::porDefecto()`
     * a su `fillForm`, o el campo sale vacío con un `default()` perfectamente
     * escrito. Ya costó verlo en pantalla una vez.
     */
    public static function campo(?string $ayuda = null): Select
    {
        return Select::make(self::CAMPO)
            ->label('¿Quién recibió el dinero?')
            ->options(static fn (): array => self::lista())
            // Obligatorio solo si hay a quién elegir: ver el docblock de arriba.
            ->required(static fn (): bool => self::lista() !== [])
            ->native(false)
            ->helperText($ayuda ?? 'Viene marcado con tu nombre; cambialo si el dinero lo recibió otra persona. De acá sale el corte de caja del día.');
    }

    /**
     * Quiénes pueden figurar como que recibieron el dinero.
     *
     * Sale del PERMISO y no de una lista de nombres: `Create:Recibo` es
     * exactamente «esta persona cobra». Así la administradora entra porque
     * cobra, y una lotificadora que arme sus roles de otra manera no tiene que
     * tocar código (Ley L0).
     *
     * ═══ 🔴 MENOS EL SUPER-ADMIN (27-ago-2026) ═══
     *
     * «Mauricio Cruz no debe de aparecer ahí» — mirando la lista en pruebas.
     * Y tiene razón: **el super-admin es la cuenta de quien hace el sistema, no
     * personal de la caja de la lotificadora.** Tiene todos los permisos por
     * definición, así que sin esto se cuela en cada pantalla que pregunte
     * «¿quién de ustedes?».
     *
     * Se excluye por ROL y no por nombre —`Shield::getSuperAdminName()`—, que
     * es lo único que sigue siendo verdad en la instalación de la próxima
     * lotificadora.
     *
     * @return array<int, string>
     */
    public static function lista(): array
    {
        /** @var array<int, string> $gente */
        $gente = User::query()
            ->permission('Create:Recibo')
            ->whereDoesntHave('roles', static fn (Builder $rol): Builder => $rol->where('name', Shield::getSuperAdminName()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return $gente;
    }

    /**
     * Quien está tecleando, para dejar el campo marcado.
     *
     * ⚠️ Solo si figura en la lista. Si el que abre el modal es el super-admin
     * —o alguien sin el permiso—, el campo sale vacío y hay que elegir: es
     * mejor que ponerle el nombre de quien no estuvo en la caja.
     */
    public static function porDefecto(): ?int
    {
        $yo = auth()->id();

        return is_numeric($yo) && array_key_exists((int) $yo, self::lista())
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
}
