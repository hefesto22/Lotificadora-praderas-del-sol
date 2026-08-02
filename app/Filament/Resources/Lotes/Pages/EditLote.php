<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Pages;

use App\Filament\Resources\Lotes\LoteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditLote extends EditRecord
{
    #[Override]
    protected static string $resource = LoteResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
