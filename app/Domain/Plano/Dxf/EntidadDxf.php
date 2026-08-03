<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Una entidad del DXF con sus pares codigo/valor EN ORDEN.
 *
 * El orden no es un detalle: en LWPOLYLINE el bulge (codigo 42) pertenece
 * al vertice leido ANTES, y solo aparece cuando es distinto de cero. Un
 * arreglo asociativo codigo => valor perderia esa informacion y produciria
 * arcos pegados al vertice equivocado.
 */
final readonly class EntidadDxf
{
    /**
     * @param list<array{int, string}> $tags
     */
    public function __construct(
        public string $tipo,
        public string $seccion,
        public array $tags,
    ) {}

    /**
     * Capa de la entidad, ya sin el prefijo de referencia externa.
     *
     * Las capas que vienen de un xref se guardan como
     * "planta-baja$0$LOTES". Sin quitar ese prefijo, ninguna capa de xref
     * coincidiria nunca con lo que el usuario eligio.
     */
    public function capa(): string
    {
        $capa = $this->primero(8) ?? '0';

        return (string) preg_replace('/^.*\$\d+\$/', '', $capa);
    }

    public function primero(int $codigo): ?string
    {
        foreach ($this->tags as [$suCodigo, $valor]) {
            if ($suCodigo === $codigo) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function todos(int $codigo): array
    {
        $valores = [];

        foreach ($this->tags as [$suCodigo, $valor]) {
            if ($suCodigo === $codigo) {
                $valores[] = $valor;
            }
        }

        return $valores;
    }

    public function numero(int $codigo): ?float
    {
        $valor = $this->primero($codigo);

        return $valor !== null && is_numeric(trim($valor)) ? (float) trim($valor) : null;
    }

    public function entero(int $codigo): ?int
    {
        $valor = $this->primero($codigo);

        return $valor !== null && is_numeric(trim($valor)) ? (int) (float) trim($valor) : null;
    }

    /**
     * ¿Esta encendido ese bit en un codigo de banderas?
     */
    public function tieneBandera(int $codigo, int $bit): bool
    {
        return (($this->entero($codigo) ?? 0) & $bit) === $bit;
    }
}
