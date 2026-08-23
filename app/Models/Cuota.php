<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\ValueObjects\Monto;
use Database\Factories\CuotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * Una cuota del plan congelado.
 *
 * ═══ NO HAY COLUMNA `estado`, Y ES A PROPOSITO ═══
 *
 * `pagada` y `vencida` se calculan aca, en PHP, a partir de dos datos que
 * no mienten: `monto_pagado` y la fecha. Guardarlos obligaria a una tarea
 * nocturna que los recalcule, y esa tarea falla justo el dia que el cliente
 * llega a pagar y el sistema le dice que no debe nada (§9.D5).
 *
 * ═══ LA CUOTA VIENE PARTIDA, DESDE EL 8-AGO-2026 ═══
 *
 * `monto` es lo que el cliente paga ese mes y sigue siendo el numero que
 * manda. `monto_capital` y `monto_interes` son en que se descompone, y suman
 * exacto —lo exige el CHECK `cuotas_partes_suman_el_monto_chk`—.
 *
 * Con tasa 0 (R1, Praderas del Sol) el capital es la cuota entera y el
 * interes es cero, que es lo que el sistema hacia antes de que estas dos
 * columnas existieran.
 *
 * ═══ DENTRO DE UNA CUOTA SE PAGA INTERES PRIMERO ═══
 *
 * `monto_pagado` sigue siendo UN numero, y de el se derivan las dos partes:
 * lo primero que cubre un pago parcial es el interes y recien despues el
 * capital. Esa es la imputacion estandar y es la que decide si un cliente
 * sale de la deuda o no sale nunca.
 *
 * No hacen falta columnas nuevas para eso: con `monto_interes` y
 * `monto_pagado` alcanza, y un dato derivado que no se guarda es un dato que
 * no se puede desincronizar.
 *
 * ═══ LA MORA NO ES PARTE DEL MONTO ═══
 *
 * `mora_pagada` y `mora_condonada` NO entran en `monto` ni en `monto_pagado`:
 * la mora es un derivado del tiempo, se calcula al vuelo (`CalculoDeMora`) y
 * se congela en el recibo. Lo unico que se guarda acá es cuanta ya se
 * resolvio, para no cobrarla dos veces cuando el cliente sigue atrasado.
 *
 * Y de ahi sale gratis la regla de que **la mora no genera mora**: la base
 * del calculo es `saldo()`, que nunca la incluye.
 *
 * ═══ NO SE EDITA ═══
 *
 * El plan es un snapshot inmutable (§9.D6). `monto_pagado` lo mueve el
 * Service de pagos al aplicar un abono, dentro de su transaccion; el monto
 * y la fecha de vencimiento solo cambian con una reprogramacion explicita,
 * auditada y con motivo.
 */
#[Fillable([
    'venta_id',
    'compromiso_id',
    'numero',
    'fecha_vencimiento',
    'monto',
    'monto_capital',
    'monto_interes',
    'monto_pagado',
    'capital_condonado',
    'mora_pagada',
    'mora_condonada',
])]
class Cuota extends Model
{
    /** @use HasFactory<CuotaFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'monto_pagado'      => '0.00',
        'capital_condonado' => '0.00',
        'monto_interes'     => '0.00',
        'mora_pagada'       => '0.00',
        'mora_condonada'    => '0.00',
    ];

    /**
     * Sin cast `decimal:x` en los montos: pasa por `number_format()`, que
     * recibe float (§8.3.1).
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
        ];
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * Los pagos que se le aplicaron, INCLUIDOS los de recibos anulados.
     *
     * 🔴 Anular un recibo devuelve `monto_pagado` a cero pero **no borra la
     * aplicación**: la fila queda como historia y su FK a `cuotas` es
     * `restrictOnDelete`. Por eso «monto_pagado = 0» NO significa «a esta
     * cuota nunca la tocó nadie», y quien vaya a borrar cuotas tiene que
     * preguntar por acá. Ver `RegistroDeRescisiones::soltarLasCuotas()`.
     *
     * @return HasMany<AplicacionDePago, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionDePago::class);
    }

    /**
     * De que lote es esta cuota.
     *
     * Con plazos distintos por lote el plan dejo de ser del contrato: el
     * lote a 12 meses termina de pagarse mientras el de 48 sigue vivo, y lo
     * que el cliente paga cada mes es la suma de las cuotas vivas.
     *
     * Null es el plan viejo, de un contrato entero. No hay ninguno todavia,
     * pero una venta historica cargada en papel (R15) va a tenerlo.
     *
     * @return BelongsTo<Compromiso, $this>
     */
    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    public function montoTotal(): Monto
    {
        return $this->montoDe('monto');
    }

