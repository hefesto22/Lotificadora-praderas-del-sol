<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Las series de numeros que el sistema consume y jamas repite (§8.3.6).
 *
 * Son dos, y su alcance es distinto a proposito:
 *
 * - CONTRATO va POR PROYECTO. El numero se ve `RPS-2026-0001`, donde `RPS`
 *   sale de `proyectos.codigo`. El secuencial **no reinicia cada anio**
 *   (decidido el 3-ago-2026): en 2027 sigue en `RPS-2027-0132`. Asi el
 *   numero de expediente —que es ese mismo secuencial pelado, R7—
 *   identifica a un cliente para siempre y no necesita cargar el anio.
 *
 * - RECIBO_INTERNO es GLOBAL, una sola serie para toda la lotificadora
 *   (R12). Don Elder y don Edwin consumen numeros de la misma secuencia
 *   desde lugares distintos, y por eso el consumo va con `SELECT … FOR
 *   UPDATE` dentro de la transaccion: es la unica forma de que dos cobros
 *   simultaneos no saquen el mismo numero.
 *
 * No hay serie de CAI: la contratante no usa talonario autorizado por el
 * SAR para estos cobros (R10).
 */
enum TipoCorrelativo: string
{
    case Contrato = 'contrato';
    case ReciboInterno = 'recibo_interno';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }

    /**
     * Los tipos cuya serie corre por proyecto.
     *
     * @return list<string>
     */
    public static function valoresPorProyecto(): array
    {
        return [self::Contrato->value];
    }

    /**
     * Los tipos con una sola serie para toda la lotificadora.
     *
     * @return list<string>
     */
    public static function valoresGlobales(): array
    {
        return [self::ReciboInterno->value];
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Contrato      => 'Contrato',
            self::ReciboInterno => 'Recibo interno',
        };
    }

    /**
     * ¿La serie corre por proyecto o es una sola para todo?
     */
    public function esPorProyecto(): bool
    {
        return $this === self::Contrato;
    }
}
