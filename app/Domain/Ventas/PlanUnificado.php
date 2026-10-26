<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Los lotes de un contrato pagándose como si fueran UNO: un solo capital, un
 * solo interés, una sola cuota por mes.
 *
 * ═══ QUE SE PIDIO — 25-sep-2026 ═══
 *
 * Un contrato con dos lotes firmados a 6 meses cada uno. El cliente paga
 * L 100,000.00 al mes porque entendió un año, y la administración quiere
 * «juntar todo y hacer como que todo fuera un solo lote». Mauricio lo cerró:
 * «un solo interés, un solo capital».
 *
 * ═══ PARA EL CLIENTE ES UNO; EN LA BASE SIGUEN SIENDO DOS ═══
 *
 * La cuenta se hace sobre el capital TOTAL, como la haría el cliente con su
 * Excel: una tabla, una tasa, una cuota. Pero las cuotas se guardan POR LOTE
 * (`cuotas.compromiso_id`), y así tiene que seguir: la rescisión es por lote,
 * la comisión es por lote, saber cuándo un lote quedó pagado para escriturarlo
 * es por lote, y la mora se cuenta por cuota de cada lote.
 *
 * Así que cada renglón del plan del contrato se reparte entre los lotes en
 * proporción a lo que debe cada uno. No altera la cuenta: con la misma tasa el
 * interés es lineal en el saldo, y repartir la tabla del total da lo mismo que
 * amortizar cada lote por su lado, salvo centavos de redondeo.
 *
 * ═══ LAS DOS INVARIANTES, IGUAL QUE `RepartoPactado` ═══
 *
 *  1. **Cada lote cierra contra SU capital**, al céntimo. Es lo que hace que
 *     su estado de cuenta termine en cero.
 *  2. **Cada mes suma exactamente la cuota del contrato**, capital con capital
 *     e interés con interés. Es lo que el cliente firma.
 *
 * Las dos a la vez con un **lote ancla** —el que más debe—: los demás se
 * reparten a prorrata con el residuo del capital a su último mes (1), y el
 * ancla se queda con lo que falta en cada mes (2). Su total sale solo, por la
 * misma resta que explica el docblock de `RepartoPactado`.
 *
 * ═══ ES PURO ═══
 *
 * Sin base de datos: lo usa la vista previa del modal y lo usa el Service al
 * escribir, con el mismo código. Lo que se ve es lo que se guarda (§10.8).
 *
 * ═══ A FECHAS, Y CON DESCUENTO (18-oct-2026) ═══
 *
 * Con fechas pactadas (`CondicionesDeReestructuracion::porFechas()`) el plan
 * del contrato lo arma `PlanDeCuotas::aFechasPactadas()` y se reparte entre
 * los lotes igual que cualquier otro: renglón por renglón, con las mismas dos
 * invariantes.
 *
 * El descuento se reparte ANTES de armar nada: cada lote pierde su parte a
 * prorrata de lo que debe, el ancla se queda con los centavos, y el plan se
 * arma sobre lo que queda. Así la invariante 1 sigue diciendo «cada lote
 * cierra contra su capital» —el de después del descuento— y lo que se
 * perdonó a cada uno queda en `$descuentos` para su constancia.
 */
