<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Recibo;
use App\Models\Venta;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Los cuatro números que se miran al entrar.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * Hasta el 8-ago-2026 el Escritorio tenía la bienvenida de Filament y un
 * medidor de disco. Todo lo demás vivía un clic adentro, en el expediente de
 * un cliente a la vez, así que la pregunta «¿cómo venimos este mes?» no tenía
 * dónde contestarse sin abrir contratos uno por uno.
 *
 * Son cuatro y no diez a propósito: un tablero con veinte cifras no se lee,
 * se ignora. Estos cuatro contestan lo que se pregunta todos los días —
 * cuánto entró, cuánto está atrasado, cuánto falta por cobrar y cuánto queda
 * por vender.
 *
 * ═══ EL DINERO NO PASA POR FLOAT (§8.3.1) ═══
 *
 * Las sumas salen de la base con `selectRaw` y entran directo a un `Monto`,
 * que trabaja con bcmath sobre strings. Nada de `->money()` de Filament ni de
 * un summarizer de tabla: los dos castean a float en el camino.
 *
 * ⚠️ Y los recibos ANULADOS no cuentan. Un recibo anulado conserva su fila y
 * su número —la serie no puede tener huecos— pero su dinero volvió a deberse.
 * Sumarlo diría que entró plata que no entró.
 */
class ComoVaElNegocio extends StatsOverviewWidget
{
    #[Override]
    protected ?string $pollingInterval = null;

    #[Override]
    protected static ?int $sort = 1;

    #[Override]
    protected int|string|array $columnSpan = 'full';

    /**
     * Lo ve quien puede ver expedientes: la administradora y el receptor.
     * Quien atiende también necesita saber cuánto se lleva cobrado hoy.
     */
    #[Override]
    public static function canView(): bool
    {
        return auth()->user()?->can('ViewAny:Venta') === true;
    }

    /**
     * @return array<int, Stat>
     */
    #[Override]
    protected function getStats(): array
    {
        return [
            $this->cobradoEsteMes(),
            $this->vencidoAHoy(),
            $this->porCobrar(),
            $this->inventario(),
        ];
    }

    // ─── Los cuatro ───────────────────────────────────────────────────

    private function cobradoEsteMes(): Stat
    {
        $inicio = today()->startOfMonth();
        $esteMes = $this->cobradoEntre($inicio->toDateString(), today()->endOfMonth()->toDateString());
        $mesPasado = $this->cobradoEntre(
            $inicio->copy()->subMonth()->toDateString(),
            $inicio->copy()->subMonth()->endOfMonth()->toDateString(),
        );

        $sube = $esteMes->mayorQue($mesPasado);
        $diferencia = $sube ? $esteMes->restar($mesPasado) : $mesPasado->restar($esteMes);

        return Stat::make('Cobrado este mes', $esteMes->formateado())
            ->description($mesPasado->esCero()
                ? 'El mes pasado no entró nada'
                : sprintf('%s %s que el mes pasado', $diferencia->formateado(), $sube ? 'más' : 'menos'))
            ->descriptionIcon($sube ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($sube || $mesPasado->esCero() ? 'success' : 'warning');
    }

    /**
     * Lo vencido de verdad: cuotas con fecha pasada que todavía deben algo.
     *
     * R2 — el atraso NO genera cargo. Este número es lo que se debía el día
     * del vencimiento y sigue sin entrar; no lleva mora sumada porque no hay
     * mora.
     */
    private function vencidoAHoy(): Stat
    {
        $vencidas = Cuota::query()
            ->reorder()
            // La cuota que sobrevive a una rescision nunca se va a pagar: sin
            // esto se quedaria clavada en «vencido» para siempre.
            ->deLotesVivos()
            ->whereColumn('monto_pagado', '<', 'monto')
            ->whereDate('fecha_vencimiento', '<', today())
            ->whereIn('venta_id', $this->ventasVigentes());

        $monto = $this->sumarSaldo($vencidas->clone());
        $expedientes = (int) $vencidas->clone()->distinct()->count('venta_id');

        return Stat::make('Vencido a hoy', $monto->formateado())
            ->description($monto->esCero()
                ? 'Nadie está atrasado'
                : sprintf('en %d expediente%s', $expedientes, $expedientes === 1 ? '' : 's'))
            ->descriptionIcon($monto->esCero() ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
            ->color($monto->esCero() ? 'success' : 'danger');
    }

    private function porCobrar(): Stat
    {
        $pendientes = Cuota::query()
            ->reorder()
            ->deLotesVivos()
            ->whereColumn('monto_pagado', '<', 'monto')
            ->whereIn('venta_id', $this->ventasVigentes());

        $vigentes = Venta::query()->where('estado', EstadoVenta::Vigente)->count();

        return Stat::make('Por cobrar', $this->sumarSaldo($pendientes)->formateado())
            ->description(sprintf('en %d expediente%s vigente%s', $vigentes, $vigentes === 1 ? '' : 's', $vigentes === 1 ? '' : 's'))
            ->descriptionIcon('heroicon-m-banknotes')
            ->color('info');
    }

    private function inventario(): Stat
    {
        $porEstado = Lote::query()
            ->reorder()
            ->selectRaw('estado, COUNT(*) AS cuantos')
            ->groupBy('estado')
            ->pluck('cuantos', 'estado');

        $cuantos = static fn (EstadoLote $estado): int => (int) ($porEstado[$estado->value] ?? 0);

        $disponibles = $cuantos(EstadoLote::Disponible);
        $total = (int) $porEstado->sum();

        return Stat::make('Lotes disponibles', sprintf('%d de %d', $disponibles, $total))
            ->description(sprintf(
                '%d vendidos · %d apartados',
                $cuantos(EstadoLote::Vendido),
                $cuantos(EstadoLote::Apartado),
            ))
            ->descriptionIcon('heroicon-m-map')
            ->color($disponibles > 0 ? 'primary' : 'gray');
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Lo cobrado entre dos fechas, sin los anulados.
     */
    private function cobradoEntre(string $desde, string $hasta): Monto
    {
        /** @var string|int|null $suma */
        $suma = Recibo::query()
            ->reorder()
            ->whereNull('anulado_el')
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(monto), 0) AS cobrado')
            ->value('cobrado');

        return new Monto(is_string($suma) || is_int($suma) ? $suma : '0');
    }

    /**
     * Los expedientes que todavía se cobran.
     *
     * Un subquery y no un `whereHas` con closure: sobre el `Builder` genérico
     * el closure llega sin tipo y PHPStan nivel 7 no lo perdona. Además deja
     * la condición escrita UNA vez para los dos números que la necesitan.
     *
     * @return Builder<Venta>
     */
    private function ventasVigentes(): Builder
    {
        return Venta::query()->reorder()->select('id')->where('estado', EstadoVenta::Vigente);
    }

    /**
     * ⚠️ `reorder()` antes de un agregado: §9 del catálogo. Un `orderBy` que
     * viene de una relación sobrevive al `SUM` y Postgres lo rechaza con
     * 42803 («column must appear in the GROUP BY clause»).
     *
     * @param Builder<Cuota> $cuotas
     */
    private function sumarSaldo(Builder $cuotas): Monto
    {
        /** @var string|int|null $suma */
        $suma = $cuotas
            ->reorder()
            ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0) AS pendiente')
            ->value('pendiente');

        return new Monto(is_string($suma) || is_int($suma) ? $suma : '0');
    }
}
