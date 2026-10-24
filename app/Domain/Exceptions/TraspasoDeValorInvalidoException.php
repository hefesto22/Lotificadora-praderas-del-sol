<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;

/**
 * Ese traspaso de valor entre lotes no se puede hacer así.
 *
 * Los mensajes le hablan a quien está acomodando un expediente contra el
 * cuaderno: dicen qué está mal en lo que pidió y qué mirar. Ver
 * `TraspasoDeValor`.
 */
final class TraspasoDeValorInvalidoException extends GrupoOlympoException
{
    public static function porFaltarElMotivo(): self
    {
        return new self(
            'Falta el motivo. Mover valor entre lotes le cambia la cuota a cada lote: tiene que '
            .'quedar escrito por qué, con las palabras de quien lo pidió.'
        );
    }

    public static function porExpedienteQueNoEstaVigente(string $estado): self
    {
        return new self("Este expediente figura como {$estado}. Solo se mueve valor en un expediente vigente.");
    }

    public static function porLoteQueNoEstaVivo(string $codigo): self
    {
        return new self(
            "El lote {$codigo} ya no está vigente en este expediente (se rescindió o se liberó). "
            .'Con un lote caído el traspaso no sabe qué hacer con lo que se le cobró: no se hace.'
        );
    }

    public static function porLoteQueNoEsDeLaVenta(string $codigo): self
    {
        return new self("El lote {$codigo} no es de este expediente. Revisar el código y la venta.");
    }

    public static function porFaltarUnLote(string $codigo): self
    {
        return new self(
            "Falta el lote {$codigo}. Van TODOS los lotes del contrato, con el valor que les queda "
            .'—aunque no cambie—: así se ve de un vistazo que la suma sigue siendo la misma.'
        );
    }

    public static function porValorEnCero(string $codigo): self
    {
        return new self("El valor nuevo del lote {$codigo} es cero. Un lote vendido no puede valer L 0.00.");
    }

    public static function porPrimaMayorAlValor(string $codigo, Monto $prima, Monto $valor): self
    {
        return new self(sprintf(
            'El lote %s tiene %s de prima y quedaría valiendo %s. La prima no puede ser más que el valor.',
            $codigo,
            $prima->formateado(),
            $valor->formateado(),
        ));
    }

    public static function porNoSumarLoMismo(Monto $antes, Monto $despues): self
    {
        return new self(sprintf(
            'Hoy los lotes suman %s y los valores pedidos suman %s. Un traspaso mueve valor de un lote '
            .'a otro: el contrato tiene que seguir valiendo lo mismo. Para cambiar el total, va '
            .'olympo:corregir-valor.',
            $antes->formateado(),
            $despues->formateado(),
        ));
    }

    public static function porNoCambiarNada(): self
    {
        return new self('Cada lote ya vale lo que se pidió. No hay nada que traspasar.');
    }

    public static function porLoteConInteresOMora(string $codigo): self
    {
        return new self(
            "El lote {$codigo} cobra interés o mora. Rehacer su plan cambiaría cuánto corrió en cada "
            .'cuota: este traspaso no lo hace.'
        );
    }

    public static function porLoteSinPlazo(string $codigo): self
    {
        return new self(sprintf(
            'El lote %s no tiene guardado el plazo con que se firmó. Sin ese número no se puede rehacer su plan: hay que corregirlo primero.',
            $codigo,
        ));
    }

    public static function porLoteDeContadoQueVolveriaADeber(string $codigo): self
    {
        return new self(
            "El lote {$codigo} se vendió de contado y quedaría debiendo. No se sabe a cuántos meses: "
            .'ese caso se arregla a mano, con quien firmó.'
        );
    }

    public static function porValorQueNoCierraConElArea(string $codigo, Monto $pedido, Monto $daria): self
    {
        return new self(sprintf(
            'El lote %s no puede valer exactamente %s con su área: el precio por vara más cercano da %s.',
            $codigo,
            $pedido->formateado(),
            $daria->formateado(),
        ));
    }

    public static function porQuedarBajoElPrecioDeListaSinMotivo(string $codigo): self
    {
        return new self(
            "El lote {$codigo} quedaría por debajo de su precio de lista y no tiene motivo de descuento. "
            .'Si fue un descuento, primero hay que anotarlo.'
        );
    }

    public static function porReciboQueNoSeSabeMover(string $folio, string $por): self
    {
        return new self("El recibo {$folio} {$por} Este traspaso no sabe volver a repartirlo: no se hizo nada.");
    }

    public static function porReciboQueNoCuadra(string $folio): self
    {
        return new self(
            "El recibo {$folio} no cuadra con lo que aplicó. Primero va olympo:cuadrar-recibos: "
            .'mover valor sobre un recibo descuadrado escondería el descuadre.'
        );
    }

    public static function porRastroDeUnAnulado(string $folio): self
    {
        return new self(
            "El recibo anulado {$folio} dejó su rastro en las cuotas de este expediente, y esas cuotas "
            .'se tienen que rehacer. Este traspaso no lo hace.'
        );
    }

    public static function porCuotaPagadaEnPartes(string $codigo, int $numero): self
    {
        return new self(
            "La cuota {$numero} del lote {$codigo} se pagó en partes, con más de un recibo. "
            .'Este traspaso solo sabe mover cuotas que pagó entera un solo recibo.'
        );
    }

    public static function porPrimeraCuotaDistinta(string $codigo, string $antes, string $despues): self
    {
        return new self(
            "El plan nuevo del lote {$codigo} empezaría el {$despues} y el de hoy empieza el {$antes}. "
            .'No se cambia el calendario de un contrato de pasada.'
        );
    }

    public static function porReciboQueNoAlcanza(string $folio, Monto $monto, Monto $cuotas): self
    {
        return new self(sprintf(
            'El recibo %s cobró %s y las mismas cuotas, a su monto nuevo, suman %s. No alcanza: este '
            .'traspaso no sabe cuál dejar a medias.',
            $folio,
            $monto->formateado(),
            $cuotas->formateado(),
        ));
    }

    public static function porSobranteSinLote(string $folio, Monto $sobra): self
    {
        return new self(sprintf(
            'Al recibo %s le sobran %s después de pagar las mismas cuotas, y no tenía UN abono a capital '
            .'a donde mandarlos. Este traspaso no adivina a qué lote van.',
            $folio,
            $sobra->formateado(),
        ));
    }

    public static function porNoQuedarDondeDebia(string $que, Monto $quedo, Monto $debia): self
    {
        return new self(sprintf(
            '%s quedó en %s y tenía que quedar en %s. No se escribió nada.',
            $que,
            $quedo->formateado(),
            $debia->formateado(),
        ));
    }
}
