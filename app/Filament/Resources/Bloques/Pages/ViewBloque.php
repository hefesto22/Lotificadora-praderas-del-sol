<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bloques\Pages;

use App\Filament\Resources\Bloques\BloqueResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;

class ViewBloque extends ViewRecord
{
    #[Override]
    protected static string $resource = BloqueResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
