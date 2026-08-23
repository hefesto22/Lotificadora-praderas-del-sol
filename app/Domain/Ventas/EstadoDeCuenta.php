<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Venta;
use Carbon\CarbonImmutable;

/**
 * Como va un expediente completo. Modulo del contrato, Clausula Segunda.
 *
 * ═══ NO GUARDA NADA, Y NO PUEDE ═══
 *
 * Todo sale de `cuotas`, que es el contrato de hoy. Una columna
 * `saldo_actual` que se desincroniza es la forma mas cara de mentirle a un
 * cliente: el papel diria una cosa y la escalera de cuotas otra, y las dos
 * llevarian el sello de la lotificadora.
 *
 * ═══ POR QUE ES UN OBJETO Y NO UNA CONSULTA EN LA PLANTILLA ═══
 *
 * Los totales de un estado de cuenta son la parte que un cliente va a revisar
 * con una calculadora en la mano. Aca se pueden probar sin renderizar una
 * sola linea de HTML, que es la unica forma de saber que cierran.
 *
 * ═══ UNO POR CONTRATO, CON SECCIONES POR LOTE ═══
 *
 * El cliente firmo un expediente, no tres. Arriba va el resumen del contrato
 * y abajo una seccion por lote con su escalera —porque desde el 5-ago cada
 * lote lleva su propio plazo—. Con un solo lote se lee igual que siempre.
 *
 * ═══ MUESTRA EL ATRASO, NO LO COBRA ═══
 *
 * R2: no hay mora. Los dias de atraso y las cuotas vencidas se imprimen
 * porque la administracion los necesita para llamar al cliente, pero el saldo
 * es exactamente el mismo que el dia del vencimiento.
 */
