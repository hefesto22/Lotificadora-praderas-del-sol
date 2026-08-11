<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Domain\Archivos\GuardadoDeArchivos;
use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\FormaDePago;
use App\Domain\Gastos\RegistroDeGastos;
use App\Domain\ValueObjects\Monto;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Gasto;
use App\Models\Proyecto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('fecha')
                    ->label('Fecha del gasto')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(today())
                    /*
                     * No se puede fechar un gasto en el futuro. No lo frena la
                     * base —un CHECK con CURRENT_DATE no es inmutable y
                     * Postgres no lo admite—, así que la raya se pone acá.
                     */
                    ->maxDate(today())
                    ->helperText('La del comprobante, no la de hoy si son distintas.'),

                Select::make('categoria')
                    ->label('En qué se gastó')
                    ->options(CategoriaDeGasto::opciones())
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->helperText('Es lo que después permite sumar por rubro.'),

                Textarea::make('descripcion')
                    ->label('Detalle')
                    ->required()
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->helperText('Concreto: «cunetas del bloque H, segunda etapa». Dentro de un año «Materiales — L 48,000» no le dice nada a nadie.'),

                MontoField::make('monto', 'Monto')
                    // La base exige > 0 (`gastos_monto_positivo_chk`). Acá se
                    // dice antes, para que no se entere al guardar.
                    ->rules(['numeric', 'decimal:0,2', 'gt:0']),

                MayusculasField::make('beneficiario')
                    ->label('A quién se le pagó')
                    ->maxLength(120)
                    ->helperText('Opcional: hay gastos sin contraparte con nombre.'),

                Select::make('forma_pago')
                    ->label('Cómo se pagó')
                    ->options($this->formasDePago())
                    ->required()
                    ->live()
                    ->native(false)
                    ->default(FormaDePago::Efectivo->value),

                TextInput::make('referencia')
                    ->label('Número de referencia')
                    ->maxLength(60)
                    ->visible(static fn (Get $get): bool => self::exigeReferencia($get))
                    ->required(static fn (Get $get): bool => self::exigeReferencia($get))
                    ->helperText('Es lo único que después permite cruzar esta salida contra el estado de cuenta del banco (R11).'),

                TextInput::make('factura')
                    ->label('Nº de factura o recibo')
                    ->maxLength(60)
                    ->helperText('El del papel que dio el proveedor. No se confunde con la referencia del banco.'),

                /*
                 * `preserveFilenames` NO: el nombre original trae acentos,
                 * espacios y a veces el nombre del proveedor. Filament le pone
                 * un uuid, y el nombre legible se arma al descargar.
                 */
                FileUpload::make('archivo')
                    ->label('Comprobante')
                    ->disk(Gasto::DISCO)
                    ->directory('gastos')
                    ->visibility('private')
                    ->maxSize(10240)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                    ->columnSpanFull()
                    ->helperText('La foto de la factura o el PDF, hasta 10 MB. Las fotos se guardan convertidas a WebP: pesan seis veces menos y abren al instante.')
                    /*
                     * 🔴 El guardado pasa por `GuardadoDeArchivos`: una foto de
                     * teléfono entra en 2–5 MB y sale en WebP de 250–400 KB,
                     * con el lado largo topado en 2,400 px. El PDF pasa
                     * intacto, y ante cualquier problema —GD sin WebP, imagen
                     * corrupta— se guarda el original: un comprobante no se
                     * pierde por optimizarlo.
                     *
                     * El PESO no se calcula acá. Lo lee el modelo del disco
                     * después de convertir, que es el único momento en que el
                     * número es cierto.
                     */
                    /*
                     * 🔴 LOS NOMBRES `$component` Y `$file` SON UN CONTRATO.
                     *
                     * Filament evalúa este closure con `evaluate($callback,
                     * ['file' => $file])`: busca los parámetros POR NOMBRE en
                     * ese arreglo y, al que no encuentra, lo pide al
                     * contenedor. Llamarle `$subido` hace que Filament intente
                     * construir un `TemporaryUploadedFile` desde cero y
                     * reviente con `BindingResolutionException:
                     * Unresolvable dependency [$path]`.
                     *
                     * Por eso van en inglés, contra el estilo del resto del
                     * repo: acá el nombre no es una decisión nuestra.
                     */
                    ->saveUploadedFileUsing(static fn (BaseFileUpload $component, TemporaryUploadedFile $file): ?string => resolve(GuardadoDeArchivos::class)->guardar($component, $file)),
            ]);
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
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('numero')
                    ->label('Nº')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (Gasto $record): string => $record->folio())
                    ->searchable()
                    ->sortable(),

                TextColumn::make('categoria')
                    ->label('Categoría')
                    ->badge()
                    ->color(static fn (Gasto $record): string => $record->getAttribute('categoria') instanceof CategoriaDeGasto
                        ? $record->getAttribute('categoria')->color()
                        : 'gray')
                    ->formatStateUsing(static fn (Gasto $record): string => $record->getAttribute('categoria') instanceof CategoriaDeGasto
                        ? $record->getAttribute('categoria')->etiqueta()
                        : '—')
                    ->sortable(),

                TextColumn::make('descripcion')
                    ->label('Detalle')
                    ->wrap()
                    ->limit(70)
                    ->tooltip(static fn (Gasto $record): string => (string) $record->getAttribute('descripcion'))
                    ->searchable(),

                TextColumn::make('beneficiario')
                    ->label('A quién')
                    ->placeholder('—')
                    ->toggleable()
                    ->searchable(),

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
                    ->sortable(),

                TextColumn::make('forma_pago')
                    ->label('Forma')
                    ->badge()
                    ->color(static fn (Gasto $record): string => $record->getAttribute('forma_pago') instanceof FormaDePago
                        ? $record->getAttribute('forma_pago')->color()
                        : 'gray')
                    ->formatStateUsing(static fn (Gasto $record): string => $record->getAttribute('forma_pago') instanceof FormaDePago
                        ? $record->getAttribute('forma_pago')->etiqueta()
                        : '—')
                    ->toggleable(),

                IconColumn::make('archivo')
                    ->label('Comprobante')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->state(static fn (Gasto $record): bool => $record->tieneComprobante()),
            ])
            ->filters([
                SelectFilter::make('categoria')
                    ->label('Categoría')
                    ->options(CategoriaDeGasto::opciones())
                    ->multiple(),

                SelectFilter::make('forma_pago')
                    ->label('Forma de pago')
                    ->options($this->formasDePago()),

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
                    ->modalHeading('Registrar un gasto del proyecto')
                    ->modalSubmitActionLabel('Registrar')
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
                Action::make('comprobante')
                    ->label('Comprobante')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(static fn (Gasto $record): bool => $record->tieneComprobante()
                        && auth()->user()?->can('view', $record) === true)
                    ->action(static fn (Gasto $record): StreamedResponse => Storage::disk(Gasto::DISCO)
                        ->download((string) $record->getAttribute('archivo'), $record->nombreDeDescarga())),

                EditAction::make()
                    ->modalDescription('Todo lo que cambie queda en la bitácora, con tu nombre y la hora.'),

                DeleteAction::make()
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
     * Cuánto se lleva gastado, repartido por categoría.
     *
     * Sale de la consulta FILTRADA, así que contesta por lo que se está
     * mirando. Las clases CSS viven en el partial `filament.estilos-olympo`
     * —el CSS de Filament está precompilado y no ve las clases que arma un
     * `HtmlString` del lado de PHP (§9.A7)—.
     */
    private function resumen(): HtmlString
    {
        $renglones = $this->totalesPorCategoria();

        if ($renglones === []) {
            // `span` y no `p`: esto se imprime DENTRO de un parrafo.
            return new HtmlString('<span class="olympo-vacio">Todavía no hay nada que sumar.</span>');
        }

        $total = Monto::cero();
        $movimientos = 0;

        foreach ($renglones as $renglon) {
            $total = $total->sumar($renglon['total']);
            $movimientos += $renglon['cuantos'];
        }

        $pastillas = '';

        foreach ($renglones as $renglon) {
            $pastillas .= sprintf(
                '<span class="olympo-pill" style="margin: 0 .375rem .375rem 0;">%s — %s · %s</span>',
                e($renglon['categoria']->etiqueta()),
                e($renglon['total']->formateado()),
                e($this->porcentaje($renglon['total'], $total)),
            );
        }

        return new HtmlString(
            '<span style="display: block; margin-bottom: .5rem;">'
            .'<strong style="font-size: 1rem; font-variant-numeric: tabular-nums;">Total gastado '
            .e($total->formateado()).'</strong> '
            .'<span>en '.e((string) $movimientos).' movimiento'.($movimientos === 1 ? '' : 's')
            .'. Suma lo que se está viendo: si hay un filtro puesto, el total es el del filtro.</span>'
            .'</span>'
            .$pastillas
        );
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

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function formasDePago(): array
    {
        $opciones = [];

        foreach (FormaDePago::cases() as $forma) {
            $opciones[$forma->value] = $forma->etiqueta();
        }

        return $opciones;
    }

    /**
     * R11: en todo lo que no es efectivo la referencia es obligatoria, y el
     * CHECK `gastos_referencia_segun_forma_chk` no admite lo contrario.
     */
    private static function exigeReferencia(Get $get): bool
    {
        $forma = FormaDePago::tryFrom(is_string($get('forma_pago')) ? $get('forma_pago') : '');

        return $forma instanceof FormaDePago && $forma->exigeReferencia();
    }
}
