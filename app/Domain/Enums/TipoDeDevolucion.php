<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Los dos motivos por los que sale plata de la caja hacia un cliente.
 *
 * Se liquidan con la misma aritmética —entró tanto, se devolvió tanto, quedó
 * tanto a favor de la lotificadora— y por eso viven en la misma tabla. Lo que
 * los separa es qué se deshace y qué firma el cliente:
 *
 * - **Seña**: se cayó un apartado de quince días (R14). El lote nunca fue
 *   suyo del todo y no hubo contrato.
 * - **Rescisión**: se cae un lote de un contrato firmado (R22). Hubo prima,
 *   hubo cuotas y hay un expediente que sigue existiendo.
 */
enum TipoDeDevolucion: string
{
    case Senia = 'senia';
    case Rescision = 'rescision';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Senia     => 'Devolución de seña',
            self::Rescision => 'Rescisión de lote',
        };
    }

    /**
     * El título del papel que se le entrega al cliente.
     *
     * En mayúsculas porque es un encabezado de documento, igual que
     * «RECIBO DE CUOTA» y «FACTURA».
     */
    public function titulo(): string
    {
        return match ($this) {
            self::Senia     => 'COMPROBANTE DE DEVOLUCIÓN',
            self::Rescision => 'ACTA DE RESCISIÓN Y LIQUIDACIÓN',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Senia     => 'warning',
            self::Rescision => 'danger',
        };
    }

    /**
     * Cómo se llama, en el papel, lo que NO se devolvió.
     *
     * No es un detalle de redacción: en un apartado esa plata se queda «a
     * favor del proyecto» porque el lote estuvo retenido quince días; en una
     * rescisión se queda por incumplimiento de un contrato firmado, y ahí la
     * palabra que corresponde es otra.
     */
    public function rotuloDeLoRetenido(): string
    {
        return match ($this) {
            self::Senia     => 'Queda a favor del proyecto',
            self::Rescision => 'Retenido por la lotificadora',
        };
    }
}
