<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reportes\CierreDelMes;
use App\Filament\Support\Menu;
use App\Models\Cuota;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Recibo;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Override;

/**
 * La puerta al estado de resultados del mes.
 *
 * ═══ POR QUE EXISTE ESTA PANTALLA ═══
 *
 * Mauricio, 25-ago-2026, mirando el reporte terminado: «¿dónde le doy click
 * para acceder, o para filtrar la de qué mes quiero ver?».
 *
 * Y tenía razón: el documento vivía en una URL que había que **teclear a
 * mano**, con el id del proyecto y el mes adentro. Un reporte al que solo se
 * llega escribiendo la dirección es un reporte que nadie saca.
 *
 * ═══ POR QUE ES UNA PANTALLA Y NO UN BOTON EN «PROYECTOS» ═══
 *
 * Porque el mes es la mitad de la pregunta. Un botón en la ficha del proyecto
 * abriría siempre el mes corriente, y el día 2 de septiembre lo que hace falta
 * es agosto — justo cuando se cierra y se reparte. Acá se eligen las dos cosas
 * antes de abrir nada.
 *
 * ═══ 🔴 EL SELECTOR NO ES LA PUERTA ═══
 *
 * Esta pantalla elige; **quien decide si se puede ver es el controlador**, que
 * pide ver ESE proyecto y ver gastos. `canAccess()` de acá es solo para que el
 * ítem no aparezca en el menú de quien nunca va a poder abrirlo: el receptor
 * ve proyectos pero no gastos, y un menú que ofrece una pantalla que devuelve
 * 403 es peor que un menú sin esa pantalla.
 *
 * @property Schema $form
 */
class EstadoMensual extends Page
{
    #[Override]
    protected string $view = 'filament.pages.estado-mensual';

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    /**
     * Justo después de «Proyectos», que está en 6: se entra acá desde el
     * desarrollo, no desde la ventanilla.
     */
    #[Override]
    protected static ?int $navigationSort = 7;

    /**
     * Cuántos meses hacia atrás llega el selector como mucho.
     *
     * La cartera vieja trae cuotas de hace años y sin tope la lista saldría
     * con ochenta opciones. Cinco años cubre cualquier cierre que alguien vaya
     * a reimprimir; lo anterior se pide por URL si de verdad hace falta.
     */
    private const int MESES_HACIA_ATRAS = 60;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    #[Override]
    public function getTitle(): string
    {
        return 'Estado mensual';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Estado mensual';
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return Menu::DESARROLLO;
    }

    /**
     * ⚠️ Las MISMAS dos llaves que exige `EstadoMensualController`, y por
     * política y no por nombre de permiso: `$user->can('viewAny', Gasto)` pasa
     * por `GastoPolicy`, que es exactamente lo que hace el controlador. Copiar
     * el string del permiso sería tener dos reglas que pueden separarse — y ya
     * pasó una vez, con un `ViewAny:Socio` que no tiene nadie.
     */
    #[Override]
    public static function canAccess(): bool
    {
        $usuario = auth()->user();

        return $usuario !== null
            && $usuario->can('viewAny', Proyecto::class)
            && $usuario->can('viewAny', Gasto::class);
    }

