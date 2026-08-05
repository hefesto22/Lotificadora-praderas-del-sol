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
     * El motivo limpio, o null si lo que vino eran espacios.
     */
    public function motivoLimpio(): ?string
    {
        $motivo = trim($this->motivo ?? '');

        return $motivo === '' ? null : $motivo;
    }
}
