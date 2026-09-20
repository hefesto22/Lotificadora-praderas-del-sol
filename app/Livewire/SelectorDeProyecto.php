<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Resources\Proyectos\ProyectoResource;
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
        $id = $this->elegido === '' ? null : (int) $this->elegido;

        app(ProyectoActivo::class)->elegir($id);

        /*
         * 🔴 Si estás viendo el PLANO de un proyecto y cambiás de proyecto, hay
         * que saltar al plano del NUEVO, no recargar la URL vieja: esa apunta a
         * un proyecto que ya no es el que mirás, y con la lista recortada
         * confunde (antes, además, daba 404). El resto de las pantallas se
         * recargan y se recortan solas.
         *
         * La decisión se toma en el navegador —la petición de Livewire no sabe
         * en qué ruta está la pestaña— comparando el pathname contra el patrón
         * del plano y cambiándole el id.
         */
        $destino = $id === null ? null : ProyectoResource::getUrl('plano', ['record' => $id]);

        $this->js(<<<JS
            (function () {
                var destino = {$this->comoJs($destino)};
                if (destino && /^\/proyectos\/\d+\/plano\/?$/.test(window.location.pathname)) {
                    window.location.href = destino;
                } else {
                    window.location.reload();
                }
            })();
        JS);
    }

    /**
     * Un string PHP como literal seguro para incrustar en el JS de arriba, o
     * `null` cuando no hay destino (se eligió «Todos»).
     */
    private function comoJs(?string $valor): string
    {
        return $valor === null ? 'null' : json_encode($valor, JSON_THROW_ON_ERROR);
    }

    public function render(): View
    {
        return view('livewire.selector-de-proyecto', [
            'proyectos' => app(ProyectoActivo::class)->disponibles(),
        ]);
    }
}
