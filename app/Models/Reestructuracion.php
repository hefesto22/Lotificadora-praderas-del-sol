<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CondicionesDeReestructuracion;
use App\Domain\Ventas\TasaDeInteres;
use App\Traits\HasAuditFields;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * El acta de que el plan de un contrato se rehízo (25-sep-2026).
 *
 * Una fila por trámite y por CONTRATO: es el papel que el cliente firma, con
 * el antes y el después. Lo que le pasó a cada lote —qué cuotas se borraron y
 * cuáles quedaron— vive en `reprogramaciones`, una fila por lote que apunta
 * acá, igual que la constancia de un abono a capital. Así la pestaña
 * «Reprogramaciones» del expediente la muestra sin que nadie la toque.
 *
 * ═══ POR QUE UNA TABLA PROPIA Y NO SOLO REPROGRAMACIONES ═══
 *
 * Porque el plan nuevo es del CONTRATO —un capital, una tasa, una cuota— y el
 * acta tiene que poder imprimirse igual dentro de un año, aunque en el medio
 * un abono haya reescrito las cuotas de un lote. Por eso guarda el plan que se
 * pactó (`plan_nuevo`), renglón por renglón, además de los números de antes.
 *
 * ═══ NO SE EDITA NI SE BORRA ═══
 *
 * Es historia, igual que una reprogramación. Si se reestructuró mal, se
 * reestructura otra vez, con su motivo, y el expediente tiene las dos actas.
 *
 * ═══ LO YA PAGADO (26-sep-2026) ═══
 *
 * La reestructuración reubica sola lo que el cliente ya había pagado sobre el
 * tramo: `ya_pagado` dice cuánto, `pagos_reubicados` qué recibo fue de qué
 * cuotas a cuáles, y `mora_antes` / `mora_despues` cuánta mora corría el día
 * del trámite y cuánta quedó. Son los números que explican por qué un recibo
 * reimpreso dice otra cuota que el día que se entregó.
 *
 * El nombre de la tabla va escrito: el pluralizador inglés de Laravel saca
 * `reestructuracions`.
 */
#[Fillable([
    'venta_id',
    'numero',
    'fecha',
    'motivo',
    'fijado',
    'interes_incluido',
    'tasa_anterior',
    'tasa_nueva',
    'capital',
    'cuota_anterior',
    'cuota_nueva',
    'meses_antes',
    'meses_despues',
    'interes_antes',
    'interes_despues',
    'termina_antes',
    'termina_despues',
    'primer_vencimiento',
    'dia_pago_anterior',
    'dia_pago_nuevo',
    'lotes',
    'plan_nuevo',
    'descuento',
    'tipo',
    'quita_la_mora',
    'mora_anterior',
    'ya_pagado',
    'mora_antes',
    'mora_despues',
    'pagos_reubicados',
    'agrupacion',
    'lotes_detalle',
])]
#[Table(name: 'reestructuraciones')]
class Reestructuracion extends Model
{
    use HasAuditFields;

    /**
     * Reestructurar es cuadrar el plan; renegociar es pactar condiciones
     * nuevas para lo que debe (18-oct-2026). Las dos son un acta de esta
     * tabla, con la misma numeración por contrato.
     */
    public const string TIPO_REESTRUCTURACION = 'reestructuracion';

    public const string TIPO_RENEGOCIACION = 'renegociacion';

    /**
     * Cómo quedaron los lotes (Praderas, 25-oct-2026): pagándose como UNO,
     * con una sola cuota —lo que hace Maya siempre—, o cada uno con su plan y
     * su cuota. «Que se pueda unificar los lotes para una sola cuota o que
     * tengan cuota independiente según como lo reestructure» —Mauricio—.
     */
    public const string UNIFICADOS = 'unificado';

    public const string CADA_LOTE = 'por_lote';

    /**
     * Los montos no se castean a `decimal:x` (§8.3.1): PDO de Postgres ya los
     * entrega como string.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'fecha'              => 'date',
            'termina_antes'      => 'date',
            'termina_despues'    => 'date',
            'primer_vencimiento' => 'date',
            'interes_incluido'   => 'boolean',
            'quita_la_mora'      => 'boolean',
            'plan_nuevo'         => 'array',
            'pagos_reubicados'   => 'array',
            'lotes_detalle'      => 'array',
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
     * Lo que le pasó a cada lote: sus cuotas viejas y cuántas quedaron.
     *
     * @return HasMany<Reprogramacion, $this>
     */
    public function reprogramaciones(): HasMany
    {
        return $this->hasMany(Reprogramacion::class);
    }

    // ─── Para el papel ────────────────────────────────────────────────

    /**
     * El número del acta: el contrato y cuál reestructuración es.
     *
     * No gasta un correlativo propio: el contrato ya es único y la
     * reestructuración es suya, así que «XXX-2026-0007-R1» no se puede repetir
     * y se lee sin explicar nada.
     */
    public function folio(): string
    {
        $venta = $this->venta;
        $contrato = $venta instanceof Venta ? $venta->getAttribute('numero_contrato') : null;

        return (is_string($contrato) ? $contrato : 'Contrato').'-R'.(int) $this->getAttribute('numero');
    }

