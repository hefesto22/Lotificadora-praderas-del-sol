<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Estados contractuales de un lote (§8.2).
 *
 * Son EXACTAMENTE estos cuatro. Agregar uno requiere aprobación de la
 * contratante, porque el estado del lote aparece en reportes que ella usa
 * para decidir. Esta lista es la fuente de verdad: la migración genera su
 * CHECK constraint a partir de valores(), así que la base y el código no
 * pueden divergir.
 */
enum EstadoLote: string
{
    case Disponible = 'disponible';
    case Apartado = 'apartado';
    case Vendido = 'vendido';
    case Cancelado = 'cancelado';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Apartado   => 'Apartado',
            self::Vendido    => 'Vendido',
            self::Cancelado  => 'Cancelado',
        };
    }

    /**
     * Color del badge en Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::Disponible => 'success',
            self::Apartado   => 'warning',
            self::Vendido    => 'info',
            self::Cancelado  => 'danger',
        };
    }

    /**
     * Color del relleno en el plano, en hexadecimal.
     *
     * Vive pegado a color() a proposito: son la misma decision vista en
     * dos superficies —el badge de la tabla y el poligono del plano— y
     * separarlas es garantizar que algun dia el lote vendido sea azul en
     * un lado y rojo en el otro.
     *
     * Se sigue el enum, NO la convencion de los portales de venta (donde
     * vendido suele ser rojo). Adentro del panel, rojo significa problema
     * y una venta cerrada no es un problema: es el objetivo.
     */
    public function colorHex(): string
    {
        return match ($this) {
            self::Disponible => '#16a34a',
            self::Apartado   => '#d97706',
            self::Vendido    => '#2563eb',
            self::Cancelado  => '#dc2626',
        };
    }

    /**
     * El relleno con el que el lote se pinta en la vidriera publica.
     *
     * Distinto de `colorHex()` a proposito. Adentro del panel el color es una
     * etiqueta —verde bueno, azul cerrado— y un relleno saturado se lee bien
     * porque quien mira sabe que esta mirando. En el plano publico son 301
     * poligonos pegados uno al lado del otro: con relleno saturado la pagina
     * se ve como un tablero de ajedrez y deja de leerse la FORMA del terreno,
     * que es para lo que el cliente abrio el link. Pastel adentro y saturado
     * en el borde dice lo mismo sin gritar.
     *
     * ⚠️ Vive aca y no en el CSS de `publico/plano.blade.php` porque los usan
     * TRES superficies: ese CSS, la leyenda de la pagina y el PNG que ve
     * WhatsApp. Tres listas de colores que tienen que coincidir terminan no
     * coincidiendo.
     */
    public function relleno(): string
    {
        return match ($this) {
            self::Disponible => '#b8ead0',
            self::Apartado   => '#fbdcab',
            self::Vendido    => '#f7b8b3',
            self::Cancelado  => '#e4e4e7',
        };
    }

    /**
     * El borde, que es lo que de verdad distingue un lote del vecino cuando
     * el plano esta alejado y cada uno mide tres pixeles.
     */
    public function borde(): string
    {
        return match ($this) {
            self::Disponible => '#4eb37e',
            self::Apartado   => '#dfa04a',
            self::Vendido    => '#e0736a',
            self::Cancelado  => '#a1a1aa',
        };
    }

    /**
     * ¿Se explica en la leyenda del plano publico?
     *
     * Cancelado no. Es un estado interno —un apartado que se cayo, una venta
     * rescindida— y para el cliente que mira la vidriera solo significa «no
     * esta a la venta». Nombrarlo invita a preguntar por que, que es una
     * conversacion que no le toca tener a una pagina web.
     */
    public function enLeyendaPublica(): bool
    {
        return $this !== self::Cancelado;
    }

    /**
     * ¿El lote está comprometido con un cliente?
     *
     * Un lote apartado o vendido no puede volver a apartarse ni venderse
     * a otra persona sin pasar antes por una rescisión o un vencimiento.
     */
    public function estaComprometido(): bool
    {
        return $this === self::Apartado || $this === self::Vendido;
    }

    /**
     * ¿Se pueden editar área, precio y valor?
     *
     * §8.2: "Un lote vendido no se edita en precio ni área — el valor que
     * vale es el congelado en venta_lote". La regla se hace cumplir en tres
     * capas: acá, en el modelo Lote y en un trigger de PostgreSQL, para que
     * ni un seeder ni un import ni un tinker puedan saltearla.
     */
    public function permiteEditarValores(): bool
    {
        return $this !== self::Vendido;
    }
}
