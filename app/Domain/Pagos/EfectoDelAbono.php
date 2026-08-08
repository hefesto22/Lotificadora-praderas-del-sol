<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\TasaDeInteres;
use App\Models\Cuota;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Que le hace un abono a capital al plan de un lote (R21). No escribe nada.
 *
 * ═══ POR QUE ES UN OBJETO Y NO CODIGO ADENTRO DEL SERVICE ═══
 *
 * El §10.8 manda mostrar el efecto ANTES de confirmar: quien atiende tiene un
 * cliente enfrente preguntando «¿y con esto en cuanto me queda la cuota?», y
 * esa respuesta no puede llegar despues de apretar el boton. Si la pantalla
 * calculara por su lado y el Service por el suyo, el dia que uno de los dos
 * cambie el cliente firma un numero y la base guarda otro.
 *
 * Es exactamente lo que `PlanDeCuotas` hace por el formulario de venta.
 *
 * ═══ LOS DOS DETALLES QUE DECIDIO MAURICIO EL 6-AGO-2026 ═══
 *
 * 1. **Con cuotas vencidas, el abono primero pone al dia.** Cubre lo vencido
 *    en FIFO —y su mora, desde el 8-ago— y solo el sobrante baja el capital.
 *    Si no, quedaria alguien «con capital abonado» y moroso al mismo tiempo —
 *    dos verdades sobre el mismo contrato. Y si el abono no alcanza ni para
 *    eso, esto NO es un abono: es un pago normal y no se reescribe ningun
 *    plan (`esPagoNormal`).
 *
 * 2. **La cuota pagada a medias se respeta.** Si la 5 tiene L 12,500.00 de
 *    L 25,000.00, esa cuota queda tal cual y el plan nuevo empieza en la 6.
 *    Lo pagado no se toca nunca, y asi el recibo viejo sigue apuntando a una
 *    cuota que existe. La alternativa —absorber el parcial y recalcular
 *    todo— deja aplicaciones de pago colgando de cuotas borradas, y ahi «¿por
 *    que la 5 aparece a medias?» deja de tener respuesta.
 *
 * ═══ 🔴 CON INTERES SE REAMORTIZA EL CAPITAL, NO LA SUMA DE LAS CUOTAS ═══
 *
 * Las cuotas que se reemplazan traen adentro el interes del plan viejo. Si se
 * reprogramara sobre `montoTotal()` —como hacia este codigo hasta el
 * 7-ago—, ese interes entraria como si fuera capital y el plan nuevo le
 * cobraria interes encima: anatocismo, por accidente y sin que nada chille.
 *
 * Por eso lo que se reparte es `capitalPendiente()`, y por eso el numero que
 * el cliente mira para decidir es `interesesAhorrados()`: con interes, abonar
 * a capital no solo acorta el plazo — **borra los intereses que esas cuotas
 * iban a devengar**, y esa cifra suele ser varias veces el abono.
 *
 * Sin interes (R1, Praderas del Sol) `capitalPendiente()` es identico a
 * `saldo()` y este objeto se comporta exactamente como antes.
 *
 * ═══ EL TOPE, QUE NO ES EL SALDO DEL LOTE ═══
 *
 * Solo se puede abonar hasta `tope` = lo vencido + su mora + lo que se puede
 * reprogramar. Lo que le falta a una cuota a medias NO entra: respetarla
 * significa no tocarla, ni siquiera para cobrarla de paso. Un cliente que
 * quiere cancelar el lote entero lo hace por «Registrar un pago», que cubre
 * todo FIFO sin reescribir nada.
 */
