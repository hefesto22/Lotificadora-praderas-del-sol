<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Trait que llena automáticamente created_by, updated_by y deleted_by
 * con el ID del usuario autenticado al crear/actualizar/eliminar registros.
 *
 * Requisitos en la tabla del modelo:
 *  - foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()
 *  - foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()
 *  - foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete()
 */
trait HasAuditFields
{
    public static function bootHasAuditFields(): void
    {
        static::creating(function (Model $model): void {
            if (Auth::check() && $model->getAttribute('created_by') === null) {
                $model->setAttribute('created_by', Auth::id());
            }
        });

        static::updating(function (Model $model): void {
            if (Auth::check()) {
                $model->setAttribute('updated_by', Auth::id());
            }
        });

        // Solo registramos el listener de delete si el modelo usa SoftDeletes.
        // En modelos sin SoftDeletes el delete es destructivo y no tiene sentido
        // poblar deleted_by porque la fila desaparece.
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::deleting(function (Model $model): void {
                if (! Auth::check()) {
                    return;
                }

                /** @var Model&object{isForceDeleting: callable, saveQuietly: callable} $model */
                if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                    return;
                }

                $model->setAttribute('deleted_by', Auth::id());
                $model->saveQuietly();
            });
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
