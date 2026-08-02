<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Campo monetario. El estado NUNCA se convierte a float.
 *
 * ⚠️ NO usar ->numeric() acá. Ese método registra un NumberStateCast
 * (vendor/filament/forms/src/Components/TextInput.php:305) que convierte
 * el estado a int o float antes de que llegue al modelo. El §8.3.1
 * prohíbe float en el camino del dinero, y el guard de Lote::decimalDe()
 * lo detecta lanzando ValueObjectInvalidoException.
 *
 * Se reemplaza por sus tres efectos, sin el cast:
 *   - inputMode('decimal') → teclado numérico en celular, que importa:
 *     los receptores cobran desde el teléfono (§14).
 *   - rule('numeric')      → la misma validación.
 *   - step                 → el mismo control del navegador.
 *
 * Uso:
 *   MontoField::make('precio_vara', 'Precio por vara²')
 */
final class MontoField
{
    public static function make(string $name, ?string $label = null): TextInput
    {
        $simbolo = (string) config('honduras.moneda.simbolo', 'L.');

        return TextInput::make($name)
            ->label($label ?? Str::headline($name))
            ->required()
            ->inputMode('decimal')
            ->step('0.01')
            ->minValue(0)
            ->prefix($simbolo)
            ->placeholder('0.00')
            ->rules(['numeric', 'decimal:0,2', 'min:0']);
    }
}
