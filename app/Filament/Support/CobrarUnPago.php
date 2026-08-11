<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Pagos\EfectoDelAbono;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as Cuotas;
use Illuminate\Support\HtmlString;

/**
 * El modal donde entra el dinero, uno solo para todas las pantallas.
 *
 * ═══ QUE PROBLEMA RESUELVE ═══
 *
 * Cobrar era un trámite que empezaba navegando. El botón estaba en el
 * expediente, así que desde el listado de ventas —y desde la ficha del
 * cliente, que es donde de verdad se atiende— había que entrar al expediente,
 * cobrar, y volver. Mauricio lo pidió el 10-ago-2026 con todas las letras:
 * «acá no debe de redirigirme a la vista de ventas, siempre en la vista de
 * cliente ahí debe de abrirse el modal».
 *
 * Vivía en `ViewVenta`, que llegó a 996 líneas de las cuales ~850 eran este
 * modal. Ahora vive acá y `ViewVenta` vuelve a ser lo que dice su nombre.
 *
 * ═══ POR QUE UNA CLASE Y NO UN TRAIT NI UN COMPONENTE ═══
 *
 * Es el mismo argumento de `ImprimirRecibo`, que ya está en el repo: esto
 * MUEVE DINERO, y el día que cambie una regla —un permiso nuevo, una
 * confirmación, otro reparto— tiene que cambiar en un solo lugar o alguna de
 * las tres pantallas se queda cobrando con las reglas viejas. Un trait se
 * copia adentro de cada clase y se puede pisar sin que nada avise; una clase
 * con constructor privado no.
 *
 * ═══ LA FORMA, Y POR QUE ES ASI ═══
 *
 * Las closures de una acción reciben `$record` inyectado —tanto en una fila de
 * tabla como en una página de registro— así que cada una construye su propia
 * instancia con la venta que le tocó. Sin estado compartido entre filas: dos
 * modales abiertos sobre dos ventas distintas no se pisan.
 *
 * ═══ LAS DOS PUERTAS AL MISMO MODAL ═══
 *
 * - `accion()` — nombre `cobrar`, abre en «Cuota». Es la de todos los días y
 *   va en la tabla, en la ficha del cliente y en el expediente.
 * - `abonoDirecto()` — nombre `abonar_a_capital`, el MISMO modal abierto en
 *   «Abono». Es el trámite de la administradora y por eso conserva su botón
 *   propio en el expediente, con su permiso propio (R21).
 *
 * Los dos nombres se conservaron a propósito: `CobrarDesdeElExpedienteTest` y
 * `AbonarACapitalTest` disparan las acciones por nombre, así que sirvieron de
 * red para la mudanza sin tocarles una línea.
 *
 * ⚠️ El 10-ago, más tarde, `AbonarACapitalTest` SÍ tuvo que cambiar: el abono
 * pasó de un `Select` de un lote a renglones por lote, y eso es un cambio
 * deliberado del contrato de la pantalla, no una rotura. Lo que NO se tocó son
 * sus assertions ni `AbonoACapitalTest`, el del dominio — que es donde vive la
 * red de verdad del comportamiento.
 */
