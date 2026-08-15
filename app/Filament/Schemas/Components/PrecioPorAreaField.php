<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Models\Lote;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * El precio por vara² o por metro². SEIS decimales, no dos.
 *
 * ═══ POR QUÉ NO ES UN MontoField ═══
 *
 * Porque no es dinero: es el FACTOR con el que se calcula el dinero. La
 * lotificadora cobra un precio POR LOTE y el precio de la vara sale de dividir
 * lo cobrado entre lo que mide — 325,000 entre 337.5 vr² da 962.962962…, que
 * no cabe en dos decimales. Está explicado largo en Lote::DECIMALES_DEL_PRECIO
 * y en la migración `precio_vara_con_seis_decimales`.
 *
 * ═══ EL BUG QUE ESTO ARREGLA (14-AGO-2026) ═══
 *
 * La columna guarda seis decimales desde el 11-ago, pero el campo seguía
 * validando con `decimal:0,2`. Un lote a L 2,000.00 el m² vuelve de la base
 * como `2000.000000`, y al abrir la venta desde el plano el formulario
 * rebotaba con «El campo precio por m² debe tener 0-2 cifras decimales»: el
 * sistema rechazaba su propio dato. Mauricio lo encontró vendiendo un lote de
 * El Bambú.
 *
 * ⚠️ **El dinero sigue con dos decimales.** Lo que gana precisión es el
 * factor, nunca el resultado: MontoField no se toca.
 *
 * ═══ POR QUÉ SE MUESTRA RECORTADO ═══
 *
 * `2000.000000` en una casilla no se lee: se cuenta. Los ceros de relleno se
 * quitan al mostrar y se dejan siempre dos decimales, que es como se escribe
 * un precio. Los decimales que SÍ valen no se tocan — 962.962963 sale entero.
 * Recortar es de presentación; lo que se teclea y lo que se guarda pasan
 * enteros.
 *
 * Uso:
 *   PrecioPorAreaField::make('precio_vara', 'Precio por vara²')
 */
final class PrecioPorAreaField
{
    public static function make(string $name, ?string $label = null): TextInput
    {
        $simbolo = (string) config('honduras.moneda.simbolo', 'L.');
        $decimales = Lote::DECIMALES_DEL_PRECIO;

        return TextInput::make($name)
            ->label($label ?? Str::headline($name))
            ->required()
            // Sin ->numeric(): ese método castea el estado a float y el precio
            // entra a bcmath junto con el área (§8.3.1). Ver MontoField.
            ->inputMode('decimal')
            ->step('0.000001')
            ->minValue(0)
            ->prefix($simbolo)
            ->placeholder('0.00')
            ->formatStateUsing(static fn (mixed $state): mixed => self::sinCerosDeRelleno($state))
            ->rules(['numeric', "decimal:0,{$decimales}", 'min:0']);
    }

    /**
     * `2000.000000` → `2000.00`, y `962.962963` se queda como está.
     *
     * Nunca toca la parte entera ni convierte a número: entra texto y sale
     * texto. Un cast a float acá reintroduciría por la ventana el problema que
     * los seis decimales vinieron a resolver.
     */
    private static function sinCerosDeRelleno(mixed $state): mixed
    {
        if (! is_string($state) && ! is_int($state)) {
            return $state;
        }

        $texto = trim((string) $state);

        if (! str_contains($texto, '.')) {
            return $texto;
        }

        [$entera, $decimal] = explode('.', $texto, 2);

        $decimal = rtrim($decimal, '0');

        // Un precio se escribe con dos decimales aunque sean cero: «2000» a
        // secas se lee como un redondeo, y no lo es.
        $decimal = str_pad($decimal, 2, '0');

        return $entera.'.'.$decimal;
    }
}
