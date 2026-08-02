<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Pages;

use App\Filament\Resources\Lotes\LoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListLotes extends ListRecords
{
    #[Override]
    protected static string $resource = LoteResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
