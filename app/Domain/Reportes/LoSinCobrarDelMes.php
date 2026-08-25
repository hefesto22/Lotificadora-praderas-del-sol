<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\ValueObjects\Monto;

/**
 * Todo lo que el mes esperaba cobrar y no cobró, junto con sus totales.
 *
 * ═══ 🔴 ESTO NO ENTRA EN EL RESULTADO DEL MES ═══
 *
 * Y no es un olvido: el estado de resultados de esta hoja es de **base
 * efectivo** —se reparte plata que entró, no plata que alguien promete—. Lo
 * que sigue debiéndose es información de cobranza, y por eso va en su propia
 * sección, después de la utilidad y sin tocarla.
 *
 * Sumarlo a los ingresos repartiría entre los socios dinero que no existe. Lo
 * que sí hace este anexo es contestar la pregunta que el resultado deja en el
 * aire: «entraron L 998,867 — ¿y cuánto tenía que haber entrado?».
 */
final readonly class LoSinCobrarDelMes
{
    /**
     * @param list<CuotaSinPagar> $cuotas
     */
    private function __construct(
        public array $cuotas,
        /** Lo que esas cuotas valían. */
        public Monto $monto,
        /** Lo que se abonó a cuenta de ellas. */
        public Monto $pagado,
        /** Lo que quedó debiéndose. */
        public Monto $saldo,
        /** Cuántos expedientes distintos aparecen. */
        public int $expedientes,
        /** Cuántas de esas cuotas ya pasaron su fecha. */
        public int $vencidas,
    ) {}

    /**
     * @param list<CuotaSinPagar> $cuotas
     */
    public static function de(array $cuotas): self
    {
        $monto = Monto::cero();
        $pagado = Monto::cero();
        $saldo = Monto::cero();
        $expedientes = [];
        $vencidas = 0;

        foreach ($cuotas as $cuota) {
            $monto = $monto->sumar($cuota->monto);
            $pagado = $pagado->sumar($cuota->pagado);
            $saldo = $saldo->sumar($cuota->saldo);

            $expedientes[$cuota->expediente] = true;

            if ($cuota->yaVencio()) {
                $vencidas++;
            }
        }

        return new self(
            cuotas: $cuotas,
            monto: $monto,
            pagado: $pagado,
            saldo: $saldo,
            expedientes: count($expedientes),
            vencidas: $vencidas,
        );
    }

    public function hayAlgo(): bool
    {
        return $this->cuotas !== [];
    }

    /**
     * Cuánto de lo que vencía en el mes se llegó a cobrar, en porcentaje.
     *
     * Es el número que resume el anexo entero: «se cobró el 78 % de lo que
     * vencía». Sale del papel sin que nadie tenga que dividir a mano.
     *
     * ⚠️ Se calcula sobre las cuotas del mes, pagadas y no pagadas, así que
     * necesita el total del mes que le pasa `CierreDelMes` —acá adentro solo
     * están las que quedaron debiendo—.
     */
    public function cumplimiento(Monto $loQueVencioEnElMes): ?string
    {
        if ($loQueVencioEnElMes->esCero()) {
            return null;
        }

        $cobrado = $loQueVencioEnElMes->restar($this->saldo);

        /*
         * Regla de tres con bcmath, nunca con float (§8.3.1). Y sobre
         * `->valor` y no sobre `(string) $monto`: el casteo pasa por
         * `formateado()`, que devuelve «L. 1,234.00» —con símbolo y con comas
         * de miles— y bcmath lo leería como 1.
         */
        $porcentaje = bcdiv(
            bcmul($cobrado->valor, '100', 4),
            $loQueVencioEnElMes->valor,
            1,
        );

        return rtrim(rtrim($porcentaje, '0'), '.').' %';
    }
}
