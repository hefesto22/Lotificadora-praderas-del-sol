<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes;

use App\Filament\Resources\Lotes\Pages\CreateLote;
use App\Filament\Resources\Lotes\Pages\EditLote;
use App\Filament\Resources\Lotes\Pages\ListLotes;
use App\Filament\Resources\Lotes\Pages\ViewLote;
use App\Filament\Resources\Lotes\Schemas\LoteForm;
use App\Filament\Resources\Lotes\Schemas\LoteInfolist;
use App\Filament\Resources\Lotes\Tables\LotesTable;
use App\Models\Lote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoteResource extends Resource
{
    protected static ?string $model = Lote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $recordTitleAttribute = 'numero';

    protected static ?string $modelLabel = 'Lote';

    protected static ?string $pluralModelLabel = 'Lotes';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Lotificación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Lotes';
    }

    public static function getBreadcrumb(): string
    {
        return 'Lotes';
    }

    /**
     * §10.7 y §12: eager loading con columnas nombradas. La tabla muestra
     * el nombre del proyecto y del bloque en cada fila; sin esto son dos
     * queries por lote, o sea un N+1 sobre 500 filas.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'proyecto:id,nombre',
            'bloque:id,nombre',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return LoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LotesTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['numero'];
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListLotes::route('/'),
            'create' => CreateLote::route('/create'),
            'view'   => ViewLote::route('/{record}'),
            'edit'   => EditLote::route('/{record}/edit'),
        ];
    }
}
