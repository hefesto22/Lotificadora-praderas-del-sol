<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;
use Countable;

/**
 * El motor de cuotas. Aritmetica pura, sin base de datos y sin Laravel.
 *
 * ═══ DOS CAMINOS, Y CUAL CORRE CADA LOTIFICADORA ═══
 *
 * Desde el 8-ago-2026 el interes es **configurable por plan de pago** (§8.5):
 * cada lotificadora decide si su plazo de 48 meses cobra 0 % o 12 %, y ese
 * numero se congela en el compromiso al firmar. De ahi salen dos caminos:
 *
 *  - **Tasa 0 — el de siempre.** `cuota = (valor − prima) ÷ plazo`, con el
 *    residuo a la ultima (§8.2). Es el que corre Praderas del Sol por R1, y
 *    es **exactamente el mismo codigo que corria antes del 8-ago**: no se
 *    toco una linea de su aritmetica. El golden test del §9.C9 lo congela.
 *  - **Tasa > 0 — tabla francesa.** `TablaDeAmortizacion` reparte el capital
 *    y cada cuota sale partida en capital e interes.
 *
 * ═══ 🔴 POR QUE SON DOS CAMINOS Y NO UNO ═══
 *
 * `docs/que-le-falta.md` §1.2 argumento que la formula francesa con `i = 0`
 * «degenera exactamente en P ÷ n» y que por eso habria **un solo camino de
 * codigo**. Matematicamente es cierto —es el limite— pero **la cuenta no se
 * puede hacer**: con `i = 0` la formula es `P × 0 ÷ (1 − 1)`, o sea `0 ÷ 0`.
 * En float daria `NAN`; en bcmath, division por cero.
 *
 * Asi que el `if` de la tasa cero no es una optimizacion prescindible: es
 * obligatorio. Y tiene una consecuencia que conviene por otro motivo — a doce
 * dias del arranque, **el cliente que va a operar el 20-ago no cambia de
 * camino de codigo**.
 *
 * ═══ EL RESIDUO VA A LA ULTIMA CUOTA ═══
 *
 * La division casi nunca cierra exacta. El §8.2 lo resuelve mandando el
 * residuo a la ultima cuota, de modo que **la suma del CAPITAL es exactamente
 * igual al saldo, al centimo**. Es la unica forma de que un estado de cuenta
 * termine en cero el ultimo mes en vez de dejar un arrastre de centavos que
 * nadie sabe explicar.
 *
 * ⚠️ Con interes, lo que cierra contra el saldo es el CAPITAL y no la suma de
 * las cuotas: esa da capital + intereses. Ver `cierraExacto()`.
 *
 * El golden test del §9.C9 congela el caso de referencia, sin interes:
 *
 *     250 varas² x L 1,400.00 = L 350,000.00, prima L 100,000.00
 *     → saldo L 250,000.00 en 72 cuotas
 *     → 71 de L 3,472.22 + ultima de L 3,472.38 = L 250,000.00 exacto
 *
 * ═══ LOS TRES CONSTRUCTORES, Y CUAL USA CADA UNO ═══
 *
 * - `nuevo()`      — la venta que se firma. Valor, prima, plazo y tasa.
 * - `porCuotaFija()` — R21, camino «misma cuota, menos meses». La cuota es
 *   un dato de entrada y lo que se calcula es cuantas faltan.
 * - `porPlazoFijo()` — R21, camino «mismos meses, cuota mas baja». Los meses
 *   son el dato de entrada y lo que se calcula es la cuota.
 *
 * La tasa entra como ULTIMO parametro y con default en los tres: todas las
 * llamadas que existian el 7-ago siguen compilando y siguen dando el mismo
 * numero.
 *
 * ═══ POR QUE ES UN VALUE OBJECT Y NO UN SERVICE ═══
 *
 * No escribe nada, no depende de nada y se puede construir mil veces por
 * request. Eso le permite al formulario de venta mostrar el plan ANTES de
 * guardar (§10.8) con el mismo codigo que despues lo persiste. Un solo
 * calculo: el que se ve en pantalla es el que queda en la base.
 *
 * Quien lo persista sera un Service, en transaccion, y ahi el plan se
 * congela (§9.D6: snapshot inmutable).
 */
