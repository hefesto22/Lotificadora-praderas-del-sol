<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
use App\Models\Pivots\DuenoDelExpediente;
use App\Traits\HasAuditFields;
use Carbon\CarbonImmutable;
use Database\Factories\VentaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\Activitylog\Models\Activity;
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
    'vendedor_id',
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
     * Quien cerro la venta, cuando no la cerro la lotificadora.
     *
     * Null es la respuesta normal y no es un hueco: la mayoria de los
     * contratos los cierra la lotificadora misma. Ver `Vendedor`.
     *
     * @return BelongsTo<Vendedor, $this>
     */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class);
    }

    /**
     * Los duenos del expediente (R8), con su marca de titular y su orden
     * de aparicion en el contrato.
     *
     * El cuarto parametro generico es el PIVOT: `->using()` lo cambia de
     * `Pivot` a la clase propia, y sin decirlo el tipo declarado y el
     * devuelto dejan de coincidir (BelongsToMany no es covariante).
     *
     * @return BelongsToMany<Cliente, $this, DuenoDelExpediente, 'pivot'>
     */
    public function clientes(): BelongsToMany
    {
        return $this->belongsToMany(Cliente::class, 'venta_cliente')
            ->using(DuenoDelExpediente::class)
            ->withPivot(['titular', 'orden', 'titular_hasta'])
            ->withTimestamps()
            ->orderByPivot('orden');
    }

    /**
     * El titular, pero como RELACION.
     *
     * ═══ POR QUE EXISTE SI YA ESTA `titular()` ═══
     *
     * Porque `titular()` hace su propia consulta cada vez que se lo llama, y
     * en una tabla de setenta expedientes eso son setenta consultas. Esta se
     * puede precargar con `with('titulares')`: una sola para toda la página.
     *
     * Y hay una razón de tipos además de la de rendimiento: filtrar el pivot
     * en el closure de un `with()` obliga a tipar el parámetro como
     * `BelongsToMany`, que es MAS ESTRECHO que el `Builder` que Laravel
     * declara — contravarianza rota, y PHPStan lo rechaza. Con la relación
     * nombrada acá, la pantalla precarga pasando un string y no hay closure
     * que tipar.
     *
     * En plural porque una relación `hasMany`-ish devuelve colección: hoy
     * siempre trae uno —solo un dueño lleva la marca— pero el tipo no puede
     * prometer lo que la base no obliga.
     *
     * @return BelongsToMany<Cliente, $this, DuenoDelExpediente, 'pivot'>
     */
    public function titulares(): BelongsToMany
    {
        return $this->clientes()->wherePivot('titular', true);
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
     * Las llamadas de cobro que se le hicieron a este expediente.
     *
     * @return HasMany<GestionDeCobro, $this>
     */
    public function gestiones(): HasMany
    {
        return $this->hasMany(GestionDeCobro::class)
            ->orderByDesc('contactado_el')
            ->orderByDesc('id');
    }

    /**
     * La última llamada, que es la que manda.
     *
     * ═══ POR QUE LA ULTIMA Y NO «SI HAY ALGUNA» ═══
     *
     * Porque una promesa vieja no sobrevive a una llamada nueva. Si el 20
     * prometió pagar el 30 y el 22 lo vuelven a llamar y no atiende, el
     * expediente tiene que volver a la lista el 23 —no el 30—: lo que se
     * sabe de ese cliente es lo último que pasó, no lo más conveniente.
     *
     * ⚠️ El desempate por `id` no es decorativo: `contactado_el` es una
     * FECHA, y dos llamadas del mismo día empatan siempre. Sin el segundo
     * criterio, cuál de las dos gana lo decidiría el orden físico de las
     * filas.
     *
     * @return HasOne<GestionDeCobro, $this>
     */
    public function ultimaGestion(): HasOne
    {
        return $this->hasOne(GestionDeCobro::class)->latestOfMany(['contactado_el', 'id']);
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
     * Los papeles del expediente: la promesa firmada, el DNI, los comprobantes.
     *
     * @return HasMany<Documento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class)->latest();
    }

    /**
     * Lo que este expediente ha pagado, del más reciente al más viejo.
     *
     * @return HasMany<Recibo, $this>
     */
    public function recibos(): HasMany
    {
        return $this->hasMany(Recibo::class)->latest('numero');
    }

    /**
     * Las veces que se reescribió el plan de alguno de sus lotes (R21).
     *
     * De la más reciente a la más vieja: la pregunta que se hace en ventanilla
     * es siempre «¿y esta última qué cambió?».
     *
     * @return HasMany<Reprogramacion, $this>
     */
    public function reprogramaciones(): HasMany
    {
        return $this->hasMany(Reprogramacion::class)->latest();
    }

    /**
     * Lo que se le cambió a este expediente, y quién — 22-ago-2026.
     *
     * ═══ QUE CUENTA COMO UNA ACTUALIZACION ═══
     *
     * Dos cosas, y las dos por la misma razón: cambian lo que dice el
     * contrato sin dejar rastro en ninguna otra pantalla.
     *
     *   1. **La venta**: estado, montos, plazo, y el cambio de titular —que
     *      `CambioDeTitular` asienta contra la venta justamente para esto—.
     *
     *   2. **Sus dueños**: el nombre y si están activos. Corregir «ORTIZ»
     *      por «ORTIS» no toca ni una columna de `ventas`, pero cambia el
     *      nombre que sale impreso en el estado de cuenta y en el recibo.
     *      Es del expediente aunque la fila viva en `clientes`.
     *
     * Lo que NO entra: recibos, documentos y reprogramaciones. Los tres
     * tienen su propia pestaña al lado, y repetirlos acá taparía lo que sí
     * es una modificación — un expediente a 48 meses junta cientos de
     * recibos y la corrección de un apellido quedaría enterrada.
     *
     * ═══ 🔴 TIENE QUE SER UNA RELACION, AUNQUE EL TIPO DIGA OTRA COSA ═══
     *
     * La primera versión devolvía un `Builder` de Eloquent, porque el método
     * de Filament que lo consume está tipado `Relation | Builder`. **El tipo
     * miente**: `Table::getRelationshipQuery()` hace `$relationship
     * ->getQuery()`, y eso solo funciona sobre una relación —ahí devuelve el
     * builder de Eloquent—. Sobre un builder de Eloquent baja un nivel más y
     * devuelve el query builder CRUDO, que ya no encaja con el tipo de
     * retorno: seis tests en rojo con un TypeError de vendor que no nombra
     * ni la venta ni la pestaña. Medido el 22-ago-2026.
     *
     * ⚠️ Y `noConstraints` no es una astucia: una relación normal trae
     * clavado su `where subject_id = <esta venta>`, y con esa condición
     * suelta afuera el `orWhere` de los dueños traería los cambios de
     * clientes de OTROS expedientes. Sin ella, el paréntesis de abajo es la
     * única condición y cualquier filtro que Filament agregue después entra
     * con AND sobre el conjunto entero, que es lo que uno espera.
     *
     * Por lo mismo esto NO se puede usar con `with()`: el eager loading
     * agregaría su propio `whereIn` por fuera del paréntesis.
     *
     * @return HasMany<Activity, $this>
     */
    public function actualizaciones(): HasMany
    {
        /*
         * El pivot crudo y no `clientes()`: ahí adentro están también los ex
         * titulares —los que tienen `titular_hasta`—, y que alguien haya
         * dejado de ser dueño no borra lo que se le corrigió mientras lo era.
         */
        $duenos = DB::table('venta_cliente')
            ->where('venta_id', $this->getKey())
            ->select('cliente_id');

        /** @var HasMany<Activity, $this> $relacion */
        $relacion = Relation::noConstraints(fn (): HasMany => $this->hasMany(Activity::class, 'subject_id'));

        $relacion->where(function (Builder $query) use ($duenos): void {
            $query
                ->where(function (Builder $laVenta): void {
                    $laVenta
                        ->where('subject_type', self::class)
                        ->where('subject_id', $this->getKey());
                })
                ->orWhere(function (Builder $susDuenos) use ($duenos): void {
                    $susDuenos
                        ->where('subject_type', Cliente::class)
                        ->whereIn('subject_id', $duenos);
                });
        });

        return $relacion->latest();
    }

    /**
     * Lo que el cliente paga cada mes, agrupado en tramos.
     *
     * ═══ SALE DE LAS CUOTAS GUARDADAS, NO DE UN RECALCULO ═══
     *
     * El contrato es lo que está en `cuotas`. Recalcularlo acá abriría la
     * puerta a que la pantalla diga un número y el papel diga otro.
     *
     * Con plazos distintos por lote el número BAJA solo: cuando el lote a 12
     * meses termina de pagarse, a partir del mes 13 es una cuota menos. Es lo
     * único que puede contestar «¿cuánto pago por mes?» sin mentir.
     *
     * @return list<array{desde: int, hasta: int, monto: Monto}>
     */
    public function tramosDeCuotas(): array
    {
        $porMes = Cuota::query()
            ->where('venta_id', $this->getKey())
            ->deLotesVivos()
            ->selectRaw('numero, SUM(monto) AS total')
            ->groupBy('numero')
            ->orderBy('numero')
            ->pluck('total', 'numero');

        // El tramo en curso vive en una variable y se reemplaza entero: tocarle
        // una clave a un elemento de la lista le ensancha el tipo y `monto`
        // deja de ser un Monto.
        $tramos = [];
        $actual = null;

        foreach ($porMes as $numero => $total) {
            $monto = new Monto(is_string($total) || is_int($total) ? (string) $total : '0');
            $mes = (int) $numero;

            if ($actual === null) {
                $actual = ['desde' => $mes, 'hasta' => $mes, 'monto' => $monto];

                continue;
            }

            if ($actual['monto']->igualA($monto)) {
                $actual = ['desde' => $actual['desde'], 'hasta' => $mes, 'monto' => $actual['monto']];

                continue;
            }

            $tramos[] = $actual;
            $actual = ['desde' => $mes, 'hasta' => $mes, 'monto' => $monto];
        }

        if ($actual !== null) {
            $tramos[] = $actual;
        }

        return $tramos;
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
            ->deLotesVivos()
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

    /**
     * Quienes fueron titulares de este expediente y dejaron de serlo.
     *
     * Siguen siendo dueños listados —no se les borra la fila— porque sus
     * recibos apuntan acá y porque el expediente tiene que poder contar su
     * propia historia sin ir a la bitácora. Ver `CambioDeTitular`.
     *
     * @return Collection<int, Cliente>
     */
    public function titularesAnteriores(): Collection
    {
        return $this->clientes()
            ->wherePivotNotNull('titular_hasta')
            // reorder(): `clientes()` ya ordena por `orden` y orderByPivot
            // APPENDEA, asi que sin esto la lista sale por posicion en el
            // contrato y no por fecha —y con dos cesiones se lee al reves—.
            ->reorder()
            ->orderByPivot('titular_hasta')
            ->get();
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

    /**
     * El expediente que ya no debe nada deja de estar vigente.
     *
     * ═══ POR QUE ACA, SI ESTE MODELO NO CAMBIA ESTADOS ═══
     *
     * Porque hay DOS momentos en que una venta puede quedar en cero, y son de
     * dos Services distintos:
     *
     *  1. El último pago la termina de pagar → `RegistroDePagos`.
     *  2. **Nace pagada**: la venta de contado. Se firma, se cobra el 100%
     *     como prima y no genera ni una cuota, así que jamás pasa por un
     *     cobro. Hasta el 23-ago-2026 se quedaba «Vigente» para siempre —con
     *     botón de cobrar sobre un contrato saldado—, y lo cazó Mauricio:
     *     «ya fueron pagados en su totalidad, no tiene lógica que sigan
     *     vigentes».
     *
     * Dos lugares que escriben el mismo cierre son dos lugares donde
     * olvidarse de `cerrada_el`, y el CHECK `ventas_cierre_segun_estado_chk`
     * exige que el estado y la fecha vayan juntos o no vayan. Por eso el
     * cierre es UNA operación, acá, y los Services la llaman.
     *
     * ⚠️ La condición NO es «fue de contado»: es «no queda saldo». Un
     * contrato de tres lotes puede llevar uno al contado y dos financiados, y
     * ese sigue vigente porque todavía debe. Preguntar por `plazo_meses = 0`
     * cerraría contratos con cuotas por cobrar.
     *
     * ⚠️ Se mira el saldo de las CUOTAS, no la mora. Un contrato con todo el
     * capital pagado y mora suelta se liquida igual: la mora es un cargo del
     * atraso, no parte del precio, y dejar el expediente abierto por ella
     * haría que un cliente que ya pagó su lote figure debiendo el lote.
     */
    public function liquidarSiYaNoDebe(CarbonImmutable $cuando): void
    {
        if ($this->getAttribute('estado') !== EstadoVenta::Vigente) {
            return;
        }

        if (! $this->saldoPendiente()->esCero()) {
            return;
        }

        $this->update([
            'estado'     => EstadoVenta::Liquidada,
            'cerrada_el' => $cuando->toDateString(),
        ]);
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
