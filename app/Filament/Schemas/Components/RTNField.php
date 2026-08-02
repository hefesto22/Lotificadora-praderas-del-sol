<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\ValueObjects\RTN;
use Filament\Forms\Components\TextInput;

/**
 * Componente Filament reutilizable para campos RTN (Honduras).
 *
 * Centraliza máscara, validación y mensaje de ayuda. Evita repetir
 * esta config en cada Form que capture un RTN (§8.4.3).
 *
 * Uso:
 *   RTNField::make()                       // 'rtn', no requerido
 *   RTNField::make('rtn_emisor', true)     // requerido
 */
final class RTNField
{
    public static function make(string $name = 'rtn', bool $required = false): TextInput
    {
        return TextInput::make($name)
            ->label('RTN')
            ->placeholder('08019985012345')
            ->maxLength(RTN::LONGITUD)
            ->minLength(RTN::LONGITUD)
            ->mask(str_repeat('9', RTN::LONGITUD))
            ->rules([
                $required ? 'required' : 'nullable',
                'string',
                'size:'.RTN::LONGITUD,
                'regex:'.RTN::REGEX,
            ])
            ->required($required)
            ->helperText(RTN::LONGITUD.' dígitos numéricos sin guiones ni espacios.');
    }
}
