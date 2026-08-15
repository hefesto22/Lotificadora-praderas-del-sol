<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Schemas;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\ListaDePrecios;
use App\Domain\Ventas\PlanDeCuotas;
use App\Filament\Schemas\Components\DNIField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\PrecioPorAreaField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Filament\Support\Unidades;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Vendedor;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Throwable;

/**
 * El formulario mas complejo del sistema (§10.8).
 *
 * ═══ LA VISTA PREVIA SE CALCULA EN EL SERVIDOR, NO EN JS ═══
 *
 * `LoteForm` calcula area x precio en el navegador con BigInt, para no pagar
 * un round-trip por cada tecla. Aca no: el valor de la venta depende de los
 * lotes elegidos, que viven en la base. El round-trip es inevitable — y ya
 * que se paga, se paga bien: la vista previa la arma **el mismo
 * `PlanDeCuotas` que despues persiste la venta**.
 *
 * Eso es lo que pide el §10.8: "el usuario debe ver el numero de cuota antes
 * de confirmar, no despues". Y no es una aproximacion que se parezca al
 * calculo real: es el calculo real, con bcmath, hasta el ultimo centavo de
 * la ultima cuota.
 *
 * ═══ NO HAY TAB "ESTADO" ═══
 *
 * El §10.1 pide tres tabs, el tercero de estado. Una venta no tiene estado
 * que elegir: nace vigente cuando la prima esta pagada (R5) y de ahi en
 * adelante se mueve con acciones que tienen nombre —rescindir, liquidar—,
 * cada una con su motivo. Un `Select` de estado seria una forma de saltarse
 * esos tramites. El tercer tab es la vista previa, que es lo que el usuario
 * necesita mirar antes de firmar.
 */
class VentaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Venta')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('Lotes y clientes')
                            ->icon('heroicon-o-map')
                            ->columns(2)
                            ->schema([
                                Select::make('proyecto_id')
                                    ->label('Proyecto')
                                    ->relationship('proyecto', 'nombre')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->columnSpanFull()
                                    ->helperText('El código del proyecto es el prefijo del número de contrato.'),

                                /*
                                | Un repetidor y no un multi-select porque
                                | cada lote lleva SU precio: el de lista viene
                                | precargado y se puede bajar, con motivo (R4).
                                |
                                | Ojo: no hay forma de impedir desde el
                                | formulario que el mismo lote entre dos veces
                                | —Select v5 no trae ->distinct()—. Lo rechaza
                                | el dominio, con nombre y apellido del lote.
                                */
                                Repeater::make('detalle')
                                    ->label('Lotes del contrato')
                                    ->addActionLabel('Agregar otro lote')
                                    ->columnSpanFull()
                                    ->columns(12)
                                    ->live()
                                    ->minItems(1)
                                    ->defaultItems(1)
                                    ->itemLabel(fn (array $state): ?string => self::rotuloDeFila($state))
                                    ->schema([
                                        Select::make('lote_id')
                                            ->label('Lote')
                                            ->options(fn (Get $get): array => self::lotesDisponibles($get))
                                            ->disabled(fn (Get $get): bool => blank($get('../../proyecto_id')))
                                            ->searchable()
                                            ->required()
                                            ->live()
                                            ->columnSpan(5)
                                            // Al elegir el lote se trae su precio de lista. De
                                            // ahi en adelante el campo es del usuario.
                                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                                $set('precio_vara', self::precioDeLista($state, $get));
                                                $set('motivo_descuento', null);
                                            }),

                                        PrecioPorAreaField::make('precio_vara')
                                            ->label(static fn (Get $get, ?Component $livewire): string => 'Precio '.Unidades::delFormulario($get, null, $livewire, '../../proyecto_id')->porUnidad())
                                            ->live(onBlur: true)
                                            ->columnSpan(3),

                                        Placeholder::make('valor_lote')
                                            ->label('Valor')
                                            ->columnSpan(4)
                                            ->content(fn (Get $get): string => self::valorDeFila($get)),

                                        TextInput::make('motivo_descuento')
                                            ->label('Motivo del descuento')
                                            ->maxLength(200)
                                            ->columnSpanFull()
                                            ->visible(fn (Get $get): bool => self::hayDescuento($get))
                                            ->required(fn (Get $get): bool => self::hayDescuento($get))
                                            ->helperText(
                                                'Este lote va por debajo del precio de lista. R4: queda '.
                                                'guardado con tu usuario y la fecha. Sin motivo no se graba.'
                                            ),

                                        /*
                                        | ═══ A NOMBRE DE QUIEN SALEN LOS RECIBOS DE ESTE LOTE ═══
                                        |
                                        | Lo pidio Mauricio el 12-ago-2026: un grupo compra junto y
                                        | firma UNA sola persona, pero cada representado tiene SU lote
                                        | adentro del contrato y quiere el papel a su nombre.
                                        |
                                        | Va POR LOTE y no por contrato porque asi es como pasa: «si
                                        | son 3 lotes debe decidir a nombre de quien sale el recibo de
                                        | ESE lote». Vacio es el caso normal y el placeholder lo dice,
                                        | porque un campo opcional que no explica que hace cuando se lo
                                        | deja vacio es un campo que la gente llena por las dudas.
                                        |
                                        | NO se guarda en `clientes`: ahi van los del expediente, y un
                                        | representado no compro nada a su nombre. Meterlo ahi lo
                                        | metria tambien en la lista de este mismo formulario.
                                        */
                                        MayusculasField::make('titular_recibo')
                                            ->label('Titular del recibo')
                                            ->placeholder('El dueño del expediente')
                                            ->maxLength(150)
                                            ->columnSpan(8)
                                            ->live(onBlur: true)
                                            ->helperText(
                                                'Opcional. Solo para el caso del representante: los recibos de '.
                                                'ESTE lote salen a este nombre. El contrato no cambia de dueño.'
                                            ),

                                        DNIField::make('titular_recibo_dni')
                                            ->label('DNI del titular')
                                            ->columnSpan(4)
                                            ->visible(fn (Get $get): bool => filled($get('titular_recibo')))
                                            ->helperText('Opcional, pero es lo que hace que ese papel sirva de prueba.'),
                                    ])
                                    ->helperText(
                                        'Un contrato puede llevar varios lotes. Solo se listan los '.
                                        'disponibles y los apartados; un apartado a nombre de otra '.
                                        'persona se rechaza al guardar.'
                                    ),

                                Select::make('titular_id')
                                    ->label('Titular')
                                    ->options(fn (): array => self::clientes())
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    // live: el selector de abajo lo saca de su
                                    // lista, y tiene que enterarse al toque.
                                    ->live()
                                    ->helperText('A su nombre sale el estado de cuenta.'),

                                /*
                                 * El titular NO aparece acá. El índice único
                                 * (venta_id, cliente_id) del pivot lo rechazaría
                                 * igual, y ofrecer algo que se va a rechazar es
                                 * una trampa para quien atiende.
                                 */
                                Select::make('copropietarios')
                                    ->label('Copropietarios')
                                    ->multiple()
                                    ->options(fn (Get $get): array => self::clientesMenosElTitular($get))
                                    ->searchable()
                                    ->helperText('Opcional: marido y mujer o socios, los dos en el contrato.'),
                            ]),

                        Tab::make('Condiciones')
                            ->icon('heroicon-o-banknotes')
                            ->columns(3)
                            ->schema([
                                MontoField::make('prima', 'Prima')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText('Se paga completa al firmar. Si hubo apartado, ya cuenta como parte.'),

                                /*
                                 * La prima entra el dia de la firma y sale con
                                 * su recibo, asi que R11 pide saber como entro.
                                 * Lo que se cobra hoy es la prima MENOS las
                                 * señas de apartado (R14); el Service resta y
                                 * el papel lo explica.
                                 */
                                Select::make('forma_pago_prima')
                                    ->label('¿Cómo entra la prima?')
                                    ->options(self::formasDePago())
                                    ->required()
                                    ->live()
                                    ->native(false)
                                    ->default(FormaDePago::Efectivo->value)
                                    ->helperText('Va impreso en el recibo de la prima.'),

                                TextInput::make('referencia_prima')
                                    ->label('Número de referencia')
                                    ->maxLength(60)
                                    ->visible(fn (Get $get): bool => self::exigeReferenciaDeLaPrima($get))
                                    ->required(fn (Get $get): bool => self::exigeReferenciaDeLaPrima($get))
                                    ->helperText('Sin él no hay cómo cruzar el recibo contra el banco (R11).'),

                                TextInput::make('plazo_meses')
                                    ->label('Plazo en meses')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(PlanDeCuotas::PLAZO_MAXIMO_MESES)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->default(fn (): int => self::configEntero('lotificadora.ventas.plazo_meses_default', 60))
                                    ->helperText('0 = venta de contado, sin cuotas.'),

                                Select::make('dia_pago')
                                    ->label('Día de pago')
                                    // Las etiquetas van como STRING: `Select::options()`
                                    // declara `array<array<string>|string>` y un array de
                                    // enteros no lo satisface bajo PHPStan nivel 7.
                                    ->options(self::diasDelMes())
                                    ->required()
                                    ->live()
                                    ->native(false)
                                    ->default(fn (): int => self::configEntero('lotificadora.ventas.dia_pago_default', 5))
                                    ->helperText('En los meses cortos se corre al último día.'),

                                DatePicker::make('fecha_contrato')
                                    ->label('Fecha del contrato')
                                    ->required()
                                    ->live()
                                    ->default(fn (): string => today()->toDateString())
                                    ->native(false)
                                    ->displayFormat('d/m/Y'),

                                /*
                                 * 🔴 QUIEN VENDIO — Y POR QUE ESTA VACIO POR
                                 * DEFECTO.
                                 *
                                 * Vacio quiere decir que la vendio la
                                 * lotificadora, y ese es el caso normal: de
                                 * los 76 expedientes del cuaderno viejo, seis
                                 * traen vendedor. Poner uno por defecto haria
                                 * que todas las ventas de la casa aparecieran
                                 * a nombre de alguien, y el dia que exista la
                                 * comision eso seria plata mal atribuida.
                                 *
                                 * `createOptionForm` deja darlo de alta sin
                                 * salir de la venta: el vendedor nuevo aparece
                                 * cuando el cliente ya esta sentado enfrente,
                                 * no una semana antes.
                                 */
                                Select::make('vendedor_id')
                                    ->label('Vendido por')
                                    ->options(fn (): array => Vendedor::query()
                                        ->activos()
                                        ->orderBy('nombre')
                                        ->pluck('nombre', 'id')
                                        ->all())
                                    ->searchable()
                                    ->native(false)
                                    ->placeholder('La lotificadora')
                                    ->helperText('Dejalo vacío si la venta la cerró la lotificadora.')
                                    ->createOptionForm([
                                        MayusculasField::make('nombre')
                                            ->label('Nombre completo')
                                            ->required()
                                            ->maxLength(150)
                                            ->placeholder('Ej: JONY GERSON GARCÍA MELGAR')
                                            ->columnSpanFull(),

                                        TelefonoHondurasField::make(),
                                    ])
                                    ->createOptionUsing(static fn (array $data): int => (int) Vendedor::query()
                                        ->create($data)
                                        ->getKey())
                                    ->columnSpan(2),

                                Textarea::make('observaciones')
                                    ->label('Observaciones')
                                    ->rows(3)
                                    ->columnSpan(2),
                            ]),

                        Tab::make('Plan de cuotas')
                            ->icon('heroicon-o-calendar-days')
                            ->schema([
                                Section::make('Lo que se va a firmar')
                                    ->description('Se calcula con el mismo motor que después guarda la venta.')
                                    ->icon('heroicon-o-document-check')
                                    ->columns(3)
                                    ->schema([
                                        Placeholder::make('vp_valor')
                                            ->label('Valor de los lotes')
                                            ->content(fn (Get $get): string => self::dato($get, 'valor')),

                                        Placeholder::make('vp_prima')
                                            ->label('Prima')
                                            ->content(fn (Get $get): string => self::dato($get, 'prima')),

                                        Placeholder::make('vp_saldo')
                                            ->label('Saldo a financiar')
                                            ->content(fn (Get $get): string => self::dato($get, 'saldo')),

                                        Placeholder::make('vp_cuota')
                                            ->label('Cuota mensual')
                                            ->content(fn (Get $get): string => self::dato($get, 'cuota')),

                                        Placeholder::make('vp_cantidad')
                                            ->label('Cantidad de cuotas')
                                            ->content(fn (Get $get): string => self::dato($get, 'cantidad')),

                                        Placeholder::make('vp_ultima')
                                            ->label('Última cuota')
                                            ->content(fn (Get $get): string => self::dato($get, 'ultima')),

                                        Placeholder::make('vp_primer_vencimiento')
                                            ->label('Primer vencimiento')
                                            ->content(fn (Get $get): string => self::dato($get, 'primero'))
                                            ->columnSpan(2),

                                        Placeholder::make('vp_ultimo_vencimiento')
                                            ->label('Último vencimiento')
                                            ->content(fn (Get $get): string => self::dato($get, 'ultimo')),
                                    ]),

                                Placeholder::make('vp_nota')
                                    ->label('')
                                    ->content(
                                        'El saldo no genera interés y el atraso no genera mora. '.
                                        'La última cuota absorbe el residuo del redondeo para que la '.
                                        'suma cierre exacta contra el saldo.'
                                    ),
                            ]),
                    ]),
            ]);
    }

    // ─── Vista previa ─────────────────────────────────────────────────

    /**
     * Un dato del resumen, listo para pantalla.
     *
     * Cuando el plan no se puede armar todavía —faltan lotes, la prima
     * supera el valor, el plazo es imposible— devuelve el motivo en vez de
     * un guión: quien está armando la venta necesita saber qué le falta,
     * no quedarse mirando un campo vacío.
     */
    private static function dato(Get $get, string $clave): string
    {
        $resumen = self::resumen($get);

        if (isset($resumen['error'])) {
            return $clave === 'valor' ? $resumen['error'] : '—';
        }

        return $resumen[$clave] ?? '—';
    }

    /**
     * @return array<string, string>
     */
    private static function resumen(Get $get): array
    {
        $renglones = self::renglones($get('detalle'));

        if ($renglones === []) {
            return ['error' => 'Elegí al menos un lote para ver el plan.'];
        }

        $valor = self::sumaDeRenglones($renglones);
        $prima = self::monto($get('prima'));
        $plazo = (int) $get('plazo_meses');
        $diaPago = (int) $get('dia_pago');
        $fecha = self::fecha($get('fecha_contrato'));

        try {
            $plan = PlanDeCuotas::nuevo($valor, $prima, $plazo, $diaPago, $fecha);
        } catch (GrupoOlympoException $e) {
            // El mensaje del dominio ya está escrito para quien atiende.
            return ['error' => $e->getMessage()];
        }

        $ultima = $plan->ultima();

        return [
            'valor'    => $valor->formateado(),
            'prima'    => $prima->formateado(),
            'saldo'    => $plan->saldoFinanciado->formateado(),
            'cuota'    => $plan->cuotaMensual()?->formateado() ?? 'Sin cuotas (contado)',
            'cantidad' => $plan->count() === 0 ? 'Contado' : $plan->count().' cuotas',
            'ultima'   => $ultima?->monto->formateado() ?? '—',
            'primero'  => $plan->cuotas === [] ? '—' : $plan->cuotas[0]->vencimiento->format('d/m/Y'),
            'ultimo'   => $ultima?->vencimiento->format('d/m/Y') ?? '—',
        ];
    }

    // ─── Opciones ─────────────────────────────────────────────────────

    /**
     * Los clientes, menos quien ya es titular.
     *
     * @return array<int, string>
     */
    private static function clientesMenosElTitular(Get $get): array
    {
        $crudo = $get('titular_id');
        $titular = is_numeric($crudo) ? (int) $crudo : 0;

        $elegidos = [];

        if (is_array($get('copropietarios'))) {
            foreach ($get('copropietarios') as $id) {
                if (is_numeric($id)) {
                    $elegidos[] = (int) $id;
                }
            }
        }

        $opciones = [];

        foreach (self::clientes() as $id => $nombre) {
            /*
             * El titular no se OFRECE, pero si YA estaba elegido se sigue
             * listando: sacarlo con el estado apuntándolo tumba el formulario
             * entero por la regla `in`, con un mensaje que no nombra a nadie.
             * El Service lo filtra igual al guardar.
             */
            if ($id !== $titular || in_array($id, $elegidos, true)) {
                $opciones[$id] = $nombre;
            }
        }

        return $opciones;
    }

    /**
     * Los lotes vendibles del proyecto elegido.
     *
     * `../../proyecto_id` y no `proyecto_id`: esto corre DENTRO de una fila
     * del repetidor, y ahi el scope es la fila. Dos saltos suben al
     * formulario — uno sale del item, el otro del repetidor.
     *
     * @return array<int, string>
     */
    private static function lotesDisponibles(Get $get): array
    {
        $proyecto = $get('../../proyecto_id');

        if (blank($proyecto)) {
            return [];
        }

        $unidad = Unidades::de(Proyecto::query()->whereKey($proyecto)->first());

        return self::vendibles()
            ->where('proyecto_id', $proyecto)
            ->get()
            ->mapWithKeys(static fn (Lote $lote): array => [
                (int) $lote->getKey() => sprintf(
                    '%s — %s '.$unidad->abreviada().' — %s '.$unidad->porUnidad().'%s',
                    (string) $lote->getAttribute('codigo'),
                    (string) $lote->getAttribute('area_varas'),
                    new Monto((string) $lote->getAttribute('precio_vara'))->formateado(),
                    $lote->getAttribute('estado') === EstadoLote::Apartado ? ' (apartado)' : '',
                ),
            ])
            ->all();
    }

    /**
     * @return Builder<Lote>
     */
    private static function vendibles(): Builder
    {
        return Lote::query()
            ->whereIn('estado', [EstadoLote::Disponible->value, EstadoLote::Apartado->value])
            ->orderBy('codigo');
    }

    /**
     * El precio de lista del lote, para precargar el campo.
     *
     * Se devuelve como string con dos decimales porque el campo es un
     * MontoField: si acá entrara un float, el §8.3.1 se rompe en el primer
     * lote cuyo precio no sea representable en binario.
     */
    private static function precioDeLista(mixed $loteId, Get $get): string
    {
        $lote = self::lote($loteId);

        return $lote instanceof Lote ? self::lista($lote, $get)->redondeado() : '';
    }

    /**
     * El precio de lista PARA EL PLAZO ELEGIDO.
     *
     * No es el de la ficha del lote: desde que existe la lista por plazo,
     * el precio contra el que se mide un descuento es el del plan que se
     * eligió. Si no, vender de contado al precio de contado de la lista
     * contaría como descuento y pediría motivo (R4) sin haberlo.
     *
     * `../../plazo_meses` porque esto corre dentro de una fila del
     * repetidor: dos saltos suben al formulario.
     */
    private static function lista(Lote $lote, Get $get): Monto
    {
        $proyecto = $lote->getRelationValue('proyecto');

        if (! $proyecto instanceof Proyecto) {
            return new Monto((string) $lote->getAttribute('precio_vara'));
        }

        return app(ListaDePrecios::class)->deListaPara($proyecto, $lote, (int) $get('../../plazo_meses'));
    }

    /**
     * El valor de una fila: área del lote × precio tecleado.
     *
     * Dice también el precio de lista cuando el tecleado es menor, para que
     * quien está armando la venta vea el descuento sin tener que abrir otra
     * pantalla a comparar.
     */
    private static function valorDeFila(Get $get): string
    {
        $lote = self::lote($get('lote_id'));

        if (! $lote instanceof Lote) {
            return '—';
        }

        $area = (string) $lote->getAttribute('area_varas');
        $lista = self::lista($lote, $get);
        $precio = self::monto($get('precio_vara'));
        $valor = new Monto($precio->multiplicarPor($area)->redondeado());

        if (! $precio->menorQue($lista)) {
            return $valor->formateado();
        }

        $descuento = new Monto($lista->restar($precio)->multiplicarPor($area)->redondeado());

        return sprintf(
            '%s  ·  %s menos que la lista',
            $valor->formateado(),
            $descuento->formateado(),
        );
    }

    /**
     * ¿Esta fila va por debajo del precio de lista? (R4)
     */
    private static function hayDescuento(Get $get): bool
    {
        $lote = self::lote($get('lote_id'));

        if (! $lote instanceof Lote) {
            return false;
        }

        return self::monto($get('precio_vara'))->menorQue(self::lista($lote, $get));
    }

    /**
     * El título plegado de cada fila del repetidor.
     *
     * @param array<string, mixed> $state
     */
    private static function rotuloDeFila(array $state): ?string
    {
        $lote = self::lote($state['lote_id'] ?? null);

        return $lote instanceof Lote ? (string) $lote->getAttribute('codigo') : null;
    }

    private static function lote(mixed $id): ?Lote
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Lote::query()->find((int) $id);
    }

    /**
     * Los 31 días, como etiquetas de texto.
     *
     * @return array<int, string>
     */
    private static function diasDelMes(): array
    {
        $dias = [];

        for ($dia = 1; $dia <= 31; $dia++) {
            $dias[$dia] = (string) $dia;
        }

        return $dias;
    }

    /**
     * @return array<int, string>
     */
    private static function clientes(): array
    {
        return Cliente::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->all();
    }

    // ─── Conversiones ─────────────────────────────────────────────────

    /**
     * Las filas del repetidor que ya tienen lote elegido.
     *
     * El estado de un repetidor es un mapa con claves uuid, no una lista, y
     * las filas a medio llenar son normales: alguien apretó «Agregar» y
     * todavía no eligió. Se descartan sin protestar.
     *
     * @return list<array{lote_id: int, precio: string}>
     */
    private static function renglones(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $filas = [];

        foreach ($valor as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            if (! is_numeric($fila['lote_id'] ?? null)) {
                continue;
            }
            $precio = $fila['precio_vara'] ?? null;

            $filas[] = [
                'lote_id' => (int) $fila['lote_id'],
                'precio'  => is_string($precio) || is_int($precio) ? (string) $precio : '0',
            ];
        }

        return $filas;
    }

    /**
     * El valor de la venta: la suma de área × precio PACTADO de cada fila.
     *
     * Una sola consulta para todos los lotes y no una por fila: esto se
     * recalcula en cada tecla que cambia el formulario (§4.L4).
     *
     * @param list<array{lote_id: int, precio: string}> $renglones
     */
    private static function sumaDeRenglones(array $renglones): Monto
    {
        $areas = Lote::query()
            ->whereIn('id', array_column($renglones, 'lote_id'))
            ->pluck('area_varas', 'id');

        $total = Monto::cero();

        foreach ($renglones as $renglon) {
            $area = $areas->get($renglon['lote_id']);

            // Un lote que ya no está —lo borraron mientras se armaba la
            // venta— no suma. El Service lo va a rechazar con nombre propio.
            if (! is_string($area) && ! is_int($area)) {
                continue;
            }

            $total = $total->sumar(
                new Monto(self::monto($renglon['precio'])->multiplicarPor((string) $area)->redondeado())
            );
        }

        return $total;
    }

    /**
     * Un monto a medio tipear no es un error: es alguien escribiendo.
     * Devuelve cero en vez de reventar la vista previa.
     */
    private static function monto(mixed $valor): Monto
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

    private static function fecha(mixed $valor): CarbonImmutable
    {
        if (is_string($valor) && $valor !== '') {
            try {
                return CarbonImmutable::parse($valor);
            } catch (Throwable) {
                // Fecha a medio escribir: se usa hoy para la vista previa.
            }
        }

        return CarbonImmutable::parse(today()->toDateString());
    }

    private static function configEntero(string $clave, int $porDefecto): int
    {
        $valor = config($clave, $porDefecto);

        return is_int($valor) ? $valor : $porDefecto;
    }

    /**
     * Las tres de R11. Cheque no esta, y no se agrega «por si acaso».
     *
     * @return array<string, string>
     */
    private static function formasDePago(): array
    {
        $opciones = [];

        foreach (FormaDePago::cases() as $forma) {
            $opciones[$forma->value] = $forma->etiqueta();
        }

        return $opciones;
    }

    private static function exigeReferenciaDeLaPrima(Get $get): bool
    {
        $forma = $get('forma_pago_prima');

        return is_string($forma)
            && FormaDePago::tryFrom($forma)?->exigeReferencia() === true;
    }
}
