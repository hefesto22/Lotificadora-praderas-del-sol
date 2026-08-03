<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Código legible del lote: RPS-B-012.
 *
 * Con un proyecto y 54 lotes la tabla se recorre con la vista. Con diez
 * proyectos y ~200 lotes cada uno son 2,000 filas, y ahí encontrar "el lote
 * 12 del bloque B de Praderas" a punta de filtros es un ejercicio de
 * paciencia. El código es lo que la gente ya dice por teléfono y lo que va
 * impreso en el contrato y en el recibo: se teclea en la barra de búsqueda
 * y cae el lote, desde cualquier pantalla del panel.
 *
 * NO es un correlativo. Es DERIVADO de (proyecto.codigo, bloque.nombre,
 * numero), así que no necesita tabla de secuencias ni lockForUpdate como
 * pide el §10.3 — se recalcula en cada guardado igual que `valor`, siguiendo
 * el patrón del §8.3.4 para columnas derivadas almacenadas.
 *
 * El número va con relleno a 3 dígitos a propósito: así el ORDEN
 * ALFABÉTICO del código ES el orden correcto (proyecto, bloque, número), y
 * la tabla se ordena por una sola columna indexada en vez de por una
 * expresión. Sin el relleno, el lote 2 aparecería después del 19 — que es
 * exactamente el bug que tenía el listado antes de esta migración.
 *
 * Los sufijos se conservan: el lote "12-A" queda como RPS-B-012-A.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table): void {
            $table->string('codigo', 40)->nullable()->after('numero');
        });

        $this->rellenarCodigos();

        Schema::table('lotes', function (Blueprint $table): void {
            $table->string('codigo', 40)->nullable(false)->change();
        });

        DB::statement('CREATE UNIQUE INDEX lotes_codigo_unico ON lotes (codigo)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lotes_codigo_unico');

        Schema::table('lotes', function (Blueprint $table): void {
            $table->dropColumn('codigo');
        });
    }

    /**
     * El relleno va en SQL y no en PHP a propósito: son 2,000 filas
     * potenciales y hacerlo con Eloquent serían 2,000 UPDATE más sus
     * eventos. Acá es una sola sentencia.
     *
     * substring(numero from '^[0-9]+') devuelve los dígitos del inicio;
     * la segunda saca lo que venga después (el "-A" de "12-A"). Si el
     * número no empieza con dígitos, se deja tal cual.
     */
    private function rellenarCodigos(): void
    {
        DB::statement(<<<'SQL'
            UPDATE lotes AS l
            SET codigo = p.codigo || '-' || b.nombre || '-' ||
                CASE
                    WHEN substring(l.numero from '^[0-9]+') IS NULL
                        THEN l.numero
                    ELSE lpad(substring(l.numero from '^[0-9]+'), 3, '0')
                         || coalesce(substring(l.numero from '^[0-9]+(.*)$'), '')
                END
            FROM proyectos AS p, bloques AS b
            WHERE l.proyecto_id = p.id
              AND l.bloque_id = b.id
        SQL);
    }
};
