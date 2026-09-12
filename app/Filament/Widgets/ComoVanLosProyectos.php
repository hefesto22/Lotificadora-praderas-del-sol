<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
use App\Models\Cuota;
use App\Models\Devolucion;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Support\ProyectoActivo;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Override;

/**
 * Lo que el proyecto costó, contra lo que ya volvió — 11-sep-2026.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * «Hoy hay que sumar a mano lo cobrado y lo gastado para saber cómo va el
 * proyecto.» Los dos números existían desde el 11-ago —los gastos viven en la
 * pestaña del proyecto y lo cobrado en el Escritorio— pero en pantallas
 * distintas, así que la resta la hacía alguien con una calculadora, cuando se
 * acordaba.
 *
 * ═══ 🔴 POR QUE ES UN WIDGET APARTE Y NO CUATRO STATS MAS EN `ComoVaElNegocio` ═══
 *
 * Por dos razones, y las dos importan:
 *
 *  1. `ComoVaElNegocio` lo ve **quien puede ver expedientes**, y eso incluye al
 *     receptor. Cuánto costó el desarrollo y cuánto se lleva recuperado es
 *     información del dueño, no de la ventanilla. Este se cuelga de
 *     `ViewAny:Gasto`, que es el permiso que ya separa esa frontera.
 *  2. El propio docblock de `ComoVaElNegocio` dice «son cuatro y no diez a
 *     propósito: un tablero con veinte cifras no se lee, se ignora». Meterle
 *     cuatro más lo convertía justo en lo que ese comentario evita.
 *
 * ═══ 🔴 ESTO ES CAJA, NO UTILIDAD CONTABLE ═══
 *
 * «Invertido» son los gastos que se registraron; «recuperado» es el dinero que
 * entró por la puerta. Un proyecto recién comprado y sin vender sale en rojo, y
 * **eso es correcto**: la pregunta que contesta este tablero es «¿ya recuperé
 * lo que puse?», no «¿cuánta utilidad devengué?». Para lo segundo hay que
 * repartir el costo del terreno entre los lotes vendidos y los que no, que es
 * otra cuenta y pide un contador.
 *
 * Por eso el cuarto cuadro existe: sin «falta por cobrar», un proyecto sano a
 * mitad de plazo se ve igual que uno que no vendió nada.
 *
 * ═══ LO QUE NO SE CUENTA, Y YA ESTABA DECIDIDO ═══
 *
 * Las entregas a socios NO son costo. Lo dice `EntregaASocio`: «un gasto es lo
 * que el desarrollo costó y se resta antes de saber cuánto hay para repartir;
 * esto sale de esa utilidad ya calculada». Sumarlas acá lo restaría dos veces y
 * el proyecto parecería menos rentable cada vez que un socio retira lo suyo.
 *
 * Las devoluciones SÍ se restan de lo recuperado: es dinero que volvió al
 * cliente. Es la misma cuenta que hace `CorteDeCajaDeHoy` con el egreso del día.
 *
 * ═══ ⚠️ EL RECIBO DE UNA SEÑA NO CUELGA DE UNA VENTA ═══
 *
 * `recibos_cuelgan_de_un_compromiso_chk` (R13) admite `venta_id` en NULL
 * mientras haya `compromiso_id`: es el caso de la seña de un apartado, que
 * todavía no tiene contrato. Un `join` contra `ventas` se las comería en
 * silencio —dinero que entró y no aparecería en ningún proyecto—, así que el
 * proyecto sale de `COALESCE(ventas.proyecto_id, compromisos.proyecto_id)`.
 *
 * ═══ 🔴 `Monto` NO ADMITE NEGATIVOS, Y ACA ESO PESA ═══
 *
 * `Monto::restar()` LANZA cuando el resultado daría menos de cero, y eso es a
 * propósito: en este dominio el dinero nunca es negativo. Pero un proyecto que
 * todavía no recuperó lo invertido es el caso **normal** —es el estado de casi
 * cualquier lotificadora a mitad de plazo—, así que un `recuperado.restar(
 * invertido)` escrito de la forma obvia revienta el Escritorio el primer día.
 *
 * Por eso cada resta de acá va siempre del mayor al menor, se pregunta antes
 * con `menorQue()`, y **el signo lo pone el rótulo**, no el número.
 *
 * ⚠️ `reorder()` antes de cada agregado: §9 del catálogo. Un `orderBy` heredado
 * sobrevive al `SUM` y Postgres lo rechaza con 42803.
 */
