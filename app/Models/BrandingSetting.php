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
     *
     * ═══ SE CACHEAN LOS DATOS, NO EL OBJETO ═══
     *
     * Guardar el modelo entero en Redis escribe el nombre de la clase dentro
     * del blob serializado. El dia que la clase se mueva de namespace —o que
     * otra app comparta la misma base de Redis— PHP no la encuentra al
     * deshidratar, devuelve `__PHP_Incomplete_Class` y el `: self` de esta
     * firma revienta con un TypeError.
     *
     * No es teorico: el 6-ago-2026 tumbo el estado de cuenta con un 500 que
     * ademas mostraba el trace en pantalla. El panel lo disimulaba porque
     * `AdminPanelProvider` envuelve esta llamada en un try/catch, asi que el
     * problema solo se veia en las paginas de documentos.
     *
     * Cacheando el array de atributos —puros strings y numeros— no hay
     * ningun nombre de clase en Redis y el caso no puede volver.
     */
    public static function current(): self
    {
        $atributos = Cache::rememberForever(
            self::CACHE_KEY,
            static fn (): array => self::singleton()->getAttributes()
        );

        /*
         * Y por si Redis todavia trae un blob viejo del formato anterior: se
         * tira y se vuelve a leer. Sin esto, el sistema queda en 500 hasta
         * que alguien corra `cache:clear` a mano.
         */
        if (! is_array($atributos)) {
            Cache::forget(self::CACHE_KEY);

            $atributos = self::singleton()->getAttributes();

            Cache::forever(self::CACHE_KEY, $atributos);
        }

        return new self()->newFromBuilder($atributos);
    }

    /**
     * La unica fila, leida de la base.
     */
    private static function singleton(): self
    {
        return self::query()->firstOrCreate([], ['primary_color' => '#f59e0b']);
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
