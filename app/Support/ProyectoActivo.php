<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Qué proyecto se está mirando — 11-sep-2026.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * «Cuando carguemos otros proyectos —ya hablamos con la clienta y
 * posiblemente agreguemos otros dos en unos días o semanas» — Mauricio.
 *
 * Hasta hoy la instalación de Praderas tenía UN proyecto, así que sumar todo
 * y sumar ese proyecto era lo mismo. Con tres desarrollos deja de serlo: el
 * Escritorio diría «104 lotes disponibles de 309» mezclando inventarios de
 * tres residenciales distintos, y la lista de a quién llamar mezclaría
 * clientes de los tres. Quien cobra trabaja un desarrollo a la vez.
 *
 * Esto es el interruptor: la pantalla se recorta al proyecto elegido, y
 * «Todos» sigue existiendo para la foto de la empresa completa.
 *
 * ═══ 🔴 VIVE EN LA SESION, NO EN LA BASE ═══
 *
 * Elegir un proyecto es una preferencia de PANTALLA, no un dato del negocio.
 * Guardarlo en `users` haría que cambiar de vista escriba en la base y deje
 * rastro en la bitácora, y que dos pestañas abiertas se pisen entre sí. En la
 * sesión no molesta a nadie y se olvida al salir.
 *
 * ═══ 🔴 UN COMANDO NUNCA VE UN RECORTE DE PANTALLA ═══
 *
 * `olympo:verificar-produccion`, `olympo:cuadrar-recibos` y los seeders
 * corren sin sesión: `session()->get()` devuelve null y esto contesta
 * «Todos». Es exactamente lo que tiene que pasar — un comando que cuadra la
 * cartera no puede estar mirando un pedazo—, y por eso el guardado va
 * envuelto en `try`/`catch` en vez de preguntar por `runningInConsole()`:
 * bajo Pest ese método devuelve true y dejaría al selector sin poder
 * probarse.
 *
 * ═══ POR QUE NO ES UN GLOBAL SCOPE ═══
 *
 * Un `addGlobalScope` filtraría todo solo, con una línea. Y sería la peor
 * decisión del repo: un recorte invisible sobre consultas de dinero es cómo
 * se llega a «el número está mal y nadie sabe por qué». Cada lugar que
 * recorta lo dice en su propia línea, y `grep ProyectoActivo` los encuentra
 * a todos.
 */
final class ProyectoActivo
{
    public const string CLAVE = 'olympo.proyecto_activo';

    private ?Proyecto $elegido = null;

    private bool $yaLoBusco = false;

    /**
     * El proyecto que se está mirando, o null cuando son todos.
     *
     * ⚠️ Memoriza en la INSTANCIA y no en un `static`: la clase se resuelve
     * del contenedor, que Pest rehace por test. Un `static` sobreviviría de
     * un test al siguiente y el segundo vería el proyecto del primero.
     */
    public function proyecto(): ?Proyecto
    {
        if ($this->yaLoBusco) {
            return $this->elegido;
        }

        $this->yaLoBusco = true;
        $id = $this->guardado();

        // Un proyecto borrado deja de existir y la pantalla vuelve a «Todos»
        // sola, en vez de mostrar un listado vacío que nadie sabe explicar.
        $this->elegido = $id === null ? null : Proyecto::query()->find($id);

        return $this->elegido;
    }

    public function id(): ?int
    {
        $proyecto = $this->proyecto();

        return $proyecto instanceof Proyecto ? (int) $proyecto->getKey() : null;
    }

    public function hayUno(): bool
    {
        return $this->proyecto() instanceof Proyecto;
    }

    public function elegir(?int $id): void
    {
        $this->yaLoBusco = false;
        $this->elegido = null;

        try {
            if ($id === null) {
                session()->forget(self::CLAVE);

                return;
            }

            session()->put(self::CLAVE, $id);
        } catch (Throwable) {
            // Sin sesión no hay nada que recordar, y tampoco nada que romper.
        }
    }

    /**
     * Recorta una consulta al proyecto que se está mirando.
     *
     * El molde de los listados: `ProyectoActivo::recortar()` dentro de
     * `getEloquentQuery()` y listo. En «Todos» devuelve la consulta intacta,
     * que es literalmente el comportamiento de siempre — por eso una
     * instalación de un solo proyecto no nota nada.
     *
     * ⚠️ NO sirve para `recibos`: esa tabla no tiene `proyecto_id` y llega a
     * su proyecto por la venta o por el compromiso, según sea un cobro o la
     * seña de un apartado. Para eso está `Recibo::delProyecto()`.
     *
     * @template TModelo of Model
     *
     * @param Builder<TModelo> $query
     *
     * @return Builder<TModelo>
     */
    public function recortar(Builder $query, string $columna = 'proyecto_id'): Builder
    {
        $id = $this->id();

        return $id === null ? $query : $query->where($columna, $id);
    }

    /**
     * Los proyectos entre los que se puede elegir, en el orden del menú.
     *
     * @return Collection<int, Proyecto>
     */
    public function disponibles(): Collection
    {
        return Proyecto::query()->orderBy('nombre')->get(['id', 'nombre', 'codigo']);
    }

    /**
     * Cuántos hay. Con uno solo el selector no se dibuja: un interruptor de
     * una sola posición es ruido en la barra.
     */
    public function cuantosHay(): int
    {
        return Proyecto::query()->count();
    }

    private function guardado(): ?int
    {
        try {
            $valor = session()->get(self::CLAVE);
        } catch (Throwable) {
            return null;
        }

        return is_int($valor) || (is_string($valor) && ctype_digit($valor)) ? (int) $valor : null;
    }
}
