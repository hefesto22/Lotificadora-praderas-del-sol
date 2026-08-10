<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Exceptions\Foto360InvalidaException;

/**
 * Las marcas que se dibujan encima de la foto 360: contornos y rotulos.
 *
 * ═══ 🔴 ESTA CLASE ES UNA LISTA BLANCA, IGUAL QUE `PlanoPublico` ═══
 *
 * Lo que entra es un texto que alguien pego en el panel, y lo que sale viaja
 * al navegador de cualquiera que abra el link. Asi que no se guarda lo que
 * llego: **se construye un arreglo nuevo, campo por campo**, con cada numero
 * verificado y cada texto acotado.
 *
 * Si algun dia el editor agrega una propiedad, hay que venir aca y escribirla.
 * Eso es a proposito: es la unica forma de que nada llegue a la pagina publica
 * sin que alguien lo haya decidido.
 *
 * ═══ QUE SE ACEPTA ═══
 *
 *   contorno → puntos (2 a 64), color, grosor, cerrada
 *   rotulo   → un punto, texto, tamaño, orientacion, giro, vista
 *
 * Los angulos se acotan a su rango real —longitud en ±2π, latitud en ±π/2— y
 * no porque el editor los pueda pasar, sino porque el campo es un textarea y
 * cualquiera puede escribir cualquier cosa.
 */
final readonly class MarcasDelLote
{
    private const int MAXIMO_FIGURAS = 40;

    private const int MAXIMO_PUNTOS = 64;

    private const int LARGO_TEXTO = 40;

    /** @var list<string> */
    private const array ORIENTACIONES = ['plano', 'parado', 'suelo'];

    /**
     * Del texto pegado en el panel a la lista limpia que se guarda.
     *
     * @return list<array<string, mixed>>
     *
     * @throws Foto360InvalidaException si el texto no es una lista de marcas
     */
    public function desdeElTexto(?string $crudo): array
    {
        if ($crudo === null || trim($crudo) === '') {
            return [];
        }

        $datos = json_decode(trim($crudo), true);

        if (! is_array($datos) || ! array_is_list($datos)) {
            throw new Foto360InvalidaException(
                'Eso no parecen marcas del editor 360. Apretá «Copiar marcas» en el editor y pegá '
                .'el texto completo, sin recortarlo.'
            );
        }

        return $this->limpiar($datos);
    }

    /**
     * Lo que ya esta guardado, revisado otra vez antes de publicarlo.
     *
     * Se limpia en los DOS extremos y no solo al guardar: una fila puede venir
     * de un import, de un tinker o de una version anterior de esta clase, y la
     * pagina publica no es el lugar para descubrirlo.
     *
     * @return list<array<string, mixed>>
     */
    public function paraPublicar(mixed $guardado): array
    {
        return is_array($guardado) && array_is_list($guardado) ? $this->limpiar($guardado) : [];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param list<mixed> $datos
     *
     * @return list<array<string, mixed>>
     */
    private function limpiar(array $datos): array
    {
        $limpias = [];

        foreach (array_slice($datos, 0, self::MAXIMO_FIGURAS) as $figura) {
            if (! is_array($figura)) {
                continue;
            }

            $puntos = $this->puntosDe($figura['puntos'] ?? null);

            if ($puntos === []) {
                continue;
            }

            $limpias[] = ($figura['tipo'] ?? '') === 'rotulo'
                ? $this->rotulo($figura, $puntos)
                : $this->contorno($figura, $puntos);
        }

        return $limpias;
    }

    /**
     * @param array<string, mixed> $f
     * @param list<array{float, float}> $puntos
     *
     * @return array<string, mixed>
     */
    private function contorno(array $f, array $puntos): array
    {
        return [
            'tipo'    => 'contorno',
            'puntos'  => $puntos,
            'color'   => $this->color($f['color'] ?? null),
            'grosor'  => $this->entre($f['grosor'] ?? 6, 1, 12),
            'cerrada' => ($f['cerrada'] ?? false) === true,
        ];
    }

    /**
     * @param array<string, mixed> $f
     * @param list<array{float, float}> $puntos
     *
     * @return array<string, mixed>
     */
    private function rotulo(array $f, array $puntos): array
    {
        $vista = $this->puntosDe([$f['vista'] ?? null]);
        $orientacion = is_string($f['orientacion'] ?? null) ? $f['orientacion'] : 'plano';

        return [
            'tipo' => 'rotulo',
            // Un rotulo tiene UN punto: si llegaron mas, sobran.
            'puntos'      => [$puntos[0]],
            'texto'       => $this->texto($f['texto'] ?? null),
            'color'       => $this->color($f['color'] ?? null),
            'tamano'      => $this->entre($f['tamano'] ?? 26, 6, 90),
            'orientacion' => in_array($orientacion, self::ORIENTACIONES, true) ? $orientacion : 'plano',
            'giro'        => $this->entre($f['giro'] ?? 0, -180, 180),
            'vista'       => $vista === [] ? [0.0, 0.0] : $vista[0],
        ];
    }

    /**
     * @return list<array{float, float}>
     */
    private function puntosDe(mixed $crudos): array
    {
        if (! is_array($crudos)) {
            return [];
        }

        $puntos = [];

        foreach (array_slice($crudos, 0, self::MAXIMO_PUNTOS) as $par) {
            if (! is_array($par)) {
                continue;
            }

            if (! isset($par[0], $par[1])) {
                continue;
            }

            if (! is_numeric($par[0])) {
                continue;
            }

            if (! is_numeric($par[1])) {
                continue;
            }
            $puntos[] = [
                $this->acotar((float) $par[0], 2 * M_PI),
                $this->acotar((float) $par[1], M_PI / 2),
            ];
        }

        return $puntos;
    }

    private function acotar(float $valor, float $tope): float
    {
        if (! is_finite($valor)) {
            return 0.0;
        }

        return round(max(-$tope, min($tope, $valor)), 6);
    }

    private function entre(mixed $valor, int $minimo, int $maximo): int
    {
        return is_numeric($valor) ? max($minimo, min($maximo, (int) round((float) $valor))) : $minimo;
    }

    /**
     * Solo `#rrggbb`. Se acepta un color y no una cadena CSS a proposito: lo
     * que se guarda acaba dentro de un atributo del navegador del cliente.
     */
    private function color(mixed $valor): string
    {
        return is_string($valor) && preg_match('/^#[0-9a-fA-F]{6}$/', $valor) === 1
            ? strtolower($valor)
            : '#ffd400';
    }

    private function texto(mixed $valor): string
    {
        $limpio = is_string($valor) ? trim(preg_replace('/\s+/u', ' ', $valor) ?? '') : '';

        return $limpio === '' ? 'Lote' : mb_substr($limpio, 0, self::LARGO_TEXTO);
    }
}
