<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\RelationManagers;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\ValueObjects\Monto;
use App\Models\Cuota;
use App\Models\Reestructuracion;
use App\Models\Reprogramacion;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * El plan de cuotas del expediente, cuota por cuota.
 *
 * ═══ POR QUE SE AGRUPA POR LOTE ═══
 *
 * Desde el 5-ago-2026 cada lote del contrato tiene su propio plazo, así que
 * el plan dejó de ser uno solo: el lote a 12 meses termina de pagarse mientras
 * el de 48 sigue vivo. Cada cuota apunta a su compromiso, y la pregunta que se
 * hace en ventanilla —«¿cuánto le falta a ESE lote?»— solo se puede contestar
 * mirándolas separadas.
 *
 * ═══ UNA PESTAÑA POR LOTE (26-oct-2026) ═══
 *
 * «Que me aparezcan las cuotas en un toggle de cada lote para que no se
 * confunda con esa intercalación» —Mauricio, con el 0033 reestructurado a
 * cada lote con su cuota—. Con dos o más lotes hay una pestaña por lote, con
 * cómo va cada uno en su insignia, y al final «Todo el contrato», que es la
 * lista intercalada de siempre.
 *
 * ═══ LOS UNIFICADOS, EN UNA SOLA ═══
 *
 * «Si es unificada, solo uno para los lotes unificados.» Los lotes que una
 * reestructuración dejó pagando UNA cuota (`Reestructuracion::UNIFICADOS`)
 * van juntos en la pestaña «Plan único», con un renglón por mes: lo que paga
 * cada lote en su columna y el mes entero sumado. Si todos los lotes del
 * contrato están en ese plan no hay pestañas: hay un plan solo.
 *
 * Un lote sale del plan único en cuanto algo lo toca por su cuenta —un abono
 * a capital de ese lote solo, una reestructuración después—: el último
 * movimiento del lote en `reprogramaciones` es el que manda.
 *
 * ═══ SOLO SE MIRA ═══
 *
 * Sin botones de crear, editar ni borrar. El plan es un snapshot inmutable
 * (§9.D6) y lo mueve el Service de pagos, no un formulario. Que no aparezcan
 * no depende de esta clase: lo decide `CuotaPolicy`, que devuelve `false` a
 * todas las escrituras.
 */
class CuotasRelationManager extends RelationManager
{
    private const string PESTANIA_DE_LOTE = 'lote-';

    private const string PESTANIA_DE_PLAN = 'plan-';

    private const string TODO_EL_CONTRATO = 'todo';

    #[Override]
    protected static string $relationship = 'cuotas';

    #[Override]
    protected static ?string $title = 'Plan de cuotas';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-calendar-days';

    /**
     * Cuántas cuotas tiene cada lote del expediente, contadas una sola vez.
     *
     * @var array<int, int>|null
     */
    private ?array $cuotasPorLote = null;

    /**
     * Los lotes con cuotas, en el orden del contrato, con cómo va cada uno.
     *
     * @var array<int, array{codigo: string, vivo: bool, cuantas: int, pagadas: int, vencidas: int}>|null
     */
    private ?array $lotes = null;

    /**
     * Los planes únicos: llave de su pestaña → ids de compromiso, en el orden
     * del contrato.
     *
     * @var array<string, list<int>>|null
     */
    private ?array $planes = null;

    /**
     * Las cuotas de los lotes del plan único que se está mirando, por número
     * y por compromiso: lo que suma cada renglón.
     *
     * @var array<int, array<int, Cuota>>|null
     */
    private ?array $meses = null;

