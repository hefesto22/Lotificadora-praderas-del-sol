<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\RelationManagers;

use App\Filament\Resources\Clientes\Pages\ViewCliente;
use App\Filament\Resources\Ventas\VentaResource;
use App\Models\Cliente;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Los expedientes de este cliente, adentro de su ficha.
 *
 * ═══ NO REDEFINE NI UNA COLUMNA, Y ES EL PUNTO ═══
 *
 * `$relatedResource` hace que Filament aplique la MISMA `VentasTable` de la
 * pantalla de Ventas: las mismas columnas, los mismos filtros, el mismo saldo
 * calculado con subconsulta y el mismo botón de ver. El día que esa tabla
 * gane una columna, aparece acá sola — y nunca puede decir algo distinto de
 * lo que dice la pantalla grande.
 *
 * De regalo viajan otras dos cosas: el permiso —`canViewForRecord` del padre
 * termina preguntando `VentaResource::canAccess()`, así que quien no puede
 * entrar a Ventas tampoco ve esta pestaña (§13.5)— y el destino del botón
 * «Ver», que abre el expediente de verdad y no un modal a medias.
 */
class VentasRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'ventas';

    #[Override]
    protected static ?string $relatedResource = VentaResource::class;

    #[Override]
    protected static ?string $title = 'Ventas';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-document-text';

    /**
     * El número en la pestaña, y NULL cuando no hay ninguna.
     *
     * Un cero permanente se vuelve parte del decorado y dentro de un mes ya
     * nadie lo ve — el mismo criterio del contador de apartados vencidos que
     * vive en el menú.
     */
    #[Override]
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Cliente) {
            return null;
        }

        $cuantas = $ownerRecord->ventas()->count();

        return $cuantas === 0 ? null : (string) $cuantas;
    }

    /**
     * Solo en la ficha, no en el formulario de edición.
     *
     * Tres tablas colgando debajo de un formulario donde se corrige un
     * teléfono son ruido, y la ficha está a un clic. El `parent::` se
     * conserva para que la pregunta del permiso la siga contestando
     * `VentaResource` y no esta clase.
     */
    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewCliente::class
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /**
     * Nunca de solo lectura por ser una página de vista: el default de
     * Filament escondería hasta el botón de abrir el expediente.
     */
    #[Override]
    public function isReadOnly(): bool
    {
        return false;
    }
}
