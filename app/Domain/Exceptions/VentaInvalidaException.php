<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\UnidadDeArea;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\TasaDeInteres;

/**
 * Esa venta no se puede registrar asi.
 *
 * Los mensajes le hablan a quien esta armando el expediente con el cliente
 * enfrente: dicen que corregir, no que fallo.
 */
final class VentaInvalidaException extends GrupoOlympoException
{
    public static function porNoTenerLotes(): self
    {
        return new self(
            'La venta no tiene lotes. Hay que elegir al menos uno antes de firmar el contrato.'
        );
    }

    public static function porNoTenerClientes(): self
    {
        return new self(
            'La venta no tiene a nombre de quien ir. Hay que elegir al menos un cliente; '.
            'si son dos duenos, el primero queda como titular.'
        );
    }

    public static function porLoteDeOtroProyecto(string $codigo): self
    {
        return new self(
            "El lote {$codigo} es de otro proyecto. Una venta no puede mezclar lotes de dos "
            .'desarrollos: el numero de contrato sale del codigo del proyecto.'
        );
    }

    public static function porLoteRepetido(string $codigo): self
    {
        return new self("El lote {$codigo} esta dos veces en la misma venta.");
    }

    /**
     * El re-check DENTRO de la transaccion (§8.3.2).
     *
     * Entre que se armo el formulario y se apreto Guardar pudo pasar de
     * todo: otro receptor aparto ese lote desde su computadora, o alguien
     * lo cancelo. Por eso no alcanza con lo que decia la pantalla.
     */
    public static function porLoteNoDisponible(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} ya no esta disponible: figura como {$estado}. "
            .'Alguien lo movio mientras se armaba esta venta. Refrescar y volver a empezar.'
        );
    }

    public static function porApartadoDeOtroCliente(string $codigo, string $cliente): self
    {
        return new self(
            "El lote {$codigo} esta apartado a nombre de {$cliente}. Para venderselo a otra "
            .'persona hay que liberar el apartado primero, y eso deberia quedar conversado '
            .'con quien lo tenia.'
        );
    }

    public static function porPrimaMayorAlValor(Monto $prima, Monto $valor): self
    {
        return new self(
            "La prima de {$prima->formateado()} es mayor que el valor de los lotes "
            ."({$valor->formateado()}). Revisar el monto o los lotes elegidos."
        );
    }

    /**
     * R4, verificada ANTES de que se queme el correlativo.
     *
     * La misma regla vive como CHECK en `compromisos` y como guarda en
     * `RegistroDeCompromisos`. Se adelanta aca porque cuando el compromiso
     * se escribe el numero de contrato ya se consumio: la transaccion lo
     * devolveria igual, pero una validacion que se puede hacer antes no
     * tiene por que hacerse despues.
     */
    public static function porDescuentoSinMotivo(string $codigo, Monto $lista, Monto $pactado, UnidadDeArea $unidad = UnidadDeArea::Varas): self
    {
        return new self(
            "El lote {$codigo} se esta vendiendo a {$pactado->formateado()} {$unidad->porUnidad()} cuando "
            ."el precio de lista es {$lista->formateado()}. Un precio menor se puede registrar, "
            .'pero hay que escribir el motivo del descuento: queda anotado con el usuario y la fecha.'
        );
    }

    /**
     * R4, aplicado al precio del dinero. Ver la gemela en
     * `CompromisoInvalidoException`: la regla es una sola y cada Service
     * tira la excepcion que le sirve a quien esta atendiendo.
     */
    public static function porTasaSinMotivo(string $codigo, TasaDeInteres $lista, TasaDeInteres $pactada): self
    {
        return new self(
            "El lote {$codigo} se esta vendiendo con un interes de {$pactada->formateada()} anual "
            ."cuando el plan de ese plazo ofrece {$lista->formateada()}. Una tasa menor se puede "
            .'registrar, pero hay que escribir el motivo: queda anotado con el usuario y la fecha.'
        );
    }

    /**
     * Las primas por lote no dan la prima del contrato.
     *
     * Pasa cuando la pantalla manda una prima para cada lote y su suma no
     * coincide con la que dice el contrato. No es un error de calculo: es
     * que dos numeros que tienen que ser el mismo no lo son, y grabar
     * cualquiera de los dos dejaria un expediente que no cuadra.
     */
    public static function porPrimasQueNoSuman(Monto $porLote, Monto $delContrato): self
    {
        return new self(
            "Las primas de los lotes suman {$porLote->formateado()} pero el contrato dice "
            ."{$delContrato->formateado()}. Revisa la prima de cada lote: los dos numeros "
            .'tienen que ser el mismo.'
        );
    }

    /**
     * Algo del plan de UN lote no cierra.
     *
     * Con plazos distintos por lote, un mensaje sin nombre —«el saldo es
     * demasiado chico para 60 meses»— obliga a adivinar cual de los tres es.
     * Se antepone el codigo y se conserva el mensaje del dominio, que ya
     * esta escrito para quien atiende.
     */
    public static function porElLote(string $codigo, string $mensaje): self
    {
        return new self("En el lote {$codigo}: {$mensaje}");
    }

    /**
     * La red de seguridad del §8.3.4.
     *
     * Si el plan no suma exactamente el saldo, no se escribe ni una cuota.
     * Un plan que no cierra produce un estado de cuenta que nunca llega a
     * cero, y eso se descubre meses despues con dinero de por medio.
     */
    public static function porPlanQueNoCierra(Monto $sumaDeCuotas, Monto $saldo): self
    {
        return new self(
            "El plan de cuotas suma {$sumaDeCuotas->formateado()} pero el saldo a financiar "
            ."es {$saldo->formateado()}. No se registro nada. Es un error del calculo, no "
            .'de los datos: reportarlo.'
        );
    }

    /**
     * R14: la seña del apartado cuenta como parte de la prima.
     *
     * Si las señas ya suman mas que la prima que se escribio en la pantalla,
     * el numero esta mal: el cliente entrego mas plata de la que el contrato
     * declara como prima, y el papel de hoy saldria en negativo.
     *
     * No se acepta y se descuenta del saldo: eso rompe el «valor - prima»
     * que el contrato impreso declara, y esa conversacion es de la
     * contratante, no del sistema.
     */
    public static function porSeniaMayorALaPrima(Monto $senias, Monto $prima): self
    {
        return new self(
            "Los apartados de este contrato ya suman {$senias->formateado()} en señas, y la prima ".
            "quedo en {$prima->formateado()}. La seña cuenta como parte de la prima (R14), asi que ".
            'la prima no puede ser menor a lo que el cliente ya entrego: hay que subirla.'
        );
    }

    /**
     * R11: sin numero de referencia no hay con que cruzarlo contra el banco.
     */
    public static function porPrimaSinReferencia(FormaDePago $forma): self
    {
        return new self(
            'La prima entro por '.mb_strtolower($forma->etiqueta()).' y falta el numero de '.
            'referencia. Es lo unico que despues permite encontrar ese movimiento en el estado '.
            'de cuenta del banco; en efectivo no hace falta.'
        );
    }
}
