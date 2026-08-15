<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La factura con CAI deja de ser configuración y empieza a emitir.
 *
 * ═══ QUÉ PASÓ ═══
 *
 * Mauricio firmó una venta en El Bambú —vinculado a la facturación de la
 * inmobiliaria— y el papel salió como RECIBO DE CUOTA N.º 000207 mientras la
 * autorización seguía marcando 1000 facturas disponibles y el próximo número
 * en 00000001. Su mensaje: «no tomó el rango de facturas». Tenía razón: el
 * 13-ago se construyó la configuración y la emisión quedó deliberadamente
 * afuera, esperando que su contador revisara los códigos.
 *
 * ═══ POR QUÉ LAS COLUMNAS VAN EN `recibos` Y NO EN UNA TABLA APARTE ═══
 *
 * Porque una factura no es otra cosa que un recibo: es el mismo hecho —entró
 * dinero contra un contrato— documentado en un papel que además sirve ante el
 * SAR. Partirlo en dos tablas obligaría a preguntar en dos lados «¿cuánto
 * cobramos hoy?», y el día que alguien se olvide de una, el corte de caja
 * miente.
 *
 * Lo que sí cambia es CUÁNTAS series consume. Un recibo interno consume una
 * —la de la lotificadora, R12, sin huecos, para auditar la caja—. Una factura
 * consume esa MISMA y además la del SAR. Las dos quedan impresas: el número
 * interno es el que busca quien cuadra el día, y el de dieciséis dígitos es
 * el que el cliente presenta. No es duplicación: son dos preguntas de dos
 * personas distintas.
 *
 * ═══ POR QUÉ SE COPIAN LA CAI, EL RANGO Y LA FECHA LÍMITE ═══
 *
 * Porque una factura reimpresa tiene que salir EXACTAMENTE como salió la
 * primera vez. Leerlas de la autorización vigente HOY haría que la copia de
 * una factura de enero saliera con la CAI de agosto, y esa factura nunca
 * llevó esa CAI impresa. Mismo criterio del §8.2 con el área y el precio: lo
 * que se imprimió se congela. Es también lo único que contesta «¿con qué
 * autorización se emitió esta?», que es la primera pregunta de una
 * fiscalización.
 *
 * ═══ LOS CHECK ═══
 *
 * `recibos_factura_completa_chk` exige los ocho campos o ninguno. Sin él, un
 * bug futuro podría dejar una factura con número y sin CAI —un papel que no
 * es ni una cosa ni la otra— y eso no se descubre hasta que lo descubre el
 * SAR. Van normales y no NOT VALID a propósito: todas las filas que ya
 * existen son recibos internos con las ocho columnas nuevas en NULL, así que
 * validan sin tocar nada.
 *
 * El único de `numero_factura` es la red debajo del bloqueo de fila de
 * ConsumoDeFacturas. En Postgres los NULL no chocan entre sí, así que los
 * recibos internos conviven sin necesitar un índice parcial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recibos', function (Blueprint $table): void {
            /*
             * Con qué facturación salió, y con cuál de sus autorizaciones.
             * restrictOnDelete y no cascade, igual que el resto de la tabla:
             * una factura entregada no desaparece porque alguien borre la
             * configuración con la que se emitió.
             */
            $table->foreignId('facturacion_id')->nullable()->after('tipo_documento')
                ->constrained('facturaciones')->restrictOnDelete();
            $table->foreignId('autorizacion_id')->nullable()->after('facturacion_id')
                ->constrained('autorizaciones_de_impresion')->restrictOnDelete();

            // 19 caracteres: NNN-NNN-NN-NNNNNNNN. Se guarda armado porque es
            // lo que va impreso, y armarlo de nuevo al reimprimir seria
            // volver a leer los codigos de la facturacion de HOY.
            $table->string('numero_factura', 20)->nullable()->after('autorizacion_id');
            $table->unsignedInteger('correlativo_factura')->nullable()->after('numero_factura');

            $table->string('cai', 100)->nullable()->after('correlativo_factura');
            $table->unsignedInteger('rango_desde')->nullable()->after('cai');
            $table->unsignedInteger('rango_hasta')->nullable()->after('rango_desde');
            $table->date('fecha_limite_emision')->nullable()->after('rango_hasta');

            $table->unique('numero_factura');

            // Dos facturas no pueden llevarse el mismo correlativo de la
            // misma autorizacion. Es redundante con el de arriba mientras los
            // codigos de la facturacion no cambien — y justamente por eso
            // existe: si alguien los edita, el numero armado cambia y el
            // unico de `numero_factura` deja de proteger la serie.
            $table->unique(['autorizacion_id', 'correlativo_factura'], 'recibos_correlativo_por_autorizacion_unico');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE recibos
            ADD CONSTRAINT recibos_tipo_documento_valido_chk
            CHECK (tipo_documento IN ('recibo_interno', 'factura'))
        SQL);

        /*
         * Los ocho campos o ninguno. Los valores estan escritos a mano y no
         * salen del enum a proposito: una migracion aplicada no se vuelve a
         * correr, y no tiene por que romperse el dia que el enum se mueva de
         * namespace. Ya paso en este repo.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE recibos
            ADD CONSTRAINT recibos_factura_completa_chk
            CHECK (
                (
                    tipo_documento = 'factura'
                    AND facturacion_id IS NOT NULL
                    AND autorizacion_id IS NOT NULL
                    AND numero_factura IS NOT NULL
                    AND correlativo_factura IS NOT NULL
                    AND btrim(cai) <> ''
                    AND rango_desde IS NOT NULL
                    AND rango_hasta IS NOT NULL
                    AND fecha_limite_emision IS NOT NULL
                )
                OR (
                    tipo_documento <> 'factura'
                    AND facturacion_id IS NULL
                    AND autorizacion_id IS NULL
                    AND numero_factura IS NULL
                    AND correlativo_factura IS NULL
                    AND cai IS NULL
                    AND rango_desde IS NULL
                    AND rango_hasta IS NULL
                    AND fecha_limite_emision IS NULL
                )
            )
        SQL);

        // El correlativo tiene que caer adentro del rango que el SAR
        // autorizo. Un numero fuera del rango es una factura invalida aunque
        // lleve CAI.
        DB::statement(<<<'SQL'
            ALTER TABLE recibos
            ADD CONSTRAINT recibos_correlativo_dentro_del_rango_chk
            CHECK (
                correlativo_factura IS NULL
                OR (correlativo_factura >= rango_desde AND correlativo_factura <= rango_hasta)
            )
        SQL);
    }

    public function down(): void
    {
        foreach ([
            'recibos_correlativo_dentro_del_rango_chk',
            'recibos_factura_completa_chk',
            'recibos_tipo_documento_valido_chk',
        ] as $constraint) {
            DB::statement("ALTER TABLE recibos DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('recibos', function (Blueprint $table): void {
            $table->dropUnique('recibos_correlativo_por_autorizacion_unico');
            $table->dropUnique(['numero_factura']);
            $table->dropConstrainedForeignId('facturacion_id');
            $table->dropConstrainedForeignId('autorizacion_id');
            $table->dropColumn([
                'numero_factura',
                'correlativo_factura',
                'cai',
                'rango_desde',
                'rango_hasta',
                'fecha_limite_emision',
            ]);
        });
    }
};
