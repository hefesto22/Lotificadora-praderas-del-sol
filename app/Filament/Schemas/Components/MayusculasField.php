<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Filament\Forms\Components\TextInput;

/**
 * Campo de texto del dominio que se normaliza a MAYÚSCULAS (§10.4).
 *
 * Dos de las tres defensas del patrón viven acá:
 *  1. el estilo inline muestra el texto en mayúsculas mientras se escribe;
 *  2. `dehydrateStateUsing` lo normaliza con mb_strtoupper UTF-8 al guardar.
 * La tercera es el mutator `Attribute` de cada modelo, que cubre a los
 * seeders, los imports y tinker, que nunca pasan por el formulario.
 *
 * Se usa estilo inline y no la clase `uppercase` de Tailwind: el CSS de
 * Filament está precompilado y una clase que el panel no incluya
 * simplemente no existe ahí (§9.A7).
 *
 * ⚠️ NO aplicar a nombres de personas, correos, contraseñas ni a símbolos
 * con casing significativo como m² o vara².
 *
 * Por qué una factory y no una macro: el §10.4 describe el patrón como
 * un modificador encadenable, pero una macro de Laravel no existe para
 * PHPStan y obligaría a silenciar un method.notFound en CADA uso, en todos
 * los Resources presentes y futuros. El §9.B.6 es explícito en que primero
 * se corrige el código y silenciar es el último recurso. Esta forma además
 * es la que ya usan RTNField, MontoField y TelefonoHondurasField, así que
 * no introduce un patrón nuevo: reutiliza el que el repo ya tenía.
 *
 * (Ojo: no escribir la anotación de PHPStan literalmente en un docblock.
 * El analizador la lee como directiva real e intenta parsearla, y falla
 * con ignore.parseError, que además es non-ignorable. Misma familia que
 * el §9.B.4.)
 *
 * Uso:
 *   MayusculasField::make('codigo')->label('Código')->required()
 */
final class MayusculasField
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->extraInputAttributes(['style' => 'text-transform: uppercase;'])
            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                ? mb_strtoupper($state, 'UTF-8')
                : null);
    }
}
