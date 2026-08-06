<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué papel es el que se está guardando en el expediente.
 *
 * El expediente de una venta es, además de números, una carpeta: la promesa
 * de venta firmada, la copia del DNI, el comprobante del depósito. Hoy eso
 * vive en un archivador y en el WhatsApp de alguien.
 *
 * `Otro` existe a propósito y con su nombre obligatorio: la lista no puede
 * anticipar todo lo que un cliente trae, y forzar a clasificar mal es peor
 * que dejar una puerta con etiqueta.
 *
 * La lista es la fuente de verdad: la migración arma su CHECK a partir de
 * `valores()`.
 */
enum TipoDeDocumento: string
{
    case PromesaDeVenta = 'promesa_venta';
    case Contrato = 'contrato';
    case Identidad = 'identidad';
    case Comprobante = 'comprobante';
    case Otro = 'otro';

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
            self::PromesaDeVenta => 'Promesa de venta',
            self::Contrato       => 'Contrato firmado',
            self::Identidad      => 'Documento de identidad',
            self::Comprobante    => 'Comprobante de pago',
            self::Otro           => 'Otro',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PromesaDeVenta => 'warning',
            self::Contrato       => 'success',
            self::Identidad      => 'info',
            self::Comprobante    => 'primary',
            self::Otro           => 'gray',
        };
    }
}
