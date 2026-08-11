<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Archivos\GuardadoDeArchivos;
use App\Domain\Enums\TipoDeDocumento;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Un papel del expediente: la promesa de venta, el DNI, un comprobante.
 *
 * El archivo vive en el disco PRIVADO. No se sirve por URL: se descarga con
 * una acción que pasa por la política, porque una promesa firmada y una copia
 * de identidad llevan datos personales y las URLs se filtran solas.
 */
#[Fillable([
    'venta_id',
    'tipo',
    'nombre',
    'archivo',
    'bytes',
    'observaciones',
])]
class Documento extends Model
{
    use HasAuditFields;
    use LogsActivity;

    /**
     * El disco donde viven los papeles. Privado a propósito.
     */
    public const string DISCO = 'local';

    /**
     * El peso lo dice el DISCO, no el navegador (11-ago-2026).
     *
     * Desde que las imágenes se guardan convertidas a WebP, el tamaño que
     * reportaba el archivo subido —el del original— dejó de ser cierto: sobra
     * por seis. Se pregunta al disco después de guardar, y así vale también al
     * editar y al importar, no solo en el formulario.
     *
     * Si el archivo no está, NO se toca lo que venga: `pesoEnDisco()` devuelve
     * `null` y este hook se hace a un lado. Ver `GuardadoDeArchivos`.
     */
    #[Override]
    protected static function booted(): void
    {
        static::saving(function (self $documento): void {
            if (! $documento->isDirty('archivo')) {
                return;
            }

            $peso = GuardadoDeArchivos::pesoEnDisco(self::DISCO, $documento->getAttribute('archivo'));

            if ($peso === null) {
                return;
            }

            $documento->setAttribute('bytes', $peso);
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return ['tipo' => TipoDeDocumento::class];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'nombre', 'archivo'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Documento {$evento}");
    }

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * El nombre con el que se descarga: se lee en la carpeta de Descargas.
     */
    public function nombreDeDescarga(): string
    {
        $archivo = (string) $this->getAttribute('archivo');
        $extension = pathinfo($archivo, PATHINFO_EXTENSION);

        $base = str((string) $this->getAttribute('nombre'))->slug()->toString();

        return $extension === '' ? $base : $base.'.'.$extension;
    }

    /**
     * El tamaño, como se lee.
     */
    public function peso(): string
    {
        $bytes = (int) $this->getAttribute('bytes');

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