    public function interesIncluido(): bool
    {
        return (bool) $this->getAttribute('interes_incluido');
    }

    public function fijoLaCuota(): bool
    {
        return $this->getAttribute('fijado') === 'cuota';
    }

    /**
     * ¿Es una renegociación del contrato, y no una reestructuración del plan?
     */
    public function esRenegociacion(): bool
    {
        return $this->getAttribute('tipo') === self::TIPO_RENEGOCIACION;
    }

    /**
     * ¿Cada lote quedó con su propio plan y su propia cuota? (25-oct-2026).
     * Si no, se pagan como uno: una sola cuota por todos.
     */
    public function cadaLoteConSuCuota(): bool
    {
        return $this->getAttribute('agrupacion') === self::CADA_LOTE;
    }

    /**
     * Cómo quedó cada lote, como se escribió ese día: el resumen que el acta
     * imprime lote por lote (25-oct-2026). Las actas que no lo tienen —las de
     * un plan unificado de antes— devuelven la lista vacía.
     *
     * @return list<array{codigo: string, capital: Monto, cuotas: int, cuota: Monto, ultima: Monto, desde: int, primera: string, termina: string}>
     */
    public function lotesDetalle(): array
    {
        $crudo = $this->getAttribute('lotes_detalle');

        if (! is_array($crudo)) {
            return [];
        }

        $lotes = [];

        foreach ($crudo as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $codigo = $fila['codigo'] ?? null;
            $cuotas = $fila['cuotas'] ?? null;
            $desde = $fila['desde'] ?? null;

            if (! is_string($codigo)) {
                continue;
            }

            if (! is_int($cuotas)) {
                continue;
            }

            if (! is_int($desde)) {
                continue;
            }

            $primera = $fila['primera'] ?? null;
            $termina = $fila['termina'] ?? null;

            $lotes[] = [
                'codigo'  => $codigo,
                'capital' => $this->montoDeFila($fila, 'capital'),
                'cuotas'  => $cuotas,
                'cuota'   => $this->montoDeFila($fila, 'cuota'),
                'ultima'  => $this->montoDeFila($fila, 'ultima'),
                'desde'   => $desde,
                'primera' => is_string($primera) ? $primera : '',
                'termina' => is_string($termina) ? $termina : '',
            ];
        }

        return $lotes;
    }

    /**
     * ¿Desde esta acta los lotes dejaron de cobrar mora?
     */
    public function quitoLaMora(): bool
    {
        return (bool) $this->getAttribute('quita_la_mora');
    }

    /**
     * La mora que tenían los lotes antes de quitarla, como se leía ese día.
     */
    public function moraAnterior(): ?string
    {
        $texto = $this->getAttribute('mora_anterior');

        return is_string($texto) && $texto !== '' ? $texto : null;
    }

    /**
     * ¿Se pactaron fechas en vez de meses o cuota? (18-oct-2026)
     */
    public function esAFechas(): bool
    {
        return $this->getAttribute('fijado') === CondicionesDeReestructuracion::FIJA_FECHAS;
    }

    /**
     * Lo que se le perdonó del capital al cliente en esta acta (18-oct-2026).
     */
    public function montoDescuento(): Monto
    {
        return $this->montoDe('descuento');
    }

    /**
     * El capital del plan nuevo: el que se debía menos el descuento. El CHECK
     * `reestructuraciones_descuento_chk` lo deja siempre por encima de cero.
     */
    public function capitalDespues(): Monto
    {
        return $this->montoCapital()->restar($this->montoDescuento());
    }

    public function montoCapital(): Monto
    {
        return $this->montoDe('capital');
    }

    public function montoCuotaAnterior(): ?Monto
    {
        $valor = $this->getAttribute('cuota_anterior');

        return is_string($valor) || is_int($valor) ? new Monto($valor) : null;
    }

    public function montoCuotaNueva(): Monto
    {
        return $this->montoDe('cuota_nueva');
    }

    public function montoInteresAntes(): Monto
    {
        return $this->montoDe('interes_antes');
    }

    public function montoInteresDespues(): Monto
    {
        return $this->montoDe('interes_despues');
    }

    public function montoYaPagado(): Monto
    {
        return $this->montoDe('ya_pagado');
    }

    public function montoMoraAntes(): Monto
    {
        return $this->montoDe('mora_antes');
    }

    public function montoMoraDespues(): Monto
    {
        return $this->montoDe('mora_despues');
    }

    /**
     * Lo que queda por pagar desde el acta: el plan nuevo entero menos lo que
     * ya estaba pagado.
     */
    public function faltaDespues(): Monto
    {
        $total = $this->capitalDespues()->sumar($this->montoInteresDespues());

        return $total->mayorQue($this->montoYaPagado()) ? $total->restar($this->montoYaPagado()) : Monto::cero();
    }

