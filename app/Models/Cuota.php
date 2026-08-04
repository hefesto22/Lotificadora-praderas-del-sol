<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Monto;
use Database\Factories\CuotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * ═══ NO HAY MORA ═══
 *
 * R2: el atraso no genera cargo. `diasDeAtraso()` existe porque la
 * administracion necesita saber quien esta atrasado —para llamarlo, no para
 * cobrarle de mas—. Un cliente atrasado debe exactamente lo mismo que
 * debia el dia del vencimiento.
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
    'numero',
    'fecha_vencimiento',
    'monto',
    'monto_pagado',
])]
class Cuota extends Model
{
    /** @use HasFactory<CuotaFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'monto_pagado' => '0.00',
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
     * Lo que falta para darla por pagada.
     */
    public function saldo(): Monto
    {
        return $this->montoTotal()->restar($this->montoPagado());
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
     * Es informacion para la administracion, no la base de ningun cobro:
     * no hay mora (R2).
     */
    public function diasDeAtraso(): int
    {
        if (! $this->estaVencida()) {
            return 0;
        }

        $vence = $this->getAttribute('fecha_vencimiento');

        return (int) $vence->diffInDays(today());
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
