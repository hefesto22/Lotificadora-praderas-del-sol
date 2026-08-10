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
    'monto_mora',
    'mora_condonada',
    'motivo_condonacion',
    'condonada_por',
    'anulado_el',
    'anulado_por',
    'motivo_anulacion',
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
            'anulado_el' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['numero', 'concepto', 'forma_pago', 'referencia', 'monto', 'fecha', 'cliente_id',
                'anulado_el', 'anulado_por', 'motivo_anulacion'])
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
     * Quién anuló este recibo.
     *
     * @return BelongsTo<User, $this>
     */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
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

    // ─── Anulación ────────────────────────────────────────────────────

    /**
     * ¿Este recibo dejó de valer?
     *
     * Un recibo anulado NO desaparece: conserva su número —la serie no puede
     * tener huecos (R12)— y conserva sus aplicaciones, que son la traza de a
     * qué se había aplicado. Lo que se revierte es `cuotas.monto_pagado`, que
     * es de donde sale el saldo.
     *
     * ⚠️ Todo lo que sume DINERO desde `recibos` —un corte de caja, un
     * reporte de cobros— tiene que filtrar `anulado_el IS NULL`. El saldo del
     * cliente no hace falta que lo filtre, porque no se calcula desde acá.
     */
    public function estaAnulado(): bool
    {
        return $this->getAttribute('anulado_el') !== null;
    }

    // ─── Los lotes ────────────────────────────────────────────────────

    /**
     * Los lotes que este recibo tocó, por código y sin repetir.
     *
     * `compromiso_id` es la respuesta rápida, y es lo que hay en la enorme
     * mayoría de los recibos. Cuando está en NULL el cobro fue de varios lotes
     * del mismo contrato, y entonces la verdad son las aplicaciones: cada una
     * apunta a una cuota, y cada cuota a su lote.
     *
     * Un recibo de prima no toca ninguno y devuelve la lista vacía — la
     * pantalla muestra su guion.
     *
     * @return list<string>
     */
    public function codigosDeLotes(): array
    {
        $delRecibo = $this->compromiso?->lote?->getAttribute('codigo');

        if (is_string($delRecibo)) {
            return [$delRecibo];
        }

        $codigos = [];

        foreach ($this->aplicaciones as $aplicacion) {
            $codigo = $aplicacion->cuota?->compromiso?->lote?->getAttribute('codigo');

            if (is_string($codigo) && ! in_array($codigo, $codigos, true)) {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }

    /**
     * Los lotes como se leen en el papel.
     */
    public function rotuloDeLotes(): string
    {
        $codigos = $this->codigosDeLotes();

        return $codigos === [] ? '—' : implode(' · ', $codigos);
    }

    public function tocaVariosLotes(): bool
    {
        return count($this->codigosDeLotes()) > 1;
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
    /**
     * De lo que este recibo le aplicó a las cuotas, cuánto fue interés.
     *
     * Sale de los RENGLONES y no de la cuota: el recibo acredita este pago, y
     * la cuota puede traer encima plata de otro recibo. Preguntarle a la
     * cuota daría el acumulado, que en un papel que dice «recibí de usted»
     * sería un número que el cliente no entregó hoy.
     */
    public function interesDeCuotas(): Monto
    {
        $total = Monto::cero();

        foreach ($this->aplicaciones as $aplicacion) {
            $total = $total->sumar($aplicacion->montoInteres());
        }

        return $total;
    }

    /**
     * Y cuánto fue capital. ⚠️ No confundir con `montoACapital()`, que es el
     * abono extra del R21: este es el capital que venía adentro de la cuota.
     */
    public function capitalDeCuotas(): Monto
    {
        $total = Monto::cero();

        foreach ($this->aplicaciones as $aplicacion) {
            $total = $total->sumar($aplicacion->montoCapital());
        }

        return $total;
    }

    public function cobroInteres(): bool
    {
        return ! $this->interesDeCuotas()->esCero();
    }

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

    // ─── Mora ─────────────────────────────────────────────────────────

    /**
     * La mora que entro con este recibo. Ya esta adentro de `monto`: no se
     * suma aparte, se DESGLOSA — el papel dice «de los L 15,000, L 287.67
     * fueron mora».
     */
    public function montoMora(): Monto
    {
        return $this->montoDeColumna('monto_mora');
    }

    /**
     * La mora que se perdono en este cobro. NO esta adentro de `monto`:
     * nunca entro por la puerta.
     */
    public function moraCondonada(): Monto
    {
        return $this->montoDeColumna('mora_condonada');
    }

    public function cobroMora(): bool
    {
        return ! $this->montoMora()->esCero();
    }

    public function condonoMora(): bool
    {
        return ! $this->moraCondonada()->esCero();
    }

    /**
     * Lo que se aplico al contrato: el monto sin la mora.
     *
     * Es el numero que baja la deuda, y el que el cliente busca cuando
     * compara el recibo contra su estado de cuenta.
     */
    public function montoAlContrato(): Monto
    {
        return $this->montoTotal()->restar($this->montoMora());
    }

    private function montoDeColumna(string $columna): Monto
    {
        $valor = $this->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }
}
