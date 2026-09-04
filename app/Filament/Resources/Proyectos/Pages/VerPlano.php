<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\UnidadDeArea;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\Lotes\RegistroDeReservas;
use App\Domain\Plano\AcomodadorDelPlano;
use App\Domain\Plano\Dxf\ImportadorDeDxf;
use App\Domain\Plano\Dxf\OpcionesDeImportacion;
use App\Domain\Plano\Dxf\UnidadDxf;
use App\Domain\Plano\ParametrosDeAcomodo;
use App\Domain\Plano\PlanoDelProyecto;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CotizacionDelLote;
use App\Domain\Ventas\ListaDePrecios;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\PlanDelContrato;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeVentas;
use App\Domain\Ventas\TasaDeInteres;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Schemas\Components\DNIField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\PrecioPorAreaField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Filament\Support\CobrarUnPago;
use App\Filament\Support\Cuadros;
use App\Filament\Support\DevolverLaSenia;
use App\Filament\Support\QuienRecibeElDinero;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;
use Override;
use Throwable;

/**
 * El plano del proyecto: los lotes dibujados y pintados por estado.
 *
 * Es una pagina de SOLO LECTURA. Cambiar el estado de un lote desde el
 * plano exige elegir cliente, y eso vive en su propia accion —no en un
 * clic suelto sobre un poligono, que es dinero moviendose sin registro.
 */
class VerPlano extends Page
{
    use InteractsWithRecord;

    #[Override]
    protected static string $resource = ProyectoResource::class;

    #[Override]
    protected string $view = 'filament.resources.proyectos.pages.ver-plano';

    /**
     * Los lotes ya leidos en este request, por su lista de ids.
     *
     * Privada a proposito: Livewire solo serializa lo publico, asi que esto
     * vive y muere adentro de un request y nunca viaja al navegador.
     *
     * @var array<string, list<Lote>>
     */
    private array $lotesLeidos = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    #[Override]
    public function getTitle(): string
    {
        return 'Plano de '.$this->getRecord()->getAttribute('nombre');
    }

    #[Override]
    public function getHeading(): string
    {
        return $this->getTitle();
    }

    /**
     * Sin migas de pan — pedido de Mauricio, 23-ago-2026.
     *
     * «Proyectos › RESIDENCIAL PRADERAS DEL SOL › Plano de RESIDENCIAL
     * PRADERAS DEL SOL» dice tres veces dónde estás parado, y la tercera
     * repite palabra por palabra el título que está justo debajo. Nadie
     * necesita la ruta acá: para volver está «Volver al proyecto», que es un
     * botón y no un renglón de texto.
     *
     * @return array<string>
     */
    #[Override]
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->accionDeImportar(),

            /*
             * 🔴 Con permiso propio desde el 23-ago-2026. `visible()` es lo
             * que saca el botón de la pantalla; la guarda de verdad está
             * adentro de `->action()`, porque una acción de Filament se monta
             * por URL y el botón no es una frontera.
             */
            Action::make('acomodar')
                ->label('Acomodar plano')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->visible(fn (): bool => auth()->user()?->can('acomodarPlano', Proyecto::class) === true)
                ->modalHeading('Acomodar el plano de forma esquemática')
                ->modalDescription(
                    'Dibuja los lotes que YA existen, en el orden de su código y cada uno con su área '.
                    'real. No toca número, área, precio ni estado. El resultado es un esquema, no el '.
                    'plano del topógrafo: el proyecto queda marcado como esquemático.'
                )
                ->modalSubmitActionLabel('Acomodar')
                ->schema([
                    TextInput::make('fondo')
                        ->label('Fondo de cada lote (varas)')
                        ->numeric()
                        ->required()
                        ->default('25')
                        ->helperText('El frente de cada lote sale de dividir SU área entre este fondo, '.
                                     'así que el rectángulo encierra exactamente el área cargada.'),

                    TextInput::make('filas')
                        ->label('Filas por bloque')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(20)
                        ->required()
                        ->default(2)
                        ->helperText('Dos filas es lo habitual: los lotes se dan la espalda y quedan '.
                                     'con frente a dos calles.'),

                    TextInput::make('separacionBloques')
                        ->label('Separación entre bloques (varas)')
                        ->numeric()
                        ->required()
                        ->default('10')
                        ->helperText('El espacio de calle que queda entre un bloque y el siguiente.'),
                ])
                ->action(function (array $data): void {
                    $this->exigirPermisoDelPlano('acomodarPlano');

                    /** @var Proyecto $proyecto */
                    $proyecto = $this->getRecord();

                    $dibujados = new AcomodadorDelPlano()->acomodarProyecto($proyecto, new ParametrosDeAcomodo(
                        fondoVaras: $this->texto($data, 'fondo', '25'),
                        filas: $this->entero($data, 'filas', 2),
                        separacionBloquesVaras: $this->texto($data, 'separacionBloques', '10'),
                    ));

                    if ($dibujados === 0) {
                        Notification::make()
                            ->title('No había lotes para dibujar')
                            ->body('Este proyecto todavía no tiene lotes cargados en ningún bloque.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Se dibujaron {$dibujados} lotes")
                        ->body('El plano quedó marcado como esquemático: respeta el área de cada lote, '.
                               'pero no su ubicación real en el terreno.')
                        ->success()
                        ->send();

                    $this->redirect(ProyectoResource::getUrl('plano', ['record' => $proyecto]));
                }),

            Action::make('volver')
                ->label('Volver al proyecto')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProyectoResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    // ─── Movimientos del lote, disparados desde el plano ──────────────

    /*
     * Estas tres acciones no van en la cabecera: se montan desde el modal
     * del lote con $wire.mountAction('apartarLote', { lote: id }). Filament
     * las resuelve por el nombre del metodo —{nombre}Action— y les inyecta
     * los argumentos.
     *
     * Apartar y liberar pasan por RegistroDeCompromisos. VENDER pasa por
     * RegistroDeVentas, que es otra cosa: numera el expediente y arma el
     * plan de cuotas.
     */

    /**
     * Apartar: el formulario pide SOLO el cliente.
     *
     * El monto y el vencimiento se eligen en el modal del lote —y llegan
     * con los numeros de R14 puestos—, asi que volver a pedirlos aca era
     * hacerle el mismo tramite dos veces a la misma persona. Viajan en
     * campos ocultos y se muestran como resumen, para que se vea que se
     * esta confirmando.
     *
     * ═══ POR QUE CAMPOS OCULTOS Y NO $arguments ═══
     *
     * `$arguments` se inyecta en los closures de la ACCION —fillForm,
     * action— pero NO en los de un componente del schema: Filament tira
     * BindingResolutionException al evaluar el content() de un Placeholder.
     * El estado del formulario si llega a todos lados, y un `Hidden` se
     * deshidrata aunque no se vea (a diferencia de un campo con
     * visible(false), que no se envia).
     */
    public function apartarLoteAction(): Action
    {
        return Action::make('apartarLote')
            ->label('Apartar lote')
            ->icon(Heroicon::OutlinedBookmark)
            ->color('warning')
            ->modalHeading('¿A nombre de quien?')
            ->modalDescription('Quedan reservados para esa persona. Se pueden liberar despues sin consecuencias.')
            ->modalSubmitActionLabel('Apartar')
            ->modalWidth('2xl')
            ->fillForm(fn (array $arguments): array => $this->datosInicialesDeApartado($arguments))
            ->schema([
                Hidden::make('lote_id'),
                Hidden::make('monto_senia'),
                Hidden::make('vence_el'),

                $this->selectorDeCliente('Cliente'),

                /*
                 * Los otros lotes que se marcaron en el plano. Solo los
                 * DISPONIBLES: un lote ya apartado no se aparta de nuevo, y
                 * ofrecerlo aca seria ofrecer algo que se va a rechazar.
                 */
                Select::make('lotes_extra')
                    ->label('Sumar otro lote')
                    ->multiple()
                    ->options(fn (Get $get): array => $this->otrosLotesVendibles($get, soloDisponibles: true))
                    ->searchable()
                    ->live()
                    ->helperText('Se marcan en el plano; aca solo se corrigen.'),

                Section::make('Lo que se reserva')
                    ->icon(Heroicon::OutlinedBookmark)
                    ->columns(3)
                    ->schema([
                        Placeholder::make('apartado_lotes')
                            ->label('Lotes')
                            ->columnSpanFull()
                            ->content(fn (Get $get): string => $this->cuentaDelApartado($get, 'lotes')),

                        Placeholder::make('apartado_senia')
                            ->label('Seña por lote')
                            ->content(fn (Get $get): string => $this->cuentaDelApartado($get, 'senia')),

                        Placeholder::make('apartado_total')
                            ->label('Se cobra hoy')
                            ->content(fn (Get $get): string => $this->cuentaDelApartado($get, 'total')),

                        Placeholder::make('apartado_vence')
                            ->label('Vence el')
                            ->content(fn (Get $get): string => $this->cuentaDelApartado($get, 'vence')),

                        Placeholder::make('resumen')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(
                                'Al vender cuenta como parte de la prima. Si vence sin que el cliente vuelva, '.
                                'el lote se libera y el dinero se devuelve.'
                            ),

                        /*
                         * La misma puerta que en la venta, y por lo mismo: el
                         * apartado cobra plata de verdad y quema un numero de
                         * recibo. Acá la pregunta es más corta porque el
                         * trámite es más corto — lo que se puede equivocar es
                         * el lote, la seña y a nombre de quién.
                         */
                        Checkbox::make('confirmado')
                            ->label('Revisé el lote, la seña y a nombre de quién queda.')
                            ->accepted()
                            ->columnSpanFull()
                            /*
                             * La banda ámbar/verde — 31-ago-2026. Va en el
                             * ENVOLTORIO del campo y no en el input, que es lo
                             * único que abarca la casilla y su texto. El CSS
                             * vive en `filament/estilos-olympo`, con su
                             * explicación.
                             */
                            ->extraFieldWrapperAttributes(['class' => 'olympo-confirmar'])
                            ->validationMessages([
                                'accepted' => 'Falta confirmar que revisaste los datos del apartado.',
                            ]),
                    ]),

                /*
                 * La seña se cobra AHORA, y por eso hay que decir como
                 * entro: cada apartado sale con su recibo numerado (R14 +
                 * R12) y la forma de pago va impresa en el papel.
                 *
                 * Los dos campos desaparecen cuando la seña es L 0.00 —
                 * apartar sin adelanto es legitimo—, y `visible(false)` en
                 * Filament ademas no envia el campo: por eso la accion lee
                 * `forma_pago` con tryFrom y no lo da por presente.
                 */
                Select::make('forma_pago')
                    ->label('¿Como entro la seña?')
                    ->options(fn (): array => $this->formasDePago())
                    ->live()
                    ->native(false)
                    ->visible(fn (Get $get): bool => $this->hayQueCobrarSenia($get))
                    ->required(fn (Get $get): bool => $this->hayQueCobrarSenia($get))
                    ->helperText('Va impreso en el recibo que se lleva el cliente.'),

                /*
                 * Quien recibio la seña (R24) — 31-ago-2026. «Aca en apartar
                 * que se coloque quien recibe el dinero» — Mauricio.
                 *
                 * Al lado de la forma de pago y con su misma visibilidad: sin
                 * seña que cobrar no hay recibo, y preguntar quien recibio un
                 * dinero que nadie entrego no tiene respuesta.
                 */
                QuienRecibeElDinero::campo(
                    'Viene marcado con tu nombre; cambialo si la seña la recibió otra persona. De acá sale el corte de caja del día.'
                )->visible(fn (Get $get): bool => $this->hayQueCobrarSenia($get)),

                TextInput::make('referencia')
                    ->label('Numero de referencia')
                    ->maxLength(60)
                    ->visible(fn (Get $get): bool => $this->hayQueCobrarSenia($get) && $this->exigeReferencia($get))
                    ->required(fn (Get $get): bool => $this->hayQueCobrarSenia($get) && $this->exigeReferencia($get))
                    ->helperText('Es lo unico que despues permite encontrar ese movimiento en el estado de cuenta del banco (R11).'),

                Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    $cliente = Cliente::query()->findOrFail($this->entero($data, 'cliente_id', 0));
                    $lotes = $this->lotesDelContrato((int) $lote->getKey(), $data['lotes_extra'] ?? null);

                    app(RegistroDeCompromisos::class)
                        ->loRecibio(QuienRecibeElDinero::elegido($data))
                        ->apartarVarios(
                            $lotes,
                            $cliente,
                            montoSenia: $this->texto($data, 'monto_senia', '') ?: null,
                            venceEl: $this->texto($data, 'vence_el', '') ?: null,
                            observaciones: $this->texto($data, 'observaciones', '') ?: null,
                            forma: FormaDePago::tryFrom($this->texto($data, 'forma_pago', '')),
                            referencia: $this->texto($data, 'referencia', '') ?: null,
                        );

                    return sprintf(
                        '%s %s a nombre de %s.',
                        $this->codigosDe($lotes),
                        count($lotes) > 1 ? 'quedaron apartados' : 'quedo apartado',
                        (string) $cliente->getAttribute('nombre'),
                    );
                });
            });
    }

