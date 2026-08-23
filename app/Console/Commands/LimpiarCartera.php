<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoLote;
use App\Models\Proyecto;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deja la cartera de un proyecto en cero, como el día uno.
 *
 *   php artisan olympo:limpiar-cartera RPS
 *   php artisan olympo:limpiar-cartera RPS --clientes --forzar
 *
 * ═══ PARA QUE EXISTE ═══
 *
 * Toda instalación pasa por el mismo momento: se prueba el sistema con ventas
 * de mentira —para ver el plano, para probar un cobro, para enseñárselo al
 * cliente— y llega el día de cargar la cartera de verdad. Esas pruebas hay que
 * barrerlas, y borrarlas a mano desde el panel son veinte clics y el riesgo de
 * dejar una a medias que después nadie entiende.
 *
 * En Praderas pasó exactamente eso: quedaron cuatro lotes vendidos y uno
 * apartado en el bloque C, todos de prueba, y la cartera real son otros lotes.
 *
 * ⚠️ **Esto NO es para producción con datos buenos.** Borra ventas, contratos,
 * recibos y cuotas sin posibilidad de deshacer. Por eso pregunta antes y por
 * eso muestra qué se lleva puesto.
 *
 * ═══ QUE **NO** BORRA ═══
 *
 * - **Los lotes, bloques y calles.** El plano se queda: es lo que costó
 *   importar. Los lotes solo vuelven a `disponible`.
 * - **Los planes de pago** del proyecto, salvo que se pida `--planes`. Son el
 *   precio de lista, no un movimiento.
 *
 *   💡 En Praderas conviene borrarlos: **cada lote se vende a su precio**, y un
 *   plan activo hace que el sistema mida cada venta contra un precio de lista
 *   único y pida motivo de descuento por algo que no es un descuento. Sin plan,
 *   `ListaDePrecios` toma el precio propio de cada lote, que es lo correcto.
 * - **Los clientes**, salvo que se pida `--clientes`. Un cliente de prueba
 *   ensucia el padrón, pero uno real que además compró de prueba no debería
 *   desaparecer. Se decide a mano.
 * - **Los gastos.** No son cartera: es lo que el desarrollo costó, y eso pasó
 *   de verdad aunque las ventas fueran de mentira.
 *
 * ═══ EL ORDEN IMPORTA ═══
 *
 * Se borra de la hoja hacia la raíz —impresiones, aplicaciones, recibos,
 * cuotas, ventas, compromisos— porque las llaves foráneas son
 * `restrictOnDelete` a propósito: la base se niega a dejar huérfano un recibo,
 * y hace bien.
 *
 * Y los correlativos vuelven a cero, para que el primer contrato de verdad sea
 * el 0001 y no el 0007 con seis números quemados en pruebas.
 */
#[Description('Borra ventas, apartados, recibos y cuotas de un proyecto, y libera sus lotes')]
#[Signature('olympo:limpiar-cartera
                            {codigo : Código del proyecto, por ejemplo RPS}
                            {--clientes : Borrar también los clientes que quedaron sin ninguna venta}
                            {--planes : Borrar también los planes de pago (el precio de lista por plazo)}
                            {--forzar : No preguntar}')]
