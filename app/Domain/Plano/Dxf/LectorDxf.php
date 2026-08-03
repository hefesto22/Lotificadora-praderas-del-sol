<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Parser de DXF ASCII.
 *
 * Tres decisiones que parecen detalles y son la diferencia entre leer un
 * plano y leer basura:
 *
 * 1. SE LEE ESTRICTAMENTE EN PARES desde el primer byte. La tentacion es
 *    escanear buscando lineas iguales a "0" para encontrar las entidades,
 *    y produce entidades fantasma: hay VALORES que son la cadena "0" —el
 *    color, las banderas, la elevacion— y el escaner toma la linea
 *    siguiente como si fuera un nombre de entidad.
 *
 * 2. SE HACE trim() DEL CODIGO Y DEL VALOR. La spec obliga a escribir los
 *    codigos en un campo de tres caracteres justificado a la derecha
 *    ("  0", " 10", "100"), y los valores numericos tambien vienen
 *    rellenados ("    76"). Sin trim, ningun codigo compara igual.
 *
 * 3. SE ADMITEN CRLF Y LF. AutoCAD escribe CRLF; casi todo lo demas, LF.
 *    Un "\r" pegado al final convierte la capa "LOTES" en "LOTES\r", que
 *    no coincide con nada.
 */
final readonly class LectorDxf
{
    /** Secciones cuyas entidades se conservan. El resto se ignora. */
    private const array SECCIONES_CON_ENTIDADES = ['ENTITIES', 'BLOCKS', 'TABLES'];

    public function leer(string $contenido): ArchivoDxf
    {
        // El BOM de UTF-8 delante del primer codigo lo volveria no numerico.
        $contenido = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $lineas = preg_split("/\r\n|\n|\r/", $contenido);

        if ($lineas === false || count($lineas) < 2) {
            throw ArchivoDxfInvalidoException::porArchivoVacio();
        }

        $total = count($lineas);
        $seccion = null;
        $esperaNombreDeSeccion = false;
        $variable = null;

        /** @var list<EntidadDxf> $entidades */
        $entidades = [];
        /** @var array<string, list<array{int, string}>> $header */
        $header = [];
        /** @var array{tipo: string, seccion: string, tags: list<array{int, string}>}|null $abierta */
        $abierta = null;

        for ($i = 0; $i + 1 < $total; $i += 2) {
            $crudo = trim($lineas[$i]);
            $valor = rtrim($lineas[$i + 1], "\r\n");

            if ($crudo === '' || preg_match('/^-?\d+$/', $crudo) !== 1) {
                // Una linea en blanco al final del archivo es inofensiva.
                if ($crudo === '' && trim($valor) === '') {
                    break;
                }

                throw ArchivoDxfInvalidoException::porParDesalineado($i + 1, $crudo);
            }

            $codigo = (int) $crudo;

            if ($esperaNombreDeSeccion) {
                $esperaNombreDeSeccion = false;

                if ($codigo === 2) {
                    $seccion = trim($valor);

                    continue;
                }
            }

            if ($codigo === 0) {
                if ($abierta !== null) {
                    $entidades[] = new EntidadDxf($abierta['tipo'], $abierta['seccion'], $abierta['tags']);
                    $abierta = null;
                }

                $tipo = trim($valor);

                if ($tipo === 'SECTION') {
                    $esperaNombreDeSeccion = true;

                    continue;
                }

                if ($tipo === 'ENDSEC') {
                    $seccion = null;
                    $variable = null;

                    continue;
                }

                if ($tipo === 'EOF') {
                    break;
                }

                if ($seccion !== null && in_array($seccion, self::SECCIONES_CON_ENTIDADES, true)) {
                    $abierta = ['tipo' => $tipo, 'seccion' => $seccion, 'tags' => []];
                }

                continue;
            }

            if ($seccion === 'HEADER') {
                if ($codigo === 9) {
                    $variable = trim($valor);
                    $header[$variable] ??= [];

                    continue;
                }

                if ($variable !== null) {
                    $header[$variable][] = [$codigo, $valor];
                }

                continue;
            }

            if ($abierta !== null) {
                $abierta['tags'][] = [$codigo, $valor];
            }
        }

        if ($abierta !== null) {
            $entidades[] = new EntidadDxf($abierta['tipo'], $abierta['seccion'], $abierta['tags']);
        }

        if ($entidades === [] && $header === []) {
            throw ArchivoDxfInvalidoException::porArchivoVacio();
        }

        return new ArchivoDxf($entidades, $header);
    }
}
