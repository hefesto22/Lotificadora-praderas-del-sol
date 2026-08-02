<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Filament\Resources\Proyectos\ProyectoResource;
use Filament\Resources\Pages\CreateRecord;
use Override;

class CreateProyecto extends CreateRecord
{
    #[Override]
    protected static string $resource = ProyectoResource::class;
}
