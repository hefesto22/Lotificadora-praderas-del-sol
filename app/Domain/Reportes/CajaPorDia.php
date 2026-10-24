<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * La caja, día por día: cuánto dinero entró cada día — 24-oct-2026.
 *
 * ═══ DE DONDE SALE ═══
 *
 * Mauricio: «agregaremos función de caja, aunque solo será para reportes de
 * lo que se recibió qué día … nadie la abrirá, será del día: el 1 de
 * octubre, el 2 de octubre y así, para poder saber cuánto dinero se recibió
 * por día, y poder hacerlo solo de un proyecto o de dos o de más».
 *
 * ═══ NO HAY APERTURA NI CIERRE ═══
 *
 * Es un REPORTE, no una caja que alguien abre en la mañana y cierra en la
 * tarde. No guarda nada y no tiene tabla propia: lee los recibos. Así no hay
 * un segundo registro del dinero que se pueda desincronizar del primero.
 *
 * ═══ LA MISMA CUENTA QUE EL ESCRITORIO ═══
 *
 * Recibos NO anulados, por su `fecha` (§7.5: la fecha de negocio, nunca
 * `created_at`), con la seña de un apartado incluida —entró dinero aunque
 * todavía no haya contrato—. Es exactamente lo que suman «Cobrado hoy» y
 * «Cobrado este mes» del Escritorio, y hay un test que lo ata: si esta
 * pantalla y el Escritorio dijeran números distintos del mismo mes, la
 * administradora dejaría de creerles a los dos.
 *
 * ⚠️ Por eso NO reusa `CierreDelMes::cobradoPorForma()`: el estado de
 * resultados cuenta solo lo que cuelga de una venta (la seña no es ingreso
 * hasta que se formaliza, regla del 13-ago). Son dos preguntas distintas
 * —«qué gané» y «qué dinero me entregaron»— y esta es la segunda.
 *
 * ═══ EL DINERO NO PASA POR FLOAT ═══
 *
 * Las sumas las hace Postgres y entran a `Monto` como string (§8.3.1).
 */
