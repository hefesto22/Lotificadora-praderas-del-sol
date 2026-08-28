<?php

declare(strict_types=1);

namespace Database\Seeders\Clientes;

use Illuminate\Database\Seeder;

/**
 * INMOBILIARIA MAYA — los dos desarrollos, de una corrida.
 *
 *   php artisan db:seed --class="Database\Seeders\Clientes\InmobiliariaMayaSeeder"
 *
 * Es lo unico que hay que correr en la instalacion de Inmobiliaria Maya
 * despues de `migrate` y de `olympo:sembrar-permisos`. Deja los dos
 * proyectos con sus manzanas, sus lotes y su geometria.
 *
 * Es IDEMPOTENTE: correrlo dos veces deja lo mismo. Y se detiene solo
 * -sin borrar nada- en cuanto alguno de los dos tenga un lote apartado o
 * vendido, porque reemplaza el trazado. Desde ese dia el plano se corrige
 * con `olympo:completar-plano`, que solo inserta.
 *
 * ⚠️ Este es el seeder de UNA instalacion, no del producto. No entra en
 * `DatabaseSeeder`: en el servidor de otra lotificadora no tiene nada que
 * hacer (§4.L0).
 */
final class InmobiliariaMayaSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AltamiraSeeder::class,
            ElBambuSeeder::class,
        ]);
    }
}
