<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\UnidadDeArea;
use App\Traits\HasAuditFields;
use Database\Factories\BloqueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Agrupador de lotes dentro de un proyecto.
 *
 * `area_total_varas` y `lotes_planificados` son datos DECLARADOS del
 * plano, no un caché de lo que hay cargado. Ver lotesRegistrados().
 */
#[Fillable([
    'proyecto_id',
    'nombre',
    'area_total_varas',
    'lotes_planificados',
    'orden',
    'observaciones',
])]
class Bloque extends Model
{
    use HasAuditFields;

    /** @use HasFactory<BloqueFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * `area_total_varas` NO se castea a decimal a propósito.
     *
     * El cast `decimal:x` de Laravel usa number_format(), que recibe float
     * y reintroduciría por la puerta de atrás el error que Monto existe
     * para evitar (§8.3.1). PDO de PostgreSQL ya devuelve NUMERIC como
     * string, que es exactamente lo que necesita bcmath.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'lotes_planificados' => 'integer',
            'orden'              => 'integer',
        ];
    }

    /**
     * Tercera defensa del §10.4. El nombre del bloque es una letra o un
     * código corto del plano: "a" y "A" serían dos bloques distintos para
     * el índice único (proyecto_id, nombre).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function nombre(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? mb_strtoupper($valor, 'UTF-8')
                : null,
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'area_total_varas', 'lotes_planificados'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Bloque {$evento}");
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return HasMany<Lote, $this>
     */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /**
     * La unidad de área del proyecto de este bloque.
     *
     * Va por la RELACIÓN, no por una consulta fresca como
     * calcularCodigo(): las tablas la piden una vez por fila y sin
     * `with('proyecto')` esto es el N+1 del §4.L4. Los listados que la
     * usan cargan la relación; el `?? UnidadDeArea::Varas` es para el
     * modelo suelto de un test, no una excusa para no cargarla.
     */
    public function unidadDeArea(): UnidadDeArea
    {
        $proyecto = $this->proyecto;

        return $proyecto instanceof Proyecto ? $proyecto->unidadDeArea() : UnidadDeArea::Varas;
    }

    /**
     * Cantidad REAL de lotes cargados, contra `lotes_planificados`, que es
     * lo que dice el plano.
     *
     * Para listados usar withCount('lotes'): llamar a esto por fila es un
     * N+1 de manual (§4.L4).
     */
    public function lotesRegistrados(): int
    {
        return $this->lotes()->count();
    }

    /**
     * ¿Faltan lotes por cargar respecto de lo que declara el plano?
     */
    public function tieneLotesPendientesDeCargar(): bool
    {
        $planificados = $this->getAttribute('lotes_planificados');

        if (! is_int($planificados)) {
            return false;
        }

        return $this->lotesRegistrados() < $planificados;
    }
}
