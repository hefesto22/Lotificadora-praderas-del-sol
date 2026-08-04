<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\Numeracion;
use App\Domain\Plano\GeneradorDeLotes;
use App\Domain\Plano\ParametrosDeGeneracion;
use App\Models\Bloque;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Carga la estructura de Residencial Praderas del Sol desde su plano.
 *
 * DE DONDE SALE CADA DATO
 *
 * Los datos generales, del sello del plano: PLANO DE DISTRIBUCION DE
 * LOTES, Corpus, La Union, Copan, Honduras. Propietario Dionel Pinto.
 * Levanto TOP Antonio Mejia; calculo, dibujo y reviso Ing. Gerson
 * Menjivar. Metodo estacion total. Abril de 2026. CICH 9293.
 *
 * Los bloques y la cantidad de lotes de cada uno, leidos del plano. La
 * lectura se cruzo contra una deteccion automatica de las etiquetas
 * "vr2" del PDF: 303 etiquetas detectadas contra 312 lotes contados a
 * mano. Dos metodos independientes con 97 % de coincidencia.
 *
 * LO QUE ESTE SEEDER *NO* SABE
 *
 * La GEOMETRIA es esquematica. El plano viene de Civil 3D 2021, pero el
 * PDF trae los contornos como segmentos sueltos —cero figuras cerradas— y
 * los numeros y las areas dibujados como lineas, no como texto. Reconstruir
 * eso a mano seria adivinar sobre areas, y de las areas sale el dinero.
 *
 * Por eso cada bloque se genera con SU medida tipica leida del plano, y el
 * proyecto queda marcado como esquematico. Cuando llegue el DXF del Ing.
 * Menjivar, se importa encima con ImportadorDeDxf y la geometria pasa a ser
 * la real, con los numeros y las areas de la fuente.
 *
 *   PRECIO_VARA=1500 php artisan db:seed --class=PraderasDelSolSeeder
 */
class PraderasDelSolSeeder extends Seeder
{
    private const string CODIGO = 'RPS';

