<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos teléfonos en el membrete, porque el talonario de papel lleva dos.
 *
 * Lo pidió Mauricio el 14-ago-2026 mandando la foto del talonario que usa
 * Praderas hoy: «Tels: 9993-0743 / 3369-0764». Veinte caracteres no dan
 * para dos números con su separador —esa línea sola son 21—, así que la
 * columna se ensancha.
 *
 * Y con eso, los datos del emisor dejan de ser solo cosa de la factura:
 * el recibo interno también los necesita, y hasta hoy salían de
 * `config/lotificadora.php`, que es UNO para toda la instalación. Con dos
 * urbanizaciones eso ya no alcanza: cada una tiene su nombre, su teléfono
 * y su dirección impresos en su propio talonario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturaciones', function (Blueprint $table): void {
            $table->string('telefono', 60)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('facturaciones', function (Blueprint $table): void {
            $table->string('telefono', 20)->nullable()->change();
        });
    }
};
