<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\ProyectoConMovimientoException;
use App\Traits\HasAuditFields;
use Database\Factories\ProyectoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
     * Borrar un proyecto se lleva TODO lo que cuelga de el.
     *
     * Las FK son restrictOnDelete a proposito: la base no borra en
     * cascada sola, porque un `delete` distraido sobre un proyecto con
     * 300 lotes no deberia ser silencioso. Pero el boton de Filament, un
     * tinker y `artisan proyecto:eliminar` tienen que comportarse igual,
     * asi que la cascada -y sobre todo la regla que la frena- viven aca y
     * no en cada llamador.
     *
     * La regla: si un solo lote dejo de estar DISPONIBLE, no se borra. Es
     * la misma que usa PlanoRealPraderasSeeder para no pisar geometria
     * (§8.2). Un proyecto de prueba se tira sin drama; uno donde alguien
     * ya aparto o compro tiene un cliente y un recibo detras.
     */
    #[Override]
    protected static function booted(): void
    {
        static::deleting(function (Proyecto $proyecto): void {
            $ocupados = $proyecto->lotesConMovimiento();

            if ($ocupados > 0) {
                throw ProyectoConMovimientoException::porLotesNoDisponibles(
                    (string) $proyecto->getAttribute('codigo'),
                    $ocupados,
                );
            }

            DB::transaction(function () use ($proyecto): void {
                $id = $proyecto->getKey();

                // En este orden: los compromisos cuelgan de los lotes y
                // los lotes de los bloques. Al reves, la FK
                // restrictOnDelete corta el borrado por la mitad.
                Compromiso::query()->where('proyecto_id', $id)->delete();
                Lote::query()->where('proyecto_id', $id)->delete();
                Bloque::query()->where('proyecto_id', $id)->delete();
                Calle::query()->where('proyecto_id', $id)->delete();
            });
        });
    }

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
     *
     * Los espacios de más se colapsan además del cambio de caja: "Praderas
     *  del  Sol" y "Praderas del Sol" son el mismo proyecto, y el índice
     * único no los distingue.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function nombre(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($valor)), 'UTF-8')
                : null,
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function municipio(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? mb_strtoupper(trim($valor), 'UTF-8')
                : null,
        );
    }

    /**
     * El código es el prefijo de los correlativos de contrato
     * (RPS-2026-0065): una minúscula suelta produciría dos series
     * distintas.
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
     * Cuantos lotes del proyecto dejaron de estar DISPONIBLES.
     *
     * Es la pregunta que frena el borrado, expuesta para que quien vaya a
     * borrar pueda hacerla ANTES en vez de provocar la excepcion y
     * atajarla. La excepcion sigue existiendo como ultima linea: por el
     * boton de Filament, por tinker, por lo que venga.
     */
    public function lotesConMovimiento(): int
    {
        return Lote::query()
            ->where('proyecto_id', $this->getKey())
            ->where('estado', '!=', EstadoLote::Disponible->value)
            ->count();
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
