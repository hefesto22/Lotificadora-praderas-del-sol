<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\ValueObjects\DNI;
use Filament\Forms\Components\TextInput;

/**
 * Campo de DNI hondureño, hermano de RTNField.
 *
 * Se captura y se guarda en dígitos limpios, sin guiones — la máscara es de
 * 13 nueves, no '9999-9999-99999'. Una máscara con separadores deja los
 * guiones dentro del estado deshidratado y obliga a limpiarlos en cada
 * validación; el formato bonito es cosa de la pantalla que muestra, no del
 * campo que captura.
 */
final class DNIField
{
    public static function make(string $name = 'dni', bool $required = false): TextInput
    {
        return TextInput::make($name)
            ->label('DNI')
            ->placeholder('0801198501234')
            ->maxLength(DNI::LONGITUD)
            ->minLength(DNI::LONGITUD)
            ->mask(str_repeat('9', DNI::LONGITUD))
            ->rules([
                $required ? 'required' : 'nullable',
                'string',
                'size:'.DNI::LONGITUD,
                'regex:'.DNI::REGEX,
            ])
            ->required($required)
            ->helperText(DNI::LONGITUD.' dígitos sin guiones. En los listados aparece como 0801-1985-01234.');
    }
}
