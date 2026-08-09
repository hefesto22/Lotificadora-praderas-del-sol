<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Domain\Enums\ModalidadDeMora;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\TasaDeInteres;
use App\Filament\Schemas\Components\MontoField;
use App\Models\PlanDePago;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Override;

/**
 * El precio de la vara², el interes y la mora, a cada plazo.
 *
 * Vive acá, en el proyecto, y no en un archivo de configuración: quien
 * decide estos números es la administración, y tiene que poder cambiarlos
 * sin tocar código ni esperar un despliegue.
 *
 * ═══ SON DOS COSAS DISTINTAS, Y CONVIENE NO MEZCLARLAS ═══
 *
 * El **precio de la vara²** cambia con el plazo porque «no es el mismo precio
 * de vara a 1 año que a 4» (Mauricio, 5-ago-2026). Eso NO es interés: elegido
 * el plazo, el precio queda fijo y la cuota es una división.
 *
 * El **interés** es otra cosa: hace que el saldo financiado devengue, parte
 * cada cuota en capital e interés, y obliga a imprimir los dos por separado.
 * Una lotificadora puede usar uno, el otro, o los dos.
 *
 * Praderas del Sol usa solo el primero — R1 y R2, contestadas por la
 * contratante el 3-ago-2026 — y por eso los campos de interés y mora **nacen
 * apagados**. El sistema es un producto: las demás lotificadoras deciden.
 *
 * El plazo 0 es contado. Si no se carga, el plano cotiza cada lote a su
 * propio precio. Un plan de contado no puede llevar interés: no hay saldo que
 * devengue, y lo frena tanto esta pantalla como el CHECK de la base.
 */
class PlanesDePagoRelationManager extends RelationManager
{
    /** El lote tipo del plano: 233 de los 301 lotes de Praderas miden esto. */
    private const string VARAS_DE_REFERENCIA = '250';

    #[Override]
    protected static string $relationship = 'planesDePago';

    #[Override]
    protected static ?string $title = 'Planes de pago';

