<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Que clase de compromiso ata a un cliente con un lote.
 *
 * Son tres y cada uno se corresponde con UN estado de EstadoLote: un
 * apartado deja el lote en `apartado`, una venta en `vendido` y una
 * donacion en `donado`. La correspondencia no es casual y no depende de
 * que nadie se acuerde: `estadoDelLote()` es lo que
 * `RegistroDeCompromisos::crear()` usa para mover el lote, asi que un tipo
 * nuevo NO COMPILA sin decidir en que estado lo deja. Ademas hay un test
 * que recorre los tres.
 *
 * ═══ LA DONACION SE AGREGO EL 12-AGO-2026 ═══
 *
 * La pidio Mauricio. Venia de la planilla que va a llenar la contratante:
 * el desplegable de «tipo de operacion» ofrecia DONACION y el sistema solo
 * sabia apartar y vender, asi que era una promesa que despues no se iba a
 * poder cumplir al momento de subir el archivo.
 *
 * Lo que la hace distinta de una venta no es el monto —una venta puede ser
 * de contado y quedar saldada el mismo dia—, sino que **nunca hubo dinero**:
 * no lleva prima, ni plan de cuotas, ni recibos, ni cartera. Por eso no pasa
 * por `RegistroDeVentas::activar()`, que existe justamente para armar todo
 * eso.
 */
enum TipoCompromiso: string
{
    case Apartado = 'apartado';
    case Venta = 'venta';
    case Donacion = 'donacion';

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
            self::Apartado => 'Apartado',
            self::Venta    => 'Venta',
            self::Donacion => 'Donación',
        };
    }

    /**
     * En que estado queda el lote mientras este compromiso este vigente.
     *
     * ⚠️ Esto NO es informativo: es lo que escribe el estado del lote. Antes
     * cada metodo del Service ponia el suyo a mano —`apartar()` escribia
     * `Apartado` y `vender()` escribia `Vendido`— y este match existia al
     * lado sin que nadie lo llamara. Dos fuentes para la misma verdad, y la
     * que mandaba era la que estaba lejos de donde se decide.
     */
    public function estadoDelLote(): EstadoLote
    {
        return match ($this) {
            self::Apartado => EstadoLote::Apartado,
            self::Venta    => EstadoLote::Vendido,
            self::Donacion => EstadoLote::Donado,
        };
    }

    /**
     * ¿Se puede soltar el lote sin rescindir un contrato?
     *
     * Un apartado se libera y listo. Una venta no: el §8.2 congela el
     * valor y deshacerla es una rescision, que es otro tramite y
     * probablemente otra conversacion con la contratante.
     *
     * Una donacion tampoco pasa por aca, y no es lo mismo: para cuando una
     * donacion de verdad quedo registrada, lo normal es que la escritura ya
     * este firmada a nombre de otro, y eso no se deshace con un boton.
     *
     * Lo que SI existe desde el 13-ago-2026 es corregir un REGISTRO
     * equivocado —«se marcaron cinco y solo eran tres»— y vive en
     * `RegistroDeCompromisos::deshacerDonacion()`, aparte a proposito: no
     * comparte camino con `liberar()` porque liberar sabe de señas que hay
     * que devolver, y una donacion nunca movio un lempira.
     */
    public function seLibera(): bool
    {
        return $this === self::Apartado;
    }
}
