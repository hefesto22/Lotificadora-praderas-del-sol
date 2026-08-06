<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
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
            /*
             * 1. Las cuotas del lote, bloqueadas y en orden.
             *
             * `orderBy('numero')` y no por fecha: dos cuotas pueden vencer el
             * mismo día si el plan se reprogramó, y el número es el que no se
             * repite.
             */
            $pendientes = Cuota::query()
                ->where('compromiso_id', $lote->getKey())
                ->whereColumn('monto_pagado', '<', 'monto')
                ->orderBy('numero')
                ->lockForUpdate()
                ->get();

            if ($pendientes->isEmpty()) {
                throw PagoInvalidoException::porNoDeberNada($this->codigo($lote));
            }

            // 2. Lo que se debe, recién leído. La pantalla puede estar vieja.
            $saldo = Monto::cero();

            foreach ($pendientes as $cuota) {
                $saldo = $saldo->sumar($cuota->saldo());
            }

            if ($monto->mayorQue($saldo)) {
                throw PagoInvalidoException::porPagarDeMas($monto, $saldo, $this->codigo($lote));
            }

            // 3. Recién ahora se quema un número (R12).
            $recibo = Recibo::query()->create([
                'numero'        => $this->correlativos->siguienteDeReciboInterno(),
                'venta_id'      => $venta->getKey(),
                'compromiso_id' => $lote->getKey(),
                'cliente_id'    => $cliente->getKey(),
                'concepto'      => ConceptoDeRecibo::Cuota,
                'forma_pago'    => $forma,
                'referencia'    => $limpia === '' ? null : $limpia,
                'monto'         => $monto->redondeado(),
                'fecha'         => $cuando->toDateString(),
                'observaciones' => $observaciones,
            ]);

            // 4. FIFO: la más vieja primero, hasta agotar el dinero.
            $this->repartir($recibo, $pendientes, $monto);

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
     * El reparto FIFO. Devuelve lo que sobró, que siempre es cero.
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

    private function codigo(Compromiso $lote): string
    {
        return (string) $lote->lote()->value('codigo');
    }
}
