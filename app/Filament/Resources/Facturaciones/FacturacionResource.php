<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturaciones;

use App\Filament\Resources\Facturaciones\Pages\CreateFacturacion;
use App\Filament\Resources\Facturaciones\Pages\EditFacturacion;
use App\Filament\Resources\Facturaciones\Pages\ListFacturaciones;
use App\Filament\Resources\Facturaciones\RelationManagers\AutorizacionesRelationManager;
use App\Filament\Resources\Facturaciones\Schemas\FacturacionForm;
use App\Filament\Resources\Facturaciones\Tables\FacturacionesTable;
use App\Filament\Support\Menu;
use App\Models\Facturacion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

/**
 * Facturación: con qué papel cobra cada desarrollo.
 *
 * Vive en ADMINISTRACIÓN y no en Lotificación a propósito. Quien atiende
 * en ventanilla no configura esto nunca: se carga una vez, cuando el
 * contador trae la autorización del SAR, y después solo se mira cuando el
 * rango se está acabando.
 */
class FacturacionResource extends Resource
{
    #[Override]
    protected static ?string $model = Facturacion::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    #[Override]
    protected static ?string $recordTitleAttribute = 'nombre';

    #[Override]
    protected static ?string $modelLabel = 'Facturación';

    #[Override]
    protected static ?string $pluralModelLabel = 'Facturación';

    #[Override]
    protected static ?int $navigationSort = 4;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return Menu::ADMINISTRACION;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Facturación';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Facturación';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return FacturacionForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return FacturacionesTable::configure($table);
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
            AutorizacionesRelationManager::class,
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListFacturaciones::route('/'),
            'create' => CreateFacturacion::route('/create'),
            'edit'   => EditFacturacion::route('/{record}/edit'),
        ];
    }
}