    #[Override]
    public function form(Schema $schema): Schema
    {
        // No se edita ninguna, pero el contrato del padre pide el método.
        return $schema->components([]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        /*
         * Una columna por lote de un plan único, con su código arriba. Se arman
         * siempre y se muestran solo mirando ese plan: la tabla se construye
         * antes de que llegue el cambio de pestaña, y una columna que existe y
         * se esconde no se pierde en ese render (lo mismo que en Maya).
         */
        $partes = [];

        foreach ($this->planes() as $plan) {
            foreach ($plan as $id) {
                $partes[] = TextColumn::make('parte_'.$id)
                    ->label($this->lotes()[$id]['codigo'] ?? '—')
                    ->alignEnd()
                    ->color('gray')
                    ->state(fn (Cuota $record): string => $this->parteDe($record, $id))
                    ->visible(fn (): bool => in_array($id, $this->planALaVista() ?? [], true));
            }
        }

        return $table
            ->recordTitleAttribute('numero')
            /*
             * Mirando un plan único, un renglón por mes: se listan las cuotas
             * de UN lote del plan —el que más tiene— y cada columna dice el mes
             * entero, sumando los lotes (`delMes()`).
             */
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with('compromiso.lote');

                $representante = $this->representante();

                return $representante === null ? $query : $query->where('cuotas.compromiso_id', $representante);
            })
            ->columns([
                TextColumn::make('compromiso.lote.codigo')
                    ->label('Lote')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->placeholder('—')
                    // En la pestaña de un lote ya lo dice la pestaña.
                    ->visible(fn (): bool => $this->loteElegido() === null && $this->planALaVista() === null),

                /*
                 * El «de M» sale de contar las cuotas que HAY, no de
                 * `compromisos.plazo_meses`. Desde R21 los dos numeros pueden
                 * diferir: un abono a capital que acorta el plazo deja nueve
                 * cuotas en un renglon que se firmo a doce, y la pantalla
                 * decia «Cuota 9 de 12» con nueve cuotas en la tabla.
                 *
                 * El plazo del compromiso sigue siendo el que se firmo, y esta
                 * bien que asi sea: lo que cambia es el plan, no el contrato.
                 */
                TextColumn::make('numero')
                    ->label('Cuota')
                    ->formatStateUsing(fn (Cuota $record): string => sprintf(
                        '%d de %d',
                        (int) $record->getAttribute('numero'),
                        $this->cuantasCuotasTieneElLote($record),
                    ))
                    ->sortable(),

                TextColumn::make('fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable(),

                ...$partes,

                TextColumn::make('monto')
                    ->label(fn (): string => $this->planALaVista() === null ? 'Monto' : 'A pagar')
                    ->alignEnd()
                    ->weight(fn (): ?string => $this->planALaVista() === null ? null : 'bold')
                    ->formatStateUsing(fn (Cuota $record): string => $this->sumaDelMes($record, static fn (Cuota $cuota): Monto => $cuota->montoTotal())->formateado()),

                TextColumn::make('monto_pagado')
                    ->label('Pagado')
                    ->alignEnd()
                    ->color(fn (Cuota $record): string => $this->sumaDelMes($record, static fn (Cuota $cuota): Monto => $cuota->montoPagado())->esCero() ? 'gray' : 'success')
                    ->formatStateUsing(function (Cuota $record): string {
                        $pagado = $this->sumaDelMes($record, static fn (Cuota $cuota): Monto => $cuota->montoPagado());

                        return $pagado->esCero() ? '—' : $pagado->formateado();
                    }),

                TextColumn::make('saldo')
                    ->label('Falta')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (Cuota $record): string => $this->sumaDelMes($record, static fn (Cuota $cuota): Monto => $cuota->saldo())->formateado()),

                /*
                 * `pagada` y `vencida` NO son columnas: se calculan de
                 * `monto_pagado` y de la fecha (§9.D5). Guardarlas obligaría a
                 * una tarea nocturna que las recalcule, y esa tarea falla justo
                 * el día que el cliente llega a pagar.
                 *
                 * En un plan único el mes está pagado si lo están todos sus
                 * lotes, y vencido si a alguno le falta y ya pasó la fecha.
                 */
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(function (Cuota $record): string {
                        $mes = $this->delMes($record);

                        if (array_all($mes, static fn (Cuota $cuota): bool => $cuota->estaPagada())) {
                            return 'Pagada';
                        }

                        // Los días del lote que más lleva sin pagar ese mes.
                        $atraso = null;

                        foreach ($mes as $cuota) {
                            if ($cuota->estaVencida()) {
                                $atraso = max($atraso ?? 0, $cuota->diasDeAtraso());
                            }
                        }

                        return $atraso === null ? 'Pendiente' : sprintf('Vencida (%d días)', $atraso);
                    })
                    ->color(function (Cuota $record): string {
                        $mes = $this->delMes($record);

                        return match (true) {
                            array_all($mes, static fn (Cuota $cuota): bool => $cuota->estaPagada())  => 'success',
                            array_any($mes, static fn (Cuota $cuota): bool => $cuota->estaVencida()) => 'danger',
                            default                                                                  => 'gray',
                        };
                    }),
            ])
            ->filters([
                Filter::make('pendientes')
                    ->label('Solo lo que falta')
                    ->query(static fn (Builder $query): Builder => $query->whereColumn('monto_pagado', '<', 'monto')),

                Filter::make('vencidas')
                    ->label('Solo las vencidas')
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereColumn('monto_pagado', '<', 'monto')
                        ->whereDate('fecha_vencimiento', '<', today())),
            ])
            ->defaultSort('fecha_vencimiento')
            ->paginationPageOptions([12, 24, 48, 100])
            ->emptyStateHeading('Esta venta no tiene cuotas')
            ->emptyStateDescription('Se pagó de contado: la prima cubrió el valor completo.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    /**
     * Nunca de solo lectura por ser una página de vista: acá no hay nada que
     * escribir de todos modos, pero el default de Filament escondería hasta
     * los filtros.
     */
    #[Override]
    public function isReadOnly(): bool
    {
        return false;
    }

    // ─── Las pestañas ─────────────────────────────────────────────────

    /**
     * Una por lote, en el orden del contrato, los de un plan único juntos en
     * el lugar del primero de ellos, y al final el contrato entero. Con un
     * solo lote —o con todos en un mismo plan único— ninguna: no hay nada que
     * elegir.
     *
     * La insignia dice cómo va cada uno sin entrar: el que dice «Atrasado» es
     * al que hay que mirar.
     *
     * @return array<string, Tab>
     */
    #[Override]
    public function getTabs(): array
    {
        if (! $this->hayPestanias()) {
            return [];
        }

        $pestanias = [];

        foreach ($this->lotes() as $id => $lote) {
            $plan = $this->planDe($id);

            if ($plan !== null) {
                if (! isset($pestanias[$plan])) {
                    [$insignia, $color] = $this->insigniaDe($this->planes()[$plan]);

                    $pestanias[$plan] = Tab::make('Plan único: '.$this->codigosDe($this->planes()[$plan]))
                        ->badge($insignia)
                        ->badgeColor($color);
                }

                continue;
            }

            [$insignia, $color] = $this->insigniaDe([$id]);

            $pestanias[self::PESTANIA_DE_LOTE.$id] = Tab::make('Lote '.$lote['codigo'])
                ->badge($insignia)
                ->badgeColor($color)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('cuotas.compromiso_id', $id));
        }

        $pestanias[self::TODO_EL_CONTRATO] = Tab::make('Todo el contrato');

        return $pestanias;
    }

    /**
     * Abre en la primera pestaña —el primer lote, o el plan único si su
     * primer lote va antes—, no en «Todo el contrato». Explícito y no por
     * orden de aparición: reordenar las pestañas movería en silencio la
     * pantalla con la que se abre.
     */
    #[Override]
    public function getDefaultActiveTab(): string|int|null
    {
        if (! $this->hayPestanias()) {
            return null;
        }

        $primero = array_key_first($this->lotes());

        if ($primero === null) {
            return null;
        }

        return $this->planDe($primero) ?? self::PESTANIA_DE_LOTE.$primero;
    }

    private function hayPestanias(): bool
    {
        $lotes = $this->lotes();

        if (count($lotes) < 2) {
            return false;
        }

        return $this->todosEnUnPlan() === null;
    }

    /**
     * El plan único que reúne a TODOS los lotes del contrato, si lo hay:
     * entonces no hay pestañas, hay un plan solo.
     *
     * @return list<int>|null
     */
    private function todosEnUnPlan(): ?array
    {
        $planes = $this->planes();

        if (count($planes) !== 1) {
            return null;
        }

        $plan = array_first($planes);

        return count($plan) === count($this->lotes()) ? $plan : null;
    }

    /**
     * El compromiso de la pestaña elegida. `null` es el contrato entero o un
     * plan único.
     */
    private function loteElegido(): ?int
    {
        $pestania = $this->activeTab;

        if (! is_string($pestania) || ! str_starts_with($pestania, self::PESTANIA_DE_LOTE)) {
            return null;
        }

        $id = (int) substr($pestania, strlen(self::PESTANIA_DE_LOTE));

        return array_key_exists($id, $this->lotes()) ? $id : null;
    }

    /**
     * Los lotes del plan único que se está mirando: el de su pestaña, o el
     * del contrato entero cuando todos están en él. `null` si no se mira uno.
     *
     * @return list<int>|null
     */
    private function planALaVista(): ?array
    {
        $todos = $this->todosEnUnPlan();

        if ($todos !== null) {
            return $todos;
        }

        $pestania = $this->activeTab;

        return is_string($pestania) ? ($this->planes()[$pestania] ?? null) : null;
    }

    /**
     * El lote cuyas cuotas son los renglones del plan único: el que más
     * tiene, para que ningún mes quede sin renglón. Con empate, el primero
     * del contrato. `null` si no se mira un plan único.
     */
    private function representante(): ?int
    {
        $plan = $this->planALaVista();

        if ($plan === null) {
            return null;
        }

        $elegido = null;
        $mas = -1;

        foreach ($plan as $id) {
            $cuantas = $this->lotes()[$id]['cuantas'] ?? 0;

            if ($cuantas > $mas) {
                $elegido = $id;
                $mas = $cuantas;
            }
        }

        return $elegido;
    }

    /**
     * La pestaña del plan único al que pertenece el lote, o `null`.
     */
    private function planDe(int $id): ?string
    {
        foreach ($this->planes() as $llave => $plan) {
            if (in_array($id, $plan, true)) {
                return $llave;
            }
        }

        return null;
    }

    /**
     * «RPS-O-005 y RPS-O-006», «RPS-A-001, RPS-A-002 y RPS-A-003».
     *
     * @param list<int> $ids
     */
    private function codigosDe(array $ids): string
    {
        $codigos = array_map(fn (int $id): string => $this->lotes()[$id]['codigo'] ?? '—', $ids);
        $ultimo = array_pop($codigos);

        return $codigos === [] ? (string) $ultimo : implode(', ', $codigos).' y '.$ultimo;
    }

    /**
     * La insignia de una pestaña: la del lote que peor va.
     *
     * @param list<int> $ids
     *
     * @return array{0: string, 1: string}
     */
    private function insigniaDe(array $ids): array
    {
        $lotes = [];

        foreach ($ids as $id) {
            if (isset($this->lotes()[$id])) {
                $lotes[] = $this->lotes()[$id];
            }
        }

        return match (true) {
            array_any($lotes, static fn (array $lote): bool => $lote['vivo'] && $lote['vencidas'] > 0)                   => ['Atrasado', 'danger'],
            array_all($lotes, static fn (array $lote): bool => ! $lote['vivo'])                                          => ['Rescindido', 'gray'],
            array_all($lotes, static fn (array $lote): bool => ! $lote['vivo'] || $lote['pagadas'] === $lote['cuantas']) => ['Pagado', 'success'],
            default                                                                                                      => ['Al día', 'success'],
        };
    }

    // ─── Lo que se cuenta una vez por render ──────────────────────────

    /**
     * Los lotes del expediente que tienen cuotas, con su código y cómo va
     * cada uno, en el orden del contrato. El `ORDER BY` va escrito (§9.D18):
     * de él sale el orden de las pestañas y cuál se abre primero.
     *
     * Una consulta, no una por lote. «Hoy» lo pone PHP (§7.5).
     *
     * @return array<int, array{codigo: string, vivo: bool, cuantas: int, pagadas: int, vencidas: int}>
     */
    private function lotes(): array
    {
        if ($this->lotes !== null) {
            return $this->lotes;
        }

        $filas = DB::table('cuotas')
            ->join('compromisos', 'compromisos.id', '=', 'cuotas.compromiso_id')
            ->join('lotes', 'lotes.id', '=', 'compromisos.lote_id')
            ->where('cuotas.venta_id', $this->getOwnerRecord()->getKey())
            ->groupBy('compromisos.id', 'lotes.codigo', 'compromisos.estado')
            ->orderBy('lotes.codigo')
            ->orderBy('compromisos.id')
            ->selectRaw(
                'compromisos.id AS id, lotes.codigo AS codigo, compromisos.estado AS estado, COUNT(*) AS cuantas, '
                .'SUM(CASE WHEN cuotas.monto_pagado >= cuotas.monto THEN 1 ELSE 0 END) AS pagadas, '
                .'SUM(CASE WHEN cuotas.monto_pagado < cuotas.monto AND cuotas.fecha_vencimiento < ? THEN 1 ELSE 0 END) AS vencidas',
                [today()->toDateString()],
            )
            ->get();

        $lotes = [];

        foreach ($filas as $fila) {
            $id = $fila->id ?? null;
            $codigo = $fila->codigo ?? null;

            if (! is_numeric($id)) {
                continue;
            }

            if (! is_string($codigo)) {
                continue;
            }

            $lotes[(int) $id] = [
                'codigo'   => $codigo,
                'vivo'     => ($fila->estado ?? null) === EstadoCompromiso::Vigente->value,
                'cuantas'  => is_numeric($fila->cuantas ?? null) ? (int) $fila->cuantas : 0,
                'pagadas'  => is_numeric($fila->pagadas ?? null) ? (int) $fila->pagadas : 0,
                'vencidas' => is_numeric($fila->vencidas ?? null) ? (int) $fila->vencidas : 0,
            ];
        }

        return $this->lotes = $lotes;
    }

    /**
     * Los planes únicos del contrato: los lotes cuyo ÚLTIMO movimiento de
     * plan es la constancia de una reestructuración que los dejó pagando una
     * sola cuota (`Reestructuracion::UNIFICADOS`). Dos o más; uno solo es una
     * pestaña de lote como cualquier otra.
     *
     * @return array<string, list<int>>
     */
    private function planes(): array
    {
        if ($this->planes !== null) {
            return $this->planes;
        }

        $ultimoDe = [];

        foreach (Reprogramacion::query()
            ->where('venta_id', $this->getOwnerRecord()->getKey())
            ->reorder()
            ->orderByDesc('id')
            ->get(['id', 'compromiso_id', 'reestructuracion_id']) as $constancia) {
            $lote = $constancia->getAttribute('compromiso_id');
            $acta = $constancia->getAttribute('reestructuracion_id');

            // Una constancia de la venta entera no es de ningún lote.
            if (! is_numeric($lote)) {
                continue;
            }

            // La primera que aparece es la última que se hizo: esa manda.
            $ultimoDe[(int) $lote] ??= is_numeric($acta) ? (int) $acta : 0;
        }

        $actas = array_values(array_unique(array_filter($ultimoDe)));

        $unificadas = $actas === [] ? [] : array_map(intval(...), Reestructuracion::query()
            ->whereKey($actas)
            ->where('agrupacion', Reestructuracion::UNIFICADOS)
            ->pluck('id')
            ->all());

        $planes = [];

        // En el orden del contrato, que es el de `lotes()`.
        foreach ($this->lotes() as $id => $lote) {
            $acta = $ultimoDe[$id] ?? 0;

            if ($lote['vivo'] && in_array($acta, $unificadas, true)) {
                $planes[self::PESTANIA_DE_PLAN.$acta][] = $id;
            }
        }

        return $this->planes = array_filter($planes, static fn (array $plan): bool => count($plan) >= 2);
    }

    /**
     * Las cuotas de ese mismo número en los lotes del plan único que se mira;
     * fuera de un plan único, la cuota sola.
     *
     * @return list<Cuota>
     */
    private function delMes(Cuota $record): array
    {
        $plan = $this->planALaVista();

        if ($plan === null) {
            return [$record];
        }

        if ($this->meses === null) {
            $meses = [];

            foreach (Cuota::query()->whereIn('compromiso_id', $plan)->get() as $cuota) {
                $meses[(int) $cuota->getAttribute('numero')][(int) $cuota->getAttribute('compromiso_id')] = $cuota;
            }

            $this->meses = $meses;
        }

        return array_values($this->meses[(int) $record->getAttribute('numero')] ?? [$record]);
    }

    /**
     * Lo que suma el mes en los lotes del plan único: monto, pagado o lo que
     * falta. Fuera de un plan único, lo de la cuota sola.
     *
     * @param callable(Cuota): Monto $cuanto
     */
    private function sumaDelMes(Cuota $record, callable $cuanto): Monto
    {
        $suma = Monto::cero();

        foreach ($this->delMes($record) as $cuota) {
            $suma = $suma->sumar($cuanto($cuota));
        }

        return $suma;
    }

    /**
     * Lo que paga ESE lote en el mes del renglón, o una raya si ya no tiene
     * cuota ese mes.
     */
    private function parteDe(Cuota $record, int $id): string
    {
        foreach ($this->delMes($record) as $cuota) {
            if ((int) $cuota->getAttribute('compromiso_id') === $id) {
                return $cuota->montoTotal()->formateado();
            }
        }

        return '—';
    }

    /**
     * Cuántas cuotas tiene HOY el plan de ese lote.
     *
     * Una sola consulta por render, no una por fila: se cuentan todos los
     * lotes del expediente de golpe y se guarda el resultado. Con 48 cuotas en
     * pantalla, hacerlo fila por fila serían 48 consultas por un número que no
     * cambia mientras se mira la tabla.
     */
    private function cuantasCuotasTieneElLote(Cuota $cuota): int
    {
        if ($this->cuotasPorLote === null) {
            $conteo = [];

            $filas = Cuota::query()
                ->where('venta_id', $cuota->getAttribute('venta_id'))
                ->whereNotNull('compromiso_id')
                ->selectRaw('compromiso_id, COUNT(*) AS cuantas')
                ->groupBy('compromiso_id')
                ->pluck('cuantas', 'compromiso_id');

            foreach ($filas as $lote => $cuantas) {
                if ((is_int($lote) || is_string($lote)) && (is_int($cuantas) || is_string($cuantas))) {
                    $conteo[(int) $lote] = (int) $cuantas;
                }
            }

            $this->cuotasPorLote = $conteo;
        }

        return $this->cuotasPorLote[(int) $cuota->getAttribute('compromiso_id')] ?? 0;
    }
}
