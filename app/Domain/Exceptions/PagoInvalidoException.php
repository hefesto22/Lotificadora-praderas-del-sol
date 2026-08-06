<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;

/**
 * Lo que impide registrar un pago, dicho como se lo dirías a quien atiende.
 *
 * Nunca «constraint violation». La persona que lee esto tiene un cliente
 * enfrente con el dinero en la mano, y necesita saber qué hacer ahora.
 */
final class PagoInvalidoException extends GrupoOlympoException
{
    public static function porMontoNoPositivo(): self
    {
        return new self('El monto del pago tiene que ser mayor que cero. No se registró nada.');
    }

    public static function porFaltarReferencia(string $forma): self
    {
        return new self(
            "En {$forma} el número de referencia es obligatorio (R11): es lo único que después ".
            'permite cruzar este recibo contra el estado de cuenta del banco.'
        );
    }

    public static function porLoteDeOtraVenta(string $codigo, string $contrato): self
    {
        return new self(
            "El lote {$codigo} no pertenece al contrato {$contrato}. Un pago se aplica a un lote ".
            'de ESTE expediente; si el cliente tiene dos contratos, cada uno cobra el suyo.'
        );
    }

    public static function porNoDeberNada(string $codigo): self
    {
        return new self(
            "El lote {$codigo} no debe nada: sus cuotas están todas pagadas. Revisá si el pago ".
            'va a otro lote del contrato.'
        );
    }

    public static function porPagarDeMas(Monto $pago, Monto $saldo, string $codigo): self
    {
        return new self(
            "El pago de {$pago->formateado()} supera lo que debe el lote {$codigo}, que es ".
            "{$saldo->formateado()}. No se registró nada: cobrá el saldo exacto, o registrá la ".
            'diferencia como abono a capital sobre otro lote.'
        );
    }

    public static function porVentaQueNoEstaVigente(string $estado): self
    {
        return new self(
            "No se puede cobrar sobre un expediente {$estado}. Solo una venta vigente recibe pagos."
        );
    }
}
