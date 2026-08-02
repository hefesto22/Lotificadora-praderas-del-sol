<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bloques\Pages;

use App\Filament\Resources\Bloques\BloqueResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBloque extends CreateRecord
{
    protected static string $resource = BloqueResource::class;
}
