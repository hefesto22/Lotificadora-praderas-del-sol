<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Domain\Enums\TipoDocumento;
use Carbon\CarbonImmutable;

/**
 * El número de una factura, con todo lo que va impreso al lado.
 *
 * ═══ POR QUÉ SE COPIA Y NO SE LEE DE LA AUTORIZACIÓN ═══
 *
 * Porque una factura reimpresa tiene que salir EXACTAMENTE como salió la
 * primera vez. Si el papel leyera la autorización vigente HOY, la copia de
 * una factura de enero saldría con la CAI de la autorización de agosto —y esa
 * factura, la de enero, nunca llevó esa CAI impresa—. Es el mismo criterio
 * del §8.2 con el área y el precio del lote: lo que se imprimió se congela.
 *
 * Es también la única forma de contestar «¿con qué autorización se emitió
 * esta?», que es lo primero que pregunta una fiscalización.
 */
final readonly class NumeroDeFactura
{
    public function __construct(
        public int $facturacionId,
        public int $autorizacionId,
        public string $numero,
        public int $correlativo,
        public string $cai,
        public int $rangoDesde,
        public int $rangoHasta,
        public CarbonImmutable $fechaLimiteEmision,
    ) {}

    /**
     * Las columnas que este número le agrega al recibo.
     *
     * Se devuelve armado y no campo por campo para que los tres lugares que
     * emiten documentos —la seña, la prima y el cobro— no puedan olvidarse
     * de uno: el CHECK `recibos_factura_completa_chk` exige que estén los
     * ocho o ninguno.
     *
     * @return array<string, mixed>
     */
    public function paraElRecibo(): array
    {
        return [
            'tipo_documento'       => TipoDocumento::Factura,
            'facturacion_id'       => $this->facturacionId,
            'autorizacion_id'      => $this->autorizacionId,
            'numero_factura'       => $this->numero,
            'correlativo_factura'  => $this->correlativo,
            'cai'                  => $this->cai,
            'rango_desde'          => $this->rangoDesde,
            'rango_hasta'          => $this->rangoHasta,
            'fecha_limite_emision' => $this->fechaLimiteEmision->toDateString(),
        ];
    }
}
