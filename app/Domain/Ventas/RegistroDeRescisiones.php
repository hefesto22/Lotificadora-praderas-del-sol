<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Enums\TipoDeDevolucion;
use App\Domain\Exceptions\RescisionInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Devolucion;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * R22: se cae UN LOTE de un contrato firmado, y se liquida lo que entro.
 *
 * ═══ EL CASO, CON LAS PALABRAS DE QUIEN LO PIDIO ═══
 *
 * La contratante, 6-ago-2026: «dio la prima, pago dos meses y ya no quiere el
 * lote». Mauricio, 14-ago-2026: «si ya pasaron varios meses y la inmobiliaria
 * no le quiere devolver el dinero puede hacerlo, asi que eso quedaria como
 * saldo a favor de la inmobiliaria; o si se le devuelve una parte, que se
 * registre cuanto fue».
 *
 * Son la misma cosa vista desde los dos lados del mostrador, y el sistema
 * tiene que dejar constancia de **cuanto entro, cuanto salio y quien lo
 * autorizo**. No calcula cuanto devolver: eso lo decide la administracion
 * caso por caso (R6) y ninguna formula lo sabe.
 *
 * ═══ SE RESCINDE UN LOTE, NO EL CONTRATO ═══
 *
 * Si el expediente lleva tres lotes y el cliente devuelve uno, se cae ESE:
 * sus cuotas pendientes desaparecen, vuelve a estar disponible en el plano y
 * el expediente sigue vivo con los otros dos, con su saldo recalculado. Con
 * un solo lote equivale a anular el contrato entero, asi que este mismo
 * tramite cubre los dos casos.
 *
 * La alternativa —anular todo siempre— obligaria a rehacer a mano el contrato
 * del cliente que se queda con dos lotes, y con un numero nuevo.
 *
 * ═══ 🔴 LO RETENIDO NO VUELVE A SUMAR ═══
 *
 * Decidido por Mauricio el 14-ago-2026. Esa plata **ya entro** el dia que se
 * cobro, ya tiene su recibo o su factura y ya sumo en el corte de caja de
 * aquel dia. La rescision no la vuelve a ingresar: lo unico que cambia es que
 * deja de tener un lote atras.
 *
 * Volver a sumarla seria contar la misma plata dos veces, y el dia que
 * alguien cuadre el año contra los depositos del banco no le va a dar.
 *
 * Lo que SI mueve caja es lo que se devuelve, y eso lo hace la fila de
 * `devoluciones` que ya resta en el corte del dia.
 *
 * ═══ LOS RECIBOS Y LAS FACTURAS NO SE TOCAN ═══
 *
 * Ni se anulan ni se marcan, por lo mismo que no se toca el recibo de una
 * seña devuelta: esa plata entro de verdad y ese lote sí estuvo vendido.
 * Anular diria que el cobro no debio registrarse, que es falso.
 *
 * ⚠️ Cuando el desarrollo factura con CAI y ademas se devuelve dinero, lo que
 * corresponde ante el SAR es una NOTA DE CREDITO por el monto devuelto —no
 * anular una factura que el cliente tiene en la mano desde hace meses—. Es un
 * documento fiscal aparte, con su propio CAI y su propio rango, y entra en su
 * propio drop. Hasta entonces el acta deja el monto por escrito para que el
 * contador la emita.
 */