final readonly class EstadoDeCuenta
{
    /**
     * @param list<CuentaDelLote> $lotes
     * @param list<Cliente> $copropietarios sin el titular
     */
    private function __construct(
        public Venta $venta,
        public ?Cliente $titular,
        public array $copropietarios,
        public array $lotes,
        public Monto $valorTotal,
        public Monto $prima,
        public Monto $pagadoEnCuotas,
        public Monto $saldo,
        public Monto $vencido,
        /* El desglose del contrato: la suma de lo mismo por cada lote. */
        public bool $llevaInteres,
        public Monto $interes,
        public Monto $interesPagado,
        public Monto $capitalPagado,
        public Monto $mora,
        public Monto $moraCondonada,
        public int $cuotasVencidas,
        public int $cuotasPagadas,
        public int $cuotasTotales,
        public CarbonImmutable $alDia,
    ) {}

    public static function de(Venta $venta): self
    {
        $venta->loadMissing(['clientes', 'compromisos.lote']);

        $lotes = [];
        $pagado = Monto::cero();
        $saldo = Monto::cero();
        $vencido = Monto::cero();
        $interes = Monto::cero();
        $interesPagado = Monto::cero();
        $capitalPagado = Monto::cero();
        $mora = Monto::cero();
        $moraCondonada = Monto::cero();
        $llevaInteres = false;
        $vencidas = 0;
        $pagadas = 0;
        $totales = 0;

        /*
         * 🔴 ORDENADOS POR CODIGO, Y NO POR COMO VENGAN.
         *
         * `compromisos` no trae `orderBy`, asi que Postgres los devuelve en
         * el orden fisico de la tabla — y ese orden CAMBIA en cuanto una
         * fila se actualiza, porque Postgres reescribe la fila al final del
         * heap en vez de tocarla donde estaba. Resultado: el mismo contrato
         * imprimia sus lotes en un orden antes de cobrar y en otro despues.
         *
         * Se descubrio el 13-ago-2026 por un test que fallaba salteado
         * —`lotes[0]` era el de 12 meses o el de 24 segun el dia—, pero el
         * problema no era del test: es el papel que recibe el cliente. Un
         * estado de cuenta que lista los mismos tres lotes en distinto orden
         * cada vez que se imprime obliga a leerlo entero para compararlo con
         * el anterior.
         *
         * Por CODIGO porque es el orden del contrato: RPS-A-001, RPS-A-002.
         */
        /*
         * 🔴 Los lotes RESCINDIDOS no entran (R22, 14-ago-2026). Un estado de
         * cuenta contesta «que tengo y cuanto debo HOY», y un lote que se
         * cayo no es ninguna de las dos cosas. Dejarlo adentro le sumaria al
         * cliente el saldo de una cuota que quedo viva por tener un pago
         * encima, por un terreno que ya devolvio.
         *
         * Su historia no se pierde: vive en el acta de rescision, en los
         * recibos que se le entregaron y en la bitacora del compromiso.
         */
        $compromisos = $venta->compromisos
            ->reject(static fn (Compromiso $compromiso): bool => $compromiso->getAttribute('estado') === EstadoCompromiso::Rescindido)
            ->sortBy(static fn (Compromiso $compromiso): string => (string) $compromiso->lote?->getAttribute('codigo'))
            ->values();

        foreach ($compromisos as $compromiso) {
            $cuenta = CuentaDelLote::de($compromiso);

            $lotes[] = $cuenta;
            $pagado = $pagado->sumar($cuenta->pagado);
            $saldo = $saldo->sumar($cuenta->saldo);
            $vencido = $vencido->sumar($cuenta->vencido);

            $llevaInteres = $llevaInteres || $cuenta->llevaInteres;
            $interes = $interes->sumar($cuenta->interes);
            $interesPagado = $interesPagado->sumar($cuenta->interesPagado);
            $capitalPagado = $capitalPagado->sumar($cuenta->capitalPagado);
            $mora = $mora->sumar($cuenta->mora);
            $moraCondonada = $moraCondonada->sumar($cuenta->moraCondonada);
            $vencidas += $cuenta->cuotasVencidas;
            $pagadas += $cuenta->cuotasPagadas;
            $totales += $cuenta->cuotasTotales();
        }

        $titular = $venta->titular();

        return new self(
            venta: $venta,
            titular: $titular,
            copropietarios: self::acompanantes($venta, $titular),
            lotes: $lotes,
            valorTotal: $venta->montoValorTotal(),
            prima: $venta->montoPrima(),
            pagadoEnCuotas: $pagado,
            saldo: $saldo,
            vencido: $vencido,
            llevaInteres: $llevaInteres,
            interes: $interes,
            interesPagado: $interesPagado,
            capitalPagado: $capitalPagado,
            mora: $mora,
            moraCondonada: $moraCondonada,
            cuotasVencidas: $vencidas,
            cuotasPagadas: $pagadas,
            cuotasTotales: $totales,
            // `today()` lo genera PHP, nunca Postgres: el servidor puede estar
            // en UTC y el corte saldria corrido seis horas (§7.5.1).
            alDia: CarbonImmutable::parse(today()->toDateString()),
        );
    }

    /**
     * Todo lo que el cliente ha entregado por este contrato.
     *
     * La prima entra: se pago al firmar (R5) y para el cliente es plata que
     * puso. Dejarla afuera haria que el papel diga que pago menos de lo que
     * pago, y esa es la clase de numero por la que alguien vuelve al
     * mostrador con el recibo en la mano.
     */
    public function totalPagado(): Monto
    {
        return $this->prima->sumar($this->pagadoEnCuotas);
    }

    public function estaAlDia(): bool
    {
        return $this->cuotasVencidas === 0;
    }

    /**
     * El interés que todavía falta pagar de todo el expediente.
     */
    public function interesPendiente(): Monto
    {
        return $this->interes->restar($this->interesPagado);
    }

    /**
     * ¿Hubo mora en el expediente —cobrada o perdonada—? Con R2, nunca.
     */
    public function huboMora(): bool
    {
        // Cobrada + condonada: las dos son no negativas, así que la suma es
        // cero solo si las dos lo son. Sin `||`, que es lo que pide Rector
        // (ReturnBinaryOrToEarlyReturn) y además se lee mejor.
        return ! $this->mora->sumar($this->moraCondonada)->esCero();
    }

    public function estaCancelado(): bool
    {
        return $this->saldo->esCero();
    }

    /**
     * Los dias de atraso de la cuota vencida mas vieja de todo el contrato.
     *
     * Informacion para la administracion, no la base de ningun cobro: no hay
     * mora (R2).
     */
    public function diasDeAtraso(): int
    {
        $maximo = 0;

        foreach ($this->lotes as $lote) {
            $maximo = max($maximo, $lote->diasDeAtraso());
        }

        return $maximo;
    }

    /**
     * La proxima cuota que vence en todo el contrato: el «su siguiente pago».
     *
     * Con plazos mezclados puede ser de cualquiera de los lotes, asi que se
     * compara por fecha y no por numero.
     */
    public function proximaCuota(): ?Cuota
    {
        $proxima = null;

        foreach ($this->lotes as $lote) {
            $candidata = $lote->proxima();

            if (! $candidata instanceof Cuota) {
                continue;
            }

            if (! $proxima instanceof Cuota) {
                $proxima = $candidata;

                continue;
            }

            $unaFecha = $candidata->getAttribute('fecha_vencimiento');
            $otraFecha = $proxima->getAttribute('fecha_vencimiento');

            if ($unaFecha !== null && $otraFecha !== null && $unaFecha < $otraFecha) {
                $proxima = $candidata;
            }
        }

        return $proxima;
    }

    /**
     * Lo que hay que pagar este mes: la suma de las cuotas vivas del mes.
     *
     * Con plazos por lote este numero BAJA solo cuando un lote se termina, y
     * por eso no se puede leer de `ventas.cuota_mensual`.
     */
    public function cuotaDelMes(): Monto
    {
        $total = Monto::cero();

        foreach ($this->lotes as $lote) {
            if ($lote->cuota instanceof Monto) {
                $total = $total->sumar($lote->cuota);
            }
        }

        return $total;
    }

    /**
     * ¿Hay que mostrar la escalera por lote, o alcanza con un solo plan?
     */
    public function tieneVariosLotes(): bool
    {
        return count($this->lotes) > 1;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Los copropietarios, sin el titular (R8).
     *
     * @return list<Cliente>
     */
    private static function acompanantes(Venta $venta, ?Cliente $titular): array
    {
        $otros = [];

        foreach ($venta->clientes as $cliente) {
            if ($titular instanceof Cliente && (int) $cliente->getKey() === (int) $titular->getKey()) {
                continue;
            }

            /*
             * 🔴 22-ago-2026. Quien cedió sus derechos SIGUE en `clientes`
             * —su fila no se borra, ver `CambioDeTitular`— pero ya no es
             * dueño de nada. Sin este filtro el estado de cuenta que se le
             * entrega al cliente lo imprime bajo «Copropietarios», y el
             * papel dice algo que la pantalla no dice.
             */
            if ($cliente->getAttribute('pivot')?->getAttribute('titular_hasta') !== null) {
                continue;
            }

            $otros[] = $cliente;
        }

        return $otros;
    }
}
