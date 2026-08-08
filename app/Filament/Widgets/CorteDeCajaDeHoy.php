<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Models\Recibo;
use App\Models\User;
use App\Support\Roles;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Cuánto entró hoy, y cuánto de eso tiene que estar en la caja.
 *
 * ═══ POR QUE EL EFECTIVO VA APARTE ═══
 *
 * Porque es el único número que alguien tiene que CONTAR al final del día.
 * Una transferencia se cruza contra el banco cuando llegue el estado de
 * cuenta; el efectivo se cuadra hoy, sobre el mostrador, y si no cuadra hay
 * que saberlo antes de que la persona se vaya a su casa.
 *
 * ═══ CADA RECEPTOR VE LO SUYO ═══
 *
 * `Roles::RECEPTOR` promete que un receptor «NO ve el arqueo de otro
 * receptor». Hasta hoy esa promesa no la cumplía nada: la lista de recibos se
 * ve entera. Acá sí: quien no administra ve solo lo que cobró él, y la
 * administradora ve el total del día con el desglose por persona.
 *
 * ⚠️ Los recibos ANULADOS no cuentan. Su número sigue en la serie, pero su
 * dinero volvió a deberse y no está en la caja.
 */
class CorteDeCajaDeHoy extends StatsOverviewWidget
{
    #[Override]
    protected ?string $pollingInterval = null;

    #[Override]
    protected static ?int $sort = 2;

    #[Override]
    protected int|string|array $columnSpan = 'full';

    #[Override]
    public static function canView(): bool
    {
        return auth()->user()?->can('ViewAny:Recibo') === true;
    }

    /**
     * @return array<int, Stat>
     */
    #[Override]
    protected function getStats(): array
    {
        $porForma = $this->cobradoHoyPorForma();

        $efectivo = $porForma[FormaDePago::Efectivo->value] ?? Monto::cero();
        $total = Monto::cero();

        foreach ($porForma as $monto) {
            $total = $total->sumar($monto);
        }

        $banco = $total->restar($efectivo);

        return [
            Stat::make($this->rotuloDelTotal(), $total->formateado())
                ->description($this->quienesCobraron())
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($total->esCero() ? 'gray' : 'success'),

            Stat::make('En efectivo', $efectivo->formateado())
                ->description('Es lo que tiene que estar en la caja al cerrar')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($efectivo->esCero() ? 'gray' : 'success'),

            Stat::make('Por banco y tarjeta', $banco->formateado())
                ->description($this->desglosePorForma($porForma))
                ->descriptionIcon('heroicon-m-building-library')
                ->color($banco->esCero() ? 'gray' : 'info'),
        ];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @return array<string, Monto>
     */
    private function cobradoHoyPorForma(): array
    {
        $filas = $this->deHoy()
            ->selectRaw('forma_pago, COALESCE(SUM(monto), 0) AS cobrado')
            ->groupBy('forma_pago')
            ->pluck('cobrado', 'forma_pago');

        $porForma = [];

        foreach ($filas as $forma => $cobrado) {
            $porForma[(string) $forma] = new Monto(is_string($cobrado) || is_int($cobrado) ? $cobrado : '0');
        }

        return $porForma;
    }

    private function quienesCobraron(): string
    {
        $recibos = (int) $this->deHoy()->count();

        if ($recibos === 0) {
            return 'Todavía no se ha cobrado nada hoy';
        }

        $cuantos = sprintf('%d recibo%s', $recibos, $recibos === 1 ? '' : 's');

        if ($this->soloLoMio()) {
            return $cuantos.' tuyos';
        }

        $porPersona = $this->deHoy()
            ->selectRaw('created_by, COALESCE(SUM(monto), 0) AS cobrado')
            ->groupBy('created_by')
            ->pluck('cobrado', 'created_by');

        $nombres = User::query()
            ->whereIn('id', $porPersona->keys()->filter()->all())
            ->pluck('name', 'id');

        $partes = [];

        foreach ($porPersona as $usuario => $cobrado) {
            $nombre = $nombres[$usuario] ?? 'Sin usuario';
            $monto = new Monto(is_string($cobrado) || is_int($cobrado) ? $cobrado : '0');
            $partes[] = sprintf('%s %s', is_string($nombre) ? $nombre : 'Sin usuario', $monto->formateado());
        }

        return $partes === [] ? $cuantos : $cuantos.' · '.implode(' · ', $partes);
    }

    /**
     * @param array<string, Monto> $porForma
     */
    private function desglosePorForma(array $porForma): string
    {
        $partes = [];

        foreach ($porForma as $valor => $monto) {
            $forma = FormaDePago::tryFrom($valor);

            if (! $forma instanceof FormaDePago) {
                continue;
            }

            // El efectivo se muestra aparte: es el único que hay que contar.
            if ($forma === FormaDePago::Efectivo) {
                continue;
            }

            if ($monto->esCero()) {
                continue;
            }

            $partes[] = sprintf('%s %s', $forma->etiqueta(), $monto->formateado());
        }

        return $partes === [] ? 'Sin movimientos por banco hoy' : implode(' · ', $partes);
    }

    private function rotuloDelTotal(): string
    {
        return $this->soloLoMio() ? 'Cobrado por vos hoy' : 'Cobrado hoy';
    }

    /**
     * Un receptor ve solo lo que cobró él. La administradora ve todo.
     *
     * Se pregunta por el ROL y no por un permiso: «ver el arqueo de todos» no
     * es una acción sobre un recurso, y inventarle un permiso al cruce del
     * §9.E3 sería fabricar un `VerArqueo:Recibo` que ninguna política conoce.
     */
    private function soloLoMio(): bool
    {
        $usuario = auth()->user();

        if (! $usuario instanceof User) {
            return true;
        }

        return ! $usuario->hasRole([Roles::SUPER_ADMIN, Roles::ADMINISTRADORA]);
    }

    /**
     * @return Builder<Recibo>
     */
    private function deHoy(): Builder
    {
        $consulta = Recibo::query()
            ->reorder()
            ->whereNull('anulado_el')
            ->whereDate('fecha', today());

        if ($this->soloLoMio()) {
            $consulta->where('created_by', auth()->id());
        }

        return $consulta;
    }
}
