<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Pages;

use App\Filament\Resources\Lotes\LoteResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;

class ViewLote extends ViewRecord
{
    #[Override]
    protected static string $resource = LoteResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
