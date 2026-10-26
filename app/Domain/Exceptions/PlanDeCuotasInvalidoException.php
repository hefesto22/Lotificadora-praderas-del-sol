<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\TasaDeInteres;
use Carbon\CarbonImmutable;

/**
 * Ese plan de pagos no se puede armar.
 *
 * Igual que CompromisoInvalidoException: los mensajes le hablan a quien
 * esta armando una venta con el cliente sentado enfrente, no a un
 * programador. Cada uno dice que hacer, no solo que fallo.
 */
final class PlanDeCuotasInvalidoException extends GrupoOlympoException
{
    public static function porPrimaMayorAlValor(Monto $prima, Monto $valor): self
    {
        return new self(
            "La prima de {$prima->formateado()} es mayor que el valor de la venta ".
            "({$valor->formateado()}). Revisar el precio de los lotes o el monto de la prima."
        );
    }

    /**
     * El lote al que nunca se le puso precio (24-ago-2026).
     *
     * ═══ POR QUE NO ALCANZA CON EL MENSAJE DE CONTADO ═══
     *
     * Con el valor en cero la prima tambien vale cero, asi que
     * tecnicamente «la prima cubre el valor completo» y aquel mensaje es
     * CIERTO. Y no sirve para nada: manda a revisar la prima cuando lo que
     * falta es el precio del lote.
     *
     * Lo vio Mauricio en el modal del plano: las cinco filas de plazo en
     * L 0.00 y la misma explicacion de contado repetida en todas.
     *
     * Un mensaje verdadero que manda a buscar el problema donde no esta
     * cuesta mas caro que uno que falta.
     */
    public static function porLoteSinPrecio(): self
    {
        return new self(
            'Este lote todavia no tiene precio, asi que no hay nada que financiar. '.
            'Ponéselo con «Fijar precio por vara²» en el proyecto, o carga el plan de '.
            'pago del plazo que corresponda.'
        );
    }

    /**
     * Prima completa = venta de contado, y una venta de contado no tiene
     * plan de cuotas. No es un error de calculo, es que no hay nada que
     * financiar.
     */
    public static function porContadoConPlazo(int $plazoMeses): self
    {
        return new self(
            'La prima cubre el valor completo, asi que no queda saldo que financiar, '.
            "pero se pidieron {$plazoMeses} cuotas. Una venta de contado va con plazo 0."
        );
    }

    public static function porPlazoInvalido(int $plazoMeses): self
    {
        return new self(
            "El plazo de {$plazoMeses} meses no es valido: tiene que ser de 1 a ".
            PlanDeCuotas::PLAZO_MAXIMO_MESES.' meses.'
        );
    }

    public static function porDiaDePagoInvalido(int $diaPago): self
    {
        return new self(
            "El dia de pago {$diaPago} no existe. Tiene que ser un numero del 1 al 31; ".
            'en los meses mas cortos el sistema lo corre al ultimo dia del mes.'
        );
    }

    /**
     * El caso raro pero real: financiar un saldo chico en un plazo largo
     * deja una cuota tan pequena que el residuo de redondeo se come la
     * ultima. Antes de dejar pasar una cuota de cero o negativa, se para.
     */
    public static function porSaldoDemasiadoChicoParaElPlazo(Monto $saldo, int $plazoMeses): self
    {
        return new self(
            "Un saldo de {$saldo->formateado()} no alcanza para {$plazoMeses} cuotas: ".
            'la cuota queda en centavos y la ultima no cierra. Bajar el plazo o subir la prima.'
        );
    }

    public static function porCuotaEnCero(): self
    {
        return new self(
            'La cuota fija no puede ser cero: con eso el saldo nunca termina de pagarse.'
        );
    }

    /**
     * La cuota y sus dos partes tienen que sumar exacto.
     *
     * No es un error que un usuario pueda provocar: es la red que atrapa un
     * reparto de residuo mal hecho antes de que llegue a un estado de cuenta.
     */
    public static function porCuotaQueNoCuadraConSusPartes(
        Monto $monto,
        Monto $capital,
        Monto $interes,
        int $numero,
    ): self {
        return new self(
            "La cuota {$numero} dice {$monto->formateado()} pero sus partes suman "
            ."{$capital->sumar($interes)->formateado()} ({$capital->formateado()} de capital "
            ."+ {$interes->formateado()} de interes)."
        );
    }

