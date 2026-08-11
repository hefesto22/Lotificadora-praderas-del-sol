<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El teléfono también puede empezar con 7 y con 8 (11-ago-2026).
 *
 * ═══ COMO SE ENCONTRO ═══
 *
 * Cargando la cartera vieja. El teléfono de una clienta —**8810-7508**— rebotó
 * contra `clientes_telefono_formato_chk`, que solo aceptaba `[239]`. No era un
 * dato malo: era el CHECK.
 *
 * ═══ LOS PREFIJOS DE VERDAD ═══
 *
 * En Honduras los números son de ocho dígitos y el primero dice de quién es:
 *
 *   2  fijos (desde 2010, cuando pasaron de siete a ocho dígitos)
 *   3  Sercom — Claro
 *   7  Hondutel
 *   8  Digicel
 *   9  Celtel — Tigo
 *
 * El sistema aceptaba `[239]`: le faltaban **Digicel y Hondutel**, o sea dos de
 * los cuatro operadores móviles del país. Cualquier cliente con un número Tigo
 * de los que empiezan en 8 —que son muchísimos— no se podía registrar.
 *
 * ⚠️ **Esto habría reventado en producción el primer día**, y de la peor forma:
 * con un cliente enfrente firmando, y un error de base de datos que no explica
 * nada. Se encontró cargando la cartera vieja porque ahí hay teléfonos de
 * verdad; con datos de prueba inventados nunca habría salido.
 *
 * ═══ POR QUE NO SE ABRE A CUALQUIER COSA ═══
 *
 * Se podría poner `^[0-9]{8}$` y olvidarse. Pero un teléfono que empieza en 0,
 * 1, 4, 5 o 6 no existe en Honduras: es un dedazo, y el CHECK está justamente
 * para atraparlo antes de que alguien intente llamar a un cliente y descubra
 * que el número no sirve.
 *
 * El mismo patrón se corrigió en `TelefonoHondurasField` y en
 * `BaseFormRequest`, que son las otras dos puertas por donde entra un teléfono.
 */
return new class extends Migration
{
    /** Fijos, Claro, Hondutel, Digicel y Tigo. */
    private const string PATRON_NUEVO = '^[23789][0-9]{7}$';

    /** Lo que había: sin Hondutel y sin Digicel. */
    private const string PATRON_VIEJO = '^[239][0-9]{7}$';

    public function up(): void
    {
        $this->aplicar(self::PATRON_NUEVO);
    }

    /**
     * ⚠️ Revertir puede fallar, y está bien que falle: si ya hay clientes con
     * número de Digicel o de Hondutel, el CHECK viejo no los admite. Primero
     * habría que decidir qué se hace con esos clientes.
     */
    public function down(): void
    {
        $this->aplicar(self::PATRON_VIEJO);
    }

    private function aplicar(string $patron): void
    {
        DB::statement(<<<SQL
            ALTER TABLE clientes
                DROP CONSTRAINT IF EXISTS clientes_telefono_formato_chk,
                ADD CONSTRAINT clientes_telefono_formato_chk
                CHECK (telefono IS NULL OR telefono ~ '{$patron}')
        SQL);
    }
};
