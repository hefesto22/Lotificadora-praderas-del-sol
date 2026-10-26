<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;

/**
 * Lo que el cliente YA pagó, puesto sobre el plan nuevo (26-sep-2026).
 *
 * ═══ QUE SE PIDIO ═══
 *
 * «Desde reestructurar debería hacerse todo eso, nada de andar tocando en
 * otros lugares» —Mauricio—. El cliente de dos lotes ya había entregado
 * L 100,000.00 cuando se decidió juntarlos, y ese dinero tiene que contar en
 * el plan nuevo sin anular recibos ni volver a cobrar: la cuota 1 del plan
 * nuevo queda pagada con el recibo que ya se llevó.
 *
 * ═══ COMO SE REPARTE: COMO UN COBRO, PERO SOBRE EL PLAN NUEVO ═══
 *
 * En el orden en que se cobra: mes por mes, y dentro de cada mes lote por lote
 * en el orden del contrato (id del compromiso, escrito — §9.D18). Adentro de
 * cada cuota, el interés antes que el capital, igual que
 * `RegistroDePagos::repartir()`. Así el plan nuevo queda exactamente como si
 * esos cobros hubieran entrado sobre él desde el principio.
 *
 * El Service lo llama una vez por recibo, del más viejo al más nuevo, y
 * cada llamada sigue donde dejó la anterior. La vista previa lo llama una vez
 * con el total: da lo mismo mes por mes, porque llenar en orden con a y
 * después con b es llenar en orden con a + b.
 *
 * ═══ ES PURO, Y ES LA UNICA COPIA ═══
 *
 * Sin base de datos. La pantalla y el Service usan esta clase, no una cuenta
 * propia cada uno: lo que el modal dice que queda cubierto es lo que queda
 * cubierto (§10.8).
 *
 * ⚠️ No es `readonly` a propósito: lo cubierto CAMBIA con cada llamada, y eso
 * es lo que permite repartir recibo por recibo sin volver a empezar.
 */
final class CoberturaDelPlan
{
    /**
     * @param list<array{lote: int, numero: int, monto: Monto, interes: Monto, cubierto: Monto}> $casillas
     */
    private function __construct(
        /**
         * Las cuotas del plan nuevo, lote por lote, en el orden en que se llenan.
         */
        private array $casillas
    ) {}

    /**
     * Las cuotas de un plan unificado, todavía sin un centavo.
     *
     * Con los montos YA REDONDEADOS, los mismos que se escriben en `cuotas`:
     * llenar contra el número sin redondear dejaría una cuota «pagada» a la
     * que en la base le falta un centavo.
     */
    public static function de(PlanUnificado $plan): self
    {
        $lotes = array_keys($plan->porLote);
        sort($lotes);

        $casillas = [];

        foreach (array_keys($plan->contrato->cuotas) as $i) {
            foreach ($lotes as $lote) {
                $cuota = $plan->porLote[$lote][$i] ?? null;

                if (! $cuota instanceof CuotaProyectada) {
                    continue;
                }

                $casillas[] = [
                    'lote'     => $lote,
                    'numero'   => $cuota->numero,
                    'monto'    => new Monto($cuota->montoParaBase()),
                    'interes'  => new Monto($cuota->interesParaBase()),
                    'cubierto' => Monto::cero(),
                ];
            }
        }

        return new self($casillas);
    }

    /**
     * Pone `$dinero` sobre lo que falta, en orden, y dice a dónde fue cada
     * lempira.
     *
     * `sobra` es lo que no cupo porque el plan entero ya quedó pagado. El que
     * llama decide qué hacer con eso —el Service se niega—; esta clase no
     * inventa a dónde mandarlo.
     *
     * @return array{piezas: list<array{lote: int, numero: int, interes: Monto, capital: Monto}>, sobra: Monto}
     */
    public function aplicar(Monto $dinero): array
    {
        return $this->llenar($dinero, null);
    }

    /**
     * Lo mismo que `aplicar()`, pero solo sobre las cuotas de UN lote
     * (Praderas, 25-oct-2026).
     *
     * Con cada lote por su lado (`PlanUnificado::$cadaLote`) lo que un recibo
     * pagó de un lote vuelve a ese lote: no hay una cuota del contrato que
     * llenar en orden, hay un plan por lote. Pasarlo al otro sería mover plata
     * entre dos planes que el cliente firmó por separado.
     *
     * `sobra` es lo que no cupo porque el plan de ESE lote ya quedó pagado.
     *
     * @return array{piezas: list<array{lote: int, numero: int, interes: Monto, capital: Monto}>, sobra: Monto}
     */
    public function aplicarAlLote(int $lote, Monto $dinero): array
    {
        return $this->llenar($dinero, $lote);
    }