    /**
     * Una cuota que no cubre ni el interes del mes deja la deuda creciendo
     * para siempre. Pasa al acortar plazo (R21) con una tasa alta.
     */
    public static function porCuotaQueNoCubreElInteres(Monto $cuota, Monto $interes, TasaDeInteres $tasa): self
    {
        return new self(
            "La cuota de {$cuota->formateado()} no alcanza a cubrir el interes del mes "
            ."({$interes->formateado()} al {$tasa->formateada()} anual): la deuda nunca bajaria. "
            .'Hace falta una cuota mayor o una tasa menor.'
        );
    }

    /**
     * La tabla no cierra contra el capital que dice repartir.
     */
    public static function porTablaQueNoCierra(Monto $suma, Monto $capital): self
    {
        return new self(
            "La tabla de amortizacion reparte {$suma->formateado()} de capital "
            ."y el saldo a financiar es {$capital->formateado()}."
        );
    }

    /**
     * 🔴 Con tasa 0 la formula francesa es 0 ÷ 0. El camino sin interes es
     * otro —el de siempre— y quien llame a la tabla con tasa cero se
     * equivoco de puerta, no de numero.
     */
    public static function porTasaCeroEnLaTablaFrancesa(): self
    {
        return new self(
            'La tabla francesa no admite tasa 0: la formula quedaria 0 ÷ 0. '
            .'Un plan sin interes se arma con el camino de siempre, (valor - prima) ÷ plazo.'
        );
    }

    // ─── El plan que se pacta fecha por fecha (05-sep-2026) ──────────

    public static function porPlanPactadoSinFechas(): self
    {
        return new self(
            'No se pactó ninguna fecha de pago. Agregá al menos una: la fecha en que el '.
            'cliente se compromete a pagar el saldo, y cuánto de ese saldo cancela.'
        );
    }

    public static function porDemasiadasFechasPactadas(int $cuantas): self
    {
        return new self(
            "Se pactaron {$cuantas} fechas y el máximo son ".PlanDeCuotas::PAGOS_PACTADOS_MAXIMOS.'. '.
            'Si son tantas ya no es un acuerdo especial: vendé con un plazo en meses y el '.
            'sistema arma el calendario solo.'
        );
    }

    /**
     * Prima completa = de contado, y de contado no hay nada que pactar.
     * Es el gemelo de `porContadoConPlazo()`, para el otro camino.
     */
    public static function porContadoConFechasPactadas(): self
    {
        return new self(
            'La prima cubre el valor completo, así que no queda saldo que pactar. '.
            'Si la venta es de contado, quitá las fechas; si no lo es, bajá la prima.'
        );
    }

    public static function porFechaPactadaFueraDeOrden(
        CarbonImmutable $fecha,
        CarbonImmutable $anterior,
        int $renglon,
    ): self {
        return new self(
            "La fecha del pago {$renglon} ({$fecha->format('d/m/Y')}) no es posterior a ".
            "{$anterior->format('d/m/Y')}. Las fechas van en orden y ninguna puede caer el mismo ".
            'día que la anterior ni el día de la firma: si dos pagos son del mismo día, es un solo pago.'
        );
    }

    public static function porPagoPactadoEnCero(int $renglon): self
    {
        return new self(
            "El pago {$renglon} no dice cuánto cancela del saldo. Un pago pactado de L 0.00 no ".
            'es un pago: borrá el renglón o escribí el monto.'
        );
    }

    public static function porPagoPactadoQueSuperaElSaldo(Monto $capital, Monto $vivo, int $renglon): self
    {
        return new self(
            "El pago {$renglon} cancela {$capital->formateado()} y a esa altura del plan solo ".
            "quedan {$vivo->formateado()} por cancelar. Bajá ese monto o revisá los anteriores."
        );
    }

