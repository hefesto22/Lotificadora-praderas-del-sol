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
use App\Filament\Support\Menu;
use App\Models\Bloque;
use App\Support\ProyectoActivo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

class BloqueResource extends Resource
{
    #[Override]
    protected static ?string $model = Bloque::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    #[Override]
    protected static ?string $recordTitleAttribute = 'nombre';

    #[Override]
    protected static ?string $modelLabel = 'Bloque';

    #[Override]
    protected static ?string $pluralModelLabel = 'Bloques';

    #[Override]
    protected static ?int $navigationSort = 2;

    /**
     * Fuera del menú principal (5-ago-2026).
     *
     * Los bloques no se dan de alta desde acá: entran con el plano, que el
     * importador de DXF lee del archivo del topógrafo. Esta pantalla es para
     * consultar y corregir, y se llega a ella desde la ficha del proyecto.
     *
     * El Resource sigue existiendo entero —rutas, permisos y policy— así que
     * los enlaces directos y `getUrl()` funcionan igual. Lo único que cambia
     * es que no ocupa un renglón del menú de doña Rosa Elena (§14).
     */
    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return Menu::DESARROLLO;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Bloques';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Bloques';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return BloqueForm::configure($schema);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return BloqueInfolist::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return BloquesTable::configure($table);
    }

    /**
     * Recortado al proyecto que se está mirando — 11-sep-2026.
     *
     * En «Todos» la consulta sale intacta: es el comportamiento de siempre, y
     * por eso una instalación de un solo proyecto no nota el cambio.
     *
     * 🔴 Va en `getEloquentQuery()` y NO en la tabla: así recorta también la
     * ficha, el buscador global y cualquier pantalla que salga de este
     * recurso. Un listado recortado con una ficha que no lo está deja abrir
     * por búsqueda un expediente de otro proyecto, y ahí el interruptor
     * mentiría.
     *
     * @return Builder<Bloque>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Bloque> $query */
        $query = parent::getEloquentQuery();

        return app(ProyectoActivo::class)->recortar($query);
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['nombre'];
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
            'index'  => ListBloques::route('/'),
            'create' => CreateBloque::route('/create'),
            'view'   => ViewBloque::route('/{record}'),
            'edit'   => EditBloque::route('/{record}/edit'),
        ];
    }
}
