<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Models\Facturacion;

/**
 * No se puede numerar la factura.
 *
 * A diferencia de CorrelativoInvalidoException, estos mensajes SÍ los va a
 * leer quien está en ventanilla con el cliente enfrente: el caso normal es
 * que la CAI se venció o que se agotó el rango, y eso lo arregla una persona
 * cargando la autorización nueva, no quien programa.
 *
 * Por eso el texto dice qué pasó, dónde se arregla, y cuál es la salida de
 * emergencia si hoy hay que cobrar igual.
 */
final class FacturacionInvalidaException extends GrupoOlympoException
{
    /**
     * ═══ POR QUÉ SE PLANTA Y NO EMITE UN RECIBO INTERNO ═══
     *
     * Porque sería la peor de las dos opciones. El desarrollo tiene una
     * facturación ENCENDIDA, o sea que alguien decidió que acá se factura con
     * CAI; entregarle al cliente un comprobante de caja porque el sistema no
     * encontró números disponibles es emitir el papel equivocado en silencio,
     * y eso no se descubre hasta que lo descubre el SAR.
     *
     * Plantarse duele treinta segundos y se arregla en la pantalla de
     * Facturación. El aviso, además, no llega de sorpresa: la autorización
     * avisa que conviene renovar con DOS MESES de anticipación —que es
     * exactamente la ventana en la que el reglamento deja pedir la
     * siguiente (Art. 59)— y también cuando quedan menos de 50 documentos.
     */
    public static function porFaltarAutorizacionVigente(Facturacion $facturacion): self
    {
        $nombre = $facturacion->getAttribute('nombre');
        $comoSeLlama = is_string($nombre) && trim($nombre) !== '' ? trim($nombre) : 'la facturación del desarrollo';

        return new self(
            "No se puede facturar: {$comoSeLlama} no tiene ninguna autorización vigente. ".
            'O se venció la fecha límite de emisión, o se acabaron los correlativos del rango. '.
            'Cargá la autorización nueva en Facturación y volvé a cobrar. '.
            'Si hoy hay que cobrar igual y todavía no llega la autorización, apagá esa '.
            'facturación: el desarrollo vuelve a emitir recibo interno hasta que la enciendas.'
        );
    }

    /**
     * El error que este proyecto no se puede dar el lujo de cometer, otra vez.
     *
     * Es palabra por palabra el de CorrelativoInvalidoException, y esta
     * repetido a proposito: son dos series distintas, con dos servicios
     * distintos, y el dia que alguien agregue un tercero tiene que encontrar
     * el guard escrito en su idioma, no una referencia a otro archivo.
     */
    public static function porFaltarTransaccion(): self
    {
        return new self(
            'El correlativo de la factura se pidio fuera de una transaccion. Sin '.
            'transaccion, el SELECT ... FOR UPDATE no bloquea nada y dos cobros '.
            'simultaneos se llevan el mismo numero de factura (§8.3.6). Envolver la '.
            'operacion completa en DB::transaction() y volver a llamar desde adentro.'
        );
    }
}
