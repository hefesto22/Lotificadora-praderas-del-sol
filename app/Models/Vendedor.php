<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Database\Factories\VendedorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Quien cierra la venta cuando no la cierra la lotificadora.
 *
 * Existe porque el cuaderno de la cartera vieja anota «Vendido por» y ese
 * dato no tenia donde vivir: seis expedientes, cuatro grafias del mismo
 * nombre y L 4,350,000.00 en contratos sin poder decir de quien son.
 *
 * ⚠️ NO calcula comisiones ni sabe cuanto se le debe a nadie. Guarda quien
 * vendio, que es lo que se estaba perdiendo. Ver la migracion.
 */
#[Fillable([
    'nombre',
    'dni',
    'telefono',
    'activo',
    'observaciones',
])]
#[Table(name: 'vendedores')]
class Vendedor extends Model
{
    use HasAuditFields;

    /** @use HasFactory<VendedorFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * El DNI y el telefono son PII y no entran a la bitacora, igual que en
     * `Cliente` (§13.5). Lo que importa auditar es quien tocó la ficha.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'activo'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Vendedor {$evento}");
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * @return HasMany<Venta, $this>
     */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    // ─── Consultas ────────────────────────────────────────────────────

    /**
     * Los que siguen vendiendo. Un vendedor inactivo no se borra —sus
     * contratos siguen siendo suyos— pero deja de aparecer al elegir.
     *
     * @param Builder<Vendedor> $query
     *
     * @return Builder<Vendedor>
     */
    #[Scope]
    protected function activos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