    public function montoPagado(): Monto
    {
        return $this->montoDe('monto_pagado');
    }

    /**
     * La parte de la cuota que baja la deuda.
     */
    public function montoCapital(): Monto
    {
        $capital = $this->getAttribute('monto_capital');

        // Sin la columna cargada —una cuota recien construida en memoria— la
        // verdad de un plan sin interes es que todo el monto es capital.
        return is_string($capital) || is_int($capital)
            ? new Monto($capital)
            : $this->montoTotal();
    }

    /**
     * La parte de la cuota que paga el interes del mes. Cero con R1.
     */
    public function montoInteres(): Monto
    {
        return $this->montoDe('monto_interes');
    }

    /**
     * Lo que falta para darla por pagada. NO incluye mora.
     */
    public function saldo(): Monto
    {
        return $this->montoTotal()->restar($this->montoPagado());
    }

    /**
     * Cuánto de lo «pagado» de esta cuota fue perdonado — 23-ago-2026.
     *
     * ═══ 🔴 ESTA ADENTRO DE `monto_pagado`, NO AL LADO ═══
     *
     * Y no es un descuido de nombre: catorce lugares del repo calculan lo que
     * falta pagar con SQL crudo (`SUM(monto - monto_pagado)`,
     * `monto_pagado < monto`) —el saldo del expediente, la columna «Saldo»,
     * el contador de vencidos, el Escritorio, el plano—. Restar el perdón por
     * fuera dejaría a esos catorce diciendo que un lote saldado todavía debe.
     *
     * Así que `monto_pagado` es **lo que resuelve la cuota** y esto dice qué
     * parte de eso no fue dinero. Un CHECK de la base garantiza que nunca
     * supere a lo pagado.
     *
     * ⚠️ Es lo contrario de `mora_condonada`, que va por FUERA — porque la
     * mora también vive fuera de `monto` y ningún SQL la resta. Dos ejes
     * distintos, no dos criterios.
     */
    public function capitalCondonado(): Monto
    {
        return $this->montoDe('capital_condonado');
    }

    /**
     * Lo que de verdad entró por la puerta por esta cuota.
     */
    public function pagadoEnDinero(): Monto
    {
        return $this->montoPagado()->restar($this->capitalCondonado());
    }

    // ─── El reparto adentro de la cuota: interes primero ──────────────

    /**
     * Cuanto del interes de esta cuota ya se cubrio.
     *
     * Derivado, no guardado: el pago cubre interes primero, asi que lo pagado
     * es interes hasta que el interes se acaba.
     */
    public function interesPagado(): Monto
    {
        $pagado = $this->montoPagado();
        $interes = $this->montoInteres();

        return $pagado->mayorQue($interes) ? $interes : $pagado;
    }

    public function interesPendiente(): Monto
    {
        return $this->montoInteres()->restar($this->interesPagado());
    }

    public function capitalPagado(): Monto
    {
        return $this->montoPagado()->restar($this->interesPagado());
    }

    /**
     * Lo que esta cuota todavia le debe al capital.
     *
     * Es el numero que se reamortiza en un abono a capital (R21): reprogramar
     * sobre `saldo()` cobraria interes sobre el interes del plan viejo.
     */
    public function capitalPendiente(): Monto
    {
        return $this->montoCapital()->restar($this->capitalPagado());
    }

