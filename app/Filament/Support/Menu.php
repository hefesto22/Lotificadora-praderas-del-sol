<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Los grupos del menú de la izquierda, en un solo lugar.
 *
 * ═══ POR QUE UNA CLASE PARA CUATRO STRINGS ═══
 *
 * Porque el nombre del grupo es una **llave**: Filament junta los recursos
 * cuyo `getNavigationGroup()` devuelve exactamente el mismo texto, y ordena
 * según la lista de `AdminPanelProvider->navigationGroups()`. Hasta el
 * 22-ago-2026 ese texto estaba escrito a mano en **once archivos**, con
 * tilde y todo.
 *
 * Lo que eso significa en la práctica: una `n` en vez de una `ñ`, o un
 * acento perdido en un solo recurso, y ese recurso **se va solo a un grupo
 * nuevo al final del menú**. Sin error, sin aviso, sin test que lo note —
 * porque Filament crea el grupo que no conoce en vez de quejarse.
 *
 * Con la constante eso deja de poder pasar: un typo ya no compila.
 *
 * ═══ POR QUE ESTOS NOMBRES ═══
 *
 * Mauricio, 22-ago-2026: el menú «se ve muy seco, no se sabe qué es lo
 * importante ni cómo se maneja». Los rótulos viejos —Lotificación,
 * Administración, Sistema— nombran **el negocio**; estos nombran **cuándo
 * se entra ahí**, que es la pregunta que se hace quien está trabajando.
 *
 * `Día a día` es lo que Rosa Elena abre todas las mañanas. `El desarrollo`
 * es el plano y sus lotes: se toca cuando cambia el terreno, no cuando
 * llega un cliente. Los otros dos nacen plegados por eso mismo.
 *
 * ⚠️ Ley L0: son nombres del PRODUCTO, no de Praderas. Cualquier
 * lotificadora ve estos mismos cuatro.
 */
final class Menu
{
    /** Clientes, ventas, recibos, apartados y prospectos: la ventanilla. */
    public const string DIA_A_DIA = 'Día a día';

    /** El proyecto, su plano y sus lotes. Se toca cuando cambia el terreno. */
    public const string DESARROLLO = 'El desarrollo';

    /** Quién entra, qué puede hacer, qué quedó registrado y con qué se factura. */
    public const string ADMINISTRACION = 'Administración';

    /** La instalación: marca, colores y datos de la lotificadora. */
    public const string SISTEMA = 'Sistema';

    /**
     * En el orden en que salen. `AdminPanelProvider` la consume para
     * armar los `NavigationGroup`, así que la lista y las constantes no
     * se pueden desincronizar.
     *
     * @return list<string>
     */
    public static function grupos(): array
    {
        return [self::DIA_A_DIA, self::DESARROLLO, self::ADMINISTRACION, self::SISTEMA];
    }

    /**
     * Los que nacen plegados: existen, pero no compiten con el trabajo del
     * día. Quien los necesita los abre una vez y el navegador se acuerda.
     *
     * @return list<string>
     */
    public static function deFondo(): array
    {
        return [self::ADMINISTRACION, self::SISTEMA];
    }
}
