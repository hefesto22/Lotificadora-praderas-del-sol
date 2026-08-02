<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use Stringable;

/**
 * Monto monetario inmutable y EXACTO.
 *
 * Cumple el §8.3.1 del documento rector: bcmath sobre strings, escala
 * interna 12, redondeo half-up SOLO al exponer. No hay un solo `float`
 * en todo el camino del dinero.
 *
 * Por qué no float ni centavos-como-int:
 *
 *  - La versión anterior recibía `float` y multiplicaba con
 *    `round($valor * $factor, 2)`. Medido sobre 300.000 pares realistas
 *    de `area_varas` (4 decimales) x `precio_vara` (2 decimales), eso
 *    producía 42 resultados equivocados por un centavo — 1 de cada 7.143.
 *    Ejemplo real: 613.0405 x 2530.00 daba L 1,550,992.46 cuando el valor
 *    exacto es L 1,550,992.47.
 *  - Un centavo suelto es anecdótico en un lote, pero el motor de cuotas
 *    genera entre 12 y 120 cuotas por venta con prorrateo y residuo de
 *    redondeo a la última (§8.2). Ahí el error se multiplica, y el golden
 *    test del §9.C9 compara al céntimo.
 *  - Centavos como `int` tampoco alcanza: las áreas tienen 4 decimales y
 *    el producto área x precio necesita 6 decimales exactos antes de
 *    redondear a 2.
 *
 * Regla de uso: se opera SIN redondear y se redondea una sola vez, al
 * final, cuando el número sale a la base de datos o a la pantalla.
 *
 *   $valorLote = new Monto('2530.00')
 *       ->multiplicarPor('613.0405')
 *       ->redondeado();          // '1550992.47' — listo para NUMERIC(14,2)
 */
