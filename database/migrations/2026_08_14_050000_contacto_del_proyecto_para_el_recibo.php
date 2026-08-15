<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teléfonos y correo del desarrollo, para el membrete del recibo interno.
 *
 * Lo enderezó Mauricio el 14-ago-2026: «si es solo recibo interno que se
 * configure desde el proyecto, ahí donde se agrega el logo; la ubicación se
 * saca de la ubicación y solo registra números de teléfono y correo, y así
 * no se mezclan recibos internos y facturación».
 *
 * ═══ POR QUE SOLO DOS COLUMNAS ═══
 *
 * Porque el resto del membrete YA lo tiene el proyecto y no hay que
 * repetirlo:
 *
 *   · el nombre         → `proyectos.nombre`
 *   · el logo           → `proyectos.logo_path`
 *   · la dirección      → `proyectos.direccion`, con su municipio y su
 *                         departamento, que se cargan en la pestaña
 *                         Ubicación
 *
 * Lo unico que faltaba eran los telefonos y el correo. Yo se los habia
 * puesto a la `facturacion`, y eso dejaba la direccion escrita en DOS
 * lugares: el dia que alguien corrigiera uno, el otro quedaba viejo y el
 * recibo saldria con una direccion que ya no es.
 *
 * ⚠️ Esto NO reemplaza los datos del emisor de una FACTURA. Ahi la
 * direccion que va impresa es la del ESTABLECIMIENTO —el lugar desde donde
 * se emite, que no siempre es donde esta el terreno— y esa sigue viviendo
 * en `facturaciones`. Son dos papeles distintos con dos reglas distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            // Sesenta y no veinte: el talonario de Praderas lleva DOS
            // numeros —«9993-0743 / 3369-0764»— y esa linea sola son 21.
            $table->string('telefonos', 60)->nullable()->after('longitud');
            $table->string('correo', 120)->nullable()->after('telefonos');
        });
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn(['telefonos', 'correo']);
        });
    }
};
