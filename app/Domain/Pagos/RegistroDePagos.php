<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El dinero que entra, aplicado a las cuotas que lo esperan.
 *
 * ═══ FIFO, Y POR LOTE ═══
 *
 * Un pago se aplica a las cuotas pendientes MAS VIEJAS primero. No es una
 * preferencia: es lo que el cliente entiende cuando dice «vengo a pagar», y es
 * lo que hace que el atraso se vaya achicando en vez de dejar huecos en el
 * medio del plan.
 *
 * Y va contra UN lote. Desde el 5-ago-2026 cada lote del contrato tiene su
 * propio plazo y su propio plan (R21/R22), así que «pagar la cuota» sin decir
 * de cuál lote no significa nada.
 *
 * ═══ UNA CUOTA SE PAGA EN VARIAS VECES (R19) ═══
 *
 * No hay nada especial que hacer: el monto se reparte hasta agotarse y la
 * última cuota tocada queda parcial. Lo que falta se arrastra y NO genera
 * cargo — R2, el atraso no cuesta. El cliente debe exactamente lo que le
 * faltaba el día del vencimiento.
 *
 * ═══ Y EL ABONO A CAPITAL, QUE ES OTRA COSA (R21) ═══
 *
 * `cobrarCuotas()` reparte y no toca el plan. `abonarACapital()` primero pone
 * al día y después REESCRIBE las cuotas que nadie tocó todavía, en una de dos
 * formas que elige el cliente. Los dos emiten un recibo y los dos son todo o
 * nada; lo que cambia es qué pasa con el contrato después.
 *
 * ═══ TODO O NADA ═══
 *
 * El correlativo del recibo (R12) se consume con bloqueo de fila DENTRO de la
 * transacción: dos receptores cobrando al mismo tiempo desde lugares distintos
 * no pueden sacar el mismo número. Y las cuotas se releen con `FOR UPDATE`:
 * lo que decía la pantalla no vale, igual que en RegistroDeVentas.
 */
