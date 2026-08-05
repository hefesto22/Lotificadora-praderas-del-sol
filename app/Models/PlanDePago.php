<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Database\Factories\PlanDePagoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Cuánto vale la vara² a cada plazo, en este proyecto.
 *
 * No es una tasa de interés y no hay que leerlo como una: el saldo no
 * devenga nada (R1). Es el precio de lista, que a 48 meses no es el mismo
 * que de contado. Elegido el plazo, el precio queda fijo.
 *
 * `meses = 0` es contado, igual que en `ventas.plazo_meses`.
 *
 * Se audita con activitylog a propósito: es el número del que cuelga cada
 * cotización, y cuando alguien pregunte «¿desde cuándo vale esto?» la
 * respuesta tiene que estar en el sistema y no en la memoria de nadie.
 */
#[Fillable([
    'proyecto_id',
    'meses',
    'precio_vara',
    'activo',
    'etiqueta',
])]
#[Table(name: 'planes_de_pago')]
class PlanDePago extends Model
{
    use HasAuditFields;

    /** @use HasFactory<PlanDePagoFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * `precio_vara` NO se castea a decimal: el cast de Laravel pasa por
     * number_format(), que recibe float y reintroduce el error que Monto
     * existe para evitar (§8.3.1). PDO de Postgres ya lo devuelve string.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'meses'  => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['meses', 'precio_vara', 'activo', 'etiqueta'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Plan de pago {$evento}");
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @param Builder<PlanDePago> $query
     *
     * @return Builder<PlanDePago>
     */
    #[Scope]
    protected function activos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function montoPrecioVara(): Monto
    {
        $valor = $this->getAttribute('precio_vara');

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    public function esDeContado(): bool
    {
        return $this->getAttribute('meses') === 0;
    }

    /**
     * Cómo se llama este plan en pantalla.
     *
     * La etiqueta es opcional y sirve para los casos que el número no
     * explica: «12 meses (promoción de feria)».
     */
    public function nombre(): string
    {
        $etiqueta = $this->getAttribute('etiqueta');

        if (is_string($etiqueta) && $etiqueta !== '') {
            return $etiqueta;
        }

        return $this->esDeContado() ? 'Contado' : $this->getAttribute('meses').' meses';
    }
}