final readonly class CobrarUnPago
{
    /**
     * Registrar un pago. La puerta de todos los días.
     */
    public static function accion(): Action
    {
        return self::modal('cobrar', ModoDeCobro::Cuota)
            ->label('Registrar un pago')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(static fn (Venta $record): bool => self::seLePuedeCobrar($record)
                && auth()->user()?->can('create', Recibo::class) === true);
    }

    /**
     * El mismo modal, abierto en «Abono a capital» (R21).
     *
     * No es una copia: es `modal()` con otro valor inicial del toggle. Lo que
     * cambia de verdad es el permiso, que acá es el caro.
     */
    public static function abonoDirecto(): Action
    {
        return self::modal('abonar_a_capital', ModoDeCobro::Abono)
            ->label('Abonar a capital')
            ->icon(Heroicon::OutlinedArrowTrendingDown)
            ->color('primary')
            ->visible(static fn (Venta $record): bool => self::seLePuedeCobrar($record)
                && self::puedeReprogramar());
    }

    /**
     * La única definición del modal. Las dos acciones de arriba son esto con
     * otra etiqueta, otro permiso y otro valor inicial del toggle.
     */
    private static function modal(string $nombre, ModoDeCobro $porDefecto): Action
    {
        return Action::make($nombre)
            ->modalHeading('Registrar un pago')
            ->modalSubmitActionLabel('Registrar y emitir el recibo')
            ->modalWidth('2xl')
            ->fillForm(static fn (Venta $record): array => new self($record)->valoresIniciales($porDefecto))
            ->schema(static fn (Venta $record): array => new self($record)->campos())
            ->action(static function (Venta $record, array $data): void {
                new self($record)->registrar($data);
            });
    }

    private function __construct(private Venta $venta) {}

    // ─── Quién puede qué ──────────────────────────────────────────────

    /**
     * Un expediente cerrado no recibe dinero.
     *
     * El Service lo rechaza igual, pero ofrecer el botón sería invitar a un
     * movimiento que no se puede hacer.
     */
    private static function seLePuedeCobrar(Venta $venta): bool
    {
        return $venta->getAttribute('estado') === EstadoVenta::Vigente;
    }

    /**
     * 🔴 La frontera de R21: el receptor cobra, la administradora reprograma.
     *
     * Se pregunta en tres momentos y los tres hacen falta: para armar la lista
     * del toggle, para ofrecer el botón del abono, y —el que de verdad
     * protege— antes de ejecutar. Los dos primeros son comodidad; el tercero es
     * el borde.
     */
    private static function puedeReprogramar(): bool
    {
        return auth()->user()?->can('reprogramar', Venta::class) === true;
    }

    // ─── El formulario ────────────────────────────────────────────────

    /**
     * Lo que el modal propone al abrirse.
     *
     * En «Cuota», todo marcado y cada lote con su cuota del mes: es el caso de
     * todos los días —el cliente de un contrato de tres lotes viene a pagar el
     * mes de los tres— y desmarcar dos es más rápido que teclear tres montos.
     * Nada se cobra sin que el receptor vea el desglose y apriete el botón.
     *
     * @return array<string, mixed>
     */
    private function valoresIniciales(ModoDeCobro $porDefecto): array
    {
        $datos = [
            'modo'          => $porDefecto->value,
            'fecha'         => today()->toDateString(),
            'forma_pago'    => FormaDePago::Efectivo->value,
            'compromiso_id' => $this->primerLoteConSaldo()?->getKey(),
            'modalidad'     => ModalidadDeReprogramacion::AcortarPlazo->value,
        ];

        $lotes = $this->lotesQueDeben();

        foreach ($lotes as $lote) {
            $id = (int) $lote->getKey();

            $datos["cobrar_{$id}"] = true;
            $datos["monto_{$id}"] = $this->cuotaSugerida($lote)?->redondeado();

            /*
             * El abono NO viene marcado ni con monto: a diferencia de la cuota,
             * acá no hay un número esperado. El cliente trae lo que trae y elige
             * a qué lote va — proponerlo sería el sistema decidiendo, que es
             * exactamente lo que R21 no quiere.
             *
             * La única excepción es el contrato de UN lote, donde no hay nada
             * que elegir y dejar la casilla apagada es un clic de peaje.
             */
            $datos["abonar_{$id}"] = count($lotes) === 1;
            $datos["modalidad_{$id}"] = ModalidadDeReprogramacion::AcortarPlazo->value;
        }

        return $datos;
    }

    /**
     * @return list<Component>
     */
    private function campos(): array
    {
        return [
            /*
             * El toggle no se muestra cuando hay una sola opción: un receptor
             * vería un único botón encendido que no se puede apagar, que es
             * ruido. Cuando está oculto, Filament no lo deshidrata y `modoDe()`
             * cae en «Cuota» — que es exactamente lo que ese usuario puede.
             */
            ToggleButtons::make('modo')
                ->label('¿Qué es este pago?')
                ->options($this->modos())
                ->grouped()
                ->live()
                ->required()
                /*
                 * ═══ SIN `colors()`, Y ES UNA DECISION ═══
                 *
                 * Tenía uno por opción —verde, ámbar, azul— y Mauricio lo bajó
                 * el 10-ago: tres fondos saturados compiten entre sí y ninguno
                 * dice cuál está elegido. El acabado ahora es un control
                 * segmentado: riel gris y UNA pastilla blanca, que es la única
                 * señal y no compite con nada.
                 *
                 * Vive en el CSS del tema, colgado de esta clase, así que no
                 * alcanza a ningún otro `ToggleButtons` del sistema. El porqué
                 * completo está escrito allá, en el §12.
                 */
                ->extraAttributes(['class' => 'olympo-modo'])
                ->visible(fn (): bool => count($this->modos()) > 1)
                ->helperText(fn (Get $get): string => $this->modoDeLaPantalla($get)->explicacion()),

            /*
             * ── El billete que está sobre el mostrador ──────────────────
             *
             * Se teclea UNA vez y de él sale todo: las cuotas marcadas se
             * restan y el sobrante baja capital. Es la decisión de Mauricio del
             * 10-ago —«un monto y el sistema reparte, es lo que menos se puede
             * teclear mal»— y además hace que el papel cuadre solo contra lo
             * que el cliente entregó.
             */
            MontoField::make('monto_total', 'Monto total recibido')
                ->required()
                ->live(onBlur: true)
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) === ModoDeCobro::Ambas)
                ->helperText('Lo que entregó el cliente. Abajo marcás qué cuotas cubre; lo que sobre baja capital.'),

            // ── Las cuotas: el mismo widget para «Cuota» y para «Ambas» ─
            Section::make('¿Qué viene a pagar?')
                ->description('Puede ser menos que la cuota: lo que falte se arrastra, sin recargo (R2).')
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) !== ModoDeCobro::Abono)
                ->schema($this->renglonesDeCobro()),

            /*
             * ── El abono, que desde el 10-ago se reparte entre lotes ────
             *
             * Un renglón por lote, con SU monto y SU modalidad. Es el mismo
             * widget del cobro —marcar, teclear— porque es el mismo gesto de
             * mostrador y quien atiende ya lo conoce.
             *
             * La modalidad va adentro del renglón y no arriba: los dos caminos
             * los elige el cliente (R21) y con dos lotes puede querer terminar
             * antes el que va a construir y bajar la cuota del otro.
             */
            Section::make('¿A qué lotes abona?')
                ->description('El monto de cada lote lo escribís vos: el sistema no reparte nada solo (R21).')
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) === ModoDeCobro::Abono)
                ->schema($this->renglonesDeAbono()),

            /*
             * ── «Ambas» sigue contra UN lote ───────────────────────────
             *
             * Y es una decisión, no una simplificación pendiente: «Ambas»
             * resuelve una cuota pagada a medias, que es un caso puntual de un
             * lote concreto. Repartir además la raya cuota/abono en cada lote
             * convertiría el modal en una planilla.
             */
            Select::make('compromiso_id')
                ->label('¿A qué lote va el sobrante?')
                ->options(fn (): array => $this->lotesConSaldo())
                ->required()
                ->live()
                ->native(false)
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) === ModoDeCobro::Ambas)
                ->helperText('Lo que sobre después de las cuotas baja el capital de este lote (R21).'),

            Radio::make('modalidad')
                ->label('¿Qué hacemos con lo que falta?')
                ->options(fn (): array => $this->modalidades())
                ->required()
                ->live()
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) === ModoDeCobro::Ambas)
                ->helperText('Lo elige el cliente, no el sistema: los dos caminos son correctos (R21).'),

            // ── Lo que vale para los tres ──────────────────────────────
            Select::make('forma_pago')
                ->label('Forma de pago')
                ->options(fn (): array => $this->formasDePago())
                ->required()
                ->live()
                ->native(false),

            TextInput::make('referencia')
                ->label('Número de referencia')
                ->maxLength(60)
                ->visible(fn (Get $get): bool => $this->exigeReferencia($get))
                ->required(fn (Get $get): bool => $this->exigeReferencia($get))
                ->helperText('Es lo único que después permite cruzar este recibo contra el estado de cuenta del banco (R11).'),

            /*
             * §10.8: «el usuario debe ver el número de cuota antes de
             * confirmar, no después». Quien atiende tiene un cliente enfrente
             * preguntando «¿y con esto qué me queda?».
             */
            Placeholder::make('reparto')
                ->label('Cómo se va a repartir')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) === ModoDeCobro::Cuota)
                ->content(fn (Get $get): HtmlString => $this->repartoEstimado($get)),

            Placeholder::make('efecto')
                ->label(fn (Get $get): string => $this->modoDeLaPantalla($get) === ModoDeCobro::Abono
                    ? 'Cómo quedan los lotes'
                    : 'Cómo queda el lote')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get)->reprograma())
                ->content(fn (Get $get): HtmlString => $this->modoDeLaPantalla($get) === ModoDeCobro::Ambas
                    ? $this->efectoDeAmbas($get)
                    : $this->efectoDeCadaLote($get)),

            DatePicker::make('fecha')
                ->label('Fecha del pago')
                ->required()
                ->native(false)
                ->displayFormat('d/m/Y')
                /*
                 * Acotada de los dos lados. Sin tope, un cobro se podía fechar
                 * en 2019 —el clásico error de tipear el año— o el mes que
                 * viene, dejando una cuota pagada antes de haberse cobrado.
                 *
                 * ⚠️ `endOfDay()` y `startOfDay()`, no la fecha pelada.
                 * Filament valida con `before_or_equal` contra el INSTANTE
                 * exacto, y un tope en «hoy a medianoche» rechaza el propio día
                 * de hoy según cómo venga hidratado el estado —pasó, y solo en
                 * el caso en que la fecha sale del `fillForm` en vez de
                 * tecleárse—. El borde de verdad es
                 * `RegistroDePagos::verificarLaFecha()`, que sí es estricto;
                 * acá alcanza con no dejar elegir OTRO día.
                 */
                ->maxDate(today()->endOfDay())
                ->minDate(function (): ?CarbonInterface {
                    $firma = $this->venta->getAttribute('fecha_contrato');

                    return $firma instanceof CarbonInterface ? $firma->startOfDay() : null;
                }),

            Textarea::make('motivo')
                ->label('¿Por qué?')
                ->required()
                ->rows(2)
                ->maxLength(500)
                ->placeholder('Abono a capital solicitado por el cliente')
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get)->reprograma())
                ->helperText('Queda con tu usuario y la fecha. El mes que viene alguien va a preguntar por qué cambió el número (R21).'),

            Textarea::make('observaciones')
                ->label('Observaciones')
                ->rows(2),
        ];
    }

    /**
     * Las opciones del toggle, recortadas por permiso.
     *
     * @return array<string, string>
     */
    private function modos(): array
    {
        $opciones = [ModoDeCobro::Cuota->value => ModoDeCobro::Cuota->etiqueta()];

        if (! self::puedeReprogramar()) {
            return $opciones;
        }

        $opciones[ModoDeCobro::Abono->value] = ModoDeCobro::Abono->etiqueta();
        $opciones[ModoDeCobro::Ambas->value] = ModoDeCobro::Ambas->etiqueta();

        return $opciones;
    }

    /**
     * El modo que está mostrando la pantalla en este instante.
     *
     * Es SOLO para decidir qué campos se ven. La decisión que mueve dinero la
     * toma `modoDe()` sobre los datos ya enviados, y vuelve a preguntar el
     * permiso — un `Get` es estado del navegador.
     */
    private function modoDeLaPantalla(Get $get): ModoDeCobro
    {
        $elegido = $get('modo');

        return is_string($elegido)
            ? ModoDeCobro::tryFrom($elegido) ?? ModoDeCobro::Cuota
            : ModoDeCobro::Cuota;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function modoDe(array $data): ModoDeCobro
    {
        $elegido = $data['modo'] ?? null;

        return is_string($elegido)
            ? ModoDeCobro::tryFrom($elegido) ?? ModoDeCobro::Cuota
            : ModoDeCobro::Cuota;
    }

    /**
     * Un renglón por lote que debe: la casilla y su monto.
     *
     * ═══ POR QUE LOS CAMPOS SE LLAMAN `cobrar_12`, PLANO ═══
     *
     * Un nombre con puntos (`lotes.12.monto`) arma estado ANIDADO, y con claves
     * numéricas Filament lo deshidrata como lista: el id 12 deja de ser el id
     * 12 y pasa a ser «el treceavo». Plano no tiene ese problema, y leerlo de
     * vuelta es recorrer estos mismos lotes.
     *
     * @return list<Component>
     */
    private function renglonesDeCobro(): array
    {
        $lotes = $this->lotesQueDeben();

        if ($lotes === []) {
            return [
                Placeholder::make('sin_saldo')
                    ->hiddenLabel()
                    ->content('Este expediente no debe nada: todas las cuotas están pagadas.'),
            ];
        }

        $renglones = [];

        foreach ($lotes as $lote) {
            $id = (int) $lote->getKey();

            $renglones[] = Grid::make(12)->schema([
                Checkbox::make("cobrar_{$id}")
                    ->label(sprintf(
                        '%s — debe %s',
                        (string) $lote->lote?->getAttribute('codigo'),
                        $this->saldoDe($lote)->formateado(),
                    ))
                    ->live()
                    ->columnSpan(7),

                MontoField::make("monto_{$id}", 'Monto')
                    ->hiddenLabel()
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => $get("cobrar_{$id}") === true)
                    ->columnSpan(5),
            ]);
        }

        return $renglones;
    }

    /**
     * Un renglón por lote que debe: la casilla, su monto y SU modalidad.
     *
     * Es el gemelo de `renglonesDeCobro()` y comparte su decisión de nombres:
     * campos planos (`abonar_12`, `abono_12`, `modalidad_12`) y no anidados,
     * porque con claves numéricas Filament deshidrata el estado anidado como
     * lista y el id 12 deja de ser el id 12 para pasar a ser «el treceavo».
     *
     * ⚠️ Los prefijos son distintos a los del cobro (`cobrar_`/`monto_`) a
     * propósito: los dos juegos de campos viven en el MISMO formulario, uno
     * visible por modo. Repetirlos haría que marcar un lote para cobrar lo
     * dejara marcado para abonar.
     *
     * @return list<Component>
     */
    private function renglonesDeAbono(): array
    {
        $lotes = $this->lotesQueDeben();

        if ($lotes === []) {
            return [
                Placeholder::make('sin_saldo_abono')
                    ->hiddenLabel()
                    ->content('Este expediente no debe nada: no hay capital que bajar.'),
            ];
        }

        $renglones = [];

        foreach ($lotes as $lote) {
            $id = (int) $lote->getKey();

            $renglones[] = Grid::make(12)->schema([
                Checkbox::make("abonar_{$id}")
                    ->label(sprintf(
                        '%s — debe %s',
                        (string) $lote->lote?->getAttribute('codigo'),
                        $this->saldoDe($lote)->formateado(),
                    ))
                    ->live()
                    ->columnSpan(7),

                MontoField::make("abono_{$id}", 'Monto')
                    ->hiddenLabel()
                    ->live(onBlur: true)
                    ->required(fn (Get $get): bool => $get("abonar_{$id}") === true)
                    ->visible(fn (Get $get): bool => $get("abonar_{$id}") === true)
                    ->columnSpan(5),

                Radio::make("modalidad_{$id}")
                    ->hiddenLabel()
                    ->options(fn (): array => $this->modalidades())
                    ->live()
                    ->required(fn (Get $get): bool => $get("abonar_{$id}") === true)
                    ->visible(fn (Get $get): bool => $get("abonar_{$id}") === true)
                    ->columnSpanFull(),
            ]);
        }

        return $renglones;
    }

    /**
     * Los renglones marcados del abono, en el formato que pide el Service.
     *
     * El monto se valida con un `preg_match` antes de construir el `Monto`: la
     * validación del formulario ya lo impide, pero el borde del dinero no se
     * confía de la pantalla.
     *
     * ⚠️ La modalidad cae en `AcortarPlazo` si viniera vacía, que es el default
     * declarado de R21 —lo que la contratante contestó en el cuestionario
     * original—. No debería pasar nunca: el campo es obligatorio cuando la
     * casilla está marcada. Está para que un formulario a medias no elija en
     * silencio el camino contrario al que el cliente pidió.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{lote: Compromiso, monto: Monto, modalidad: ModalidadDeReprogramacion}>
     */
    private function renglonesDeAbonoTecleados(array $data): array
    {
        $renglones = [];

        foreach ($this->lotesQueDeben() as $lote) {
            $id = (int) $lote->getKey();

            if (($data["abonar_{$id}"] ?? false) !== true) {
                continue;
            }

            $crudo = $data["abono_{$id}"] ?? null;
            $texto = is_string($crudo) ? trim($crudo) : '';

            if (preg_match('/^\d+(\.\d{1,2})?$/', $texto) !== 1) {
                continue;
            }

            $elegida = $data["modalidad_{$id}"] ?? null;

            $renglones[] = [
                'lote'      => $lote,
                'monto'     => new Monto($texto),
                'modalidad' => (is_string($elegida) ? ModalidadDeReprogramacion::tryFrom($elegida) : null)
                    ?? ModalidadDeReprogramacion::AcortarPlazo,
            ];
        }

        return $renglones;
    }

    // ─── Lo que se ejecuta ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function registrar(array $data): void
    {
        $modo = $this->modoDe($data);

        /*
         * 🔴 El borde de verdad. `modo` es un campo del formulario y un campo
         * se falsifica: sin esto, cualquiera con `Create:Recibo` mandaría
         * `modo=ambas` y reescribiría un plan firmado. Es el §9.E3 —el permiso
         * se comprueba donde se ejecuta, no donde se dibuja el botón.
         */
        if ($modo->reprograma() && ! self::puedeReprogramar()) {
            Notification::make()
                ->title('No se registró el movimiento')
                ->body('Reprogramar un plan de cuotas es de la administración. Este pago se puede registrar como cuota.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        try {
            $recibo = match ($modo) {
                ModoDeCobro::Cuota => $this->soloLaCuota($data),
                ModoDeCobro::Abono => $this->soloElAbono($data),
                ModoDeCobro::Ambas => $this->laCuotaYElAbono($data),
            };
        } catch (GrupoOlympoException $error) {
            // El mensaje del dominio ya está escrito para quien atiende.
            $this->avisarDelError($error);

            return;
        }

        if ($modo === ModoDeCobro::Cuota) {
            $this->avisarDelCobro($recibo);

            return;
        }

        $this->avisarDelAbono($recibo, conCuotas: $modo === ModoDeCobro::Ambas);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function soloLaCuota(array $data): Recibo
    {
        return app(RegistroDePagos::class)->cobrarVariosLotes(
            venta: $this->venta,
            cliente: $this->quienPaga(),
            renglones: $this->renglonesTecleados($data),
            forma: FormaDePago::from((string) $data['forma_pago']),
            referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
            fecha: CarbonImmutable::parse((string) $data['fecha']),
            observaciones: is_string($data['observaciones'] ?? null) ? $data['observaciones'] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function soloElAbono(array $data): Recibo
    {
        return app(RegistroDePagos::class)->abonarAVariosLotes(
            venta: $this->venta,
            cliente: $this->quienPaga(),
            renglones: $this->renglonesDeAbonoTecleados($data),
            motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
            forma: FormaDePago::from((string) $data['forma_pago']),
            referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
            fecha: CarbonImmutable::parse((string) $data['fecha']),
            observaciones: is_string($data['observaciones'] ?? null) ? $data['observaciones'] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function laCuotaYElAbono(array $data): Recibo
    {
        return app(RegistroDePagos::class)->cobrarYAbonar(
            venta: $this->venta,
            cliente: $this->quienPaga(),
            cuotas: $this->renglonesTecleados($data),
            loteDelAbono: $this->loteElegido($data),
            aCapital: $this->sobranteTecleado($data),
            modalidad: ModalidadDeReprogramacion::from((string) $data['modalidad']),
            motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
            forma: FormaDePago::from((string) $data['forma_pago']),
            referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
            fecha: CarbonImmutable::parse((string) $data['fecha']),
            observaciones: is_string($data['observaciones'] ?? null) ? $data['observaciones'] : null,
        );
    }

    /**
     * El sobrante: lo que entregó el cliente menos las cuotas que marcó.
     *
     * No se teclea nunca — se deriva del total, que es el único número que la
     * pantalla pide. Si diera negativo vuelve cero, y el dominio rechaza el
     * abono con su mensaje: acá no se decide nada sobre el dinero.
     *
     * @param array<string, mixed> $data
     */
    private function sobranteTecleado(array $data): Monto
    {
        $crudo = $data['monto_total'] ?? null;
        $texto = is_string($crudo) ? trim($crudo) : '';

        if (preg_match('/^\d+(\.\d{1,2})?$/', $texto) !== 1) {
            return Monto::cero();
        }

        $total = new Monto($texto);
        $enCuotas = Monto::cero();

        foreach ($this->renglonesTecleados($data) as $renglon) {
            $enCuotas = $enCuotas->sumar($renglon['monto']);
        }

        return $total->mayorQue($enCuotas) ? $total->restar($enCuotas) : Monto::cero();
    }

    /**
     * `whereKey()->firstOrFail()` y no `findOrFail()`: el segundo acepta
     * también un arreglo de ids, así que Larastan lo tipa
     * `Compromiso|Collection` y toda llamada posterior es «método indefinido en
     * Collection».
     *
     * @param array<string, mixed> $data
     */
    private function loteElegido(array $data): Compromiso
    {
        return Compromiso::query()->whereKey($data['compromiso_id'] ?? null)->firstOrFail();
    }

    private function quienPaga(): Cliente
    {
        return $this->venta->titular() ?? $this->venta->clientes()->firstOrFail();
    }

    // ─── Lo que se muestra antes de confirmar (§10.8) ─────────────────

    /**
     * Cómo caería este cobro, con las mismas reglas que después persisten.
     *
     * Con varios lotes marcados hace falta además el TOTAL: es el número que el
     * cliente va a contar sobre el mostrador, y sumar tres cuotas de cabeza con
     * alguien esperando es como se equivoca.
     *
     * Es un ESTIMADO. El que manda es el Service: relee las cuotas con
     * `FOR UPDATE` dentro de la transacción, porque entre que se pintó esta
     * pantalla y se apretó Guardar, el otro receptor pudo cobrar lo mismo.
     */
    private function repartoEstimado(Get $get): HtmlString
    {
        $marcados = [];

        foreach ($this->lotesQueDeben() as $lote) {
            $id = (int) $lote->getKey();

            if ($get("cobrar_{$id}") !== true) {
                continue;
            }

            $monto = $this->montoTecleado($get, "monto_{$id}");

            if ($monto instanceof Monto) {
                $marcados[] = ['lote' => $lote, 'monto' => $monto];
            }
        }

        if ($marcados === []) {
            return new HtmlString('<p class="olympo-vacio">Marcá al menos un lote y escribí el monto.</p>');
        }

        $html = '';
        $total = Monto::cero();
        $avisos = [];

        foreach ($marcados as $marcado) {
            $codigo = (string) $marcado['lote']->lote?->getAttribute('codigo');
            $reparto = $this->repartoDeUnLote($marcado['lote'], $marcado['monto']);

            if ($reparto['filas'] === '') {
                $avisos[] = sprintf('El lote %s no debe nada.', $codigo);

                continue;
            }

            $html .= '<p class="olympo-lote">'.e($codigo).'</p>'
                .'<ul class="olympo-escalera">'.$reparto['filas'].'</ul>';

            $total = $total->sumar($marcado['monto']->restar($reparto['sobra']));

            if (! $reparto['sobra']->esCero()) {
                $avisos[] = sprintf(
                    'En %s sobran %s: el monto supera lo que debe ese lote y el cobro se va a rechazar.',
                    $codigo,
                    $reparto['sobra']->formateado(),
                );
            }
        }

        if ($html === '') {
            return new HtmlString('<p class="olympo-vacio">'.e(implode(' ', $avisos)).'</p>');
        }

        $html .= sprintf(
            '<div class="olympo-total"><span>Total a cobrar</span><span>%s</span></div>',
            e($total->formateado()),
        );

        return new HtmlString($avisos === []
            ? $html
            : $html.'<p class="olympo-nota">'.e(implode(' ', $avisos)).'</p>');
    }

    /**
     * El reparto de UN lote: un renglón por cuota que toca, y lo que sobra.
     *
     * Lo que sobra no se tira: es el aviso de que el monto supera lo que ese
     * lote debe, y el Service lo va a rechazar. Verlo antes de apretar Cobrar
     * es la diferencia entre corregir un número y explicarle a un cliente por
     * qué no se le pudo cobrar.
     *
     * @return array{filas: string, sobra: Monto}
     */
    private function repartoDeUnLote(Compromiso $lote, Monto $monto): array
    {
        $porRepartir = $monto;
        $filas = '';

        foreach ($this->pendientesDe($lote) as $cuota) {
            if ($porRepartir->esCero()) {
                break;
            }

            $falta = $cuota->saldo();
            $leToca = $porRepartir->mayorQue($falta) ? $falta : $porRepartir;
            $queda = $falta->restar($leToca);

            $filas .= sprintf(
                '<li><span class="meses">Cuota %d — vence %s%s</span><span class="monto">%s</span></li>',
                (int) $cuota->getAttribute('numero'),
                e($cuota->getAttribute('fecha_vencimiento')?->format('d/m/Y') ?? '—'),
                $queda->esCero() ? '' : e(sprintf(' · le quedan %s', $queda->formateado())),
                e($leToca->formateado()),
            );

            $porRepartir = $porRepartir->restar($leToca);
        }

        return ['filas' => $filas, 'sobra' => $porRepartir];
    }

    /**
     * El efecto de CADA lote marcado, con el mismo objeto que después persiste.
     *
     * §10.8 manda mostrarlo antes de confirmar, y con varios lotes hace falta
     * además el TOTAL: es el número que el cliente va a contar sobre el
     * mostrador, y sumar dos abonos de cabeza con alguien esperando es como se
     * equivoca.
     */
    private function efectoDeCadaLote(Get $get): HtmlString
    {
        $marcados = [];

        foreach ($this->lotesQueDeben() as $lote) {
            $id = (int) $lote->getKey();

            if ($get("abonar_{$id}") !== true) {
                continue;
            }

            $monto = $this->montoTecleado($get, "abono_{$id}");
            $elegida = $get("modalidad_{$id}");
            $modalidad = is_string($elegida) ? ModalidadDeReprogramacion::tryFrom($elegida) : null;

            if ($monto instanceof Monto && $modalidad instanceof ModalidadDeReprogramacion) {
                $marcados[] = ['lote' => $lote, 'monto' => $monto, 'modalidad' => $modalidad];
            }
        }

        if ($marcados === []) {
            return new HtmlString('<p class="olympo-vacio">Marcá al menos un lote, escribí el monto y decidí qué pasa con la cuota.</p>');
        }

        $html = '';
        $total = Monto::cero();

        foreach ($marcados as $marcado) {
            $html .= '<p class="olympo-lote">'.e((string) $marcado['lote']->lote?->getAttribute('codigo')).'</p>'
                .$this->efectoDeUnLote($marcado['lote'], $marcado['monto'], $marcado['modalidad']);

            $total = $total->sumar($marcado['monto']);
        }

        // Con un solo lote el total repetiría el monto que se acaba de teclear.
        if (count($marcados) > 1) {
            $html .= sprintf(
                '<div class="olympo-total"><span>Total a recibir</span><span>%s</span></div>',
                e($total->formateado()),
            );
        }

        return new HtmlString($html);
    }

    /**
     * El antes y el después de UN lote, con el MISMO objeto que después
     * persiste el Service.
     */
    private function efectoDeUnLote(Compromiso $lote, Monto $abono, ModalidadDeReprogramacion $modalidad): string
    {
        $pendientes = $this->pendientesDe($lote);

        if ($pendientes->isEmpty()) {
            return '<p class="olympo-nota">Este lote no debe nada.</p>';
        }

        $efecto = EfectoDelAbono::calcular(
            $pendientes,
            $abono,
            $modalidad,
            (int) $this->venta->getAttribute('dia_pago'),
        );

        if ($abono->mayorQue($efecto->saldoDelLote)) {
            return '<p class="olympo-nota">El abono supera lo que debe el lote ('
                .e($efecto->saldoDelLote->formateado()).'). Se va a rechazar, y con él los otros lotes: '
                .'un recibo se emite entero o no se emite.</p>';
        }

        if ($efecto->esPagoNormal) {
            return '<p class="olympo-nota">No alcanza para poner al día lo vencido ('
                .e($efecto->ponerAlDia->formateado()).'), así que en este lote <strong>no hay '
                .'reprogramación</strong>: se registra como un pago normal y su plan queda como está.</p>';
        }

        if ($efecto->superaElTope) {
            return '<p class="olympo-nota">Acá se puede abonar hasta '
                .e($efecto->tope->formateado()).'. La diferencia es lo que le falta a una cuota que ya está '
                .'pagada a medias, y esa cuota no se toca. Con <strong>«Ambas»</strong> se cobra ese resto y el '
                .'sobrante baja el capital, en un solo recibo.</p>';
        }

        if ($efecto->problema !== null) {
            return '<p class="olympo-nota">'.e($efecto->problema).'</p>';
        }

        return $this->repartoDelAbono($efecto)
            .$this->antesYDespues($efecto)
            .$this->notaDelAbono($efecto);
    }

    /**
     * El modo «Ambas»: dónde cae la raya entre las cuotas y el capital.
     *
     * Es la previsualización que más trabajo hace, porque el número que el
     * cliente mira —cuánto le baja el capital— no está tecleado en ningún lado:
     * sale de restarle a lo que entregó las cuotas que se marcaron.
     *
     * ⚠️ La mora va en cero, igual que en las otras previsualizaciones de esta
     * pantalla: se calcula adentro de la transacción, con las cuotas bloqueadas
     * y a la fecha del pago. Con R2 (Praderas no cobra mora) los dos números
     * son el mismo.
     */
    private function efectoDeAmbas(Get $get): HtmlString
    {
        $lote = Compromiso::query()->whereKey($get('compromiso_id'))->first();
        $total = $this->montoTecleado($get, 'monto_total');
        $elegida = $get('modalidad');
        $modalidad = is_string($elegida) ? ModalidadDeReprogramacion::tryFrom($elegida) : null;

        if (! $lote instanceof Compromiso || ! $total instanceof Monto || ! $modalidad instanceof ModalidadDeReprogramacion) {
            return new HtmlString('<p class="olympo-vacio">Escribí el total recibido, marcá las cuotas y elegí a qué lote va el sobrante.</p>');
        }

        $enCuotas = Monto::cero();
        $suCuota = Monto::cero();

        foreach ($this->lotesQueDeben() as $renglon) {
            $id = (int) $renglon->getKey();

            if ($get("cobrar_{$id}") !== true) {
                continue;
            }

            $monto = $this->montoTecleado($get, "monto_{$id}");

            if (! $monto instanceof Monto) {
                continue;
            }

            $enCuotas = $enCuotas->sumar($monto);

            // Lo que se le cobra AL LOTE DEL ABONO, que es lo que cambia su plan
            // antes de que el sobrante lo toque.
            if ($id === (int) $lote->getKey()) {
                $suCuota = $monto;
            }
        }

        if (! $total->mayorQue($enCuotas)) {
            return new HtmlString('<p class="olympo-nota">Las cuotas marcadas suman '
                .e($enCuotas->formateado()).' y el total recibido es '.e($total->formateado())
                .': no sobra nada para bajar capital. Cobralo por <strong>«Cuota»</strong>, '
                .'que hace exactamente lo mismo sin tocar el plan.</p>');
        }

        $sobrante = $total->restar($enCuotas);
        $codigo = (string) $lote->lote?->getAttribute('codigo');

        $raya = sprintf(
            '<ul class="olympo-escalera">'
            .'<li><span class="meses">A cuotas</span><span class="monto">%s</span></li>'
            .'<li><span class="meses">Baja el capital de %s</span><span class="monto">%s</span></li>'
            .'</ul>',
            e($enCuotas->formateado()),
            e($codigo),
            e($sobrante->formateado()),
        );

        $proyectadas = $this->comoQuedanTrasCobrar($lote, $suCuota);

        if ($proyectadas === []) {
            return new HtmlString($raya.'<p class="olympo-nota">Con las cuotas marcadas, el lote '.e($codigo)
                .' queda sin pendientes: no hay plan que reescribir y el sobrante se va a rechazar.</p>');
        }

        $efecto = EfectoDelAbono::calcular(
            $proyectadas,
            $sobrante,
            $modalidad,
            (int) $this->venta->getAttribute('dia_pago'),
        );

        if ($sobrante->mayorQue($efecto->saldoDelLote)) {
            return new HtmlString($raya.'<p class="olympo-nota">El sobrante supera lo que le quedaría debiendo el lote ('
                .e($efecto->saldoDelLote->formateado()).'). Se va a rechazar.</p>');
        }

        if ($efecto->esPagoNormal) {
            return new HtmlString($raya.'<p class="olympo-nota">Al lote '.e($codigo).' le quedarían '
                .e($efecto->ponerAlDia->formateado()).' vencidos y el sobrante no los cubre, así que '
                .'<strong>no bajaría capital</strong>. Sumale esa diferencia a la cuota de ese lote.</p>');
        }

        if ($efecto->superaElTope) {
            return new HtmlString($raya.'<p class="olympo-nota">Sobre ese lote se puede abonar hasta '
                .e($efecto->tope->formateado()).'. La diferencia es lo que le falta a una cuota pagada a '
                .'medias: marcala arriba para que entre en el cobro.</p>');
        }

        if ($efecto->problema !== null) {
            return new HtmlString($raya.'<p class="olympo-nota">'.e($efecto->problema).'</p>');
        }

        return new HtmlString($raya.$this->antesYDespues($efecto).$this->notaDelAbono($efecto));
    }

    /**
     * Las cuotas del lote como van a quedar DESPUES de cobrarle su renglón.
     *
     * ═══ POR QUE COPIAS EN MEMORIA ═══
     *
     * El efecto del abono depende del estado POSTERIOR al cobro —esa es la
     * razón de ser del modo «Ambas»: la cuota a medias se salda primero y recién
     * ahí el lote es reprogramable—. Para mostrarlo antes de confirmar hay que
     * proyectar ese estado sin escribir nada.
     *
     * Se clona cada cuota y se le suma lo que el FIFO le va a aplicar.
     * `EfectoDelAbono` no nota la diferencia: lee `saldo()` y `montoPagado()`, y
     * los dos salen de atributos. Nada de esto toca la base — `clone` de un
     * modelo Eloquent no lo persiste, y no se llama a `save()` en ningún lado.
     *
     * @return list<Cuota>
     */
    private function comoQuedanTrasCobrar(Compromiso $lote, Monto $cobrado): array
    {
        $porRepartir = $cobrado;
        $proyectadas = [];

        foreach ($this->pendientesDe($lote) as $cuota) {
            $falta = $cuota->saldo();
            $leToca = $porRepartir->mayorQue($falta) ? $falta : $porRepartir;
            $porRepartir = $porRepartir->restar($leToca);

            // Saldada por el cobro: deja de ser pendiente y sale del abono.
            if ($falta->restar($leToca)->esCero()) {
                continue;
            }

            $copia = clone $cuota;
            $copia->setAttribute('monto_pagado', $cuota->montoPagado()->sumar($leToca)->redondeado());

            $proyectadas[] = $copia;
        }

        return $proyectadas;
    }

    /**
     * En qué se divide el dinero: lo que pone al día y lo que baja el capital.
     */
    private function repartoDelAbono(EfectoDelAbono $efecto): string
    {
        $filas = '';

        if ($efecto->aplicaciones !== []) {
            $numeros = array_map(
                static fn (array $fila): string => (string) $fila['numero'],
                $efecto->aplicaciones,
            );

            $filas .= sprintf(
                '<li><span class="meses">Pone al día — %s %s</span><span class="monto">%s</span></li>',
                count($numeros) === 1 ? 'cuota' : 'cuotas',
                e(implode(', ', $numeros)),
                e($efecto->ponerAlDia->formateado()),
            );
        }

        $filas .= sprintf(
            '<li><span class="meses">Baja el capital</span><span class="monto">%s</span></li>',
            e($efecto->aCapital->formateado()),
        );

        return '<ul class="olympo-escalera">'.$filas.'</ul>';
    }

    /**
     * Las dos columnas que el cliente compara para decidir.
     */
    private function antesYDespues(EfectoDelAbono $efecto): string
    {
        $plan = $efecto->planNuevo;
        $antes = count($efecto->numerosReemplazados);
        $despues = $plan?->count() ?? 0;

        $cuotaNueva = $efecto->cuotaNueva();
        /*
         * Indexada, no con `end()`: esa funcion recibe el arreglo POR
         * REFERENCIA y `planAnterior` es una propiedad readonly, asi que
         * pasarsela tira «Cannot modify readonly property» en pleno modal.
         */
        $ultimaAntes = $efecto->planAnterior === []
            ? null
            : $efecto->planAnterior[count($efecto->planAnterior) - 1]['vence'];
        $ultimaDespues = $plan?->ultima()?->vencimiento;

        $renglon = static fn (string $que, string $antes, string $despues): string => sprintf(
            '<tr><td>%s</td><td class="apagado">%s</td><td class="fuerte">%s</td></tr>',
            e($que),
            e($antes),
            e($despues),
        );

        $filas = $renglon(
            'Saldo por reprogramar',
            $efecto->saldoReprogramable->formateado(),
            $efecto->saldoNuevo->formateado(),
        );

        $filas .= $renglon(
            'Cuota',
            $efecto->cuotaVigente?->formateado() ?? '—',
            $cuotaNueva?->formateado() ?? '—',
        );

        $filas .= $renglon(
            'Cuotas que faltan',
            (string) $antes,
            (string) $despues,
        );

        $filas .= $renglon(
            'Termina de pagar',
            is_string($ultimaAntes) ? CarbonImmutable::parse($ultimaAntes)->format('m/Y') : '—',
            $ultimaDespues?->format('m/Y') ?? '—',
        );

        return '<table class="olympo-tabla"><thead><tr><th></th><th>Hoy</th><th>Después del abono</th></tr></thead>'
            .'<tbody>'.$filas.'</tbody></table>';
    }

    private function notaDelAbono(EfectoDelAbono $efecto): string
    {
        if ($efecto->cancelaElPlan()) {
            return '<p class="olympo-nota">Con este abono el lote queda sin cuotas pendientes.</p>';
        }

        $meses = $efecto->mesesAhorrados();

        if ($meses > 0) {
            return sprintf(
                '<p class="olympo-nota">Sigue pagando lo mismo cada mes y termina %d %s antes.</p>',
                $meses,
                $meses === 1 ? 'mes' : 'meses',
            );
        }

        return '<p class="olympo-nota">Termina el mismo mes que tenía pactado, pagando menos cada mes.</p>';
    }

    // ─── Los lotes y sus saldos ───────────────────────────────────────

    /**
     * Los lotes del contrato que todavía deben, con cuánto.
     *
     * @return array<int, string>
     */
    private function lotesConSaldo(): array
    {
        $opciones = [];

        foreach ($this->venta->compromisos as $renglon) {
            $saldo = $this->saldoDe($renglon);

            if ($saldo->esCero()) {
                continue;
            }

            $opciones[(int) $renglon->getKey()] = sprintf(
                '%s — debe %s',
                (string) $renglon->lote?->getAttribute('codigo'),
                $saldo->formateado(),
            );
        }

        return $opciones;
    }

    /**
     * Los lotes del contrato que todavía deben algo, como objetos.
     *
     * `lotesConSaldo()` devuelve lo mismo armado para el Select del abono;
     * esto devuelve los renglones, que es lo que necesita un cobro de varios.
     *
     * @return list<Compromiso>
     */
    private function lotesQueDeben(): array
    {
        $lotes = [];

        foreach ($this->venta->compromisos as $renglon) {
            if (! $this->saldoDe($renglon)->esCero()) {
                $lotes[] = $renglon;
            }
        }

        return $lotes;
    }

    private function primerLoteConSaldo(): ?Compromiso
    {
        foreach ($this->venta->compromisos as $renglon) {
            if (! $this->saldoDe($renglon)->esCero()) {
                return $renglon;
            }
        }

        return null;
    }

    /**
     * Lo que le toca a este lote este mes.
     *
     * Es lo que le FALTA a su cuota pendiente más vieja, no el monto de la
     * cuota: si ya quedó pagada a medias, lo que se cobra es el resto (R19).
     */
    private function cuotaSugerida(Compromiso $lote): ?Monto
    {
        $primera = $this->pendientesDe($lote)->first();

        return $primera instanceof Cuota ? $primera->saldo() : null;
    }

    /**
     * Los renglones marcados, en el formato que pide el Service.
     *
     * El monto se valida con un `preg_match` antes de construir el `Monto`: la
     * validación del formulario ya lo impide, pero el borde del dinero no se
     * confía de la pantalla. Un «0» pasa a propósito — que lo rechace el
     * dominio con su mensaje, y no un silencio acá.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{lote: Compromiso, monto: Monto}>
     */
    private function renglonesTecleados(array $data): array
    {
        $renglones = [];

        foreach ($this->lotesQueDeben() as $lote) {
            $id = (int) $lote->getKey();

            if (($data["cobrar_{$id}"] ?? false) !== true) {
                continue;
            }

            $crudo = $data["monto_{$id}"] ?? null;
            $texto = is_string($crudo) ? trim($crudo) : '';

            if (preg_match('/^\d+(\.\d{1,2})?$/', $texto) !== 1) {
                continue;
            }

            $renglones[] = ['lote' => $lote, 'monto' => new Monto($texto)];
        }

        return $renglones;
    }

    /**
     * Las cuotas del lote que todavía deben algo, en el mismo orden que el
     * FIFO del Service.
     *
     * @return Cuotas<int, Cuota>
     */
    private function pendientesDe(Compromiso $lote): Cuotas
    {
        return Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->whereColumn('monto_pagado', '<', 'monto')
            ->orderBy('numero')
            ->get();
    }

    private function saldoDe(Compromiso $renglon): Monto
    {
        $saldo = Monto::cero();

        foreach ($renglon->cuotas()->get() as $cuota) {
            $saldo = $saldo->sumar($cuota->saldo());
        }

        return $saldo;
    }

    /**
     * El monto del formulario, o null si todavía no hay uno usable.
     *
     * ═══ POR QUE SE VALIDA EL FORMATO ACA ═══
     *
     * `Monto` rechaza todo lo que no sea un decimal en notación simple —con
     * razón, es el borde del dinero— y el campo es `live`, así que mientras el
     * usuario teclea llega «1,500», «12.» o vacío ANTES de que corra la
     * validación del formulario. Construir un Monto con eso revienta el modal
     * con el cliente enfrente. Acá no hay nada que avisar: todavía no terminó
     * de escribir.
     */
    private function montoTecleado(Get $get, string $campo = 'monto'): ?Monto
    {
        $monto = $get($campo);

        if (! is_string($monto) || preg_match('/^\d+(\.\d{1,2})?$/', trim($monto)) !== 1) {
            return null;
        }

        $valor = new Monto(trim($monto));

        return $valor->esCero() ? null : $valor;
    }

    // ─── Opciones y avisos ────────────────────────────────────────────

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
     * @return array<string, string>
     */
    private function modalidades(): array
    {
        $opciones = [];

        foreach (ModalidadDeReprogramacion::cases() as $modalidad) {
            $opciones[$modalidad->value] = $modalidad->etiqueta().' — '.$modalidad->explicacion();
        }

        return $opciones;
    }

    private function exigeReferencia(Get $get): bool
    {
        $forma = $get('forma_pago');

        return is_string($forma)
            && FormaDePago::tryFrom($forma)?->exigeReferencia() === true;
    }

    /**
     * El mensaje del dominio ya está escrito para quien atiende. Lo que NO
     * puede pasar es una pantalla de error 500 con el cliente enfrente.
     */
    private function avisarDelError(GrupoOlympoException $error): void
    {
        Notification::make()
            ->title('No se registró el movimiento')
            ->body($error->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * El botón de imprimir va en la notificación y no solo en la lista: el
     * flujo de ventanilla es cobrar y entregar el papel, y hacer que quien
     * atiende vaya a buscarlo a otra pantalla es la forma más segura de que el
     * cliente se vaya sin recibo.
     *
     * Persistente por lo mismo: una notificación que se desvanece a los cinco
     * segundos se lleva el botón con ella.
     */
    private function avisarDelCobro(Recibo $recibo): void
    {
        $cuotas = $recibo->aplicaciones()->count();
        $lotes = count($recibo->codigosDeLotes());

        Notification::make()
            ->title("Recibo {$recibo->folio()}")
            ->body(sprintf(
                '%s aplicados a %d %s de %d %s.',
                $recibo->montoTotal()->formateado(),
                $cuotas,
                $cuotas === 1 ? 'cuota' : 'cuotas',
                $lotes,
                $lotes === 1 ? 'lote' : 'lotes',
            ))
            ->success()
            ->persistent()
            ->actions([ImprimirRecibo::enNotificacion($recibo)])
            ->send();
    }

    /**
     * Un abono puede terminar de tres formas, y desde el 10-ago puede tocar
     * varios lotes a la vez.
     *
     * En «Ambas» se antepone cuánto se fue en cuotas: el cliente entregó UN
     * monto y el papel dice dos números, así que la notificación tiene que
     * decir los mismos dos o el recibo parece no cuadrar.
     */
    private function avisarDelAbono(Recibo $recibo, bool $conCuotas = false): void
    {
        $constancias = $recibo->reprogramaciones()->get();

        /*
         * Ninguna: a ningún lote le alcanzó para bajar capital. El dinero se
         * registró igual —ya estaba sobre el mostrador— y quien atiende tiene
         * que enterarse de que el plan quedó como estaba.
         */
        if ($constancias->isEmpty()) {
            Notification::make()
                ->title("Recibo {$recibo->folio()}")
                ->body(sprintf(
                    '%s aplicados a las cuotas vencidas. NO hubo reprogramación: el abono no alcanzó '.
                    'a bajar el capital, así que el plan quedó como estaba.',
                    $recibo->montoTotal()->formateado(),
                ))
                ->warning()
                ->persistent()
                ->actions([ImprimirRecibo::enNotificacion($recibo)])
                ->send();

            return;
        }

        $prefijo = $conCuotas
            ? sprintf(
                'De %s, %s terminaron de pagar cuotas. ',
                $recibo->montoTotal()->formateado(),
                $recibo->montoTotal()->restar($this->totalAbonado($constancias))->formateado(),
            )
            : '';

        if ($constancias->count() === 1) {
            Notification::make()
                ->title("Recibo {$recibo->folio()}")
                ->body($prefijo.$this->resumenDe($constancias->firstOrFail()))
                ->success()
                ->persistent()
                ->actions([ImprimirRecibo::enNotificacion($recibo)])
                ->send();

            return;
        }

        /*
         * Varios lotes: el encabezado dice el total —que es lo que el cliente
         * entregó— y después va un renglón por lote. Sin el total, quien
         * atiende tendría que sumar de cabeza para saber si el papel cuadra
         * con el billete.
         */
        $renglones = [];

        foreach ($constancias as $constancia) {
            $renglones[] = sprintf(
                '%s: %s',
                $this->codigoDe($constancia),
                $this->resumenDe($constancia),
            );
        }

        Notification::make()
            ->title("Recibo {$recibo->folio()}")
            ->body(sprintf(
                '%s%s repartidos en %d lotes. %s',
                $prefijo,
                $recibo->montoTotal()->formateado(),
                $constancias->count(),
                implode(' ', $renglones),
            ))
            ->success()
            ->persistent()
            ->actions([ImprimirRecibo::enNotificacion($recibo)])
            ->send();
    }

    /**
     * Lo que le pasó a UN lote, en una oración.
     */
    private function resumenDe(Reprogramacion $constancia): string
    {
        if ($constancia->cancelaElLote()) {
            return 'quedó sin cuotas pendientes.';
        }

        $meses = $constancia->mesesAhorrados();

        if ($meses > 0) {
            return sprintf(
                'abonados %s a capital, termina %d %s antes con la misma cuota de %s.',
                $constancia->montoAbonado()->formateado(),
                $meses,
                $meses === 1 ? 'mes' : 'meses',
                $constancia->montoCuotaNueva()?->formateado() ?? '—',
            );
        }

        return sprintf(
            'abonados %s a capital, la cuota baja de %s a %s con los mismos meses.',
            $constancia->montoAbonado()->formateado(),
            $constancia->montoCuotaAnterior()?->formateado() ?? '—',
            $constancia->montoCuotaNueva()?->formateado() ?? '—',
        );
    }

    /**
     * `iterable` y no la colección tipada: el archivo ya importa
     * `Eloquent\Collection` bajo el alias `Cuotas`, y traerla una segunda vez
     * con otro nombre para el mismo tipo se lee peor de lo que aclara.
     *
     * @param iterable<Reprogramacion> $constancias
     */
    private function totalAbonado(iterable $constancias): Monto
    {
        $total = Monto::cero();

        foreach ($constancias as $constancia) {
            $total = $total->sumar($constancia->montoAbonado());
        }

        return $total;
    }

    private function codigoDe(Reprogramacion $constancia): string
    {
        return (string) $constancia->compromiso?->lote?->getAttribute('codigo');
    }
}
