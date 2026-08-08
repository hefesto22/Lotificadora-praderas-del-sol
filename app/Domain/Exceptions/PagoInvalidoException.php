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

    /**
     * Nadie marcó un lote.
     *
     * Pasa cuando se desmarcan los tres renglones y se aprieta Cobrar. La
     * pantalla no tiene por qué adivinar a cuál iba el dinero, y adivinar mal
     * es un recibo contra el lote equivocado.
     */
    public static function porNoElegirNingunLote(): self
    {
        return new self(
            'No hay ningún lote marcado. Marcá al menos uno y escribí cuánto se le cobra a cada uno.'
        );
    }

    /**
     * El mismo lote dos veces en un solo cobro.
     *
     * Sumar los dos renglones en silencio dejaría un recibo con un total que
     * nadie tecleó. Un lote, un monto: el FIFO ya se encarga de repartirlo
     * entre sus cuotas.
     */
    public static function porLoteRepetido(string $codigo): self
    {
        return new self(
            "El lote {$codigo} viene dos veces en el mismo cobro. Dejá un solo monto por lote: ".
            'el reparto se encarga de dividirlo entre sus cuotas.'
        );
    }

    // ─── La fecha del pago ────────────────────────────────────────────

    /**
     * Un cobro con fecha futura.
     *
     * No es un capricho: el estado de cuenta ordena por fecha y los días de
     * atraso se cuentan contra hoy. Un recibo fechado el mes que viene deja
     * una cuota que figura pagada antes de haberse cobrado.
     */
    public static function porFechaFutura(string $fecha): self
    {
        return new self(
            "La fecha del pago ({$fecha}) es posterior a hoy. Un recibo no se emite por adelantado: ".
            'si el cliente paga hoy, la fecha es hoy.'
        );
    }

    public static function porFechaAnteriorAlContrato(string $fecha, string $contrato): self
    {
        return new self(
            "La fecha del pago ({$fecha}) es anterior a la firma del contrato ({$contrato}). ".
            'Revisá si te equivocaste de año, que es lo que pasa casi siempre.'
        );
    }

    // ─── Anular un recibo ─────────────────────────────────────────────

    public static function porReciboYaAnulado(string $folio): self
    {
        return new self("El recibo {$folio} ya estaba anulado. No hay nada que revertir.");
    }

    /**
     * R12: el motivo es obligatorio y la base también lo exige.
     *
     * Un recibo anulado sin motivo es dinero que desapareció del estado de
     * cuenta sin que nadie tenga que explicarlo.
     */
    public static function porFaltarElMotivoDeLaAnulacion(): self
    {
        return new self(
            'Anular un recibo borra un cobro del estado de cuenta del cliente, así que hace falta '.
            'escribir por qué. Alcanza con una línea: dentro de seis meses alguien va a preguntar '.
            'qué pasó con ese número.'
        );
    }

    /**
     * Solo se anulan los cobros de cuota.
     *
     * Una prima o una seña no son un cobro suelto: consumieron el correlativo
     * del contrato o dejaron un lote apartado. Revertirlas es deshacer una
     * venta o un apartado, que son otros trámites y tienen otro permiso.
     */
    public static function porConceptoQueNoSeAnulaAsi(string $concepto, string $folio): self
    {
        return new self(
            "El recibo {$folio} es de {$concepto}, y eso no se anula desde acá: revertirlo significa ".
            'deshacer la venta o el apartado del que salió. Es otro trámite.'
        );
    }

    /**
     * Un abono a capital reescribió el plan de cuotas.
     *
     * Deshacerlo no es devolver el dinero: es devolverle al lote las cuotas
     * que ese abono borró, con sus fechas y sus montos. El plan viejo está
     * guardado entero en `reprogramaciones.plan_anterior`, así que se puede —
     * pero es un trámite propio y no una variante de este.
     */
    public static function porReciboQueReprogramo(string $folio): self
    {
        return new self(
            "El recibo {$folio} es un abono a capital: reescribió el plan de cuotas del lote. ".
            'Anularlo tendría que devolver las cuotas que borró, y eso todavía no está construido. '.
            'Avisá antes de tocarlo.'
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
