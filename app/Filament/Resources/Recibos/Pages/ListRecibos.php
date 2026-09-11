<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recibos\Pages;

use App\Filament\Resources\Recibos\ReciboResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Sin botón de crear: un recibo nace cobrando, no llenando un formulario.
 */
class ListRecibos extends ListRecords
{
    /**
     * La pestaña sin filtrar, para que la nombre quien manda a esta pantalla.
     *
     * La usa `ListadoDelCliente::recibos()`: el contador de la ficha cuenta
     * TODOS los recibos del cliente, así que el link tiene que abrir en
     * «Todos» o muestra «Recibos 3» y al entrar aparecen dos (§9.E6). Es el
     * mismo caso que Ventas resolvió el 22-ago-2026.
     */
    public const string TODOS = 'todos';

    /**
     * Con la que abre la pantalla: lo que todavía cuenta.
     */
    public const string ACTIVOS = 'activos';

    #[Override]
    protected static string $resource = ReciboResource::class;

    /**
     * Sin migas de pan, como el plano (23-ago-2026).
     *
     * «Recibos › Listado» arriba del título «Recibos» es la misma palabra dos
     * veces y una ruta de un solo salto. Para volver está el menú de la
     * izquierda, que además dice dónde estás parado sin gastar un renglón.
     *
     * @return array<string>
     */
    #[Override]
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * 🔴 Activos y anulados, separados — 11-sep-2026.
     *
     * «Esos anulados deben de estar en un toggle que sea activos y anulados
     * para cambiar entre ellos y que no se amontonen» — Mauricio, el mismo día
     * en que anular empezó a usarse de verdad.
     *
     * Y tiene razón por una razón que no se veía hasta hoy: hasta el 11-sep un
     * recibo anulado era raro, y ahora un cobro mal registrado tacha CUATRO
     * papeles de una vez. Con dos errores del mes, la primera pantalla de la
     * lista es casi toda tinta roja de cosas que ya no cuentan.
     *
     * ═══ ⚠️ LO QUE SE PIERDE, Y POR QUE SE ACEPTA ═══
     *
     * Hasta hoy la lista mostraba TODO sin filtrar, y estaba escrito por qué:
     * «la búsqueda es por número y quien llega con el papel tiene que
     * encontrarlo». Eso sigue siendo cierto — y con «Activos» por defecto, el
     * que llega con un papel ANULADO en la mano no lo encuentra buscándolo.
     *
     * Por eso la pestaña «Todos» existe y no es decorativa: es dónde se busca
     * cuando el número no aparece. Y por eso «Anulados» lleva el conteo a la
     * vista: quien no encuentra un folio ve, sin cambiar de pantalla, que hay
     * papeles anulados donde podría estar.
     *
     * ═══ 🔴 EL PARAMETRO SE LLAMA `$query`. NO SE LE CAMBIA EL NOMBRE ═══
     *
     * `Tab::modifyQuery()` inyecta el builder POR NOMBRE —pasa
     * `['query' => $query]`— y usa el valor de retorno como query de la tabla.
     * Con el parámetro llamado de otra forma, Filament no lo encuentra por
     * nombre, cae a resolverlo por TIPO y entrega otro builder: uno sin modelo.
     * Ese huérfano pasa a ser el query de la tabla y revienta más adelante todo
     * lo que necesite el modelo.
     *
     * Se escribió `$consulta` la primera vez, el 11-sep-2026. Costó cuatro
     * vueltas de la puerta, y ninguno de los errores apuntaba acá: el primero
     * culpaba a un `withCount('impresiones')` que llevaba dos semanas sin
     * tocarse, y al quitarlo el siguiente culpó a los filtros. El síntoma se
     * mueve porque la causa está arriba de los dos.
     *
     * El orden importa: `getDefaultActiveTab()` toma la PRIMERA clave si no se
     * declara, así que mover «activos» de lugar cambiaría lo que se ve al
     * entrar. Acá está declarado, igual que en `ListVentas`.
     *
     * @return array<string, Tab>
     */
    #[Override]
    public function getTabs(): array
    {
        return [
            self::ACTIVOS => Tab::make('Activos')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereNull('anulado_el')),

            'anulados' => Tab::make('Anulados')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereNotNull('anulado_el'))
                /*
                 * El conteo sin el badge sería un número que hay que ir a
                 * buscar. Acá es la pista de que el folio que no aparece
                 * puede estar del otro lado.
                 */
                /*
                 * Por `getEloquentQuery()` del Resource y no por `Recibo::query()`:
                 * §9.E6 pide que el contador use EXACTAMENTE el mismo scoping que
                 * su listado, o dice cuántas hay de algo que el usuario no ve.
                 */
                ->badge(static fn (): ?int => ReciboResource::getEloquentQuery()
                    ->whereNotNull('anulado_el')
                    ->count() ?: null),

            self::TODOS => Tab::make('Todos'),
        ];
    }

    /**
     * Con cuál abre.
     *
     * Explícito y no por orden de aparición: Filament toma la PRIMERA del
     * array (`array_key_first`), así que reordenar las pestañas movería en
     * silencio la pantalla con la que se entra todos los días. Es la misma
     * razón por la que `ListVentas` lo declara.
     */
    #[Override]
    public function getDefaultActiveTab(): string
    {
        return self::ACTIVOS;
    }
}
