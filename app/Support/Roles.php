<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lista única de roles del sistema (§9.E.5).
 *
 * `User::canAccessPanel()` valida contra OPERATIVOS, nunca contra una
 * lista escrita a mano en el modelo: cuando se agregue un rol nuevo hay
 * un solo lugar donde tocarlo, y el seeder de roles —que es la matriz de
 * verdad del §9.E.7— usa la misma constante.
 *
 * Los nombres de super_admin y panel_user los define Shield en
 * config/filament-shield.php; se replican acá para tener la lista
 * completa en un solo lugar, y hay un test que verifica que no se
 * desincronicen.
 */
final class Roles
{
    /** Acceso total. Shield le sincroniza todos los permisos generados. */
    public const string SUPER_ADMIN = 'super_admin';

    /** Acceso al panel sin permisos de Resource. Base de los demás. */
    public const string PANEL_USER = 'panel_user';

    /**
     * Rosa Elena: administra el residencial completo.
     *
     * Ve y opera todo el negocio, pero NO es super_admin: no administra
     * usuarios del sistema ni configuración técnica.
     */
    public const string ADMINISTRADORA = 'administradora';

    /**
     * Quien cobra en ventanilla (§13.1).
     *
     * Registra pagos y emite recibos. NO anula, NO edita ventas, NO ve
     * gastos y NO ve el arqueo de otro receptor.
     */
    public const string RECEPTOR = 'receptor';

    /**
     * Roles con acceso al panel.
     *
     * @return list<string>
     */
    public static function operativos(): array
    {
        return [
            self::SUPER_ADMIN,
            self::PANEL_USER,
            self::ADMINISTRADORA,
            self::RECEPTOR,
        ];
    }

    /**
     * Roles del negocio, sin los técnicos de Shield.
     *
     * @return list<string>
     */
    public static function delNegocio(): array
    {
        return [
            self::ADMINISTRADORA,
            self::RECEPTOR,
        ];
    }
}
