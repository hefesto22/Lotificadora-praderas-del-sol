<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use Stringable;

/**
 * Documento Nacional de Identificación (Honduras).
 *
 * 13 dígitos. Estructura:
 *   - Posiciones 1-2:   Departamento
 *   - Posiciones 3-4:   Municipio
 *   - Posiciones 5-8:   Año de nacimiento
 *   - Posiciones 9-13:  Correlativo
 *
 * Se guarda LIMPIO —13 dígitos, sin guiones— igual que el RTN, y se formatea
 * al mostrarlo. Guardar el formato visual obligaría a normalizar en cada
 * búsqueda y dejaría entrar dos veces a la misma persona: una con guiones y
 * otra sin ellos, que para el índice único son distintas.
 *
 * Valida estructura, NO identidad: el RNP no publica algoritmo de dígito
 * verificador. El año de nacimiento sí se valida contra un rango sensato,
 * porque un '0000' ahí suele ser un dedazo y no una persona.
 *
 * §8.2 y §13.5: el DNI es PII. No entra a logs, ni a Sentry, ni a exports
 * públicos.
 */
final readonly class DNI implements Stringable
{
    public const string REGEX = '/^\d{13}$/';

    public const int LONGITUD = 13;

    /** El RNP emite desde 1893; el margen alto cubre recién nacidos. */
    private const int ANIO_MINIMO = 1893;

    public function __construct(public string $valor)
    {
        if (! preg_match(self::REGEX, $valor)) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'dni',
                valor: $valor,
                razon: 'Debe tener exactamente 13 dígitos numéricos sin guiones ni espacios.'
            );
        }

        $anio = (int) substr($valor, 4, 4);

        if ($anio < self::ANIO_MINIMO || $anio > (int) date('Y')) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'dni',
                valor: $valor,
                razon: 'El año de nacimiento (posiciones 5 a 8) está fuera de rango.'
            );
        }
    }

    /**
     * Construye desde lo que sea que haya tecleado la persona: con guiones,
     * con espacios, copiado de un Excel. Devuelve null si queda vacío, para
     * que un campo opcional en blanco no reviente.
     */
    public static function desdeEntrada(?string $entrada): ?self
    {
        $limpio = preg_replace('/\D/', '', (string) $entrada) ?? '';

        return $limpio === '' ? null : new self($limpio);
    }

    /** Solo los dígitos, o null. Para mutators de Eloquent. */
    public static function normalizarONull(?string $entrada): ?string
    {
        $limpio = preg_replace('/\D/', '', (string) $entrada) ?? '';

        return $limpio === '' ? null : $limpio;
    }

    public function departamento(): string
    {
        return substr($this->valor, 0, 2);
    }

    public function municipio(): string
    {
        return substr($this->valor, 2, 2);
    }

    public function anioNacimiento(): int
    {
        return (int) substr($this->valor, 4, 4);
    }

    public function correlativo(): string
    {
        return substr($this->valor, 8, 5);
    }

    /** Formato visual del carnet: 0801-1985-01234 */
    public function formateado(): string
    {
        return self::formatearCrudo($this->valor);
    }

    /**
     * Formatea SIN validar. Existe para las pantallas: el constructor exige
     * que el año de nacimiento sea sensato, y una fila vieja que entrara por
     * SQL crudo con un '0000' ahí haría explotar el listado entero al
     * dibujarlo. Una pantalla no debe caerse por un dato malo: lo muestra
     * como está y el CHECK de la migración es el piso que impide que entre
     * cualquier cosa.
     */
    public static function formatearCrudo(string $valor): string
    {
        if (strlen($valor) !== self::LONGITUD) {
            return $valor;
        }

        return sprintf(
            '%s-%s-%s',
            substr($valor, 0, 4),
            substr($valor, 4, 4),
            substr($valor, 8, 5)
        );
    }

    public function igualA(self $otro): bool
    {
        return $this->valor === $otro->valor;
    }

    public function __toString(): string
    {
        return $this->valor;
    }
}
