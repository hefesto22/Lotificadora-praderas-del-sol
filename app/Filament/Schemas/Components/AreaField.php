<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Filament\Support\Unidades;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Campo de área en la unidad DEL PROYECTO (§8.3.7).
 *
 * Cuatro decimales, y por la misma razón que MontoField: sin ->numeric(),
 * porque ese método castea el estado a float y las áreas entran a bcmath
 * junto con el precio para calcular el valor del lote.
 *
 * El sufijo ya no sale de la config: desde el 13-ago-2026 la unidad es de
 * cada desarrollo —hay proyectos en varas² y proyectos en metros²— así
 * que se resuelve por closure contra el proyecto del formulario. El
 * símbolo tiene casing significativo y por eso nunca pasa por
 * ->mayusculas() (§10.4).
 *
 * Uso:
 *   AreaField::make('area_varas', 'Área del lote')
 */
final class AreaField
{
    public static function make(string $name, ?string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label ?? Str::headline($name))
            ->inputMode('decimal')
            ->step('0.0001')
            ->minValue(0)
            ->suffix(static fn (Get $get, ?Model $record, ?Component $livewire): string => Unidades::delFormulario($get, $record, $livewire)->plural())
            ->placeholder('0.0000')
            ->rules(['numeric', 'decimal:0,4', 'min:0']);
    }
}
