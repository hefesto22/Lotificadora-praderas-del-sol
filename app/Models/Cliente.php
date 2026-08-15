<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoCompromiso;
use App\Domain\ValueObjects\DNI;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\RTN;
use App\Traits\HasAuditFields;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Módulo a) del contrato: la persona que compra (§8.2).
 *
 * `dni` y `rtn` viven limpios —solo dígitos— y se formatean al mostrar.
 * Los mutators normalizan lo que sea que haya tecleado la persona, así que
 * "0801-1985-01234" y "0801198501234" son el mismo cliente y el índice
 * único los ve como uno solo.
 *
 * Soft deletes: un cliente con pagos no se borra. Ver el comentario de la
 * migración sobre por qué los índices únicos son parciales.
 */
#[Fillable([
    'nombre',
    'dni',
    'rtn',
    'telefono',
    'correo',
    'direccion',
    'activo',
    'observaciones',
])]
class Cliente extends Model
{
    use HasAuditFields;

    /** @use HasFactory<ClienteFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
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
     * §13.5 y §8.2: el DNI, el RTN, el teléfono, el correo y la dirección
     * son PII y NO entran a la bitácora.
     *
     * `activity_log` la puede leer cualquiera con permiso de auditoría, y
     * auditar esos campos copiaría la identificación de cada cliente a una
     * tabla con otro control de acceso. Lo que importa auditar es QUIÉN tocó
     * la ficha y CUÁNDO — eso queda igual con el causer y el timestamp.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'activo'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Cliente {$evento}");
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * Los expedientes donde este cliente firma, solo o acompañado (R8).
     *
     * Es el reverso de `Venta::clientes()`, y NO distingue al titular del
     * copropietario a propósito: la pregunta que llega al mostrador es «¿este
     * señor qué compró?», y un lote comprado entre marido y mujer es de los
     * dos aunque la titular sea ella.
     *
     * @return BelongsToMany<Venta, $this>
     */
    public function ventas(): BelongsToMany
    {
        return $this->belongsToMany(Venta::class, 'venta_cliente')
            ->withPivot(['titular', 'orden'])
            ->withTimestamps();
    }

    /**
     * Todo lo que este cliente tiene comprometido: lo apartado y lo vendido.
     *
     * @return HasMany<Compromiso, $this>
     */
    public function compromisos(): HasMany
    {
        return $this->hasMany(Compromiso::class);
    }

    /**
     * Los apartados, que son compromisos de tipo apartado (§8.2).
     *
     * La condición es LA MISMA línea que `ApartadoResource::getEloquentQuery()`,
     * y eso es a propósito: el contador de la ficha y la pantalla que abre el
     * link tienen que estar de acuerdo sobre qué es un apartado, o el número
     * dice dos y la lista muestra tres (§9.E6).
     *
     * @return HasMany<Compromiso, $this>
     */
    public function apartados(): HasMany
    {
        return $this->hasMany(Compromiso::class)->where('tipo', TipoCompromiso::Apartado);
    }

    /**
     * Lo que este cliente ha pagado, anulados incluidos.
     *
     * Los anulados NO se filtran porque la pantalla de Recibos tampoco los
     * esconde: el número sigue en la serie y el papel sigue en la mano de
     * alguien. Un contador que dijera menos que la lista que abre sería un
     * contador que miente.
     *
     * @return HasMany<Recibo, $this>
     */
    public function recibos(): HasMany
    {
        return $this->hasMany(Recibo::class);
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    /**
     * Lo que este cliente debe hoy, sumando TODOS sus expedientes vigentes.
     *
     * ═══ POR QUE SOLO LOS VIGENTES ═══
     *
     * Es la misma regla del «Por cobrar» del Escritorio. Una venta rescindida
     * deja sus cuotas impagas en la tabla, y sumarlas sería inventarle al
     * cliente una deuda que nadie le va a cobrar nunca.
     *
     * ═══ SE CALCULA, NO SE GUARDA ═══
     *
     * Lo mismo que dice `Venta::saldoPendiente()`: una columna `saldo_actual`
     * que se desincroniza es la forma más cara de mentirle a un cliente
     * (§8.3.4).
     */
    public function saldoPendiente(): Monto
    {
        /** @var string|int|null $suma */
        $suma = self::query()
            ->whereKey($this->getKey())
            ->addSelect(['saldo_pendiente' => self::consultaDeSaldo()])
            ->value('saldo_pendiente');

        return new Monto(is_string($suma) || is_int($suma) ? $suma : '0');
    }

    /**
     * La misma cuenta de arriba, como subconsulta correlacionada.
     *
     * Va adentro del `addSelect` de un listado de clientes y resuelve toda la
     * página con UNA consulta, en vez de una por fila (§9.D). Correlaciona
     * contra `clientes.id` de la consulta de afuera, así que solo sirve
     * adentro de una consulta sobre `clientes`.
     *
     * `venta_cliente` se nombra acá por segunda vez —la primera está en
     * `Venta::clientes()`— porque una relación de Eloquent no sabe
     * correlacionar contra una columna de la consulta de afuera: es un join a
     * la pivote, o es una consulta por fila.
     *
     * @return Builder<Cuota>
     */
    public static function consultaDeSaldo(): Builder
    {
        return Cuota::query()
            ->reorder()
            ->deLotesVivos()
            ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0)')
            ->whereIn('venta_id', Venta::query()
                ->vigentes()
                ->select('ventas.id')
                ->join('venta_cliente', 'venta_cliente.venta_id', '=', 'ventas.id')
                ->whereColumn('venta_cliente.cliente_id', 'clientes.id'));
    }