    /**
     * Lo mismo con el plan de antes, para la columna de la izquierda.
     */
    public function faltaAntes(): Monto
    {
        $total = $this->montoCapital()->sumar($this->montoInteresAntes());

        return $total->mayorQue($this->montoYaPagado()) ? $total->restar($this->montoYaPagado()) : Monto::cero();
    }

    /**
     * Los recibos que se movieron, como quedaron escritos ese día.
     *
     * Se filtra fila por fila, igual que `planNuevo()`: lo que vuelve de una
     * columna jsonb es `mixed` de verdad, y un renglón que no se puede leer se
     * saltea en vez de romper el papel.
     *
     * @return list<array{folio: string, fecha: string, dinero: Monto, mora: Monto, antes: list<array{lote: string, numero: int, monto: Monto, mora: Monto}>, despues: list<array{lote: string, numero: int, monto: Monto, mora: Monto}>}>
     */
    public function pagosReubicados(): array
    {
        $crudo = $this->getAttribute('pagos_reubicados');

        if (! is_array($crudo)) {
            return [];
        }

        $recibos = [];

        foreach ($crudo as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $folio = $fila['folio'] ?? null;
            $fecha = $fila['fecha'] ?? null;

            if (! is_string($folio)) {
                continue;
            }

            $recibos[] = [
                'folio'   => $folio,
                'fecha'   => is_string($fecha) ? $fecha : '',
                'dinero'  => $this->montoDeFila($fila, 'dinero'),
                'mora'    => $this->montoDeFila($fila, 'mora'),
                'antes'   => $this->renglonesDe($fila['antes'] ?? null),
                'despues' => $this->renglonesDe($fila['despues'] ?? null),
            ];
        }

        return $recibos;
    }

    public function tasaAnterior(): ?TasaDeInteres
    {
        $valor = $this->getAttribute('tasa_anterior');

        return $valor === null ? null : TasaDeInteres::deBase($valor);
    }

    public function tasaNueva(): TasaDeInteres
    {
        return TasaDeInteres::deBase($this->getAttribute('tasa_nueva'));
    }

    public function fechaDe(string $columna): ?CarbonImmutable
    {
        $valor = $this->getAttribute($columna);

        return $valor instanceof DateTimeInterface ? CarbonImmutable::parse($valor->format('Y-m-d')) : null;
    }

    /**
     * El plan que se pactó, como quedó escrito ese día.
     *
     * Se filtra fila por fila, igual que `Reprogramacion::planAnterior()`: lo
     * que vuelve de una columna jsonb es `mixed` de verdad.
     *
     * `cubierto` es lo que lo ya pagado cubrió de ese mes. Las actas de antes
     * del 26-sep-2026 no lo tienen, y ahí vale cero, que es lo que fue.
     *
     * @return list<array{numero: int, vence: string, monto: Monto, capital: Monto, interes: Monto, cubierto: Monto}>
     */
    public function planNuevo(): array
    {
        $crudo = $this->getAttribute('plan_nuevo');

        if (! is_array($crudo)) {
            return [];
        }

        $renglones = [];

        foreach ($crudo as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $numero = $fila['numero'] ?? null;
            $vence = $fila['vence'] ?? null;
            $monto = $fila['monto'] ?? null;
            $capital = $fila['capital'] ?? null;
            $interes = $fila['interes'] ?? null;

            if (! is_int($numero)) {
                continue;
            }

            if (! is_string($vence)) {
                continue;
            }

            if (! is_string($monto)) {
                continue;
            }

            if (! is_string($capital)) {
                continue;
            }

            if (! is_string($interes)) {
                continue;
            }

            $renglones[] = [
                'numero'   => $numero,
                'vence'    => $vence,
                'monto'    => new Monto($monto),
                'capital'  => new Monto($capital),
                'interes'  => new Monto($interes),
                'cubierto' => $this->montoDeFila($fila, 'cubierto'),
            ];
        }

        return $renglones;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param array<mixed> $fila
     */
    private function montoDeFila(array $fila, string $clave): Monto
    {
        $valor = $fila[$clave] ?? null;

        return is_string($valor) && is_numeric($valor) ? new Monto($valor) : Monto::cero();
    }

    /**
     * @return list<array{lote: string, numero: int, monto: Monto, mora: Monto}>
     */
    private function renglonesDe(mixed $crudo): array
    {
        if (! is_array($crudo)) {
            return [];
        }

        $renglones = [];

        foreach ($crudo as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $numero = $fila['numero'] ?? null;
            $lote = $fila['lote'] ?? null;

            if (! is_int($numero)) {
                continue;
            }

            $renglones[] = [
                'lote'   => is_string($lote) ? $lote : '',
                'numero' => $numero,
                'monto'  => $this->montoDeFila($fila, 'monto'),
                'mora'   => $this->montoDeFila($fila, 'mora'),
            ];
        }

        return $renglones;
    }

    private function montoDe(string $columna): Monto
    {
        $valor = $this->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }
}
