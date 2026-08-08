<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\Enums\ModalidadDeMora;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CondicionesDeMora;
use App\Domain\Ventas\TasaDeInteres;
use Carbon\CarbonImmutable;

/**
 * Cuanta mora debe UNA cuota atrasada, al dia de hoy. No escribe nada.
 *
 * ═══ LA MORA NO SE GUARDA COMO CUOTA, Y NO PUEDE ═══
 *
 * Es un derivado del tiempo: cambia sola todas las noches. Guardarla como una
 * fila mas de `cuotas` obligaria a una tarea nocturna que la recalcule, y esa
 * tarea falla justo el dia que el cliente llega a pagar (§9.D5, el mismo
 * argumento por el que `vencida` tampoco se guarda).
 *
 * Se calcula al vuelo al momento de cobrar y **se congela en el recibo**. Eso
 * ademas da la traza que hace falta cuando alguien reclama: el papel dice
 * cuanta mora se cobro ese dia y por cuantos dias.
 *
 * ═══ LA MORA NO GENERA MORA ═══
 *
 * Anatocismo —interes sobre interes—. Acá es estructural y no una regla que
 * alguien tenga que recordar: la base del calculo es el SALDO DE LA CUOTA
 * (capital + interes pendientes), y la mora nunca entra a esa cifra porque
 * nunca se escribe en `cuotas.monto`. Para que hubiera anatocismo habria que
 * cambiar el esquema, no olvidarse de un `if`.
 *
 * ═══ LOS MESES SE CUENTAN HACIA ARRIBA ═══
 *
 * En las dos modalidades «por mes», una fraccion cuenta como mes entero: es
 * lo que dice todo contrato del rubro y lo que espera quien atiende. Un mes
 * son 30 dias y no «el mismo dia del mes siguiente», porque el segundo
 * criterio hace que febrero cobre distinto que marzo por el mismo atraso.
 *
 * ⚠️ Esa es exactamente la razon por la que `TasaAnual` es la modalidad
 * recomendada: acá, atrasarse un dia cuesta lo mismo que atrasarse
 * veintinueve.
 */
final readonly class CalculoDeMora
{
    /** Dias que cuentan como un mes en las modalidades «por mes». */
    private const int DIAS_POR_MES = 30;

    private function __construct(
        public Monto $monto,
        public int $diasDeAtraso,
        public int $diasCobrados,
        public int $mesesCobrados,
        public ModalidadDeMora $modalidad,
        public Monto $baseDelCalculo,
    ) {}

    public static function ninguna(): self
    {
        return new self(Monto::cero(), 0, 0, 0, ModalidadDeMora::Ninguna, Monto::cero());
    }

    /**
     * @param Monto $saldoVencido lo que la cuota todavia debe, SIN mora
     * @param CarbonImmutable $alDia la fecha del cobro, no necesariamente hoy
     */
    public static function sobre(
        Monto $saldoVencido,
        CarbonImmutable $vencimiento,
        CarbonImmutable $alDia,
        CondicionesDeMora $condiciones,
    ): self {
        if (! $condiciones->cobra() || $saldoVencido->esCero()) {
            return self::ninguna();
        }

        $atraso = $vencimiento->startOfDay()->diffInDays($alDia->startOfDay(), absolute: false);
        $dias = (int) max(0, $atraso);

        // La mora corre DESDE que termina la gracia, no desde el vencimiento.
        // Ver el docblock de `CondicionesDeMora`: es una decision, no un bug.
        $cobrados = max(0, $dias - $condiciones->diasDeGracia);

        if ($cobrados === 0) {
            return new self(Monto::cero(), $dias, 0, 0, $condiciones->modalidad, $saldoVencido);
        }

        $meses = (int) ceil($cobrados / self::DIAS_POR_MES);

        $monto = match ($condiciones->modalidad) {
            // Una sola vez, dure lo que dure el atraso.
            ModalidadDeMora::FijaPorCuota => $condiciones->monto,

            ModalidadDeMora::FijaPorMes => $condiciones->monto->multiplicarPor($meses),

            ModalidadDeMora::PorcentajeMensual => self::aDosDecimales(bcmul(
                bcmul($saldoVencido->valor, $condiciones->fraccionMensual(), TasaDeInteres::ESCALA),
                (string) $meses,
                TasaDeInteres::ESCALA,
            )),

            ModalidadDeMora::TasaAnual => self::aDosDecimales(bcmul(
                bcmul($saldoVencido->valor, $condiciones->fraccionDiaria(), TasaDeInteres::ESCALA),
                (string) $cobrados,
                TasaDeInteres::ESCALA,
            )),

            // Inalcanzable: `cobra()` ya lo filtro arriba. Va explicito para
            // que el match sea exhaustivo y PHPStan no tenga que suponerlo.
            ModalidadDeMora::Ninguna => Monto::cero(),
        };

        return new self($monto, $dias, $cobrados, $meses, $condiciones->modalidad, $saldoVencido);
    }

    public function hayMora(): bool
    {
        return ! $this->monto->esCero();
    }

    /**
     * Como se explica en el mostrador, que es donde se discute.
     *
     * «L 287.67 — 24 % anual sobre L 14,583.33 por 30 dias de atraso»
     */
    public function explicacion(): string
    {
        if (! $this->hayMora()) {
            return $this->diasDeAtraso === 0
                ? 'Al dia.'
                : 'Atrasada '.$this->diasDeAtraso.' dias, sin mora.';
        }

        $porQue = match ($this->modalidad) {
            ModalidadDeMora::FijaPorCuota      => 'cargo fijo por cuota atrasada',
            ModalidadDeMora::FijaPorMes        => 'cargo fijo por '.$this->mesesCobrados.' '.($this->mesesCobrados === 1 ? 'mes' : 'meses'),
            ModalidadDeMora::PorcentajeMensual => 'porcentaje mensual sobre '.$this->baseDelCalculo->formateado().' por '.$this->mesesCobrados.' '.($this->mesesCobrados === 1 ? 'mes' : 'meses'),
            ModalidadDeMora::TasaAnual         => 'tasa anual sobre '.$this->baseDelCalculo->formateado().' por '.$this->diasCobrados.' '.($this->diasCobrados === 1 ? 'dia' : 'dias'),
            ModalidadDeMora::Ninguna           => 'sin mora',
        };

        return $this->monto->formateado().' — '.$porQue.'.';
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Mismo camino que `TablaDeAmortizacion`: el primer `Monto` valida y
     * normaliza la cadena cruda de bcmath, `redondeado()` es el unico half-up
     * del sistema (§8.3.1).
     */
    private static function aDosDecimales(string $crudo): Monto
    {
        /** @var numeric-string $crudo */
        return new Monto(new Monto($crudo)->redondeado());
    }
}
