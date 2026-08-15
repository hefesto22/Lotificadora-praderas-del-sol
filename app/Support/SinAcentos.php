<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quitarle los acentos a un texto, para BUSCAR con él. Nunca para guardarlo.
 *
 * Lo pidió Mauricio el 13-ago-2026: «ya que todos los clientes se guardan en
 * mayúsculas, no debería haber acentuación, ya que cuando los busco no
 * coinciden». El problema es real —tecleás DIAZ y no aparece DÍAZ— pero la
 * cura no es sacarle la tilde al dato:
 *
 * ⚠️ EL NOMBRE GUARDADO NO SE TOCA. Ese nombre es el que se imprime en el
 * contrato y en la escritura, y «ADELA DIAZ HERNANDEZ» sin tilde es el
 * nombre MAL ESCRITO de una señora en un papel que ella firma. Además no se
 * puede deshacer: una vez guardados sin tilde, ya no hay de dónde sacar
 * cuáles la llevaban. Lo que se dobla es la BÚSQUEDA.
 *
 * ═══ POR QUE VIVE EN DOS LADOS Y TIENEN QUE COINCIDIR ═══
 *
 * De un lado, esta clase dobla lo que la persona teclea. Del otro, la
 * columna generada `nombre_busqueda` guarda el nombre ya doblado, con el
 * mismo `TRANSLATE` escrito en SQL. Las dos listas —{@see self::ACENTOS} y
 * {@see self::LLANAS}— están copiadas iguales en la migración
 * `2026_08_13_210000_buscar_nombres_sin_acentos`, y si alguna vez se le
 * agrega una letra hay que agregarla en los DOS lugares y volver a generar
 * la columna. Es el precio de no depender de la extensión `unaccent`, que
 * exige superusuario para instalarse y no está garantizada en el VPS.
 *
 * ═══ POR QUE UNA COLUMNA Y NO TRANSLATE EN CADA CONSULTA ═══
 *
 * Porque `TRANSLATE(nombre, …) ILIKE …` obliga a recorrer la tabla entera
 * calculando la función fila por fila, y porque metería SQL crudo en cada
 * una de las pantallas que busca por nombre. Con la columna, el buscador de
 * Filament hace el `ILIKE` de siempre contra una columna común: ni una
 * línea de SQL a mano en la interfaz.
 */
final readonly class SinAcentos
{
    /**
     * Las vocales acentuadas del español, más la ñ y la ç del catalán y el
     * portugués —aparecen en apellidos de acá— y la diéresis alemana, que
     * llega en los apellidos de las colonias.
     */
    public const string ACENTOS = 'ÁÀÄÂÉÈËÊÍÌÏÎÓÒÖÔÚÙÜÛÑÇáàäâéèëêíìïîóòöôúùüûñç';

    /** Una por una, en el MISMO orden. Las dos listas miden 44. */
    public const string LLANAS = 'AAAAEEEEIIIIOOOOUUUUNCaaaaeeeeiiiioooouuuunc';

    /**
     * El texto sin acentos. No cambia mayúsculas ni minúsculas: de eso se
     * encarga el `ILIKE`, que ya es insensible a la caja.
     */
    public static function de(string $texto): string
    {
        return strtr($texto, self::mapa());
    }

    /**
     * El par de listas como arreglo, que es lo que `strtr` sabe leer.
     *
     * `mb_str_split` y no `str_split`: cada acentuada ocupa dos bytes en
     * UTF-8, y partir por bytes las rompe a la mitad.
     *
     * @return array<string, string>
     */
    private static function mapa(): array
    {
        /** @var array<int, string> $acentos */
        $acentos = mb_str_split(self::ACENTOS);
        /** @var array<int, string> $llanas */
        $llanas = mb_str_split(self::LLANAS);

        return array_combine($acentos, $llanas);
    }
}
