<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\ProyectoActivo;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * El interruptor de proyecto de la barra superior — 11-sep-2026.
 *
 * ═══ POR QUE ARRIBA Y NO UN FILTRO EN CADA PANTALLA ═══
 *
 * Un filtro por tabla obliga a elegir el proyecto otra vez en cada pantalla,
 * y con tres desarrollos eso es cuatro clics antes de empezar a trabajar.
 * Peor: el Escritorio no es una tabla y no tendría dónde ponerlo, así que las
 * cifras del tablero seguirían mezclando todo mientras los listados no.
 *
 * Arriba, una sola vez, y toda la pantalla se recorta.
 *
 * ⚠️ Con UN solo proyecto no se dibuja. Un interruptor de una sola posición
 * es ruido en la barra, y hoy —hasta que entren los dos que vienen— esa es la
 * situación de Praderas.
 *
 * ═══ POR QUE RECARGA LA PAGINA ENTERA ═══
 *
 * Cambiar de proyecto cambia CADA cifra de la pantalla: los cuatro cuadros
 * del mes, el de la caja, el del proyecto y el listado de abajo. Refrescar
 * componente por componente sería más elegante y dejaría a cualquiera que
 * olvide suscribirse mostrando el número del proyecto anterior — que es un
 * número correcto de otra cosa, la peor clase de error. Una recarga cuesta
 * medio segundo y no deja nada viejo en pantalla.
 */
class SelectorDeProyecto extends Component
{
    /**
     * El id como string porque un `<select>` devuelve strings, y «» es
     * «Todos». Convertir acá y no en la vista deja el casteo en un solo lado.
     */
    public string $elegido = '';

    public function mount(): void
    {
        $id = app(ProyectoActivo::class)->id();

        $this->elegido = $id === null ? '' : (string) $id;
    }

    public function updatedElegido(): void
    {
        app(ProyectoActivo::class)->elegir($this->elegido === '' ? null : (int) $this->elegido);

        $this->js('window.location.reload()');
    }

    public function render(): View
    {
        return view('livewire.selector-de-proyecto', [
            'proyectos' => app(ProyectoActivo::class)->disponibles(),
        ]);
    }
}
