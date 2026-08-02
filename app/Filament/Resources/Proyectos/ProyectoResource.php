<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos;

use App\Filament\Resources\Proyectos\Pages\CreateProyecto;
use App\Filament\Resources\Proyectos\Pages\EditProyecto;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\Pages\ViewProyecto;
use App\Filament\Resources\Proyectos\Schemas\ProyectoForm;
use App\Filament\Resources\Proyectos\Schemas\ProyectoInfolist;
use App\Filament\Resources\Proyectos\Tables\ProyectosTable;
use App\Models\Proyecto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

/**
 * Raíz de la jerarquía proyectos → bloques → lotes (ADR-0002).
 *
 * Hoy existe un solo proyecto, pero el contrato reconoce que la
 * contratante administra desarrollos: el Resource existe desde el día uno
 * para que nadie tenga que inventar dónde configurar el segundo.
 */
class ProyectoResource extends Resource
{
    #[Override]
    protected static ?string $model = Proyecto::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    #[Override]
    protected static ?string $recordTitleAttribute = 'nombre';

    #[Override]
    protected static ?string $modelLabel = 'Proyecto';

    #[Override]
    protected static ?string $pluralModelLabel = 'Proyectos';

    #[Override]
    protected static ?int $navigationSort = 1;

    /**
     * §10.5: navegación explícita. Str::headline produce cosas como
     * "Formas De Pago", así que nunca se deja al automático.
     */
    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Lotificación';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Proyectos';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Proyectos';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return ProyectoForm::configure($schema);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return ProyectoInfolist::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ProyectosTable::configure($table);
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['nombre', 'codigo', 'municipio'];
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
            'index'  => ListProyectos::route('/'),
            'create' => CreateProyecto::route('/create'),
            'view'   => ViewProyecto::route('/{record}'),
            'edit'   => EditProyecto::route('/{record}/edit'),
        ];
    }
}
