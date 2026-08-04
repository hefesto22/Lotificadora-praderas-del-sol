<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\EstadoLote;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Datos de demostracion de Residencial Praderas del Sol.
 *
 * NO entra en DatabaseSeeder a proposito: se corre a mano con
 * `artisan db:seed --class=DemoSeeder`. Si estuviera en el seeder por
 * defecto, `composer setup` poblaria de datos inventados cualquier entorno
 * nuevo, incluido el del dia que se instale en el VPS.
 *
 * Todo es DETERMINISTA: sin faker, sin random. Correrlo dos veces da los
 * mismos numeros, y un `migrate:fresh --seed` seguido de este seeder deja
 * la base exactamente igual que la ultima vez. Eso permite tomarle una
 * captura al panel y que la captura siga siendo cierta manana.
 *
 * Las areas y los precios son STRINGS (§8.3.1). Un float aca reintroduciria
 * por la puerta de atras el error que Monto existe para evitar, y encima en
 * el dato que despues sale en las capturas que ve la contratante.
 *
 * OJO con los lotes en estado `vendido`: quedan inmutables por el §8.2 —
 * modelo y trigger de PostgreSQL. Son a proposito, para poder mostrar que
 * el candado funciona, pero si queres jugar con ellos hay que rehacer la
 * base con migrate:fresh.
 */
class DemoSeeder extends Seeder
{
    private const array PROYECTO = [
        'nombre'       => 'Residencial Praderas del Sol',
        'codigo'       => 'RPS',
        'municipio'    => 'Cucuyagua',
        'departamento' => 'Copán',
        'direccion'    => 'Carretera CA-4, salida a Santa Rosa de Copán',
    ];

    /**
     * El plano declara; el sistema cuenta. `cargar` a proposito NO siempre
     * es igual a `planificados`: el bloque C queda a medias y el D sin
     * cargar, que es como se ve un plano real a mitad de digitacion.
     *
     * `base` y `paso` generan areas variadas sin usar random: el area del
     * lote i es base + i x paso, con bcmath a 4 decimales.
     *
     * Los cuatro campos numericos se declaran `numeric-string`, no `string`:
     * bcadd/bcmul lo exigen y PHPStan nivel 7 lo verifica contra los literales
     * de esta misma constante. Si alguien agrega un bloque con un area mal
     * escrita, el error sale en el analisis y no en la corrida del seeder.
     *
     * @var list<array{nombre: string, area: numeric-string, planificados: int, precio: numeric-string, cargar: int, base: numeric-string, paso: numeric-string}>
     */
    private const array BLOQUES = [
        ['nombre' => 'A', 'area' => '12500.0000', 'planificados' => 24, 'precio' => '2530.00', 'cargar' => 24, 'base' => '480.0000', 'paso' => '13.5750'],
        ['nombre' => 'B', 'area' => '9800.0000',  'planificados' => 18, 'precio' => '2380.00', 'cargar' => 18, 'base' => '412.5000', 'paso' => '9.2400'],
        ['nombre' => 'C', 'area' => '11200.0000', 'planificados' => 20, 'precio' => '2650.00', 'cargar' => 12, 'base' => '505.0000', 'paso' => '17.3325'],
        ['nombre' => 'D', 'area' => '7600.0000',  'planificados' => 16, 'precio' => '2210.00', 'cargar' => 0,  'base' => '390.0000', 'paso' => '8.1500'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DemoSeeder no corre en produccion: son datos inventados y quedarian mezclados con los reales.'
            );
        }

        // Autenticar al admin para que el activity log tenga causer. Sin
        // esto la pantalla de auditoria muestra "Sistema" en todo y no se
        // puede demostrar la trazabilidad, que es justamente el punto.
        $admin = User::query()->orderBy('id')->first();

        if ($admin instanceof User) {
            Auth::login($admin);
        }

        DB::transaction(function (): void {
            $proyecto = $this->crearProyecto();
            $totalLotes = 0;

            foreach (self::BLOQUES as $datos) {
                $bloque = $this->crearBloque($proyecto, $datos);
                $totalLotes += $this->crearLotes($bloque, $datos);
            }

            $this->command?->info('Demo lista: 1 proyecto, '.count(self::BLOQUES)." bloques, {$totalLotes} lotes.");
        });

        Auth::logout();
    }

    private function crearProyecto(): Proyecto
    {
        /** @var Proyecto $proyecto */
        $proyecto = Proyecto::query()->firstOrCreate(
            ['codigo' => self::PROYECTO['codigo']],
            [
                'nombre'        => self::PROYECTO['nombre'],
                'municipio'     => self::PROYECTO['municipio'],
                'departamento'  => self::PROYECTO['departamento'],
                'direccion'     => self::PROYECTO['direccion'],
                'activo'        => true,
                'observaciones' => 'Datos de demostracion — DemoSeeder.',
            ]
        );

        return $proyecto;
    }

    /**
     * @param array{nombre: string, area: numeric-string, planificados: int, precio: numeric-string, cargar: int, base: numeric-string, paso: numeric-string} $datos
     */
    private function crearBloque(Proyecto $proyecto, array $datos): Bloque
    {
        /** @var Bloque $bloque */
        $bloque = Bloque::query()->firstOrCreate(
            ['proyecto_id' => $proyecto->getKey(), 'nombre' => $datos['nombre']],
            [
                'area_total_varas'   => $datos['area'],
                'lotes_planificados' => $datos['planificados'],
                'orden'              => array_search($datos['nombre'], array_column(self::BLOQUES, 'nombre'), true),
            ]
        );

        return $bloque;
    }

    /**
     * @param array{nombre: string, area: numeric-string, planificados: int, precio: numeric-string, cargar: int, base: numeric-string, paso: numeric-string} $datos
     *
     * @return int cantidad de lotes que existen en el bloque despues de correr
     */
    private function crearLotes(Bloque $bloque, array $datos): int
    {
        for ($i = 1; $i <= $datos['cargar']; $i++) {
            $area = bcadd($datos['base'], bcmul((string) $i, $datos['paso'], 4), 4);

            Lote::query()->firstOrCreate(
                ['bloque_id' => $bloque->getKey(), 'numero' => (string) $i],
                [
                    'proyecto_id' => $bloque->getAttribute('proyecto_id'),
                    'area_varas'  => $area,
                    'precio_vara' => $datos['precio'],
                    'estado'      => $this->estadoPara($i)->value,
                ]
            );
        }

        return $datos['cargar'];
    }

    /**
     * Reparto de estados sin random, para que la demo sea reproducible.
     * Da aproximadamente 9% vendidos, 14% apartados y un cancelado por
     * bloque grande — una mezcla que se parece a un proyecto arrancando.
     */
    private function estadoPara(int $numero): EstadoLote
    {
        return match (true) {
            $numero % 11 === 0 => EstadoLote::Vendido,
            $numero % 7 === 0  => EstadoLote::Apartado,
            $numero === 5      => EstadoLote::Cancelado,
            default            => EstadoLote::Disponible,
        };
    }
}
