<?php

declare(strict_types=1);

namespace Database\Seeders\Clientes;

use App\Domain\Enums\UnidadDeArea;
use Database\Seeders\PlanoDeclarado;
use Database\Seeders\PlanoDesdeDxfSeeder;

/**
 * EL BAMBÚ — Inmobiliaria Maya. Carga inicial del plano.
 *
 *   php artisan db:seed --class="Database\Seeders\Clientes\ElBambuSeeder"
 *
 * ═══ ES LA MISMA LECTURA DEL 13-AGO-2026, AHORA REPETIBLE ═══
 *
 * El 13-ago este plano entro a mano por la pantalla de importacion y dio,
 * palabra por palabra: «Se importaron 84 lotes. Capa de lotes: LOTES.
 * Area total: 16,438.68. Repartidos: 36 en A, 7 en B, 8 en C, 17 en D,
 * 8 en E, 8 en F.» Este seeder corre el MISMO importador sobre el MISMO
 * archivo y da lo mismo, que es lo que lo hace confiable: no es una
 * transcripcion de aquel resultado, es la misma cuenta otra vez.
 *
 * Lo que aquella carga dejo suelto y aca ya no pasa:
 *
 *  - **El bloque G.** Quedaba vacio, de un plano viejo de 26 lotes que
 *    estaba repartido en A…G. Este seeder reemplaza el trazado entero, asi
 *    que el proyecto nace con seis manzanas y ninguna de sobra.
 *  - **La unidad.** El 13-ago se resolvio poniendo `vara_en_metros = 1`
 *    porque `unidad_area` todavia no existia. Ahora se declara: metros².
 *
 * ⚠️ **El codigo es `REB` y el nombre `RESIDENCIAL EL BAMBU`** porque asi
 * entro el 13-ago y asi esta en pantalla. No se renombra: el codigo es el
 * prefijo de la serie de recibos y de los correlativos de contrato, y un
 * proyecto que ya existe se ACTUALIZA, no se duplica. Cambiarlo seria
 * mover la serie de un desarrollo que ya esta cargado.
 *
 * ═══ SE VENDE EN METROS² ═══
 *
 * Mauricio, 13-ago-2026: «la unidad de medida de todo ese proyecto sera
 * metros no varas, eso me dijeron». El topografo rotulo tambien el area en
 * varas² con una vara de 0.8350 m -no los 0.8359 del sistema-, y ese
 * numero no se usa. Si algun dia se decide cobrar en varas, se recalcula:
 * no hay conversion escondida en ningun lado.
 *
 * ═══ LO QUE DICE EL PLANO IMPRESO ═══
 *
 * 84 lotes en seis manzanas, A a F, y 16,438.69 m² sumando los rotulos.
 * NO existe manzana G: si aparece una, es basura del plano viejo.
 *
 * Y ese 16,438.69 es el numero que queda cargado, no una aproximacion: el
 * area de cada lote sale del rotulo del topografo. Ver
 * OpcionesDeImportacion::$sufijosDeArea y RotuloDxf::areaRotulada().
 *
 * ⚠️ **No se importan calles, y no es un olvido.** El archivo trae
 * `LIMITE`, `ACER`, `CA 4` y `DERECHO DE VIA`, pero ninguna area de
 * calle cerrada que se pueda importar.
 * El argumento `capaDeCalles` no aparece abajo porque su valor es el
 * default y Rector borra el argumento redundante -llevandose el comentario
 * que lo explicaba-. La razon vive aca, en el docblock, donde no se la
 * puede llevar un fixer.
 *
 * ═══ LO QUE LE FALTA ═══
 *
 * El PRECIO —los 84 entran en 0.00, igual que Altamira— y los planes de
 * pago.
 */
final class ElBambuSeeder extends PlanoDesdeDxfSeeder
{
    protected function plano(): PlanoDeclarado
    {
        return new PlanoDeclarado(
            codigo: 'REB',
            nombre: 'RESIDENCIAL EL BAMBU',
            archivo: 'database/data/el-bambu-plano.dxf',
            // Contado del plano impreso. De la A a la F: no hay G.
            lotesPorBloque: [
                'A' => 36, 'B' => 7, 'C' => 8,
                'D' => 17, 'E' => 8, 'F' => 8,
            ],
            // La suma de los 84 rotulos «A=...m2» del plano.
            areaTotal: 16438.69,
            capaDeLotes: 'LOTES',
            // Este dibujante puso los numeros en `NOMENCLATURA`, no en
            // `textos`: la capa es de cada plano y por eso se declara.
            capaDeRotulos: 'NOMENCLATURA',
            unidad: UnidadDeArea::Metros,
            precioPorUnidad: '0',
            datos: [
                'municipio'     => 'SANTA ROSA DE COPAN',
                'departamento'  => 'CP',
                'direccion'     => 'SANTA ROSA DE COPAN, COPAN, HONDURAS C.A.',
                'observaciones' => 'Plano del topógrafo, 12 de agosto de 2026 (AutoCAD 2018). '.
                                   'La geometría de los 84 lotes sale del DXF nativo, y el área de cada lote es '.
                                   'la que el topógrafo rotuló en el plano.',
            ],
        );
    }
}
