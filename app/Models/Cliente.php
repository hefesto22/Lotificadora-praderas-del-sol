<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\DNI;
use App\Domain\ValueObjects\RTN;
use App\Traits\HasAuditFields;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
