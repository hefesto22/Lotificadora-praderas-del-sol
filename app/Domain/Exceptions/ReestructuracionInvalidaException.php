<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;

/**
 * Ese plan no se puede reestructurar, o no así.
 *
 * Igual que las demás excepciones del dominio: le habla a la administradora
 * que está con el cliente enfrente, y cada mensaje dice QUE HACER, no solo qué
 * falló.
 *
 * Desde el 26-sep-2026 lo ya pagado ya no traba: la reestructuración lo
 * reubica en el plan nuevo sola (`CoberturaDelPlan`). Por eso se fueron las
 * negativas por cuota pagada a medias, por mora ya cobrada y por cuotas
 * pagadas fuera de orden; quedan las que de verdad no tienen arreglo acá.
 */
final class ReestructuracionInvalidaException extends GrupoOlympoException
{
    public static function porFaltarElMotivo(): self
    {
        return new self(
            'Falta el motivo. Reestructurar cambia el plan que el cliente firmó, y dentro de un año '.
            'va a ser lo único que conteste por qué.'
        );
    }

    public static function porVentaQueNoEstaVigente(string $contrato): self
    {
        return new self(
            "El contrato {$contrato} no está vigente: solo se reestructura el plan de un contrato que se sigue pagando."
        );
    }

    /**
     * Reestructurar es cuadrar el plan; cambiarle las condiciones es
     * renegociarlo (18-oct-2026). Las fechas, el descuento y quitar la mora
     * van por `RegistroDeReestructuraciones::renegociar()`, que sale con su
     * propia acta y su propio permiso.
     */
    public static function porSerUnaRenegociacion(): self
    {
        return new self(
            'Las fechas pactadas, el descuento y quitar la mora son una renegociación del contrato: hacelo con '
            .'«Renegociar el contrato». Reestructurar es para cuadrar el plan.'
        );
    }

    /**
     * Un contrato a fechas no se reestructura: no tiene cuota por mes que
     * cuadrar. Sus fechas se cambian renegociándolo (18-oct-2026).
     */
    public static function porPlanAFechasPactadas(string $contrato): self
    {
        return new self(
            "El contrato {$contrato} es a fechas pactadas: no tiene cuota por mes que cuadrar. "
            .'Para cambiarle las fechas, renegocialo con «Renegociar el contrato».'
        );
    }

    /**
     * Un contrato a fechas tiene UN calendario (18-oct-2026). Pactar fechas
     * para unos lotes y dejar a otros con su cuota por mes lo partiría en
     * dos, y `ventas.plan_a_fechas` es del contrato entero: el día de pago, el
     * abono a capital y el resto al final leen esa bandera.
     */
    public static function porFechasConLotesAfuera(string $contrato, string $afuera): self
    {
        return new self(
            "El contrato {$contrato} se pacta a fechas con todos sus lotes: {$afuera} se quedaría con su plan por mes "
            .'y el contrato tendría dos calendarios. Marcá todos los lotes.'
        );
    }

    /**
     * «A fechas» y el descuento van por el camino que no anula recibos
     * (18-oct-2026): lo que ya pagó sigue con sus recibos y paga el plan
     * nuevo. El reparto por depósito es de la cuota por mes.
     */
    public static function porFechasAlVolverAEmitir(): self
    {
        return new self(
            'Lo que se renegocia —fechas, descuento, quitar la mora— va sin anular los recibos: lo que ya pagó sigue con '
            .'sus mismos recibos y paga el plan nuevo. Desmarcá «Anular los recibos y volver a emitirlos».'
        );
    }

    public static function porNoQuedarNadaQueReestructurar(string $contrato): self
    {
        return new self(
            "El contrato {$contrato} no tiene cuotas pendientes: no hay plan que reestructurar."
        );
    }

    public static function porNoElegirNingunLote(): self
    {
        return new self('Marcá al menos un lote: son los que se van a pagar con el plan nuevo.');
    }

