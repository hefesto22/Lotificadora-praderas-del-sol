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
use Illuminate\Support\Str;
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
    'latitud',
    'longitud',
    'activo',
    'plano_esquematico',
    'medidas_en_metros',
    'vara_en_metros',
    'slug',
    'plano_publico',
    'whatsapp',
    'servicios',
    'observaciones',
])]
class Proyecto extends Model
{
    use HasAuditFields;

    /** @use HasFactory<ProyectoFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Cuanto del nombre entra en el slug.
     *
     * `proyectos.slug` mide 80; los 11 que sobran son para el guion y el
     * codigo del proyecto, que es lo que desempata cuando dos desarrollos se
     * llaman parecido.
     */
    private const int SLUG_BASE = 69;

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
    #[Override]
    protected $attributes = [
        'plano_esquematico' => false,
        'medidas_en_metros' => false,
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
        /*
         * 🔴 El slug se rellena solo, y por eso el formulario no lo exige.
         *
         * Es la direccion con la que el proyecto vive en internet. Cuando se
         * agrego la columna quedo NOT NULL y el campo del panel `required()`,
         * y eso volteo 418 tests de una sola vez: cada `Proyecto::factory()`
         * del sistema inserta sin slug. La leccion no fue «arreglá los
         * factories» — fue que un dato que se deriva del nombre no hay por
         * que pedirselo a nadie.
         *
         * `saving` y no `creating`: si alguien borra el campo en el panel y
         * guarda, la alternativa seria un 500 contra el CHECK de la base.
         *
         * ⚠️ Solo cuando esta VACIO. Un slug que se recalcula porque alguien
         * corrigio una tilde del nombre rompe todos los links ya mandados por
         * WhatsApp, y nadie relaciona una cosa con la otra.
         */
        static::saving(function (Proyecto $proyecto): void {
            $slug = $proyecto->getAttribute('slug');

            if (is_string($slug) && trim($slug) !== '') {
                return;
            }

            $proyecto->setAttribute('slug', self::slugPara(
                (string) $proyecto->getAttribute('nombre'),
                (string) $proyecto->getAttribute('codigo'),
                $proyecto->exists ? $proyecto->getKey() : null,
            ));
        });

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
     * La direccion libre para un proyecto, sacada de su nombre.
     *
     * `Str::slug()` y no un `strtolower(str_replace(...))`: sabe de tildes y
     * de la ñ. «LA CAÑADA» tiene que dar `la-canada` y no romperse.
     *
     * Cuando la base ya esta tomada desempata con el codigo del proyecto, que
     * es unico — dos desarrollos pueden llamarse parecido. Y si hasta eso
     * choca, numera. El recorte deja lugar al sufijo dentro de los 80 de la
     * columna, y el `trim` saca el guion que el corte pudo dejar colgando: el
     * CHECK de la base no acepta uno al final.
     */
    public static function slugPara(string $nombre, string $codigo, mixed $exceptoId = null): string
    {
        $base = trim(Str::limit(Str::slug($nombre), self::SLUG_BASE, ''), '-');

        if ($base === '') {
            $base = trim(Str::limit(Str::slug($codigo), self::SLUG_BASE, ''), '-');
        }

        if ($base === '') {
            $base = 'proyecto';
        }

        $desempate = trim(Str::slug($codigo), '-');
        $candidato = $base;
        $vuelta = 0;

        while (true) {
            $consulta = self::query()->where('slug', $candidato);

            if ($exceptoId !== null) {
                $consulta->whereKeyNot($exceptoId);
            }

            if (! $consulta->exists()) {
                return $candidato;
            }

            $vuelta++;

            $candidato = $vuelta === 1 && $desempate !== ''
                ? $base.'-'.$desempate
                : $base.'-'.$vuelta;
        }
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
            'medidas_en_metros' => 'boolean',
            'plano_publico'     => 'boolean',
            'servicios'         => 'array',
        ];
    }

    /**
     * Cuánto mide la vara de ESTE proyecto, en metros.
     *
     * `null` en la columna significa «la vara del sistema»: el default
     * sigue viviendo en config/lotificadora.php y no copiado en cada fila
     * (§8.3.7). Un proyecto guarda su propio número solo cuando su
     * topógrafo usa otra vara —la castellana son 0.8359 m, la mexicana
     * 0.8380 y la de Texas 0.8467—.
     *
     * Devuelve string y nunca float: de este número sale cuántas varas²
     * tiene cada lote al importar el plano, y el precio es POR VARA². Es
     * el mismo criterio del §8.3.1 —el área en varas² por el precio por
     * vara² ES dinero— y por eso el valor entra a bcmath como texto.
     */
    public function varaEnMetros(): string
    {
        $propia = $this->getAttribute('vara_en_metros');

        if (is_numeric($propia) && (float) $propia > 0) {
            return number_format((float) $propia, 6, '.', '');
        }

        return (string) config('lotificadora.area.vara_en_metros', '0.8359');
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
            ->logOnly(['nombre', 'codigo', 'activo', 'plano_esquematico', 'medidas_en_metros', 'vara_en_metros'])
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
     * El precio de la vara² a cada plazo (5-ago-2026).
     *
     * No es interes: R1 sigue en pie y el saldo no devenga nada. Es el
     * precio de lista, que a 48 meses no es el mismo que de contado.
     *
     * @return HasMany<PlanDePago, $this>
     */
    public function planesDePago(): HasMany
    {
        return $this->hasMany(PlanDePago::class);
    }

    /**
     * Lo que este desarrollo ha costado (11-ago-2026).
     *
     * Cuelga del proyecto y no del lote porque asi se gasta: la
     * retroexcavadora no entra a un lote, abre la calle de un bloque entero.
     * Repartir ese costo entre los lotes que toca es un prorrateo, y un
     * prorrateo es una decision de contabilidad, no un dato que alguien tenga
     * enfrente al pagar la factura.
     *
     * @return HasMany<Gasto, $this>
     */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
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
