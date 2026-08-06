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
 * ═══ LA REGLA QUE LO DEFINE TODO ═══
 *
 * R1 del `docs/dominio.md`, contestada por la contratante el 3-ago-2026:
 * **el saldo financiado NO genera interes.** El precio del lote ya incluye
 * todo, asi que la cuota es una division y nada mas:
 *
 *     cuota = (valor de la venta − prima) ÷ plazo en meses
 *
 * No hay tabla francesa, no hay capital e interes separados, no hay
 * columna de interes en ninguna parte. Y por R2 tampoco hay mora: un
 * cliente atrasado debe exactamente lo mismo que debia el dia del
 * vencimiento.
 *
 * ═══ EL RESIDUO VA A LA ULTIMA CUOTA ═══
 *
 * La division casi nunca cierra exacta. El §8.2 lo resuelve mandando el
 * residuo a la ultima cuota, de modo que **la suma de las cuotas es
 * exactamente igual al saldo, al centimo**. Es la unica forma de que un
 * estado de cuenta termine en cero el ultimo mes en vez de dejar un
 * arrastre de centavos que nadie sabe explicar.
 *
 * El golden test del §9.C9 congela el caso de referencia:
 *
 *     250 varas² x L 1,400.00 = L 350,000.00, prima L 100,000.00
 *     → saldo L 250,000.00 en 72 cuotas
 *     → 71 de L 3,472.22 + ultima de L 3,472.38 = L 250,000.00 exacto
 *
 * ═══ LOS TRES CONSTRUCTORES, Y CUAL USA CADA UNO ═══
 *
 * - `nuevo()`      — la venta que se firma. Valor, prima y plazo.
 * - `porCuotaFija()` — R21, camino «misma cuota, menos meses». La cuota es
 *   un dato de entrada y lo que se calcula es cuantas faltan.
 * - `porPlazoFijo()` — R21, camino «mismos meses, cuota mas baja». Los meses
 *   son el dato de entrada y lo que se calcula es la cuota.
 *
 * Los tres terminan en el mismo `armar()`, asi que el residuo se reparte
 * igual en los tres y el golden test los cubre a todos.
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
    ) {}

    /**
     * El plan de una venta nueva (R1).
     *
     * La primera cuota vence el `diaPago` del mes SIGUIENTE al contrato.
     * Que caiga a pocos dias de la firma es normal y buscado: el dia de
     * pago es fijo por venta, no un aniversario movil.
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function nuevo(
        Monto $valorTotal,
        Monto $prima,
        int $plazoMeses,
        int $diaPago,
        CarbonImmutable $fechaContrato,
    ): self {
        if ($prima->mayorQue($valorTotal)) {
            throw PlanDeCuotasInvalidoException::porPrimaMayorAlValor($prima, $valorTotal);
        }

        $saldo = $valorTotal->restar($prima);

        // Venta de contado: no hay nada que financiar. Se devuelve un plan
        // vacio en vez de reventar, porque "sin cuotas" es un resultado
        // legitimo y el formulario tiene que poder mostrarlo.
        if ($saldo->esCero()) {
            if ($plazoMeses !== 0) {
                throw PlanDeCuotasInvalidoException::porContadoConPlazo($plazoMeses);
            }

            return new self([], $saldo);
        }

        return self::porPlazoFijo($saldo, $plazoMeses, $diaPago, $fechaContrato->addMonthsNoOverflow(1));
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
    ): self {
        if ($cuota->esCero()) {
            throw PlanDeCuotasInvalidoException::porCuotaEnCero();
        }

        self::verificarDiaDePago($diaPago);

        // Saldo en cero: el abono termino de pagar la venta. Plan vacio, y
        // ninguna cuota de L 0.00 colgando (R3).
        if ($saldo->esCero()) {
            return new self([], $saldo);
        }

        $cantidad = self::cuotasQueCaben($saldo, $cuota);
        $acumuladoPrevio = $cuota->multiplicarPor($cantidad - 1);
        $ultima = $saldo->restar($acumuladoPrevio);

        return new self(
            self::armar($cuota, $ultima, $cantidad, $diaPago, $mesDelPrimerVencimiento, $primerNumero),
            $saldo,
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
    ): self {
        // Saldo en cero: el abono cancelo lo que quedaba. Plan vacio, sin
        // cuotas de L 0.00 colgando — el mismo borde que R3 fija arriba.
        if ($saldo->esCero()) {
            return new self([], $saldo);
        }

        self::verificarPlazo($plazoMeses);
        self::verificarDiaDePago($diaPago);

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

    /**
     * La suma de todas las cuotas.
     *
     * Tiene que dar exactamente `saldoFinanciado`. El golden test del
     * §9.C9 vive de esta igualdad, y el Service que persista el plan la
     * verifica dentro de la transaccion (§8.3.4).
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
     * ¿El plan cierra al centimo contra el saldo que dice financiar?
     *
     * Se expone a proposito en vez de dejarlo solo en los tests: el
     * Service lo llama antes de escribir, porque un plan que no cierra no
     * debe llegar nunca a la base de datos.
     */
    public function cierraExacto(): bool
    {
        return $this->total()->igualA($this->saldoFinanciado);
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
            $cuotas[] = new CuotaProyectada(
                numero: $primerNumero + $i,
                vencimiento: self::vencimiento($mesCero, $i, $diaPago),
                monto: $i === $cantidad - 1 ? $ultima : $base,
            );
        }

        return $cuotas;
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