class ComoVanLosProyectos extends StatsOverviewWidget
{
    #[Override]
    protected ?string $pollingInterval = null;

    /**
     * Debajo del arqueo del día (2) y encima del disco (5): lo primero es lo
     * de hoy, esto es lo del proyecto entero.
     */
    #[Override]
    protected static ?int $sort = 3;

    #[Override]
    protected int|string|array $columnSpan = 'full';

    /*
     * 🔴 CUATRO COLUMNAS SIEMPRE, AUNQUE EL RENGLON TENGA TRES CUADROS.
     *
     * Filament decide solas las columnas por la CANTIDAD de cifras: tres o
     * seis dan tres columnas, cuatro dan cuatro. Con los cuatro tableros del
     * Escritorio uno abajo del otro, eso hacía que las tarjetas de «La caja
     * de hoy» fueran más anchas que las de «El mes» —tres contra cuatro— y
     * que nada se alineara en vertical.
     *
     * Peor: el corte de caja dibuja un cuarto cuadro solo los días que hubo
     * egreso, así que el ancho de sus tarjetas CAMBIABA de un día para otro.
     *
     * Fijo en cuatro, todas las tarjetas del Escritorio miden lo mismo y
     * están en la misma cuadrícula. Un renglón con tres deja la cuarta celda
     * vacía, y eso se lee como una rejilla, no como un error.
     */
    #[Override]
    /*
     * ⚠️ El tipo va IGUAL que en el padre —`int|array|null`— aunque acá el
     * null no se use nunca: PHP no deja estrechar el tipo de una propiedad
     * al heredarla, y `int|array` sería un error fatal al cargar la clase.
     */
    protected int|array|null $columns = 4;

    /**
     * El título de la sección — 11-sep-2026.
     *
     * Hasta el 11-sep el Escritorio eran tres filas de cuadros del mismo
     * tamaño y el mismo peso, así que nada parecía más importante que lo
     * demás y había que leerlas todas para encontrar la que se buscaba.
     *
     * ⚠️ Acá es un MÉTODO y no la propiedad `$heading` de los otros tres
     * widgets, porque cambia: «El proyecto» cuando se está mirando uno; «Los
     * proyectos» cuando son todos. Con tres desarrollos, un título en
     * singular sobre la suma de los tres es el mismo error que las cifras
     * que este widget arregla.
     */
    #[Override]
    protected function getHeading(): ?string
    {
        return app(ProyectoActivo::class)->hayUno() ? 'El proyecto' : 'Los proyectos';
    }

    /**
     * Quien ve los gastos. El receptor no los ve, y por eso tampoco ve esto.
     */
    #[Override]
    public static function canView(): bool
    {
        return auth()->user()?->can('ViewAny:Gasto') === true;
    }