final readonly class CajaPorDia
{
    /**
     * Un año, como mucho, de una vez.
     *
     * La consulta aguanta más —es una sola, agrupada—, pero ni la pantalla ni
     * el papel: tres años son mil renglones que nadie lee. Si piden más, se
     * recorta desde el «hasta» hacia atrás y se dice.
     */
    public const int DIAS_COMO_MUCHO = 366;

    /**
     * @param list<DiaDeCaja> $dias del más viejo al más nuevo, solo los que tuvieron cobros
     * @param array<string, Monto> $porForma clave: `FormaDePago::value`, en el orden del enum
     */
    private function __construct(
        public CarbonImmutable $desde,
        public CarbonImmutable $hasta,
        public array $dias,
        public array $porForma,
        public Monto $total,
        public int $recibos,
    ) {}

    /**
     * La caja de un rango de días.
     *
     * Una sola consulta agrupada por día y forma de pago, no una por día: el
     * rango puede ser un año, y el costo no puede crecer con él.
     *
     * @param list<int> $proyectos los que se suman; vacío = todos
     * @param int|null $recibidoPor solo lo que recibió esa persona; null = todos
     */
    public static function de(
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        array $proyectos = [],
        ?int $recibidoPor = null,
    ): self {
        $filas = self::recibos($desde, $hasta, $proyectos, $recibidoPor)
            ->groupBy('fecha', 'forma_pago')
            ->selectRaw('fecha, forma_pago, COUNT(*) AS recibos, COALESCE(SUM(monto), 0) AS total')
            ->orderBy('fecha')
            ->toBase()
            ->get();

        $recibosPorDia = [];
        $formasPorDia = [];

        foreach ($filas as $fila) {
            // El query builder entrega stdClass: se convierte en el borde.
            $datos = (array) $fila;

            $fecha = $datos['fecha'] ?? null;
            $forma = $datos['forma_pago'] ?? null;

            if (! is_string($fecha)) {
                continue;
            }

            if (! is_string($forma)) {
                continue;
            }

            $dia = substr($fecha, 0, 10);

            $recibosPorDia[$dia] = ($recibosPorDia[$dia] ?? 0) + self::entero($datos['recibos'] ?? 0);
            $formasPorDia[$dia][$forma] = self::monto($datos['total'] ?? '0');
        }

        $dias = [];
        $porForma = [];
        $total = Monto::cero();
        $recibos = 0;

        // Las filas vienen ordenadas por fecha y el arreglo respeta el orden
        // en que se llenó: los días quedan del 1 al 31, como se lee un mes.
        foreach ($formasPorDia as $dia => $formas) {
            $ordenadas = self::enElOrdenDelEnum($formas);
            $delDia = self::sumar($ordenadas);
            $cuantos = $recibosPorDia[$dia] ?? 0;

            $dias[] = new DiaDeCaja(
                fecha: CarbonImmutable::parse($dia)->startOfDay(),
                recibos: $cuantos,
                porForma: $ordenadas,
                total: $delDia,
            );

            foreach ($ordenadas as $valor => $monto) {
                $porForma[$valor] = ($porForma[$valor] ?? Monto::cero())->sumar($monto);
            }

            $total = $total->sumar($delDia);
            $recibos += $cuantos;
        }

        return new self(
            desde: $desde,
            hasta: $hasta,
            dias: $dias,
            porForma: self::enElOrdenDelEnum($porForma),
            total: $total,
            recibos: $recibos,
        );
    }

    /**
     * El rango que de verdad se muestra: ordenado, y con tope de un año.
     *
     * Lo usan la pantalla y el papel. Si cada uno acotara por su lado, un día
     * la hoja impresa diría un período distinto del que se estaba mirando.
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable, recortado: bool}
     */
    public static function acotar(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        // Dadas vuelta no es un error que valga la pena gritar: se entiende.
        if ($hasta->isBefore($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $tope = $hasta->subDays(self::DIAS_COMO_MUCHO - 1);

        if ($desde->isBefore($tope)) {
            return ['desde' => $tope, 'hasta' => $hasta, 'recortado' => true];
        }

        return ['desde' => $desde, 'hasta' => $hasta, 'recortado' => false];
    }

    /**
     * Los recibos que cuenta la caja. EL filtro, escrito una sola vez.
     *
     * Lo usan el total, el detalle de un día y quién recibió: si cada uno
     * armara el suyo, tarde o temprano uno se olvidaría de los anulados y el
     * renglón abierto dejaría de sumar lo que dice su total.
     *
     * @param list<int> $proyectos vacío = todos
     *
     * @return Builder<Recibo>
     */
    public static function recibos(
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        array $proyectos = [],
        ?int $recibidoPor = null,
    ): Builder {
        $consulta = Recibo::query()
            ->reorder()
            // 🔴 Los anulados no cuentan: su número sigue en la serie, pero su
            // dinero volvió a deberse y no está en la caja.
            ->whereNull('anulado_el')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()]);

        if ($proyectos !== []) {
            $consulta = $consulta->deLosProyectos($proyectos);
        }

        // Lo de alguien es lo que RECIBIÓ, no lo que tecleó: la administradora
        // puede registrar un cobro que recibió un receptor en la caseta.
        if ($recibidoPor !== null) {
            $consulta->where('recibido_por', $recibidoPor);
        }

        return $consulta;
    }

    /**
     * Los recibos del rango, uno por renglón, listos para leer.
     *
     * Lo usan el detalle de un día en la pantalla y el anexo del papel. Se
     * arma acá —y no en cada vista— porque las etiquetas son reglas: el folio,
     * a nombre de quién salió, qué lote tocó.
     *
     * Con tope: la pantalla no puede depender de que sean pocos (§12). Quien
     * llama dice cuántos quedaron afuera comparando contra `$recibos`.
     *
     * @param list<int> $proyectos
     *
     * @return list<array{id: int, fecha: CarbonImmutable, folio: string, nombre: string, lotes: string, concepto: string, forma: string, recibio: string, monto: Monto}>
     */
    public static function renglones(
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        array $proyectos = [],
        ?int $recibidoPor = null,
        int $tope = 200,
    ): array {
        $recibos = self::recibos($desde, $hasta, $proyectos, $recibidoPor)
            // Lo mismo que precarga la lista de Recibos para dibujar el lote:
            // sin esto, cada renglón haría sus propias consultas.
            ->with(['cliente', 'compromiso.lote', 'aplicaciones.cuota.compromiso.lote', 'recibidoPor', 'createdBy'])
            ->orderBy('fecha')
            ->orderBy('id')
            ->limit($tope)
            ->get();

        $renglones = [];

        foreach ($recibos as $recibo) {
            $concepto = $recibo->getAttribute('concepto');
            $forma = $recibo->getAttribute('forma_pago');
            $fecha = $recibo->getAttribute('fecha');
            $lotes = $recibo->codigosDeLotes();

            $renglones[] = [
                'id'       => (int) $recibo->getKey(),
                'fecha'    => CarbonImmutable::parse($fecha instanceof DateTimeInterface ? $fecha->format('Y-m-d') : $desde->toDateString()),
                'folio'    => $recibo->folio(),
                'nombre'   => $recibo->nombreDelPapel(),
                'lotes'    => $lotes === [] ? '—' : implode(' · ', $lotes),
                'concepto' => $concepto instanceof ConceptoDeRecibo ? $concepto->etiqueta() : '—',
                'forma'    => $forma instanceof FormaDePago ? $forma->etiqueta() : '—',
                'recibio'  => $recibo->nombreDeQuienRecibio() ?? '—',
                'monto'    => $recibo->montoTotal(),
            ];
        }

        return $renglones;
    }

    /**
     * Quién recibió el dinero del rango —un día o un mes—, de mayor a menor.
     *
     * Por `recibido_por` —el «¿Quién recibió el dinero?» del cobro, R24—, que
     * es lo que pregunta un arqueo: quién tiene el billete en la mano. Los
     * recibos de antes del 27-ago que no lo anotaron van a «Sin anotar», para
     * que la suma de las personas siga siendo el total.
     *
     * @param list<int> $proyectos
     *
     * @return list<array{nombre: string, total: Monto}>
     */
    public static function quienesRecibieron(
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        array $proyectos = [],
        ?int $recibidoPor = null,
    ): array {
        $filas = self::recibos($desde, $hasta, $proyectos, $recibidoPor)
            ->groupBy('recibido_por')
            ->selectRaw('recibido_por, COALESCE(SUM(monto), 0) AS total')
            ->toBase()
            ->get();

        $totales = [];

        foreach ($filas as $fila) {
            $datos = (array) $fila;

            // 0 = sin anotar. Ningún usuario tiene id 0.
            $totales[self::entero($datos['recibido_por'] ?? 0)] = self::monto($datos['total'] ?? '0');
        }

        if ($totales === []) {
            return [];
        }

        // `withTrashed()`: quien cobró y después dejó de trabajar ahí sigue
        // siendo quien recibió ese dinero.
        /** @var array<int, string> $nombres */
        $nombres = User::query()
            ->withTrashed()
            ->whereKey(array_keys($totales))
            ->pluck('name', 'id')
            ->all();

        uasort($totales, static fn (Monto $a, Monto $b): int => $b->mayorQue($a) ? 1 : ($a->mayorQue($b) ? -1 : 0));

        $lista = [];

        foreach ($totales as $id => $monto) {
            $lista[] = [
                'nombre' => $id === 0 ? 'Sin anotar' : mb_strtoupper($nombres[$id] ?? '—'),
                'total'  => $monto,
            ];
        }

        return $lista;
    }

    /**
     * «Todos los proyectos», o sus nombres: «NORTE, SUR y ESTE».
     *
     * @param list<int> $proyectos
     */
    public static function proyectosEscritos(array $proyectos): string
    {
        if ($proyectos === []) {
            return 'Todos los proyectos';
        }

        /** @var list<string> $nombres */
        $nombres = Proyecto::query()
            ->reorder()
            ->whereKey($proyectos)
            ->orderBy('nombre')
            ->pluck('nombre')
            ->all();

        if (count($nombres) < 2) {
            return $nombres[0] ?? 'Todos los proyectos';
        }

        $ultimo = array_pop($nombres);

        return implode(', ', $nombres).' y '.$ultimo;
    }

    // ─── Lo que se lee de la caja ─────────────────────────────────────

    /**
     * «Del 1 al 24 de octubre de 2026», o el día solo si es uno.
     */
    public function periodoEscrito(): string
    {
        if ($this->desde->isSameDay($this->hasta)) {
            return ucfirst($this->desde->translatedFormat('l j \d\e F \d\e Y'));
        }

        if ($this->desde->year !== $this->hasta->year) {
            return 'Del '.$this->desde->translatedFormat('j \d\e F \d\e Y').' al '.$this->hasta->translatedFormat('j \d\e F \d\e Y');
        }

        if ($this->desde->month !== $this->hasta->month) {
            return 'Del '.$this->desde->translatedFormat('j \d\e F').' al '.$this->hasta->translatedFormat('j \d\e F \d\e Y');
        }

        return 'Del '.$this->desde->day.' al '.$this->hasta->translatedFormat('j \d\e F \d\e Y');
    }

    /**
     * «Jueves 1 de octubre». Con el año solo cuando el rango cruza de año.
     */
    public function nombreDelDia(DiaDeCaja $dia): string
    {
        $texto = ucfirst($dia->fecha->translatedFormat('l j \d\e F'));

        return $this->desde->year === $this->hasta->year ? $texto : $texto.' de '.$dia->fecha->year;
    }

    /**
     * Las formas de pago que tuvieron dinero en el rango, en el orden del enum.
     *
     * La tabla dibuja una columna por cada una. Una columna «Remesa» vacía
     * en todos los renglones ocupa ancho para no decir nada.
     *
     * @return list<FormaDePago>
     */
    public function formas(): array
    {
        $formas = [];

        foreach (FormaDePago::cases() as $forma) {
            if (isset($this->porForma[$forma->value])) {
                $formas[] = $forma;
            }
        }

        return $formas;
    }

    public function deLaForma(FormaDePago $forma): Monto
    {
        return $this->porForma[$forma->value] ?? Monto::cero();
    }

    /**
     * Lo único que alguien tiene que CONTAR con la mano.
     */
    public function efectivo(): Monto
    {
        return $this->deLaForma(FormaDePago::Efectivo);
    }

    /**
     * Lo que se cuadra contra el estado de cuenta del banco.
     */
    public function porBanco(): Monto
    {
        return $this->total->restar($this->efectivo());
    }

    public function elDiaQueMasEntro(): ?DiaDeCaja
    {
        $mejor = null;

        foreach ($this->dias as $dia) {
            if (! $mejor instanceof DiaDeCaja || $dia->total->mayorQue($mejor->total)) {
                $mejor = $dia;
            }
        }

        return $mejor;
    }

    public function huboMovimiento(): bool
    {
        return $this->dias !== [];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param array<string, Monto> $formas
     *
     * @return array<string, Monto>
     */
    private static function enElOrdenDelEnum(array $formas): array
    {
        $ordenadas = [];

        foreach (FormaDePago::cases() as $forma) {
            if (isset($formas[$forma->value])) {
                $ordenadas[$forma->value] = $formas[$forma->value];
            }
        }

        return $ordenadas;
    }

    /**
     * @param array<string, Monto> $montos
     */
    private static function sumar(array $montos): Monto
    {
        $total = Monto::cero();

        foreach ($montos as $monto) {
            $total = $total->sumar($monto);
        }

        return $total;
    }

    private static function monto(mixed $valor): Monto
    {
        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    /**
     * PDO entrega los enteros de Postgres como int o como string según la
     * versión y la configuración; se aceptan los dos.
     */
    private static function entero(mixed $valor): int
    {
        if (is_int($valor)) {
            return $valor;
        }

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : 0;
    }
}
