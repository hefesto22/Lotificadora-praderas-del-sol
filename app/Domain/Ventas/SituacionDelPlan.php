<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\ReestructuracionInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Models\AplicacionDePago;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * La foto del plan de un contrato ANTES de reestructurarlo.
 *
 * Contesta las dos preguntas que hace falta tener delante para decidir: qué
 * se reemplaza —desde qué cuota, cuánto capital, cuánto se pagaba por mes,
 * cuánto interés, hasta cuándo, cuánto de eso ya está pagado— y si hay algo
 * que impida tocar el plan.
 *
 * La arma `RegistroDeReestructuraciones::situacion()`, una vez sin bloquear
 * para la vista previa y otra bloqueando adentro de la transacción. Es la
 * MISMA clase las dos veces: lo que el modal mostró es lo que se verifica al
 * escribir, releído de la base (§9.D17).
 *
 * ═══ EL TRAMO QUE SE REEMPLAZA (26-sep-2026) ═══
 *
 * Desde el primer mes al que le falta algo, en cualquier lote, hasta el
 * final: `$desde`. Todo lo anterior está pagado entero y no se toca. Lo de
 * ahí en adelante se reemplaza ENTERO —también lo pagado adentro del tramo—
 * y ese dinero se vuelve a poner sobre el plan nuevo (`$pagos`), recibo por
 * recibo. Así el cliente que ya pagó la cuota 1 de un lote y un pedazo de la
 * del otro no tiene que anular nada: su recibo queda pagando la cuota 1 del
 * plan nuevo.
 *
 * El capital del tramo es el capital de sus cuotas, pagadas o no: lo que se
 * debía al empezar el mes `$desde`. Lo ya pagado no lo baja —no es un abono—;
 * cuenta como cuotas del plan nuevo, que es lo que el cliente pidió.
 */