    /**
     * @return array<int, Stat>
     */
    #[Override]
    protected function getStats(): array
    {
        $invertido = $this->soloElElegido($this->gastadoPorProyecto());
        $cobrado = $this->soloElElegido($this->cobradoPorProyecto());
        $devuelto = $this->soloElElegido($this->devueltoPorProyecto());
        $porCobrar = $this->soloElElegido($this->porCobrarPorProyecto());

        $recuperado = [];

        foreach ($this->proyectosConMovimiento($invertido, $cobrado) as $id) {
            $recuperado[$id] = ($cobrado[$id] ?? Monto::cero())->restar($devuelto[$id] ?? Monto::cero());
        }

        $totalInvertido = $this->sumar($invertido);
        $totalRecuperado = $this->sumar($recuperado);
        $totalDevuelto = $this->sumar($devuelto);
        $totalPorCobrar = $this->sumar($porCobrar);

        // 🔴 Del mayor al menor, siempre: ver el docblock de la clase.
        $alcanzo = ! $totalRecuperado->menorQue($totalInvertido);

        $diferencia = $alcanzo
            ? $totalRecuperado->restar($totalInvertido)
            : $totalInvertido->restar($totalRecuperado);

        return [
            Stat::make('Invertido', $totalInvertido->formateado())
                ->description($this->cuantosGastos())
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color($totalInvertido->esCero() ? 'gray' : 'warning'),

            Stat::make('Recuperado', $totalRecuperado->formateado())
                ->description($this->cuantoSeLlevaRecuperado($totalInvertido, $totalRecuperado, $totalDevuelto))
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color($totalRecuperado->esCero() ? 'gray' : 'success'),

            /*
             * 🔴 SIN GASTOS CARGADOS NO HAY RESULTADO, Y DECIRLO IMPORTA.
             *
             * Con `invertido` en cero la resta da todo lo cobrado, así que
             * el cuadro decía «Ya se recuperó, y sobra L. 7,810,997.00» —en
             * verde, con su palomita— sobre un proyecto donde **nadie
             * cargó un solo gasto todavía**. Es una cifra correcta y una
             * conclusión falsa, que es la peor clase de número en un
             * tablero: se cree.
             *
             * Se vio en pruebas el 11-sep-2026, el mismo día que entró.
             */
            $totalInvertido->esCero()
                ? Stat::make('Sin gastos cargados', '—')
                    ->description('Sin gastos no hay contra qué comparar lo cobrado')
                    ->descriptionIcon('heroicon-m-exclamation-circle')
                    ->color('gray')
                : Stat::make($this->rotuloDelResultado($alcanzo, $diferencia), $diferencia->formateado())
                    ->description($this->porProyecto($invertido, $recuperado))
                    ->descriptionIcon($alcanzo ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle')
                    ->color($alcanzo ? 'success' : 'danger'),

            Stat::make('Falta por cobrar', $totalPorCobrar->formateado())
                ->description($this->siEntraTodo($totalInvertido, $totalRecuperado, $totalPorCobrar))
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),
        ];
    }

    // ─── Los textos de abajo de cada cifra ────────────────────────────

    private function cuantosGastos(): string
    {
        $cuantos = Gasto::query()->reorder()->count();

        return $cuantos === 0
            ? 'Todavía no se ha registrado ningún gasto'
            : sprintf('en %d gasto%s registrado%s', $cuantos, $cuantos === 1 ? '' : 's', $cuantos === 1 ? '' : 's');
    }

    /**
     * El signo del resultado vive acá, porque el número no lo puede llevar.
     *
     * «Y sobra L 0.00» cuando cae justo sería una cifra correcta y una frase
     * tonta, así que el empate tiene su propio rótulo.
     */
    private function rotuloDelResultado(bool $alcanzo, Monto $diferencia): string
    {
        if ($diferencia->esCero()) {
            return 'Va justo a la par';
        }

        return $alcanzo ? 'Ya se recuperó, y sobra' : 'Falta por recuperar';
    }

    private function cuantoSeLlevaRecuperado(Monto $invertido, Monto $recuperado, Monto $devuelto): string
    {
        $nota = $devuelto->esCero()
            ? ''
            : sprintf(' · menos %s devueltos', $devuelto->formateado());

        if ($invertido->esCero()) {
            return 'En toda la vida del proyecto'.$nota;
        }

        /*
         * bcmath y no float, aunque sea para un texto: el §8.3.1 no hace
         * excepciones, y un porcentaje calculado con float al lado de cifras
         * calculadas con bcmath es como se cuelan los dos criterios en un
         * mismo archivo. `$invertido` no es cero: lo garantiza el `if` de
         * arriba, que es lo único que protege de una división por cero.
         */
        $porciento = bcdiv(bcmul($recuperado->valor, '100', 4), $invertido->valor, 0);

        return sprintf('%s%% de lo invertido', $porciento).$nota;
    }

