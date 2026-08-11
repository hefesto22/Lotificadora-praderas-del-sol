<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recibos;

use App\Filament\Resources\Recibos\Pages\ListRecibos;
use App\Filament\Resources\Recibos\Pages\ViewRecibo;
use App\Filament\Resources\Recibos\Schemas\ReciboInfolist;
use App\Filament\Resources\Recibos\Tables\RecibosTable;
use App\Models\Recibo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

/**
 * Módulo h) del contrato: documentos de cobro.
 *
 * ═══ POR QUE UNA PANTALLA PROPIA Y NO SOLO LA PESTAÑA DEL EXPEDIENTE ═══
 *
 * Los recibos cuelgan de una venta, así que la pestaña del expediente es lo
 * natural. Pero la pregunta que llega al mostrador es al revés: alguien trae
 * un papel y lo único que sabe es el número. Sin esta lista habría que
 * adivinar de qué expediente es antes de poder encontrarlo.
 *
 * También es por donde se mira lo cobrado en el día, que es el arqueo de
 * Etapa 2 en su versión más simple.
 *
 * ═══ NO SE CREA, NO SE EDITA, NO SE BORRA ═══
 *
 * Un recibo nace en la transacción que cobra (`RegistroDePagos`), con su
 * correlativo y su detalle de aplicación. Un formulario de creación
 * produciría un número quemado sin dinero detrás. Y un recibo entregado en
 * papel no se corrige: se anula y se emite otro — eso será su propia acción
 * con motivo, y su permiso `Anular:Recibo` nombrado uno por uno (§9.E3).
 *
 * Todo eso lo impone `ReciboPolicy`, que devuelve `false` a las escrituras;
 * acá solo no se registran las páginas.
 */
class ReciboResource extends Resource
{
    #[Override]
    protected static ?string $model = Recibo::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    #[Override]
    protected static ?string $recordTitleAttribute = 'numero';

    #[Override]
    protected static ?string $modelLabel = 'Recibo';

    #[Override]
    protected static ?string $pluralModelLabel = 'Recibos';

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
        return 'Recibos';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Recibos';
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return ReciboInfolist::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return RecibosTable::configure($table);
    }

    /**
     * Por número, que es lo único que trae quien llega con el papel.
     *
     * @return array<int, string>
     */
    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['numero'];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRecibos::route('/'),
            'view'  => ViewRecibo::route('/{record}'),
        ];
    }
}
