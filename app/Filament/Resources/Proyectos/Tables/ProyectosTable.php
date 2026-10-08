<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Tables;

use App\Domain\Gastos\RegistroDeGastos;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Filament\Resources\Proyectos\Schemas\GastoForm;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Socio;
use App\Support\ProyectoActivo;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §10.7: columnas explícitas, filtros con la misma fuente que el scoping,
 * defaultSort y paginación 25/50/100.
 *
 * Los conteos usan ->counts(), que agrega el withCount a la query: hacerlo
 * con $record->bloques()->count() por fila sería un N+1 de manual.
 */
class ProyectosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Solo el proyecto elegido en el interruptor de la barra; en
            // «Todos», la lista completa (18-sep-2026, traído de Maya).
            //
            // 🔴 Va en la TABLA y no en `getEloquentQuery()`: el resource
            // resuelve con esa consulta el record de TODAS sus páginas, y
            // recortarla ahí hace que el plano o la ficha de un proyecto den
            // 404 en cuanto el elegido es otro. En Maya pasó exactamente eso.
            ->modifyQueryUsing(static fn (Builder $query): Builder => app(ProyectoActivo::class)->recortar($query, 'id'))
            ->columns([
                TextColumn::make('nombre')
                    ->label('Proyecto')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('municipio')
                    ->label('Municipio')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('bloques_count')
                    ->label('Bloques')
                    ->counts('bloques')
                    ->badge()
                    ->color('info')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('lotes_count')
                    ->label('Lotes')
                    ->counts('lotes')
                    ->badge()
                    ->color('success')
                    ->alignCenter()
                    ->sortable(),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),
            ])
            ->recordActions([
                /*
                | Fuera del menú de tres puntos a propósito.
                |
                | El plano es a lo que se entra todos los días —es la
                | pantalla donde se aparta y se vende— así que no debería
                | costar dos clics y adivinar qué hay detrás del menú. Ver,
                | editar y borrar sí van adentro: son ocasionales.
                */
                /*
                 * ⚠️ EL ORDEN ES A PROPÓSITO (8-oct-2026). «Compartir» solo
                 * existe en los proyectos con el plano publicado; si fuera
                 * al final, los botones de cada fila quedarían corridos de
                 * lugar según el proyecto. Primero el que puede faltar, y
                 * «Ver plano» —el de todos los días— siempre en el mismo
                 * sitio, pegado al menú.
                 *
                 * Los dos nuevos van con borde y sin relleno: el único botón
                 * lleno de la fila es el que más se usa.
                 */
                self::compartirElPlano(),

                self::registrarGasto(),

                Action::make('plano')
                    ->label('Ver plano')
                    ->icon(Heroicon::OutlinedMap)
                    ->button()
                    ->color('warning')
                    ->url(fn (Proyecto $record): string => ProyectoResource::getUrl('plano', ['record' => $record])),

                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    // Borrar un proyecto arrastra bloques, lotes y calles
                    // (ver Proyecto::booted). Que el modal lo diga.
                    DeleteAction::make()
                        ->modalDescription('Se borran también sus bloques, sus lotes y sus calles. Si algún lote está apartado o vendido, no se borra nada.'),
                ]),
            ])
            ->defaultSort('nombre')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay proyectos')
            ->emptyStateDescription('Un proyecto agrupa bloques y lotes. Creá el primero para empezar a cargar el residencial.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }

    /**
     * El mismo formulario de la pestaña Gastos, sin entrar a la ficha.
     *
     * Lo pidió el cliente el 8-oct-2026, «para registrarlo más rápido»: el
     * gasto se anota cuando llega la factura, y abrir el proyecto, buscar la
     * pestaña y recién ahí el botón eran tres pasos de más.
     *
     * El formulario es `GastoForm` —el mismo de la pestaña— y el alta pasa
     * por `RegistroDeGastos`, que es la única puerta: el número de
     * comprobante se consume dentro de una transacción.
     *
     * Solo lo ve quien puede registrar un gasto (`Create:Gasto`): el receptor
     * no ve ni la pestaña, y tampoco este botón.
     */
    private static function registrarGasto(): Action
    {
        return Action::make('gasto')
            ->label('Registrar gasto')
            ->icon(Heroicon::OutlinedBanknotes)
            ->button()
            ->outlined()
            ->color('gray')
            ->visible(static fn (): bool => auth()->user()?->can('create', Gasto::class) === true)
            ->modalIcon(Heroicon::OutlinedBanknotes)
            ->modalHeading('Registrar un gasto')
            ->modalDescription(static fn (Proyecto $record): string => (string) $record->getAttribute('nombre'))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Registrar el gasto')
            ->schema(static fn (Proyecto $record): array => GastoForm::componentes($record))
            ->action(static function (Proyecto $record, array $data): void {
                $gasto = resolve(RegistroDeGastos::class)->registrar($record, $data);

                /*
                 * `getRelationValue()` y no `->socio?->`: Larastan declara la
                 * relación no nula y el `?->` se vuelve un error de PHPStan
                 * (§9.B1). `instanceof` estrecha sin discutir.
                 */
                $socio = $gasto->getRelationValue('socio');

                $aQuien = $socio instanceof Socio
                    ? sprintf('cargado a %s', (string) $socio->getAttribute('nombre'))
                    : 'del proyecto';

                Notification::make()
                    ->success()
                    ->title(sprintf('Gasto %s registrado', $gasto->folio()))
                    ->body(sprintf('%s, %s.', $gasto->monto()->formateado(), $aQuien))
                    ->send();
            });
    }

    /**
     * Compartir el plano público: copiar el enlace, mandarlo por WhatsApp o
     * abrirlo.
     *
     * Solo aparece cuando el enlace ABRE —proyecto activo y plano publicado,
     * ver `Proyecto::enlaceDelPlanoPublico()`—. Un botón que comparte un 404
     * es peor que no tener botón.
     *
     * No pide permiso propio: el enlace es público por definición, y quien lo
     * recibe ya lo puede abrir sin entrar al sistema.
     */
    private static function compartirElPlano(): Action
    {
        return Action::make('compartir')
            ->label('Compartir')
            ->icon(Heroicon::OutlinedShare)
            ->button()
            ->outlined()
            ->color('success')
            ->visible(static fn (Proyecto $record): bool => $record->enlaceDelPlanoPublico() !== null)
            ->modalHeading('Compartir el plano')
            ->modalDescription(static fn (Proyecto $record): string => sprintf(
                'La página pública de %s: el plano, las medidas y los lotes disponibles. Se abre sin usuario ni contraseña.',
                (string) $record->getAttribute('nombre'),
            ))
            ->modalIcon(Heroicon::OutlinedShare)
            ->schema(static fn (Proyecto $record): array => [
                TextEntry::make('enlace')
                    ->label('Enlace')
                    ->state((string) $record->enlaceDelPlanoPublico())
                    ->copyable()
                    ->copyMessage('Enlace copiado')
                    ->icon(Heroicon::OutlinedLink),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->extraModalFooterActions(static fn (Proyecto $record): array => [
                Action::make('whatsapp')
                    ->label('Mandar por WhatsApp')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->color('success')
                    ->url(self::enlaceDeWhatsapp($record))
                    ->openUrlInNewTab(),

                Action::make('abrir')
                    ->label('Abrir el plano')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url((string) $record->enlaceDelPlanoPublico())
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * El enlace que abre WhatsApp con el mensaje ya escrito.
     *
     * `wa.me` sin número: WhatsApp pregunta a quién mandarlo, que es lo que
     * se quiere — el mismo plano va a muchos clientes distintos. De usted y
     * no de vos, porque lo lee el cliente de la lotificadora.
     */
    public static function enlaceDeWhatsapp(Proyecto $proyecto): string
    {
        $mensaje = sprintf(
            'Le comparto el plano de %s, con los lotes disponibles: %s',
            (string) $proyecto->getAttribute('nombre'),
            (string) $proyecto->enlaceDelPlanoPublico(),
        );

        return 'https://wa.me/?text='.rawurlencode($mensaje);
    }
}
