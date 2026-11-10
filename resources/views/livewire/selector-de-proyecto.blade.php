{{--
    Con un solo proyecto no hay nada que elegir. El `<div>` vacío es a
    propósito: Livewire exige un elemento raíz único en el render.

    La clase del raíz la usa el tema (§18) en el teléfono, donde el interruptor
    baja a su propia fila y todo lo demás del bloque del logo se esconde.
--}}
<div class="olympo-selector-ctn">
    @if ($proyectos->count() > 1)
        <label class="olympo-selector">
            <span class="olympo-selector-rotulo">Proyecto</span>

            <select wire:model.live="elegido" class="olympo-selector-campo">
                <option value="">Todos</option>

                @foreach ($proyectos as $proyecto)
                    <option value="{{ $proyecto->getKey() }}">{{ $proyecto->getAttribute('nombre') }}</option>
                @endforeach
            </select>
        </label>
    @endif
</div>
