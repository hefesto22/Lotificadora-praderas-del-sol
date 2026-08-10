<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\RelationManagers;

use App\Filament\Resources\Clientes\Pages\ViewCliente;
use App\Filament\Resources\Recibos\ReciboResource;
use App\Models\Cliente;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Todo lo que este cliente ha pagado, adentro de su ficha.
 *
 * Reusa entera la `RecibosTable` vía `$relatedResource` — ver el docblock de
 * `VentasRelationManager`. Lo que gana la ventanilla es reimprimir un recibo
 * sin tener que adivinar de qué expediente era.
 *
 * ═══ NO ES LA MISMA PESTAÑA QUE LA DEL EXPEDIENTE ═══
 *
 * `Ventas > Recibos` muestra lo pagado de UN contrato. Esta muestra lo que
 * pagó la PERSONA, aunque tenga tres contratos y aunque alguno de esos pagos
 * haya ido a un lote que compró con su esposa.
 *
 * Los anulados salen igual, tachados: el número sigue en la serie y el papel
 * sigue en la mano de alguien (R12).
 */
class RecibosRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'recibos';

    #[Override]
    protected static ?string $relatedResource = ReciboResource::class;

    #[Override]
    protected static ?string $title = 'Recibos';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-receipt-percent';

    #[Override]
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Cliente) {
            return null;
        }

        $cuantos = $ownerRecord->recibos()->count();

        return $cuantos === 0 ? null : (string) $cuantos;
    }

    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewCliente::class
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /**
     * Sin esto, el default de Filament para una página de vista esconde el
     * botón de imprimir, que es a lo que se entra.
     */
    #[Override]
    public function isReadOnly(): bool
    {
        return false;
    }
}
