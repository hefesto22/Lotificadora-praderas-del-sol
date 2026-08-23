<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Domain\Enums\EstadoVenta;
use App\Filament\Resources\Ventas\VentaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Override;

class ListVentas extends ListRecords
{
    /**
     * La pestaña que junta todos los estados.
     *
     * Es una LLAVE DE URL, no un rótulo: `ListadoDelCliente` la manda en el
     * query string para que el link de la ficha del cliente abra la lista
     * completa. Escrita a mano en dos archivos, el día que cambie deja el
     * link apuntando a una pestaña que no existe — y Filament no se queja:
     * abre la primera.
     */
    public const string TODAS = 'todas';

    #[Override]
    protected static string $resource = VentaResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva venta'),
        ];
    }

    /**
     * Pestañas por estado, con su contador.
     *
     * §9.E6: el badge usa EXACTAMENTE la misma condición que la pestaña. Un
     * contador que no comparte el scoping de su listado filtra información
     * —dice cuántas hay de algo que el usuario no puede ver—.
     *
     * ⚠️ El `Tab` de las pestañas de listado es
     * `Filament\Schemas\Components\Tabs\Tab`, **el mismo de los tabs del
     * formulario**. En Filament v3 vivía en
     * `Filament\Resources\Pages\ListRecords\Tab`, y esa ruta ya no existe:
     * el trait `HasTabs` del propio Filament importa la de Schemas.
     *
     * ═══ 22-AGO-2026: ABRE EN «VIGENTE» Y «TODAS» SE VA AL FINAL ═══
     *
     * Mauricio: «si está [Todas], de nada sirve el toggle». Y era cierto:
     * con la lista completa de portada las pestañas no filtraban NADA al
     * entrar —cuatro rótulos decorativos arriba de las mismas 116 filas— y
     * el trabajo del día quedaba mezclado con lo que ya se cerró.
     *
     * Ahora la pantalla abre en los vigentes, que es a lo que se entra.
     *
     * ⚠️ «Todas» se queda, pero al final: de portada pasa a ser la SALIDA.
     * Es la única forma de buscar por nombre a alguien que ya liquidó —la
     * búsqueda global solo mira `numero_contrato`, no el nombre del
     * cliente— y es adonde apunta el link de la ficha del cliente, cuyo
     * contador cuenta todos los estados (§9.E6).
     *
     * @return array<string, Tab>
     */
    #[Override]
    public function getTabs(): array
    {
        $tabs = [];

        foreach (EstadoVenta::cases() as $estado) {
            // Borrador no se muestra: hoy nada lo produce (R5, la venta nace
            // vigente cuando la prima está pagada). El día que exista una
            // cotización, se saca esta línea.
            if ($estado === EstadoVenta::Borrador) {
                continue;
            }

            $tabs[$estado->value] = Tab::make($estado->etiqueta())
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('estado', $estado->value))
                ->badge(fn (): int => VentaResource::getEloquentQuery()->where('estado', $estado->value)->count())
                ->badgeColor($estado->color());
        }

        // Sin contador a propósito: el número de «Todas» no le pide nada a
        // nadie, y al lado de los otros cuatro se leería como uno más.
        $tabs[self::TODAS] = Tab::make('Todas');

        return $tabs;
    }

    /**
     * Con cuál abre.
     *
     * Explícito y no por orden de aparición: Filament toma la PRIMERA del
     * array (`array_key_first`), así que reordenar las pestañas movería en
     * silencio la pantalla con la que arranca el sistema todos los días.
     */
    #[Override]
    public function getDefaultActiveTab(): string
    {
        return EstadoVenta::Vigente->value;
    }
}
