<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoLote;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra un proyecto entero desde la terminal.
 *
 *   php artisan proyecto:eliminar RVV
 *   php artisan proyecto:eliminar RVV PRB --forzar
 *   php artisan proyecto:eliminar RVV --forzar --liberar
 *
 * El borrado en cascada y la regla que lo frena viven en el modelo
 * (Proyecto::booted), no acá: así el botón de Filament, un tinker y este
 * comando se comportan igual. Acá solo está la conversación con quien lo
 * corre — mostrar qué se lleva puesto y pedir confirmación.
 *
 * La regla se pregunta ANTES con lotesConMovimiento() en vez de provocar
 * la excepción y atajarla: un comando de consola tiene que contestar con
 * una línea legible, no con un stack trace.
 */
final class EliminarProyecto extends Command
{
    protected $signature = 'proyecto:eliminar
                            {codigo* : Código del proyecto, por ejemplo RVV}
                            {--forzar : No preguntar}
                            {--liberar : Liberar antes los lotes apartados o vendidos}';

    protected $description = 'Borra un proyecto con sus bloques, lotes, calles y compromisos';

    public function handle(): int
    {
        /** @var list<string> $codigos */
        $codigos = $this->argument('codigo');
        $salida = self::SUCCESS;

        foreach ($codigos as $codigo) {
            if (! $this->eliminar(mb_strtoupper($codigo))) {
                $salida = self::FAILURE;
            }
        }

        return $salida;
    }

    private function eliminar(string $codigo): bool
    {
        $proyecto = Proyecto::query()->where('codigo', $codigo)->first();

        if (! $proyecto instanceof Proyecto) {
            $this->error("No existe ningún proyecto con código {$codigo}.");

            return false;
        }

        $bloques = $proyecto->bloques()->count();
        $lotes = $proyecto->lotes()->count();
        $ocupados = $proyecto->lotesConMovimiento();

        $this->line("{$proyecto->getAttribute('nombre')} ({$codigo}): {$bloques} bloque(s), {$lotes} lote(s).");

        if ($ocupados > 0 && ! (bool) $this->option('liberar')) {
            $this->error("Tiene {$ocupados} lote(s) que no están disponibles: no se borra.");
            $this->line('Liberalos primero. Si es un proyecto de prueba y da igual, agregá --liberar.');

            return false;
        }

        if (! (bool) $this->option('forzar') && ! $this->confirm('¿Lo borro con todo lo que cuelga de él?')) {
            $this->line('No se borró nada.');

            return true;
        }

        DB::transaction(function () use ($proyecto, $ocupados): void {
            if ($ocupados > 0) {
                $this->liberar($proyecto);
            }

            $proyecto->delete();
        });

        $this->info("Borrado {$codigo}.");

        return true;
    }

    /**
     * Deja disponibles los lotes con movimiento, dejando dicho cuáles.
     *
     * Va por update masivo a propósito: el proyecto entero se borra en la
     * misma transacción, así que no hay a quién notificarle el cambio de
     * estado, y pasar 78 lotes por el modelo uno por uno solo agrega
     * eventos que nadie va a escuchar. Los códigos se imprimen para que
     * quede constancia de qué se liberó.
     */
    private function liberar(Proyecto $proyecto): void
    {
        $codigos = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('estado', '!=', EstadoLote::Disponible->value)
            ->orderBy('codigo')
            ->pluck('codigo')
            ->all();

        $this->warn('Se liberan antes de borrar: '.implode(', ', $codigos));

        Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('estado', '!=', EstadoLote::Disponible->value)
            ->update(['estado' => EstadoLote::Disponible->value]);
    }
}