    // ─── Normalización de entrada ─────────────────────────────────────

    /**
     * El §10.4 excluye explícitamente los nombres de personas del
     * auto-mayúsculas: "María de los Ángeles Rodríguez" no es un código de
     * catálogo. Solo se recortan los espacios sobrantes, que sí producen
     * duplicados invisibles al buscar.
     *
     * DECISION DEL 3/8/2026: los nombres de personas TAMBIEN van en
     * mayusculas. Deroga la excepcion que traia el §10.4 —"Maria de los
     * Angeles no es un codigo de catalogo"— por pedido expreso: en este
     * sistema todo se guarda y se muestra en mayusculas, sin excepciones.
     * Ver docs/mayusculas.md.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function nombre(): Attribute
    {
        return Attribute::make(
            set: static function (?string $valor): ?string {
                if (blank($valor)) {
                    return null;
                }

                return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($valor)), 'UTF-8');
            },
        );
    }

    /**
     * La direccion tambien va en mayusculas, por la misma decision.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function direccion(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? mb_strtoupper(trim($valor), 'UTF-8')
                : null,
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function dni(): Attribute
    {
        return Attribute::make(
            // desdeEntrada valida y lanza ValueObjectInvalidoException con
            // un mensaje del dominio. Un DNI mal formado no llega nunca a
            // la base, ni desde el panel, ni desde un seeder, ni desde tinker.
            set: static fn (?string $valor): ?string => DNI::desdeEntrada($valor)?->valor,
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function rtn(): Attribute
    {
        return Attribute::make(
            set: static function (?string $valor): ?string {
                $limpio = self::soloDigitos($valor);

                return $limpio === null ? null : new RTN($limpio)->valor;
            },
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function telefono(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => self::soloDigitos($valor),
        );
    }

    /**
     * Los correos van en minúsculas: "Rosa@Gmail.com" y "rosa@gmail.com"
     * son la misma casilla, y guardarlos distinto rompe cualquier búsqueda.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function correo(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? mb_strtolower(trim($valor), 'UTF-8')
                : null,
        );
    }

    // ─── Presentación ─────────────────────────────────────────────────

    /** 0801-1985-01234, o null si el cliente no tiene DNI cargado. */
    public function dniFormateado(): ?string
    {
        $valor = $this->getAttribute('dni');

        return is_string($valor) && $valor !== ''
            ? DNI::formatearCrudo($valor)
            : null;
    }

    /** 0801-1998-501234, o null. */
    public function rtnFormateado(): ?string
    {
        $valor = $this->getAttribute('rtn');

        return is_string($valor) && $valor !== ''
            ? new RTN($valor)->formateado()
            : null;
    }

    /** +504 9988-7766, o null. */
    public function telefonoFormateado(): ?string
    {
        $valor = $this->getAttribute('telefono');

        if (! is_string($valor) || strlen($valor) !== 8) {
            return null;
        }

        return '+504 '.substr($valor, 0, 4).'-'.substr($valor, 4, 4);
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * @param Builder<Cliente> $query
     *
     * @return Builder<Cliente>
     */
    #[Scope]
    protected function activos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    // ─── Utilidades ───────────────────────────────────────────────────

    private static function soloDigitos(?string $valor): ?string
    {
        $limpio = preg_replace('/\D/', '', (string) $valor) ?? '';

        return $limpio === '' ? null : $limpio;
    }
}
