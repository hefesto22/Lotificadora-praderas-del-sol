{{--
    El encabezado del Escritorio. Reemplaza al «Bienvenida/o» de fábrica.

    Sin una sola clase de Tailwind, por lo mismo que el resto del chasis
    visual: el CSS vive en `filament/tema-olympo`, que se inyecta en el
    <head> por renderHook. Una clase de Tailwind que Vite no haya compilado
    no existe en el panel, y acá no hay build que la compile.
--}}
<div class="olympo-encabezado">
    <div class="olympo-encabezado-marca">
        @if ($logo)
            <img src="{{ $logo }}" alt="" class="olympo-encabezado-logo">
        @endif

        <div class="olympo-encabezado-texto">
            <p class="olympo-encabezado-rotulo">Sistema de lotificación</p>
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
