<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\UnidadDeArea;
use App\Domain\Exceptions\ProyectoConMovimientoException;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Database\Factories\ProyectoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Desarrollo inmobiliario. Raíz de la jerarquía proyectos → bloques → lotes
 * (ADR-0002).
 *
 * `codigo` es el prefijo de los correlativos de contrato: RPS-2026-0065.
 */
#[Fillable([
    'nombre',
    'codigo',
    'logo_path',
    'facturacion_id',
    'unidad_area',
    'dona_lotes',
    'lotes_a_donar',
    'reserva_lotes',
    'lotes_a_reservar',
    'municipio',
    'departamento',
    'direccion',
    'latitud',
    'longitud',
    'telefonos',
    'correo',
    'activo',
    'plano_esquematico',
    'medidas_en_metros',
    'vara_en_metros',
    'slug',
    'plano_publico',
    'whatsapp',
    'servicios',
    'observaciones',
])]
class Proyecto extends Model
{
    use HasAuditFields;

    /** @use HasFactory<ProyectoFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Cuanto del nombre entra en el slug.
     *
     * `proyectos.slug` mide 80; los 11 que sobran son para el guion y el
     * codigo del proyecto, que es lo que desempata cuando dos desarrollos se
     * llaman parecido.
     */
    private const int SLUG_BASE = 69;

    /**
     * Valor inicial de `plano_esquematico` en memoria, no solo en la base.
     *
     * Sin esto, un modelo recien creado NO tiene el atributo cargado: al
     * leerlo, el cast a boolean convierte el null ausente en false, y
     * spatie/activitylog compara null contra false y concluye que cambio.
     * Resultado: cada update del proyecto registraba una modificacion
     * fantasma de esta columna, y `dontLogEmptyChanges` dejaba de servir
     * porque siempre habia "algo" que loguear.
     *
     * El default de la migracion arregla la base; este arregla PHP. Los
     * dos tienen que existir y decir lo mismo.
     *
     * ⚠️ Vale IGUAL para un atributo casteado a ENUM. `unidad_area` cayo
     * en la misma trampa el 13-ago: sin el default de aca, el modelo
     * recien creado no lo trae, el cast convierte el null ausente en
     * UnidadDeArea::Varas, y el primer update registraba un cambio de
     * unidad que nadie hizo. **Toda columna nueva con default en la
     * migracion se repite en esta lista.**
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'plano_esquematico' => false,
        'medidas_en_metros' => false,
        'unidad_area'       => UnidadDeArea::Varas->value,
        'dona_lotes'        => false,
        'lotes_a_donar'     => 0,
        'reserva_lotes'     => false,
        'lotes_a_reservar'  => 0,
    ];

    /**
     * Borrar un proyecto se lleva TODO lo que cuelga de el.
     *
     * Las FK son restrictOnDelete a proposito: la base no borra en
     * cascada sola, porque un `delete` distraido sobre un proyecto con
     * 300 lotes no deberia ser silencioso. Pero el boton de Filament, un
     * tinker y `artisan proyecto:eliminar` tienen que comportarse igual,
     * asi que la cascada -y sobre todo la regla que la frena- viven aca y
     * no en cada llamador.
     *
     * La regla: si un solo lote dejo de estar DISPONIBLE, no se borra. Es
     * la misma que usa PlanoRealPraderasSeeder para no pisar geometria
     * (§8.2). Un proyecto de prueba se tira sin drama; uno donde alguien
     * ya aparto o compro tiene un cliente y un recibo detras.
     */
    #[Override]
    protected static function booted(): void
    {
        /*
         * 🔴 El slug se rellena solo, y por eso el formulario no lo exige.
         *
         * Es la direccion con la que el proyecto vive en internet. Cuando se
         * agrego la columna quedo NOT NULL y el campo del panel `required()`,
         * y eso volteo 418 tests de una sola vez: cada `Proyecto::factory()`
         * del sistema inserta sin slug. La leccion no fue «arreglá los
         * factories» — fue que un dato que se deriva del nombre no hay por
         * que pedirselo a nadie.
         *
         * `saving` y no `creating`: si alguien borra el campo en el panel y
         * guarda, la alternativa seria un 500 contra el CHECK de la base.
         *
         * ⚠️ Solo cuando esta VACIO. Un slug que se recalcula porque alguien
         * corrigio una tilde del nombre rompe todos los links ya mandados por
         * WhatsApp, y nadie relaciona una cosa con la otra.
         */
        static::saving(function (Proyecto $proyecto): void {
            $slug = $proyecto->getAttribute('slug');

            if (is_string($slug) && trim($slug) !== '') {
                return;
            }

            $proyecto->setAttribute('slug', self::slugPara(
                (string) $proyecto->getAttribute('nombre'),
                (string) $proyecto->getAttribute('codigo'),
                $proyecto->exists ? $proyecto->getKey() : null,
            ));
        });

        static::deleting(function (Proyecto $proyecto): void {
            $ocupados = $proyecto->lotesConMovimiento();

            if ($ocupados > 0) {
                throw ProyectoConMovimientoException::porLotesNoDisponibles(
                    (string) $proyecto->getAttribute('codigo'),
                    $ocupados,
                );
            }

            DB::transaction(function () use ($proyecto): void {
                $id = $proyecto->getKey();

                // En este orden: los compromisos cuelgan de los lotes y
                // los lotes de los bloques. Al reves, la FK
                // restrictOnDelete corta el borrado por la mitad.
                Compromiso::query()->where('proyecto_id', $id)->delete();
                Lote::query()->where('proyecto_id', $id)->delete();
                Bloque::query()->where('proyecto_id', $id)->delete();
                Calle::query()->where('proyecto_id', $id)->delete();
            });
        });
    }

