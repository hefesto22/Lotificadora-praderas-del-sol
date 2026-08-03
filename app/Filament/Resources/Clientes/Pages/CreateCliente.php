<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Pages;

use App\Filament\Resources\Clientes\ClienteResource;
use Filament\Resources\Pages\CreateRecord;
use Override;

class CreateCliente extends CreateRecord
{
    #[Override]
    protected static string $resource = ClienteResource::class;
}
