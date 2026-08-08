<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use Stringable;

/**
 * Una tasa de interes ANUAL, exacta, sin un solo float.
 *
 * ═══ POR QUE UN VALUE OBJECT Y NO UN `float` NI UN `Monto` ═══
 *
 * Un `float` esta prohibido en el camino del dinero (§8.3.1) y una tasa
 * multiplica dinero: el error se propaga a las 48 cuotas. Y un `Monto`
 * tampoco sirve —no es plata, es un porcentaje— aunque comparta el mismo
 * cuidado: si `Monto` se pudiera usar como tasa, tarde o temprano alguien
 * sumaria lempiras con por ciento y nadie lo notaria hasta el estado de
 * cuenta.
 *
 * ═══ ESCALA 20, Y NO 12 COMO `Monto` ═══
 *
 * La mensual sale de dividir dos veces —entre 100 y entre 12— y `bcdiv`
 * TRUNCA, no redondea. Con 10 % anual la mensual es 0.00833333... periodica:
 * a escala 12 el corte deja un error que despues se multiplica por el saldo y
 * por 48 periodos. Con escala 20 el arrastre queda muy por debajo del centavo
 * en cualquier plazo que este sistema admita (600 meses).
 *
 * ═══ EL TOPE ES DE CORDURA, NO LEGAL ═══
 *
 * `MAXIMA` frena un error de digitacion —un 1200 donde iba 12.00—, no dice
 * que 119 % sea legal. **El tope legal hay que verificarlo con un abogado**:
 * en Honduras la Ley de Creditos Usurarios (Decreto 100-62) no fija un
 * numero en su texto, sino que delega en la Secretaria de Finanzas el maximo
 * no bancario, y ademas habla de contratos de PRESTAMO —no de compraventa a
 * plazo—, asi que ni siquiera esta claro que aplique. Poner una tasa mal no
 * es un bug: es una clausula impugnable.
 */
final readonly class TasaDeInteres implements Stringable
{
    /**
     * Escala de trabajo. Ver el docblock: no es la de `Monto` a proposito.
     */
    public const int ESCALA = 20;

    /** Decimales con los que la tasa sale a la base y a la pantalla. */
    public const int DECIMALES = 3;

    /** Tope de cordura. No es el tope legal; ver el docblock. */
    public const string MAXIMA = '120';

    /**
     * El porcentaje anual. `12` significa 12 %, no 1200 %.
     *
     * @var numeric-string
     */
    public string $anual;

    /**
     * `string|int` y nunca `float`, por la misma razon que `Monto`: pasarle
     * un float bajo strict_types es un TypeError, que es justo lo que se
     * quiere. Postgres devuelve NUMERIC como string via PDO.
     */
    public function __construct(string|int $anual)
    {
        $normalizado = $this->normalizar((string) $anual);

        if (bccomp($normalizado, '0', self::ESCALA) < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'tasa_interes_anual',
                valor: $normalizado,
                razon: 'La tasa no puede ser negativa.'
            );
        }

        if (bccomp($normalizado, self::MAXIMA, self::ESCALA) > 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'tasa_interes_anual',
                valor: $normalizado,
                razon: 'Supera el '.self::MAXIMA.' % anual. Reviselo: casi siempre es un punto decimal de mas.'
            );
        }

        $this->anual = $normalizado;
    }

    public static function cero(): self
    {
        return new self('0');
    }

    /**
     * La que viene de la base, que puede ser NULL en las filas viejas.
     */
    public static function deBase(mixed $valor): self
    {
        return new self(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    public function esCero(): bool
    {
        return bccomp($this->anual, '0', self::ESCALA) === 0;
    }

    /**
     * La tasa MENSUAL, como fraccion. 12 % anual devuelve 0.01.
     *
     * ═══ NOMINAL ÷ 12, Y NO LA EFECTIVA EQUIVALENTE ═══
     *
     * La efectiva —`(1+anual)^(1/12) − 1`— es mas correcta y **no la usa
     * nadie** en el rubro: todo el mercado hondureño de lotificaciones, y los
     * bancos, dicen «12 % anual» y dividen entre doce. Sobre L 700,000 a 48
     * meses la diferencia son L 18,433.68 contra L 17,930 de cuota, y el
     * cliente que compare con su banco espera la primera.
     *
     * Que el contrato lo diga con todas las letras: «12 % anual sobre saldos,
     * equivalente a 1 % mensual».
     *
     * @return numeric-string
     */
    public function mensual(): string
    {
        /** @var numeric-string $mensual */
        $mensual = bcdiv(bcdiv($this->anual, '100', self::ESCALA), '12', self::ESCALA);

        return $mensual;
    }

    /**
     * La tasa DIARIA, como fraccion, para la mora prorrateada por dias.
     *
     * Sobre 365 y no sobre 360: el año comercial de 360 dias es una herencia
     * de cuando esto se calculaba a mano, y hoy solo sirve para que el cliente
     * que rehaga la cuenta con el calendario encuentre una diferencia que
     * nadie le puede explicar.
     *
     * @return numeric-string
     */
    public function diaria(): string
    {
        /** @var numeric-string $diaria */
        $diaria = bcdiv(bcdiv($this->anual, '100', self::ESCALA), '365', self::ESCALA);

        return $diaria;
    }

    /**
     * La tasa como fraccion anual. 12 % devuelve 0.12.
     *
     * @return numeric-string
     */
    public function fraccion(): string
    {
        /** @var numeric-string $fraccion */
        $fraccion = bcdiv($this->anual, '100', self::ESCALA);

        return $fraccion;
    }

    /**
     * Tal como va a la columna NUMERIC(6,3).
     */
    public function paraBase(): string
    {
        return $this->redondeada();
    }

    /**
     * Half-up, igual que `Monto::redondeado()`: `bcadd` trunca, asi que el
     * ajuste va sumado antes de cortar.
     */
    public function redondeada(int $decimales = self::DECIMALES): string
    {
        /** @var numeric-string $ajuste */
        $ajuste = bcdiv('5', bcpow('10', (string) ($decimales + 1), self::ESCALA), self::ESCALA);

        return bcadd(bcadd($this->anual, $ajuste, self::ESCALA), '0', $decimales);
    }

    /**
     * Como se lee en pantalla: «12 %», «12.5 %», «0 %».
     *
     * Sin ceros de relleno: una tasa de 12 % escrita «12.000 %» se lee como
     * si la precision importara, y no importa.
     */
    public function formateada(): string
    {
        $texto = $this->redondeada();

        if (str_contains($texto, '.')) {
            $texto = rtrim(rtrim($texto, '0'), '.');
        }

        return ($texto === '' ? '0' : $texto).' %';
    }

    public function __toString(): string
    {
        return $this->formateada();
    }

    public function igualA(self $otra): bool
    {
        return bccomp($this->anual, $otra->anual, self::ESCALA) === 0;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Misma regla que `Monto::normalizar()`: notacion simple, sin separador
     * de miles ni cientifica. La regex es la regla; `is_numeric` es lo que
     * deja a PHPStan estrechar el tipo a `numeric-string`, que es lo que
     * exige bcmath.
     *
     * @return numeric-string
     */
    private function normalizar(string $valor): string
    {
        $limpio = trim($valor);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $limpio) || ! is_numeric($limpio)) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'tasa_interes_anual',
                valor: $valor,
                razon: 'Debe ser un decimal en notacion simple, sin separador de miles ni notacion cientifica.'
            );
        }

        return bcadd($limpio, '0', self::ESCALA);
    }
}
