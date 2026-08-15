<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Una facturación: bajo qué autorización del SAR emite un desarrollo.
 *
 * SIEMPRE es factura con CAI. El recibo interno no pasa por acá —lo dice el
 * proyecto, que simplemente no apunta a ninguna facturación—, y eso lo
 * enderezó Mauricio el 14-ago-2026. Ver la migración
 * `2026_08_14_070000_la_facturacion_es_solo_de_cai`.
 *
 * Lo pidió Mauricio el 13-ago-2026. Existe por su cuenta —y no como
 * columnas de `proyectos`— justamente para que se pueda COMPARTIR: dos
 * desarrollos que emiten desde la misma oficina apuntan a la misma
 * facturación y usan el mismo rango sin que haya nada que sincronizar.
 *
 * ⚠️ Que se pueda compartir no quiere decir que siempre se deba. Lo
 * decide dónde se EMITE la factura, no dónde está el terreno: el SAR
 * autoriza el rango por punto de emisión y el código del establecimiento
 * va adentro del número. Está explicado largo en la migración
 * `2026_08_13_230000_facturacion_por_proyecto`.
 */
#[Fillable([
    'nombre',
    'activa',
    'emite_notas_credito',
    'rtn',
    'razon_social',
    'nombre_comercial',
    'direccion_casa_matriz',
    'direccion_establecimiento',
    'telefono',
    'correo',
    'codigo_establecimiento',
    'codigo_punto_emision',
    'codigo_documento',
    'imprenta_nombre',
    'imprenta_rtn',
    'imprenta_certificado',
    'observaciones',
])]
#[Table(name: 'facturaciones')]
class Facturacion extends Model
{
    use HasAuditFields;
    use LogsActivity;

    /**
     * Los defaults en memoria, no solo en la base.
     *
     * ⚠️ Sin esto, un modelo recién creado no trae el modo y la primera
     * edición registra en el ActivityLog un cambio que nadie hizo. Ya
     * mordió tres veces en este repo con otras columnas.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'activa'           => true,
        'codigo_documento' => '01',
    ];

    /**
     * @return HasMany<AutorizacionDeImpresion, $this>
     */
    public function autorizaciones(): HasMany
    {
        return $this->hasMany(AutorizacionDeImpresion::class)
            ->orderByDesc('fecha_limite_emision');
    }

    /**
     * @return HasMany<Proyecto, $this>
     */
    public function proyectos(): HasMany
    {
        return $this->hasMany(Proyecto::class);
    }

    /**
     * La autorización con la que se emite HOY.
     *
     * La que sirve —no vencida y con números por delante— y de esas, la
     * que vence primero: se usa la más vieja antes de estrenar la nueva,
     * porque los correlativos que sobran al vencerse se pierden.
     *
     * Null cuando no hay ninguna vigente: o se vencieron todas, o se
     * agotaron los rangos. En los dos casos, hoy no se puede emitir.
     */
    public function autorizacionVigente(): ?AutorizacionDeImpresion
    {
        foreach ($this->autorizaciones()->reorder()->orderBy('fecha_limite_emision')->get() as $autorizacion) {
            if ($autorizacion->sirveHoy()) {
                return $autorizacion;
            }
        }

        return null;
    }

    /**
     * ¿Se puede emitir un documento con esta facturación ahora mismo?
     *
     * Hace falta una autorización que sirva: no vencida y con números por
     * delante. Sin eso no hay con qué numerar el papel.
     */
    public function puedeEmitir(): bool
    {
        return (bool) $this->getAttribute('activa')
            && $this->autorizacionVigente() instanceof AutorizacionDeImpresion;
    }

    /**
     * El membrete: quién emite, tal como sale impreso arriba del papel.
     *
     * Sirve para los DOS modos. Hasta el 14-ago-2026 estos datos vivían en
     * `config/lotificadora.php` —uno solo para toda la instalación— y con
     * dos urbanizaciones eso dejó de alcanzar: cada una tiene su nombre,
     * sus teléfonos y su dirección impresos en su propio talonario. Lo
     * pidió Mauricio mandando la foto del de Praderas.
     *
     * ⚠️ Devuelve las MISMAS claves que devolvía la config —`nombre`,
     * `rtn`, `residencial`, `direccion`, `telefono`— a propósito: así las
     * vistas del recibo y del estado de cuenta no se tocan, y un proyecto
     * sin facturación elegida sigue saliendo con lo de la config como
     * hasta ayer.
     *
     * `residencial` es la línea grande de arriba y sale del nombre
     * comercial —que es el del desarrollo—; si no hay, cae en la razón
     * social. `direccion` prefiere la del ESTABLECIMIENTO: es la del lugar
     * desde donde se emite, que es lo que el cliente necesita para ir a
     * pagar.
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
            'nombre'      => $texto('razon_social'),
            'rtn'         => $texto('rtn'),
            'residencial' => $texto('nombre_comercial') ?? $texto('razon_social') ?? $texto('nombre'),
            'direccion'   => $texto('direccion_establecimiento') ?? $texto('direccion_casa_matriz'),
            'telefono'    => $texto('telefono'),
        ];
    }

    /**
     * El número completo, con sus 16 dígitos: `NNN-NNN-NN-NNNNNNNN`.
     *
     * Establecimiento, punto de emisión, tipo de documento y correlativo
     * (Acuerdo 481-2017, Art. 10, num. 7). Los ceros de adelante son parte
     * del número, no relleno de pantalla: el establecimiento 001 no es
     * el 1.
     *
     * ⚠️ Son DIECISÉIS. El formato de catorce que se ve en sistemas viejos
     * es del Acuerdo 189-2014, que está derogado.
     */
    public function numeroCompleto(int $correlativo): string
    {
        return sprintf(
            '%s-%s-%s-%08d',
            $this->codigoDe('codigo_establecimiento'),
            $this->codigoDe('codigo_punto_emision'),
            $this->codigoDe('codigo_documento'),
            $correlativo,
        );
    }

    private function codigoDe(string $columna): string
    {
        $valor = $this->getAttribute($columna);

        return is_string($valor) && $valor !== '' ? $valor : '000';
    }

    /**
     * El RTN se guarda en dígitos limpios, como el DNI del cliente.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function rtn(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $valor): ?string => filled($valor)
                ? (preg_replace('/\D/', '', $valor) ?: null)
                : null,
        );
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activa'              => 'boolean',
            'emite_notas_credito' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'activa', 'emite_notas_credito', 'rtn', 'razon_social', 'codigo_establecimiento', 'codigo_punto_emision', 'codigo_documento'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Facturacion {$evento}");
    }

    /**
     * ¿Esta lotificadora puede emitir notas de crédito?
     *
     * Es un permiso del SAR aparte del de facturar —CAI propio y rango
     * propio— y la mayoría no lo tiene. Apagado no bloquea nada: lo único que
     * cambia es lo que dice el acta de una rescisión con devolución.
     */
    public function emiteNotasDeCredito(): bool
    {
        return (bool) $this->getAttribute('emite_notas_credito');
    }
}
