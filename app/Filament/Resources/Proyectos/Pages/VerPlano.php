<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Plano\AcomodadorDelPlano;
use App\Domain\Plano\Dxf\ImportadorDeDxf;
use App\Domain\Plano\Dxf\OpcionesDeImportacion;
use App\Domain\Plano\Dxf\UnidadDxf;
use App\Domain\Plano\ParametrosDeAcomodo;
use App\Domain\Plano\PlanoDelProyecto;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\ListaDePrecios;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Filament\Schemas\Components\DNIField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
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
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->accionDeImportar(),

            Action::make('acomodar')
                ->label('Acomodar plano')
                ->icon(Heroicon::OutlinedSquares2x2)
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
            ->modalDescription('Queda reservado para esa persona. Se puede liberar despues sin consecuencias.')
            ->modalSubmitActionLabel('Apartar')
            ->modalWidth('lg')
            ->fillForm(fn (array $arguments): array => $this->datosInicialesDeApartado($arguments))
            ->schema([
                Hidden::make('monto_senia'),
                Hidden::make('vence_el'),

                $this->selectorDeCliente('Cliente'),

                Placeholder::make('resumen')
                    ->label('Condiciones')
                    ->content(fn (Get $get): string => $this->resumenDeApartado($get)),

                Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    $cliente = Cliente::query()->findOrFail($this->entero($data, 'cliente_id', 0));

                    new RegistroDeCompromisos()->apartar(
                        $lote,
                        $cliente,
                        montoSenia: $this->texto($data, 'monto_senia', '') ?: null,
                        venceEl: $this->texto($data, 'vence_el', '') ?: null,
                        observaciones: $this->texto($data, 'observaciones', '') ?: null,
                    );

                    return sprintf(
                        '%s quedo apartado a nombre de %s.',
                        (string) $lote->getAttribute('codigo'),
                        (string) $cliente->getAttribute('nombre')
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
                'Se numera el expediente, se congelan el area y el precio, y queda armado el plan '.
                'de cuotas. Si el lote estaba apartado, tiene que ser a nombre de la misma persona.'
            )
            ->modalSubmitActionLabel('Firmar la venta')
            ->modalWidth('2xl')
            ->fillForm(fn (array $arguments): array => $this->datosInicialesDeVenta($arguments))
            ->schema([
                Hidden::make('lote_id'),
                Hidden::make('cotizado'),

                $this->selectorDeCliente('¿Quien compra?'),

                Placeholder::make('resumen')
                    ->label('Lo que se va a firmar')
                    ->content(fn (Get $get): string => $this->resumenDeVenta($get)),

                TextInput::make('plazo_meses')
                    ->label('Plazo en meses')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(PlanDeCuotas::PLAZO_MAXIMO_MESES)
                    ->required()
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => ! $get('cotizado'))
                    ->helperText('0 es contado, sin cuotas.'),

                MontoField::make('precio_vara', 'Precio por vara²')
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => ! $get('cotizado')),

                MontoField::make('prima', 'Prima')
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => ! $get('cotizado'))
                    ->helperText('Se paga completa al firmar (R5).'),

                Select::make('dia_pago')
                    ->label('Dia de pago')
                    ->options($this->diasDelMes())
                    ->required()
                    ->live()
                    ->native(false)
                    ->helperText('En los meses cortos se corre al ultimo dia.'),

                DatePicker::make('fecha_contrato')
                    ->label('Fecha del contrato')
                    ->required()
                    ->live()
                    ->native(false)
                    ->displayFormat('d/m/Y'),

                TextInput::make('motivo_descuento')
                    ->label('Motivo del descuento')
                    ->maxLength(200)
                    ->visible(fn (Get $get): bool => $this->hayDescuento($get))
                    ->required(fn (Get $get): bool => $this->hayDescuento($get))
                    ->helperText('El precio va por debajo del de lista. R4: queda con tu usuario y la fecha.'),

                Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    $cliente = Cliente::query()->findOrFail($this->entero($data, 'cliente_id', 0));

                    /** @var Proyecto $proyecto */
                    $proyecto = $this->getRecord();

                    $venta = app(RegistroDeVentas::class)->activar(
                        proyecto: $proyecto,
                        lotes: [$lote],
                        clientes: [$cliente],
                        prima: $this->monto($this->texto($data, 'prima', '0')),
                        plazoMeses: $this->entero($data, 'plazo_meses', 0),
                        diaPago: $this->entero($data, 'dia_pago', 1),
                        fechaContrato: CarbonImmutable::parse(
                            $this->texto($data, 'fecha_contrato', today()->toDateString())
                        ),
                        observaciones: $this->texto($data, 'observaciones', '') ?: null,
                        precios: [new PrecioPactado(
                            loteId: (int) $lote->getKey(),
                            precioVara: $this->monto($this->texto($data, 'precio_vara', '0')),
                            motivo: $this->texto($data, 'motivo_descuento', '') ?: null,
                        )],
                    );

                    return sprintf(
                        '%s vendido a %s. Contrato %s por %s.',
                        (string) $lote->getAttribute('codigo'),
                        (string) $cliente->getAttribute('nombre'),
                        (string) $venta->getAttribute('numero_contrato'),
                        $venta->montoValorTotal()->formateado(),
                    );
                });
            });
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
            ])
            ->action(function (array $arguments, array $data): void {
                $this->conElLote($arguments, function (Lote $lote) use ($data): string {
                    new RegistroDeCompromisos()->liberar($lote, $this->texto($data, 'motivo', 'Sin motivo'));

                    return $lote->getAttribute('codigo').' volvio a estar disponible.';
                });
            });
    }

    // ─── Ayudas de la venta desde el plano ────────────────────────────

    /**
     * Con que llega precargado el formulario al abrirse.
     *
     * Se propone el plan MAS CORTO que ofrezca el proyecto: es el que menos
     * compromete al cliente. Si no hay ninguno, manda el precio propio del
     * lote, que es lo que habia antes de que existiera la lista por plazo.
     *
     * @param array<string, mixed> $arguments
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

        return [
            'plazo_meses' => match (true) {
                $plazo >= 0                 => $plazo,
                $plan instanceof PlanDePago => (int) $plan->getAttribute('meses'),
                default                     => $this->configEntero('lotificadora.ventas.plazo_meses_default', 60),
            },
            'precio_vara' => match (true) {
                $cotizado !== ''            => $cotizado,
                $plan instanceof PlanDePago => $plan->montoPrecioVara()->redondeado(),
                default                     => is_string($propio) || is_int($propio) ? (string) $propio : '0',
            },
            'prima'    => $this->texto($arguments, 'prima', '') ?: null,
            'dia_pago' => $this->configEntero('lotificadora.ventas.dia_pago_default', 5),

            /*
             * Al estado, no a $arguments: los closures de un componente del
             * schema no reciben $arguments —Filament revienta al evaluarlos—
             * pero el estado del formulario si llega a todos lados.
             */
            'lote_id'        => $lote?->getKey(),
            'cotizado'       => $this->vinoCotizado($arguments),
            'fecha_contrato' => today()->toDateString(),
        ];
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
        return $this->entero($arguments, 'plazo', -1) >= 0;
    }

    /**
     * Plazo, precio y prima, leidos del estado del formulario.
     *
     * Un campo con visible(false) NO se envia —Filament no deshidrata lo
     * invisible—, asi que cuando la cotizacion vino del modal los tres se
     * llenan igual por fillForm y quedan ahi aunque no se vean.
     *
     * @return array{plazo: int, precio: Monto, prima: Monto}
     */
    private function condicionesEnPantalla(Get $get): array
    {
        return [
            'plazo'  => (int) $get('plazo_meses'),
            'precio' => $this->monto($get('precio_vara')),
            'prima'  => $this->monto($get('prima')),
        ];
    }

    /**
     * El resumen del apartado: los dos numeros que se estan confirmando.
     */
    private function resumenDeApartado(Get $get): string
    {
        $monto = $this->monto($get('monto_senia'));
        $vence = $get('vence_el');

        return sprintf(
            '%s, vence el %s. Al vender cuenta como parte de la prima; si vence sin que el cliente vuelva, el lote se libera y el dinero se devuelve.',
            $monto->formateado(),
            $this->fechaDe(is_string($vence) ? $vence : null)->format('d/m/Y'),
        );
    }

    /**
     * Valor, saldo y cuota, con el mismo motor que despues persiste.
     */
    private function resumenDeVenta(Get $get): string
    {
        $lote = Lote::query()->find($this->entero(['id' => $get('lote_id')], 'id', 0));

        if (! $lote instanceof Lote) {
            return 'No se encontro el lote.';
        }

        $condiciones = $this->condicionesEnPantalla($get);
        $precio = $condiciones['precio'];
        $prima = $condiciones['prima'];
        $valor = new Monto($precio->multiplicarPor((string) $lote->getAttribute('area_varas'))->redondeado());

        try {
            $plan = PlanDeCuotas::nuevo(
                $valor,
                $prima,
                $condiciones['plazo'],
                (int) $get('dia_pago'),
                $this->fechaDe($get('fecha_contrato')),
            );
        } catch (GrupoOlympoException $error) {
            // El mensaje del dominio ya esta escrito para quien atiende.
            return $error->getMessage();
        }

        $encabezado = sprintf(
            '%s la vara² · valor %s · prima %s · saldo %s',
            $precio->formateado(),
            $valor->formateado(),
            $prima->formateado(),
            $plan->saldoFinanciado->formateado(),
        );

        $cuota = $plan->cuotaMensual();

        if (! $cuota instanceof Monto) {
            return $encabezado.' · de contado, sin cuotas.';
        }

        return $encabezado.sprintf(
            ' · %d cuotas de %s (la ultima, %s).',
            $plan->count(),
            $cuota->formateado(),
            $plan->ultima()?->monto->formateado() ?? '—',
        );
    }

    /**
     * ¿El precio tecleado va por debajo del de lista PARA ESE PLAZO? (R4)
     */
    private function hayDescuento(Get $get): bool
    {
        $lote = Lote::query()->find($this->entero(['id' => $get('lote_id')], 'id', 0));

        if (! $lote instanceof Lote) {
            return false;
        }

        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        $condiciones = $this->condicionesEnPantalla($get);
        $lista = app(ListaDePrecios::class)->deListaPara($proyecto, $lote, $condiciones['plazo']);

        return $condiciones['precio']->menorQue($lista);
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

        $this->redirect(ProyectoResource::getUrl('plano', ['record' => $this->getRecord()]));
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

                Select::make('bloque_id')
                    ->label('Bloque donde entran los lotes')
                    ->options(fn (): array => Bloque::query()
                        ->where('proyecto_id', $this->getRecord()->getKey())
                        ->orderBy('orden')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->required()
                    ->helperText('Si el plano trae varias manzanas, conviene importar una por vez '.
                                 'filtrando por su capa.'),

                Select::make('unidad')
                    ->label('¿En que unidad esta dibujado el plano?')
                    ->options($this->unidadesDisponibles())
                    ->default((string) UnidadDxf::Metros->value)
                    ->required()
                    ->helperText('Se pregunta siempre, aunque el archivo lo declare: en planos de '.
                                 'topografia esa variable viene sin configurar muy seguido, y de '.
                                 'este dato sale el area de cada lote.'),

                TextInput::make('precio_vara')
                    ->label('Precio por vara² para los lotes nuevos')
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
                $contenido = $this->contenidoDelArchivo($data['archivo'] ?? null);

                if ($contenido === null) {
                    Notification::make()
                        ->title('No se pudo leer el archivo')
                        ->body('La subida no llego completa. Proba de nuevo.')
                        ->danger()
                        ->send();

                    return;
                }

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
                    varaEnMetros: (string) config('lotificadora.area.vara_en_metros', '0.8359'),
                ));

                $cuerpo = sprintf(
                    'Capa de lotes: %s. Area total: %s varas². %s',
                    $capaDeLotes,
                    number_format($resultado->areaTotalVaras, 2),
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
                ])
                ->all(),
        ];
    }
}
