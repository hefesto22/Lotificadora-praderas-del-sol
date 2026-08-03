<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Plano\Dxf\ImportadorDeDxf;
use App\Domain\Plano\Dxf\OpcionesDeImportacion;
use App\Domain\Plano\Dxf\UnidadDxf;
use App\Models\Bloque;
use App\Models\Proyecto;
use Illuminate\Database\Seeder;

/**
 * Proyecto de demostracion armado importando un plano DXF de verdad.
 *
 * NO es dato de produccion: sirve para ver el sistema funcionando con un
 * plano completo antes de que llegue el de Praderas del Sol, y para tener
 * con que probar el visor sin tocar los lotes reales.
 *
 * El plano sale de tests/Fixtures/valle-verde.dxf, que se genera con el
 * script de Python que esta al lado. Son 78 lotes: manzanas rectangulares,
 * una fila de trapecios contra un lindero diagonal y seis lotes en abanico
 * alrededor de una cul-de-sac.
 *
 *   php artisan db:seed --class=PlanoDemoSeeder
 *
 * Correrlo dos veces no duplica nada: si el proyecto ya existe, se sale.
 */
class PlanoDemoSeeder extends Seeder
{
    private const string CODIGO = 'RVV';

    public function run(): void
    {
        if (Proyecto::query()->where('codigo', self::CODIGO)->exists()) {
            $this->command?->warn('El proyecto de demostracion ya existe. No se toca nada.');

            return;
        }

        $ruta = base_path('tests/Fixtures/valle-verde.dxf');

        if (! is_file($ruta)) {
            $this->command?->error("Falta el plano de prueba en {$ruta}.");
            $this->command?->line('Se regenera con: python3 tests/Fixtures/valle-verde.py');

            return;
        }

        $proyecto = Proyecto::query()->create([
            'nombre'        => 'Residencial Valle Verde (demostracion)',
            'codigo'        => self::CODIGO,
            'municipio'     => 'Villanueva',
            'departamento'  => 'CR',
            'activo'        => true,
            'observaciones' => 'Proyecto de demostracion generado a partir de un plano DXF. '.
                               'No corresponde a un desarrollo real.',
        ]);

        $bloque = Bloque::query()->create([
            'proyecto_id' => $proyecto->getKey(),
            'nombre'      => 'A',
            'orden'       => 1,
        ]);

        $resultado = new ImportadorDeDxf()->importar(
            $bloque,
            (string) file_get_contents($ruta),
            new OpcionesDeImportacion(
                capaDeLotes: 'LOTES',
                precioVara: '1250.00',
                capaDeRotulos: 'TEXTOS',
                capaDeCalles: 'CALLES',
                unidad: UnidadDxf::Metros,
                varaEnMetros: (string) config('lotificadora.area.vara_en_metros', '0.8359'),
            )
        );

        $this->command?->info(sprintf(
            'Valle Verde: %d lotes y %d calles, %s varas² en total.',
            $resultado->lotesCreados,
            $resultado->callesCreadas,
            number_format($resultado->areaTotalVaras, 2)
        ));

        foreach ($resultado->advertencias as $advertencia) {
            $this->command?->warn('  '.$advertencia);
        }
    }
}