    public function mount(): void
    {
        $meses = $this->meses();

        $this->form->fill([
            'proyecto' => Proyecto::query()->reorder()->orderBy('id')->value('id'),
            // El mes corriente si está en la lista; si no, el más reciente.
            'mes' => array_key_first($meses),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Qué mes se quiere ver')
                    ->description('Se abre el estado de resultados de ese mes: lo que entró, lo que se gastó, cuánto le toca a cada socio y qué cuotas quedaron sin pagar.')
                    ->schema([
                        /*
                         * ⚠️ `selectablePlaceholder(false)` en los dos: sin eso
                         * el select trae su «×» para vaciarse, y el modal —que
                         * lee el estado sin pasar por la validación— armaría la
                         * URL sin proyecto y mostraría un 404 adentro del marco.
                         * Acá no hay «ninguno»: siempre hay un mes y un
                         * proyecto elegidos.
                         */
                        Select::make('proyecto')
                            ->label('Proyecto')
                            ->options(fn (): array => Proyecto::query()
                                ->reorder()
                                ->orderBy('nombre')
                                ->pluck('nombre', 'id')
                                ->all())
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->required(),

                        Select::make('mes')
                            ->label('Mes')
                            ->options(fn (): array => $this->meses())
                            ->native(false)
                            ->searchable()
                            ->selectablePlaceholder(false)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * El botón que abre el documento, en una ventana flotante.
     *
     * ═══ 🔴 POR QUE UN `<iframe>` Y NO EL HTML PEGADO ADENTRO ═══
     *
     * Mauricio, 25-ago-2026: «debería de verse en una ventana flotante, no
     * redirigir a otra ventana».
     *
     * La hoja es un documento COMPLETO —su `<style>` fija tipografías, tamaños
     * y hasta `@page`— pensado para una página en blanco, no para convivir con
     * Tailwind y Filament. Pegado adentro del modal, ese CSS se derrama sobre
     * el panel: reglas como `table { width: 100% }` o `td { text-align: right }`
     * no saben quedarse quietas.
     *
     * El marco lo resuelve de raíz: adentro es otro documento, con su CSS
     * encerrado, y el mismo `window.print()` de siempre imprime SOLO la hoja.
     * Un documento, servido por la misma URL, mostrado en dos contextos —y sin
     * una segunda versión del reporte que después haya que mantener al día—.
     *
     * @return array<Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver')
                ->label('Ver el estado del mes')
                ->icon(Heroicon::OutlinedDocumentText)
                ->modalHeading(fn (): string => 'Estado de resultados · '.$this->mesElegidoEscrito())
                ->modalDescription(fn (): ?string => $this->nombreDelProyectoElegido())
                /*
                 * 4xl (56 rem) y no 7xl: la hoja mide 186 mm ≈ 700 px y el
                 * modal le deja un margen gris parejo a los dos lados, como la
                 * vista previa de una impresora. En 7xl la hoja quedaba
                 * flotando en el medio de un campo gris enorme.
                 */
                ->modalWidth(Width::FourExtraLarge)
                ->modalContent(fn (): View => view('filament.pages.estado-mensual-marco', [
                    'url' => $this->urlDelDocumento(),
                ]))
                // No hay nada que guardar: se mira y se cierra.
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar'),
        ];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * ⚠️ Lee `$this->data` y NO `$this->form->getState()`.
     *
     * `getState()` corre la validación, y esto se llama mientras se ARMA el
     * modal: una excepción de validación ahí no pinta un campo en rojo, deja
     * la ventana a medio abrir. La validación no hace falta porque los dos
     * selects no se pueden vaciar —ver `selectablePlaceholder(false)`— y
     * `mount()` los deja llenos.
     */
    private function urlDelDocumento(): string
    {
        return route('documentos.estado-mensual', [
            'proyecto' => $this->proyectoElegido() ?? 0,
            'mes'      => $this->mesElegido(),
        ]);
    }

    private function proyectoElegido(): ?int
    {
        $elegido = $this->data['proyecto'] ?? null;

        return is_int($elegido) || (is_string($elegido) && $elegido !== '') ? (int) $elegido : null;
    }

    private function mesElegido(): string
    {
        $elegido = $this->data['mes'] ?? null;

        return is_string($elegido) && preg_match('/^\d{4}-\d{2}$/', $elegido) === 1
            ? $elegido
            : today()->format('Y-m');
    }

    /**
     * El nombre del proyecto, bajo el título de la ventana.
     *
     * Sale del select y no de una consulta nueva: el nombre ya está entre las
     * opciones que la pantalla acaba de dibujar.
     */
    private function nombreDelProyectoElegido(): ?string
    {
        $elegido = $this->proyectoElegido();

        if ($elegido === null) {
            return null;
        }

        $nombre = Proyecto::query()->reorder()->whereKey($elegido)->value('nombre');

        return is_string($nombre) ? $nombre : null;
    }

    /**
     * El mes elegido escrito, para el título de la ventana.
     */
    private function mesElegidoEscrito(): string
    {
        return ucfirst(CierreDelMes::comoSeEscribe(
            CarbonImmutable::createFromFormat('Y-m-d', $this->mesElegido().'-01') ?: CarbonImmutable::now(),
        ));
    }

    /**
     * Los meses que se pueden pedir: del corriente hacia atrás, hasta donde
     * hay algo que contar.
     *
     * ═══ POR QUE NO DEPENDE DEL PROYECTO ELEGIDO ═══
     *
     * Porque haría falta un select `live()` que recalcula el otro, y el mes
     * elegido se perdería cada vez que se cambia de proyecto. El rango se saca
     * de todo el sistema: como mucho sobran unos meses vacíos al final de la
     * lista, y un mes vacío se ve de un vistazo. La alternativa se rompe en la
     * mano.
     *
     * ⚠️ Nada de meses futuros. En un mes que todavía no llegó, ninguna cuota
     * se pagó porque todavía no le toca a nadie — el anexo de pendientes
     * mostraría a toda la cartera como morosa.
     *
     * @return array<string, string> clave `YYYY-MM`, valor «Agosto de 2026»
     */
    private function meses(): array
    {
        $hoy = CarbonImmutable::parse(today()->toDateString())->startOfMonth();
        $desde = $this->elPrimerMesConDatos($hoy);

        $opciones = [];

        for ($mes = $hoy; ! $mes->isBefore($desde); $mes = $mes->subMonth()) {
            $opciones[$mes->format('Y-m')] = ucfirst(CierreDelMes::comoSeEscribe($mes));
        }

        return $opciones;
    }

    /**
     * El mes más viejo del que vale la pena ofrecer un cierre.
     *
     * Se pregunta por las tres cosas que pueden dar contenido a la hoja: un
     * cobro, un gasto y una cuota que venció. La tercera importa —un mes sin
     * un solo cobro igual tiene su anexo de lo que no se pagó, y ese mes es
     * justamente el que alguien va a querer mirar—.
     */
    private function elPrimerMesConDatos(CarbonImmutable $hoy): CarbonImmutable
    {
        $tope = $hoy->subMonths(self::MESES_HACIA_ATRAS);

        $fechas = [
            Recibo::query()->reorder()->whereNull('anulado_el')->min('fecha'),
            Gasto::query()->reorder()->min('fecha'),
            Cuota::query()->reorder()->min('fecha_vencimiento'),
        ];

        $primero = $hoy;

        foreach ($fechas as $fecha) {
            if (! is_string($fecha)) {
                continue;
            }

            $mes = CarbonImmutable::parse($fecha)->startOfMonth();

            if ($mes->isBefore($primero)) {
                $primero = $mes;
            }
        }

        return $primero->isBefore($tope) ? $tope : $primero;
    }
}
