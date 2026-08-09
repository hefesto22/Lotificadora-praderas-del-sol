<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Lo que el desarrollo YA tiene, para el plano publico.
 *
 * ═══ POR QUE UN CATALOGO Y NO TEXTO LIBRE ═══
 *
 * «Agua potable» escrito a mano sale tambien como «Agua Potable», «agua» y
 * «servicio de agua». Con texto libre, la misma lotificadora termina con
 * cuatro formas de decir lo mismo y ninguna se puede filtrar ni traducir.
 *
 * El catalogo tambien resuelve el icono: cada caso trae el suyo, asi que la
 * pagina publica no necesita que nadie elija dibujitos.
 *
 * ⚠️ Esto es del PRODUCTO, no de Praderas del Sol. Cada lotificadora marca
 * los suyos y la seccion no aparece si no marca ninguno — una lista vacia con
 * el titulo puesto se ve peor que no tenerla.
 */
enum ServicioDelProyecto: string
{
    case Agua = 'agua';
    case Electricidad = 'electricidad';
    case Alumbrado = 'alumbrado';
    case Calles = 'calles';
    case Escriturado = 'escriturado';
    case LibreDeGravamen = 'libre_de_gravamen';
    case Financiamiento = 'financiamiento';
    case AreasVerdes = 'areas_verdes';
    case Seguridad = 'seguridad';
    case Drenaje = 'drenaje';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $servicio): string => $servicio->value, self::cases());
    }

    /**
     * Para el CheckboxList del panel.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $servicio) {
            $opciones[$servicio->value] = $servicio->etiqueta();
        }

        return $opciones;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Agua            => 'Agua potable',
            self::Electricidad    => 'Energía eléctrica',
            self::Alumbrado       => 'Alumbrado público',
            self::Calles          => 'Calles conformadas',
            self::Escriturado     => 'Escriturado',
            self::LibreDeGravamen => 'Libre de gravamen',
            self::Financiamiento  => 'Financiamiento propio',
            self::AreasVerdes     => 'Áreas verdes',
            self::Seguridad       => 'Acceso controlado',
            self::Drenaje         => 'Drenaje',
        };
    }

    /**
     * El `d` de un SVG de 24x24, trazo de 1.8 y sin relleno.
     *
     * Va acá y no en la plantilla para que la pagina publica no tenga que
     * conocer los casos: se recorre lo marcado y cada uno se dibuja solo.
     * Son heroicons simplificados a un solo trazo — sin libreria de iconos,
     * que es un archivo mas que bajar en un telefono con mala señal.
     */
    public function trazo(): string
    {
        return match ($this) {
            self::Agua            => 'M12 3.5s6 6.2 6 10.1a6 6 0 1 1-12 0C6 9.7 12 3.5 12 3.5Z',
            self::Electricidad    => 'M13 2 4.5 13.5H11l-1 8.5 8.5-11.5H12l1-8.5Z',
            self::Alumbrado       => 'M9.5 20h5m-4-3h3m-3.7-3.2a5.5 5.5 0 1 1 4.4 0c-.5.3-.7.8-.7 1.4v.3h-3v-.3c0-.6-.2-1.1-.7-1.4Z',
            self::Calles          => 'M12 3v3m0 4.5v3m0 4.5v3M4 21 8 3m12 18L16 3',
            self::Escriturado     => 'M7 3h7l4.5 4.5V21H7V3Zm7 0v5h5M9.5 12.5h5m-5 4h5',
            self::LibreDeGravamen => 'M12 3 4.5 6v6c0 4.6 3.1 8.4 7.5 9.5 4.4-1.1 7.5-4.9 7.5-9.5V6L12 3Zm-2.5 9 2 2 3.5-3.8',
            self::Financiamiento  => 'M3 9.5 12 4l9 5.5M5 10v8m4-8v8m6-8v8m4-8v8M3 21h18',
            self::AreasVerdes     => 'M12 21v-6m0 0c-3.5 0-6-2.4-6-5.5S8.5 4 12 4s6 2.4 6 5.5-2.5 5.5-6 5.5Z',
            self::Seguridad       => 'M7.5 10.5V8a4.5 4.5 0 1 1 9 0v2.5M5.5 10.5h13V21h-13V10.5Z',
            self::Drenaje         => 'M4 8h16M4 8l1.5 12h13L20 8M9 8V4h6v4M10 12v4m4-4v4',
        };
    }
}
