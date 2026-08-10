<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Tables;

use App\Domain\ValueObjects\DNI;
use App\Domain\ValueObjects\Monto;
use App\Filament\Support\ListadoDelCliente;
use App\Models\Cliente;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §10.7: columnas explícitas, filtros con la misma fuente que el scoping,
 * defaultSort y paginación 25/50/100.
 *
 * ═══ LOS TRES CONTADORES SON UN LINK ═══
 *
 * «Ventas», «Apartados» y «Recibos» no están para decorar: cada número abre
 * SU listado ya filtrado por ese cliente. Antes, contestar «¿y este señor qué
 * compró?» obligaba a irse a Ventas y buscar por nombre — y dos clientes que
 * se apellidan parecido convierten eso en una apuesta.
 *
 * El número, además, contesta de un vistazo lo que no se ve entrando uno por
 * uno: quién ya compró, quién solo tiene un lote apartado y quién no ha
 * pagado nunca nada.
 *
 * ═══ SE CUENTAN EN LA MISMA CONSULTA ═══
 *
 * `withCount` mete tres subconsultas en la consulta de la tabla, y el saldo
 * una cuarta. Preguntarle a la relación fila por fila serían 100 consultas en
 * una página de 25 clientes, en la pantalla que más se abre del sistema
 * (§9.D).
 */
class ClientesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->withCount(['ventas', 'apartados', 'recibos'])
                ->addSelect(['saldo_pendiente' => Cliente::consultaDeSaldo()]))
            ->columns([
                TextColumn::make('nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                /*
                | El DNI se guarda en dígitos limpios pero la gente lo teclea
                | como lo lee del carnet, con guiones. Sin este query el
                | buscador no encuentra nada al pegar "0801-1985-01234" y
                | parece que el cliente no existe.
                */
                TextColumn::make('dni')
                    ->label('DNI')
                    ->formatStateUsing(fn (?string $state): string => is_string($state) && $state !== ''
                        ? DNI::formatearCrudo($state)
                        : '—')
                    ->placeholder('—')
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        $digitos = preg_replace('/\D/', '', $search) ?? '';

                        return $digitos === '' ? $query : $query->where('dni', 'like', $digitos.'%');
                    })
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->formatStateUsing(fn (?string $state, Cliente $record): string => $record->telefonoFormateado() ?? '—')
                    ->placeholder('—')
                    ->searchable()
                    ->icon('heroicon-o-phone')
                    ->toggleable(),

                TextColumn::make('correo')
                    ->label('Correo')
                    ->searchable()
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                | ═══ EL CERO TAMBIEN SE MUESTRA, Y EN GRIS ═══
                |
                | Un cliente sin ventas es un dato: es justo al que hay que
                | llamar. Va en gris para que la columna no parezca un
                | semáforo y el ojo encuentre solo lo que sí tiene movimiento.
                |
                | Las tres llevan `visible()`: quien no puede entrar a Recibos
                | tampoco tiene por qué saber cuántos hay, y sin el permiso el
                | link lo llevaría a un 403 (§13.5).
                */
                TextColumn::make('ventas_count')
                    ->label('Ventas')
                    ->badge()
                    ->alignCenter()
                    ->sortable()
                    ->toggleable()
                    ->color(static fn (mixed $state): string => self::cuantos($state) > 0 ? 'success' : 'gray')
                    ->tooltip('Ver los expedientes de este cliente')
                    ->url(static fn (Cliente $record): string => ListadoDelCliente::ventas($record))
                    ->visible(static fn (): bool => ListadoDelCliente::puedeVerVentas()),

                TextColumn::make('apartados_count')
                    ->label('Apartados')
                    ->badge()
                    ->alignCenter()
                    ->sortable()
                    ->toggleable()
                    ->color(static fn (mixed $state): string => self::cuantos($state) > 0 ? 'warning' : 'gray')
                    ->tooltip('Ver los lotes que este cliente tiene apartados')
                    ->url(static fn (Cliente $record): string => ListadoDelCliente::apartados($record))
                    ->visible(static fn (): bool => ListadoDelCliente::puedeVerApartados()),

                TextColumn::make('recibos_count')
                    ->label('Recibos')
                    ->badge()
                    ->alignCenter()
                    ->sortable()
                    ->toggleable()
                    ->color(static fn (mixed $state): string => self::cuantos($state) > 0 ? 'info' : 'gray')
                    ->tooltip('Ver todo lo que este cliente ha pagado')
                    ->url(static fn (Cliente $record): string => ListadoDelCliente::recibos($record))
                    ->visible(static fn (): bool => ListadoDelCliente::puedeVerRecibos()),

                /*
                | ═══ LA PREGUNTA QUE LLEGA AL MOSTRADOR ═══
                |
                | «¿Cuánto debe este señor?» Los contadores dicen cuántos
                | papeles hay; este dice la plata. Un cliente con tres lotes en
                | dos contratos obligaba a entrar a los dos y sumar a mano.
                |
                | Cuenta con la MISMA regla que el «Por cobrar» del Escritorio:
                | solo expedientes vigentes. Una venta rescindida deja cuotas
                | impagas en la tabla, y sumarlas le inventaría al cliente una
                | deuda que nadie va a cobrar nunca.
                |
                | El cero se muestra como «—» y no como L. 0.00: quien no debe
                | nada y quien nunca compró son, para esta columna, la misma
                | respuesta —no hay nada que cobrarle—, y una lista llena de
                | ceros esconde los números que sí importan.
                */
                TextColumn::make('saldo_pendiente')
                    ->label('Debe')
                    ->formatStateUsing(static function (mixed $state): string {
                        $saldo = new Monto(is_string($state) || is_int($state) ? $state : '0');

                        return $saldo->esCero() ? '—' : $saldo->formateado();
                    })
                    ->alignEnd()
                    ->sortable()
                    ->toggleable()
                    ->tooltip('Lo que debe hoy, sumando todos sus expedientes vigentes')
                    ->visible(static fn (): bool => ListadoDelCliente::puedeVerVentas()),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),

                TrashedFilter::make()
                    ->label('Archivados'),
            ])
            /*
             * Tres bloques con una línea que los separa: mirar y corregir,
             * después a dónde ir, y al final lo que destruye. Un menú donde
             * «Ver sus ventas» queda pegado a «Eliminar» es un menú donde
             * alguien va a apretar de más un martes a las cinco de la tarde.
             */
            ->recordActions([
                ActionGroup::make([
                    ActionGroup::make([
                        ViewAction::make(),
                        EditAction::make(),
                    ])->dropdown(false),

                    ActionGroup::make([
                        self::verSusVentas(),
                        self::verSusApartados(),
                        self::verSusRecibos(),
                    ])->dropdown(false),

                    ActionGroup::make([
                        DeleteAction::make(),
                        RestoreAction::make(),
                        ForceDeleteAction::make(),
                    ])->dropdown(false),
                ]),
            ])
            ->defaultSort('nombre')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay clientes')
            ->emptyStateDescription('Registrá al primer cliente para poder apartar o vender un lote.')
            ->emptyStateIcon('heroicon-o-users');
    }

    // ─── Los tres atajos del menú ─────────────────────────────────────

    private static function verSusVentas(): Action
    {
        return Action::make('ver_ventas')
            ->label('Ver sus ventas')
            ->icon(Heroicon::OutlinedDocumentText)
            ->url(static fn (Cliente $record): string => ListadoDelCliente::ventas($record))
            ->visible(static fn (): bool => ListadoDelCliente::puedeVerVentas());
    }

    private static function verSusApartados(): Action
    {
        return Action::make('ver_apartados')
            ->label('Ver sus apartados')
            ->icon(Heroicon::OutlinedBookmark)
            ->url(static fn (Cliente $record): string => ListadoDelCliente::apartados($record))
            ->visible(static fn (): bool => ListadoDelCliente::puedeVerApartados());
    }

    private static function verSusRecibos(): Action
    {
        return Action::make('ver_recibos')
            ->label('Ver sus recibos')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->url(static fn (Cliente $record): string => ListadoDelCliente::recibos($record))
            ->visible(static fn (): bool => ListadoDelCliente::puedeVerRecibos());
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El estado de una columna `withCount` llega como `mixed`.
     *
     * PDO de Postgres entrega los agregados como string y Eloquent no los
     * castea: comparar `$state > 0` sin esto es comparar un string con un
     * int, que en PHP 8 ya no es la conversión silenciosa de antes.
     */
    private static function cuantos(mixed $estado): int
    {
        return is_numeric($estado) ? (int) $estado : 0;
    }
}
