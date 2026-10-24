<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\FormaDePago;
use App\Domain\Reportes\CajaPorDia;
use App\Domain\Reportes\DiaDeCaja;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Recibos\ReciboResource;
use App\Models\Proyecto;
use App\Support\ProyectoActivo;
use App\Support\Roles;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Override;

/**
 * La caja: cuánto dinero entró cada día — 24-oct-2026.
 *
 * ═══ QUE ES Y QUE NO ES ═══
 *
 * Mauricio: «función de caja, aunque solo será para reportes de lo que se
 * recibió qué día … nadie la abrirá». Es un reporte: no se abre, no se
 * cierra, no se cuadra contra nada que alguien haya tecleado. Lee los
 * recibos y los ordena por día. El cálculo vive en `CajaPorDia`; acá solo
 * se elige qué mirar y se dibuja.
 *
 * ═══ POR QUE SIN GRUPO, PEGADA AL ESCRITORIO ═══
 *
 * «Ahí abajo de Escritorio, que diga Caja». Las entradas sin grupo salen
 * arriba de todo, junto al Escritorio, y el orden -1 la deja justo debajo
 * (Filament le pone -2 al Escritorio). Meterla en «Día a día» la habría
 * dejado debajo de «Por cobrar hoy», que no es lo pedido.
 *
 * ═══ LO QUE SE ELIGE ═══
 *
 * Desde, hasta y los proyectos —uno, dos o los que sean—. Sin ninguno
 * elegido suma todos. Abre en el mes corriente y en el proyecto del
 * interruptor de la barra, si hay uno: es lo que la persona ya está
 * mirando.
 *
 * ═══ EL PAPEL (24-oct-2026, la misma tarde) ═══
 *
 * «¿No hay un botón como para imprimir el reporte de lo que se selecciona?»
 * — Mauricio, ya en producción. «Imprimir» abre `/documentos/caja` con el
 * rango y los proyectos de la pantalla, en la misma ventana flotante del
 * Estado mensual. La hoja la arma `ReporteDeCajaController` con el mismo
 * `CajaPorDia`: lo que se imprime es lo que se estaba mirando.
 *
 * ═══ QUIEN LA VE ═══
 *
 * Quien ve la lista de recibos. Pero el receptor ve SOLO lo que recibió él
 * —la misma regla del corte de caja del Escritorio, `Roles::veLaCajaDeTodos()`—
 * porque «NO ve el arqueo de otro receptor». El papel aplica la misma regla
 * por su cuenta: no confía en lo que diga la URL.
 *
 * @property Schema $form
 */
class Caja extends Page
{
    #[Override]
    protected string $view = 'filament.pages.caja';

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    #[Override]
    protected static ?int $navigationSort = -1;

    /** Los recibos que se listan al abrir un día. El renglón dice si quedaron más. */
    private const int RECIBOS_EN_EL_DETALLE = 200;

    /**
     * @var array<string, mixed>
     */
    public array $filtros = [];

    /**
     * El día cuyo detalle está abierto, `YYYY-MM-DD`. Uno a la vez.
     */
    public ?string $diaAbierto = null;

    #[Override]
    public function getTitle(): string
    {
        return 'Caja';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Caja';
    }

