<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoDocumento;
use App\Domain\ValueObjects\Monto;
use App\Traits\HasAuditFields;
use Database\Factories\ReciboFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * El documento que se le entrega al cliente cuando paga.
 *
 * Puede ser de dos clases, y lo dice `tipo_documento`:
 *
 *  - **Recibo interno.** Sin CAI (R10). Lo que lo hace serio no es el papel
 *    sino su número —uno solo para toda la lotificadora (R12)— y su detalle
 *    de aplicación, que dice a qué cuota le tocó cada lempira.
 *  - **Factura con CAI.** Desde el 14-ago-2026. Lleva ADEMÁS el número de
 *    dieciséis dígitos del SAR, con la CAI, el rango autorizado y la fecha
 *    límite de emisión copiados de la autorización con la que salió.
 *
 * ⚠️ Una factura consume las DOS series. El número interno no se saltea: es
 * el que cuadra la caja, y una serie con huecos deja de servir para eso. Ver
 * la migración `2026_08_14_090000_la_factura_toma_el_rango`.
 *
 * ═══ NO SE EDITA ═══
 *
 * Un recibo entregado no se corrige: se anula y se emite otro. Cambiar el
 * monto de uno ya impreso es dejar el papel del cliente diciendo una cosa y la
 * base diciendo otra, que es exactamente el problema que un correlativo viene
 * a evitar.
 */
#[Fillable([
    'numero',
    'tipo_documento',
    'facturacion_id',
    'autorizacion_id',
    'numero_factura',
    'correlativo_factura',
    'cai',
    'rango_desde',
    'rango_hasta',
    'fecha_limite_emision',
    'venta_id',
    'compromiso_id',
    'cliente_id',
    'a_nombre_de',
    'a_nombre_de_dni',
    'concepto',
    'forma_pago',
    'referencia',
    'monto',
    'fecha',
    'observaciones',
    'monto_mora',
    'mora_condonada',
    'motivo_condonacion',
    'condonada_por',
    'anulado_el',
    'anulado_por',
    'motivo_anulacion',
])]
class Recibo extends Model
{
    use HasAuditFields;

    /** @use HasFactory<ReciboFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Los defaults en memoria, no solo en la base.
     *
     * ⚠️ Sin esto, un recibo recién construido no trae tipo de documento y la
     * primera edición registra en el ActivityLog un cambio que nadie hizo. Ya
     * mordió cuatro veces en este repo con otras columnas.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'tipo_documento' => 'recibo_interno',
    ];

    /**
     * Sin cast `decimal:x` en los montos: pasa por `number_format()`, que
     * recibe float (§8.3.1).
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'concepto'             => ConceptoDeRecibo::class,
            'forma_pago'           => FormaDePago::class,
            'tipo_documento'       => TipoDocumento::class,
            'fecha'                => 'date',
            'fecha_limite_emision' => 'date',
            'anulado_el'           => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['numero', 'tipo_documento', 'numero_factura', 'concepto', 'forma_pago', 'referencia',
                'monto', 'fecha', 'cliente_id', 'anulado_el', 'anulado_por', 'motivo_anulacion'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Recibo {$evento}");
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * El lote al que se le abonó.
     *
     * Con plazos distintos por lote, un pago va contra UNO: el plan de cuotas
     * es del renglón del contrato, no del expediente.
     *
     * @return BelongsTo<Compromiso, $this>
     */
    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    /**
     * Quién anuló este recibo.
     *
     * @return BelongsTo<User, $this>
     */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    /**
     * El titular del expediente: de quien es el contrato.
     *
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * ¿Este recibo sale a nombre de alguien que no firmo el contrato?
     */
    public function esANombreDeOtro(): bool
    {
        return $this->textoDe('a_nombre_de') !== null;
    }

    /**
     * El DNI del titular del recibo, si se cargo.
     *
     * Opcional a proposito: un representado puede no tenerlo a mano el dia que
     * paga, y eso no puede impedir que se lleve su papel.
     */
    public function dniDelPapel(): ?string
    {
        return $this->textoDe('a_nombre_de_dni');
    }

    /**
     * El nombre que va impreso en el «Recibi de».
     *
     * Vive en el modelo y no en la plantilla porque lo preguntan el papel, la
     * tabla de recibos y el estado de cuenta. Tres lugares que tienen que decir
     * lo mismo terminan no diciendolo si cada uno arma la frase.
     */
    public function nombreDelPapel(): string
    {
        $otro = $this->textoDe('a_nombre_de');

        if ($otro !== null) {
            return $otro;
        }

        $titular = $this->cliente?->getAttribute('nombre');

        return is_string($titular) && trim($titular) !== '' ? $titular : '—';
    }

