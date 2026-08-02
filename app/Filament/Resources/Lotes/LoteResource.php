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
use Override;

class LoteResource extends Resource
{
    #[Override]
    protected static ?string $model = Lote::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    #[Override]
    protected static ?string $recordTitleAttribute = 'numero';

    #[Override]
    protected static ?string $modelLabel = 'Lote';

    #[Override]
    protected static ?string $pluralModelLabel = 'Lotes';

    #[Override]
    protected static ?int $navigationSort = 3;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Lotificación';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Lotes';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Lotes';
    }

    /**
     * §10.7 y §12: eager loading con columnas nombradas. La tabla muestra
     * el nombre del proyecto y del bloque en cada fila; sin esto son dos
     * queries por lote, o sea un N+1 sobre 500 filas.
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'proyecto:id,nombre',
            'bloque:id,nombre',
        ]);
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return LoteForm::configure($schema);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return LoteInfolist::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return LotesTable::configure($table);
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['numero'];
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    #[Override]
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
