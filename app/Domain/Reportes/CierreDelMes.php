<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Devolucion;
use App\Models\EntregaASocio;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Socio;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * El cierre de un mes: qué entró, qué salió, qué quedó y cuánto hay que entregar.
 *
 * ═══ QUE PIDIO MAURICIO, TEXTUAL (24-AGO-2026) ═══
 *
 * Primero: «reportes mensuales de pagos, gastos, y porcentajes dependiendo de
 * cuánto tengan los socios, todo detallado para que mes a mes lleven todo al
 * detalle».
 *
 * Y después, mirando la primera versión: **«nada de qué hay en la caja, solo
 * que muestre el estado de resultados mes a mes, nada de acumulado, y qué hay
 * que entregar».**
 *
 * ═══ 🔴 MES A MES, SIN ACUMULADO — Y LO QUE ESO IMPLICA ═══
 *
 * La primera versión repartía sobre la utilidad acumulada del proyecto, para
 * que «le tocaba X, se le entregó Y» siguiera siendo cierto después de un mes
 * malo. Mauricio lo bajó: quiere el mes, cerrado y suelto, como un estado de
 * resultados.
 *
 * ⚠️ **Lo que se pierde, dicho para que nadie lo descubra tarde:** un mes bueno
 * reparte de más y el siguiente, malo, no tiene de dónde descontarlo — no hay a
 * quién pedirle la vuelta. El día que eso incomode, la salida no es rehacer
 * esta hoja: es agregarle la columna del acumulado al lado, que sale de estos
 * mismos datos.
 *
 * ═══ SOBRE QUE SE REPARTE — LA PREGUNTA QUE DEJO ABIERTA `socios` ═══
 *
 * La migración de `socios` (13-ago) decía: «no reparte nada todavía; antes de
 * escribirlo hay que saber sobre QUÉ se reparte». La respuesta es:
 *
 *   **Sobre la utilidad de CAJA del mes**: lo cobrado —recibos no anulados—
 *   menos los gastos y menos lo que se le devolvió a clientes, todo con fecha
 *   de ese mes. Nada de devengado: se reparte plata que entró, no plata que
 *   alguien promete pagar en 2029.
 *
 * ═══ POR QUE NO HAY UN NUMERO NEGATIVO EN NINGUN LADO ═══
 *
 * `Monto` no admite negativos, y no se le va a torcer el brazo. Un mes que
 * cierra en rojo no se dice con un signo menos: se dice con `$perdidaDelMes`,
 * su propio renglón. En el papel se lee «Pérdida del mes: L 12,000.00» y nadie
 * tiene que fijarse en un guion chiquito para no leer al revés el resultado.
 *
 * ═══ 🔴 Y LO QUE NO ENTRO, QUE NO ES LO MISMO QUE UN INGRESO ═══
 *
 * Mauricio, 25-ago-2026: «sería bueno que también diga lo pendiente de
 * personas que no pagaron cuota que les tocaba ese mes».
 *
 * Va en `$sinCobrar`, y **fuera del resultado**. Con base de caja, la cuota
 * que no se pagó no es un ingreso: sumarla repartiría entre los socios plata
 * que nadie tiene. Lo que hace es contestar la pregunta que el resultado deja
 * en el aire —«entraron X, ¿y cuánto tenía que haber entrado?»— y dar la lista
 * con nombre y apellido para salir a cobrar.
 *
 * «Que les tocaba ese mes» se lee literal: `fecha_vencimiento` DENTRO del mes.
 * No es la deuda entera del cliente —el atraso de junio ya salió en el papel
 * de junio— sino lo que este mes esperaba cobrar y no cobró.
 *
 * ═══ EL REPARTO NO PIERDE UN CENTAVO ═══
 *
 * Cada parte se redondea a dos decimales y la suma casi nunca da el total: el
 * 33.5 % de L 1,000.01 no es redondo. El sobrante —uno o dos centavos— se le
 * suma al socio de MAYOR porcentaje, que es la convención que menos discusión
 * genera. Repartir sin juntar el residuo es exactamente como se pierden los
 * centavos que después nadie sabe de quién eran.
 *
 * ═══ LO QUE UN SOCIO PONE DE SU BOLSA (10-OCT-2026) ═══
 *
 * El 8-oct se construyó «a quién se le carga un gasto» y se le DESCONTABA al
 * socio. Era al revés. Mauricio, el 10-oct, ya en producción: «es dinero que
 * ellos ponen de su bolsa al proyecto, ese dinero es a favor de ellos». Y
 * eligió devolvérselo en el reparto del mes.
 *
 *   1. Es gasto del proyecto como cualquier otro: entra en los egresos, baja
 *      la utilidad y cada socio carga con su parte. La comisión de una venta
 *      es costo del desarrollo la haya pagado quien la haya pagado.
 *   2. Pero no salió de la caja: al que la puso se le devuelve, encima de lo
 *      que le toca (`ParteDeUnSocio::$puso`).
 *
 * Ejemplo, socios 60/40: cobrados L 100,000, la caja pagó L 20,000 y Elder
 * puso L 10,000. Utilidad L 70,000 → Elder 42,000 + 10,000 = 52,000; Edwin
 * 28,000. Suman 80,000, que es exactamente lo que hay en la caja.
 *
 * ═══ Y SI LA CAJA NO ALCANZA PARA DEVOLVERLO ═══
 *
 * Mes flojo: cobrados L 2,000 y Edwin puso L 5,000. Pérdida L 3,000. La caja
 * entera (2,000) va para Edwin, y los otros 3,000 se cubren entre todos según
 * su parte (`ParteDeUnSocio::$cubre`): Elder queda debiendo 1,800 y Edwin
 * recibe 5,000 − 1,200 = 3,800. Cada uno termina perdiendo su porcentaje de
 * los 3,000, que es lo justo.
 *
 * ⚠️ Solo se cubre la parte de la pérdida que viene de lo puesto. Si la caja
 * ya estaba en rojo por sí sola —gastó más de lo que cobró—, ese rojo es la
 * pérdida del mes de siempre, y no se le cobra a nadie: el cierre es mes a mes
 * y sin acumulado (ver arriba).
 *
 * La identidad que lo prueba: lo que queda por entregar entre todos, menos lo
 * que quedan debiendo, es lo cobrado menos lo que salió de la caja y menos lo
 * ya entregado. Al céntimo.
 *
 * ═══ POR QUE ALGUNAS CONSULTAS SON PUBLICAS (10-OCT-2026) ═══
 *
 * El estado general del proyecto (`EstadoGeneral`) cuenta lo mismo que esta
 * hoja, pero de toda la vida del proyecto. Usa ESTAS consultas —qué es un
 * ingreso, qué es un egreso, quién recibió el dinero— con un rango que lo
 * abarca todo. Una sola definición: así la suma de los meses da lo mismo que
 * el estado general, y nadie tiene que explicar por qué no.
 */
