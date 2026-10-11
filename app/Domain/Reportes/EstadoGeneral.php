<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\ValueObjects\Monto;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Devolucion;
use App\Models\EntregaASocio;
use App\Models\Gasto;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Socio;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * El estado general del proyecto: toda su vida, y hacia dónde va.
 *
 * ═══ QUE PIDIO MAURICIO, TEXTUAL (10-OCT-2026) ═══
 *
 * Desde la pantalla del Estado mensual: «hagamos un estado de resultados
 * general de todo, ganancias, gastos, cuánto recibió cada socio, gastos, en
 * qué recibió, o sea todo eso general de todo el proyecto. Este será general
 * con proyección de lo que ya se vendió y falta de cobrar».
 *
 * ═══ 🔴 LOS NUMEROS SON LOS MISMOS DEL ESTADO MENSUAL ═══
 *
 * Qué es un ingreso, qué es un egreso y quién recibió el dinero lo deciden
 * las consultas de `CierreDelMes`, que esta clase llama con un rango que lo
 * abarca todo. No hay una segunda definición: **la suma de los cierres de
 * cada mes da exactamente lo que dice esta hoja**. Y la tabla año por año lo
 * prueba sola, porque sus renglones suman los totales de arriba — hay un test
 * que lo exige.
 *
 * Eso incluye la regla de la seña: no cuenta como ingreso hasta que el
 * apartado se formaliza (13-ago-2026, ver `RepartoDelMes`). La plata de los
 * apartados en curso sale aparte, en la cartera, para que no se pierda de
 * vista.
 *
 * ═══ LOS SOCIOS: SOBRE LA UTILIDAD DE TODA LA VIDA ═══
 *
 * Con la misma regla del mes (`RepartoEntreSocios`), pero sobre la utilidad
 * acumulada. **Puede no coincidir con la suma de los cierres mensuales**, y
 * no es un error: el cierre del mes reparte mes a mes y no descuenta los meses
 * en pérdida (lo pidió así Mauricio el 24-ago). Esta hoja sí los descuenta,
 * porque es la cuenta del proyecto entero. Si a un socio se le entregó más de
 * lo que le toca de la utilidad de toda la vida, sale como adelanto.
 *
 * ═══ LA PROYECCION: LO VENDIDO, NO LO SOÑADO ═══
 *
 *  - **Lo que falta cobrar** son las cuotas con saldo de los expedientes
 *    vigentes y de lotes vivos: la misma cuenta del Escritorio. Lo atrasado
 *    va aparte; lo que viene, año por año según su vencimiento.
 *  - **Lo que no se ha vendido NO se proyecta.** Mauricio, 10-oct-2026: «los
 *    que no se han vendido que no se proyecten, ya que unos quizás no
 *    pertenezcan al proyecto». El inventario cuenta cuántos lotes hay en cada
 *    estado, sin ponerles precio.
 *  - No cuenta gastos futuros: no hay de dónde sacarlos.
 *
 * ═══ SIN NEGATIVOS, COMO EN TODA LA CASA ═══
 *
 * `Monto` no admite negativos. Un resultado en rojo es `perdida`, con su
 * propio renglón, igual que en el estado mensual.
 */
