<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;

/**
 * Lo que el cliente paga por mes cuando cada lote tiene su propio plazo.
 *
 * ═══ EL PROBLEMA QUE RESUELVE ═══
 *
 * Tres lotes en un contrato: uno a 12 meses, otro a 24 y otro a 48. La
 * pregunta que hace el cliente parado en el mostrador es una sola —«¿cuánto
 * pago por mes?»— y no tiene una sola respuesta: paga los tres juntos hasta
 * el mes 12, dos hasta el 24 y uno hasta el 48. La cuota BAJA dos veces.
 *
 * Contestar con el primer número a secas sería exacto por doce meses y falso
 * por treinta y seis. Por eso lo que se muestra son TRAMOS.
 *
 * ═══ NO GUARDA NADA ═══
 *
 * Es una vista sobre los planes de cada lote, que son los que se congelan en
 * `cuotas`. Si algún día los dos dijeran cosas distintas, manda `cuotas`:
 * este objeto se recalcula, aquellas filas son el contrato.
 */
final readonly class PlanDelContrato
{
    /**
     * @param list<array{etiqueta: string, plan: PlanDeCuotas}> $renglones
     */
    public function __construct(public array $renglones) {}

    /**
     * El plazo más largo: hasta cuándo dura el contrato.
     */
    public function plazoMaximo(): int
    {
        $maximo = 0;

        foreach ($this->renglones as $renglon) {
            $maximo = max($maximo, $renglon['plan']->count());
        }

        return $maximo;
    }

    /**
     * Lo que se paga el mes N: la suma de las cuotas que siguen vivas.
     *
     * Un lote que ya terminó de pagarse no aporta nada, y por eso el número
     * baja solo. Los meses se cuentan desde 1.
     */
    public function cuotaDelMes(int $mes): Monto
    {
        $total = Monto::cero();

        foreach ($this->renglones as $renglon) {
            $cuota = $renglon['plan']->cuotas[$mes - 1] ?? null;

            if ($cuota instanceof CuotaProyectada) {
                $total = $total->sumar($cuota->monto);
            }
        }

        return $total;
    }

    /**
     * Lo que paga el primer mes. Es el número más alto del contrato.
     *
     * Null cuando no hay nada que financiar: todos los lotes de contado.
     */
    public function primeraCuota(): ?Monto
    {
        if ($this->plazoMaximo() === 0) {
            return null;
        }

        return $this->cuotaDelMes(1);
    }

    /**
     * Los tramos: «meses 1 a 12, tanto; 13 a 24, tanto».
     *
     * Se agrupan los meses consecutivos que se pagan igual. Con todos los
     * lotes al mismo plazo da UN tramo, que es exactamente lo que pasaba
     * antes de que existieran los plazos por lote.
     *
     * @return list<array{desde: int, hasta: int, monto: Monto}>
     */
    public function tramos(): array
    {
        $plazo = $this->plazoMaximo();

        if ($plazo === 0) {
            return [];
        }

        $tramos = [];
        $desde = 1;
        $monto = $this->cuotaDelMes(1);

        for ($mes = 2; $mes <= $plazo; $mes++) {
            $delMes = $this->cuotaDelMes($mes);

            if ($delMes->igualA($monto)) {
                continue;
            }

            $tramos[] = ['desde' => $desde, 'hasta' => $mes - 1, 'monto' => $monto];
            $desde = $mes;
            $monto = $delMes;
        }

        $tramos[] = ['desde' => $desde, 'hasta' => $plazo, 'monto' => $monto];

        return $tramos;
    }

    /**
     * La suma de todo lo que se va a financiar.
     */
    public function saldoFinanciado(): Monto
    {
        $total = Monto::cero();

        foreach ($this->renglones as $renglon) {
            $total = $total->sumar($renglon['plan']->saldoFinanciado);
        }

        return $total;
    }

    /**
     * La suma de todas las cuotas de todos los lotes.
     *
     * Tiene que dar exactamente `saldoFinanciado()`: cada plan cierra al
     * céntimo contra el suyo, así que la suma cierra contra la suma.
     */
    public function total(): Monto
    {
        $total = Monto::cero();

        foreach ($this->renglones as $renglon) {
            $total = $total->sumar($renglon['plan']->total());
        }

        return $total;
    }

    /**
     * ¿Todos los lotes se pagaron al firmar?
     */
    public function esDeContado(): bool
    {
        return $this->plazoMaximo() === 0;
    }

    /**
     * ¿Los lotes van a plazos distintos?
     *
     * Con un solo tramo el contrato se lee como siempre: una cuota fija de
     * principio a fin. Con más de uno hay que mostrar la escalera.
     */
    public function tienePlazosMezclados(): bool
    {
        $plazos = [];

        foreach ($this->renglones as $renglon) {
            $plazos[$renglon['plan']->count()] = true;
        }

        return count($plazos) > 1;
    }
}
