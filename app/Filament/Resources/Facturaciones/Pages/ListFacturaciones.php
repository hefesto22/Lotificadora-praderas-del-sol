<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturaciones\Pages;

use App\Filament\Resources\Facturaciones\FacturacionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListFacturaciones extends ListRecords
{
    #[Override]
    protected static string $resource = FacturacionResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
