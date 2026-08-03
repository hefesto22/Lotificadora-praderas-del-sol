<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Domain\Plano\PlanoDelProyecto;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * El plano del proyecto: los lotes dibujados y pintados por estado.
 *
 * Es una pagina de SOLO LECTURA. Cambiar el estado de un lote desde el
 * plano exige elegir cliente, y eso vive en su propia accion —no en un
 * clic suelto sobre un poligono, que es dinero moviendose sin registro.
 */
class VerPlano extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProyectoResource::class;

    protected string $view = 'filament.resources.proyectos.pages.ver-plano';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Plano de '.$this->getRecord()->getAttribute('nombre');
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('volver')
                ->label('Volver al proyecto')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProyectoResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        return [
            'plano' => new PlanoDelProyecto()->para($proyecto),
        ];
    }
}
