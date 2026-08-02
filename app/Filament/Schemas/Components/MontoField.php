<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Componente Filament reutilizable para campos monetarios.
 *
 * Centraliza el formato (prefijo "L."), step de 0.01, validación de
 * no-negativos y placeholder consistente. Evita repetir esta config
 * en cada Form que tenga campos de dinero (§8.4.3).
 *
 * Uso:
 *   MontoField::make('precio_unitario')
 *   MontoField::make('limite_credito', 'Límite de crédito autorizado')
 */
final class MontoField
{
    public static function make(string $name, ?string $label = null): TextInput
    {
        $simbolo = (string) config('honduras.moneda.simbolo', 'L.');

        return TextInput::make($name)
            ->label($label ?? Str::headline($name))
            ->required()
            ->numeric()
            ->minValue(0)
            ->step(0.01)
            ->prefix($simbolo)
            ->placeholder('0.00')
            ->default(0)
            ->rules(['decimal:0,2']);
    }
}
