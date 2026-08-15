<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Facturacion\EstadoDelTalonario;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Override;

/**
 * El aviso de que el talonario se está acabando, donde sí se mira.
 *
 * ═══ LO QUE EL CONTRATO PIDE POR NOMBRE ═══
 *
 * Cláusula Segunda, módulo g-ii: «control de talonario manual y **alertas de
 * agotamiento**». El cálculo existía desde el 13-ago-2026 y se dibujaba
 * adentro de `Administración → Facturación`, una pantalla a la que se entra
 * dos veces al año. Un aviso al que hay que ir a buscar no es un aviso.
 *
 * ═══ POR QUE ARRIBA DE TODO, Y POR QUE DESAPARECE ═══
 *
 * `sort = 0`: cuando aparece, es lo primero que se ve al abrir el sistema.
 * Y cuando no hay nada que decir **no se dibuja nada** —ni un recuadro verde
 * diciendo «todo bien»—, porque un aviso que está siempre presente se deja de
 * leer, y el día que cambie de color nadie lo va a notar.
 *
 * ═══ QUIEN LO VE ═══
 *
 * Quien pueda ver recibos, que es quien cobra. No es información del dueño
 * como los gastos: el que se queda con un cliente enfrente y sin poder emitir
 * el papel es el receptor, y avisarle solo a la administradora sería avisarle
 * a quien no está en la ventanilla.
 */
class ElTalonarioSeAcaba extends StatsOverviewWidget
{
    #[Override]
    protected ?string $pollingInterval = null;

    #[Override]
    protected static ?int $sort = 0;

    #[Override]
    protected int|string|array $columnSpan = 'full';

    #[Override]
    public static function canView(): bool
    {
        if (auth()->user()?->can('ViewAny:Recibo') !== true) {
            return false;
        }

        // Sin nada que avisar, el widget no existe.
        return EstadoDelTalonario::lasQueAvisan() !== [];
    }

    /**
     * @return array<int, Stat>
     */
    #[Override]
    protected function getStats(): array
    {
        $stats = [];

        foreach (EstadoDelTalonario::lasQueAvisan() as $estado) {
            $stats[] = Stat::make($estado->nombre(), $estado->titular())
                ->description($estado->detalle())
                ->descriptionIcon($estado->esUnParo()
                    ? 'heroicon-m-x-circle'
                    : 'heroicon-m-exclamation-triangle')
                ->color($estado->color());
        }

        return $stats;
    }
}
