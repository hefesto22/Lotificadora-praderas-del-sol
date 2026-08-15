<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturaciones\Pages;

use App\Filament\Resources\Facturaciones\FacturacionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditFacturacion extends EditRecord
{
    #[Override]
    protected static string $resource = FacturacionResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
