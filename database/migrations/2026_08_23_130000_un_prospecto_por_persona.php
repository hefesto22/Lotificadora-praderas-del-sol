<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un prospecto es una PERSONA, no una consulta — 23-ago-2026
 *
 * Lo vio Mauricio mirando la lista con tres filas y dos personas: «si la misma
 * persona contacta no hay necesidad de hacer 2, solo que aparezca por cuáles
 * lotes fue que contactó; además sería identificado por el número de
 * teléfono».
 *
 * Tenía razón, y el problema era peor que la repetición: con una fila por
 * consulta, «ya lo llamé» quedaba marcado en UNA de ellas y las otras seguían
 * pidiendo llamada. La administradora terminaba llamando dos veces a la misma
 * persona, o creyendo que ya la llamó cuando no.
 *
 * ═══ QUE CAMBIA ═══
 *
 *   prospectos            la persona: nombre, teléfono, si ya la llamaron
 *   lotes_consultados     por qué lote preguntó, cuándo y cuántas veces
 *
 * ═══ EL TELEFONO ES LA IDENTIDAD, Y LA CALCULA POSTGRES ═══
 *
 * `telefono_clave` es una columna GENERADA con solo los dígitos: así
 * «3301-2827», «3301 2827» y «33012827» son la misma persona, y el índice
 * único lo garantiza. Normalizar en PHP dejaría la puerta abierta a que un
 * import, un seeder o una consulta suelta escriban la fila que duplica.
 *
 * ⚠️ El único es por (proyecto_id, telefono_clave): el mismo número en dos
 * desarrollos son dos prospectos. El prospecto pertenece a un proyecto, y
 * unificarlos haría que quien administra Praderas vea consultas de El Bambú.
 *
 * ═══ LOS QUE YA ESTABAN ═══
 *
 * No se pierde ninguno. Cada prospecto viejo se convierte en su consulta, y
 * los que comparten teléfono se fusionan en el MAS ANTIGUO —que conserva
 * desde cuándo esa persona viene preguntando— tomando el nombre del más
 * reciente y la marca de atendido si alguna la tenía.
 *
 * La fusión va en PHP y no en un SQL de tres pisos: se lee, y el día que algo
 * salga raro se puede seguir con el dedo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_consultados', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('prospecto_id')->constrained('prospectos')->cascadeOnDelete();

            // Igual que antes: se puede preguntar por el desarrollo sin
            // señalar un lote, y un lote borrado no borra la consulta.
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();

            $table->unsignedSmallInteger('plazo_meses')->nullable();
            $table->text('mensaje')->nullable();

            /*
             * Preguntar tres veces por el mismo lote no son tres filas: es
             * una persona insistiendo, y eso se dice con un número. La fila
             * conserva la primera vez —cuándo apareció— y la última, que es
             * la que ordena la lista de a quién llamar.
             */
            $table->unsignedSmallInteger('veces')->default(1);
            $table->timestamp('primera_vez');
            $table->timestamp('ultima_vez');

            $table->timestamps();

            $table->index(['prospecto_id', 'ultima_vez']);
        });

        // ─── Cada prospecto viejo pasa a ser su propia consulta ───────────
        DB::statement(<<<'SQL'
            INSERT INTO lotes_consultados
                (prospecto_id, lote_id, plazo_meses, mensaje, veces, primera_vez, ultima_vez, created_at, updated_at)
            SELECT id, lote_id, plazo_meses, mensaje, 1, created_at, created_at, created_at, created_at
              FROM prospectos
        SQL);

        $this->fusionarLosRepetidos();

        /*
         * 🔴 EL UNICO VA DESPUES DE LA FUSION, Y NO ANTES.
         *
         * Creado antes, la fusión se cae sola: mover las consultas del
         * prospecto 2 al 1 choca contra él en cuanto los dos preguntaron por
         * el mismo lote — que es el caso normal y no el raro. Pasó en la
         * primera corrida, con «Key (prospecto_id, lote_id)=(1, 5024) already
         * exists».
         *
         * Un lote por prospecto, y solo cuando hay lote: con `lote_id` nulo
         * los índices únicos de Postgres no chocan entre sí, así que el
         * parcial es el que de verdad impide el duplicado.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX lotes_consultados_uno_por_lote_uidx
                ON lotes_consultados (prospecto_id, lote_id)
             WHERE lote_id IS NOT NULL
        SQL);

        /*
         * La identidad, ya sin duplicados que la hagan fallar. Va DESPUES de
         * la fusión a propósito: al revés, la migración se caería en la
         * primera base que tenga dos consultas de la misma persona — que es
         * exactamente la base de la que venimos.
         */
        Schema::table('prospectos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lote_id');
            $table->dropColumn(['plazo_meses', 'mensaje']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE prospectos
                ADD COLUMN telefono_clave text
                GENERATED ALWAYS AS (regexp_replace(telefono, '\D', '', 'g')) STORED
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX prospectos_uno_por_telefono_uidx
                ON prospectos (proyecto_id, telefono_clave)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prospectos_uno_por_telefono_uidx');

        Schema::table('prospectos', function (Blueprint $table): void {
            $table->dropColumn('telefono_clave');
        });

        Schema::table('prospectos', function (Blueprint $table): void {
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $table->unsignedSmallInteger('plazo_meses')->nullable();
            $table->text('mensaje')->nullable();
        });

        /*
         * Se devuelve la PRIMERA consulta de cada prospecto. Las demás se van
         * con la tabla: en el modelo viejo no había dónde ponerlas, y eso es
         * justamente lo que esta migración vino a arreglar.
         */
        DB::statement(<<<'SQL'
            UPDATE prospectos p
               SET lote_id     = c.lote_id,
                   plazo_meses = c.plazo_meses,
                   mensaje     = c.mensaje
              FROM (
                    SELECT DISTINCT ON (prospecto_id)
                           prospecto_id, lote_id, plazo_meses, mensaje
                      FROM lotes_consultados
                     ORDER BY prospecto_id, primera_vez, id
                   ) c
             WHERE c.prospecto_id = p.id
        SQL);

        Schema::dropIfExists('lotes_consultados');
    }

    /**
     * Los que comparten teléfono se vuelven uno solo.
     *
     * El que se queda es el de `id` más chico: es el más viejo, y con él se
     * conserva desde cuándo esa persona viene preguntando. Del resto se
     * rescata lo que sí es más fresco —el nombre— y lo que no se puede
     * perder: que alguien ya la haya llamado.
     */
    private function fusionarLosRepetidos(): void
    {
        $grupos = DB::table('prospectos')
            ->selectRaw("proyecto_id, regexp_replace(telefono, '\\D', '', 'g') AS clave")
            ->selectRaw('COUNT(*) AS cuantos')
            ->groupByRaw("proyecto_id, regexp_replace(telefono, '\\D', '', 'g')")
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($grupos as $grupo) {
            $filas = DB::table('prospectos')
                ->where('proyecto_id', $grupo->proyecto_id)
                ->whereRaw("regexp_replace(telefono, '\\D', '', 'g') = ?", [$grupo->clave])
                ->orderBy('id')
                ->get();

            $principal = $filas->first();

            if ($principal === null) {
                continue;
            }

            $sobrantes = $filas->slice(1);

            // El nombre del último: es el que la persona acaba de teclear.
            $ultimo = $filas->last();

            /*
             * Y si CUALQUIERA de las filas estaba atendida, la persona ya fue
             * llamada. Perder eso sería mandar a la administradora a llamar
             * de nuevo a alguien con quien ya habló.
             */
            $atendido = $filas->first(static fn (object $fila): bool => $fila->atendido_el !== null);

            /*
             * ⚠️ `->` y no `?->`. Con un `??` a la derecha el nullsafe es
             * redundante —PHP ya devuelve el default si el objeto fuera
             * null— y PHPStan lo rechaza por eso.
             *
             * Y lo de `atendido` va con un `if` y no encadenado: ahí sí
             * puede no haber ninguna fila atendida, y decidirlo una vez se
             * lee mejor que tres nullsafe seguidos.
             */
            $cambios = ['nombre' => $ultimo->nombre ?? $principal->nombre];

            if ($atendido !== null) {
                $cambios['atendido_el'] = $atendido->atendido_el;
                $cambios['atendido_por'] = $atendido->atendido_por;
                $cambios['nota'] = $atendido->nota ?? $principal->nota;
            }

            DB::table('prospectos')->where('id', $principal->id)->update($cambios);

            $ids = $sobrantes->pluck('id')->all();

            /*
             * Las consultas se mudan ANTES de borrar al dueño viejo: el
             * `cascadeOnDelete` de `prospecto_id` se las llevaría con él.
             */
            DB::table('lotes_consultados')
                ->whereIn('prospecto_id', $ids)
                ->update(['prospecto_id' => $principal->id]);

            /*
             * Y si la mudanza dejó dos filas del mismo lote —la persona
             * preguntó dos veces por el mismo— se juntan en una que dice
             * cuántas.
             */
            $this->juntarLotesRepetidos((int) $principal->id);

            DB::table('prospectos')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Dos consultas del mismo lote y el mismo prospecto son una con `veces`.
     */
    private function juntarLotesRepetidos(int $prospecto): void
    {
        $repetidos = DB::table('lotes_consultados')
            ->where('prospecto_id', $prospecto)
            ->whereNotNull('lote_id')
            ->select('lote_id')
            ->selectRaw('COUNT(*) AS cuantos')
            ->selectRaw('MIN(primera_vez) AS desde')
            ->selectRaw('MAX(ultima_vez) AS hasta')
            ->groupBy('lote_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($repetidos as $repetido) {
            $filas = DB::table('lotes_consultados')
                ->where('prospecto_id', $prospecto)
                ->where('lote_id', $repetido->lote_id)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $sequeda = array_shift($filas);

            DB::table('lotes_consultados')->where('id', $sequeda)->update([
                'veces'       => (int) $repetido->cuantos,
                'primera_vez' => $repetido->desde,
                'ultima_vez'  => $repetido->hasta,
            ]);

            DB::table('lotes_consultados')->whereIn('id', $filas)->delete();
        }
    }
};
