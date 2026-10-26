<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CoberturaDelPlan;
use App\Domain\Ventas\CondicionesDeReestructuracion;
use App\Domain\Ventas\CuotaProyectada;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\PlanUnificado;
use App\Domain\Ventas\RegistroDeReestructuraciones;
use App\Domain\Ventas\SituacionDelPlan;
use App\Domain\Ventas\TasaDeInteres;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Component as ComponenteLivewire;
use Throwable;

/**
 * «Reestructurar el contrato» — el botón del expediente (traído de Maya,
 * 25-oct-2026).
 *
 * ═══ QUE SE PIDIO ═══
 *
 * «Hay que hacer reestructuración de contrato, ya sea de un lote o de dos …
 * así como lo manejamos en Constructora Maya; debería de tener más o menos
 * ese mismo diseño» —Mauricio, con el expediente 0033 enfrente—. Y con dos
 * lotes, las dos maneras: «puede unificar los lotes para una sola cuota o que
 * tengan cuota independiente, según como lo reestructure».
 *
 * ═══ LAS DOS MANERAS ═══
 *
 *  - **Cada lote con su cuota** (de fábrica): cada lote se rehace a los meses
 *    pactados con lo que debe él, y su cuota sale sola. Lo que un recibo pagó
 *    de un lote se queda en ese lote. Es lo que pidió el 0033 —«los dos lotes
 *    de manera individual a 24 meses cada uno»—, y es la que no mueve plata
 *    entre lotes: por eso va primero.
 *  - **Unificados**: los lotes se pagan con una sola cuota, como en Maya. Se
 *    pactan los meses Y la cuota, y si en esos meses no alcanza, lo que falte
 *    es una cuota más al final.
 *
 * ═══ DESDE DÓNDE ═══
 *
 * De fábrica, desde el primer mes que debe algo: lo pagado entero antes no se
 * toca. Con «Rehacer el plan desde la cuota 1» se rehace entero y TODOS los
 * recibos se vuelven a poner sobre el plan nuevo, por fecha, sin cambiar de
 * número. En el 0033 son las dos cuentas que se le explicaron a Mauricio:
 * L 8,333.33 por lote desde la próxima, o L 10,000.00 por lote desde la 1 con
 * las cuatro primeras ya pagadas.
 *
 * ═══ COMO SE VE ═══
 *
 * El diseño de Maya, sección por sección: «El contrato hoy» —la franja del
 * contrato y las tarjetas de cómo está—, «El plan nuevo», «Así queda» —la
 * franja verde o ámbar de lo ya pagado, las tarjetas de antes → después, cada
 * lote y el plan cuota por cuota— y «Para el acta». Las clases viven en
 * `estilos-olympo.blade.php` (§9.A7).
 *
 * La vista previa sale de `RegistroDeReestructuraciones::propuesta()`, el
 * MISMO camino que después escribe: lo que se ve es lo que se guarda (§10.8).
 *
 * ═══ POR QUE VIVE EN SUPPORT ═══
 *
 * El mismo molde que `CobrarUnPago`: una acción con su propia clase, lista
 * para colgarla donde haga falta sin copiar el modal.
 */
