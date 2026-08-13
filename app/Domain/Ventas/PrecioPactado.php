<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;

/**
 * Lo que se firmo por UN lote, cuando no es el precio de lista.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * El precio por vara² del lote es el de LISTA: lo fija la administracion
 * por proyecto o por bloque y es lo que se cotiza. Al momento de firmar
 * puede ser otro —la pregunta 4 la contesto la contratante con «se negocia
 * caso por caso»— y ese otro precio es el que va al contrato.
 *
 * Esta clase es el vehiculo de ese acuerdo entre el formulario y el
 * Service. No sabe nada de la base ni de Filament.
 *
 * ═══ LA REGLA VIVE ACA, LOS MENSAJES NO ═══
 *
 * `exigeMotivo()` es R4 en una linea, y la usan los dos Services que
 * pueden bajar un precio: `RegistroDeVentas` —que necesita fallar ANTES de
 * quemar un correlativo— y `RegistroDeCompromisos`, que es el unico que
 * escribe la fila. La regla es una sola; cada uno tira la excepcion que
 * corresponde a su contexto, con el mensaje que le sirve a quien esta
 * atendiendo.
 *
 * La base tiene la misma regla como CHECK. No es redundancia: el CHECK
 * cubre el import, la consola y la pestaña abierta dos veces; esto cubre
 * el mensaje que la persona necesita leer.
 */
final readonly class PrecioPactado
{
    public function __construct(
        public int $loteId,
        public Monto $precioVara,
        public ?string $motivo = null,
        /*
         * ═══ EL PLAZO Y LA PRIMA SON DE ESTE LOTE ═══
         *
         * Desde el 5-ago-2026 un contrato puede llevar el primer lote a 12
         * meses, el segundo a 24 y el tercero a 48. Null significa «el del
         * contrato»: es el caso normal y el que existia antes.
         *
         * La prima null NO es cero. Es «repartime la del contrato»: el
         * Service le da a este lote la parte que le toca segun su valor,
         * porque una cuota no se puede calcular sin saber cuanto se adelanto
         * por ESE lote.
         */
        public ?int $plazoMeses = null,
        public ?Monto $prima = null,

        /*
         * ═══ EL PRECIO DEL DINERO TAMBIEN SE NEGOCIA ═══
         *
         * El vendedor sentado frente al cliente baja medio punto para
         * cerrar, igual que baja el precio de la vara². Null significa «la
         * del plan del plazo elegido», que es el caso normal.
         *
         * Y como bajarla es regalar plata igual que bajar el precio, lleva su
         * propio motivo: no se reusa `motivo`, porque «descuento por pago
         * adelantado de la prima» explica el precio del terreno y no tiene
         * por que explicar el del dinero.
         */
        public ?TasaDeInteres $tasa = null,
        public ?string $motivoTasa = null,

        /*
         * ═══ A NOMBRE DE QUIEN SALEN LOS RECIBOS DE ESTE LOTE ═══
         *
         * Null es el caso normal y quiere decir «a nombre del dueño del
         * expediente». Se llena cuando un grupo compra junto y firma UNA sola
         * persona: el contrato es del representante, pero cada representado
         * tiene SU lote adentro y quiere el papel a su nombre.
         *
         * Texto y no un cliente, por decision de Mauricio (12-ago-2026): en
         * `clientes` van los del expediente, y un representado no compro nada a
         * su nombre. El DNI es opcional.
         */
        public ?string $titularRecibo = null,
        public ?string $dniTitularRecibo = null,
    ) {}

    /**
     * ¿Este precio necesita un motivo escrito para poder grabarse?
     *
     * Solo cuando BAJA del de lista. Vender por encima del precio de lista
     * no necesita justificarse ante nadie.
     */
    public static function exigeMotivo(Monto $lista, Monto $pactado, ?string $motivo): bool
    {
        return $pactado->menorQue($lista) && trim($motivo ?? '') === '';
    }

    /**
     * Lo mismo, para la tasa. Subirla no necesita justificarse ante nadie.
     */
    public static function exigeMotivoDeTasa(TasaDeInteres $lista, TasaDeInteres $pactada, ?string $motivo): bool
    {
        return $pactada->menorQue($lista) && trim($motivo ?? '') === '';
    }

    /**
     * El motivo limpio, o null si lo que vino eran espacios.
     */
    public function motivoLimpio(): ?string
    {
        $motivo = trim($this->motivo ?? '');

        return $motivo === '' ? null : $motivo;
    }

    /**
     * El titular del recibo limpio, o null si lo que vino eran espacios.
     *
     * El CHECK `compromisos_titular_recibo_no_vacio_chk` no admite una cadena
     * en blanco: un nombre de espacios se leeria como «hay titular» y el papel
     * saldria a nombre de nadie.
     */
    public function titularReciboLimpio(): ?string
    {
        $nombre = trim($this->titularRecibo ?? '');

        return $nombre === '' ? null : $nombre;
    }

    /**
     * El DNI del titular del recibo, y solo si hay titular.
     *
     * Un DNI sin nombre no dice nada y la base lo rechaza
     * (`compromisos_dni_sin_titular_chk`).
     */
    public function dniTitularReciboLimpio(): ?string
    {
        if ($this->titularReciboLimpio() === null) {
            return null;
        }

        $dni = trim($this->dniTitularRecibo ?? '');

        return $dni === '' ? null : $dni;
    }

    public function motivoDeTasaLimpio(): ?string
    {
        $motivo = trim($this->motivoTasa ?? '');

        return $motivo === '' ? null : $motivo;
    }
}
