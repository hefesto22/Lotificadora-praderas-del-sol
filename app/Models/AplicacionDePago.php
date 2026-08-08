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
    'monto_mora',
    'monto_interes',
    'monto_capital',
    'mora_condonada',
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

    // ─── El desglose: mora → interes → capital ────────────────────────

    /**
     * Los tres suman `monto`, y lo exige el CHECK
     * `aplicaciones_partes_suman_el_monto_chk`. Sin interes ni mora, todo es
     * capital y estos metodos devuelven lo que devolvian antes de existir.
     */
    public function montoMora(): Monto
    {
        return $this->parte('monto_mora');
    }

    public function montoInteres(): Monto
    {
        return $this->parte('monto_interes');
    }

    public function montoCapital(): Monto
    {
        return $this->parte('monto_capital');
    }

    /**
     * Lo que este renglon perdono de mora. FUERA de `monto`: no es dinero
     * que entro. Existe para que anular el recibo pueda deshacer el perdon
     * de ESTE recibo sin borrar el de otro.
     */
    public function moraCondonada(): Monto
    {
        return $this->parte('mora_condonada');
    }

    /**
     * Lo que este renglon le aplico a la cuota: interes + capital, sin mora.
     * Es lo que movio `cuotas.monto_pagado`.
     */
    public function montoALaCuota(): Monto
    {
        return $this->montoInteres()->sumar($this->montoCapital());
    }

    private function parte(string $columna): Monto
    {
        $valor = $this->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }
}
