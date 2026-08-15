<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una facturación es SIEMPRE una factura con CAI. El recibo interno no pasa
 * por acá.
 *
 * Lo enderezó Mauricio el 14-ago-2026: «en facturación no debe estar recibo
 * interno, acá solo facturación con CAI; en el toggle que está dentro del
 * proyecto ahí es donde debe estar, nada más».
 *
 * ═══ POR QUE EL MODO SOBRABA ═══
 *
 * Porque el modo ya lo dice OTRA cosa, y mejor: el proyecto apunta a una
 * facturación o no apunta a ninguna.
 *
 *   · sin facturación  → recibo interno, con el membrete del proyecto
 *   · con facturación  → factura con CAI, con el del establecimiento
 *
 * Tener además una columna `modo` adentro de `facturaciones` abría un
 * estado que no significa nada —una «facturación» que no factura— y dejaba
 * la misma verdad escrita en dos lugares. Es el mismo criterio con el que
 * ya se resolvieron los tres modos del proyecto: se deducen, no se guardan.
 *
 * ⚠️ EL CHECK VA `NOT VALID`, Y NO ES PEREZA. Puede haber filas cargadas
 * como recibo interno —sin RTN ni establecimiento, porque en ese modo no
 * hacían falta— y un CHECK normal fallaría al crearse, tumbando la
 * migración en la máquina de quien ya probó el formulario. `NOT VALID` lo
 * exige de acá en adelante, a lo nuevo y a lo que se edite, y deja lo viejo
 * quieto para que alguien lo complete a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * 🔴 PRIMERO se van los CHECK, y despues la columna. Al reves no:
         * el primer intento hacia un `UPDATE ... SET modo = 'factura_cai'`
         * con el CHECK viejo todavia puesto, y ese CHECK dice «si el modo es
         * factura_cai entonces el RTN y los codigos no pueden ser null».
         * La facturacion que Mauricio ya habia cargado como recibo interno
         * no tenia establecimiento —en ese modo no hacia falta— asi que la
         * migracion se caia con un 23514 antes de llegar a ninguna parte.
         *
         * Y el UPDATE sobraba de entrada: la columna se borra tres lineas
         * mas abajo, asi que normalizar su contenido antes no servia para
         * nada.
         */
        DB::statement('ALTER TABLE facturaciones DROP CONSTRAINT IF EXISTS facturaciones_modo_valido_chk');
        DB::statement('ALTER TABLE facturaciones DROP CONSTRAINT IF EXISTS facturaciones_codigos_del_correlativo_chk');

        Schema::table('facturaciones', function (Blueprint $table): void {
            $table->dropColumn('modo');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE facturaciones
                ADD CONSTRAINT facturaciones_codigos_del_correlativo_chk
                CHECK (
                    rtn IS NOT NULL
                    AND codigo_establecimiento IS NOT NULL
                    AND codigo_punto_emision IS NOT NULL
                ) NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE facturaciones DROP CONSTRAINT IF EXISTS facturaciones_codigos_del_correlativo_chk');

        Schema::table('facturaciones', function (Blueprint $table): void {
            $table->string('modo', 20)->default('factura_cai')->after('nombre');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE facturaciones
                ADD CONSTRAINT facturaciones_modo_valido_chk
                CHECK (modo IN ('factura_cai', 'recibo_interno'))
        SQL);
    }
};
