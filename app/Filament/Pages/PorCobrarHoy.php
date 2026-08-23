<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\ResultadoDeGestion;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Support\CobrarUnPago;
use App\Filament\Support\Menu;
use App\Models\Cuota;
use App\Models\GestionDeCobro;
use App\Models\Venta;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * La lista de llamadas del día: quién está atrasado y a quién le toca hoy.
 *
 * ═══ DE DONDE SALE ═══
 *
 * Mauricio, 23-ago-2026: «que ahí se vean las personas que llevan cuota
 * atrasada o les toca pago ese día, así evitamos las notificaciones y se van
 * listando ahí… le llaman de que le toca cuota y marcan que ya se
 * contactaron con él para cobrarles».
 *
 * La idea de fondo, que es la que decide el diseño: **una notificación se
 * ignora una vez y ya; una lista de trabajo que se vacía se atiende.** Por
 * eso esto no avisa nada — es una pantalla que hay que dejar vacía.
 *
 * ═══ LAS TRES DECISIONES ═══
 *
 * 1. **La fila es el EXPEDIENTE, no la cuota.** A un cliente con tres cuotas
 *    vencidas se lo llama una vez. Con una fila por cuota, la misma llamada
 *    habría que marcarla tres veces para que desaparezca.
 *
 * 2. **Nadie desmarca nada.** La lista se arma de las cuotas que deben, así
 *    que cuando el cliente paga se vacía sola. Lo único que se marca a mano
 *    es la llamada, y eso se silencia por un tiempo — no para siempre.
 *
 * 3. **El silencio dura lo que dura la promesa.** Ver
 *    `GestionDeCobro` y su migración: prometió el 25 → vuelve el 25; no
 *    prometió nada → vuelve mañana. Un «ya lo llamé» que dure para siempre
 *    convierte esta pantalla en una lista de gente a la que nadie va a
 *    volver a llamar.
 *
 * ⚠️ R2: no hay mora. Lo que se cobra es la cuota, y lo atrasado no engorda
 * con el tiempo. Esta pantalla ordena por cuántas cuotas debe cada uno, que
 * es lo que sí crece.
 */
class PorCobrarHoy extends Page implements HasTable
{
    use InteractsWithTable;

    #[Override]
    protected string $view = 'filament.pages.por-cobrar-hoy';

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    /**
     * Primera del grupo, arriba de Clientes.
     *
     * `Menu::DIA_A_DIA` se llama así porque es «lo que Rosa Elena abre todas
     * las mañanas». Esto es literalmente eso: el trabajo del día.
     */
    #[Override]
    protected static ?int $navigationSort = 0;

    #[Override]
    public function getTitle(): string
    {
        return 'Por cobrar hoy';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Por cobrar hoy';
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return Menu::DIA_A_DIA;
    }

    /**
     * El número que reemplaza a la notificación.
     *
     * Es la misma consulta que la tabla —`porCobrar()`—, no una parecida: un
     * badge que diga 7 sobre una pantalla que muestra 5 es peor que no tener
     * badge (§9.E6).
     */
    #[Override]
    public static function getNavigationBadge(): ?string
    {
        $cuantos = self::porCobrar()->count();

        return $cuantos === 0 ? null : (string) $cuantos;
    }

