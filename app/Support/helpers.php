<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Helpers globales — Grupo Olympo
|--------------------------------------------------------------------------
|
| Funciones de formato consistente para usar en toda la aplicación
| (Blade, Filament Tables, Resources, exports, PDFs, notificaciones).
|
| Centralizar el formato evita que cada vista decida cómo mostrar un
| monto o fecha y termine con inconsistencias visuales (§8 — duplicación
| de conocimiento de presentación).
|
*/

use App\Domain\ValueObjects\Monto;
use Carbon\CarbonInterface;

if (! function_exists('lempiras')) {
    /**
     * Formatea un monto como Lempiras hondureños.
     *
     *   lempiras(1234.56)               => "L. 1,234.56"
     *   lempiras(1234.5, decimales: 0)  => "L. 1,235"
     *   lempiras(new Monto(99.99))      => "L. 99.99"
     */
    function lempiras(float|int|Monto $monto, int $decimales = 2): string
    {
        $valor = $monto instanceof Monto ? $monto->valor : (float) $monto;
        $simbolo = (string) config('honduras.moneda.simbolo', 'L.');

        return $simbolo.' '.number_format($valor, $decimales, '.', ',');
    }
}

if (! function_exists('moneda')) {
    /**
     * Formatea con cualquier símbolo monetario.
     *
     *   moneda(1234.56, 'USD')   => "USD 1,234.56"
     *   moneda(1234.56, '$')     => "$ 1,234.56"
     */
    function moneda(float|int|Monto $monto, string $simbolo = 'L.', int $decimales = 2): string
    {
        $valor = $monto instanceof Monto ? $monto->valor : (float) $monto;

        return $simbolo.' '.number_format($valor, $decimales, '.', ',');
    }
}

if (! function_exists('fechaCorta')) {
    /**
     * Formato de fecha corto (numérico) para tablas y listados.
     *
     *   fechaCorta(now())        => "26/04/2026"
     *   fechaCorta(now(), true)  => "26/04/2026 14:30"
     */
    function fechaCorta(?CarbonInterface $fecha, bool $conHora = false): string
    {
        if ($fecha === null) {
            return '—';
        }

        return $conHora
            ? $fecha->format('d/m/Y H:i')
            : $fecha->format('d/m/Y');
    }
}

if (! function_exists('fechaLarga')) {
    /**
     * Formato de fecha legible en español para reportes y PDFs.
     *
     *   fechaLarga(now())        => "26 de abril de 2026"
     *   fechaLarga(now(), true)  => "lunes, 26 de abril de 2026"
     */
    function fechaLarga(?CarbonInterface $fecha, bool $conDiaSemana = false): string
    {
        if ($fecha === null) {
            return '—';
        }

        return $conDiaSemana
            ? $fecha->translatedFormat('l, d \d\e F \d\e Y')
            : $fecha->translatedFormat('d \d\e F \d\e Y');
    }
}

if (! function_exists('fechaHora')) {
    /**
     * Fecha + hora con formato legible.
     *
     *   fechaHora(now())  => "26/04/2026 14:30:15"
     */
    function fechaHora(?CarbonInterface $fecha): string
    {
        return $fecha?->format('d/m/Y H:i:s') ?? '—';
    }
}

if (! function_exists('haceCuanto')) {
    /**
     * Tiempo relativo en español (cuándo ocurrió algo).
     *
     *   haceCuanto(now()->subHours(3))   => "hace 3 horas"
     *   haceCuanto(now()->subDays(2))    => "hace 2 días"
     */
    function haceCuanto(?CarbonInterface $fecha): string
    {
        return $fecha?->diffForHumans() ?? '—';
    }
}

if (! function_exists('porcentaje')) {
    /**
     * Formatea un valor como porcentaje.
     *
     *   porcentaje(0.15)            => "15.00%"
     *   porcentaje(0.157, 1)        => "15.7%"
     *   porcentaje(15, 2, false)    => "15.00%"  (asume que 15 ya es %, no 0.15)
     */
    function porcentaje(float|int $valor, int $decimales = 2, bool $convertirDeFraccion = true): string
    {
        $numero = $convertirDeFraccion ? ((float) $valor * 100) : (float) $valor;

        return number_format($numero, $decimales, '.', '').'%';
    }
}

if (! function_exists('numeroFormato')) {
    /**
     * Formatea un número con separadores de miles (sin símbolo monetario).
     *
     *   numeroFormato(1234567)        => "1,234,567"
     *   numeroFormato(1234.5, 2)      => "1,234.50"
     */
    function numeroFormato(float|int $valor, int $decimales = 0): string
    {
        return number_format((float) $valor, $decimales, '.', ',');
    }
}

if (! function_exists('telefonoHN')) {
    /**
     * Formatea teléfono hondureño con prefijo +504 visible.
     *
     *   telefonoHN('99887766')  => "+504 9988-7766"
     *   telefonoHN(null)        => "—"
     */
    function telefonoHN(?string $telefono): string
    {
        if ($telefono === null || $telefono === '') {
            return '—';
        }

        // Limpia caracteres no numéricos
        $limpio = (string) preg_replace('/\D/', '', $telefono);

        // Si tiene el código de país, lo separamos
        if (str_starts_with($limpio, '504') && strlen($limpio) === 11) {
            $limpio = substr($limpio, 3);
        }

        if (strlen($limpio) !== 8) {
            return $telefono; // formato inválido, devolver crudo
        }

        return sprintf('+504 %s-%s', substr($limpio, 0, 4), substr($limpio, 4, 4));
    }
}
