<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Configuración visual del sistema (logo, favicon, color primario).
 *
 * Patrón singleton: solo existe UNA fila en la tabla, accesible vía
 * BrandingSetting::current(). El registro inicial lo crea
 * BrandingSettingSeeder.
 *
 * Cache forever invalidado en cada save() — se lee en CADA request del
 * panel (brand logo, favicon, color), pero solo se guarda cuando el
 * admin lo edita desde la página de Configuración.
 *
 * @property int $id
 * @property string|null $logo_path
 * @property string|null $favicon_path
 * @property string $primary_color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BrandingSetting extends Model
{
    private const CACHE_KEY = 'branding_setting:current';

    /** @var array<int, string> */
    protected $fillable = [
        'logo_path',
        'favicon_path',
        'primary_color',
    ];

    /**
     * Obtiene el registro singleton (cacheado).
     * Si no existe lo crea con valores por defecto.
     */
    public static function current(): self
    {
        /** @var self $setting */
        $setting = Cache::rememberForever(
            self::CACHE_KEY,
            static fn (): self => self::firstOrCreate(
                [],
                ['primary_color' => '#f59e0b']
            )
        );

        return $setting;
    }

    /**
     * Limpia el cache cuando se modifica el registro.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(static fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * URL pública del logo, o null si no se ha subido.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if ($this->logo_path === null || $this->logo_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * URL pública del favicon, o null si no se ha subido.
     */
    public function getFaviconUrlAttribute(): ?string
    {
        if ($this->favicon_path === null || $this->favicon_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->favicon_path);
    }
}
