<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto 360 del lote: parada en el terreno, mirando alrededor.
 *
 * ═══ QUE GUARDA ESTA COLUMNA ═══
 *
 * La ruta en el disco `public` del archivo YA PROCESADO, no el que subio la
 * administradora. Una camara 360 escupe un equirectangular de 6000×3000 y
 * 8-20 MB; lo que aca queda es de 4096×2048 y medio mega. Ver `Foto360`.
 *
 * ═══ POR QUE UNA COLUMNA Y NO UNA TABLA ═══
 *
 * Una foto por lote, decidido con la contratante. Praderas tiene 301 lotes:
 * cada foto de mas es una salida al terreno mas, y quien mira el plano en el
 * telefono quiere tocar y ver, no elegir entre «desde la calle» y «desde el
 * centro».
 *
 * El dia que hagan falta varias, esta columna se convierte en la primera fila
 * de la tabla nueva y nadie pierde nada.
 *
 * ═══ NULLABLE, Y VA A SER NULL CASI SIEMPRE ═══
 *
 * Fotografiar 301 lotes lleva meses. El plano publico tiene que funcionar
 * igual el dia uno: el boton «Ver 360» aparece solo en los lotes que ya la
 * tienen, y el resto se ve exactamente como hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table): void {
            $table->string('foto360_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table): void {
            $table->dropColumn('foto360_path');
        });
    }
};
