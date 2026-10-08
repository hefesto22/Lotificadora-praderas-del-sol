<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Archivos\GuardadoDeArchivos;
use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Database\Factories\GastoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Lo que costó el desarrollo, renglón por renglón.
 *
 * Es el segundo EGRESO del sistema, después de la devolución de la seña, y el
 * primero que es plata de la lotificadora y no del cliente. Contesta la
 * pregunta que hasta hoy no tenía respuesta en ningún lado: **cuánto me ha
 * costado este proyecto, y en qué**.
 *
 * ═══ POR QUE SI SE PUEDE EDITAR, SI UNA DEVOLUCION NO ═══
 *
 * Una devolución la firma el cliente y se lleva el papel; corregirla sería
 * cambiar lo que dice un documento que ya está afuera. Un gasto es un asiento
 * interno: el respaldo es la factura del proveedor, que no cambia porque acá
 * se arregle un monto mal tecleado.
 *
 * Se puede editar y borrar, **pero solo la administradora** —quien está en la
 * ventanilla no ve siquiera la pestaña— y **todo queda en la bitácora**. Esa
 * es la garantía real: no que el número sea inmutable, sino que se sepa quién
 * lo cambió y cuándo.
 *
 * ═══ EL NUMERO NO SE PONE A MANO ═══
 *
 * `numero` lo consume `RegistroDeGastos` dentro de una transacción, con
 * `ConsumoDeCorrelativos`. Está en el `#[Fillable]` porque el servicio lo
 * asigna por ahí, no porque un formulario deba mandarlo — ninguno lo pide.
 *
 * ═══ EL NOMBRE DE LA TABLA NO HACE FALTA DECLARARLO ═══
 *
 * A diferencia de `Devolucion` y `Reprogramacion`, el pluralizador inglés
 * saca `gastos` de `Gasto` sin ayuda.
 *
 * ═══ A QUIEN SE LE CARGA (8-OCT-2026) ═══
 *
 * `socio_id` en NULL: el gasto es del proyecto y lo pagan todos los socios
 * según su parte — lo de siempre. Con un socio: lo paga SOLO él. No baja la
 * utilidad que se reparte, y en el Estado mensual se le descuenta a ese socio
 * de lo que le toca, igual que un adelanto (regla de Mauricio, 8-oct).
 *
 * Que el socio sea del mismo proyecto no lo cuida este modelo: lo garantiza la
 * FK compuesta `gastos_socio_del_mismo_proyecto_fk`.
 */
#[Fillable([
    'numero',
    'proyecto_id',
    'socio_id',
    'categoria',
    'descripcion',
    'beneficiario',
    'factura',
    'monto',
    'forma_pago',
    'referencia',
    'fecha',
    'archivo',
    'archivo_bytes',
])]
class Gasto extends Model
{
    use HasAuditFields;

    /** @use HasFactory<GastoFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * El disco del comprobante escaneado.
     *
     * Privado, igual que `Documento::DISCO`: una factura trae el nombre del
     * proveedor, su RTN y montos que no son públicos, y una URL pública se
     * filtra sola —se pega en un chat, queda en el historial, viaja en una
     * captura—. Que el nombre del archivo sea imposible de adivinar no es
     * seguridad, es suerte.
     */
    public const string DISCO = 'local';

    /**
     * El peso del comprobante lo dice el DISCO, no el navegador.
     *
     * El tamaño que reporta el archivo subido es el del original; después de
     * la conversión a WebP ese número sobra por seis, y una columna que miente
     * es peor que una vacía. Se pregunta acá y no en el formulario para que
     * valga también al editar, al importar y desde tinker.
     *
     * Si el archivo no está —una ruta inventada, un disco falso de un test—,
     * NO se toca lo que venga: `pesoEnDisco()` devuelve `null` y este hook se
     * hace a un lado.
     */
    #[Override]
    protected static function booted(): void
    {
        static::saving(function (self $gasto): void {
            if (! $gasto->isDirty('archivo')) {
                return;
            }

            $peso = GuardadoDeArchivos::pesoEnDisco(self::DISCO, $gasto->getAttribute('archivo'));

            if ($peso === null) {
                return;
            }

            $gasto->setAttribute('archivo_bytes', $peso);
        });
    }

