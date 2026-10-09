<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Domain\Archivos\GuardadoDeArchivos;
use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\FormaDePago;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Socio;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * El formulario de un gasto del proyecto, en UN solo lugar.
 *
 * ═══ POR QUE SE MUDO ACA ═══
 *
 * Hasta el 8-oct-2026 vivía adentro de `GastosRelationManager`. Ese día el
 * cliente pidió un botón para registrar un gasto desde la lista de proyectos,
 * «para registrarlo más rápido», sin entrar a la ficha. Copiar el formulario
 * en la tabla habría dejado dos formularios del mismo gasto, y el día que uno
 * cambie —un campo nuevo, una regla de R11— el otro se queda atrás sin que
 * nadie lo note. La pestaña y el botón de la lista usan ESTE.
 *
 * El proyecto llega por parámetro y no se lee del `$record`: desde la lista,
 * el `$record` es el proyecto; desde la pestaña, es el gasto.
 *
 * ═══ A QUIEN SE LE CARGA (8-OCT-2026) ═══
 *
 * «Lo más importante: al registrar un gasto, registrar a quién de los socios
 * se le carga.» Va PRIMERO en el formulario por eso. Las reglas de plata están
 * en `Gasto` y en `CierreDelMes`; acá solo se pregunta.
 */
final class GastoForm
{
    /**
     * El valor de la opción «Al proyecto». En la base es NULL; un botón de
     * opción no puede tener NULL como valor, así que viaja con nombre y se
     * traduce al guardar (`dehydrateStateUsing`).
     */
    public const string AL_PROYECTO = 'proyecto';

    /**
     * La pestaña «Socios» del formulario del proyecto, tal como Filament la
     * nombra en la URL (`persistTabInQueryString()` de `ProyectoForm`): el
     * rótulo en slug, el `statePath` del formulario y `::tab`. Si la pestaña
     * cambia de nombre, el enlace abre en la primera pestaña — no se rompe.
     */
    private const string PESTANA_SOCIOS = 'socios::data::tab';

