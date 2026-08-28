<?php

declare(strict_types=1);

namespace Database\Seeders\Clientes;

use App\Domain\Enums\UnidadDeArea;
use Database\Seeders\PlanoDeclarado;
use Database\Seeders\PlanoDesdeDxfSeeder;

/**
 * RESIDENCIAL ALTAMIRA — Inmobiliaria Maya. Carga inicial del plano.
 *
 *   php artisan db:seed --class="Database\Seeders\Clientes\AltamiraSeeder"
 *
 * Planta de distribucion de MAYAP CONSTRUCTORA, agosto de 2026, escala
 * 1:200. Santa Rosa de Copan. El archivo que se lee es el DXF nativo que
 * entrego el dibujante, sin conversion de por medio.
 *
 * ═══ SE VENDE EN METROS², NO EN VARAS² ═══
 *
 * Lo dijo Mauricio el 25-ago-2026: «ese es en Mts2». El plano rotula las
 * dos areas -«A=200.00m2» y «286.85v2», con la vara de 0.8350 m del
 * topografo- y la que manda es la primera. Con `unidad_area = metros` la
 * vara del proyecto vale 1.000000 y `lotes.area_varas` guarda metros²;
 * las pantallas escriben «m²» solas. Ver UnidadDeArea.
 *
 * ═══ LO QUE DICE EL PLANO IMPRESO ═══
 *
 * 268 lotes en 16 manzanas, A a P, numeradas de 1 a N sin saltos, y
 * 64,214.72 m² sumando los 268 rotulos de area. Esos numeros se contaron
 * del PDF del dibujante, no de la salida de una importacion: son el
 * control, y un control que sale del mismo archivo que vigila no vigila
 * nada. Ver el docblock de PlanoDeclarado.
 *
 * ⚠️ Ese 64,214.72 es el numero que queda CARGADO, no una aproximacion:
 * el area de cada lote sale del rotulo del topografo y no del contorno,
 * que con un lado curvo mide de menos. Ver
 * OpcionesDeImportacion::$sufijosDeArea y RotuloDxf::areaRotulada().
 *
 * Diez lotes de esquina son los de 314.16 m² -el lado curvo de radio 20 m
 * que se ve en el plano-. En seis de ellos el rotulo del area quedo
 * dibujado por fuera del lindero; el area entra igual, porque sale de la
 * geometria y no del texto.
 *
 * ⚠️ **No se importan calles, y no es un olvido.** El plano dibuja
 * aceras (capa `ACERAS`) y jardineria (`JARDINERIA`), no un area de
 * rodaje cerrada: no hay nada que pasarle al importador.
 * El argumento `capaDeCalles` no aparece abajo porque su valor es el
 * default y Rector borra el argumento redundante -llevandose el comentario
 * que lo explicaba-. La razon vive aca, en el docblock, donde no se la
 * puede llevar un fixer.
 *
 * ═══ LO QUE LE FALTA ═══
 *
 * El PRECIO. Los 268 entran con `precio_vara = 0.00` porque todavia no
 * esta definido, y un lote sin precio no se puede vender: el sistema lo
 * dice con todas las letras en la ficha y en el plano publico. Se fija
 * desde Proyecto → Planes de pago cuando Inmobiliaria Maya lo decida.
 * Faltan tambien los planes de pago del desarrollo.
 */
final class AltamiraSeeder extends PlanoDesdeDxfSeeder
{
    protected function plano(): PlanoDeclarado
    {
        return new PlanoDeclarado(
            codigo: 'RAL',
            nombre: 'RESIDENCIAL ALTAMIRA',
            archivo: 'database/data/altamira-plano.dxf',
            // Contado del plano impreso, manzana por manzana.
            lotesPorBloque: [
                'A' => 35, 'B' => 11, 'C' => 28, 'D' => 27,
                'E' => 17, 'F' => 16, 'G' => 14, 'H' => 14,
                'I' => 7,  'J' => 17, 'K' => 16, 'L' => 25,
                'M' => 13, 'N' => 15, 'O' => 8,  'P' => 5,
            ],
            // La suma de los 268 rotulos «A=...m2» del plano.
            areaTotal: 64214.72,
            capaDeLotes: 'LOTES',
            // Los numeros de lote viven en la capa `textos`, junto con las
            // areas y las medidas de los lados; el importador se queda con
            // el texto que TENGA FORMA de rotulo de lote y este mas cerca
            // del centro. Ver RotuloDxf::FORMA_DE_ROTULO.
            capaDeRotulos: 'textos',
            unidad: UnidadDeArea::Metros,
            precioPorUnidad: '0',
            datos: [
                'municipio'     => 'SANTA ROSA DE COPAN',
                'departamento'  => 'CP',
                'direccion'     => 'SANTA ROSA DE COPAN, COPAN, HONDURAS C.A.',
                'observaciones' => 'Planta de distribución de MAYAP CONSTRUCTORA, agosto de 2026, escala 1:200. '.
                                   'Propietario: INVERSIONES LA ROCA. Dibujó: Arq. Alejandra María Reyes. '.
                                   'Aprobó: Ing. Bayron Huberto Peña. La geometría de los 268 lotes sale del DXF '.
                                   'nativo del dibujante, y el área de cada lote es la que él rotuló en el plano.',
            ],
        );
    }
}