final readonly class Monto implements Stringable
{
    /** Escala interna de trabajo (§8.3.1). */
    private const int ESCALA = 12;

    /** Decimales con los que el dinero sale al mundo. */
    private const int DECIMALES = 2;

    /**
     * Valor exacto, con ESCALA decimales.
     *
     * Es la verdad interna, no el número que se muestra. Para exponer,
     * usar redondeado() o formateado().
     *
     * Tipado `numeric-string` y no `string`: es lo que exigen las
     * funciones de bcmath, y así PHPStan nivel 7 verifica en cada
     * llamada que nadie metió una cadena arbitraria en el dinero.
     *
     * @var numeric-string
     */
    public string $valor;

    /**
     * El constructor acepta string o int a propósito: pasarle un float
     * es un TypeError bajo strict_types, que es justo lo que queremos.
     * Postgres además devuelve NUMERIC como string vía PDO, así que este
     * es el tipo natural.
     */
    public function __construct(string|int $valor, public string $moneda = 'HNL')
    {
        $normalizado = $this->normalizar((string) $valor);

        if (bccomp($normalizado, '0', self::ESCALA) < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'monto',
                valor: $normalizado,
                razon: 'No puede ser negativo.'
            );
        }

        if (strlen($moneda) !== 3) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'moneda',
                valor: $moneda,
                razon: 'Debe ser código ISO-4217 de 3 letras (ej: HNL, USD).'
            );
        }

        $this->valor = $normalizado;
    }

    public static function cero(string $moneda = 'HNL'): self
    {
        return new self('0', $moneda);
    }

    public static function deCentavos(int $centavos, string $moneda = 'HNL'): self
    {
        return new self(bcdiv((string) $centavos, '100', self::ESCALA), $moneda);
    }

    // ─── Aritmética — nunca redondea ──────────────────────────────────

    public function sumar(self $otro): self
    {
        $this->verificarMismaMoneda($otro);

        return new self(bcadd($this->valor, $otro->valor, self::ESCALA), $this->moneda);
    }

    public function restar(self $otro): self
    {
        $this->verificarMismaMoneda($otro);

        $resultado = bcsub($this->valor, $otro->valor, self::ESCALA);

        if (bccomp($resultado, '0', self::ESCALA) < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'monto',
                valor: $resultado,
                razon: 'La resta produciría monto negativo.'
            );
        }

        return new self($resultado, $this->moneda);
    }

    public function multiplicarPor(string|int $factor): self
    {
        $normalizado = $this->normalizar((string) $factor);

        if (bccomp($normalizado, '0', self::ESCALA) < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'factor',
                valor: $normalizado,
                razon: 'No se permite multiplicar por factor negativo (usar restar()).'
            );
        }

        return new self(bcmul($this->valor, $normalizado, self::ESCALA), $this->moneda);
    }

    public function dividirPor(string|int $divisor): self
    {
        $normalizado = $this->normalizar((string) $divisor);

        if (bccomp($normalizado, '0', self::ESCALA) === 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'divisor',
                valor: $normalizado,
                razon: 'No se puede dividir entre cero.'
            );
        }

        return new self(bcdiv($this->valor, $normalizado, self::ESCALA), $this->moneda);
    }

    public function aplicarPorcentaje(string|int $porcentaje): self
    {
        return $this->multiplicarPor($this->normalizar((string) $porcentaje))->dividirPor('100');
    }

    // ─── Exposición — acá y solo acá se redondea ──────────────────────

    /**
     * Valor redondeado half-up, listo para NUMERIC(14,2) o para pantalla.
     */
    public function redondeado(int $decimales = self::DECIMALES): string
    {
        /** @var numeric-string $ajuste */
        $ajuste = bcdiv('5', bcpow('10', (string) ($decimales + 1), self::ESCALA), self::ESCALA);

        return bcadd(bcadd($this->valor, $ajuste, self::ESCALA), '0', $decimales);
    }

    public function enCentavos(): int
    {
        return (int) $this->multiplicarPor('100')->redondeado(0);
    }

    /**
     * Formatea sin pasar por float: number_format() recibe float y volvería
     * a introducir el error que este value object existe para evitar.
     */
    public function formateado(?string $simbolo = null, int $decimales = self::DECIMALES): string
    {
        $simbolo ??= function_exists('app') && app()->bound('config')
            ? (string) config('honduras.moneda.simbolo', 'L.')
            : 'L.';

        $partes = explode('.', $this->redondeado($decimales));
        $entero = strrev(implode(',', str_split(strrev($partes[0]), 3)));

        return $simbolo.' '.$entero.(isset($partes[1]) ? '.'.$partes[1] : '');
    }

    public function __toString(): string
    {
        return $this->formateado();
    }

    // ─── Comparación ──────────────────────────────────────────────────

    public function esCero(): bool
    {
        return bccomp($this->valor, '0', self::ESCALA) === 0;
    }

    public function mayorQue(self $otro): bool
    {
        $this->verificarMismaMoneda($otro);

        return bccomp($this->valor, $otro->valor, self::ESCALA) > 0;
    }

    public function menorQue(self $otro): bool
    {
        $this->verificarMismaMoneda($otro);

        return bccomp($this->valor, $otro->valor, self::ESCALA) < 0;
    }

    public function igualA(self $otro): bool
    {
        return $this->moneda === $otro->moneda
            && bccomp($this->valor, $otro->valor, self::ESCALA) === 0;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Valida el formato decimal y lo lleva a la escala interna.
     *
     * Es el único punto por donde entra un valor al value object, así
     * que acá se establece el invariante `numeric-string` del que
     * depende todo bcmath aguas abajo.
     *
     * @return numeric-string
     */
    private function normalizar(string $valor): string
    {
        $limpio = trim($valor);

        // La regex es la REGLA DE NEGOCIO: notación simple, sin separador
        // de miles ni científica. `is_numeric` no aporta a esa regla —
        // aceptaría '1e3' y '  5 ' — pero sí es la única forma de que
        // PHPStan estreche el tipo a `numeric-string`, que es lo que
        // exige bcmath. Las dos condiciones cumplen funciones distintas
        // y por eso van juntas, en vez de anotar el tipo a mano.
        if (! preg_match('/^-?\d+(\.\d+)?$/', $limpio) || ! is_numeric($limpio)) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'monto',
                valor: $valor,
                razon: 'Debe ser un decimal en notación simple, sin separador de miles ni notación científica.'
            );
        }

        return bcadd($limpio, '0', self::ESCALA);
    }

    private function verificarMismaMoneda(self $otro): void
    {
        if ($this->moneda !== $otro->moneda) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'moneda',
                valor: "{$this->moneda} vs {$otro->moneda}",
                razon: 'No se pueden operar montos de monedas distintas sin conversión explícita.'
            );
        }
    }
}