final readonly class EfectoDelAbono
{
    /**
     * @param list<array{numero: int, monto: Monto, salda: bool}> $aplicaciones el reparto FIFO que pone al dia
     * @param list<int> $numerosReemplazados las cuotas pendientes que se reescriben
     * @param list<array{numero: int, vence: string, monto: string}> $planAnterior el snapshot que va a jsonb
     */
    private function __construct(
        public Monto $abono,
        public ModalidadDeReprogramacion $modalidad,
        public Monto $ponerAlDia,
        public Monto $mora,
        public Monto $aCapital,
        public array $aplicaciones,
        public array $numerosReemplazados,
        public array $planAnterior,
        public Monto $saldoDelLote,
        public Monto $tope,
        public Monto $saldoReprogramable,
        public Monto $saldoNuevo,
        public ?Monto $cuotaVigente,
        public Monto $interesDelPlanViejo,
        public int $desdeNumero,
        public ?PlanDeCuotas $planNuevo,
        public TasaDeInteres $tasa,
        public bool $esPagoNormal,
        public bool $superaElTope,
        public ?string $problema,
    ) {}

    /**
     * @param iterable<int, Cuota> $pendientes las cuotas del lote que todavia deben algo
     * @param int $diaPago el de la venta; los vencimientos nuevos caen ahi
     * @param TasaDeInteres|null $tasa la CONGELADA del compromiso, no la del proyecto
     * @param Monto|null $moraPendiente la que hay que cubrir antes de abonar
     */
    public static function calcular(
        iterable $pendientes,
        Monto $abono,
        ModalidadDeReprogramacion $modalidad,
        int $diaPago,
        ?TasaDeInteres $tasa = null,
        ?Monto $moraPendiente = null,
    ): self {
        $tasa ??= TasaDeInteres::cero();
        $mora = $moraPendiente ?? Monto::cero();

        $cuotas = self::enOrden($pendientes);

        $saldoDelLote = Monto::cero();
        $vencido = Monto::cero();

        foreach ($cuotas as $cuota) {
            $saldoDelLote = $saldoDelLote->sumar($cuota->saldo());

            if ($cuota->estaVencida()) {
                $vencido = $vencido->sumar($cuota->saldo());
            }
        }

        // Lo que hay que cubrir antes de que un centavo baje capital.
        $alDia = $vencido->sumar($mora);

        /*
         * No alcanza ni para eso: es un pago normal y no hay reprogramacion.
         * No se reescribe un plan por algo que no bajo el capital. La pantalla
         * lo registra igual —el dinero ya esta sobre el mostrador— y avisa.
         */
        if (! $abono->mayorQue($alDia)) {
            return self::sinReprogramar($abono, $modalidad, $alDia, $mora, $saldoDelLote, $cuotas, $tasa, esPagoNormal: true);
        }

        $aCapital = $abono->restar($alDia);
        $reparto = self::repartoFifo($cuotas, $vencido);

        /*
         * Las que quedan pendientes DESPUES de poner al dia, partidas en dos:
         * las que ya tienen algo pagado se respetan enteras, y las que nadie
         * toco todavia son las unicas que se pueden reescribir.
         */
        $reemplazables = [];
        $reprogramable = Monto::cero();
        $interesViejo = Monto::cero();

        foreach ($cuotas as $indice => $cuota) {
            $pagado = $cuota->montoPagado()->sumar($reparto[$indice] ?? Monto::cero());

            if ($pagado->esCero()) {
                $reemplazables[] = $cuota;
                // CAPITAL, no el monto de la cuota. Ver el docblock: la
                // diferencia es anatocismo.
                $reprogramable = $reprogramable->sumar($cuota->capitalPendiente());
                $interesViejo = $interesViejo->sumar($cuota->interesPendiente());
            }
        }

        $tope = $alDia->sumar($reprogramable);

        // `$reemplazables === []` ya esta cubierto por el tope: sin nada que
        // reprogramar el tope es lo vencido, y aca el abono siempre lo supera.
        if ($abono->mayorQue($tope) || $reemplazables === []) {
            return self::sinReprogramar($abono, $modalidad, $alDia, $mora, $saldoDelLote, $cuotas, $tasa, esPagoNormal: false);
        }

        $primera = $reemplazables[0];
        $desde = (int) $primera->getAttribute('numero');
        $cuotaVigente = $primera->montoTotal();
        $saldoNuevo = $reprogramable->restar($aCapital);

        /*
         * El calendario NO se mueve: la primera cuota nueva vence el dia en
         * que vencia la primera que se reemplaza. Lo que cambia es cuanto o
         * cuantas, nunca cuando.
         */
        $desdeCuando = self::vencimientoDe($primera) ?? CarbonImmutable::parse(today()->toDateString());

        $plan = null;
        $problema = null;

        try {
            $plan = $modalidad === ModalidadDeReprogramacion::AcortarPlazo
                ? PlanDeCuotas::porCuotaFija($saldoNuevo, $cuotaVigente, $diaPago, $desdeCuando, $desde, $tasa)
                : PlanDeCuotas::porPlazoFijo($saldoNuevo, count($reemplazables), $diaPago, $desdeCuando, $desde, $tasa);
        } catch (PlanDeCuotasInvalidoException $error) {
            // Pasa con un saldo que queda en centavos repartido entre muchos
            // meses, y tambien con una cuota que no cubre ni el interes del
            // mes. Se muestra en el modal en vez de reventar la pantalla.
            $problema = $error->getMessage();
        }

        return new self(
            abono: $abono,
            modalidad: $modalidad,
            ponerAlDia: $alDia,
            mora: $mora,
            aCapital: $aCapital,
            aplicaciones: self::aplicacionesDe($cuotas, $reparto),
            numerosReemplazados: array_map(
                static fn (Cuota $cuota): int => (int) $cuota->getAttribute('numero'),
                $reemplazables,
            ),
            planAnterior: self::snapshot($reemplazables),
            saldoDelLote: $saldoDelLote,
            tope: $tope,
            saldoReprogramable: $reprogramable,
            saldoNuevo: $saldoNuevo,
            cuotaVigente: $cuotaVigente,
            interesDelPlanViejo: $interesViejo,
            desdeNumero: $desde,
            planNuevo: $plan,
            tasa: $tasa,
            esPagoNormal: false,
            superaElTope: false,
            problema: $problema,
        );
    }

    /**
     * ¿Este abono efectivamente reescribe el plan?
     */
    public function hayReprogramacion(): bool
    {
        return ! $this->esPagoNormal && ! $this->superaElTope && $this->problema === null;
    }

    /**
     * ¿El abono termina de pagar lo que quedaba por reprogramar?
     */
    public function cancelaElPlan(): bool
    {
        return $this->hayReprogramacion() && $this->saldoNuevo->esCero();
    }

    /**
     * La cuota que va a pagar de acá en adelante. Null si no queda ninguna.
     */
    public function cuotaNueva(): ?Monto
    {
        return $this->planNuevo?->cuotaMensual();
    }

    /**
     * Cuantos meses se ahorra. Cero cuando eligio bajar la cuota.
     */
    public function mesesAhorrados(): int
    {
        return max(0, count($this->numerosReemplazados) - ($this->planNuevo?->count() ?? 0));
    }

    /**
     * ═══ EL NUMERO QUE HACE QUE ALGUIEN ABONE ═══
     *
     * Los intereses que esas cuotas iban a devengar, menos los que va a
     * devengar el plan nuevo. Con tasa 0 da cero y la pantalla no lo muestra;
     * con 12 % a 48 meses, un abono de L 50,000 puede ahorrar mas de
     * L 30,000 en intereses — y ESE es el argumento, no los meses.
     */
    public function interesesAhorrados(): Monto
    {
        $nuevo = $this->planNuevo?->totalInteres() ?? Monto::cero();

        return $this->interesDelPlanViejo->mayorQue($nuevo)
            ? $this->interesDelPlanViejo->restar($nuevo)
            : Monto::cero();
    }

    public function llevaInteres(): bool
    {
        return ! $this->tasa->esCero();
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param iterable<int, Cuota> $pendientes
     *
     * @return list<Cuota>
     */
    private static function enOrden(iterable $pendientes): array
    {
        $cuotas = [];

        foreach ($pendientes as $cuota) {
            $cuotas[] = $cuota;
        }

        /*
         * Por numero y no por fecha: dos cuotas pueden vencer el mismo dia si
         * el plan ya se reprogramo alguna vez, y el numero es el que no se
         * repite. Es el mismo criterio que usa el FIFO de RegistroDePagos.
         */
        usort(
            $cuotas,
            static fn (Cuota $uno, Cuota $otro): int => (int) $uno->getAttribute('numero') <=> (int) $otro->getAttribute('numero'),
        );

        return $cuotas;
    }

    /**
     * El reparto FIFO de `$monto`, indexado igual que `$cuotas`.
     *
     * Es un ESTIMADO, igual que el de la pantalla de cobro: el que manda es
     * `RegistroDePagos::repartir()`, que relee las cuotas con `FOR UPDATE`
     * dentro de la transaccion. Las dos recorren la misma lista en el mismo
     * orden con la misma regla.
     *
     * @param list<Cuota> $cuotas
     *
     * @return array<int, Monto>
     */
    private static function repartoFifo(array $cuotas, Monto $monto): array
    {
        $porRepartir = $monto;
        $reparto = [];

        foreach ($cuotas as $indice => $cuota) {
            if ($porRepartir->esCero()) {
                break;
            }

            $falta = $cuota->saldo();
            $leToca = $porRepartir->mayorQue($falta) ? $falta : $porRepartir;

            $reparto[$indice] = $leToca;
            $porRepartir = $porRepartir->restar($leToca);
        }

        return $reparto;
    }

    /**
     * @param list<Cuota> $cuotas
     * @param array<int, Monto> $reparto
     *
     * @return list<array{numero: int, monto: Monto, salda: bool}>
     */
    private static function aplicacionesDe(array $cuotas, array $reparto): array
    {
        $filas = [];

        foreach ($cuotas as $indice => $cuota) {
            $leToca = $reparto[$indice] ?? null;

            if (! $leToca instanceof Monto) {
                continue;
            }

            if ($leToca->esCero()) {
                continue;
            }

            $filas[] = [
                'numero' => (int) $cuota->getAttribute('numero'),
                'monto'  => $leToca,
                'salda'  => $cuota->saldo()->igualA($leToca),
            ];
        }

        return $filas;
    }

    /**
     * El plan viejo tal como va a la columna jsonb.
     *
     * @param list<Cuota> $reemplazables
     *
     * @return list<array{numero: int, vence: string, monto: string}>
     */
    private static function snapshot(array $reemplazables): array
    {
        $filas = [];

        foreach ($reemplazables as $cuota) {
            $filas[] = [
                'numero' => (int) $cuota->getAttribute('numero'),
                'vence'  => self::vencimientoDe($cuota)?->toDateString() ?? '',
                'monto'  => $cuota->montoTotal()->redondeado(),
            ];
        }

        return $filas;
    }

    /**
     * El cast `date` de Cuota devuelve un Carbon MUTABLE, y el dominio trabaja
     * con `CarbonImmutable` a proposito (por eso `CarbonToDateFacadeRector`
     * esta apagado). Se convierte en el borde y una sola vez.
     */
    private static function vencimientoDe(Cuota $cuota): ?CarbonImmutable
    {
        $fecha = $cuota->getAttribute('fecha_vencimiento');

        return $fecha instanceof DateTimeInterface
            ? CarbonImmutable::parse($fecha->format('Y-m-d'))
            : null;
    }

    /**
     * El resultado cuando no hay nada que reprogramar.
     *
     * @param list<Cuota> $cuotas
     */
    private static function sinReprogramar(
        Monto $abono,
        ModalidadDeReprogramacion $modalidad,
        Monto $alDia,
        Monto $mora,
        Monto $saldoDelLote,
        array $cuotas,
        TasaDeInteres $tasa,
        bool $esPagoNormal,
    ): self {
        $reprogramable = Monto::cero();
        $interesViejo = Monto::cero();

        foreach ($cuotas as $cuota) {
            if ($cuota->montoPagado()->esCero() && ! $cuota->estaVencida()) {
                $reprogramable = $reprogramable->sumar($cuota->capitalPendiente());
                $interesViejo = $interesViejo->sumar($cuota->interesPendiente());
            }
        }

        /*
         * El estimado del reparto va SIN la mora: la mora se cobra antes que
         * las cuotas, asi que el dinero que llega a ellas es lo que sobre.
         * Sin este descuento el modal mostraria cuotas saldadas que en el
         * mostrador van a salir parciales.
         */
        $paraLasCuotas = $esPagoNormal
            ? ($abono->mayorQue($mora) ? $abono->restar($mora) : Monto::cero())
            : $alDia->restar($mora);

        return new self(
            abono: $abono,
            modalidad: $modalidad,
            ponerAlDia: $esPagoNormal ? $abono : $alDia,
            mora: $mora,
            aCapital: Monto::cero(),
            aplicaciones: self::aplicacionesDe($cuotas, self::repartoFifo($cuotas, $paraLasCuotas)),
            numerosReemplazados: [],
            planAnterior: [],
            saldoDelLote: $saldoDelLote,
            tope: $alDia->sumar($reprogramable),
            saldoReprogramable: $reprogramable,
            saldoNuevo: $reprogramable,
            cuotaVigente: null,
            interesDelPlanViejo: $interesViejo,
            desdeNumero: 0,
            planNuevo: null,
            tasa: $tasa,
            esPagoNormal: $esPagoNormal,
            superaElTope: ! $esPagoNormal,
            problema: null,
        );
    }
}
