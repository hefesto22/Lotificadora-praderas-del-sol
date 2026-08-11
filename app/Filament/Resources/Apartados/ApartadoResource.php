<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apartados;

use App\Domain\Enums\TipoCompromiso;
use App\Filament\Resources\Apartados\Pages\ListApartados;
use App\Filament\Resources\Apartados\Tables\ApartadosTable;
use App\Models\Compromiso;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * R14: los apartados, y sobre todo los que se están venciendo.
 *
 * ═══ EL AGUJERO QUE VIENE A TAPAR ═══
 *
 * Hasta el 6-ago-2026 el sistema guardaba `vence_el` y **nadie lo miraba
 * nunca**. Un apartado al que se le pasaba la fecha dejaba el lote reservado
 * para siempre, hasta que a alguien se le ocurriera buscarlo en el plano y
 * liberarlo a mano. Con 301 lotes y apartados de quince días, eso es plata
 * parada que nadie ve.
 *
 * Por eso esta pantalla existe y por eso lleva contador en el menú: la
 * pregunta «¿qué se venció?» no se hace si hay que acordarse de hacerla.
 *
 * ═══ POR QUE NO LOS SUELTA UN PROCESO AUTOMATICO ═══
 *
 * Se decidió el 6-ago con Mauricio. Un trabajo diario que libere todo lo
 * vencido suena más prolijo, pero suelta el lote sin que ninguna persona lo
 * decida: el cliente que llega el día 16 a las nueve de la mañana con los
 * L 5,000.00 se encuentra con que ya se lo llevó otro, y la lotificadora
 * pierde una venta por una regla de tres líneas.
 *
 * El sistema avisa; libera una persona.
 *
 * ═══ ES UN COMPROMISO, FILTRADO ═══
 *
 * No hay tabla ni modelo de apartados: un apartado es un `Compromiso` de
 * tipo `apartado` (§8.2). Por eso el Resource apunta a `Compromiso` y
 * recorta la consulta — y por eso hereda `CompromisoPolicy` sin inventar
 * permisos nuevos para mirar. Los dos que sí son nuevos, `Prorrogar` y
 * `DevolverSenia`, se nombran uno por uno en el RoleSeeder (§9.E3).
 *
 * ═══ NO SE CREA NI SE EDITA DESDE ACA ═══
 *
 * Un apartado nace en el plano, eligiendo un lote y un cliente, y cobrando
 * su seña. Un formulario genérico dejaría crear un apartado sobre un lote
 * vendido, o cambiarle el monto a uno que ya tiene recibo entregado.
 */
class ApartadoResource extends Resource
{
    #[Override]
    protected static ?string $model = Compromiso::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    #[Override]
    protected static ?string $modelLabel = 'Apartado';

    #[Override]
    protected static ?string $pluralModelLabel = 'Apartados';

    #[Override]
    protected static ?int $navigationSort = 4;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Lotificación';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Apartados';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Apartados';
    }

    /**
     * Un apartado es un compromiso de tipo apartado, y nada más.
     *
     * Incluye los cerrados —liberados y convertidos— a propósito: sin ellos
     * no se puede contestar «¿a este lote ya se le cayó un apartado antes?»,
     * que es justo lo que uno quiere saber antes de dar una prórroga.
     *
     * @return Builder<Compromiso>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        /*
         * El `@var` no es decorativo: `parent::getEloquentQuery()` esta
         * declarado sobre `Model` generico, asi que sin esto el analisis ve
         * un `Builder<Model>` donde la firma promete `Builder<Compromiso>`.
         */
        /** @var Builder<Compromiso> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tipo', TipoCompromiso::Apartado);
    }

    /**
     * Lo vencido, en el menú, sin que nadie tenga que entrar a mirar.
     *
     * Null y no '0' cuando no hay nada: un cero en rojo permanente se vuelve
     * parte del decorado y dentro de un mes ya nadie lo ve.
     */
    #[Override]
    public static function getNavigationBadge(): ?string
    {
        $vencidos = Compromiso::query()->vencidos()->count();

        return $vencidos === 0 ? null : (string) $vencidos;
    }

    #[Override]
    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    #[Override]
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Apartados vencidos que todavía ocupan su lote';
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ApartadosTable::configure($table);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListApartados::route('/'),
        ];
    }
}
