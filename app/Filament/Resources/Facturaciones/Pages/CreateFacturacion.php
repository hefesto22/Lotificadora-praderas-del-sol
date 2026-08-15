<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturaciones\Pages;

use App\Filament\Resources\Facturaciones\FacturacionResource;
use Filament\Resources\Pages\CreateRecord;
use Override;

class CreateFacturacion extends CreateRecord
{
    #[Override]
    protected static string $resource = FacturacionResource::class;
}
