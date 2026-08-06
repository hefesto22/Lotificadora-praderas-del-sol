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
        $this->verificar($venta, $lote, $monto, $forma, $referencia);

        $cuando = $fecha ?? CarbonImmutable::parse(today()->toDateString());
        $limpia = trim($referencia ?? '');

        return DB::transaction(function () use (
            $venta,
            $lote,
            $cliente,
            $monto,
            $forma,
            $limpia,
            $cuando,
            $observaciones
        ): Recibo {
            // 1. Las cuotas del lote, bloqueadas y en orden.
            $pendientes = $this->pendientesBloqueadas($lote);

            // 2. Lo que se debe, recién leído. La pantalla puede estar vieja.
            $saldo = $this->saldoDe($pendientes);

            if ($monto->mayorQue($saldo)) {
                throw PagoInvalidoException::porPagarDeMas($monto, $saldo, $this->codigo($lote));
            }

            // 3. Recién ahora se quema un número (R12).
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

            // 4. FIFO: la más vieja primero, hasta agotar el dinero.
            $this->repartir($recibo, $pendientes, $monto);

            return $recibo;
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
     * El documento. Un solo lugar donde se quema un correlativo.
     */
    private function emitir(
        Venta $venta,
        Compromiso $lote,
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
            'compromiso_id' => $lote->getKey(),
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
