<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Monto;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón del recibo: cuánto de ese pago le tocó a esta cuota.
 *
 * Es lo que contesta «¿por qué la cuota 5 aparece a medias?». Un pago de
 * L 100,000.00 sobre cuotas de L 43,750.00 cubre la 3 entera, la 4 entera y
 * L 12,500.00 de la 5: tres renglones, un solo recibo, un solo papel.
 *
 * Sin este detalle, `cuotas.monto_pagado` sería un número sin historia y
 * anular un recibo obligaría a adivinar cuánto devolverle a cada cuota.
 */
#[Fillable([
    'recibo_id',
    'cuota_id',
    'monto',
])]
#[Table(name: 'aplicaciones_de_pago')]
class AplicacionDePago extends Model
{
    /**
     * @return BelongsTo<Recibo, $this>
     */
    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class);
    }

    /**
     * @return BelongsTo<Cuota, $this>
     */
    public function cuota(): BelongsTo
    {
        return $this->belongsTo(Cuota::class);
    }

    public function montoAplicado(): Monto
    {
        $monto = $this->getAttribute('monto');

        return new Monto(is_string($monto) || is_int($monto) ? $monto : '0');
    }
}
