<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Schemas;

use App\Filament\Support\ListadoDelCliente;
use App\Models\Cliente;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

/**
 * La ficha del cliente: UN solo cuadro, a todo el ancho.
 *
 * ═══ POR QUE UNO Y NO DOS ═══
 *
 * Decisión de Mauricio el 10-ago, después de tres intentos con dos y con
 * cuatro cajas. El motivo es geométrico y es el que nos venía mordiendo:
 * dos tarjetas lado a lado nunca miden lo mismo, y la más corta deja un
 * hueco blanco al lado de la más larga. Con una sola no hay hueco posible.
 *
 * ═══ LA GRILLA CIERRA JUSTA ═══
 *
 * Cuatro columnas, y cada renglón se llena entero:
 *
 *   1. Nombre (dos columnas, es lo único largo) · DNI · Teléfono
 *   2. RTN · Correo · Dirección (dos)
 *   3. Observaciones (dos) · Activo · Registrado
 *   4. Debe hoy, a todo el ancho
 *
 * «Activo» y «Registrado» van al final a propósito: no son datos DEL
 * cliente sino de su ficha —cuándo se creó y si sigue en uso—. Arriba
 * competían con el DNI y el teléfono, que es lo que se busca de verdad.
 *
 * ═══ EL SALDO VA ABAJO, COMO EN UNA FACTURA ═══
 *
 * No es capricho: el total al pie es la convención de cualquier documento
 * de cobro desde antes de que existieran las pantallas. Se lee al final,
 * después de saber de quién estamos hablando, y cierra la ficha.
 *
 * ═══ LO QUE FALTA DICE «PENDIENTE» ═══
 *
 * Escondiendo los campos vacíos, una ficha incompleta se veía IGUAL que una
 * completa y nadie se enteraba de que faltaba pedirle esos datos al cliente.
 * Diciéndolo, la ficha se convierte en la lista de lo que hay que conseguir.
 *
 * ═══ NO AGREGA NI UNA CONSULTA ═══
 *
 * `saldoPendiente()` es la única que pega a la base, y ya estaba.
 */
class ClienteInfolist
{
    /** Lo que se muestra cuando el dato todavía no se consiguió. */
    private const string PENDIENTE = 'Pendiente';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos del cliente')
                    ->icon(Heroicon::OutlinedIdentification)
                    /*
                     * ═══ POR QUE HACEN FALTA LAS DOS COSAS ═══
                     *
                     * `ViewRecord::defaultInfolist()` le impone al infolist
                     * una grilla de DOS columnas, asi que sin `columnSpanFull`
                     * la caja toma una sola y deja media pantalla en blanco.
                     *
                     * Pero con eso NO alcanza, y el motivo se midio en el
                     * navegador el 10-ago-2026: adentro del envoltorio hay un
                     * contenedor `display: flex`, y el `<section>` es un item
                     * de flex sin `flex-grow`, o sea que mide lo que mide su
                     * contenido —688px de 1216—. La clase `olympo-ancho-total`
                     * es el ancla que usa el CSS del tema para estirar esa
                     * seccion; el porque completo esta escrito alla.
                     *
                     * La clase existe SOLO para esta caja: no le puede pasar
                     * nada a ninguna otra pantalla.
                     */
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'olympo-ancho-total'])
                    ->columns(4)
                    ->schema([
                        /*
                         * El nombre se lleva dos columnas: «MARIA DE LOS
                         * ANGELES RODRIGUEZ» en una sola se parte en tres
                         * renglones y descuadra la fila entera.
                         */
                        TextEntry::make('nombre')
                            ->label('Nombre completo')
                            ->weight(FontWeight::SemiBold)
                            ->columnSpan(2),

                        /*
                         * El DNI, el RTN y el teléfono se muestran formateados
                         * con los métodos del modelo, no con notación de punto
                         * ni con formatStateUsing sobre el crudo (§9.A.14).
                         */
                        TextEntry::make('dni')
                            ->label('DNI')
                            ->state(fn (Cliente $record): ?string => $record->dniFormateado())
                            ->placeholder(self::PENDIENTE)
                            ->copyable(),

                        TextEntry::make('telefono')
                            ->label('Teléfono')
                            ->state(fn (Cliente $record): ?string => $record->telefonoFormateado())
                            ->placeholder(self::PENDIENTE)
                            ->icon(Heroicon::OutlinedPhone)
                            ->copyable(),

                        TextEntry::make('rtn')
                            ->label('RTN')
                            ->state(fn (Cliente $record): ?string => $record->rtnFormateado())
                            ->placeholder(self::PENDIENTE)
                            ->copyable(),

                        TextEntry::make('correo')
                            ->label('Correo')
                            ->placeholder(self::PENDIENTE)
                            ->icon(Heroicon::OutlinedEnvelope)
                            ->copyable(),

                        TextEntry::make('direccion')
                            ->label('Dirección')
                            ->placeholder(self::PENDIENTE)
                            ->columnSpan(2),

                        TextEntry::make('observaciones')
                            ->label('Observaciones')
                            ->placeholder(self::PENDIENTE)
                            ->columnSpan(2),

                        IconEntry::make('activo')
                            ->label('Activo')
                            ->boolean(),

                        TextEntry::make('created_at')
                            ->label('Registrado')
                            ->date('d/m/Y'),

                        /*
                         * Este NO dice «Pendiente» y no se muestra vacío: un
                         * cliente archivado es un estado, no un dato que
                         * falte. Un «Archivado: Pendiente» permanente sería
                         * una mentira en gris.
                         */
                        TextEntry::make('deleted_at')
                            ->label('Archivado')
                            ->dateTime('d/m/Y H:i')
                            ->color('danger')
                            ->columnSpanFull()
                            ->visible(fn (Cliente $record): bool => filled($record->getAttribute('deleted_at'))),

                        /*
                         * El total al pie, a todo el ancho. Desaparece para
                         * quien no puede ver Ventas: el número sale de ahí
                         * (§13.5), y sin él la ficha sigue cerrando.
                         */
                        TextEntry::make('saldo_pendiente')
                            ->label('Debe hoy')
                            ->state(fn (Cliente $record): string => $record->saldoPendiente()->formateado())
                            ->helperText('Sumando todos sus expedientes vigentes. Lo rescindido no cuenta.')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->columnSpanFull()
                            ->visible(fn (): bool => ListadoDelCliente::puedeVerVentas()),
                    ]),
            ]);
    }
}
