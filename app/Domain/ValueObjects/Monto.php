<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use Stringable;

/**
 * Monto monetario inmutable.
 *
 * Centraliza la validación (no negativos), la aritmética redondeada
 * a 2 decimales y la verificación de moneda compatible. Evita el uso
 * de `float` directo para dinero, que produce errores acumulativos
 * (0.1 + 0.2 != 0.3) y es la fuente del 90% de bugs financieros.
 *
 * Internamente trabaja en CENTAVOS (int) para evitar pérdida de
 * precisión, pero expone `valor` como float para interoperar con
 * Eloquent/casts.
 *
 * Uso:
 *   $subtotal = new Monto(1000.00);
 *   $isv      = $subtotal->aplicarPorcentaje(15);
 *   $total    = $subtotal->sumar($isv);
 */
final readonly class Monto implements Stringable
{
    private const DECIMALES = 2;

    /** Valor en centavos para precisión. */
    private int $centavos;

    public function __construct(
        public float $valor,
        public string $moneda = 'HNL',
    ) {
        if ($valor < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'monto',
                valor: (string) $valor,
                razon: 'No puede ser negativo.'
            );
        }

        if ($moneda === '' || strlen($moneda) !== 3) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'moneda',
                valor: $moneda,
                razon: 'Debe ser código ISO-4217 de 3 letras (ej: HNL, USD).'
            );
        }

        $this->centavos = (int) round($valor * (10 ** self::DECIMALES));
    }

    public static function cero(string $moneda = 'HNL'): self
    {
        return new self(0.0, $moneda);
    }

    public static function deCentavos(int $centavos, string $moneda = 'HNL'): self
    {
        return new self($centavos / (10 ** self::DECIMALES), $moneda);
    }

    public function sumar(self $otro): self
    {
        $this->verificarMismaMoneda($otro);

        return self::deCentavos($this->centavos + $otro->centavos, $this->moneda);
    }

    public function restar(self $otro): self
    {
        $this->verificarMismaMoneda($otro);

        $resultado = $this->centavos - $otro->centavos;

        if ($resultado < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'monto',
                valor: (string) ($resultado / 100),
                razon: 'La resta produciría monto negativo.'
            );
        }

        return self::deCentavos($resultado, $this->moneda);
    }

    public function multiplicarPor(float $factor): self
    {
        if ($factor < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'factor',
                valor: (string) $factor,
                razon: 'No se permite multiplicar por factor negativo (usaría restar()).'
            );
        }

        return new self(round($this->valor * $factor, self::DECIMALES), $this->moneda);
    }

    public function aplicarPorcentaje(float $porcentaje): self
    {
        return $this->multiplicarPor($porcentaje / 100);
    }

    public function esCero(): bool
    {
        return $this->centavos === 0;
    }

    public function mayorQue(self $otro): bool
    {
        $this->verificarMismaMoneda($otro);

        return $this->centavos > $otro->centavos;
    }

    public function igualA(self $otro): bool
    {
        return $this->moneda === $otro->moneda && $this->centavos === $otro->centavos;
    }

    public function formateado(?string $simbolo = null): string
    {
        // Si no se pasa símbolo y existe el container de Laravel con la
        // config de Honduras, lo usamos. Si no, default 'L.' (Lempira).
        // Esto mantiene el Value Object usable sin booteo de Laravel (tests Unit).
        $simbolo ??= function_exists('app') && app()->bound('config')
            ? (string) config('honduras.moneda.simbolo', 'L.')
            : 'L.';

        return $simbolo.' '.number_format($this->valor, self::DECIMALES, '.', ',');
    }

    public function __toString(): string
    {
        return $this->formateado();
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
