<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\ResultadoDeGestion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Una llamada de cobro: a qué expediente, quién llamó y qué contestaron.
 *
 * Es un HISTORIAL. No se edita ni se borra: si la llamada salió distinta a
 * lo anotado, se registra otra —así queda que hubo dos— y la última manda.
 * Ver la migración `2026_08_23_120000_la_gestion_de_cobro.php`.
 *
 * ⚠️ `vuelve_el` NO está en `$fillable`: la calcula Postgres
 * (`COALESCE(promesa_el, contactado_el + 1)`) y mandársela en un `create()`
 * es un error de la base, no un valor que se pisa en silencio.
 */
#[Fillable([
    'venta_id',
    'user_id',
    'resultado',
    'contactado_el',
    'promesa_el',
    'nota',
])]
#[Table(name: 'gestiones_de_cobro')]
class GestionDeCobro extends Model
{
    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'resultado'     => ResultadoDeGestion::class,
            'contactado_el' => 'date',
            'promesa_el'    => 'date',
            'vuelve_el'     => 'date',
        ];
    }

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * Quién hizo la llamada. Se llama `usuario` y no `user` porque en la
     * pantalla se lee «lo llamó Rosa Elena», no «user».
     *
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
