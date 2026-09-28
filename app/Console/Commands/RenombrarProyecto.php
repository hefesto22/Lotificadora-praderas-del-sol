<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Venta;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cambia el código de un proyecto que todavía no imprimió ningún número.
 *
 *   php artisan olympo:renombrar-proyecto ABC XYZ --nombre="RESIDENCIAL LOS ALMENDROS" --ensayo
 *
 * ═══ POR QUE EXISTE SI LA FICHA CONGELA EL CODIGO ═══
 *
 * La ficha del proyecto lo congela (§10.3) porque el código viaja adentro de
 * papeles que ya se entregaron: el contrato dice `RPS-2026-0065` y el recibo
 * `RPS-00000012`. Cambiarlo después partiría cada serie en dos, con la mitad
 * de los papeles diciendo un código que ya no existe.
 *
 * Esa razón deja de valer mientras no se haya impreso NADA con el código. Lo
 * trajo Río Blanco el 28-sep-2026: se importó el plano como `CRB · COLONIA RIO
 * BLANCO` y el cuaderno del dueño llevaba los contratos firmados como
 * `RRB-2025-002` — «Residencial Río Blanco». Cargar la cartera con `CRB`
 * habría impreso un número de contrato distinto al que el cliente tiene en la
 * mano, y después ya no habría vuelta.
 *
 * ═══ LA REGLA ═══
 *
 * Se niega si el proyecto ya tiene un contrato numerado o un recibo en su
 * serie: esos papeles llevan el código escrito. Todo lo demás cuelga del
 * proyecto por `proyecto_id` —correlativos, planes, gastos, socios— y el
 * cambio no los toca.
 *
 * ⚠️ La dirección pública del plano (`slug`) NO cambia: ya pudo haberse
 * mandado por WhatsApp, y `Proyecto::booted()` solo la calcula cuando está
 * vacía justamente por eso.
 *
 * El código de cada lote —`XYZ-A-001`— sale de `Lote::componerCodigo()`, la
 * misma función que usa el modelo al guardar, y se escribe solo esa columna:
 * ni el valor ni el estado del lote se tocan.
 */
#[Description('Cambia el código (y el nombre) de un proyecto que todavía no imprimió ningún contrato ni recibo')]
#[Signature('olympo:renombrar-proyecto
                            {codigo : El código de hoy, por ejemplo ABC}
                            {nuevo : El código nuevo, por ejemplo XYZ}
                            {--nombre= : El nombre nuevo, si también cambia}
                            {--ensayo : Muestra lo que haría y no escribe nada}')]
final class RenombrarProyecto extends Command
{
    public function handle(): int
    {
        $codigo = $this->textoDe($this->argument('codigo'));
        $nuevo = $this->textoDe($this->argument('nuevo'));
        $nombre = $this->textoDe($this->option('nombre'));

        $proyecto = Proyecto::query()->where('codigo', $codigo)->first();

        if (! $proyecto instanceof Proyecto) {
            $this->components->error("No existe ningún proyecto con código {$codigo}.");

            return self::FAILURE;
        }

        $quejas = $this->quejas($proyecto, $codigo, $nuevo);

        if ($quejas !== []) {
            $this->components->error("No se renombra {$codigo}:");

            foreach ($quejas as $queja) {
                $this->line("   · {$queja}");
            }

            return self::FAILURE;
        }

        $nombreDeAntes = (string) $proyecto->getAttribute('nombre');
        $ensayo = (bool) $this->option('ensayo');

        DB::beginTransaction();

        try {
            $atributos = ['codigo' => $nuevo];

            if ($nombre !== '') {
                $atributos['nombre'] = $nombre;
            }

            $proyecto->forceFill($atributos)->save();
            $lotes = $this->renombrarLosLotes($proyecto, $nuevo);

            $ejemplo = Lote::query()
                ->where('proyecto_id', $proyecto->getKey())
                ->orderBy('codigo')
                ->value('codigo');
        } catch (Throwable $throwable) {
            DB::rollBack();

            throw $throwable;
        }

        if ($ensayo) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        $nombreDeDespues = (string) $proyecto->getAttribute('nombre');
        $slug = (string) $proyecto->getAttribute('slug');

        $this->line("   {$codigo} · {$nombreDeAntes}  →  {$nuevo} · {$nombreDeDespues}");
        $this->line("   {$lotes} lote(s) con código nuevo. Por ejemplo: ".(is_string($ejemplo) ? $ejemplo : '—'));
        $this->line("   El plano público sigue en la misma dirección: /plano/{$slug}");
        $this->line("   Los contratos y recibos que se impriman de acá en adelante llevan {$nuevo} adelante.");

        if ($ensayo) {
            $this->components->warn('Ensayo: no se escribió nada. Sin --ensayo, queda así.');

            return self::SUCCESS;
        }

        $this->components->info("Listo: {$codigo} ahora es {$nuevo}.");

        return self::SUCCESS;
    }