    // ─── Mora ─────────────────────────────────────────────────────────

    public function moraPagada(): Monto
    {
        return $this->montoDe('mora_pagada');
    }

    public function moraCondonada(): Monto
    {
        return $this->montoDe('mora_condonada');
    }

    /**
     * La mora de esta cuota que ya no hay que volver a cobrar.
     */
    public function moraResuelta(): Monto
    {
        return $this->moraPagada()->sumar($this->moraCondonada());
    }

    // ─── Estado derivado ──────────────────────────────────────────────

    public function estaPagada(): bool
    {
        return $this->saldo()->esCero();
    }

    /**
     * ¿Se le paso la fecha y todavia debe algo?
     *
     * Una cuota pagada nunca esta vencida, por vieja que sea.
     */
    public function estaVencida(): bool
    {
        $vence = $this->getAttribute('fecha_vencimiento');

        return ! $this->estaPagada()
            && $vence !== null
            && $vence->isBefore(today());
    }

    /**
     * Dias corridos desde el vencimiento. Cero si no esta vencida.
     *
     * Sigue siendo informacion —quien esta atrasado, para llamarlo— y no la
     * base de ningun cobro: quien calcula la mora es `CalculoDeMora`, con las
     * condiciones congeladas del compromiso y los dias de gracia que
     * correspondan.
     */
    public function diasDeAtraso(): int
    {
        if (! $this->estaVencida()) {
            return 0;
        }

        $vence = $this->getAttribute('fecha_vencimiento');

        return (int) $vence->diffInDays(today());
    }

    public function llevaInteres(): bool
    {
        return ! $this->montoInteres()->esCero();
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * Las que todavia deben algo. Usa el indice parcial de la migracion.
     *
     * @param Builder<Cuota> $query
     *
     * @return Builder<Cuota>
     */
    #[Scope]
    protected function pendientes(Builder $query): Builder
    {
        return $query->whereColumn('monto_pagado', '<', 'monto');
    }

    /**
     * Solo las cuotas de lotes que siguen siendo del cliente.
     *
     * ═══ POR QUE HACE FALTA ═══
     *
     * Al rescindir un lote (R22) sus cuotas pendientes se borran, **pero no
     * todas pueden borrarse**: la que se pago a medias y la que tuvo un pago
     * anulado se quedan, porque tienen aplicaciones colgando de un recibo que
     * el cliente guardo. Esas cuotas conservan saldo, y sin este filtro el
     * expediente seguiria diciendo que el cliente debe por un lote que ya no
     * es suyo.
     *
     * No se borra ni se reescribe nada: la cuota sigue ahi con su historia
     * intacta. Lo que cambia es que **deja de contar como deuda**.
     *
     * ⚠️ Va en las sumas de DINERO, no en las de historia: el recibo impreso
     * y las aplicaciones de pago siguen viendo todo.
     *
     * @param Builder<Cuota> $query
     *
     * @return Builder<Cuota>
     */
    #[Scope]
    protected function deLotesVivos(Builder $query): Builder
    {
        return $query->whereDoesntHave('compromiso', static function (Builder $compromiso): void {
            $compromiso->where('estado', EstadoCompromiso::Rescindido->value);
        });
    }

    /**
     * Las vencidas al dia de hoy.
     *
     * `today()` lo genera PHP, nunca Postgres: el servidor puede estar en
     * UTC y el corte saldria corrido seis horas (§7.5.1).
     *
     * @param Builder<Cuota> $query
     *
     * @return Builder<Cuota>
     */
    #[Scope]
    protected function vencidas(Builder $query): Builder
    {
        return $query->pendientes()->where('fecha_vencimiento', '<', today()->toDateString());
    }

    // ─── Interno ──────────────────────────────────────────────────────

    private function montoDe(string $columna): Monto
    {
        $valor = $this->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }
}
