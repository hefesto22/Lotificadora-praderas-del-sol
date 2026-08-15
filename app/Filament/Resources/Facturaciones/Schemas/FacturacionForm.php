<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturaciones\Schemas;

use App\Filament\Schemas\Components\MayusculasField;
use App\Models\Facturacion;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Los datos con los que se arma el papel que recibe el cliente.
 *
 * La mitad de la pantalla se esconde en modo recibo interno, y no es
 * cosmética: un recibo de caja no lleva RTN del emisor, ni establecimiento,
 * ni imprenta. Pedirlos igual sería inventar campos obligatorios para el
 * caso que HOY es el único que está en producción.
 */
final class FacturacionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('De qué desarrollo es esta facturación')
                    ->schema([
                        MayusculasField::make('nombre')
                            ->label('Nombre de esta facturación')
                            ->required()
                            ->maxLength(120)
                            ->helperText('Cómo la van a reconocer al elegirla en un proyecto. Conviene que diga desde dónde se emite: «El Bambú — oficina de Talanga».'),

                        Toggle::make('activa')
                            ->label('Activa')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('gray')
                            ->helperText('Apagada deja de ofrecerse al configurar un proyecto. Los que ya la tienen puesta no se tocan.'),

                        /*
                         * Facturar y emitir notas de credito son DOS permisos
                         * distintos del SAR. Nace apagado porque la mayoria
                         * no tiene la segunda autorizacion — ver la migracion
                         * `notas_de_credito_opcionales`.
                         */
                        Toggle::make('emite_notas_credito')
                            ->label('Emite notas de crédito')
                            ->default(false)
                            ->onColor('success')
                            ->offColor('gray')
                            ->helperText('Solo si el SAR les autorizó un talonario de notas de crédito, que se tramita aparte del de facturas. Apagado, al rescindir un lote con devolución el acta le avisa al contador en vez de emitirla.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                /*
                 * ═══ TODO LO DE ABAJO ES SOLO PARA LA FACTURA ═══
                 *
                 * Son los requisitos del formato del Acuerdo 481-2017,
                 * Art. 10, num. 1: lo que va PREIMPRESO en el talonario.
                 * Un recibo interno no lleva nada de esto.
                 */
                /*
                 * ═══ EL MEMBRETE VA EN LOS DOS MODOS ═══
                 *
                 * Lo pidio Mauricio el 14-ago-2026 con la foto del talonario
                 * de Praderas: «para recibo interno que coloquen datos
                 * tambien, como ser nombre, telefono o telefonos y
                 * direccion». Tiene razon y es un agujero que ya existia:
                 * esos datos salian de `config/lotificadora.php`, que es UNO
                 * para toda la instalacion. Con dos urbanizaciones —cada una
                 * con su talonario, su telefono y su direccion— eso dejo de
                 * alcanzar.
                 *
                 * Lo unico que sigue siendo solo de la factura es el RTN
                 * OBLIGATORIO: el CHECK de la base lo exige cuando el modo es
                 * factura con CAI, y en un recibo de caja es opcional.
                 */
                Section::make('Quién emite')
                    ->description('Lo que va impreso arriba de la factura.')
                    ->icon('heroicon-o-building-office')
                    ->schema([
                        TextInput::make('rtn')
                            ->label('RTN del emisor')
                            ->required()
                            ->maxLength(20)
                            ->helperText('Se guarda en dígitos limpios; tecléalo como te sea cómodo.'),

                        /*
                         * ⚠️ ESTOS DOS SE CONFUNDEN, Y CONVIENE QUE NO.
                         *
                         * Le paso a Mauricio el 13-ago-2026 y le va a pasar a
                         * quien cargue los datos: la razon social es el
                         * nombre LEGAL de la empresa —el de la escritura y el
                         * RTN— y el nombre comercial es el del rotulo. Al
                         * reves, la factura sale a nombre de alguien que no
                         * existe ante el SAR.
                         *
                         * El caso de esta lotificadora es el ejemplo perfecto
                         * y por eso esta escrito en la ayuda: la empresa se
                         * llama Inmobiliaria Maya y los desarrollos —El
                         * Bambu, Altamira— son nombres comerciales, no
                         * sociedades.
                         */
                        MayusculasField::make('razon_social')
                            ->label('Razón o denominación social')
                            ->maxLength(160)
                            ->helperText('El nombre LEGAL de la empresa, el de la escritura y el RTN. Es el mismo para todos los desarrollos.'),

                        MayusculasField::make('nombre_comercial')
                            ->label('Nombre comercial')
                            ->maxLength(160)
                            ->helperText('El del rótulo, y acá va el del desarrollo. Tiene que estar registrado así ante el SAR para este establecimiento: no es texto libre.'),

                        Textarea::make('direccion_casa_matriz')
                            ->label('Dirección de la casa matriz')
                            ->rows(2)
                            ->columnSpan(['default' => 1, 'lg' => 2]),

                        Textarea::make('direccion_establecimiento')
                            ->label('Dirección del establecimiento')
                            ->rows(2)
                            ->helperText('La del lugar desde donde se EMITE la factura, que no siempre es donde está el terreno. Va impresa y es lo que amarra el rango a este establecimiento.')
                            ->columnSpan(['default' => 1, 'lg' => 2]),

                        TextInput::make('telefono')
                            ->label('Teléfono(s)')
                            ->maxLength(60)
                            ->helperText('Se imprimen tal cual. Si son dos, van separados por una barra: «9993-0743 / 3369-0764».'),
                        TextInput::make('correo')->label('Correo')->email()->maxLength(120),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                /*
                 * ═══ EL NUMERO, CON SU VISTA PREVIA ═══
                 *
                 * Mauricio lo pidio el 13-ago-2026: «mejora el diseño en el
                 * numero de factura, se ve muy amontonado». Tenia razon —tres
                 * campos angostos con tres parrafos de ayuda abajo—, y la
                 * cura no fue solo acortar el texto:
                 *
                 * Los codigos son tres numeritos sin significado propio. Lo
                 * que la persona quiere ver es EL NUMERO ARMADO, que es lo
                 * que va a salir impreso. Con la vista previa abajo, las
                 * explicaciones sobran: se teclea, se mira, se entiende. El
                 * ultimo segmento va en gris porque no se elige aca —lo pone
                 * el correlativo de cada autorizacion— y eso tambien se
                 * entiende mirandolo, sin que nadie lo escriba.
                 */
                Section::make('El número de la factura')
                    ->description('Los tres primeros segmentos. El último lo pone el correlativo de cada autorización.')
                    ->icon('heroicon-o-hashtag')
                    ->schema([
                        TextInput::make('codigo_establecimiento')
                            ->label('Establecimiento')
                            ->required()
                            ->length(3)
                            ->rule('regex:/^\d{3}$/')
                            ->default('000')
                            ->live(onBlur: true)
                            ->helperText('La casa matriz es 000.'),

                        TextInput::make('codigo_punto_emision')
                            ->label('Punto de emisión')
                            ->required()
                            ->length(3)
                            ->rule('regex:/^\d{3}$/')
                            ->default('001')
                            ->live(onBlur: true)
                            ->helperText('Una caja, una terminal.'),

                        TextInput::make('codigo_documento')
                            ->label('Documento')
                            ->required()
                            ->length(2)
                            ->rule('regex:/^\d{2}$/')
                            ->default('01')
                            ->live(onBlur: true)
                            ->helperText('01 es Factura.'),

                        Placeholder::make('vista_del_numero')
                            ->label('Así va a salir impreso')
                            ->content(static fn (Get $get): HtmlString => self::comoSeVeElNumero($get))
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                /*
                 * ═══ QUIEN IMPRIME EL PAPEL ═══
                 *
                 * Mauricio lo corrigio el 13-ago-2026: «eso de la imprenta
                 * no, ya que seria como autoimpresor». Tiene razon para SU
                 * caso, y el sistema es justamente lo que va a imprimir.
                 *
                 * Pero la opcion del talonario no se borra: esto se le vende
                 * a mas lotificadoras (Ley L0) y la que compre mañana bien
                 * puede seguir con talonario de imprenta. Lo que se hizo fue
                 * poner AUTOIMPRESOR como lo normal y dejar la imprenta
                 * detras de una eleccion explicita.
                 *
                 * ⚠️ No es columna: se deduce de si hay imprenta cargada.
                 * Mismo criterio que el modo de facturacion del proyecto —un
                 * segundo lugar donde viva la misma verdad es un segundo
                 * lugar que se puede quedar viejo.
                 */
                Section::make('Cómo se imprime el documento')
                    ->icon('heroicon-o-printer')
                    ->schema([
                        Radio::make('quien_imprime')
                            ->label('¿Quién imprime?')
                            ->options([
                                'autoimpresor' => 'Autoimpresor',
                                'imprenta'     => 'Talonario de una imprenta autorizada',
                            ])
                            ->descriptions([
                                'autoimpresor' => 'Lo imprime este sistema. Es lo normal cuando la factura sale de la computadora y no de un block preimpreso.',
                                'imprenta'     => 'El talonario lo imprimió una imprenta inscrita en el Registro Fiscal de Imprentas, y sus datos van preimpresos en el papel.',
                            ])
                            ->default('autoimpresor')
                            ->dehydrated(false)
                            ->live()
                            ->formatStateUsing(static fn (?Facturacion $record): string => $record instanceof Facturacion
                                && filled($record->getAttribute('imprenta_nombre'))
                                    ? 'imprenta'
                                    : 'autoimpresor')
                            ->afterStateUpdated(static function (string $state, Set $set): void {
                                if ($state !== 'autoimpresor') {
                                    return;
                                }

                                // Se limpian de verdad: si quedaran cargados,
                                // al reabrir la ficha volveria a decir
                                // «imprenta» y nadie entenderia por que.
                                $set('imprenta_nombre', null);
                                $set('imprenta_rtn', null);
                                $set('imprenta_certificado', null);
                            })
                            ->columnSpanFull(),

                        MayusculasField::make('imprenta_nombre')
                            ->label('Nombre de la imprenta')
                            ->maxLength(160)
                            ->visible(static fn (Get $get): bool => $get('quien_imprime') === 'imprenta'),

                        TextInput::make('imprenta_rtn')
                            ->label('RTN de la imprenta')
                            ->maxLength(20)
                            ->visible(static fn (Get $get): bool => $get('quien_imprime') === 'imprenta'),

                        TextInput::make('imprenta_certificado')
                            ->label('N.º de certificado')
                            ->maxLength(40)
                            ->helperText('El del Registro Fiscal de Imprentas.')
                            ->visible(static fn (Get $get): bool => $get('quien_imprime') === 'imprenta'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Observaciones')
                    ->schema([
                        Textarea::make('observaciones')
                            ->label('Notas internas')
                            ->rows(3)
                            ->helperText('No se imprime en ningún lado. Sirve para dejar anotado quién tramitó la autorización o qué dijo el contador.'),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * El número armado, para verlo antes de que exista.
     *
     * El cuarto segmento va en gris y con un ejemplo: no se elige acá, lo
     * pone el correlativo de la autorización que se cargue después. Verlo
     * apagado dice eso mejor que una frase.
     */
    private static function comoSeVeElNumero(Get $get): HtmlString
    {
        $segmento = static function (mixed $valor, int $largo): string {
            $texto = is_string($valor) ? trim($valor) : '';

            return $texto === '' ? str_repeat('·', $largo) : e($texto);
        };

        return new HtmlString(sprintf(
            '<span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:1.35rem;'.
            'font-weight:600;letter-spacing:.06em">%s-%s-%s-<span style="opacity:.4">00000001</span></span>',
            $segmento($get('codigo_establecimiento'), 3),
            $segmento($get('codigo_punto_emision'), 3),
            $segmento($get('codigo_documento'), 2),
        ));
    }
}
