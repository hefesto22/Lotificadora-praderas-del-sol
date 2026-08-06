<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una salida impresa de un recibo.
 *
 * La numero 1 es el original; de la 2 en adelante el papel lleva la marca
 * COPIA. Quien y cuando los traen `created_by` y `created_at`.
 *
 * ═══ EL NOMBRE DE LA TABLA VA ESCRITO ═══
 *
 * El pluralizador de Laravel es ingles y de `ImpresionDeRecibo` saca
 * `impresion_de_recibos`. Igual que `AplicacionDePago` y `Reprogramacion`, el
 * nombre real se declara y no se adivina.
 */
#[Fillable([
    'recibo_id',
    'numero_de_impresion',
])]
#[Table(name: 'impresiones_de_recibo')]
class ImpresionDeRecibo extends Model
{
    use HasAuditFields;

    /**
     * @return BelongsTo<Recibo, $this>
     */
    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class);
    }

    /**
     * ¿Este papel sale marcado como copia?
     *
     * El original es uno solo. Todo lo demas es una reimpresion, y el cliente
     * tiene derecho a saber cual de los dos papeles tiene en la mano.
     */
    public function esCopia(): bool
    {
        return (int) $this->getAttribute('numero_de_impresion') > 1;
    }
}
