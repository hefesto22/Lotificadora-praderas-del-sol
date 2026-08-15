<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes;

use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Filament\Resources\Clientes\Pages\ViewCliente;
use App\Filament\Resources\Clientes\RelationManagers\ApartadosRelationManager;
use App\Filament\Resources\Clientes\RelationManagers\RecibosRelationManager;
use App\Filament\Resources\Clientes\RelationManagers\VentasRelationManager;
use App\Filament\Resources\Clientes\Schemas\ClienteForm;
use App\Filament\Resources\Clientes\Schemas\ClienteInfolist;
use App\Filament\Resources\Clientes\Tables\ClientesTable;
use App\Models\Cliente;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

/**
 * Módulo a) del contrato.
 *
 * `getGloballySearchableAttributes` NO incluye dni ni rtn: la búsqueda
 * global vive en la barra superior de todo el panel y sus resultados se ven
 * sin abrir el Resource. Exponer la identificación ahí es filtrar PII a
 * cualquiera que tenga acceso al panel (§13.5). Dentro del listado sí se
 * puede buscar por DNI, donde ya hay que tener el permiso.
 */
class ClienteResource extends Resource
{
    #[Override]
    protected static ?string $model = Cliente::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    #[Override]
    protected static ?string $recordTitleAttribute = 'nombre';

    #[Override]
    protected static ?string $modelLabel = 'Cliente';

    #[Override]
    protected static ?string $pluralModelLabel = 'Clientes';

    #[Override]
    protected static ?int $navigationSort = 1;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Lotificación';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Clientes';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Clientes';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return ClienteForm::configure($schema);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return ClienteInfolist::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ClientesTable::configure($table);
    }

    /**
     * Solo el nombre. Ver la nota de PII en el docblock de la clase.
     *
     * @return array<int, string>
     */
    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        /*
         * Las DOS columnas. `nombre_busqueda` atiende a quien teclea sin
         * tildes —el caso de todos los dias— y `nombre` a quien las
         * escribe. Filament arma un OR con la lista, asi que sale gratis.
         */
        return ['nombre', 'nombre_busqueda'];
    }

    /**
     * El expediente completo del cliente, en pestañas.
     *
     * ═══ SON TRES Y NO UNA POR UNA RAZON ═══
     *
     * Filament arma las solapas solo cuando hay más de un relation manager.
     * Con estos tres, la ficha contesta las tres preguntas que llegan al
     * mostrador —qué compró, qué tiene reservado, qué ha pagado— sin salir de
     * la pantalla y sin que nadie tenga que acordarse de ir a buscarlas.
     *
     * Ninguno redefine una columna: cada uno declara su `$relatedResource` y
     * Filament aplica la tabla de esa pantalla tal cual. Ver el docblock de
     * `VentasRelationManager`.
     *
     * El orden es el del ciclo de vida —se aparta, se vende, se paga— pero al
     * revés, porque lo que más se consulta es lo que ya se vendió.
     *
     * @return array<int, string>
     */
    #[Override]
    public static function getRelations(): array
    {
        return [
            VentasRelationManager::class,
            ApartadosRelationManager::class,
            RecibosRelationManager::class,
        ];
    }

    /**
     * Permite ver y restaurar clientes archivados desde el filtro de la
     * tabla. Sin esto, el TrashedFilter no tiene a qué aplicarse.
     *
     * El generic queda en Model, no en Cliente: `parent::getEloquentQuery()`
     * está tipado `Builder<Model>` en Filament y estrecharlo acá sería
     * mentirle a PHPStan sobre algo que el padre no garantiza. Reescribirlo
     * como `Cliente::query()` sí devolvería el tipo estrecho, pero saltearía
     * lo que el padre haga con la query — hoy poco, mañana quién sabe.
     *
     * @return Builder<Model>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListClientes::route('/'),
            'create' => CreateCliente::route('/create'),
            'view'   => ViewCliente::route('/{record}'),
            'edit'   => EditCliente::route('/{record}/edit'),
        ];
    }
}
