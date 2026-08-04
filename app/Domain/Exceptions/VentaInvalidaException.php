<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;

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
}
