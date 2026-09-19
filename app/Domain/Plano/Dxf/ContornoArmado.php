<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Un contorno cerrado que NO venia dibujado como tal: lo armo
 * ArmadorDeContornos siguiendo lineas sueltas.
 *
 * Ademas de los puntos guarda los NODOS del grafo por los que pasa, y de
 * ahi sale lo unico que un PoligonoDxf no puede contestar: con quien
 * comparte lindero. Dos contornos armados del mismo grafo que pasan por
 * el mismo par de nodos comparten ese lado -exactamente, sin tolerancias-
 * porque el lado es UNA arista del grafo y no dos dibujos parecidos.
 */
final readonly class ContornoArmado
{
    /**
     * @param list<array{float, float}> $puntos en sentido antihorario
     * @param list<int> $nodos el nodo del grafo de cada punto, en el mismo orden
     */
    public function __construct(
        public array $puntos,
        public array $nodos,
    ) {}

    public function area(): float
    {
        return GeometriaPlana::area($this->puntos);
    }

    public function contiene(float $x, float $y): bool
    {
        return GeometriaPlana::contiene($this->puntos, $x, $y);
    }

    /**
     * Los lados del contorno, sin direccion: "12-57" es el mismo lindero
     * visto desde cualquiera de los dos lotes que separa.
     *
     * @return array<string, true>
     */
    public function linderos(): array
    {
        $linderos = [];

        foreach ($this->aristas() as [$desde, $hacia]) {
            $linderos[min($desde, $hacia).'-'.max($desde, $hacia)] = true;
        }

        return $linderos;
    }

    /**
     * Este contorno y el otro fundidos en uno, borrando el lindero que
     * comparten. Null si no comparten ninguno o si lo que queda no es UN
     * contorno simple -un hueco, o dos pedazos unidos por una esquina-.
     *
     * Existe por la linea sobrante: un trazo que el dibujante dejo cruzando
     * un lote lo parte en dos caras. Quien decide que dos mitades son un
     * lote NO es este metodo -ver LotesDeLineasSueltas-; aca solo esta la
     * geometria de fundirlas.
     *
     * Los dos contornos giran en el mismo sentido, asi que el lindero
     * compartido aparece en uno como a→b y en el otro como b→a. Se quitan
     * esos pares y lo que queda se encadena.
     */
    public function unidoCon(self $otro): ?self
    {
        $mias = $this->aristasPorClave();
        $suyas = $otro->aristasPorClave();

        /** @var array<int, int> $siguiente */
        $siguiente = [];
        $inicio = null;
        $quitadas = 0;

        foreach ([[$mias, $suyas], [$suyas, $mias]] as [$propias, $ajenas]) {
            foreach ($propias as [$desde, $hacia]) {
                if (isset($ajenas[$hacia.'>'.$desde])) {
                    $quitadas++;

                    continue;
                }

                // Dos salidas del mismo nodo: los pedazos se tocan en una
                // esquina y el resultado no es un contorno simple.
                if (isset($siguiente[$desde])) {
                    return null;
                }

                $siguiente[$desde] = $hacia;
                $inicio ??= $desde;
            }
        }

        if ($inicio === null || $quitadas === 0 || count($siguiente) < 3) {
            return null;
        }

        $punto = [];

        foreach ([$this, $otro] as $contorno) {
            foreach ($contorno->nodos as $i => $nodo) {
                $punto[$nodo] = $contorno->puntos[$i];
            }
        }

        $nodos = [];
        $puntos = [];
        $actual = $inicio;

        for ($paso = 0, $tope = count($siguiente); $paso < $tope; $paso++) {
            if (! isset($siguiente[$actual], $punto[$actual])) {
                return null;
            }

            $nodos[] = $actual;
            $puntos[] = $punto[$actual];
            $actual = $siguiente[$actual];
        }

        // Si al agotar las aristas no se volvio al principio, quedaban dos
        // ciclos -un hueco- y no uno.
        return $actual === $inicio ? new self($puntos, $nodos) : null;
    }

    /**
     * Los puntos del contorno sin los que caen sobre un lado recto.
     *
     * En un contorno armado sobran vertices: donde el lote de atras tiene
     * su esquina, el lado de este lote queda partido en dos tramos
     * alineados. Para el grafo ese nodo es indispensable -es lo que hace
     * que los dos lotes compartan lindero-; para el dibujo es ruido, y en
     * el croquis un lado de 15 m saldria acotado como 7.50 + 7.50.
     *
     * @return list<array{float, float}>
     */
    public function puntosSinAlineados(float $holgura): array
    {
        $total = count($this->puntos);

        if ($total <= 3) {
            return $this->puntos;
        }

        $limpios = [];

        for ($i = 0; $i < $total; $i++) {
            $antes = $limpios === [] ? $this->puntos[$total - 1] : $limpios[count($limpios) - 1];
            $este = $this->puntos[$i];
            $despues = $this->puntos[($i + 1) % $total];

            if (! $this->estaAlineado($antes, $este, $despues, $holgura)) {
                $limpios[] = $este;
            }
        }

        return count($limpios) >= 3 ? $limpios : $this->puntos;
    }

    /**
     * @return list<array{int, int}>
     */
    private function aristas(): array
    {
        $total = count($this->nodos);
        $aristas = [];

        for ($i = 0; $i < $total; $i++) {
            $aristas[] = [$this->nodos[$i], $this->nodos[($i + 1) % $total]];
        }

        return $aristas;
    }

    /**
     * @return array<string, array{int, int}>
     */
    private function aristasPorClave(): array
    {
        $porClave = [];

        foreach ($this->aristas() as [$desde, $hacia]) {
            $porClave[$desde.'>'.$hacia] = [$desde, $hacia];
        }

        return $porClave;
    }

    /**
     * ¿El punto del medio cae SOBRE el tramo que une a sus dos vecinos?
     *
     * Sobre el tramo y no sobre la recta: una espiga que vuelve sobre si
     * misma esta en la recta y no esta alineada.
     *
     * @param array{float, float} $antes
     * @param array{float, float} $este
     * @param array{float, float} $despues
     */
    private function estaAlineado(array $antes, array $este, array $despues, float $holgura): bool
    {
        $dx = $despues[0] - $antes[0];
        $dy = $despues[1] - $antes[1];
        $largo = ($dx * $dx) + ($dy * $dy);

        if ($largo < 1e-18) {
            return false;
        }

        $t = ((($este[0] - $antes[0]) * $dx) + (($este[1] - $antes[1]) * $dy)) / $largo;

        if ($t <= 0.0 || $t >= 1.0) {
            return false;
        }

        return hypot($este[0] - ($antes[0] + ($t * $dx)), $este[1] - ($antes[1] + ($t * $dy))) < $holgura;
    }
}
