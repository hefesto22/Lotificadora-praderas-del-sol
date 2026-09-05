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

    /**
     * Un lote rescindido no se cobra, aunque le quede una cuota con saldo.
     *
     * La cuota pagada a medias sobrevive a la rescision —tiene un recibo
     * encima— y por eso el lote sigue «debiendo» a los ojos de una consulta
     * ingenua. Sin esta guarda, la ventanilla podria emitirle un recibo
     * numerado a alguien por un terreno que ya devolvio.
     *
     * Va en el Service y no solo en la pantalla: el modal de cobro ya los
     * filtra, pero el plano y cualquier pantalla futura entran por acá.
     */
    public static function porLoteRescindido(string $codigo): self
    {
        return new self(
            "El lote {$codigo} esta rescindido: ya no es de este cliente y no se le puede cobrar. ".
            'Si le queda un saldo en pantalla es de una cuota vieja que se conserva como historia; '.
            'lo que paso con esa plata esta en el acta de rescision.'
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
     * Un lote atrasado no recibe abono a capital.
     *
     * ═══ QUE PIDIO MAURICIO, TEXTUAL (24-AGO-2026) ═══
     *
     * «Que no pueda hacer abono a capital si tiene cuotas pendientes okey».
     *
     * ═══ 🔴 ESTO REEMPLAZA LO QUE SE DECIDIO EL 6-AGO ═══
     *
     * Hasta hoy el abono «primero ponia al dia»: se comia lo vencido en FIFO y
     * solo el sobrante bajaba capital. Funcionaba, pero dejaba UN papel que
     * contaba dos historias —cobro y abono— y, cuando no alcanzaba ni para lo
     * vencido, un recibo que decia abono sin haber abonado nada.
     *
     * Ahora el camino esta separado: las cuotas vencidas se cobran por «Cuota»,
     * y quien trae plata para las dos cosas usa «Ambas», que hace exactamente
     * eso en un solo recibo y ya rechazaba este mismo caso
     * (`porSobranteQueNoBajaCapital`). El abono a capital queda para lo que
     * dice su nombre: un lote al dia al que le baja el saldo.
     *
     * ⚠️ El corte es `Cuota::estaVencida()`, que es la misma regla que usa la
     * mora y la pantalla: vencida es la que YA PASO su fecha. La que vence hoy
     * todavia no atrasa, y el abono se puede hacer.
     */
    public static function porCuotasVencidasAntesDelAbono(int $cuantas, Monto $vencido, string $codigo): self
    {
        $atrasadas = $cuantas === 1 ? 'una cuota vencida' : "{$cuantas} cuotas vencidas";

        return new self(
            "El lote {$codigo} tiene {$atrasadas} por {$vencido->formateado()}, así que no puede recibir un ".
            'abono a capital: primero se pone al día. Cobrá esas cuotas por «Cuota», o usá «Ambas» y el '.
            'sobrante baja el capital en el mismo recibo.'
        );
    }

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

    /**
     * Perdonar la mora es un tramite, no un campo que se deja vacio: sin
     * motivo escrito no hay condonacion, igual que el descuento de R4.
     */
    public static function porFaltarElMotivoDeLaCondonacion(): self
    {
        return new self('Para condonar la mora hace falta escribir por que. Queda en el recibo, con el nombre de quien la autorizo.');
    }

    /**
     * El modo «Ambas» con un sobrante que no llega a bajar capital.
     *
     * ═══ POR QUE SE RECHAZA EN VEZ DE REGISTRARLO ═══
     *
     * Despues de cobrar las cuotas marcadas, al lote elegido todavia le queda
     * algo vencido y el sobrante no lo cubre. Ese dinero se aplicaria a cuotas
     * y no bajaria un centavo de capital — o sea que «Ambas» no habria hecho lo
     * que promete, y quedaria un recibo de abono que no abono nada.
     *
     * Se rechaza con el numero exacto que falta porque la pantalla sigue
     * abierta: mover ese dinero al renglon de la cuota es un campo, y quien
     * atiende lo hace con el cliente enfrente sin cerrar nada.
     */
    public static function porSobranteQueNoBajaCapital(Monto $sobrante, Monto $vencido, string $codigo): self
    {
        return new self(
            "El sobrante de {$sobrante->formateado()} no baja capital: al lote {$codigo} le quedan ".
            "{$vencido->formateado()} vencidos y hay que cubrirlos primero. Sumale esa diferencia a la ".
            'cuota de ese lote, o abona mas.'
        );
    }

    // ─── Pronto pago (23-ago-2026) ────────────────────────────────────

    /**
     * Sin motivo no hay descuento. Es la misma regla que R4 al vender y que
     * la condonacion de mora: el sistema no opina CUANTO, pero exige que
     * quede escrito POR QUE, y quien lo autorizo.
     */
    public static function porFaltarElMotivoDelDescuento(): self
    {
        return new self(
            'Un pronto pago perdona parte del saldo, asi que hace falta escribir por que. Alcanza con '.
            'una linea: queda en el expediente con tu nombre y la fecha, y dentro de dos anios va a '.
            'ser lo unico que conteste por que a este cliente se le descontaron esos lempiras.'
        );
    }

    /**
     * El descuento no puede pasarse de lo que el lote debe: perdonar mas que
     * el saldo dejaria a la lotificadora debiendole al cliente.
     */
    public static function porDescuentoQueSuperaElSaldo(Monto $descuento, Monto $saldo, string $codigo): self
    {
        return new self(
            "El lote {$codigo} debe {$saldo->formateado()} y el descuento es de ".
            "{$descuento->formateado()}. No se puede perdonar mas de lo que se debe."
        );
    }

    /**
     * ═══ 🔴 POR QUE SE RECHAZA EN VEZ DE COBRARLA DE PASO ═══
     *
     * Un pronto pago reparte el dinero del cliente contra las cuotas y perdona
     * la cola. Meter la mora en ese reparto abre una pregunta que NADIE
     * contesto todavia: si el descuento alcanza para la mora, ¿se perdono mora
     * o capital? Son dos columnas distintas, dos permisos distintos y dos
     * numeros distintos en el corte de caja.
     *
     * Praderas del Sol no cobra mora (R2), asi que este camino no se cruza
     * nunca aca. Se rechaza a proposito para no adivinar una regla de negocio
     * que todavia no existe: el dia que una lotificadora con mora lo pida, se
     * decide con ella y con un caso real sobre la mesa.
     */
    public static function porMoraPendienteEnProntoPago(Monto $mora, string $codigo): self
    {
        return new self(
            "El lote {$codigo} tiene {$mora->formateado()} de mora pendiente. Cobrala primero por ".
            '«Registrar un pago» y despues hace el pronto pago: el descuento se aplica al saldo del '.
            'plan, no a la mora.'
        );
    }

    /**
     * Corregir un recibo sin decir por qué.
     *
     * El motivo pesa lo mismo que en la anulación: el papel que el cliente
     * tiene en la mano dejó de coincidir con la base, y alguien va a querer
     * saber quién lo decidió y con qué razón.
     */
    public static function porFaltarElMotivoDeLaCorreccion(): self
    {
        return new self(
            'Corregir un recibo cambia lo que dice la base sobre un papel que ya se entregó, así '.
            'que hace falta escribir por qué. Alcanza con una línea: «la referencia de la '.
            'transferencia se tecleó mal», «el dinero lo recibió don Elder, no doña Rosa».'
        );
    }

    /**
     * Un recibo anulado ya no se corrige: se corrige el que lo reemplazó.
     */
    public static function porCorregirUnReciboAnulado(string $folio): self
    {
        return new self(
            "El recibo {$folio} está anulado, así que ya no hay nada que corregirle. Si el papel ".
            'bueno es el que lo reemplazó, corregí ese.'
        );
    }

    /**
     * El usuario elegido ya no existe.
     *
     * Pasa si alguien deja el modal abierto y mientras tanto se borra a esa
     * persona. Sin esto la caída sería un error de llave foránea de Postgres,
     * que no le dice nada a quien está en la pantalla.
     */
    public static function porQuienRecibioQueNoExiste(): self
    {
        return new self(
            'La persona que elegiste como quien recibió el dinero ya no existe en el sistema. '.
            'Recargá la página y elegí de la lista nueva.'
        );
    }
}