    /**
     * La invariante del §8.2 dicha para este camino: lo pactado tiene que dar
     * el saldo al centimo. Acá NO se ajusta el ultimo renglon —como sí hacen
     * los otros tres constructores con el residuo de su division— porque
     * estos numeros los escribio una persona y moverle uno sin avisar es
     * cambiarle el contrato al cliente.
     */
    public static function porPagosPactadosQueNoCierran(Monto $suma, Monto $saldo): self
    {
        $diferencia = $suma->mayorQue($saldo) ? $suma->restar($saldo) : $saldo->restar($suma);
        $verbo = $suma->mayorQue($saldo) ? 'sobran' : 'faltan';

        return new self(
            "Lo pactado suma {$suma->formateado()} y el saldo a financiar es {$saldo->formateado()}: ".
            "{$verbo} {$diferencia->formateado()}. Los pagos pactados tienen que cubrir el saldo exacto."
        );
    }

    public static function porRepartoSinLotes(): self
    {
        return new self('No hay ningún lote al que repartirle el calendario pactado.');
    }

    public static function porRepartoSinSaldo(): self
    {
        return new self(
            'Los lotes de este contrato no dejan saldo que financiar, así que no hay nada '.
            'que pactar. Si la venta es de contado, quitá las fechas.'
        );
    }

    /**
     * El reparto entre lotes dejo al lote ancla sin nada que cobrar en una
     * fecha. Pasa cuando un lote es muchisimo mas chico que los otros y la
     * prorrata le asigna practicamente todo el pago.
     */
    public static function porRepartoQueDejaUnLoteEnCero(string $fecha): self
    {
        return new self(
            "Al repartir el pago del {$fecha} entre los lotes del contrato, uno queda sin nada ".
            'que cancelar. Suele pasar cuando los lotes son de valores muy distintos: subí el '.
            'monto de esa fecha, o vendé los lotes en contratos separados.'
        );
    }

    public static function porTasaQueNoAmortiza(): self
    {
        return new self('Con esa tasa y ese plazo la cuota no se puede calcular.');
    }

    // ─── La cuota que se pacta y las cuotas que hacen falta (02-oct-2026) ──

    /**
     * Una cuota que no cubre el interés de su tramo deja la deuda creciendo.
     * Es el gemelo de `porCuotaQueNoCubreElInteres()` para el plan pactado,
     * donde el tramo no es un mes sino «cada tantos meses».
     */
    public static function porEntregaQueNoCubreElInteres(Monto $entrega, Monto $interes, TasaDeInteres $tasa): self
    {
        return new self(
            "La cuota de {$entrega->formateado()} no alcanza ni para el interés del tramo "
            ."({$interes->formateado()} al {$tasa->formateada()} anual): la deuda nunca bajaría. "
            .'Subí la cuota o pagá más seguido.'
        );
    }

    public static function porEntregaQueNecesitaDemasiadasFechas(Monto $entrega, int $cadaMeses): self
    {
        $cada = $cadaMeses === 1 ? 'cada mes' : "cada {$cadaMeses} meses";

        return new self(
            "Con cuotas de {$entrega->formateado()} {$cada} harían falta más de "
            .PlanDeCuotas::PAGOS_PACTADOS_MAXIMOS.' pagos, que es el máximo de una venta a fechas. '
            .'Subí la cuota.'
        );
    }

    /**
     * Un descuento de capital que no deja nada por pagar (18-oct-2026).
     *
     * Perdonar el capital entero no es reestructurar el plan: el plan nuevo se
     * quedaría sin cuotas. Para eso está el pronto pago, que cierra el lote
     * con el descuento escrito en el recibo.
     */
    public static function porDescuentoQueNoDejaCapital(Monto $descuento, Monto $capital): self
    {
        return new self(
            "El descuento ({$descuento->formateado()}) no puede ser todo el capital ({$capital->formateado()}): "
            .'el plan nuevo se quedaría sin nada que pagar. Bajá el descuento, o cerrá el lote con «Pronto pago».'
        );
    }
}
