<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * En qué unidad se mide y se COBRA la superficie de un desarrollo.
 *
 * ═══ POR QUÉ POR PROYECTO Y NO EN LA CONFIG ═══
 *
 * En Honduras se lotifica en varas² por costumbre, pero no siempre: el
 * topógrafo de EL BAMBÚ entregó su plano en metros² y así se vende. Un
 * solo ajuste global obliga a elegir cuál de los dos desarrollos muestra
 * la unidad equivocada, y «equivocada» acá quiere decir impresa en un
 * contrato.
 *
 * ⚠️ ESTO ES UNA ETIQUETA, NO UNA CONVERSIÓN. El área de cada lote se
 * guarda en la columna `area_varas` sea cual sea la unidad; lo que cambia
 * es en qué se midió al importarla y con qué palabra se escribe. Un
 * proyecto en metros² guarda 200.0000 y lee «200.00 m²»; uno en varas²
 * guarda 286.2351 y lee «286.24 varas²». **Cambiar la unidad de un
 * proyecto que ya tiene lotes NO reconvierte ni un número**, y por eso
 * `Proyecto::puedeCambiarLaUnidad()` la traba en cuanto se vendió el
 * primero (decisión de Mauricio, 13-ago-2026).
 *
 * ═══ LAS TRES FORMAS ═══
 *
 * El repo ya escribía la vara de tres maneras según el lugar, y se
 * conservan las tres para no moverle la pantalla a un proyecto que ya
 * está en producción:
 *
 *  - `plural()`     — «varas²»  para el sufijo de una columna, donde sobra lugar
 *  - `abreviada()`  — «vr²»     para una línea de texto o un encabezado
 *  - `corta()`      — «v²»      para el mapa y el carrito, donde no entra nada
 *
 * En metros² las tres son «m²»: no hay abreviatura que inventar.
 */
enum UnidadDeArea: string
{
    case Varas = 'varas';
    case Metros = 'metros';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $unidad): string => $unidad->value, self::cases());
    }

    /**
     * Las opciones del selector, con el valor como clave.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $unidad) {
            $opciones[$unidad->value] = $unidad->etiqueta();
        }

        return $opciones;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Varas  => 'Varas² (la vara castellana, 0.8359 m)',
            self::Metros => 'Metros² (m²)',
        };
    }

    /**
     * «varas²» / «metros²» — el sufijo de una columna.
     */
    public function plural(): string
    {
        return match ($this) {
            self::Varas  => 'varas²',
            self::Metros => 'metros²',
        };
    }

    /**
     * «vr²» / «m²» — dentro de una frase o un encabezado de tabla.
     */
    public function abreviada(): string
    {
        return match ($this) {
            self::Varas  => 'vr²',
            self::Metros => 'm²',
        };
    }

    /**
     * «v²» / «m²» — el mapa y el carrito, donde el lugar se mide en píxeles.
     */
    public function corta(): string
    {
        return match ($this) {
            self::Varas  => 'v²',
            self::Metros => 'm²',
        };
    }

    /**
     * «por vara²» / «por m²» — para hablar del precio.
     *
     * Sin artículo a propósito: «la vara²» y «el m²» no se pueden armar
     * con la misma plantilla, y «por» sirve para los dos.
     */
    public function porUnidad(): string
    {
        return match ($this) {
            self::Varas  => 'por vara²',
            self::Metros => 'por m²',
        };
    }

    /**
     * ¿Hace falta preguntar a cuánto equivale la vara?
     *
     * En metros² no: la unidad del área ES el metro, así que el factor
     * vale uno y la sección «Medidas del plano» del formulario se
     * esconde entera. Ver Proyecto::varaEnMetros().
     */
    public function necesitaFactor(): bool
    {
        return $this === self::Varas;
    }

    /**
     * Cuántos metros mide el lado de una unidad de área de este proyecto.
     *
     * En metros², uno. En varas², null: lo contesta el proyecto con su
     * propio factor —cada topógrafo levanta con la suya— o la config.
     */
    public function ladoEnMetros(): ?string
    {
        return $this === self::Metros ? '1.000000' : null;
    }
}
