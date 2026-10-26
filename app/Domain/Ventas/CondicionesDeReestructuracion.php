<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;

/**
 * Lo que se pacta al reestructurar un plan: cuánto por mes o cuántos meses,
 * si la cuota trae el interés adentro, a qué tasa y desde cuándo.
 *
 * ═══ DE DONDE SALE — 25-sep-2026 ═══
 *
 * Lo pidió la primera lotificadora que lo necesitó, con un contrato enfrente:
 * dos lotes firmados a 6 meses cada uno, pagándose al mismo tiempo, y un
 * cliente que paga L 100,000.00 al mes porque entendió un año. Lo que se
 * pidió:
 *
 *  - «Unificar el expediente: que todo sea como un solo lote», un solo
 *    capital y un solo interés (Mauricio: «si se unifican se tomarán como
 *    uno solo, un solo interés, un solo capital»).
 *  - Poder fijar **cuántos meses** o **de cuánto la cuota**, y que con la
 *    cuota el sistema diga el mínimo de meses que hace falta pagarla.
 *  - Que diga **si la cuota ya trae el interés o se le suma**.
 *
 * ═══ UNA DE LAS DOS, NUNCA LAS DOS ═══
 *
 * Meses y cuota son el mismo contrato visto de dos lados: fijado uno, el otro
 * sale de la cuenta. Dejar escribir los dos sería invitar a una combinación
 * que no cierra. Por eso hay dos constructores con nombre y el general es
 * privado.
 *
 * ═══ EL DIA DE PAGO SALE DEL PRIMER VENCIMIENTO ═══
 *
 * No se pregunta aparte: la primera cuota vence un día, y ese es el día de
 * todas las demás. Preguntar las dos cosas por separado deja pactar un plan
 * cuyo primer pago cae un día que después no se repite nunca.
 *
 * ═══ O A FECHAS, Y CON DESCUENTO (18-oct-2026) ═══
 *
 * El caso que lo pidió: un lote que pagó la cuota 1 de un plan a 95 meses y se
 * renegoció a dos pagos —L 650,000.00 el 13/10/2026 y L 350,000.00 el
 * 15/01/2027— perdonándole una parte del capital para que quedara en un
 * millón parejo. Ni meses ni cuota: fechas (`porFechas()`), con el mismo
 * motor que la venta a fechas pactadas. Y el descuento (`conDescuento()`),
 * firme el día del acta, que vale para cualquiera de las tres.
 *
 * ═══ ESO ES RENEGOCIAR, NO REESTRUCTURAR (18-oct-2026, tarde) ═══
 *
 * «Ya está la reestructuración, que es para cuadrar un plan, pero falta la
 * renegociación, que es para rehacer de 0 con lo que pagó, si se le dan
 * descuentos, si se le quita la mora o se le cambia la tasa» —Mauricio—.
 * Las fechas, el descuento y quitar la mora (`sinMora()`) solo entran por
 * `RegistroDeReestructuraciones::renegociar()`; `pideRenegociar()` es lo que
 * le permite a `reestructurar()` negarse.
 */
