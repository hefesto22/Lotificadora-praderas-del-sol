<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El contorno y los rotulos del lote, guardados como ANGULOS y no como pixeles.
 *
 * ═══ 🔴 POR QUE NO ALCANZABA CON DIBUJARLOS EN LA FOTO ═══
 *
 * Se probo, y se probo bien: un editor que deja marcar el contorno mirando la
 * esfera y devuelve el JPG con las lineas ya curvadas. Funciona, y aun asi el
 * resultado se ve peor que lo que se veia editando. No por un error de
 * calculo — se persiguieron tres y se arreglaron los tres— sino por algo que
 * no tiene arreglo por ese camino:
 *
 *   **una linea quemada en la imagen deja de ser una linea.**
 *
 * Pasa a ser pixeles, y despues `Foto360` la reduce de 12000 a 6144, le aplica
 * realce y la comprime con WebP. Cuando el cliente hace zoom no agranda la
 * linea: agranda sus pixeles. Y el rotulo que se acomodo con cuidado queda a
 * merced de esa cadena.
 *
 * ═══ GUARDADAS COMO DATOS, EL VISOR LAS TRAZA ═══
 *
 * Cada punto es un par de angulos sobre la esfera. El visor los proyecta y
 * dibuja el trazo con la resolucion de la pantalla, en cada cuadro:
 *
 *   · nitidas a cualquier zoom, siempre, sin importar cuanto pese la foto
 *   · el rotulo mantiene su posicion, su giro y su tamaño exactos
 *   · reemplazar la foto no borra las marcas
 *   · corregir un numero de lote es editar un texto, no rehacer una foto
 *
 * ═══ POR QUE `jsonb` Y NO TABLAS ═══
 *
 * Nada consulta esto por dentro: se lee entero para dibujar y se escribe
 * entero al editar. Un contorno de cuatro puntas no es una entidad del
 * negocio, es la forma de un dibujo. Tablas darian tres joins para no
 * contestar ninguna pregunta que alguien vaya a hacer.
 *
 * El CHECK exige que sea una LISTA. Sin eso, un `{}` pegado a mano en el panel
 * llega hasta el navegador del cliente y rompe la pagina publica, que es el
 * peor lugar donde enterarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table): void {
            $table->jsonb('foto360_marcas')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE lotes
                ADD CONSTRAINT lotes_foto360_marcas_es_lista_chk
                CHECK (foto360_marcas IS NULL OR jsonb_typeof(foto360_marcas) = 'array')
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lotes DROP CONSTRAINT IF EXISTS lotes_foto360_marcas_es_lista_chk');

        Schema::table('lotes', function (Blueprint $table): void {
            $table->dropColumn('foto360_marcas');
        });
    }
};
