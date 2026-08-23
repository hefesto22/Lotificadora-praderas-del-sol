<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas;

use App\Domain\Enums\EstadoVenta;
use App\Filament\Resources\Ventas\Pages\CreateVenta;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\RelationManagers\ActualizacionesRelationManager;
use App\Filament\Resources\Ventas\RelationManagers\CuotasRelationManager;
use App\Filament\Resources\Ventas\RelationManagers\DocumentosRelationManager;
use App\Filament\Resources\Ventas\RelationManagers\RecibosRelationManager;
use App\Filament\Resources\Ventas\RelationManagers\ReprogramacionesRelationManager;
use App\Filament\Resources\Ventas\Schemas\VentaForm;
use App\Filament\Resources\Ventas\Schemas\VentaInfolist;
use App\Filament\Resources\Ventas\Tables\VentasTable;
use App\Filament\Support\Menu;
use App\Models\Cuota;
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
    protected static ?int $navigationSort = 2;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return Menu::DIA_A_DIA;
    }

    /**
     * Cuántos expedientes tienen una cuota vencida hoy.
     *
     * ═══ POR QUE ESTE NUMERO Y NO OTRO ═══
     *
     * Mauricio, 22-ago-2026: el menú «no dice qué es lo importante». De todo
     * lo que se puede contar, esto es lo único que **le pide algo a alguien**
     * hoy: son los clientes a los que hay que llamar. Cuántas ventas hay o
     * cuánto se vendió es información; esto es trabajo.
     *
     * Cuenta EXPEDIENTES, no cuotas: quien atiende llama a una persona, no a
     * una cuota. Un contrato con cinco cuotas atrasadas es una llamada.
     *
     * ⚠️ Los tres filtros son los mismos que usa el Escritorio en
     * `ComoVaElNegocio::vencidoAHoy()`, y tienen que seguir siéndolo — dos
     * pantallas que cuentan lo mismo con criterios distintos es peor que no
     * tener ninguna de las dos:
     *
     *  - `deLotesVivos()` — la cuota que sobrevive a una rescisión (R22) no
     *    se va a pagar nunca; sin esto se queda clavada en «vencido».
     *  - `vencidas()` — pendiente Y con fecha pasada, con `today()` de PHP y
     *    no de Postgres (§7.5.1: el servidor puede estar en UTC).
     *  - solo ventas VIGENTES — una liquidada o anulada no debe nada.
     *
     * `reorder()` antes del agregado: un `orderBy` heredado sobrevive al
     * COUNT y Postgres lo rechaza con 42803.
     */
    #[Override]
    public static function getNavigationBadge(): ?string
    {
        $expedientes = Cuota::query()
            ->reorder()
            ->vencidas()
            ->deLotesVivos()
            ->whereIn('venta_id', Venta::query()->reorder()->select('id')->where('estado', EstadoVenta::Vigente))
            ->distinct()
            ->count('venta_id');

        return $expedientes === 0 ? null : (string) $expedientes;
    }

    #[Override]
    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    #[Override]
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Expedientes con al menos una cuota vencida';
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
        return [
            CuotasRelationManager::class,
            RecibosRelationManager::class,
            ReprogramacionesRelationManager::class,
            DocumentosRelationManager::class,
            /*
             * Última a propósito: es la que menos se abre y la única que no
             * ve todo el mundo. `canViewForRecord()` la esconde para quien
             * no sea super_admin, así que a Rosa Elena la fila de pestañas
             * le queda igual que antes.
             */
            ActualizacionesRelationManager::class,
        ];
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
