<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\TasaDeInteres;

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

    public static function porTasaQueNoAmortiza(): self
    {
        return new self('Con esa tasa y ese plazo la cuota no se puede calcular.');
    }
}
