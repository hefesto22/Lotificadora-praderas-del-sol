<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\Apartados\ApartadoResource;
use App\Filament\Resources\Recibos\ReciboResource;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Filament\Resources\Ventas\VentaResource;
use App\Models\Cliente;

/**
 * Los tres listados del sistema, abiertos ya filtrados por un cliente.
 *
 * ═══ QUE PROBLEMA RESUELVE ═══
 *
 * La ficha del cliente contestaba quién es y a qué teléfono se le llama, pero
 * no qué tiene. Para saberlo había que irse a Ventas y buscarlo a mano por el
 * nombre — y con dos MARIA RODRIGUEZ en la lista, eso es una apuesta.
 *
 * ═══ POR QUE UNA CLASE Y NO UN getUrl() SUELTO EN CADA LADO ═══
 *
 * El link se arma con la forma del query string de Filament: `ListRecords`
 * publica sus filtros como `#[Url(as: 'filters')]`, así que la URL termina
 * siendo `?filters[cliente][value]=7`. Esa forma es de Filament y no nuestra:
 * el día que cambie de versión se arregla ACÁ, y no en los seis lugares que
 * hoy arman el link.
 *
 * Los permisos viven en el mismo archivo por la misma razón: la columna del
 * listado, la opción del menú y el botón de la ficha tienen que aparecer y
 * desaparecer juntos. Tres copias de la misma condición son dos copias que
 * algún día se quedan viejas.
 */
final class ListadoDelCliente
{
    /**
     * El nombre del SelectFilter en las tres tablas.
     *
     * Es el contrato entre esta clase y `VentasTable`, `ApartadosTable` y
     * `RecibosTable`. Si se renombra allá y no acá, el link deja de filtrar y
     * la pantalla se abre ENTERA sin avisar de nada — por eso hay un test que
     * abre cada listado con este filtro puesto y cuenta las filas.
     */
    private const string FILTRO = 'cliente';

    /**
     * ⚠️ Va con pestaña y las otras dos no.
     *
     * Desde el 22-ago la pantalla de Ventas abre en «Vigente», y el
     * contador de la ficha cuenta TODOS los estados. Sin esta línea, el
     * cliente que ya liquidó un lote muestra «Ventas 3» y al hacer clic
     * aparecen dos filas — que es exactamente el contador que miente del
     * §9.E6, solo que del lado del listado.
     */
    public static function ventas(Cliente $cliente): string
    {
        return VentaResource::getUrl('index', [
            ...self::filtradoPor($cliente),
            /*
             * 🔴 La llave es `tab`, NO `activeTab`.
             *
             * La propiedad de la página se llama `activeTab`, pero Filament
             * la publica al query string como `#[Url(as: 'tab')]`. Con el
             * nombre de la propiedad, Livewire descarta el parámetro EN
             * SILENCIO —sin error, sin aviso— y la pantalla abre en la
             * pestaña por defecto, que es justo lo que hay que evitar acá.
             *
             * Escrito con `activeTab` la primera vez, el 22-ago. Lo agarró
             * `QueTieneElClienteTest` y no la pantalla, que es el orden en
             * el que conviene enterarse.
             */
            'tab' => ListVentas::TODAS,
        ]);
    }

    public static function apartados(Cliente $cliente): string
    {
        return ApartadoResource::getUrl('index', self::filtradoPor($cliente));
    }

    public static function recibos(Cliente $cliente): string
    {
        return ReciboResource::getUrl('index', self::filtradoPor($cliente));
    }

    public static function puedeVerVentas(): bool
    {
        return auth()->user()?->can('ViewAny:Venta') === true;
    }

    /**
     * Un apartado es un Compromiso y hereda su permiso: no hay un
     * `ViewAny:Apartado` que dar (ver el docblock de `ApartadoResource`).
     */
    public static function puedeVerApartados(): bool
    {
        return auth()->user()?->can('ViewAny:Compromiso') === true;
    }

    public static function puedeVerRecibos(): bool
    {
        return auth()->user()?->can('ViewAny:Recibo') === true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function filtradoPor(Cliente $cliente): array
    {
        return ['filters' => [self::FILTRO => ['value' => $cliente->getKey()]]];
    }
}
