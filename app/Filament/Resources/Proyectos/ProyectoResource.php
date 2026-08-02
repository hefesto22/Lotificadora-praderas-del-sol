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

/**
 * Raíz de la jerarquía proyectos → bloques → lotes (ADR-0002).
 *
 * Hoy existe un solo proyecto, pero el contrato reconoce que la
 * contratante administra desarrollos: el Resource existe desde el día uno
 * para que nadie tenga que inventar dónde configurar el segundo.
 */
class ProyectoResource extends Resource
{
    protected static ?string $model = Proyecto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Proyecto';

    protected static ?string $pluralModelLabel = 'Proyectos';

    protected static ?int $navigationSort = 1;

    /**
     * §10.5: navegación explícita. Str::headline produce cosas como
     * "Formas De Pago", así que nunca se deja al automático.
     */
    public static function getNavigationGroup(): ?string
    {
        return 'Lotificación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Proyectos';
    }

    public static function getBreadcrumb(): string
    {
        return 'Proyectos';
    }

    public static function form(Schema $schema): Schema
    {
        return ProyectoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProyectoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProyectosTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['nombre', 'codigo', 'municipio'];
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
            'index'  => ListProyectos::route('/'),
            'create' => CreateProyecto::route('/create'),
            'view'   => ViewProyecto::route('/{record}'),
            'edit'   => EditProyecto::route('/{record}/edit'),
        ];
    }
}