    /**
     * El desglose, y solo cuando hay más de un proyecto.
     *
     * Con uno solo, repetir su nombre debajo de una cifra que ya es la suya no
     * agrega nada: dice el nombre y ya.
     *
     * @param array<int, Monto> $invertido
     * @param array<int, Monto> $recuperado
     */
    private function porProyecto(array $invertido, array $recuperado): string
    {
        $nombres = $this->nombresDeProyecto();
        $ids = $this->proyectosConMovimiento($invertido, $recuperado);

        if ($ids === []) {
            return 'Sin movimientos todavía';
        }

        /*
         * Con un solo proyecto se explica el número en vez de repetir el
         * nombre: el encabezado del Escritorio ya dice de qué residencial
         * es, y decirlo dos veces en la misma pantalla no informa, ensucia.
         */
        if (count($ids) === 1) {
            return 'Lo recuperado menos lo invertido';
        }

        $partes = [];

        foreach ($ids as $id) {
            $puso = $invertido[$id] ?? Monto::cero();
            $volvio = $recuperado[$id] ?? Monto::cero();

            // 🔴 Del mayor al menor: `restar()` lanza si daría negativo.
            $alcanzo = ! $volvio->menorQue($puso);
            $saldo = $alcanzo ? $volvio->restar($puso) : $puso->restar($volvio);

            $partes[] = sprintf(
                '%s %s%s',
                $nombres[$id] ?? 'Proyecto '.$id,
                $alcanzo ? '+' : '−',
                $saldo->formateado(),
            );
        }

        return implode(' · ', $partes);
    }

    /**
     * El cuadro que evita confundir «va mal» con «va a medio camino».
     *
     * Sin esto, un proyecto sano a mitad de plazo se ve igual que uno que no
     * vendió nada: los dos en rojo, porque los dos gastaron más de lo que han
     * cobrado. La diferencia está en lo que falta por entrar.
     */
    private function siEntraTodo(Monto $invertido, Monto $recuperado, Monto $porCobrar): string
    {
        if ($porCobrar->esCero()) {
            return 'No queda saldo pendiente en expedientes vigentes';
        }

        // Sin gastos cargados, «cierra en +X» sería la misma conclusión
        // falsa del cuadro de al lado: no hay contra qué cerrar.
        if ($invertido->esCero()) {
            return 'En cuotas de expedientes vigentes';
        }

        $conTodo = $recuperado->sumar($porCobrar);

        // 🔴 Del mayor al menor, igual que arriba.
        $cierraArriba = ! $conTodo->menorQue($invertido);
        $cierre = $cierraArriba ? $conTodo->restar($invertido) : $invertido->restar($conTodo);

        return sprintf(
            'Si entra todo, el proyecto cierra en %s%s',
            $cierraArriba ? '+' : '−',
            $cierre->formateado(),
        );
    }

    // ─── Las cuatro consultas ─────────────────────────────────────────

    /**
     * @return array<int, Monto>
     */
    private function gastadoPorProyecto(): array
    {
        return $this->enMontos(
            Gasto::query()
                ->reorder()
                ->selectRaw('proyecto_id AS proyecto, COALESCE(SUM(monto), 0) AS total')
                ->groupBy('proyecto')
                ->pluck('total', 'proyecto'),
        );
    }

    /**
     * @return array<int, Monto>
     */
    private function cobradoPorProyecto(): array
    {
        return $this->enMontos(
            Recibo::query()
                ->reorder()
                ->whereNull('recibos.anulado_el')
                ->leftJoin('ventas', 'ventas.id', '=', 'recibos.venta_id')
                ->leftJoin('compromisos', 'compromisos.id', '=', 'recibos.compromiso_id')
                ->selectRaw('COALESCE(ventas.proyecto_id, compromisos.proyecto_id) AS proyecto, COALESCE(SUM(recibos.monto), 0) AS total')
                ->groupBy('proyecto')
                ->pluck('total', 'proyecto'),
        );
    }

    /**
     * @return array<int, Monto>
     */
    private function devueltoPorProyecto(): array
    {
        return $this->enMontos(
            Devolucion::query()
                ->reorder()
                ->leftJoin('ventas', 'ventas.id', '=', 'devoluciones.venta_id')
                ->leftJoin('compromisos', 'compromisos.id', '=', 'devoluciones.compromiso_id')
                ->selectRaw('COALESCE(ventas.proyecto_id, compromisos.proyecto_id) AS proyecto, COALESCE(SUM(devoluciones.monto_devuelto), 0) AS total')
                ->groupBy('proyecto')
                ->pluck('total', 'proyecto'),
        );
    }