    /**
     * Un campo de texto del recibo, o null si esta vacio.
     *
     * Los CHECK de la base ya impiden guardar una cadena en blanco, pero un
     * modelo recien construido en memoria todavia no paso por ahi.
     */
    private function textoDe(string $campo): ?string
    {
        $valor = $this->getAttribute($campo);

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /**
     * A qué cuotas se repartió, en el orden en que se aplicaron.
     *
     * @return HasMany<AplicacionDePago, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionDePago::class);
    }

    /**
     * Cada vez que este recibo salió impreso, de la más vieja a la más nueva.
     *
     * La primera es el original; de la segunda en adelante el papel lleva la
     * marca COPIA. Dos papeles con el mismo número no pueden hacerse pasar por
     * dos cobros distintos, que es lo que un correlativo viene a evitar.
     *
     * @return HasMany<ImpresionDeRecibo, $this>
     */
    public function impresiones(): HasMany
    {
        return $this->hasMany(ImpresionDeRecibo::class)->oldest();
    }

    /**
     * ¿Ya salió impreso alguna vez?
     */
    public function yaSeImprimio(): bool
    {
        return $this->impresiones()->exists();
    }

    public function vecesImpreso(): int
    {
        return $this->impresiones()->count();
    }

    /**
     * Las reprogramaciones que este abono provocó (R21).
     *
     * Vacía en la enorme mayoría de los recibos: solo un abono a capital
     * reescribe un plan. Cuando hay, es lo que contesta «¿por qué después de
     * este pago mi cuota cambió?».
     *
     * ═══ POR QUE PLURAL, DESDE EL 10-AGO-2026 ═══
     *
     * Era `hasOne` porque un abono iba contra UN lote. Desde que se puede
     * repartir un abono entre varios lotes del mismo contrato —L 20,000.00 al
     * lote 1 y L 10,000.00 al lote 2, en un solo trámite— el recibo lleva una
     * constancia POR LOTE: cada plan que se reescribe deja la suya, con su
     * modalidad, su saldo anterior y el plan viejo completo.
     *
     * Una sola constancia sumada no serviría: el CHECK
     * `reprogramaciones_saldo_cuadra_chk` verifica por fila, y «¿en qué quedó
     * el lote 2?» necesita los números del lote 2, no un total.
     *
     * La base no hizo falta tocarla: `reprogramaciones.recibo_id` nunca tuvo
     * un unique.
     *
     * @return HasMany<Reprogramacion, $this>
     */
    public function reprogramaciones(): HasMany
    {
        return $this->hasMany(Reprogramacion::class);
    }

    // ─── Anulación ────────────────────────────────────────────────────

    /**
     * ¿Este recibo dejó de valer?
     *
     * Un recibo anulado NO desaparece: conserva su número —la serie no puede
     * tener huecos (R12)— y conserva sus aplicaciones, que son la traza de a
     * qué se había aplicado. Lo que se revierte es `cuotas.monto_pagado`, que
     * es de donde sale el saldo.
     *
     * ⚠️ Todo lo que sume DINERO desde `recibos` —un corte de caja, un
     * reporte de cobros— tiene que filtrar `anulado_el IS NULL`. El saldo del
     * cliente no hace falta que lo filtre, porque no se calcula desde acá.
     */
    public function estaAnulado(): bool
    {
        return $this->getAttribute('anulado_el') !== null;
    }

    // ─── Los lotes ────────────────────────────────────────────────────

    /**
     * Los lotes que este recibo tocó, por código y sin repetir.
     *
     * `compromiso_id` es la respuesta rápida, y es lo que hay en la enorme
     * mayoría de los recibos. Cuando está en NULL el cobro fue de varios lotes
     * del mismo contrato, y entonces la verdad son las aplicaciones: cada una
     * apunta a una cuota, y cada cuota a su lote.
     *
     * Un recibo de prima no toca ninguno y devuelve la lista vacía — la
     * pantalla muestra su guion.
     *
     * @return list<string>
     */
    public function codigosDeLotes(): array
    {
        $delRecibo = $this->compromiso?->lote?->getAttribute('codigo');

        if (is_string($delRecibo)) {
            return [$delRecibo];
        }

        $codigos = [];

        foreach ($this->aplicaciones as $aplicacion) {
            $codigo = $aplicacion->cuota?->compromiso?->lote?->getAttribute('codigo');

            if (is_string($codigo) && ! in_array($codigo, $codigos, true)) {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }

    /**
     * Los lotes como se leen en el papel.
     */
    public function rotuloDeLotes(): string
    {
        $codigos = $this->codigosDeLotes();

        return $codigos === [] ? '—' : implode(' · ', $codigos);
    }

    public function tocaVariosLotes(): bool
    {
        return count($this->codigosDeLotes()) > 1;
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    public function montoTotal(): Monto
    {
        $monto = $this->getAttribute('monto');

        return new Monto(is_string($monto) || is_int($monto) ? $monto : '0');
    }

    /**
     * Lo que este recibo aplicó a cuotas.
     */
    public function montoAplicadoACuotas(): Monto
    {
        $total = Monto::cero();

        foreach ($this->aplicaciones as $aplicacion) {
            $total = $total->sumar($aplicacion->montoAplicado());
        }

        return $total;
    }

    /**
     * De lo que este recibo le aplicó a las cuotas, cuánto fue interés.
     *
     * Sale de los RENGLONES y no de la cuota: el recibo acredita este pago, y
     * la cuota puede traer encima plata de otro recibo. Preguntarle a la
     * cuota daría el acumulado, que en un papel que dice «recibí de usted»
     * sería un número que el cliente no entregó hoy.
     */
    public function interesDeCuotas(): Monto
    {
        $total = Monto::cero();

        foreach ($this->aplicaciones as $aplicacion) {
            $total = $total->sumar($aplicacion->montoInteres());
        }

        return $total;
    }

    /**
     * Y cuánto fue capital. ⚠️ No confundir con `montoACapital()`, que es el
     * abono extra del R21: este es el capital que venía adentro de la cuota.
     */
    public function capitalDeCuotas(): Monto
    {
        $total = Monto::cero();

        foreach ($this->aplicaciones as $aplicacion) {
            $total = $total->sumar($aplicacion->montoCapital());
        }

        return $total;
    }

    public function cobroInteres(): bool
    {
        return ! $this->interesDeCuotas()->esCero();
    }

    /**
     * Lo que este recibo bajó del capital, sin pasar por ninguna cuota (R21).
     *
     * En un cobro normal es cero: todo el dinero se repartió entre cuotas. En
     * un abono a capital es la diferencia, porque el mismo papel puede haber
     * puesto al día lo vencido y bajado el saldo con el sobrante. Los dos
     * renglones tienen que verse impresos, o el cliente no entiende por qué
     * pagó L 100,000.00 y sus cuotas solo bajaron L 50,000.00.
     */
    public function montoACapital(): Monto
    {
        return $this->montoTotal()->restar($this->montoAplicadoACuotas());
    }

    /**
     * El número, como se lee en el papel.
     */
    public function folio(): string
    {
        return str_pad((string) $this->getAttribute('numero'), 6, '0', STR_PAD_LEFT);
    }

    // ─── Factura con CAI ──────────────────────────────────────────────

    /**
     * Qué clase de papel es este. Nunca null: la columna tiene default y CHECK.
     *
     * Se lee por acá y no por el atributo crudo para que un recibo recién
     * construido con `new Recibo` —sin pasar por la base— también conteste.
     */
    public function tipoDeDocumento(): TipoDocumento
    {
        $tipo = $this->getAttribute('tipo_documento');

        return $tipo instanceof TipoDocumento ? $tipo : TipoDocumento::ReciboInterno;
    }

    public function esFactura(): bool
    {
        return $this->tipoDeDocumento() === TipoDocumento::Factura;
    }

    /**
     * El número GRANDE del papel.
     *
     * En una factura es el de dieciséis dígitos, que es el que existe para el
     * SAR. En un recibo interno es el folio de siempre. El otro número no
     * desaparece: la factura también imprime su folio, abajo y chiquito, como
     * control interno — es el que cuadra la caja.
     */
    public function numeroDelPapel(): string
    {
        $factura = $this->getAttribute('numero_factura');

        return is_string($factura) && trim($factura) !== '' ? trim($factura) : $this->folio();
    }

    /**
     * El rango autorizado, como va impreso.
     *
     * Acuerdo 481-2017, Art. 10: la factura tiene que decir entre qué números
     * está autorizada a moverse. Con los ocho dígitos, porque los ceros de
     * adelante son parte del número.
     */
    public function rangoAutorizado(): ?string
    {
        $desde = $this->getAttribute('rango_desde');
        $hasta = $this->getAttribute('rango_hasta');

        if (! is_int($desde) || ! is_int($hasta)) {
            return null;
        }

        return sprintf('%08d al %08d', $desde, $hasta);
    }

    /**
     * El RTN o la identidad de quien se lleva el papel.
     *
     * La factura los pide (Art. 10): sin uno de los dos, el documento no
     * identifica al adquiriente. Se prefiere el RTN porque es lo que sirve
     * para crédito fiscal; el DNI es el respaldo de quien no tiene RTN, que
     * es la mayoría de los compradores de lote.
     *
     * Cuando el recibo sale a nombre de un representado se usa el documento
     * que se cargó en ventanilla —el de esa persona—, no el del titular del
     * expediente: el papel dice «recibí de» esa persona.
     */
    public function identidadDelPapel(): ?string
    {
        $propio = $this->dniDelPapel();

        if ($propio !== null || $this->esANombreDeOtro()) {
            return $propio;
        }

        foreach (['rtn', 'dni'] as $columna) {
            $valor = $this->cliente?->getAttribute($columna);

            if (is_string($valor) && trim($valor) !== '') {
                return trim($valor);
            }
        }

        return null;
    }

    /**
     * El desglose del impuesto, como va impreso en la factura.
     *
     * ═══ 🔴 ESTO LO TIENE QUE CONFIRMAR UN CONTADOR ═══
     *
     * El Art. 10 pide que la factura separe importe exento, exonerado,
     * gravado al 15%, gravado al 18% e ISV. Acá va TODO a exento y el ISV en
     * cero, y la razón es que lo que se vende es TIERRA: la transferencia de
     * bienes inmuebles no está sujeta al ISV, que grava bienes muebles y
     * servicios. Los intereses del financiamiento tampoco.
     *
     * Eso es lo que dice la ley leída de frente, y es lo que hace hoy el
     * papel. Pero no es una opinión que este archivo pueda firmar: si el
     * contador de la lotificadora dice que alguna parte del cobro —una mora,
     * un gasto administrativo— sí grava, se cambia ACÁ, en un solo lugar, y
     * el papel entero se acomoda. Por eso el desglose vive en el modelo y no
     * repartido en la plantilla.
     *
     * @return array<string, Monto>
     */
    public function desgloseFiscal(): array
    {
        return [
            'Importe exento'      => $this->montoTotal(),
            'Importe gravado 15%' => Monto::cero(),
            'Importe gravado 18%' => Monto::cero(),
            'I.S.V.'              => Monto::cero(),
        ];
    }

    /**
     * Con qué facturación salió esta. Null en un recibo interno.
     *
     * @return BelongsTo<Facturacion, $this>
     */
    public function facturacion(): BelongsTo
    {
        return $this->belongsTo(Facturacion::class);
    }

    /**
     * Con qué autorización del SAR se numeró.
     *
     * ⚠️ No sirve para armar el papel —eso sale de las columnas congeladas—
     * sino para contestar al revés: «¿qué facturas emitimos con esta CAI?».
     *
     * @return BelongsTo<AutorizacionDeImpresion, $this>
     */
    public function autorizacion(): BelongsTo
    {
        return $this->belongsTo(AutorizacionDeImpresion::class, 'autorizacion_id');
    }

    // ─── Mora ─────────────────────────────────────────────────────────

    /**
     * La mora que entro con este recibo. Ya esta adentro de `monto`: no se
     * suma aparte, se DESGLOSA — el papel dice «de los L 15,000, L 287.67
     * fueron mora».
     */
    public function montoMora(): Monto
    {
        return $this->montoDeColumna('monto_mora');
    }

    /**
     * La mora que se perdono en este cobro. NO esta adentro de `monto`:
     * nunca entro por la puerta.
     */
    public function moraCondonada(): Monto
    {
        return $this->montoDeColumna('mora_condonada');
    }

    public function cobroMora(): bool
    {
        return ! $this->montoMora()->esCero();
    }

    public function condonoMora(): bool
    {
        return ! $this->moraCondonada()->esCero();
    }

    /**
     * Lo que se aplico al contrato: el monto sin la mora.
     *
     * Es el numero que baja la deuda, y el que el cliente busca cuando
     * compara el recibo contra su estado de cuenta.
     */
    public function montoAlContrato(): Monto
    {
        return $this->montoTotal()->restar($this->montoMora());
    }

    private function montoDeColumna(string $columna): Monto
    {
        $valor = $this->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }
}