    /**
     * La direccion libre para un proyecto, sacada de su nombre.
     *
     * `Str::slug()` y no un `strtolower(str_replace(...))`: sabe de tildes y
     * de la ñ. «LA CAÑADA» tiene que dar `la-canada` y no romperse.
     *
     * Cuando la base ya esta tomada desempata con el codigo del proyecto, que
     * es unico — dos desarrollos pueden llamarse parecido. Y si hasta eso
     * choca, numera. El recorte deja lugar al sufijo dentro de los 80 de la
     * columna, y el `trim` saca el guion que el corte pudo dejar colgando: el
     * CHECK de la base no acepta uno al final.
     */
    public static function slugPara(string $nombre, string $codigo, mixed $exceptoId = null): string
    {
        $base = trim(Str::limit(Str::slug($nombre), self::SLUG_BASE, ''), '-');

        if ($base === '') {
            $base = trim(Str::limit(Str::slug($codigo), self::SLUG_BASE, ''), '-');
        }

        if ($base === '') {
            $base = 'proyecto';
        }

        $desempate = trim(Str::slug($codigo), '-');
        $candidato = $base;
        $vuelta = 0;

        while (true) {
            $consulta = self::query()->where('slug', $candidato);

            if ($exceptoId !== null) {
                $consulta->whereKeyNot($exceptoId);
            }

            if (! $consulta->exists()) {
                return $candidato;
            }

            $vuelta++;

            $candidato = $vuelta === 1 && $desempate !== ''
                ? $base.'-'.$desempate
                : $base.'-'.$vuelta;
        }
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activo'            => 'boolean',
            'plano_esquematico' => 'boolean',
            'medidas_en_metros' => 'boolean',
            'unidad_area'       => UnidadDeArea::class,
            'dona_lotes'        => 'boolean',
            'lotes_a_donar'     => 'integer',
            'reserva_lotes'     => 'boolean',
            'lotes_a_reservar'  => 'integer',
            'plano_publico'     => 'boolean',
            'servicios'         => 'array',
        ];
    }

    /**
     * Cuánto mide la vara de ESTE proyecto, en metros.
     *
     * `null` en la columna significa «la vara del sistema»: el default
     * sigue viviendo en config/lotificadora.php y no copiado en cada fila
     * (§8.3.7). Un proyecto guarda su propio número solo cuando su
     * topógrafo usa otra vara —la castellana son 0.8359 m, la mexicana
     * 0.8380 y la de Texas 0.8467—.
     *
     * Devuelve string y nunca float: de este número sale cuántas varas²
     * tiene cada lote al importar el plano, y el precio es POR VARA². Es
     * el mismo criterio del §8.3.1 —el área en varas² por el precio por
     * vara² ES dinero— y por eso el valor entra a bcmath como texto.
     */
    public function varaEnMetros(): string
    {
        /*
         * En un proyecto que trabaja en metros² la unidad del área ES el
         * metro: el factor vale uno y no hay nada que preguntarle al
         * topógrafo. Se consulta ANTES que la columna a propósito — un
         * `vara_en_metros` viejo no puede contradecir a la unidad.
         */
        $porLaUnidad = $this->unidadDeArea()->ladoEnMetros();

        if ($porLaUnidad !== null) {
            return $porLaUnidad;
        }

        $propia = $this->getAttribute('vara_en_metros');

        if (is_numeric($propia) && (float) $propia > 0) {
            return number_format((float) $propia, 6, '.', '');
        }

        return (string) config('lotificadora.area.vara_en_metros', '0.8359');
    }

    /**
     * Tercera defensa del §10.4: aunque el valor entre por un seeder, un
     * import o tinker —sin pasar por el formulario— queda en mayúsculas.
     *
     * Los espacios de más se colapsan además del cambio de caja: "Praderas
     *  del  Sol" y "Praderas del Sol" son el mismo proyecto, y el índice
     * único no los distingue.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function nombre(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($valor)), 'UTF-8')
                : null,
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function municipio(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? mb_strtoupper(trim($valor), 'UTF-8')
                : null,
        );
    }

    /**
     * El código es el prefijo de los correlativos de contrato
     * (RPS-2026-0065): una minúscula suelta produciría dos series
     * distintas.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function codigo(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? mb_strtoupper($valor, 'UTF-8')
                : null,
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'codigo', 'unidad_area', 'activo', 'plano_esquematico', 'medidas_en_metros', 'vara_en_metros', 'dona_lotes', 'lotes_a_donar', 'reserva_lotes', 'lotes_a_reservar', 'facturacion_id', 'logo_path'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Proyecto {$evento}");
    }

    /**
     * La unidad en la que este desarrollo mide y COBRA la superficie.
     *
     * Nunca devuelve null: la columna tiene default y CHECK. Se lee por
     * acá y no por el atributo crudo para que un proyecto recién armado
     * con `new Proyecto` —sin pasar por la base— también conteste.
     */
    public function unidadDeArea(): UnidadDeArea
    {
        $unidad = $this->getAttribute('unidad_area');

        return $unidad instanceof UnidadDeArea ? $unidad : UnidadDeArea::Varas;
    }

    public function trabajaEnMetros(): bool
    {
        return $this->unidadDeArea() === UnidadDeArea::Metros;
    }

    /**
     * ¿Todavía se puede cambiar la unidad de este desarrollo?
     *
     * Hasta que salga el primer lote. Cambiarla NO reconvierte ningún
     * área —ver UnidadDeArea— así que después de una venta el número del
     * contrato firmado y el de la pantalla estarían en unidades
     * distintas. Regla de Mauricio, 13-ago-2026: «se puede editar solo
     * si no se ha vendido ninguno, de ahí no se puede editar».
     *
     * Un APARTADO no traba: es reversible —para eso existe la devolución
     * de la seña— y todavía no hay escritura de por medio. Una DONACIÓN
     * sí, porque el lote salió del inventario para siempre.
     */
    public function puedeCambiarLaUnidad(): bool
    {
        return ! Lote::query()
            ->where('proyecto_id', $this->getKey())
            ->whereIn('estado', [EstadoLote::Vendido->value, EstadoLote::Donado->value])
            ->exists();
    }

    /**
     * El membrete del RECIBO INTERNO, armado con lo que el proyecto ya tiene.
     *
     * Lo pidió Mauricio el 14-ago-2026: un recibo de caja no necesita una
     * facturación —no tiene CAI, ni establecimiento, ni rango— así que se
     * configura acá mismo. Y la dirección NO se vuelve a teclear: sale de
     * la pestaña Ubicación, que es donde ya estaba.
     *
     * ⚠️ Devuelve las MISMAS claves que `Facturacion::comoEmisor()` y que
     * la config: así el recibo y el estado de cuenta no distinguen de dónde
     * salió el membrete, y las tres fuentes se pueden encadenar.
     *
     * Sin RTN a propósito: un comprobante de caja no lo lleva. El que
     * factura con CAI pasa por `Facturacion`, que sí lo exige.
     *
     * @return array{nombre: string|null, rtn: string|null, residencial: string|null, direccion: string|null, telefono: string|null}
     */
    public function comoEmisor(): array
    {
        $texto = function (string $columna): ?string {
            $valor = $this->getAttribute($columna);

            return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
        };

        return [
            'nombre'      => null,
            'rtn'         => null,
            'residencial' => $texto('nombre'),
            'direccion'   => $texto('direccion') ?? $this->municipioYDepartamento(),
            'telefono'    => $texto('telefonos'),
        ];
    }

    /**
     * «Corpus, Copán» — el respaldo cuando no cargaron una dirección larga.
     */
    private function municipioYDepartamento(): ?string
    {
        $partes = [];

        foreach (['municipio', 'departamento'] as $columna) {
            $valor = $this->getAttribute($columna);

            if (is_string($valor) && trim($valor) !== '') {
                $partes[] = trim($valor);
            }
        }

        return $partes === [] ? null : implode(', ', $partes);
    }

    /**
     * La URL del logo de este desarrollo, o null si no le cargaron ninguno.
     *
     * Se guarda la RUTA y se arma la URL acá: el dominio cambia entre la
     * Mac y el VPS, y una URL guardada apuntaría al lugar equivocado el día
     * del despliegue.
     *
     * Comprueba que el archivo EXISTA. Un `<img>` roto en un contrato
     * impreso se ve peor que un contrato sin logo, y el archivo se puede
     * haber ido en una restauración de backup.
     */
    public function logoUrl(): ?string
    {
        $ruta = $this->getAttribute('logo_path');

        if (! is_string($ruta) || trim($ruta) === '') {
            return null;
        }

        $disco = Storage::disk('public');

        return $disco->exists($ruta) ? $disco->url($ruta) : null;
    }

    /**
     * Con qué papel cobra este desarrollo.
     *
     * Null hasta que alguien se la elija, y eso está bien: los proyectos
     * que ya existen siguieron funcionando igual el día que se agregó
     * esto. Varios proyectos pueden apuntar a la MISMA facturación —es la
     * forma de compartir un rango— pero solo cuando emiten desde la misma
     * oficina. Ver la migración `2026_08_13_230000`.
     *
     * @return BelongsTo<Facturacion, $this>
     */
    public function facturacion(): BelongsTo
    {
        return $this->belongsTo(Facturacion::class);
    }

    // ─── Donaciones ───────────────────────────────────────────────────

    /**
     * ¿Este desarrollo dona lotes, y cuántos le quedan por donar?
     *
     * Donar saca un lote del inventario sin que entre un lempira. El cupo
     * es la decisión escrita ANTES —cuántos se van a regalar— y lo que
     * hace que el botón desaparezca solo cuando se cumplió, en vez de
     * quedar disponible para siempre. Regla de Mauricio, 13-ago-2026.
     */
    public function donaLotes(): bool
    {
        return (bool) $this->getAttribute('dona_lotes');
    }

    public function cupoDeDonaciones(): int
    {
        return (int) $this->getAttribute('lotes_a_donar');
    }

    /**
     * Los lotes de este proyecto que YA se donaron.
     *
     * Cuenta el estado del lote y no los compromisos: un lote donado es
     * uno, y contar compromisos abriría la puerta a contar dos veces el
     * día que un lote se done, se deshaga y se vuelva a donar.
     */
    public function lotesDonados(): int
    {
        return Lote::query()
            ->where('proyecto_id', $this->getKey())
            ->where('estado', EstadoLote::Donado->value)
            ->count();
    }

    /**
     * Cuántas donaciones quedan por hacer. Nunca negativo: si alguien
     * bajó el cupo por debajo de lo ya entregado, quedan cero — lo hecho
     * no se deshace solo.
     */
    public function donacionesQueQuedan(): int
    {
        if (! $this->donaLotes()) {
            return 0;
        }

        return max(0, $this->cupoDeDonaciones() - $this->lotesDonados());
    }

    public function puedeDonarOtroLote(): bool
    {
        return $this->donacionesQueQuedan() > 0;
    }

    // ─── Herencia ─────────────────────────────────────────────────────

    /**
     * ¿Este desarrollo guarda lotes para la familia, y cuántos le quedan?
     *
     * El gemelo del cupo de donaciones y por la misma razón: un lote
     * reservado sale del mercado sin que entre un lempira, así que cuántos
     * se guardan es una decisión escrita ANTES —cuando se arma el
     * desarrollo— y no un botón encendido para siempre. Regla de Mauricio,
     * 13-ago-2026.
     *
     * ⚠️ La columna dice `reserva` y la pantalla dice «Herencia». Es a
     * propósito: el estado del lote se llama `reservado` en la base y en
     * la leyenda del plano público, donde esa palabra cierra la
     * conversación. Adentro se administra herencia. Ver
     * EstadoLote::etiquetaInterna().
     */
    public function reservaLotes(): bool
    {
        return (bool) $this->getAttribute('reserva_lotes');
    }

    public function cupoDeReservas(): int
    {
        return (int) $this->getAttribute('lotes_a_reservar');
    }

    /**
     * Los lotes de este proyecto que YA están guardados.
     *
     * Cuenta el estado del lote, igual que `lotesDonados()` y por el mismo
     * motivo: un lote guardado es uno, y no hay ninguna otra tabla que
     * pueda contarlo dos veces.
     */
    public function lotesReservados(): int
    {
        return Lote::query()
            ->where('proyecto_id', $this->getKey())
            ->where('estado', EstadoLote::Reservado->value)
            ->count();
    }

    /**
     * Cuántos lotes quedan por guardar. Nunca negativo: si alguien bajó el
     * cupo por debajo de lo ya guardado, quedan cero — lo hecho no se
     * deshace solo, se saca lote por lote desde el plano.
     */
    public function reservasQueQuedan(): int
    {
        if (! $this->reservaLotes()) {
            return 0;
        }

        return max(0, $this->cupoDeReservas() - $this->lotesReservados());
    }

    public function puedeReservarOtroLote(): bool
    {
        return $this->reservasQueQuedan() > 0;
    }

    /**
     * Cuantos lotes del proyecto dejaron de estar DISPONIBLES.
     *
     * Es la pregunta que frena el borrado, expuesta para que quien vaya a
     * borrar pueda hacerla ANTES en vez de provocar la excepcion y
     * atajarla. La excepcion sigue existiendo como ultima linea: por el
     * boton de Filament, por tinker, por lo que venga.
     */
    public function lotesConMovimiento(): int
    {
        return Lote::query()
            ->where('proyecto_id', $this->getKey())
            ->where('estado', '!=', EstadoLote::Disponible->value)
            ->count();
    }

    /**
     * @return HasMany<Bloque, $this>
     */
    public function bloques(): HasMany
    {
        return $this->hasMany(Bloque::class);
    }

    /**
     * Lotes del proyecto, sin pasar por bloques.
     *
     * `proyecto_id` está denormalizado en `lotes` a propósito (ADR-0002):
     * los reportes filtran por proyecto en cada consulta y hacerlo vía
     * bloques obligaría a un join en todas.
     *
     * @return HasMany<Lote, $this>
     */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /**
     * Los dueños del PROYECTO y la parte que le toca a cada uno.
     *
     * No son clientes: un cliente compra un lote, un socio puso el terreno o el
     * dinero. Ordenados de mayor a menor parte, que es como se lee un reparto.
     *
     * @return HasMany<Socio, $this>
     */
    public function socios(): HasMany
    {
        return $this->hasMany(Socio::class)->orderByDesc('porcentaje');
    }

    /**
     * Cuánto suman las partes de los socios activos.
     *
     * Tiene que dar 100. No lo puede garantizar un CHECK —mira una fila y esto
     * es la suma de todas— así que lo pregunta la pantalla y lo tendrá que
     * exigir el reparto: repartir con 90 deja 10 sin dueño.
     */
    public function partesDeLosSocios(): Monto
    {
        $total = Monto::cero();

        foreach ($this->socios()->activos()->get() as $socio) {
            $total = $total->sumar($socio->porcentaje());
        }

        return $total;
    }

    /**
     * ¿Las partes cierran en 100?
     *
     * Un proyecto SIN socios cargados también devuelve true: no es que el
     * reparto esté mal, es que no hay reparto. Avisar ahí sería pedirle a todo
     * el mundo que cargue socios que quizá no tenga.
     */
    public function elRepartoCierra(): bool
    {
        $total = $this->partesDeLosSocios();

        if ($total->esCero()) {
            return true;
        }

        return $total->igualA(new Monto('100'));
    }

    /**
     * El precio de la vara² a cada plazo (5-ago-2026).
     *
     * No es interes: R1 sigue en pie y el saldo no devenga nada. Es el
     * precio de lista, que a 48 meses no es el mismo que de contado.
     *
     * @return HasMany<PlanDePago, $this>
     */
    public function planesDePago(): HasMany
    {
        return $this->hasMany(PlanDePago::class);
    }

    /**
     * Lo que este desarrollo ha costado (11-ago-2026).
     *
     * Cuelga del proyecto y no del lote porque asi se gasta: la
     * retroexcavadora no entra a un lote, abre la calle de un bloque entero.
     * Repartir ese costo entre los lotes que toca es un prorrateo, y un
     * prorrateo es una decision de contabilidad, no un dato que alguien tenga
     * enfrente al pagar la factura.
     *
     * @return HasMany<Gasto, $this>
     */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }

    /**
     * @param Builder<Proyecto> $query
     *
     * @return Builder<Proyecto>
     */
    #[Scope]
    protected function activos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
