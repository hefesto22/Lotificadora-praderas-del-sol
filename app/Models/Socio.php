<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Database\Factories\SocioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Dueño de una parte del PROYECTO, no de un lote.
 *
 * Un cliente compra un lote y tiene expediente, saldo y estado de cuenta. Un
 * socio no compra nada: puso el terreno o el dinero, y le toca un porcentaje de
 * lo que el proyecto produzca. Son dos cosas distintas y por eso viven en dos
 * tablas distintas.
 *
 * ⚠️ El porcentaje es de ESTE proyecto. La misma persona puede ser socia de dos
 * con partes distintas.
 */
#[Fillable([
    'proyecto_id',
    'nombre',
    'dni',
    'telefono',
    'correo',
    'porcentaje',
    'activo',
    'observaciones',
])]
class Socio extends Model
{
    use HasAuditFields;

    /** @use HasFactory<SocioFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * El porcentaje NO se castea a decimal: el cast de Laravel pasa por
     * `number_format()`, que recibe float (§8.3.1). Postgres ya lo devuelve
     * como string, que es lo que consume bcmath sin perder medio punto.
     *
     * Llega hasta el centésimo —50, 33.33, 66.67— y las partes de un proyecto
     * suman 100 siempre. Lo primero lo garantiza la escala de la columna,
     * numeric(5,2); lo segundo lo exige el formulario, porque un CHECK mira una
     * fila y la suma es de todas.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * El DNI, el teléfono y el correo son PII y no entran a la bitácora, igual
     * que en `Cliente` y `Vendedor` (§13.5).
     *
     * El PORCENTAJE sí, y es el que más importa: es la única columna de esta
     * tabla que decide a dónde va dinero.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'porcentaje', 'activo'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Socio {$evento}");
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    // ─── Su parte ─────────────────────────────────────────────────────

    /**
     * El porcentaje como Monto, para poder operar sin tocar un float.
     *
     * Monto no es «dinero» acá sino un decimal exacto sobre bcmath, que es lo
     * que hace falta: repartir con floats es como se pierden los centavos que
     * después nadie sabe de quién eran.
     */
    public function porcentaje(): Monto
    {
        $valor = $this->getAttribute('porcentaje');

        return new Monto(is_string($valor) || is_int($valor) ? (string) $valor : '0');
    }

    /**
     * Cuánto de un monto le toca a este socio.
     *
     * ⚠️ NO redondea acá. Quien reparte tiene que juntar todas las partes y
     * darle el sobrante de centavos a alguien: el 33.33% de L 1,000.01 no da un
     * número redondo, y redondear cada parte por separado es exactamente como se
     * pierde un centavo que después nadie sabe de quién era.
     */
    public function suParteDe(Monto $total): Monto
    {
        /*
         * `redondeado(2)` y NO `(string)`: el casteo de Monto devuelve el
         * formateado —«L. 33.33»— y eso no es un número. La columna es
         * numeric(5,2), así que dos decimales son exactos y no pierden nada.
         *
         * 🔴 Si algún día la columna admite un decimal más, este 2 se queda
         * corto y le recorta la parte a alguien sin avisar (27-ago-2026).
         */
        return $total->aplicarPorcentaje($this->porcentaje()->redondeado(2));
    }

    // ─── Consultas ────────────────────────────────────────────────────

    /**
     * Los que hoy participan del reparto. Un socio que salió no se borra —lo
     * que ya cobró es historia— pero deja de contar.
     *
     * @param Builder<Socio> $query
     *
     * @return Builder<Socio>
     */
    #[Scope]
    protected function activos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
