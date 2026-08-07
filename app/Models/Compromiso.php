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
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * Los precios son DOS: `precio_vara_lista` es lo que el lote valia ese dia
 * y `precio_vara` es lo que se firmo. En un apartado coinciden; en una
 * venta pueden no coincidir, porque se negocia caso por caso (R4), y ahi
 * `motivo_descuento` es obligatorio — lo exige un CHECK de la base.
 *
 * Un lote puede tener muchos compromisos a lo largo del tiempo, pero uno
 * solo VIGENTE. Eso no se valida acá: lo garantiza un indice unico parcial
 * en la base, que ni un import ni dos pestañas abiertas pueden saltear.
 */
#[Fillable([
    'proyecto_id',
    'lote_id',
    'cliente_id',
    'venta_id',
    'tipo',
    'estado',
    'area_varas',
    'precio_vara',
    'precio_vara_lista',
    'valor',
    'plazo_meses',
    'prima',
    'motivo_descuento',
    'monto_senia',
    'fecha',
    'vence_el',
    'prorrogas',
    'senia_devuelta_el',
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
            'tipo'              => TipoCompromiso::class,
            'estado'            => EstadoCompromiso::class,
            'fecha'             => 'date',
            'vence_el'          => 'date',
            'cerrado_el'        => 'date',
            'senia_devuelta_el' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'estado', 'cliente_id', 'valor', 'monto_senia', 'vence_el', 'prorrogas', 'senia_devuelta_el', 'motivo'])
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

    /**
     * El expediente al que pertenece este lote, si tiene uno.
     *
     * Es nula en dos casos legitimos: los apartados —que no pertenecen a
     * ninguna venta y nunca pueden hacerlo, hay un CHECK— y los lotes que
     * ya estaban vendidos antes de que existiera el sistema, cargados con
     * dueno y valor pero sin expediente (R15).
     *
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    /**
     * El plan de cuotas DE ESTE LOTE, en orden.
     *
     * Vacio en un apartado y en una venta de contado. Ordenado por numero
     * porque asi se lee un estado de cuenta y asi se aplica un pago.
     *
     * @return HasMany<Cuota, $this>
     */
    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class)->orderBy('numero');
    }

    /**
     * Las reprogramaciones de ESTE lote (R21).
     *
     * El plan de `cuotas()` es el de hoy; esto es por qué es ese y no el que
     * se firmó. Un abono a capital borra las cuotas pendientes y escribe
     * otras, así que sin esta relación el cambio no tendría explicación.
     *
     * @return HasMany<Reprogramacion, $this>
     */
    public function reprogramaciones(): HasMany
    {
        return $this->hasMany(Reprogramacion::class)->latest();
    }

    /**
     * Los recibos de ESTE lote: la seña del apartado y despues cada pago.
     *
     * Cuelgan del compromiso y no de la venta porque el plan de cuotas es del
     * renglon: un pago va contra un lote. La seña tambien esta aca — se emitio
     * antes de que existiera el expediente y sigue perteneciendo al apartado
     * que la genero, aunque despues se le agregue el `venta_id`.
     *
     * Ordenados por numero y no por fecha: el correlativo (R12) ya es
     * cronologico y no se repite, mientras que dos recibos del mismo dia
     * quedarian en cualquier orden.
     *
     * @return HasMany<Recibo, $this>
     */
    public function recibos(): HasMany
    {
        return $this->hasMany(Recibo::class)->orderBy('numero');
    }

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

    /**
     * Cuantos dias faltan para que se venza. Negativo si ya paso.
     *
     * Null cuando no hay fecha —una venta, o un apartado historico cargado
     * sin vencimiento— porque «faltan 0 dias» y «no vence» son cosas
     * distintas y la pantalla las pinta distinto.
     */
    public function diasParaVencer(): ?int
    {
        $vence = $this->getAttribute('vence_el');

        if ($vence === null) {
            return null;
        }

        return (int) today()->diffInDays($vence, false);
    }

    /**
     * ¿Le queda prorroga? R14: una sola, y la autoriza la administracion.
     *
     * Solo los apartados vigentes: una venta no vence, y un apartado ya
     * liberado o convertido no se estira hacia atras.
     */
    public function puedeProrrogarse(): bool
    {
        $tipo = $this->getAttribute('tipo');

        if (! $tipo instanceof TipoCompromiso || $tipo !== TipoCompromiso::Apartado) {
            return false;
        }

        return $this->estaVigente() && $this->prorrogasUsadas() < self::prorrogasMaximas();
    }

    public function prorrogasUsadas(): int
    {
        return (int) $this->getAttribute('prorrogas');
    }

    /**
     * La seña que quedo por devolverle a esta persona, si quedo alguna.
     *
     * Es lo que R14 promete cuando el apartado se cae: la plata vuelve. No
     * hay modulo de egresos todavia, asi que esto es lo que alimenta el
     * aviso y la lista de pendientes — y `senia_devuelta_el` es lo que la
     * saca de esa lista.
     */
    public function seniaPorDevolver(): ?Monto
    {
        $estado = $this->getAttribute('estado');

        if (! $estado instanceof EstadoCompromiso || $estado !== EstadoCompromiso::Liberado) {
            return null;
        }

        if ($this->getAttribute('senia_devuelta_el') !== null) {
            return null;
        }

        $senia = $this->getAttribute('monto_senia');

        if (! is_string($senia) && ! is_int($senia)) {
            return null;
        }

        $monto = new Monto($senia);

        return $monto->esCero() ? null : $monto;
    }

    /**
     * El tope de R14, de la config y no de una constante.
     *
     * El monto, los dias de vigencia, los de prorroga y este numero los fijo
     * la contratante y se cambian juntos. Por eso ninguno esta clavado en un
     * CHECK ni en el codigo.
     */
    public static function prorrogasMaximas(): int
    {
        $maximas = config('lotificadora.apartados.prorrogas_maximas', 1);

        return is_int($maximas) && $maximas >= 0 ? $maximas : 1;
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

    /**
     * @param Builder<Compromiso> $query
     *
     * @return Builder<Compromiso>
     */
    #[Scope]
    protected function apartados(Builder $query): Builder
    {
        return $query->where('tipo', TipoCompromiso::Apartado);
    }

    /**
     * Los apartados a los que ya se les paso la fecha y siguen ocupando el
     * lote. Es la pregunta del lunes por la mañana.
     *
     * @param Builder<Compromiso> $query
     *
     * @return Builder<Compromiso>
     */
    #[Scope]
    protected function vencidos(Builder $query): Builder
    {
        return $query->apartados()
            ->vigentes()
            ->whereNotNull('vence_el')
            ->whereDate('vence_el', '<', today());
    }

    /**
     * Los que vencen de hoy en adelante dentro de N dias — los que todavia
     * se pueden salvar con una llamada.
     *
     * @param Builder<Compromiso> $query
     *
     * @return Builder<Compromiso>
     */
    #[Scope]
    protected function porVencer(Builder $query, int $dias = 3): Builder
    {
        return $query->apartados()
            ->vigentes()
            ->whereNotNull('vence_el')
            ->whereDate('vence_el', '>=', today())
            ->whereDate('vence_el', '<=', today()->addDays($dias));
    }

    /**
     * Apartados que se cayeron y todavia le deben plata a alguien (R14).
     *
     * @param Builder<Compromiso> $query
     *
     * @return Builder<Compromiso>
     */
    #[Scope]
    protected function conSeniaPorDevolver(Builder $query): Builder
    {
        return $query->apartados()
            ->where('estado', EstadoCompromiso::Liberado)
            ->whereNotNull('monto_senia')
            ->where('monto_senia', '>', 0)
            ->whereNull('senia_devuelta_el');
    }
}
