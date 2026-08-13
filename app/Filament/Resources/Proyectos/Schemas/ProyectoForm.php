<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Domain\Enums\ServicioDelProyecto;
use App\Domain\ValueObjects\Monto;
use App\Filament\Schemas\Components\DNIField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Proyecto;
use Carbon\CarbonInterface;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
                                    ->placeholder('Ej: Residencial Praderas del Sol')
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
                                    ->helperText(
                                        'Prefijo de los números de contrato: RPS-2026-0065. '.
                                        'No se puede cambiar después de crear el proyecto, '.
                                        'porque partiría la numeración en dos series.'
                                    ),
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
                                        | Enteros o medios: 0.5, 10, 20.5.
                                        | Regla de Mauricio, y simplifica el
                                        | reparto — tres socios se acomodan en
                                        | 33.5 + 33.5 + 33 y no queda un tercio
                                        | periódico dejando centavos sueltos.
                                        |
                                        | La base lo repite en un CHECK: un
                                        | seeder o un import tampoco pueden
                                        | meter 33.3.
                                        */
                                        TextInput::make('porcentaje')
                                            ->label('Le toca')
                                            ->numeric()
                                            ->required()
                                            ->minValue(0.5)
                                            ->maxValue(100)
                                            ->step(0.5)
                                            ->suffix('%')
                                            ->columnSpan(4)
                                            ->live(onBlur: true)
                                            ->helperText('Enteros o medios: 0.5, 10, 20.5.')
                                            ->rules([
                                                static fn (): Closure => self::soloEnterosOMedios(...),
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
     * Un porcentaje sin ceros de relleno: 33.5 y no 33.50, 20 y no 20.0.
     */
    private static function comoSeLee(Monto $porcentaje): string
    {
        return rtrim(rtrim($porcentaje->redondeado(1), '0'), '.');
    }

    /**
     * El porcentaje va en enteros o medios. Por dos tiene que dar entero.
     *
     * Se compara sobre el string y no sobre el float: `20.5 * 2` en coma
     * flotante no es exactamente 41 en todas las máquinas (§8.3.1).
     */
    private static function soloEnterosOMedios(string $campo, mixed $valor, Closure $fallar): void
    {
        if (! is_numeric($valor)) {
            return;
        }

        $doble = new Monto((string) $valor)->multiplicarPor('2')->redondeado(4);

        if (! str_ends_with($doble, '.0000')) {
            $fallar('El porcentaje va en enteros o medios: 0.5, 10, 20.5.');
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
}
