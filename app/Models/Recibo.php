<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoCompromiso;
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
use Illuminate\Support\Facades\Auth;
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
    'serie',
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
    'recibido_por',
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

    /**
     * Quién recibió el dinero, lo pregunte o no el camino que emitió el papel.
     *
     * ═══ 🔴 LO QUE LLENA UN SOLO CAMINO SE OLVIDA EN TODOS LOS DEMÁS ═══
     *
     * `recibido_por` nació el 27-ago-2026 en el modal de cobro, que sí lo
     * pregunta. Los otros dos caminos que emiten recibos —la PRIMA de una
     * venta y la SEÑA de un apartado— nunca lo escribieron, así que sus
     * papeles quedaban sin dueño: el corte de caja del día los sumaba bajo
     * «Sin usuario», y el papel no podía decir quién había recibido el dinero.
     *
     * Es el mismo molde de `Venta::liquidarSiYaNoDebe()`, y por eso el default
     * vive acá y no en cada `create()`: el camino que se olvide de escribirlo
     * sigue quedando bien.
     *
     * Quien teclea es quien recibe mientras nadie diga otra cosa —es lo que el
     * sistema asumió siempre, y lo que la migración del 27-ago escribió en los
     * 257 papeles viejos—. El modal, que sí pregunta, ya trae su valor cuando
     * llega acá, y no se lo pisa.
     *
     * Sin sesión —los seeders, la consola— queda en NULL, igual que hoy.
     */
    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (self $recibo): void {
            if ($recibo->getAttribute('recibido_por') === null && Auth::check()) {
                $recibo->setAttribute('recibido_por', Auth::id());
            }
        });
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
     * Quién recibió el dinero — no necesariamente quien lo tecleó.
     *
     * La administradora puede registrar un cobro que recibió un receptor en la
     * caseta: el efectivo lo tiene él, y de acá sale el corte de caja del día.
     * `created_by` sigue contestando otra pregunta —quién lo escribió— y las
     * dos se guardan porque son dos cosas.
     *
     * Nulo solo si ese usuario se borró (`nullOnDelete`).
     *
     * @return BelongsTo<User, $this>
     */
    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
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
     * El nombre de quien recibió el dinero, como va impreso.
     *
     * «También debe de decir el nombre de la persona que recibió el dinero»
     * — Mauricio, 31-ago-2026. El dato se guardaba desde el 27-ago (R24) y el
     * corte de caja cuenta por él; lo que faltaba era que el papel del cliente
     * lo dijera. Cuando alguien vuelve con un reclamo, «a quién le pagué» es
     * la primera pregunta, y hasta hoy la contestaba la memoria de ventanilla.
     *
     * De respaldo, quien TECLEÓ el recibo: es lo que el sistema asumió hasta
     * el 27-ago y nunca es mentira. Si no hay ninguno de los dos —la cartera
     * vieja se cargó sin sesión— devuelve null, y el papel no inventa un
     * nombre: no imprime el renglón.
     */
    public function nombreDeQuienRecibio(): ?string
    {
        $usuario = $this->recibidoPor ?? $this->createdBy;

        $nombre = $usuario instanceof User ? $usuario->getAttribute('name') : null;

        return is_string($nombre) && trim($nombre) !== '' ? trim($nombre) : null;
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
        return $this->codigosDe($this->compromisosTocados());
    }

    /**
     * Los lotes de los que HABLA el papel — que no siempre son los que tocó.
     *
     * ═══ 🔴 EL RECIBO DE LA PRIMA SALIA CON UN GUION EN «LOTE» ═══
     *
     * «Acá en lote aparece solo una línea en el recibo» — Mauricio,
     * 31-ago-2026, mirando el RPS-00000008.
     *
     * `compromisosTocados()` contesta a qué lote se le APLICÓ el dinero, y esa
     * es la pregunta correcta para el detalle y para el saldo. Pero la prima
     * no se aplica a ningún lote: se pacta por el CONTRATO aunque el
     * expediente lleve tres (R5), y por eso su recibo va sin `compromiso_id` y
     * sin aplicaciones. La lista quedaba vacía y el papel salía sin decir de
     * qué lote hablaba — justo el papel que el cliente guarda para siempre.
     *
     * Cuando no tocó ninguno, los lotes del papel son los RENGLONES VIVOS DEL
     * CONTRATO. Los rescindidos quedan afuera por la misma razón que en el
     * aviso del modal de cobro: nombrarlos diría que el contrato lleva tres
     * lotes cuando ya lleva dos.
     *
     * El orden es por código y está escrito. Si el orden decide lo que se lee,
     * no lo decide el planificador de Postgres.
     *
     * @return list<Compromiso>
     */
    public function compromisosDelPapel(): array
    {
        $tocados = $this->compromisosTocados();

        if ($tocados !== []) {
            return $tocados;
        }

        $renglones = $this->venta?->compromisos;

        if ($renglones === null) {
            return [];
        }

        $vivos = [];

        foreach ($renglones as $renglon) {
            if ($renglon->getAttribute('estado') !== EstadoCompromiso::Rescindido) {
                $vivos[] = $renglon;
            }
        }

        usort($vivos, static fn (Compromiso $uno, Compromiso $otro): int => strcmp(
            self::codigoDe($uno),
            self::codigoDe($otro),
        ));

        return $vivos;
    }

    /**
     * Y sus códigos, sin repetir.
     *
     * @return list<string>
     */
    public function codigosDelPapel(): array
    {
        return $this->codigosDe($this->compromisosDelPapel());
    }

    /**
     * La regla de «un código por lote, sin repetir», escrita una sola vez.
     *
     * @param list<Compromiso> $renglones
     *
     * @return list<string>
     */
    private function codigosDe(array $renglones): array
    {
        $codigos = [];

        foreach ($renglones as $renglon) {
            $codigo = self::codigoDe($renglon);

            if ($codigo !== '' && ! in_array($codigo, $codigos, true)) {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }

    /**
     * El código de un renglón, o cadena vacía si el lote no llegó cargado.
     *
     * Vacía y no null: es lo que se compara al ordenar, y una comparación con
     * null decidiría el orden por donde no debe.
     */
    private static function codigoDe(Compromiso $renglon): string
    {
        $codigo = $renglon->lote?->getAttribute('codigo');

        return is_string($codigo) ? $codigo : '';
    }

    /**
     * Los LOTES que este recibo tocó, no solo sus códigos.
     *
     * ═══ POR QUE EXISTE, Y POR QUE `codigosDeLotes()` LO USA ═══
     *
     * «Qué lotes tocó este recibo» tiene una respuesta con dos mitades: el
     * `compromiso_id` cuando el papel es de un solo lote, y las cuotas que
     * aplicó cuando toca varios —ahí la columna queda vacía a propósito (R13),
     * porque diría una mentira—.
     *
     * Esa regla se escribe UNA vez. Cuando el 27-ago-2026 hubo que imprimir el
     * saldo por lote, el controlador miraba solo `compromiso_id` y devolvía
     * null: **el recibo que más necesita el desglose —el de varios lotes— era
     * justo el único que no lo mostraba.**
     *
     * @return list<Compromiso>
     */
    public function compromisosTocados(): array
    {
        $propio = $this->compromiso;

        if ($propio instanceof Compromiso) {
            return [$propio];
        }

        $lotes = [];

        foreach ($this->aplicaciones as $aplicacion) {
            $lote = $aplicacion->cuota?->compromiso;

            if ($lote instanceof Compromiso) {
                $lotes[(int) $lote->getKey()] = $lote;
            }
        }

        return array_values($lotes);
    }

    /**
     * Los lotes como se leen en el papel.
     */
    public function rotuloDeLotes(): string
    {
        $codigos = $this->codigosDelPapel();

        return $codigos === [] ? '—' : implode(' · ', $codigos);
    }

    /**
     * ¿El papel NOMBRA más de un lote? — decide el rótulo en singular o plural.
     *
     * No es lo mismo que `tocaVariosLotes()`, y la diferencia importa: un
     * recibo de prima de un contrato de tres lotes no toca ninguno y nombra
     * los tres. Cuando hay aplicaciones las dos contestan igual, porque ahí
     * los lotes del papel SON los que tocó.
     */
    public function nombraVariosLotes(): bool
    {
        return count($this->codigosDelPapel()) > 1;
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
     * Cómo se llama, en el papel, el dinero que no fue a ninguna cuota.
     *
     * ═══ 🔴 EL RECIBO DE LA PRIMA DECIA «ABONO A CAPITAL» ═══
     *
     * `montoACapital()` es una RESTA —lo cobrado menos lo aplicado a cuotas—,
     * así que en un recibo de prima o de seña da el papel entero: esos dos
     * conceptos no tocan cuotas por definición (R5, R14). El renglón se había
     * escrito para el abono del R21 y salía con ese nombre en los tres.
     *
     * Y no es un detalle de redacción. Un recibo de prima que dice «Abono a
     * capital» le dice al cliente que su saldo bajó por fuera del plan, y a
     * quien lo revise dentro de dos años, que hubo un extraordinario que nunca
     * existió.
     *
     * El renglón se llama como el CONCEPTO del recibo. «Abono a capital» queda
     * para cuando de verdad lo es: el abono a secas, o el sobrante de un cobro
     * de cuotas —que es el único concepto que se aplica a cuotas, y por eso lo
     * que le sobra sí bajó capital—.
     */
    public function rotuloDelSobrante(): string
    {
        $concepto = $this->concepto;

        if (! $concepto instanceof ConceptoDeRecibo || $concepto->seAplicaACuotas()) {
            return ConceptoDeRecibo::AbonoCapital->etiqueta();
        }

        return $concepto->etiqueta();
    }

    /**
     * Lo que las constancias de este recibo dicen que bajó del capital (R21).
     */
    public function capitalReprogramado(): Monto
    {
        $total = Monto::cero();

        foreach ($this->reprogramaciones as $constancia) {
            $total = $total->sumar($constancia->montoAbonado());
        }

        return $total;
    }

    /**
     * ═══ 🔴 EL CUADRE: LO QUE DICE EL PAPEL CONTRA LO QUE HIZO ═══
     *
     * Todo lempira de un recibo tiene que haber ido a una cuota o haber bajado
     * el capital. Nada en el sistema comparaba las dos mitades, y por eso el
     * defecto del 27-ago-2026 vivió invisible: el recibo RPS-00000005 de
     * Praderas decía L 24,000.00 y solo había movido L 17,020.83.
     *
     * ⚠️ Solo tiene sentido en los recibos que cuelgan de cuotas —`cuota` y
     * `abono_capital`—. Una prima o una seña no aplican a ninguna cuota: para
     * ellas esto da el monto entero y no significa nada.
     *
     * `montoACapital()` no sirve para esto porque es una RESTA: da lo que el
     * papel no aplicó, sin preguntarle a las constancias si de verdad bajó.
     */
    public function loQueAplico(): Monto
    {
        return $this->montoAplicadoACuotas()->sumar($this->capitalReprogramado());
    }

    public function cuadra(): bool
    {
        return $this->montoTotal()->igualA($this->loQueAplico());
    }

    /**
     * El dinero que el cliente entregó y no llegó a ningún lado.
     *
     * Cero cuando cuadra y también cuando aplicó de MÁS —que es otro problema,
     * y `Monto::restar()` no admite negativos—. La dirección peligrosa para el
     * cliente es esta, y es la que se repara.
     */
    public function descuadre(): Monto
    {
        $aplicado = $this->loQueAplico();

        return $this->montoTotal()->mayorQue($aplicado)
            ? $this->montoTotal()->restar($aplicado)
            : Monto::cero();
    }

    /**
     * El número, como se lee en el papel.
     *
     * ═══ DOS FORMAS, Y LA DIFERENCIA ES LA SERIE ═══
     *
     * Desde el 23-ago-2026 cada desarrollo numera lo suyo, y el código del
     * proyecto va adelante: **`RPS-00000001`**. Ocho dígitos, que es el ancho
     * del talonario de papel al que le va a tocar convivir.
     *
     * 🔴 **Sin serie, el folio se ve como se veía antes: `000001`.** No es un
     * caso raro ni un dato incompleto: son los 257 recibos que documentan la
     * cartera anterior al sistema, y Mauricio pidió que quedaran exactamente
     * como se cargaron. Ponerles prefijo sería renumerar papeles que ya se
     * entregaron.
     */
    public function folio(): string
    {
        $numero = (string) $this->getAttribute('numero');
        $serie = $this->getAttribute('serie');

        if (! is_string($serie) || trim($serie) === '') {
            return str_pad($numero, 6, '0', STR_PAD_LEFT);
        }

        return trim($serie).'-'.str_pad($numero, 8, '0', STR_PAD_LEFT);
    }

    /**
     * ¿Este recibo es de la cartera anterior al sistema?
     *
     * Se pregunta por la SERIE y no por la fecha: hay cobros viejos que se
     * registraron después y cobros nuevos con fecha vieja. La serie es lo que
     * dice de qué numeración salió el papel.
     */
    public function esDeLaCarteraVieja(): bool
    {
        $serie = $this->getAttribute('serie');

        return ! is_string($serie) || trim($serie) === '';
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

    /**
     * Lo que este papel perdono de SALDO: el descuento de un pronto pago
     * (23-ago-2026). Tampoco esta adentro de `monto` — no entro por la puerta.
     *
     * ⚠️ DERIVADO de los renglones, no una columna del recibo. La mora si
     * tiene la suya porque el corte de caja la agrupa en SQL; esta no la
     * agrupa nadie, y una columna que solo se lee de a un recibo por vez es
     * una copia mas que se puede desincronizar de sus renglones.
     */
    public function capitalCondonado(): Monto
    {
        $total = Monto::cero();

        foreach ($this->aplicaciones as $aplicacion) {
            $total = $total->sumar($aplicacion->capitalCondonado());
        }

        return $total;
    }

    /**
     * ¿Este papel lleva un descuento por pronto pago?
     */
    public function tuvoDescuento(): bool
    {
        return ! $this->capitalCondonado()->esCero();
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
