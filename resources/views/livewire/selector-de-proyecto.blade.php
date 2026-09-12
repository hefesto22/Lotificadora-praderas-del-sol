{{--
    Con un solo proyecto no hay nada que elegir. El `<div>` vacío es a
    propósito: Livewire exige un elemento raíz único en el render.
--}}
<div>
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