final readonly class CondicionesDeReestructuracion
{
    public const string FIJA_MESES = 'meses';

    public const string FIJA_CUOTA = 'cuota';

    public const string FIJA_FECHAS = 'fechas';

    /**
     * @param list<PagoPactado> $pagos con «A fechas», los pagos pactados en
     *                                 orden; vacío en las otras dos
     */
    private function __construct(
        public ?int $meses,
        public ?Monto $cuota,
        public bool $interesIncluido,
        public TasaDeInteres $tasa,
        public CarbonImmutable $primerVencimiento,
        /**
         * Con la cuota fija: ¿el pago chico que sobra al final va sumado a la
         * última cuota? (26-sep-2026). Con los meses fijos no aplica: ahí
         * nunca sobra un pago.
         */
        public bool $restoALaUltima = false,
        public array $pagos = [],
        /**
         * Con «A fechas»: desde cuándo devenga el primer pago. Lo vuelve a
         * poner el Service con la foto bloqueada (§9.D17).
         */
        public ?CarbonImmutable $devengaDesde = null,
        /**
         * Lo que se le perdona del capital al reestructurar (18-oct-2026).
         * Null es sin descuento.
         */
        public ?Monto $descuento = null,
        /**
         * Renegociando: ¿desde el acta los lotes dejan de cobrar mora?
         * (18-oct-2026). La que ya corría se perdona igual que siempre.
         */
        public bool $quitaLaMora = false,
        /**
         * ¿Cada lote con su plan y su cuota, en vez de juntarlos en una sola?
         * (Praderas, 25-oct-2026). Las mismas condiciones se le aplican a
         * cada lote por su lado: «24 meses» son 24 meses para cada uno.
         */
        public bool $cadaLote = false,
    ) {}

    /**
     * Se fijan los meses y la cuota sale de la cuenta.
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function porMeses(
        int $meses,
        bool $interesIncluido,
        TasaDeInteres $tasa,
        CarbonImmutable $primerVencimiento,
    ): self {
        if ($meses < 1 || $meses > PlanDeCuotas::PLAZO_MAXIMO_MESES) {
            throw PlanDeCuotasInvalidoException::porPlazoInvalido($meses);
        }

        return new self($meses, null, $interesIncluido, $tasa, $primerVencimiento->startOfDay());
    }

    /**
     * Se fija la cuota y los meses salen de la cuenta: los mínimos que hacen
     * falta para pagar el capital con esa cuota.
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function porCuota(
        Monto $cuota,
        bool $interesIncluido,
        TasaDeInteres $tasa,
        CarbonImmutable $primerVencimiento,
        bool $restoALaUltima = false,
    ): self {
        if ($cuota->esCero()) {
            throw PlanDeCuotasInvalidoException::porCuotaEnCero();
        }

        return new self(null, $cuota, $interesIncluido, $tasa, $primerVencimiento->startOfDay(), $restoALaUltima);
    }

    /**
     * Las fechas se pactan una por una (18-oct-2026).
     *
     * Lo pactado es CAPITAL, igual que al vender a fechas (ver `PagoPactado`):
     * sin interés es lo que el cliente entrega; con interés se le suma el de
     * los días de cada tramo (`PlanDeCuotas::aFechasPactadas()`). La primera
     * fecha es el primer vencimiento, y su día queda como día de pago del
     * contrato, que en un plan a fechas no significa nada (`ventas.dia_pago`).
     *
     * `$devengaDesde` es obligatorio a propósito: el primer tramo devenga
     * desde el vencimiento de la última cuota que queda pagada, y un default
     * inventado —«hoy», «la firma»— no fallaría: mentiría el interés del
     * primer pago (la lección del 19-sep-2026 con `EfectoDelAbono`). La
     * pantalla lo pide a `SituacionDelPlan::devengaDesde()` y el Service lo
     * vuelve a poner con la foto bloqueada.
     *
     * @param list<PagoPactado> $pagos en el orden en que se van a pagar
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function porFechas(array $pagos, TasaDeInteres $tasa, CarbonImmutable $devengaDesde): self
    {
        if ($pagos === []) {
            throw PlanDeCuotasInvalidoException::porPlanPactadoSinFechas();
        }

        if (count($pagos) > PlanDeCuotas::PAGOS_PACTADOS_MAXIMOS) {
            throw PlanDeCuotasInvalidoException::porDemasiadasFechasPactadas(count($pagos));
        }

        return new self(
            meses: null,
            cuota: null,
            // El interés de un plan a fechas va encima de lo pactado: lo
            // pactado es capital. Con tasa cero la pregunta no existe.
            interesIncluido: false,
            tasa: $tasa,
            primerVencimiento: $pagos[0]->fecha->startOfDay(),
            pagos: $pagos,
            devengaDesde: $devengaDesde->startOfDay(),
        );
    }

    /**
     * Las mismas condiciones con un descuento de capital (18-oct-2026).
     *
     * Firme y el día del acta —Mauricio, 18-oct-2026, eligiendo entre firme y
     * condicionado a que pague a tiempo—: el plan nuevo se arma sobre el
     * capital menos el descuento, y lo que se perdonó queda escrito en el
     * acta y en la constancia de cada lote. Cero es sin descuento.
     *
     * Que no llegue al capital entero lo verifica `PlanUnificado::armar()`,
     * que es el que conoce el capital.
     */
    public function conDescuento(Monto $descuento): self
    {
        return new self(
            $this->meses,
            $this->cuota,
            $this->interesIncluido,
            $this->tasa,
            $this->primerVencimiento,
            $this->restoALaUltima,
            $this->pagos,
            $this->devengaDesde,
            $descuento->esCero() ? null : new Monto($descuento->redondeado()),
            $this->quitaLaMora,
            $this->cadaLote,
        );
    }

    /**
     * Las mismas condiciones, y desde el acta sin mora (18-oct-2026).
     *
     * Lo que pidió el cliente del lote que se renegoció a 0 % «y sin mora»:
     * la mora es del contrato firmado (§8.6), así que quitarla es parte de lo
     * que se renegocia y queda escrito en el acta, con la que tenía antes.
     */
    public function sinMora(): self
    {
        return new self(
            $this->meses,
            $this->cuota,
            $this->interesIncluido,
            $this->tasa,
            $this->primerVencimiento,
            $this->restoALaUltima,
            $this->pagos,
            $this->devengaDesde,
            $this->descuento,
            true,
            $this->cadaLote,
        );
    }

    /**
     * Las mismas condiciones devengando desde otra fecha: la que dice la foto
     * bloqueada, no la que traía la pantalla (§9.D17). Sin «A fechas» no
     * cambia nada.
     */
    public function conDevengoDesde(CarbonImmutable $fecha): self
    {
        if (! $this->esAFechas()) {
            return $this;
        }

        return new self(
            $this->meses,
            $this->cuota,
            $this->interesIncluido,
            $this->tasa,
            $this->primerVencimiento,
            $this->restoALaUltima,
            $this->pagos,
            $fecha->startOfDay(),
            $this->descuento,
            $this->quitaLaMora,
            $this->cadaLote,
        );
    }

    /**
     * Las mismas condiciones, para cada lote por su lado (Praderas,
     * 25-oct-2026): cada uno con su plan y su cuota, en vez de juntarlos.
     *
     * «Que se pueda unificar los lotes para una sola cuota o que tengan cuota
     * independiente según como lo reestructure» —Mauricio, con el expediente
     * 0033 enfrente: dos lotes a 24 meses cada uno—. Con un solo lote da lo
     * mismo: un lote solo ya es «cada uno por su lado».
     */
    public function cadaLoteConSuCuota(): self
    {
        return new self(
            $this->meses,
            $this->cuota,
            $this->interesIncluido,
            $this->tasa,
            $this->primerVencimiento,
            $this->restoALaUltima,
            $this->pagos,
            $this->devengaDesde,
            $this->descuento,
            $this->quitaLaMora,
            true,
        );
    }

    /**
     * Cómo quedan los lotes, tal como se guarda en
     * `reestructuraciones.agrupacion`.
     */
    public function agrupacion(): string
    {
        // A fechas el calendario es uno solo para el contrato (`PlanUnificado::armar()`).
        return $this->cadaLote && ! $this->esAFechas() ? 'por_lote' : 'unificado';
    }

    /**
     * ¿Se pactaron fechas en vez de meses o cuota?
     */
    public function esAFechas(): bool
    {
        return $this->pagos !== [];
    }

    /**
     * Lo que se perdona del capital; cero si no hay descuento.
     */
    public function descuento(): Monto
    {
        return $this->descuento ?? Monto::cero();
    }

    /**
     * ¿Trae algo que solo se pacta renegociando? Fechas, un descuento o
     * quitar la mora (18-oct-2026). Cambiar la tasa no: reestructurar ya la
     * cambiaba desde el 25-sep-2026.
     */
    public function pideRenegociar(): bool
    {
        if ($this->esAFechas()) {
            return true;
        }

        if (! $this->descuento()->esCero()) {
            return true;
        }

        return $this->quitaLaMora;
    }

    public function fijaMeses(): bool
    {
        return $this->meses !== null;
    }

    /**
     * Qué se fijó, tal como se guarda en `reestructuraciones.fijado`.
     */
    public function fijado(): string
    {
        if ($this->esAFechas()) {
            return self::FIJA_FECHAS;
        }

        return $this->fijaMeses() ? self::FIJA_MESES : self::FIJA_CUOTA;
    }

    /**
     * ¿El interés va ENCIMA de la cuota?
     *
     * Con tasa cero la pregunta no existe: «incluido» y «encima» son el mismo
     * plan, todo capital. Se contesta que no para que haya un solo camino.
     */
    public function interesEncima(): bool
    {
        return ! $this->interesIncluido && ! $this->tasa->esCero();
    }

    public function diaDePago(): int
    {
        return $this->primerVencimiento->day;
    }
}
