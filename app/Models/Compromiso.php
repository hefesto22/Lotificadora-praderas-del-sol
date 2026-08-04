<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Database\Factories\CompromisoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * El respaldo de un lote comprometido: quien, cuando y por cuanto.
 *
 * `area_varas`, `precio_vara` y `valor` son COPIAS CONGELADAS del lote al
 * momento del compromiso, no una referencia. Es lo que pide el §8.2: si
 * manana sube el precio por vara del proyecto, la venta cerrada conserva
 * el suyo y el estado de cuenta del cliente sigue cuadrando.
 *
 * Un lote puede tener muchos compromisos a lo largo del tiempo, pero uno
 * solo VIGENTE. Eso no se valida acá: lo garantiza un indice unico parcial
 * en la base, que ni un import ni dos pestañas abiertas pueden saltear.
 */
#[Fillable([
    'proyecto_id',
    'lote_id',
    'cliente_id',
    'tipo',
    'estado',
    'area_varas',
    'precio_vara',
    'valor',
    'monto_senia',
    'fecha',
    'vence_el',
    'cerrado_el',
    'motivo',
    'observaciones',
])]
class Compromiso extends Model
{
    use HasAuditFields;

    /** @use HasFactory<CompromisoFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Los montos NO se castean a decimal, por la misma razon que en Lote:
     * el cast de Laravel pasa por number_format(), que recibe float. PDO
     * de PostgreSQL ya devuelve NUMERIC como string, que es lo que consume
     * bcmath sin perder un centavo (§8.3.1).
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'tipo'       => TipoCompromiso::class,
            'estado'     => EstadoCompromiso::class,
            'fecha'      => 'date',
            'vence_el'   => 'date',
            'cerrado_el' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'estado', 'cliente_id', 'valor', 'monto_senia', 'vence_el', 'motivo'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Compromiso {$evento}");
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
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    public function montoValor(): Monto
    {
        $valor = $this->getAttribute('valor');

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    // ─── Estado ───────────────────────────────────────────────────────

    public function estaVigente(): bool
    {
        $estado = $this->getAttribute('estado');

        return $estado instanceof EstadoCompromiso && $estado->ocupaElLote();
    }

    /**
     * ¿Se le paso la fecha a un apartado que sigue vigente?
     *
     * Devuelve false para los compromisos sin vencimiento y para los ya
     * cerrados: un apartado liberado la semana pasada no esta "vencido",
     * esta terminado.
     */
    public function estaVencido(): bool
    {
        $vence = $this->getAttribute('vence_el');

        return $this->estaVigente()
            && $vence !== null
            && $vence->isBefore(today());
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * @param Builder<Compromiso> $query
     *
     * @return Builder<Compromiso>
     */
    #[Scope]
    protected function vigentes(Builder $query): Builder
    {
        return $query->where('estado', EstadoCompromiso::Vigente);
    }

    /**
     * @param Builder<Compromiso> $query
     *
     * @return Builder<Compromiso>
     */
    #[Scope]
    protected function delProyecto(Builder $query, Proyecto $proyecto): Builder
    {
        return $query->where('proyecto_id', $proyecto->getKey());
    }
}