    /**
     * Juntando solo algunos lotes, el día de pago del contrato sigue siendo
     * el de los otros. Ver `RegistroDeReestructuraciones::verificarElDiaDePago()`.
     */
    public static function porDiaDePagoConLotesAfuera(int $nuevo, int $delContrato, string $afuera): self
    {
        return new self(
            "El primer vencimiento cae el día {$nuevo}, y el contrato paga los días {$delContrato}. ".
            "Como {$afuera} se quedan con su plan, el día de pago no puede cambiar: elegí una fecha ".
            "de día {$delContrato}, o marcá todos los lotes."
        );
    }

    /**
     * Un pronto pago salda cuotas con un descuento: parte de lo «pagado» es
     * capital perdonado, no dinero. Ese perdón se pactó sobre ESAS cuotas y
     * con ese plan; llevarlo a un plan nuevo sería regalarlo otra vez con
     * otros números. Es raro —un contrato saldado no se reestructura—, y se
     * dice con nombre.
     */
    public static function porCuotaConDescuento(int $numero, string $lote): self
    {
        return new self(
            "La cuota {$numero} del lote {$lote} se saldó con un descuento de pronto pago. ".
            'Ese descuento no se puede pasar a un plan nuevo: anulá el pronto pago y después reestructurá.'
        );
    }

    /**
     * ═══ LO PAGADO SE MUEVE RENGLON POR RENGLON ═══
     *
     * Reestructurar reubica cada recibo en el plan nuevo, y para eso lee los
     * renglones de los recibos. Si la cuota dice que tiene pagado un número y
     * sus recibos suman otro, no se sabe qué recibo pagó qué, y mover a ciegas
     * es cómo un recibo termina aplicando más o menos de lo que cobró (§4.L7).
     */
    public static function porPagoQueNoCuadraConSusRecibos(int $numero, string $lote, Monto $dice, Monto $recibos): self
    {
        return new self(
            "La cuota {$numero} del lote {$lote} dice que tiene pagado {$dice->formateado()}, pero sus recibos ".
            "suman {$recibos->formateado()}. Hay que revisarla antes de reestructurar: así no se sabe qué recibo pagó qué."
        );
    }

    /**
     * Juntar los lotes pone lo pagado en el orden del plan nuevo, sin mirar de
     * qué lote vino. Con un solo nombre en los recibos da igual; con dos,
     * movería lo que pagó una persona a la cuota de otra.
     */
    public static function porTitularesDistintos(string $contrato): self
    {
        return new self(
            "Los lotes del contrato {$contrato} sacan los recibos a nombre de personas distintas, y ya hay dinero ".
            'pagado: juntarlos pasaría lo que pagó una a la cuota de otra. Para unificarlos, primero dejá los '.
            'recibos de todos los lotes a un mismo nombre, o rehacé cada lote con su propia cuota.'
        );
    }

    public static function porPagadoMayorQueElPlan(Monto $pagado, Monto $plan): self
    {
        return new self(
            "Lo que ya pagó ({$pagado->formateado()}) es más que todo el plan nuevo ({$plan->formateado()}): ".
            'no hay dónde ponerlo. Revisá la tasa o los meses.'
        );
    }

    /**
     * Una cuota que tuvo un cobro anulado conserva el renglón de ese recibo,
     * así que no se puede borrar: se reescribe en su lugar. Si el plan nuevo
     * no llega a ese número, no hay dónde.
     */
    public static function porCuotaConCobroAnuladoFueraDelPlan(int $numero, string $lote): self
    {
        return new self(
            "La cuota {$numero} del lote {$lote} tuvo un cobro que se anuló, y el plan nuevo no llega a ".
            'ese número. Probá con más meses o una cuota más baja.'
        );
    }

    public static function porEmpezarAntesDelContrato(string $fecha, string $firma): self
    {
        return new self(
            "El primer vencimiento ({$fecha}) no puede ser anterior a la firma del contrato ({$firma})."
        );
    }

    public static function porEmpezarAntesDeLoPagado(string $fecha, string $ultima): self
    {
        return new self(
            "El primer vencimiento ({$fecha}) tiene que ser después de la última cuota pagada, que vencía el {$ultima}."
        );
    }