    /**
     * Bloques leidos del plano.
     *
     * frente x fondo son las medidas rotuladas en el plano para el lote
     * tipico de ese bloque. Los lotes de borde son irregulares y en el
     * esquema salen con la medida tipica: por eso `planificados` guarda la
     * cuenta real del plano, para que la conciliacion del sistema
     * —"el plano dice 22, hay 20 cargados"— tenga contra que comparar.
     *
     * @return list<array{nombre: string, col: int, fila: int, lotes: int, filas: int, frente: string, fondo: string, nota: string}>
     */
    private function bloquesDelPlano(): array
    {
        return [
            // col 2 — la corrida central del plano
            ['nombre' => 'A', 'col' => 2, 'fila' => 0, 'lotes' => 7,  'filas' => 1,  'frente' => '14.00', 'fondo' => '18.00', 'nota' => 'Frente al área verde. Lote 7 irregular (206.63 vr²).'],
            ['nombre' => 'B', 'col' => 2, 'fila' => 1, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'C', 'col' => 2, 'fila' => 2, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'D', 'col' => 2, 'fila' => 3, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'E', 'col' => 2, 'fila' => 4, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'F', 'col' => 2, 'fila' => 5, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'G', 'col' => 2, 'fila' => 6, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'H', 'col' => 2, 'fila' => 7, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'La fila 9-16 es más profunda en el plano: 12.50 × 27.00 = 337.50 vr².'],

            // col 3 — la corrida del oriente
            ['nombre' => 'P', 'col' => 3, 'fila' => 0, 'lotes' => 8,  'filas' => 1,  'frente' => '14.00', 'fondo' => '18.00', 'nota' => 'Frente al área verde, igual que el bloque A.'],
            ['nombre' => 'O', 'col' => 3, 'fila' => 1, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'N', 'col' => 3, 'fila' => 2, 'lotes' => 14, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes 7 y 8 irregulares (428.41 y 345.09 vr²).'],
            ['nombre' => 'M', 'col' => 3, 'fila' => 3, 'lotes' => 12, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes 6 y 7 irregulares (466.77 y 413.68 vr²).'],
            ['nombre' => 'L', 'col' => 3, 'fila' => 4, 'lotes' => 12, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes 6 y 7 irregulares (424.90 y 435.88 vr²).'],
            ['nombre' => 'K', 'col' => 3, 'fila' => 5, 'lotes' => 12, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes 6 y 7 irregulares (428.98 y 401.75 vr²).'],
            ['nombre' => 'J', 'col' => 3, 'fila' => 6, 'lotes' => 12, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes 6 y 7 irregulares (380.72 y 436.97 vr²).'],
            ['nombre' => 'I', 'col' => 3, 'fila' => 7, 'lotes' => 12, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes de borde irregulares.'],

            // col 1 — la corrida del poniente
            ['nombre' => 'R', 'col' => 1, 'fila' => 2, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'S', 'col' => 1, 'fila' => 3, 'lotes' => 16, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => ''],
            ['nombre' => 'T', 'col' => 1, 'fila' => 4, 'lotes' => 13, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes 7 y 8 irregulares (351.76 y 450.47 vr²).'],
            ['nombre' => 'U', 'col' => 1, 'fila' => 5, 'lotes' => 11, 'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes 6 y 7 irregulares (226.81 y 325.51 vr²).'],
            ['nombre' => 'V', 'col' => 1, 'fila' => 6, 'lotes' => 8,  'filas' => 2,  'frente' => '12.50', 'fondo' => '20.00', 'nota' => 'Lotes 3 y 5 irregulares (351.79 y 200.56 vr²).'],
            ['nombre' => 'W', 'col' => 1, 'fila' => 7, 'lotes' => 5,  'filas' => 2,  'frente' => '12.50', 'fondo' => '27.00', 'nota' => 'Bloque chico contra el lindero sur.'],

            // col 0 — el borde del poniente
            ['nombre' => 'Q', 'col' => 0, 'fila' => 1, 'lotes' => 4,  'filas' => 1,  'frente' => '25.00', 'fondo' => '35.00', 'nota' => 'Lotes grandes e irregulares: 702 a 1,200 vr² en el plano.'],
            ['nombre' => 'X', 'col' => 0, 'fila' => 3, 'lotes' => 22, 'filas' => 11, 'frente' => '15.00', 'fondo' => '28.00', 'nota' => 'Todo el bloque es irregular: sigue el lindero diagonal del poniente. Áreas de 247 a 695 vr².'],
        ];
    }

    /** Separacion entre columnas de bloques, en varas. */
    private const float PASO_COLUMNA = 130.0;

    /** Separacion entre filas de bloques, en varas. */
    private const float PASO_FILA = 62.0;

    public function run(): void
    {
        $precio = (string) env('PRECIO_VARA', '');

        if (! is_numeric($precio) || (float) $precio <= 0) {
            $this->command?->error('Falta el precio por vara².');
            $this->command?->line('No lo invento: de ese numero sale el valor de cada lote y termina en un contrato.');
            $this->command?->line('');
            $this->command?->line('  PRECIO_VARA=1500 php artisan db:seed --class=PraderasDelSolSeeder');

            return;
        }

        $proyecto = Proyecto::query()->where('codigo', self::CODIGO)->first();

        if ($proyecto instanceof Proyecto) {
            $this->limpiar($proyecto);
        }

        $proyecto = $this->crearProyecto($proyecto);
        $generador = new GeneradorDeLotes;
        $total = 0;

        foreach ($this->bloquesDelPlano() as $orden => $datos) {
            $filas = $datos['filas'];
            $columnas = (int) ceil($datos['lotes'] / $filas);

            $bloque = Bloque::query()->create([
                'proyecto_id'        => $proyecto->getKey(),
                'nombre'             => $datos['nombre'],
                'orden'              => $orden + 1,
                'lotes_planificados' => $datos['lotes'],
                'observaciones'      => trim('Leído del plano CICH 9293. '.$datos['nota']),
            ]);

            /*
             * Cada bloque va en SU lugar de la grilla. Sin esto los 24
             * bloques se generan todos en el origen y el plano sale como
             * un amontonamiento ilegible.
             */
            $creados = $generador->generar($bloque, new ParametrosDeGeneracion(
                filas: $filas,
                columnas: $columnas,
                frenteVaras: $datos['frente'],
                fondoVaras: $datos['fondo'],
                precioVara: $precio,
                origenX: $datos['col'] * self::PASO_COLUMNA,
                origenY: $datos['fila'] * self::PASO_FILA,
                numeracion: Numeracion::Serpentina,
            ));

            // El generador llena la cuadricula completa; el plano puede
            // tener menos. Los sobrantes se borran para que la cuenta
            // coincida con lo que dice el plano.
            $sobrantes = $creados->slice($datos['lotes']);

            foreach ($sobrantes as $lote) {
                $lote->delete();
            }

            $total += $datos['lotes'];
            $this->command?->line(sprintf(
                '  Bloque %-2s  %2d lotes de %s × %s varas',
                $datos['nombre'],
                $datos['lotes'],
                $datos['frente'],
                $datos['fondo']
            ));
        }

        $proyecto->update(['plano_esquematico' => true]);

        $this->command?->newLine();
        $this->command?->info(sprintf('Praderas del Sol: %d bloques y %d lotes.', count($this->bloquesDelPlano()), $total));
        $this->command?->warn('El plano quedo marcado como ESQUEMATICO: las areas son las tipicas de cada');
        $this->command?->warn('bloque, no las del plano lote por lote. Importar el DXF del Ing. Menjivar');
        $this->command?->warn('para reemplazarlas por las reales.');
    }

    private function limpiar(Proyecto $proyecto): void
    {
        DB::transaction(function () use ($proyecto): void {
            $lotes = Lote::query()->where('proyecto_id', $proyecto->getKey())->pluck('id');

            Compromiso::query()->whereIn('lote_id', $lotes)->delete();
            Lote::query()->where('proyecto_id', $proyecto->getKey())->delete();
            Bloque::query()->where('proyecto_id', $proyecto->getKey())->delete();
        });

        $this->command?->warn('Se borraron los lotes y bloques anteriores de Praderas del Sol.');
    }

    private function crearProyecto(?Proyecto $existente): Proyecto
    {
        $datos = [
            'nombre'        => 'Residencial Praderas del Sol',
            'codigo'        => self::CODIGO,
            'municipio'     => 'Corpus',
            'departamento'  => 'CP',
            'direccion'     => 'Corpus, La Unión, Copán, Honduras C.A.',
            'activo'        => true,
            'observaciones' => 'Plano de distribución de lotes, CICH 9293, abril de 2026. '.
                               'Propietario: Dionel Pinto. Levantó: TOP Antonio Mejía. '.
                               'Calculó, dibujó y revisó: Ing. Gerson Menjívar. '.
                               'Método de medición: estación total. '.
                               'Áreas verdes del plano: 4,668.94 vr² y 2,436.33 vr².',
        ];

        if ($existente instanceof Proyecto) {
            $existente->update($datos);

            return $existente;
        }

        return Proyecto::query()->create($datos);
    }
}
