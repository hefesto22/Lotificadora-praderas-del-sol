<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Models\Devolucion;
use App\Models\Gasto;
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
 *
 * ═══ LO QUE SALIO TAMBIEN CUENTA (11-ago-2026) ═══
 *
 * Hasta hoy el widget sumaba solo ingresos, y el número que decía «es lo que
 * tiene que estar en la caja» era falso cualquier día que se pagara algo en
 * efectivo. Ahora se restan los dos egresos que existen: los **gastos** del
 * proyecto y las **devoluciones de seña**, que estaba anotado como pendiente
 * desde el 10-ago.
 *
 * Solo se le muestran a quien ve el arqueo completo. Un receptor ve lo que
 * cobró él, y de la caja de la administración no registra ni decide nada.
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

        /*
         * La suma viene ARMADA desde acá y no adentro del método: `selectRaw()`
         * está tipado `literal-string`, así que una expresión con una variable
         * interpolada es un error de PHPStan —y la regla no es capricho: ese
         * parámetro va crudo al SQL—. El alias `total` es lo que vuelve
         * intercambiables a las dos tablas.
         */
        $gastos = $this->egresoEnEfectivo(
            Gasto::query()->reorder()->selectRaw('COALESCE(SUM(monto), 0) AS total'),
        );

        $devoluciones = $this->egresoEnEfectivo(
            Devolucion::query()->reorder()->selectRaw('COALESCE(SUM(monto_devuelto), 0) AS total'),
        );

        $egresos = $gastos->sumar($devoluciones);

        return [
            Stat::make($this->rotuloDelTotal(), $total->formateado())
                ->description($this->quienesCobraron())
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($total->esCero() ? 'gray' : 'success'),

            Stat::make('En efectivo', $efectivo->formateado())
                ->description($this->loQueQuedaEnLaCaja($efectivo, $egresos))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($efectivo->esCero() ? 'gray' : 'success'),

            Stat::make('Por banco y tarjeta', $banco->formateado())
                ->description($this->desglosePorForma($porForma))
                ->descriptionIcon('heroicon-m-building-library')
                ->color($banco->esCero() ? 'gray' : 'info'),

            /*
             * El cuarto cuadro aparece solo cuando hay algo que contar. Un
             * «Salió de la caja: L 0.00» todos los días entrena al ojo a
             * saltarse el renglón, que es justo el que hay que mirar el día
             * que dice otra cosa.
             */
            ...($egresos->esCero() ? [] : [
                Stat::make('Salió de la caja hoy', $egresos->formateado())
                    ->description($this->desgloseDelEgreso($gastos, $devoluciones))
                    ->descriptionIcon('heroicon-m-arrow-up-tray')
                    ->color('danger'),
            ]),
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

    /**
     * Lo que sale en efectivo hoy, de una tabla de egresos.
     *
     * ⚠️ La suma la hace Postgres —el `selectRaw` lo pone el llamador— y entra
     * a `Monto` como string. `->sum()` del query builder castea a float y el
     * §8.3.1 lo prohíbe en el camino del dinero.
     *
     * ⚠️ Nunca se filtra por `created_by`: un receptor no ve este número —lo
     * corta `soloLoMio()` antes de pedirlo— y la administradora tiene que ver
     * la caja entera, no la parte que registró ella.
     *
     * @param Builder<Gasto>|Builder<Devolucion> $consulta
     */
    private function egresoEnEfectivo(Builder $consulta): Monto
    {
        if ($this->soloLoMio()) {
            return Monto::cero();
        }

        /*
         * `value()` y no `pluck()->first()`: traer una colección de un
         * elemento para quedarse con el primero es una consulta que ya sabía
         * pedir una sola fila. Larastan lo marca
         * (`noUnnecessaryCollectionCall`) y tiene razón.
         *
         * El `select` ya viene puesto por el llamador, así que `value()` NO lo
         * pisa: `onceWithColumns` solo reemplaza las columnas cuando no hay
         * ninguna.
         */
        $total = $consulta
            ->whereDate('fecha', today())
            ->where('forma_pago', FormaDePago::Efectivo->value)
            ->value('total');

        return new Monto(is_string($total) || is_int($total) ? $total : '0');
    }

    /**
     * La frase que hace verdadero el número de arriba.
     *
     * Sin egresos, «en efectivo» ES lo que tiene que estar en la caja. Con
     * egresos ya no, y decirlo igual sería mandar a alguien a cuadrar contra
     * un número que nadie va a encontrar.
     */
    private function loQueQuedaEnLaCaja(Monto $efectivo, Monto $egresos): string
    {
        if ($egresos->esCero()) {
            return 'Es lo que tiene que estar en la caja al cerrar';
        }

        // Puede pasar, y no es un error: se paga con el efectivo que quedó de
        // ayer. `Monto::restar()` no admite negativos, así que se pregunta
        // antes en vez de atrapar la excepción.
        if ($egresos->mayorQue($efectivo)) {
            return sprintf(
                'Salieron %s, más de lo que entró: la diferencia sale del efectivo con que arrancó el día',
                $egresos->formateado(),
            );
        }

        return sprintf(
            'Menos %s que salieron: en la caja tienen que quedar %s',
            $egresos->formateado(),
            $efectivo->restar($egresos)->formateado(),
        );
    }

    private function desgloseDelEgreso(Monto $gastos, Monto $devoluciones): string
    {
        $partes = [];

        if (! $gastos->esCero()) {
            $partes[] = 'Gastos '.$gastos->formateado();
        }

        if (! $devoluciones->esCero()) {
            $partes[] = 'Devoluciones y rescisiones '.$devoluciones->formateado();
        }

        return implode(' · ', $partes);
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
