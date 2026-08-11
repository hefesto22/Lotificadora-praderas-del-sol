<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Support\CobrarUnPago;
use App\Models\Venta;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
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
        ];
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
