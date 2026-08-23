<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CambioDeTitular;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeRescisiones;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Schemas\Components\DNIField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Support\CobrarUnPago;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Venta;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * La ficha del expediente.
 *
 * Tres botones, y dos de ellos mueven dinero: COBRAR, que es lo que se hace
 * todos los días, y ABONAR A CAPITAL, que además reescribe el plan. Editar una
 * venta firmada no es una acción genérica (ver el docblock de `VentaResource`):
 * rescindir, liquidar e imprimir el contrato entran acá cuando se construya
 * cada trámite, cada uno con su nombre y su motivo.
 *
 * ═══ POR QUE ESTA PAGINA PASO DE 996 LINEAS A ESTO ═══
 *
 * Los dos modales de dinero se mudaron a `App\Filament\Support\CobrarUnPago` el
 * 10-ago-2026. No fue por estética: Mauricio pidió cobrar DESDE LA TABLA sin
 * salir de la pantalla donde se está —«siempre en la vista de cliente ahí debe
 * de abrirse el modal»— y un modal que vive en una página no se puede abrir
 * desde una fila. Copiarlo habría dejado dos modales de dinero que hay que
 * mantener iguales, y el día que se separen uno de los dos le miente a un
 * cliente.
 *
 * Los dos botones siguen acá porque acá es donde la administradora trabaja. Lo
 * que cambió es que su contenido se define en un solo lugar.
 */