final readonly class PlanDeCuotas implements Countable
{
    /**
     * Tope de cordura, no una regla del negocio.
     *
     * El contrato no fija un plazo maximo; 50 anios de cuotas es un error
     * de digitacion, no una venta. Si algun dia el negocio necesita un
     * plazo mayor, sube este numero y su test.
     */
    public const int PLAZO_MAXIMO_MESES = 600;

    /**
     * @param list<CuotaProyectada> $cuotas
     */
    private function __construct(
        public array $cuotas,
        public Monto $saldoFinanciado,
        public TasaDeInteres $tasa,
    ) {}

    /**
     * El plan de una venta nueva.
     *
     * La primera cuota vence el `diaPago` del mes SIGUIENTE al contrato.
     * Que caiga a pocos dias de la firma es normal y buscado: el dia de
     * pago es fijo por venta, no un aniversario movil.
     *
     * @param TasaDeInteres|null $tasa null es lo mismo que 0 %: el caso de
     *                                 Praderas del Sol (R1)
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function nuevo(
        Monto $valorTotal,
        Monto $prima,
        int $plazoMeses,
        int $diaPago,
        CarbonImmutable $fechaContrato,
        ?TasaDeInteres $tasa = null,
    ): self {
        if ($prima->mayorQue($valorTotal)) {
            throw PlanDeCuotasInvalidoException::porPrimaMayorAlValor($prima, $valorTotal);
        }

        /*
         * 🔴 El lote sin precio se dice con todas las letras (24-ago-2026).
         *
         * Sin esto cae en el mensaje de contado de mas abajo —que es cierto
         * y no sirve—. El porque completo esta en
         * `PlanDeCuotasInvalidoException::porLoteSinPrecio()`.
         */
        if ($valorTotal->esCero()) {
            throw PlanDeCuotasInvalidoException::porLoteSinPrecio();
        }

        $saldo = $valorTotal->restar($prima);

        // Venta de contado: no hay nada que financiar. Se devuelve un plan
        // vacio en vez de reventar, porque "sin cuotas" es un resultado
        // legitimo y el formulario tiene que poder mostrarlo.
        if ($saldo->esCero()) {
            if ($plazoMeses !== 0) {
                throw PlanDeCuotasInvalidoException::porContadoConPlazo($plazoMeses);
            }

            return new self([], $saldo, $tasa ?? TasaDeInteres::cero());
        }

        return self::porPlazoFijo(
            $saldo,
            $plazoMeses,
            $diaPago,
            $fechaContrato->addMonthsNoOverflow(1),
            1,
            $tasa,
        );
    }

    /**
     * El plan que queda despues de un abono, acortando el plazo (R21).
     *
     * Es el default historico —lo que la contratante contesto en el
     * cuestionario (R3)— y desde el 6-ago-2026 uno de dos caminos: el
     * cliente puede pedir el otro, `porPlazoFijo()`.
     *
     * La cuota es un dato de entrada —la pactada, que en este camino no
     * cambia nunca— y lo que se calcula es cuantas faltan. La ultima
     * vuelve a absorber el residuo, y por construccion queda menor o igual
     * que la cuota pactada.
     *
     * ⚠️ Con interes, `$saldo` es CAPITAL pendiente, no la suma de las cuotas
     * que quedan: reamortizar sobre un numero que ya incluia intereses seria
     * cobrar interes sobre el interes del plan viejo.
     *
     * @param CarbonImmutable $mesDelPrimerVencimiento cualquier dia del mes
     *                                                 en que cae la proxima cuota
     * @param int $primerNumero con que numero arranca el plan; ver `armar()`
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function porCuotaFija(
        Monto $saldo,
        Monto $cuota,
        int $diaPago,
        CarbonImmutable $mesDelPrimerVencimiento,
        int $primerNumero = 1,
        ?TasaDeInteres $tasa = null,
    ): self {
        $tasa ??= TasaDeInteres::cero();

        if ($cuota->esCero()) {
            throw PlanDeCuotasInvalidoException::porCuotaEnCero();
        }

        self::verificarDiaDePago($diaPago);

        // Saldo en cero: el abono termino de pagar la venta. Plan vacio, y
        // ninguna cuota de L 0.00 colgando (R3).
        if ($saldo->esCero()) {
            return new self([], $saldo, $tasa);
        }

        if (! $tasa->esCero()) {
            return self::conTabla(
                TablaDeAmortizacion::porCuota($saldo, $tasa, $cuota, self::PLAZO_MAXIMO_MESES),
                $saldo,
                $tasa,
                $diaPago,
                $mesDelPrimerVencimiento,
                $primerNumero,
            );
        }

        $cantidad = self::cuotasQueCaben($saldo, $cuota);
        $acumuladoPrevio = $cuota->multiplicarPor($cantidad - 1);
        $ultima = $saldo->restar($acumuladoPrevio);

        return new self(
            self::armar($cuota, $ultima, $cantidad, $diaPago, $mesDelPrimerVencimiento, $primerNumero),
            $saldo,
            $tasa,
        );
    }

    /**
     * El plan que queda despues de un abono, bajando la cuota (R21).
     *
     * El otro camino, agregado en la reunion del 6-ago-2026: **mismos
     * meses, cuota mas baja**. El saldo nuevo se reparte entre los meses
     * que quedaban, asi que el cliente termina el mismo mes que tenia
     * pactado pero paga menos cada uno.
     *
     * Tambien es el motor de `nuevo()`: una venta que se firma es esto
     * mismo, con el saldo ya descontada la prima y el primer vencimiento
     * en el mes siguiente al contrato.
     *
     * @param CarbonImmutable $mesDelPrimerVencimiento cualquier dia del mes
     *                                                 en que cae la proxima cuota
     * @param int $primerNumero con que numero arranca el plan; ver `armar()`
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function porPlazoFijo(
        Monto $saldo,
        int $plazoMeses,
        int $diaPago,
        CarbonImmutable $mesDelPrimerVencimiento,
        int $primerNumero = 1,
        ?TasaDeInteres $tasa = null,
    ): self {
        $tasa ??= TasaDeInteres::cero();

        // Saldo en cero: el abono cancelo lo que quedaba. Plan vacio, sin
        // cuotas de L 0.00 colgando — el mismo borde que R3 fija arriba.
        if ($saldo->esCero()) {
            return new self([], $saldo, $tasa);
        }

        self::verificarPlazo($plazoMeses);
        self::verificarDiaDePago($diaPago);

        if (! $tasa->esCero()) {
            return self::conTabla(
                TablaDeAmortizacion::porPlazo($saldo, $tasa, $plazoMeses),
                $saldo,
                $tasa,
                $diaPago,
                $mesDelPrimerVencimiento,
                $primerNumero,
            );
        }

        $base = new Monto($saldo->dividirPor($plazoMeses)->redondeado());

        /*
         * Una cuota base de L 0.00 sale de dividir centavos entre muchos
         * meses. Antes esto pasaba de largo y lo frenaba el CHECK
         * `cuotas_monto_positivo_chk` de la base, o sea un error de Postgres
         * en la cara del usuario en vez de una frase que diga que hacer.
         */
        if ($base->esCero()) {
            throw PlanDeCuotasInvalidoException::porSaldoDemasiadoChicoParaElPlazo($saldo, $plazoMeses);
        }

        $acumuladoPrevio = $base->multiplicarPor($plazoMeses - 1);

        /*
         * Con un saldo chico y un plazo largo, el redondeo hacia arriba de
         * cada cuota puede acumular mas que el saldo entero y dejar la
         * ultima en cero o negativa. Pasa recien cuando la cuota cae a
         * centavos —financiar L 17 a 60 meses—, pero pasa. Se para aca en
         * vez de emitir un plan que no cierra.
         */
        if (! $saldo->mayorQue($acumuladoPrevio)) {
            throw PlanDeCuotasInvalidoException::porSaldoDemasiadoChicoParaElPlazo($saldo, $plazoMeses);
        }

        $ultima = $saldo->restar($acumuladoPrevio);

        return new self(
            self::armar($base, $ultima, $plazoMeses, $diaPago, $mesDelPrimerVencimiento, $primerNumero),
            $saldo,
            $tasa,
        );
    }

    /**
     * La cuota que el cliente paga todos los meses.
     *
     * Es la de la primera; la ultima puede diferir por el residuo. En una
     * venta de contado no hay ninguna.
     */
    public function cuotaMensual(): ?Monto
    {
        return $this->cuotas[0]->monto ?? null;
    }

    public function ultima(): ?CuotaProyectada
    {
        return $this->cuotas === [] ? null : $this->cuotas[count($this->cuotas) - 1];
    }

    public function llevaInteres(): bool
    {
        return ! $this->tasa->esCero();
    }

    /**
     * La suma de todas las cuotas: lo que el cliente termina pagando.
     *
     * ⚠️ Sin interes esto da el saldo financiado; con interes da el saldo MAS
     * los intereses. Para «¿el plan cierra?» va `cierraExacto()`, que compara
     * capital contra capital.
     */
    public function total(): Monto
    {
        $total = Monto::cero();

        foreach ($this->cuotas as $cuota) {
            $total = $total->sumar($cuota->monto);
        }

        return $total;
    }

    /**
     * La suma del capital. Tiene que dar exactamente `saldoFinanciado`.
     */
    public function totalCapital(): Monto
    {
        $total = Monto::cero();

        foreach ($this->cuotas as $cuota) {
            $total = $total->sumar($cuota->capital);
        }

        return $total;
    }

    /**
     * Lo que el interes le agrega al precio del lote.
     *
     * Es el numero que hay que imprimir en el contrato con todas las letras.
     * A 48 meses y 12 % anual son L 184,816.92 sobre un lote de L 700,000: un
     * 26 % mas. Esconderlo dentro de la cuota es exactamente como se pierde
     * un juicio.
     */
    public function totalInteres(): Monto
    {
        $total = Monto::cero();

        foreach ($this->cuotas as $cuota) {
            $total = $total->sumar($cuota->interes);
        }

        return $total;
    }

    /**
     * ¿El plan cierra al centimo contra el saldo que dice financiar?
     *
     * Se expone a proposito en vez de dejarlo solo en los tests: el
     * Service lo llama antes de escribir, porque un plan que no cierra no
     * debe llegar nunca a la base de datos.
     *
     * ⚠️ Compara **capital**, no la suma de las cuotas. Sin interes son el
     * mismo numero y esta funcion se comporta igual que siempre; con interes,
     * comparar la suma de las cuotas daria falso en todos los planes validos.
     */
    public function cierraExacto(): bool
    {
        return $this->totalCapital()->igualA($this->saldoFinanciado);
    }

    public function count(): int
    {
        return count($this->cuotas);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * ═══ POR QUE EL PLAN NO SIEMPRE EMPIEZA EN 1 ═══
     *
     * Una venta nueva numera desde 1 y ese es el caso normal. Una
     * reprogramacion (R21) no: las cuotas ya pagadas —y la que quedo a
     * medias— se respetan tal como estan, y el plan nuevo empieza en la
     * siguiente. Si renumerara desde 1 chocaria contra el indice unico
     * `cuotas_numero_por_lote_uidx` y, peor, el recibo viejo quedaria
     * apuntando a una cuota 5 que ahora significa otra cosa.
     *
     * @return list<CuotaProyectada>
     */
    private static function armar(
        Monto $base,
        Monto $ultima,
        int $cantidad,
        int $diaPago,
        CarbonImmutable $mesDelPrimerVencimiento,
        int $primerNumero,
    ): array {
        $mesCero = $mesDelPrimerVencimiento->startOfMonth();
        $cuotas = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $cuotas[] = CuotaProyectada::sinInteres(
                numero: $primerNumero + $i,
                vencimiento: self::vencimiento($mesCero, $i, $diaPago),
                monto: $i === $cantidad - 1 ? $ultima : $base,
            );
        }

        return $cuotas;
    }

    /**
     * El mismo calendario que `armar()`, sobre una tabla francesa ya armada.
     *
     * Los vencimientos se calculan igual en los dos caminos: la tabla dice
     * CUANTO y esto dice CUANDO. Separarlos es lo que hace que un plan con
     * interes y uno sin interes venzan el mismo dia del mes.
     */
    private static function conTabla(
        TablaDeAmortizacion $tabla,
        Monto $saldo,
        TasaDeInteres $tasa,
        int $diaPago,
        CarbonImmutable $mesDelPrimerVencimiento,
        int $primerNumero,
    ): self {
        $mesCero = $mesDelPrimerVencimiento->startOfMonth();
        $cuotas = [];

        foreach ($tabla->renglones as $i => $renglon) {
            $cuotas[] = CuotaProyectada::conInteres(
                numero: $primerNumero + $i,
                vencimiento: self::vencimiento($mesCero, $i, $diaPago),
                capital: $renglon['capital'],
                interes: $renglon['interes'],
            );
        }

        return new self($cuotas, $saldo, $tasa);
    }

    /**
     * El vencimiento del mes `$offset`, con el dia de pago acomodado.
     *
     * Se cuenta desde el dia 1 del mes a proposito: sumarle meses a un 31
     * arrastra el desbordamiento de febrero por todo el plan. Desde el 1
     * no hay desbordamiento posible, y el dia se fija al final contra los
     * dias reales de ESE mes. Un dia de pago 31 cae el 28 en febrero y
     * vuelve al 31 en marzo, que es como lo cobraria una persona.
     */
    private static function vencimiento(CarbonImmutable $mesCero, int $offset, int $diaPago): CarbonImmutable
    {
        $mes = $mesCero->addMonthsNoOverflow($offset);

        return $mes->day(min($diaPago, $mes->daysInMonth));
    }

    /**
     * Cuantas cuotas de `$cuota` hacen falta para cubrir `$saldo`.
     *
     * Es un techo, no una division: si sobra aunque sea un centavo, hace
     * falta una cuota mas. `bcdiv` con escala 0 trunca, asi que el resto
     * se detecta comparando el producto contra el saldo.
     */
    private static function cuotasQueCaben(Monto $saldo, Monto $cuota): int
    {
        $enteras = (int) bcdiv($saldo->valor, $cuota->valor, 0);
        $cubierto = $cuota->multiplicarPor($enteras);

        return $saldo->mayorQue($cubierto) ? $enteras + 1 : $enteras;
    }

    private static function verificarPlazo(int $plazoMeses): void
    {
        if ($plazoMeses < 1 || $plazoMeses > self::PLAZO_MAXIMO_MESES) {
            throw PlanDeCuotasInvalidoException::porPlazoInvalido($plazoMeses);
        }
    }

    private static function verificarDiaDePago(int $diaPago): void
    {
        if ($diaPago < 1 || $diaPago > 31) {
            throw PlanDeCuotasInvalidoException::porDiaDePagoInvalido($diaPago);
        }
    }
}
