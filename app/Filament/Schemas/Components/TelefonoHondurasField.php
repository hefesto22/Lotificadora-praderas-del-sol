<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Componente Filament para teléfonos hondureños — 8 dígitos,
 * con prefijo +504 visible y máscara fija.
 *
 * Uso:
 *   TelefonoHondurasField::make('telefono')
 *   TelefonoHondurasField::make('whatsapp', 'WhatsApp', required: true)
 */
final class TelefonoHondurasField
{
    public static function make(string $name = 'telefono', ?string $label = null, bool $required = false): TextInput
    {
        return TextInput::make($name)
            ->label($label ?? Str::headline($name))
            ->prefix('+504')
            ->placeholder('99887766')
            ->maxLength(8)
            ->minLength(8)
            ->mask('99999999')
            ->tel()
            ->rules([
                $required ? 'required' : 'nullable',
                'string',
                'regex:/^[239][0-9]{7}$/',
            ])
            ->required($required)
            ->helperText('8 dígitos. Empieza con 2, 3 o 9.');
    }
}