    /**
     * @return array{piezas: list<array{lote: int, numero: int, interes: Monto, capital: Monto}>, sobra: Monto}
     */
    private function llenar(Monto $dinero, ?int $soloElLote): array
    {
        $resto = $dinero;
        $piezas = [];

        foreach ($this->casillas as $i => $casilla) {
            if ($resto->esCero()) {
                break;
            }

            if ($soloElLote !== null && $casilla['lote'] !== $soloElLote) {
                continue;
            }

            $falta = $casilla['monto']->restar($casilla['cubierto']);

            if ($falta->esCero()) {
                continue;
            }

            $aLaCuota = $resto->mayorQue($falta) ? $falta : $resto;

            // El interés que esta cuota todavía no cobró: lo pagado es interés
            // hasta que el interés se acaba (`Cuota::interesPagado()`).
            $interesCubierto = $casilla['cubierto']->mayorQue($casilla['interes']) ? $casilla['interes'] : $casilla['cubierto'];
            $interesQueFalta = $casilla['interes']->restar($interesCubierto);

            $aInteres = $aLaCuota->mayorQue($interesQueFalta) ? $interesQueFalta : $aLaCuota;

            $piezas[] = [
                'lote'    => $casilla['lote'],
                'numero'  => $casilla['numero'],
                'interes' => $aInteres,
                'capital' => $aLaCuota->restar($aInteres),
            ];

            $this->casillas[$i]['cubierto'] = $casilla['cubierto']->sumar($aLaCuota);
            $resto = $resto->restar($aLaCuota);
        }

        return ['piezas' => $piezas, 'sobra' => $resto];
    }

    /**
     * Lo cubierto de un mes, sumando todos los lotes.
     */
    public function cubiertoDelMes(int $numero): Monto
    {
        $total = Monto::cero();

        foreach ($this->casillas as $casilla) {
            if ($casilla['numero'] === $numero) {
                $total = $total->sumar($casilla['cubierto']);
            }
        }

        return $total;
    }

    /**
     * Lo que vale el plan entero, con los montos como se escriben.
     */
    public function total(): Monto
    {
        $total = Monto::cero();

        foreach ($this->casillas as $casilla) {
            $total = $total->sumar($casilla['monto']);
        }

        return $total;
    }

    /**
     * Lo que vale el plan de un lote, con los montos como se escriben.
     */
    public function totalDelLote(int $lote): Monto
    {
        $total = Monto::cero();

        foreach ($this->casillas as $casilla) {
            if ($casilla['lote'] === $lote) {
                $total = $total->sumar($casilla['monto']);
            }
        }

        return $total;
    }

    public function cubierto(): Monto
    {
        $total = Monto::cero();

        foreach ($this->casillas as $casilla) {
            $total = $total->sumar($casilla['cubierto']);
        }

        return $total;
    }

    /**
     * Cuántos meses quedaron pagados enteros, desde el primero y sin saltar.
     */
    public function mesesCubiertos(): int
    {
        $enteros = [];

        foreach ($this->casillas as $casilla) {
            $enteros[$casilla['numero']] = ($enteros[$casilla['numero']] ?? true)
                && $casilla['cubierto']->igualA($casilla['monto']);
        }

        $completos = 0;

        foreach ($enteros as $entero) {
            if (! $entero) {
                break;
            }

            $completos++;
        }

        return $completos;
    }

    /**
     * La última cuota que quedó pagada entera, del lote que se pida o de
     * cualquiera. Null si ninguna.
     *
     * Es donde se asienta la mora que un recibo ya había cobrado: en una cuota
     * pagada la mora ya no se vuelve a calcular, así que esa plata no le
     * descuenta nada a un atraso futuro del plan nuevo (26-sep-2026).
     *
     * @return array{lote: int, numero: int}|null
     */
    public function ultimaCubierta(?int $lote = null): ?array
    {
        $ultima = null;

        foreach ($this->casillas as $casilla) {
            if ($lote !== null && $casilla['lote'] !== $lote) {
                continue;
            }

            if ($casilla['cubierto']->igualA($casilla['monto'])) {
                $ultima = ['lote' => $casilla['lote'], 'numero' => $casilla['numero']];
            }
        }

        return $ultima;
    }

    /**
     * El primer mes al que todavía le falta algo, y cuánto, sumando todos
     * los lotes. Null si el plan quedó pagado entero.
     *
     * Es lo que contesta «¿quedó en números cerrados?»: si el mes que sigue
     * no tiene nada cubierto, cerró; si tiene algo, esto dice cuánto falta.
     *
     * @return array{numero: int, cubierto: Monto, falta: Monto}|null
     */
    public function primerMesQueDebe(): ?array
    {
        $numero = null;

        foreach ($this->casillas as $casilla) {
            if ($casilla['cubierto']->menorQue($casilla['monto'])) {
                $numero = $casilla['numero'];

                break;
            }
        }

        if ($numero === null) {
            return null;
        }

        $cubierto = Monto::cero();
        $falta = Monto::cero();

        foreach ($this->casillas as $casilla) {
            if ($casilla['numero'] === $numero) {
                $cubierto = $cubierto->sumar($casilla['cubierto']);
                $falta = $falta->sumar($casilla['monto']->restar($casilla['cubierto']));
            }
        }

        return ['numero' => $numero, 'cubierto' => $cubierto, 'falta' => $falta];
    }
}
