<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon Service Provider.
 *
 * Registra el gate `viewHorizon` que controla quién puede acceder al
 * dashboard /horizon en TODOS los entornos (incluido producción).
 *
 * Sin este gate, /horizon es accesible públicamente — riesgo de seguridad
 * crítico (§15.1).
 *
 * Política: solo super_admin ve y opera Horizon.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Notificaciones de eventos críticos de Horizon.
        // Configurar ALERT_EMAIL en .env para recibir alertas.
        // Horizon::routeMailNotificationsTo(env('ALERT_EMAIL'));
        // Horizon::routeSlackNotificationsTo(env('SLACK_WEBHOOK'), '#alertas');
    }

    /**
     * Define el Gate de autorización para acceder al dashboard.
     *
     * Filament Shield no protege rutas externas a Filament — Horizon vive
     * fuera del panel, por eso necesita su propio gate.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            // En entornos no productivos, super_admin Y panel_user pueden ver.
            // En producción, SOLO super_admin.
            if ($user === null) {
                return false;
            }

            if (app()->environment('production')) {
                return $user->hasRole(ShieldUtils::getSuperAdminName());
            }

            return $user->hasRole(ShieldUtils::getSuperAdminName())
                || $user->hasRole(ShieldUtils::getPanelUserRoleName());
        });
    }
}
