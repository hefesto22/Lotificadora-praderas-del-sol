<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;

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
}
