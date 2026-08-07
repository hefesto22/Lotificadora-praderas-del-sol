<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apartados\Tables;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Models\Compromiso;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los apartados ordenados por lo que se vence primero.
 *
 * El orden NO es por fecha de creación como en el resto del sistema: acá la
 * pregunta es «¿qué se me está cayendo?», y lo más urgente tiene que estar
 * arriba sin que nadie toque un filtro.
 */
class ApartadosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['lote', 'cliente']))
            ->columns([
                TextColumn::make('lote.codigo')
                    ->label('Lote')
                    ->weight('bold')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cliente.nombre')
                    ->label('A nombre de')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('fecha')
                    ->label('Apartado el')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                /*
                 * La columna que justifica la pantalla. El color dice lo que
                 * un vendedor necesita saber de un vistazo, y el texto de
                 * abajo lo dice con palabras para quien no distingue los
                 * colores o está mirando desde el teléfono (§14).
                 */
                TextColumn::make('vence_el')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('sin fecha')
                    ->badge()
                    ->color(static fn (Compromiso $record): string => self::colorDelVencimiento($record))
                    ->description(static fn (Compromiso $record): ?string => self::cuandoVence($record)),

                TextColumn::make('prorrogas')
                    ->label('Prórrogas')
                    ->badge()
                    ->color(static fn (Compromiso $record): string => $record->puedeProrrogarse() ? 'gray' : 'warning')
                    ->formatStateUsing(static fn (Compromiso $record): string => sprintf(
                        '%d de %d',
                        $record->prorrogasUsadas(),
                        Compromiso::prorrogasMaximas(),
                    ))
                    ->toggleable(),

                /*
                 * ⚠️ Sin ->money(): ese formateador pasa por number_format(),
                 * que recibe float, y el §8.3.1 prohibe float en el camino
                 * del dinero. Monto lo formatea desde el string de bcmath,
                 * igual que RecibosTable.
                 */
                TextColumn::make('monto_senia')
                    ->label('Seña')
                    ->alignEnd()
                    ->placeholder('sin seña')
                    ->sortable()
                    ->formatStateUsing(static fn (Compromiso $record): string => self::senia($record)),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(static fn (Compromiso $record): string => self::colorDelEstado($record))
                    ->formatStateUsing(static fn (Compromiso $record): string => self::etiquetaDelEstado($record)),
            ])
            ->filters([
                /*
                 * Los tres filtros son las tres preguntas reales. «Vencidos»
                 * es el que se abre el lunes; «por vencer» es el que evita
                 * que el lunes siguiente haya más.
                 */
                /*
                 * ═══ POR QUE UN whereIn CONTRA UN SUBQUERY ═══
                 *
                 * La regla de «vencido» vive en el scope del modelo y tiene
                 * que vivir en UN solo lugar: la pantalla, el contador del
                 * menu y los tests tienen que estar de acuerdo sobre que es
                 * un apartado vencido.
                 *
                 * Filament entrega un `Builder<Model>` generico y los scopes
                 * solo se resuelven sobre `Builder<Compromiso>`. Encadenarlos
                 * directo obliga a repetir las condiciones aca —y el dia que
                 * cambien, una de las dos copias se queda vieja en silencio—.
                 * El subquery deja el scope como unica fuente y le devuelve a
                 * Filament el tipo que espera. Son unos cientos de apartados:
                 * el `IN` no se nota.
                 */
                Filter::make('vencidos')
                    ->label('Vencidos, todavía ocupando el lote')
                    ->query(static fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        Compromiso::query()->vencidos()->select('id'),
                    )),

                Filter::make('por_vencer')
                    ->label('Vencen dentro de 3 días')
                    ->query(static fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        Compromiso::query()->porVencer(3)->select('id'),
                    )),

                Filter::make('senia_por_devolver')
                    ->label('Con seña por devolver')
                    ->query(static fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        Compromiso::query()->conSeniaPorDevolver()->select('id'),
                    )),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(static fn (): array => self::estados()),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::prorrogar(),
                    self::liberar(),
                    self::devolverLaSenia(),
                ]),
            ])
            /*
             * Los que no tienen fecha van al final: son los apartados viejos
             * cargados sin vencimiento (R15) y no le corren a nadie.
             */
            ->defaultSort('vence_el', 'asc')
            ->emptyStateHeading('No hay apartados')
            ->emptyStateDescription('Se apartan desde el plano del proyecto, eligiendo un lote y un cliente.')
            ->emptyStateIcon('heroicon-o-bookmark');
    }

    // ─── Acciones ─────────────────────────────────────────────────────

    /**
     * R14: quince días más, una sola vez, con el motivo escrito.
     */
    private static function prorrogar(): Action
    {
        return Action::make('prorrogar')
            ->label('Prorrogar')
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->visible(static fn (Compromiso $record): bool => $record->puedeProrrogarse()
                && auth()->user()?->can('Prorrogar:Compromiso') === true)
            ->modalHeading('Prorrogar el apartado')
            ->modalDescription(
                'Se le suman los días de prórroga que fijó la contratante. Es la única prórroga '.
                'que R14 autoriza: después de esta, para darle más tiempo hay que liberar y volver a apartar.'
            )
            ->modalSubmitActionLabel('Prorrogar')
            ->schema([
                Textarea::make('motivo')
                    ->label('¿Por qué se prorroga?')
                    ->required()
                    ->rows(2)
                    ->placeholder('El cliente pidió unos días más para juntar la prima, ...')
                    ->helperText('Queda anotado con tu usuario y la fecha.'),
            ])
            ->action(static function (Compromiso $record, array $data): void {
                self::corriendo(
                    static fn (): Compromiso => app(RegistroDeCompromisos::class)->prorrogar(
                        $record,
                        is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                    ),
                    'El apartado se prorrogó',
                    static fn (Compromiso $fresco): string => sprintf(
                        'El lote %s queda apartado hasta el %s.',
                        (string) $fresco->lote()->value('codigo'),
                        $fresco->getAttribute('vence_el')?->format('d/m/Y') ?? '—',
                    ),
                );
            });
    }

    /**
     * Soltar el lote. Si había seña, el aviso dice cuánto hay que devolver.
     */
    private static function liberar(): Action
    {
        return Action::make('liberar')
            ->label('Liberar el lote')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(static fn (Compromiso $record): bool => $record->estaVigente()
                && auth()->user()?->can('Update:Compromiso') === true)
            ->modalHeading('Liberar el apartado')
            ->modalDescription('El lote vuelve a quedar disponible. El apartado queda en el historial con su motivo.')
            ->modalSubmitActionLabel('Liberar')
            ->schema([
                Textarea::make('motivo')
                    ->label('¿Por qué se libera?')
                    ->required()
                    ->rows(2)
                    ->placeholder('Se venció el plazo, el cliente desistió, ...'),
            ])
            ->action(static function (Compromiso $record, array $data): void {
                self::corriendo(
                    static fn (): Compromiso => app(RegistroDeCompromisos::class)->liberar(
                        $record->lote()->firstOrFail(),
                        is_string($data['motivo'] ?? null) ? $data['motivo'] : 'Sin motivo',
                    ),
                    'El lote volvió a estar disponible',
                    static fn (Compromiso $fresco): string => self::avisoDeLaSenia($fresco),
                );
            });
    }

    /**
     * R14: si el apartado se cae, la plata vuelve.
     *
     * Esto NO es un egreso — no hay comprobante de salida todavía, y se
     * decidió el 6-ago dejar ese módulo para después. Lo que hace es sacar
     * el pendiente de la lista, que es lo que evita que la promesa de R14
     * quede escrita solo en el contrato.
     */
    private static function devolverLaSenia(): Action
    {
        return Action::make('devolver_senia')
            ->label('Marcar seña devuelta')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(static fn (Compromiso $record): bool => $record->seniaPorDevolver() instanceof Monto
                && auth()->user()?->can('DevolverSenia:Compromiso') === true)
            ->modalHeading('¿Ya se le devolvió la seña?')
            ->modalDescription(
                'Queda anotado con tu usuario y la fecha, y el apartado sale de la lista de '.
                'pendientes por devolver. La salida de caja se registra aparte.'
            )
            ->modalSubmitActionLabel('Sí, ya se devolvió')
            ->schema([
                Textarea::make('observacion')
                    ->label('¿Cómo se devolvió?')
                    ->rows(2)
                    ->placeholder('En efectivo en la oficina, por transferencia, ...'),
            ])
            ->action(static function (Compromiso $record, array $data): void {
                self::corriendo(
                    static fn (): Compromiso => app(RegistroDeCompromisos::class)->devolverLaSenia(
                        $record,
                        is_string($data['observacion'] ?? null) ? $data['observacion'] : null,
                    ),
                    'Seña devuelta',
                    static fn (Compromiso $fresco): string => sprintf(
                        'Ya no queda nada pendiente del apartado del lote %s.',
                        (string) $fresco->lote()->value('codigo'),
                    ),
                );
            });
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Corre un movimiento del dominio y avisa, sin pantalla de error 500.
     *
     * Los mensajes de `GrupoOlympoException` ya están escritos para quien
     * atiende. Dejarlos subir mostraría un stack trace con un cliente
     * enfrente, que es lo que el §10 no admite.
     *
     * @param callable(): Compromiso $movimiento
     * @param callable(Compromiso): string $mensaje
     */
    private static function corriendo(callable $movimiento, string $titulo, callable $mensaje): void
    {
        try {
            $fresco = $movimiento();
        } catch (GrupoOlympoException $error) {
            Notification::make()
                ->title('No se pudo hacer ese movimiento')
                ->body($error->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title($titulo)
            ->body($mensaje($fresco))
            ->success()
            ->send();
    }

    /**
     * Lo que hay que hacer después de soltar el lote.
     *
     * Si el cliente había dejado seña, esa plata es suya y hay que
     * devolvérsela (R14). Decirlo acá —en el momento en que se libera, no en
     * un reporte de fin de mes— es la diferencia entre que se devuelva y que
     * nadie se acuerde.
     */
    private static function avisoDeLaSenia(Compromiso $compromiso): string
    {
        $senia = $compromiso->seniaPorDevolver();
        $codigo = (string) $compromiso->lote()->value('codigo');

        if (! $senia instanceof Monto) {
            return "El lote {$codigo} volvió a estar disponible.";
        }

        return sprintf(
            'El lote %s volvió a estar disponible. Ojo: hay %s de seña que devolverle a %s — '.
            'queda en la lista «con seña por devolver» hasta que se marque.',
            $codigo,
            $senia->formateado(),
            (string) $compromiso->cliente()->value('nombre'),
        );
    }

    private static function senia(Compromiso $record): string
    {
        $senia = $record->getAttribute('monto_senia');

        if (! is_string($senia) && ! is_int($senia)) {
            return '—';
        }

        return new Monto($senia)->formateado();
    }

    private static function colorDelVencimiento(Compromiso $record): string
    {
        if (! $record->estaVigente()) {
            return 'gray';
        }

        $dias = $record->diasParaVencer();

        return match (true) {
            $dias === null => 'gray',
            $dias < 0      => 'danger',
            $dias <= 3     => 'warning',
            default        => 'success',
        };
    }

    private static function cuandoVence(Compromiso $record): ?string
    {
        if (! $record->estaVigente()) {
            return null;
        }

        $dias = $record->diasParaVencer();

        return match (true) {
            $dias === null => null,
            $dias < 0      => sprintf('vencido hace %d día(s)', abs($dias)),
            $dias === 0    => 'vence hoy',
            $dias === 1    => 'vence mañana',
            default        => sprintf('faltan %d días', $dias),
        };
    }

    private static function colorDelEstado(Compromiso $record): string
    {
        $estado = $record->getAttribute('estado');

        if (! $estado instanceof EstadoCompromiso) {
            return 'gray';
        }

        return match ($estado) {
            EstadoCompromiso::Vigente    => $record->estaVencido() ? 'danger' : 'success',
            EstadoCompromiso::Convertido => 'info',
            default                      => 'gray',
        };
    }

    private static function etiquetaDelEstado(Compromiso $record): string
    {
        $estado = $record->getAttribute('estado');

        if (! $estado instanceof EstadoCompromiso) {
            return '—';
        }

        // «Vigente» a secas mentiría sobre un apartado al que ya se le pasó
        // la fecha: sigue ocupando el lote, pero no porque valga.
        if ($estado === EstadoCompromiso::Vigente && $record->estaVencido()) {
            return 'Vencido';
        }

        return $estado->etiqueta();
    }

    /**
     * ⚠️ `SelectFilter::options()` exige `array<string, string>`.
     *
     * @return array<string, string>
     */
    private static function estados(): array
    {
        $opciones = [];

        foreach (EstadoCompromiso::cases() as $estado) {
            $opciones[$estado->value] = $estado->etiqueta();
        }

        return $opciones;
    }
}