final readonly class SituacionDelPlan
{
    /**
     * `$lotes` va por id de compromiso, en el orden del contrato; en cada uno
     * `conCobroAnulado` es número de cuota → id de la fila. `$pagos` van del
     * recibo más viejo al más nuevo, cada uno con sus renglones sobre el tramo.
     *
     * @param array<int, array{compromiso: Compromiso, codigo: string, capital: Monto, tramo: list<Cuota>, conCobroAnulado: array<int, int>}> $lotes
     * @param list<array{recibo: Recibo, dinero: Monto, renglones: list<AplicacionDePago>}> $pagos
     * @param list<ReestructuracionInvalidaException> $impedimentos
     * @param list<string> $afuera los lotes que todavía deben y NO se marcaron
     *                             (26-sep-2026), por código: siguen con su plan
     * @param int|null $primeraDelPlan el número de la primera cuota de los lotes, pagada o no:
     *                                 de ahí arranca el tramo si se rehace entero (07-oct-2026)
     */
    public function __construct(
        public Venta $venta,
        public int $desde,
        public array $lotes,
        public array $pagos,
        public array $impedimentos,
        public Monto $moraCorrida,
        public ?CarbonImmutable $vencidaMasVieja,
        public ?CarbonImmutable $ultimaPagadaVence,
        public array $afuera = [],
        public ?int $primeraDelPlan = null,
    ) {}

    /**
     * ¿Hay meses pagados enteros antes del tramo? Si los hay, se puede pedir
     * rehacer el plan desde la cuota 1 (`RegistroDeReestructuraciones`).
     */
    public function dejaMesesPagadosAntes(): bool
    {
        return $this->primeraDelPlan !== null && $this->desde > $this->primeraDelPlan;
    }

    /**
     * ¿El tramo es el plan entero, desde su primera cuota?
     */
    public function esElPlanEntero(): bool
    {
        return $this->primeraDelPlan !== null && $this->desde === $this->primeraDelPlan;
    }

    public function sePuede(): bool
    {
        return $this->impedimentos === [] && $this->lotes !== [];
    }

    /**
     * @return array<int, Monto> por id de compromiso
     */
    public function capitales(): array
    {
        $capitales = [];

        foreach ($this->lotes as $id => $lote) {
            $capitales[$id] = $lote['capital'];
        }

        return $capitales;
    }

    public function capitalTotal(): Monto
    {
        $total = Monto::cero();

        foreach ($this->lotes as $lote) {
            $total = $total->sumar($lote['capital']);
        }

        return $total;
    }

    /**
     * Con qué número arranca el plan nuevo: el mismo para todos los lotes,
     * el del primer mes que todavía debe algo. En un plan unificado el mes 7
     * es la cuota 7 de TODOS los lotes, y así lo leen la escalera de la ficha
     * y el agrupado por mes.
     */
    public function primerNumero(): int
    {
        return $this->desde;
    }

    /**
     * Desde cuándo devenga el primer pago de un plan «A fechas»
     * (18-oct-2026): el vencimiento de la última cuota que queda pagada
     * entera antes del tramo. Si no queda ninguna —se rehace desde la cuota
     * 1, o todavía no pagó ninguna—, la firma del contrato.
     *
     * Es el mismo borde que ya cuida `verificarElPrimerVencimiento()`: el
     * primer pago pactado tiene que caer después de los dos.
     */
    public function devengaDesde(): CarbonImmutable
    {
        if ($this->ultimaPagadaVence instanceof CarbonImmutable) {
            return $this->ultimaPagadaVence;
        }

        $firma = $this->venta->getAttribute('fecha_contrato');

        // Una venta vigente siempre está firmada: la otra rama es para el analizador.
        return $firma instanceof DateTimeInterface
            ? CarbonImmutable::parse($firma->format('Y-m-d'))
            : CarbonImmutable::parse(today()->toDateString());
    }

    /**
     * Lo que el cliente ya entregó sobre el tramo, sin la mora: interés más
     * capital, que es lo que se vuelve a poner sobre las cuotas nuevas.
     */
    public function yaPagado(): Monto
    {
        $total = Monto::cero();

        foreach ($this->pagos as $pago) {
            $total = $total->sumar($pago['dinero']);
        }

        return $total;
    }

    /**
     * La mora que esos mismos recibos cobraron, aparte.
     *
     * NO entra en lo ya pagado ni en la cuenta del plan nuevo: es ganancia
     * del atraso, no un abono a capital ni interés (Mauricio, 26-sep-2026).
     * Se dice para que nadie pregunte por qué los recibos suman más que lo
     * que se reubica.
     */
    public function moraYaCobrada(): Monto
    {
        $total = Monto::cero();

        foreach ($this->pagos as $pago) {
            foreach ($pago['renglones'] as $renglon) {
                $total = $total->sumar($renglon->montoMora());
            }
        }

        return $total;
    }

    /**
     * Lo que el lote ya tiene pagado en el tramo.
     */
    public function yaPagadoDe(int $lote): Monto
    {
        $total = Monto::cero();

        foreach ($this->lotes[$lote]['tramo'] ?? [] as $cuota) {
            $total = $total->sumar($cuota->montoPagado());
        }

        return $total;
    }

    /**
     * El dinero de un recibo —interés y capital, sin la mora— separado por el
     * lote de la cuota que pagaba (Praderas, 25-oct-2026).
     *
     * Con cada lote por su lado, eso es lo que vuelve a cada lote: el recibo
     * que pagó la cuota 3 del lote A paga cuotas del plan nuevo de A, y de
     * ningún otro. Los lotes salen en el orden del contrato.
     *
     * @param array{recibo: Recibo, dinero: Monto, renglones: list<AplicacionDePago>} $pago
     *
     * @return array<int, Monto> por id de compromiso, sin los que quedan en cero
     */
    public function dineroPorLote(array $pago): array
    {
        $dondeEstaba = [];

        foreach ($this->lotes as $id => $lote) {
            foreach ($lote['tramo'] as $cuota) {
                $dondeEstaba[(int) $cuota->getKey()] = $id;
            }
        }

        $porLote = [];

        foreach (array_keys($this->lotes) as $id) {
            $porLote[$id] = Monto::cero();
        }

        foreach ($pago['renglones'] as $renglon) {
            $lote = $dondeEstaba[(int) $renglon->getAttribute('cuota_id')] ?? null;

            if ($lote === null) {
                continue;
            }

            $porLote[$lote] = $porLote[$lote]->sumar($renglon->montoInteres())->sumar($renglon->montoCapital());
        }

        return array_filter($porLote, static fn (Monto $monto): bool => ! $monto->esCero());
    }

    /**
     * Los recibos cuyo dinero se reubica, como se leen en el papel.
     *
     * @return list<string>
     */
    public function folios(): array
    {
        return array_map(static fn (array $pago): string => $pago['recibo']->folio(), $this->pagos);
    }

    /**
     * Cuándo vence la primera cuota que se reemplaza: la propuesta de fábrica
     * para el primer vencimiento del plan nuevo. Así, si no se toca, el
     * calendario no se mueve.
     */
    public function proximoVencimiento(): ?CarbonImmutable
    {
        $primera = null;

        foreach ($this->lotes as $lote) {
            foreach ($lote['tramo'] as $cuota) {
                $vence = self::vence($cuota);

                if ($vence instanceof CarbonImmutable && (! $primera instanceof CarbonImmutable || $vence->lessThan($primera))) {
                    $primera = $vence;
                }
            }
        }

        return $primera;
    }

    /**
     * La fecha que se propone para el primer vencimiento del plan nuevo.
     *
     * La de la primera cuota que se reemplaza, así el calendario no se mueve
     * y lo ya pagado cae en los mismos meses para los que se pagó —el caso
     * que lo originó: el cobro del 15 tiene que pagar la cuota del 15—. Si ya
     * pasó y no hay nada pagado, el plan nacería atrasado —y reestructurar
     * deja la mora en cero—: se propone el mismo día, desde hoy.
     */
    public function primerVencimientoSugerido(): CarbonImmutable
    {
        $proximo = $this->proximoVencimiento();

        if (! $proximo instanceof CarbonImmutable) {
            return CarbonImmutable::parse(today()->toDateString());
        }

        if ($this->yaPagado()->esCero()) {
            return $this->primerVencimientoDesdeHoy();
        }

        return $proximo;
    }

    /**
     * El mismo día del mes de la primera cuota que se reemplaza, en la
     * primera fecha desde hoy. Es la salida que propone el mensaje cuando el
     * plan nacería atrasado.
     */
    public function primerVencimientoDesdeHoy(): CarbonImmutable
    {
        $hoy = CarbonImmutable::parse(today()->toDateString());
        $proximo = $this->proximoVencimiento();

        if (! $proximo instanceof CarbonImmutable) {
            return $hoy;
        }

        if (! $proximo->lessThan($hoy)) {
            return $proximo;
        }

        $meses = 1;

        while ($proximo->addMonthsNoOverflow($meses)->lessThan($hoy)) {
            $meses++;
        }

        return $proximo->addMonthsNoOverflow($meses);
    }

    /**
     * Lo que se pagaba por mes al arrancar el tramo, sumando todos los lotes.
     *
     * Es el número que el cliente tiene en la cabeza —«pago L 197,200 al
     * mes»— y el que el acta pone en la columna de antes.
     */
    public function cuotaDeHoy(): ?Monto
    {
        $total = null;

        foreach ($this->lotes as $lote) {
            $primera = $lote['tramo'][0] ?? null;

            if ($primera instanceof Cuota) {
                $total = ($total ?? Monto::cero())->sumar($primera->montoTotal());
            }
        }

        return $total;
    }

    /**
     * Cuántos meses tiene el tramo: cuotas distintas por NÚMERO, no filas.
     *
     * Con dos lotes hay dos filas por mes, y contar filas diría que a un plan
     * de 6 meses le faltan 12.
     */
    public function mesesQueFaltan(): int
    {
        $numeros = [];

        foreach ($this->lotes as $lote) {
            foreach ($lote['tramo'] as $cuota) {
                $numeros[(int) $cuota->getAttribute('numero')] = true;
            }
        }

        return count($numeros);
    }

    /**
     * El interés del tramo entero, pagado o no. Contra este número se dice
     * cuánto sube o baja con el plan nuevo: las dos cuentas cubren los mismos
     * meses y el mismo capital.
     */
    public function interesDelTramo(): Monto
    {
        $total = Monto::cero();

        foreach ($this->lotes as $lote) {
            foreach ($lote['tramo'] as $cuota) {
                $total = $total->sumar($cuota->montoInteres());
            }
        }

        return $total;
    }

    /**
     * Lo que le falta pagar con el plan de hoy: capital más interés del
     * tramo, menos lo ya pagado.
     */
    public function faltaHoy(): Monto
    {
        $total = $this->capitalTotal()->sumar($this->interesDelTramo());

        return $total->mayorQue($this->yaPagado()) ? $total->restar($this->yaPagado()) : Monto::cero();
    }

    public function terminaEl(): ?CarbonImmutable
    {
        $ultima = null;

        foreach ($this->lotes as $lote) {
            foreach ($lote['tramo'] as $cuota) {
                $vence = self::vence($cuota);

                if ($vence instanceof CarbonImmutable && (! $ultima instanceof CarbonImmutable || $vence->greaterThan($ultima))) {
                    $ultima = $vence;
                }
            }
        }

        return $ultima;
    }

    /**
     * Las tasas congeladas de los lotes que entran, sin repetir.
     *
     * @return list<TasaDeInteres>
     */
    public function tasas(): array
    {
        $tasas = [];

        foreach ($this->lotes as $lote) {
            $tasa = $lote['compromiso']->tasaDeInteres();
            $tasas[$tasa->paraBase()] = $tasa;
        }

        return array_values($tasas);
    }

    /**
     * La tasa que se propone para el plan unificado: la del lote que más
     * debe. Con una sola tasa —el caso normal— es esa y no hay nada que
     * decidir; con dos, la pantalla lo avisa y la administradora elige.
     */
    public function tasaSugerida(): TasaDeInteres
    {
        $sugerida = TasaDeInteres::cero();
        $mayor = null;

        foreach ($this->lotes as $lote) {
            if (! $mayor instanceof Monto || $lote['capital']->mayorQue($mayor)) {
                $mayor = $lote['capital'];
                $sugerida = $lote['compromiso']->tasaDeInteres();
            }
        }

        return $sugerida;
    }

    /**
     * La tasa con la que se venía pagando, si era una sola. Null si los
     * lotes venían con tasas distintas: el acta lo dice así en vez de elegir
     * una.
     */
    public function tasaAnterior(): ?TasaDeInteres
    {
        $tasas = $this->tasas();

        return count($tasas) === 1 ? $tasas[0] : null;
    }

    /**
     * Los códigos de los lotes que entran, para el papel.
     */
    public function codigos(): string
    {
        return implode(' · ', array_map(static fn (array $lote): string => $lote['codigo'], $this->lotes));
    }

    /**
     * El cast `date` de Cuota da un Carbon MUTABLE y el dominio trabaja con
     * inmutables: se convierte en el borde, una vez.
     */
    public static function vence(Cuota $cuota): ?CarbonImmutable
    {
        $fecha = $cuota->getAttribute('fecha_vencimiento');

        return $fecha instanceof DateTimeInterface
            ? CarbonImmutable::parse($fecha->format('Y-m-d'))
            : null;
    }
}
