<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\RelationManagers;

use App\Filament\Resources\Apartados\ApartadoResource;
use App\Filament\Resources\Clientes\Pages\ViewCliente;
use App\Models\Cliente;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Los lotes que este cliente tiene reservados, adentro de su ficha.
 *
 * Reusa entera la `ApartadosTable` vía `$relatedResource` — ver el docblock
 * de `VentasRelationManager`, que explica por qué eso importa. Acá vale
 * doble: las acciones de prorrogar, liberar y marcar la seña devuelta (R14)
 * quedan al alcance sin salir de la ficha, con su permiso propio cada una.
 *
 * La relación `Cliente::apartados()` ya filtra por tipo, así que esta
 * pestaña nunca puede mostrar un lote vendido.
 */
class ApartadosRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'apartados';

    #[Override]
    protected static ?string $relatedResource = ApartadoResource::class;

    #[Override]
    protected static ?string $title = 'Apartados';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-bookmark';

    #[Override]
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Cliente) {
            return null;
        }

        $cuantos = $ownerRecord->apartados()->count();

        return $cuantos === 0 ? null : (string) $cuantos;
    }

    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewCliente::class
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /**
     * Sin esto, el default de Filament para una página de vista esconde
     * prorrogar, liberar y devolver la seña — que son la razón de la pestaña.
     */
    #[Override]
    public function isReadOnly(): bool
    {
        return false;
    }
}
