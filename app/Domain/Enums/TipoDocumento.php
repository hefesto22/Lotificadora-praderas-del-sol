<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué papel se le entregó al cliente por su dinero.
 *
 * La columna `recibos.tipo_documento` existe desde el 6-ago-2026 con un solo
 * valor posible en la práctica, y su migración lo dejó dicho: «el día que
 * aparezca un talonario autorizado por el SAR se agrega el tipo sin migrar
 * los recibos ya emitidos». Ese día llegó el 14-ago-2026, cuando Mauricio
 * cargó la facturación de INMOBILIARIA MAYA y avisó que «no tomó el rango de
 * facturas».
 *
 * ═══ SON DOS SERIES, NO UNA CON DOS NOMBRES ═══
 *
 * Un recibo interno lleva el correlativo de la lotificadora (R12), que no
 * tiene huecos y sirve para auditar la caja. Una factura lleva ADEMÁS el
 * correlativo de la autorización del SAR, que es otra serie, con otro dueño y
 * otras reglas: la autoriza el SAR por punto de emisión, vence en una fecha y
 * no reinicia al renovarse.
 *
 * Una factura consume las DOS. El número interno es el de la caja —el que
 * busca don Elder al cuadrar el día— y el de dieciséis dígitos es el que el
 * cliente presenta ante el SAR. Que un mismo papel lleve los dos no es
 * duplicación: son dos preguntas distintas hechas por dos personas distintas.
 */
enum TipoDocumento: string
{
    /** Comprobante de caja, sin CAI. No da crédito fiscal. */
    case ReciboInterno = 'recibo_interno';

    /** Factura con CAI, numerada contra una autorización del SAR. */
    case Factura = 'factura';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::ReciboInterno => 'Recibo interno',
            self::Factura       => 'Factura',
        };
    }

    /**
     * La denominación que va IMPRESA arriba del papel.
     *
     * En la factura no es decorativa: el Acuerdo 481-2017, Art. 10, num. 5
     * exige que el documento diga cómo se llama.
     */
    public function denominacion(): string
    {
        return match ($this) {
            self::ReciboInterno => 'Recibo',
            self::Factura       => 'FACTURA',
        };
    }

    /**
     * ¿Este papel sirve ante el SAR?
     *
     * Es lo que decide si abajo va la leyenda «NO VÁLIDO PARA CRÉDITO
     * FISCAL» —texto de la Cláusula Segunda del contrato, módulo g-i— o el
     * bloque con la CAI.
     */
    public function daCreditoFiscal(): bool
    {
        return $this === self::Factura;
    }
}
