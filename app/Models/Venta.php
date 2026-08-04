<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Database\Factories\VentaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * La venta, que es tambien el expediente (modulos c y d del contrato).
 *
 * ═══ LO QUE ESTE MODELO NO HACE ═══
 *
 * No activa ventas, no numera, no genera cuotas y no mueve lotes. Todo eso
 * pasa en una transaccion con varias tablas y vive en el Service (§11).
 * Aca solo hay relaciones, casts, lecturas derivadas y scopes.
 *
 * ═══ LOS LOTES SON COMPROMISOS ═══
 *
 * No hay `venta_lote`. Los lotes de una venta son sus `compromisos` de tipo
 * venta, que ya congelan area, precio y valor al momento de venderse
 * (§8.2). Una sola tabla congelando el dinero, no dos discrepando.
 *
 * ═══ LOS DUENOS SON VARIOS ═══
 *
 * Marido y mujer o socios van los dos en el contrato (R8). Uno esta marcado
 * `titular` en el pivot, y la base garantiza que no haya dos.
 */
#[Fillable([
    'proyecto_id',
    'numero_expediente',
    'numero_contrato',
    'fecha_contrato',
    'estado',
    'area_total',
    'valor_total',
    'prima',
    'saldo_financiar',
    'cuota_mensual',
    'plazo_meses',
    'dia_pago',
    'observaciones',
    'cerrada_el',
    'motivo',
])]
class Venta extends Model
{
    use HasAuditFields;

    /** @use HasFactory<VentaFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Los defaults de Postgres NO llegan al modelo en memoria tras
     * `create()` (§9.C6), y con activitylog eso es peor que un inconveniente:
     * comparar un null ausente contra el valor real produce un cambio
     * fantasma en cada update. El default de la migracion arregla la base;
     * este arregla PHP, y los dos tienen que decir lo mismo.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'estado'          => EstadoVenta::Borrador->value,
        'area_total'      => '0.0000',
        'valor_total'     => '0.00',
        'prima'           => '0.00',
        'saldo_financiar' => '0.00',
        'plazo_meses'     => 0,
    ];

    /**
     * Los montos NO se castean a `decimal:x`: ese cast pasa por
     * `number_format()`, que recibe float y reintroduce el error que Monto
     * existe para evitar (§8.3.1). PDO de Postgres ya entrega NUMERIC como
     * string, que es lo que consume bcmath.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'estado'         => EstadoVenta::class,
            'fecha_contrato' => 'date',
            'cerrada_el'     => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'estado', 'numero_contrato', 'numero_expediente', 'fecha_contrato',
                'valor_total', 'prima', 'saldo_financiar', 'cuota_mensual', 'plazo_meses', 'motivo',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Venta {$evento}");
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
     * Los duenos del expediente (R8), con su marca de titular y su orden
     * de aparicion en el contrato.
     *
     * @return BelongsToMany<Cliente, $this>
     */
    public function clientes(): BelongsToMany
    {
        return $this->belongsToMany(Cliente::class, 'venta_cliente')
            ->withPivot(['titular', 'orden'])
            ->withTimestamps()
            ->orderByPivot('orden');
    }

    /**
     * Los lotes de la venta, como compromisos: ahi esta el dinero congelado.
     *
     * @return HasMany<Compromiso, $this>
     */
    public function compromisos(): HasMany
    {
        return $this->hasMany(Compromiso::class);
    }

    /**
     * Los lotes propiamente dichos, para cuando hace falta el poligono o el
     * bloque y no el valor congelado.
     *
     * @return HasManyThrough<Lote, Compromiso, $this>
     */
    public function lotes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Lote::class,
            Compromiso::class,
            'venta_id',
            'id',
            'id',
            'lote_id',
        );
    }

    /**
     * @return HasMany<Cuota, $this>
     */
    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class)->orderBy('numero');
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    public function montoValorTotal(): Monto
    {
        return $this->montoDe('valor_total');
    }

    public function montoPrima(): Monto
    {
        return $this->montoDe('prima');
    }

    public function montoSaldoFinanciar(): Monto
    {
        return $this->montoDe('saldo_financiar');
    }

    public function montoCuotaMensual(): ?Monto
    {
        $cuota = $this->getAttribute('cuota_mensual');

        return is_string($cuota) || is_int($cuota) ? new Monto($cuota) : null;
    }

    /**
     * Lo que el cliente todavia debe, derivado de las cuotas.
     *
     * Se calcula, no se guarda: una columna `saldo_actual` que se
     * desincroniza es la forma mas cara de mentirle a un cliente. Si algun
     * dia el rendimiento lo exige, el §8.3.4 permite cachearla —pero
     * actualizada dentro de la misma transaccion y con un test que la
     * reconstruya desde cero.
     *
     * El `reorder()` no es decorativo: la relacion `cuotas()` viene con
     * `orderBy('numero')`, y ese ORDER BY sobrevive al agregado. Postgres
     * entonces exige que `numero` este en el GROUP BY o dentro de una
     * funcion de agregacion, y tira un error 42803. MySQL lo dejaria pasar
     * en silencio; Postgres tiene razon y avisa.
     */
    public function saldoPendiente(): Monto
    {
        /** @var string|int|null $suma */
        $suma = $this->cuotas()
            ->reorder()
            ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0) AS pendiente')
            ->value('pendiente');

        return new Monto(is_string($suma) || is_int($suma) ? $suma : '0');
    }

    // ─── Duenos ───────────────────────────────────────────────────────

    /**
     * El cliente a cuyo nombre sale el estado de cuenta.
     *
     * Cualquiera de los copropietarios puede pagar; el titular es a quien
     * se le dirigen los documentos. Es el criterio conservador mientras la
     * contratante no diga otra cosa (pregunta abierta en docs/dominio.md).
     */
    public function titular(): ?Cliente
    {
        return $this->clientes()->wherePivot('titular', true)->first();
    }

    // ─── Estado ───────────────────────────────────────────────────────

    public function esBorrador(): bool
    {
        return $this->estadoActual() === EstadoVenta::Borrador;
    }

    public function estaVigente(): bool
    {
        return $this->estadoActual() === EstadoVenta::Vigente;
    }

    /**
     * ¿Es una venta de contado? Sin saldo no hay plan de cuotas.
     */
    public function esDeContado(): bool
    {
        return (int) $this->getAttribute('plazo_meses') === 0;
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * @param Builder<Venta> $query
     *
     * @return Builder<Venta>
     */
    #[Scope]
    protected function vigentes(Builder $query): Builder
    {
        return $query->where('estado', EstadoVenta::Vigente);
    }

    /**
     * @param Builder<Venta> $query
     *
     * @return Builder<Venta>
     */
    #[Scope]
    protected function delProyecto(Builder $query, Proyecto $proyecto): Builder
    {
        return $query->where('proyecto_id', $proyecto->getKey());
    }

    // ─── Interno ──────────────────────────────────────────────────────

    private function montoDe(string $columna): Monto
    {
        $valor = $this->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    private function estadoActual(): ?EstadoVenta
    {
        $estado = $this->getAttribute('estado');

        return $estado instanceof EstadoVenta ? $estado : null;
    }
}
