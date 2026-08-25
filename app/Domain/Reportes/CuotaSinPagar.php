<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;

/**
 * Una cuota que vencía en el mes y quedó debiendo.
 *
 * ═══ QUE ES «SIN PAGAR» ACA ═══
 *
 * Mauricio, 25-ago-2026: «sería bueno que también diga lo pendiente de
 * personas que no pagaron cuota que les tocaba ese mes».
 *
 * «Les tocaba ese mes» es la fecha de vencimiento, no la deuda entera del
 * cliente: acá no aparecen los atrasos de meses anteriores —esos ya salieron
 * en el papel de su mes— sino lo que este mes esperaba cobrar y no cobró. Un
 * mes cierra y empieza otro, igual que el resultado.
 *
 * ⚠️ **La cuota a medias también entra.** Quien pagó L 500 de L 2,000 no pagó
 * su cuota, y el renglón lo dice con las tres columnas: monto, pagado y saldo.
 * Dejarla afuera por «algo pagó» escondería tres cuartas partes de la deuda
 * del mes.
 */
final readonly class CuotaSinPagar
{
    public function __construct(
        public string $expediente,
        public string $cliente,
        public string $lote,
        public int $numero,
        public CarbonImmutable $vence,
        public Monto $monto,
        public Monto $pagado,
        public Monto $saldo,
        /** Días corridos desde el vencimiento. Cero si todavía no vence. */
        public int $diasDeAtraso,
    ) {}

    /**
     * ¿Ya se le pasó la fecha?
     *
     * ═══ POR QUE LA PREGUNTA EXISTE ═══
     *
     * Porque esta hoja se saca también del mes corriente, y ahí hay dos cosas
     * distintas en la misma lista: la cuota del 5 que nadie pagó —eso es
     * atraso— y la del 30, que todavía no le toca a nadie. Marcarlas iguales
     * pondría en mora a un cliente que está al día, y ese es el tipo de error
     * que llega al teléfono del cliente antes que a nadie.
     */
    public function yaVencio(): bool
    {
        return $this->diasDeAtraso > 0;
    }

    /**
     * Pagó algo pero no todo: el abono parcial.
     */
    public function esPagoAMedias(): bool
    {
        return ! $this->pagado->esCero();
    }
}