    /**
     * Vender: tambien pide solo lo que falta.
     *
     * El plazo, el precio por vara y la prima se cotizan en el modal del
     * lote. Aca se muestran en un resumen —con la cuota, calculada con el
     * mismo PlanDeCuotas que despues persiste (§10.8)— y se pregunta lo
     * que el modal no puede: quien compra, que dia paga y con que fecha.
     *
     * Los tres SOLO aparecen como campos cuando el modal no los mando, que
     * es el caso de un proyecto sin planes cargados. El formulario pregunta
     * lo que falta, literalmente.
     *
     * Detras sigue mandando el servidor: RegistroDeVentas revalida el
     * precio contra la lista del plazo y exige motivo si baja (R4). Lo que
     * viaja por la pantalla no es autoridad sobre el dinero.
     */
    public function venderLoteAction(): Action
    {
        return Action::make('venderLote')
            ->label('Vender lote')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->modalHeading('Firmar la venta')
            ->modalDescription(
                'Se numera el expediente, se congelan el area y el precio de cada lote, y queda '.
                'armado el plan de cuotas. Si un lote venia apartado, tiene que ser a nombre del titular.'
            )
            ->modalSubmitActionLabel('Firmar la venta')
            /*
             * 🔴 5xl desde el 23-ago-2026. En 3xl todo caía en una columna
             * angosta con media pantalla vacía a los lados, y firmar una venta
             * pedía tres pantallazos de scroll. Lo pidió Mauricio mirando una
             * venta de contado: «se quiere ver los datos sin tener que hacer
             * tanto scroll, aprovechá mejor el espacio».
             */
            ->modalWidth('5xl')
            ->fillForm(fn (array $arguments): array => $this->datosInicialesDeVenta($arguments))
            ->schema([
                Hidden::make('lote_id'),
                Hidden::make('cotizado'),

                Section::make('Quienes firman')
                    ->description('El titular es a quien le sale el estado de cuenta. Los demas firman igual y pueden pagar igual.')
                    ->icon(Heroicon::OutlinedUsers)
                    ->columns(2)
                    ->schema([
                        $this->selectorDeCliente('Titular'),

                        /*
                         * Marido y mujer, o socios. Un lote puede quedar a
                         * nombre de varias personas (R8): el pivot
                         * `venta_cliente` guarda a todas y marca a UNA como
                         * titular —tiene un indice unico parcial sobre
                         * `venta_id WHERE titular`, o sea que la base no
                         * admite dos.
                         */
                        Select::make('copropietarios')
                            ->label('Copropietarios')
                            ->multiple()
                            ->options(fn (Get $get): array => $this->clientesMenosElTitular($get))
                            ->searchable()
                            ->helperText('Opcional. Si falta alguno, se carga con el mismo boton +.'),
                    ]),

                /*
                 * Lo que viaja del plano: una linea por lote con SU plazo, SU
                 * precio y SU prima. Va como JSON y no como arreglo porque un
                 * `Hidden` pinta su valor adentro de un <input>, y un arreglo
                 * ahi se convierte en la palabra «Array».
                 */
                Hidden::make('condiciones'),

                Section::make('Que se lleva')
                    ->description('Cada lote con el plazo que se le marco en el plano.')
                    ->icon(Heroicon::OutlinedMap)
                    ->schema([
                        Placeholder::make('tabla_de_lotes')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (Get $get): HtmlString => $this->tablaDeLotes($get)),
                    ]),

                /*
                 * 🔴 DE CONTADO ESTA SECCION NO SALE (23-ago-2026).
                 *
                 * Sus tres números son «Valor», «Prima» y «Saldo a financiar»,
                 * y de contado la prima ES el valor y el saldo es L 0.00: tres
                 * casillas para decir un solo número, más una escalera de
                 * cuotas que dice «De contado, sin cuotas». Un cuarto de la
                 * altura del modal contando algo que no pasa.
                 *
                 * Lo que de contado SÍ hace falta —cuánto es y que se cobra
                 * todo hoy— ya lo dice la línea de «Antes de firmar», que está
                 * pegada al botón, que es donde se lee.
                 *
                 * Financiado no se toca: ahí los tres números son distintos
                 * entre sí y la escalera es la respuesta a «¿cuánto pago por
                 * mes?», que con plazos mezclados no tiene una sola.
                 */
                Section::make('Lo que se va a firmar')
                    ->description('Con el mismo motor que despues guarda la venta.')
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->columns(3)
                    ->visible(fn (Get $get): bool => ! $this->soloContado($get))
                    ->schema([
                        Placeholder::make('resumen_valor')
                            ->label('Valor')
                            ->content(fn (Get $get): string => $this->cuenta($get, 'valor')),

                        Placeholder::make('resumen_prima')
                            ->label('Prima')
                            ->content(fn (Get $get): string => $this->cuenta($get, 'prima')),

                        Placeholder::make('resumen_saldo')
                            ->label('Saldo a financiar')
                            ->content(fn (Get $get): string => $this->cuenta($get, 'saldo')),

                        /*
                         * La escalera. Con un lote a 12 meses, otro a 24 y otro
                         * a 48, «¿cuanto pago por mes?» no tiene una sola
                         * respuesta: cuando el primero se termina de pagar, a
                         * partir del mes 13 es una cuota menos.
                         */
                        Placeholder::make('resumen_cuotas')
                            ->label('Lo que paga por mes')
                            ->columnSpanFull()
                            ->content(fn (Get $get): HtmlString => $this->escaleraDeCuotas($get)),

                        // Solo aparece cuando hay algo que no cierra. Ocupa el
                        // ancho entero porque lo que dice es una frase, no un
                        // numero, y leerla es lo que desatasca el tramite.
                        Placeholder::make('resumen')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $this->cuenta($get, 'aviso') !== '')
                            ->content(fn (Get $get): string => $this->cuenta($get, 'aviso')),
                    ]),

                /*
                 * ═══ DOS COLUMNAS Y NO TRES (31-ago-2026) ═══
                 *
                 * Lo dijo Mauricio mirando el modal: «que use mejor el
                 * espacio, se ve feo». Y era cierto, aunque el motivo no se
                 * ve hasta contar los campos.
                 *
                 * Despues de cotizar quedan CUATRO campos a la vista —dia de
                 * pago, fecha, como entra la prima y quien la recibio— y
                 * cuatro no llenan una grilla de tres: dejaban un tercio en
                 * blanco en cada fila, con los campos angostos y los textos de
                 * ayuda partidos en tres renglones. De a dos, esos cuatro son
                 * dos filas al ras.
                 *
                 * El camino manual —el que teclea plazo, precio, prima y
                 * tasa— tambien cierra parejo, y por eso la tasa subio al lado
                 * de la prima: los cuatro numeros del negocio juntos, arriba,
                 * y las fechas y el dinero abajo.
                 */
                Section::make('Condiciones')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(2)
                    ->schema([
                        /*
                         * ╔══ dehydratedWhenHidden() NO ES DECORACION ══╗
                         *
                         * Filament NO envia lo que esta oculto: isDehydrated()
                         * llama a isHiddenAndNotDehydratedWhenHidden() y borra
                         * la clave del estado. Estos tres campos se esconden
                         * justamente cuando el modal ya los cotizo —que es el
                         * camino normal—, asi que sin esta linea el plazo, el
                         * precio y la prima llegaban vacios a la accion: la
                         * venta se armaba a L 0.00 y de contado.
                         *
                         * No se cae solo: el Service ve un precio por debajo de
                         * la lista, pide motivo por escrito (R4) y tira un
                         * mensaje que no tiene nada que ver con lo que paso.
                         * Peor que reventar.
                         */
                        TextInput::make('plazo_meses')
                            ->label('Plazo en meses')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(PlanDeCuotas::PLAZO_MAXIMO_MESES)
                            ->required()
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => ! $get('cotizado'))
                            ->dehydratedWhenHidden()
                            ->helperText('0 es contado, sin cuotas.'),

                        PrecioPorAreaField::make('precio_vara')
                            ->label('Precio '.$this->unidad()->porUnidad())
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => ! $get('cotizado'))
                            ->dehydratedWhenHidden(),

                        /*
                         * 🔴 OBLIGATORIA Y MAYOR QUE CERO cuando hay cuotas.
                         *
                         * Pedido de Mauricio el 14-ago-2026. Una venta
                         * financiada sin prima es la que se cae: el cliente
                         * se lleva el lote sin haber puesto nada, y el dia
                         * que deja de pagar no hay nada retenido con que
                         * liquidar la rescision (R22).
                         *
                         * CUANTO cobrar sigue siendo decision comercial: el
                         * sistema exige que haya prima, no cuanta. Un piso
                         * en el codigo obliga a pelearse con el sistema el
                         * dia que negocien distinto.
                         *
                         * Al contado no se pide: ahi la prima ES el valor y
                         * la impone `primaDelRenglon()` — el campo queda solo
                         * como referencia de lo que se va a cobrar.
                         */
                        MontoField::make('prima', 'Prima')
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => ! $get('cotizado'))
                            ->dehydratedWhenHidden()
                            ->required(fn (Get $get): bool => ! $this->soloContado($get))
                            ->rules(fn (Get $get): array => $this->soloContado($get)
                                ? []
                                : ['gt:0'])
                            ->validationMessages(['gt' => 'Con plan de cuotas la prima no puede ser L 0.00.'])
                            ->helperText(fn (Get $get): string => $this->soloContado($get)
                                ? 'Al contado no se teclea: la prima es el valor entero y se paga completo al firmar.'
                                : 'Se paga completa al firmar (R5), y no puede ser cero.'),