    /**
     * Lo que falta cobrar, solo de expedientes vigentes y lotes vivos.
     *
     * `deLotesVivos()` por lo mismo que en `ComoVaElNegocio`: la cuota que
     * sobrevive a una rescisión no se va a pagar nunca, y contarla diría que
     * al proyecto todavía le va a entrar un dinero que ya no le entra.
     *
     * @return array<int, Monto>
     */
    private function porCobrarPorProyecto(): array
    {
        return $this->enMontos(
            Cuota::query()
                ->reorder()
                ->deLotesVivos()
                ->whereColumn('cuotas.monto_pagado', '<', 'cuotas.monto')
                // El `join` ya trae la venta: filtrar acá es una condición
                // menos que un `whereIn` con subconsulta, y el mismo resultado.
                ->join('ventas', 'ventas.id', '=', 'cuotas.venta_id')
                ->where('ventas.estado', EstadoVenta::Vigente)
                ->selectRaw('ventas.proyecto_id AS proyecto, COALESCE(SUM(cuotas.monto - cuotas.monto_pagado), 0) AS total')
                ->groupBy('proyecto')
                ->pluck('total', 'proyecto'),
        );
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Las filas de un `pluck` agrupado, en montos con su id de proyecto.
     *
     * Una fila sin proyecto se descarta en vez de sumarse al total: aparecería
     * como «Proyecto 0» en el desglose y nadie sabría de dónde salió. Por el
     * CHECK de R13 no debería existir ninguna.
     *
     * @param Collection<array-key, mixed> $filas
     *
     * @return array<int, Monto>
     */
    private function enMontos(Collection $filas): array
    {
        $montos = [];

        foreach ($filas as $proyecto => $total) {
            /*
             * Sin `is_int() || is_string()`: la clave de una colección es
             * `array-key`, o sea `int|string` y nada más, así que preguntarlo
             * es una condición que PHPStan sabe verdadera —«is_string() with
             * string will always evaluate to true»—. El `(int)` alcanza para
             * las dos.
             */
            $id = (int) $proyecto;

            if ($id <= 0) {
                continue;
            }

            $montos[$id] = new Monto(is_string($total) || is_int($total) ? $total : '0');
        }

        return $montos;
    }

    /**
     * Deja solo el proyecto que se está mirando — 11-sep-2026.
     *
     * ⚠️ Recorta en PHP y no en SQL, y es a propósito: las cuatro consultas
     * ya vienen agrupadas por proyecto, así que son tantas filas como
     * desarrollos tenga la lotificadora —tres, cinco— y filtrarlas acá cuesta
     * nada. Meterle la condición a cada `groupBy` serían cuatro `when()` más,
     * cuatro lugares donde olvidarse de uno, y el mismo resultado.
     *
     * @param array<int, Monto> $montos
     *
     * @return array<int, Monto>
     */
    private function soloElElegido(array $montos): array
    {
        $id = app(ProyectoActivo::class)->id();

        if ($id === null) {
            return $montos;
        }

        return array_filter(
            $montos,
            static fn (int $proyecto): bool => $proyecto === $id,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<int, Monto> $montos
     */
    private function sumar(array $montos): Monto
    {
        $total = Monto::cero();

        foreach ($montos as $monto) {
            $total = $total->sumar($monto);
        }

        return $total;
    }

    /**
     * Los proyectos que aparecen en cualquiera de los dos lados, ordenados.
     *
     * Un proyecto con gastos y sin una sola venta tiene que salir —es el que
     * más urge mirar—, y uno vendiéndose sin gastos cargados también.
     *
     * @param array<int, Monto> $unos
     * @param array<int, Monto> $otros
     *
     * @return list<int>
     */
    private function proyectosConMovimiento(array $unos, array $otros): array
    {
        $ids = array_keys($unos + $otros);

        // `array_keys()` ya devuelve una lista y `sort()` la reindexa en el
        // lugar, así que un `array_values()` acá no hace nada — y PHPStan lo
        // dice con todas las letras: «call has no effect».
        sort($ids);

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    private function nombresDeProyecto(): array
    {
        $nombres = [];

        foreach (Proyecto::query()->reorder()->orderBy('id')->get(['id', 'nombre']) as $proyecto) {
            $nombre = $proyecto->getAttribute('nombre');

            if (is_string($nombre)) {
                $nombres[(int) $proyecto->getKey()] = $nombre;
            }
        }

        return $nombres;
    }
}
