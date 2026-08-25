<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Lo que efectivamente se le entregó a un socio de su parte.
 *
 * ═══ NO ES UN GASTO, Y POR ESO NO ESTA EN `gastos` ═══
 *
 * Un gasto es lo que el desarrollo COSTÓ —la retroexcavadora, el registro, la
 * publicidad— y se resta antes de saber cuánto hay para repartir. Esto sale de
 * esa utilidad ya calculada. Meterlo entre los gastos lo restaría dos veces y
 * el proyecto parecería menos rentable cada vez que un socio retira lo suyo.
 *
 * ═══ EL MES NO ES LA FECHA ═══
 *
 * ⚠️ `fecha` es el día en que se entregó; `mes` es a qué mes se imputa. Se
 * puede entregar el 3 de septiembre lo que corresponde a agosto, y el reporte
 * de agosto tiene que verlo. Guardar uno solo obligaría a elegir entre un
 * cierre que miente o una fecha que no es la real.
 */
#[Fillable([
    'proyecto_id',
    'socio_id',
    'monto',
    'forma_pago',
    'referencia',
    'fecha',
    'mes',
    'observaciones',
])]
#[Table(name: 'entregas_a_socios')]
class EntregaASocio extends Model
{
    use HasAuditFields;
    use LogsActivity;

    /**
     * ⚠️ `monto` NO se castea a decimal: el cast de Laravel pasa por
     * `number_format()`, que recibe float (§8.3.1). Postgres lo devuelve como
     * string y así lo consume bcmath, sin perder medio centavo.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'fecha'      => 'date',
            'mes'        => 'date',
            'forma_pago' => FormaDePago::class,
        ];
    }

    /**
     * Todo lo de esta tabla va a la bitácora, y no es exceso: cada fila mueve
     * dinero de la caja del proyecto al bolsillo de una persona. La única
     * pregunta que importa después —«¿quién anotó esto y cuándo?»— la contesta
     * la bitácora o no la contesta nadie.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['socio_id', 'monto', 'forma_pago', 'referencia', 'fecha', 'mes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Entrega a socio {$evento}");
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class);
    }

    // ─── Lecturas ─────────────────────────────────────────────────────

    public function monto(): Monto
    {
        $valor = $this->getAttribute('monto');

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }
}
