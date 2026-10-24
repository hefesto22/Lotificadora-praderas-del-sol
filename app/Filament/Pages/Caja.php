<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\ConceptoDeRecibo;
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
use Filament\Support\Icons\Heroicon;
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
 * ═══ QUIEN LA VE ═══
 *
 * Quien ve la lista de recibos. Pero el receptor ve SOLO lo que recibió él
 * —la misma regla del corte de caja del Escritorio, `Roles::veLaCajaDeTodos()`—
 * porque «NO ve el arqueo de otro receptor».
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

    /**
     * Un año, como mucho, de una vez.
     *
     * La consulta aguanta más —es una sola, agrupada—, pero la pantalla no:
     * tres años son mil renglones que nadie lee. Si piden más, se recorta
     * desde el «hasta» hacia atrás y se dice.
     */
    private const int DIAS_COMO_MUCHO = 366;

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
     * Los tres rangos que se piden siempre, a un clic.
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
     * Lo que se ve al abrir un día: sus recibos y quién recibió el dinero.
     *
     * Se arma acá y no en la vista para que la vista solo imprima texto: las
     * etiquetas, el folio y el enlace son reglas, y una regla en un blade no
     * la ve nadie.
     *
     * @return array{recibos: list<array{folio: string, url: string, nombre: string, lotes: string, concepto: string, forma: string, recibio: string, monto: string}>, quienes: list<array{nombre: string, total: Monto}>, faltan: int}
     */
    public function detalleDelDia(DiaDeCaja $dia): array
    {
        $proyectos = $this->proyectosElegidos();
        $soloDe = $this->soloDe();

        $recibos = [];

        foreach (CajaPorDia::recibosDelDia($dia->fecha, $proyectos, $soloDe, self::RECIBOS_EN_EL_DETALLE) as $recibo) {
            $concepto = $recibo->getAttribute('concepto');
            $forma = $recibo->getAttribute('forma_pago');
            $lotes = $recibo->codigosDeLotes();

            $recibos[] = [
                'folio'    => $recibo->folio(),
                'url'      => ReciboResource::getUrl('view', ['record' => $recibo]),
                'nombre'   => $recibo->nombreDelPapel(),
                'lotes'    => $lotes === [] ? '—' : implode(' · ', $lotes),
                'concepto' => $concepto instanceof ConceptoDeRecibo ? $concepto->etiqueta() : '—',
                'forma'    => $forma instanceof FormaDePago ? $forma->etiqueta() : '—',
                'recibio'  => $recibo->nombreDeQuienRecibio() ?? '—',
                'monto'    => $recibo->montoTotal()->formateado(),
            ];
        }

        return [
            'recibos' => $recibos,
            // Al receptor no: todo lo que ve es suyo, y la línea diría su nombre y el total que ya está arriba.
            'quienes' => $this->soloLoMio() ? [] : CajaPorDia::quienesRecibieron($dia->fecha, $proyectos, $soloDe),
            'faltan'  => max(0, $dia->recibos - count($recibos)),
        ];
    }

    /**
     * «Jueves 1 de octubre». Con el año solo cuando el rango cruza de año.
     */
    public function nombreDelDia(DiaDeCaja $dia): string
    {
        $periodo = $this->periodo();

        $texto = ucfirst($dia->fecha->translatedFormat('l j \d\e F'));

        if ($periodo['desde']->year !== $periodo['hasta']->year) {
            $texto .= ' de '.$dia->fecha->year;
        }

        return $dia->fecha->isSameDay($this->hoy()) ? $texto.' · hoy' : $texto;
    }

    /**
     * «Del 1 al 24 de octubre de 2026 · Todos los proyectos».
     */
    public function periodoEscrito(): string
    {
        $periodo = $this->periodo();
        $desde = $periodo['desde'];
        $hasta = $periodo['hasta'];

        if ($desde->isSameDay($hasta)) {
            $fechas = ucfirst($desde->translatedFormat('l j \d\e F \d\e Y'));
        } elseif ($desde->year !== $hasta->year) {
            $fechas = 'Del '.$desde->translatedFormat('j \d\e F \d\e Y').' al '.$hasta->translatedFormat('j \d\e F \d\e Y');
        } elseif ($desde->month !== $hasta->month) {
            $fechas = 'Del '.$desde->translatedFormat('j \d\e F').' al '.$hasta->translatedFormat('j \d\e F \d\e Y');
        } else {
            $fechas = 'Del '.$desde->day.' al '.$hasta->translatedFormat('j \d\e F \d\e Y');
        }

        return $fechas.' · '.$this->proyectosEscritos();
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

        $desde = $this->fechaDelFiltro('desde') ?? $hoy->startOfMonth();
        $hasta = $this->fechaDelFiltro('hasta') ?? $hoy;

        // Dadas vuelta no es un error que valga la pena gritar: se entiende.
        if ($hasta->isBefore($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $tope = $hasta->subDays(self::DIAS_COMO_MUCHO - 1);

        if ($desde->isBefore($tope)) {
            return ['desde' => $tope, 'hasta' => $hasta, 'recortado' => true];
        }

        return ['desde' => $desde, 'hasta' => $hasta, 'recortado' => false];
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
        $elegidos = $this->proyectosElegidos();

        if ($elegidos === []) {
            return 'Todos los proyectos';
        }

        /** @var list<string> $nombres */
        $nombres = Proyecto::query()
            ->reorder()
            ->whereKey($elegidos)
            ->orderBy('nombre')
            ->pluck('nombre')
            ->all();

        if (count($nombres) < 2) {
            return $nombres[0] ?? 'Todos los proyectos';
        }

        $ultimo = array_pop($nombres);

        return implode(', ', $nombres).' y '.$ultimo;
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
