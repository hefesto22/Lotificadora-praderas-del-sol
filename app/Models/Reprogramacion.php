<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * La constancia de que el plan de un lote se reescribio (R21).
 *
 * Es la respuesta a «¿por que mi cuota cambio?». Guarda las dos puntas —lo
 * que se debia y lo que se debe, la cuota vieja y la nueva, cuantos meses
 * faltaban y cuantos faltan— mas el plan viejo completo en `plan_anterior`,
 * para poder reconstruir el estado de cuenta de cualquier fecha.
 *
 * ═══ NO SE EDITA NI SE BORRA ═══
 *
 * Es historia, igual que un recibo. Si una reprogramacion se hizo mal, la
 * correccion es otra reprogramacion con su motivo, no cambiarle el motivo a
 * esta. `ReprogramacionPolicy` devuelve `false` a todas las escrituras.
 *
 * ═══ SIN activitylog, Y ES A PROPOSITO ═══
 *
 * Esta tabla YA es el registro de auditoria del cambio, con su motivo y su
 * `created_by`. Ponerle encima `LogsActivity` seria auditar la auditoria:
 * dos filas diciendo lo mismo y la bitacora del expediente llena de ruido.
 *
 * ═══ EL NOMBRE DE LA TABLA VA ESCRITO ═══
 *
 * El pluralizador de Laravel es ingles y de `Reprogramacion` saca
 * `reprogramacions`. Igual que `AplicacionDePago`, el nombre real se declara
 * y no se adivina.
 */
#[Fillable([
    'venta_id',
    'compromiso_id',
    'recibo_id',
    'modalidad',
    'motivo',
    'abono_capital',
    'saldo_anterior',
    'saldo_nuevo',
    'cuota_anterior',
    'cuota_nueva',
    'cuotas_antes',
    'cuotas_despues',
    'desde_numero',
    'plan_anterior',
])]
#[Table(name: 'reprogramaciones')]
class Reprogramacion extends Model
{
    use HasAuditFields;

    /**
     * Los montos NO se castean a `decimal:x`: ese cast pasa por
     * `number_format()`, que recibe float (§8.3.1). PDO de Postgres ya
     * entrega NUMERIC como string, que es lo que consume bcmath.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'modalidad'     => ModalidadDeReprogramacion::class,
            'plan_anterior' => 'array',
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
     * El lote cuyo plan se reescribio. Un abono va contra UNO (R21).
     *
     * @return BelongsTo<Compromiso, $this>
     */
    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    /**
     * El recibo del abono que la origino.
     *
     * Nulo cuando la reprogramacion no vino de dinero entrando — el caso que
     * va a traer la rescision por lote (R22).
     *
     * @return BelongsTo<Recibo, $this>
     */
    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class);
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    public function montoAbonado(): Monto
    {
        return $this->montoDe('abono_capital');
    }

    public function montoSaldoAnterior(): Monto
    {
        return $this->montoDe('saldo_anterior');
    }

    public function montoSaldoNuevo(): Monto
    {
        return $this->montoDe('saldo_nuevo');
    }

    public function montoCuotaAnterior(): ?Monto
    {
        return $this->montoNullableDe('cuota_anterior');
    }

    public function montoCuotaNueva(): ?Monto
    {
        return $this->montoNullableDe('cuota_nueva');
    }

    // ─── El plan que se reemplazo ─────────────────────────────────────

    /**
     * Las cuotas viejas, tal como estaban antes del abono.
     *
     * Se filtra fila por fila en vez de anotar el tipo con `@var`: lo que
     * vuelve de una columna jsonb es `mixed` de verdad —cualquiera puede
     * haber escrito ahi con un UPDATE a mano— y prometerle a PHPStan una
     * forma que la base no garantiza es como no tener PHPStan.
     *
     * @return list<array{numero: int, vence: string, monto: string}>
     */
    public function planAnterior(): array
    {
        $crudo = $this->getAttribute('plan_anterior');

        if (! is_array($crudo)) {
            return [];
        }

        $cuotas = [];

        foreach ($crudo as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $numero = $fila['numero'] ?? null;
            $vence = $fila['vence'] ?? null;
            $monto = $fila['monto'] ?? null;

            if (! is_int($numero)) {
                continue;
            }

            if (! is_string($vence)) {
                continue;
            }

            if (! is_string($monto)) {
                continue;
            }

            $cuotas[] = ['numero' => $numero, 'vence' => $vence, 'monto' => $monto];
        }

        return $cuotas;
    }

    /**
     * La modalidad, ya como enum.
     *
     * Existe para que la pantalla no tenga que hacer
     * `getAttribute('modalidad') instanceof X ? getAttribute('modalidad')->…`:
     * son dos llamadas distintas y PHPStan no narrowea la segunda por el
     * `instanceof` de la primera.
     */
    public function modalidadElegida(): ?ModalidadDeReprogramacion
    {
        $modalidad = $this->getAttribute('modalidad');

        return $modalidad instanceof ModalidadDeReprogramacion ? $modalidad : null;
    }

    public function etiquetaDeModalidad(): string
    {
        return $this->modalidadElegida()?->etiqueta() ?? '—';
    }

    public function colorDeModalidad(): string
    {
        return $this->modalidadElegida()?->color() ?? 'gray';
    }

    /**
     * ¿Este abono termino de pagar el lote?
     */
    public function cancelaElLote(): bool
    {
        return $this->montoSaldoNuevo()->esCero();
    }

    /**
     * Cuantos meses se ahorro el cliente. Cero cuando eligio bajar la cuota.
     */
    public function mesesAhorrados(): int
    {
        return max(0, (int) $this->getAttribute('cuotas_antes') - (int) $this->getAttribute('cuotas_despues'));
    }

    // ─── Interno ──────────────────────────────────────────────────────

    private function montoDe(string $columna): Monto
    {
        $valor = $this->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    private function montoNullableDe(string $columna): ?Monto
    {
        $valor = $this->getAttribute($columna);

        return is_string($valor) || is_int($valor) ? new Monto($valor) : null;
    }
}
