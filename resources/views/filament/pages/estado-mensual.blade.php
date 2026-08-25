<x-filament-panels::page>
    {{-- Sin `wire:submit`: el botón que abre el documento vive en las acciones
         del encabezado, arriba a la derecha, que es donde Filament pone la
         acción principal de una pantalla en todo el resto del panel. --}}
    {{ $this->form }}
</x-filament-panels::page>
