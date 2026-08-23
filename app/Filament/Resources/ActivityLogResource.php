<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogResource\Pages\ViewActivityLog;
use App\Filament\Support\Menu;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Override;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    #[Override]
    protected static ?string $model = Activity::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    #[Override]
    protected static ?string $modelLabel = 'Registro de Actividad';

    #[Override]
    protected static ?string $pluralModelLabel = 'Registros de Actividad';

    #[Override]
    protected static ?int $navigationSort = 3;

    /**
     * El rótulo del menú se escribe a mano, y no es un capricho.
     *
     * Sin este método Filament arma la etiqueta con `Str::title()` sobre el
     * plural, y `Str::title()` es inglés: capitaliza TODAS las palabras. En
     * el menú salía «Registros De Actividad», con esa `De` que en español no
     * existe y que delata que el rótulo lo escribió una máquina.
     *
     * Cualquier recurso cuyo nombre lleve «de», «del» o «y» necesita lo
     * mismo. Es el motivo por el que `Prospectos` también lo declara.
     */
    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Registros de actividad';
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return Menu::ADMINISTRACION;
    }

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[Override]
    public static function canEdit($record): bool
    {
        return false;
    }

    #[Override]
    public static function canDelete($record): bool
    {
        return false;
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_name')
                    ->label('Tipo')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('subject_type')
                    ->label('Modelo')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('causer.name')
                    ->label('Realizado por')
                    ->placeholder('Sistema')
                    ->searchable()
                    ->icon('heroicon-o-user'),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Tipo de log')
                    ->options(fn () => Activity::query()->distinct()->pluck('log_name', 'log_name')->toArray()),
                SelectFilter::make('subject_type')
                    ->label('Modelo')
                    ->options(fn () => Activity::query()->distinct()
                        ->whereNotNull('subject_type')
                        ->pluck('subject_type')
                        ->mapWithKeys(fn ($type): array => [$type => class_basename($type)])
                        ->toArray()),
                Filter::make('created_at')
                    ->indicateUsing(function (array $data): ?string {
                        if ($data['from'] ?? null) {
                            return 'Desde: '.$data['from'];
                        }

                        return null;
                    })
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalle de Actividad')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('log_name')
                                ->label('Tipo de log')
                                ->badge()
                                ->color('primary'),
                            TextEntry::make('description')
                                ->label('Descripción'),
                            TextEntry::make('subject_type')
                                ->label('Modelo afectado')
                                ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                            TextEntry::make('subject_id')
                                ->label('ID del registro'),
                            TextEntry::make('causer.name')
                                ->label('Realizado por')
                                ->placeholder('Sistema'),
                            TextEntry::make('created_at')
                                ->label('Fecha y hora')
                                ->dateTime('d/m/Y H:i:s'),
                        ]),
                    ]),

                Section::make('Cambios Realizados')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->collapsible()
                    ->schema([
                        Grid::make(2)->schema([
                            /*
                            | ->state() con el $record entero, NO make('attribute_changes.old').
                            |
                            | Con la notacion de punto Filament resuelve un ARRAY y llama a
                            | formatStateUsing UNA VEZ POR ELEMENTO, unidos por coma. El
                            | callback recibia strings sueltos, is_array() daba false y la
                            | pantalla mostraba "—, —" con el dato intacto en la base.
                            */
                            TextEntry::make('valores_anteriores')
                                ->label('Valores anteriores')
                                ->state(fn (Activity $record): ?string => self::comoJson($record->attribute_changes?->get('old')))
                                ->markdown()
                                ->placeholder('Sin datos anteriores'),
                            TextEntry::make('valores_nuevos')
                                ->label('Valores nuevos')
                                ->state(fn (Activity $record): ?string => self::comoJson($record->attribute_changes?->get('attributes')))
                                ->markdown()
                                ->placeholder('Sin datos nuevos'),
                        ]),
                    ]),
            ]);
    }

    /**
     * Bloque de codigo markdown con el diff, o null para que salga el
     * placeholder. Publico porque hay un test que lo llama directo: es la
     * unica parte con logica de toda la pantalla.
     */
    public static function comoJson(mixed $valores): ?string
    {
        $arreglo = match (true) {
            $valores instanceof Collection => $valores->toArray(),
            is_array($valores)             => $valores,
            default                        => [],
        };

        if ($arreglo === []) {
            return null;
        }

        $json = json_encode($arreglo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return null;
        }

        return "```json\n".$json."\n```";
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view'  => ViewActivityLog::route('/{record}'),
        ];
    }
}
