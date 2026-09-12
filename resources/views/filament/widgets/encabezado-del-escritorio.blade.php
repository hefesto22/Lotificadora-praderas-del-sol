{{--
    El encabezado del Escritorio. Reemplaza al «Bienvenida/o» de fábrica.

    🔴 EL `<x-filament-widgets::widget>` NO ES DECORACION.

    Ese componente es el que aplica `gridColumn($this->getColumnSpan())`, o
    sea el que hace valer el `columnSpan = 'full'` de la clase. Sin él, la
    propiedad se declara y no la lee nadie: el widget cae en UNA columna de
    las dos del Escritorio y el encabezado sale a media pantalla, con la
    fecha envuelta debajo del logo en vez de a la derecha.

    Se vio en pruebas el 12-sep-2026 y costó una vuelta entera. Los widgets
    de cifras no tenían el problema porque `StatsOverviewWidget` ya trae ese
    envoltorio en su propia vista.

    Sin una sola clase de Tailwind, por lo mismo que el resto del chasis
    visual: el CSS vive en `filament/tema-olympo`, que se inyecta en el
    <head> por renderHook. Una clase de Tailwind que Vite no haya compilado
    no existe en el panel, y acá no hay build que la compile.
--}}
<x-filament-widgets::widget>
    <div class="olympo-encabezado">
        <div class="olympo-encabezado-marca">
            @if ($logo)
                <img src="{{ $logo }}" alt="" class="olympo-encabezado-logo">
            @endif

            <div class="olympo-encabezado-texto">
                <p class="olympo-encabezado-rotulo">{{ $rotulo }}</p>
                <h1 class="olympo-encabezado-nombre">{{ $residencial }}</h1>
            </div>
        </div>

        <div class="olympo-encabezado-dia">
            <p class="olympo-encabezado-fecha">{{ $fecha }}</p>

            @if ($quien !== '')
                <p class="olympo-encabezado-quien">
                    {{ $quien }}@if ($rol !== '') <span>· {{ $rol }}</span>@endif
                </p>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
