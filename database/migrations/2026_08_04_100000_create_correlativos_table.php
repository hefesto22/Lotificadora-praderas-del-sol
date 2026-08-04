<?php

declare(strict_types=1);

use App\Domain\Enums\TipoCorrelativo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los numeros que no se pueden repetir jamas (§8.3.6).
 *
 * Una fila por serie, con el ultimo numero entregado. Se consume con
 * `SELECT … FOR UPDATE` dentro de la transaccion, nunca con
 * `MAX(numero) + 1`: dos receptores cobrando al mismo tiempo desde
 * lugares distintos sacarian el mismo numero, y un recibo repetido es un
 * problema con el cliente enfrente.
 *
 * Decisiones que quedan grabadas aca:
 *
 * 1. NO HAY COLUMNA `anio`. El secuencial del contrato no reinicia cada
 *    anio (decidido el 3-ago-2026, R7). El anio del numero `RPS-2026-0001`
 *    es el anio en que se firmo, no parte de la llave. Si algun dia
 *    reiniciara, esta tabla necesitaria `anio` y el numero de expediente
 *    dejaria de identificar a un cliente por si solo.
 *
 * 2. EL ALCANCE DE CADA SERIE LO IMPONE LA BASE. El contrato corre por
 *    proyecto (`proyecto_id` obligatorio); el recibo interno es una sola
 *    serie para toda la lotificadora (`proyecto_id` nulo, R12). No es una
 *    convencion que alguien pueda romper por descuido: hay un CHECK.
 *
 * 3. LOS UNICOS SON PARCIALES. En Postgres NULL ≠ NULL, asi que un unico
 *    ordinario sobre `(proyecto_id, tipo)` dejaria crear diez series
 *    globales del mismo tipo (§9.D8). Van dos indices: uno para las series
 *    con proyecto y otro para las globales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correlativos', function (Blueprint $table): void {
            $table->id();

            // Nulo = serie global. La FK es restrictOnDelete: borrar un
            // proyecto que ya entrego numeros de contrato reiniciaria la
            // serie, y esos numeros estan impresos en papel.
            $table->foreignId('proyecto_id')->nullable()->constrained('proyectos')->restrictOnDelete();

            $table->string('tipo', 30);
            $table->unsignedBigInteger('ultimo_numero')->default(0);

            $table->timestamps();
        });

        $tipos = self::comoLista(TipoCorrelativo::valores());
        $porProyecto = self::comoLista(TipoCorrelativo::valoresPorProyecto());
        $globales = self::comoLista(TipoCorrelativo::valoresGlobales());

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

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX correlativos_serie_por_proyecto_uq
                ON correlativos (proyecto_id, tipo)
                WHERE proyecto_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX correlativos_serie_global_uq
                ON correlativos (tipo)
                WHERE proyecto_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('correlativos');
    }

    /**
     * @param list<string> $valores
     */
    private static function comoLista(array $valores): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $valores));
    }
};
