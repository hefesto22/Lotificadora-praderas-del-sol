<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Filament\Resources\Proyectos\ProyectoResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Override;

class ViewProyecto extends ViewRecord
{
    #[Override]
    protected static string $resource = ProyectoResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('plano')
                ->label('Ver lotes')
                ->icon(Heroicon::OutlinedMap)
                ->url(fn (): string => ProyectoResource::getUrl('plano', ['record' => $this->getRecord()])),
            EditAction::make(),
        ];
    }
}
