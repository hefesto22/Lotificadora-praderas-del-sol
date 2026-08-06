<?php

declare(strict_types=1);

use App\Domain\Enums\TipoDeDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La carpeta del expediente: los papeles escaneados.
 *
 * ═══ POR QUE HACE FALTA ═══
 *
 * «Para guardar la promesa de venta, debe poder guardarse en el expediente de
 * la venta» (reunión del 6-ago-2026). Hoy ese papel vive en un archivador y
 * en el WhatsApp de alguien: cuando el cliente reclama, hay que buscarlo.
 *
 * ═══ EN DISCO PRIVADO, NO PUBLICO ═══
 *
 * Un documento de identidad y una promesa firmada llevan datos personales. En
 * el disco `public` cualquiera con la URL los lee, tenga o no cuenta — y las
 * URLs se filtran solas: se pegan en un chat, quedan en el historial del
 * navegador, viajan en una captura. Van al disco privado y se descargan por
 * una acción que verifica la política.
 *
 * `restrictOnDelete` no: cascade. La carpeta no sobrevive al expediente — si
 * algún día se borra una venta, sus papeles se van con ella. Lo que no se
 * borra es la venta: se anula o se rescinde (§8.2), y ahí queda todo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tipos = "'".implode("', '", TipoDeDocumento::valores())."'";

        Schema::create('documentos', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();

            $table->string('tipo', 20);
            $table->string('nombre', 120);
            $table->string('archivo', 255);
            $table->unsignedInteger('bytes')->default(0);
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['venta_id', 'tipo']);
        });

        DB::statement(<<<SQL
            ALTER TABLE documentos
                ADD CONSTRAINT documentos_tipo_valido_chk
                CHECK (tipo IN ({$tipos})),

                -- Un documento sin archivo es una fila que miente: dice que el
                -- papel está guardado cuando no hay nada que abrir.
                ADD CONSTRAINT documentos_archivo_no_vacio_chk
                CHECK (btrim(archivo) <> ''),

                ADD CONSTRAINT documentos_nombre_no_vacio_chk
                CHECK (btrim(nombre) <> '')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos');
    }
};
