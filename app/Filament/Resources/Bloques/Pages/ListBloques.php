<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bloques\Pages;

use App\Filament\Resources\Bloques\BloqueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListBloques extends ListRecords
{
    #[Override]
    protected static string $resource = BloqueResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