class ViewVenta extends ViewRecord
{
    #[Override]
    protected static string $resource = VentaResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        /*
         * 🔴 SIN «Abonar a capital» — 14-ago-2026.
         *
         * Era un segundo boton para el mismo modal: adentro de «Registrar un
         * pago» el primer control ya pregunta «¿que es este pago?» con las
         * tres opciones —cuota, abono a capital, ambas—. Dos puertas a la
         * misma pantalla obligan a decidir antes de entrar algo que se decide
         * adentro, y quien atiende duda si son dos cosas distintas.
         *
         * ⚠️ NO afloja el permiso de R21: `CobrarUnPago::modos()` solo ofrece
         * «Abono» y «Ambas» a quien puede reprogramar. El receptor abre el
         * mismo modal y ve una sola opcion.
         */
        return [
            $this->estadoDeCuentaAction(),
            CobrarUnPago::accion(),
            $this->titularesDeReciboAction(),
            $this->cambiarTitularAction(),
            $this->rescindirAction(),
        ];
    }

    /**
     * El expediente pasa a otra persona: la cesion de derechos.
     *
     * ═══ POR QUE NO ES UN CAMPO EDITABLE ═══
     *
     * Porque no es corregir un dato mal tecleado: es que el contrato cambia
     * de dueño. Un Select suelto en un formulario de edicion no deja
     * constancia de nada y se puede tocar sin querer. Como accion con su
     * confirmacion, deja asiento en la bitacora con el usuario y la fecha —
     * que es exactamente lo que Mauricio pidio el 22-ago-2026.
     *
     * ⚠️ Se lista TODO cliente activo, incluidos los copropietarios de este
     * mismo expediente: el caso mas comun no es que entre un extraño, es que
     * la titularidad pase del marido a la esposa que ya firmaba al lado.
     *
     * ⚠️ El texto del modal dice lo de los recibos porque es lo primero que
     * alguien va a suponer al reves. Los pagos NO se reasignan.
     */
    private function cambiarTitularAction(): Action
    {
        return Action::make('cambiar_titular')
            ->label('Cambiar titular')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            // El Service lo rechaza igual, pero ofrecer el boton en un
            // expediente rescindido o anulado invita a un tramite que no se
            // puede hacer. Mismo criterio que `rescindirAction()`.
            ->visible(fn (): bool => $this->elLoteSigueSiendoDeAlguien()
                && auth()->user()?->can('cambiarTitular', Venta::class) === true)
            ->modalHeading('Pasar este expediente a otra persona')
            ->modalDescription(
                'Los pagos no se mueven: los recibos ya emitidos siguen a nombre de quien pagó. '.
                'De aquí en adelante el estado de cuenta y los recibos nuevos salen a nombre del '.
                'titular nuevo, y quien sale queda listado como titular anterior con la fecha.'
            )
            ->modalSubmitActionLabel('Cambiar el titular')
            ->modalWidth('xl')
            ->schema([
                Placeholder::make('titular_actual')
                    ->label('Titular hoy')
                    ->content(fn (): string => (string) ($this->venta()->titular()?->getAttribute('nombre') ?? '—')),

                Select::make('cliente_id')
                    ->label('Nuevo titular')
                    ->options(fn (): array => $this->clientesParaTitular())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Si todavía no está registrado, hay que darlo de alta en Clientes primero.'),

                Textarea::make('motivo')
                    ->label('Por qué cambia (opcional)')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Queda en la bitácora junto con tu usuario y la fecha.'),
            ])
            ->action(function (array $data): void {
                $nuevo = Cliente::query()->whereKey((int) ($data['cliente_id'] ?? 0))->first();

                if (! $nuevo instanceof Cliente) {
                    return;
                }

                try {
                    $anterior = app(CambioDeTitular::class)->cambiar(
                        venta: $this->venta(),
                        nuevo: $nuevo,
                        motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : null,
                    );
                } catch (GrupoOlympoException $error) {
                    Notification::make()
                        ->title('No se pudo cambiar el titular')
                        ->body($error->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('El expediente cambió de titular')
                    ->body(sprintf(
                        'De %s a %s. Los recibos ya emitidos no cambiaron.',
                        (string) ($anterior?->getAttribute('nombre') ?? '—'),
                        (string) $nuevo->getAttribute('nombre'),
                    ))
                    ->success()
                    ->persistent()
                    ->send();

                // La ficha muestra los dueños arriba: sin esto sigue con el
                // nombre viejo hasta que alguien recargue.
                $this->getRecord()->refresh();
            });
    }

    /**
     * ¿Este expediente todavia tiene lotes de alguien?
     *
     * Rescindido o anulado no queda titularidad que ceder. Se lee del
     * atributo casteado porque `Venta::estadoActual()` es privado.
     */
    private function elLoteSigueSiendoDeAlguien(): bool
    {
        $estado = $this->venta()->getAttribute('estado');

        return $estado instanceof EstadoVenta && $estado->ocupaLosLotes();
    }

    /**
     * A quien se le puede pasar el expediente: cualquier cliente activo
     * menos el titular de hoy, que no tendria sentido elegir.
     *
     * @return array<int, string>
     */
    private function clientesParaTitular(): array
    {
        $actual = $this->venta()->titular()?->getKey();

        return Cliente::query()
            ->activos()
            ->when($actual !== null, fn (Builder $q): Builder => $q->whereKeyNot($actual))
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->all();
    }

    /**
     * A nombre de quien salen los recibos de cada lote de este contrato.
     *
     * ═══ POR QUE ACA Y NO EN UNA PANTALLA APARTE ═══
     *
     * Porque es una configuracion DEL EXPEDIENTE, y quien la necesita ya esta
     * parado en el expediente. Se configura al vender, pero el caso llega
     * tarde: el grupo firma en junio y en septiembre aparece uno de los
     * representados pidiendo su recibo a su nombre.
     *
     * Se listan TODOS los lotes del contrato, no solo los que ya tienen
     * nombre: la pregunta es «¿como sale cada uno?» y eso incluye a los que
     * salen a nombre del dueño del expediente. Sin ellos, quien mira no puede
     * saber si el lote 3 esta sin configurar o si no existe.
     *
     * ⚠️ Los recibos ya emitidos NO cambian. Se quedaron con su copia
     * congelada del nombre (§8.2): un papel entregado no se corrige, se anula
     * y se emite otro. La descripcion del modal lo dice, porque es lo primero
     * que alguien va a suponer al revés.
     */
    private function titularesDeReciboAction(): Action
    {
        return Action::make('titulares_de_recibo')
            ->label('Titular de los recibos')
            ->icon(Heroicon::OutlinedIdentification)
            ->color('gray')
            ->modalHeading('¿A nombre de quién sale el recibo de cada lote?')
            ->modalDescription(
                'Vacío quiere decir que sale a nombre del dueño del expediente. Esto vale para los '.
                'cobros que vengan: los recibos ya entregados no cambian.'
            )
            ->modalSubmitActionLabel('Guardar')
            ->modalWidth('3xl')
            ->fillForm(fn (): array => ['lotes' => $this->lotesConSuTitular()])
            ->schema([
                Repeater::make('lotes')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columns(12)
                    ->itemLabel(fn (array $state): ?string => is_string($state['codigo'] ?? null)
                        ? $state['codigo']
                        : null)
                    ->schema([
                        Hidden::make('compromiso_id'),
                        Hidden::make('codigo'),

                        MayusculasField::make('titular_recibo')
                            ->label('Titular del recibo')
                            ->placeholder('El dueño del expediente')
                            ->maxLength(150)
                            ->columnSpan(8)
                            ->live(onBlur: true),

                        DNIField::make('titular_recibo_dni')
                            ->label('DNI del titular')
                            ->columnSpan(4)
                            ->visible(fn (Get $get): bool => filled($get('titular_recibo')))
                            ->helperText('Opcional.'),
                    ]),
            ])
            ->action(function (array $data): void {
                try {
                    $cuantos = $this->guardarLosTitulares($data);
                } catch (GrupoOlympoException $error) {
                    Notification::make()
                        ->title('No se pudo guardar')
                        ->body($error->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($cuantos === 1
                        ? 'Un lote sale a nombre de otra persona'
                        : "{$cuantos} lotes salen a nombre de otra persona")
                    ->body('Los recibos que se emitan de aquí en adelante llevan esos nombres.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Los lotes del contrato con lo que tengan configurado hoy.
     *
     * @return list<array<string, string|int|null>>
     */
    private function lotesConSuTitular(): array
    {
        $filas = [];

        foreach ($this->venta()->compromisos()->with('lote')->get() as $renglon) {
            $filas[] = [
                'compromiso_id'      => (int) $renglon->getKey(),
                'codigo'             => (string) $renglon->lote?->getAttribute('codigo'),
                'titular_recibo'     => $renglon->titularDelRecibo(),
                'titular_recibo_dni' => $renglon->dniDelTitularDelRecibo(),
            ];
        }

        return $filas;
    }

    /**
     * Pasa lo tecleado por el Service, y devuelve cuantos quedaron a nombre de
     * otra persona.
     *
     * `whereKey()->first()` y no `findOrFail()`: una fila vieja de una pestaña
     * que estuvo abierta no puede tumbar el guardado de las demas.
     *
     * @param array<string, mixed> $data
     */
    private function guardarLosTitulares(array $data): int
    {
        $registro = app(RegistroDeCompromisos::class);
        $filas = is_array($data['lotes'] ?? null) ? $data['lotes'] : [];
        $conNombre = 0;

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            if (! is_numeric($fila['compromiso_id'] ?? null)) {
                continue;
            }
            $renglon = Compromiso::query()->whereKey((int) $fila['compromiso_id'])->first();

            if (! $renglon instanceof Compromiso) {
                continue;
            }

            $nombre = is_string($fila['titular_recibo'] ?? null) ? $fila['titular_recibo'] : null;
            $dni = is_string($fila['titular_recibo_dni'] ?? null) ? $fila['titular_recibo_dni'] : null;

            $registro->ponerElTitularDelRecibo($renglon, $nombre, $dni);

            if ($renglon->refresh()->titularDelRecibo() !== null) {
                $conNombre++;
            }
        }

        return $conNombre;
    }

    /**
     * R22: se cae un lote del contrato, y se liquida lo que entro.
     *
     * ═══ POR QUE PREGUNTA Y NO CALCULA ═══
     *
     * Cuanto se le devuelve al cliente lo decide la administracion caso por
     * caso (R6). El sistema muestra cuanto entro por ese lote —que es el
     * techo— y anota la respuesta. Proponer un numero seria el sistema
     * tomando una decision comercial que no le toca, y el primero que
     * aceptara el numero propuesto sin pensarlo seria quien esta apurado.
     *
     * ═══ QUIEN PUEDE ═══
     *
     * Solo quien pueda BORRAR ventas, que en la practica es la
     * administradora. Es el mismo criterio de los gastos: lo que el
     * desarrollo pierde es informacion del dueño. Un receptor cobra; deshacer
     * un contrato firmado y decidir que la lotificadora se queda con la plata
     * de alguien no es cobrar.
     */
    private function rescindirAction(): Action
    {
        return Action::make('rescindir')
            ->label('Rescindir un lote')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (): bool => $this->venta()->estaVigente()
                && $this->lotesVigentes() !== []
                && auth()->user()?->can('delete', $this->venta()) === true)
            ->modalHeading('Rescindir un lote de este contrato')
            ->modalDescription(
                'El lote vuelve a estar disponible y sus cuotas pendientes quedan sin efecto. '.
                'Lo que no se le devuelva al cliente queda retenido por la lotificadora. Esto no se deshace.'
            )
            ->modalSubmitActionLabel('Rescindir y emitir el acta')
            ->modalWidth('2xl')
            ->fillForm(fn (): array => [
                'compromiso_id'  => array_key_first($this->lotesVigentes()),
                'monto_devuelto' => '0.00',
                'forma_pago'     => FormaDePago::Efectivo->value,
            ])
            ->schema([
                Select::make('compromiso_id')
                    ->label('Lote que se cae')
                    ->options(fn (): array => $this->lotesVigentes())
                    ->required()
                    ->live()
                    ->native(false),

                // Lo que entró por ESE lote: su parte congelada de la prima,
                // más lo aplicado a sus cuotas y su mora. Es el techo de lo
                // que se puede devolver, y hay que verlo ANTES de decidir.
                Placeholder::make('recibido')
                    ->label('Entró por ese lote')
                    ->content(fn (Get $get): string => $this->recibidoDe($get)->formateado()),

                MontoField::make('monto_devuelto', 'Se le devuelve al cliente')
                    ->live(onBlur: true)
                    ->helperText('Puede ser L 0.00: si no se le devuelve nada, todo queda retenido.'),

                Placeholder::make('retenido')
                    ->label('Queda retenido por la lotificadora')
                    ->content(fn (Get $get): string => $this->retenidoDe($get)->formateado()),

                Select::make('forma_pago')
                    ->label('Forma de pago de la devolución')
                    ->options(fn (): array => $this->formasDePago())
                    ->required()
                    ->live()
                    ->native(false),

                TextInput::make('referencia')
                    ->label('Número de referencia')
                    ->maxLength(60)
                    ->visible(fn (Get $get): bool => $this->exigeReferencia($get))
                    ->required(fn (Get $get): bool => $this->exigeReferencia($get))
                    ->helperText('Es lo único que después permite cruzar esta salida contra el banco (R11).'),

                Textarea::make('motivo')
                    ->label('Por qué se rescinde')
                    ->required()
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText('Queda impreso en el acta que firma el cliente, con tu usuario y la fecha.'),
            ])
            ->action(function (array $data): void {
                $lote = Compromiso::query()->whereKey((int) ($data['compromiso_id'] ?? 0))->first();

                if (! $lote instanceof Compromiso) {
                    return;
                }

                try {
                    $acta = app(RegistroDeRescisiones::class)->rescindir(
                        lote: $lote,
                        devuelto: new Monto((string) ($data['monto_devuelto'] ?? '0')),
                        forma: FormaDePago::from((string) $data['forma_pago']),
                        motivo: (string) $data['motivo'],
                        referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
                    );
                } catch (GrupoOlympoException $error) {
                    Notification::make()
                        ->title('No se pudo rescindir')
                        ->body($error->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Lote rescindido — acta {$acta->folio()}")
                    ->body(sprintf(
                        'Se le devolvieron %s y quedan %s retenidos.',
                        $acta->montoDevuelto()->formateado(),
                        $acta->montoRetenido()->formateado(),
                    ))
                    ->success()
                    ->persistent()
                    ->actions([
                        // Misma clase que los botones del encabezado: desde
                        // Filament 4 las notificaciones usan `Filament\Actions\Action`.
                        Action::make('imprimir')
                            ->label('Imprimir el acta')
                            ->url(route('documentos.devolucion', $acta))
                            ->openUrlInNewTab(),
                    ])
                    ->send();

                // El encabezado del expediente cambia de saldo y puede
                // cambiar de estado: sin esto, la pantalla sigue mostrando
                // el contrato como estaba antes de rescindir.
                $this->getRecord()->refresh();
                $this->refreshFormData(['estado', 'valor_total', 'prima', 'saldo_financiar', 'cuota_mensual', 'plazo_meses']);
            });
    }

    /**
     * Los lotes que todavia estan vivos en este contrato.
     *
     * @return array<int, string>
     */
    private function lotesVigentes(): array
    {
        $opciones = [];

        foreach ($this->venta()->compromisos()->with('lote')->get() as $renglon) {
            if ($renglon->getAttribute('estado') !== EstadoCompromiso::Vigente) {
                continue;
            }

            $opciones[(int) $renglon->getKey()] = (string) $renglon->lote?->getAttribute('codigo');
        }

        return $opciones;
    }

    private function recibidoDe(Get $get): Monto
    {
        $lote = Compromiso::query()->whereKey((int) $get('compromiso_id'))->first();

        return $lote instanceof Compromiso
            ? app(RegistroDeRescisiones::class)->loRecibido($lote)
            : Monto::cero();
    }

    /**
     * Lo que queda del lado de la lotificadora, en vivo mientras se teclea.
     *
     * Nunca negativo en pantalla: si alguien teclea de mas, el numero se
     * queda en cero y el Service lo rechaza al confirmar con el mensaje que
     * explica por que. Mostrar un retenido negativo seria peor que no
     * mostrarlo.
     */
    private function retenidoDe(Get $get): Monto
    {
        $recibido = $this->recibidoDe($get);
        $tecleado = $get('monto_devuelto');
        $devuelto = is_numeric($tecleado) ? new Monto((string) $tecleado) : Monto::cero();

        return $devuelto->mayorQue($recibido) ? Monto::cero() : $recibido->restar($devuelto);
    }

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

    private function exigeReferencia(Get $get): bool
    {
        $forma = $get('forma_pago');

        return is_string($forma) && FormaDePago::from($forma)->exigeReferencia();
    }

    /**
     * El estado de cuenta del expediente, listo para el papel.
     *
     * Sin `visible()`: quien está parado en esta página ya tiene `View:Venta`,
     * que es exactamente el permiso que pide el documento. Repetir la
     * comprobación acá sería una condición más que mantener sincronizada con
     * el controlador, para la misma decisión.
     *
     * Pestaña nueva, como el recibo: quien atiende no pierde el expediente.
     */
    private function estadoDeCuentaAction(): Action
    {
        return Action::make('estado_de_cuenta')
            ->label('Estado de cuenta')
            ->icon(Heroicon::OutlinedDocumentChartBar)
            ->color('gray')
            ->url(fn (): string => route('documentos.estado-de-cuenta', $this->venta()))
            ->openUrlInNewTab();
    }

    private function venta(): Venta
    {
        /** @var Venta $venta */
        $venta = $this->getRecord();

        return $venta;
    }
}
