<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Pagos\CorreccionDeRecibo;
use App\Models\Recibo;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * «Corregir» — lo que se tecleó mal y no es dinero (4-sep-2026).
 *
 * ═══ QUE PIDIO MAURICIO ═══
 *
 * «Que pueda editar los recibos la administradora, solo los recibos.» Salió de
 * un caso real: el recibo RPS-00000022 quedó sin «quién recibió el dinero»
 * —era una PRIMA, y esa puerta no preguntaba hasta el 31-ago— y el corte de
 * caja lo sumaba bajo «Sin usuario». Hubo que entrar por SSH a producción a
 * arreglarlo a mano. Esta acción es para que no vuelva a hacer falta.
 *
 * ═══ POR QUE VIVE ACA Y NO ADENTRO DE `RecibosTable` ═══
 *
 * Por lo mismo que `ImprimirRecibo`: aparece en DOS pantallas —la lista y la
 * ficha del recibo— y la ficha es donde uno está parado cuando descubre el
 * error, porque es lo que abre el link. Copiada en dos lados, la próxima regla
 * que se agregue va a entrar en una sola.
 *
 * ═══ LO QUE ESTE MODAL NO OFRECE ═══
 *
 * El monto, el concepto, la fecha y el correlativo. No es una omisión: está en
 * `CorreccionDeRecibo::CAMPOS` y explicado en `ReciboPolicy`. Para un error de
 * plata sigue estando anular + reemitir, que devuelve el dinero a las cuotas y
 * deja los dos números en la serie.
 *
 * Por eso el modal ABRE mostrando lo que no se puede tocar: quien llega a
 * corregir una referencia tiene que ver, sin preguntar, que el monto no está
 * en juego.
 */
final class CorregirRecibo
{
    public static function accion(): Action
    {
        return Action::make('corregir')
            ->label('Corregir')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(static fn (Recibo $record): bool => auth()->user()?->can('corregir', $record) === true)
            ->modalHeading(static fn (Recibo $record): string => "Corregir el recibo {$record->folio()}")
            ->modalDescription('Solo los datos que no mueven dinero. El monto, el concepto y la fecha '
                .'no se tocan acá: para eso está anular y volver a emitir.')
            ->modalSubmitActionLabel('Guardar la corrección')
            ->modalWidth('lg')
            /*
             * 🔴 EL ESTADO INICIAL VA ACA Y NO EN `default()` DE CADA CAMPO.
             * `fillForm()` ES el estado inicial del formulario: los `default()`
             * no se aplican. Está anotado en `QuienRecibeElDinero` y en
             * `CobrarUnPago`, y ya costó verlo en pantalla dos veces.
             */
            ->fillForm(static fn (Recibo $record): array => [
                'recibido_por'  => $record->getAttribute('recibido_por'),
                'forma_pago'    => self::formaActual($record),
                'referencia'    => $record->getAttribute('referencia'),
                'observaciones' => $record->getAttribute('observaciones'),
            ])
            ->schema([
                Select::make('forma_pago')
                    ->label('Forma de pago')
                    ->options(static fn (): array => self::formasDePago())
                    ->required()
                    ->live()
                    ->native(false),

                /*
                 * La misma pregunta, la misma lista y el mismo default que las
                 * tres puertas que cobran. La ayuda sí cambia: acá no se está
                 * recibiendo dinero, se está arreglando a nombre de quién
                 * quedó el que ya se recibió.
                 */
                QuienRecibeElDinero::campo(
                    'De acá sale el corte de caja del día. Cambialo si el papel quedó a nombre de quien no estuvo en la caja.'
                ),

                /*
                 * Escondida en efectivo, igual que en el modal de cobro: no hay
                 * nada que cruzar contra el banco. Y NO es obligatoria — el
                 * cobro dejó de exigirla el 27-ago-2026, y esta pantalla es
                 * justamente donde se teclea el número que ese día no se tenía.
                 */
                TextInput::make('referencia')
                    ->label('Número de referencia')
                    ->maxLength(60)
                    ->visible(static fn (Get $get): bool => self::exigeReferencia($get))
                    ->helperText('Es lo único que después permite cruzar este recibo contra el estado de cuenta del banco (R11).'),

                Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Lo que salga impreso en el papel la próxima vez que se imprima.'),

                Textarea::make('motivo')
                    ->label('¿Por qué se corrige?')
                    ->required()
                    ->rows(3)
                    ->maxLength(500)
                    ->placeholder('El dinero lo recibió don Elder en la caseta, no doña Rosa')
                    ->helperText('Queda en Registros de actividad con tu usuario, la fecha y el antes y el después. '
                        .'No se guarda en el recibo: el papel del cliente no cambia.'),
            ])
            ->action(function (Recibo $record, array $data): void {
                try {
                    $cambio = app(CorreccionDeRecibo::class)->corregir(
                        $record,
                        $data,
                        is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                    );
                } catch (GrupoOlympoException $error) {
                    // El mensaje del dominio ya está escrito para quien atiende.
                    Notification::make()
                        ->title('No se corrigió')
                        ->body($error->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                if (! $cambio) {
                    Notification::make()
                        ->title('No había nada que cambiar')
                        ->body("El recibo {$record->folio()} quedó igual que estaba.")
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Recibo {$record->folio()} corregido")
                    ->body('El cambio quedó en Registros de actividad con tu motivo. El monto y el número no se tocaron.')
                    ->success()
                    ->send();
            });
    }

    /**
     * La forma de pago que tiene hoy, como string para el `Select`.
     *
     * ⚠️ `getAttribute()` devuelve `mixed` en nivel 7 y el cast lo entrega
     * como enum: sacar el `value` a mano es lo que evita seis errores de
     * PHPStan repartidos por el archivo.
     */
    private static function formaActual(Recibo $recibo): ?string
    {
        $forma = $recibo->getAttribute('forma_pago');

        return $forma instanceof FormaDePago ? $forma->value : null;
    }

    /**
     * ¿La forma elegida en el formulario necesita referencia?
     */
    private static function exigeReferencia(Get $get): bool
    {
        $elegida = $get('forma_pago');

        if (! is_string($elegida)) {
            return false;
        }

        $forma = FormaDePago::tryFrom($elegida);

        return $forma instanceof FormaDePago && $forma->exigeReferencia();
    }

    /**
     * ⚠️ `Select::options()` exige `array<string, string>`: con enums hay que
     * sacar el `value` a mano o no pasa el nivel 7.
     *
     * @return array<string, string>
     */
    private static function formasDePago(): array
    {
        $opciones = [];

        foreach (FormaDePago::cases() as $forma) {
            $opciones[$forma->value] = $forma->etiqueta();
        }

        return $opciones;
    }
}
