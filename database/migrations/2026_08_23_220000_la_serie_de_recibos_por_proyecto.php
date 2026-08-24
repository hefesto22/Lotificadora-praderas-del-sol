<?php

declare(strict_types=1);

use App\Domain\Enums\TipoCorrelativo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada desarrollo numera sus propios recibos.
 *
 * ═══ QUE CAMBIA, Y POR QUE CAMBIA LA R12 ═══
 *
 * Hasta hoy el recibo interno tenía UNA sola serie para toda la lotificadora.
 * La razón era buena y sigue siendo válida DENTRO de un desarrollo: don Elder
 * en la oficina y don Edwin en el campo cobran al mismo tiempo y no pueden
 * sacar el mismo número — eso lo resuelve el `SELECT … FOR UPDATE` de
 * `ConsumoDeCorrelativos`, y no se toca.
 *
 * Lo que la R12 no previó es el segundo desarrollo. Con una sola serie, los
 * recibos de Praderas y los de El Bambú se intercalan: el 000121 es de uno y
 * el 000122 del otro, y nadie puede mirar una serie y cuadrar la caja de un
 * proyecto. Lo decidió Mauricio el 23-ago-2026: **la serie corre por
 * proyecto**, con el código del desarrollo adelante, igual que los contratos.
 *
 *     RPS-00000001   Praderas del Sol
 *     BAM-00000001   El Bambú
 *
 * ═══ 🔴 LOS 257 RECIBOS DE LA CARTERA VIEJA NO SE TOCAN ═══
 *
 * «Así como se subieron, así quedan; solo los que imprimamos después de
 * colocar el número de dónde iniciará» — Mauricio, el mismo día.
 *
 * Por eso `serie` es NULLABLE y no tiene default: **null es la serie vieja**,
 * la que ya existe, y esos recibos se siguen viendo como se ven hoy —`000001`,
 * sin prefijo—. No se renumera nada, no se reimprime nada.
 *
 * El único de `numero` se parte en dos índices parciales, que es la forma que
 * este repo ya usa para las columnas que pueden ir en null (§8.3):
 *
 *   - `WHERE serie IS NULL`     → la serie vieja sigue siendo única entre sí.
 *   - `WHERE serie IS NOT NULL` → cada proyecto es único en lo suyo.
 *
 * Un `UNIQUE (serie, numero)` a secas NO habría servido: en Postgres dos NULL
 * no son iguales, así que la serie vieja habría quedado sin protección y dos
 * recibos históricos podrían repetir número sin que nada avise.
 *
 * ═══ EL CORRELATIVO SE PARTE IGUAL ═══
 *
 * `recibo_interno` pasa a ser POR PROYECTO, y nace `recibo_historico`, global,
 * que solo usa `CarteraHistoricaSeeder`. Así una recarga de la cartera vieja
 * vuelve a producir exactamente los mismos números que hoy, sin tocar la serie
 * con la que el sistema imprime.
 *
 * La fila global que existe se MUEVE a `recibo_historico` con el número donde
 * la dejó la carga. Se mueve **entre el DROP y el ADD de los CHECK**: con los
 * viejos puestos, `recibo_historico` global no pasaría, y con los nuevos,
 * `recibo_interno` global tampoco.
 *
 * ═══ DESDE QUE NUMERO EMPIEZA A IMPRIMIR ═══
 *
 * `proyectos.proximo_recibo` es el campo de la pestaña Facturación. En null,
 * el proyecto numera desde 1. Con un número, la serie se acomoda para que el
 * siguiente recibo sea EXACTAMENTE ese.
 *
 * Va en el proyecto y no en el `.env` a propósito: «pueden haber más proyectos
 * en el futuro y se confundirán».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recibos', function (Blueprint $table): void {
            /*
             * Diez caracteres alcanzan: es el `codigo` del proyecto, que ya
             * está limitado a lo mismo. No lleva default — null es la serie
             * vieja, y un default la borraría.
             */
            $table->string('serie', 10)->nullable()->after('numero');
        });

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->unsignedBigInteger('proximo_recibo')->nullable()->after('codigo');
        });

        /*
         * El único viejo se va ANTES de crear los parciales: mientras esté,
         * dos proyectos no podrían usar el mismo número.
         */
        DB::statement('ALTER TABLE recibos DROP CONSTRAINT IF EXISTS recibos_numero_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recibos_numero_de_la_serie_vieja_uq
                ON recibos (numero)
                WHERE serie IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recibos_numero_por_serie_uq
                ON recibos (serie, numero)
                WHERE serie IS NOT NULL
        SQL);

        // La serie de un recibo, si la tiene, es la de un proyecto de verdad.
        DB::statement(<<<'SQL'
            ALTER TABLE recibos
                ADD CONSTRAINT recibos_serie_no_vacia_chk
                CHECK (serie IS NULL OR btrim(serie) <> '')
        SQL);

        $this->rehacerLosCheckDeCorrelativos();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recibos DROP CONSTRAINT IF EXISTS recibos_serie_no_vacia_chk');
        DB::statement('DROP INDEX IF EXISTS recibos_numero_por_serie_uq');
        DB::statement('DROP INDEX IF EXISTS recibos_numero_de_la_serie_vieja_uq');

        DB::table('correlativos')
            ->whereNull('proyecto_id')
            ->where('tipo', 'recibo_historico')
            ->update(['tipo' => 'recibo_interno', 'updated_at' => now()]);

        DB::table('correlativos')
            ->whereNotNull('proyecto_id')
            ->where('tipo', 'recibo_interno')
            ->delete();

        Schema::table('recibos', function (Blueprint $table): void {
            $table->dropColumn('serie');
        });

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn('proximo_recibo');
        });

        DB::statement('ALTER TABLE recibos ADD CONSTRAINT recibos_numero_unique UNIQUE (numero)');
    }

    /**
     * Los dos CHECK de `correlativos`, con las listas nuevas.
     *
     * 🔴 El orden importa y no es estilo: la fila global de `recibo_interno`
     * se muda MIENTRAS no hay CHECK puesto. Con los viejos, el tipo nuevo
     * rebota; con los nuevos, el viejo global rebota. Cualquier otro orden
     * deja la migración a medias.
     */
    private function rehacerLosCheckDeCorrelativos(): void
    {
        DB::statement('ALTER TABLE correlativos DROP CONSTRAINT IF EXISTS correlativos_tipo_valido_chk');
        DB::statement('ALTER TABLE correlativos DROP CONSTRAINT IF EXISTS correlativos_alcance_segun_tipo_chk');

        DB::table('correlativos')
            ->whereNull('proyecto_id')
            ->where('tipo', 'recibo_interno')
            ->update(['tipo' => TipoCorrelativo::ReciboHistorico->value, 'updated_at' => now()]);

        $tipos = $this->comoLista(TipoCorrelativo::valores());
        $porProyecto = $this->comoLista(TipoCorrelativo::valoresPorProyecto());
        $globales = $this->comoLista(TipoCorrelativo::valoresGlobales());

        DB::statement(<<<SQL
            ALTER TABLE correlativos
                ADD CONSTRAINT correlativos_tipo_valido_chk CHECK (tipo IN ({$tipos}))
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE correlativos
                ADD CONSTRAINT correlativos_alcance_segun_tipo_chk
                CHECK (
                    (tipo IN ({$porProyecto}) AND proyecto_id IS NOT NULL)
                    OR (tipo IN ({$globales}) AND proyecto_id IS NULL)
                )
        SQL);
    }

    /**
     * @param list<string> $valores
     */
    private function comoLista(array $valores): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $valores));
    }
};