                        /*
                         * El precio del DINERO, al lado del precio del
                         * terreno y con la misma regla. Vive fuera del cuadro
                         * cotizado por lo mismo que `motivo_descuento`: es uno
                         * por contrato, y el Service lo aplica a cada renglon.
                         */
                        TextInput::make('tasa_interes_anual')
                            ->label('Interés anual')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(TasaDeInteres::MAXIMA)
                            ->step('0.001')
                            ->suffix('%')
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => ! $get('cotizado'))
                            ->dehydratedWhenHidden()
                            ->helperText('Vacío o 0 es sin interés. Si baja de la del plan, hay que escribir por qué.'),

                        /*
                         * Sin cuotas no hay dia de pago. Lo pidio Mauricio el
                         * 14-ago-2026 viendo el modal de una venta de contado:
                         * preguntar «¿que dia paga?» cuando no va a pagar
                         * ningun dia mas es pedir un dato que no significa
                         * nada, y quien atiende se queda dudando si entendio
                         * mal la venta.
                         *
                         * `dehydratedWhenHidden()`: el valor viaja igual
                         * aunque no se vea. La columna `ventas.dia_pago` es
                         * NOT NULL con CHECK de 1 a 31, y una venta de
                         * contado tambien tiene que guardarse.
                         */
                        Select::make('dia_pago')
                            ->label('Dia de pago')
                            ->options($this->diasDelMes())
                            ->required(fn (Get $get): bool => ! $this->soloContado($get))
                            ->visible(fn (Get $get): bool => ! $this->soloContado($get))
                            ->dehydratedWhenHidden()
                            ->live()
                            ->native(false)
                            ->helperText('En los meses cortos se corre al ultimo dia.'),

                        DatePicker::make('fecha_contrato')
                            ->label('Fecha del contrato')
                            ->required()
                            ->live()
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        /*
                         * La prima se paga completa al firmar (R5), asi que
                         * al firmar hay dinero entrando y sale su recibo. Lo
                         * que se cobra hoy es la prima MENOS lo que ya se
                         * recibio en señas de apartado (R14) — el Service
                         * hace esa resta y el recibo lo dice.
                         */
                        Select::make('forma_pago_prima')
                            ->label('¿Como entra la prima?')
                            ->options(fn (): array => $this->formasDePago())
                            ->required()
                            ->live()
                            ->native(false)
                            ->helperText('Va impreso en el recibo de la prima.'),

                        /*
                         * Quien recibio la prima (R24) — 31-ago-2026. «Y cuando
                         * se vende tambien quien recibe el dinero» — Mauricio.
                         *
                         * Es la entrada de dinero mas grande del sistema y era
                         * la unica que no decia de quien era: el corte de caja
                         * del dia la sumaba bajo «Sin usuario».
                         */
                        QuienRecibeElDinero::campo(
                            'Viene con tu nombre. Cambialo si la recibió otra persona: de acá sale el corte de caja del día.'
                        ),

                        TextInput::make('referencia_prima')
                            ->label('Numero de referencia')
                            ->maxLength(60)
                            ->visible(fn (Get $get): bool => $this->exigeReferenciaDeLaPrima($get))
                            ->required(fn (Get $get): bool => $this->exigeReferenciaDeLaPrima($get))
                            ->helperText('Es lo unico que despues permite encontrar ese movimiento en el estado de cuenta del banco (R11).'),

                        /*
                         * Los dos motivos van AL FINAL y a lo ancho, y no
                         * intercalados entre los campos cortos: son frases, no
                         * datos, y aparecen solo cuando alguien bajo un
                         * numero. Puestos en el medio partian una fila de dos
                         * al ras y dejaban media pantalla en blanco arriba de
                         * la prima (31-ago-2026, mirando el modal).
                         */
                        TextInput::make('motivo_tasa')
                            ->label('Motivo de la tasa')
                            ->maxLength(200)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $this->hayRebajaDeTasa($get))
                            ->required(fn (Get $get): bool => $this->hayRebajaDeTasa($get))
                            ->helperText('El interés va por debajo del que ofrece el plan. R4: queda con tu usuario y la fecha.'),

                        TextInput::make('motivo_descuento')
                            ->label('Motivo del descuento')
                            ->maxLength(200)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $this->hayDescuento($get))
                            ->required(fn (Get $get): bool => $this->hayDescuento($get))
                            ->helperText('El precio va por debajo del de lista. R4: queda con tu usuario y la fecha.'),

                        Textarea::make('observaciones')
                            ->label('Observaciones')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                /*
                 * ═══ 🔴 LA ULTIMA PUERTA ANTES DE QUEMAR UN CORRELATIVO ═══
                 *
                 * Lo pidio Mauricio el 14-ago-2026: «que aparezca una alerta
                 * cuando le de vender, para que confirmen que estan seguros de
                 * esa venta, en ese plazo y con ese precio por vara y cuota
                 * mensual, en una tabla, para mayor seguridad ya que se pueden
                 * equivocar».
                 *
                 * La tabla ya estaba —«Que se lleva», mas arriba— pero se
                 * podia firmar de largo sin mirarla. Lo que faltaba no era el
                 * dato: era el ACTO de confirmarlo.
                 *
                 * ═══ POR QUE UN CHECKBOX Y NO UN SEGUNDO MODAL ═══
                 *
                 * Porque este ya es un modal, y Filament no encadena uno sobre
                 * otro sin pelearse con Livewire. Y porque un modal encima de
                 * otro se cierra con el mismo gesto reflejo con el que se
                 * abrio: no hace leer nada. Un checkbox obligatorio, al lado
                 * del resumen y arriba del boton, es la unica friccion que
                 * de verdad obliga a mirar.
                 *
                 * ⚠️ Se repiten los numeros que MAS caro salen mal —plazo,
                 * precio por unidad y cuota— aunque ya esten arriba. La
                 * repeticion es el punto: quien scrolleo hasta aca sin leer,
                 * los ve una vez mas justo antes de apretar.
                 */
                Section::make('Antes de firmar, revisá esto con el cliente')
                    ->description('Se va a numerar el expediente y estos números quedan congelados. Deshacerlo después es una rescisión.')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->schema([
                        Placeholder::make('confirmacion_tabla')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (Get $get): HtmlString => $this->tablaDeLotes($get)),

                        Placeholder::make('confirmacion_plata')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (Get $get): string => sprintf(
                                'Valor %s · Prima %s · %s',
                                $this->cuenta($get, 'valor'),
                                $this->cuenta($get, 'prima'),
                                $this->soloContado($get)
                                    ? 'DE CONTADO: se cobra todo hoy y no quedan cuotas'
                                    : 'Saldo a financiar '.$this->cuenta($get, 'saldo'),
                            )),

                        Checkbox::make('confirmado')
                            ->label('Revisé el plazo, el precio y la cuota con el cliente, y están correctos.')
                            ->accepted()
                            ->columnSpanFull()
                            // La misma banda que en apartar: mientras falta se
                            // ve ámbar, y en cuanto se tilda se pone verde.
                            ->extraFieldWrapperAttributes(['class' => 'olympo-confirmar'])
                            ->validationMessages([
                                'accepted' => 'Falta confirmar que revisaste los números antes de firmar.',
                            ]),
                    ]),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    /** @var Proyecto $proyecto */
                    $proyecto = $this->getRecord();

                    /*
                     * 🔴 SIN PLAN DE PAGO NO SE VENDE (23-ago-2026).
                     *
                     * Acá y no solo en el botón: el botón se deshabilita, pero
                     * esta acción se monta desde JS —`$wire.mountAction(...)`—
                     * y eso se dispara desde la consola del navegador en diez
                     * segundos. Es la misma lección que `modo` en el modal de
                     * cobro: un campo se falsifica, un botón se rehabilita.
                     */
                    if (! $proyecto->tieneConQueVender()) {
                        throw VentaInvalidaException::porProyectoSinPlanDePago(
                            (string) $proyecto->getAttribute('nombre'),
                        );
                    }

                    $titular = Cliente::query()->findOrFail($this->entero($data, 'cliente_id', 0));
                    $clientes = $this->clientesDeLaVenta($titular, $data);

                    /*
                     * Cada lote llega con LO SUYO: su plazo, su precio por
                     * vara² —que depende del plazo— y su prima. El Service
                     * arma un plan de cuotas por renglon.
                     *
                     * El motivo del descuento, si lo hubo, viaja con cada uno
                     * porque el CHECK de la base lo exige lote por lote.
                     */
                    $condiciones = $this->condicionesDelFormulario($data, $lote);
                    $lotes = $this->lotesDelContrato((int) $lote->getKey(), array_column($condiciones, 'lote'));
                    $motivo = $this->texto($data, 'motivo_descuento', '') ?: null;
                    $motivoTasa = $this->texto($data, 'motivo_tasa', '') ?: null;

                    $porLote = [];

                    foreach ($condiciones as $condicion) {
                        $porLote[$condicion['lote']] = $condicion;
                    }

                    $primaTotal = Monto::cero();
                    $precios = [];

                    foreach ($lotes as $uno) {
                        $suyo = $porLote[(int) $uno->getKey()] ?? null;
                        $precioDelLote = $this->monto($suyo['precio'] ?? '0');

                        // El mismo calculo que la previsualizacion, para que
                        // lo que se firma sea lo que se vio.
                        $prima = $this->primaDelRenglon(
                            $suyo['plazo'] ?? 0,
                            new Monto($precioDelLote->multiplicarPor($this->areaDe($uno))->redondeado()),
                            $this->monto($suyo['prima'] ?? '0'),
                        );

                        $primaTotal = $primaTotal->sumar($prima);

                        $precios[] = new PrecioPactado(
                            loteId: (int) $uno->getKey(),
                            precioVara: $precioDelLote,
                            motivo: $motivo,
                            plazoMeses: $suyo['plazo'] ?? 0,
                            prima: $prima,
                            // Null es «la del plan de ese plazo»: el Service
                            // la resuelve y la congela junto al precio.
                            tasa: $this->tasaTecleada($suyo['tasa'] ?? null),
                            motivoTasa: $motivoTasa,
                        );
                    }

                    $venta = app(RegistroDeVentas::class)
                        ->loRecibio(QuienRecibeElDinero::elegido($data))
                        ->activar(
                            proyecto: $proyecto,
                            lotes: $lotes,
                            clientes: $clientes,
                            prima: $primaTotal,
                            plazoMeses: $condiciones[0]['plazo'] ?? 0,
                            diaPago: $this->entero($data, 'dia_pago', 1),
                            fechaContrato: CarbonImmutable::parse(
                                $this->texto($data, 'fecha_contrato', today()->toDateString())
                            ),
                            observaciones: $this->texto($data, 'observaciones', '') ?: null,
                            precios: $precios,
                            formaPrima: FormaDePago::tryFrom($this->texto($data, 'forma_pago_prima', ''))
                                ?? FormaDePago::Efectivo,
                            referenciaPrima: $this->texto($data, 'referencia_prima', '') ?: null,
                        );

                    // El contrato ya existe: lo que sigue pasa en su ficha.
                    $this->destinoDespues = VentaResource::getUrl('view', ['record' => $venta]);

                    return $this->avisoDeVenta($venta, $lotes, $titular, count($clientes));
                });
            });
    }

    /**
     * Corregir una donación que quedó registrada por error.
     *
     * El caso de Mauricio, 13-ago-2026: «iban a donar 5, los donaron, pero
     * hubo un error, así que solo se donarían 3; esos 2 deben quedar
     * disponibles para la venta».
     *
     * La palabra del botón importa y por eso NO dice «devolver»: no se
     * está deshaciendo una entrega que ocurrió —para eso ya habría una
     * escritura firmada a nombre de otro— sino sacándole la marca a un
     * lote que nunca se regaló. Lo que lo hace posible es que una donación
     * no movió un lempira: no hay seña, ni recibos, ni cuotas que desarmar.
     */
    public function deshacerDonacionAction(): Action
    {
        return Action::make('deshacerDonacion')
            ->label('Quitar de donación')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->modalHeading('Quitar la donación de este lote')
            ->modalDescription('El lote vuelve a estar DISPONIBLE para la venta. Como una donación no lleva plata, no hay nada que devolver ni recibo que anular.')
            ->modalSubmitActionLabel('Quitar la donación')
            ->modalWidth('lg')
            ->schema([
                Textarea::make('motivo')
                    ->label('¿Por qué se le quita?')
                    ->required()
                    ->rows(3)
                    ->placeholder('Se marcaron cinco lotes por error; la junta aprobó donar solo tres.')
                    ->helperText('Queda anotado con tu usuario y la fecha. Es lo único que después explica por qué este lote figuró como regalado y volvió al inventario.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    app(RegistroDeCompromisos::class)->deshacerDonacion(
                        $lote,
                        $this->texto($data, 'motivo', ''),
                    );

                    return sprintf(
                        '%s ya no figura como donado y volvió a estar disponible.',
                        (string) $lote->getAttribute('codigo'),
                    );
                });
            });
    }

    /**
     * Donar: el lote sale del inventario sin que entre un lempira.
     *
     * ═══ POR QUE NO ES UNA PESTAÑA MAS AL LADO DE VENDER Y APARTAR ═══
     *
     * Porque no es una forma de vender. Vender y apartar son el mismo gesto en
     * dos tiempos —hay un cliente que paga— y por eso comparten el conmutador,
     * la seleccion de lotes y la cotizacion. Una donacion no cotiza nada, es
     * de UN lote y ocurre una vez cada varios meses. Metida entre las dos
     * pestañas, lo unico que lograria es estar un click mas cerca del error.
     *
     * ═══ LO QUE PREGUNTA, Y LO QUE NO ═══
     *
     * A nombre de quien y por que. El motivo es OBLIGATORIO: de los tres
     * compromisos, este es el unico que se va sin dejar rastro de plata, y
     * dentro de un año alguien —un socio, un heredero, un contador— va a
     * preguntar por que ese lote no genero ningun ingreso.
     *
     * No pregunta el valor. Se congela el de lista, que es el que hace falta
     * para la escritura; si el documento declara otro numero, va escrito en
     * las observaciones. Dejarlo teclear obligaria a inventar un precio por
     * vara² que cuadre con el CHECK `valor = ROUND(area_varas * precio_vara, 2)`,
     * y ese numero inventado despues sale en los reportes de precios como si
     * fuera real.
     *
     * ⚠️ El valor se muestra en un Placeholder que lee el ESTADO del
     * formulario y no `$arguments`: Filament no inyecta los argumentos de la
     * accion en los closures de un componente del schema. Es la misma razon
     * por la que `apartarLote` usa campos ocultos — ver su docblock.
     */
    public function donarLoteAction(): Action
    {
        return Action::make('donarLote')
            ->label('Donar lote')
            ->icon(Heroicon::OutlinedGift)
            ->color('teal')
            ->modalHeading('Donar el lote')
            ->modalDescription('Sale del inventario y no vuelve. No lleva prima, ni cuotas, ni recibos.')
            ->modalSubmitActionLabel('Donar')
            ->modalWidth('xl')
            ->fillForm(fn (array $arguments): array => $this->datosInicialesDeDonacion($arguments))
            ->schema([
                Hidden::make('lote_id'),
                Hidden::make('valor'),

                Placeholder::make('lo_que_se_dona')
                    ->label('Lo que se entrega')
                    ->content(function (Get $get): string {
                        $valor = $get('valor');

                        return is_string($valor) && $valor !== '' ? $valor : 'Sin valor cargado';
                    }),

                $this->selectorDeCliente('¿A nombre de quién?'),

                Textarea::make('motivo')
                    ->label('¿Por qué se dona?')
                    ->required()
                    ->rows(3)
                    ->placeholder('Donado a la Iglesia Congregacional. Acta de la junta directiva del ...')
                    ->helperText('Queda anotado con tu usuario y la fecha. Es lo único que después explica por qué este lote no generó ningún ingreso.'),

                Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2)
                    ->helperText('Si la escritura declara otro valor —el catastral, o uno simbólico—, va acá.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    $cliente = Cliente::query()->findOrFail($this->entero($data, 'cliente_id', 0));

                    app(RegistroDeCompromisos::class)->donar(
                        $lote,
                        $cliente,
                        $this->texto($data, 'motivo', ''),
                        $this->texto($data, 'observaciones', '') ?: null,
                    );

                    return sprintf(
                        '%s quedó donado a %s.',
                        (string) $lote->getAttribute('codigo'),
                        (string) $cliente->getAttribute('nombre'),
                    );
                });
            });
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function datosInicialesDeDonacion(array $arguments): array
    {
        $lote = Lote::query()->find($this->entero($arguments, 'lote', 0));

        return [
            'lote_id' => $lote?->getKey(),
            /*
              * Los dos decimales de siempre y no los CUATRO de la columna:
              * `area_varas` es numeric(12,4) y crudo se lee «250.0000 v²».
              * Pasa por Monto —bcmath sobre strings— y no por number_format,
              * que recibe float (§8.3.1).
              */
            'valor' => $lote instanceof Lote
                ? sprintf(
                    '%s · %s %s',
                    $lote->montoValor()->formateado(),
                    new Monto((string) $lote->getAttribute('area_varas'))->redondeado(),
                    $this->unidad()->corta(),
                )
                : null,
        ];
    }

    /**
     * Guardar un lote para la familia.
     *
     * Lo pidio Mauricio el 13-ago-2026: «para los reservados, estos son
     * para lotes heredados». Es el gemelo de donar y comparte su forma —un
     * lote, un motivo obligatorio, un cupo declarado antes— pero NO su
     * maquinaria: no pregunta a nombre de quien y no escribe ningun
     * compromiso. Guardar un lote no ata a nadie todavia; ata cuando el
     * tramite se cierra y el lote se dona, que es el camino de al lado.
     *
     * ⚠️ El boton dice «herencia» y el plano publico dira «Reservado». Ver
     * EstadoLote::etiquetaInterna().
     */
    public function reservarLoteAction(): Action
    {
        return Action::make('reservarLote')
            ->label('Guardar para herencia')
            ->icon(Heroicon::OutlinedHomeModern)
            // `gray` y no un violeta: es el color que el panel tiene
            // registrado para el reservado (ver AdminPanelProvider). El
            // #7c3aed del enum es para el SVG del plano, que no pasa por
            // la paleta de Filament.
            ->color('gray')
            ->modalHeading('Guardar este lote para herencia')
            ->modalDescription('Sale del mercado y deja de ofrecerse. No genera venta ni cartera, y se puede devolver a la venta cuando haga falta.')
            ->modalSubmitActionLabel('Guardar')
            ->modalWidth('lg')
            ->schema([
                Textarea::make('motivo')
                    ->label('¿Por qué se guarda?')
                    ->required()
                    ->rows(3)
                    ->placeholder('Reservado para los herederos de ... Acuerdo de la junta del ...')
                    ->helperText('Queda anotado con tu usuario y la fecha, en las observaciones del lote. Es lo único que después explica por qué este lote no está a la venta.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    app(RegistroDeReservas::class)->reservar($lote, $this->texto($data, 'motivo', ''));

                    return sprintf(
                        '%s quedó guardado para herencia y salió del mercado.',
                        (string) $lote->getAttribute('codigo'),
                    );
                });
            });
    }

    /**
     * Devolver a la venta un lote guardado.
     *
     * El mismo caso que corregir una donacion, y por eso la misma
     * respuesta: guardarlo no movio un lempira, asi que soltarlo tampoco
     * tiene nada que desarmar. Lo escrito no se borra —la anotacion nueva
     * se suma arriba— porque que un lote haya estado fuera del mercado es
     * justo lo que alguien va a querer entender despues.
     */
    public function deshacerReservaAction(): Action
    {
        return Action::make('deshacerReserva')
            ->label('Devolver a la venta')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->modalHeading('Devolver este lote a la venta')
            ->modalDescription('Deja de estar guardado para herencia y vuelve a estar DISPONIBLE. Como no llevaba plata, no hay nada que devolver.')
            ->modalSubmitActionLabel('Devolver a la venta')
            ->modalWidth('lg')
            ->schema([
                Textarea::make('motivo')
                    ->label('¿Por qué vuelve a la venta?')
                    ->required()
                    ->rows(3)
                    ->placeholder('La familia decidió no quedárselo; la junta aprobó ponerlo a la venta.')
                    ->helperText('Queda anotado con tu usuario y la fecha, arriba del motivo por el que se había guardado.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    app(RegistroDeReservas::class)->deshacerReserva($lote, $this->texto($data, 'motivo', ''));

                    return sprintf(
                        '%s ya no está guardado y volvió a estar disponible.',
                        (string) $lote->getAttribute('codigo'),
                    );
                });
            });
    }

    /**
     * Cobrar y abonar sin salir del plano.
     *
     * Las dos son `CobrarUnPago` tal cual —el mismo modal que la tabla de
     * Ventas y el expediente—, solo que la venta se resuelve desde el lote
     * que se tocó. Todo lo que se puede romper vive allá; acá solo se
     * enchufan, que es exactamente el punto: no hay una tercera pantalla
     * de cobro que mantener igual a las otras dos.
     */
    public function cobrarDesdeElPlanoAction(): Action
    {
        return CobrarUnPago::desdeElPlano();
    }

    /**
     * Del plano al expediente completo.
     *
     * Existe porque el panel del lote NO tiene que crecer hasta ser el
     * expediente: anular un recibo, imprimir un estado de cuenta o ver el
     * historial son pantallas enteras, y meterlas en un cuadrito flotante
     * arriba de un mapa las haría peores. Lo que el plano resuelve es lo
     * de todos los días —cobrar la cuota—; para lo demás, este salto.
     *
     * Va por Livewire y no por un enlace armado en Alpine: la ruta la sabe
     * Filament, y una URL escrita a mano en el blade es una que se rompe
     * callada el día que alguien mueve el recurso.
     */
    public function abrirExpediente(int $venta): void
    {
        $this->redirect(VentaResource::getUrl('view', ['record' => $venta]));
    }

    public function liberarLoteAction(): Action
    {
        return Action::make('liberarLote')
            ->label('Liberar lote')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalHeading('Liberar el apartado')
            ->modalDescription('El lote vuelve a quedar disponible. El apartado queda en el historial con su motivo.')
            ->modalSubmitActionLabel('Liberar')
            ->schema([
                Textarea::make('motivo')
                    ->label('¿Por que se libera?')
                    ->required()
                    ->rows(2)
                    ->placeholder('Se vencio el plazo, el cliente desistio, ...'),

                /*
                 * Los mismos campos que la tabla de apartados: si habia seña,
                 * hay que decir que se hizo con ella. Aca el `$record` no se
                 * inyecta —el plano trabaja con `$arguments`— asi que el
                 * apartado se resuelve a mano.
                 */
                ...DevolverLaSenia::campos(),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    $registro = app(RegistroDeCompromisos::class);
                    $devolucion = DevolverLaSenia::loTecleado($data, $registro->vigenteDe($lote));

                    $registro->liberar(
                        $lote,
                        $this->texto($data, 'motivo', 'Sin motivo'),
                        $devolucion['devuelto'],
                        $devolucion['forma'],
                        $devolucion['referencia'],
                    );

                    return $lote->getAttribute('codigo').' volvio a estar disponible.';
                });
            });
    }

    // ─── Ayudas de la venta desde el plano ────────────────────────────

    /**
     * El cuadro de plazos de un lote, con el motor que firma el contrato.
     *
     * 🔴 Lo llama Alpine con `$wire.cotizar(...)` cada vez que cambia la
     * prima, un precio o una tasa. Antes se calculaba en el navegador y por
     * eso mostraba L 54,166.67 donde el contrato decia L 57,751.71: dividia
     * el valor entre los meses sin mirar el interes. Ver el docblock de
     * `CotizacionDelLote` — ahi esta la historia entera.
     *
     * ⚠️ Es un metodo PUBLICO de Livewire, o sea que lo puede llamar el
     * navegador con el id de lote que se le ocurra. Por eso el lote se busca
     * acotado al proyecto de ESTA pantalla.
     *
     * @param array<string, mixed> $datos
     *
     * @return list<array<string, mixed>>
     */
    public function cotizar(array $datos): array
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        $lote = Lote::query()
            ->whereKey(is_numeric($datos['lote'] ?? null) ? (int) $datos['lote'] : 0)
            ->where('proyecto_id', $proyecto->getKey())
            ->first();

        if (! $lote instanceof Lote) {
            return [];
        }

        $areaVaras = (string) $lote->getAttribute('area_varas');

        /*
         * ═══ LO NEGOCIADO ES DEL RENGLON MARCADO ═══
         *
         * La prima y el descuento se escriben ADENTRO de la fila del plazo
         * que se esta armando, y por eso se aplican SOLO a esa fila. Las
         * otras tres quedan en lista: son con lo que se compara.
         *
         * Sin plazo marcado no hay nada negociado y las cuatro salen de
         * lista, que es lo correcto: todavia no se eligio la oferta.
         */
        $elegido = $this->plazoDe($datos['plazo'] ?? null);
        $descuento = $this->montoTecleado($datos['descuento'] ?? null) ?? Monto::cero();
        $prima = $this->montoTecleado($datos['prima'] ?? null) ?? Monto::cero();

        /*
         * ═══ DOS PUERTAS AL MISMO NUMERO ═══
         *
         * El precio del lote se puede escribir de dos maneras: cuanto se le
         * baja, o cuanto queda. Lo pidio Mauricio el 31-ago-2026 —«por si lo
         * quieren dejar en un numero cerrado»— y es como se negocia de
         * verdad: a veces se regatea la rebaja y a veces se cierra el total.
         *
         * `modo` dice cual de las dos casillas se tecleo de ultimo. No se
         * adivina mirando cual trae texto: las dos traen, porque la que no se
         * toca se rellena sola con lo que devolvio esta misma funcion.
         */
        $porValor = ($datos['modo'] ?? '') === 'valor';
        $valorMeta = $porValor ? $this->montoTecleado($datos['valor'] ?? null) : null;

        $planes = [];

        foreach ($this->planesVigentes() as $plan) {
            $meses = (int) $plan->getAttribute('meses');
            $suyo = $elegido !== null && $meses === $elegido;
            $precioLista = $plan->montoPrecioVara();
            $tasaLista = $plan->tasaDeInteres();

            $planes[] = [
                'meses'    => $meses,
                'etiqueta' => $plan->nombre(),
                'precio'   => match (true) {
                    ! $suyo                     => $precioLista,
                    $valorMeta instanceof Monto => $this->precioParaValor($valorMeta, $areaVaras),
                    default                     => $this->conDescuento($precioLista, $descuento, $areaVaras),
                },
                'precioLista' => $precioLista,
                // De contado no hay prima: se paga todo de una vez.
                'prima' => $suyo && $meses > 0 ? $prima : Monto::cero(),
                /*
                 * La tasa es la del plan y no se negocia desde acá. Se sacó
                 * el 31-ago-2026 a pedido de Mauricio junto con la columna
                 * del precio por vara²: el cuadro quedó en «cuánto vale,
                 * cuánto le bajo y cuánto de prima». El formulario de venta
                 * la sigue teniendo.
                 */
                'tasa'      => $tasaLista,
                'tasaLista' => $tasaLista,
            ];
        }

        return new CotizacionDelLote()->para(
            $areaVaras,
            $planes,
            CarbonImmutable::parse(today()->toDateString()),
            $this->configEntero('lotificadora.ventas.dia_pago_default', 5),
        );
    }

    /**
     * El plazo marcado, como entero.
     *
     * ⚠️ Livewire hidrata los numeros del navegador como float, no como int
     * —la misma trampa que documenta `comoTexto()` mas abajo—, asi que un
     * `is_int()` a secas devolveria null para el 24 que se acaba de marcar y
     * la fila saldria de lista sin que nadie se entere.
     */
    private function plazoDe(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (is_float($valor) && $valor === floor($valor)) {
            return (int) $valor;
        }

        return is_string($valor) && $valor !== '' && ctype_digit($valor) ? (int) $valor : null;
    }

    /**
     * El precio por vara² que queda después de un descuento EN LEMPIRAS.
     *
     * ═══ POR QUÉ ACÁ Y NO EN EL NAVEGADOR ═══
     *
     * Porque es una división de dinero, y el §8.3.1 no deja hacerla en
     * `float`. El sistema cobra por vara²: un descuento de L 37,130.60 sobre
     * 285.62 v² son L 130.000000 por vara, y sobre 285.63 no da redondo.
     *
     * ═══ POR QUÉ SE REDONDEA ACÁ, Y A SEIS ═══
     *
     * Porque este es el número que se FIRMA: viaja al formulario de venta y
     * el servidor recalcula el valor del lote a partir de él. Si acá quedara
     * la división con doce decimales, el cuadro mostraría un valor y el
     * contrato diría otro.
     *
     * 🔴 Y a SEIS y no a dos. `precio_vara` lleva seis decimales desde el
     * 11-ago-2026 —ver la migración `precio_vara_con_seis_decimales`— y la
     * razón es exactamente esta: la lotificadora no cobra por vara², cobra un
     * precio por LOTE redondeado a una cifra vendible, y con dos decimales
     * ese precio NO SE PUEDE ESCRIBIR. Un rato lo redondeé a dos y pedir
     * «300,000 cerrados» daba 299,999.31. Lo cachó Mauricio el 31-ago-2026.
     *
     * El dinero sigue con dos decimales; lo que gana precisión es el factor.
     */
    private function conDescuento(Monto $precioLista, Monto $descuento, string $areaVaras): Monto
    {
        if ($descuento->esCero() || ! is_numeric($areaVaras) || (float) $areaVaras <= 0.0) {
            return $precioLista;
        }

        $porVara = $descuento->dividirPor($areaVaras);

        /*
         * Un descuento más grande que el lote entero dejaría el precio en
         * negativo, y `Monto::restar()` lo rechaza con una excepción que
         * tumbaría el cuadro completo. Se topa en cero: quien atiende ve
         * «L 0.00» y entiende solo que se pasó.
         */
        if (! $porVara->menorQue($precioLista)) {
            return Monto::cero();
        }

        return new Monto($precioLista->restar($porVara)->redondeado(Lote::DECIMALES_DEL_PRECIO));
    }

    /**
     * El precio por vara² para que el lote valga un total dado.
     *
     * ⚠️ EL TOTAL PEDIDO CASI NUNCA SE ALCANZA EXACTO, y no es un defecto de
     * esta cuenta: `compromisos.precio_vara` es DECIMAL(14,2), asi que el
     * valor del lote solo puede caer en los multiplos de un centavo de vara.
     * En un lote de 839.04 v² eso es un escalon de L 8.39: pedir «un millon
     * redondo» da L 999,999.16, que es el punto de la escalera mas cercano.
     *
     * La pantalla muestra el valor ALCANZADO —el que se firma— apenas se sale
     * de la casilla, para que nadie le diga un numero al cliente y firme otro.
     */
    private function precioParaValor(Monto $valor, string $areaVaras): Monto
    {
        if (! is_numeric($areaVaras) || (float) $areaVaras <= 0.0) {
            return Monto::cero();
        }

        return new Monto($valor->dividirPor($areaVaras)->redondeado(Lote::DECIMALES_DEL_PRECIO));
    }

    /**
     * Un numero tecleado, como texto plano.
     *
     * ═══ 🔴 EL FLOAT NO SE PUEDE IGNORAR, POR MAS QUE EL §8.3.1 LO PROHIBA ═══
     *
     * Un `TextInput` con `->numeric()` hace que Livewire hidrate el campo como
     * NUMERO: lo que llega del formulario es un float, no la cadena que se
     * tecleo. La primera version de estos ayudantes solo aceptaba string o int
     * y devolvia null ante cualquier otra cosa.
     *
     * Null aca significa «no lo tocaron», asi que el efecto no fue un error:
     * fue que la tasa negociada al 6 % se descartaba y el compromiso se
     * congelaba con el 12 % del plan, sin una sola linea roja en pantalla. Lo
     * agarro un test; a un cliente le habria llegado un contrato con una tasa
     * que nadie le dijo.
     *
     * La conversion es con decimales fijos y NO con un cast: `(string) $float`
     * escribe notacion cientifica en los extremos y bcmath la rechaza. De aca
     * en adelante todo el camino vuelve a ser string.
     */
    private function comoTexto(mixed $valor, int $decimales): ?string
    {
        if (is_string($valor)) {
            /*
             * ═══ 🔴 LO QUE MANDA UNA CASILLA DE TEXTO, NO UNA NUMERICA ═══
             *
             * Desde el 31-ago-2026 el descuento y la prima se escriben con
             * separador de miles a la vista —«1,234,567»— y eso obligo a que
             * sean campos de texto: un `input` numerico no deja entrar la
             * coma. Con texto, lo que llega ya no es siempre un numero.
             *
             * Dos estados rompian de maneras distintas:
             *
             *  · «42,744» con coma: `is_numeric` dice que NO, asi que volvia
             *    null y null significa «no lo tocaron». El descuento se
             *    descartaba EN SILENCIO y el lote se cotizaba a precio de
             *    lista con quien atiende creyendo que lo habia rebajado.
             *
             *  · «42744.» con el punto puesto y el decimal todavia no —un
             *    estado legitimo a mitad de una tecla—: `is_numeric` dice que
             *    SI y `Monto` dice que no, asi que llegaba a `new Monto()` y
             *    tumbaba el panel con un 500 mientras la persona escribia.
             *
             * Se limpian los dos acá, en la puerta. La coma en un campo de
             * dinero es separador de miles y no significa otra cosa; el punto
             * colgando no es un decimal, es una tecla a medio camino.
             */
            $texto = rtrim(str_replace(',', '', trim($valor)), '.');

            return $texto === '' || ! is_numeric($texto) ? null : $texto;
        }

        if (is_int($valor)) {
            return (string) $valor;
        }

        return is_float($valor) && is_finite($valor)
            ? number_format($valor, $decimales, '.', '')
            : null;
    }

    /**
     * Lo que se tecleo en una casilla de dinero, o null si no sirve.
     *
     * Null significa «no lo tocaron»: quien llama pone el de lista. Por eso
     * NO se devuelve cero ante un texto invalido — cero es un precio, y de
     * los que piden motivo escrito.
     */
    private function montoTecleado(mixed $valor): ?Monto
    {
        // Dos, que es la escala de toda columna de dinero del sistema.
        // `Monto::DECIMALES` es privada y no vale la pena abrirla por esto.
        $texto = $this->comoTexto($valor, 2);

        return $texto === null || (float) $texto < 0 ? null : new Monto($texto);
    }

    /**
     * Lo mismo para una tasa. Null es «la del plan», y por eso un cero
     * escrito a mano SI vale: es una venta sin interes, que es una decision.
     */
    private function tasaTecleada(mixed $valor): ?TasaDeInteres
    {
        $texto = $this->comoTexto($valor, TasaDeInteres::DECIMALES);

        if ($texto === null) {
            return null;
        }

        $numero = (float) $texto;

        // Fuera de rango se ignora: el CHECK de la base lo rechazaria igual,
        // y aca la consecuencia seria un cuadro en blanco sin explicacion.
        return $numero < 0 || $numero > (float) TasaDeInteres::MAXIMA
            ? null
            : new TasaDeInteres($texto);
    }

    /**
     * Con que llega precargado el formulario al abrirse.
     *
     * Se propone el plan MAS CORTO que ofrezca el proyecto: es el que menos
     * compromete al cliente. Si no hay ninguno, manda el precio propio del
     * lote, que es lo que habia antes de que existiera la lista por plazo.
     *
     *
     * @return array<string, mixed>
     */
    private function datosInicialesDeVenta(array $arguments): array
    {
        $lote = Lote::query()->find($this->entero($arguments, 'lote', 0));

        /*
         * Lo que se cotizo en el modal del lote MANDA. El vendedor ya marco
         * el plazo, quizas toco el precio y quizas escribio la prima: llegar
         * al formulario con todo eso en blanco seria pedirselo dos veces.
         *
         * Nada de esto se cree a ciegas: el Service revalida el precio
         * contra la lista del plazo y exige motivo si baja (R4).
         */
        $plazo = $this->entero($arguments, 'plazo', -1);
        $plan = $plazo >= 0 ? $this->planDelPlazo($plazo) : $this->primerPlan();
        $propio = $lote?->getAttribute('precio_vara');
        $cotizado = $this->texto($arguments, 'precio', '');

        $plazoFinal = match (true) {
            $plazo >= 0                 => $plazo,
            $plan instanceof PlanDePago => (int) $plan->getAttribute('meses'),
            default                     => $this->configEntero('lotificadora.ventas.plazo_meses_default', 60),
        };

        $precioFinal = match (true) {
            $cotizado !== ''            => $cotizado,
            $plan instanceof PlanDePago => $plan->montoPrecioVara()->redondeado(),
            default                     => is_string($propio) || is_int($propio) ? (string) $propio : '0',
        };

        $extra = $this->idsExtra($arguments);
        $condiciones = $this->condicionesDe($arguments['condiciones'] ?? null);

        return [
            /*
             * Una linea por lote, con SU plazo. Van como JSON en un `Hidden`:
             * un arreglo adentro de un <input> se convierte en «Array».
             *
             * Vacio es el proyecto sin planes de pago cargados: ahi no hubo
             * plazo que elegir y el formulario pregunta los tres campos, para
             * un solo lote.
             */
            'condiciones' => $condiciones === []
                ? null
                : json_encode($condiciones, JSON_THROW_ON_ERROR),

            'plazo_meses' => $plazoFinal,
            'precio_vara' => $precioFinal,

            /*
             * La tasa que se cotizo en el modal, o la del plan de ese plazo.
             * Llegar al formulario con esto en blanco seria pedirlo dos veces
             * — y peor: guardarlo en cero sin que nadie lo haya decidido.
             */
            'tasa_interes_anual' => $this->texto($arguments, 'tasa', '') !== ''
                ? $this->texto($arguments, 'tasa', '')
                : $this->tasaDelPlazo($plazoFinal)->redondeada(),
            'prima'    => $this->primaInicial($arguments, $lote, $extra, $plazoFinal, $precioFinal),
            'dia_pago' => $this->configEntero('lotificadora.ventas.dia_pago_default', 5),

            /*
             * Al estado, no a $arguments: los closures de un componente del
             * schema no reciben $arguments —Filament revienta al evaluarlos—
             * pero el estado del formulario si llega a todos lados.
             */
            'lote_id'        => $lote?->getKey(),
            'cotizado'       => $condiciones !== [],
            'fecha_contrato' => today()->toDateString(),

            // Mismo motivo que en el apartado: es como llega la prima en el
            // mostrador, y un Select requerido sin default obliga a un clic
            // mas en el tramite mas largo del sistema.
            'forma_pago_prima' => FormaDePago::Efectivo->value,

            // Igual que el de arriba: `fillForm()` es el estado inicial, así
            // que el valor va acá y no en un `default()` del campo.
            'recibido_por' => QuienRecibeElDinero::porDefecto(),
        ];
    }

    /**
     * Con que prima llega el formulario.
     *
     * Lo que se escribio en el modal manda. Pero vacio y DE CONTADO, la
     * prima ES el valor: de contado no queda nada que financiar, asi que
     * llegar con la prima en blanco es llegar a un formulario que no se
     * puede guardar —y que ademas explica mal por que, porque el motor
     * culpa al plazo—. Editable igual: si el cliente da menos, se cambia y
     * se elige un plazo.
     *
     * @param array<string, mixed> $arguments
     * @param list<int> $extra
     */
    private function primaInicial(array $arguments, ?Lote $lote, array $extra, int $plazo, string $precio): ?string
    {
        $escrita = $this->texto($arguments, 'prima', '');

        /*
         * 🔴 El orden importa, y estaba al reves. Al contado el valor MANDA
         * sobre lo tecleado: el campo del modal del lote nace en «0.00», que
         * es «algo escrito» para cualquier comparacion contra vacio, y ese
         * 0.00 ganaba. Resultado: saldo distinto de cero con plazo 0, y la
         * venta de contado no se podia firmar. Ver `primaDelRenglon()`.
         */
        if ($plazo !== 0 || ! $lote instanceof Lote) {
            return $escrita !== '' ? $escrita : null;
        }

        $precioVara = $this->monto($precio);
        $total = Monto::cero();

        foreach ($this->lotesDelContrato((int) $lote->getKey(), $extra) as $uno) {
            $total = $total->sumar(new Monto($precioVara->multiplicarPor($this->areaDe($uno))->redondeado()));
        }

        return $total->esCero() ? null : $total->redondeado();
    }

    /**
     * Con que llega precargado el apartado.
     *
     * Si el vendedor escribio monto o vencimiento en el modal del lote,
     * manda eso. Vacio son los numeros de R14, que es lo normal: los tres
     * los fijo la contratante y no se negocian por venta.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function datosInicialesDeApartado(array $arguments): array
    {
        $senia = $this->texto($arguments, 'senia', '');
        $vence = $this->texto($arguments, 'vence', '');

        return [
            'monto_senia' => $senia !== ''
                ? $senia
                : $this->configTexto('lotificadora.apartados.monto', '0.00'),
            'vence_el' => $vence !== ''
                ? $vence
                : today()
                    ->addDays($this->configEntero('lotificadora.apartados.dias_de_vigencia', 15))
                    ->toDateString(),

            /*
             * Efectivo por defecto porque es como llega el 95% de las señas
             * —el cliente esta parado enfrente con los billetes—, y porque un
             * Select requerido sin default obliga a un clic mas en el tramite
             * mas apurado del mostrador. Quien deposito lo cambia.
             */
            'forma_pago' => FormaDePago::Efectivo->value,

            /*
             * ⚠️ Acá y NO en un `->default()` del campo: `fillForm()` ES el
             * estado inicial y los `default()` no se aplican. Mismo motivo que
             * en el modal de cobro, donde ya costó verlo en pantalla.
             */
            'recibido_por' => QuienRecibeElDinero::porDefecto(),

            // Al estado y no a $arguments: los closures de un componente del
            // schema no reciben $arguments. Mismo motivo que en la venta.
            'lote_id'     => $this->entero($arguments, 'lote', 0) ?: null,
            'lotes_extra' => $this->idsExtra($arguments),
        ];
    }

    /**
     * ¿La cotizacion vino del modal del lote?
     *
     * Si vino, el formulario no vuelve a preguntar plazo, precio ni prima:
     * los muestra en el resumen. Si no vino —proyecto sin planes cargados—
     * los pide, porque si no la venta no se puede armar.
     *
     * @param array<string, mixed> $arguments
     */
    private function vinoCotizado(array $arguments): bool
    {
        return $this->condicionesDe($arguments['condiciones'] ?? null) !== [];
    }

    /**
     * Un renglon del cuadro del apartado.
     *
     * La seña es POR LOTE, no por apartado: son N compromisos, cada uno con
     * el suyo. Decir «L 5,000.00» a secas cuando son tres lotes seria
     * cotizarle mal al cliente que esta enfrente, y por eso «Seña por lote»
     * y «Se cobra hoy» son dos numeros distintos y no uno.
     */
    private function cuentaDelApartado(Get $get, string $renglon): string
    {
        $lotes = $this->lotesEnPantalla($get);
        $monto = $this->monto($get('monto_senia'));
        $vence = $get('vence_el');

        return match ($renglon) {
            'lotes' => $lotes === []
                ? '—'
                : sprintf(
                    '%s (%s)',
                    $this->codigosDe($lotes),
                    count($lotes) === 1 ? 'un lote' : sprintf('%d lotes', count($lotes)),
                ),
            'senia' => $monto->formateado(),
            'total' => count($lotes) < 2
                ? $monto->formateado()
                : new Monto($monto->multiplicarPor((string) count($lotes))->redondeado())->formateado(),
            'vence' => $this->fechaDe(is_string($vence) ? $vence : null)->format('d/m/Y'),
            default => '—',
        };
    }

    /**
     * ¿Hay plata que cobrar hoy?
     *
     * Con seña en L 0.00 no se emite recibo —el CHECK de `recibos` no admite
     * monto cero— y entonces preguntar la forma de pago seria pedirle a quien
     * atiende un dato de un movimiento que no existe.
     */
    private function hayQueCobrarSenia(Get $get): bool
    {
        return ! $this->monto($get('monto_senia'))->esCero();
    }

    private function exigeReferencia(Get $get): bool
    {
        $forma = $get('forma_pago');

        return is_string($forma)
            && FormaDePago::tryFrom($forma)?->exigeReferencia() === true;
    }

    private function exigeReferenciaDeLaPrima(Get $get): bool
    {
        $forma = $get('forma_pago_prima');

        return is_string($forma)
            && FormaDePago::tryFrom($forma)?->exigeReferencia() === true;
    }

    /**
     * Las tres de R11. Cheque no esta, y no se agrega «por si acaso».
     *
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
     * Un renglon del cuadro «Lo que se va a firmar».
     */
    private function cuenta(Get $get, string $renglon): string
    {
        $cuentas = $this->cuentasDeLaVenta($get);

        return is_string($cuentas[$renglon] ?? null) ? $cuentas[$renglon] : '';
    }

    /**
     * Las condiciones de cada lote, leidas de donde esten.
     *
     * Viajan como JSON en un `Hidden`: un arreglo adentro de un <input> se
     * convierte en la palabra «Array».
     *
     * Vacio significa que el plano no cotizo —proyecto sin planes de pago
     * cargados—, y ahi manda lo que se teclea en el formulario, para un solo
     * lote. Es el unico camino que queda sin plazos por lote, y a proposito:
     * sin lista de precios por plazo no hay plazos que elegir.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{lote: int, plazo: int, precio: string, prima: string, tasa: string|null}>
     */
    private function condicionesDelFormulario(array $data, ?Lote $lote = null): array
    {
        $condiciones = $this->condicionesDe($data['condiciones'] ?? null);

        if ($condiciones !== [] || ! $lote instanceof Lote) {
            return $condiciones;
        }

        return [[
            'lote'   => (int) $lote->getKey(),
            'plazo'  => $this->entero($data, 'plazo_meses', 0),
            'precio' => $this->texto($data, 'precio_vara', '0'),
            'prima'  => $this->texto($data, 'prima', '0'),
            'tasa'   => $this->tasaCruda($data['tasa_interes_anual'] ?? null),
        ]];
    }

    /**
     * 🔴 AL CONTADO LA PRIMA **ES** EL VALOR, teclee lo que teclee.
     *
     * ═══ EL BUG QUE ESTO CIERRA — 14-ago-2026 ═══
     *
     * Marcar «Al Contado» y firmar tiraba *«El plazo de 0 meses no es valido:
     * tiene que ser de 1 a 600 meses»*, y una venta de contado no se podia
     * hacer por ninguna pantalla del sistema.
     *
     * El dominio nunca estuvo roto: `PlanDeCuotas::nuevo()` devuelve un plan
     * VACIO cuando el saldo da cero. Lo que pasaba es que la pantalla mandaba
     * la prima en **L 0.00** —el valor con que nace el campo del modal del
     * lote—, asi que el saldo no daba cero, el motor se iba por el camino
     * financiado y ahi el plazo 0 no existe. El mensaje culpaba al plazo
     * cuando el problema era la prima: **peor que reventar**.
     *
     * `primaInicial()` ya intentaba resolverlo precargando el valor, pero
     * miraba si el vendedor «habia escrito algo» — y `0.00` es algo.
     *
     * Por eso no se precarga: se IMPONE. Al contado, cuanto se paga no es
     * una negociacion, es una definicion —el valor entero, el dia que se
     * firma (R5)— y dejarlo tecleable solo abre la puerta a firmar una venta
     * de contado que en realidad no lo es.
     *
     * ⚠️ Vive acá y lo llaman los DOS caminos —la previsualizacion y la
     * firma—, porque el dia que digan cosas distintas el cliente firma una y
     * el sistema guarda la otra.
     */
    private function primaDelRenglon(int $plazo, Monto $valor, Monto $tecleada): Monto
    {
        return $plazo === 0 ? $valor : $tecleada;
    }

    /**
     * ¿Todos los lotes de este contrato van al contado?
     *
     * Con uno solo financiado hay cuotas, y donde hay cuotas hace falta el
     * dia de pago y una prima de verdad. La pregunta es por TODOS y no por
     * «el primero» a proposito: un contrato puede llevar un lote de contado
     * y otro a 48 meses.
     */
    private function soloContado(Get $get): bool
    {
        $renglones = $this->renglonesEnPantalla($get);

        if ($renglones === []) {
            return false;
        }

        return array_all($renglones, fn ($renglon): bool => $renglon['plazo'] === 0);
    }

    /**
     * @return list<array{lote: int, plazo: int, precio: string, prima: string, tasa: string|null}>
     */
    private function condicionesDe(mixed $crudo): array
    {
        $lista = is_string($crudo) ? json_decode($crudo, true) : $crudo;

        if (! is_array($lista)) {
            return [];
        }

        $condiciones = [];
        $vistos = [];

        foreach ($lista as $fila) {
            /*
             * Sin plazo NO hay condicion. El plano manda `plazo: null` cuando
             * el proyecto no tiene planes de pago cargados y no hubo nada que
             * elegir; tomarlo como 0 seria grabar la venta como si fuera de
             * contado porque falta una configuracion.
             */
            if (! is_array($fila)) {
                continue;
            }

            if (! is_numeric($fila['lote'] ?? null)) {
                continue;
            }

            if (! is_numeric($fila['plazo'] ?? null)) {
                continue;
            }

            $id = (int) $fila['lote'];

            if (isset($vistos[$id])) {
                continue;
            }

            $vistos[$id] = true;

            $condiciones[] = [
                'lote'   => $id,
                'plazo'  => is_numeric($fila['plazo'] ?? null) ? (int) $fila['plazo'] : 0,
                'precio' => $this->decimalDe($fila['precio'] ?? null),
                'prima'  => $this->decimalDe($fila['prima'] ?? null),
                /*
                 * ⚠️ Null y NO '0' cuando no viene. `decimalDe()` devuelve
                 * cero ante lo que sea, y un cero en la tasa no es un vacio:
                 * es una venta sin interes, que baja de la de lista y pide
                 * motivo escrito. El vacio tiene que seguir significando
                 * «la del plan».
                 */
                'tasa' => $this->tasaCruda($fila['tasa'] ?? null),
            ];
        }

        return $condiciones;
    }

    private function decimalDe(mixed $valor): string
    {
        return is_string($valor) || is_int($valor) ? (string) $valor : '0';
    }

    /**
     * La tasa tal como vino, o null si no vino. Ver el comentario de arriba.
     */
    private function tasaCruda(mixed $valor): ?string
    {
        return $this->comoTexto($valor, TasaDeInteres::DECIMALES);
    }

    /**
     * La tasa que ofrece el plan de ese plazo hoy. Cero si no hay plan
     * cargado, que es lo que hacia el sistema antes de que el interes
     * existiera (R1, R2).
     */
    private function tasaDelPlazo(int $meses): TasaDeInteres
    {
        $plan = $this->planDelPlazo($meses);

        return $plan instanceof PlanDePago ? $plan->tasaDeInteres() : TasaDeInteres::cero();
    }

    /**
     * ¿La tasa tecleada baja de la que ofrece el plan de alguno de los lotes?
     *
     * Es R4 aplicado al precio del dinero: alcanza con que uno baje para
     * tener que escribir el motivo. El Service vuelve a mirarlos uno por uno
     * y dice cual falla.
     */
    private function hayRebajaDeTasa(Get $get): bool
    {
        /*
         * ⚠️ Se mira el CUADRO COTIZADO cuando lo hay, y no el campo del
         * formulario. Con un contrato cotizado desde el plano ese campo esta
         * escondido y cada lote lleva SU tasa: preguntarle al campo diria que
         * no hubo rebaja, el motivo no se pediria, y el Service reventaria
         * con un mensaje sobre un lote que la pantalla ni menciona.
         */
        $condiciones = $this->condicionesDe($get('condiciones'));

        if ($condiciones !== []) {
            return array_any(
                $condiciones,
                fn (array $renglon): bool => ($this->tasaTecleada($renglon['tasa']) ?? $this->tasaDelPlazo($renglon['plazo']))
                    ->menorQue($this->tasaDelPlazo($renglon['plazo'])),
            );
        }

        $tecleada = $this->tasaTecleada($get('tasa_interes_anual'));
        $plazo = $get('plazo_meses');

        return $tecleada instanceof TasaDeInteres
            && $tecleada->menorQue($this->tasaDelPlazo(is_numeric($plazo) ? (int) $plazo : 0));
    }

    /**
     * Un renglon del contrato, ya calculado: valor, prima y su plan propio.
     *
     * @return list<array{lote: Lote, codigo: string, area: string, plazo: int, precio: Monto, prima: Monto, valor: Monto, plan: PlanDeCuotas|null, error: string}>
     */
    private function renglonesEnPantalla(Get $get): array
    {
        $condiciones = $this->condicionesDelFormulario(
            [
                'condiciones' => $get('condiciones'),
                'plazo_meses' => $get('plazo_meses'),
                'precio_vara' => $get('precio_vara'),
                'prima'       => $get('prima'),
            ],
            Lote::query()->find($this->entero(['id' => $get('lote_id')], 'id', 0)),
        );

        $ids = array_column($condiciones, 'lote');
        $lotes = [];

        foreach ($this->lotesDelContrato($ids[0] ?? 0, $ids) as $lote) {
            $lotes[(int) $lote->getKey()] = $lote;
        }

        $diaPago = (int) $get('dia_pago');
        $fecha = $this->fechaDe($get('fecha_contrato'));
        $renglones = [];

        foreach ($condiciones as $condicion) {
            $lote = $lotes[$condicion['lote']] ?? null;

            if (! $lote instanceof Lote) {
                continue;
            }

            $precio = $this->monto($condicion['precio']);
            $area = $this->areaDe($lote);
            $valor = new Monto($precio->multiplicarPor($area)->redondeado());
            $prima = $this->primaDelRenglon(
                $condicion['plazo'],
                $valor,
                $this->monto($condicion['prima']),
            );
            $plan = null;
            $error = '';

            try {
                /*
                 * ═══ 🔴 LA TASA. NO SE PUEDE OMITIR ═══
                 *
                 * `PlanDeCuotas::nuevo()` la recibe de ultima y por defecto es
                 * null, que significa SIN INTERES. Omitirla acá hacía que este
                 * cuadro —«Lo que se va a firmar»— dividiera el saldo entre los
                 * meses y mostrara esa cuota.
                 *
                 * Con los planes al 0 % nadie lo vio. El 31-ago-2026 Mauricio
                 * puso los cuatro planes de Altamira al 12 % y el error salio a
                 * la luz en la misma pantalla: el plano cotizaba L 21,323.71 y
                 * dos clics despues el cuadro de la firma decia L 20,000.00
                 * —240,000 entre 12, sin un centavo de interes—.
                 *
                 * La venta de verdad nunca estuvo mal: `PrecioPactado` manda la
                 * tasa en null y `RegistroDeVentas` la resuelve como la del plan
                 * (ver su linea 661). O sea que el contrato SI salia con el
                 * 12 %: lo que mentia era el numero que el vendedor le lee al
                 * cliente antes de firmar. Es el mismo pecado que documenta
                 * `CotizacionDelLote` y por el que ese cuadro se calcula en el
                 * servidor.
                 *
                 * `null` acá significa «la que ofrece el plan de ese plazo», y
                 * se resuelve con la MISMA regla que el Service para que los dos
                 * numeros no puedan separarse nunca mas.
                 */
                $plan = PlanDeCuotas::nuevo(
                    $valor,
                    $prima,
                    $condicion['plazo'],
                    $diaPago,
                    $fecha,
                    $this->tasaTecleada($condicion['tasa'] ?? null) ?? $this->tasaDelPlazo($condicion['plazo']),
                );
            } catch (GrupoOlympoException $problema) {
                // El mensaje del dominio ya esta escrito para quien atiende;
                // lo que falta es de que lote esta hablando.
                $error = $problema->getMessage();
            }

            $renglones[] = [
                'lote'   => $lote,
                'codigo' => (string) $lote->getAttribute('codigo'),
                'area'   => $area,
                'plazo'  => $condicion['plazo'],
                'precio' => $precio,
                'prima'  => $prima,
                'valor'  => $valor,
                'plan'   => $plan,
                'error'  => $error,
            ];
        }

        return $renglones;
    }

    /**
     * Valor, prima y saldo. Y, si algo no cierra, por que y de que lote.
     *
     * @return array{valor: string, prima: string, saldo: string, aviso: string}
     */
    private function cuentasDeLaVenta(Get $get): array
    {
        $renglones = $this->renglonesEnPantalla($get);

        if ($renglones === []) {
            return ['valor' => '—', 'prima' => '—', 'saldo' => '—', 'aviso' => 'No se encontro el lote.'];
        }

        $valor = Monto::cero();
        $prima = Monto::cero();
        $aviso = '';

        /*
         * La suma se hace RENGLON POR RENGLON —cada lote redondeado a dos
         * decimales y recien ahi sumado—, no como area total x precio. Es la
         * misma cuenta que hace RegistroDeVentas::congelarPrecios() y la que
         * exige el CHECK `valor = ROUND(area_varas * precio_vara, 2)`.
         */
        foreach ($renglones as $renglon) {
            $valor = $valor->sumar($renglon['valor']);
            $prima = $prima->sumar($renglon['prima']);

            if ($aviso === '' && $renglon['error'] !== '') {
                $aviso = sprintf('En el lote %s: %s', $renglon['codigo'], $renglon['error']);
            }
        }

        return [
            'valor' => $valor->formateado(),
            'prima' => $prima->formateado(),
            'saldo' => $prima->mayorQue($valor) ? '—' : $valor->restar($prima)->formateado(),
            'aviso' => $aviso,
        ];
    }

    /**
     * La tabla de lotes del contrato que se esta armando.
     *
     * El armado del HTML vive en Cuadros, compartido con la ficha del
     * expediente: son el mismo cuadro contando dos momentos —antes y despues
     * de firmar— y con una copia cada uno, algun dia uno diria 48 cuotas y el
     * otro 47.
     */
    private function tablaDeLotes(Get $get): HtmlString
    {
        return Cuadros::lotes(
            array_map(
                static fn (array $renglon): array => [
                    'codigo' => $renglon['codigo'],
                    'area'   => $renglon['area'],
                    'plazo'  => $renglon['plazo'],
                    'precio' => $renglon['precio'],
                    'valor'  => $renglon['valor'],
                    'prima'  => $renglon['prima'],
                    'cuota'  => $renglon['plan']?->cuotaMensual(),
                ],
                $this->renglonesEnPantalla($get),
            ),
            unidad: $this->unidad(),
        );
    }

    /**
     * La escalera de cuotas de lo que se esta cotizando.
     */
    private function escaleraDeCuotas(Get $get): HtmlString
    {
        $planes = [];

        foreach ($this->renglonesEnPantalla($get) as $renglon) {
            if ($renglon['plan'] instanceof PlanDeCuotas) {
                $planes[] = ['etiqueta' => $renglon['codigo'], 'plan' => $renglon['plan']];
            }
        }

        return Cuadros::escalera(new PlanDelContrato($planes)->tramos());
    }

    /**
     * ¿El precio tecleado va por debajo del de lista PARA ESE PLAZO? (R4)
     */
    private function hayDescuento(Get $get): bool
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        $lista = app(ListaDePrecios::class);

        /*
         * Cada lote se mide contra la lista DE SU PROPIO PLAZO: con el primero
         * a 12 meses y el tercero a 48, el precio de lista de los dos no es el
         * mismo. Alcanza con que uno baje para tener que escribir el motivo
         * (R4). El Service vuelve a mirarlos uno por uno y dice cual falla.
         */
        return array_any(
            $this->renglonesEnPantalla($get),
            fn (array $renglon): bool => $renglon['precio']
                ->menorQue($lista->deListaPara($proyecto, $renglon['lote'], $renglon['plazo'])),
        );
    }

    /**
     * Los lotes que el plano marco ademas del que se abrio.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<int>
     */
    private function idsExtra(array $arguments): array
    {
        $extra = $arguments['extra'] ?? null;

        if (! is_array($extra)) {
            return [];
        }

        /*
         * El lote abierto se SACA de la lista de otros lotes.
         *
         * No es cosmetica. `Select::getInValidationRuleValues()` devuelve un
         * conjunto VACIO —o sea: nada permitido— cuando hay mas valores
         * elegidos que etiquetas que sepa resolver (Select.php:1763). El del
         * plano no esta entre las opciones de este selector, asi que dejarlo
         * en el estado tumbaba el formulario entero, y con un mensaje que ni
         * siquiera decia cual de los lotes era el problema.
         *
         * El duplicado igual se vuelve a filtrar en lotesDelContrato(): esto
         * lo saca de la pantalla, aquello lo saca de la venta.
         */
        $abierto = $this->entero($arguments, 'lote', 0);

        return array_values(array_filter(
            $this->idsElegidos($extra),
            static fn (int $id): bool => $id !== $abierto,
        ));
    }

    /**
     * Un puñado de ids que vino de la pantalla, limpio.
     *
     * @return list<int>
     */
    private function idsElegidos(mixed $valores): array
    {
        if (! is_array($valores)) {
            return [];
        }

        $ids = [];

        foreach ($valores as $valor) {
            if (is_numeric($valor)) {
                $ids[] = (int) $valor;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Los lotes que se estan cotizando: el del plano, mas los agregados.
     *
     * @return list<Lote>
     */
    private function lotesEnPantalla(Get $get): array
    {
        return $this->lotesDelContrato(
            $this->entero(['id' => $get('lote_id')], 'id', 0),
            $get('lotes_extra'),
        );
    }

    /**
     * El lote del plano encabeza; despues los agregados, sin repetidos.
     *
     * El orden importa: es el que va a salir impreso en el contrato. Y el
     * dedup no es cortesia —RegistroDeVentas rechaza el mismo lote dos
     * veces en una venta— sino evitar que un clic de mas termine en un
     * mensaje de error por algo que la pantalla podia resolver sola.
     *
     * @return list<Lote>
     */
    private function lotesDelContrato(int $principal, mixed $extras): array
    {
        $ids = [$principal];

        if (is_array($extras)) {
            foreach ($extras as $extra) {
                if (is_numeric($extra)) {
                    $ids[] = (int) $extra;
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        $clave = implode(',', $ids);

        /*
         * El cuadro del resumen son cinco Placeholders y los cinco preguntan
         * lo mismo. Sin esta memoria, abrir el formulario con tres lotes
         * marcados son quince consultas para pintar cuatro numeros.
         */
        if (isset($this->lotesLeidos[$clave])) {
            return $this->lotesLeidos[$clave];
        }

        $encontrados = [];

        foreach (Lote::query()->whereIn('id', $ids)->get() as $lote) {
            $encontrados[(int) $lote->getKey()] = $lote;
        }

        $lotes = [];

        foreach ($ids as $id) {
            if (isset($encontrados[$id])) {
                $lotes[] = $encontrados[$id];
            }
        }

        return $this->lotesLeidos[$clave] = $lotes;
    }

    /**
     * Los otros lotes del proyecto que se pueden sumar a este movimiento.
     *
     * Para vender se listan los disponibles Y los apartados: un apartado a
     * nombre del mismo titular se convierte y su seña cuenta como parte de
     * la prima (R14); a nombre de otra persona lo rechaza el Service
     * diciendo de quien es. Para apartar, solo los disponibles.
     *
     * ═══ LA LISTA SIEMPRE INCLUYE LO QUE YA ESTA ELEGIDO ═══
     *
     * Aunque hoy no califique. No es cortesia:
     * `Select::getInValidationRuleValues()` devuelve un conjunto VACIO
     * —nada permitido— cuando hay mas valores elegidos que etiquetas que
     * sepa resolver (Select.php:1763). O sea que un lote que otro vendedor
     * aparto mientras este armaba la pantalla no daria «ese lote ya no esta
     * disponible» sino un formulario invalido entero, sin decir cual de
     * todos. Se listan con su estado a la vista, y el Service es el que
     * explica que paso.
     *
     * @return array<int, string>
     */
    private function otrosLotesVendibles(Get $get, bool $soloDisponibles = false): array
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        $estados = $soloDisponibles
            ? [EstadoLote::Disponible->value]
            : [EstadoLote::Disponible->value, EstadoLote::Apartado->value];

        $yaElegidos = $this->idsElegidos($get('lotes_extra'));

        $lotes = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where(static function (Builder $consulta) use ($estados, $yaElegidos): void {
                $consulta->whereIn('estado', $estados);

                if ($yaElegidos !== []) {
                    $consulta->orWhereIn('id', $yaElegidos);
                }
            })
            ->whereKeyNot($this->entero(['id' => $get('lote_id')], 'id', 0))
            ->orderBy('codigo')
            ->get();

        $opciones = [];

        foreach ($lotes as $lote) {
            $estado = $lote->getAttribute('estado');

            $opciones[(int) $lote->getKey()] = sprintf(
                '%s — %s '.$this->unidad()->abreviada().'%s',
                (string) $lote->getAttribute('codigo'),
                Cuadros::conMiles(new Monto($this->areaDe($lote))->redondeado()),
                $estado instanceof EstadoLote && $estado !== EstadoLote::Disponible
                    ? sprintf(' (%s)', mb_strtolower($estado->etiquetaInterna()))
                    : '',
            );
        }

        return $opciones;
    }

    /**
     * Los clientes que pueden firmar ADEMAS del titular.
     *
     * El titular NO se ofrece. El indice unico (venta_id, cliente_id) del
     * pivot lo rechazaria de todos modos, y ofrecer algo que se va a rechazar
     * es una trampa: quien atiende no tiene por que saber que esa opcion, que
     * ve ahi, no se puede elegir.
     *
     * El Service lo filtra igual: la pantalla puede quedar desincronizada
     * —elegir copropietario y despues volverlo titular— y ahi el que manda
     * es el de atras.
     *
     * @return array<int, string>
     */
    private function clientesMenosElTitular(Get $get): array
    {
        $titular = $this->entero(['id' => $get('cliente_id')], 'id', 0);
        $elegidos = $this->idsElegidos($get('copropietarios'));
        $opciones = [];

        foreach (self::clientesDisponibles() as $id => $nombre) {
            /*
             * El titular no se OFRECE, pero si YA estaba elegido se sigue
             * listando. Pasa de verdad: se marca a alguien como copropietario
             * y despues se lo pone de titular.
             *
             * Sacarlo de las opciones con el estado todavia apuntandolo no
             * daria «ese no puede ser copropietario» sino el formulario
             * INVALIDO ENTERO, sin nombrar a nadie: Filament devuelve un
             * conjunto vacio de valores permitidos cuando hay mas elegidos que
             * etiquetas que sepa resolver. Se lista, se ve, se puede quitar —
             * y si no se quita, el Service lo filtra igual.
             */
            if ($id !== $titular || in_array($id, $elegidos, true)) {
                $opciones[$id] = $nombre;
            }
        }

        return $opciones;
    }

    /**
     * El titular primero; despues quienes firman con el, sin repetirlo.
     *
     * Si alguien elige a la misma persona como titular y como
     * copropietaria, el indice unico (venta_id, cliente_id) del pivot la
     * rechazaria con un error de base que no le dice nada a nadie. Se
     * filtra aca, que es donde todavia se puede explicar.
     *
     * @param array<string, mixed> $data
     *
     * @return list<Cliente>
     */
    private function clientesDeLaVenta(Cliente $titular, array $data): array
    {
        $otros = [];
        $copropietarios = $data['copropietarios'] ?? null;

        if (is_array($copropietarios)) {
            foreach ($copropietarios as $id) {
                if (is_numeric($id) && (int) $id !== (int) $titular->getKey()) {
                    $otros[] = (int) $id;
                }
            }
        }

        /** @var list<Cliente> $acompanantes */
        $acompanantes = $otros === []
            ? []
            : Cliente::query()->whereIn('id', array_unique($otros))->get()->all();

        return [$titular, ...$acompanantes];
    }

    /**
     * Los codigos de los lotes, como se leen en una notificacion.
     *
     * @param list<Lote> $lotes
     */
    private function codigosDe(array $lotes): string
    {
        return implode(', ', array_map(
            static fn (Lote $lote): string => (string) $lote->getAttribute('codigo'),
            $lotes,
        ));
    }

    /**
     * El area de un lote como decimal en string. Nunca float (§8.3.1).
     */
    private function areaDe(Lote $lote): string
    {
        $area = $lote->getAttribute('area_varas');

        return is_string($area) || is_int($area) ? (string) $area : '0';
    }

    /**
     * A donde ir cuando el movimiento termina, si no es de vuelta al plano.
     *
     * ═══ POR QUE UNA PROPIEDAD Y NO UN PARAMETRO ═══
     *
     * `conElLote()` envuelve TODOS los movimientos del plano —apartar, donar,
     * liberar, guardar para herencia— y los cinco terminan igual: de vuelta al
     * plano, que es donde quien atiende sigue trabajando.
     *
     * La venta es el unico que no. Ensanchar la firma de la envoltura para el
     * caso raro obligaria a tocar los cinco; esto lo pone solo el que lo
     * necesita, adentro de su propio `->action()`, y `conElLote()` lo lee sin
     * saber de quien vino.
     *
     * Privada: Livewire no la serializa y no hace falta que lo haga — se
     * escribe y se consume dentro del mismo request.
     */
    private ?string $destinoDespues = null;

    /**
     * Lo que se lee en la notificacion despues de firmar.
     *
     * @param list<Lote> $lotes
     */
    private function avisoDeVenta(Venta $venta, array $lotes, Cliente $titular, int $firmantes): string
    {
        return sprintf(
            '%s vendido%s a %s%s. Contrato %s por %s.',
            $this->codigosDe($lotes),
            count($lotes) > 1 ? 's' : '',
            (string) $titular->getAttribute('nombre'),
            $firmantes > 1 ? sprintf(' y %d mas', $firmantes - 1) : '',
            (string) $venta->getAttribute('numero_contrato'),
            $venta->montoValorTotal()->formateado(),
        );
    }

    private function primerPlan(): ?PlanDePago
    {
        return $this->planesVigentes()[0] ?? null;
    }

    private function planDelPlazo(int $meses): ?PlanDePago
    {
        foreach ($this->planesVigentes() as $plan) {
            if ((int) $plan->getAttribute('meses') === $meses) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @return list<PlanDePago>
     */
    private function planesVigentes(): array
    {
        /** @var list<PlanDePago> $planes */
        $planes = PlanDePago::query()
            ->where('proyecto_id', $this->getRecord()->getKey())
            ->activos()
            ->orderBy('meses')
            ->get()
            ->all();

        return $planes;
    }

    /**
     * Los 31 dias, como etiquetas de texto: Select::options() declara
     * array<array<string>|string> y un array de enteros no lo satisface.
     *
     * @return array<int, string>
     */
    private function diasDelMes(): array
    {
        $dias = [];

        for ($dia = 1; $dia <= 31; $dia++) {
            $dias[$dia] = (string) $dia;
        }

        return $dias;
    }

    /**
     * Un monto a medio tipear no es un error: es alguien escribiendo.
     */
    private function monto(mixed $valor): Monto
    {
        if (! is_string($valor) && ! is_int($valor)) {
            return Monto::cero();
        }

        try {
            return new Monto($valor);
        } catch (GrupoOlympoException) {
            return Monto::cero();
        }
    }

    private function fechaDe(mixed $valor): CarbonImmutable
    {
        if (is_string($valor) && $valor !== '') {
            try {
                return CarbonImmutable::parse($valor);
            } catch (Throwable) {
                // Fecha a medio escribir: para la vista previa vale hoy.
            }
        }

        return CarbonImmutable::parse(today()->toDateString());
    }

    private function configEntero(string $clave, int $porDefecto): int
    {
        $valor = config($clave, $porDefecto);

        return is_int($valor) ? $valor : $porDefecto;
    }

    private function configTexto(string $clave, string $porDefecto): string
    {
        $valor = config($clave, $porDefecto);

        return is_string($valor) ? $valor : $porDefecto;
    }

    /**
     * 🔴 La frontera de verdad de los dos botones del encabezado.
     *
     * `visible()` decide qué se DIBUJA; esto decide qué se PUEDE. Una acción
     * de Filament se monta por URL o desde la consola del navegador, así que
     * un botón escondido no es una guarda — es la misma lección que `modo` en
     * el modal de cobro y que vender sin plan de pago.
     *
     * Los dos preguntan por el mismo lado —`ProyectoPolicy`— para que no
     * puedan desincronizarse con lo que decide `visible()`.
     */
    private function exigirPermisoDelPlano(string $habilidad): void
    {
        abort_unless(
            auth()->user()?->can($habilidad, Proyecto::class) === true,
            403,
            'No tenés permiso para cargar el plano de un desarrollo.',
        );
    }

    /**
     * Corre un movimiento sobre el lote de los argumentos y avisa.
     *
     * Las excepciones del dominio se muestran como notificacion y no como
     * pantalla de error: su mensaje esta escrito para quien esta
     * atendiendo a un cliente, no para un programador.
     *
     * @param array<string, mixed> $arguments
     * @param callable(Lote): string $movimiento
     */
    private function conElLote(array $arguments, callable $movimiento): void
    {
        $lote = Lote::query()->find($this->entero($arguments, 'lote', 0));

        if (! $lote instanceof Lote) {
            Notification::make()->title('No se encontro el lote')->danger()->send();

            return;
        }

        try {
            $mensaje = $movimiento($lote);
        } catch (GrupoOlympoException $error) {
            Notification::make()
                ->title('No se pudo hacer ese movimiento')
                ->body($error->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title($mensaje)->success()->send();

        /*
         * Al plano, salvo que el movimiento haya pedido otra cosa. Despues de
         * FIRMAR una venta lo que sigue no esta en el plano: es imprimir el
         * contrato, mirar el plan de cuotas o cobrar. Quedarse aca obliga a ir
         * a buscar el expediente que uno acaba de crear. Lo pidio Mauricio el
         * 14-ago-2026, viendo la notificacion del contrato REB-2026-0003.
         */
        $this->redirect(
            $this->destinoDespues
            ?? ProyectoResource::getUrl('plano', ['record' => $this->getRecord()])
        );
    }

    /**
     * Selector de cliente con alta rapida incorporada.
     *
     * Quien esta atendiendo en ventanilla no deberia tener que abandonar
     * el plano —y perder el lote que tenia seleccionado— porque el
     * comprador todavia no esta cargado. Pide lo minimo: nombre, DNI y
     * telefono. El resto de la ficha se completa despues, desde Clientes.
     *
     * Las opciones se resuelven con un closure y no de una vez: asi el
     * cliente recien creado aparece en la lista sin recargar la pagina.
     */
    private function selectorDeCliente(string $etiqueta): Select
    {
        return Select::make('cliente_id')
            ->label($etiqueta)
            ->options(static fn (): array => self::clientesDisponibles())
            ->searchable()
            ->required()
            // live: el selector de copropietarios saca de su lista a quien
            // quede como titular, y para eso tiene que enterarse al toque.
            ->live()
            ->createOptionForm($this->altaRapidaDeCliente())
            ->createOptionUsing(static fn (array $data): int => self::crearClienteRapido($data));
    }

    /**
     * @return array<int, mixed>
     */
    private function altaRapidaDeCliente(): array
    {
        /*
         * Los indices unicos de `clientes` son PARCIALES: llevan
         * `WHERE deleted_at IS NULL`. La regla `unique` de Laravel no sabe
         * nada de eso y si mira las filas borradas, asi que sin este
         * whereNull diria "ya existe" por un cliente archivado que la
         * persona ni siquiera puede ver. Es el mismo cuidado que ya esta
         * documentado en ClienteForm.
         */
        /*
         * El parametro se llama $rule y NO $regla: Filament inyecta los
         * argumentos de este closure POR NOMBRE contra una lista fija. Con
         * otro nombre la inyeccion falla, cae a resolverlo por tipo desde
         * el contenedor, e intenta construir un Unique sin tabla.
         */
        $soloVivos = static fn (Unique $rule): Unique => $rule->whereNull('deleted_at');

        return [
            // §10.4: el auto-mayusculas NO aplica a nombres de personas.
            MayusculasField::make('nombre')
                ->label('Nombre completo')
                ->required()
                ->maxLength(150)
                ->prefixIcon('heroicon-o-user')
                ->placeholder('Ej: MARÍA DE LOS ÁNGELES RODRÍGUEZ')
                ->columnSpanFull(),

            /*
              * ignoreRecord: false es obligatorio aca. Por defecto Filament
              * ignora "el registro del formulario", y el registro de esta
              * pagina es el PROYECTO: sin apagarlo, la consulta de unicidad
              * sobre `clientes` sale con un "proyectos"."id" <> N pegado y
              * Postgres la rechaza por una tabla que no esta en el FROM.
              */
            DNIField::make()
                ->unique(
                    table: Cliente::class,
                    column: 'dni',
                    ignoreRecord: false,
                    modifyRuleUsing: $soloVivos,
                ),

            TelefonoHondurasField::make('telefono', 'Teléfono'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function crearClienteRapido(array $data): int
    {
        $cliente = Cliente::query()->create([
            'nombre'   => self::campo($data, 'nombre') ?? '',
            'dni'      => self::campo($data, 'dni'),
            'telefono' => self::campo($data, 'telefono'),
            'activo'   => true,
        ]);

        Notification::make()
            ->title('Cliente registrado')
            ->body(sprintf(
                '%s quedo cargado. Su ficha completa se puede terminar despues, desde Clientes.',
                (string) $cliente->getAttribute('nombre')
            ))
            ->success()
            ->send();

        return (int) $cliente->getKey();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function campo(array $data, string $nombre): ?string
    {
        $valor = $data[$nombre] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }

    /**
     * @return array<int|string, string>
     */
    private static function clientesDisponibles(): array
    {
        return Cliente::query()->activos()->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    /**
     * Importar el plano del topografo desde un DXF de AutoCAD.
     *
     * Las capas NO se piden en un formulario reactivo a proposito: el
     * archivo se analiza al vuelo y se sugieren solas por su nombre, con
     * el vocabulario que se usa de verdad en los planos de lotificacion.
     * Quien quiera mandar puede escribirlas; quien no, no tiene que saber
     * como se llaman las capas adentro de su propio DXF.
     */
    private function accionDeImportar(): Action
    {
        return Action::make('importarDxf')
            ->label('Importar plano DXF')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            // Ver el comentario del botón «Acomodar plano» (23-ago-2026).
            ->visible(fn (): bool => auth()->user()?->can('importarPlano', Proyecto::class) === true)
            ->modalHeading('Importar el plano desde AutoCAD')
            ->modalDescription(
                'Lee las polilineas cerradas del archivo y crea un lote por cada una, con su '.
                'area real y el numero que diga el rotulo de adentro. El plano deja de estar '.
                'marcado como esquematico.'
            )
            ->modalSubmitActionLabel('Importar')
            ->schema([
                FileUpload::make('archivo')
                    ->label('Archivo DXF')
                    ->required()
                    ->storeFiles(false)
                    ->maxSize(20480)
                    ->helperText('DXF en formato ASCII, hasta 20 MB. Si el plano esta en DWG, '.
                                 'hay que exportarlo a DXF desde AutoCAD primero.'),

                Toggle::make('bloque_por_rotulo')
                    ->label('El rotulo trae la letra de su manzana (A1, B7, C-3…)')
                    ->default(false)
                    ->helperText('Prendido, cada lote entra en el bloque que dice su rotulo y los '.
                                 'que falten se crean solos. El plano entero se importa de UNA vez: '.
                                 'partirlo en varios archivos apilaria las manzanas una encima de otra.'),

                Select::make('bloque_id')
                    ->label('Bloque donde entran los lotes')
                    ->options(fn (): array => Bloque::query()
                        ->where('proyecto_id', $this->getRecord()->getKey())
                        ->orderBy('orden')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->required()
                    ->helperText('Con la opcion de arriba prendida, este es solo el destino de los '.
                                 'lotes cuyo rotulo NO traiga letra.'),

                Select::make('unidad')
                    ->label('¿En que unidad esta dibujado el plano?')
                    ->options($this->unidadesDisponibles())
                    ->default((string) UnidadDxf::Metros->value)
                    ->required()
                    ->helperText('Se pregunta siempre, aunque el archivo lo declare: en planos de '.
                                 'topografia esa variable viene sin configurar muy seguido, y de '.
                                 'este dato sale el area de cada lote.'),

                TextInput::make('precio_vara')
                    ->label('Precio '.$this->unidad()->porUnidad().' para los lotes nuevos')
                    ->numeric()
                    ->required()
                    ->default('1200.00'),

                TextInput::make('capa_lotes')
                    ->label('Capa de los lotes (opcional)')
                    ->helperText('En blanco, se detecta sola.'),

                TextInput::make('capa_rotulos')
                    ->label('Capa de los numeros (opcional)'),

                TextInput::make('capa_calles')
                    ->label('Capa de las calles (opcional)'),
            ])
            ->action(function (array $data): void {
                $this->exigirPermisoDelPlano('importarPlano');

                $contenido = $this->contenidoDelArchivo($data['archivo'] ?? null);

                if ($contenido === null) {
                    Notification::make()
                        ->title('No se pudo leer el archivo')
                        ->body('La subida no llego completa. Proba de nuevo.')
                        ->danger()
                        ->send();

                    return;
                }

                /** @var Proyecto $proyecto */
                $proyecto = $this->getRecord();

                $bloque = Bloque::query()->findOrFail($this->entero($data, 'bloque_id', 0));
                $importador = new ImportadorDeDxf;
                $analisis = $importador->analizar($contenido);

                $capaDeLotes = $this->texto($data, 'capa_lotes', '') ?: ($analisis->capaSugeridaDeLotes() ?? '');
                $unidadElegida = $this->texto($data, 'unidad', (string) UnidadDxf::Metros->value);

                $resultado = $importador->importar($bloque, $contenido, new OpcionesDeImportacion(
                    capaDeLotes: $capaDeLotes,
                    precioVara: $this->texto($data, 'precio_vara', '0'),
                    capaDeRotulos: $this->texto($data, 'capa_rotulos', '') ?: $analisis->capaSugeridaDeRotulos(),
                    capaDeCalles: $this->texto($data, 'capa_calles', '') ?: $analisis->capaSugeridaDeCalles(),
                    unidad: UnidadDxf::desde($unidadElegida === 'varas' ? null : (int) $unidadElegida),
                    dibujadoEnVaras: $unidadElegida === 'varas',
                    /*
                     * La vara es del DESARROLLO, no del sistema: de este
                     * factor sale cuantas varas² tiene cada lote, y el
                     * precio es por vara². Si el topografo de este proyecto
                     * levanto con otra vara, el area de todo el residencial
                     * sale corrida. Se configura en la ficha del proyecto,
                     * pestaña «Estado» → «Medidas del plano»; vacio usa la
                     * del sistema.
                     */
                    varaEnMetros: $proyecto->varaEnMetros(),
                    bloquePorRotulo: $this->booleano($data, 'bloque_por_rotulo'),
                ));

                $cuerpo = sprintf(
                    'Capa de lotes: %s. Area total: %s '.$proyecto->unidadDeArea()->plural().'. %s%s%s',
                    $capaDeLotes,
                    number_format($resultado->areaTotalVaras, 2),
                    count($resultado->lotesPorBloque) > 1 ? "Repartidos: {$resultado->repartoEnTexto()}. " : '',
                    $resultado->bloquesCreados === []
                        ? ''
                        : 'Se crearon los bloques '.implode(', ', $resultado->bloquesCreados).'. ',
                    $resultado->callesCreadas > 0 ? "Ademas se dibujaron {$resultado->callesCreadas} calles." : ''
                );

                Notification::make()
                    ->title("Se importaron {$resultado->lotesCreados} lotes")
                    ->body(trim($cuerpo.' '.implode(' ', $resultado->advertencias)))
                    ->success()
                    ->persistent()
                    ->send();

                $this->redirect(ProyectoResource::getUrl('plano', ['record' => $this->getRecord()]));
            });
    }

    /**
     * @return array<string, string>
     */
    private function unidadesDisponibles(): array
    {
        $opciones = ['varas' => 'El plano ya esta dibujado en varas'];

        foreach (UnidadDxf::cases() as $unidad) {
            if ($unidad->enMetros() !== null) {
                $opciones[(string) $unidad->value] = $unidad->etiqueta();
            }
        }

        return $opciones;
    }

    /**
     * El contenido del DXF subido, sin guardarlo en disco.
     *
     * Se usa storeFiles(false) para que el archivo no quede tirado en el
     * almacenamiento del proyecto: lo que importa son los lotes que salen
     * de el, no el archivo.
     */
    private function contenidoDelArchivo(mixed $estado): ?string
    {
        $archivo = is_array($estado) ? reset($estado) : $estado;

        if (is_object($archivo) && method_exists($archivo, 'get')) {
            $contenido = $archivo->get();

            return is_string($contenido) ? $contenido : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function texto(array $data, string $campo, string $porDefecto): string
    {
        $valor = $data[$campo] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : $porDefecto;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function entero(array $data, string $campo, int $porDefecto): int
    {
        $valor = $data[$campo] ?? null;

        return is_numeric($valor) ? (int) $valor : $porDefecto;
    }

    /**
     * La unidad de área del proyecto que se está mirando.
     *
     * Toda esta pantalla es de UN proyecto, así que la unidad es una sola
     * y se resuelve una vez por llamada en vez de arrastrarla por
     * parámetro hasta el fondo de cada sprintf.
     */
    private function unidad(): UnidadDeArea
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        return $proyecto->unidadDeArea();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function booleano(array $data, string $campo): bool
    {
        $valor = $data[$campo] ?? false;

        // Un Toggle de Filament llega como bool, pero el mismo estado
        // rehidratado desde el request puede venir como "1". Las dos formas
        // quieren decir lo mismo y ninguna es un cast ciego a bool, que
        // convertiria la cadena "false" en verdadero.
        return in_array($valor, [true, 1, '1'], true);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function getViewData(): array
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        return [
            'plano' => new PlanoDelProyecto()->para($proyecto),

            /*
             * R21: el receptor cobra, la administradora reprograma. Se
             * pregunta aca para no DIBUJAR el boton del abono a quien no
             * puede; el borde de verdad esta adentro de la accion.
             */

            /*
             * Los planes NO pasan por PlanoDelProyecto: son del negocio y
             * no de la geometria. Solo los que se ofrecen hoy, y ya
             * convertidos a strings —el precio nunca viaja como float.
             */
            'planes' => $proyecto->planesDePago()
                ->activos()
                ->orderBy('meses')
                ->get()
                ->map(static fn (PlanDePago $plan): array => [
                    'meses'      => (int) $plan->getAttribute('meses'),
                    'etiqueta'   => $plan->nombre(),
                    'precioVara' => $plan->montoPrecioVara()->redondeado(),
                    'tasa'       => $plan->tasaDeInteres()->redondeada(),
                ])
                ->all(),
        ];
    }
}