final readonly class EstadoGeneral
{
    /**
     * Las consultas de `CierreDelMes` piden un rango. Este es el que lo abarca
     * todo: el estado general no recorta por fecha, cuenta lo registrado.
     */
    private const string DESDE_SIEMPRE = '1900-01-01';

    private const string HASTA_SIEMPRE = '9999-12-31';

    /**
     * @param array<string, Monto> $cobradoPorConcepto clave: el `value` del ConceptoDeRecibo
     * @param array<string, Monto> $cobradoPorForma clave: el `value` de la FormaDePago
     * @param list<array{nombre: string, porForma: array<string, Monto>, total: Monto}> $cobradoPorPersona
     * @param array<string, Monto> $gastadoPorCategoria clave: el `value` de la CategoriaDeGasto
     * @param list<array{nombre: string, monto: Monto}> $puestoPorSocios
     * @param array<int, array<string, Monto>> $entregadoPorForma clave: el id del socio
     * @param list<array{nombre: string, puso: Monto, entregado: Monto, porForma: array<string, Monto>}> $exSocios
     * @param list<array{anio: int, ingresos: Monto, egresos: Monto, utilidad: Monto, perdida: Monto}> $anioPorAnio
     * @param list<array{anio: int, cuotas: int, monto: Monto}> $porCobrarPorAnio
     * @param list<array{estado: EstadoLote, cuantos: int}> $lotes
     */
    private function __construct(
        public Proyecto $proyecto,
        public CarbonImmutable $hoy,
        /** El primer cobro o gasto registrado; null si el proyecto no tiene ninguno. */
        public ?CarbonImmutable $desde,
        // ─── Ingresos ───
        public array $cobradoPorConcepto,
        public array $cobradoPorForma,
        public array $cobradoPorPersona,
        public Monto $cobrado,
        public int $recibos,
        // ─── Egresos ───
        public array $gastadoPorCategoria,
        public Monto $gastado,
        public int $gastos,
        public Monto $devuelto,
        public Monto $salidas,
        public array $puestoPorSocios,
        public Monto $puestoPorSociosTotal,
        // ─── Resultado ───
        public Monto $utilidad,
        public Monto $perdida,
        // ─── Socios ───
        public RepartoEntreSocios $reparto,
        public array $entregadoPorForma,
        public array $exSocios,
        // ─── La historia ───
        public array $anioPorAnio,
        // ─── La cartera ───
        public Monto $vendido,
        public int $lotesVendidos,
        public int $expedientes,
        public Monto $porCobrar,
        public Monto $atrasado,
        public int $expedientesAtrasados,
        public Monto $proximos12Meses,
        public array $porCobrarPorAnio,
        public Monto $seniasEnCurso,
        public int $apartadosEnCurso,
        // ─── Los lotes ───
        public array $lotes,
    ) {}

    /**
     * @param CarbonImmutable|null $hoy el día de corte para lo atrasado; hoy si no se dice
     */
    public static function de(Proyecto $proyecto, ?CarbonImmutable $hoy = null): self
    {
        // `today()` de PHP, nunca de Postgres (§7.5.1).
        $hoy ??= CarbonImmutable::parse(today()->toDateString());

        $id = (int) $proyecto->getKey();
        $desde = CarbonImmutable::parse(self::DESDE_SIEMPRE);
        $hasta = CarbonImmutable::parse(self::HASTA_SIEMPRE);

        // ─── Lo que entró y lo que salió: las consultas del estado mensual ───

        $cobradoPorConcepto = CierreDelMes::cobradoPorConcepto($id, $desde, $hasta);
        $gastadoPorCategoria = CierreDelMes::gastadoPorCategoria($id, $desde, $hasta);

        $cobrado = self::sumar($cobradoPorConcepto);
        $gastado = self::sumar($gastadoPorCategoria);
        $devuelto = CierreDelMes::devuelto($id, $desde, $hasta);
        $salidas = $gastado->sumar($devuelto);

        ['utilidad' => $utilidad, 'perdida' => $perdida] = self::resultado($cobrado, $salidas);

        // ─── Los socios ───

        $socios = RepartoEntreSocios::sociosDe($id);
        $puesto = CierreDelMes::puestoPorSocio($id, $desde, $hasta);
        $entregadoPorForma = self::entregadoPorSocioYForma($id);

        $entregas = [];

        foreach ($entregadoPorForma as $socio => $formas) {
            $entregas[$socio] = self::sumar($formas);
        }

        // ─── La cartera ───

        $vendidos = self::lotesVendidos($id);
        $atrasadas = self::atrasadas($id, $hoy);
        $senias = self::seniasEnCurso($id);

        return new self(
            proyecto: $proyecto,
            hoy: $hoy,
            desde: self::primerMovimiento($id),
            cobradoPorConcepto: $cobradoPorConcepto,
            cobradoPorForma: CierreDelMes::cobradoPorForma($id, $desde, $hasta),
            cobradoPorPersona: CierreDelMes::cobradoPorPersona($id, $desde, $hasta),
            cobrado: $cobrado,
            recibos: Recibo::query()
                ->reorder()
                ->whereNull('anulado_el')
                ->whereIn('venta_id', self::ventasDelProyecto($id))
                ->count(),
            gastadoPorCategoria: $gastadoPorCategoria,
            gastado: $gastado,
            gastos: Gasto::query()->reorder()->where('proyecto_id', $id)->count(),
            devuelto: $devuelto,
            salidas: $salidas,
            puestoPorSocios: CierreDelMes::puestoConNombre($puesto),
            puestoPorSociosTotal: self::sumar($puesto),
            utilidad: $utilidad,
            perdida: $perdida,
            reparto: RepartoEntreSocios::armar($utilidad, $perdida, $socios, $entregas, $puesto),
            entregadoPorForma: $entregadoPorForma,
            exSocios: self::exSocios($socios, $puesto, $entregadoPorForma),
            anioPorAnio: self::anioPorAnio($id, $hoy),
            vendido: $vendidos['valor'],
            lotesVendidos: $vendidos['lotes'],
            expedientes: $vendidos['expedientes'],
            porCobrar: self::aMonto(
                self::pendientes($id)
                    ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0) AS total')
                    ->value('total'),
            ),
            atrasado: $atrasadas['monto'],
            expedientesAtrasados: $atrasadas['expedientes'],
            proximos12Meses: self::aMonto(
                self::pendientes($id)
                    ->whereBetween('fecha_vencimiento', [$hoy->toDateString(), $hoy->addYear()->subDay()->toDateString()])
                    ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0) AS total')
                    ->value('total'),
            ),
            porCobrarPorAnio: self::porCobrarPorAnio($id, $hoy),
            seniasEnCurso: $senias['monto'],
            apartadosEnCurso: $senias['apartados'],
            lotes: self::lotes($id),
        );
    }

    // ─── Lecturas para la hoja ────────────────────────────────────────

    /**
     * «10 de octubre de 2026»: el día de corte, escrito como se dice. Los
     * nombres de los meses salen de `CierreDelMes`, para que las dos hojas
     * digan «septiembre» igual.
     */
    public function fechaDeCorteEscrita(): string
    {
        return $this->hoy->format('j').' de '.CierreDelMes::comoSeEscribe($this->hoy);
    }

    public function huboMovimiento(): bool
    {
        return $this->desde instanceof CarbonImmutable;
    }

    /**
     * Lo que vence de hoy en adelante: lo que falta cobrar menos lo atrasado.
     */
    public function porVencer(): Monto
    {
        return $this->porCobrar->restar($this->atrasado);
    }

    /**
     * Lo vendido que ya no se debe: prima, cuotas y abonos pagados, y lo
     * perdonado en un pronto pago.
     *
     * 🔴 Del mayor al menor: con interés pactado lo que falta cobrar puede
     * pasar a lo vendido (el interés no es parte del precio del lote), y
     * `restar()` lanzaría en vez de decir cero.
     */
    public function yaPagadoDeLoVendido(): Monto
    {
        return $this->vendido->mayorQue($this->porCobrar)
            ? $this->vendido->restar($this->porCobrar)
            : Monto::cero();
    }

    /**
     * La barra de la cartera entera: lo pagado más lo que falta.
     */
    public function carteraTotal(): Monto
    {
        return $this->yaPagadoDeLoVendido()->sumar($this->porCobrar);
    }

    /**
     * Qué parte es `$parte` de `$total`, en por ciento y sin pasar por float.
     *
     * Sirve para el texto («37 %») y para el ancho de las barras de la hoja.
     * Con total en cero da «0»: una barra vacía, no una división por cero.
     */
    public static function porcentaje(Monto $parte, Monto $total, int $decimales = 0): string
    {
        if ($total->esCero()) {
            return '0';
        }

        $crudo = $parte->multiplicarPor('100')->dividirPor($total->valor);

        return $crudo->redondeado($decimales);
    }

    /**
     * El por ciento para leer: sin decimales, y «< 1» cuando hay algo pero no
     * llega a uno. Un «0 %» al lado de L 64,000.00 parece un error.
     */
    public static function porcentajeEscrito(Monto $parte, Monto $total): string
    {
        $redondo = self::porcentaje($parte, $total);

        return $redondo === '0' && ! $parte->esCero() ? '< 1' : $redondo;
    }

    /**
     * El resultado si entra todo lo que falta cobrar de lo ya vendido, sin
     * gastos nuevos.
     *
     * @return array{utilidad: Monto, perdida: Monto}
     */
    public function siSeCobraTodo(): array
    {
        return self::resultado($this->cobrado->sumar($this->porCobrar), $this->salidas);
    }

    public function totalDeLotes(): int
    {
        $cuantos = 0;

        foreach ($this->lotes as $fila) {
            $cuantos += $fila['cuantos'];
        }

        return $cuantos;
    }

    /**
     * Qué parte de todos los lotes del proyecto son `$cuantos`, en por ciento.
     */
    public function parteDeLotes(int $cuantos): string
    {
        return self::porcentajeEscrito(new Monto($cuantos), new Monto($this->totalDeLotes()));
    }

    /**
     * Lo que queda sin dueño de la utilidad: con partes que suman 100 % da
     * cero. El mismo aviso del estado mensual.
     */
    public function sinRepartir(): Monto
    {
        $repartido = $this->reparto->leToca();

        return $this->utilidad->mayorQue($repartido) ? $this->utilidad->restar($repartido) : Monto::cero();
    }

    public function hayGastosDeSocios(): bool
    {
        return ! $this->puestoPorSociosTotal->esCero();
    }

    /**
     * Lo entregado a todos los socios, también a los que ya no participan.
     */
    public function entregadoATodos(): Monto
    {
        $total = Monto::cero();

        foreach ($this->entregadoPorForma as $formas) {
            $total = $total->sumar(self::sumar($formas));
        }

        return $total;
    }

    /**
     * Las formas en que se les entregó dinero a los socios, en el orden del
     * enum: las columnas de la tabla.
     *
     * @return list<string>
     */
    public function formasDeEntrega(): array
    {
        $formas = [];

        foreach (FormaDePago::cases() as $forma) {
            foreach ($this->entregadoPorForma as $porForma) {
                if (isset($porForma[$forma->value])) {
                    $formas[] = $forma->value;

                    break;
                }
            }
        }

        return $formas;
    }

    /**
     * Lo entregado a un socio, abierto por forma.
     *
     * @return array<string, Monto>
     */
    public function entregadoA(Socio $socio): array
    {
        return $this->entregadoPorForma[(int) $socio->getKey()] ?? [];
    }

    /**
     * El año más alto de la tabla, para escalar sus barras.
     */
    public function mayorIngresoAnual(): Monto
    {
        $mayor = Monto::cero();

        foreach ($this->anioPorAnio as $fila) {
            if ($fila['ingresos']->mayorQue($mayor)) {
                $mayor = $fila['ingresos'];
            }
        }

        return $mayor;
    }

    /**
     * El renglón más alto de lo que falta cobrar —lo atrasado incluido—, para
     * escalar las barras.
     */
    public function mayorPorCobrarAnual(): Monto
    {
        $mayor = $this->atrasado;

        foreach ($this->porCobrarPorAnio as $fila) {
            if ($fila['monto']->mayorQue($mayor)) {
                $mayor = $fila['monto'];
            }
        }

        return $mayor;
    }

    // ─── Los socios ───────────────────────────────────────────────────

    /**
     * Lo entregado a cada socio, por socio y por forma.
     *
     * Todas las entregas, de cualquier mes: es la vida entera del proyecto.
     *
     * @return array<int, array<string, Monto>>
     */
    private static function entregadoPorSocioYForma(int $proyecto): array
    {
        $filas = EntregaASocio::query()
            ->reorder()
            ->where('proyecto_id', $proyecto)
            ->groupBy('socio_id', 'forma_pago')
            ->selectRaw('socio_id, forma_pago, COALESCE(SUM(monto), 0) AS total')
            ->toBase()
            ->get();

        $crudo = [];

        foreach ($filas as $fila) {
            // El query builder entrega stdClass: se convierte en el borde.
            $datos = (array) $fila;

            $socio = $datos['socio_id'] ?? null;
            $forma = $datos['forma_pago'] ?? null;

            if (! is_string($forma)) {
                continue;
            }

            if (! is_numeric($socio)) {
                continue;
            }

            $crudo[(int) $socio][$forma] = self::aMonto($datos['total'] ?? '0');
        }

        // Las formas en el orden del enum, igual que en el estado mensual.
        $porSocio = [];

        foreach ($crudo as $socio => $formas) {
            $porSocio[$socio] = [];

            foreach (FormaDePago::cases() as $forma) {
                if (isset($formas[$forma->value])) {
                    $porSocio[$socio][$forma->value] = $formas[$forma->value];
                }
            }
        }

        return $porSocio;
    }

    /**
     * Los que ya no participan del reparto pero alguna vez pusieron o
     * recibieron dinero. Su historia es del proyecto: no se esconde.
     *
     * @param Collection<int, Socio> $activos
     * @param array<int, Monto> $puesto
     * @param array<int, array<string, Monto>> $entregadoPorForma
     *
     * @return list<array{nombre: string, puso: Monto, entregado: Monto, porForma: array<string, Monto>}>
     */
    private static function exSocios(Collection $activos, array $puesto, array $entregadoPorForma): array
    {
        $deAntes = array_diff(
            array_keys($puesto + $entregadoPorForma),
            array_map(static fn (Socio $socio): int => (int) $socio->getKey(), $activos->all()),
        );

        if ($deAntes === []) {
            return [];
        }

        /** @var array<int, string> $nombres */
        $nombres = Socio::query()
            ->withTrashed()
            ->whereIn('id', $deAntes)
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->all();

        $lista = [];

        foreach ($nombres as $id => $nombre) {
            $porForma = $entregadoPorForma[$id] ?? [];

            $lista[] = [
                'nombre'    => $nombre,
                'puso'      => $puesto[$id] ?? Monto::cero(),
                'entregado' => self::sumar($porForma),
                'porForma'  => $porForma,
            ];
        }

        return $lista;
    }

    // ─── La historia, año por año ─────────────────────────────────────

    /**
     * Ingresos, egresos y resultado de cada año, del primero con movimiento
     * hasta el corriente.
     *
     * 🔴 Los mismos filtros que las consultas de `CierreDelMes`, agrupados por
     * año en vez de por rango: los renglones tienen que sumar exactamente los
     * totales de arriba, y un test lo exige.
     *
     * @return list<array{anio: int, ingresos: Monto, egresos: Monto, utilidad: Monto, perdida: Monto}>
     */
    private static function anioPorAnio(int $proyecto, CarbonImmutable $hoy): array
    {
        $cobrado = self::porAnio(
            Recibo::query()
                ->reorder()
                ->whereNull('anulado_el')
                ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
                ->groupByRaw("to_char(fecha, 'YYYY')")
                ->selectRaw("to_char(fecha, 'YYYY') AS anio, COALESCE(SUM(monto), 0) AS total")
                ->pluck('total', 'anio')
                ->all(),
        );

        $gastado = self::porAnio(
            Gasto::query()
                ->reorder()
                ->where('proyecto_id', $proyecto)
                ->groupByRaw("to_char(fecha, 'YYYY')")
                ->selectRaw("to_char(fecha, 'YYYY') AS anio, COALESCE(SUM(monto), 0) AS total")
                ->pluck('total', 'anio')
                ->all(),
        );

        // Las dos mitades de `devoluciones`, como en `CierreDelMes::devuelto()`.
        $devueltoPorVenta = self::porAnio(
            Devolucion::query()
                ->reorder()
                ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
                ->groupByRaw("to_char(fecha, 'YYYY')")
                ->selectRaw("to_char(fecha, 'YYYY') AS anio, COALESCE(SUM(monto_devuelto), 0) AS total")
                ->pluck('total', 'anio')
                ->all(),
        );

        $devueltoPorCompromiso = self::porAnio(
            Devolucion::query()
                ->reorder()
                ->whereNull('venta_id')
                ->whereIn('compromiso_id', self::compromisosDelProyecto($proyecto))
                ->groupByRaw("to_char(fecha, 'YYYY')")
                ->selectRaw("to_char(fecha, 'YYYY') AS anio, COALESCE(SUM(monto_devuelto), 0) AS total")
                ->pluck('total', 'anio')
                ->all(),
        );

        $anios = array_keys($cobrado + $gastado + $devueltoPorVenta + $devueltoPorCompromiso);

        if ($anios === []) {
            return [];
        }

        $filas = [];

        for ($anio = min($anios); $anio <= max(max($anios), $hoy->year); $anio++) {
            $ingresos = $cobrado[$anio] ?? Monto::cero();
            $egresos = ($gastado[$anio] ?? Monto::cero())
                ->sumar($devueltoPorVenta[$anio] ?? Monto::cero())
                ->sumar($devueltoPorCompromiso[$anio] ?? Monto::cero());

            ['utilidad' => $utilidad, 'perdida' => $perdida] = self::resultado($ingresos, $egresos);

            $filas[] = [
                'anio'     => $anio,
                'ingresos' => $ingresos,
                'egresos'  => $egresos,
                'utilidad' => $utilidad,
                'perdida'  => $perdida,
            ];
        }

        return $filas;
    }

    /**
     * El primer día con un cobro o un gasto: desde cuándo cuenta la hoja.
     */
    private static function primerMovimiento(int $proyecto): ?CarbonImmutable
    {
        $fechas = [
            Recibo::query()
                ->reorder()
                ->whereNull('anulado_el')
                ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
                ->min('fecha'),
            Gasto::query()->reorder()->where('proyecto_id', $proyecto)->min('fecha'),
        ];

        $primero = null;

        foreach ($fechas as $fecha) {
            if (! is_string($fecha)) {
                continue;
            }

            $dia = CarbonImmutable::parse($fecha)->startOfDay();

            if (! $primero instanceof CarbonImmutable || $dia->isBefore($primero)) {
                $primero = $dia;
            }
        }

        return $primero;
    }

    // ─── La cartera ───────────────────────────────────────────────────

    /**
     * Lo vendido: el valor congelado de cada lote al venderse.
     *
     * Sale de los compromisos de venta vivos —no de `ventas.valor_total`—
     * porque un contrato de varios lotes puede haber perdido uno en una
     * rescisión (R22) y seguir vigente con los demás. Cuenta lo vigente Y lo
     * ya liquidado: un lote pagado completo sigue siendo un lote vendido.
     *
     * @return array{valor: Monto, lotes: int, expedientes: int}
     */
    private static function lotesVendidos(int $proyecto): array
    {
        $fila = Compromiso::query()
            ->reorder()
            ->where('tipo', TipoCompromiso::Venta->value)
            ->where('estado', EstadoCompromiso::Vigente->value)
            ->whereIn('venta_id', self::ventasDelProyecto($proyecto)->whereIn('estado', [
                EstadoVenta::Vigente->value,
                EstadoVenta::Liquidada->value,
            ]))
            ->selectRaw('COUNT(*) AS lotes, COUNT(DISTINCT venta_id) AS expedientes, COALESCE(SUM(valor), 0) AS total')
            ->toBase()
            ->first();

        $datos = (array) $fila;

        return [
            'valor'       => self::aMonto($datos['total'] ?? '0'),
            'lotes'       => self::entero($datos['lotes'] ?? 0),
            'expedientes' => self::entero($datos['expedientes'] ?? 0),
        ];
    }

    /**
     * Las cuotas que todavía deben algo, de expedientes vigentes y de lotes
     * vivos.
     *
     * Es la misma cuenta del cuadro «Falta por cobrar» del Escritorio
     * (`ComoVanLosProyectos`): dos pantallas que dicen distinto lo que falta
     * cobrar es lo que hace que nadie le crea a ninguna.
     *
     * @return Builder<Cuota>
     */
    private static function pendientes(int $proyecto): Builder
    {
        return Cuota::query()
            ->reorder()
            ->deLotesVivos()
            ->pendientes()
            ->whereIn('venta_id', self::ventasDelProyecto($proyecto)->vigentes());
    }

    /**
     * @return array{monto: Monto, expedientes: int}
     */
    private static function atrasadas(int $proyecto, CarbonImmutable $hoy): array
    {
        $fila = self::pendientes($proyecto)
            ->where('fecha_vencimiento', '<', $hoy->toDateString())
            ->selectRaw('COUNT(DISTINCT venta_id) AS expedientes, COALESCE(SUM(monto - monto_pagado), 0) AS total')
            ->toBase()
            ->first();

        $datos = (array) $fila;

        return [
            'monto'       => self::aMonto($datos['total'] ?? '0'),
            'expedientes' => self::entero($datos['expedientes'] ?? 0),
        ];
    }

    /**
     * Lo que vence de hoy en adelante, por año. Lo atrasado NO está acá: va
     * en su propio renglón, arriba de la tabla.
     *
     * @return list<array{anio: int, cuotas: int, monto: Monto}>
     */
    private static function porCobrarPorAnio(int $proyecto, CarbonImmutable $hoy): array
    {
        $filas = self::pendientes($proyecto)
            ->where('fecha_vencimiento', '>=', $hoy->toDateString())
            ->groupByRaw("to_char(fecha_vencimiento, 'YYYY')")
            ->selectRaw("to_char(fecha_vencimiento, 'YYYY') AS anio, COUNT(*) AS cuotas, COALESCE(SUM(monto - monto_pagado), 0) AS total")
            ->toBase()
            ->get();

        $porAnio = [];

        foreach ($filas as $fila) {
            $datos = (array) $fila;
            $anio = $datos['anio'] ?? null;

            if (! is_numeric($anio)) {
                continue;
            }

            $porAnio[(int) $anio] = [
                'anio'   => (int) $anio,
                'cuotas' => self::entero($datos['cuotas'] ?? 0),
                'monto'  => self::aMonto($datos['total'] ?? '0'),
            ];
        }

        ksort($porAnio);

        return array_values($porAnio);
    }

    /**
     * La plata de los apartados que siguen en pie: entró a la caja, pero por
     * la regla del 13-ago todavía no es ingreso. Cuando el apartado se vende,
     * pasa a contar como parte de la prima.
     *
     * @return array{monto: Monto, apartados: int}
     */
    private static function seniasEnCurso(int $proyecto): array
    {
        $fila = Recibo::query()
            ->reorder()
            ->whereNull('anulado_el')
            ->whereNull('venta_id')
            ->where('concepto', ConceptoDeRecibo::Senia->value)
            ->whereIn('compromiso_id', Compromiso::query()
                ->reorder()
                ->select('id')
                ->where('proyecto_id', $proyecto)
                ->where('tipo', TipoCompromiso::Apartado->value)
                ->where('estado', EstadoCompromiso::Vigente->value))
            ->selectRaw('COUNT(DISTINCT compromiso_id) AS apartados, COALESCE(SUM(monto), 0) AS total')
            ->toBase()
            ->first();

        $datos = (array) $fila;

        return [
            'monto'     => self::aMonto($datos['total'] ?? '0'),
            'apartados' => self::entero($datos['apartados'] ?? 0),
        ];
    }

    // ─── Los lotes ────────────────────────────────────────────────────

    /**
     * Cuántos lotes hay en cada estado. Sin precio: lo que no se ha vendido no
     * se proyecta (ver la cabecera).
     *
     * @return list<array{estado: EstadoLote, cuantos: int}>
     */
    private static function lotes(int $proyecto): array
    {
        $filas = Lote::query()
            ->reorder()
            ->where('proyecto_id', $proyecto)
            ->groupBy('estado')
            ->selectRaw('estado, COUNT(*) AS cuantos')
            ->toBase()
            ->get();

        $porEstado = [];

        foreach ($filas as $fila) {
            $datos = (array) $fila;
            $estado = $datos['estado'] ?? null;

            if (is_string($estado)) {
                $porEstado[$estado] = $datos;
            }
        }

        // En el orden del enum: así la hoja de este mes y la del que viene
        // tienen los renglones en el mismo lugar.
        $lista = [];

        foreach (EstadoLote::cases() as $estado) {
            $datos = $porEstado[$estado->value] ?? null;

            if ($datos === null) {
                continue;
            }

            $lista[] = [
                'estado'  => $estado,
                'cuantos' => self::entero($datos['cuantos'] ?? 0),
            ];
        }

        return $lista;
    }

    // ─── Herramientas ─────────────────────────────────────────────────

    /**
     * Los mismos expedientes que usan las consultas de `CierreDelMes`: todos
     * los del proyecto, con cualquier estado. Un cobro de marzo entró en la
     * caja de marzo aunque el expediente se anule en agosto.
     *
     * @return Builder<Venta>
     */
    private static function ventasDelProyecto(int $proyecto): Builder
    {
        return Venta::query()->reorder()->select('id')->where('proyecto_id', $proyecto);
    }

    /**
     * @return Builder<Compromiso>
     */
    private static function compromisosDelProyecto(int $proyecto): Builder
    {
        return Compromiso::query()->reorder()->select('id')->whereIn('venta_id', self::ventasDelProyecto($proyecto));
    }

    /**
     * Las filas de un `pluck` por año, en montos con el año como entero.
     *
     * @param array<array-key, mixed> $filas
     *
     * @return array<int, Monto>
     */
    private static function porAnio(array $filas): array
    {
        $montos = [];

        foreach ($filas as $anio => $total) {
            if (! is_numeric($anio)) {
                continue;
            }

            $montos[(int) $anio] = self::aMonto($total);
        }

        return $montos;
    }

    /**
     * Entradas menos salidas, dicho sin signos.
     *
     * @return array{utilidad: Monto, perdida: Monto}
     */
    private static function resultado(Monto $entradas, Monto $salidas): array
    {
        return $salidas->mayorQue($entradas)
            ? ['utilidad' => Monto::cero(), 'perdida' => $salidas->restar($entradas)]
            : ['utilidad' => $entradas->restar($salidas), 'perdida' => Monto::cero()];
    }

    /**
     * @param array<array-key, Monto> $montos
     */
    private static function sumar(array $montos): Monto
    {
        $total = Monto::cero();

        foreach ($montos as $monto) {
            $total = $total->sumar($monto);
        }

        return $total;
    }

    private static function aMonto(mixed $valor): Monto
    {
        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    private static function entero(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }
}
