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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
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

                                Select::make('lotes')
                                    ->label('Lotes')
                                    ->multiple()
                                    ->required()
                                    ->live()
                                    ->columnSpanFull()
                                    ->options(fn (Get $get): array => self::lotesDisponibles($get))
                                    ->disabled(fn (Get $get): bool => blank($get('proyecto_id')))
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
        $ids = self::ids($get('lotes'));

        if ($ids === []) {
            return ['error' => 'Elegí al menos un lote para ver el plan.'];
        }

        $valor = self::sumaDeValores($ids);
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
     * @return array<int, string>
     */
    private static function lotesDisponibles(Get $get): array
    {
        $proyecto = $get('proyecto_id');

        if (blank($proyecto)) {
            return [];
        }

        return Lote::query()
            ->where('proyecto_id', $proyecto)
            ->whereIn('estado', [EstadoLote::Disponible->value, EstadoLote::Apartado->value])
            ->orderBy('codigo')
            ->get()
            ->mapWithKeys(static fn (Lote $lote): array => [
                (int) $lote->getKey() => sprintf(
                    '%s — %s vr² — %s%s',
                    (string) $lote->getAttribute('codigo'),
                    (string) $lote->getAttribute('area_varas'),
                    $lote->montoValor()->formateado(),
                    $lote->getAttribute('estado') === EstadoLote::Apartado ? ' (apartado)' : '',
                ),
            ])
            ->all();
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
     * @return list<int>
     */
    private static function ids(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $valor,
        )));
    }

    /**
     * @param list<int> $ids
     */
    private static function sumaDeValores(array $ids): Monto
    {
        $total = Monto::cero();

        foreach (Lote::query()->whereIn('id', $ids)->get() as $lote) {
            $total = $total->sumar($lote->montoValor());
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