    /**
     * ═══ COMO SE VE (8-OCT-2026, SEGUNDA VUELTA) ═══
     *
     * «Que se vea más bonito, se ve feo» — Mauricio, con el modal abierto
     * desde la lista: un campo debajo del otro, a todo el ancho, cada uno con
     * su párrafo de ayuda. Era una planilla, no un formulario.
     *
     *   - Tres tarjetas, en el orden en que se decide: a quién se le carga, el
     *     gasto, el comprobante.
     *   - Los datos del gasto en DOS columnas, en el orden en que se leen de
     *     la factura: cuándo y en qué, el detalle, cuánto y cómo, a quién.
     *   - El comprobante aparte, porque es opcional.
     *   - Las ayudas, cortas. Lo que era un párrafo pasó a ser el ejemplo
     *     dentro del campo (`placeholder`), que desaparece al escribir.
     *
     * Y la pregunta del socio se ve SIEMPRE, también en un proyecto sin
     * socios: escondida, nadie sabía que existía. Sin socios ofrece solo «Al
     * proyecto» y dice dónde se cargan.
     *
     * ═══ UNA TARJETA POR OPCION, NO UN RIEL (TERCERA VUELTA) ═══
     *
     * «Me gusta el toggle, pero se ve raro, como si fuese uno solo» —
     * Mauricio, ya en producción y con los dos socios cargados. El riel de
     * pastillas del cobro sirve para TRES palabras cortas; con nombres
     * completos las pastillas bajaban de renglón dentro del mismo fondo gris y
     * se leían como un solo bloque partido.
     *
     * Ahora cada opción es su propia tarjeta (`olympo-tarjetas`, §12-quater de
     * `tema-olympo`): el nombre arriba, su parte del reparto abajo, y la
     * elegida con el borde y el tinte del color primario. Con uno, dos o cinco
     * socios se acomodan solas en la cuadrícula, y en el celular quedan una
     * debajo de la otra.
     *
     * @return list<Component>
     */
    public static function componentes(Proyecto $proyecto): array
    {
        return [
            Section::make('¿A quién se le carga?')
                ->icon(Heroicon::OutlinedUsers)
                ->description(static fn (mixed $record): string|HtmlString => self::queQuiereDecir(
                    $proyecto,
                    self::aQuienSeLeCarga($proyecto, self::elGasto($record)),
                ))
                ->compact()
                ->columnSpanFull()
                ->schema([
                    Radio::make('socio_id')
                        // El rótulo no se dibuja —lo dice la tarjeta—, pero
                        // es el que usa el mensaje de validación.
                        ->label('A quién se le carga')
                        ->hiddenLabel()
                        /*
                         * `$record` es el GASTO cuando se edita desde la
                         * pestaña, y el PROYECTO cuando se registra desde la
                         * lista. Solo en el primer caso hay un socio actual
                         * que conservar.
                         */
                        ->options(static fn (mixed $record): array => self::aQuienSeLeCarga($proyecto, self::elGasto($record)))
                        ->descriptions(static fn (mixed $record): array => self::comoSeReparte($proyecto, self::elGasto($record)))
                        ->default(self::AL_PROYECTO)
                        ->required()
                        ->inline()
                        ->extraAttributes(['class' => 'olympo-tarjetas'])
                        ->formatStateUsing(static fn (mixed $state): string => self::esUnSocio($state) ? (string) $state : self::AL_PROYECTO)
                        ->dehydrateStateUsing(static fn (mixed $state): ?int => self::esUnSocio($state) ? (int) $state : null),
                ]),

            Section::make('El gasto')
                ->icon(Heroicon::OutlinedBanknotes)
                ->compact()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    DatePicker::make('fecha')
                        ->label('Fecha')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(today())
                        /*
                         * No se puede fechar un gasto en el futuro. No lo frena
                         * la base —un CHECK con CURRENT_DATE no es inmutable y
                         * Postgres no lo admite—, así que la raya se pone acá.
                         */
                        ->maxDate(today())
                        ->helperText('La del comprobante.'),

                    Select::make('categoria')
                        ->label('En qué se gastó')
                        ->options(CategoriaDeGasto::opciones())
                        ->required()
                        ->native(false)
                        ->searchable(),

                    Textarea::make('descripcion')
                        ->label('Detalle')
                        ->required()
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull()
                        // Concreto: dentro de un año «Materiales — L 48,000»
                        // no le dice nada a nadie.
                        ->placeholder('Ej.: cemento y varilla para las cunetas del bloque H'),

                    MontoField::make('monto', 'Monto')
                        // La base exige > 0 (`gastos_monto_positivo_chk`). Acá
                        // se dice antes, para que no se entere al guardar.
                        ->rules(['numeric', 'decimal:0,2', 'gt:0']),

                    Select::make('forma_pago')
                        ->label('Cómo se pagó')
                        ->options(self::formasDePago())
                        ->required()
                        ->live()
                        ->native(false)
                        ->default(FormaDePago::Efectivo->value),

                    MayusculasField::make('beneficiario')
                        ->label('A quién se le pagó')
                        ->maxLength(120)
                        /*
                         * Opcional: hay gastos sin contraparte con nombre. Va
                         * como `hint` y no como `placeholder`: el campo se
                         * escribe en mayúsculas y el ejemplo salía «OPCIONAL».
                         */
                        ->hint('Opcional'),

                    TextInput::make('factura')
                        ->label('Nº de factura o recibo')
                        ->maxLength(60)
                        // El del papel del proveedor; no es la referencia del banco.
                        ->hint('Opcional'),

                    TextInput::make('referencia')
                        ->label('Número de referencia')
                        ->maxLength(60)
                        ->columnSpanFull()
                        ->visible(static fn (Get $get): bool => self::exigeReferencia($get))
                        ->required(static fn (Get $get): bool => self::exigeReferencia($get))
                        ->helperText('Para cruzar esta salida con el estado de cuenta del banco (R11).'),
                ]),

