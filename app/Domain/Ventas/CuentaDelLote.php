<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;
use App\Models\Compromiso;
use App\Models\Cuota;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Como va UN lote del contrato. No escribe nada.
 *
 * ═══ POR QUE EL ESTADO DE CUENTA SE PARTE POR LOTE ═══
 *
 * Desde el 5-ago-2026 cada lote lleva su propio plazo y su propia cuota, asi
 * que «¿en que voy?» no tiene una sola respuesta: el lote a 12 meses puede
 * estar terminado mientras el de 48 recien va por la sexta. Un solo listado
 * corrido mezclaria dos escaleras distintas y nadie podria seguir la suya.
 *
 * Los totales del contrato son la suma de estos renglones, y por eso viven en
 * `EstadoDeCuenta` y no se calculan dos veces.
 */
final readonly class CuentaDelLote
{
    /**
     * @param list<Cuota> $cuotas el plan de ESTE lote, en orden
     */
    private function __construct(
        public Compromiso $compromiso,
        public string $codigo,
        public array $cuotas,
        public Monto $valor,
        public Monto $prima,
        public Monto $pagado,
        public Monto $saldo,
        public Monto $vencido,
        public int $cuotasVencidas,
        public int $cuotasPagadas,
        public ?Monto $cuota,
        public ?CarbonImmutable $termina,
    ) {}

    public static function de(Compromiso $compromiso): self
    {
        /** @var list<Cuota> $cuotas */
        $cuotas = $compromiso->cuotas()->get()->all();

        $pagado = Monto::cero();
        $saldo = Monto::cero();
        $vencido = Monto::cero();
        $vencidas = 0;
        $pagadas = 0;

        foreach ($cuotas as $cuota) {
            $pagado = $pagado->sumar($cuota->montoPagado());
            $saldo = $saldo->sumar($cuota->saldo());

            if ($cuota->estaPagada()) {
                $pagadas++;

                continue;
            }

            if ($cuota->estaVencida()) {
                $vencidas++;
                $vencido = $vencido->sumar($cuota->saldo());
            }
        }

        return new self(
            compromiso: $compromiso,
            codigo: (string) $compromiso->lote?->getAttribute('codigo'),
            cuotas: $cuotas,
            valor: $compromiso->montoValor(),
            prima: self::montoDe($compromiso, 'prima'),
            pagado: $pagado,
            saldo: $saldo,
            vencido: $vencido,
            cuotasVencidas: $vencidas,
            cuotasPagadas: $pagadas,
            /*
             * La cuota VIGENTE, que es la de la primera pendiente y no la del
             * contrato: un abono a capital (R21) pudo bajarla, y el papel
             * tiene que decir la que el cliente va a pagar el mes que viene.
             */
            cuota: self::cuotaVigente($cuotas),
            termina: self::ultimoVencimiento($cuotas),
        );
    }

    /**
     * ¿Este lote ya se termino de pagar?
     */
    public function estaCancelado(): bool
    {
        return $this->saldo->esCero();
    }

    public function estaAlDia(): bool
    {
        return $this->cuotasVencidas === 0;
    }

    /**
     * Los dias de atraso de la cuota vencida MAS VIEJA.
     *
     * Es informacion, no la base de ningun cobro: no hay mora (R2). El cliente
     * atrasado debe exactamente lo que debia el dia del vencimiento.
     */
    public function diasDeAtraso(): int
    {
        foreach ($this->cuotas as $cuota) {
            if ($cuota->estaVencida()) {
                return $cuota->diasDeAtraso();
            }
        }

        return 0;
    }

    /**
     * La proxima que vence, para el «su siguiente pago es el...».
     */
    public function proxima(): ?Cuota
    {
        foreach ($this->cuotas as $cuota) {
            if (! $cuota->estaPagada()) {
                return $cuota;
            }
        }

        return null;
    }

    public function cuotasTotales(): int
    {
        return count($this->cuotas);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param list<Cuota> $cuotas
     */
    private static function cuotaVigente(array $cuotas): ?Monto
    {
        foreach ($cuotas as $cuota) {
            if (! $cuota->estaPagada()) {
                return $cuota->montoTotal();
            }
        }

        return null;
    }

    /**
     * @param list<Cuota> $cuotas
     */
    private static function ultimoVencimiento(array $cuotas): ?CarbonImmutable
    {
        $ultima = $cuotas === [] ? null : $cuotas[count($cuotas) - 1];

        if (! $ultima instanceof Cuota) {
            return null;
        }

        $fecha = $ultima->getAttribute('fecha_vencimiento');

        return $fecha instanceof DateTimeInterface
            ? CarbonImmutable::parse($fecha->format('Y-m-d'))
            : null;
    }

    private static function montoDe(Compromiso $compromiso, string $columna): Monto
    {
        $valor = $compromiso->getAttribute($columna);

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }
}
