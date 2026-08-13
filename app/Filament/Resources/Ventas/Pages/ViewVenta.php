<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Schemas\Components\DNIField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Support\CobrarUnPago;
use App\Models\Compromiso;
use App\Models\Venta;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Override;

/**
 * La ficha del expediente.
 *
 * Tres botones, y dos de ellos mueven dinero: COBRAR, que es lo que se hace
 * todos los días, y ABONAR A CAPITAL, que además reescribe el plan. Editar una
 * venta firmada no es una acción genérica (ver el docblock de `VentaResource`):
 * rescindir, liquidar e imprimir el contrato entran acá cuando se construya
 * cada trámite, cada uno con su nombre y su motivo.
 *
 * ═══ POR QUE ESTA PAGINA PASO DE 996 LINEAS A ESTO ═══
 *
 * Los dos modales de dinero se mudaron a `App\Filament\Support\CobrarUnPago` el
 * 10-ago-2026. No fue por estética: Mauricio pidió cobrar DESDE LA TABLA sin
 * salir de la pantalla donde se está —«siempre en la vista de cliente ahí debe
 * de abrirse el modal»— y un modal que vive en una página no se puede abrir
 * desde una fila. Copiarlo habría dejado dos modales de dinero que hay que
 * mantener iguales, y el día que se separen uno de los dos le miente a un
 * cliente.
 *
 * Los dos botones siguen acá porque acá es donde la administradora trabaja. Lo
 * que cambió es que su contenido se define en un solo lugar.
 */
class ViewVenta extends ViewRecord
{
    #[Override]
    protected static string $resource = VentaResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->estadoDeCuentaAction(),
            CobrarUnPago::accion(),
            CobrarUnPago::abonoDirecto(),
            $this->titularesDeReciboAction(),
        ];
    }

    /**
     * A nombre de quien salen los recibos de cada lote de este contrato.
     *
     * ═══ POR QUE ACA Y NO EN UNA PANTALLA APARTE ═══
     *
     * Porque es una configuracion DEL EXPEDIENTE, y quien la necesita ya esta
     * parado en el expediente. Se configura al vender, pero el caso llega
     * tarde: el grupo firma en junio y en septiembre aparece uno de los
     * representados pidiendo su recibo a su nombre.
     *
     * Se listan TODOS los lotes del contrato, no solo los que ya tienen
     * nombre: la pregunta es «¿como sale cada uno?» y eso incluye a los que
     * salen a nombre del dueño del expediente. Sin ellos, quien mira no puede
     * saber si el lote 3 esta sin configurar o si no existe.
     *
     * ⚠️ Los recibos ya emitidos NO cambian. Se quedaron con su copia
     * congelada del nombre (§8.2): un papel entregado no se corrige, se anula
     * y se emite otro. La descripcion del modal lo dice, porque es lo primero
     * que alguien va a suponer al revés.
     */
    private function titularesDeReciboAction(): Action
    {
        return Action::make('titulares_de_recibo')
            ->label('Titular de los recibos')
            ->icon(Heroicon::OutlinedIdentification)
            ->color('gray')
            ->modalHeading('¿A nombre de quién sale el recibo de cada lote?')
            ->modalDescription(
                'Vacío quiere decir que sale a nombre del dueño del expediente. Esto vale para los '.
                'cobros que vengan: los recibos ya entregados no cambian.'
            )
            ->modalSubmitActionLabel('Guardar')
            ->modalWidth('3xl')
            ->fillForm(fn (): array => ['lotes' => $this->lotesConSuTitular()])
            ->schema([
                Repeater::make('lotes')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columns(12)
                    ->itemLabel(fn (array $state): ?string => is_string($state['codigo'] ?? null)
                        ? $state['codigo']
                        : null)
                    ->schema([
                        Hidden::make('compromiso_id'),
                        Hidden::make('codigo'),

                        MayusculasField::make('titular_recibo')
                            ->label('Titular del recibo')
                            ->placeholder('El dueño del expediente')
                            ->maxLength(150)
                            ->columnSpan(8)
                            ->live(onBlur: true),

                        DNIField::make('titular_recibo_dni')
                            ->label('DNI del titular')
                            ->columnSpan(4)
                            ->visible(fn (Get $get): bool => filled($get('titular_recibo')))
                            ->helperText('Opcional.'),
                    ]),
            ])
            ->action(function (array $data): void {
                try {
                    $cuantos = $this->guardarLosTitulares($data);
                } catch (GrupoOlympoException $error) {
                    Notification::make()
                        ->title('No se pudo guardar')
                        ->body($error->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($cuantos === 1
                        ? 'Un lote sale a nombre de otra persona'
                        : "{$cuantos} lotes salen a nombre de otra persona")
                    ->body('Los recibos que se emitan de aquí en adelante llevan esos nombres.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Los lotes del contrato con lo que tengan configurado hoy.
     *
     * @return list<array<string, string|int|null>>
     */
    private function lotesConSuTitular(): array
    {
        $filas = [];

        foreach ($this->venta()->compromisos()->with('lote')->get() as $renglon) {
            $filas[] = [
                'compromiso_id'      => (int) $renglon->getKey(),
                'codigo'             => (string) $renglon->lote?->getAttribute('codigo'),
                'titular_recibo'     => $renglon->titularDelRecibo(),
                'titular_recibo_dni' => $renglon->dniDelTitularDelRecibo(),
            ];
        }

        return $filas;
    }

    /**
     * Pasa lo tecleado por el Service, y devuelve cuantos quedaron a nombre de
     * otra persona.
     *
     * `whereKey()->first()` y no `findOrFail()`: una fila vieja de una pestaña
     * que estuvo abierta no puede tumbar el guardado de las demas.
     *
     * @param array<string, mixed> $data
     */
    private function guardarLosTitulares(array $data): int
    {
        $registro = app(RegistroDeCompromisos::class);
        $filas = is_array($data['lotes'] ?? null) ? $data['lotes'] : [];
        $conNombre = 0;

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            if (! is_numeric($fila['compromiso_id'] ?? null)) {
                continue;
            }
            $renglon = Compromiso::query()->whereKey((int) $fila['compromiso_id'])->first();

            if (! $renglon instanceof Compromiso) {
                continue;
            }

            $nombre = is_string($fila['titular_recibo'] ?? null) ? $fila['titular_recibo'] : null;
            $dni = is_string($fila['titular_recibo_dni'] ?? null) ? $fila['titular_recibo_dni'] : null;

            $registro->ponerElTitularDelRecibo($renglon, $nombre, $dni);

            if ($renglon->refresh()->titularDelRecibo() !== null) {
                $conNombre++;
            }
        }

        return $conNombre;
    }

    /**
     * El estado de cuenta del expediente, listo para el papel.
     *
     * Sin `visible()`: quien está parado en esta página ya tiene `View:Venta`,
     * que es exactamente el permiso que pide el documento. Repetir la
     * comprobación acá sería una condición más que mantener sincronizada con
     * el controlador, para la misma decisión.
     *
     * Pestaña nueva, como el recibo: quien atiende no pierde el expediente.
     */
    private function estadoDeCuentaAction(): Action
    {
        return Action::make('estado_de_cuenta')
            ->label('Estado de cuenta')
            ->icon(Heroicon::OutlinedDocumentChartBar)
            ->color('gray')
            ->url(fn (): string => route('documentos.estado-de-cuenta', $this->venta()))
            ->openUrlInNewTab();
    }

    private function venta(): Venta
    {
        /** @var Venta $venta */
        $venta = $this->getRecord();

        return $venta;
    }
}
