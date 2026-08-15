<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoDeDevolucion;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * El comprobante de que salió dinero (R14).
 *
 * Es el primer EGRESO de Olympo. Hasta el 10-ago-2026 el sistema solo sabía
 * de dinero entrando: había recibos y no había papel de salida, y la seña de
 * un apartado que se caía quedaba con una fecha de «devuelta» que no decía
 * cuánto ni dejaba nada firmado.
 *
 * Contesta tres preguntas que antes no tenían respuesta: cuánto se le
 * devolvió al cliente, cuánto quedó a favor del proyecto, y por qué.
 *
 * ═══ NO SE EDITA NI SE BORRA ═══
 *
 * Es historia, igual que un recibo. Si una devolución se hizo mal, la
 * corrección es otro movimiento con su motivo, no cambiarle el monto a esta:
 * el cliente ya se fue con un papel en la mano que dice otra cosa.
 *
 * ═══ LOS TRES MONTOS, Y POR QUE SE GUARDA EL RETENIDO ═══
 *
 * `monto_retenido` es `recibido − devuelto` y se podría calcular. Se guarda
 * igual, y la base obliga a que cuadre (`devoluciones_cuadra_chk`), por la
 * misma razón que en `reprogramaciones`: el comprobante que el cliente firmó
 * dice tres números, y tienen que seguir diciendo lo mismo dentro de cinco
 * años aunque alguien cambie una fórmula.
 *
 * ═══ CUELGA DE UN APARTADO O DE UNA VENTA ═══
 *
 * Las dos cosas, desde el 14-ago-2026: una devolución de seña trae solo el
 * compromiso del apartado; una **rescisión** (R22) trae el compromiso del
 * lote que se cayó Y la venta de la que salió. El compromiso es obligatorio
 * en los dos casos —la plata siempre entró por un lote— y `tipo` dice cuál de
 * los dos trámites fue, porque el título de un papel que el cliente firma no
 * debería depender de si una llave vino nula.
 *
 * ═══ EL NOMBRE DE LA TABLA VA ESCRITO ═══
 *
 * El pluralizador de Laravel es inglés y de `Devolucion` saca `devolucions`.
 * Igual que `Reprogramacion` y `AplicacionDePago`, se declara y no se adivina.
 */
#[Fillable([
    'numero',
    'tipo',
    'compromiso_id',
    'venta_id',
    'cliente_id',
    'recibo_id',
    'monto_recibido',
    'monto_devuelto',
    'monto_retenido',
    'forma_pago',
    'referencia',
    'motivo',
    'fecha',
])]
#[Table(name: 'devoluciones')]
class Devolucion extends Model
{
    use HasAuditFields;

    /**
     * El default en memoria, no solo en la base.
     *
     * ⚠️ Sin esto, una devolución recién construida no trae tipo y el papel
     * no sabría cómo titularse antes del primer `save()`. Es la misma cicatriz
     * que `tipo_documento` en `Recibo`.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'tipo' => 'senia',
    ];

    /**
     * Los montos NO se castean a `decimal:x`: ese cast pasa por
     * `number_format()`, que recibe float (§8.3.1). PDO de Postgres ya
     * entrega NUMERIC como string, que es lo que consume bcmath.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'tipo'       => TipoDeDevolucion::class,
            'forma_pago' => FormaDePago::class,
            'fecha'      => 'date',
        ];
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * @return BelongsTo<Compromiso, $this>
     */
    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * El recibo por el que ese dinero había entrado.
     *
     * @return BelongsTo<Recibo, $this>
     */
    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class);
    }

    // ─── El dinero ────────────────────────────────────────────────────

    public function montoRecibido(): Monto
    {
        return $this->monto('monto_recibido');
    }

    public function montoDevuelto(): Monto
    {
        return $this->monto('monto_devuelto');
    }

    /**
     * Lo que quedó a favor del proyecto.
     */
    public function montoRetenido(): Monto
    {
        return $this->monto('monto_retenido');
    }

    /**
     * ¿Se le devolvió todo lo que había entregado?
     *
     * Es la pregunta que separa las dos frases del comprobante: «se le
     * devolvió su seña» y «se le devolvió una parte».
     */
    public function fueTotal(): bool
    {
        return $this->montoRetenido()->esCero();
    }

    /**
     * El número como se escribe en el papel: `D-000001`.
     *
     * La `D` no es decoración: en un archivador donde ya hay recibos con el
     * mismo ancho de dígitos, es lo único que a simple vista distingue un
     * papel de entrada de uno de salida.
     */
    public function folio(): string
    {
        return 'D-'.str_pad((string) $this->getAttribute('numero'), 6, '0', STR_PAD_LEFT);
    }

    private function monto(string $columna): Monto
    {
        $valor = $this->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? (string) $valor : '0');
    }

    // ─── Qué trámite fue ──────────────────────────────────────────────

    /**
     * El tipo, ya como enum y nunca nulo.
     *
     * Las filas anteriores al 14-ago-2026 son todas devoluciones de seña —era
     * lo único que el sistema sabía hacer— y la migración las dejó rotuladas
     * así. Este método es la red por si alguna llega de otro lado.
     */
    public function tipoDeDevolucion(): TipoDeDevolucion
    {
        $tipo = $this->getAttribute('tipo');

        return $tipo instanceof TipoDeDevolucion ? $tipo : TipoDeDevolucion::Senia;
    }

    public function esRescision(): bool
    {
        return $this->tipoDeDevolucion() === TipoDeDevolucion::Rescision;
    }
}