final readonly class RegistroDeRescisiones
{
    public function __construct(private ConsumoDeCorrelativos $correlativos) {}

    /**
     * Rescinde un lote y emite el acta con los tres montos.
     *
     * @param Monto $devuelto lo que se le entrega al cliente; **puede ser cero**
     *
     * @throws RescisionInvalidaException
     */
    public function rescindir(
        Compromiso $lote,
        Monto $devuelto,
        FormaDePago $forma,
        string $motivo,
        ?string $referencia = null,
    ): Devolucion {
        $porQue = trim($motivo);
        $limpia = trim($referencia ?? '');

        return DB::transaction(function () use ($lote, $devuelto, $forma, $porQue, $limpia): Devolucion {
            /*
             * Bloquear y re-mirar, igual que al vender: lo que decia la
             * pantalla puede tener minutos, y en ese rato otro receptor pudo
             * cobrarle una cuota mas a este mismo lote. Sin el lock, esa
             * cuota entraria despues de calcular lo recibido y el acta diria
             * un numero que no es.
             */
            $fresco = Compromiso::query()
                ->whereKey($lote->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $delPlano = $fresco->lote()->first();
            $codigo = (string) $delPlano?->getAttribute('codigo');

            $this->verificar($fresco, $codigo, $porQue, $forma, $limpia);

            $venta = $fresco->venta()->lockForUpdate()->firstOrFail();
            $this->verificarLaVenta($venta);

            $recibido = $this->loRecibido($fresco);

            if ($recibido->esCero()) {
                throw RescisionInvalidaException::porNoHaberRecibidoNada($codigo);
            }

            if ($devuelto->mayorQue($recibido)) {
                throw RescisionInvalidaException::porDevolverDeMas($devuelto, $recibido, $codigo);
            }

            $sueltas = $this->soltarLasCuotas($fresco);

            $fresco->update([
                'estado'     => EstadoCompromiso::Rescindido,
                'cerrado_el' => today(),
                'motivo'     => $porQue,
            ]);

            $delPlano?->update(['estado' => EstadoLote::Disponible]);

            $devolucion = $this->emitirElActa($fresco, $venta, $recibido, $devuelto, $forma, $porQue, $limpia);

            $this->acomodarLaVenta($venta, $porQue);

            $this->anotar($fresco, $devolucion, $recibido, $devuelto, $sueltas);

            return $devolucion;
        });
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Lo que se puede negar antes de tocar una sola fila.
     *
     * @throws RescisionInvalidaException
     */
    private function verificar(
        Compromiso $lote,
        string $codigo,
        string $motivo,
        FormaDePago $forma,
        string $referencia,
    ): void {
        $tipo = $lote->getAttribute('tipo');

        if ($tipo !== TipoCompromiso::Venta) {
            throw RescisionInvalidaException::porNoSerUnaVenta($codigo);
        }

        $estado = $lote->getAttribute('estado');

        if ($estado !== EstadoCompromiso::Vigente) {
            throw RescisionInvalidaException::porNoEstarVigente(
                $codigo,
                $estado instanceof EstadoCompromiso ? $estado->etiqueta() : 'en un estado desconocido',
            );
        }

        if ($motivo === '') {
            throw RescisionInvalidaException::porFaltarElMotivo($codigo);
        }

        // R11: sin referencia no se puede cruzar la salida contra el banco.
        if ($forma->exigeReferencia() && $referencia === '') {
            throw RescisionInvalidaException::porFaltarLaReferencia($forma->etiqueta());
        }
    }

    /**
     * @throws RescisionInvalidaException
     */
    private function verificarLaVenta(Venta $venta): void
    {
        $estado = $venta->getAttribute('estado');

        if ($estado === EstadoVenta::Vigente) {
            return;
        }

        throw RescisionInvalidaException::porVentaCerrada(
            (string) $venta->getAttribute('numero_contrato'),
            $estado instanceof EstadoVenta ? $estado->etiqueta() : 'cerrado',
        );
    }

    /**
     * Todo lo que entro por ESTE lote, que es el techo de lo que se puede
     * devolver.
     *
     * ═══ POR QUE SALE DEL COMPROMISO Y NO DE LOS RECIBOS ═══
     *
     * Porque un contrato de varios lotes se cobra en UN recibo, con
     * `compromiso_id` vacio a proposito, y la prima del contrato tambien
     * cuelga de la venta y no de un lote. Sumar recibos daria la plata del
     * expediente entero.
     *
     * El compromiso, en cambio, tiene congelada SU parte de la prima —la que
     * `repartirPrima()` le asigno el dia de la firma— y sus cuotas saben
     * cuanto se les pago. Por R5 una venta vigente tiene la prima cobrada
     * completa, asi que sumarla no es optimismo.
     *
     * La mora entra: es plata que el cliente entrego por este lote. El
     * interes ya viene adentro de `monto_pagado`.
     *
     * Es publico porque la pantalla lo necesita ANTES de rescindir: quien
     * decide cuanto devolver tiene que ver cuanto entro, y verlo despues de
     * confirmar no sirve de nada.
     */
    public function loRecibido(Compromiso $lote): Monto
    {
        $prima = $lote->getAttribute('prima');
        $recibido = new Monto(is_string($prima) ? $prima : '0');

        foreach ($lote->cuotas()->get() as $cuota) {
            $recibido = $recibido
                ->sumar($cuota->montoPagado())
                ->sumar($cuota->moraPagada());
        }

        return $recibido;
    }

    /**
     * Las cuotas que nadie toco se van; las que tienen plata encima se
     * quedan.
     *
     * 🔴 Es la misma regla del abono a capital (R21) y por la misma razon: una
     * cuota pagada —aunque sea a medias— tiene aplicaciones de pago colgando,
     * y esas aplicaciones apuntan a un recibo que el cliente tiene guardado.
     * Borrarla dejaria el recibo hablando de una cuota que ya no existe, y
     * «¿por que la 5 aparece a medias?» dejaria de tener respuesta.
     *
     * Lo que se borra son promesas a futuro que ya no se van a cumplir.
     *
     * ═══ 🔴 «MONTO_PAGADO = 0» NO ALCANZA ═══
     *
     * `RegistroDePagos::anular()` devuelve `monto_pagado` a cero **y deja la
     * aplicacion de pago viva**, porque es historia. La FK
     * `aplicaciones_de_pago.cuota_id` es `restrictOnDelete`, asi que borrar
     * esa cuota reventaria con un 23503 de Postgres —en la cara de la
     * administradora, a mitad de la transaccion— por una cuota que a los ojos
     * de las columnas parecia intacta.
     *
     * Por eso la pregunta que manda es `whereDoesntHave('aplicaciones')`. Los
     * tres montos en cero se quedan igual: son mas baratos que el EXISTS y
     * atrapan el caso comun sin ir a la otra tabla.
     */
    private function soltarLasCuotas(Compromiso $lote): int
    {
        return (int) Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->where('monto_pagado', 0)
            ->where('mora_pagada', 0)
            ->where('mora_condonada', 0)
            ->whereDoesntHave('aplicaciones')
            ->delete();
    }

    /**
     * El papel del cliente: entro tanto, se le devolvio tanto, quedo tanto.
     *
     * ⚠️ Quema un numero de la serie de devoluciones, y por eso se llama
     * SIEMPRE dentro de la transaccion: un correlativo consumido por un
     * tramite que despues se cae deja un hueco que R12 no perdona.
     */
    private function emitirElActa(
        Compromiso $lote,
        Venta $venta,
        Monto $recibido,
        Monto $devuelto,
        FormaDePago $forma,
        string $motivo,
        string $referencia,
    ): Devolucion {
        return Devolucion::query()->create([
            'numero'         => $this->correlativos->siguienteDeDevolucion(),
            'tipo'           => TipoDeDevolucion::Rescision,
            'compromiso_id'  => $lote->getKey(),
            'venta_id'       => $venta->getKey(),
            'cliente_id'     => $lote->getAttribute('cliente_id'),
            'monto_recibido' => $recibido->redondeado(),
            'monto_devuelto' => $devuelto->redondeado(),
            'monto_retenido' => $recibido->restar($devuelto)->redondeado(),
            'forma_pago'     => $forma,
            'referencia'     => $referencia === '' ? null : $referencia,
            'motivo'         => $motivo,
            'fecha'          => today()->toDateString(),
        ]);
    }

    /**
     * El expediente despues de perder un lote.
     *
     * Dos caminos, y el segundo es el que sorprende:
     *
     * 1. **Quedan lotes vivos** → el contrato sigue, con sus totales
     *    recalculados sobre los que quedan. El cliente que se queda con dos
     *    lotes conserva su numero de expediente, su historia y sus recibos.
     *
     * 2. **Era el ultimo** → la venta pasa a `rescindida` y **los totales NO
     *    se tocan**. Un contrato cerrado es historia: dejarlo en cero borraria
     *    por cuanto se habia firmado, que es justamente lo que alguien va a
     *    querer saber. El CHECK `ventas_saldo_cuadra_chk` sigue cuadrando
     *    porque las tres columnas quedan como estaban.
     */
    private function acomodarLaVenta(Venta $venta, string $motivo): void
    {
        $vivos = $venta->compromisos()
            ->where('estado', EstadoCompromiso::Vigente->value)
            ->get();

        if ($vivos->isEmpty()) {
            $venta->update([
                'estado'     => EstadoVenta::Rescindida,
                'cerrada_el' => today(),
                'motivo'     => $motivo,
            ]);

            return;
        }

        $area = Monto::cero();
        $valor = Monto::cero();
        $prima = Monto::cero();
        $cuota = Monto::cero();
        $plazo = 0;

        foreach ($vivos as $vivo) {
            $area = $area->sumar($this->columna($vivo, 'area_varas'));
            $valor = $valor->sumar($this->columna($vivo, 'valor'));
            $prima = $prima->sumar($this->columna($vivo, 'prima'));
            $plazo = max($plazo, (int) $vivo->getAttribute('plazo_meses'));

            /*
             * La PRIMERA cuota de cada lote, no la que sigue pendiente: es
             * exactamente lo que `activar()` guardo el dia de la firma —«lo
             * que se paga el primer mes, que es el numero mas alto»— y asi
             * este resumen significa lo mismo antes y despues de la rescision.
             */
            $primera = $vivo->cuotas()->reorder()->orderBy('numero')->value('monto');

            if (is_string($primera)) {
                $cuota = $cuota->sumar(new Monto($primera));
            }
        }

        $venta->update([
            'area_total'      => $area->redondeado(4),
            'valor_total'     => $valor->redondeado(),
            'prima'           => $prima->redondeado(),
            'saldo_financiar' => $valor->restar($prima)->redondeado(),
            'plazo_meses'     => $plazo,
            /*
             * Sin plazo no hay cuota, y con plazo TIENE que haberla: es el
             * CHECK `ventas_cuota_segun_plazo_chk`. Por eso el unico caso que
             * pone null es el plazo cero —todos los lotes que quedan son de
             * contado—: mandar null con plazo > 0 lo rechazaria la base.
             */
            'cuota_mensual' => $plazo === 0 ? null : $cuota->redondeado(),
        ]);
    }

    private function columna(Compromiso $lote, string $nombre): Monto
    {
        $valor = $lote->getAttribute($nombre);

        return new Monto(is_string($valor) ? $valor : '0');
    }

    /**
     * La historia, en el lugar donde alguien la va a leer.
     *
     * `motivo` guarda POR QUE se rescindio y esto guarda QUE PASO con la
     * plata. Van a campos distintos porque son dos preguntas distintas y las
     * dos tienen que sobrevivir.
     */
    private function anotar(
        Compromiso $lote,
        Devolucion $devolucion,
        Monto $recibido,
        Monto $devuelto,
        int $cuotasSueltas,
    ): void {
        $viejas = trim((string) $lote->getAttribute('observaciones'));

        $linea = sprintf(
            'Rescisión %s: habían entrado %s, se le devolvieron %s y quedan %s retenidos. %s',
            $devolucion->folio(),
            $recibido->formateado(),
            $devuelto->formateado(),
            $devolucion->montoRetenido()->formateado(),
            $cuotasSueltas === 1
                ? 'Se canceló 1 cuota pendiente.'
                : sprintf('Se cancelaron %d cuotas pendientes.', $cuotasSueltas),
        );

        $lote->update([
            'observaciones' => $viejas === '' ? $linea : $viejas."\n".$linea,
        ]);
    }
}
