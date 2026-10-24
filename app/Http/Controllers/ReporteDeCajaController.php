<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reportes\CajaPorDia;
use App\Http\Controllers\Concerns\MembreteDelProyecto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * El reporte de caja, listo para el papel — 24-oct-2026.
 *
 *     /documentos/caja?desde=2026-10-01&hasta=2026-10-24&proyectos=1,3
 *
 * «¿No hay un botón como para imprimir el reporte de lo que se selecciona?»
 * — Mauricio, mirando la pantalla «Caja» en producción. Es esa pantalla en
 * una hoja: el mismo `CajaPorDia`, el mismo rango acotado y la misma regla de
 * quién ve qué. Lo que se imprime es lo que se estaba mirando.
 *
 * HTML y no PDF, como el estado mensual: una hoja con su CSS adentro y el
 * botón de imprimir del navegador.
 *
 * ═══ 🔴 QUIEN LO VE, Y POR QUE NO SE LE CREE A LA URL ═══
 *
 * La llave es la de la lista de recibos (`viewAny` de `ReciboPolicy`). Y el
 * recorte del receptor —solo lo que recibió él— se decide ACÁ, con el usuario
 * conectado: la URL trae fechas y proyectos, nunca a quién recortar. Si la
 * trajera, un receptor que la editara vería la caja de los demás.
 *
 * ═══ EL MEMBRETE ═══
 *
 * Con UN proyecto elegido, el suyo —la misma escalera del estado mensual—.
 * Con varios o con todos, el de la lotificadora: una hoja que suma tres
 * residenciales no puede salir con el nombre de uno.
 */
final class ReporteDeCajaController
{
    use MembreteDelProyecto;

    /**
     * Los recibos que entran en el anexo. Un mes normal trae unas decenas; si
     * un rango trae más que esto, la hoja lo dice y la lista completa está en
     * el panel.
     */
    private const int RECIBOS_EN_EL_ANEXO = 1000;

    public function __invoke(Request $request): View
    {
        Gate::authorize('viewAny', Recibo::class);

        $hoy = CarbonImmutable::parse(today()->toDateString());

        $periodo = CajaPorDia::acotar(
            $this->fecha($request, 'desde') ?? $hoy->startOfMonth(),
            $this->fecha($request, 'hasta') ?? $hoy,
        );

        $proyectos = $this->proyectos($request);
        $usuario = $request->user();
        $recibidoPor = Roles::veLaCajaDeTodos($usuario) ? null : $this->idDe($usuario);

        $caja = CajaPorDia::de($periodo['desde'], $periodo['hasta'], $proyectos, $recibidoPor);
        $renglones = CajaPorDia::renglones($periodo['desde'], $periodo['hasta'], $proyectos, $recibidoPor, self::RECIBOS_EN_EL_ANEXO);

        $unSolo = count($proyectos) === 1
            ? Proyecto::query()->whereKey($proyectos[0])->first()
            : null;

        return view('documentos.caja', [
            'caja'            => $caja,
            'recortado'       => $periodo['recortado'],
            'proyectos'       => CajaPorDia::proyectosEscritos($proyectos),
            'quienes'         => $recibidoPor === null ? CajaPorDia::quienesRecibieron($periodo['desde'], $periodo['hasta'], $proyectos) : [],
            'renglones'       => $renglones,
            'faltan'          => max(0, $caja->recibos - count($renglones)),
            'soloDe'          => $recibidoPor === null ? null : $this->nombreDe($usuario),
            'emisor'          => $unSolo instanceof Proyecto ? $this->emisorDe($unSolo) : $this->emisor(),
            'logo'            => $this->logo(),
            'logoDelProyecto' => $unSolo?->logoUrl(),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Una fecha `YYYY-MM-DD` de la URL, o null. Es una dirección que alguien
     * puede teclear a mano: lo que no se entiende cae al mes corriente en vez
     * de reventar.
     */
    private function fecha(Request $request, string $campo): ?CarbonImmutable
    {
        $valor = $request->query($campo);

        if (! is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        $fecha = CarbonImmutable::createFromFormat('Y-m-d', $valor);

        // `2026-99-99` no da error: PHP lo desborda a otro año. Se compara de
        // vuelta para que una fecha imposible caiga al mes corriente.
        if (! $fecha instanceof CarbonImmutable || $fecha->format('Y-m-d') !== $valor) {
            return null;
        }

        return $fecha->startOfDay();
    }

    /**
     * `proyectos=1,3` — por coma, como `/documentos/recibos?recibos=12,13`.
     * Vacío o ausente = todos.
     *
     * @return list<int>
     */
    private function proyectos(Request $request): array
    {
        $valor = $request->query('proyectos');

        if (! is_string($valor) || trim($valor) === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $valor) as $parte) {
            $parte = trim($parte);

            if ($parte !== '' && ctype_digit($parte)) {
                $ids[] = (int) $parte;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Sin usuario, 0: nadie tiene ese id y la hoja sale vacía.
     */
    private function idDe(mixed $usuario): int
    {
        $id = $usuario instanceof User ? $usuario->getKey() : null;

        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) ? (int) $id : 0;
    }

    private function nombreDe(mixed $usuario): string
    {
        $nombre = $usuario instanceof User ? $usuario->getAttribute('name') : null;

        return is_string($nombre) && trim($nombre) !== '' ? mb_strtoupper(trim($nombre)) : '—';
    }
}