    /**
     * Todo lo que impide el cambio, junto: se contesta una vez y no de a uno.
     *
     * @return list<string>
     */
    private function quejas(Proyecto $proyecto, string $codigo, string $nuevo): array
    {
        $quejas = [];

        if (preg_match('/^[A-ZÑ0-9]{2,10}$/u', $nuevo) !== 1) {
            $quejas[] = "«{$nuevo}» no sirve como código: de 2 a 10 letras o números, sin guiones ni espacios. Ejemplo: RLA.";
        }

        if ($nuevo === $codigo) {
            $quejas[] = "el código nuevo es el mismo que el de hoy ({$codigo}).";
        }

        if (Proyecto::query()->where('codigo', $nuevo)->exists()) {
            $quejas[] = "ya hay otro proyecto con código {$nuevo}.";
        }

        $contratos = Venta::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->whereNotNull('numero_contrato')
            ->count();

        if ($contratos > 0) {
            $quejas[] = "ya tiene {$contratos} contrato(s) numerado(s) con {$codigo}: esos papeles quedarían diciendo un código que no existe.";
        }

        $recibos = DB::table('recibos')->whereIn('serie', [$codigo, $nuevo])->count();

        if ($recibos > 0) {
            $quejas[] = "hay {$recibos} recibo(s) impreso(s) en la serie {$codigo} o {$nuevo}: la serie quedaría partida en dos.";
        }

        return $quejas;
    }

    /**
     * El código de cada lote, rearmado con el código nuevo.
     *
     * `lazyById` y no `get()`: el mismo comando tiene que servirle a un
     * desarrollo de 3,000 lotes. Y un `update` por lote de UNA columna, no un
     * `save()`: el `saving` del modelo recalcula también el valor, y este
     * cambio no tiene nada que decir sobre el valor de nadie.
     */
    private function renombrarLosLotes(Proyecto $proyecto, string $nuevo): int
    {
        /** @var array<int, string> $bloques */
        $bloques = [];

        foreach (Bloque::query()->where('proyecto_id', $proyecto->getKey())->get(['id', 'nombre']) as $bloque) {
            $bloques[(int) $bloque->getKey()] = (string) $bloque->getAttribute('nombre');
        }

        $cambiados = 0;

        $lotes = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->select(['id', 'bloque_id', 'numero'])
            ->lazyById(500);

        foreach ($lotes as $lote) {
            $bloque = $bloques[(int) $lote->getAttribute('bloque_id')] ?? '';

            Lote::query()->whereKey($lote->getKey())->update([
                'codigo' => Lote::componerCodigo($nuevo, $bloque, (string) $lote->getAttribute('numero')),
            ]);

            $cambiados++;
        }

        return $cambiados;
    }

    private function textoDe(mixed $valor): string
    {
        return is_string($valor) ? mb_strtoupper(trim($valor), 'UTF-8') : '';
    }
}
