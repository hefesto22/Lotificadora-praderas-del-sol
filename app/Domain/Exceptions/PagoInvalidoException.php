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

    // ─── Abono a capital (R21) ────────────────────────────────────────

    /**
     * R21 pide que la reprogramación quede registrada CON SU MOTIVO. La base
     * lo exige con un CHECK; esto es para que el mensaje lo escriba alguien.
     */
    public static function porFaltarElMotivoDelAbono(): self
    {
        return new self(
            'Un abono a capital reescribe el plan de cuotas del lote, así que hace falta escribir '.
            'por qué (R21). Alcanza con una línea: el mes que viene alguien va a preguntar por qué '.
            'cambió el número.'
        );
    }

    /**
     * El abono se pasa de lo que se puede reprogramar.
     *
     * Pasa cuando el lote tiene una cuota pagada a medias: esa cuota se
     * respeta —no se toca ni para cobrarla de paso— así que lo que le falta
     * queda fuera del alcance del abono. Cancelar el lote entero es otro
     * trámite, y por eso el mensaje dice los dos números.
     */
    public static function porAbonoQueNoSePuedeReprogramar(
        Monto $abono,
        Monto $tope,
        Monto $saldo,
        string $codigo,
    ): self {
        return new self(
            "Sobre el lote {$codigo} se puede abonar hasta {$tope->formateado()}, y este abono es de ".
            "{$abono->formateado()}. La diferencia es lo que le falta a una cuota que ya está pagada a ".
            'medias, y esa cuota no se toca. Si el cliente quiere cancelar el lote son '.
            "{$saldo->formateado()} por «Registrar un pago»."
        );
    }

    /**
     * El plan nuevo no se puede armar. El mensaje del motor de cuotas ya está
     * escrito para quien atiende, así que se conserva entero y se le antepone
     * el lote — igual que hace RegistroDeVentas con los suyos.
     */
    public static function porPlanQueNoSePudoArmar(string $razon, string $codigo): self
    {
        return new self("No se pudo reprogramar el lote {$codigo}. {$razon}");
    }

    /**
     * §8.3.4: un plan que no cierra al céntimo no llega nunca a la base. Si
     * esto salta, hay un error de aritmética y lo que NO se puede hacer es
     * guardar un estado de cuenta que no cierra en cero el último mes.
     */
    public static function porPlanQueNoCierra(Monto $suma, Monto $saldo): self
    {
        return new self(
            "El plan nuevo suma {$suma->formateado()} y el saldo a reprogramar es {$saldo->formateado()}. ".
            'No se registró nada. Avisá a soporte: es un error de cálculo, no de digitación.'
        );
    }
}
