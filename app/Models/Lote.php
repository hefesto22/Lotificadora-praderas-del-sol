<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\LoteInmutableException;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Database\Factories\LoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * La unidad que se vende (§8.2).
 *
 * `valor` se almacena en vez de derivarse en cada lectura porque el estado
 * de cuenta y los reportes lo consultan a diario. Siguiendo el patrón del
 * §8.3.4 para columnas derivadas almacenadas, se recalcula en cada guardado
 * y hay un golden test que lo verifica al céntimo desde cero.
 */
#[Fillable([
    'proyecto_id',
    'bloque_id',
    'numero',
    'area_varas',
    'precio_vara',
    'estado',
    'observaciones',
])]
class Lote extends Model
{
    use HasAuditFields;

    /** @use HasFactory<LoteFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * `area_varas`, `precio_vara` y `valor` NO se castean a decimal.
     *
     * El cast `decimal:x` de Laravel pasa por number_format(), que recibe
     * float. PDO de PostgreSQL ya entrega NUMERIC como string, que es lo
     * que consume bcmath sin pérdida (§8.3.1).
     *
     * `valor` tampoco es fillable: lo calcula el modelo, no el formulario.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'estado' => EstadoLote::class,
        ];
    }

    #[Override]
    protected static function booted(): void
    {
        // El valor y el código SIEMPRE se recalculan: así un seeder, un
        // import o un tinker no pueden guardar un lote con un valor
        // inconsistente ni con un código que ya no corresponde a su bloque.
        static::saving(function (Lote $lote): void {
            $lote->setAttribute('valor', $lote->calcularValor());
            $lote->setAttribute('codigo', $lote->calcularCodigo());
        });

        // §8.2: un lote vendido no se edita en precio ni área. Esta es la
        // segunda de tres capas — el enum lo declara y un trigger de
        // PostgreSQL lo impide aunque alguien escriba por fuera de Eloquent.
        // Acá existe para dar un error del dominio, legible, en vez de un
        // SQLSTATE crudo.
        static::updating(function (Lote $lote): void {
            if ($lote->getRawOriginal('estado') !== EstadoLote::Vendido->value) {
                return;
            }

            if ($lote->isDirty(['area_varas', 'precio_vara', 'valor'])) {
                throw LoteInmutableException::porEstadoVendido(
                    (string) $lote->getAttribute('numero')
                );
            }
        });
    }

    /**
     * Tercera defensa del §10.4. El número admite formatos como "12-A", y
     * "12-a" sería otro lote para el índice único.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function numero(): Attribute
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
            ->logOnly(['numero', 'area_varas', 'precio_vara', 'valor', 'estado'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Lote {$evento}");
    }

    // ─── Código legible ───────────────────────────────────────────────

    /**
     * RPS-B-012 — lo que la gente dice por teléfono y lo que va impreso en
     * el contrato y en el recibo.
     *
     * Se lee del bloque y del proyecto con una consulta fresca, no de la
     * relación cacheada: si alguien mueve el lote de bloque, la relación en
     * memoria todavía apunta al viejo y el código quedaría mintiendo.
     */
    public function calcularCodigo(): string
    {
        $bloque = Bloque::query()
            ->select(['id', 'proyecto_id', 'nombre'])
            ->whereKey($this->getAttribute('bloque_id'))
            ->firstOrFail();

        $proyecto = Proyecto::query()
            ->select(['id', 'codigo'])
            ->whereKey($bloque->getAttribute('proyecto_id'))
            ->firstOrFail();

        return self::componerCodigo(
            (string) $proyecto->getAttribute('codigo'),
            (string) $bloque->getAttribute('nombre'),
            (string) $this->getAttribute('numero')
        );
    }

    /**
     * El número va con relleno a 3 dígitos para que el orden ALFABÉTICO del
     * código sea el orden correcto: RPS-B-002 antes que RPS-B-010. Sin el
     * relleno, el lote 2 aparece después del 19 — que es exactamente el bug
     * que tenía el listado.
     *
     * Los sufijos se conservan: "12-A" produce "012-A".
     */
    public static function componerCodigo(string $proyecto, string $bloque, string $numero): string
    {
        if (preg_match('/^(\d+)(.*)$/', $numero, $partes) === 1) {
            $numero = str_pad($partes[1], 3, '0', STR_PAD_LEFT).$partes[2];
        }

        return "{$proyecto}-{$bloque}-{$numero}";
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    /**
     * valor = area_varas × precio_vara, exacto y redondeado half-up una
     * sola vez al final (§8.3.1).
     */
    public function calcularValor(): string
    {
        return new Monto($this->decimalDe('precio_vara'))
            ->multiplicarPor($this->decimalDe('area_varas'))
            ->redondeado();
    }

    public function montoValor(): Monto
    {
        return new Monto($this->decimalDe('valor'));
    }

    /**
     * Lee un atributo numérico como string apto para bcmath.
     *
     * Rechaza float explícitamente: es la regla del §8.3.1 y falla acá,
     * con un mensaje que explica por qué, en vez de perder un centavo sin
     * que nadie se entere.
     */
    private function decimalDe(string $campo): string
    {
        $valor = $this->getAttribute($campo);

        if (is_string($valor) || is_int($valor)) {
            return (string) $valor;
        }

        throw ValueObjectInvalidoException::paraCampo(
            campo: $campo,
            valor: get_debug_type($valor),
            razon: 'Debe ser string o int. El §8.3.1 prohíbe float en el camino del dinero: '.
                   'asignalo como string, por ejemplo "1350.00" en lugar de 1350.00.'
        );
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return BelongsTo<Bloque, $this>
     */
    public function bloque(): BelongsTo
    {
        return $this->belongsTo(Bloque::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * @param Builder<Lote> $query
     *
     * @return Builder<Lote>
     */
    #[Scope]
    protected function disponibles(Builder $query): Builder
    {
        return $query->where('estado', EstadoLote::Disponible);
    }

    /**
     * Lotes comprometidos con un cliente: apartados o vendidos.
     *
     * @param Builder<Lote> $query
     *
     * @return Builder<Lote>
     */
    #[Scope]
    protected function comprometidos(Builder $query): Builder
    {
        return $query->whereIn('estado', [EstadoLote::Apartado, EstadoLote::Vendido]);
    }

    /**
     * @param Builder<Lote> $query
     *
     * @return Builder<Lote>
     */
    #[Scope]
    protected function delProyecto(Builder $query, Proyecto $proyecto): Builder
    {
        return $query->where('proyecto_id', $proyecto->getKey());
    }
}
