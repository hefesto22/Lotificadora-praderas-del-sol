<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas;

use App\Filament\Resources\Ventas\Pages\CreateVenta;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\Schemas\VentaForm;
use App\Filament\Resources\Ventas\Schemas\VentaInfolist;
use App\Filament\Resources\Ventas\Tables\VentasTable;
use App\Models\Venta;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

/**
 * Modulos c) y d) del contrato: ventas y contratos.
 *
 * ═══ NO HAY PAGINA DE EDICION, Y ES A PROPOSITO ═══
 *
 * El §10 del documento rector pone una pagina de edicion en todos los
 * Resources. Este no la lleva, y la razon es del dominio: una venta vigente
 * tiene el plan de cuotas CONGELADO (§9.D6) y un numero de contrato ya
 * impreso en papel. Un formulario de edicion generico invita a cambiar el
 * valor, la prima o el plazo de algo que un cliente ya firmo.
 *
 * Lo que se puede cambiar despues de firmada —observaciones, rescision,
 * liquidacion— son acciones con nombre propio, cada una con su motivo y su
 * bitacora. Se agregan cuando se construya cada tramite.
 *
 * ═══ Y NO HAY BOTON DE BORRAR ═══
 *
 * Una venta no se borra: se anula desde borrador o se rescinde (§8.2). Las
 * dos cosas son historia consultable, no filas que desaparecen.
 */
class VentaResource extends Resource
{
    #[Override]
    protected static ?string $model = Venta::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    #[Override]
    protected static ?string $recordTitleAttribute = 'numero_contrato';

    #[Override]
    protected static ?string $modelLabel = 'Venta';

    #[Override]
    protected static ?string $pluralModelLabel = 'Ventas';

    #[Override]
    protected static ?int $navigationSort = 1;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Lotificación';
    }

    /**
     * `Str::headline` produciría "Ventas" igual, pero el §10.5 pide que sea
     * explícito: el día que el label cambie, se cambia acá y no se descubre
     * en pantalla.
     */
    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Ventas';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Ventas';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return VentaForm::configure($schema);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return VentaInfolist::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return VentasTable::configure($table);
    }

    /**
     * El número de contrato y el de expediente son el mismo correlativo
     * (R7), así que buscar por cualquiera de los dos encuentra lo mismo.
     *
     * @return array<int, string>
     */
    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['numero_contrato'];
    }

    /**
     * @return array<int, string>
     */
    #[Override]
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListVentas::route('/'),
            'create' => CreateVenta::route('/create'),
            'view'   => ViewVenta::route('/{record}'),
        ];
    }
}