    /**
     * ⚠️ `monto` NO se castea a `decimal:2`: ese cast pasa por
     * `number_format()`, que recibe float (§8.3.1). PDO de Postgres ya entrega
     * NUMERIC como string, que es lo que consume bcmath.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'categoria'  => CategoriaDeGasto::class,
            'forma_pago' => FormaDePago::class,
            'fecha'      => 'date',
        ];
    }

    /**
     * Se registra TODO lo que mueve el número o el motivo.
     *
     * Es la contrapartida de dejarlo editable: la bitácora es lo que hace que
     * un gasto corregido siga siendo auditable.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['numero', 'socio_id', 'categoria', 'descripcion', 'beneficiario', 'factura',
                'monto', 'forma_pago', 'referencia', 'fecha', 'archivo'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Gasto {$evento}");
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
     * El socio al que se le carga. NULL es «al proyecto».
     *
     * `withTrashed()` a propósito: un socio que salió del repetidor queda
     * borrado con SoftDeletes, pero el gasto que se le cargó sigue siendo
     * suyo. Sin esto la tabla diría «Al proyecto» de un gasto que no lo es.
     *
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class)->withTrashed();
    }

    // ─── Consultas ────────────────────────────────────────────────────

    /**
     * Los gastos del PROYECTO: los que pagan todos los socios según su parte.
     *
     * Es lo que baja la utilidad del mes y lo que el tablero cuenta como
     * invertido. Un gasto cargado a un socio no es costo del desarrollo: es
     * plata de ese socio, como una entrega.
     *
     * @param Builder<Gasto> $query
     *
     * @return Builder<Gasto>
     */
    #[Scope]
    protected function comunes(Builder $query): Builder
    {
        return $query->whereNull('gastos.socio_id');
    }

    /**
     * Los que se le cargaron a un socio en particular.
     *
     * @param Builder<Gasto> $query
     *
     * @return Builder<Gasto>
     */
    #[Scope]
    protected function cargadosASocio(Builder $query): Builder
    {
        return $query->whereNotNull('gastos.socio_id');
    }

    /**
     * ¿Este gasto se le carga a un socio y no al proyecto?
     */
    public function esDeUnSocio(): bool
    {
        return $this->getAttribute('socio_id') !== null;
    }

    // ─── El dinero ────────────────────────────────────────────────────

    public function monto(): Monto
    {
        $valor = $this->getAttribute('monto');

        return new Monto(is_string($valor) || is_int($valor) ? (string) $valor : '0');
    }

    // ─── Como se lee ──────────────────────────────────────────────────

    /**
     * El número como se escribe en el papel: `G-000001`.
     *
     * La `G` cumple la misma función que la `D` de una devolución: en un
     * archivador donde ya hay recibos con el mismo ancho de dígitos, la letra
     * es lo único que a simple vista dice qué clase de papel es.
     */
    public function folio(): string
    {
        return 'G-'.str_pad((string) $this->getAttribute('numero'), 6, '0', STR_PAD_LEFT);
    }

    public function tieneComprobante(): bool
    {
        $archivo = $this->getAttribute('archivo');

        return is_string($archivo) && trim($archivo) !== '';
    }

    /**
     * Con qué nombre se baja el comprobante.
     *
     * Con el folio adelante: quien descarga veinte facturas para el contador
     * necesita que se ordenen solas y que cada una se pueda buscar en el
     * sistema por el nombre del archivo.
     */
    public function nombreDeDescarga(): string
    {
        $archivo = (string) $this->getAttribute('archivo');
        $extension = pathinfo($archivo, PATHINFO_EXTENSION);

        $base = $this->folio().'-'.str((string) $this->getAttribute('descripcion'))->slug()->limit(40, '')->toString();

        return $extension === '' ? $base : $base.'.'.$extension;
    }

    /**
     * El tamaño del comprobante, como se lee.
     */
    public function peso(): string
    {
        $bytes = (int) $this->getAttribute('archivo_bytes');

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
