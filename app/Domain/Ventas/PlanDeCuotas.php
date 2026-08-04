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

        self::verificarPlazo($plazoMeses);
        self::verificarDiaDePago($diaPago);

        $base = new Monto($saldo->dividirPor($plazoMeses)->redondeado());
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
            self::armar($base, $ultima, $plazoMeses, $diaPago, $fechaContrato->addMonthsNoOverflow(1)),
            $saldo,
        );
    }

    /**
     * El plan que queda despues de un abono extraordinario a capital (R3).
     *
     * La contratante fue explicita: **se acorta el plazo, no se baja la
     * cuota**. El cliente sigue pagando lo mismo cada mes y termina antes.
     * Por eso aca la cuota es un dato de entrada —la pactada, que no
     * cambia nunca— y lo que se calcula es cuantas faltan.
     *
     * La ultima vuelve a absorber el residuo, y por construccion queda
     * menor o igual que la cuota pactada.
     *
     * @param CarbonImmutable $mesDelPrimerVencimiento cualquier dia del mes
     *                                                 en que cae la proxima cuota
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function porCuotaFija(
        Monto $saldo,
        Monto $cuota,
        int $diaPago,
        CarbonImmutable $mesDelPrimerVencimiento,
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
            self::armar($cuota, $ultima, $cantidad, $diaPago, $mesDelPrimerVencimiento),
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
     * @return list<CuotaProyectada>
     */
    private static function armar(
        Monto $base,
        Monto $ultima,
        int $cantidad,
        int $diaPago,
        CarbonImmutable $mesDelPrimerVencimiento,
    ): array {
        $mesCero = $mesDelPrimerVencimiento->startOfMonth();
        $cuotas = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $cuotas[] = new CuotaProyectada(
                numero: $i + 1,
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
