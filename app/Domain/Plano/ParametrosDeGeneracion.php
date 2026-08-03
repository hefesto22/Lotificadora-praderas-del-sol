<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Enums\Numeracion;
use App\Domain\Exceptions\ValueObjectInvalidoException;

/**
 * Lo que hay que saber para dibujar un bloque de lotes rectangulares.
 *
 * Las MEDIDAS son strings y las COORDENADAS son floats, y la diferencia
 * no es capricho:
 *
 *  - `frenteVaras` x `fondoVaras` produce `area_varas`, y esa area entra
 *    despues a `area x precio_vara` = dinero. Va por bcmath, exacta,
 *    igual que todo el §8.3.1.
 *  - `origenX` y `origenY` solo ubican el bloque en el dibujo. Un error
 *    de una millonesima de vara ahi mueve el poligono menos que el ancho
 *    de la linea con que se pinta. Float esta bien y es mas comodo.
 *
 * El generador es para trazados ORTOGONALES. Un bloque irregular se
 * genera aproximado y despues se le arrastran los vertices; no existe un
 * juego de parametros que produzca un plano torcido de verdad.
 */
final readonly class ParametrosDeGeneracion
{
    /**
     * Tope por tanda. No es una limitacion tecnica: es que 1000 lotes de
     * golpe casi siempre son un cero de mas en filas o columnas, y
     * revertirlo a mano cuesta mas que volver a pedirlo bien.
     */
    public const int MAXIMO_POR_TANDA = 1000;

    /** @var numeric-string */
    public string $frenteVaras;

    /** @var numeric-string */
    public string $fondoVaras;

    /** @var numeric-string */
    public string $precioVara;

    /** @var numeric-string */
    public string $separacionFilasVaras;

    /** @var numeric-string */
    public string $separacionColumnasVaras;

    public function __construct(
        public int $filas,
        public int $columnas,
        string $frenteVaras,
        string $fondoVaras,
        string $precioVara,
        public float $origenX = 0.0,
        public float $origenY = 0.0,
        string $separacionFilasVaras = '0',
        string $separacionColumnasVaras = '0',
        public Numeracion $numeracion = Numeracion::Serpentina,
        public int $numeroInicial = 1,
    ) {
        $this->frenteVaras = $this->medidaPositiva('frenteVaras', $frenteVaras);
        $this->fondoVaras = $this->medidaPositiva('fondoVaras', $fondoVaras);
        $this->precioVara = $this->medidaNoNegativa('precioVara', $precioVara);
        $this->separacionFilasVaras = $this->medidaNoNegativa('separacionFilasVaras', $separacionFilasVaras);
        $this->separacionColumnasVaras = $this->medidaNoNegativa('separacionColumnasVaras', $separacionColumnasVaras);

        $this->enteroPositivo('filas', $filas);
        $this->enteroPositivo('columnas', $columnas);
        $this->enteroPositivo('numeroInicial', $numeroInicial);
    }

    public function totalDeLotes(): int
    {
        return $this->filas * $this->columnas;
    }

    /**
     * Area de cada lote, exacta, con los 4 decimales de la columna.
     *
     * Sale de multiplicar frente por fondo con bcmath, NO de medir el
     * poligono. Es la misma regla de siempre vista desde el otro lado: al
     * generar, el rectangulo define el area; al editar el dibujo despues,
     * el area ya cargada manda y el poligono solo avisa si difiere.
     *
     * @return numeric-string
     */
    public function areaPorLoteVaras(): string
    {
        /** @var numeric-string $area */
        $area = bcmul($this->frenteVaras, $this->fondoVaras, 4);

        return $area;
    }

    /**
     * @return numeric-string
     */
    private function medidaPositiva(string $campo, string $valor): string
    {
        $normalizado = $this->numerica($campo, $valor);

        if (bccomp($normalizado, '0', 6) <= 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: $campo,
                valor: $valor,
                razon: 'Debe ser mayor que cero: un lote sin frente o sin fondo no es un lote.'
            );
        }

        return $normalizado;
    }

    /**
     * @return numeric-string
     */
    private function medidaNoNegativa(string $campo, string $valor): string
    {
        $normalizado = $this->numerica($campo, $valor);

        if (bccomp($normalizado, '0', 6) < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: $campo,
                valor: $valor,
                razon: 'No puede ser negativo.'
            );
        }

        return $normalizado;
    }

    /**
     * @return numeric-string
     */
    private function numerica(string $campo, string $valor): string
    {
        if (! is_numeric($valor)) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: $campo,
                valor: $valor,
                razon: 'Debe ser un numero en formato string, por ejemplo "10.5000". '.
                       'El §8.3.1 prohibe float en las medidas que despues multiplican dinero.'
            );
        }

        return $valor;
    }

    private function enteroPositivo(string $campo, int $valor): void
    {
        if ($valor < 1) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: $campo,
                valor: (string) $valor,
                razon: 'Debe ser mayor o igual a 1.'
            );
        }
    }
}