final readonly class RegistroDePagos
{
    public function __construct(private ConsumoDeCorrelativos $correlativos) {}

    /**
     * Cobrar cuotas de un lote.
     *
     * El caso de un solo renglón de `cobrarVariosLotes()`, que es donde vive
     * la lógica. Sigue existiendo porque «cobrarle a un lote» es una frase que
     * el negocio dice todos los días y porque es lo que llama medio sistema.
     *
     * @throws PagoInvalidoException
     */
    public function cobrarCuotas(
        Venta $venta,
        Compromiso $lote,
        Cliente $cliente,
        Monto $monto,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
    ): Recibo {
        return $this->cobrarVariosLotes(
            venta: $venta,
            cliente: $cliente,
            renglones: [['lote' => $lote, 'monto' => $monto]],
            forma: $forma,
            referencia: $referencia,
            fecha: $fecha,
            observaciones: $observaciones,
        );
    }

    /**
     * Cobrar cuotas de VARIOS lotes del mismo contrato, en un solo recibo.
     *
     * ═══ POR QUE UN SOLO PAPEL ═══
     *
     * Un contrato de tres lotes tiene tres planes. Hasta hoy, pagar el mes de
     * los tres eran tres trámites y tres papeles, para un cliente que entregó
     * un solo billete. El dinero se aplica igual que siempre —lote por lote,
     * FIFO adentro de cada uno—; lo que cambia es que el documento es uno, con
     * el desglose adentro. No hizo falta migrar nada: `aplicaciones_de_pago`
     * cuelga de la CUOTA, no del lote, así que la base ya lo permitía.
     *
     * `compromiso_id` se sigue llenando cuando el cobro es de un solo lote. En
     * la enorme mayoría de los recibos la columna dice lo mismo que antes y
     * las pantallas que la leen no cambian. Con dos o más queda en NULL, que
     * es la verdad —este recibo no es de un lote— y es lo que el CHECK
     * `recibos_cuelgan_de_un_compromiso_chk` ya contemplaba: cuelga de la
     * venta (R13).
     *
     * ═══ EL ORDEN DEL BLOQUEO NO ES CASUAL ═══
     *
     * Los renglones se ordenan por id ANTES de bloquear. Dos receptores
     * cobrando los mismos dos lotes en orden distinto se traban el uno al otro
     * —el deadlock clásico de dos transacciones que toman los mismos candados
     * al revés—. Con un orden único para todo el sistema, el segundo espera y
     * sigue.
     *
     * ═══ TODO O NADA ═══
     *
     * Se bloquea y se verifica TODO antes de emitir. Si el tercer renglón paga
     * de más, el correlativo no llegó a moverse: medio recibo no existe.
     *
     * @param list<array{lote: Compromiso, monto: Monto}> $renglones
     *
     * @throws PagoInvalidoException
     */
    public function cobrarVariosLotes(
        Venta $venta,
        Cliente $cliente,
        array $renglones,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
    ): Recibo {
        if ($renglones === []) {
            throw PagoInvalidoException::porNoElegirNingunLote();
        }

        $vistos = [];

        foreach ($renglones as $renglon) {
            $this->verificar($venta, $renglon['lote'], $renglon['monto'], $forma, $referencia);

            $id = (int) $renglon['lote']->getKey();

            if (in_array($id, $vistos, true)) {
                throw PagoInvalidoException::porLoteRepetido($this->codigo($renglon['lote']));
            }

            $vistos[] = $id;
        }

        $cuandoSePago = $fecha ?? CarbonImmutable::parse(today()->toDateString());
        $this->verificarLaFecha($venta, $cuandoSePago);

        // El orden del bloqueo, igual para todos. Ver el docblock.
        usort(
            $renglones,
            static fn (array $uno, array $otro): int => (int) $uno['lote']->getKey() <=> (int) $otro['lote']->getKey(),
        );

        $cuando = $cuandoSePago;
        $limpia = trim($referencia ?? '');

        return DB::transaction(function () use (
            $venta,
            $cliente,
            $renglones,
            $forma,
            $limpia,
            $cuando,
            $observaciones
        ): Recibo {
            $total = Monto::cero();
            $tandas = [];

            /*
             * 1 y 2. Las cuotas de cada lote, bloqueadas y en orden, y lo que
             * cada uno debe recién leído. La pantalla puede estar vieja: entre
             * que se pintó el modal y se apretó Guardar, el otro receptor pudo
             * cobrar el mismo lote.
             */
            foreach ($renglones as $renglon) {
                $lote = $renglon['lote'];
                $monto = $renglon['monto'];

                $pendientes = $this->pendientesBloqueadas($lote);
                $saldo = $this->saldoDe($pendientes);

                if ($monto->mayorQue($saldo)) {
                    throw PagoInvalidoException::porPagarDeMas($monto, $saldo, $this->codigo($lote));
                }

                $tandas[] = ['pendientes' => $pendientes, 'monto' => $monto];
                $total = $total->sumar($monto);
            }

            // 3. Recién ahora se quema un número (R12). Uno solo, para todo.
            $recibo = $this->emitir(
                $venta,
                count($renglones) === 1 ? $renglones[0]['lote'] : null,
                $cliente,
                ConceptoDeRecibo::Cuota,
                $total,
                $forma,
                $limpia,
                $cuando,
                $observaciones,
            );

            // 4. FIFO adentro de cada lote, sobre el mismo recibo.
            foreach ($tandas as $tanda) {
                $this->repartir($recibo, $tanda['pendientes'], $tanda['monto']);
            }

            // 5. Si con esto terminó de pagar todo, el expediente se cierra.
            $this->cerrarSiQuedoPagada($venta, $cuando);

            return $recibo;
        });
    }

    /**
     * Anular un recibo mal emitido.
     *
     * ═══ QUE HACE, EXACTAMENTE ═══
     *
     * Devuelve a las cuotas lo que ese recibo les había aplicado, marca el
     * recibo con quién lo anuló y por qué, y —si la venta se había liquidado
     * con ese cobro— la vuelve a abrir. El número NO se libera y la fila NO se
     * borra: una serie con huecos deja de servir para decir «entre el 000120 y
     * el 000130 no falta ninguno», que es lo único que hace serio a un recibo
     * interno (R12).
     *
     * Las aplicaciones tampoco se borran: son la traza de a qué se había
     * aplicado, y sin ellas «¿por qué la cuota 5 volvió a deber?» no tiene
     * respuesta.
     *
     * ═══ QUE NO HACE ═══
     *
     * No devuelve dinero. Anular dice que el cobro no debió registrarse, no
     * que haya que sacar plata de la caja — eso es un egreso, y no existe
     * todavía. Si el cliente sí pagó y el error fue el monto, el camino es
     * anular y volver a cobrar con el número nuevo.
     *
     * ═══ SOLO COBROS DE CUOTA ═══
     *
     * Una prima o una seña consumieron el correlativo de un contrato o dejaron
     * un lote apartado; un abono a capital reescribió un plan. Los tres se
     * rechazan con su motivo: revertirlos es deshacer otra cosa.
     *
     * @throws PagoInvalidoException
     */
    public function anular(Recibo $recibo, string $motivo): Recibo
    {
        $porQue = trim($motivo);

        if ($porQue === '') {
            throw PagoInvalidoException::porFaltarElMotivoDeLaAnulacion();
        }

        if ($recibo->estaAnulado()) {
            throw PagoInvalidoException::porReciboYaAnulado($recibo->folio());
        }

        $concepto = $recibo->getAttribute('concepto');

        if ($concepto !== ConceptoDeRecibo::Cuota) {
            throw $concepto === ConceptoDeRecibo::AbonoCapital
                ? PagoInvalidoException::porReciboQueReprogramo($recibo->folio())
                : PagoInvalidoException::porConceptoQueNoSeAnulaAsi(
                    $concepto instanceof ConceptoDeRecibo ? $concepto->etiqueta() : 'otro concepto',
                    $recibo->folio(),
                );
        }

        return DB::transaction(function () use ($recibo, $porQue): Recibo {
            /*
             * Se relee bloqueando. Dos personas anulando el mismo recibo al
             * mismo tiempo devolverían el saldo dos veces, y la cuota quedaría
             * debiendo más de lo que vale.
             *
             * ⚠️ `whereKey()->firstOrFail()` y NO `findOrFail()`: este último
             * acepta también un arreglo de ids, así que PHPStan nivel 7 lo tipa
             * `Recibo|Collection<int, Recibo>`, y a partir de ahí cada llamada
             * sobre `$vivo` es «método indefinido en Collection». Seis errores
             * salían de esta sola línea.
             */
            $vivo = Recibo::query()
                ->whereKey($recibo->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($vivo->estaAnulado()) {
                throw PagoInvalidoException::porReciboYaAnulado($vivo->folio());
            }

            foreach ($vivo->aplicaciones()->with('cuota')->get() as $aplicacion) {
                $cuota = $aplicacion->cuota;

                if (! $cuota instanceof Cuota) {
                    continue;
                }

                $cuota->update([
                    'monto_pagado' => $cuota->montoPagado()->restar($aplicacion->montoAplicado())->redondeado(),
                ]);
            }

            $vivo->update([
                'anulado_el'       => now(),
                'anulado_por'      => auth()->id(),
                'motivo_anulacion' => $porQue,
            ]);

            $this->reabrirSiVolvioADeber($vivo);

            return $vivo;
        });
    }

    /**
     * Abono extraordinario a capital, con su reprogramación (R21).
     *
     * ═══ LOS OCHO PASOS, EN ORDEN, Y POR QUE ESE ORDEN ═══
     *
     *  1. Se bloquean las cuotas del lote y se releen. Entre que se pintó el
     *     modal y se apretó Guardar, el otro receptor pudo cobrar.
     *  2. Se calcula el efecto ANTES de escribir nada — el mismo objeto que la
     *     pantalla ya le mostró al cliente.
     *  3. Se rechaza lo que no se puede hacer: pagar de más, pasarse del tope,
     *     un plan que no se puede armar. Todo antes del paso 5.
     *  4. Si el abono no alcanza ni para lo vencido, esto NO era un abono: se
     *     registra como pago normal y no se reescribe ningún plan.
     *  5. Recién acá se quema un número de recibo.
     *  6. Se pone al día lo vencido, FIFO.
     *  7. Se borran las cuotas que nadie tocó y se escribe el plan nuevo. Lo
     *     pagado —incluida la cuota a medias— no se toca nunca.
     *  8. Queda la constancia con su motivo, y el resumen del expediente se
     *     recalcula desde las cuotas.
     *
     * Si algo falla en cualquier paso se cae todo junto: el correlativo
     * vuelve, el plan viejo sigue en pie y no queda media reprogramación.
     *
     * @param string $motivo obligatorio (R21); la base también lo exige
     *
     * @throws PagoInvalidoException
     */
    public function abonarACapital(
        Venta $venta,
        Compromiso $lote,
        Cliente $cliente,
        Monto $monto,
        ModalidadDeReprogramacion $modalidad,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
    ): Recibo {
        $this->verificar($venta, $lote, $monto, $forma, $referencia);

        $porQue = trim($motivo);

        if ($porQue === '') {
            throw PagoInvalidoException::porFaltarElMotivoDelAbono();
        }

        $cuando = $fecha ?? CarbonImmutable::parse(today()->toDateString());
        $this->verificarLaFecha($venta, $cuando);
        $limpia = trim($referencia ?? '');

        return DB::transaction(function () use (
            $venta,
            $lote,
            $cliente,
            $monto,
            $modalidad,
            $porQue,
            $forma,
            $limpia,
            $cuando,
            $observaciones
        ): Recibo {
            // 1 y 2. Releer bloqueando, y calcular con lo recién leído.
            $pendientes = $this->pendientesBloqueadas($lote);
            $efecto = EfectoDelAbono::calcular($pendientes, $monto, $modalidad, $this->diaDePago($venta));

            // 3. Lo que no se puede hacer, antes de quemar nada.
            if ($monto->mayorQue($efecto->saldoDelLote)) {
                throw PagoInvalidoException::porPagarDeMas($monto, $efecto->saldoDelLote, $this->codigo($lote));
            }

            if ($efecto->superaElTope) {
                throw PagoInvalidoException::porAbonoQueNoSePuedeReprogramar(
                    $monto,
                    $efecto->tope,
                    $efecto->saldoDelLote,
                    $this->codigo($lote),
                );
            }

            /*
             * 4. No alcanza ni para lo vencido: es un pago normal y no hay
             * reprogramación. No se reescribe un plan por algo que no bajó el
             * capital. El dinero se registra igual —ya está sobre el
             * mostrador— y quien atiende lo ve en la notificación.
             */
            if ($efecto->esPagoNormal) {
                $recibo = $this->emitir(
                    $venta,
                    $lote,
                    $cliente,
                    ConceptoDeRecibo::Cuota,
                    $monto,
                    $forma,
                    $limpia,
                    $cuando,
                    $observaciones,
                );

                $this->repartir($recibo, $pendientes, $monto);
                $this->cerrarSiQuedoPagada($venta, $cuando);

                return $recibo;
            }

            $plan = $efecto->planNuevo;

            if ($efecto->problema !== null || ! $plan instanceof PlanDeCuotas) {
                throw PagoInvalidoException::porPlanQueNoSePudoArmar(
                    $efecto->problema ?? 'No se pudo armar el plan nuevo.',
                    $this->codigo($lote),
                );
            }

            // Un plan que no cierra al céntimo no llega nunca a la base
            // (§8.3.4). Es la misma verificación que hace RegistroDeVentas.
            if (! $plan->cierraExacto()) {
                throw PagoInvalidoException::porPlanQueNoCierra($plan->total(), $efecto->saldoNuevo);
            }

            // 5. Recién ahora se quema un número (R12).
            $recibo = $this->emitir(
                $venta,
                $lote,
                $cliente,
                ConceptoDeRecibo::AbonoCapital,
                $monto,
                $forma,
                $limpia,
                $cuando,
                $observaciones,
            );

            /*
             * 6. Poner al día, FIFO. Con lo vencido cubierto por completo, esas
             * cuotas quedan saldadas y ninguna sale parcial de este paso.
             */
            if (! $efecto->ponerAlDia->esCero()) {
                $this->repartir($recibo, $pendientes, $efecto->ponerAlDia);
            }

            // 7 y 8.
            $this->reescribirElPlan($venta, $lote, $efecto, $plan);
            $this->asentarLaConstancia($venta, $lote, $recibo, $efecto, $plan, $porQue);
            $this->recalcularElResumen($venta);
            $this->cerrarSiQuedoPagada($venta, $cuando);

            return $recibo;
        });
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Lo que se puede verificar sin tocar la base.
     *
     * @throws PagoInvalidoException
     */
    private function verificar(
        Venta $venta,
        Compromiso $lote,
        Monto $monto,
        FormaDePago $forma,
        ?string $referencia,
    ): void {
        if ($monto->esCero()) {
            throw PagoInvalidoException::porMontoNoPositivo();
        }

        $estado = $venta->getAttribute('estado');

        if ($estado !== EstadoVenta::Vigente) {
            throw PagoInvalidoException::porVentaQueNoEstaVigente(
                $estado instanceof EstadoVenta ? $estado->value : 'desconocido'
            );
        }

        if ((int) $lote->getAttribute('venta_id') !== (int) $venta->getKey()) {
            throw PagoInvalidoException::porLoteDeOtraVenta(
                $this->codigo($lote),
                (string) $venta->getAttribute('numero_contrato'),
            );
        }

        // R11. La base tiene el mismo CHECK; esto es para que el mensaje lo
        // escriba alguien y no Postgres.
        if ($forma->exigeReferencia() && trim($referencia ?? '') === '') {
            throw PagoInvalidoException::porFaltarReferencia($forma->etiqueta());
        }
    }

    /**
     * Las cuotas que todavía deben algo, bloqueadas hasta el fin de la
     * transacción.
     *
     * `orderBy('numero')` y no por fecha: dos cuotas pueden vencer el mismo
     * día si el plan se reprogramó, y el número es el que no se repite.
     *
     * @return Collection<int, Cuota>
     *
     * @throws PagoInvalidoException
     */
    private function pendientesBloqueadas(Compromiso $lote): Collection
    {
        $pendientes = Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->whereColumn('monto_pagado', '<', 'monto')
            ->orderBy('numero')
            ->lockForUpdate()
            ->get();

        if ($pendientes->isEmpty()) {
            throw PagoInvalidoException::porNoDeberNada($this->codigo($lote));
        }

        return $pendientes;
    }

    /**
     * @param Collection<int, Cuota> $pendientes
     */
    private function saldoDe(Collection $pendientes): Monto
    {
        $saldo = Monto::cero();

        foreach ($pendientes as $cuota) {
            $saldo = $saldo->sumar($cuota->saldo());
        }

        return $saldo;
    }

    /**
     * La fecha del pago tiene que ser creíble.
     *
     * ═══ POR QUE NO ALCANZA CON EL DATEPICKER ═══
     *
     * La pantalla lo limita, pero la pantalla no es el borde: el Service es la
     * única puerta y lo llama también el import de la cartera vieja. Un cobro
     * fechado el mes que viene deja una cuota que figura pagada antes de
     * haberse cobrado, y uno fechado en 2019 —el clásico error de tipear el
     * año— entra sin que nada chille.
     *
     * @throws PagoInvalidoException
     */
    private function verificarLaFecha(Venta $venta, CarbonImmutable $cuando): void
    {
        $hoy = CarbonImmutable::parse(today()->toDateString());

        if ($cuando->greaterThan($hoy)) {
            throw PagoInvalidoException::porFechaFutura($cuando->format('d/m/Y'));
        }

        $firma = $venta->getAttribute('fecha_contrato');

        if ($firma instanceof CarbonInterface && $cuando->lessThan($firma->startOfDay())) {
            throw PagoInvalidoException::porFechaAnteriorAlContrato(
                $cuando->format('d/m/Y'),
                $firma->format('d/m/Y'),
            );
        }
    }

    /**
     * Un expediente que terminó de pagarse deja de estar vigente.
     *
     * ═══ POR QUE ACA Y NO EN UNA ACCION APARTE ═══
     *
     * `EstadoVenta::Liquidada` existía desde la primera migración y **nadie lo
     * asignaba nunca**: una venta pagada al último centavo se quedaba
     * «Vigente» para siempre, ofreciendo el botón de cobrar sobre un contrato
     * que no debe nada. No es un trámite que alguien deba acordarse de hacer:
     * es una consecuencia aritmética del último pago.
     *
     * El CHECK `ventas_cierre_segun_estado_chk` exige `cerrada_el` cuando el
     * estado es uno de los cerrados, así que van juntos o no van.
     */
    private function cerrarSiQuedoPagada(Venta $venta, CarbonImmutable $cuando): void
    {
        if ($venta->getAttribute('estado') !== EstadoVenta::Vigente) {
            return;
        }

        if (! $venta->saldoPendiente()->esCero()) {
            return;
        }

        $venta->update([
            'estado'     => EstadoVenta::Liquidada,
            'cerrada_el' => $cuando->toDateString(),
        ]);
    }

    /**
     * Anular el cobro que la cerró la vuelve a abrir.
     *
     * Sin esto, anular el último recibo dejaría un expediente «Liquidado» que
     * vuelve a deber dinero y sin botón para cobrarlo.
     */
    private function reabrirSiVolvioADeber(Recibo $recibo): void
    {
        $venta = $recibo->venta;

        if (! $venta instanceof Venta || $venta->getAttribute('estado') !== EstadoVenta::Liquidada) {
            return;
        }

        if ($venta->saldoPendiente()->esCero()) {
            return;
        }

        $venta->update([
            'estado'     => EstadoVenta::Vigente,
            'cerrada_el' => null,
        ]);
    }

    /**
     * El documento. Un solo lugar donde se quema un correlativo.
     *
     * `$lote` viene en null cuando el recibo cubre varios lotes del contrato:
     * `compromiso_id` no puede decir «estos tres», y ponerle uno de los tres
     * sería peor que dejarla vacía. El CHECK
     * `recibos_cuelgan_de_un_compromiso_chk` la deja pasar porque `venta_id`
     * está puesto — R13: todo pago cuelga de algo.
     */
    private function emitir(
        Venta $venta,
        ?Compromiso $lote,
        Cliente $cliente,
        ConceptoDeRecibo $concepto,
        Monto $monto,
        FormaDePago $forma,
        string $referencia,
        CarbonImmutable $cuando,
        ?string $observaciones,
    ): Recibo {
        return Recibo::query()->create([
            'numero'        => $this->correlativos->siguienteDeReciboInterno(),
            'venta_id'      => $venta->getKey(),
            'compromiso_id' => $lote?->getKey(),
            'cliente_id'    => $cliente->getKey(),
            'concepto'      => $concepto,
            'forma_pago'    => $forma,
            'referencia'    => $referencia === '' ? null : $referencia,
            'monto'         => $monto->redondeado(),
            'fecha'         => $cuando->toDateString(),
            'observaciones' => $observaciones,
        ]);
    }

    /**
     * El reparto FIFO.
     *
     * @param Collection<int, Cuota> $pendientes
     */
    private function repartir(Recibo $recibo, mixed $pendientes, Monto $monto): void
    {
        $porRepartir = $monto;

        foreach ($pendientes as $cuota) {
            if ($porRepartir->esCero()) {
                break;
            }

            $falta = $cuota->saldo();

            // Lo que le toca a esta cuota: todo lo que le falta, o lo que
            // quede del pago si ya no alcanza.
            $leToca = $porRepartir->mayorQue($falta) ? $falta : $porRepartir;

            $recibo->aplicaciones()->create([
                'cuota_id' => $cuota->getKey(),
                'monto'    => $leToca->redondeado(),
            ]);

            /*
             * `monto_pagado` es la suma de sus aplicaciones. Se guarda igual y
             * no se deriva en cada lectura: el estado de cuenta lo consulta
             * lote por lote y hacerlo con un JOIN por cuota es pagar una
             * consulta cara por un número que no cambia solo.
             */
            $cuota->update([
                'monto_pagado' => $cuota->montoPagado()->sumar($leToca)->redondeado(),
            ]);

            $porRepartir = $porRepartir->restar($leToca);
        }
    }

    /**
     * Borra las cuotas que nadie tocó y escribe el plan nuevo (R21).
     *
     * ═══ POR QUE SE PUEDE BORRAR SIN MIEDO ═══
     *
     * Solo se borran las que tienen `monto_pagado = 0`, y una cuota sin nada
     * pagado no tiene aplicaciones de pago —el CHECK
     * `aplicaciones_monto_positivo_chk` no admite renglones de L 0.00—. Por eso
     * el `restrictOnDelete` de `aplicaciones_de_pago.cuota_id` nunca se
     * dispara acá: es la red por si algún día esta invariante se rompe, no un
     * obstáculo que haya que esquivar.
     */
    private function reescribirElPlan(Venta $venta, Compromiso $lote, EfectoDelAbono $efecto, PlanDeCuotas $plan): void
    {
        Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->whereIn('numero', $efecto->numerosReemplazados)
            ->delete();

        if ($plan->cuotas === []) {
            // El abono canceló lo que quedaba: no hay cuotas nuevas y no queda
            // ninguna de L 0.00 colgando (R3).
            return;
        }

        $ahora = now();
        $filas = [];

        foreach ($plan->cuotas as $cuota) {
            $filas[] = [
                'venta_id'          => $venta->getKey(),
                'compromiso_id'     => $lote->getKey(),
                'numero'            => $cuota->numero,
                'fecha_vencimiento' => $cuota->vencimientoParaBase(),
                'monto'             => $cuota->montoParaBase(),
                'monto_pagado'      => '0.00',
                'created_at'        => $ahora,
                'updated_at'        => $ahora,
            ];
        }

        Cuota::query()->insert($filas);
    }

    /**
     * La fila que contesta «¿por qué mi cuota cambió?».
     */
    private function asentarLaConstancia(
        Venta $venta,
        Compromiso $lote,
        Recibo $recibo,
        EfectoDelAbono $efecto,
        PlanDeCuotas $plan,
        string $motivo,
    ): void {
        Reprogramacion::query()->create([
            'venta_id'      => $venta->getKey(),
            'compromiso_id' => $lote->getKey(),
            'recibo_id'     => $recibo->getKey(),
            'modalidad'     => $efecto->modalidad,
            'motivo'        => $motivo,
            /*
             * Solo lo que bajó el capital. Lo que el mismo recibo usó para
             * poner al día no reprogramó nada, y meterlo acá rompería el CHECK
             * `reprogramaciones_saldo_cuadra_chk` — que es justamente para lo
             * que está.
             */
            'abono_capital'  => $efecto->aCapital->redondeado(),
            'saldo_anterior' => $efecto->saldoReprogramable->redondeado(),
            'saldo_nuevo'    => $efecto->saldoNuevo->redondeado(),
            'cuota_anterior' => $efecto->cuotaVigente?->redondeado(),
            'cuota_nueva'    => $plan->cuotaMensual()?->redondeado(),
            'cuotas_antes'   => count($efecto->numerosReemplazados),
            'cuotas_despues' => $plan->count(),
            'desde_numero'   => $efecto->desdeNumero,
            'plan_anterior'  => $efecto->planAnterior,
        ]);
    }

    /**
     * El resumen del expediente, recalculado desde las cuotas.
     *
     * `plazo_meses` es el HORIZONTE del contrato y `cuota_mensual` lo que se
     * paga el PRIMER mes: los dos son un resumen de `cuotas`, que es el
     * contrato. Acortar el plazo de un lote baja el horizonte, y dejarlo viejo
     * haría que la lista de ventas diga 48 meses cuando ya son 40.
     *
     * El `reorder()` no hace falta acá porque `Cuota::query()` no arrastra el
     * `orderBy` de la relación; el 42803 de Postgres aparece cuando se agrega
     * sobre `$venta->cuotas()`, como documenta `Venta::saldoPendiente()`.
     */
    private function recalcularElResumen(Venta $venta): void
    {
        /** @var string|int|null $horizonte */
        $horizonte = Cuota::query()
            ->where('venta_id', $venta->getKey())
            ->selectRaw('COALESCE(MAX(numero), 0) AS horizonte')
            ->value('horizonte');

        /** @var string|int|null $primera */
        $primera = Cuota::query()
            ->where('venta_id', $venta->getKey())
            ->where('numero', 1)
            ->selectRaw('COALESCE(SUM(monto), 0) AS total')
            ->value('total');

        $cuotaMensual = new Monto(is_string($primera) || is_int($primera) ? $primera : '0');

        $venta->update([
            'plazo_meses'   => is_string($horizonte) || is_int($horizonte) ? (int) $horizonte : 0,
            'cuota_mensual' => $cuotaMensual->esCero() ? null : $cuotaMensual->redondeado(),
        ]);
    }

    /**
     * El día de pago del contrato, sin inventar un default.
     *
     * Si viniera vacío, `PlanDeCuotas` lo rechaza con su propio mensaje y el
     * abono se cae explicando por qué. Poner un 1 «por si acaso» sería
     * mover todos los vencimientos de un contrato sin que nadie lo pida.
     */
    private function diaDePago(Venta $venta): int
    {
        $dia = $venta->getAttribute('dia_pago');

        if (is_int($dia)) {
            return $dia;
        }

        return is_string($dia) ? (int) $dia : 0;
    }

    private function codigo(Compromiso $lote): string
    {
        return (string) $lote->lote()->value('codigo');
    }
}
