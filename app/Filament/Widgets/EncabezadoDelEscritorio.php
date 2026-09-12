<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\BrandingSetting;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Override;
use Throwable;

/**
 * El encabezado del Escritorio — 11-sep-2026.
 *
 * ═══ QUE REEMPLAZA, Y POR QUE ═══
 *
 * Al `AccountWidget` de fábrica: un recuadro que decía «Bienvenida/o» con el
 * nombre de quien entró y un botón de salir. «Ese bienvenido debería quitarse
 * y hay que hacerlo más profesional y empresarial» —Mauricio—, y tenía razón
 * por una razón concreta: ese recuadro ocupaba el lugar de más peso de la
 * pantalla —arriba del todo, ancho completo— para decir algo que no le sirve
 * a nadie dos veces. El botón de salir ya vive en el menú del usuario, arriba
 * a la derecha, donde lo busca cualquiera.
 *
 * En ese mismo lugar ahora va lo que sí identifica el sistema: de quién es y
 * de qué día habla lo que se está mirando.
 *
 * ═══ POR QUE NO ES UN SALUDO CON OTRO NOMBRE ═══
 *
 * El nombre de quien entró queda, pero como una línea chica al costado y no
 * como el título. La diferencia no es de estilo: un tablero que empieza
 * diciendo «hola» se lee como una aplicación personal, y uno que empieza
 * diciendo de qué residencial es y de qué fecha habla se lee como el sistema
 * de una empresa. Es lo que pidió, y es lo que ve un cliente al que le
 * muestran la pantalla.
 *
 * ═══ EL LOGO SALE DE LA MARCA, NO DE UN ARCHIVO FIJO ═══
 *
 * `BrandingSetting::current()->logoUrl`, el mismo que usa el panel en la
 * barra lateral. Una lotificadora que todavía no cargó su logo ve solo el
 * nombre, sin un hueco ni un ícono de imagen rota: el blade pregunta antes de
 * dibujar.
 *
 * ⚠️ La lectura va con `try`/`catch` como en `AdminPanelProvider::brandingValue()`:
 * si la tabla de marca no existe todavía —una instalación nueva, una
 * migración a medias— el Escritorio tiene que abrir igual. Un encabezado que
 * tumba la pantalla de inicio es peor que un encabezado sin logo.
 */
class EncabezadoDelEscritorio extends Widget
{
    #[Override]
    protected string $view = 'filament.widgets.encabezado-del-escritorio';

    /**
     * Antes que todo, incluido el aviso del talonario (`sort = 0`).
     *
     * No le quita el primer lugar a ese aviso en lo que importa: el
     * encabezado no dice nada, ROTULA. Lo primero que le *avisa* algo a
     * alguien sigue siendo el talonario, justo debajo.
     */
    #[Override]
    protected static ?int $sort = -10;

    #[Override]
    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function getViewData(): array
    {
        $hoy = CarbonImmutable::now();

        return [
            'residencial' => $this->nombreDelSistema(),
            'logo'        => $this->logoDeLaMarca(),
            'fecha'       => fechaLarga($hoy, conDiaSemana: true),
            'quien'       => $this->quienEntro(),
            'rol'         => $this->elRolDeQuienEntro(),
        ];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El nombre del residencial, de `config('app.name')`.
     *
     * Es el mismo que usa `olympo:verificar-produccion` para encabezar su
     * informe —«Revisión del servidor — RESIDENCIAL PRADERAS DEL SOL»— y el
     * que cada instalación pone en su `.env`. No se inventa otra fuente para
     * lo mismo: dos nombres para el mismo sistema es cómo terminan diciendo
     * cosas distintas.
     *
     * ⚠️ `config()` y NUNCA `env()`: con `config:cache` puesto —y en el
     * servidor lo está— `env()` devuelve null y el encabezado saldría vacío.
     */
    private function nombreDelSistema(): string
    {
        $nombre = config('app.name');

        return is_string($nombre) && trim($nombre) !== '' ? $nombre : 'Olympo';
    }

    private function logoDeLaMarca(): ?string
    {
        $url = $this->deLaMarca('logoUrl');

        return $url !== null && trim($url) !== '' ? $url : null;
    }

    /**
     * Un atributo de la marca, o null si algo falla.
     *
     * Mismo molde que `AdminPanelProvider::brandingValue()`, y por el mismo
     * motivo: sin la tabla —instalación nueva, migración a medias— esto tiene
     * que devolver null y dejar abrir el panel, no reventarlo.
     */
    private function deLaMarca(string $atributo): ?string
    {
        try {
            $valor = BrandingSetting::current()->{$atributo} ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($valor) ? $valor : null;
    }

    private function quienEntro(): string
    {
        $nombre = auth()->user()?->getAttribute('name');

        return is_string($nombre) ? $nombre : '';
    }

    private function elRolDeQuienEntro(): string
    {
        $usuario = auth()->user();

        if ($usuario === null || ! method_exists($usuario, 'getRoleNames')) {
            return '';
        }

        $rol = $usuario->getRoleNames()->first();

        return is_string($rol) ? str_replace('_', ' ', $rol) : '';
    }
}
