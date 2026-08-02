<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;
use stdClass;

class User extends Authenticatable implements FilamentUser
{
    use HasAuditFields;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPanelShield;
    use HasRoles;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'is_active',
        'last_login_at',
        'last_login_ip',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'last_login_at'     => 'datetime',
        ];
    }

    /**
     * Bloquea el acceso al panel para usuarios inactivos o sin rol.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->hasRole(ShieldUtils::getSuperAdminName())
            || $this->hasRole(ShieldUtils::getPanelUserRoleName());
    }

    /**
     * Configuración de Activity Log — solo cambios significativos.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Usuario {$eventName}");
    }

    /**
     * Registra el último login exitoso.
     */
    public function recordLogin(string $ip): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }

    // ─── Relaciones jerárquicas ───────────────────────────────

    /**
     * Usuarios creados directamente por este usuario.
     *
     * @return HasMany<User, $this>
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(self::class, 'created_by');
    }

    /**
     * IDs de toda la rama descendente (hijos, nietos, bisnietos…).
     *
     * Implementado con CTE recursivo de PostgreSQL — UNA sola query
     * sin importar la profundidad del árbol. Reemplaza la versión
     * recursiva PHP que generaba N+1 (un query por cada usuario).
     *
     * Para árboles típicos de empresa (5-10 niveles, 100-1000 usuarios)
     * pasa de ~1000ms a ~5ms.
     *
     * @return array<int, int>
     */
    public function getDescendantIds(): array
    {
        $sql = <<<'SQL'
            WITH RECURSIVE descendientes AS (
                SELECT id
                FROM users
                WHERE created_by = ?
                  AND deleted_at IS NULL

                UNION ALL

                SELECT u.id
                FROM users u
                INNER JOIN descendientes d ON u.created_by = d.id
                WHERE u.deleted_at IS NULL
            )
            SELECT id FROM descendientes
        SQL;

        return array_map(
            static fn (stdClass $row): int => (int) $row->id, // @phpstan-ignore-line property.notFound
            DB::select($sql, [$this->id])
        );
    }

    /**
     * IDs visibles para este usuario: él mismo + descendientes.
     *
     * @return array<int, int>
     */
    public function getVisibleUserIds(): array
    {
        return array_merge([$this->id], $this->getDescendantIds());
    }

    // ─── Scopes ───────────────────────────────────────────────

    /**
     * @param Builder<User> $query
     *
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<User> $query
     *
     * @return Builder<User>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Filtra usuarios visibles según jerarquía.
     * Super admin ve todos; el resto solo su rama descendente.
     *
     * @param Builder<User> $query
     *
     * @return Builder<User>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole(ShieldUtils::getSuperAdminName())) {
            return $query;
        }

        return $query->whereIn('id', $user->getVisibleUserIds());
    }
}
