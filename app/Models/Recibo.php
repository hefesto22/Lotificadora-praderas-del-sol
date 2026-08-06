<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * El documento que se le entrega al cliente cuando paga.
 *
 * Es interno: no hay CAI (R10). Lo que lo hace serio no es el papel sino su
 * número —uno solo para toda la lotificadora (R12)— y su detalle de
 * aplicación, que dice a qué cuota le tocó cada lempira.
 *
 * ═══ NO SE EDITA ═══
 *
 * Un recibo entregado no se corrige: se anula y se emite otro. Cambiar el
 * monto de uno ya impreso es dejar el papel del cliente diciendo una cosa y la
 * base diciendo otra, que es exactamente el problema que un correlativo viene
 * a evitar.
 */
#[Fillable([
    'numero',
    'tipo_documento',
    'venta_id',
    'compromiso_id',
    'cliente_id',
    'concepto',
    'forma_pago',
    'referencia',
    'monto',
    'fecha',
    'observaciones',
])]
class Recibo extends Model
{
    use HasAuditFields;
    use LogsActivity;

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
            'concepto'   => ConceptoDeRecibo::class,
            'forma_pago' => FormaDePago::class,
            'fecha'      => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['numero', 'concepto', 'forma_pago', 'referencia', 'monto', 'fecha', 'cliente_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Recibo {$evento}");
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
     * El lote al que se le abonó.
     *
     * Con plazos distintos por lote, un pago va contra UNO: el plan de cuotas
     * es del renglón del contrato, no del expediente.
     *
     * @return BelongsTo<Compromiso, $this>
     */
    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * A qué cuotas se repartió, en el orden en que se aplicaron.
     *
     * @return HasMany<AplicacionDePago, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionDePago::class);
    }

    /**
     * Cada vez que este recibo salió impreso, de la más vieja a la más nueva.
     *
     * La primera es el original; de la segunda en adelante el papel lleva la
     * marca COPIA. Dos papeles con el mismo número no pueden hacerse pasar por
     * dos cobros distintos, que es lo que un correlativo viene a evitar.
     *
     * @return HasMany<ImpresionDeRecibo, $this>
     */
    public function impresiones(): HasMany
    {
        return $this->hasMany(ImpresionDeRecibo::class)->oldest();
    }

    /**
     * ¿Ya salió impreso alguna vez?
     */
    public function yaSeImprimio(): bool
    {
        return $this->impresiones()->exists();
    }

    public function vecesImpreso(): int
    {
        return $this->impresiones()->count();
    }

    /**
     * La reprogramación que este abono provocó (R21).
     *
     * Nula en la enorme mayoría de los recibos: solo un abono a capital
     * reescribe un plan. Cuando existe, es lo que contesta «¿por qué después
     * de este pago mi cuota cambió?».
     *
     * @return HasOne<Reprogramacion, $this>
     */
    public function reprogramacion(): HasOne
    {
        return $this->hasOne(Reprogramacion::class);
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    public function montoTotal(): Monto
    {
        $monto = $this->getAttribute('monto');

        return new Monto(is_string($monto) || is_int($monto) ? $monto : '0');
    }

    /**
     * Lo que este recibo aplicó a cuotas.
     */
    public function montoAplicadoACuotas(): Monto
    {
        $total = Monto::cero();

        foreach ($this->aplicaciones as $aplicacion) {
            $total = $total->sumar($aplicacion->montoAplicado());
        }

        return $total;
    }

    /**
     * Lo que este recibo bajó del capital, sin pasar por ninguna cuota (R21).
     *
     * En un cobro normal es cero: todo el dinero se repartió entre cuotas. En
     * un abono a capital es la diferencia, porque el mismo papel puede haber
     * puesto al día lo vencido y bajado el saldo con el sobrante. Los dos
     * renglones tienen que verse impresos, o el cliente no entiende por qué
     * pagó L 100,000.00 y sus cuotas solo bajaron L 50,000.00.
     */
    public function montoACapital(): Monto
    {
        return $this->montoTotal()->restar($this->montoAplicadoACuotas());
    }

    /**
     * El número, como se lee en el papel.
     */
    public function folio(): string
    {
        return str_pad((string) $this->getAttribute('numero'), 6, '0', STR_PAD_LEFT);
    }
}
