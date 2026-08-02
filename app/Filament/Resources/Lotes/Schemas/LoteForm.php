<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Schemas;

use App\Domain\Enums\EstadoLote;
use App\Filament\Schemas\Components\AreaField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Bloque;
use App\Models\Lote;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LoteForm
{
    /**
     * §8.2: un lote vendido no se edita en área ni precio.
     *
     * El modelo lanza LoteInmutableException y un trigger de PostgreSQL lo
     * impide igual, pero si el formulario dejara los campos habilitados la
     * administradora escribiría un precio nuevo, apretaría guardar y
     * recibiría una excepción. Correcto, pero mala UX: mejor no dejarla
     * intentarlo y explicarle por qué.
     *
     * §9.A2: en CREATE el schema recibe un modelo VACÍO, no null. Por eso
     * se lee el estado con getRawOriginal contra el value del enum, en vez
     * de asumir que hay una instancia casteada.
     */
    private static function estaVendido(?Lote $record): bool
    {
        return $record?->getRawOriginal('estado') === EstadoLote::Vendido->value;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Lote')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('Ubicación')
                            ->icon('heroicon-o-map-pin')
                            ->columns(3)
                            ->schema([
                                Select::make('proyecto_id')
                                    ->label('Proyecto')
                                    ->relationship('proyecto', 'nombre')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->disabledOn('edit')
                                    ->helperText('No se puede mover un lote de proyecto: rompería la trazabilidad de su venta.'),

                                Select::make('bloque_id')
                                    ->label('Bloque')
                                    ->options(fn (Get $get): array => Bloque::query()
                                        ->where('proyecto_id', $get('proyecto_id'))
                                        ->orderBy('orden')
                                        ->orderBy('nombre')
                                        ->pluck('nombre', 'id')
                                        ->all())
                                    ->searchable()
                                    ->required()
                                    ->disabled(fn (Get $get, ?Lote $record): bool => $record?->exists === true || blank($get('proyecto_id')))
                                    // La FK compuesta (bloque_id, proyecto_id) de la base
                                    // rechaza un bloque de otro proyecto. Acá el selector
                                    // directamente no los ofrece.
                                    ->helperText('Solo se listan los bloques del proyecto elegido.'),

                                MayusculasField::make('numero')
                                    ->label('Número de lote')
                                    ->required()
                                    ->maxLength(20)
                                    ->prefixIcon('heroicon-o-hashtag')
                                    ->placeholder('12 o 12-A')
                                    ->helperText('Único dentro del bloque.'),
                            ]),

                        Tab::make('Medidas y precio')
                            ->icon('heroicon-o-calculator')
                            ->columns(3)
                            ->schema([
                                AreaField::make('area_varas', 'Área')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->disabled(fn (?Lote $record): bool => self::estaVendido($record))
                                    ->afterStateUpdatedJs(self::JS_CALCULAR_VALOR),

                                MontoField::make('precio_vara', 'Precio por vara²')
                                    ->live(onBlur: true)
                                    ->disabled(fn (?Lote $record): bool => self::estaVendido($record))
                                    ->afterStateUpdatedJs(self::JS_CALCULAR_VALOR),

                                TextInput::make('valor')
                                    ->label('Valor del lote')
                                    ->prefix('L')
                                    ->disabled()
                                    // NUNCA se envía: lo calcula el modelo con bcmath en
                                    // el hook saving(). Si el formulario lo mandara, se
                                    // podría guardar un valor que no cuadre con
                                    // área × precio, justo lo que el golden test del
                                    // §9.C9 existe para impedir.
                                    ->dehydrated(false)
                                    ->helperText('Se calcula solo: área × precio por vara².'),

                                Textarea::make('observaciones')
                                    ->label('Observaciones')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Estado')
                            ->icon('heroicon-o-flag')
                            ->schema([
                                Select::make('estado')
                                    ->label('Estado del lote')
                                    ->options(fn (): array => collect(EstadoLote::cases())
                                        ->mapWithKeys(fn (EstadoLote $estado): array => [$estado->value => $estado->etiqueta()])
                                        ->all())
                                    ->default(EstadoLote::Disponible->value)
                                    ->required()
                                    ->native(false)
                                    ->helperText(
                                        'Los cuatro estados son contractuales. Un lote vendido '.
                                        'queda con su área y su precio congelados.'
                                    ),

                                Section::make('Información del registro')
                                    ->description('Datos de auditoría que mantiene el sistema.')
                                    ->icon('heroicon-o-information-circle')
                                    ->visibleOn('edit')
                                    ->columns(2)
                                    ->schema([
                                        Placeholder::make('valor_vigente')
                                            ->label('Valor vigente')
                                            ->content(static fn (?Lote $record): string => $record?->montoValor()->formateado() ?? '—'),

                                        Placeholder::make('editable')
                                            ->label('Área y precio')
                                            ->content(fn (?Lote $record): string => self::estaVendido($record)
                                                ? 'Bloqueados: el lote está vendido y su valor quedó congelado en la venta.'
                                                : 'Editables.'),

                                        Placeholder::make('creado_en')
                                            ->label('Creado')
                                            ->content(static function (?Lote $record): string {
                                                $fecha = $record?->getAttribute('created_at');

                                                return $fecha instanceof CarbonInterface ? fechaLarga($fecha) : '—';
                                            }),

                                        Placeholder::make('actualizado_en')
                                            ->label('Última modificación')
                                            ->content(static function (?Lote $record): string {
                                                $fecha = $record?->getAttribute('updated_at');

                                                return $fecha instanceof CarbonInterface ? haceCuanto($fecha) : '—';
                                            }),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Vista previa del valor mientras se tipea, sin round-trip (§9.A10).
     *
     * Usa BigInt y no `area * precio`: en coma flotante ese producto se
     * equivoca por un centavo en 1 de cada 7.143 combinaciones reales de
     * área y precio —está medido, ver el golden test de MontoTest—, y la
     * administradora vería un número mientras el sistema guarda otro. Con
     * enteros el resultado es idéntico al de bcmath en el servidor.
     *
     * Aun así esto es SOLO presentación: el campo valor no se envía y el
     * valor real lo calcula el modelo.
     */
    private const string JS_CALCULAR_VALOR = <<<'JS'
        const aEntero = (valor, decimales) => {
            const [entera, decimal = ''] = String(valor ?? '0').split('.');
            const digitos = (decimal + '0'.repeat(decimales)).slice(0, decimales);

            return BigInt((entera === '' ? '0' : entera) + digitos);
        };

        try {
            const area = aEntero($get('area_varas'), 4);
            const precio = aEntero($get('precio_vara'), 2);
            const centavos = (area * precio + 5000n) / 10000n;

            $set('valor', (Number(centavos) / 100).toFixed(2));
        } catch (e) {
            $set('valor', null);
        }
    JS;
}
