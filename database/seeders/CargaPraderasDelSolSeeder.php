<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\ValueObjects\Monto;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use Illuminate\Database\Seeder;

/**
 * Deja RESIDENCIAL PRADERAS DEL SOL listo para trabajar, y NADA MÁS.
 *
 * Pedido por Mauricio el 23-ago-2026 para el ambiente de pruebas recién
 * montado: «un seeder que cargue el proyecto de Praderas del Sol, **solo el
 * proyecto, sin clientes ni nada de eso**».
 *
 *     php artisan db:seed --class=CargaPraderasDelSolSeeder
 *
 * ═══ QUÉ CARGA ═══
 *
 *  1. El proyecto, sus 24 bloques y sus 309 lotes con la geometría REAL del
 *     plano del Ing. Menjivar. Eso no se reescribe acá: lo hace
 *     `PlanoRealPraderasSeeder`, que es el dueño del trazado y del archivo
 *     `database/data/praderas-plano.json`. Este seeder lo llama.
 *  2. Los datos del membrete que ese otro no toca —teléfonos y correo—,
 *     porque salen impresos en cada recibo.
 *  3. Los planes de pago, **solo si se le da un precio** (ver abajo).
 *
 * ═══ QUÉ NO CARGA, A PROPÓSITO ═══
 *
 * Ni un cliente, ni una venta, ni un recibo, ni un apartado. El plano queda
 * entero en verde. Para la cartera vieja está `CarteraHistoricaSeeder`, que
 * es otra cosa y se corre aparte.
 *
 * Tampoco el logo: es un archivo del disco `public`, no una fila. Se sube
 * una vez desde Proyecto → Facturación.
 *
 * ═══ 🔴 POR QUÉ NO INVENTA EL PRECIO ═══
 *
 * Misma ley que `PraderasDelSolSeeder`: de ese número sale el valor de cada
 * lote y termina escrito en un contrato. Sin `PRECIO_VARA` el seeder carga
 * el proyecto igual pero **no crea ningún plan de pago**, y avisa — porque
 * sin planes de pago el botón de vender está bloqueado a propósito (la
 * guarda de `Proyecto::tieneConQueVender()`).
 *
 *     PRECIO_VARA=1500 php artisan db:seed --class=CargaPraderasDelSolSeeder
 *
 * Ese número es el precio de LISTA a 12 meses. Los otros plazos salen de la
 * escala de abajo, que es una convención de arranque para poder probar — no
 * la lista real de la contratante. Se corrigen en pantalla en Proyecto →
 * Planes de pago, que además es la forma de probar esa pantalla.
 */
final class CargaPraderasDelSolSeeder extends Seeder
{
    private const string CODIGO = 'RPS';

    /** Salen impresos en el recibo (§ del membrete). */
    private const string TELEFONOS = '33012826/33012827';

    private const string CORREO = 'correo@gmail.com';

    /**
     * Cuánto se aparta del precio de 12 meses cada plazo.
     *
     * Al contado la vara vale MENOS y a 48 meses vale MÁS: el precio de
     * lista depende del plazo, y por eso vender de contado al precio de
     * contado no es un descuento que pida motivo (R4).
     *
     * ⚠️ El precio se multiplica con `Monto`, NO con `bcmul` pelado (§8.3.1).
     * Además de ser la ley del repo —el dinero no anda suelto por ahí— evita
     * el error que `bcmul` tira en PHPStan: exige `numeric-string` y un
     * `string` declarado en un array no lo es.
     *
     * @return list<array{meses: int, factor: string, etiqueta: string}>
     */
    private function escala(): array
    {
        return [
            ['meses' => 0,  'factor' => '0.90', 'etiqueta' => 'Al contado'],
            ['meses' => 12, 'factor' => '1.00', 'etiqueta' => '12 meses'],
            ['meses' => 24, 'factor' => '1.10', 'etiqueta' => '24 meses'],
            ['meses' => 36, 'factor' => '1.20', 'etiqueta' => '36 meses'],
            ['meses' => 48, 'factor' => '1.30', 'etiqueta' => '48 meses'],
        ];
    }

    public function run(): void
    {
        // 1. El proyecto, los bloques y los 309 lotes con la geometría real.
        $this->call(PlanoRealPraderasSeeder::class);

        $proyecto = Proyecto::query()->where('codigo', self::CODIGO)->first();

        if (! $proyecto instanceof Proyecto) {
            $this->command?->error('El plano no dejó el proyecto '.self::CODIGO.'. No sigo.');

            return;
        }

        // 2. El membrete del recibo.
        $proyecto->update([
            'telefonos' => self::TELEFONOS,
            'correo'    => self::CORREO,
        ]);

        // 3. Los planes de pago, si hay con qué calcularlos.
        $precio = (string) (getenv('PRECIO_VARA') ?: '');

        if (! is_numeric($precio) || (float) $precio <= 0) {
            $this->avisarQueFaltaElPrecio();

            return;
        }

        $planes = 0;

        foreach ($this->escala() as $tramo) {
            PlanDePago::query()->updateOrCreate(
                ['proyecto_id' => $proyecto->getKey(), 'meses' => $tramo['meses']],
                [
                    'precio_vara' => new Monto($precio)->multiplicarPor($tramo['factor'])->redondeado(),
                    'etiqueta'    => $tramo['etiqueta'],
                    'activo'      => true,
                ],
            );

            $planes++;
        }

        $this->command?->info("✓ Praderas del Sol lista: 309 lotes y {$planes} planes de pago. Sin clientes ni ventas.");
    }

    /**
     * El proyecto queda cargado pero no se puede vender. Se dice.
     *
     * Callarlo dejaría a alguien mirando un botón gris sin entender por qué
     * —que es exactamente el bug que se arregló el 23-ago—.
     */
    private function avisarQueFaltaElPrecio(): void
    {
        $this->command?->warn('✓ Praderas del Sol cargada: 309 lotes, sin clientes ni ventas.');
        $this->command?->line('');
        $this->command?->line('⚠️  NO se creó ningún plan de pago, así que el botón de vender va a estar');
        $this->command?->line('   bloqueado (apartar sí funciona). No invento el precio de la vara²:');
        $this->command?->line('   de ese número sale el valor de cada lote y termina en un contrato.');
        $this->command?->line('');
        $this->command?->line('   Corré esto con el precio de lista a 12 meses:');
        $this->command?->line('     PRECIO_VARA=1500 php artisan db:seed --class=CargaPraderasDelSolSeeder');
        $this->command?->line('');
        $this->command?->line('   O creálos a mano en Proyectos → Praderas → Planes de pago.');
    }
}
