<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoCalle;
use App\Traits\HasAuditFields;
use Database\Factories\CalleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Una vía del plano: calle, avenida, boulevard, callejón o paso peatonal.
 *
 * Se guarda como LÍNEA (`trazo`) más un ANCHO, no como polígono. Al
 * dibujar se renderiza como un trazo grueso, que es exactamente lo que
 * una calle es. Ver la migración para el razonamiento completo.
 *
 * Las calles NO se venden y no tienen estado: son el espacio que queda
 * entre bloques. Por eso viven en su propia tabla y no como un `estado`
 * más de Lote — un "lote calle" contaminaría todos los conteos del
 * negocio ("215 lotes" dejaría de significar 215 lotes vendibles).
 */
#[Fillable([
    'proyecto_id',
    'nombre',
    'tipo',
    'ancho_varas',
    'trazo',
    'orden',
    'observaciones',
])]
class Calle extends Model
{
    use HasAuditFields;

    /** @use HasFactory<CalleFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * `ancho_varas` NO se castea a decimal, por la misma razón que las
     * áreas de Lote y Bloque: el cast de Laravel pasa por number_format(),
     * que recibe float. PDO de PostgreSQL ya devuelve NUMERIC como string,
     * que es lo que consume bcmath sin pérdida (§8.3.1).
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'tipo'  => TipoCalle::class,
            'trazo' => 'array',
            'orden' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // `trazo` queda fuera a propósito: mover una calle en el editor
        // generaría un registro de auditoría con cientos de coordenadas
        // por cada arrastre del mouse, y enterraría los cambios que sí
        // importan. El QUE cambió queda registrado; el dibujo exacto no.
        return LogOptions::defaults()
            ->logOnly(['nombre', 'tipo', 'ancho_varas'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Calle {$evento}");
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    // ─── Geometría ────────────────────────────────────────────────────

    /**
     * Puntos del trazo, ya validados como pares [x, y] numéricos.
     *
     * El CHECK de la base garantiza que sea un array de 2 o más
     * elementos, pero no que cada elemento sea un par: eso en SQL costaría
     * una función y acá cuesta cuatro líneas.
     *
     * @return list<array{float, float}>
     */
    public function puntos(): array
    {
        $trazo = $this->getAttribute('trazo');

        if (! is_array($trazo)) {
            return [];
        }

        $puntos = [];

        foreach ($trazo as $punto) {
            if (! is_array($punto)) {
                continue;
            }

            $valores = array_values($punto);

            if (count($valores) < 2 || ! is_numeric($valores[0]) || ! is_numeric($valores[1])) {
                continue;
            }

            $puntos[] = [(float) $valores[0], (float) $valores[1]];
        }

        return $puntos;
    }

    /**
     * Largo del trazo en varas, sumando segmento por segmento.
     *
     * Float a propósito y sin problema: este número no toca el camino del
     * dinero. Sirve para el plano y para estimar obra, no para cobrar.
     */
    public function largoVaras(): float
    {
        $puntos = $this->puntos();
        $largo = 0.0;

        for ($i = 1, $total = count($puntos); $i < $total; $i++) {
            $largo += hypot(
                $puntos[$i][0] - $puntos[$i - 1][0],
                $puntos[$i][1] - $puntos[$i - 1][1],
            );
        }

        return $largo;
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * @param Builder<Calle> $query
     *
     * @return Builder<Calle>
     */
    #[Scope]
    protected function delProyecto(Builder $query, Proyecto $proyecto): Builder
    {
        return $query->where('proyecto_id', $proyecto->getKey());
    }
}
