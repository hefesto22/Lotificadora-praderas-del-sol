<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Cómo salió la llamada de cobro.
 *
 * ═══ POR QUE CUATRO Y NO UN CHECK ═══
 *
 * Mauricio, 23-ago-2026: «que ahí se vean las personas que llevan cuota
 * atrasada o les toca pago ese día, así evitamos las notificaciones… le
 * llaman de que le toca cuota y marcan que ya se contactaron».
 *
 * Un «ya lo llamé» a secas alcanza para hoy y no sirve mañana: al otro día
 * nadie sabe si el cliente prometió pagar, si el teléfono está malo o si
 * simplemente no atendió. Son tres trabajos distintos —esperar, conseguir
 * otro número, volver a llamar— y la lista del día siguiente se arma
 * distinto en cada caso.
 *
 * Son CUATRO a propósito. Una lista larga de motivos se llena a ojo con el
 * primero que aparece; estos cuatro son los que cambian qué hacer después.
 *
 * ⚠️ `YaPago` NO registra un pago. Es lo que el cliente DICE por teléfono
 * («ya deposité, mañana llevo el papel»). El dinero entra por el recibo, y
 * hasta que entre el expediente sigue debiendo. Por eso saca al cliente de
 * la lista solo hasta mañana: si el papel no llegó, vuelve a aparecer.
 */
enum ResultadoDeGestion: string
{
    case Prometio = 'prometio';
    case NoContesta = 'no_contesta';
    case NumeroMalo = 'numero_malo';
    case YaPago = 'ya_pago';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $resultado): string => $resultado->value, self::cases());
    }

    /**
     * Para el `<select>` del modal.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $resultado) {
            $opciones[$resultado->value] = $resultado->etiqueta();
        }

        return $opciones;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Prometio   => 'Habló y prometió pagar',
            self::NoContesta => 'No contesta',
            self::NumeroMalo => 'Número equivocado o fuera de servicio',
            self::YaPago     => 'Dice que ya pagó',
        };
    }

    /**
     * El rótulo corto, para la columna de la tabla.
     *
     * La etiqueta larga es para elegir —ahí conviene que no quede duda—; en
     * una celda de tabla, al lado de la fecha y el nombre de quien llamó,
     * ocupa el ancho de tres columnas para decir lo mismo.
     */
    public function corta(): string
    {
        return match ($this) {
            self::Prometio   => 'Prometió',
            self::NoContesta => 'No contesta',
            self::NumeroMalo => 'Número malo',
            self::YaPago     => 'Dice que pagó',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Prometio   => 'info',
            self::NoContesta => 'gray',
            self::NumeroMalo => 'danger',
            self::YaPago     => 'success',
        };
    }

    /**
     * ¿Este resultado viene con una fecha prometida?
     *
     * Es la única puerta: la fecha se pide **solo** cuando el cliente
     * prometió algo, y en los otros tres el campo no se dibuja. Un formulario
     * que pide «¿para cuándo?» después de «no contesta» se llena con
     * cualquier cosa, y esa cualquier cosa es la que decide cuándo vuelve el
     * cliente a la lista.
     */
    public function exigePromesa(): bool
    {
        return $this === self::Prometio;
    }
}
