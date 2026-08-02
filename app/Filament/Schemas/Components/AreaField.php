<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Campo de área en varas cuadradas, la unidad del negocio (§8.3.7).
 *
 * Cuatro decimales, y por la misma razón que MontoField: sin ->numeric(),
 * porque ese método castea el estado a float y las áreas entran a bcmath
 * junto con el precio para calcular el valor del lote.
 *
 * El sufijo sale de config/lotificadora.php: el símbolo vara² tiene
 * casing significativo y por eso nunca pasa por ->mayusculas() (§10.4).
 *
 * Uso:
 *   AreaField::make('area_varas', 'Área del lote')
 */
final class AreaField
{
    public static function make(string $name, ?string $label = null): TextInput
    {
        $unidad = (string) config('lotificadora.area.unidad_plural', 'varas²');

        return TextInput::make($name)
            ->label($label ?? Str::headline($name))
            ->inputMode('decimal')
            ->step('0.0001')
            ->minValue(0)
            ->suffix($unidad)
            ->placeholder('0.0000')
            ->rules(['numeric', 'decimal:0,4', 'min:0']);
    }
}