final readonly class CierreDelMes
{
    /**
     * Los meses escritos, una sola vez: los usan el título de la hoja y la
     * tabla del año. Dos listas separadas es como una dice «setiembre» y la
     * otra «septiembre» en el mismo papel.
     *
     * @var array<int, string>
     */
    private const array MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /**
     * @param array<string, Monto> $cobradoPorConcepto clave: el `value` del ConceptoDeRecibo
     * @param array<string, Monto> $cobradoPorForma clave: el `value` de la FormaDePago
     * @param array<string, Monto> $gastadoPorCategoria clave: el `value` de la CategoriaDeGasto
     * @param list<ParteDeUnSocio> $reparto
     * @param list<array{nombre: string, monto: Monto}> $puestoPorSocios
     * @param list<array{nombre: string, porForma: array<string, Monto>, total: Monto}> $cobradoPorPersona
     */
    private function __construct(
        public Proyecto $proyecto,
        public CarbonImmutable $primerDia,
        public CarbonImmutable $ultimoDia,
        public array $cobradoPorConcepto,
        public array $cobradoPorForma,
        public Monto $cobradoDelMes,
        public array $gastadoPorCategoria,
        public Monto $gastadoDelMes,
        public Monto $devueltoDelMes,
        public Monto $salidasDelMes,
        public Monto $utilidadDelMes,
        public Monto $perdidaDelMes,
        public Monto $entregadoDelMes,
        public Monto $porEntregar,
        public array $reparto,
        /** Lo que vencía en el mes y quedó debiéndose. NO entra en el resultado. */
        public LoSinCobrarDelMes $sinCobrar,
        /** Lo que vencía en el mes, se haya pagado o no: el denominador del cumplimiento. */
        public Monto $vencioEnElMes,
        /** Lo que los socios pagaron de su bolsa en el mes. YA está en los egresos; se les devuelve en el reparto. */
        public Monto $puestoPorSociosDelMes,
        /** Uno por socio que puso algo en el mes, también si ya no participa. */
        public array $puestoPorSocios,
        /**
         * Quién recibió el dinero del mes y en qué forma (10-oct-2026): uno
         * por persona, de mayor a menor. Suma exactamente lo cobrado.
         */
        public array $cobradoPorPersona,
    ) {}

    /**
     * @param CarbonImmutable $mes cualquier día del mes; se usa el mes entero
     */
    public static function de(Proyecto $proyecto, CarbonImmutable $mes): self
    {
        $primerDia = $mes->startOfMonth();
        $ultimoDia = $mes->endOfMonth();

        $id = (int) $proyecto->getKey();

        $cobradoPorConcepto = self::cobradoPorConcepto($id, $primerDia, $ultimoDia);
        $cobradoPorForma = self::cobradoPorForma($id, $primerDia, $ultimoDia);
        $gastadoPorCategoria = self::gastadoPorCategoria($id, $primerDia, $ultimoDia);

        $cobradoDelMes = self::sumar($cobradoPorConcepto);
        $gastadoDelMes = self::sumar($gastadoPorCategoria);
        $devueltoDelMes = self::devuelto($id, $primerDia, $ultimoDia);
        $salidasDelMes = $gastadoDelMes->sumar($devueltoDelMes);

        ['utilidad' => $utilidadDelMes, 'perdida' => $perdidaDelMes] = self::resultado(
            $cobradoDelMes,
            $salidasDelMes,
        );

        $socios = RepartoEntreSocios::sociosDe($id);
        $puesto = self::puestoPorSocio($id, $primerDia, $ultimoDia);

        // La regla del reparto vive en `RepartoEntreSocios`: la usa también el
        // estado general del proyecto, y dos copias acabarían repartiendo distinto.
        $elReparto = RepartoEntreSocios::armar(
            utilidad: $utilidadDelMes,
            perdida: $perdidaDelMes,
            socios: $socios,
            entregas: self::entregadoPorSocio($id, $primerDia),
            puesto: $puesto,
        );

        return new self(
            proyecto: $proyecto,
            primerDia: $primerDia,
            ultimoDia: $ultimoDia,
            cobradoPorConcepto: $cobradoPorConcepto,
            cobradoPorForma: $cobradoPorForma,
            cobradoDelMes: $cobradoDelMes,
            gastadoPorCategoria: $gastadoPorCategoria,
            gastadoDelMes: $gastadoDelMes,
            devueltoDelMes: $devueltoDelMes,
            salidasDelMes: $salidasDelMes,
            utilidadDelMes: $utilidadDelMes,
            perdidaDelMes: $perdidaDelMes,
            entregadoDelMes: $elReparto->entregado,
            porEntregar: $elReparto->porEntregar,
            reparto: $elReparto->partes,
            sinCobrar: LoSinCobrarDelMes::de(self::cuotasSinPagar($id, $primerDia, $ultimoDia)),
            vencioEnElMes: self::loQueVencio($id, $primerDia, $ultimoDia),
            puestoPorSociosDelMes: self::sumar($puesto),
            puestoPorSocios: self::puestoConNombre($puesto),
            cobradoPorPersona: self::cobradoPorPersona($id, $primerDia, $ultimoDia),
        );
    }

    /**
     * Lo que queda sin repartir de la utilidad del mes.
     *
     * Con socios que suman 100 % da cero. Da distinto de cero cuando el
     * formulario dejó pasar partes que no cierran, o cuando un socio se
     * desactivó y su parte quedó sin dueño — y entonces el papel tiene que
     * decirlo, no callarlo.
     */
    public function sinRepartir(): Monto
    {
        $repartido = Monto::cero();

        foreach ($this->reparto as $parte) {
            $repartido = $repartido->sumar($parte->leToca);
        }

        return $this->utilidadDelMes->mayorQue($repartido)
            ? $this->utilidadDelMes->restar($repartido)
            : Monto::cero();
    }

    /**
     * ¿Algún socio pagó un gasto de su bolsa en el mes? Decide si la hoja
     * muestra la tabla de lo puesto y la columna del reparto: un mes sin eso
     * se imprime igual que antes del 8-oct-2026.
     */
    public function hayGastosDeSocios(): bool
    {
        return ! $this->puestoPorSociosDelMes->esCero();
    }

    /**
     * Lo puesto por los socios que están en el reparto, para el total de la
     * columna. Puede ser menos que `$puestoPorSociosDelMes` si un socio que ya
     * no participa puso algo: ese sale en la tabla de lo puesto, no acá.
     */
    public function puestoEnElReparto(): Monto
    {
        $total = Monto::cero();

        foreach ($this->reparto as $parte) {
            $total = $total->sumar($parte->puso);
        }

        return $total;
    }

    /**
     * ¿La caja no alcanzó a devolver lo puesto, y los socios tienen que
     * cubrir la diferencia? Decide la columna «Cubre» de la hoja.
     */
    public function hayQueCubrir(): bool
    {
        return ! $this->cubiertoEnElReparto()->esCero();
    }

    public function cubiertoEnElReparto(): Monto
    {
        $total = Monto::cero();

        foreach ($this->reparto as $parte) {
            $total = $total->sumar($parte->cubre);
        }

        return $total;
    }

    public function huboMovimiento(): bool
    {
        if (! $this->cobradoDelMes->esCero()) {
            return true;
        }

        if (! $this->gastadoDelMes->esCero()) {
            return true;
        }

        return ! $this->devueltoDelMes->esCero();
    }

    /**
     * El mes escrito como se dice: «agosto de 2026».
     */
    public function mesEscrito(): string
    {
        return self::comoSeEscribe($this->primerDia);
    }

    /**
     * Lo mismo, pero sin necesitar un cierre entero.
     *
     * Lo usa el selector de meses de la pantalla del panel. Sin esto, esa
     * lista tendría sus propios nombres de mes escritos aparte — y ahí es
     * exactamente donde una pantalla dice «setiembre» y el papel que sale de
     * ella dice «septiembre».
     */
    public static function comoSeEscribe(CarbonImmutable $mes): string
    {
        return (self::MESES[$mes->month] ?? '').' de '.$mes->year;
    }

    /**
     * Los doce meses del año, uno por renglón.
     *
     * ═══ POR QUE ESTA TABLA VALE LA HOJA ═══
     *
     * «Mes a mes» —Mauricio—. Un cierre suelto contesta «¿cuánto entró en
     * agosto?»; esto contesta «¿cómo viene el año?», que es la pregunta que
     * alguien se hace con el papel en la mano. Y sin acumulados: cada renglón
     * es su mes, cerrado.
     *
     * Tres consultas agrupadas para los doce meses, no doce cierres: un
     * `GROUP BY` sobre un índice que ya existe cuesta lo mismo que uno solo.
     *
     * @return list<array{mes: string, ingresos: Monto, egresos: Monto, utilidad: Monto, perdida: Monto, esElQueSeCierra: bool}>
     */
    public function mesAMesDelAnio(): array
    {
        $anio = $this->primerDia->year;
        $id = (int) $this->proyecto->getKey();

        $cobrado = $this->cobradoPorMes($id, $anio);
        $gastado = $this->gastadoPorMes($id, $anio);
        $devuelto = $this->devueltoPorMes($id, $anio);

        $filas = [];

        foreach (self::MESES as $numero => $nombre) {
            $clave = sprintf('%04d-%02d', $anio, $numero);

            $ingresos = $cobrado[$clave] ?? Monto::cero();
            $egresos = ($gastado[$clave] ?? Monto::cero())->sumar($devuelto[$clave] ?? Monto::cero());

            ['utilidad' => $utilidad, 'perdida' => $perdida] = self::resultado($ingresos, $egresos);

            $filas[] = [
                'mes'             => $nombre,
                'ingresos'        => $ingresos,
                'egresos'         => $egresos,
                'utilidad'        => $utilidad,
                'perdida'         => $perdida,
                'esElQueSeCierra' => $numero === $this->primerDia->month,
            ];
        }

        return $filas;
    }

    // ─── Los números ──────────────────────────────────────────────────

    /**
     * Lo cobrado, abierto por concepto y SIN los anulados.
     *
     * Es la misma cuenta que hace el cuadro «Cobrado este mes» del Escritorio
     * —`recibos.monto`, sin `anulado_el`— y tiene que seguir siéndolo: dos
     * pantallas del mismo sistema que dicen números distintos del mismo mes es
     * lo que hace que nadie vuelva a creerle a ninguna.
     *
     * @return array<string, Monto>
     */
    public static function cobradoPorConcepto(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $filas = Recibo::query()
            ->reorder()
            ->whereNull('anulado_el')
            ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('concepto')
            ->selectRaw('concepto, COALESCE(SUM(monto), 0) AS total')
            ->pluck('total', 'concepto');

        $porConcepto = [];

        // El orden es el del enum y no el de la base: así el papel del mes que
        // viene tiene los renglones en el mismo lugar que el de este mes.
        foreach (ConceptoDeRecibo::cases() as $concepto) {
            $total = $filas[$concepto->value] ?? null;

            if ($total === null) {
                continue;
            }

            $porConcepto[$concepto->value] = new Monto(is_string($total) || is_int($total) ? $total : '0');
        }

        return $porConcepto;
    }

    /**
     * Lo cobrado abierto por FORMA de cobro.
     *
     * No es un adorno: es la columna con la que se cuadra contra el banco. El
     * efectivo tiene que estar en la caja y lo demás tiene que aparecer en el
     * estado de cuenta bancario; separarlos es la mitad de un arqueo.
     *
     * @return array<string, Monto>
     */
    public static function cobradoPorForma(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $filas = Recibo::query()
            ->reorder()
            ->whereNull('anulado_el')
            ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('forma_pago')
            ->selectRaw('forma_pago, COALESCE(SUM(monto), 0) AS total')
            ->pluck('total', 'forma_pago');

        $porForma = [];

        foreach (FormaDePago::cases() as $forma) {
            $total = $filas[$forma->value] ?? null;

            if ($total === null) {
                continue;
            }

            $porForma[$forma->value] = self::aMonto($total);
        }

        return $porForma;
    }

    /**
     * Quién recibió el dinero del mes, y en qué forma.
     *
     * Mauricio, 10-oct-2026: «cuánto dinero recibió cada uno, en pagos y en
     * qué tipo de pagos, ya que ellos dos reciben el dinero». Sale de
     * `recibos.recibido_por` —el «¿Quién recibió el dinero?» del cobro, R24—,
     * con el MISMO filtro que los ingresos: la suma de todas las personas es
     * exactamente el total de ingresos de la hoja, al céntimo.
     *
     * Un recibo sin quién lo recibió —los de antes del 27-ago— va a «Sin
     * anotar»: esconderlo haría que la tabla no sume lo cobrado.
     *
     * @return list<array{nombre: string, porForma: array<string, Monto>, total: Monto}>
     */
    public static function cobradoPorPersona(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $filas = Recibo::query()
            ->reorder()
            ->whereNull('anulado_el')
            ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('recibido_por', 'forma_pago')
            ->selectRaw('recibido_por, forma_pago, COALESCE(SUM(monto), 0) AS total')
            ->toBase()
            ->get();

        /** @var array<int, array<string, Monto>> $porPersona clave 0: sin anotar */
        $porPersona = [];

        foreach ($filas as $fila) {
            // El query builder entrega stdClass: se convierte en el borde.
            $datos = (array) $fila;

            $quien = $datos['recibido_por'] ?? null;
            $forma = $datos['forma_pago'] ?? null;

            if (! is_string($forma)) {
                continue;
            }

            $id = is_int($quien) || (is_string($quien) && ctype_digit($quien)) ? (int) $quien : 0;

            $porPersona[$id][$forma] = self::aMonto($datos['total'] ?? '0');
        }

        if ($porPersona === []) {
            return [];
        }

        // `withTrashed()`: quien cobró y después dejó de trabajar ahí sigue
        // siendo quien recibió ese dinero, y su renglón es justo el que alguien
        // va a preguntar.
        /** @var array<int, string> $nombres */
        $nombres = User::query()
            ->withTrashed()
            ->whereKey(array_keys($porPersona))
            ->pluck('name', 'id')
            ->all();

        // De mayor a menor. Se ordena el mapa de Monto y no la lista: un
        // closure con `array $a` no puede llevar `@param` y pierde la forma.
        $totales = [];

        foreach ($porPersona as $id => $formas) {
            $totales[$id] = self::sumar($formas);
        }

        uasort($totales, static fn (Monto $a, Monto $b): int => $b->mayorQue($a) ? 1 : ($a->mayorQue($b) ? -1 : 0));

        $lista = [];

        foreach ($totales as $id => $total) {
            // En el orden del enum, igual que la franja de formas de cobro.
            $ordenadas = [];

            foreach (FormaDePago::cases() as $forma) {
                if (isset($porPersona[$id][$forma->value])) {
                    $ordenadas[$forma->value] = $porPersona[$id][$forma->value];
                }
            }

            $lista[] = [
                'nombre'   => $id === 0 ? 'Sin anotar' : mb_strtoupper($nombres[$id] ?? '—'),
                'porForma' => $ordenadas,
                'total'    => $total,
            ];
        }

        return $lista;
    }

    /**
     * @return array<string, Monto>
     */
    public static function gastadoPorCategoria(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        // TODOS: el que pagó un socio de su bolsa también es costo del
        // desarrollo. Que se le devuelva lo resuelve el reparto (10-oct-2026).
        $filas = Gasto::query()
            ->reorder()
            ->where('proyecto_id', $proyecto)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('categoria')
            ->selectRaw('categoria, COALESCE(SUM(monto), 0) AS total')
            ->pluck('total', 'categoria');

        $porCategoria = [];

        foreach (CategoriaDeGasto::cases() as $categoria) {
            $total = $filas[$categoria->value] ?? null;

            if ($total === null) {
                continue;
            }

            $porCategoria[$categoria->value] = new Monto(is_string($total) || is_int($total) ? $total : '0');
        }

        return $porCategoria;
    }

    /**
     * Lo que salió de caja para devolverle a un cliente.
     *
     * ⚠️ Se pregunta DOS veces y se suma, en vez de un `orWhere` con closure:
     * `devoluciones` cuelga de la venta O del compromiso —nunca de los dos, lo
     * garantiza un CHECK— así que los dos conjuntos no se pisan. Un `orWhere`
     * sin agrupar se llevaría puesto el filtro de fechas, y el closure que lo
     * agruparía llega sin tipo a PHPStan nivel 7.
     *
     * Solo `monto_devuelto`: lo retenido no salió de la caja, se quedó.
     */
    public static function devuelto(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): Monto
    {
        $entre = [$desde->toDateString(), $hasta->toDateString()];

        $porVenta = Devolucion::query()
            ->reorder()
            ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
            ->whereBetween('fecha', $entre)
            ->selectRaw('COALESCE(SUM(monto_devuelto), 0) AS total')
            ->value('total');

        $porCompromiso = Devolucion::query()
            ->reorder()
            ->whereNull('venta_id')
            ->whereIn('compromiso_id', self::compromisosDelProyecto($proyecto))
            ->whereBetween('fecha', $entre)
            ->selectRaw('COALESCE(SUM(monto_devuelto), 0) AS total')
            ->value('total');

        return self::aMonto($porVenta)->sumar(self::aMonto($porCompromiso));
    }

    /**
     * Lo entregado a cada socio POR ESE MES, indexado por su id.
     *
     * ⚠️ Se imputa por `mes`, no por `fecha`: se puede entregar el 3 de
     * septiembre lo que corresponde a agosto, y el cierre de agosto tiene que
     * verlo. Guardar una sola fecha obligaría a elegir entre un cierre que
     * miente y una fecha de entrega que no es la real.
     *
     * @return array<int, Monto>
     */
    private static function entregadoPorSocio(int $proyecto, CarbonImmutable $mes): array
    {
        $filas = EntregaASocio::query()
            ->reorder()
            ->where('proyecto_id', $proyecto)
            ->where('mes', $mes->startOfMonth()->toDateString())
            ->groupBy('socio_id')
            ->selectRaw('socio_id, COALESCE(SUM(monto), 0) AS total')
            ->pluck('total', 'socio_id');

        $porSocio = [];

        foreach ($filas as $socio => $total) {
            $porSocio[(int) $socio] = self::aMonto($total);
        }

        return $porSocio;
    }

    /**
     * Lo que cada socio pagó de su bolsa en el mes, indexado por su id.
     *
     * Por la FECHA del gasto, igual que los egresos del proyecto —no por un
     * «mes» aparte como las entregas—: un gasto se imputa al mes en que se
     * gastó, y eso ya lo dice su fecha.
     *
     * @return array<int, Monto>
     */
    public static function puestoPorSocio(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $filas = Gasto::query()
            ->reorder()
            ->where('proyecto_id', $proyecto)
            ->pagadosPorUnSocio()
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('socio_id')
            ->selectRaw('socio_id, COALESCE(SUM(monto), 0) AS total')
            ->pluck('total', 'socio_id');

        $porSocio = [];

        foreach ($filas as $socio => $total) {
            $porSocio[(int) $socio] = self::aMonto($total);
        }

        return $porSocio;
    }

    /**
     * Lo puesto, con el nombre de cada socio, de mayor a menor.
     *
     * `withTrashed()`: un socio que ya salió pudo poner algo antes de irse, y
     * ese renglón tiene que salir con su nombre — es justo el que alguien va
     * a preguntar.
     *
     * @param array<int, Monto> $puesto
     *
     * @return list<array{nombre: string, monto: Monto}>
     */
    public static function puestoConNombre(array $puesto): array
    {
        if ($puesto === []) {
            return [];
        }

        /** @var array<int, string> $nombres */
        $nombres = Socio::query()
            ->withTrashed()
            ->whereIn('id', array_keys($puesto))
            ->pluck('nombre', 'id')
            ->all();

        // De mayor a menor. Se ordena el mapa —que sabe que son Monto— y no
        // la lista: un closure con `array $a` no puede llevar `@param`, pierde
        // la forma y PHPStan deja de saber que adentro hay un Monto.
        uasort($puesto, static fn (Monto $a, Monto $b): int => $b->mayorQue($a) ? 1 : ($a->mayorQue($b) ? -1 : 0));

        $lista = [];

        foreach ($puesto as $id => $monto) {
            $lista[] = ['nombre' => $nombres[$id] ?? '—', 'monto' => $monto];
        }

        return $lista;
    }

    // ─── Lo que vencía en el mes y no se pagó ─────────────────────────

    /**
     * Las cuotas que vencían en el mes y quedaron debiendo, una por renglón.
     *
     * ═══ QUE ENTRA Y QUE NO ═══
     *
     *  - `pendientes()` — le falta algo. **La cuota a medias entra**: quien
     *    abonó L 500 de L 2,000 no pagó su cuota, y las columnas monto/pagado/
     *    saldo lo cuentan sin que haya que explicarlo.
     *  - `deLotesVivos()` — la cuota de un lote rescindido (R22) que sobrevivió
     *    por tener un recibo colgando NO es deuda de nadie. Sin esto, el papel
     *    saldría a cobrarle a alguien que ya devolvió el lote.
     *  - `vigentes()` — el borrador todavía no es un contrato y el anulado dejó
     *    de serlo.
     *
     * ⚠️ **Las ventas se filtran por `vigentes()` acá y NO en los ingresos, y
     * es a propósito.** Un cobro de marzo entró en la caja de marzo aunque el
     * expediente se anule en agosto: la historia no se reescribe. La deuda sí
     * es del presente, y la de un expediente muerto no existe.
     *
     * @return list<CuotaSinPagar>
     */
    private static function cuotasSinPagar(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $cuotas = self::cuotasQueVencenEntre($proyecto, $desde, $hasta)
            ->pendientes()
            /*
             * Precargado: el titular y el lote son dos consultas para toda la
             * lista. Sin esto, cien cuotas son doscientas consultas —y esta
             * hoja se imprime con cien cuotas justamente los meses malos—.
             */
            ->with(['venta.titulares', 'compromiso.lote'])
            ->orderBy('fecha_vencimiento')
            ->orderBy('venta_id')
            ->orderBy('numero')
            ->get();

        $filas = [];

        foreach ($cuotas as $cuota) {
            $vence = $cuota->getAttribute('fecha_vencimiento');

            if (! $vence instanceof CarbonInterface) {
                continue;
            }

            $venta = $cuota->venta;
            $lote = $cuota->compromiso?->lote;

            $filas[] = new CuotaSinPagar(
                expediente: self::texto($venta?->getAttribute('numero_contrato')),
                cliente: self::texto($venta?->titulares->first()?->getAttribute('nombre')),
                lote: self::texto($lote?->getAttribute('codigo')),
                numero: (int) $cuota->getAttribute('numero'),
                vence: CarbonImmutable::parse($vence->toDateString()),
                monto: $cuota->montoTotal(),
                pagado: $cuota->montoPagado(),
                saldo: $cuota->saldo(),
                // El atraso lo dice la cuota, que es donde vive la regla.
                diasDeAtraso: $cuota->diasDeAtraso(),
            );
        }

        return $filas;
    }

    /**
     * Todo lo que vencía en el mes, pagado o no.
     *
     * Es el denominador del cumplimiento: sin él, «quedaron L 40,000 sin
     * cobrar» no dice si eso fue un mal mes o un mes normal.
     */
    private static function loQueVencio(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): Monto
    {
        return self::aMonto(
            self::cuotasQueVencenEntre($proyecto, $desde, $hasta)
                ->selectRaw('COALESCE(SUM(monto), 0) AS total')
                ->value('total')
        );
    }

    /**
     * La base de las dos consultas de arriba: una sola definición de «las
     * cuotas de este mes», para que el detalle y el total no puedan discrepar.
     *
     * @return Builder<Cuota>
     */
    private static function cuotasQueVencenEntre(int $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): Builder
    {
        return Cuota::query()
            ->reorder()
            ->deLotesVivos()
            ->whereIn('venta_id', self::ventasVigentesDelProyecto($proyecto))
            ->whereBetween('fecha_vencimiento', [$desde->toDateString(), $hasta->toDateString()]);
    }

    // ─── El año, mes por mes ──────────────────────────────────────────

    /**
     * @return array<string, Monto> clave `YYYY-MM`
     */
    private function cobradoPorMes(int $proyecto, int $anio): array
    {
        $filas = Recibo::query()
            ->reorder()
            ->whereNull('anulado_el')
            ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
            ->whereBetween('fecha', $this->elAnio($anio))
            ->groupByRaw("to_char(fecha, 'YYYY-MM')")
            ->selectRaw("to_char(fecha, 'YYYY-MM') AS mes, COALESCE(SUM(monto), 0) AS total")
            ->pluck('total', 'mes');

        return $this->aMontos($filas->all());
    }

    /**
     * @return array<string, Monto> clave `YYYY-MM`
     */
    private function gastadoPorMes(int $proyecto, int $anio): array
    {
        // Los mismos egresos que el cierre del mes: un renglón del año que
        // no coincide con la hoja de ese mes es la discrepancia que hace que
        // nadie le crea a ninguna de las dos.
        $filas = Gasto::query()
            ->reorder()
            ->where('proyecto_id', $proyecto)
            ->whereBetween('fecha', $this->elAnio($anio))
            ->groupByRaw("to_char(fecha, 'YYYY-MM')")
            ->selectRaw("to_char(fecha, 'YYYY-MM') AS mes, COALESCE(SUM(monto), 0) AS total")
            ->pluck('total', 'mes');

        return $this->aMontos($filas->all());
    }

    /**
     * Las dos mitades de `devoluciones` —la de la venta y la del compromiso—
     * sumadas mes por mes. El porqué de las dos consultas está arriba, en
     * `devuelto()`.
     *
     * @return array<string, Monto> clave `YYYY-MM`
     */
    private function devueltoPorMes(int $proyecto, int $anio): array
    {
        $porVenta = Devolucion::query()
            ->reorder()
            ->whereIn('venta_id', self::ventasDelProyecto($proyecto))
            ->whereBetween('fecha', $this->elAnio($anio))
            ->groupByRaw("to_char(fecha, 'YYYY-MM')")
            ->selectRaw("to_char(fecha, 'YYYY-MM') AS mes, COALESCE(SUM(monto_devuelto), 0) AS total")
            ->pluck('total', 'mes');

        $porCompromiso = Devolucion::query()
            ->reorder()
            ->whereNull('venta_id')
            ->whereIn('compromiso_id', self::compromisosDelProyecto($proyecto))
            ->whereBetween('fecha', $this->elAnio($anio))
            ->groupByRaw("to_char(fecha, 'YYYY-MM')")
            ->selectRaw("to_char(fecha, 'YYYY-MM') AS mes, COALESCE(SUM(monto_devuelto), 0) AS total")
            ->pluck('total', 'mes');

        $total = $this->aMontos($porVenta->all());

        foreach ($this->aMontos($porCompromiso->all()) as $mes => $monto) {
            $total[$mes] = ($total[$mes] ?? Monto::cero())->sumar($monto);
        }

        return $total;
    }

    /**
     * @return array{string, string}
     */
    private function elAnio(int $anio): array
    {
        return [sprintf('%04d-01-01', $anio), sprintf('%04d-12-31', $anio)];
    }

    /**
     * @param array<array-key, mixed> $filas
     *
     * @return array<string, Monto>
     */
    private function aMontos(array $filas): array
    {
        $montos = [];

        foreach ($filas as $clave => $valor) {
            $montos[(string) $clave] = self::aMonto($valor);
        }

        return $montos;
    }

    // ─── Herramientas ─────────────────────────────────────────────────

    /**
     * @return Builder<Venta>
     */
    private static function ventasDelProyecto(int $proyecto): Builder
    {
        return Venta::query()->reorder()->select('id')->where('proyecto_id', $proyecto);
    }

    /**
     * Solo los expedientes que siguen vivos. El porqué —y por qué los ingresos
     * NO lo usan— está en `cuotasSinPagar()`.
     *
     * @return Builder<Venta>
     */
    private static function ventasVigentesDelProyecto(int $proyecto): Builder
    {
        return self::ventasDelProyecto($proyecto)->vigentes();
    }

    /**
     * @return Builder<Compromiso>
     */
    private static function compromisosDelProyecto(int $proyecto): Builder
    {
        return Compromiso::query()->reorder()->select('id')->whereIn('venta_id', self::ventasDelProyecto($proyecto));
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

    /**
     * Un dato de texto para el papel, o la raya que se usa cuando no hay.
     *
     * El guion largo y no la cadena vacía: una celda vacía en una tabla
     * impresa parece un error de impresión, y quien la ve pregunta por ella.
     */
    private static function texto(mixed $valor): string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : '—';
    }
}