    /**
     * La misma llave que el corte de caja del Escritorio y que la lista de
     * recibos: esto es esa lista, sumada por día.
     */
    #[Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:Recibo') === true;
    }

    public function mount(): void
    {
        $hoy = $this->hoy();
        $elegido = app(ProyectoActivo::class)->id();

        $this->form->fill([
            'desde'     => $hoy->startOfMonth()->toDateString(),
            'hasta'     => $hoy->toDateString(),
            'proyectos' => $elegido === null ? [] : [$elegido],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filtros')
            ->components([
                Section::make('Qué días, y de qué proyectos')
                    ->description('Cada renglón de abajo es un día; al tocarlo se abren sus recibos.')
                    ->schema([
                        DatePicker::make('desde')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (): void {
                                $this->diaAbierto = null;
                            }),

                        DatePicker::make('hasta')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (): void {
                                $this->diaAbierto = null;
                            }),

                        /*
                         * Vacío = todos. No hay una opción «Todos» adentro de
                         * la lista: mezclada con los proyectos, alguien la
                         * marcaría junto con uno y no se sabría qué quiso.
                         *
                         * Con un solo proyecto en la instalación no se dibuja:
                         * no hay nada que elegir.
                         */
                        Select::make('proyectos')
                            ->label('Proyectos')
                            ->multiple()
                            ->options(fn (): array => Proyecto::query()
                                ->reorder()
                                ->orderBy('nombre')
                                ->pluck('nombre', 'id')
                                ->all())
                            ->placeholder('Todos los proyectos')
                            ->helperText('Vacío suma todos.')
                            ->live()
                            ->afterStateUpdated(function (): void {
                                $this->diaAbierto = null;
                            })
                            ->hidden(fn (): bool => Proyecto::query()->count() < 2),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * Los tres rangos que se piden siempre, a un clic, y el papel.
     *
     * @return array<Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        $hoy = $this->hoy();
        $mesPasado = $hoy->subMonthNoOverflow();

        return [
            Action::make('hoy')
                ->label('Hoy')
                ->color('gray')
                ->action(function () use ($hoy): void {
                    $this->verDel($hoy, $hoy);
                }),

            Action::make('este_mes')
                ->label('Este mes')
                ->color('gray')
                ->action(function () use ($hoy): void {
                    $this->verDel($hoy->startOfMonth(), $hoy);
                }),

            Action::make('mes_pasado')
                ->label('Mes pasado')
                ->color('gray')
                ->action(function () use ($mesPasado): void {
                    $this->verDel($mesPasado->startOfMonth(), $mesPasado->endOfMonth()->startOfDay());
                }),

            /*
             * El papel de lo que se está mirando, en la ventana flotante del
             * Estado mensual (el porqué del `<iframe>` está en su marco).
             *
             * El único botón de color: los otros tres eligen QUÉ mirar; este
             * es lo que se viene a hacer con lo que se eligió.
             */
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->modalHeading('Reporte de caja')
                ->modalDescription(fn (): string => $this->caja()->periodoEscrito().' · '.$this->proyectosEscritos())
                ->modalWidth(Width::FourExtraLarge)
                ->modalContent(fn (): View => view('filament.pages.estado-mensual-marco', [
                    'url'    => $this->urlDelReporte(),
                    'titulo' => 'Reporte de caja',
                ]))
                // No hay nada que guardar: se mira, se imprime y se cierra.
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar'),
        ];
    }

    /**
     * Abre o cierra el detalle de un día. Lo llama el renglón de la tabla.
     */
    public function abrir(string $dia): void
    {
        // Llega del navegador: se valida la forma antes de guardarla.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) !== 1) {
            return;
        }

        $this->diaAbierto = $this->diaAbierto === $dia ? null : $dia;
    }

    // ─── Lo que lee la vista ──────────────────────────────────────────

    public function caja(): CajaPorDia
    {
        $periodo = $this->periodo();

        return CajaPorDia::de(
            desde: $periodo['desde'],
            hasta: $periodo['hasta'],
            proyectos: $this->proyectosElegidos(),
            recibidoPor: $this->soloDe(),
        );
    }

    /**
     * La dirección del papel, con lo que está elegido en pantalla.
     *
     * ⚠️ Lleva el rango y los proyectos, NUNCA a quién recortar: eso lo decide
     * el controlador con el usuario conectado. Una URL que dijera «todo»
     * no puede hacer que un receptor vea la caja de otro.
     */
    public function urlDelReporte(): string
    {
        $periodo = $this->periodo();
        $proyectos = $this->proyectosElegidos();

        return route('documentos.caja', array_filter([
            'desde'     => $periodo['desde']->toDateString(),
            'hasta'     => $periodo['hasta']->toDateString(),
            'proyectos' => $proyectos === [] ? null : implode(',', $proyectos),
        ], static fn (?string $valor): bool => $valor !== null));
    }

    /**
     * Lo que se ve al abrir un día: sus recibos y quién recibió el dinero.
     *
     * @return array{recibos: list<array{folio: string, url: string, nombre: string, lotes: string, concepto: string, forma: string, recibio: string, monto: string}>, quienes: list<array{nombre: string, total: Monto}>, faltan: int}
     */
    public function detalleDelDia(DiaDeCaja $dia): array
    {
        $proyectos = $this->proyectosElegidos();
        $soloDe = $this->soloDe();

        $recibos = [];

        foreach (CajaPorDia::renglones($dia->fecha, $dia->fecha, $proyectos, $soloDe, self::RECIBOS_EN_EL_DETALLE) as $renglon) {
            $recibos[] = [
                'folio'    => $renglon['folio'],
                'url'      => ReciboResource::getUrl('view', ['record' => $renglon['id']]),
                'nombre'   => $renglon['nombre'],
                'lotes'    => $renglon['lotes'],
                'concepto' => $renglon['concepto'],
                'forma'    => $renglon['forma'],
                'recibio'  => $renglon['recibio'],
                'monto'    => $renglon['monto']->formateado(),
            ];
        }

        return [
            'recibos' => $recibos,
            // Al receptor no: todo lo que ve es suyo, y la línea diría su nombre y el total que ya está arriba.
            'quienes' => $this->soloLoMio() ? [] : CajaPorDia::quienesRecibieron($dia->fecha, $dia->fecha, $proyectos, $soloDe),
            'faltan'  => max(0, $dia->recibos - count($recibos)),
        ];
    }

    /**
     * «Jueves 1 de octubre», y «· hoy» cuando es hoy.
     */
    public function nombreDelDia(CajaPorDia $caja, DiaDeCaja $dia): string
    {
        $texto = $caja->nombreDelDia($dia);

        return $dia->fecha->isSameDay($this->hoy()) ? $texto.' · hoy' : $texto;
    }

    /**
     * «Del 1 al 24 de octubre de 2026 · Todos los proyectos».
     */
    public function periodoEscrito(CajaPorDia $caja): string
    {
        return $caja->periodoEscrito().' · '.$this->proyectosEscritos();
    }

    /**
     * La línea de abajo del total: cuántos papeles y en cuántos días.
     */
    public function cuantosRecibos(CajaPorDia $caja): string
    {
        $dias = count($caja->dias);

        return sprintf(
            '%d recibo%s en %d día%s con cobro',
            $caja->recibos,
            $caja->recibos === 1 ? '' : 's',
            $dias,
            $dias === 1 ? '' : 's',
        );
    }

    /**
     * Lo que entró por banco, abierto por forma. El efectivo va aparte.
     */
    public function desgloseDelBanco(CajaPorDia $caja): string
    {
        $partes = [];

        foreach ($caja->formas() as $forma) {
            if ($forma === FormaDePago::Efectivo) {
                continue;
            }

            $partes[] = $forma->etiqueta().' '.$caja->deLaForma($forma)->formateado();
        }

        return $partes === [] ? 'Nada por banco ni tarjeta' : implode(' · ', $partes);
    }

    /**
     * Un receptor ve solo lo que recibió él (ver la cabecera de la clase).
     */
    public function soloLoMio(): bool
    {
        return ! Roles::veLaCajaDeTodos(auth()->user());
    }

    /**
     * El rango que de verdad se muestra: ordenado, y con tope de un año.
     *
     * ⚠️ Lee `$this->filtros` y NO `$this->form->getState()`: la vista lo
     * pide en cada dibujo, y `getState()` valida — una fecha borrada a medio
     * escribir tiraría una excepción en vez de dejar la pantalla como estaba.
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable, recortado: bool}
     */
    public function periodo(): array
    {
        $hoy = $this->hoy();

        return CajaPorDia::acotar(
            $this->fechaDelFiltro('desde') ?? $hoy->startOfMonth(),
            $this->fechaDelFiltro('hasta') ?? $hoy,
        );
    }

    // ─── Interno ──────────────────────────────────────────────────────

    private function verDel(CarbonImmutable $desde, CarbonImmutable $hasta): void
    {
        $this->form->fill([
            ...$this->filtros,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
        ]);

        // Un solo día se abre solo: es lo que se vino a ver.
        $this->diaAbierto = $desde->isSameDay($hasta) ? $desde->toDateString() : null;
    }

    /**
     * @return list<int>
     */
    private function proyectosElegidos(): array
    {
        $elegidos = $this->filtros['proyectos'] ?? [];

        if (! is_array($elegidos)) {
            return [];
        }

        $ids = [];

        foreach ($elegidos as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function proyectosEscritos(): string
    {
        return CajaPorDia::proyectosEscritos($this->proyectosElegidos());
    }

    /**
     * El usuario al que se recorta la caja, o null si ve la de todos.
     */
    private function soloDe(): ?int
    {
        if (! $this->soloLoMio()) {
            return null;
        }

        // §9.A8: `auth()->id()` es int|string|null. Sin usuario, 0: nadie
        // tiene ese id y la caja sale vacía, que es lo correcto.
        $id = auth()->id();

        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) ? (int) $id : 0;
    }

    private function fechaDelFiltro(string $campo): ?CarbonImmutable
    {
        $valor = $this->filtros[$campo] ?? null;

        if (! is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}/', $valor) !== 1) {
            return null;
        }

        return CarbonImmutable::parse(substr($valor, 0, 10))->startOfDay();
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::parse(today()->toDateString());
    }
}
