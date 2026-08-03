<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Database\Factories\ProyectoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Desarrollo inmobiliario. Raíz de la jerarquía proyectos → bloques → lotes
 * (ADR-0002).
 *
 * `codigo` es el prefijo de los correlativos de contrato: RPS-2026-0065.
 */
#[Fillable([
    'nombre',
    'codigo',
    'municipio',
    'departamento',
    'direccion',
    'activo',
    'plano_esquematico',
    'observaciones',
])]
class Proyecto extends Model
{
    use HasAuditFields;

    /** @use HasFactory<ProyectoFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Valor inicial de `plano_esquematico` en memoria, no solo en la base.
     *
     * Sin esto, un modelo recien creado NO tiene el atributo cargado: al
     * leerlo, el cast a boolean convierte el null ausente en false, y
     * spatie/activitylog compara null contra false y concluye que cambio.
     * Resultado: cada update del proyecto registraba una modificacion
     * fantasma de esta columna, y `dontLogEmptyChanges` dejaba de servir
     * porque siempre habia "algo" que loguear.
     *
     * El default de la migracion arregla la base; este arregla PHP. Los
     * dos tienen que existir y decir lo mismo.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'plano_esquematico' => false,
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activo'            => 'boolean',
            'plano_esquematico' => 'boolean',
        ];
    }

    /**
     * Tercera defensa del §10.4: aunque el valor entre por un seeder, un
     * import o tinker —sin pasar por el formulario— queda en mayúsculas.
     * El código es el prefijo de los correlativos de contrato (RPS-2026-0065)
     * y una minúscula suelta produciría dos series distintas.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function codigo(): Attribute
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
            ->logOnly(['nombre', 'codigo', 'activo', 'plano_esquematico'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Proyecto {$evento}");
    }

    /**
     * @return HasMany<Bloque, $this>
     */
    public function bloques(): HasMany
    {
        return $this->hasMany(Bloque::class);
    }

    /**
     * Lotes del proyecto, sin pasar por bloques.
     *
     * `proyecto_id` está denormalizado en `lotes` a propósito (ADR-0002):
     * los reportes filtran por proyecto en cada consulta y hacerlo vía
     * bloques obligaría a un join en todas.
     *
     * @return HasMany<Lote, $this>
     */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /**
     * @param Builder<Proyecto> $query
     *
     * @return Builder<Proyecto>
     */
    #[Scope]
    protected function activos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