    #[Override]
    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    #[Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can('Cobranza:Venta') === true;
    }

    /**
     * Los expedientes a los que hay que llamar hoy.
     *
     * Dos condiciones, y la segunda es la que hace que la pantalla sirva:
     *
     *  1. Tiene al menos una cuota viva que vence hoy o venció antes.
     *  2. **No está silenciado**: la ÚLTIMA gestión de cobro no lo tapa. Ver
     *     `Venta::ultimaGestion()` — la última manda, porque una promesa
     *     vieja no sobrevive a una llamada nueva.
     *
     * ⚠️ La fecha la pone PHP y NUNCA Postgres (§7.5.1): el servidor puede
     * estar en UTC y el corte del día saldría corrido seis horas, que acá
     * significa llamar a quien no toca.
     *
     * @return Builder<Venta>
     */
    public static function porCobrar(): Builder
    {
        $hoy = today()->toDateString();

        return Venta::query()
            ->vigentes()
            ->whereExists(self::cuotasQueSeDeben('<=', $hoy))
            /*
             * El silencio. `COALESCE(…, $hoy) <= $hoy` deja pasar al que
             * nunca fue llamado: sin gestión la subconsulta da NULL, el
             * COALESCE la vuelve hoy, y hoy <= hoy es verdadero.
             */
            ->whereRaw(<<<'SQL'
                COALESCE((
                    SELECT g.vuelve_el
                      FROM gestiones_de_cobro g
                     WHERE g.venta_id = ventas.id
                     ORDER BY g.contactado_el DESC, g.id DESC
                     LIMIT 1
                ), ?) <= ?
            SQL, [$hoy, $hoy]);
    }

    /**
     * Las cuotas vivas de ESTE expediente que ya se deben a la fecha dada.
     *
     * Correlacionada contra `ventas.id`: sirve igual para un `EXISTS` —quién
     * entra a la lista— y para las tres subconsultas de la tabla —cuántas
     * debe y cuánto—. Una sola definición de «lo que se debe»: si mañana
     * cambia, cambia en los cuatro lugares a la vez.
     *
     * ⚠️ Sale de `Cuota::query()` y NO del closure de un `whereHas()`. Ese
     * closure recibe un `Builder<Model>` genérico —la relación no le mete el
     * modelo adentro— y ahí `deLotesVivos()` es un método que no existe:
     * cuatro errores de PHPStan que ni `php -l` ni Pint pueden ver. El tipo
     * se repone en el ORIGEN.
     *
     * @return Builder<Cuota>
     */
    private static function cuotasQueSeDeben(string $comparador, string $fecha): Builder
    {
        return Cuota::query()
            ->reorder()
            ->deLotesVivos()
            ->pendientes()
            ->whereColumn('cuotas.venta_id', 'ventas.id')
            ->whereDate('fecha_vencimiento', $comparador, $fecha);
    }

    public function table(Table $table): Table
    {
        $hoy = today()->toDateString();

        return $table
            ->query(fn (): Builder => self::porCobrar()
                ->select('ventas.*')
                /*
                 * El titular es a quien se llama, y su teléfono el dato que
                 * hace útil la pantalla. Precargado: una consulta para toda
                 * la página en vez de una por fila.
                 */
                ->with(['titulares', 'ultimaGestion.usuario'])
                ->addSelect([
                    'cuotas_vencidas' => self::cuotasQueSeDeben('<', $hoy)->selectRaw('COUNT(*)'),
                    'vence_hoy'       => self::cuotasQueSeDeben('=', $hoy)->selectRaw('COUNT(*)'),
                    'monto_a_hoy'     => self::cuotasQueSeDeben('<=', $hoy)
                        ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0)'),
                ]))
            // El más atrasado primero: es a quien más urge llamar.
            ->defaultSort('cuotas_vencidas', 'desc')
            ->columns([
                TextColumn::make('cliente')
                    ->label('A quién llamar')
                    ->weight('medium')
                    ->getStateUsing(static fn (Venta $record): string => (string) ($record->titulares->first()
                        ?->getAttribute('nombre') ?? '—'))
                    ->description(static fn (Venta $record): string => (string) $record->getAttribute('numero_contrato'))
                    /*
                     * ⚠️ El `orWhere` va DENTRO de un grupo. Suelto se
                     * escaparía del `where` de arriba y traería expedientes
                     * liquidados y silenciados: un OR sin paréntesis no
                     * agrega una condición, borra las que estaban.
                     */
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->where(static function (Builder $grupo) use ($search): void {
                            $grupo
                                ->whereHas('clientes', static fn (Builder $cliente): Builder => $cliente
                                    ->where('nombre', 'ilike', "%{$search}%"))
                                ->orWhere('numero_contrato', 'ilike', "%{$search}%");
                        })),

                /*
                 * `copyable()` y no un `tel:`: se llama desde el teléfono de
                 * la oficina, no desde la computadora. Un clic copia el
                 * número y quien atiende lo marca.
                 */
                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->getStateUsing(static fn (Venta $record): string => $record->titulares->first()
                        ?->telefonoFormateado() ?? 'Sin teléfono')
                    ->color(static fn (Venta $record): ?string => $record->titulares->first()
                        ?->telefonoFormateado() === null ? 'danger' : null)
                    ->copyable()
                    ->copyMessage('Número copiado'),

                TextColumn::make('cuotas_vencidas')
                    ->label('Situación')
                    ->badge()
                    ->getStateUsing(static function (Venta $record): string {
                        $vencidas = (int) $record->getAttribute('cuotas_vencidas');

                        if ($vencidas === 0) {
                            return 'Le toca hoy';
                        }

                        return $vencidas === 1 ? '1 cuota vencida' : "{$vencidas} cuotas vencidas";
                    })
                    ->color(static fn (Venta $record): string => (int) $record->getAttribute('cuotas_vencidas') === 0
                        ? 'warning'
                        : 'danger')
                    ->sortable(),

                TextColumn::make('monto_a_hoy')
                    ->label('Debe a hoy')
                    ->alignEnd()
                    ->weight('medium')
                    ->formatStateUsing(static fn (mixed $state): string => new Monto(
                        is_string($state) || is_int($state) ? $state : '0',
                    )->formateado())
                    ->sortable(),

                /*
                 * Sin esta columna, al tercer día nadie sabe si al cliente lo
                 * llamaron dos veces o ninguna. Con ella, quien atiende abre
                 * la llamada sabiendo qué le dijeron la vez pasada.
                 */
                TextColumn::make('ultimo_contacto')
                    ->label('Último contacto')
                    ->placeholder('Nunca se lo llamó')
                    ->badge()
                    ->getStateUsing(static fn (Venta $record): ?string => self::resultadoDe($record)?->corta())
                    ->color(static fn (Venta $record): ?string => self::resultadoDe($record)?->color())
                    ->description(static fn (Venta $record): ?string => self::debajoDelContacto($record)),
            ])
            ->filters([
                SelectFilter::make('cuando')
                    ->label('Mostrar')
                    ->placeholder('Todos')
                    ->options([
                        'atrasados' => 'Solo los atrasados',
                        'hoy'       => 'Solo los que vencen hoy',
                    ])
                    ->query(static function (Builder $query, array $data) use ($hoy): Builder {
                        $valor = $data['value'] ?? null;

                        if ($valor === 'atrasados') {
                            return $query->whereExists(self::cuotasQueSeDeben('<', $hoy));
                        }

                        if ($valor === 'hoy') {
                            return $query->whereExists(self::cuotasQueSeDeben('=', $hoy));
                        }

                        return $query;
                    }),
            ])
            ->recordActions([
                $this->registrarContacto(),

                /*
                 * El MISMO modal de cobro de las otras tres pantallas. Si el
                 * cliente dice «voy para allá» y paga por teléfono, se cobra
                 * desde acá y la fila desaparece sola.
                 */
                CobrarUnPago::accion()->iconButton(),

                Action::make('expediente')
                    ->label('Ver expediente')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->color('gray')
                    ->iconButton()
                    ->url(static fn (Venta $record): string => VentaResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Nadie por llamar hoy')
            ->emptyStateDescription('Ni un expediente atrasado ni una cuota que venza hoy. '
                .'Los que ya se contactaron vuelven a aparecer el día que prometieron pagar.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /**
     * El resultado de la última llamada, con su tipo puesto.
     *
     * ⚠️ `getAttribute()` devuelve `mixed` y ahí se pierde el cast del
     * modelo: `?->corta()` sobre mixed no compila en PHPStan. El tipo se
     * repone acá, una vez, y no en cada uno de los tres lugares que lo usan.
     */
    private static function resultadoDe(Venta $record): ?ResultadoDeGestion
    {
        $resultado = $record->ultimaGestion?->getAttribute('resultado');

        return $resultado instanceof ResultadoDeGestion ? $resultado : null;
    }

    /**
     * El renglón chico debajo del último contacto.
     *
     * Junta lo que hace falta para levantar el teléfono: cuándo fue, quién
     * llamó, y qué prometió si prometió algo.
     */
    private static function debajoDelContacto(Venta $record): ?string
    {
        $gestion = $record->ultimaGestion;

        if (! $gestion instanceof GestionDeCobro) {
            return null;
        }

        $partes = [];

        $cuando = $gestion->getAttribute('contactado_el');
        $partes[] = $cuando instanceof CarbonInterface ? $cuando->format('d/m/Y') : '';

        $quien = $gestion->usuario?->getAttribute('name');

        if (is_string($quien) && $quien !== '') {
            $partes[] = $quien;
        }

        $promesa = $gestion->getAttribute('promesa_el');

        if ($promesa instanceof CarbonInterface) {
            $partes[] = 'dijo que paga el '.$promesa->format('d/m/Y');
        }

        $limpias = array_values(array_filter($partes, static fn (string $parte): bool => $parte !== ''));

        return $limpias === [] ? null : implode(' · ', $limpias);
    }

    /**
     * «Ya lo contacté», con lo que dijo.
     *
     * ⚠️ La fecha de la promesa se dibuja SOLO cuando el resultado es
     * «prometió». En los otros tres no se pregunta nada más — y como un
     * campo oculto no se envía, `$data['promesa_el']` llega ausente, que es
     * exactamente lo que el CHECK de la base exige.
     */
    private function registrarContacto(): Action
    {
        return Action::make('contactar')
            ->label('Ya lo contacté')
            ->icon(Heroicon::OutlinedPhone)
            ->color('primary')
            ->modalHeading(static fn (Venta $record): string => 'Llamada al contrato '
                .$record->getAttribute('numero_contrato'))
            ->modalDescription('Queda con tu usuario y la fecha. Según lo que contestó, el cliente '
                .'vuelve a esta lista mañana o el día que prometió pagar.')
            ->modalSubmitActionLabel('Guardar la llamada')
            ->modalWidth('lg')
            ->schema([
                Select::make('resultado')
                    ->label('¿Cómo salió?')
                    ->options(ResultadoDeGestion::opciones())
                    ->required()
                    ->native(false)
                    ->live(),

                DatePicker::make('promesa_el')
                    ->label('¿Para cuándo dijo que paga?')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->minDate(today()->startOfDay())
                    ->helperText('Ese día vuelve solo a esta lista.')
                    ->visible(static fn (Get $get): bool => $get('resultado') === ResultadoDeGestion::Prometio->value),

                Textarea::make('nota')
                    ->label('¿Algo más que anotar?')
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('Contestó la esposa, él viaja el lunes'),
            ])
            ->action(static function (Venta $record, array $data): void {
                $resultado = ResultadoDeGestion::from((string) ($data['resultado'] ?? ''));

                /*
                 * La promesa se descarta si el resultado no la admite. El
                 * formulario ya la esconde; esto es la segunda llave, porque
                 * una fecha colada con «no contesta» violaría el CHECK y el
                 * error que vería quien atiende sería de Postgres.
                 */
                $promesa = $resultado->exigePromesa() ? ($data['promesa_el'] ?? null) : null;
                $nota = trim((string) ($data['nota'] ?? ''));

                GestionDeCobro::query()->create([
                    'venta_id'      => $record->getKey(),
                    'user_id'       => auth()->id(),
                    'resultado'     => $resultado,
                    'contactado_el' => today()->toDateString(),
                    'promesa_el'    => $promesa,
                    'nota'          => $nota === '' ? null : $nota,
                ]);

                Notification::make()
                    ->title('Llamada registrada')
                    ->body($resultado->exigePromesa()
                        ? 'Vuelve a la lista el día que prometió pagar.'
                        : 'Vuelve a la lista mañana.')
                    ->success()
                    ->send();
            });
    }
}
