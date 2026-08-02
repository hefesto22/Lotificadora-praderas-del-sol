<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bloques\Pages;

use App\Filament\Resources\Bloques\BloqueResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditBloque extends EditRecord
{
    #[Override]
    protected static string $resource = BloqueResource::class;

    /**
     * §9.A1: acciones directas en cabecera, nunca dentro de un ActionGroup.
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
