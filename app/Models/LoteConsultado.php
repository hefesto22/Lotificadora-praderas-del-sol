<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Por cuál lote preguntó un prospecto, cuándo y cuántas veces.
 *
 * Nace el 23-ago-2026, cuando el prospecto dejó de ser una consulta y pasó a
 * ser una persona (ver `2026_08_23_130000_un_prospecto_por_persona.php`).
 *
 * ⚠️ Preguntar tres veces por el mismo lote es UNA fila con `veces = 3`, no
 * tres filas. Lo garantiza un índice único parcial en la base, así que ni un
 * import ni una consulta suelta pueden duplicarla. `primera_vez` dice desde
 * cuándo ese lote le interesa y `ultima_vez` es la que ordena la lista de a
 * quién llamar.
 *
 * `lote_id` es nullable a propósito: se puede preguntar por el desarrollo sin
 * señalar un lote, y un lote borrado no se lleva la consulta —el interés de
 * esa persona existió igual—.
 */
#[Fillable([
    'prospecto_id',
    'lote_id',
    'plazo_meses',
    'mensaje',
    'veces',
    'primera_vez',
    'ultima_vez',
])]
#[Table(name: 'lotes_consultados')]
class LoteConsultado extends Model
{
    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'plazo_meses' => 'integer',
            'veces'       => 'integer',
            'primera_vez' => 'datetime',
            'ultima_vez'  => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Prospecto, $this>
     */
    public function prospecto(): BelongsTo
    {
        return $this->belongsTo(Prospecto::class);
    }

    /**
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /**
     * El plazo que miraba, dicho como se lee.
     *
     * Es media conversación: quien miraba 48 meses no quiere lo mismo que
     * quien miraba contado, y saberlo antes de marcar el teléfono cambia cómo
     * arranca la llamada.
     */
    public function plazoEnPalabras(): string
    {
        $meses = $this->getAttribute('plazo_meses');

        if (! is_int($meses)) {
            return 'No indicó';
        }

        return $meses === 0 ? 'Contado' : $meses.' meses';
    }
}
