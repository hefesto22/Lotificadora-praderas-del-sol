<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bloques;

use App\Filament\Resources\Bloques\Pages\CreateBloque;
use App\Filament\Resources\Bloques\Pages\EditBloque;
use App\Filament\Resources\Bloques\Pages\ListBloques;
use App\Filament\Resources\Bloques\Pages\ViewBloque;
use App\Filament\Resources\Bloques\Schemas\BloqueForm;
use App\Filament\Resources\Bloques\Schemas\BloqueInfolist;
use App\Filament\Resources\Bloques\Tables\BloquesTable;
use App\Models\Bloque;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BloqueResource extends Resource
{
    protected static ?string $model = Bloque::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Bloque';

    protected static ?string $pluralModelLabel = 'Bloques';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Lotificación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Bloques';
    }

    public static function getBreadcrumb(): string
    {
        return 'Bloques';
    }

    public static function form(Schema $schema): Schema
    {
        return BloqueForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BloqueInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BloquesTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['nombre'];
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
            'index'  => ListBloques::route('/'),
            'create' => CreateBloque::route('/create'),
            'view'   => ViewBloque::route('/{record}'),
            'edit'   => EditBloque::route('/{record}/edit'),
        ];
    }
}
