<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * La prueba de que el cron del servidor está corriendo, en un archivo.
 *
 * ═══ POR QUE EXISTE (10-OCT-2026) ═══
 *
 * Mauricio: «ese del cron se presenta la falla cada que hacemos commit».
 *
 * `olympo:verificar-produccion` leía el latido que deja
 * `health:schedule-check-heartbeat`, y ese latido vive en la CACHÉ. El
 * despliegue corre `optimize:clear`, que vacía la caché: el latido se borraba
 * en cada despliegue y la revisión de justo después decía «el cron nunca
 * latió» — con el cron sano. Había que esperar un minuto y volver a correrla.
 *
 * Un aviso que salta siempre se deja de leer, y el día que el cron se caiga
 * de verdad nadie lo va a creer. Así que el latido se escribe también en un
 * archivo del disco `local`, que ninguna limpieza de caché toca.
 *
 * El latido de la caché se queda: lo sigue usando `/health`. Este es el que
 * sobrevive a un despliegue.
 */
final class LatidoDelCron
{
    private const string ARCHIVO = 'latido-del-cron';

    /**
     * Lo llama la agenda cada minuto (`routes/console.php`).
     */
    public static function latir(): void
    {
        Storage::disk('local')->put(self::ARCHIVO, (string) time());
    }

    /**
     * El momento del último latido, en segundos desde 1970; null si el cron
     * nunca latió en este servidor.
     */
    public static function ultimo(): ?int
    {
        $contenido = Storage::disk('local')->get(self::ARCHIVO);

        if (! is_string($contenido)) {
            return null;
        }

        $contenido = trim($contenido);

        return ctype_digit($contenido) ? (int) $contenido : null;
    }
}
