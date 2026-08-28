<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domain\Enums\UnidadDeArea;
use Database\Seeders\PlanoDeclarado;
use Database\Seeders\PlanoDesdeDxfSeeder;

/**
 * EL CASO DE CONTROL de PlanoDesdeDxfSeeder: un plano declarado que el
 * archivo no puede dar.
 *
 * Declara una manzana G de seis lotes que el DXF de EL BAMBÚ no tiene —es
 * la forma exacta que tuvo la manzana I de Praderas del Sol el
 * 22-ago-2026, media manzana que ningun control del importador podia
 * atrapar— y sirve para probar las dos mitades de la promesa del seeder:
 * que se da cuenta, y que no deja NADA cargado cuando se da cuenta.
 *
 * Un control sin caso de control es una linea de codigo que nadie ejecuto
 * nunca. Ver [[los-detectores]].
 */
final class PlanoQueNoCuadraSeeder extends PlanoDesdeDxfSeeder
{
    protected function plano(): PlanoDeclarado
    {
        return new PlanoDeclarado(
            codigo: 'ZZP',
            nombre: 'PLANO QUE NO CUADRA',
            archivo: 'database/data/el-bambu-plano.dxf',
            lotesPorBloque: [
                'A' => 36, 'B' => 7, 'C' => 8,
                'D' => 17, 'E' => 8, 'F' => 8,
                // La manzana que el archivo no trae.
                'G' => 6,
            ],
            areaTotal: 16438.69,
            capaDeRotulos: 'NOMENCLATURA',
            unidad: UnidadDeArea::Metros,
        );
    }
}
