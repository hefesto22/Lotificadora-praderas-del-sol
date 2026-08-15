<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Models\AutorizacionDeImpresion;
use App\Models\Facturacion;

/**
 * Cómo va el talonario de una facturación: cuánto le queda y cuánto le falta
 * para vencerse.
 *
 * ═══ POR QUÉ EXISTE ═══
 *
 * El contrato lo pide por nombre —Cláusula Segunda, módulo g-ii: «control de
 * talonario manual y **alertas de agotamiento**»— y hasta el 14-ago-2026 el
 * sistema sabía calcularlo pero solo lo dibujaba **adentro** de la pantalla
 * de Facturación, donde nadie entra dos veces al año.
 *
 * Eso no es un aviso: es un dato esperando a que alguien lo busque. El día
 * que el rango se agota, quien se entera es la persona que está cobrando, con
 * un cliente enfrente y una excepción en la pantalla.
 *
 * ═══ LOS TRES ESTADOS ═══
 *
 *  1. **Tranquilo** — hay autorización vigente y le sobra tiempo y números.
 *     No se dice nada: un aviso que aparece siempre se deja de leer.
 *  2. **Conviene renovar** — quedan 60 días o menos, o 50 documentos o menos.
 *     Los 60 días no son un número inventado: es la ventana en la que el
 *     reglamento deja pedir la siguiente («dentro de los dos (2) meses
 *     previos a la fecha límite de emisión», Acuerdo 481-2017, Art. 59).
 *     Avisar antes es ruido; avisar después, tarde.
 *  3. **No puede emitir** — no hay ninguna autorización que sirva: o se
 *     vencieron todas, o se agotaron los rangos. Acá ya no es un aviso, es un
 *     paro: la próxima venta de ese desarrollo se planta.
 *
 * ⚠️ Una facturación APAGADA no avisa nada. Apagada significa «esta ya no
 * emite», así que su talonario no le importa a nadie.
 */
final readonly class EstadoDelTalonario
{
    private function __construct(
        public Facturacion $facturacion,
        public ?AutorizacionDeImpresion $autorizacion,
        public int $documentos,
        public int $dias,
    ) {}

    public static function de(Facturacion $facturacion): self
    {
        $vigente = $facturacion->autorizacionVigente();

        return new self(
            facturacion: $facturacion,
            autorizacion: $vigente,
            documentos: $vigente?->quedanDocumentos() ?? 0,
            dias: $vigente?->diasParaVencer() ?? 0,
        );
    }

    /**
     * Todas las facturaciones encendidas que hay que mirar hoy.
     *
     * Devuelve solo las que tienen algo que decir: si están todas tranquilas,
     * la lista viene vacía y el aviso no se dibuja.
     *
     * @return list<self>
     */
    public static function lasQueAvisan(): array
    {
        $avisos = [];

        foreach (Facturacion::query()->where('activa', true)->orderBy('nombre')->get() as $facturacion) {
            $estado = self::de($facturacion);

            if ($estado->hayQueAvisar()) {
                $avisos[] = $estado;
            }
        }

        return $avisos;
    }

    public function hayQueAvisar(): bool
    {
        if (! $this->autorizacion instanceof AutorizacionDeImpresion) {
            return true;
        }

        return $this->autorizacion->convieneRenovar();
    }

    /**
     * ¿Ya no se puede emitir? Esto no es un aviso, es un paro.
     */
    public function esUnParo(): bool
    {
        return ! $this->autorizacion instanceof AutorizacionDeImpresion;
    }

    /**
     * El renglón grande: lo que primero se va a acabar.
     *
     * Entre el tiempo y los números manda **el que llegue antes**, porque es
     * el que va a cortar la emisión. Decir «vence en 45 días» cuando quedan
     * tres facturas sería exacto y sin embargo inútil.
     */
    public function titular(): string
    {
        if ($this->esUnParo()) {
            return 'No se puede facturar';
        }

        if ($this->documentos <= AutorizacionDeImpresion::DOCUMENTOS_DE_AVISO && $this->documentos <= $this->dias) {
            return $this->documentos === 1
                ? 'Queda 1 factura'
                : sprintf('Quedan %d facturas', $this->documentos);
        }

        if ($this->dias <= 0) {
            return 'La CAI vence hoy';
        }

        return $this->dias === 1
            ? 'La CAI vence mañana'
            : sprintf('La CAI vence en %d días', $this->dias);
    }

    /**
     * El renglón chico: qué hacer, no qué pasa.
     */
    public function detalle(): string
    {
        if ($this->esUnParo()) {
            return 'Se venció la CAI o se agotó el rango. La próxima venta de este desarrollo se va a plantar: hay que pedirle otra autorización al SAR.';
        }

        return sprintf(
            'Quedan %d facturas y %d días. Se puede pedir la siguiente desde ya.',
            $this->documentos,
            max(0, $this->dias),
        );
    }

    public function color(): string
    {
        return $this->esUnParo() ? 'danger' : 'warning';
    }

    public function nombre(): string
    {
        return (string) $this->facturacion->getAttribute('nombre');
    }
}