    /**
     * «Si se reestructura, la mora no va» (26-sep-2026): la reestructuración
     * perdona la mora, y a cambio el plan nuevo tiene que nacer al día. Una
     * cuota vencida y sin cubrir correría mora desde su fecha, sobre un plan
     * que el cliente recién firmó.
     */
    public static function porNacerAtrasado(int $numero, string $fecha, Monto $falta, string $desdeHoy): self
    {
        return new self(
            "La cuota {$numero} del plan nuevo vencería el {$fecha}, que ya pasó, y le faltarían {$falta->formateado()}: ".
            'el plan nacería atrasado. Reestructurar deja la mora en cero, así que el plan tiene que arrancar al día: '.
            "poné el primer vencimiento el {$desdeHoy} o después, o una cuota que lo ya pagado cubra entera. ".
            'Si los pagos de esos meses todavía no están en el sistema, marcá que los registrás después, cada uno con su fecha.'
        );
    }

    // ─── Anular los recibos y emitir uno por cuota (07-oct-2026) ──────

    public static function porNoHaberRecibosQueVolverAEmitir(string $contrato): self
    {
        return new self(
            "El contrato {$contrato} no tiene recibos de cuota vigentes en estos lotes: no hay nada que volver a emitir. ".
            'Desmarcá «anular los recibos y emitir uno por cuota».'
        );
    }

    /**
     * Una factura con CAI no se anula y se vuelve a emitir como un recibo
     * interno: cada una es un número ante el SAR, y anularla pide nota de
     * crédito.
     */
    public static function porProyectoQueFacturaConCai(string $contrato): self
    {
        return new self(
            "El desarrollo del contrato {$contrato} factura con CAI: sus pagos son facturas ante el SAR y no se anulan ".
            'y se vuelven a emitir desde acá. Reestructurá sin esa opción.'
        );
    }

    public static function porReciboQueEsFactura(string $folio): self
    {
        return new self(
            "El pago {$folio} salió como factura con CAI: no se anula y se vuelve a emitir desde acá. Reestructurá sin esa opción."
        );
    }

    public static function porReciboConDescuento(string $folio): self
    {
        return new self(
            "El recibo {$folio} lleva un descuento por pronto pago: no se puede volver a emitir como cuotas. Reestructurá sin esa opción."
        );
    }

    public static function porReciboDeOtroLote(string $folio): self
    {
        return new self(
            "El recibo {$folio} también paga un lote que no marcaste: anularlo le quitaría ese pago. ".
            'Marcá todos los lotes que paga, o reestructurá sin esa opción.'
        );
    }

    public static function porEntregaEnCero(int $renglon): self
    {
        return new self(
            "Falta cuánto entregó en la cuota {$renglon}, o es cero. Escribí el monto del depósito, o borrá ese renglón si es el último."
        );
    }

    public static function porAbonoEnCero(int $renglon): self
    {
        return new self(
            "Falta el monto del abono a capital {$renglon}, o es cero. Escribí cuánto abonó, o borrá ese abono."
        );
    }

    public static function porEntregaSinCuotaQuePagar(int $renglon): self
    {
        return new self(
            "El renglón {$renglon} de «Entregó» ya no tiene cuota que pagar: lo de antes pagó el plan entero. Revisá los montos de cada cuota."
        );
    }

    public static function porQuedarAtrasadoAlVolverAEmitir(int $pagadas, int $numero, string $vence): self
    {
        return new self(
            "Lo que entregó paga {$pagadas} ".($pagadas === 1 ? 'cuota' : 'cuotas')." del plan nuevo, y la cuota {$numero} venció el {$vence}: ".
            'el plan nacería atrasado. Si esos pagos todavía no están en el sistema, marcá que los registrás después; si no, '.
            'poné el primer vencimiento más adelante.'
        );
    }

