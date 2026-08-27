<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Domain\Enums\ServicioDelProyecto;
use App\Domain\Enums\UnidadDeArea;
use App\Domain\ValueObjects\Monto;
use App\Filament\Schemas\Components\DNIField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Facturacion;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Support\ImageOptimizer;
use Carbon\CarbonInterface;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Patrón aprobado del §10: tabs persistentes en el query string, y un tab
 * "Estado" enriquecido con la información del registro — nunca un tab con
 * un solo toggle adentro.
 */
class ProyectoForm
{
    public static function configure(Schema $schema): Schema
    {
        /** @var array<string, string> $departamentos */
        $departamentos = config('honduras.localizacion.departamentos', []);

        $varaDelSistema = (string) config('lotificadora.area.vara_en_metros', '0.8359');

        return $schema
            ->components([
                Tabs::make('Proyecto')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('Identificación')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                MayusculasField::make('nombre')
                                    ->label('Nombre del proyecto')
                                    ->required()
                                    ->maxLength(150)
                                    ->unique(ignoreRecord: true)
                                    ->prefixIcon('heroicon-o-building-office-2')
                                    ->placeholder('Ej: Residencial Los Almendros')
                                    ->columnSpanFull(),

                                MayusculasField::make('codigo')
                                    ->label('Código')
                                    ->required()
                                    ->maxLength(10)
                                    ->unique(ignoreRecord: true)
                                    ->prefixIcon('heroicon-o-hashtag')
                                    ->placeholder('RPS')
                                    // §10.3: los campos que componen un correlativo se
                                    // congelan en edición. Cambiar el código partiría la
                                    // serie de contratos en dos.
                                    ->disabledOn('edit')
                                    /*
                                     * 🔴 El ejemplo sale del codigo TECLEADO, no de
                                     * una constante. Estaba escrito «RPS-2026-0065»
                                     * a mano, asi que la ficha de EL BAMBU —codigo
                                     * REB— decia que sus contratos empezaban con RPS.
                                     * Lo agarro el ensayo del 14-ago-2026 en pantalla.
                                     */
                                    ->live(onBlur: true)
                                    ->helperText(fn (Get $get): string => sprintf(
                                        'Prefijo de los números de contrato: %s-%d-0001. '.
                                        'No se puede cambiar después de crear el proyecto, '.
                                        'porque partiría la numeración en dos series.',
                                        self::codigoDeEjemplo($get),
                                        today()->year,
                                    )),

                                /*
                                 * ⚠️ ESTE CAMPO DECIDE COMO SE LEE Y SE COBRA
                                 * TODO EL DESARROLLO.
                                 *
                                 * De el salen la unidad del area de cada lote,
                                 * la del precio, la del plano, la del contrato y
                                 * la del recibo. Y NO CONVIERTE NADA: cambiarlo
                                 * con lotes cargados solo les cambia la palabra
                                 * al lado del numero, no el numero.
                                 *
                                 * Por eso se traba en cuanto sale el primer lote
                                 * —regla de Mauricio, 13-ago-2026— y no en
                                 * cuanto hay lotes: mientras esten todos
                                 * disponibles todavia se puede corregir un
                                 * dedazo sin que nadie tenga un papel firmado
                                 * que diga otra cosa.
                                 */
                                Select::make('unidad_area')
                                    ->label('Unidad del área')
                                    ->options(UnidadDeArea::opciones())
                                    // El default de la INSTALACION: en Honduras la vara²,
                                    // pero una lotificadora de otro pais lo cambia una vez
                                    // en config/lotificadora.php y nunca mas (Ley L0).
                                    ->default((string) config('lotificadora.area.unidad_por_defecto', UnidadDeArea::Varas->value))
                                    ->required()
                                    ->selectablePlaceholder(false)
                                    // live() para que «Medidas del plano», que vive en
                                    // otra pestaña, aparezca y desaparezca al elegir.
                                    ->live()
                                    ->prefixIcon('heroicon-o-scale')
                                    ->disabled(static fn (?Proyecto $record): bool => $record instanceof Proyecto && ! $record->puedeCambiarLaUnidad())
                                    ->helperText(static fn (?Proyecto $record): string => $record instanceof Proyecto && ! $record->puedeCambiarLaUnidad()
                                        ? 'Ya no se puede cambiar: este proyecto tiene lotes vendidos o donados, y la unidad está escrita en sus contratos.'
                                        : 'En qué se mide y se COBRA la superficie de este desarrollo. El área de cada lote, el precio, el plano y el contrato salen de acá. Se puede corregir mientras no se haya vendido ningún lote.'),
                            ])
                            ->columns(2),

                        Tab::make('Ubicación')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                MayusculasField::make('municipio')
                                    ->label('Municipio')
                                    ->maxLength(100)
                                    ->prefixIcon('heroicon-o-map')
                                    ->placeholder('Ej: Cucuyagua'),

                                Select::make('departamento')
                                    ->label('Departamento')
                                    ->options($departamentos)
                                    ->searchable()
                                    ->placeholder('Seleccionar departamento'),

                                Textarea::make('direccion')
                                    ->label('Dirección')
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->columnSpanFull(),

                                Textarea::make('observaciones')
                                    ->label('Observaciones')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        /*
                        |--------------------------------------------------
                        | Socios — 13-ago-2026
                        |--------------------------------------------------
                        | «Pueden ser dos propietarios, no de compra de un
                        | lote sino del proyecto en sí» — Mauricio. Es quién
                        | es dueño de qué parte del proyecto, para saber
                        | después cómo se reparte el dinero del mes.
                        |
                        | Un socio NO es un cliente: no compra un lote, no
                        | tiene expediente ni saldo. Por eso vive en su
                        | propia tabla y no en `clientes` — meterlo ahí lo
                        | metería también en el formulario de ventas y en el
                        | contador de clientes de la lotificadora.
                        |
                        | Va acá adentro y no como pestaña de abajo —al lado
                        | de Bloques y Lotes— porque no se administra: se
                        | acuerda una vez al armar el proyecto y casi nunca
                        | se toca. Es identidad del proyecto, como su nombre.
                        */
                        Tab::make('Socios')
                            ->icon('heroicon-o-users')
                            ->schema([
                                Placeholder::make('reparto')
                                    ->hiddenLabel()
                                    ->columnSpanFull()
                                    ->content(fn (Get $get): HtmlString => self::comoVaElReparto($get)),

                                Repeater::make('socios')
                                    ->relationship()
                                    ->hiddenLabel()
                                    ->addActionLabel('Agregar socio')
                                    ->columnSpanFull()
                                    ->columns(12)
                                    ->live(onBlur: true)
                                    ->defaultItems(0)
                                    ->itemLabel(fn (array $state): ?string => self::rotuloDelSocio($state))
                                    /*
                                    | ═══ SIN 100% NO SE GUARDA ═══
                                    |
                                    | «Siempre debe estar distribuido el 100, si
                                    | no, no deja guardar» — Mauricio, 13-ago.
                                    |
                                    | Vive acá y no en un CHECK porque un CHECK
                                    | mira UNA fila y esto es la suma de todas.
                                    | Y no en un trigger diferido porque el
                                    | repetidor guarda fila por fila: al
                                    | reemplazar un socio del 50% habría un
                                    | instante con 50% y el trigger tumbaría un
                                    | guardado correcto.
                                    |
                                    | Cero socios SÍ se guarda: no es un reparto
                                    | mal hecho, es que no hay reparto. Exigirlo
                                    | obligaría a conocer a los socios antes de
                                    | poder crear el proyecto.
                                    */
                                    ->rules([
                                        static fn (): Closure => self::lasPartesSuman100(...),
                                    ])
                                    ->schema([
                                        MayusculasField::make('nombre')
                                            ->label('Nombre completo')
                                            ->required()
                                            ->maxLength(150)
                                            ->columnSpan(8),

                                        /*
                                        | ═══ HASTA DOS DECIMALES ═══
                                        |
                                        | «Que me permita como 66.67 y 33.33»
                                        | — Mauricio, 27-ago-2026.
                                        |
                                        | Hasta hoy iba en enteros o medios,
                                        | que resuelve el reparto de a TRES
                                        | —33.5 + 33.5 + 33— y traba el de a
                                        | DOS: dos tercios y un tercio es
                                        | 66.67 + 33.33, y 66.5 + 33.5 no es
                                        | «casi lo mismo», es otro reparto.
                                        |
                                        | Los centésimos tampoco cierran de a
                                        | tres —33.33 × 3 = 99.99—: se acomodan
                                        | como los centavos, 33.34 + 33.33 +
                                        | 33.33. Por eso el aviso de arriba
                                        | dice cuánto falta con el número
                                        | puesto.
                                        |
                                        | El `step` es lo que le da al
                                        | navegador las flechitas y la
                                        | validación nativa; la regla de acá
                                        | abajo es la que de verdad frena, y
                                        | existe porque el navegador se saltea
                                        | con un pegado.
                                        */
                                        TextInput::make('porcentaje')
                                            ->label('Le toca')
                                            ->numeric()
                                            ->required()
                                            ->minValue(0.01)
                                            ->maxValue(100)
                                            ->step(0.01)
                                            ->suffix('%')
                                            ->columnSpan(4)
                                            ->live(onBlur: true)
                                            ->helperText('Hasta dos decimales: 50, 33.33, 66.67.')
                                            ->rules([
                                                static fn (): Closure => self::hastaDosDecimales(...),
                                            ]),

                                        DNIField::make('dni')
                                            ->columnSpan(4)
                                            ->helperText('Opcional.'),

                                        TelefonoHondurasField::make('telefono', 'Teléfono')
                                            ->columnSpan(4),

                                        TextInput::make('correo')
                                            ->label('Correo')
                                            ->email()
                                            ->maxLength(150)
                                            ->columnSpan(4),

                                        Toggle::make('activo')
                                            ->label('Participa del reparto')
                                            ->default(true)
                                            ->onColor('success')
                                            ->columnSpanFull()
                                            ->live()
                                            ->helperText(
                                                'Un socio que salió no se borra —lo que ya cobró es historia— '.
                                                'pero deja de contar en el reparto.'
                                            ),

                                        Textarea::make('observaciones')
                                            ->label('Observaciones')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ]),
                            ])
                            ->columns(1),

                        /*
                         * Pestaña propia, como pidio Mauricio el 13-ago-2026:
                         * «que tenga su propio toggle, asi como
                         * Identificacion, Ubicacion y Socios». Y tiene razon:
                         * de aca va a colgar todo lo de la factura cuando se
                         * arme la emision, y metido adentro de Identificacion
                         * iba a terminar tapando el nombre y el codigo.
                         */
                        Tab::make('Facturación')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                /*
                                 * ═══ CON QUE PAPEL COBRA ESTE DESARROLLO ═══
                                 *
                                 * Mauricio lo pidio asi el 13-ago-2026: «que
                                 * este en un toggle en cada proyecto y de ahi
                                 * se selecciona si es individual la
                                 * facturacion, si es dual o si es recibo;
                                 * dual es que ambos proyectos consumen la
                                 * misma facturacion».
                                 *
                                 * ⚠️ LOS TRES MODOS NO SE GUARDAN. La columna
                                 * sigue siendo una sola —`facturacion_id`— y
                                 * el modo se DEDUCE de ella:
                                 *
                                 *   · vacia            → recibo interno
                                 *   · con una que nadie mas usa → propia
                                 *   · con una que otro tambien usa → dual
                                 *
                                 * Guardarlo aparte seria un segundo lugar
                                 * donde vive la misma verdad, y el dia que
                                 * alguien vincule el segundo proyecto desde
                                 * el OTRO lado, el primero se quedaria
                                 * diciendo «propia» para siempre. Asi no hay
                                 * nada que sincronizar: se lee de los hechos.
                                 */
                                Section::make('Facturación')
                                    ->description('Con qué papel se le cobra a la gente de este desarrollo.')
                                    ->icon('heroicon-o-document-text')
                                    ->schema([
                                        Radio::make('modo_de_facturacion')
                                            ->label('¿Cómo factura este desarrollo?')
                                            ->options([
                                                'recibo'     => 'Solo recibo interno',
                                                'propia'     => 'Facturación propia (individual)',
                                                'compartida' => 'Facturación compartida (dual)',
                                            ])
                                            ->descriptions([
                                                'recibo'     => 'Sin CAI. Comprobante de caja: sirve para el control de la lotificadora y para el cliente, pero NO da crédito fiscal.',
                                                'propia'     => 'Este desarrollo emite desde su propia oficina, con su establecimiento y su rango.',
                                                'compartida' => 'Los dos desarrollos CONSUMEN el mismo rango. Correcto solo si emiten desde la misma oficina: el SAR autoriza el rango por punto de emisión y el código del establecimiento va adentro del número.',
                                            ])
                                            ->required()
                                            // No es columna: se deduce de facturacion_id.
                                            ->dehydrated(false)
                                            ->live()
                                            ->formatStateUsing(static fn (?Proyecto $record): string => self::modoDeFacturacionDe($record))
                                            ->afterStateUpdated(static fn (Set $set): mixed => $set('facturacion_id', null))
                                            ->columnSpanFull(),

                                        Select::make('facturacion_id')
                                            ->label(static fn (Get $get): string => $get('modo_de_facturacion') === 'compartida'
                                                ? '¿Cuál facturación comparten?'
                                                : 'Facturación de este desarrollo')
                                            ->options(static fn (Get $get, ?Proyecto $record): array => self::facturacionesPara($get, $record))
                                            ->searchable()
                                            ->visible(static fn (Get $get): bool => $get('modo_de_facturacion') !== 'recibo')
                                            ->required(static fn (Get $get): bool => $get('modo_de_facturacion') !== 'recibo')
                                            /*
                                             * Se guarda AUNQUE este escondido: al pasar a
                                             * recibo interno, `afterStateUpdated` lo dejo en
                                             * null y ese null tiene que llegar a la base. Sin
                                             * esto, el proyecto se quedaria con la
                                             * facturacion vieja pegada y nadie lo veria.
                                             */
                                            ->dehydrated()
                                            ->helperText(static fn (Get $get): string => $get('modo_de_facturacion') === 'compartida'
                                                ? 'Al lado de cada una dice qué otro desarrollo la usa. Al elegirla, los dos pasan a consumir el mismo rango de correlativos. Si todavía no la usa nadie, este es el primero de los dos.'
                                                : 'Se listan las que no está usando ningún otro desarrollo. Se cargan en Administración → Facturación.')
                                            ->columnSpanFull(),

                                        /*
                                         * El logo del DESARROLLO, que no es el de la
                                         * empresa. El de la empresa vive en
                                         * BrandingSetting y se ve arriba del panel;
                                         * este sale en el recibo, en el contrato, en
                                         * el estado de cuenta y en el plano publico,
                                         * al lado del otro. Pedido de Mauricio el
                                         * 14-ago-2026, con los tres logos en la mano.
                                         *
                                         * Mismo tratamiento que el logo de la
                                         * instalacion: se convierte a WebP al subirlo
                                         * —ver ImageOptimizer— porque un PNG de tres
                                         * megas en el encabezado de un contrato lo
                                         * unico que hace es demorar la impresion.
                                         */
                                        /*
                                         * ═══ EL MEMBRETE DEL RECIBO INTERNO ═══
                                         *
                                         * Solo dos campos, y es a proposito. Lo
                                         * enderezo Mauricio el 14-ago-2026: un
                                         * recibo de caja no necesita una
                                         * «facturacion» —no tiene CAI, ni
                                         * establecimiento, ni rango— asi que se
                                         * configura aca mismo.
                                         *
                                         * El resto del membrete YA lo tiene el
                                         * proyecto: el nombre, el logo de abajo y
                                         * la direccion de la pestaña Ubicacion. Yo
                                         * le habia puesto otra direccion a la
                                         * facturacion, y eso dejaba la misma verdad
                                         * escrita en dos lugares.
                                         */
                                        TextInput::make('telefonos')
                                            ->label('Teléfono(s)')
                                            ->maxLength(60)
                                            ->visible(static fn (Get $get): bool => $get('modo_de_facturacion') === 'recibo')
                                            ->helperText('Salen impresos en el recibo. Si son dos, van separados por una barra: «9993-0743 / 3369-0764».'),

                                        TextInput::make('correo')
                                            ->label('Correo')
                                            ->email()
                                            ->maxLength(120)
                                            ->visible(static fn (Get $get): bool => $get('modo_de_facturacion') === 'recibo'),

                                        TextInput::make('proximo_recibo')
                                            ->label('Desde qué número empieza a imprimir')
                                            ->numeric()
                                            ->minValue(1)
                                            ->step(1)
                                            /*
                                             * ═══ POR QUE ESTE CAMPO VIVE ACA ═══
                                             *
                                             * Cada desarrollo numera SUS recibos, con su
                                             * código adelante: `RPS-00000001`. Estuvo un
                                             * momento pensado como una variable del `.env`,
                                             * y Mauricio lo movió acá el 23-ago-2026:
                                             * «pueden haber más proyectos en el futuro y se
                                             * confundirán».
                                             *
                                             * En blanco, el desarrollo empieza en 1.
                                             */
                                            ->helperText(static fn (?Proyecto $record): HtmlString => self::comoSeVeraElRecibo($record))
                                            ->rule(static fn (?Proyecto $record): Closure => static function (string $atributo, mixed $valor, Closure $falla) use ($record): void {
                                                /*
                                                 * Un correlativo que RETROCEDE emite un
                                                 * número que ya está impreso y entregado, y
                                                 * el índice único lo rebota a mitad de un
                                                 * cobro, con un cliente enfrente. Se atrapa
                                                 * acá, donde el mensaje se puede leer.
                                                 */
                                                $usado = self::ultimoReciboImpreso($record);

                                                if ($usado > 0 && (int) $valor <= $usado) {
                                                    $falla("Este desarrollo ya imprimió hasta el {$usado}. "
                                                        .'El arranque tiene que ser mayor, o se repetiría un recibo ya entregado.');
                                                }
                                            })
                                            ->visible(static fn (Get $get): bool => $get('modo_de_facturacion') === 'recibo')
                                            ->columnSpanFull(),

                                        Placeholder::make('de_donde_sale_el_membrete')
                                            ->label('El resto del membrete')
                                            ->visible(static fn (Get $get): bool => $get('modo_de_facturacion') === 'recibo')
                                            ->content(new HtmlString(
                                                'El <strong>nombre</strong> y la <strong>dirección</strong> del recibo salen de lo que este '.
                                                'proyecto ya tiene: el nombre de arriba y la dirección de la pestaña <strong>Ubicación</strong>. '.
                                                'No se vuelven a teclear acá — si se corrigen allá, el recibo cambia solo.'
                                            ))
                                            ->columnSpanFull(),

                                        FileUpload::make('logo_path')
                                            ->label('Logo del desarrollo')
                                            ->image()
                                            ->imageEditor()
                                            ->disk('public')
                                            ->directory('proyectos')
                                            ->visibility('public')
                                            ->maxSize(5120)
                                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'])
                                            ->saveUploadedFileUsing(static fn (TemporaryUploadedFile $file): string => ImageOptimizer::toWebp($file, 'proyectos'))
                                            ->helperText('El de ESTE desarrollo, no el de la inmobiliaria. Sale en el recibo y la factura —junto al de la empresa, que ya está cargado en Configuración—, y también en el contrato, el estado de cuenta y el plano público. Se convierte a WebP solo. Máximo 5 MB.')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),

                        Tab::make('Estado')
                            ->icon('heroicon-o-power')
                            ->schema([
                                Toggle::make('activo')
                                    ->label('Proyecto activo')
                                    ->default(true)
                                    ->onColor('success')
                                    ->offColor('danger')
                                    ->helperText(
                                        'Un proyecto inactivo deja de ofrecerse en formularios '.
                                        'nuevos, pero conserva intactos sus lotes, ventas e histórico.'
                                    ),

                                Toggle::make('plano_esquematico')
                                    ->label('El plano es esquemático')
                                    ->onColor('warning')
                                    ->offColor('gray')
                                    ->helperText(
                                        'Se enciende solo cuando el sistema acomoda el plano. Significa que '.
                                        'el dibujo respeta el área de cada lote pero NO su ubicación real en '.
                                        'el terreno. Apagalo cuando la geometría venga del plano del topógrafo.'
                                    ),

                                /*
                                 * ═══ LA VARA ES DEL DESARROLLO, NO DEL SISTEMA ═══
                                 *
                                 * El topógrafo acota en metros y el negocio cobra por
                                 * vara². Mientras hubo un solo proyecto, un número en la
                                 * config alcanzaba; con el segundo dejó de alcanzar,
                                 * porque la vara castellana (0.8359 m) no es la única que
                                 * se usa y de ese factor sale el área que se cobra.
                                 *
                                 * El toggle es SOLO presentación —lo que se le enseña al
                                 * cliente al lado de cada lado del lote— y el campo de
                                 * abajo sí toca el dinero. Por eso están juntos: quien
                                 * abre esta sección tiene que ver la diferencia.
                                 */
                                Section::make('Medidas del plano')
                                    ->description('En qué unidad se leen las medidas de este desarrollo.')
                                    ->icon('heroicon-o-scale')
                                    /*
                                     * Desaparece entera cuando el proyecto trabaja
                                     * en metros²: ahí la unidad del área ES el
                                     * metro, el factor vale uno solo —lo resuelve
                                     * Proyecto::varaEnMetros()— y las medidas ya
                                     * están en metros. Preguntar de nuevo lo mismo
                                     * es un campo más donde equivocarse.
                                     */
                                    ->visible(static fn (Get $get): bool => $get('unidad_area') !== UnidadDeArea::Metros->value)
                                    ->schema([
                                        Toggle::make('medidas_en_metros')
                                            ->label('Mostrar las medidas en metros')
                                            ->onColor('info')
                                            ->offColor('gray')
                                            ->helperText(
                                                'Los planos de topografía vienen acotados en metros. Encendido, '.
                                                'los lados de cada lote se muestran en metros —los mismos números '.
                                                'que dice el plano impreso que el cliente tiene en la mano— y el '.
                                                'área sale con las dos unidades. El área se sigue guardando y '.
                                                'COBRANDO en varas²: esto no cambia ningún precio.'
                                            ),

                                        TextInput::make('vara_en_metros')
                                            ->label('A cuánto equivale la vara')
                                            ->numeric()
                                            ->minValue(0.5)
                                            ->maxValue(1.5)
                                            ->step('0.000001')
                                            ->suffix('m')
                                            ->placeholder($varaDelSistema)
                                            ->helperText(
                                                'De este número sale cuántas varas² tiene cada lote cuando se '.
                                                'importa el plano, así que TOCA EL PRECIO. Vacío usa la vara del '.
                                                'sistema ('.$varaDelSistema.' m, la castellana). La mexicana son '.
                                                '0.838 y la de Texas 0.8467: preguntale al topógrafo con cuál '.
                                                'levantó el plano.'
                                            ),

                                        /*
                                         * Decisión del 11-ago-2026: cambiar la vara no
                                         * recalcula nada. Rescalar el área de un lote ya
                                         * apartado o vendido es cambiarle el precio a un
                                         * contrato firmado, en silencio. El aviso solo
                                         * aparece cuando hay algo que se pueda romper.
                                         */
                                        Placeholder::make('aviso_de_la_vara')
                                            ->label('Ojo')
                                            ->columnSpanFull()
                                            ->visible(fn (?Proyecto $record): bool => ($record?->lotes()->count() ?? 0) > 0)
                                            ->content(static fn (?Proyecto $record): string => sprintf(
                                                'Este proyecto ya tiene %d lotes cargados. Cambiar la vara acá NO '.
                                                'recalcula sus áreas ni sus valores: el número nuevo rige para lo '.
                                                'que se importe de ahora en adelante. Si la vara estaba mal, hay '.
                                                'que volver a importar el plano.',
                                                $record?->lotes()->count() ?? 0,
                                            )),
                                    ])
                                    ->columns(2),

                                /*
                                 * ═══ EL CUPO, NO EL PERMISO ═══
                                 *
                                 * Donar saca un lote del inventario sin que
                                 * entre un lempira, y es el unico compromiso
                                 * que no deja rastro de plata. Un si/no deja
                                 * la puerta abierta para siempre; un cupo la
                                 * cierra sola cuando se cumplio lo que la
                                 * lotificadora decidio regalar.
                                 *
                                 * El boton «Donar este lote» del plano sale
                                 * de aca: mientras queden donaciones se
                                 * dibuja, y cuando el cupo se llena
                                 * desaparece. Ver Proyecto::puedeDonarOtroLote().
                                 */
                                Section::make('Donaciones')
                                    ->description('Cuántos lotes de este desarrollo se van a entregar sin cobrar.')
                                    ->icon('heroicon-o-gift')
                                    ->schema([
                                        Toggle::make('dona_lotes')
                                            ->label('Este desarrollo dona lotes')
                                            ->onColor('success')
                                            ->offColor('gray')
                                            ->live()
                                            ->helperText(
                                                'Apagado, el botón «Donar este lote» no aparece en el plano. '.
                                                'Una donación es definitiva y no genera cartera: conviene que '.
                                                'sea una decisión tomada antes, no un clic disponible siempre.'
                                            ),

                                        TextInput::make('lotes_a_donar')
                                            ->label('Cuántos lotes se donarán')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(9999)
                                            ->default(0)
                                            ->required()
                                            ->visible(static fn (Get $get): bool => (bool) $get('dona_lotes'))
                                            ->helperText(static function (?Proyecto $record): string {
                                                if (! $record instanceof Proyecto) {
                                                    return 'El botón de donar se muestra hasta completar esta cantidad, y después desaparece solo.';
                                                }

                                                $hechas = $record->lotesDonados();
                                                $cupo = $record->cupoDeDonaciones();

                                                if ($hechas > $cupo && $cupo > 0) {
                                                    return "⚠️ Ya hay {$hechas} lotes donados, más que el cupo de {$cupo}. ".
                                                           'Lo entregado no se deshace solo: el botón queda oculto hasta que subas el número.';
                                                }

                                                return "Van {$hechas} donados. El botón se muestra hasta completar la cantidad, y después desaparece solo.";
                                            }),
                                    ])
                                    ->columns(1),

                                /*
                                 * ═══ HERENCIA: EL GEMELO DE LAS DONACIONES ═══
                                 *
                                 * Lo pidio Mauricio el 13-ago-2026, el mismo
                                 * dia y con el mismo argumento: «para los
                                 * reservados, estos son para lotes heredados,
                                 * asi que tambien hay que colocarlo». Es la
                                 * otra forma de que el inventario se achique
                                 * sin una venta atras, asi que lleva cupo por
                                 * la misma razon.
                                 *
                                 * ⚠️ Adentro dice «Herencia» y afuera dice
                                 * «Reservado». No es un descuido: el plano
                                 * publico sigue usando la palabra que el
                                 * comprador entiende sola. Ver
                                 * EstadoLote::etiquetaInterna().
                                 */
                                Section::make('Herencia')
                                    ->description('Cuántos lotes de este desarrollo se guardan para la familia.')
                                    ->icon('heroicon-o-home-modern')
                                    ->schema([
                                        Toggle::make('reserva_lotes')
                                            ->label('Este desarrollo guarda lotes para herencia')
                                            ->onColor('success')
                                            ->offColor('gray')
                                            ->live()
                                            ->helperText(
                                                'Apagado, el botón «Guardar para herencia» no aparece en el plano. '.
                                                'Un lote guardado sale del mercado y no genera cartera: conviene '.
                                                'que sea una decisión tomada al armar el desarrollo.'
                                            ),

                                        TextInput::make('lotes_a_reservar')
                                            ->label('Cuántos lotes se guardarán')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(9999)
                                            ->default(0)
                                            ->required()
                                            ->visible(static fn (Get $get): bool => (bool) $get('reserva_lotes'))
                                            ->helperText(static function (?Proyecto $record): string {
                                                if (! $record instanceof Proyecto) {
                                                    return 'El botón de guardar se muestra hasta completar esta cantidad, y después desaparece solo.';
                                                }

                                                $guardados = $record->lotesReservados();
                                                $cupo = $record->cupoDeReservas();

                                                if ($guardados > $cupo && $cupo > 0) {
                                                    return "⚠️ Ya hay {$guardados} lotes guardados, más que el cupo de {$cupo}. ".
                                                           'El botón queda oculto hasta que subas el número, o hasta que saques alguno desde el plano.';
                                                }

                                                return "Van {$guardados} guardados. El botón se muestra hasta completar la cantidad, y después desaparece solo.";
                                            }),
                                    ])
                                    ->columns(1),

                                Section::make('Plano público')
                                    ->description('La página que se le manda al cliente por WhatsApp.')
                                    ->icon('heroicon-o-globe-alt')
                                    ->schema([
                                        Toggle::make('plano_publico')
                                            ->label('Publicar el plano')
                                            ->onColor('success')
                                            ->offColor('gray')
                                            ->helperText(
                                                'Nace apagado a propósito. Encenderlo publica en internet el '.
                                                'plano, las medidas y LA LISTA DE PRECIOS COMPLETA: la '.
                                                'competencia también la puede abrir. Los lotes vendidos se ven '.
                                                'ocupados, pero nunca a qué precio se vendieron ni a quién.'
                                            ),

                                        TextInput::make('slug')
                                            ->label('Dirección de la página')
                                            ->prefix(url('/plano').'/')
                                            ->maxLength(80)
                                            ->unique(ignoreRecord: true)
                                            ->rule('regex:/^[a-z0-9]+(-[a-z0-9]+)*$/')
                                            ->helperText(
                                                'Minúsculas, números y guiones. Si lo dejás vacío se arma solo '.
                                                'con el nombre del proyecto. Cambiarla ROMPE todos los links '.
                                                'que ya se mandaron por WhatsApp, y nadie relaciona una cosa '.
                                                'con la otra: cambiala solo si hace falta de verdad.'
                                            ),

                                        CheckboxList::make('servicios')
                                            ->label('Servicios e infraestructura')
                                            ->options(ServicioDelProyecto::opciones())
                                            ->columns(2)
                                            ->helperText(
                                                'Lo que el desarrollo YA tiene. Es lo que termina de convencer '.
                                                'a quien ya vio el precio. Si no marcás ninguno, la sección '.
                                                'simplemente no aparece en la página.'
                                            ),

                                        TextInput::make('whatsapp')
                                            ->label('WhatsApp para consultas')
                                            ->tel()
                                            ->maxLength(20)
                                            ->placeholder('9999-9999')
                                            ->helperText(
                                                'A este número llegan las consultas de ESTE proyecto. Sin '.
                                                'número, la página se ve igual pero no muestra el botón — que '.
                                                'es mejor que mandar al cliente a un chat que nadie lee.'
                                            ),

                                        /*
                                         * Despues del precio, la segunda
                                         * pregunta que hace todo el mundo es
                                         * donde queda. Con estos dos numeros
                                         * la pagina muestra botones que
                                         * arrancan Google Maps y Waze.
                                         *
                                         * `requiredWith` cruzado repite el
                                         * CHECK de la base: media coordenada
                                         * cae en el Golfo de Guinea.
                                         */
                                        TextInput::make('latitud')
                                            ->label('Latitud')
                                            ->numeric()
                                            ->minValue(-90)
                                            ->maxValue(90)
                                            ->step('0.0000001')
                                            ->requiredWith('longitud')
                                            ->placeholder('14.5896412')
                                            ->helperText(
                                                'En Google Maps, mantené el dedo sobre la entrada del '.
                                                'proyecto hasta que aparezca el pin: abajo salen dos '.
                                                'números separados por coma. El primero va acá.'
                                            ),

                                        TextInput::make('longitud')
                                            ->label('Longitud')
                                            ->numeric()
                                            ->minValue(-180)
                                            ->maxValue(180)
                                            ->step('0.0000001')
                                            ->requiredWith('latitud')
                                            ->placeholder('-88.9302517')
                                            ->helperText('El segundo número. En Honduras siempre es negativo.'),
                                    ])
                                    ->columns(1),

                                Section::make('Información del registro')
                                    ->description('Datos de auditoría que mantiene el sistema.')
                                    ->icon('heroicon-o-information-circle')
                                    ->visibleOn('edit')
                                    ->columns(2)
                                    ->schema([
                                        Placeholder::make('bloques_registrados')
                                            ->label('Bloques registrados')
                                            ->content(fn (?Proyecto $record): string => (string) ($record?->bloques()->count() ?? 0)),

                                        Placeholder::make('lotes_registrados')
                                            ->label('Lotes registrados')
                                            ->content(fn (?Proyecto $record): string => (string) ($record?->lotes()->count() ?? 0)),

                                        Placeholder::make('creado_en')
                                            ->label('Creado')
                                            ->content(static function (?Proyecto $record): string {
                                                $fecha = $record?->getAttribute('created_at');

                                                return $fecha instanceof CarbonInterface ? fechaLarga($fecha) : '—';
                                            }),

                                        Placeholder::make('actualizado_en')
                                            ->label('Última modificación')
                                            ->content(static function (?Proyecto $record): string {
                                                $fecha = $record?->getAttribute('updated_at');

                                                return $fecha instanceof CarbonInterface ? haceCuanto($fecha) : '—';
                                            }),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Cuánto suman las partes cargadas, y el aviso si no cierran.
     *
     * ═══ POR QUE NO ES UNA VALIDACION QUE IMPIDA GUARDAR ═══
     *
     * Porque mientras se carga el segundo socio el total va en 50, y eso no es
     * un error: es un formulario a medio llenar. Bloquear ahí obligaría a
     * cargar los tres de una sentada o a inventar porcentajes provisorios.
     *
     * Lo que sí hace falta es que NADIE se vaya creyendo que quedó bien. Por
     * eso el aviso es rojo y dice cuánto falta o cuánto sobra, con el número
     * puesto: «faltan 10%» se corrige, «revise los porcentajes» no.
     *
     * ⚠️ La suma va por Monto —bcmath sobre strings— y no con `array_sum()`:
     * tres partes de 33.3333 sumadas en float dan 99.99990000000001 y el aviso
     * saldría rojo sobre un reparto que está bien (§8.3.1).
     */
    private static function comoVaElReparto(Get $get): HtmlString
    {
        $total = self::sumaDeLasPartes($get('socios'));

        if (! $total instanceof Monto) {
            return new HtmlString(
                '<span style="color:rgb(113 113 122)">Sin socios cargados. Si el proyecto es de una sola '.
                'persona no hace falta poner nada acá.</span>'
            );
        }

        $cien = new Monto('100');

        if ($total->igualA($cien)) {
            return new HtmlString('<strong style="color:#15803d">Las partes cierran en 100%</strong>');
        }

        $falta = $total->menorQue($cien);
        $diferencia = $falta ? $cien->restar($total) : $total->restar($cien);

        return new HtmlString(sprintf(
            '<strong style="color:#b91c1c">Las partes suman %s%% y no 100%%: %s %s%%.</strong> '.
            '<span style="color:rgb(113 113 122)">Así no se puede guardar.</span>',
            self::comoSeLee($total),
            $falta ? 'faltan' : 'sobran',
            self::comoSeLee($diferencia),
        ));
    }

    /**
     * La suma de las partes de los socios que participan, o null si no hay
     * ninguno cargado.
     *
     * ⚠️ Por Monto —bcmath sobre strings— y no con `array_sum()`: sumar
     * porcentajes en float deja restos que hacen fallar una comparación contra
     * 100 sobre un reparto que está bien (§8.3.1).
     */
    private static function sumaDeLasPartes(mixed $filas): ?Monto
    {
        if (! is_array($filas)) {
            return null;
        }

        $total = Monto::cero();
        $cuantos = 0;

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            // Un socio que no participa no cuenta, igual que en el reparto.
            if (array_key_exists('activo', $fila) && $fila['activo'] === false) {
                continue;
            }

            $parte = $fila['porcentaje'] ?? null;

            if (! is_numeric($parte)) {
                continue;
            }

            $total = $total->sumar(new Monto((string) $parte));
            $cuantos++;
        }

        return $cuantos === 0 ? null : $total;
    }

    /**
     * Un porcentaje sin ceros de relleno: 66.67 y no 66.670, 20 y no 20.00.
     *
     * Dos decimales, que es la escala de la columna: con uno solo, «faltan
     * 0.01%» saldría escrito «faltan 0%» y el aviso mandaría a buscar un error
     * que no se ve.
     */
    private static function comoSeLee(Monto $porcentaje): string
    {
        return rtrim(rtrim($porcentaje->redondeado(2), '0'), '.');
    }

    /**
     * El porcentaje llega hasta el centésimo. Por cien tiene que dar entero.
     *
     * Se compara sobre el string y no sobre el float: `66.67 * 100` en coma
     * flotante no es exactamente 6667 en todas las máquinas (§8.3.1).
     *
     * ⚠️ Hace falta además del `step` del campo: el navegador valida lo que se
     * teclea, no lo que se pega, y la columna es numeric(5,2) —un tercer
     * decimal no lo rechaza, lo REDONDEA en silencio, que es peor.
     */
    private static function hastaDosDecimales(string $campo, mixed $valor, Closure $fallar): void
    {
        if (! is_numeric($valor)) {
            return;
        }

        $porCien = new Monto((string) $valor)->multiplicarPor('100')->redondeado(4);

        if (! str_ends_with($porCien, '.0000')) {
            $fallar('El porcentaje llega hasta dos decimales: 50, 33.33, 66.67.');
        }
    }

    /**
     * Sin 100% no se guarda. Cero socios sí: ahí no hay reparto que cerrar.
     */
    private static function lasPartesSuman100(string $campo, mixed $valor, Closure $fallar): void
    {
        $total = self::sumaDeLasPartes($valor);

        if (! $total instanceof Monto || $total->igualA(new Monto('100'))) {
            return;
        }

        $fallar(sprintf(
            'Las partes de los socios suman %s%% y tienen que sumar 100%%.',
            self::comoSeLee($total),
        ));
    }

    /**
     * El renglón plegado del repetidor: el nombre y su parte.
     *
     * @param array<string, mixed> $estado
     */
    private static function rotuloDelSocio(array $estado): ?string
    {
        $nombre = $estado['nombre'] ?? null;

        if (! is_string($nombre) || trim($nombre) === '') {
            return null;
        }

        $parte = $estado['porcentaje'] ?? null;

        if (! is_string($parte) && ! is_int($parte) && ! is_float($parte)) {
            return trim($nombre);
        }

        $limpio = rtrim(rtrim(new Monto((string) $parte)->redondeado(4), '0'), '.');

        return sprintf('%s · %s%%', trim($nombre), $limpio);
    }

    /**
     * Cómo se va a ver el próximo recibo de este desarrollo.
     *
     * Se muestra armado —`RPS-00000001`— porque un campo que pide «desde qué
     * número» y no enseña el resultado obliga a imaginárselo, y el prefijo
     * sale de otro lado (el código, en la pestaña Identificación).
     */
    private static function comoSeVeraElRecibo(?Proyecto $record): HtmlString
    {
        $codigo = $record instanceof Proyecto ? $record->getAttribute('codigo') : null;
        $prefijo = is_string($codigo) && trim($codigo) !== '' ? trim($codigo) : 'RPS';

        $arranque = $record instanceof Proyecto ? $record->getAttribute('proximo_recibo') : null;
        $numero = is_numeric($arranque) && (int) $arranque > 0 ? (int) $arranque : 1;

        $folio = $prefijo.'-'.str_pad((string) $numero, 8, '0', STR_PAD_LEFT);
        $usado = self::ultimoReciboImpreso($record);

        $ya = $usado > 0
            ? " Este desarrollo ya imprimió hasta el <strong>{$usado}</strong>."
            : '';

        return new HtmlString(
            "El próximo recibo saldrá como <strong>{$folio}</strong>. Cada desarrollo numera lo suyo, ".
            'así que dos proyectos no se pisan. En blanco, empieza en 1.'.$ya.
            ' <strong>No toca</strong> los recibos de la cartera anterior al sistema, que se quedan como están.'
        );
    }

    /**
     * El número más alto que este desarrollo YA imprimió, en su propia serie.
     *
     * Cuenta solo los de su serie: los de la cartera vieja van con `serie` en
     * null y no estorban acá — son de otra numeración.
     */
    private static function ultimoReciboImpreso(?Proyecto $record): int
    {
        $codigo = $record instanceof Proyecto ? $record->getAttribute('codigo') : null;

        if (! is_string($codigo) || trim($codigo) === '') {
            return 0;
        }

        return (int) Recibo::query()->where('serie', trim($codigo))->max('numero');
    }

    /**
     * En cuál de los tres modos está HOY este desarrollo.
     *
     * Se deduce, no se guarda. Ver el comentario de la sección Facturación:
     * un segundo lugar donde viva la misma verdad es un segundo lugar que se
     * puede quedar viejo.
     */
    private static function modoDeFacturacionDe(?Proyecto $record): string
    {
        $facturacion = $record instanceof Proyecto ? $record->getAttribute('facturacion_id') : null;

        if (! is_int($facturacion)) {
            return 'recibo';
        }

        $cuantos = Proyecto::query()->where('facturacion_id', '=', $facturacion)->count();

        return $cuantos > 1 ? 'compartida' : 'propia';
    }

    /**
     * Las facturaciones que se pueden elegir, según el modo.
     *
     * En «propia» solo las que no está usando NADIE más: elegir una que ya
     * tiene dueño sería compartirla sin decirlo.
     *
     * ⚠️ En «compartida» se listan TODAS, y esa fue una corrección del
     * 14-ago-2026. Antes salían solo las que otro desarrollo ya usaba, y
     * eso dejaba un huevo-y-gallina: la primera vez la lista aparecía
     * vacía —«no hay opciones disponibles»— porque nadie había tomado
     * ninguna todavía, y no había forma de empezar a compartir. Ahora se
     * puede elegir cualquiera y al lado dice quién más la usa, que es el
     * dato que de verdad importa al vincular.
     *
     * El modo no lo decide este selector: lo decide cuántos proyectos
     * terminan apuntando a la misma. Ver `modoDeFacturacionDe()`.
     *
     * @return array<int, string>
     */
    private static function facturacionesPara(Get $get, ?Proyecto $record): array
    {
        $modo = $get('modo_de_facturacion');
        $miId = $record instanceof Proyecto ? (int) $record->getKey() : 0;

        $opciones = [];

        $facturaciones = Facturacion::query()
            ->where('activa', '=', true)
            ->with('proyectos')
            ->orderBy('nombre')
            ->get();

        foreach ($facturaciones as $facturacion) {
            $otros = $facturacion->proyectos
                ->reject(static fn (Proyecto $proyecto): bool => (int) $proyecto->getKey() === $miId);

            $laUsaOtro = $otros->isNotEmpty();

            if ($modo === 'propia' && $laUsaOtro) {
                continue;
            }

            $etiqueta = (string) $facturacion->getAttribute('nombre');

            if ($laUsaOtro) {
                $etiqueta .= ' — la usa: '.$otros->pluck('nombre')->implode(', ');
            } elseif ($modo === 'compartida') {
                $etiqueta .= ' — todavía no la usa nadie más';
            }

            $opciones[(int) $facturacion->getKey()] = $etiqueta;
        }

        return $opciones;
    }

    /**
     * El código tal como va a salir impreso, o un ejemplo si todavía no hay.
     *
     * En MAYÚSCULAS porque así lo guarda el mutador y así sale en el contrato:
     * mostrar «reb» mientras se teclea y después imprimir «REB» haría dudar de
     * cuál de los dos vale.
     */
    private static function codigoDeEjemplo(Get $get): string
    {
        $codigo = $get('codigo');

        return is_string($codigo) && trim($codigo) !== ''
            ? mb_strtoupper(trim($codigo))
            : 'RPS';
    }
}
