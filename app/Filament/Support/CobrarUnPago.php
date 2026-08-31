<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Facturacion\ConsumoDeFacturas;
use App\Domain\Facturacion\EstadoDelTalonario;
use App\Domain\Pagos\EfectoDelAbono;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Facturacion;
use App\Models\Proyecto;
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
use Illuminate\Support\Collection;
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
 * ═══ UNA SOLA PUERTA, DESDE EL 14-AGO-2026 ═══
 *
 * `accion()` — nombre `cobrar`. Abre en «Cuota» y va en la tabla, en la ficha
 * del cliente y en el expediente. **Es la única.**
 *
 * Hasta hoy habia una segunda, `abonoDirecto()`, que abria el MISMO modal en
 * «Abono» y tenia su propio boton en el expediente. Lo saco Mauricio: «solo
 * deberia estar el boton de registrar pago, ya que al presionarlo se puede
 * hacer lo de abonar a capital». Tiene razon — dos puertas a la misma pantalla
 * obligan a decidir ANTES de entrar algo que se decide adentro, y quien
 * atiende duda si son dos tramites distintos.
 *
 * ⚠️ Sacar el boton NO aflojo el permiso de R21. La frontera nunca estuvo en
 * el boton: esta en `modos()`, que solo ofrece «Abono» y «Ambas» a quien
 * `puedeReprogramar()`. El receptor abre el mismo modal y ve una sola opcion.
 *
 * 🔴 Los tests que probaban esa frontera miraban si el BOTON estaba dibujado.
 * Ahora preguntan por `seLePermiteAbonar()`, que es donde el permiso vive de
 * verdad: un test que mira un boton pasa igual el dia que el boton se dibuja
 * bien y el permiso de adentro se rompe.
 *
 * 🔴 Y el 23-ago-2026 cayo tambien el del PLANO, por lo mismo y a pedido de
 * Mauricio: «solo deberia mostrar registrar un pago ya que al presionarlo se
 * puede hacer abono a capital». Cuando este comentario se escribio, el acceso
 * directo del panel se ganaba su lugar; despues el modal estreno el toggle de
 * cuatro modos y el atajo paso a ser la misma puerta con una opcion MENOS.
 * Un boton que existia por una razon puede dejar de tenerla sin que nadie lo
 * mueva: lo que cambio fue el modal, no el boton.
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
     * Las MISMAS dos acciones, pero abiertas desde el plano.
     *
     * Lo pidió Mauricio el 13-ago-2026: «cuando ya esté vendido, que
     * aparezca para pagar la cuota desde acá, o abonar a capital; así se
     * maneja mejor todo desde acá». Es el mismo argumento que ya justificó
     * vender desde el plano: quien cobra abre el plano, no la lista de
     * ventas, y hacerlo navegar hasta el expediente le hace perder de
     * vista el lote que tenía en pantalla.
     *
     * ⚠️ NO ES OTRO MODAL. `campos()`, `valoresIniciales()` y `registrar()`
     * son exactamente los mismos que usan la tabla de Ventas y el
     * expediente; lo único distinto es de dónde sale la venta. Copiar el
     * modal habría dejado TRES pantallas que tienen que decir lo mismo, y
     * este archivo existe justamente porque ya eran dos y se separaron.
     *
     * Lo que cambia de dónde sale la venta: allá Filament inyecta el
     * `$record`, que YA es una Venta. Acá el record es el proyecto y el
     * lote llega en `$arguments`, así que hay que subir del lote a su
     * compromiso vigente y de ahí a la venta.
     */
    public static function desdeElPlano(): Action
    {
        return self::modalDelPlano('cobrarDesdeElPlano', ModoDeCobro::Cuota)
            ->label('Registrar un pago')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success');
    }

    /**
     * ¿Este usuario puede abonar a capital? La pregunta de R21, para afuera.
     *
     * La expone el plano para no dibujar un botón que después va a rebotar.
     * El borde de verdad sigue estando adentro de `->action()`.
     */
    public static function seLePermiteAbonar(): bool
    {
        return self::puedeReprogramar();
    }

    /**
     * El modal del plano: el de siempre, con la venta resuelta desde el lote.
     */
    private static function modalDelPlano(string $nombre, ModoDeCobro $porDefecto): Action
    {
        return Action::make($nombre)
            ->modalHeading('Registrar un pago')
            ->modalSubmitActionLabel('Registrar y emitir el recibo')
            ->modalWidth('2xl')
            ->fillForm(static function (array $arguments) use ($porDefecto): array {
                $venta = self::ventaDelLote($arguments);

                return $venta instanceof Venta ? new self($venta)->valoresIniciales($porDefecto) : [];
            })
            ->schema(static function (array $arguments): array {
                $venta = self::ventaDelLote($arguments);

                if (! $venta instanceof Venta) {
                    return [];
                }

                return [...self::avisoDelPapel($venta), ...self::avisoDelTalonario($venta), ...self::avisoDelContrato($venta), ...new self($venta)->campos()];
            })
            ->action(static function (array $arguments, array $data) use ($porDefecto): void {
                $venta = self::ventaDelLote($arguments);

                if (! $venta instanceof Venta) {
                    Notification::make()
                        ->title('No se encontró el contrato de ese lote')
                        ->body('El lote no tiene una venta vigente detrás. Abrí su expediente para ver qué pasó.')
                        ->danger()
                        ->send();

                    return;
                }

                // El borde de R21, acá y no solo en el boton.
                if ($porDefecto === ModoDeCobro::Abono && ! self::puedeReprogramar()) {
                    Notification::make()
                        ->title('No se registró el movimiento')
                        ->body('Abonar a capital reprograma el plan de cuotas y eso lo hace la administradora.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                new self($venta)->registrar($data);
            });
    }

    /**
     * Del lote que se tocó en el plano a la venta que lo lleva.
     *
     * Se sube por el compromiso VIGENTE, que es el camino que el repo ya
     * usa —`RegistroDeCompromisos::vigenteDe()`—: no hay relación directa
     * de lote a venta, y no la hay a propósito, porque un lote pasa por
     * varios compromisos a lo largo de su vida y solo uno está abierto.
     *
     * Devuelve null cuando no hay nada que cobrar: un lote libre, uno
     * apartado —el apartado no tiene venta— o uno donado.
     *
     * @param array<string, mixed> $arguments
     */
    private static function ventaDelLote(array $arguments): ?Venta
    {
        $lote = $arguments['lote'] ?? null;

        if (! is_int($lote) && ! is_string($lote)) {
            return null;
        }

        $venta = Compromiso::query()
            ->where('lote_id', '=', (int) $lote)
            ->vigentes()
            ->with('venta')
            ->first()?->getRelationValue('venta');

        return $venta instanceof Venta && self::seLePuedeCobrar($venta) ? $venta : null;
    }

    /**
     * 🔴 QUE PAPEL VA A SALIR, DICHO ANTES DE COBRAR.
     *
     * ═══ DE DONDE SALIO ESTO ═══
     *
     * Del ensayo en pantalla del 14-ago-2026. Se cobro una cuota de un
     * desarrollo que factura con CAI y el papel salio como **recibo interno**,
     * sin factura y sin un solo mensaje. El correlativo del SAR ni se consumio
     * ni se salteo: la emision simplemente no ocurrio.
     *
     * Eso es peor que un error. Un error se ve; esto le entrega al cliente el
     * documento equivocado y nadie se entera hasta que lo pregunta el SAR.
     * `ConsumoDeFacturas` tiene tres salidas que devuelven null en silencio
     * —proyecto nulo, facturacion nula, facturacion apagada— y son correctas
     * como comportamiento, pero **invisibles** en el momento en que importan.
     *
     * ═══ POR QUE UN RENGLON QUE SIEMPRE ESTA ═══
     *
     * Contra la regla de que un aviso permanente se deja de leer: esto **no
     * es un aviso, es un dato del formulario**, como «Total a cobrar». Quien
     * cobra tiene que saber que va a imprimir antes de apretar el boton, no
     * despues de entregarlo.
     *
     * Y el tercer caso es el que justifica todo: cuando el desarrollo TIENE
     * facturacion configurada pero hoy no puede emitir, el sistema se
     * contradice a si mismo — ahi el renglon se pone rojo y lo dice.
     *
     * ⚠️ Pregunta con `puedeEmitir()`, que NO consume correlativo. Llamar a
     * `ConsumoDeFacturas` para previsualizar quemaria un numero del SAR cada
     * vez que alguien abre el modal y lo cierra.
     *
     * @return list<Component>
     */
    private static function avisoDelPapel(Venta $venta): array
    {
        /*
         * 🔴 Por `facturacionDe()` y NO por `$venta->proyecto?->facturacion`.
         * Es exactamente el error que este aviso existe para hacer visible: el
         * modelo que llega de una tabla puede venir con columnas de menos, y
         * preguntarle a él daria «recibo interno» sin contradiccion aparente
         * —justo el silencio que hay que romper—. El aviso tiene que ver lo
         * MISMO que va a ver el dominio al emitir, o miente con confianza.
         */
        $proyecto = $venta->proyecto;
        $facturacion = app(ConsumoDeFacturas::class)->facturacionDe($proyecto);
        $configurada = Proyecto::query()->whereKey($proyecto?->getKey())->value('facturacion_id') !== null;
        $puede = $facturacion instanceof Facturacion && $facturacion->puedeEmitir();

        if ($configurada && ! $puede) {
            return [
                Placeholder::make('papel')
                    ->label('⚠️ Ojo con el papel')
                    ->content(sprintf(
                        'Este desarrollo está configurado para facturar con CAI (%s), pero HOY no puede '.
                        'emitir: la facturación está apagada, o no tiene una autorización vigente. '.
                        'El papel va a salir como RECIBO INTERNO, sin valor fiscal. '.
                        'Revisá Administración → Facturación antes de cobrar.',
                        $facturacion?->getAttribute('nombre') ?? 'sin nombre',
                    ))
                    ->columnSpanFull(),
            ];
        }

        /*
         * 🔴 EL CASO NORMAL NO DICE NADA — pedido de Mauricio, 23-ago-2026:
         * «el proyecto ya se les entrega configurado, no hay necesidad de que
         * se los diga».
         *
         * Y tiene razón: en un desarrollo que no factura, «Recibo interno» sale
         * en TODOS los cobros, todos los días, y nunca cambia. Un renglón que
         * siempre dice lo mismo no informa: enseña a saltear ese pedazo de la
         * pantalla — y el día que ahí diga otra cosa, tampoco se va a leer.
         *
         * ⚠️ Lo que SÍ se queda son los dos casos en que el renglón dice algo
         * que quien cobra no puede adivinar, y los dos están arriba de esta
         * línea: el aviso de «configurado para facturar pero hoy no puede»
         * —que nació de un ensayo real el 14-ago, donde el papel salió interno
         * en silencio— y el de FACTURA con CAI, que además quema un
         * correlativo del SAR. Sacar los tres habría sido obedecer de más.
         */
        if (! $puede) {
            return [];
        }

        return [
            Placeholder::make('papel')
                ->label('Papel que sale')
                ->content(sprintf('FACTURA con CAI — %s', $facturacion?->getAttribute('nombre') ?? ''))
                ->columnSpanFull(),
        ];
    }

    /**
     * El aviso de que el talonario se acaba, en el momento del cobro.
     *
     * ═══ POR QUE TAMBIEN ACA, Y NO SOLO EN EL ESCRITORIO ═══
     *
     * Porque este es el unico momento en que la persona que puede hacer algo
     * al respecto esta mirando. El widget del Escritorio lo ve quien abre el
     * sistema de mañana; el que se queda con un cliente enfrente y sin poder
     * emitir el papel es quien esta cobrando ahora.
     *
     * Solo aparece si ESTE desarrollo factura y su talonario esta en
     * problemas. En Praderas —que emite recibo interno— no sale nunca.
     *
     * @return list<Component>
     */
    private static function avisoDelTalonario(Venta $venta): array
    {
        // Misma relectura que el aviso del papel, y por la misma razon.
        $facturacion = app(ConsumoDeFacturas::class)->facturacionDe($venta->proyecto);

        if (! $facturacion instanceof Facturacion) {
            return [];
        }

        $estado = EstadoDelTalonario::de($facturacion);

        if (! $estado->hayQueAvisar()) {
            return [];
        }

        return [
            Placeholder::make('talonario')
                ->label($estado->esUnParo() ? 'No se puede facturar' : 'El talonario se está acabando')
                ->content($estado->titular().'. '.$estado->detalle())
                ->columnSpanFull(),
        ];
    }

    /**
     * 🔴 «Este contrato lleva tres lotes», dicho antes de cobrar.
     *
     * El aviso que hace que esto se pueda abrir desde un lote sin mentir.
     * El recibo es del CONTRATO —un contrato de varios lotes se cobra en
     * uno solo, y por eso `Recibo::compromiso_id` queda vacío a
     * propósito—, así que quien entró haciendo clic en RPS-C-009 tiene que
     * enterarse de que el papel que va a imprimir también cubre C-010 y
     * C-011. Sin esta línea, el mismo gesto significa dos cosas distintas
     * según por dónde se haya entrado.
     *
     * En un contrato de un solo lote —la mayoría— no aparece nada: no hay
     * nada que aclarar y una advertencia de más se deja de leer.
     *
     * @return list<Component>
     */
    private static function avisoDelContrato(Venta $venta): array
    {
        $codigos = $venta->compromisos()
            ->with('lote')
            ->get()
            // Los rescindidos afuera: nombrarlos aca diria que el contrato
            // lleva tres lotes cuando ya lleva dos.
            ->reject(static fn (Compromiso $compromiso): bool => $compromiso->getAttribute('estado') === EstadoCompromiso::Rescindido)
            ->map(static fn (Compromiso $compromiso): string => (string) $compromiso->lote?->getAttribute('codigo'))
            ->filter()
            ->values()
            ->all();

        if (count($codigos) < 2) {
            return [];
        }

        return [
            Placeholder::make('lotes_del_contrato')
                ->label('Ojo: este contrato lleva '.count($codigos).' lotes')
                ->content(implode(' · ', $codigos).'. El recibo los cubre a todos.'),
        ];
    }

    /**
     * La única definición del modal. Las dos acciones de arriba son esto con
     * otra etiqueta, otro permiso y otro valor inicial del toggle.
     *
     * ═══ 🔴 LOS AVISOS FALTABAN ACA ═══
     *
     * Hasta el 14-ago-2026 este schema era solo `campos()`, y los avisos
     * —qué papel sale, el talonario que se acaba— vivían **únicamente en el
     * modal del plano**. O sea: no se veían ni desde la tabla de Ventas ni
     * desde el expediente, que es por donde se cobra todos los días.
     *
     * Lo agarró el ensayo en pantalla, no un test: un modal que se arma en
     * dos lugares distintos se separa solo, y el día que se separa uno de los
     * dos le miente a alguien. El aviso del CONTRATO sigue siendo solo del
     * plano, y eso sí es a propósito: acá el listado de lotes ya está a la
     * vista.
     */
    private static function modal(string $nombre, ModoDeCobro $porDefecto): Action
    {
        return Action::make($nombre)
            ->modalHeading('Registrar un pago')
            ->modalSubmitActionLabel('Registrar y emitir el recibo')
            ->modalWidth('2xl')
            ->fillForm(static fn (Venta $record): array => new self($record)->valoresIniciales($porDefecto))
            ->schema(static fn (Venta $record): array => [
                ...self::avisoDelPapel($record),
                ...self::avisoDelTalonario($record),
                ...new self($record)->campos(),
            ])
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

    /**
     * 🔴 La otra frontera, desde el 23-ago-2026: quién puede DESCONTAR.
     *
     * Aparte de `puedeReprogramar()` a propósito. Reprogramar reparte la misma
     * deuda de otra forma y no le cuesta un centavo a la lotificadora; un
     * pronto pago perdona saldo, sin tope. Son dos llaves y quien tiene una no
     * tiene por qué tener la otra.
     *
     * Se pregunta en dos momentos —para armar el toggle y antes de ejecutar—
     * y el segundo es el que protege.
     */
    private function puedeDarDescuento(): bool
    {
        return auth()->user()?->can('prontoPago', Venta::class) === true;
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
            /*
             * ⚠️ Acá y NO en un `->default()` del campo: la acción llena el
             * formulario con `fillForm()`, y ese arreglo ES el estado inicial —
             * los `default()` de cada campo no se aplican. Ya estaba anotado
             * unas líneas más abajo para la fecha; costó verlo en pantalla:
             * el campo salía vacío con un `default()` perfectamente escrito.
             */
            'recibido_por' => QuienRecibeElDinero::porDefecto(),
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

            /*
             * El pronto pago, por lo mismo que el abono: con un solo lote no
             * hay nada que elegir. El descuento sí nace vacío siempre — cuánto
             * se rebaja lo decide la lotificadora caso por caso, y proponer un
             * número sería el sistema sugiriendo cuánta plata regalar.
             */
            $datos["saldar_{$id}"] = count($lotes) === 1;
            $datos["descuento_{$id}"] = null;
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

            /*
             * ── Las cuotas: el mismo widget para «Cuota» y para «Ambas» ─
             *
             * ⚠️ Se nombran los DOS modos, en positivo. Estuvo escrito como
             * «distinto de Abono» hasta el 23-ago-2026, y el día que apareció
             * un cuarto modo esa condición lo incluyó sola: el pronto pago
             * abría con la sección de cuotas encima de la suya, marcada y con
             * montos. No rompía nada —el Service ignora esos campos— pero
             * pedía dos cosas a la vez y ninguna era la que se estaba
             * haciendo. Una lista negra crece sola con cada modo nuevo.
             */
            Section::make('¿Qué viene a pagar?')
                ->description('Puede ser menos que la cuota: lo que falte se arrastra, sin recargo (R2).')
                ->visible(fn (Get $get): bool => in_array(
                    $this->modoDeLaPantalla($get),
                    [ModoDeCobro::Cuota, ModoDeCobro::Ambas],
                    true,
                ))
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
                ->description('El monto de cada lote lo escribís vos: el sistema no reparte nada solo (R21). Un lote con cuotas vencidas no se puede abonar: primero se pone al día.')
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) === ModoDeCobro::Abono)
                ->schema($this->renglonesDeAbono()),

            /*
             * ── El pronto pago, desde el 23-ago-2026 ───────────────────
             *
             * Se marca el lote, se escribe CUANTO se le descontó, y abajo sale
             * lo que el cliente tiene que entregar. El monto no se teclea: es
             * el saldo menos el descuento, y hacer que quien atiende lo calcule
             * a mano con el cliente enfrente es pedir un error de mil lempiras.
             */
            Section::make('¿Qué lotes salda?')
                ->description('Cada lote marcado queda en cero. Escribí el descuento que se le dio; el resto lo entrega el cliente.')
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) === ModoDeCobro::ProntoPago)
                ->schema($this->renglonesDeProntoPago()),

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

            /*
             * ═══ QUIEN RECIBIO EL DINERO — 27-ago-2026 ═══
             *
             * «Que la administradora y yo podamos seleccionar quién recibió el
             * dinero, y también los receptores» — Mauricio. Hasta ese día el
             * sistema solo sabía quién TECLEÓ, y lo daba por lo mismo: la
             * administradora registra un pago que recibió don Elder en la
             * caseta, y el efectivo lo tiene él.
             *
             * 🔴 De esto sale el arqueo del día: el corte de caja cuenta por
             * quien recibió, no por quien tecleó.
             *
             * El campo se mudó a `QuienRecibeElDinero` el 31-ago, cuando la
             * misma pregunta entró en apartar y en vender: el dinero llega por
             * tres puertas y la pregunta tiene que ser una sola.
             */
            QuienRecibeElDinero::campo(),

            TextInput::make('referencia')
                ->label('Número de referencia')
                ->maxLength(60)
                ->visible(fn (Get $get): bool => $this->exigeReferencia($get))
                /*
                 * 🔴 Ya no es obligatorio (27-ago-2026). Llega una
                 * transferencia, el cliente está enfrente y el número todavía
                 * no lo tiene nadie: trabar el cobro ahí es peor que
                 * registrarlo sin la referencia. La ayuda sigue diciendo para
                 * qué sirve, y el CHECK de la base se fue con el freno.
                 */
                ->helperText('Es lo único que después permite cruzar este recibo contra el estado de cuenta del banco (R11). Si todavía no lo tenés, se puede dejar vacío.'),

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

            /*
             * §10.8 otra vez: el número que el cliente tiene que sacar de la
             * cartera, ANTES de confirmar. Es el único de todo el modal que se
             * dice en voz alta con el cliente enfrente.
             */
            Placeholder::make('lo_que_entrega')
                ->label('Cuánto tiene que entregar')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get) === ModoDeCobro::ProntoPago)
                ->content(fn (Get $get): HtmlString => $this->loQueEntrega($get)),

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
                ->placeholder(fn (Get $get): string => $this->modoDeLaPantalla($get)->perdonaSaldo()
                    ? 'Cliente de años, cancela y pidió rebaja'
                    : 'Abono a capital solicitado por el cliente')
                ->visible(fn (Get $get): bool => $this->modoDeLaPantalla($get)->exigeMotivo())
                ->helperText(fn (Get $get): string => $this->modoDeLaPantalla($get)->perdonaSaldo()
                    ? 'Queda con tu usuario y la fecha, en el expediente. Dentro de dos años va a ser lo único que conteste por qué a este cliente se le descontó.'
                    : 'Queda con tu usuario y la fecha. El mes que viene alguien va a preguntar por qué cambió el número (R21).'),

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

        /*
         * ⚠️ Sin `return` temprano desde el 23-ago-2026: son DOS permisos
         * independientes. Con la salida anticipada, quien tuviera
         * `ProntoPago:Venta` y no `Reprogramar:Venta` no vería su opción — un
         * permiso concedido que la pantalla se comía en silencio.
         */
        if (self::puedeReprogramar()) {
            $opciones[ModoDeCobro::Abono->value] = ModoDeCobro::Abono->etiqueta();
            $opciones[ModoDeCobro::Ambas->value] = ModoDeCobro::Ambas->etiqueta();
        }

        if ($this->puedeDarDescuento()) {
            $opciones[ModoDeCobro::ProntoPago->value] = ModoDeCobro::ProntoPago->etiqueta();
        }

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
                    ->helperText($this->lasVencidasDe($lote))
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
     * ═══ 🔴 EL LOTE ATRASADO NO TIENE CASILLA (24-ago-2026) ═══
     *
     * «Que no pueda hacer abono a capital si tiene cuotas pendientes okey»
     * —Mauricio—. En vez de dejar marcar y que el Service lo rechace con el
     * cliente enfrente, el renglón se cambia por el motivo: cuáles cuotas
     * están vencidas, cuánto suman, y por dónde entra esa plata.
     *
     * No es una casilla deshabilitada: una casilla gris invita a clickearla y
     * no explica nada. La regla de verdad vive en el Service —un campo se
     * falsifica, un botón se rehabilita— y esto es solo el aviso temprano.
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
            $atrasado = $this->porQueNoPuedeAbonar($lote);

            if ($atrasado instanceof HtmlString) {
                $renglones[] = Placeholder::make("sin_abono_{$id}")
                    ->hiddenLabel()
                    ->content($atrasado);

                continue;
            }

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
     * ⚠️ El lote atrasado se saltea acá TAMBIEN (24-ago-2026), aunque ya no
     * tenga casilla: este método recorre `lotesQueDeben()` —todos— y lee el
     * estado por nombre de campo, así que un `abonar_12` que quedó en el
     * formulario de antes entraría igual. Lo que se manda al Service es lo que
     * la pantalla mostró, ni un renglón más.
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

            if ($this->porQueNoPuedeAbonar($lote) instanceof HtmlString) {
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

    /**
     * Un renglón por lote que debe: la casilla y su descuento (23-ago-2026).
     *
     * ⚠️ NO hay campo de monto, y eso es lo que hace usable la pantalla: lo
     * que el cliente entrega es el saldo menos el descuento, y hacer que quien
     * atiende lo reste de cabeza con el cliente enfrente es pedir un error de
     * mil lempiras. El número sale abajo, en «Cuánto tiene que entregar».
     *
     * @return list<Component>
     */
    private function renglonesDeProntoPago(): array
    {
        $lotes = $this->lotesQueDeben();

        if ($lotes === []) {
            return [
                Placeholder::make('sin_saldo_pronto_pago')
                    ->hiddenLabel()
                    ->content('Este expediente no debe nada: no hay nada que saldar.'),
            ];
        }

        $renglones = [];

        foreach ($lotes as $lote) {
            $id = (int) $lote->getKey();

            $renglones[] = Grid::make(12)->schema([
                Checkbox::make("saldar_{$id}")
                    ->label(sprintf(
                        '%s — debe %s',
                        (string) $lote->lote?->getAttribute('codigo'),
                        $this->saldoDe($lote)->formateado(),
                    ))
                    ->live()
                    ->columnSpan(7),

                MontoField::make("descuento_{$id}", 'Descuento')
                    ->hiddenLabel()
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => $get("saldar_{$id}") === true)
                    ->helperText('Sin rebaja, dejalo en 0.')
                    ->columnSpan(5),
            ]);
        }

        return $renglones;
    }

    /**
     * Los lotes marcados del pronto pago, en el formato que pide el Service.
     *
     * ⚠️ Un descuento vacío es CERO, no «saltear este lote»: quien marcó la
     * casilla quiere saldarlo, con rebaja o sin ella. Es la diferencia con el
     * abono, donde un monto vacío sí es un renglón que no existe.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{lote: Compromiso, descuento: Monto}>
     */
    private function renglonesDeProntoPagoTecleados(array $data): array
    {
        $renglones = [];

        foreach ($this->lotesQueDeben() as $lote) {
            $id = (int) $lote->getKey();

            if (($data["saldar_{$id}"] ?? false) !== true) {
                continue;
            }

            $crudo = $data["descuento_{$id}"] ?? null;
            $texto = is_string($crudo) ? trim($crudo) : '';

            // El mismo `preg_match` que el abono: el borde del dinero no se
            // confía de la pantalla, aunque la validación ya lo impida.
            $renglones[] = [
                'lote'      => $lote,
                'descuento' => preg_match('/^\d+(\.\d{1,2})?$/', $texto) === 1
                    ? new Monto($texto)
                    : Monto::cero(),
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

        /*
         * 🔴 El mismo borde, para la otra llave (23-ago-2026). Un descuento es
         * plata que la lotificadora deja de cobrar, y `modo` sigue siendo un
         * campo del formulario.
         */
        if ($modo->perdonaSaldo() && ! $this->puedeDarDescuento()) {
            Notification::make()
                ->title('No se registró el movimiento')
                ->body('Dar un descuento por pronto pago es de la administración. Este pago se puede registrar como cuota.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        try {
            $recibos = match ($modo) {
                ModoDeCobro::Cuota      => $this->soloLaCuota($data),
                ModoDeCobro::Abono      => $this->soloElAbono($data),
                ModoDeCobro::Ambas      => $this->laCuotaYElAbono($data),
                ModoDeCobro::ProntoPago => $this->soloElProntoPago($data),
            };
        } catch (GrupoOlympoException $error) {
            // El mensaje del dominio ya está escrito para quien atiende.
            $this->avisarDelError($error);

            return;
        }

        /*
         * ═══ UN AVISO POR PAPEL ═══
         *
         * Desde el 13-ago-2026 un cobro puede salir en VARIOS recibos: si los
         * lotes marcados tienen titulares de recibo distintos, cada uno se
         * lleva el suyo. Cada notificación trae su propio botón de imprimir —
         * que es lo único que evita que alguien se vaya sin su papel.
         *
         * En «Ambas» el abono cae en UN solo recibo, el del lote elegido; los
         * demás son cuotas y se avisan como cuotas. Se identifica por el
         * código del lote y no por el concepto: un abono que no alcanzó a
         * reprogramar nada también se emite como `cuota`.
         */
        // El pronto pago no reprograma nada, así que su aviso es otro: dice
        // cuánto entró y cuánto se perdonó.
        if ($modo->perdonaSaldo()) {
            foreach ($recibos as $recibo) {
                $this->avisarDelProntoPago($recibo);
            }

            return;
        }

        $codigoDelAbono = $modo === ModoDeCobro::Ambas
            ? (string) $this->loteElegido($data)->lote()->value('codigo')
            : null;

        foreach ($recibos as $recibo) {
            $llevaElAbono = $modo === ModoDeCobro::Abono
                || ($codigoDelAbono !== null && in_array($codigoDelAbono, $recibo->codigosDeLotes(), true));

            if ($llevaElAbono) {
                $this->avisarDelAbono($recibo, conCuotas: $modo === ModoDeCobro::Ambas);

                continue;
            }

            $this->avisarDelCobro($recibo);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<Recibo>
     */
    private function soloLaCuota(array $data): array
    {
        return app(RegistroDePagos::class)
            ->loRecibio(QuienRecibeElDinero::elegido($data))
            ->cobrarVariosLotes(
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
     *
     * @return list<Recibo>
     */
    private function soloElAbono(array $data): array
    {
        return app(RegistroDePagos::class)
            ->loRecibio(QuienRecibeElDinero::elegido($data))
            ->abonarAVariosLotes(
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
     *
     * @return list<Recibo>
     */
    private function soloElProntoPago(array $data): array
    {
        return app(RegistroDePagos::class)
            ->loRecibio(QuienRecibeElDinero::elegido($data))
            ->prontoPago(
                venta: $this->venta,
                cliente: $this->quienPaga(),
                renglones: $this->renglonesDeProntoPagoTecleados($data),
                motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                forma: FormaDePago::from((string) $data['forma_pago']),
                referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
                fecha: CarbonImmutable::parse((string) $data['fecha']),
                observaciones: is_string($data['observaciones'] ?? null) ? $data['observaciones'] : null,
            );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<Recibo>
     */
    private function laCuotaYElAbono(array $data): array
    {
        return app(RegistroDePagos::class)
            ->loRecibio(QuienRecibeElDinero::elegido($data))
            ->cobrarYAbonar(
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
     * El número que se dice en voz alta: cuánto saca el cliente de la cartera.
     *
     * ═══ POR QUE ESTO NO ES UN ADORNO ═══
     *
     * Es el único dato del modal que NO se teclea y que sin embargo decide la
     * operación. Quien atiende marca dos lotes, escribe dos descuentos y tiene
     * que decir un número — sin esta línea lo calcularía de cabeza, con el
     * cliente esperando, y un error acá no lo atrapa ningún CHECK: el pago
     * entra por lo que se haya tecleado.
     *
     * El aviso del descuento que se pasa del saldo también sale acá: el
     * Service lo rechaza igual, pero verlo antes de apretar es la diferencia
     * entre corregir un número y explicar por qué no se pudo cobrar.
     */
    private function loQueEntrega(Get $get): HtmlString
    {
        /** @var list<array{codigo: string, saldo: Monto, descuento: Monto}> $marcados */
        $marcados = [];

        foreach ($this->lotesQueDeben() as $lote) {
            $id = (int) $lote->getKey();

            if ($get("saldar_{$id}") !== true) {
                continue;
            }

            $marcados[] = [
                'codigo'    => (string) $lote->lote?->getAttribute('codigo'),
                'saldo'     => $this->saldoDe($lote),
                'descuento' => $this->montoTecleado($get, "descuento_{$id}") ?? Monto::cero(),
            ];
        }

        $resumen = self::resumenDeProntoPago($marcados);

        if ($resumen['renglones'] === []) {
            return new HtmlString($resumen['avisos'] === []
                ? '<p class="olympo-vacio">Marcá los lotes que se saldan.</p>'
                : '<p class="olympo-vacio">'.e(implode(' ', $resumen['avisos'])).'</p>');
        }

        $filas = '';

        foreach ($resumen['renglones'] as $renglon) {
            $filas .= sprintf(
                '<li><span class="meses">%s — debe %s%s</span><span class="monto">%s</span></li>',
                e($renglon['codigo']),
                e($renglon['saldo']->formateado()),
                $renglon['descuento']->esCero() ? '' : e(sprintf(' · descuento %s', $renglon['descuento']->formateado())),
                e($renglon['entrega']->formateado()),
            );
        }

        $html = '<ul class="olympo-escalera">'.$filas.'</ul>';

        if (! $resumen['total'] instanceof Monto) {
            return new HtmlString(
                $html
                .'<p class="olympo-nota">'.e(implode(' ', $resumen['avisos'])).'</p>'
                .'<p class="olympo-nota">Corregí ese descuento: hasta entonces no hay monto que cobrar, porque el pago se rechaza entero.</p>'
            );
        }

        return new HtmlString(
            $html
            .sprintf(
                '<div class="olympo-total"><span>El cliente entrega</span><span>%s</span></div>',
                e($resumen['total']->formateado()),
            )
        );
    }

    /**
     * La cuenta del pronto pago, sin una sola línea de HTML.
     *
     * ═══ 🔴🔴 LA REGLA: SI UN LOTE ESTA MAL, NO HAY TOTAL ═══
     *
     * 23-ago-2026, encontrado mirando la pantalla. El renglón cuyo descuento
     * se pasa del saldo se saltea, así que el total sumaba SOLO los lotes
     * buenos: con dos lotes marcados y el primero pasado de rosca, el modal
     * decía en negrita «El cliente entrega L 307,000.00» —el saldo del OTRO
     * lote— y abajo, en letra chica, que el pago se iba a rechazar.
     *
     * Los dos renglones eran ciertos por separado y juntos mentían. El Service
     * rechaza el movimiento **ENTERO**: esa plata no la iba a cobrar nadie. Y
     * ese es el único renglón del modal que se dice EN VOZ ALTA con el cliente
     * enfrente — quien atiende lee el negrita, no la nota de abajo.
     *
     * Por eso `total` es `null` cuando hay un aviso, y no un número parcial:
     * **si la operación es todo-o-nada aguas abajo, el resumen también.**
     *
     * ═══ POR QUE ESTO ES PUBLICO Y ESTATICO ═══
     *
     * Porque es la única forma de PROBARLO. El resumen en vivo se dibuja en un
     * modal de Filament, y `assertSee()` **no llega a ver un modal** — el
     * intento con `mountAction()` dejó tres tests en rojo. Sacada la cuenta
     * del HTML, se prueba con una llamada y sin navegador. Ver
     * [[el-resumen-en-vivo-del-modal]].
     *
     * @param list<array{codigo: string, saldo: Monto, descuento: Monto}> $lotes
     *
     * @return array{renglones: list<array{codigo: string, saldo: Monto, descuento: Monto, entrega: Monto}>, avisos: list<string>, total: Monto|null}
     */
    public static function resumenDeProntoPago(array $lotes): array
    {
        $renglones = [];
        $avisos = [];
        $total = Monto::cero();

        foreach ($lotes as $lote) {
            // Antes de restar: `Monto::restar()` no admite negativos, y ese es
            // justamente el caso que hay que avisar en vez de reventar.
            if ($lote['descuento']->mayorQue($lote['saldo'])) {
                $avisos[] = sprintf(
                    'El descuento de %s se pasa de lo que ese lote debe (%s): el pago se va a rechazar.',
                    $lote['codigo'],
                    $lote['saldo']->formateado(),
                );

                continue;
            }

            $entrega = $lote['saldo']->restar($lote['descuento']);
            $total = $total->sumar($entrega);

            $renglones[] = [
                'codigo'    => $lote['codigo'],
                'saldo'     => $lote['saldo'],
                'descuento' => $lote['descuento'],
                'entrega'   => $entrega,
            ];
        }

        return [
            'renglones' => $renglones,
            'avisos'    => $avisos,
            'total'     => $avisos === [] ? $total : null,
        ];
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

        /*
         * ⚠️ Con una cuota vencida el Service rechaza el abono (24-ago-2026), y
         * este lote no debería tener casilla siquiera — `renglonesDeAbono()` la
         * cambia por el motivo. Se pregunta igual porque la casilla puede venir
         * marcada de un estado anterior del formulario, y una previsualización
         * que dibuja un plan nuevo para algo que se va a rechazar es peor que no
         * dibujar nada.
         *
         * Es además lo que reemplazó a la nota de `esPagoNormal`: ese caso ya no
         * llega acá —sin nada vencido, `ponerAlDia` vale cero y cualquier monto
         * lo supera— y su texto prometía un pago normal que hoy no se registra.
         */
        $atrasado = $this->porQueNoPuedeAbonar($lote);

        if ($atrasado instanceof HtmlString) {
            return (string) $atrasado;
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
     * 🔴 Los lotes del contrato, SIEMPRE en el mismo orden.
     *
     * `compromisos` no trae `orderBy`, asi que Postgres los devuelve en el
     * orden fisico de la tabla — y ese orden CAMBIA en cuanto una fila se
     * actualiza, porque Postgres reescribe la fila al final del heap. En un
     * modal de cobro eso es peor que feo: `primerLoteConSaldo()` decide a
     * QUE LOTE se le propone el pago, asi que despues de cobrar una vez el
     * monto sugerido podia caer en otro lote sin que nadie moviera nada.
     *
     * Se descubrio el 13-ago-2026 por un test que fallaba salteado en
     * `EstadoDeCuenta`, que tenia el mismo agujero.
     *
     * Por CODIGO porque es el orden del contrato: RPS-A-001, RPS-A-002.
     *
     * @return Collection<int, Compromiso>
     */
    private function compromisosEnOrden(): Collection
    {
        /*
         * 🔴 Los RESCINDIDOS quedan afuera (R22, 14-ago-2026). Un lote que se
         * cayo puede conservar una cuota con saldo —la que se pago a medias o
         * la que tuvo un pago anulado no se pueden borrar, tienen
         * aplicaciones colgando— y sin este filtro ese lote seguiria
         * apareciendo en el modal de cobro, ofreciendole a la ventanilla
         * cobrarle a alguien por un terreno que ya no es suyo.
         */
        return $this->venta->compromisos
            ->reject(static fn (Compromiso $compromiso): bool => $compromiso->getAttribute('estado') === EstadoCompromiso::Rescindido)
            ->sortBy(static fn (Compromiso $compromiso): string => (string) $compromiso->lote?->getAttribute('codigo'))
            ->values();
    }

    /**
     * Los lotes del contrato que todavía deben, con cuánto.
     *
     * @return array<int, string>
     */
    private function lotesConSaldo(): array
    {
        $opciones = [];

        foreach ($this->compromisosEnOrden() as $renglon) {
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

        foreach ($this->compromisosEnOrden() as $renglon) {
            if (! $this->saldoDe($renglon)->esCero()) {
                $lotes[] = $renglon;
            }
        }

        return $lotes;
    }

    private function primerLoteConSaldo(): ?Compromiso
    {
        foreach ($this->compromisosEnOrden() as $renglon) {
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
     * Las cuotas VENCIDAS del lote, listadas una por una.
     *
     * ═══ POR QUE SE LISTAN Y NO SE CUENTAN ═══
     *
     * Lo pidió Mauricio el 23-ago-2026: «en caso de que tenga cuotas
     * atrasadas que se listen todas las que tenga atrasadas». La lista de
     * cobranza ya dice «6 cuotas vencidas», pero quien atiende con el cliente
     * enfrente necesita el detalle —de qué meses son y cuánto es cada una—
     * para contestar «¿y de qué me estás cobrando?» sin salirse del modal.
     *
     * Van en el orden del FIFO, que es el mismo en el que se van a pagar: si
     * el cliente trae para una sola, esa plata va a la PRIMERA de la lista.
     * Por eso la primera se marca, en vez de dejarlo librado a que se deduzca.
     *
     * Devuelve null cuando no hay ninguna vencida — el lote debe, pero
     * todavía no le toca— y así el campo no dibuja ayuda vacía.
     */
    private function lasVencidasDe(Compromiso $lote): ?HtmlString
    {
        ['cuotas' => $vencidas, 'total' => $total] = $this->loVencidoDe($lote);

        if ($vencidas === []) {
            return null;
        }

        return new HtmlString(sprintf(
            '<span class="olympo-vencidas">%s, %s en total:</span>%s',
            e($this->cuantasVencidas($vencidas)),
            e($total->formateado()),
            $this->detalleDeLasVencidas($vencidas, marcarLaPrimera: true),
        ));
    }

    /**
     * Por qué este lote NO puede recibir un abono, o null si sí puede.
     *
     * Es el mismo dato que `lasVencidasDe()` con otras palabras: allá es una
     * ayuda para cobrar, acá es el motivo de que no haya casilla. Se escribe en
     * el lenguaje de la salida —«cobralas con Cuota», «usá Ambas»— porque quien
     * lo lee tiene al cliente enfrente y necesita el próximo paso, no el
     * nombre de la regla.
     */
    private function porQueNoPuedeAbonar(Compromiso $lote): ?HtmlString
    {
        ['cuotas' => $vencidas, 'total' => $total] = $this->loVencidoDe($lote);

        if ($vencidas === []) {
            return null;
        }

        return new HtmlString(sprintf(
            '<p class="olympo-lote">%s — debe %s</p>'
            .'<p class="olympo-nota"><strong>No puede recibir abono a capital:</strong> tiene %s por %s y '
            .'primero se pone al día. Cobralas con <strong>«Cuota»</strong>, o con <strong>«Ambas»</strong> '
            .'si además viene a bajar capital — ahí las dos cosas salen en un solo recibo.</p>%s',
            e((string) $lote->lote?->getAttribute('codigo')),
            e($this->saldoDe($lote)->formateado()),
            e($this->cuantasVencidas($vencidas)),
            e($total->formateado()),
            $this->detalleDeLasVencidas($vencidas, marcarLaPrimera: false),
        ));
    }

    /**
     * @param list<Cuota> $vencidas
     */
    private function cuantasVencidas(array $vencidas): string
    {
        return count($vencidas) === 1 ? '1 cuota vencida' : count($vencidas).' cuotas vencidas';
    }

    /**
     * La lista de las vencidas, una por línea.
     *
     * `$marcarLaPrimera` es la diferencia entre los dos usos: en el renglón del
     * cobro la primera se señala porque ahí ES donde va a caer la plata, y en el
     * aviso del abono no hay ninguna plata cayendo — marcarla estaría prometiendo
     * algo que este modal no va a hacer.
     *
     * @param list<Cuota> $vencidas
     */
    private function detalleDeLasVencidas(array $vencidas, bool $marcarLaPrimera): string
    {
        $filas = '';

        foreach ($vencidas as $indice => $cuota) {
            $vence = $cuota->getAttribute('fecha_vencimiento');

            $filas .= sprintf(
                '<li>Cuota %d · venció el %s · %s%s</li>',
                (int) $cuota->getAttribute('numero'),
                e($vence instanceof CarbonInterface ? $vence->format('d/m/Y') : '—'),
                e($cuota->saldo()->formateado()),
                $marcarLaPrimera && $indice === 0 ? ' <strong>← la más vieja: acá se aplica primero</strong>' : '',
            );
        }

        return '<ul class="olympo-vencidas-lista">'.$filas.'</ul>';
    }

    /**
     * Lo vencido del lote: las cuotas atrasadas y cuánto suman.
     *
     * ═══ POR QUE ES `estaVencida()` Y NO UNA COMPARACION DE ACA ═══
     *
     * Esto lo preguntan TRES cosas de esta pantalla —la ayuda del cobro, el
     * bloqueo del abono y la previsualización— y el dominio lo pregunta otra
     * vez adentro de la transacción. Con una comparación propia acá, el modal
     * podría listar «1 cuota vencida» y el Service dejar pasar el abono: dos
     * respuestas a la pregunta de si el cliente está al día, y las dos
     * impresas.
     *
     * `Cuota::estaVencida()` usa `today()` de PHP (§7.5.1) y deja fuera la que
     * vence HOY: todavía no atrasa. Esa cuota se lista como pendiente en el
     * plan, no como vencida acá.
     *
     * @return array{cuotas: list<Cuota>, total: Monto}
     */
    private function loVencidoDe(Compromiso $lote): array
    {
        $vencidas = [];
        $total = Monto::cero();

        foreach ($this->pendientesDe($lote) as $cuota) {
            if (! $cuota->estaVencida()) {
                continue;
            }

            $vencidas[] = $cuota;
            $total = $total->sumar($cuota->saldo());
        }

        return ['cuotas' => $vencidas, 'total' => $total];
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
     * El aviso del pronto pago: cuánto entró y cuánto se perdonó.
     *
     * Los DOS números, siempre. El papel dice uno solo —lo que entró— y quien
     * atiende necesita confirmar en voz alta el otro antes de que el cliente
     * se vaya: es lo único que se acordó de palabra.
     */
    private function avisarDelProntoPago(Recibo $recibo): void
    {
        $descuento = $recibo->capitalCondonado();
        $lotes = count($recibo->codigosDeLotes());

        Notification::make()
            ->title("Recibo {$recibo->folio()}")
            ->body(sprintf(
                '%d %s. Entraron %s%s.',
                $lotes,
                $lotes === 1 ? 'lote saldado' : 'lotes saldados',
                $recibo->montoTotal()->formateado(),
                $descuento->esCero() ? '' : sprintf(', con %s de descuento', $descuento->formateado()),
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
         *
         * ⚠️ Desde el 24-ago-2026 esto no debería pasar nunca: sin cuotas
         * vencidas cualquier monto baja capital, y con cuotas vencidas el
         * Service rechaza el abono. Se conserva porque un recibo sin
         * notificación es un cliente que se va sin su papel — y eso es peor que
         * un aviso que sobra.
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