    /**
     * El tipo tiene que ser IDÉNTICO al del padre —`string|BackedEnum|null`,
     * no `?string`—: PHP exige la firma exacta al redeclarar una propiedad
     * tipada, y con una estática eso revienta al cargar la clase.
     */
    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-calculator';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('meses')
                    ->label('Plazo en meses')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(PlanDeCuotas::PLAZO_MAXIMO_MESES)
                    ->required()
                    ->live(onBlur: true)
                    /*
                     * 🔴 El texto de abajo prometia algo que el formulario no
                     * cumplia: «no puede repetirse dentro del proyecto» lo
                     * hacia cumplir un indice unico de la base, y sin esta
                     * regla el segundo plan a 12 meses no era un aviso en el
                     * campo — era una pantalla de error 500 con la consulta
                     * SQL adentro, en la cara de la administradora.
                     *
                     * El alcance es el proyecto y no la tabla entera: dos
                     * lotificaciones distintas pueden ofrecer 12 meses cada
                     * una, y de hecho es lo normal.
                     */
                    /*
                     * ⚠️ El parametro TIENE que llamarse `$rule`. Filament
                     * inyecta en estos cierres por NOMBRE y, cuando el nombre
                     * no le suena, cae a resolver por TIPO desde el
                     * contenedor: `Unique` pide un `$table` en su
                     * constructor, no lo encuentra, y lo que se ve es un
                     * «Unresolvable dependency» que no menciona ni al campo
                     * ni al formulario.
                     */
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                            'proyecto_id',
                            $this->getOwnerRecord()->getKey(),
                        ),
                    )
                    ->validationMessages([
                        'unique' => 'Este proyecto ya tiene un plan a ese plazo. Editá el que existe o usá otro número de meses.',
                    ])
                    ->helperText('0 es contado. No puede repetirse dentro del proyecto.'),

                MontoField::make('precio_vara', 'Precio por vara²')
                    ->required()
                    ->helperText('Lo que cuesta la vara² si el cliente elige este plazo.'),

                TextInput::make('etiqueta')
                    ->label('Nombre en pantalla')
                    ->maxLength(60)
                    ->placeholder('12 meses')
                    ->helperText('Opcional. Para los casos que el número no explica: «12 meses (feria)».'),

                Toggle::make('activo')
                    ->label('Se ofrece')
                    ->default(true)
                    ->helperText('Un plan que se deja de ofrecer se apaga, no se borra: las ventas firmadas con él siguen existiendo.'),

                // ─── Interés ──────────────────────────────────────────

                TextInput::make('tasa_interes_anual')
                    ->label('Interés anual')
                    ->numeric()
                    ->suffix('%')
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(TasaDeInteres::MAXIMA)
                    ->required()
                    ->helperText('0 es sin interés, y es lo normal acá: el precio de la vara² ya sube con el plazo. Se dice «anual» y se divide entre 12, como los bancos.')
                    /*
                     * Un plan de CONTADO no financia nada, asi que no puede
                     * devengar. La base tiene el mismo CHECK; esto es para que
                     * el mensaje lo escriba alguien y no Postgres.
                     */
                    ->rule(static fn (Get $get): Closure => static function (string $atributo, mixed $valor, Closure $fallar) use ($get): void {
                        $texto = is_string($valor) || is_int($valor) ? trim((string) $valor) : '0';

                        /*
                         * `is_numeric()` no valida de mas: es lo unico que le
                         * deja a PHPStan estrechar `string` a `numeric-string`,
                         * que es lo que exige bcmath. Del formato ya se encarga
                         * `->numeric()`. Mismo argumento que en
                         * `Monto::normalizar()`.
                         */
                        if (! is_numeric($texto)) {
                            return;
                        }

                        if ((int) $get('meses') === 0 && bccomp($texto, '0', 3) > 0) {
                            $fallar('Un plan de contado no financia nada, así que no puede cobrar interés. Dejalo en 0.');
                        }
                    }),

                // ─── Mora ─────────────────────────────────────────────

                Select::make('mora_modalidad')
                    ->label('Mora por atraso')
                    ->options($this->modalidades())
                    ->default(ModalidadDeMora::Ninguna->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->helperText(static fn (Get $get): string => self::modalidadDe($get)->ayuda()),

                TextInput::make('mora_dias_gracia')
                    ->label('Días de gracia')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(90)
                    ->required()
                    ->visible(static fn (Get $get): bool => self::modalidadDe($get)->cobra())
                    ->helperText('Días desde el vencimiento antes de que la mora empiece a correr. La mora se cuenta DESDE que la gracia termina, así que el día 6 con 5 de gracia paga un día, no seis.'),

                MontoField::make('mora_monto', 'Monto de la mora')
                    ->required()
                    ->visible(static fn (Get $get): bool => self::modalidadDe($get)->usaMonto())
                    ->helperText('En lempiras. No escala con el monto del lote: al lote caro no le va a doler.'),

                TextInput::make('mora_porcentaje')
                    ->label('Porcentaje de la mora')
                    ->numeric()
                    ->suffix('%')
                    ->minValue(0)
                    ->maxValue(TasaDeInteres::MAXIMA)
                    ->required()
                    ->visible(static fn (Get $get): bool => self::modalidadDe($get)->usaTasa())
                    ->helperText(static fn (Get $get): string => self::modalidadDe($get) === ModalidadDeMora::TasaAnual
                        ? 'Anual, prorrateada por días reales sobre el saldo vencido. 24 % sobre una cuota de L 14,583 son L 9.59 por día.'
                        : 'Mensual, sobre lo que se debe de esa cuota. Ojo: un día de atraso cuesta lo mismo que veintinueve.'),
            ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('meses')
            ->columns([
                TextColumn::make('meses')
                    ->label('Plazo')
                    ->badge()
                    ->color(fn (PlanDePago $record): string => $record->esDeContado() ? 'success' : 'info')
                    ->formatStateUsing(fn (PlanDePago $record): string => $record->nombre())
                    ->sortable(),

                TextColumn::make('precio_vara')
                    ->label('Precio por vara²')
                    ->formatStateUsing(fn (PlanDePago $record): string => $record->montoPrecioVara()->formateado())
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('tasa_interes_anual')
                    ->label('Interés')
                    ->badge()
                    ->color(fn (PlanDePago $record): string => $record->tasaDeInteres()->esCero() ? 'gray' : 'warning')
                    ->formatStateUsing(fn (PlanDePago $record): string => $record->tasaDeInteres()->esCero()
                        ? 'Sin interés'
                        : $record->tasaDeInteres()->formateada().' anual')
                    ->alignEnd()
                    ->sortable(),

                /*
                | El lote tipo del plano, para que el número se pueda leer
                | sin sacar la calculadora. 250 vr² es la medida de 233 de
                | los 301 lotes de Praderas.
                */
                TextColumn::make('referencia')
                    ->label('Un lote de 250 vr²')
                    ->state(fn (PlanDePago $record): string => $this->valorDeReferencia($record)->formateado())
                    ->alignEnd()
                    ->color('gray'),

                /*
                | La cuota de ese mismo lote, que es lo que el cliente
                | pregunta. Con interés incluye el interés: el número que se
                | muestra tiene que ser el que se va a cobrar (§10.8).
                */
                TextColumn::make('cuota')
                    ->label('Cuota mensual')
                    ->state(fn (PlanDePago $record): string => $this->cuotaDeReferencia($record))
                    ->description(fn (PlanDePago $record): ?string => $this->interesDeReferencia($record))
                    ->alignEnd(),

                TextColumn::make('mora_modalidad')
                    ->label('Mora')
                    ->formatStateUsing(fn (PlanDePago $record): string => $record->condicionesDeMora()->descripcion())
                    ->color(fn (PlanDePago $record): string => $record->condicionesDeMora()->cobra() ? 'warning' : 'gray')
                    ->wrap()
                    ->toggleable(),

                IconColumn::make('activo')
                    ->label('Se ofrece')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo plan'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Si este plan ya se usó en una venta, mejor apagalo en vez de borrarlo: la venta conserva su precio y su tasa congelados, pero el plan deja de aparecer en el historial.'),
            ])
            ->defaultSort('meses')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay planes de pago')
            ->emptyStateDescription('Cargá el precio de la vara² a cada plazo. Mientras esté vacío, el plano cotiza cada lote a su propio precio y no muestra cuotas.')
            ->emptyStateIcon('heroicon-o-calculator');
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function modalidades(): array
    {
        $opciones = [];

        foreach (ModalidadDeMora::cases() as $modalidad) {
            $opciones[$modalidad->value] = $modalidad->etiqueta();
        }

        return $opciones;
    }

    private static function modalidadDe(Get $get): ModalidadDeMora
    {
        $valor = $get('mora_modalidad');

        return self::comoModalidad($valor);
    }

    private static function comoModalidad(mixed $valor): ModalidadDeMora
    {
        if ($valor instanceof ModalidadDeMora) {
            return $valor;
        }

        return ModalidadDeMora::tryFrom(is_string($valor) ? $valor : '') ?? ModalidadDeMora::Ninguna;
    }

    private function valorDeReferencia(PlanDePago $plan): Monto
    {
        return new Monto(
            $plan->montoPrecioVara()->multiplicarPor(self::VARAS_DE_REFERENCIA)->redondeado()
        );
    }

    /**
     * La cuota de un lote de 250 vr² a este plazo, sin prima.
     *
     * Se calcula con el MISMO motor que firma las ventas —`PlanDeCuotas`— y no
     * con una división aparte: el día que la lista de precios y el contrato
     * digan números distintos, el cliente firma uno y la base guarda otro.
     */
    private function cuotaDeReferencia(PlanDePago $plan): string
    {
        if ($plan->esDeContado()) {
            return '—';
        }

        try {
            $cuota = PlanDeCuotas::nuevo(
                $this->valorDeReferencia($plan),
                Monto::cero(),
                (int) $plan->getAttribute('meses'),
                1,
                CarbonImmutable::parse(today()->toDateString()),
                $plan->tasaDeInteres(),
            )->cuotaMensual();
        } catch (GrupoOlympoException) {
            // Un plan imposible —precio en cero, plazo absurdo— no puede
            // tumbar la lista de precios entera.
            return '—';
        }

        return $cuota?->formateado() ?? '—';
    }

    /**
     * Lo que el interés le agrega al lote de referencia, para que el número
     * no se descubra recién cuando el cliente sume las 48 cuotas.
     */
    private function interesDeReferencia(PlanDePago $plan): ?string
    {
        if ($plan->esDeContado() || $plan->tasaDeInteres()->esCero()) {
            return null;
        }

        try {
            $intereses = PlanDeCuotas::nuevo(
                $this->valorDeReferencia($plan),
                Monto::cero(),
                (int) $plan->getAttribute('meses'),
                1,
                CarbonImmutable::parse(today()->toDateString()),
                $plan->tasaDeInteres(),
            )->totalInteres();
        } catch (GrupoOlympoException) {
            return null;
        }

        return '+ '.$intereses->formateado().' de intereses';
    }
}
