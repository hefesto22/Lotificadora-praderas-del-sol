<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Schemas;

use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Cliente;
use App\Models\Lote;
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
                                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                                $set('precio_vara', self::precioDeLista($state));
                                                $set('motivo_descuento', null);
                                            }),

                                        MontoField::make('precio_vara', 'Precio por vara²')
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
                                    ->helperText('A su nombre sale el estado de cuenta.'),

                                Select::make('copropietarios')
                                    ->label('Copropietarios')
                                    ->multiple()
                                    ->options(fn (): array => self::clientes())
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

        return self::vendibles()
            ->where('proyecto_id', $proyecto)
            ->get()
            ->mapWithKeys(static fn (Lote $lote): array => [
                (int) $lote->getKey() => sprintf(
                    '%s — %s vr² — %s la vara²%s',
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
    private static function precioDeLista(mixed $loteId): string
    {
        $lote = self::lote($loteId);

        return $lote instanceof Lote
            ? new Monto((string) $lote->getAttribute('precio_vara'))->redondeado()
            : '';
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
        $lista = new Monto((string) $lote->getAttribute('precio_vara'));
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

        return self::monto($get('precio_vara'))
            ->menorQue(new Monto((string) $lote->getAttribute('precio_vara')));
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
}
