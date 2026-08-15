<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué papel cobra cada desarrollo, y bajo qué autorización del SAR.
 *
 * Lo pidió Mauricio el 13-ago-2026: «cada proyecto debe tener facturación
 * independiente, opción de factura con CAI o solo recibo como el del
 * Corpus; y si son dos proyectos, que haya opción de que ambos compartan
 * el mismo rango de facturación».
 *
 * ═══ POR QUÉ LA FACTURACIÓN NO ES UNA COLUMNA DEL PROYECTO ═══
 *
 * Porque lo que se comparte es ella. Si «con qué CAI factura» fuera un
 * campo de `proyectos`, dos desarrollos que emiten desde la misma oficina
 * tendrían que llevar copiados el mismo RTN, el mismo establecimiento, el
 * mismo rango y el mismo vencimiento — y el día que se renueve el CAI hay
 * que acordarse de cambiar los dos. Acá la facturación existe por su
 * cuenta y el proyecto apunta a una. Compartir es apuntar a la misma;
 * separarse es apuntar a otra. No hay nada que sincronizar.
 *
 * ═══ 🔴 CUÁNDO SE PUEDE COMPARTIR DE VERDAD ═══
 *
 * Esto NO lo decide el sistema: lo decide dónde se EMITE la factura.
 *
 * El SAR autoriza el rango **por punto de emisión** (Acuerdo 481-2017,
 * Art. 59), y el código del establecimiento va ADENTRO del número:
 * `NNN-NNN-NN-NNNNNNNN` es establecimiento, punto de emisión, tipo de
 * documento y correlativo (Art. 10, num. 7). Dos establecimientos tienen
 * códigos distintos, así que sus correlativos no son —no pueden ser— el
 * mismo rango. Y la factura lleva impresa la dirección del establecimiento
 * donde está el punto de emisión (Art. 10, num. 1, lit. d).
 *
 * Lo que define el establecimiento es dónde se emite el papel, NO dónde
 * está el terreno. Dos desarrollos en municipios distintos facturados
 * desde la misma oficina son UN establecimiento y comparten el rango sin
 * problema. Dos oficinas que emiten cada una lo suyo son dos, y cada una
 * necesita la suya.
 *
 * Mauricio confirmó el 13-ago que El Bambú y Altamira emiten cada uno en
 * su localidad: van con una facturación cada uno. La posibilidad de
 * compartir queda igual, porque el modelo la da gratis y el caso va a
 * aparecer.
 *
 * ⚠️ La modalidad «centralizada» —una sola autorización para casa matriz y
 * sucursales— existía en el Acuerdo 189-2014, Art. 40, y ese acuerdo está
 * DEROGADO. Si alguien recuerda que «antes se podía», tiene razón: cambió
 * en 2017.
 *
 * ═══ POR QUÉ LAS AUTORIZACIONES SON UNA TABLA APARTE ═══
 *
 * Porque un punto de emisión tiene MUCHAS a lo largo del tiempo. La
 * autorización dura un año como máximo (Art. 62) y al agotarse el rango se
 * pide otra, con su CAI nueva y su rango nuevo — pero la numeración NO
 * reinicia: sigue de largo hasta 99999999 (Art. 10, num. 7). Modelar «una
 * CAI por facturación» obligaría a pisarla cada renovación y a perder con
 * qué autorización se emitió cada factura vieja, que es exactamente lo que
 * un fiscalizador va a preguntar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturaciones', function (Blueprint $table): void {
            $table->id();

            // Cómo la reconoce la gente: «El Bambú — oficina de Talanga».
            $table->string('nombre', 120);
            /*
             * 🔴 Los valores van ESCRITOS, no leidos de un enum. El enum
             * `ModoDeFacturacion` existio entre el 13 y el 14-ago-2026 y se
             * borro al quedar la facturacion como solo-CAI; una migracion
             * vieja que lo importara reventaria al reconstruir la base
             * desde cero, que es lo que hace Pest en cada corrida.
             *
             * Regla general: una migracion se lee sola, con lo que habia el
             * dia que se escribio. La columna la borra
             * `2026_08_14_070000_la_facturacion_es_solo_de_cai`.
             */
            $table->string('modo', 20)->default('recibo_interno');
            $table->boolean('activa')->default(true);

            /*
             * Los datos que van IMPRESOS en el papel (Art. 10, num. 1).
             * Nullable porque en modo recibo interno no hacen falta: el
             * CHECK de abajo los exige solo cuando se factura con CAI.
             */
            $table->string('rtn', 14)->nullable();
            $table->string('razon_social', 160)->nullable();
            $table->string('nombre_comercial', 160)->nullable();
            $table->text('direccion_casa_matriz')->nullable();
            $table->text('direccion_establecimiento')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('correo', 120)->nullable();

            /*
             * Los tres primeros segmentos del correlativo de 16 dígitos.
             * `char` y no `integer`: los ceros de adelante son parte del
             * número —el establecimiento 001 no es el 1— y guardarlos como
             * entero obliga a rellenar a mano en cada impresión.
             */
            $table->char('codigo_establecimiento', 3)->nullable();
            $table->char('codigo_punto_emision', 3)->nullable();
            $table->char('codigo_documento', 2)->default('01');

            // Quién imprimió el talonario (Art. 10, num. 1, lit. l).
            $table->string('imprenta_nombre', 160)->nullable();
            $table->string('imprenta_rtn', 14)->nullable();
            $table->string('imprenta_certificado', 40)->nullable();

            $table->text('observaciones')->nullable();

            // Las que pide HasAuditFields: quien la cargo y quien la toco.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE facturaciones
                ADD CONSTRAINT facturaciones_modo_valido_chk
                CHECK (modo IN ('factura_cai', 'recibo_interno')),
                ADD CONSTRAINT facturaciones_codigos_del_correlativo_chk
                CHECK (
                    modo <> 'factura_cai'
                    OR (
                        rtn IS NOT NULL
                        AND codigo_establecimiento IS NOT NULL
                        AND codigo_punto_emision IS NOT NULL
                    )
                )
        SQL);

        Schema::create('autorizaciones_de_impresion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facturacion_id')->constrained('facturaciones')->restrictOnDelete();

            /*
             * 🔴 Sin validar la forma. El formato de la CAI NO está
             * publicado por el SAR; ver el docblock de
             * App\Domain\ValueObjects\CAI.
             */
            $table->string('cai', 100);

            $table->unsignedInteger('correlativo_desde');
            $table->unsignedInteger('correlativo_hasta');
            $table->unsignedInteger('proximo_correlativo');

            $table->date('autorizada_el');
            $table->date('fecha_limite_emision');

            $table->text('observaciones')->nullable();

            // Las que pide HasAuditFields: quien la cargo y quien la toco.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['facturacion_id', 'fecha_limite_emision']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE autorizaciones_de_impresion
                ADD CONSTRAINT autorizaciones_rango_coherente_chk
                CHECK (correlativo_hasta >= correlativo_desde AND correlativo_desde >= 1),
                ADD CONSTRAINT autorizaciones_correlativo_dentro_del_rango_chk
                CHECK (proximo_correlativo >= correlativo_desde AND proximo_correlativo <= correlativo_hasta + 1),
                ADD CONSTRAINT autorizaciones_vigencia_coherente_chk
                CHECK (fecha_limite_emision > autorizada_el)
        SQL);

        /*
         * El correlativo va hasta 99999999 y ahi reinicia (Art. 10,
         * num. 7). Ocho digitos, ni uno mas.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE autorizaciones_de_impresion
                ADD CONSTRAINT autorizaciones_ocho_digitos_chk
                CHECK (correlativo_hasta <= 99999999)
        SQL);

        Schema::table('proyectos', function (Blueprint $table): void {
            /*
             * Nullable: los proyectos que ya existen no tienen ninguna
             * cargada, y hasta que alguien la elija siguen funcionando
             * igual que hasta hoy. `restrictOnDelete` como todas las FK de
             * este repo: la base no borra en cascada nada que alguien haya
             * mirado alguna vez.
             */
            $table->foreignId('facturacion_id')
                ->nullable()
                ->after('codigo')
                ->constrained('facturaciones')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('facturacion_id');
        });

        Schema::dropIfExists('autorizaciones_de_impresion');
        Schema::dropIfExists('facturaciones');
    }
};