final readonly class PlanUnificado
{
    /**
     * @param array<int, list<CuotaProyectada>> $porLote por id de compromiso, en el orden en que llegaron
     * @param array<int, Monto> $descuentos lo que se le perdonó a cada lote, por id de compromiso;
     *                                      vacío sin descuento
     */
    private function __construct(
        public PlanDeCuotas $contrato,
        public array $porLote,
        public array $descuentos = [],
        /**
         * ¿Cada lote se armó por su lado, con su propio plan? (Praderas,
         * 25-oct-2026). Lo pregunta `CoberturaDelPlan`: con los lotes por su
         * lado, lo que un recibo pagó de un lote vuelve a ese lote y no a otro.
         */
        public bool $cadaLote = false,
    ) {}

    /**
     * El plan del contrato y su reparto entre los lotes.
     *
     * @param array<int, Monto> $capitales el capital que le falta a cada
     *                                     lote, por id de compromiso, en el
     *                                     orden del contrato
     * @param int $primerNumero con qué número arranca: el siguiente a la
     *                          última cuota pagada (ver `PlanDeCuotas::armar()`)
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function armar(array $capitales, CondicionesDeReestructuracion $condiciones, int $primerNumero): self
    {
        if ($capitales === []) {
            throw PlanDeCuotasInvalidoException::porRepartoSinLotes();
        }

        $total = Monto::cero();

        foreach ($capitales as $capital) {
            $total = $total->sumar($capital);
        }

        if ($total->esCero()) {
            throw PlanDeCuotasInvalidoException::porRepartoSinSaldo();
        }

        // El descuento baja el capital de cada lote antes de armar nada.
        $descuentos = self::repartirElDescuento($capitales, $condiciones->descuento(), $total);

        foreach ($descuentos as $clave => $parte) {
            $capitales[$clave] = $capitales[$clave]->restar($parte);
            $total = $total->restar($parte);
        }

        // Cada lote por su lado (Praderas, 25-oct-2026). Con uno solo da lo
        // mismo, y a fechas el calendario es uno solo para el contrato.
        if ($condiciones->cadaLote && count($capitales) > 1 && ! $condiciones->esAFechas()) {
            return self::cadaUnoPorSuLado($capitales, $total, $condiciones, $primerNumero, $descuentos);
        }

        $contrato = self::delContrato($total, $condiciones, $primerNumero);

        return new self($contrato, self::repartir($contrato, $capitales, $total), $descuentos);
    }

    /**
     * Cada lote con su plan y su cuota (Praderas, 25-oct-2026).
     *
     * ═══ QUE SE PIDIO ═══
     *
     * «Que se pueda unificar los lotes para una sola cuota o que tengan cuota
     * independiente según como lo reestructure» —Mauricio, con el expediente
     * 0033: dos lotes que se rehacen a 24 meses cada uno—.
     *
     * ═══ LA MISMA CUENTA, UNA VEZ POR LOTE ═══
     *
     * Cada lote arma su plan con `delContrato()`, que es exactamente lo que
     * hace un lote solo: el redondeo, las fechas, el resto a la última, el
     * interés incluido o encima. Escribir otra versión de esa cuenta sería
     * tener dos (§9.D10). Las condiciones son las mismas para todos —«24
     * meses» son 24 para cada uno, «L 10,000 por mes» son L 10,000 cada uno—.
     *
     * El plan «del contrato» —lo que se imprime en el acta y lo que se cobra
     * por mes— es la suma, mes por mes, de lo que cada lote paga ese mes. Con
     * los mismos meses todos los lotes vencen el mismo día, así que cada
     * renglón suma cosas del mismo día. Con una cuota fija un lote puede
     * terminar antes que otro: los meses del final suman solo a los que
     * todavía pagan.
     *
     * Las dos invariantes de la clase siguen en pie, ahora sin reparto que
     * las tenga que cuidar: cada lote cierra contra SU capital porque es su
     * propio plan, y cada mes suma exactamente lo que se cobra.
     *
     * @param array<int, Monto> $capitales ya sin el descuento
     * @param array<int, Monto> $descuentos
     *
     * @throws PlanDeCuotasInvalidoException
     */
    private static function cadaUnoPorSuLado(
        array $capitales,
        Monto $total,
        CondicionesDeReestructuracion $condiciones,
        int $primerNumero,
        array $descuentos,
    ): self {
        $porLote = [];
        $porNumero = [];

        foreach ($capitales as $clave => $capital) {
            $plan = self::delContrato($capital, $condiciones, $primerNumero);

            self::verificarQueCierra($plan->cuotas, $capital);

            $porLote[$clave] = $plan->cuotas;

            foreach ($plan->cuotas as $cuota) {
                $previo = $porNumero[$cuota->numero] ?? null;

                $porNumero[$cuota->numero] = [
                    'vencimiento' => $cuota->vencimiento,
                    'capital'     => ($previo['capital'] ?? Monto::cero())->sumar($cuota->capital),
                    'interes'     => ($previo['interes'] ?? Monto::cero())->sumar($cuota->interes),
                ];
            }
        }

        ksort($porNumero);

        $renglones = [];

        foreach ($porNumero as $numero => $mes) {
            $renglones[] = CuotaProyectada::conInteres(
                numero: $numero,
                vencimiento: $mes['vencimiento'],
                capital: $mes['capital'],
                interes: $mes['interes'],
            );
        }

        return new self(
            PlanDeCuotas::delReparto($renglones, $total, $condiciones->tasa),
            $porLote,
            $descuentos,
            cadaLote: true,
        );
    }

    /**
     * El plan de UN capital, como lo haría el cliente en su Excel.
     *
     * ═══ LOS CUATRO CASOS, Y NINGUNO ES CUENTA NUEVA ═══
     *
     * - Meses, interés incluido → `porPlazoFijo()`: la tabla francesa con la
     *   cuota cerrada hacia arriba y la última absorbiendo (31-ago-2026).
     * - Cuota, interés incluido → `porCuotaFija()`: la tabla francesa con la
     *   cuota como dato, que devuelve cuántas hacen falta. Ese conteo ES «el
     *   mínimo de meses que tiene que estar pagando eso».
     * - Meses o cuota con el interés ENCIMA → los mismos dos constructores
     *   con tasa cero, que reparten solo el capital, y `conInteresEncima()`
     *   le suma a cada mes el interés de su saldo.
     *
     * Así el redondeo, las fechas y los topes son los de siempre. Escribir la
     * francesa otra vez acá sería tener dos versiones de la misma cuenta
     * (§9.D10), y la del contrato unificado terminaría diciendo un centavo
     * distinto que la de un lote solo.
     *
     * ⚠️ Una cuota fija chica sin interés puede pedir miles de meses, y
     * `porCuotaFija()` sin tasa no tiene tope: armaría la lista entera antes de
     * que alguien pueda decir que no. Se pregunta ANTES.
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function delContrato(Monto $capital, CondicionesDeReestructuracion $condiciones, int $primerNumero): PlanDeCuotas
    {
        // A fechas (18-oct-2026): el calendario viene escrito, no se calcula.
        if ($condiciones->esAFechas()) {
            return self::aFechas($capital, $condiciones, $primerNumero);
        }

        $dia = $condiciones->diaDePago();
        $mes = $condiciones->primerVencimiento;

        if ($condiciones->cuota instanceof Monto) {
            self::verificarQueCabeEnElTope($capital, $condiciones->cuota);
        }

        if ($condiciones->interesEncima()) {
            $soloCapital = $condiciones->meses !== null
                ? PlanDeCuotas::porPlazoFijo($capital, $condiciones->meses, $dia, $mes, $primerNumero, TasaDeInteres::cero())
                : PlanDeCuotas::porCuotaFija($capital, self::cuotaDe($condiciones), $dia, $mes, $primerNumero, TasaDeInteres::cero());

            return self::conElResto(PlanDeCuotas::conInteresEncima($soloCapital, $condiciones->tasa), $condiciones);
        }

        return $condiciones->meses !== null
            ? PlanDeCuotas::porPlazoFijo($capital, $condiciones->meses, $dia, $mes, $primerNumero, $condiciones->tasa)
            : self::conElResto(
                PlanDeCuotas::porCuotaFija($capital, self::cuotaDe($condiciones), $dia, $mes, $primerNumero, $condiciones->tasa),
                $condiciones,
            );
    }

    /**
     * ¿La cuota fija deja un pago chico al final? El que sobra cuando el
     * último pago es menos de la mitad de la cuota: más que eso ya es un pago
     * de verdad, y sumarlo haría una última cuota del doble.
     */
    public static function dejaUnResto(PlanDeCuotas $plan): bool
    {
        $primera = $plan->cuotaMensual();
        $ultima = $plan->ultima()?->monto;

        return $plan->count() > 1
            && $primera instanceof Monto
            && $ultima instanceof Monto
            && $ultima->multiplicarPor(2)->menorQue($primera);
    }

    /**
     * Si se pactó así, el pago chico del final va sumado a la cuota anterior
     * (`PlanDeCuotas::conElRestoEnLaUltima()`). Solo con la cuota fija y solo
     * si de verdad sobra un pago chico: si no, el plan queda como está.
     *
     * ═══ LOS CENTAVOS DEL REDONDEO VAN SIEMPRE A LA ÚLTIMA (15-oct-2026) ═══
     *
     * Aunque no se haya pactado. REB, venta 17: tres lotes, 48 meses y la
     * cuota que el cliente venía pagando, L 35,002.13 —el saldo entre 48,
     * redondeado al centavo—. En 48 pagos quedaban centavos sin pagar, y
     * salía un pago 49 de centavos que, repartido entre los tres lotes,
     * dejaba a uno en cero: «Al repartir el pago del 18/06/2030… uno queda sin
     * nada que cancelar». Un pago de menos de un centavo por mes no es una
     * cuota: es el redondeo de la cuota, y el residuo va a la última (§8.2).
     * Un resto de verdad —L 903.36 después de doce cuotas de L 100,000— sigue
     * siendo un pago más al final, como se decidió.
     */
    private static function conElResto(PlanDeCuotas $plan, CondicionesDeReestructuracion $condiciones): PlanDeCuotas
    {
        if ($condiciones->fijaMeses()) {
            return $plan;
        }

        $plan = PlanDeCuotas::sinElRedondeoAlFinal($plan);

        if (! $condiciones->restoALaUltima) {
            return $plan;
        }

        if (! self::dejaUnResto($plan)) {
            return $plan;
        }

        return PlanDeCuotas::conElRestoEnLaUltima($plan);
    }

    /**
     * La cuota que cierra el mismo capital en `$meses` pagos parejos.
     *
     * Es la otra mitad de «el mínimo de meses»: con L 100,000.00 hacen falta
     * 13 pagos y el último es de L 900; esto contesta cuánto tendría que ser
     * la cuota para terminar en 12. Sale del MISMO motor que después firma.
     *
     * Null si no se puede: un solo mes no tiene «pagos parejos», y un capital
     * chico en muchos meses no cierra.
     */
    public static function cuotaParaCerrarEn(
        Monto $capital,
        int $meses,
        CondicionesDeReestructuracion $base,
    ): ?Monto {
        if ($meses < 1) {
            return null;
        }

        try {
            $plan = self::delContrato(
                $capital,
                CondicionesDeReestructuracion::porMeses($meses, $base->interesIncluido, $base->tasa, $base->primerVencimiento),
                1,
            );
        } catch (PlanDeCuotasInvalidoException) {
            return null;
        }

        return $plan->cuotaMensual();
    }

    public function totalInteres(): Monto
    {
        return $this->contrato->totalInteres();
    }

    /**
     * Lo que se perdonó entre todos los lotes: el descuento del acta.
     */
    public function descuentoTotal(): Monto
    {
        $total = Monto::cero();

        foreach ($this->descuentos as $parte) {
            $total = $total->sumar($parte);
        }

        return $total;
    }

    public function meses(): int
    {
        return $this->contrato->count();
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El plan de las fechas pactadas, sobre ese capital.
     *
     * Lo arma el MISMO motor que la venta a fechas: verifica el orden de las
     * fechas, que ningún pago sea cero ni pase del saldo, que lo pactado sume
     * el capital al centavo, y devenga el interés por días reales desde
     * `devengaDesde`.
     *
     * @throws PlanDeCuotasInvalidoException
     */
    private static function aFechas(Monto $capital, CondicionesDeReestructuracion $condiciones, int $primerNumero): PlanDeCuotas
    {
        $desde = $condiciones->devengaDesde;

        // `porFechas()` lo exige: si falta, alguien armó las condiciones a mano.
        if (! $desde instanceof CarbonImmutable) {
            throw new LogicException('Condiciones a fechas sin la fecha desde la que devenga.');
        }

        return PlanDeCuotas::aFechasPactadas($condiciones->pagos, $capital, $desde, $primerNumero, $condiciones->tasa);
    }

    /**
     * El descuento repartido entre los lotes, por id de compromiso.
     *
     * A prorrata de lo que debe cada uno, redondeado al centavo, y el ancla
     * —el que más debe, como en el reparto del plan— se queda con lo que falta
     * para que la suma dé el descuento exacto. Ningún lote puede quedar en
     * cero: un descuento que se come el capital de uno no es un plan para él.
     *
     * @param array<int, Monto> $capitales
     *
     * @return array<int, Monto> vacío si no hay descuento
     *
     * @throws PlanDeCuotasInvalidoException
     */
    private static function repartirElDescuento(array $capitales, Monto $descuento, Monto $total): array
    {
        if ($descuento->esCero()) {
            return [];
        }

        if (! $descuento->menorQue($total)) {
            throw PlanDeCuotasInvalidoException::porDescuentoQueNoDejaCapital($descuento, $total);
        }

        $ancla = self::ancla($capitales);
        $tomado = Monto::cero();
        $partes = [];

        foreach ($capitales as $clave => $capital) {
            if ($clave === $ancla) {
                continue;
            }

            $partes[$clave] = self::aProrrata($descuento, $capital, $total);
            $tomado = $tomado->sumar($partes[$clave]);
        }

        // Se pregunta antes de restar: `Monto::restar()` tira con negativos.
        if ($tomado->mayorQue($descuento)) {
            throw PlanDeCuotasInvalidoException::porDescuentoQueNoDejaCapital($descuento, $total);
        }

        $partes[$ancla] = $descuento->restar($tomado);

        $ordenado = [];

        foreach ($capitales as $clave => $capital) {
            if (! $partes[$clave]->menorQue($capital)) {
                throw PlanDeCuotasInvalidoException::porDescuentoQueNoDejaCapital($partes[$clave], $capital);
            }

            if (! $partes[$clave]->esCero()) {
                $ordenado[$clave] = $partes[$clave];
            }
        }

        return $ordenado;
    }

    /**
     * @throws PlanDeCuotasInvalidoException
     */
    private static function cuotaDe(CondicionesDeReestructuracion $condiciones): Monto
    {
        if (! $condiciones->cuota instanceof Monto) {
            throw PlanDeCuotasInvalidoException::porCuotaEnCero();
        }

        return $condiciones->cuota;
    }

    /**
     * ¿Esa cuota paga el capital antes del tope de cordura?
     *
     * Sin interés la cuenta es exacta —capital ÷ cuota—. Con interés hacen
     * falta MÁS meses, así que si ya sin él no entra, con él tampoco; y si
     * entra, el tope propio de `TablaDeAmortizacion::porCuota()` hace el resto.
     *
     * @throws PlanDeCuotasInvalidoException
     */
    private static function verificarQueCabeEnElTope(Monto $capital, Monto $cuota): void
    {
        if ($cuota->esCero()) {
            throw PlanDeCuotasInvalidoException::porCuotaEnCero();
        }

        $tope = $cuota->multiplicarPor(PlanDeCuotas::PLAZO_MAXIMO_MESES);

        if ($capital->mayorQue($tope)) {
            throw PlanDeCuotasInvalidoException::porPlazoInvalido(PlanDeCuotas::PLAZO_MAXIMO_MESES + 1);
        }
    }

    /**
     * Cada renglón del contrato, bajado a los lotes.
     *
     * @param array<int, Monto> $capitales
     *
     * @return array<int, list<CuotaProyectada>>
     *
     * @throws PlanDeCuotasInvalidoException
     */
    private static function repartir(PlanDeCuotas $contrato, array $capitales, Monto $total): array
    {
        // Un solo lote: no hay nada que repartir. Es el caso de reestructurar
        // el plan de un contrato de un lote, y no pasa por ninguna cuenta.
        if (count($capitales) === 1) {
            $solo = [];

            foreach (array_keys($capitales) as $clave) {
                $solo[$clave] = $contrato->cuotas;
            }

            return $solo;
        }

        $ancla = self::ancla($capitales);
        $renglones = $contrato->cuotas;
        $ultimo = count($renglones) - 1;

        $capitalTomado = array_fill(0, count($renglones), Monto::cero());
        $interesTomado = array_fill(0, count($renglones), Monto::cero());
        $reparto = [];

        foreach ($capitales as $clave => $capital) {
            if ($clave === $ancla) {
                continue;
            }

            $suyas = [];
            $acumulado = Monto::cero();

            foreach ($renglones as $i => $renglon) {
                if ($i === $ultimo) {
                    /*
                     * El residuo del capital va al último mes (§8.2), donde se
                     * ve y no arrastra. Se pregunta ANTES de restar:
                     * `Monto::restar()` no devuelve negativos, tira.
                     */
                    if ($acumulado->mayorQue($capital)) {
                        throw PlanDeCuotasInvalidoException::porRepartoQueDejaUnLoteEnCero(
                            $renglon->vencimiento->format('d/m/Y'),
                        );
                    }

                    $parteDeCapital = $capital->restar($acumulado);
                } else {
                    $parteDeCapital = self::aProrrata($renglon->capital, $capital, $total);
                    $acumulado = $acumulado->sumar($parteDeCapital);
                }

                $parteDeInteres = self::aProrrata($renglon->interes, $capital, $total);

                if ($parteDeCapital->sumar($parteDeInteres)->esCero()) {
                    throw PlanDeCuotasInvalidoException::porRepartoQueDejaUnLoteEnCero(
                        $renglon->vencimiento->format('d/m/Y'),
                    );
                }

                $suyas[] = CuotaProyectada::conInteres(
                    numero: $renglon->numero,
                    vencimiento: $renglon->vencimiento,
                    capital: $parteDeCapital,
                    interes: $parteDeInteres,
                );

                $capitalTomado[$i] = $capitalTomado[$i]->sumar($parteDeCapital);
                $interesTomado[$i] = $interesTomado[$i]->sumar($parteDeInteres);
            }

            $reparto[$clave] = $suyas;
        }

        $delAncla = [];

        foreach ($renglones as $i => $renglon) {
            if ($capitalTomado[$i]->mayorQue($renglon->capital) || $interesTomado[$i]->mayorQue($renglon->interes)) {
                throw PlanDeCuotasInvalidoException::porRepartoQueDejaUnLoteEnCero(
                    $renglon->vencimiento->format('d/m/Y'),
                );
            }

            $parteDeCapital = $renglon->capital->restar($capitalTomado[$i]);
            $parteDeInteres = $renglon->interes->restar($interesTomado[$i]);

            if ($parteDeCapital->sumar($parteDeInteres)->esCero()) {
                throw PlanDeCuotasInvalidoException::porRepartoQueDejaUnLoteEnCero(
                    $renglon->vencimiento->format('d/m/Y'),
                );
            }

            $delAncla[] = CuotaProyectada::conInteres(
                numero: $renglon->numero,
                vencimiento: $renglon->vencimiento,
                capital: $parteDeCapital,
                interes: $parteDeInteres,
            );
        }

        $reparto[$ancla] = $delAncla;

        $ordenado = [];

        foreach ($capitales as $clave => $capital) {
            self::verificarQueCierra($reparto[$clave], $capital);
            $ordenado[$clave] = $reparto[$clave];
        }

        return $ordenado;
    }

    /**
     * La invariante 1, verificada acá y no solo en un test: un reparto que no
     * cierra no tiene que poder llegar ni a la pantalla.
     *
     * @param list<CuotaProyectada> $cuotas
     *
     * @throws PlanDeCuotasInvalidoException
     */
    private static function verificarQueCierra(array $cuotas, Monto $capital): void
    {
        $suma = Monto::cero();

        foreach ($cuotas as $cuota) {
            $suma = $suma->sumar($cuota->capital);
        }

        if (! $suma->igualA($capital)) {
            throw PlanDeCuotasInvalidoException::porTablaQueNoCierra($suma, $capital);
        }
    }

    /**
     * La parte de un lote en un monto del contrato, redondeada al centavo.
     */
    private static function aProrrata(Monto $monto, Monto $capital, Monto $total): Monto
    {
        return new Monto($monto->multiplicarPor($capital->valor)->dividirPor($total->valor)->redondeado());
    }

    /**
     * El lote que absorbe los centavos: el que más debe. Con empate, el
     * primero del contrato — el orden lo decide quien llama y está escrito
     * (§9.D18).
     *
     * @param array<int, Monto> $capitales
     */
    private static function ancla(array $capitales): int
    {
        $ancla = (int) array_key_first($capitales);
        $mayor = $capitales[$ancla];

        foreach ($capitales as $clave => $capital) {
            if ($capital->mayorQue($mayor)) {
                $ancla = $clave;
                $mayor = $capital;
            }
        }

        return $ancla;
    }
}
