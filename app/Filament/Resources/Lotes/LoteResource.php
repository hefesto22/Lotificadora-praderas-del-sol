<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes;

use App\Domain\Enums\EstadoLote;
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
use Illuminate\Database\Eloquent\Model;
use Override;

class LoteResource extends Resource
{
    #[Override]
    protected static ?string $model = Lote::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    #[Override]
    protected static ?string $recordTitleAttribute = 'codigo';

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

    /**
     * El código es lo que la gente teclea: "RPS-B-12". Buscar solo por
     * `numero` devolvía todos los lotes 12 de todos los proyectos, sin nada
     * que los distinguiera en la lista de resultados.
     *
     * @return array<int, string>
     */
    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'numero'];
    }

    /**
     * Sin esto, los resultados globales muestran solo el código y hay que
     * abrirlos para saber cuál es cuál.
     *
     * @return array<string, string>
     */
    #[Override]
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Lote $record */
        $estado = $record->getAttribute('estado');

        return [
            'Proyecto' => (string) $record->proyecto?->getAttribute('nombre'),
            'Estado'   => $estado instanceof EstadoLote ? $estado->etiqueta() : '—',
            'Valor'    => 'L '.number_format((float) $record->getAttribute('valor'), 2),
        ];
    }

    /**
     * Evita un N+1 en la lista de resultados globales: sin el eager load,
     * cada fila consulta su proyecto por separado.
     *
     * @return Builder<Model>
     */
    #[Override]
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('proyecto:id,nombre');
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
