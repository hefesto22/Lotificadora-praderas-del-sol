<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Pages;

use App\Filament\Resources\Lotes\LoteResource;
use Filament\Resources\Pages\CreateRecord;
use Override;

class CreateLote extends CreateRecord
{
    #[Override]
    protected static string $resource = LoteResource::class;
}
