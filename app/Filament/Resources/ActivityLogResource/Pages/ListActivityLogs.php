<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogResource\Pages;

use App\Filament\Resources\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListActivityLogs extends ListRecords
{
    #[Override]
    protected static string $resource = ActivityLogResource::class;
}