    /**
     * Con la fecha del último depósito que está en el sistema: el caso real
     * (13-oct-2026) fue un depósito de la hoja que todavía no se había
     * registrado, y el mensaje tiene que decir qué hacer con él.
     */
    public static function porEscribirMasDeLoQueEntrego(Monto $escrito, Monto $entregado, ?string $ultimoDeposito = null): self
    {
        $ultimo = $ultimoDeposito === null ? '' : " —el último depósito registrado es del {$ultimoDeposito}—";

        return new self(
            "Lo que escribiste —cuotas y abonos— suma {$escrito->formateado()}, y los recibos que se anulan suman {$entregado->formateado()}{$ultimo}: ".
            'no se puede marcar pagado más de lo que entró. Si es un depósito que todavía no está en el sistema, ponele la fecha en que '.
            'lo entregó: entra como pago nuevo.'
        );
    }

    public static function porEntregaSinFecha(int $renglon): self
    {
        return new self("Falta la fecha en que entregó el renglón {$renglon} de «Entregó».");
    }

    public static function porPagoNuevoSinForma(int $renglon, string $fecha): self
    {
        return new self(
            "El renglón {$renglon} es del {$fecha} y ese día no hay ningún depósito registrado: entra como pago nuevo. ".
            'Falta la forma de pago con que lo entregó.'
        );
    }

    public static function porPasarDelDepositoDelDia(int $renglon, string $fecha, Monto $deMas): self
    {
        return new self(
            "El renglón {$renglon} pasa por {$deMas->formateado()} de lo que se depositó el {$fecha} —sin contar lo que ese día fue abono a capital—. ".
            'Revisá el monto o la fecha.'
        );
    }

    public static function porDepositoNuevoEnCero(int $cual): self
    {
        return new self("El depósito nuevo {$cual} está en cero: escribí cuánto entregó, o quitalo.");
    }

    public static function porDepositoCorregidoEnCero(string $fecha): self
    {
        return new self("El depósito del {$fecha} quedó en cero: escribí cuánto entregó, o quitalo con el basurero.");
    }

    public static function porDepositosQueCambiaron(): self
    {
        return new self(
            'Los recibos de este contrato cambiaron mientras la ventana estaba abierta —alguien registró o anuló un cobro—. '.
            'Cerrala y volvé a abrirla para ver los depósitos de ahora.'
        );
    }

    public static function porCapitalMayorQueElDeposito(string $fecha, Monto $deposito): self
    {
        return new self("Lo que va a capital del depósito del {$fecha} pasa de lo que entregó ese día ({$deposito->formateado()}).");
    }

    public static function porHastaFueraDelPlan(int $hasta, int $meses): self
    {
        return new self("El plan nuevo tiene {$meses} cuotas: no se puede pagar hasta la cuota {$hasta}.");
    }

    public static function porNoAlcanzarHastaLaCuota(int $hasta, Monto $falta): self
    {
        return new self(
            "No alcanza para dejar pagado hasta la cuota {$hasta}: faltan {$falta->formateado()}. ".
            'Elegí una cuota anterior, o bajá lo que va a capital en algún depósito.'
        );
    }

    public static function porPasarDeLaCuota(int $hasta, Monto $deMas): self
    {
        return new self(
            "Lo que escribiste a capital deja {$deMas->formateado()} de más para cuotas: con la cuota {$hasta} ya quedan pagadas. ".
            'Subí lo que va a capital en algún depósito, o elegí una cuota más adelante.'
        );
    }

    public static function porSobranteSinCuotasQueBajar(Monto $sobra): self
    {
        return new self(
            "Lo entregado paga el plan entero y sobran {$sobra->formateado()}: no queda capital que bajar con un abono. ".
            'Revisá el capital del contrato antes de reestructurar.'
        );
    }

    /**
     * Lo que el motor sabe armar y el expediente todavía no sabe leer
     * (Praderas, 25-oct-2026). Ver `RegistroDeReestructuraciones::verificarQueSePuedePactar()`.
     */
    public static function porCondicionQueTodaviaNoSePuede(string $que): self
    {
        return new self(
            "Reestructurar con {$que} todavía no está disponible: el estado de cuenta y los abonos tienen que ".
            'saber leerlo antes. Por ahora el plan se rehace por meses o por cuota, con la tasa que se pacte y, '.
            'si se renegocia, sin la mora.'
        );
    }
}
