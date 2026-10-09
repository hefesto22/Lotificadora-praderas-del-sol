<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\FormaDePago;
use App\Domain\Gastos\RegistroDeGastos;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Proyectos\Schemas\GastoForm;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Socio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Override;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lo que este desarrollo ha costado, y en qué.
 *
 * Lo pidió Mauricio el 11-ago-2026, con la pantalla del proyecto abierta:
 * «que ahí donde está bloques, lotes y planes de pago haya uno que sea gastos
 * de proyecto, y ahí se puedan ir registrando los gastos, los totales y el
 * motivo de en qué se gastó».
 *
 * ═══ POR QUE VIVE ACA Y NO EN EL MENU PRINCIPAL ═══
 *
 * Mismo argumento que bloques y lotes (5-ago-2026): no existe un gasto que no
 * pertenezca a un proyecto. Entrando desde la ficha, el proyecto ya está
 * decidido —es el de la pantalla— y el formulario no lo vuelve a preguntar.
 *
 * ═══ EL RESUMEN VA ARRIBA, Y RESPETA LOS FILTROS ═══
 *
 * El resumen sale de `getFilteredTableQuery()`, no de un `SUM` sobre la
 * tabla entera: filtrar por «Terracería» y ver arriba un total que incluye la
 * publicidad sería peor que no tener total. Filtrás, y el total contesta por lo
 * que estás mirando.
 *
 * ⚠️ **No se usa un `Summarizer` de Filament.** `Sum` castea a float y el
 * §8.3.1 lo prohíbe en el camino del dinero; la suma se hace en Postgres y
 * entra a `Monto` como string, igual que en `CorteDeCajaDeHoy`.
 *
 * ⚠️ **`reorder()` antes del `GROUP BY`.** El `defaultSort` de la tabla
 * sobrevive al agregado y Postgres lo rechaza con un 42803 —«column must
 * appear in the GROUP BY clause»— que en pantalla se ve como un error 500 sin
 * relación aparente con lo que se tocó.
 *
 * ═══ QUIEN ENTRA ═══
 *
 * Solo la administradora. El receptor no ve ni la pestaña, porque
 * `canViewForRecord()` de Filament resuelve `GastoPolicy::viewAny()` y él no
 * tiene `ViewAny:Gasto`. La razón está escrita en la política: lo que el
 * desarrollo cuesta es información del dueño.
 */
class GastosRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'gastos';

    #[Override]
    protected static ?string $title = 'Gastos';

    /**
     * El tipo tiene que ser IDÉNTICO al del padre —`string|BackedEnum|null`,
     * no `?string`—: PHP exige la firma exacta al redeclarar una propiedad
     * tipada, y con una estática eso revienta al cargar la clase, no al
     * usarla.
     */
    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-banknotes';

    /**
     * El formulario vive en `GastoForm` desde el 8-oct-2026: lo comparte con
     * el botón «Registrar gasto» de la lista de proyectos, y dos copias del
     * mismo formulario se separan solas.
     */
    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components(GastoForm::componentes($this->proyecto()));
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('descripcion')
            /*
             * 🔴 VA EN `description()` Y **NO** EN `header()`.
             *
             * El blade de Filament es un `@if ($header) … @elseif ($heading ||
             * $description || $headerActions)`: poner un `header()` propio
             * **reemplaza el bloque entero**, y con el se lleva el boton
             * «Registrar un gasto». Se ve como que el modulo se quedo sin alta.
             *
             * Como la descripcion se imprime dentro de un `<p>`, el resumen se
             * arma con elementos EN LINEA —`span` y `strong`, nada de `table`
             * ni `div`—: un bloque adentro de un parrafo lo cierra solo y el
             * navegador saca el contenido de su lugar.
             */
            ->description(fn (): HtmlString => $this->resumen())
            /*
             * ═══ CINCO COLUMNAS, NO NUEVE (8-OCT-2026) ═══
             *
             * «Mejorar un poco más lo de gastos, mejor diseño» — Mauricio,
             * con la pestaña abierta en producción. Eran nueve columnas en
             * un ancho de mil pixeles: la tabla se corría de lado, el
             * detalle quedaba en una tira de cinco renglones y cada fila
             * medía lo que una tarjeta.
             *
             * Ahora cada celda dice dos cosas, una fuerte y una de apoyo:
             *
             *   Fecha      — la del comprobante, y debajo el folio.
             *   Detalle    — lo que se compró, y debajo el rubro con su
             *                color y a quién se le pagó.
             *   Se le carga a — el socio o «Al proyecto».
             *   Monto      — la cifra, y debajo cómo se pagó.
             *
             * El comprobante dejó de ser una columna: el clip aparece entre
             * las acciones solo cuando hay archivo, y el filtro «Sin
             * comprobante adjunto» sigue para buscar los que faltan.
             */
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->weight('semibold')
                    ->description(static fn (Gasto $record): string => $record->folio())
                    /*
                     * El folio ya no tiene columna, pero se sigue buscando:
                     * «G-000012», «000012» y «12» son el mismo gasto. Se
                     * quedan los dígitos y se compara contra el número.
                     *
                     * Sin dígitos —alguien buscando «cemento»— no agrega
                     * condición: Laravel descarta el grupo vacío y la
                     * búsqueda sigue por las demás columnas. Nueve dígitos
                     * alcanzan para cualquier folio y no desbordan un integer.
                     */
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        $digitos = preg_replace('/\D+/', '', $search) ?? '';

                        return $digitos === '' || strlen($digitos) > 9
                            ? $query
                            : $query->where('gastos.numero', (int) $digitos);
                    })
                    ->sortable(),

                TextColumn::make('descripcion')
                    ->label('Detalle')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(static fn (Gasto $record): string => (string) $record->getAttribute('descripcion'))
                    ->description(static fn (Gasto $record): HtmlString => self::rubroYBeneficiario($record))
                    // Busca también por el beneficiario, que ya no tiene
                    // columna propia: vive en la línea de abajo.
                    ->searchable(['descripcion', 'beneficiario']),

                /*
                 * A quién se le carga (8-oct-2026). Vacío es «al proyecto»:
                 * lo pagan todos según su parte, y va en gris porque es lo
                 * de siempre. El socio va en azul con su ícono: ese gasto NO
                 * baja la utilidad de los demás, se le descuenta solo a él en
                 * el Estado mensual, y tiene que saltar a la vista sin gritar.
                 */
                TextColumn::make('socio.nombre')
                    ->label('Se le carga a')
                    ->badge()
                    ->default('Al proyecto')
                    ->color(static fn (Gasto $record): string => $record->esDeUnSocio() ? 'info' : 'gray')
                    ->icon(static fn (Gasto $record): Heroicon => $record->esDeUnSocio() ? Heroicon::OutlinedUser : Heroicon::OutlinedBuildingOffice2),

                /*
                 * ⚠️ Ni `->money()` ni `->numeric()`: los dos castean a float
                 * (§9.A13/A14). El formato sale de `Monto`, que es el mismo
                 * que imprime el recibo.
                 */
                TextColumn::make('monto')
                    ->label('Monto')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(static fn (Gasto $record): string => $record->monto()->formateado())
                    ->description(static fn (Gasto $record): string => $record->getAttribute('forma_pago') instanceof FormaDePago
                        ? $record->getAttribute('forma_pago')->etiqueta()
                        : '')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('categoria')
                    ->label('Categoría')
                    ->options(CategoriaDeGasto::opciones())
                    ->multiple(),

                SelectFilter::make('forma_pago')
                    ->label('Forma de pago')
                    ->options(GastoForm::formasDePago()),

                SelectFilter::make('cargo')
                    ->label('Se le carga a')
                    ->options(fn (): array => GastoForm::opcionesDelFiltro($this->proyecto()))
                    ->query(static function (Builder $query, array $data): Builder {
                        $valor = $data['value'] ?? null;

                        if ($valor === GastoForm::AL_PROYECTO) {
                            return $query->whereNull('socio_id');
                        }

                        return is_numeric($valor) ? $query->where('socio_id', (int) $valor) : $query;
                    }),

                Filter::make('este_mes')
                    ->label('Solo este mes')
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereBetween('fecha', [today()->startOfMonth(), today()->endOfMonth()])),

                Filter::make('sin_comprobante')
                    ->label('Sin comprobante adjunto')
                    ->query(static fn (Builder $query): Builder => $query->whereNull('archivo')),
            ])
            ->headerActions([
                /*
                 * El alta pasa por el Service y no por el `create()` de la
                 * relación: el número de comprobante se consume con
                 * `SELECT … FOR UPDATE` y `ConsumoDeCorrelativos` se niega a
                 * numerar fuera de una transacción. Ver `RegistroDeGastos`.
                 */
                CreateAction::make()
                    ->label('Registrar un gasto')
                    ->modalIcon(Heroicon::OutlinedBanknotes)
                    ->modalHeading('Registrar un gasto')
                    ->modalWidth('2xl')
                    ->modalSubmitActionLabel('Registrar el gasto')
                    ->using(fn (array $data): Gasto => resolve(RegistroDeGastos::class)->registrar(
                        /*
                         * Se relee y no se castea `getOwnerRecord()`: viene
                         * tipado `Model` y `findOrFail()` esta declarado
                         * `Modelo|Collection` —acepta un arreglo de ids—, con
                         * lo que cada llamada aguas abajo se vuelve un error
                         * de PHPStan. `whereKey()->firstOrFail()` devuelve
                         * `Proyecto` y punto.
                         */
                        Proyecto::query()->whereKey($this->getOwnerRecord()->getKey())->firstOrFail(),
                        $data,
                    )),
            ])
            ->recordActions([
                /*
                 * La descarga es una ACCION y no un enlace: pasa por la
                 * política antes de abrir el archivo. Una factura trae el
                 * nombre del proveedor, su RTN y montos que no son públicos.
                 */
                /*
                 * Las tres como ÍCONO con su globito (8-oct-2026): con texto
                 * se llevaban un cuarto del ancho de la tabla. El clip solo
                 * aparece si hay comprobante, así que de paso dice cuáles lo
                 * tienen sin necesidad de una columna.
                 */
                Action::make('comprobante')
                    ->label('Comprobante')
                    ->tooltip('Descargar el comprobante')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->color('gray')
                    ->visible(static fn (Gasto $record): bool => $record->tieneComprobante()
                        && auth()->user()?->can('view', $record) === true)
                    ->action(static fn (Gasto $record): StreamedResponse => Storage::disk(Gasto::DISCO)
                        ->download((string) $record->getAttribute('archivo'), $record->nombreDeDescarga())),

                EditAction::make()
                    ->tooltip('Editar')
                    ->iconButton()
                    ->modalWidth('2xl')
                    ->modalDescription('Todo lo que cambie queda en la bitácora, con tu nombre y la hora.'),

                DeleteAction::make()
                    ->tooltip('Borrar')
                    ->iconButton()
                    ->modalDescription('El gasto desaparece del total del proyecto. Si lo que está mal es el monto, es mejor editarlo: así queda el rastro de la corrección.')
                    ->after(static function (Gasto $record): void {
                        // Sin esto el comprobante queda huérfano en el disco:
                        // ocupando espacio, sin ninguna fila que lo nombre y
                        // sin forma de saber a qué gasto pertenecía.
                        if ($record->tieneComprobante()) {
                            Storage::disk(Gasto::DISCO)->delete((string) $record->getAttribute('archivo'));
                        }
                    }),
            ])
            ->defaultSort('fecha', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Este proyecto todavía no tiene gastos registrados')
            ->emptyStateDescription('Acá va lo que el desarrollo cuesta: terracería, calles, agua, el abogado, la planilla. Es la otra mitad de la pregunta que el sistema ya contestaba a medias.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    // ─── El cuadro de totales ─────────────────────────────────────────

    /**
     * Cuánto se lleva gastado: el total, quién lo paga y en qué rubros.
     *
     * ═══ COMO SE VE (8-OCT-2026) ═══
     *
     * Era un párrafo —«Total gastado L. 485,125.00 en 6 movimientos. Suma lo
     * que se está viendo…»— con las pastillas de los rubros debajo. Ahora son
     * cifras en tarjetas, con el rótulo en versalitas como en el Escritorio:
     *
     *   TOTAL GASTADO · DEL PROYECTO · CARGADO A SOCIOS
     *
     * Las dos últimas salen SOLO si en lo que se está viendo hay algún gasto
     * cargado a un socio: sin cargos, «del proyecto» sería el total repetido.
     * Y «del proyecto» es lo que entra en la utilidad que se reparte; lo
     * cargado se le descuenta a cada socio en el Estado mensual.
     *
     * Sale de la consulta FILTRADA, así que contesta por lo que se está
     * mirando. Las clases CSS viven en el partial `filament.estilos-olympo`
     * —el CSS de Filament está precompilado y no ve las clases que arma un
     * `HtmlString` del lado de PHP (§9.A7)—, y todo es `span`: esto se
     * imprime DENTRO del `<p>` de la descripción de la tabla.
     */
    private function resumen(): HtmlString
    {
        $rubros = $this->totalesPorCategoria();

        if ($rubros === []) {
            // `span` y no `p`: esto se imprime DENTRO de un parrafo.
            return new HtmlString('<span class="olympo-vacio">Todavía no hay nada que sumar.</span>');
        }

        $total = Monto::cero();
        $movimientos = 0;

        foreach ($rubros as $rubro) {
            $total = $total->sumar($rubro['total']);
            $movimientos += $rubro['cuantos'];
        }

        $cifras = $this->cifra('Total gastado', $total, sprintf('%d movimiento%s', $movimientos, $movimientos === 1 ? '' : 's'));

        $cargos = $this->cargadoASocios();

        if ($cargos !== []) {
            $cargado = Monto::cero();
            $quienes = '';

            foreach ($cargos as $cargo) {
                $cargado = $cargado->sumar($cargo['total']);
                $quienes .= sprintf(
                    '<span class="olympo-gastos-nota">%s · %s</span>',
                    e($cargo['nombre']),
                    e($cargo['total']->formateado()),
                );
            }

            $cifras .= $this->cifra('Del proyecto', $total->restar($cargado), 'Lo pagan todos, según su parte');

            $cifras .= sprintf(
                '<span class="olympo-gastos-cifra socios"><span class="olympo-gastos-rotulo">Cargado a socios</span>'
                .'<span class="olympo-gastos-monto">%s</span>%s</span>',
                e($cargado->formateado()),
                $quienes,
            );
        }

        $pastillas = '';

        foreach ($rubros as $rubro) {
            $pastillas .= sprintf(
                '<span class="olympo-gastos-rubro">%s%s <strong>%s</strong> <span class="olympo-gastos-tenue">%s</span></span>',
                self::punto($rubro['categoria']->color()),
                e($rubro['categoria']->etiqueta()),
                e($rubro['total']->formateado()),
                e($this->porcentaje($rubro['total'], $total)),
            );
        }

        return new HtmlString(
            '<span class="olympo-gastos">'
            .'<span class="olympo-gastos-cifras">'.$cifras.'</span>'
            .'<span class="olympo-gastos-rubros">'.$pastillas.'</span>'
            .'<span class="olympo-gastos-pie">Suma lo que se está viendo: con un filtro puesto, las cifras son las del filtro.</span>'
            .'</span>'
        );
    }

    /**
     * Una tarjeta del resumen: el rótulo, la cifra y una línea de apoyo.
     */
    private function cifra(string $rotulo, Monto $monto, string $nota): string
    {
        return sprintf(
            '<span class="olympo-gastos-cifra"><span class="olympo-gastos-rotulo">%s</span>'
            .'<span class="olympo-gastos-monto">%s</span><span class="olympo-gastos-nota">%s</span></span>',
            e($rotulo),
            e($monto->formateado()),
            e($nota),
        );
    }

    /**
     * Lo cargado a cada socio en lo que se está viendo, de mayor a menor.
     *
     * Los nombres se buscan con `withTrashed()`: lo que se le cargó a un socio
     * que ya salió sigue siendo suyo, y es justo el renglón que alguien va a
     * preguntar.
     *
     * @return list<array{nombre: string, total: Monto}>
     */
    private function cargadoASocios(): array
    {
        $consulta = $this->getFilteredTableQuery();

        if (! $consulta instanceof Builder) {
            return [];
        }

        // Las mismas dos trampas que `totalesPorCategoria()`: `reorder()`
        // antes del `GROUP BY`, y la suma en Postgres, nunca en float.
        $filas = $consulta->clone()
            ->reorder()
            ->toBase()
            ->select([])
            ->selectRaw('gastos.socio_id AS socio_id, COALESCE(SUM(gastos.monto), 0) AS total')
            ->whereNotNull('gastos.socio_id')
            ->groupBy('gastos.socio_id')
            ->orderByDesc('total')
            ->get();

        $totales = [];

        foreach ($filas as $fila) {
            $datos = (array) $fila;

            $id = $datos['socio_id'] ?? null;
            $total = $datos['total'] ?? '0';

            if (! is_int($id) && (! is_string($id) || ! ctype_digit($id))) {
                continue;
            }

            $totales[(int) $id] = new Monto(is_string($total) || is_int($total) ? $total : '0');
        }

        if ($totales === []) {
            return [];
        }

        $nombres = Socio::query()
            ->withTrashed()
            ->whereKey(array_keys($totales))
            ->pluck('nombre', 'id');

        $cargos = [];

        foreach ($totales as $id => $total) {
            $nombre = $nombres->get($id);

            $cargos[] = [
                'nombre' => is_string($nombre) ? $nombre : 'Socio',
                'total'  => $total,
            ];
        }

        return $cargos;
    }

    /**
     * @return list<array{categoria: CategoriaDeGasto, total: Monto, cuantos: int}>
     */
    private function totalesPorCategoria(): array
    {
        $consulta = $this->getFilteredTableQuery();

        if (! $consulta instanceof Builder) {
            return [];
        }

        /*
         * ⚠️ `reorder()` antes del `GROUP BY`: el `defaultSort` de la tabla
         * sobrevive al agregado y Postgres lo rechaza con un 42803.
         *
         * Y la suma la hace Postgres, no `->sum()` del query builder: ese
         * método castea a float y el §8.3.1 lo prohíbe en el camino del
         * dinero. PDO devuelve NUMERIC como string, que es lo que come bcmath.
         */
        $filas = $consulta->clone()
            ->reorder()
            ->toBase()
            /*
             * `select([])` antes del `selectRaw`: este ultimo AGREGA columnas,
             * no las reemplaza. Si la tabla ya trajera alguna seleccionada,
             * quedaria fuera del `GROUP BY` y Postgres tiraria el mismo 42803.
             */
            ->select([])
            ->selectRaw('categoria, COALESCE(SUM(monto), 0) AS total, COUNT(*) AS cuantos')
            ->groupBy('categoria')
            ->orderByDesc('total')
            ->get();

        $renglones = [];

        foreach ($filas as $fila) {
            // `first()`/`get()` del query builder entregan stdClass y PHPStan
            // no reconoce ninguna propiedad ahí: se convierte en el borde.
            $datos = (array) $fila;

            $valor = $datos['categoria'] ?? null;
            $categoria = CategoriaDeGasto::tryFrom(is_string($valor) ? $valor : '');

            if (! $categoria instanceof CategoriaDeGasto) {
                continue;
            }

            $total = $datos['total'] ?? '0';

            $renglones[] = [
                'categoria' => $categoria,
                'total'     => new Monto(is_string($total) || is_int($total) ? $total : '0'),
                'cuantos'   => (int) ($datos['cuantos'] ?? 0),
            ];
        }

        return $renglones;
    }

    /**
     * Qué tajada del gasto se fue en este rubro.
     *
     * Es el número que contesta «¿en qué se me va la plata?» sin obligar a
     * hacer la división a mano, y por eso va al lado del monto y no en otra
     * pantalla.
     */
    private function porcentaje(Monto $parte, Monto $total): string
    {
        if ($total->esCero()) {
            return '—';
        }

        return $parte->multiplicarPor(100)->dividirPor($total->valor)->redondeado(1).' %';
    }

    // ─── Las celdas de la tabla ───────────────────────────────────────

    /**
     * La línea de apoyo del detalle: el rubro con su color, y a quién se le
     * pagó si se anotó. «● Comisiones de venta · DAGOBERTO AGUILAR».
     */
    private static function rubroYBeneficiario(Gasto $gasto): HtmlString
    {
        $partes = [];

        $categoria = $gasto->getAttribute('categoria');

        if ($categoria instanceof CategoriaDeGasto) {
            $partes[] = self::punto($categoria->color()).e($categoria->etiqueta());
        }

        $beneficiario = $gasto->getAttribute('beneficiario');

        if (is_string($beneficiario) && $beneficiario !== '') {
            $partes[] = e($beneficiario);
        }

        return new HtmlString(sprintf(
            '<span class="olympo-gastos-linea">%s</span>',
            implode('<span class="olympo-gastos-tenue"> · </span>', $partes),
        ));
    }

    /**
     * El puntito de color del rubro: el mismo color que tenía su badge.
     *
     * El nombre del color termina dentro de un `style`, así que solo pasan los
     * seis del panel; cualquier otra cosa cae en gris.
     */
    private static function punto(string $color): string
    {
        $color = in_array($color, ['primary', 'gray', 'info', 'success', 'warning', 'danger'], true) ? $color : 'gray';

        return sprintf('<span class="olympo-gastos-punto" style="background: var(--%s-500);"></span>', $color);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El proyecto de la ficha, ya tipado.
     *
     * `getOwnerRecord()` viene declarado `Model`; acá siempre es un
     * `Proyecto`, y si algún día no lo fuera se relee en vez de castear.
     */
    private function proyecto(): Proyecto
    {
        $dueno = $this->getOwnerRecord();

        return $dueno instanceof Proyecto
            ? $dueno
            : Proyecto::query()->whereKey($dueno->getKey())->firstOrFail();
    }
}
