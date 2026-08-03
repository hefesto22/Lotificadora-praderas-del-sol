<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Pages;

use App\Filament\Resources\Clientes\ClienteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditCliente extends EditRecord
{
    #[Override]
    protected static string $resource = ClienteResource::class;

    /**
     * §9.A.1: en cabecera van acciones directas, nunca dentro de un
     * ActionGroup — ahí no reciben $record y quedan invisibles.
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