final readonly class ReestructurarElPlan
{
    private const string UNIFICADOS = 'unificados';

    private const string CADA_LOTE = 'cada_lote';

    /**
     * La foto con TODOS los lotes que deben: de acá salen las opciones y los
     * valores iniciales. Lo que se muestra después sale de los lotes marcados
     * (`situacionDe()`).
     */
    private SituacionDelPlan $situacion;

    private function __construct(private Venta $venta)
    {
        $this->situacion = app(RegistroDeReestructuraciones::class)->situacion($venta);
    }

    public static function accion(): Action
    {
        return Action::make('reestructurar_plan')
            ->label('Reestructurar el contrato')
            ->icon(Heroicon::OutlinedCalculator)
            ->visible(static fn (?Venta $record): bool => $record instanceof Venta && self::sePuede($record))
            ->modalHeading(static fn (?Venta $record): string => sprintf(
                'Reestructurar el contrato %s',
                (string) $record?->getAttribute('numero_contrato'),
            ))
            ->modalDescription(
                'Se rehace el plan de los lotes que marqués: en cuántos meses, desde cuándo y, con dos o más, '.
                'si cada lote queda con su cuota o se pagan con una sola. Lo que ya pagó sigue valiendo, con '.
                'los mismos recibos. Sale un acta para que la firme el cliente.'
            )
            ->modalSubmitActionLabel('Reestructurar y emitir el acta')
            ->modalWidth('4xl')
            /*
             * El valor inicial va acá y NO en un `default()` del campo: este
             * arreglo ES el estado inicial del modal.
             */
            ->fillForm(static fn (Venta $record): array => new self($record)->valoresIniciales())
            ->schema(static fn (Venta $record): array => new self($record)->campos())
            ->action(static function (Venta $record, array $data, ComponenteLivewire $livewire): void {
                new self($record)->reestructurar($data, $livewire);
            });
    }

    /**
     * Sin consultas: la acción vive en el encabezado del expediente y esto se
     * evalúa en cada render. Lo que depende de las cuotas —un pronto pago con
     * descuento, un recibo que no cuadra— lo dice el modal por dentro, con su
     * motivo.
     */
    private static function sePuede(Venta $venta): bool
    {
        return $venta->getAttribute('estado') === EstadoVenta::Vigente
            && ! $venta->esDeContado()
            && auth()->user()?->can('reestructurar', Venta::class) === true;
    }

    // ─── El modal ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function valoresIniciales(): array
    {
        return [
            'lotes'            => array_map(strval(...), array_keys($this->situacion->lotes)),
            'agrupacion'       => self::CADA_LOTE,
            'desde_la_primera' => false,
            'aunque_atrasado'  => false,
            ...$this->valoresDelPlan($this->situacion),
            // Vacíos a propósito: los pacta la administradora con el cliente,
            // y hasta que estén no hay plan que mostrar.
            'meses'   => null,
            'cuota'   => null,
            'tasa'    => $this->tasaParaElCampo($this->situacion->tasaSugerida()),
            'interes' => 'incluido',
        ];
    }

    /**
     * El valor que depende de QUÉ lotes entran y desde dónde: cuándo vence la
     * primera cuota que se reemplaza. Se vuelve a poner cuando se marca o
     * desmarca un lote, o se pide rehacerlo desde la cuota 1.
     *
     * @return array{primer_vencimiento: string}
     */
    private function valoresDelPlan(SituacionDelPlan $situacion): array
    {
        return [
            'primer_vencimiento' => $situacion->primerVencimientoSugerido()->toDateString(),
        ];
    }

    /**
     * @return list<Component>
     */
    private function campos(): array
    {
        $hayQueHacer = fn (): bool => $this->situacion->lotes !== [];

        return [
            Placeholder::make('sin_cuotas')
                ->hiddenLabel()
                ->visible(fn (): bool => $this->situacion->lotes === [])
                ->content(new HtmlString('<p class="olympo-vacio">Este contrato no tiene cuotas pendientes: no hay plan que reestructurar.</p>')),

            Section::make('El contrato hoy')
                ->description(fn (): string => $this->descripcionDelContrato())
                ->icon('heroicon-o-document-text')
                ->visible($hayQueHacer)
                ->schema([
                    Placeholder::make('datos_del_contrato')
                        ->hiddenLabel()
                        ->content(fn (): HtmlString => $this->datosDelContrato()),

                    CheckboxList::make('lotes')
                        ->label('¿Qué lotes entran?')
                        ->options(fn (): array => $this->opcionesDeLotes())
                        ->descriptions(fn (): array => $this->descripcionesDeLotes())
                        ->columns(2)
                        ->bulkToggleable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                            $this->ponerLosValoresDelPlan($state, $get('desde_la_primera'), $set);
                        })
                        ->visible(fn (): bool => count($this->situacion->lotes) > 1)
                        ->helperText('Los que no marqués siguen con su plan de siempre, y sus recibos no se tocan.'),

                    /*
                     * Solo con dos o más lotes marcados: con uno, las dos
                     * maneras son la misma.
                     */
                    ToggleButtons::make('agrupacion')
                        ->label('¿Cómo quedan los lotes?')
                        ->options([
                            self::CADA_LOTE  => 'Cada lote con su cuota',
                            self::UNIFICADOS => 'Unificados, una sola cuota',
                        ])
                        ->icons([
                            self::CADA_LOTE  => 'heroicon-o-squares-2x2',
                            self::UNIFICADOS => 'heroicon-o-square-3-stack-3d',
                        ])
                        ->inline()
                        ->live()
                        ->visible(fn (Get $get): bool => $this->cuantosEntran($get('lotes')) > 1)
                        ->helperText(fn (Get $get): string => $this->cadaLoteDe($get('agrupacion'), $get('lotes'))
                            ? 'Cada lote se rehace a los meses que se pacten con lo que debe él, y lo que pagó cada uno se queda en ese lote.'
                            : 'Los lotes se pagan con una sola cuota por mes, como si fueran uno: cada pago se reparte entre ellos según lo que debe cada uno.'),

                    /*
                     * ═══ DESDE LA CUOTA 1 ═══
                     *
                     * De fábrica se reemplaza desde el primer mes que debe
                     * algo, y lo pagado entero antes no se toca. Rehacerlo
                     * entero vuelve a poner TODOS los recibos sobre el plan
                     * nuevo, por fecha. Solo aparece si hay meses pagados
                     * enteros antes del tramo: si no, las dos son la misma.
                     */
                    Checkbox::make('desde_la_primera')
                        ->label('Rehacer el plan desde la cuota 1')
                        ->visible(fn (): bool => $this->situacion->dejaMesesPagadosAntes())
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                            $this->ponerLosValoresDelPlan($get('lotes'), $state, $set);
                        })
                        ->helperText(fn (): string => sprintf(
                            'Sin marcar, se rehace desde la cuota %d y lo pagado antes queda como está. Marcado, el plan se '
                            .'rehace entero y TODOS los recibos se vuelven a poner sobre el plan nuevo, por fecha, sin '
                            .'cambiar de número.',
                            $this->situacion->primerNumero(),
                        )),

                    // ⚠️ Un Placeholder nunca se llama como un dato que lee.
                    Placeholder::make('como_esta_hoy')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => $this->cuadroDeHoy($this->situacionDe($get('lotes'), $get('desde_la_primera')))),
                ]),

            Section::make('El plan nuevo')
                ->description(fn (Get $get): string => $this->cadaLoteDe($get('agrupacion'), $get('lotes'))
                    ? 'En cuántos meses y desde cuándo. Cada lote paga su capital en esos meses: su cuota sale sola.'
                    : 'Se pactan los dos: en cuántos meses y de cuánto la cuota. Si en esos meses no termina de pagar, lo que le falte queda en una cuota más al final.')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible($hayQueHacer)
                ->columns(2)
                ->schema([
                    TextInput::make('meses')
                        ->label('¿En cuántos meses?')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(PlanDeCuotas::PLAZO_MAXIMO_MESES)
                        ->suffix('meses')
                        ->placeholder('Ejemplo: 24')
                        ->helperText(fn (Get $get): string => sprintf(
                            'Hoy le quedan %d.',
                            $this->situacionDe($get('lotes'), $get('desde_la_primera'))->mesesQueFaltan(),
                        ))
                        ->live(onBlur: true)
                        ->required(),

                    MontoField::make('cuota', 'Cuota por mes')
                        ->live(onBlur: true)
                        ->visible(fn (Get $get): bool => ! $this->cadaLoteDe($get('agrupacion'), $get('lotes')))
                        ->helperText(fn (Get $get): string => $this->ayudaDeLaCuota($this->datosDelPlan($get))),

                    TextInput::make('tasa')
                        ->label('Interés anual')
                        ->inputMode('decimal')
                        ->rule('numeric')
                        ->minValue(0)
                        ->maxValue(TasaDeInteres::MAXIMA)
                        ->step('0.001')
                        ->suffix('%')
                        ->required()
                        ->live(onBlur: true)
                        // Sin interés en ningún lote (R1), no hay nada que pactar acá.
                        ->visible(fn (): bool => $this->llevaInteresElContrato())
                        ->helperText(fn (): string => $this->ayudaDeLaTasa()),

                    ToggleButtons::make('interes')
                        ->label('¿La cuota ya trae el interés?')
                        ->options([
                            'incluido' => 'Sí, ya lo trae',
                            'encima'   => 'No, va encima',
                        ])
                        ->grouped()
                        ->live()
                        ->visible(fn (Get $get): bool => $this->llevaInteres($get('tasa')))
                        ->helperText('Si lo trae, paga lo mismo cada mes. Si va encima, paga más al principio y va bajando.'),

                    DatePicker::make('primer_vencimiento')
                        ->label('Primer vencimiento')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->live()
                        ->helperText('Su día es el día de pago de todo el plan.'),
                ]),

            Section::make('Así queda')
                ->description('Se calcula mientras escribís, con la misma cuenta que después se guarda y sale en el acta.')
                ->icon('heroicon-o-calendar-days')
                ->visible($hayQueHacer)
                ->schema([
                    Placeholder::make('plan_propuesto')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => $this->vistaPrevia($this->datosDelPlan($get))),

                    /*
                     * Solo aparece cuando el plan nacería con cuotas vencidas
                     * sin cubrir: un plan pactado en una fecha que ya pasó y
                     * cuyos pagos todavía no están en el sistema.
                     */
                    Checkbox::make('aunque_atrasado')
                        ->label('Los pagos de los meses vencidos los registro después, cada uno con su fecha')
                        ->visible(fn (Get $get): bool => $this->naceAtrasadoCon($this->datosDelPlan($get)))
                        ->live()
                        ->helperText('El plan nace con esas cuotas pendientes. Al registrar cada pago con la fecha en que se pagó, '
                            .'va cayendo en orden, del mes más viejo al más nuevo.'),
                ]),

            Section::make('Para el acta')
                ->icon('heroicon-o-pencil-square')
                ->visible($hayQueHacer)
                ->schema([
                    Textarea::make('motivo')
                        ->label('¿Por qué se reestructura?')
                        ->required()
                        ->rows(2)
                        ->maxLength(500)
                        ->placeholder('El cliente pidió pagar cada lote en 24 meses')
                        ->helperText('Queda impreso en el acta que firma el cliente, con tu usuario y la fecha.'),
                ]),
        ];
    }

    private function ponerLosValoresDelPlan(mixed $lotes, mixed $desdeLaPrimera, Set $set): void
    {
        $elegida = $this->situacionDe($lotes, $desdeLaPrimera);

        if ($elegida->lotes === []) {
            return;
        }

        foreach ($this->valoresDelPlan($elegida) as $campo => $valor) {
            $set($campo, $valor);
        }
    }

    /**
     * Los lotes que todavía deben, por id de compromiso.
     *
     * @return array<int, string>
     */
    private function opcionesDeLotes(): array
    {
        $opciones = [];

        foreach ($this->situacion->lotes as $id => $lote) {
            $opciones[$id] = $lote['codigo'];
        }

        return $opciones;
    }

    /**
     * Lo que se sabe de cada lote sin tener que ir a buscarlo.
     *
     * @return array<int, string>
     */
    private function descripcionesDeLotes(): array
    {
        $descripciones = [];

        foreach ($this->situacion->lotes as $id => $lote) {
            $pagado = $this->situacion->yaPagadoDe($id);
            $cuotas = count($lote['tramo']);

            $descripciones[$id] = sprintf(
                'Prima %s · capital %s · %d %s%s',
                $this->primaDe($lote['compromiso'])->formateado(),
                $lote['capital']->formateado(),
                $cuotas,
                $cuotas === 1 ? 'cuota' : 'cuotas',
                $pagado->esCero() ? '' : ' · ya pagado '.$pagado->formateado(),
            );
        }

        return $descripciones;
    }

    private function primaDe(Compromiso $lote): Monto
    {
        $prima = $lote->getAttribute('prima');

        return is_string($prima) && $prima !== '' ? new Monto($prima) : Monto::cero();
    }

    /**
     * ¿Algún lote del contrato cobra interés? Sin interés (R1) la tasa no se
     * pregunta: el plan nuevo sigue sin interés.
     */
    private function llevaInteresElContrato(): bool
    {
        foreach ($this->situacion->tasas() as $tasa) {
            if (! $tasa->esCero()) {
                return true;
            }
        }

        return false;
    }

    private function ayudaDeLaTasa(): string
    {
        $tasas = $this->situacion->tasas();

        if (count($tasas) > 1) {
            return 'Los lotes venían con tasas distintas ('
                .implode(' y ', array_map(static fn (TasaDeInteres $tasa): string => $tasa->formateada(), $tasas))
                .'). El plan nuevo lleva una sola: la que se escriba acá.';
        }

        $tasa = $tasas[0] ?? TasaDeInteres::cero();

        return $tasa->esCero()
            ? 'El contrato no llevaba interés. 0 es sin interés.'
            : 'La del contrato es '.$tasa->formateada().' anual. Si se cambia, queda escrito en el acta.';
    }

    /**
     * La foto de los lotes marcados. Con todos marcados —o sin la pregunta,
     * que es un contrato de un lote— es la misma de arriba y no se vuelve a
     * leer. Rehecho desde la cuota 1 es otra foto: otro tramo, otro capital y
     * otros recibos que se mueven.
     */
    private function situacionDe(mixed $valor, mixed $desdeLaPrimera = false): SituacionDelPlan
    {
        $elegidos = $this->lotesDe($valor);
        $entero = $desdeLaPrimera === true;

        if (! $entero && ($elegidos === null || $elegidos === array_keys($this->situacion->lotes))) {
            return $this->situacion;
        }

        return app(RegistroDeReestructuraciones::class)->situacion($this->venta, $elegidos, $entero);
    }

    /**
     * Cuántos lotes quedaron marcados. Sin la pregunta —un contrato de un
     * lote— son todos los que deben.
     */
    private function cuantosEntran(mixed $valor): int
    {
        return count($this->lotesDe($valor) ?? $this->situacion->lotes);
    }

    /**
     * ¿Cada lote con su cuota? Con un solo lote marcado la pregunta no se
     * hace: un lote es su propio plan y da lo mismo.
     */
    private function cadaLoteDe(mixed $agrupacion, mixed $lotes): bool
    {
        return $this->cuantosEntran($lotes) > 1 && $agrupacion !== self::UNIFICADOS;
    }

    // ─── El contrato ──────────────────────────────────────────────────

    /**
     * La línea de debajo del título: de quién es y cuándo se firmó.
     */
    private function descripcionDelContrato(): string
    {
        $titular = $this->venta->titular()?->getAttribute('nombre');
        $firma = $this->venta->getAttribute('fecha_contrato');

        return trim(sprintf(
            'Contrato %s%s%s.',
            (string) $this->venta->getAttribute('numero_contrato'),
            $firma instanceof DateTimeInterface ? ', firmado el '.$firma->format('d/m/Y') : '',
            is_string($titular) && $titular !== '' ? ', a nombre de '.$titular : '',
        ));
    }

    /**
     * Los números del contrato entero, en una franja: lo que vale, cuánto dio
     * de PRIMA —y con qué recibo—, lo que se financió, la tasa y el día. Es lo
     * primero que pregunta el cliente cuando se le rehace el plan —«¿y lo que
     * di al principio?»— y tiene que estar a la vista.
     */
    private function datosDelContrato(): HtmlString
    {
        $prima = $this->venta->montoPrima();
        $folios = Recibo::query()
            ->where('venta_id', $this->venta->getKey())
            ->where('concepto', ConceptoDeRecibo::Prima->value)
            ->whereNull('anulado_el')
            ->reorder()
            ->orderBy('id')
            ->get()
            ->map(static fn (Recibo $recibo): string => $recibo->folio())
            ->all();

        $tasa = $this->situacion->tasaAnterior();
        $dia = (int) $this->venta->getAttribute('dia_pago');

        $datos = [
            ['Valor del contrato', $this->venta->montoValorTotal()->formateado(), '', ''],
            [
                'Prima',
                $prima->formateado(),
                $prima->esCero() ? 'Sin prima' : ($folios === [] ? 'Sin recibo de prima' : 'Recibo '.implode(', ', $folios)),
                'destacado',
            ],
            ['Financiado', $this->venta->montoSaldoFinanciar()->formateado(), '', ''],
            ['Interés', match (true) {
                ! $tasa instanceof TasaDeInteres => 'Distinto por lote',
                $tasa->esCero()                  => 'Sin interés',
                default                          => $tasa->formateada().' anual',
            }, '', ''],
            ['Día de pago', $dia > 0 ? 'Cada '.$dia : '—', '', ''],
        ];

        $html = '';

        foreach ($datos as [$rotulo, $valor, $detalle, $clase]) {
            $html .= '<div'.($clase === '' ? '' : ' class="'.$clase.'"').'>'
                .'<dt>'.e($rotulo).'</dt>'
                .'<dd>'.e($valor).'</dd>'
                .($detalle === '' ? '' : '<span>'.e($detalle).'</span>')
                .'</div>';
        }

        return new HtmlString('<dl class="olympo-datos">'.$html.'</dl>');
    }

    // ─── Lo que se ve ─────────────────────────────────────────────────

    /**
     * Cómo está hoy lo que se va a reemplazar, en tarjetas, o por qué no se
     * puede tocar.
     */
    private function cuadroDeHoy(SituacionDelPlan $situacion): HtmlString
    {
        if ($situacion->impedimentos !== []) {
            return new HtmlString($this->estado('falta', '!', 'No se puede reestructurar así', $situacion->impedimentos[0]->getMessage()));
        }

        if ($situacion->lotes === []) {
            return new HtmlString($this->estado('falta', '!', 'Falta elegir', 'Marcá al menos un lote que todavía deba algo.'));
        }

        $desde = $situacion->primerNumero();
        $meses = $situacion->mesesQueFaltan();
        $pagado = $situacion->yaPagado();
        $lotes = count($situacion->lotes);

        $tarjetas = $this->tarjeta(
            'Capital desde la cuota '.$desde,
            $situacion->capitalTotal()->formateado(),
            $lotes === 1 ? $situacion->codigos() : $lotes.' lotes: '.$situacion->codigos(),
        );

        $tarjetas .= $this->tarjeta(
            'Hoy paga por mes',
            $situacion->cuotaDeHoy()?->formateado() ?? '—',
            sprintf(
                '%d %s, hasta el %s%s',
                $meses,
                $meses === 1 ? 'mes' : 'meses',
                $situacion->terminaEl()?->format('d/m/Y') ?? '—',
                $situacion->interesDelTramo()->esCero() ? '' : ' · interés '.$situacion->interesDelTramo()->formateado(),
            ),
        );

        if (! $pagado->esCero()) {
            $folios = $situacion->folios();
            $moraCobrada = $situacion->moraYaCobrada();

            $tarjetas .= $this->tarjeta(
                'Ya pagado',
                $pagado->formateado(),
                (count($folios) === 1 ? 'Recibo ' : 'Recibos ').implode(', ', $folios).'. Sigue valiendo en el plan nuevo.'
                .($moraCobrada->esCero() ? '' : ' Sin contar '.$moraCobrada->formateado().' de mora: esa no entra en la cuenta.'),
                'pagado',
            );
        }

        // «Si se reestructura, la mora no va»: la que corre hoy se perdona con el acta.
        if (! $situacion->moraCorrida->esCero()) {
            $tarjetas .= $this->tarjeta(
                'Mora hoy',
                $situacion->moraCorrida->formateado(),
                'Se perdona al reestructurar: el plan nuevo arranca sin mora.',
                'mora',
            );
        }

        $afuera = $situacion->afuera === []
            ? ''
            : '<p class="olympo-nota">'.e(sprintf(
                'Quedan afuera %s: siguen con su plan de siempre.',
                implode(', ', $situacion->afuera),
            )).'</p>';

        return new HtmlString('<div class="olympo-kpis">'.$tarjetas.'</div>'.$afuera);
    }

    /**
     * El plan nuevo mientras se teclea, o la frase que dice qué falta.
     *
     * @param array<string, mixed> $datos
     */
    private function vistaPrevia(array $datos): HtmlString
    {
        $situacion = $this->situacionDe($datos['lotes'] ?? null, $datos['desde_la_primera'] ?? false);

        if (! $situacion->sePuede()) {
            return new HtmlString('');
        }

        $condiciones = $this->condicionesDe($datos);

        if (is_string($condiciones)) {
            return new HtmlString('<div class="olympo-espera">'.e($condiciones).'</div>');
        }

        $pactados = $this->mesesDe($datos['meses'] ?? null) ?? 0;
        $registro = app(RegistroDeReestructuraciones::class);
        $aunqueAtrasado = ($datos['aunque_atrasado'] ?? false) === true;

        try {
            $plan = $registro->propuesta($situacion, $condiciones, $aunqueAtrasado);
        } catch (GrupoOlympoException $error) {
            return new HtmlString($this->estado('falta', '!', 'Así no se puede', $error->getMessage().$this->hastaDondeSePuede($situacion, $condiciones)));
        }

        $cobertura = $registro->cobertura($situacion, $plan);
        $atrasadas = $aunqueAtrasado ? $registro->vencidasAlNacer($situacion, $plan) : ['cuotas' => 0, 'falta' => Monto::cero()];

        $aviso = $atrasadas['cuotas'] === 0 ? '' : $this->estado(
            'falta',
            '!',
            sprintf('Nace con %d %s sin pagar', $atrasadas['cuotas'], $atrasadas['cuotas'] === 1 ? 'cuota vencida' : 'cuotas vencidas'),
            sprintf(
                'Suman %s. Después registrá cada pago con «Registrar un pago» y la fecha en que se pagó: van a ir cayendo en orden, del mes más viejo al más nuevo.',
                $atrasadas['falta']->formateado(),
            ),
        );

        $notas = '';

        foreach ($this->notasDelPlan($plan, $condiciones, $situacion, $pactados) as $nota) {
            $notas .= '<li>'.e($nota).'</li>';
        }

        return new HtmlString(
            '<div class="olympo-bloques">'
            .$aviso
            .$this->estadoDeLoPagado($plan, $cobertura, $situacion)
            .'<div class="olympo-kpis">'.$this->tarjetasDelCambio($plan, $condiciones, $situacion, $pactados).'</div>'
            .($notas === '' ? '' : '<ul class="olympo-notas">'.$notas.'</ul>')
            .$this->cuadroDeCadaLote($plan, $situacion)
            .$this->tablaDelPlan($plan, $cobertura, $situacion)
            .'</div>'
        );
    }

    /**
     * Lo que la vista previa necesita del formulario, leído en un solo lugar.
     *
     * @return array<string, mixed>
     */
    private function datosDelPlan(Get $get): array
    {
        return [
            'lotes'              => $get('lotes'),
            'agrupacion'         => $get('agrupacion'),
            'desde_la_primera'   => $get('desde_la_primera'),
            'aunque_atrasado'    => $get('aunque_atrasado'),
            'meses'              => $get('meses'),
            'cuota'              => $get('cuota'),
            'tasa'               => $get('tasa'),
            'interes'            => $get('interes'),
            'primer_vencimiento' => $get('primer_vencimiento'),
        ];
    }

    /**
     * ¿Con lo que está escrito, el plan nacería con cuotas vencidas sin
     * cubrir? Es lo que decide si aparece la casilla de registrar los pagos
     * después. Mientras falte un dato, no.
     *
     * @param array<string, mixed> $datos
     */
    private function naceAtrasadoCon(array $datos): bool
    {
        $situacion = $this->situacionDe($datos['lotes'] ?? null, $datos['desde_la_primera'] ?? false);

        if (! $situacion->sePuede()) {
            return false;
        }

        $condiciones = $this->condicionesDe($datos);

        if (is_string($condiciones)) {
            return false;
        }

        return app(RegistroDeReestructuraciones::class)->naceAtrasado($situacion, $condiciones);
    }

    /**
     * Si la cuota pactada hace nacer el plan atrasado, hasta cuánto puede ser
     * para que lo ya pagado cubra lo vencido. Se DICE, no se pone: la cuota la
     * decide la administradora con el cliente. Con cada lote por su lado los
     * meses deciden la cuota, y lo que se dice es desde cuándo se puede.
     */
    private function hastaDondeSePuede(SituacionDelPlan $situacion, CondicionesDeReestructuracion $condiciones): string
    {
        $registro = app(RegistroDeReestructuraciones::class);

        if ($condiciones->cadaLote || ! $registro->naceAtrasado($situacion, $condiciones)) {
            return '';
        }

        $tope = $registro->cuotaMaximaAlDia($situacion, $condiciones);

        return $tope instanceof Monto
            ? sprintf(' Con lo que ya pagó, la cuota puede ser de hasta %s.', $tope->formateado())
            : '';
    }

    /**
     * La ayuda debajo de la cuota, en los unificados: con cuánto cierra en los
     * meses escritos, en pagos iguales, y si esa cuota haría nacer el plan
     * atrasado, hasta dónde se puede. Una referencia para pactar, no un valor
     * que se pone solo.
     *
     * @param array<string, mixed> $datos
     */
    private function ayudaDeLaCuota(array $datos): string
    {
        $base = 'La que pactaron con el cliente.';
        $meses = $this->mesesDe($datos['meses'] ?? null);
        $situacion = $this->situacionDe($datos['lotes'] ?? null, $datos['desde_la_primera'] ?? false);

        if ($meses === null || ! $situacion->sePuede()) {
            return $base;
        }

        $comun = $this->baseDe($datos);

        if (is_string($comun)) {
            return $base;
        }

        try {
            $parejas = CondicionesDeReestructuracion::porMeses($meses, $comun['incluido'], $comun['tasa'], $comun['primero']);
            $plan = PlanUnificado::armar($situacion->capitales(), $parejas, $situacion->primerNumero());
        } catch (GrupoOlympoException) {
            return $base;
        }

        $primera = $plan->contrato->cuotas[0] ?? null;

        if (! $primera instanceof CuotaProyectada) {
            return $base;
        }

        $ayuda = $parejas->interesEncima()
            ? sprintf('%s Para %d pagos: %s de capital por mes, más el interés.', $base, $meses, $primera->capital->formateado())
            : sprintf('%s Para %d pagos iguales: %s.', $base, $meses, $primera->monto->formateado());

        $registro = app(RegistroDeReestructuraciones::class);

        if (! $registro->naceAtrasado($situacion, $parejas)) {
            return $ayuda;
        }

        $tope = $registro->cuotaMaximaAlDia($situacion, $parejas);

        return $tope instanceof Monto
            ? sprintf('%s Pero así nacería atrasado: con lo que ya pagó, puede ser de hasta %s.', $ayuda, $tope->formateado())
            : $ayuda;
    }

    /**
     * La franja de arriba de la vista previa: hasta dónde alcanza lo ya
     * pagado en el plan nuevo. Verde si termina justo en el borde de una
     * cuota; ámbar si deja una a medias, con cuánto le falta. Sin nada pagado
     * no hay franja.
     */
    private function estadoDeLoPagado(PlanUnificado $plan, CoberturaDelPlan $cobertura, SituacionDelPlan $situacion): string
    {
        $pagado = $cobertura->cubierto();

        if ($pagado->esCero()) {
            return '';
        }

        $desde = $situacion->primerNumero();
        $enteras = $cobertura->mesesCubiertos();
        $debe = $cobertura->primerMesQueDebe();

        $cubiertas = match (true) {
            $enteras === 0 => '',
            $enteras === 1 => sprintf('la cuota %d completa', $desde),
            default        => sprintf('las cuotas %d a %d completas', $desde, $desde + $enteras - 1),
        };

        if ($debe === null) {
            return $this->estado('bien', '✓', 'Queda pagado entero', sprintf(
                'Lo que ya pagó (%s) cubre todo el plan nuevo.',
                $pagado->formateado(),
            ));
        }

        $vence = null;

        foreach ($plan->contrato->cuotas as $cuota) {
            if ($cuota->numero === $debe['numero']) {
                $vence = $cuota->vencimiento;
            }
        }

        if ($debe['cubierto']->esCero()) {
            return $this->estado('bien', '✓', 'Queda en números cerrados', sprintf(
                'Lo que ya pagó (%s) cubre %s. Lo próximo es la cuota %d%s, de %s.',
                $pagado->formateado(),
                $cubiertas,
                $debe['numero'],
                $vence instanceof CarbonImmutable ? ', el '.$vence->format('d/m/Y') : '',
                $debe['falta']->formateado(),
            ));
        }

        $atraso = $vence instanceof CarbonImmutable && $vence->lessThan(CarbonImmutable::parse(today()->toDateString()))
            ? sprintf(' Venció el %s: lo que le falta ya está atrasado.', $vence->format('d/m/Y'))
            : '';

        return $this->estado('falta', '!', sprintf('A la cuota %d le faltan %s', $debe['numero'], $debe['falta']->formateado()), sprintf(
            'La cuota %d es de %s y lo que ya pagó (%s) cubre %s%s de ella.%s',
            $debe['numero'],
            $debe['cubierto']->sumar($debe['falta'])->formateado(),
            $pagado->formateado(),
            $cubiertas === '' ? '' : $cubiertas.' y ',
            $debe['cubierto']->formateado(),
            $atraso,
        ));
    }

    /**
     * La franja de estado: un círculo con el signo, el titular en negrita y
     * una línea que explica. Verde cuando está bien, ámbar cuando falta algo.
     */
    private function estado(string $tono, string $signo, string $titular, string $detalle): string
    {
        return '<div class="olympo-estado '.$tono.'">'
            .'<span class="icono" aria-hidden="true">'.e($signo).'</span>'
            .'<div><strong>'.e($titular).'</strong><p>'.e($detalle).'</p></div>'
            .'</div>';
    }

    /**
     * Antes → después, en tarjetas. Las dos columnas cubren los MISMOS meses
     * —desde la primera cuota que se reemplaza— y el mismo capital.
     */
    private function tarjetasDelCambio(PlanUnificado $plan, CondicionesDeReestructuracion $condiciones, SituacionDelPlan $situacion, int $pactados): string
    {
        $contrato = $plan->contrato;
        $capital = $situacion->capitalTotal();
        $pagado = $situacion->yaPagado();
        $antes = $situacion->interesDelTramo();
        $despues = $plan->totalInteres();
        $meses = $plan->meses();

        $totalNuevo = $capital->sumar($despues);
        $faltaNuevo = $totalNuevo->mayorQue($pagado) ? $totalNuevo->restar($pagado) : Monto::cero();

        $primera = $contrato->cuotas[0] ?? null;
        $ultima = $contrato->ultima();

        $deLaCuota = match (true) {
            $plan->cadaLote                                                                               => 'Entre los '.count($plan->porLote).' lotes',
            ! $primera instanceof CuotaProyectada || ! $ultima instanceof CuotaProyectada || $meses === 1 => '',
            $condiciones->interesEncima()                                                                 => 'Baja hasta '.$ultima->monto->formateado(),
            $ultima->monto->igualA($primera->monto)                                                       => 'Todas iguales',
            default                                                                                       => 'La última, '.$ultima->monto->formateado(),
        };

        $fechas = $primera instanceof CuotaProyectada && $ultima instanceof CuotaProyectada
            ? sprintf('Del %s al %s', $primera->vencimiento->format('d/m/Y'), $ultima->vencimiento->format('d/m/Y'))
            : '';

        $tasa = match (true) {
            ! $contrato->llevaInteres()   => 'Sin interés',
            $condiciones->interesEncima() => $condiciones->tasa->formateada().' anual, encima',
            default                       => $condiciones->tasa->formateada().' anual, incluido',
        };

        return $this->tarjeta(
            $condiciones->interesEncima() ? 'Primera cuota' : 'Cuota por mes',
            $contrato->cuotaMensual()?->formateado() ?? '—',
            [$deLaCuota, 'Antes '.($situacion->cuotaDeHoy()?->formateado() ?? '—')],
        )
            .$this->tarjeta(
                'Pagos',
                (string) $meses,
                [$fechas, sprintf('Pactados %d · antes %d', $pactados, $situacion->mesesQueFaltan())],
            )
            .$this->tarjeta(
                'Interés',
                $despues->formateado(),
                [$tasa, 'Antes '.$antes->formateado()],
            )
            .$this->tarjeta(
                'Falta pagar',
                $faltaNuevo->formateado(),
                [sprintf('Total del plan %s', $contrato->total()->formateado()), 'Antes '.$situacion->faltaHoy()->formateado()],
            );
    }

    /**
     * Las aclaraciones, una por renglón y solo las que aplican.
     *
     * @return list<string>
     */
    private function notasDelPlan(PlanUnificado $plan, CondicionesDeReestructuracion $condiciones, SituacionDelPlan $situacion, int $pactados): array
    {
        $lotes = count($situacion->lotes);

        if ($plan->cadaLote) {
            return [sprintf(
                'Cada uno de los %d lotes paga su capital en %d %s, con su propia cuota. Lo que se pague de un lote se aplica a ese lote.',
                $lotes,
                $pactados,
                $pactados === 1 ? 'mes' : 'meses',
            )];
        }

        $contrato = $plan->contrato;
        $pagos = $plan->meses();
        $ultima = $contrato->ultima()?->monto;
        $notas = [];

        if (! $condiciones->cuota instanceof Monto || ! $ultima instanceof Monto) {
            return $notas;
        }

        $alMes = $condiciones->interesEncima()
            ? $condiciones->cuota->formateado().' de capital'
            : $condiciones->cuota->formateado();

        $notas[] = match (true) {
            $pagos === $pactados + 1 => sprintf(
                'Con %s al mes, después de los %d meses le quedan %s por pagar: esa es la cuota %d.',
                $alMes,
                $pactados,
                $ultima->formateado(),
                $pagos,
            ),
            $pagos > $pactados => sprintf(
                'Con %s al mes, después de los %d meses todavía le quedan %d cuotas: termina en la cuota %d, de %s.',
                $alMes,
                $pactados,
                $pagos - $pactados,
                $pagos,
                $ultima->formateado(),
            ),
            $pagos < $pactados => sprintf(
                'Con %s al mes termina en %d %s, antes de los %d meses pactados. El último es de %s.',
                $alMes,
                $pagos,
                $pagos === 1 ? 'pago' : 'pagos',
                $pactados,
                $ultima->formateado(),
            ),
            default => sprintf('Con %s al mes termina justo en los %d meses pactados.', $alMes, $pactados),
        };

        if ($lotes > 1) {
            $notas[] = sprintf('Los %d lotes se pagan con esta sola cuota; cada pago se reparte entre ellos según lo que debe cada uno.', $lotes);
        }

        return $notas;
    }

    /**
     * Cada lote del plan nuevo, en un cuadro: cuánto debe, de cuánto queda su
     * cuota, cuándo termina y lo que ya tiene pagado. Con un lote no hace
     * falta: lo dicen las tarjetas.
     */
    private function cuadroDeCadaLote(PlanUnificado $plan, SituacionDelPlan $situacion): string
    {
        if (count($situacion->lotes) < 2) {
            return '';
        }

        $filas = '';

        foreach ($situacion->lotes as $id => $lote) {
            $cuotas = $plan->porLote[$id] ?? [];
            $primera = $cuotas[0] ?? null;
            $ultima = $cuotas === [] ? null : $cuotas[count($cuotas) - 1];
            $pagado = $situacion->yaPagadoDe($id);

            $filas .= sprintf(
                '<tr><td class="lote">%s</td><td>%s</td><td>%d</td><td class="fuerte">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                e($lote['codigo']),
                e($lote['capital']->formateado()),
                count($cuotas),
                e($primera instanceof CuotaProyectada ? $primera->monto->formateado() : '—'),
                e($ultima instanceof CuotaProyectada ? $ultima->monto->formateado() : '—'),
                e($ultima instanceof CuotaProyectada ? $ultima->vencimiento->format('d/m/Y') : '—'),
                $pagado->esCero() ? '<span class="apagado">—</span>' : e($pagado->formateado()),
            );
        }

        return '<div class="olympo-scroll"><table class="olympo-tabla"><thead><tr>'
            .'<th>Lote</th><th>Capital</th><th>Cuotas</th><th>Cuota</th><th>La última</th><th>Termina</th><th>Ya pagado</th>'
            .'</tr></thead><tbody>'.$filas.'</tbody></table></div>';
    }

    /**
     * El plan cuota por cuota, con lo ya pagado de cada mes. Es el mismo que
     * va al acta. Con altura fija y el encabezado quieto: un plan de 48 meses
     * no estira el modal hasta perder de vista el motivo y el botón.
     *
     * Con cada lote por su lado, una columna por lote en vez de capital e
     * interés: lo que se pregunta es cuánto paga cada uno ese mes.
     */
    private function tablaDelPlan(PlanUnificado $plan, CoberturaDelPlan $cobertura, SituacionDelPlan $situacion): string
    {
        $conPagado = ! $cobertura->cubierto()->esCero();
        $porLote = $plan->cadaLote;
        $filas = '';
        $totales = ['monto' => Monto::cero(), 'capital' => Monto::cero(), 'interes' => Monto::cero()];
        $deCadaLote = [];

        foreach ($plan->contrato->cuotas as $i => $cuota) {
            $cubierto = $cobertura->cubiertoDelMes($cuota->numero);
            $entera = ! $cubierto->esCero() && ! $cubierto->menorQue(new Monto($cuota->montoParaBase()));

            if ($porLote) {
                $columnas = '';

                foreach (array_keys($situacion->lotes) as $id) {
                    $delLote = $plan->porLote[$id][$i] ?? null;
                    $monto = $delLote instanceof CuotaProyectada ? new Monto($delLote->montoParaBase()) : Monto::cero();
                    $deCadaLote[$id] = ($deCadaLote[$id] ?? Monto::cero())->sumar($monto);

                    $columnas .= '<td>'.($monto->esCero() ? '<span class="apagado">—</span>' : e($monto->formateado())).'</td>';
                }
            } else {
                $columnas = '<td>'.e($cuota->capital->formateado()).'</td><td class="apagado">'.e($cuota->interes->formateado()).'</td>';
            }

            $filas .= sprintf(
                '<tr%s><td class="lote">%d</td><td>%s</td>%s<td class="fuerte">%s</td>%s</tr>',
                $entera ? ' class="cubierta"' : '',
                $cuota->numero,
                e($cuota->vencimiento->format('d/m/Y')),
                $columnas,
                e($cuota->monto->formateado()),
                $conPagado ? '<td>'.$this->pagadoDelMes($cubierto, $entera).'</td>' : '',
            );

            $totales = [
                'monto'   => $totales['monto']->sumar(new Monto($cuota->montoParaBase())),
                'capital' => $totales['capital']->sumar(new Monto($cuota->capitalParaBase())),
                'interes' => $totales['interes']->sumar(new Monto($cuota->interesParaBase())),
            ];
        }

        if ($porLote) {
            $encabezado = '';
            $pieDeLotes = '';

            foreach ($situacion->lotes as $id => $lote) {
                $encabezado .= '<th>'.e($lote['codigo']).'</th>';
                $pieDeLotes .= '<td>'.e(($deCadaLote[$id] ?? Monto::cero())->formateado()).'</td>';
            }
        } else {
            $encabezado = '<th>Capital</th><th>Interés</th>';
            $pieDeLotes = '<td>'.e($totales['capital']->formateado()).'</td><td>'.e($totales['interes']->formateado()).'</td>';
        }

        // El total abajo, siempre: la prueba a la vista de que el plan cuadra.
        $pie = sprintf(
            '<tfoot><tr><td colspan="2">Total</td>%s<td>%s</td>%s</tr></tfoot>',
            $pieDeLotes,
            e($totales['monto']->formateado()),
            $conPagado ? '<td>'.e($cobertura->cubierto()->formateado()).'</td>' : '',
        );

        return '<div class="olympo-scroll olympo-scroll-alto"><table class="olympo-tabla olympo-plan"><thead><tr>'
            .'<th>Cuota</th><th>Vence</th>'.$encabezado.'<th>Paga</th>'
            .($conPagado ? '<th>Ya pagado</th>' : '')
            .'</tr></thead><tbody>'.$filas.'</tbody>'.$pie.'</table></div>';
    }

    /**
     * Lo ya pagado de un mes, con su pastilla: «Pagada», o cuánto y «parcial».
     */
    private function pagadoDelMes(Monto $cubierto, bool $entera): string
    {
        if ($cubierto->esCero()) {
            return '<span class="apagado">—</span>';
        }

        return $entera
            ? '<span class="olympo-pill bien">Pagada</span>'
            : e($cubierto->formateado()).' <span class="olympo-pill parcial">Parcial</span>';
    }

    /**
     * Una tarjeta: rótulo chico, número grande y abajo el detalle, una línea
     * por cada texto —las vacías no se pintan—. `$tono` pinta la tarjeta
     * entera (mora en rojo, lo pagado en verde).
     *
     * @param string|list<string> $detalle
     */
    private function tarjeta(string $rotulo, string $valor, string|array $detalle, ?string $tono = null): string
    {
        $lineas = '';

        foreach (is_array($detalle) ? $detalle : [$detalle] as $linea) {
            if ($linea !== '') {
                $lineas .= '<span class="detalle">'.e($linea).'</span>';
            }
        }

        return '<div class="olympo-kpi'.($tono === null ? '' : ' '.$tono).'">'
            .'<span class="rotulo">'.e($rotulo).'</span>'
            .'<span class="valor">'.e($valor).'</span>'
            .$lineas
            .'</div>';
    }

    // ─── Ejecutar ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function reestructurar(array $data, ComponenteLivewire $livewire): void
    {
        // Sin cuotas pendientes el modal no muestra campos, pero el botón
        // sigue ahí: se dice el porqué de verdad, no «falta un dato».
        if ($this->situacion->lotes === []) {
            Notification::make()
                ->title('No hay plan que reestructurar')
                ->body('Este contrato no tiene cuotas pendientes.')
                ->warning()
                ->send();

            return;
        }

        $condiciones = $this->condicionesDe($data);

        if (is_string($condiciones)) {
            Notification::make()
                ->title('Falta un dato')
                ->body($condiciones)
                ->danger()
                ->send();

            return;
        }

        try {
            $acta = app(RegistroDeReestructuraciones::class)->reestructurar(
                venta: $this->venta,
                condiciones: $condiciones,
                motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                lotes: $this->lotesDe($data['lotes'] ?? null),
                desdeLaPrimera: ($data['desde_la_primera'] ?? false) === true,
                aunqueNazcaAtrasado: ($data['aunque_atrasado'] ?? false) === true,
            );
        } catch (GrupoOlympoException $error) {
            Notification::make()
                ->title('No se pudo reestructurar el contrato')
                ->body($error->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $meses = (int) $acta->getAttribute('meses_despues');

        $cuerpo = sprintf(
            'Desde el %s paga %s por mes%s, %d %s.',
            $condiciones->primerVencimiento->format('d/m/Y'),
            $acta->montoCuotaNueva()->formateado(),
            $acta->cadaLoteConSuCuota() ? ' entre los lotes, cada uno con su cuota' : '',
            $meses,
            $meses === 1 ? 'pago' : 'pagos',
        );

        if (! $acta->montoYaPagado()->esCero()) {
            $cuerpo .= sprintf(' Lo que ya pagó (%s) quedó pagando el plan nuevo, con los mismos recibos.', $acta->montoYaPagado()->formateado());
        }

        if (! $acta->montoMoraAntes()->esCero()) {
            $cuerpo .= sprintf(' Se perdonaron %s de mora: el plan nuevo arranca sin mora.', $acta->montoMoraAntes()->formateado());
        }

        if (($data['aunque_atrasado'] ?? false) === true && Cuota::query()->where('venta_id', $this->venta->getKey())->vencidas()->exists()) {
            $cuerpo .= ' Las cuotas vencidas quedaron pendientes: registrá cada pago con la fecha en que se pagó.';
        }

        Notification::make()
            ->title("Contrato reestructurado — acta {$acta->folio()}")
            ->body($cuerpo.' Imprimí el acta para que la firme el cliente.')
            ->success()
            ->persistent()
            ->actions([
                Action::make('imprimir')
                    ->label('Imprimir el acta')
                    ->url(route('documentos.reestructuracion', $acta))
                    ->openUrlInNewTab(),
            ])
            ->send();

        /*
         * Se recarga la ficha entera, no solo el encabezado: el plan de cuotas
         * y las reprogramaciones son pestañas con su propio componente, y sin
         * esto seguirían mostrando el plan viejo hasta que alguien recargue.
         * La notificación sobrevive a la recarga.
         */
        if ($livewire instanceof ViewRecord) {
            $livewire->redirect(VentaResource::getUrl('view', ['record' => $this->venta]));
        }
    }

    /**
     * Los lotes marcados, como ids de compromiso ordenados. Null es «no se
     * preguntó» —un contrato de un lote, o una llamada sin el campo—: entran
     * todos los que deben.
     *
     * @return list<int>|null
     */
    private function lotesDe(mixed $valor): ?array
    {
        if (! is_array($valor)) {
            return null;
        }

        $ids = [];

        foreach ($valor as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    // ─── Leer el formulario ───────────────────────────────────────────

    /**
     * Las condiciones, o la frase que dice qué falta para armarlas.
     *
     * Lo mismo para la vista previa y para el botón: si leyeran distinto, la
     * pantalla podría mostrar un plan y el botón firmar otro.
     *
     * ═══ CADA LOTE CON SU CUOTA: LOS MESES ═══
     *
     * Cada lote paga su capital en los meses pactados y su cuota sale de la
     * cuenta, con el redondeo en su última (§8.2). Una sola cuota para lotes
     * que deben distinto no tendría sentido: terminarían en meses distintos.
     *
     * ═══ UNIFICADOS: LOS MESES Y LA CUOTA, LOS DOS ═══
     *
     * Como en Maya: «tengo que colocar meses y cuota; si no, no tiene por qué
     * aparecer la tabla». El plan es la cuota pactada mes a mes hasta
     * terminar; si en los meses pactados no alcanza, lo que falta es un pago
     * más, y la vista previa lo dice.
     *
     * @param array<string, mixed> $datos
     */
    private function condicionesDe(array $datos): CondicionesDeReestructuracion|string
    {
        $meses = $this->mesesDe($datos['meses'] ?? null);
        $cadaLote = $this->cadaLoteDe($datos['agrupacion'] ?? null, $datos['lotes'] ?? null);

        if ($meses === null) {
            return $cadaLote
                ? 'Escribí en cuántos meses, y acá aparece cómo queda cada lote, cuota por cuota.'
                : 'Escribí en cuántos meses y de cuánto la cuota, y acá aparece cómo queda, cuota por cuota.';
        }

        if ($meses < 1 || $meses > PlanDeCuotas::PLAZO_MAXIMO_MESES) {
            return sprintf('Los meses van de 1 a %d.', PlanDeCuotas::PLAZO_MAXIMO_MESES);
        }

        $base = $this->baseDe($datos);

        if (is_string($base)) {
            return $base;
        }

        if ($cadaLote) {
            try {
                return CondicionesDeReestructuracion::porMeses($meses, $base['incluido'], $base['tasa'], $base['primero'])
                    ->cadaLoteConSuCuota();
            } catch (GrupoOlympoException $error) {
                return $error->getMessage();
            }
        }

        $cuota = $this->sinComas($datos['cuota'] ?? null);

        if ($cuota === null) {
            return 'Escribí de cuánto es la cuota por mes. Ejemplo: 20000.00';
        }

        try {
            return CondicionesDeReestructuracion::porCuota(new Monto($cuota), $base['incluido'], $base['tasa'], $base['primero']);
        } catch (GrupoOlympoException $error) {
            return $error->getMessage();
        }
    }

    /**
     * Lo que va igual con cualquier cuota: la tasa, si el interés va incluido
     * y el primer vencimiento.
     *
     * Sin interés en ningún lote (R1) el campo de la tasa no se muestra y el
     * plan nuevo va sin interés.
     *
     * @param array<string, mixed> $datos
     *
     * @return array{tasa: TasaDeInteres, primero: CarbonImmutable, incluido: bool}|string
     */
    private function baseDe(array $datos): array|string
    {
        $tasa = $this->llevaInteresElContrato() ? $this->tasaDe($datos['tasa'] ?? null) : TasaDeInteres::cero();

        if (! $tasa instanceof TasaDeInteres) {
            return 'Escribí la tasa de interés anual. Ejemplo: 12 — o 0 si va sin interés.';
        }

        $fecha = $datos['primer_vencimiento'] ?? null;

        if (! is_string($fecha) || preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha) !== 1) {
            return 'Elegí la fecha del primer vencimiento.';
        }

        return [
            'tasa'     => $tasa,
            'primero'  => CarbonImmutable::parse(substr($fecha, 0, 10)),
            'incluido' => ($datos['interes'] ?? 'incluido') !== 'encima',
        ];
    }

    /**
     * La cuota tecleada, sin las comas de los miles, o null si no es un monto.
     */
    private function sinComas(mixed $valor): ?string
    {
        if (is_int($valor)) {
            $valor = (string) $valor;
        }

        if (! is_string($valor)) {
            return null;
        }

        $limpio = str_replace([',', ' '], '', trim($valor));

        return preg_match('/^\d+(\.\d{1,2})?$/', $limpio) === 1 ? $limpio : null;
    }

    /**
     * Los meses como número, o null si el campo está vacío o no es un entero.
     */
    private function mesesDe(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : null;
    }

    /**
     * ¿La tasa tecleada cobra algo? Sin tasa legible, no: la pregunta de si la
     * cuota trae el interés no se hace hasta que haya una.
     */
    private function llevaInteres(mixed $valor): bool
    {
        if (! $this->llevaInteresElContrato()) {
            return false;
        }

        $tasa = $this->tasaDe($valor);

        return $tasa instanceof TasaDeInteres && ! $tasa->esCero();
    }

    /**
     * La tasa tecleada, o null si no se puede leer. Entra como texto y se
     * queda como texto: `TasaDeInteres` no acepta float, igual que `Monto`.
     */
    private function tasaDe(mixed $valor): ?TasaDeInteres
    {
        if (is_int($valor)) {
            $valor = (string) $valor;
        }

        // Un campo numérico puede devolver float. Una tasa no es dinero, y a
        // tres decimales —los de la columna— no pierde nada.
        if (is_float($valor)) {
            $valor = number_format($valor, TasaDeInteres::DECIMALES, '.', '');
        }

        if (! is_string($valor) || preg_match('/^\d+(\.\d{1,3})?$/', trim($valor)) !== 1) {
            return null;
        }

        try {
            return new TasaDeInteres(trim($valor));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * «6.200» se ve como «6.2» en el campo.
     */
    private function tasaParaElCampo(TasaDeInteres $tasa): string
    {
        $texto = $tasa->redondeada();

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }
}
