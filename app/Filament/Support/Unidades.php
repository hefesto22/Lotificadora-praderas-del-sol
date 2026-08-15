<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Enums\UnidadDeArea;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * De qué proyecto sale la unidad que se imprime en pantalla.
 *
 * Existe porque la unidad dejó de ser una sola para toda la instalación
 * el 13-ago-2026 (ver UnidadDeArea): ahora cada desarrollo tiene la suya,
 * y una tabla de lotes puede estar mostrando dos proyectos a la vez.
 * Todas las pantallas preguntan acá para que la respuesta sea una sola.
 *
 * ⚠️ `de()` va por relaciones ya cargadas. Toda tabla que lo use tiene
 * que traer `with('proyecto')` en su consulta, o son 25 consultas por
 * página (§4.L4). Está anotado en cada llamador.
 */
final class Unidades
{
    /**
     * La unidad de un registro que ya existe: una fila, una ficha.
     */
    public static function de(?Model $registro): UnidadDeArea
    {
        if ($registro instanceof Proyecto) {
            return $registro->unidadDeArea();
        }

        if ($registro instanceof Lote) {
            return $registro->unidadDeArea();
        }

        if ($registro instanceof Bloque) {
            return $registro->unidadDeArea();
        }

        return UnidadDeArea::Varas;
    }

    /**
     * La unidad de un FORMULARIO, que puede estar creando algo.
     *
     * Se pregunta en tres lugares y en este orden, porque cada uno gana
     * cuando el anterior no sabe:
     *
     *  1. El `proyecto_id` que se está eligiendo en pantalla — manda,
     *     porque el usuario puede cambiarlo sin guardar.
     *  2. El registro que se está editando.
     *  3. El proyecto dueño, cuando el formulario vive adentro de una
     *     pestaña del proyecto y ni siquiera pregunta cuál es.
     */
    public static function delFormulario(Get $get, ?Model $registro, ?Component $livewire = null, string $campo = 'proyecto_id'): UnidadDeArea
    {
        // `$campo` porque adentro de una fila de un repetidor el proyecto
        // esta dos saltos arriba: '../../proyecto_id'.
        $elegido = $get($campo);

        if (is_numeric($elegido)) {
            // find() acepta tambien un array de ids, asi que Larastan lo
            // tipa Proyecto|Collection y toda llamada posterior es «metodo
            // indefinido en Collection». whereKey()->first() da Proyecto|null.
            $proyecto = Proyecto::query()->whereKey((int) $elegido)->first();

            if ($proyecto instanceof Proyecto) {
                return $proyecto->unidadDeArea();
            }
        }

        if ($registro instanceof Model) {
            return self::de($registro);
        }

        if ($livewire instanceof RelationManager) {
            return self::de($livewire->getOwnerRecord());
        }

        return UnidadDeArea::Varas;
    }
}