            Section::make('Comprobante')
                ->description('Opcional. La foto de la factura o el PDF, hasta 10 MB.')
                ->icon(Heroicon::OutlinedPaperClip)
                ->compact()
                ->columnSpanFull()
                ->schema([
                    /*
                     * `preserveFilenames` NO: el nombre original trae acentos,
                     * espacios y a veces el nombre del proveedor. Filament le
                     * pone un uuid, y el nombre legible se arma al descargar.
                     */
                    FileUpload::make('archivo')
                        ->hiddenLabel()
                        ->disk(Gasto::DISCO)
                        ->directory('gastos')
                        ->visibility('private')
                        ->maxSize(10240)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        /*
                         * 🔴 El guardado pasa por `GuardadoDeArchivos`: una foto
                         * de teléfono entra en 2–5 MB y sale en WebP de 250–400
                         * KB, con el lado largo topado en 2,400 px. El PDF pasa
                         * intacto, y ante cualquier problema —GD sin WebP,
                         * imagen corrupta— se guarda el original: un
                         * comprobante no se pierde por optimizarlo.
                         *
                         * El PESO no se calcula acá. Lo lee el modelo del disco
                         * después de convertir, que es el único momento en que
                         * el número es cierto.
                         */
                        /*
                         * 🔴 LOS NOMBRES `$component` Y `$file` SON UN CONTRATO.
                         *
                         * Filament evalúa este closure con `evaluate($callback,
                         * ['file' => $file])`: busca los parámetros POR NOMBRE
                         * en ese arreglo y, al que no encuentra, lo pide al
                         * contenedor. Llamarle `$subido` hace que Filament
                         * intente construir un `TemporaryUploadedFile` desde
                         * cero y reviente con `BindingResolutionException:
                         * Unresolvable dependency [$path]`.
                         *
                         * Por eso van en inglés, contra el estilo del resto del
                         * repo: acá el nombre no es una decisión nuestra.
                         */
                        ->saveUploadedFileUsing(static fn (BaseFileUpload $component, TemporaryUploadedFile $file): ?string => resolve(GuardadoDeArchivos::class)->guardar($component, $file)),
                ]),
        ];
    }

    /**
     * Las opciones de «¿A quién se le carga?» — el NOMBRE de cada tarjeta: el
     * proyecto y cada socio que hoy participa del reparto.
     *
     * @return array<int|string, string>
     */
    public static function aQuienSeLeCarga(Proyecto $proyecto, ?Gasto $gasto = null): array
    {
        $opciones = [];

        foreach (self::tarjetas($proyecto, $gasto) as $valor => $tarjeta) {
            $opciones[$valor] = $tarjeta['nombre'];
        }

        return $opciones;
    }

    /**
     * La segunda línea de cada tarjeta: qué parte del reparto tiene ese socio,
     * o qué quiere decir «Al proyecto».
     *
     * @return array<int|string, string>
     */
    public static function comoSeReparte(Proyecto $proyecto, ?Gasto $gasto = null): array
    {
        $detalles = [];

        foreach (self::tarjetas($proyecto, $gasto) as $valor => $tarjeta) {
            $detalles[$valor] = $tarjeta['detalle'];
        }

        return $detalles;
    }

    /**
     * Las opciones del filtro de la pestaña: el proyecto y TODOS los socios
     * que tuvo, también los que salieron. Un filtro que no deja buscar lo que
     * se le cargó a un socio que ya se fue esconde justo lo que hay que mirar.
     *
     * @return array<int|string, string>
     */
    public static function opcionesDelFiltro(Proyecto $proyecto): array
    {
        $opciones = [self::AL_PROYECTO => 'Al proyecto'];

        $socios = Socio::query()
            ->withTrashed()
            ->where('proyecto_id', $proyecto->getKey())
            ->orderBy('nombre')
            ->get();

        foreach ($socios as $socio) {
            $opciones[(int) $socio->getKey()] = (string) $socio->getAttribute('nombre');
        }

        return $opciones;
    }

    /**
     * La pestaña «Socios» del proyecto, donde se cargan las partes del
     * reparto. Es a donde manda el formulario del gasto cuando el proyecto
     * todavía no tiene a quién cargarle nada.
     */
    public static function dondeSeCarganLosSocios(Proyecto $proyecto): string
    {
        return ProyectoResource::getUrl('edit', ['record' => $proyecto, 'tab' => self::PESTANA_SOCIOS]);
    }

    /**
     * @return array<string, string>
     */
    public static function formasDePago(): array
    {
        $opciones = [];

        foreach (FormaDePago::cases() as $forma) {
            $opciones[$forma->value] = $forma->etiqueta();
        }

        return $opciones;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Las tarjetas de «¿A quién se le carga?», con sus dos renglones.
     *
     * Al EDITAR se agrega el socio que el gasto ya tiene aunque hoy esté
     * inactivo o borrado: si no, no habría tarjeta que marcar y guardar el
     * formulario le cambiaría en silencio a quién se le carga.
     *
     * @return array<int|string, array{nombre: string, detalle: string}>
     */
    private static function tarjetas(Proyecto $proyecto, ?Gasto $gasto): array
    {
        $socios = $proyecto->socios()->activos()->get();

        $tarjetas = [
            self::AL_PROYECTO => [
                'nombre'  => 'Al proyecto',
                'detalle' => $socios->isEmpty() ? 'Es del desarrollo' : 'Lo pagan todos, según su parte',
            ],
        ];

        foreach ($socios as $socio) {
            $tarjetas[(int) $socio->getKey()] = [
                'nombre'  => (string) $socio->getAttribute('nombre'),
                'detalle' => sprintf('%s %% del reparto', self::parte($socio)),
            ];
        }

        $actual = $gasto?->getAttribute('socio_id');

        if (! self::esUnSocio($actual)) {
            return $tarjetas;
        }

        $id = (int) $actual;

        if (! array_key_exists($id, $tarjetas)) {
            $socio = Socio::query()->withTrashed()->whereKey($id)->first();

            if ($socio instanceof Socio) {
                $tarjetas[$id] = [
                    'nombre'  => (string) $socio->getAttribute('nombre'),
                    'detalle' => 'Ya no participa del reparto',
                ];
            }
        }

        return $tarjetas;
    }

    /**
     * `$record` es el gasto al editar desde la pestaña y el proyecto al
     * registrar desde la lista. Solo el primero interesa acá.
     */
    private static function elGasto(mixed $record): ?Gasto
    {
        return $record instanceof Gasto ? $record : null;
    }

    /**
     * Lo que dice la tarjeta debajo de su título: la regla de plata, una sola
     * vez, en vez de repetirla en cada opción.
     *
     * Sin socios, el texto lleva a donde se cargan —a quien puede editar el
     * proyecto—: decir «se agregan en la pestaña Socios» sin decir dónde
     * queda esa pestaña es mandar a buscarla.
     *
     * @param array<int|string, string> $opciones
     */
    private static function queQuiereDecir(Proyecto $proyecto, array $opciones): string|HtmlString
    {
        if (count($opciones) <= 1) {
            $texto = 'Este proyecto todavía no tiene socios cargados, así que el gasto es del proyecto.';

            if (! ProyectoResource::canEdit($proyecto)) {
                return sprintf('%s Los socios se cargan al editar el proyecto, pestaña «Socios».', $texto);
            }

            return new HtmlString(sprintf(
                '%s <a href="%s" style="color: var(--primary-600); font-weight: 600; text-decoration: underline; text-underline-offset: 2px; white-space: nowrap;">Cargar los socios</a>',
                e($texto),
                e(self::dondeSeCarganLosSocios($proyecto)),
            ));
        }

        return 'Si es de un socio, lo paga solo él: no le baja la utilidad a los demás, y en el Estado mensual se le descuenta de lo que le toca.';
    }

    /**
     * «66.67», y «50» sin los ceros.
     */
    private static function parte(Socio $socio): string
    {
        return rtrim(rtrim($socio->porcentaje()->redondeado(2), '0'), '.');
    }

    /**
     * ¿El estado del botón es un socio, o es «Al proyecto»?
     *
     * Llega como entero desde la base y como texto desde el navegador; los dos
     * valen. Cualquier otra cosa —el `proyecto` de la primera opción, un NULL—
     * es «al proyecto».
     */
    private static function esUnSocio(mixed $estado): bool
    {
        return (is_int($estado) && $estado > 0)
            || (is_string($estado) && ctype_digit($estado) && $estado !== '0');
    }

    /**
     * R11: en todo lo que no es efectivo la referencia es obligatoria, y el
     * CHECK `gastos_referencia_segun_forma_chk` no admite lo contrario.
     */
    private static function exigeReferencia(Get $get): bool
    {
        $forma = FormaDePago::tryFrom(is_string($get('forma_pago')) ? $get('forma_pago') : '');

        return $forma instanceof FormaDePago && $forma->exigeReferencia();
    }
}
