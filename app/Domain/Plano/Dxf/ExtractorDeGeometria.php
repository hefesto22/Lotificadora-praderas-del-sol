<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Convierte las entidades crudas del DXF en contornos y rotulos.
 *
 * Aca viven las cuatro trampas del formato que rompen los parsers
 * ingenuos. Cada una esta comentada donde se resuelve:
 *
 *  1. En LWPOLYLINE el bulge (42) pertenece al vertice ANTERIOR y solo
 *     aparece cuando no es cero.
 *  2. En POLYLINE las coordenadas NO estan en la entidad sino en las
 *     entidades VERTEX que la siguen, hasta SEQEND.
 *  3. En TEXT, si hay justificacion (72 o 73 distintos de cero), la
 *     posicion real es 11/21 y no 10/20.
 *  4. En MTEXT los trozos del codigo 3 vienen ANTES del codigo 1.
 *
 * Desde el 18-sep-2026 entrega ademas los TRAMOS sueltos del dibujo
 * -segmentos()-, para los planos que no traen los lotes como contornos
 * cerrados. Ver ArmadorDeContornos.
 */
final readonly class ExtractorDeGeometria
{
    /** Bits del codigo 70 de POLYLINE que indican que no es un contorno 2D. */
    private const int POLILINEA_3D = 8;

    private const int MALLA_POLIGONAL = 16;

    private const int MALLA_DE_CARAS = 64;

    /** Bit del codigo 70 de VERTEX: punto de control de spline, no vertice. */
    private const int VERTICE_DE_CONTROL = 16;

    /**
     * @return list<PoligonoDxf>
     */
    public function poligonos(ArchivoDxf $archivo): array
    {
        $entidades = $archivo->delDibujo();
        $total = count($entidades);
        $poligonos = [];

        for ($i = 0; $i < $total; $i++) {
            $entidad = $entidades[$i];

            if ($entidad->tipo === 'LWPOLYLINE') {
                $poligono = $this->desdeLwpolyline($entidad);

                if ($poligono instanceof PoligonoDxf) {
                    $poligonos[] = $poligono;
                }

                continue;
            }

            if ($entidad->tipo !== 'POLYLINE') {
                continue;
            }

            // Trampa 2: los vertices son entidades aparte que siguen a la
            // POLYLINE hasta SEQEND. Los 10/20 de la POLYLINE son un punto
            // ficticio que siempre vale cero.
            $vertices = [];
            $j = $i + 1;

            while ($j < $total && $entidades[$j]->tipo !== 'SEQEND') {
                if ($entidades[$j]->tipo === 'VERTEX') {
                    $vertices[] = $entidades[$j];
                }

                $j++;
            }

            $poligono = $this->desdePolyline($entidad, $vertices);

            if ($poligono instanceof PoligonoDxf) {
                $poligonos[] = $poligono;
            }

            $i = $j;
        }

        return $poligonos;
    }

    /**
     * @return list<RotuloDxf>
     */
    public function rotulos(ArchivoDxf $archivo): array
    {
        $rotulos = [];

        foreach ($archivo->deTipo('TEXT', 'MTEXT') as $entidad) {
            $rotulo = $entidad->tipo === 'TEXT'
                ? $this->desdeText($entidad)
                : $this->desdeMtext($entidad);

            if ($rotulo instanceof RotuloDxf) {
                $rotulos[] = $rotulo;
            }
        }

        return $rotulos;
    }

    /**
     * Todos los tramos rectos del dibujo, vengan de donde vengan.
     *
     * Una LINE es un tramo. Una polilinea -abierta o cerrada- son tantos
     * como lados tenga, y un lado curvo entra teselado igual que en
     * poligonos(). Un ARC suelto tambien: la ochava de una esquina suele
     * dibujarse asi, y sin ella el lote de la esquina no cierra.
     *
     * A diferencia de poligonos(), aca NO se exige que la polilinea este
     * cerrada: el perimetro de una manzana dibujado como polilinea abierta
     * es justo el caso que hay que leer.
     *
     * @return list<SegmentoDxf>
     */
    public function segmentos(ArchivoDxf $archivo): array
    {
        $entidades = $archivo->delDibujo();
        $total = count($entidades);
        $segmentos = [];

        for ($i = 0; $i < $total; $i++) {
            $entidad = $entidades[$i];

            if ($entidad->tipo === 'LINE') {
                $x1 = $entidad->numero(10);
                $y1 = $entidad->numero(20);
                $x2 = $entidad->numero(11);
                $y2 = $entidad->numero(21);

                if ($x1 !== null && $y1 !== null && $x2 !== null && $y2 !== null) {
                    $segmentos[] = new SegmentoDxf($entidad->capa(), $x1, $y1, $x2, $y2);
                }

                continue;
            }

            if ($entidad->tipo === 'ARC') {
                foreach ($this->tramosDeArco($entidad) as $tramo) {
                    $segmentos[] = $tramo;
                }

                continue;
            }

            if ($entidad->tipo === 'LWPOLYLINE') {
                $vertices = $this->verticesDeLwpolyline($entidad);

                foreach ($this->tramos($entidad->capa(), $vertices, $entidad->tieneBandera(70, 1)) as $tramo) {
                    $segmentos[] = $tramo;
                }

                continue;
            }

            if ($entidad->tipo !== 'POLYLINE') {
                continue;
            }

            // Trampa 2, igual que en poligonos(): los vertices siguen a la
            // POLYLINE hasta SEQEND.
            $deVertice = [];
            $j = $i + 1;

            while ($j < $total && $entidades[$j]->tipo !== 'SEQEND') {
                if ($entidades[$j]->tipo === 'VERTEX') {
                    $deVertice[] = $entidades[$j];
                }

                $j++;
            }

            if ($this->esContorno2D($entidad)) {
                $vertices = $this->verticesDePolyline($deVertice);

                foreach ($this->tramos($entidad->capa(), $vertices, $entidad->tieneBandera(70, 1)) as $tramo) {
                    $segmentos[] = $tramo;
                }
            }

            $i = $j;
        }

        return $segmentos;
    }

    private function desdeLwpolyline(EntidadDxf $entidad): ?PoligonoDxf
    {
        return $this->armar(
            $entidad->capa(),
            $this->verticesDeLwpolyline($entidad),
            $entidad->tieneBandera(70, 1),
            'LWPOLYLINE',
            ($entidad->numero(230) ?? 1.0) < 0
        );
    }

    /**
     * @return list<array{x: float, y: float, bulge: float}>
     */
    private function verticesDeLwpolyline(EntidadDxf $entidad): array
    {
        /** @var list<array{x: float, y: float, bulge: float}> $vertices */
        $vertices = [];
        /** @var array{x: float, y: float, bulge: float}|null $abierto */
        $abierto = null;

        /*
         * Trampa 1: se abre un vertice al ver un codigo 10 y se le van
         * asignando el 20 y el 42 que vengan despues. Indexar los bulges
         * en paralelo a los vertices pondria los arcos corridos, porque el
         * 42 solo se escribe cuando es distinto de cero.
         */
        foreach ($entidad->tags as [$codigo, $valor]) {
            $numero = is_numeric(trim($valor)) ? (float) trim($valor) : null;

            if ($codigo === 10 && $numero !== null) {
                if ($abierto !== null) {
                    $vertices[] = $abierto;
                }

                $abierto = ['x' => $numero, 'y' => 0.0, 'bulge' => 0.0];

                continue;
            }

            if ($abierto === null) {
                continue;
            }

            if ($numero === null) {
                continue;
            }

            if ($codigo === 20) {
                $abierto['y'] = $numero;
            } elseif ($codigo === 42) {
                $abierto['bulge'] = $numero;
            }
        }

        if ($abierto !== null) {
            $vertices[] = $abierto;
        }

        return $vertices;
    }

    /**
     * @param list<EntidadDxf> $entidadesVertice
     */
    private function desdePolyline(EntidadDxf $entidad, array $entidadesVertice): ?PoligonoDxf
    {
        if (! $this->esContorno2D($entidad)) {
            return null;
        }

        return $this->armar(
            $entidad->capa(),
            $this->verticesDePolyline($entidadesVertice),
            $entidad->tieneBandera(70, 1),
            'POLYLINE'
        );
    }

    /**
     * Una POLYLINE 3D o una malla comparten el nombre de la entidad y nada
     * mas: no son un contorno en el plano.
     */
    private function esContorno2D(EntidadDxf $entidad): bool
    {
        return array_all([self::POLILINEA_3D, self::MALLA_POLIGONAL, self::MALLA_DE_CARAS], fn (int $bit): bool => ! $entidad->tieneBandera(70, $bit));
    }

    /**
     * @param list<EntidadDxf> $entidadesVertice
     *
     * @return list<array{x: float, y: float, bulge: float}>
     */
    private function verticesDePolyline(array $entidadesVertice): array
    {
        $vertices = [];

        foreach ($entidadesVertice as $vertice) {
            if ($vertice->tieneBandera(70, self::VERTICE_DE_CONTROL)) {
                continue;
            }

            $x = $vertice->numero(10);
            $y = $vertice->numero(20);

            if ($x === null) {
                continue;
            }

            if ($y === null) {
                continue;
            }

            $vertices[] = ['x' => $x, 'y' => $y, 'bulge' => $vertice->numero(42) ?? 0.0];
        }

        return $vertices;
    }

    /**
     * Los lados de una polilinea como tramos sueltos, con las curvas ya
     * teseladas. Cerrada, suma el lado que vuelve al primer vertice.
     *
     * @param list<array{x: float, y: float, bulge: float}> $vertices
     *
     * @return list<SegmentoDxf>
     */
    private function tramos(string $capa, array $vertices, bool $cerrada): array
    {
        $total = count($vertices);

        if ($total < 2) {
            return [];
        }

        $tramos = [];
        $lados = $cerrada ? $total : $total - 1;

        for ($i = 0; $i < $lados; $i++) {
            $actual = $vertices[$i];
            $siguiente = $vertices[($i + 1) % $total];

            $camino = [
                [$actual['x'], $actual['y']],
                ...GeometriaPlana::arcoPorBulge($actual['x'], $actual['y'], $siguiente['x'], $siguiente['y'], $actual['bulge']),
                [$siguiente['x'], $siguiente['y']],
            ];

            for ($paso = 1, $pasos = count($camino); $paso < $pasos; $paso++) {
                $tramos[] = new SegmentoDxf(
                    $capa,
                    $camino[$paso - 1][0],
                    $camino[$paso - 1][1],
                    $camino[$paso][0],
                    $camino[$paso][1],
                );
            }
        }

        return $tramos;
    }

    /**
     * Un ARC suelto, teselado.
     *
     * Los angulos del DXF van en grados y SIEMPRE en sentido antihorario
     * del 50 al 51; si el final es menor que el principio, el arco cruza
     * el cero. Con la extrusion hacia abajo (230 negativo) la entidad esta
     * espejada: el centro cambia de signo en X y los angulos se reflejan.
     *
     * @return list<SegmentoDxf>
     */
    private function tramosDeArco(EntidadDxf $entidad): array
    {
        $centroX = $entidad->numero(10);
        $centroY = $entidad->numero(20);
        $radio = $entidad->numero(40);
        $desde = $entidad->numero(50);
        $hasta = $entidad->numero(51);

        if ($centroX === null || $centroY === null || $radio === null || $desde === null || $hasta === null) {
            return [];
        }

        if (($entidad->numero(230) ?? 1.0) < 0) {
            $centroX = -$centroX;
            [$desde, $hasta] = [180.0 - $hasta, 180.0 - $desde];
        }

        $camino = GeometriaPlana::arco($centroX, $centroY, $radio, $desde, $hasta);
        $tramos = [];

        for ($paso = 1, $pasos = count($camino); $paso < $pasos; $paso++) {
            $tramos[] = new SegmentoDxf(
                $entidad->capa(),
                $camino[$paso - 1][0],
                $camino[$paso - 1][1],
                $camino[$paso][0],
                $camino[$paso][1],
            );
        }

        return $tramos;
    }

    /**
     * @param list<array{x: float, y: float, bulge: float}> $vertices
     */
    private function armar(string $capa, array $vertices, bool $cerrada, string $origen, bool $espejado = false): ?PoligonoDxf
    {
        $total = count($vertices);

        if ($total < 3) {
            return null;
        }

        /*
         * Hay exportadores que no encienden la bandera de cerrada y en su
         * lugar repiten el ultimo vertice igual al primero. Se aceptan los
         * dos casos, y en el segundo se descarta el duplicado para no
         * dejar un segmento de largo cero.
         */
        $primero = $vertices[0];
        $ultimo = $vertices[$total - 1];
        $repiteElPrimero = abs($primero['x'] - $ultimo['x']) < 1e-9
            && abs($primero['y'] - $ultimo['y']) < 1e-9;

        if ($repiteElPrimero) {
            array_pop($vertices);
            $total--;
            $cerrada = true;
        }

        if (! $cerrada || $total < 3) {
            return null;
        }

        $puntos = [];

        for ($i = 0; $i < $total; $i++) {
            $actual = $vertices[$i];
            $siguiente = $vertices[($i + 1) % $total];

            $puntos[] = [$actual['x'], $actual['y']];

            foreach (GeometriaPlana::arcoPorBulge(
                $actual['x'],
                $actual['y'],
                $siguiente['x'],
                $siguiente['y'],
                $actual['bulge']
            ) as $intermedio) {
                $puntos[] = $intermedio;
            }
        }

        return new PoligonoDxf($capa, $puntos, $origen, $espejado);
    }

    private function desdeText(EntidadDxf $entidad): ?RotuloDxf
    {
        $texto = trim($entidad->primero(1) ?? '');

        if ($texto === '') {
            return null;
        }

        /*
         * Trampa 3: el segundo punto de alineacion (11/21) solo tiene
         * sentido si hay justificacion. Los numeros de lote casi siempre se
         * rotulan centrados, asi que leer siempre 10/20 ubicaria mal
         * practicamente todas las etiquetas del plano.
         */
        $justificado = ($entidad->entero(72) ?? 0) !== 0 || ($entidad->entero(73) ?? 0) !== 0;

        $x = $justificado ? $entidad->numero(11) ?? $entidad->numero(10) : $entidad->numero(10);
        $y = $justificado ? $entidad->numero(21) ?? $entidad->numero(20) : $entidad->numero(20);

        if ($x === null || $y === null) {
            return null;
        }

        return new RotuloDxf($entidad->capa(), $texto, $x, $y, $entidad->numero(40) ?? 0.0);
    }

    private function desdeMtext(EntidadDxf $entidad): ?RotuloDxf
    {
        // Trampa 4: los trozos de 250 caracteres del codigo 3 se emiten
        // ANTES del codigo 1, que trae el resto. Quedarse solo con el 1
        // devolveria el final del texto y nada mas.
        $texto = implode('', $entidad->todos(3)).($entidad->primero(1) ?? '');
        $texto = $this->limpiarMtext($texto);

        if ($texto === '') {
            return null;
        }

        $x = $entidad->numero(10);
        $y = $entidad->numero(20);

        if ($x === null || $y === null) {
            return null;
        }

        return new RotuloDxf($entidad->capa(), $texto, $x, $y, $entidad->numero(40) ?? 0.0);
    }

    /**
     * Saca los codigos de formato en linea de un MTEXT.
     *
     * El orden importa: primero se apartan las secuencias que representan
     * un caracter literal, para que los pasos siguientes no las coman.
     */
    private function limpiarMtext(string $texto): string
    {
        $literales = ['\\\\' => "\x01", '\\{' => "\x02", '\\}' => "\x03"];
        $texto = str_replace(array_keys($literales), array_values($literales), $texto);

        $texto = str_replace(['\\P', '\\~'], ["\n", ' '], $texto);

        // Codigos con argumento, terminados en punto y coma.
        $texto = (string) preg_replace('/\\\\[fFHWQTACp][^;]*;/u', '', $texto);

        // Fracciones apiladas: se conserva el contenido.
        $texto = (string) preg_replace_callback(
            '/\\\\S([^;]*);/u',
            static fn (array $partes): string => str_replace(['^', '#'], ['/', '/'], $partes[1]),
            $texto
        );

        // Interruptores sin argumento.
        $texto = (string) preg_replace('/\\\\[LlOoKk]/u', '', $texto);

        // Llaves de agrupacion que quedaron sueltas.
        $texto = str_replace(['{', '}'], '', $texto);

        $texto = str_replace(array_values($literales), ['\\', '{', '}'], $texto);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $texto));
    }
}
