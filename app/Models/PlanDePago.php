<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\ModalidadDeMora;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CondicionesDeMora;
use App\Domain\Ventas\TasaDeInteres;
use App\Traits\HasAuditFields;
use Database\Factories\PlanDePagoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Que le cuesta al cliente elegir este plazo, en este proyecto.
 *
 * ═══ SON DOS PALANCAS DISTINTAS ═══
 *
 * `precio_vara` es el precio de LISTA: a 48 meses la vara² no vale lo mismo
 * que de contado. No es interés —el saldo no devenga— y elegido el plazo
 * queda fijo.
 *
 * `tasa_interes_anual` sí es interés: parte cada cuota en capital e interés y
 * obliga a mostrarlos separados. Desde el 8-ago-2026 es configurable por plan
 * (§8.5) y **nace en cero**: Praderas del Sol no lo usa (R1) y ninguna
 * lotificadora lo activa por accidente.
 *
 * `meses = 0` es contado, igual que en `ventas.plazo_meses`. Un plan de
 * contado no puede llevar interés —no hay saldo que devengue— y lo impide el
 * CHECK `planes_de_pago_contado_sin_interes_chk`.
 *
 * ═══ ESTOS NUMEROS SE COPIAN, NO SE REFERENCIAN ═══
 *
 * Al firmar, `RegistroDeVentas` los congela en el `compromiso`. Subir mañana
 * la tasa del proyecto NO puede reescribir contratos ya firmados, igual que
 * subir el precio de lista no cambia una venta cerrada (§8.2).
 *
 * Se audita con activitylog a propósito: es el número del que cuelga cada
 * cotización, y cuando alguien pregunte «¿desde cuándo vale esto?» la
 * respuesta tiene que estar en el sistema y no en la memoria de nadie.
 */
#[Fillable([
    'proyecto_id',
    'meses',
    'precio_vara',
    'activo',
    'etiqueta',
    'tasa_interes_anual',
    'mora_modalidad',
    'mora_monto',
    'mora_porcentaje',
    'mora_dias_gracia',
])]
#[Table(name: 'planes_de_pago')]
class PlanDePago extends Model
{
    use HasAuditFields;

    /** @use HasFactory<PlanDePagoFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'tasa_interes_anual' => '0.000',
        'mora_modalidad'     => ModalidadDeMora::Ninguna->value,
        'mora_monto'         => '0.00',
        'mora_porcentaje'    => '0.000',
        'mora_dias_gracia'   => 0,
    ];

    /**
     * `precio_vara`, `tasa_interes_anual` y los de mora NO se castean a
     * decimal: el cast de Laravel pasa por number_format(), que recibe float
     * y reintroduce el error que Monto existe para evitar (§8.3.1). PDO de
     * Postgres ya lo devuelve string.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'meses'            => 'integer',
            'activo'           => 'boolean',
            'mora_modalidad'   => ModalidadDeMora::class,
            'mora_dias_gracia' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'meses',
                'precio_vara',
                'activo',
                'etiqueta',
                'tasa_interes_anual',
                'mora_modalidad',
                'mora_monto',
                'mora_porcentaje',
                'mora_dias_gracia',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Plan de pago {$evento}");
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @param Builder<PlanDePago> $query
     *
     * @return Builder<PlanDePago>
     */
    #[Scope]
    protected function activos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function montoPrecioVara(): Monto
    {
        $valor = $this->getAttribute('precio_vara');

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    /**
     * La tasa que se va a congelar en el compromiso al firmar.
     */
    public function tasaDeInteres(): TasaDeInteres
    {
        return TasaDeInteres::deBase($this->getAttribute('tasa_interes_anual'));
    }

    /**
     * Las condiciones de mora que se van a congelar en el compromiso.
     */
    public function condicionesDeMora(): CondicionesDeMora
    {
        return CondicionesDeMora::deBase(
            $this->getAttribute('mora_modalidad'),
            $this->getAttribute('mora_monto'),
            $this->getAttribute('mora_porcentaje'),
            $this->getAttribute('mora_dias_gracia'),
        );
    }

    public function cobraInteres(): bool
    {
        return ! $this->tasaDeInteres()->esCero();
    }

    public function cobraMora(): bool
    {
        return $this->condicionesDeMora()->cobra();
    }

    public function esDeContado(): bool
    {
        return $this->getAttribute('meses') === 0;
    }

    /**
     * Cómo se llama este plan en pantalla.
     *
     * La etiqueta es opcional y sirve para los casos que el número no
     * explica: «12 meses (promoción de feria)».
     */
    public function nombre(): string
    {
        $etiqueta = $this->getAttribute('etiqueta');

        if (is_string($etiqueta) && $etiqueta !== '') {
            return $etiqueta;
        }

        return $this->esDeContado() ? 'Contado' : $this->getAttribute('meses').' meses';
    }
}