final class LimpiarCartera extends Command
{
    public function handle(): int
    {
        $codigo = mb_strtoupper((string) $this->argument('codigo'));

        $proyecto = Proyecto::query()->where('codigo', $codigo)->first();

        if (! $proyecto instanceof Proyecto) {
            $this->error("No existe ningún proyecto con código {$codigo}.");

            return self::FAILURE;
        }

        $id = (int) $proyecto->getKey();
        $conteos = $this->inventario($id);

        if (array_sum($conteos) === 0) {
            $this->info("La cartera de {$codigo} ya está en cero. No hay nada que borrar.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("Se va a BORRAR de {$codigo}, sin poder deshacerlo:");
        $this->newLine();

        foreach ($conteos as $que => $cuantos) {
            if ($cuantos > 0) {
                $this->line(sprintf('   %-30s %s', $que, $cuantos));
            }
        }

        $this->newLine();
        $this->line('   El plano, los bloques, los lotes y los planes de pago NO se tocan.');
        $this->line('   Los lotes vuelven a «disponible» y los correlativos a cero.');
        $this->newLine();

        if ($this->option('forzar') !== true
            && ! $this->confirm("¿Seguro que querés vaciar la cartera de {$codigo}?", false)) {
            $this->line('No se borró nada.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($id): void {
            $this->borrar($id);
        });

        $this->newLine();
        $this->info("✓ La cartera de {$codigo} quedó en cero.");

        if ($this->option('clientes') === true) {
            $huerfanos = $this->borrarClientesSinVentas();
            $this->info("✓ Se borraron {$huerfanos} clientes que quedaron sin ninguna venta.");
        }

        if ($this->option('planes') === true) {
            $planes = DB::table('planes_de_pago')->where('proyecto_id', $id)->delete();
            $this->info("✓ Se borraron {$planes} planes de pago. Cada lote queda con su propio precio.");
        }

        return self::SUCCESS;
    }

    /**
     * Qué hay hoy, para poder decirlo antes de borrarlo.
     *
     * @return array<string, int>
     */
    private function inventario(int $proyecto): array
    {
        $ventas = DB::table('ventas')->where('proyecto_id', $proyecto)->pluck('id');

        return [
            'Ventas y contratos'         => $ventas->count(),
            'Apartados y ventas de lote' => DB::table('compromisos')->whereIn('lote_id', $this->lotes($proyecto))->count(),
            'Recibos'                    => DB::table('recibos')
                ->whereIn('venta_id', $ventas)
                ->orWhereIn('compromiso_id', DB::table('compromisos')->whereIn('lote_id', $this->lotes($proyecto))->pluck('id'))
                ->count(),
            'Cuotas'         => DB::table('cuotas')->whereIn('venta_id', $ventas)->count(),
            'Lotes ocupados' => DB::table('lotes')
                ->where('proyecto_id', $proyecto)
                ->where('estado', '!=', EstadoLote::Disponible->value)
                ->count(),
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function lotes(int $proyecto): Collection
    {
        /** @var Collection<int, int> $ids */
        $ids = DB::table('lotes')->where('proyecto_id', $proyecto)->pluck('id');

        return $ids;
    }

    /**
     * De la hoja hacia la raíz: las foráneas son `restrictOnDelete` y la base
     * se niega —con razón— a dejar un recibo sin su venta.
     */
    private function borrar(int $proyecto): void
    {
        $lotes = $this->lotes($proyecto);
        $ventas = DB::table('ventas')->where('proyecto_id', $proyecto)->pluck('id');
        $compromisos = DB::table('compromisos')->whereIn('lote_id', $lotes)->pluck('id');

        /*
         * 🔴 UN RECIBO NO SIEMPRE CUELGA DE UNA VENTA.
         *
         * `recibos` tiene `venta_id` Y `compromiso_id`, las dos nullable, con
         * un CHECK que exige al menos una. **El recibo de la seña de un
         * apartado no tiene venta**: cuelga del compromiso, porque cuando ese
         * dinero entró todavía no había contrato.
         *
         * Buscarlos solo por `venta_id` deja esos afuera, y como la foránea es
         * `restrictOnDelete`, el borrado de compromisos rebota con un 23001.
         * Pasó la primera vez que corrió este comando.
         */
        $recibos = DB::table('recibos')
            ->whereIn('venta_id', $ventas)
            ->orWhereIn('compromiso_id', $compromisos)
            ->pluck('id');

        DB::table('impresiones_de_recibo')->whereIn('recibo_id', $recibos)->delete();
        DB::table('aplicaciones_de_pago')->whereIn('recibo_id', $recibos)->delete();
        DB::table('reprogramaciones')->whereIn('venta_id', $ventas)->delete();

        DB::table('devoluciones')->whereIn('compromiso_id', $compromisos)->delete();
        DB::table('devoluciones')->whereIn('venta_id', $ventas)->delete();

        DB::table('documentos')->whereIn('venta_id', $ventas)->delete();
        DB::table('recibos')->whereIn('id', $recibos)->delete();
        DB::table('cuotas')->whereIn('venta_id', $ventas)->delete();
        DB::table('venta_cliente')->whereIn('venta_id', $ventas)->delete();
        DB::table('compromisos')->whereIn('id', $compromisos)->delete();
        DB::table('ventas')->whereIn('id', $ventas)->delete();

        DB::table('lotes')
            ->where('proyecto_id', $proyecto)
            ->update(['estado' => EstadoLote::Disponible->value, 'updated_at' => now()]);

        /*
         * El correlativo del PROYECTO vuelve a cero: los contratos son suyos y
         * se fueron todos con esta limpieza.
         */
        DB::table('correlativos')->where('proyecto_id', $proyecto)->update(['ultimo_numero' => 0, 'updated_at' => now()]);

        $this->acomodarLasSeriesGlobales();
    }

    /**
     * Las series GLOBALES quedan donde manda lo que SOBREVIVIÓ, no en cero.
     *
     * ═══ 🔴 PONERLAS EN CERO ROMPE LA BASE ═══
     *
     * Estaban en cero, y la razón escrita era buena: que el primer documento
     * de verdad no salga con el número que dejaron las pruebas. Pero recibos,
     * devoluciones y gastos son **de toda la lotificadora, no de un proyecto**
     * (R12: una sola numeración). Limpiar RPS no se lleva los recibos de los
     * demás proyectos — y con la serie en cero, la carga siguiente vuelve a
     * emitir 1, 2, 3… hasta chocar contra uno que ya existe:
     *
     *     duplicate key value violates unique constraint "recibos_numero_unique"
     *     Key (numero)=(207) already exists.
     *
     * Pasó el 23-ago-2026, con ochenta y seis expedientes ya cargados.
     *
     * La regla que faltaba, y que vale para cualquier correlativo:
     * **una serie nunca puede quedar por detrás de las filas que existen.**
     * Se la deja en el máximo que quedó vivo — o en cero si no quedó ninguno,
     * que es la instalación nueva y lo que el comentario viejo quería lograr.
     */
    private function acomodarLasSeriesGlobales(): void
    {
        $series = [
            'recibo_interno' => 'recibos',
            'devolucion'     => 'devoluciones',
            'gasto'          => 'gastos',
        ];

        foreach ($series as $tipo => $tabla) {
            DB::table('correlativos')
                ->whereNull('proyecto_id')
                ->where('tipo', $tipo)
                ->update([
                    'ultimo_numero' => (int) DB::table($tabla)->max('numero'),
                    'updated_at'    => now(),
                ]);
        }
    }

    /**
     * Clientes que quedaron sin una sola venta. Se borra en duro y no con
     * SoftDeletes: un cliente de prueba archivado sigue apareciendo al buscar.
     */
    private function borrarClientesSinVentas(): int
    {
        $conVentas = DB::table('venta_cliente')->distinct()->pluck('cliente_id');

        return DB::table('clientes')->whereNotIn('id', $conVentas)->delete();
    }
}
