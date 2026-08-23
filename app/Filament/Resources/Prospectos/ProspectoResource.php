<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prospectos;

use App\Filament\Resources\Prospectos\Pages\ListProspectos;
use App\Filament\Resources\Prospectos\Tables\ProspectosTable;
use App\Filament\Support\Menu;
use App\Models\Prospecto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

/**
 * Quien vio el plano publico y dejo su telefono.
 *
 * ═══ LA UNICA PREGUNTA QUE CONTESTA ═══
 *
 * «¿A quien me falta llamar?». Por eso los sin atender salen primero y el
 * menu lleva contador: una lista de prospectos que hay que acordarse de
 * abrir no se abre nunca, y el contacto se enfria en dos dias.
 *
 * ═══ NO SE CREAN NI SE EDITAN DESDE ACA ═══
 *
 * Un prospecto nace cuando alguien llena el formulario del plano publico.
 * Crearlos a mano seria inventar la traza de por donde llego un cliente, que
 * es justamente el numero que esta pantalla existe para medir. Lo unico que
 * se hace es **marcarlos atendidos** y dejar una nota de la llamada.
 *
 * ⚠️ Son DATOS PERSONALES de gente que no es cliente: nombre y telefono de
 * alguien que solo miro una pagina. Van con permiso propio y el receptor no
 * los ve.
 */
class ProspectoResource extends Resource
{
    #[Override]
    protected static ?string $model = Prospecto::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    #[Override]
    protected static ?string $modelLabel = 'Prospecto';

    #[Override]
    protected static ?string $pluralModelLabel = 'Prospectos';

    #[Override]
    protected static ?int $navigationSort = 5;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return Menu::DIA_A_DIA;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Prospectos';
    }

    /**
     * Los que esperan una llamada, en el menú.
     *
     * Null y no '0' cuando no hay ninguno: un cero permanente se vuelve parte
     * del decorado y dentro de un mes ya nadie lo mira.
     */
    #[Override]
    public static function getNavigationBadge(): ?string
    {
        $pendientes = Prospecto::query()->sinAtender()->count();

        return $pendientes === 0 ? null : (string) $pendientes;
    }

    #[Override]
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    #[Override]
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Personas que dejaron su teléfono y todavía nadie llamó';
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ProspectosTable::configure($table);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListProspectos::route('/'),
        ];
    }
}
