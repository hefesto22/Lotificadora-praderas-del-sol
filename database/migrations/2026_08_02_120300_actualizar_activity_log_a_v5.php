<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * activitylog v4 -> v5.
 *
 * La v5 saco los cambios de atributos de `properties` y les dio columna
 * propia (`attribute_changes`), y elimino el sistema de batches junto con
 * su columna `batch_uuid`.
 *
 * El §12 dice que una migracion aplicada es inmutable: las tres que publico
 * el paquete en la v4 (054229/054230/054231) se quedan como estan y la
 * correccion viaja aca.
 *
 * El nombre de la tabla va literal. En la v4 salia de
 * config('activitylog.table_name'), clave que la v5 elimino; el modelo
 * Activity de la v5 fija 'activity_log' en $table y esta migracion hace lo
 * mismo para no depender de una clave que el paquete ya no lee.
 */
return new class extends Migration
{
    private const TABLA = 'activity_log';

    public function up(): void
    {
        // PostgreSQL no deja elegir la posicion de una columna nueva: el
        // ->after('causer_id') del stub de Spatie es un modificador de MySQL
        // que aca se ignora. El orden fisico no importa, el modelo lee por
        // nombre.
        Schema::table(self::TABLA, function (Blueprint $table): void {
            $table->json('attribute_changes')->nullable();
        });

        $this->moverCambios('properties', 'attribute_changes');

        Schema::table(self::TABLA, function (Blueprint $table): void {
            $table->dropColumn('batch_uuid');
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLA, function (Blueprint $table): void {
            $table->uuid('batch_uuid')->nullable();
        });

        $this->moverCambios('attribute_changes', 'properties');

        Schema::table(self::TABLA, function (Blueprint $table): void {
            $table->dropColumn('attribute_changes');
        });
    }

    /**
     * Mueve las llaves `attributes` y `old` de una columna json a la otra.
     *
     * Lo que no sean esas dos llaves se queda en la columna de origen: en
     * `properties` puede haber datos que puso withProperties() y que no
     * tienen nada que ver con el diff de atributos.
     *
     * lazyById en vez de get(): la tabla de auditoria crece sin techo y no
     * cabe en memoria de un vistazo. Pagina por `id > ultimo`, asi que las
     * filas que salen del filtro al actualizarlas no corren la ventana.
     */
    private function moverCambios(string $origen, string $destino): void
    {
        foreach (DB::table(self::TABLA)->whereNotNull($origen)->lazyById(500) as $fila) {
            // (array) en vez de $fila->columna: PHPStan nivel 7 no conoce
            // las propiedades de stdClass y pediria un ignore por linea.
            $datos = (array) $fila;

            $crudoOrigen = $datos[$origen] ?? null;

            if (! is_string($crudoOrigen)) {
                continue;
            }

            $contenidoOrigen = json_decode($crudoOrigen, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($contenidoOrigen)) {
                continue;
            }

            $movido = [];

            foreach (['attributes', 'old'] as $llave) {
                if (array_key_exists($llave, $contenidoOrigen)) {
                    $movido[$llave] = $contenidoOrigen[$llave];
                    unset($contenidoOrigen[$llave]);
                }
            }

            if ($movido === []) {
                continue;
            }

            $crudoDestino = $datos[$destino] ?? null;

            $contenidoDestino = is_string($crudoDestino)
                ? json_decode($crudoDestino, true, 512, JSON_THROW_ON_ERROR)
                : [];

            if (! is_array($contenidoDestino)) {
                $contenidoDestino = [];
            }

            DB::table(self::TABLA)
                ->where('id', $datos['id'] ?? null)
                ->update([
                    $destino => json_encode(
                        array_merge($contenidoDestino, $movido),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                    ),
                    $origen => $contenidoOrigen === []
                        ? null
                        : json_encode($contenidoOrigen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
        }
    }
};
