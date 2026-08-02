<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Trait para modelos multi-tenant por empresa.
 *
 * Aplica un global scope que filtra automáticamente por
 * empresa_id del usuario autenticado y, en `creating`, llena
 * el campo si está vacío.
 *
 * NO se activa por defecto en la plantilla — actívalo manualmente
 * en cada modelo que requiera multi-tenant:
 *
 *   class Factura extends Model
 *   {
 *       use BelongsToEmpresa;
 *   }
 *
 * Requisitos:
 *  - Tabla `empresas` con id
 *  - Modelo App\Models\Empresa
 *  - Columna `empresa_id` (foreignId, indexada) en la tabla del modelo
 *  - Columna `empresa_id` en `users` (nullable solo para super_admin)
 */
trait BelongsToEmpresa
{
    public static function bootBelongsToEmpresa(): void
    {
        static::addGlobalScope('empresa', function (Builder $builder): void {
            $user = Auth::user();

            if ($user === null || ! isset($user->empresa_id) || $user->empresa_id === null) {
                return;
            }

            $builder->where(
                $builder->getModel()->getTable().'.empresa_id',
                $user->empresa_id
            );
        });

        static::creating(function ($model): void {
            if (! empty($model->empresa_id)) {
                return;
            }

            $user = Auth::user();

            if ($user !== null && isset($user->empresa_id)) {
                $model->empresa_id = $user->empresa_id;
            }
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
