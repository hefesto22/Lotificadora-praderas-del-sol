<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Filament\Resources\Proyectos\ProyectoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListProyectos extends ListRecords
{
    #[Override]
    protected static string $resource = ProyectoResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
