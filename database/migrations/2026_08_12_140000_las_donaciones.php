<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCompromiso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El tercer tipo de compromiso —DONACION— y el sexto estado de un lote.
 *
 * ═══ QUE LA PIDIO ═══
 *
 * Mauricio, el 12-ago-2026, el mismo dia que se armo la planilla que va a
 * llenar la contratante. Esa planilla ofrece «DONACION» en el desplegable de
 * tipo de operacion, y el sistema solo sabia apartar y vender: al momento de
 * subir el archivo, cada donacion que ella cargara habria rebotado.
 *
 * El cuaderno viejo ya tiene el caso. El exp. 0098 es de una iglesia, y los
 * dieciseis lotes del bloque B estan guardados para los herederos — ese es
 * justamente el camino que esto habilita: se RESERVAN mientras el tramite
 * corre, y se DONAN cuando la escritura se firma.
 *
 * ═══ POR QUE UN ESTADO PROPIO Y NO «VENDIDO» ═══
 *
 * Porque una donacion no genera cartera. Si el lote quedara en `vendido`, el
 * numero de vendidos dejaria de significar «lotes que traen plata», que es
 * para lo que la contratante lo mira, y la unica forma de volver a separarlos
 * seria que cada reporte se acordara de filtrar por el tipo del compromiso.
 * El primero que se olvide da un numero que despues nadie puede explicar.
 *
 * ═══ LOS DOS CHECK SE REESCRIBEN ENTEROS ═══
 *
 * `lotes_estado_valido_chk` y `compromisos_tipo_valido_chk` congelan las dos
 * listas en la base, y para eso existen: que ni un seeder, ni un import, ni un
 * tinker puedan meter un valor que el codigo no conoce. Agregar un caso al
 * enum NO los actualiza solo — hay que soltarlos y volverlos a crear.
 *
 * Lo que NO hace falta tocar, y se verifico uno por uno:
 *
 *   · `compromisos_venta_solo_en_tipo_venta_chk` — una donacion va con
 *     `venta_id` en null, asi que pasa.
 *   · `compromisos_prorroga_solo_en_apartados_chk` — va con `prorrogas` en 0.
 *   · `compromisos_descuento_con_motivo_chk` — se dona al precio de lista,
 *     asi que pactado y lista son el mismo numero.
 *   · `compromisos_valor_es_area_por_precio_chk` — el valor se copia del
 *     lote, que salio de esa misma cuenta.
 *   · `compromisos_plazo_razonable_chk` y `compromisos_prima_no_supera_el_valor_chk`
 *     — las dos columnas van en null.
 */
return new class extends Migration
{
    /**
     * Las listas de antes de hoy, escritas a mano a proposito.
     *
     * Si `down()` las sacara del enum, revertir la migracion volveria a crear
     * el CHECK con el caso nuevo adentro y no revertiria nada.
     *
     * @var list<string>
     */
    private const array ESTADOS_ANTERIORES = ['disponible', 'apartado', 'vendido', 'cancelado', 'reservado'];

    /** @var list<string> */
    private const array TIPOS_ANTERIORES = ['apartado', 'venta'];

    public function up(): void
    {
        $this->reescribir('lotes', 'lotes_estado_valido_chk', 'estado', EstadoLote::valores());
        $this->reescribir('compromisos', 'compromisos_tipo_valido_chk', 'tipo', TipoCompromiso::valores());
    }

    public function down(): void
    {
        /*
         * 🔴 ESTO BORRA DATOS, Y NO HAY FORMA DE QUE NO LO HAGA.
         *
         * Un compromiso de tipo `donacion` no tiene equivalente entre los dos
         * tipos viejos: no es un apartado —no hay seña ni vencimiento— ni una
         * venta —no hay expediente, ni prima, ni cuotas—. Convertirlo en
         * cualquiera de los dos seria inventar un contrato que nadie firmo.
         *
         * Se borran, entonces, y sus lotes vuelven a `disponible`. Es
         * reversible en el unico sentido que importa: solo pueden existir
         * porque esta migracion corrio, y quien la revierte esta diciendo que
         * la funcion no va.
         *
         * No hay nada colgando de ellos: una donacion no emite recibos, ni
         * cuotas, ni reprogramaciones, ni devoluciones. Si alguna vez las
         * tuviera, la FK haria fallar este delete en vez de dejar huerfanos.
         */
        $lotes = DB::table('compromisos')
            ->where('tipo', TipoCompromiso::Donacion->value)
            ->pluck('lote_id')
            ->all();

        DB::table('compromisos')->where('tipo', TipoCompromiso::Donacion->value)->delete();

        if ($lotes !== []) {
            DB::table('lotes')
                ->whereIn('id', $lotes)
                ->update(['estado' => EstadoLote::Disponible->value, 'updated_at' => now()]);
        }

        /*
         * Y los que quedaron en `donado` sin compromiso —cargados por un
         * seeder, o corregidos a mano— tambien, o el CHECK viejo no se puede
         * crear.
         */
        DB::table('lotes')
            ->where('estado', EstadoLote::Donado->value)
            ->update(['estado' => EstadoLote::Disponible->value, 'updated_at' => now()]);

        $this->reescribir('lotes', 'lotes_estado_valido_chk', 'estado', self::ESTADOS_ANTERIORES);
        $this->reescribir('compromisos', 'compromisos_tipo_valido_chk', 'tipo', self::TIPOS_ANTERIORES);
    }

    /**
     * @param list<string> $valores
     */
    private function reescribir(string $tabla, string $constraint, string $columna, array $valores): void
    {
        $lista = implode(', ', array_map(
            static fn (string $valor): string => "'{$valor}'",
            $valores,
        ));

        DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$constraint}");

        DB::statement(
            "ALTER TABLE {$tabla} ADD CONSTRAINT {$constraint} CHECK ({$columna} IN ({$lista}))"
        );
    }
};
