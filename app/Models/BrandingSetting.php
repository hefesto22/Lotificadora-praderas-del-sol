<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Override;

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
#[Fillable([
    'logo_path',
    'favicon_path',
    'primary_color',
])]
class BrandingSetting extends Model
{
    private const string CACHE_KEY = 'branding_setting:current';

    /**
     * Obtiene el registro singleton (cacheado).
     * Si no existe lo crea con valores por defecto.
     */
    public static function current(): self
    {
        /** @var self $setting */
        $setting = Cache::rememberForever(
            self::CACHE_KEY,
            static fn (): self => self::query()->firstOrCreate([], ['primary_color' => '#f59e0b'])
        );

        return $setting;
    }

    /**
     * Limpia el cache cuando se modifica el registro.
     */
    #[Override]
    protected static function booted(): void
    {
        static::saved(static fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(static fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * URL pública del logo, o null si no se ha subido.
     *
     * El `never` del segundo parámetro no es un adorno: declara que este
     * atributo **no se puede asignar**. Solo tiene `get`, porque la URL se
     * deriva de `logo_path` y escribirla directamente no significaría nada.
     *
     * @return Attribute<string|null, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->logo_path === null || $this->logo_path === '') {
                return null;
            }

            return Storage::disk('public')->url($this->logo_path);
        });
    }

    /**
     * URL pública del favicon, o null si no se ha subido.
     *
     * Sin setter, igual que `logoUrl()`: se deriva de `favicon_path`.
     *
     * @return Attribute<string|null, never>
     */
    protected function faviconUrl(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->favicon_path === null || $this->favicon_path === '') {
                return null;
            }

            return Storage::disk('public')->url($this->favicon_path);
        });
    }
}
