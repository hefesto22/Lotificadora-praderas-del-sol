<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\ModalidadDeMora;
use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\Exceptions\ReestructuracionInvalidaException;
use App\Domain\Pagos\MoraDelLote;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Models\AplicacionDePago;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Reestructuracion;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Rehacer el plan de un contrato que se está pagando (25-oct-2026).
 *
 * ═══ DE DÓNDE VIENE ═══
 *
 * «Hay que hacer reestructuración de contrato, ya sea de un lote o de dos …
 * así como lo manejamos en Constructora Maya» —Mauricio, con el expediente
 * 0033 enfrente—. Es el motor de Maya (25-sep → 18-oct-2026), traído entero
 * en lo que sirve para cualquier lotificadora, y con una pieza nueva que Maya
 * no tenía: **cada lote con su propia cuota**.
 *
 * ═══ QUE HACE ═══
 *
 * Toma lo que le falta a cada lote del contrato desde el primer mes con saldo
 * —o desde la cuota 1, si se pide— y arma un plan nuevo con las condiciones
 * pactadas: cuántos meses o de cuánto la cuota, con el interés incluido o
 * encima, a qué tasa y desde cuándo. `PlanUnificado` hace la cuenta.
 *
 * Con dos o más lotes hay dos maneras, y las decide quien reestructura
 * (`CondicionesDeReestructuracion::$cadaLote`):
 *
 *  - **Unificados**: una sola cuota por todos. El capital se junta, se arma un
 *    plan y se reparte de vuelta entre los lotes, que es donde viven las
 *    cuotas. Lo pagado llena el plan nuevo mes por mes, lote por lote.
 *  - **Cada lote con su cuota** (Praderas, 25-oct-2026): «se reestructurarían
 *    los dos lotes de manera individual a 24 meses cada uno». Cada lote arma
 *    su plan con las mismas condiciones, y lo que un recibo pagó de un lote
 *    vuelve a ese lote, nunca al otro.
 *
 * ═══ 🔴 LO YA PAGADO SE REUBICA SOLO ═══
 *
 * El tramo que se reemplaza incluye lo pagado adentro de él, y cada recibo
 * vivo se vuelve a poner sobre el plan nuevo, del más viejo al más nuevo, en
 * el orden en que se cobra (`CoberturaDelPlan`): interés antes que capital.
 * El recibo NO cambia de número, de fecha, de monto ni de nombre: cambia a
 * qué cuotas se aplicó. Por eso:
 *
 *  - **Sigue cuadrando**: lo que se le quita a sus renglones viejos es
 *    exactamente lo que se le pone en los nuevos, y se verifica recibo por
 *    recibo antes de terminar (`asentarLosPagos()`).
 *  - **La mora que ya cobró NO entra en la cuenta**: es ganancia del atraso,
 *    no abono. Sigue siendo mora en su recibo, asentada en una cuota ya
 *    pagada (`dondeQuedaLaMora()`).
 *  - **El acta dice qué recibo se movió, de dónde y a dónde**: una
 *    reimpresión que ahora dice otra cosa se anuncia en el papel que firma el
 *    cliente, no se descubre en ventanilla.
 *  - **La mora que corría se perdona**: el plan nuevo nace al día y el acta
 *    escribe cuánta se perdonó.
 *
 * ═══ DESDE LA PRÓXIMA CUOTA, O DESDE LA CUOTA 1 ═══
 *
 * Sin `$desdeLaPrimera` los meses pagados enteros antes del tramo no se
 * tocan: el plan nuevo es para lo que falta. Con él, el plan se rehace
 * entero, TODOS los recibos se vuelven a poner sobre el plan nuevo por fecha,
 * y quedan pagando los meses para los que se pagaron. En el 0033 —dos lotes
 * de L 240,000.00 con L 40,000.00 pagados cada uno— a 24 meses: desde la
 * próxima, L 200,000.00 por lote en cuotas de L 8,333.33; desde la cuota 1,
 * L 240,000.00 por lote en cuotas de L 10,000.00, con las cuatro primeras ya
 * pagadas.
 *
 * ═══ LO QUE NO TOCA ═══
 *
 *  - **Los recibos anulados**: sus renglones son historia y se quedan
 *    colgando de la misma fila, que se reescribe en su lugar.
 *  - **El precio, el área y el valor** de cada lote: quedaron congelados al
 *    firmar (§8.6) y reestructurar no los reabre. Tampoco
 *    `compromisos.plazo_meses`, que es el plazo con el que se firmó.
 *
 * ═══ LO QUE SÍ CAMBIA, Y QUEDA ESCRITO ═══
 *
 *  - Las cuotas del tramo, por lote, con su plan viejo guardado en una
 *    constancia de `reprogramaciones` (§9.D6: el histórico no se pierde).
 *  - Los renglones de los recibos vivos sobre el tramo, y el lote del que
 *    habla un recibo de un solo lote si ahora paga cuotas de otro.
 *  - La tasa del lote, si se pactó otra. La de antes queda en el acta.
 *  - El día de pago del contrato, que es el día del primer vencimiento.
 *  - Un acta por contrato (`reestructuraciones`) con el antes y el después, y
 *    cómo quedó cada lote (`lotes_detalle`).
 *
 * ═══ LO QUE DE MAYA NO SE TRAJO, Y POR QUÉ ═══
 *
 *  - **Anular los recibos y emitir uno por cuota**: era el arreglo de los
 *    expedientes que Maya traía de su hoja de control. Acá no hace falta —los
 *    recibos se reubican sin anular nada— y quemar correlativos no se hace
 *    de pasada (R12).
 *  - **A fechas y con descuento** (`renegociar()`): el motor los sabe armar,
 *    pero un contrato a fechas y un capital perdonado tienen que poder leerse
 *    en el estado de cuenta y en el abono (R21) antes de escribirse. Hasta
 *    entonces se niegan con su motivo (`verificarQueSePuedePactar()`).
 *
 * ═══ UNA SOLA PUERTA PARA MIRAR Y PARA ESCRIBIR ═══
 *
 * `situacion()` + `propuesta()` + `cobertura()` son lo que dibuja el modal;
 * `reestructurar()` hace exactamente lo mismo adentro de la transacción,
 * releyendo con candado (§9.D17) —entre que se abrió el modal y se confirmó
 * pudo entrar un cobro—. Lo que la pantalla prometió es lo que se escribe, o
 * se niega con el motivo.
 */
final readonly class RegistroDeReestructuraciones
{
    public function __construct(
        private RegistroDePagos $pagos,
    ) {}

    /**
     * La foto de hoy, sin bloquear nada: para la vista previa.
     *
     * `$lotes` son los ids de compromiso que se juntan; null, todos los que
     * todavía deben (26-sep-2026: «si solo quiero unificar 2 de 4 lotes debe
     * poderse»). Los que quedan afuera no se tocan.
     *
     * @param list<int>|null $lotes
     * @param bool $desdeLaPrimera rehacer el plan entero, desde la cuota 1, y no desde el primer
     *                             mes que debe algo (07-oct-2026); ver el docblock de la clase
     */
    public function situacion(Venta $venta, ?array $lotes = null, bool $desdeLaPrimera = false): SituacionDelPlan
    {
        return $this->leer($venta, bloquear: false, elegidos: $lotes, desdeLaPrimera: $desdeLaPrimera);
    }

    /**
     * El plan nuevo que saldría con esas condiciones, o la razón por la que
     * no sale.
     *
     * @param bool $aunqueNazcaAtrasado dejar que nazca con cuotas vencidas sin cubrir: los
     *                                  pagos se registran después con su fecha (07-oct-2026)
     *
     * @throws ReestructuracionInvalidaException
     * @throws PlanDeCuotasInvalidoException
     */
    public function propuesta(SituacionDelPlan $situacion, CondicionesDeReestructuracion $condiciones, bool $aunqueNazcaAtrasado = false): PlanUnificado
    {
        $contrato = (string) $situacion->venta->getAttribute('numero_contrato');

        if ($situacion->impedimentos !== []) {
            throw $situacion->impedimentos[0];
        }

        if ($situacion->lotes === []) {
            throw ReestructuracionInvalidaException::porNoQuedarNadaQueReestructurar($contrato);
        }

        $condiciones = $this->normalizar($situacion, $condiciones);

        $this->verificarElPrimerVencimiento($situacion, $condiciones->primerVencimiento);
        $this->verificarElDiaDePago($situacion, $condiciones);

        /*
         * Juntarlos pasaría lo que pagó una persona a la cuota de otra. Con
         * cada lote por su lado no: lo de cada lote vuelve a su lote.
         */
        if (! $condiciones->cadaLote && $situacion->pagos !== [] && $this->hayTitularesDistintos($situacion->lotes)) {
            throw ReestructuracionInvalidaException::porTitularesDistintos(
                (string) $situacion->venta->getAttribute('numero_contrato'),
            );
        }

        $plan = PlanUnificado::armar($situacion->capitales(), $condiciones, $situacion->primerNumero());

        /*
         * Una cuota con un cobro anulado no se puede borrar —conserva el
         * renglón del recibo anulado, `restrictOnDelete`— así que se reescribe
         * en su lugar. Para eso el plan nuevo DE ESE LOTE tiene que llegar a
         * su número: con cada lote por su lado y cuota fija, uno puede
         * terminar antes que el contrato.
         */
        foreach ($situacion->lotes as $id => $lote) {
            $ultimo = $situacion->primerNumero() + ($plan->cadaLote ? count($plan->porLote[$id] ?? []) : $plan->meses()) - 1;

            foreach (array_keys($lote['conCobroAnulado']) as $numero) {
                if ($numero > $ultimo) {
                    throw ReestructuracionInvalidaException::porCuotaConCobroAnuladoFueraDelPlan($numero, $lote['codigo']);
                }
            }
        }

        $cobertura = CoberturaDelPlan::de($plan);

        if ($situacion->yaPagado()->mayorQue($cobertura->total())) {
            throw ReestructuracionInvalidaException::porPagadoMayorQueElPlan($situacion->yaPagado(), $cobertura->total());
        }

        // Con cada lote por su lado, lo pagado de un lote tiene que caber en SU plan.
        if ($plan->cadaLote) {
            foreach (array_keys($situacion->lotes) as $id) {
                if ($situacion->yaPagadoDe($id)->mayorQue($cobertura->totalDelLote($id))) {
                    throw ReestructuracionInvalidaException::porPagadoMayorQueElPlan($situacion->yaPagadoDe($id), $cobertura->totalDelLote($id));
                }
            }
        }

        if (! $aunqueNazcaAtrasado) {
            $this->verificarQueNoNazcaAtrasado($situacion, $plan);
        }

        return $plan;
    }

    /**
     * La cuota más alta con la que el plan nace al día: la que lo ya pagado
     * alcanza a cubrir en toda cuota que ya venció. Null si no hay tope —con
     * cualquier cuota nace al día— o si ninguna alcanza.
     *
     * ═══ ES UNA SUGERENCIA, NO UN AJUSTE (26-sep-2026) ═══
     *
     * Hubo un día en que el plan se ajustaba solo a esta cuota cuando los
     * meses pedidos lo hacían nacer atrasado, y no sirvió: «automático se
     * coloca 100000 cuando no se sabe si pueda ser otro número» —Mauricio—.
     * La cuota la pacta la administradora con el cliente; el sistema le dice
     * hasta dónde puede llegar y ella elige.
     *
     * El caso: el cliente ya entregó L 100,000.00 y el plan arranca el 15/09,
     * que ya pasó. Con una cuota de L 100,100.00 a la cuota 1 le faltarían
     * L 100.00 y el plan nacería con mora, que es justo lo que reestructurar
     * perdona. El tope es L 100,000.00.
     *
     * ═══ COMO ═══
     *
     * Búsqueda binaria en centavos hasta el doble del capital —con eso el
     * plan es un solo pago—. Bajar la cuota solo puede cubrir más cuotas
     * vencidas, nunca menos, así que el borde es uno solo y sale en unas 28
     * vueltas. Con la tasa, el interés y el primer vencimiento de `$base`.
     */
    public function cuotaMaximaAlDia(SituacionDelPlan $situacion, CondicionesDeReestructuracion $base): ?Monto
    {
        if (! $situacion->sePuede() || $situacion->yaPagado()->esCero()) {
            return null;
        }

        // Con el descuento de `$base`: el tope es sobre el capital que queda.
        // Y con la misma agrupación: cada lote con su cuota, la cuota es por lote.
        $conCuota = static function (int $centavos) use ($base): CondicionesDeReestructuracion {
            $condiciones = CondicionesDeReestructuracion::porCuota(
                Monto::deCentavos($centavos),
                $base->interesIncluido,
                $base->tasa,
                $base->primerVencimiento,
            )->conDescuento($base->descuento());

            return $base->cadaLote ? $condiciones->cadaLoteConSuCuota() : $condiciones;
        };

        $techo = $situacion->capitalTotal()->multiplicarPor(2)->enCentavos();

        // Ni en un solo pago nace atrasado: no hay tope que decir.
        if (! $this->naceAtrasado($situacion, $conCuota($techo))) {
            return null;
        }

        // Al terminar, `$alto` es la cuota más alta que no nace atrasada.
        $bajo = 1;
        $alto = $techo - 1;

        while ($bajo <= $alto) {
            $medio = intdiv($bajo + $alto, 2);

            if ($this->naceAtrasado($situacion, $conCuota($medio))) {
                $alto = $medio - 1;
            } else {
                $bajo = $medio + 1;
            }
        }

        if ($alto < 1) {
            return null;
        }

        try {
            PlanUnificado::armar($situacion->capitales(), $conCuota($alto), $situacion->primerNumero());
        } catch (PlanDeCuotasInvalidoException) {
            return null;
        }

        return Monto::deCentavos($alto);
    }

    /**
     * ¿Con estas condiciones el plan nacería con una cuota vencida sin
     * cubrir? Un plan que ni siquiera se puede armar —una cuota tan chica que
     * pasa del plazo máximo— cuenta como que NO: así la búsqueda de
     * `cuotaMaximaAlDia()` avanza pareja, y lo que encuentra se vuelve a
     * armar antes de devolverlo.
     *
     * La pantalla lo pregunta para saber si, junto a la negativa, conviene
     * decir hasta qué cuota se puede.
     */
    public function naceAtrasado(SituacionDelPlan $situacion, CondicionesDeReestructuracion $condiciones): bool
    {
        try {
            $plan = PlanUnificado::armar($situacion->capitales(), $this->normalizar($situacion, $condiciones), $situacion->primerNumero());
        } catch (PlanDeCuotasInvalidoException|ReestructuracionInvalidaException) {
            return false;
        }

        return $this->atraso($situacion, $plan) !== null;
    }

    /**
     * Las cuotas del plan nuevo que ya vencieron y que lo ya pagado no cubre
     * entera, con lo que les falta en total. Cero si nace al día.
     *
     * Es lo que la pantalla dice cuando se pide que nazca atrasado
     * (07-oct-2026): cuántos pagos hay que registrar después, y por cuánto.
     *
     * @return array{cuotas: int, falta: Monto}
     */
    public function vencidasAlNacer(SituacionDelPlan $situacion, PlanUnificado $plan): array
    {
        $cobertura = $this->cobertura($situacion, $plan);

        // `today()` lo genera PHP, nunca Postgres (§7.5).
        $hoy = CarbonImmutable::parse(today()->toDateString());
        $cuotas = 0;
        $falta = Monto::cero();

        foreach ($plan->contrato->cuotas as $cuota) {
            if (! $cuota->vencimiento->lessThan($hoy)) {
                break;
            }

            $leFalta = $cuota->monto->restar($cobertura->cubiertoDelMes($cuota->numero));

            if ($leFalta->mayorQue(Monto::cero())) {
                $cuotas++;
                $falta = $falta->sumar($leFalta);
            }
        }

        return ['cuotas' => $cuotas, 'falta' => $falta];
    }

    /**
     * Lo ya pagado puesto sobre ese plan, recibo por recibo y en el mismo
     * orden en que lo va a escribir `reestructurar()`. Es lo que el modal
     * muestra en la columna «Ya pagado».
     */
    public function cobertura(SituacionDelPlan $situacion, PlanUnificado $plan): CoberturaDelPlan
    {
        $cobertura = CoberturaDelPlan::de($plan);

        foreach ($situacion->pagos as $pago) {
            $this->ponerElPago($cobertura, $situacion, $plan, $pago);
        }

        return $cobertura;
    }

    /**
     * Cuadra el plan: lo rehace con meses o cuota, reubica lo pagado y emite
     * el acta.
     *
     * ═══ EL ORDEN DE LOS CANDADOS ═══
     *
     * La venta, después los recibos que se van a mover, después las cuotas de
     * los lotes ordenadas por lote y por número. Anular un recibo bloquea el
     * recibo y después sus cuotas, y un cobro bloquea cuotas: con este orden
     * nadie espera a nadie en círculo (§9.D15).
     *
     * ═══ CUADRAR, NO RENEGOCIAR (18-oct-2026) ═══
     *
     * Las fechas pactadas, el descuento y quitar la mora son condiciones
     * nuevas del contrato: van por `renegociar()`, con su acta y su permiso.
     * Y un contrato a fechas no tiene cuota por mes que cuadrar.
     *
     * @param list<int>|null $lotes los que se juntan; null, todos los que deben
     * @param bool $desdeLaPrimera rehacer el plan entero, desde la cuota 1 (07-oct-2026)
     * @param bool $aunqueNazcaAtrasado dejar que nazca con cuotas vencidas sin cubrir (07-oct-2026)
     *
     * @throws ReestructuracionInvalidaException
     * @throws PlanDeCuotasInvalidoException
     */
    public function reestructurar(
        Venta $venta,
        CondicionesDeReestructuracion $condiciones,
        string $motivo,
        ?array $lotes = null,
        bool $desdeLaPrimera = false,
        bool $aunqueNazcaAtrasado = false,
    ): Reestructuracion {
        if ($condiciones->pideRenegociar()) {
            throw ReestructuracionInvalidaException::porSerUnaRenegociacion();
        }

        return $this->rehacer($venta, $condiciones, $motivo, $lotes, $desdeLaPrimera, $aunqueNazcaAtrasado, Reestructuracion::TIPO_REESTRUCTURACION);
    }

    /**
     * Renegocia el contrato: condiciones nuevas para lo que debe —cuota por
     * mes o fechas, la tasa, la mora y un descuento— con lo que ya pagó tal
     * como se pagó (18-oct-2026).
     *
     * ═══ QUE SE PIDIO ═══
     *
     * «Ya está la reestructuración, que es para cuadrar un plan, pero falta la
     * renegociación, que es para rehacer de 0 con lo que pagó, si se le dan
     * descuentos, si se le quita la mora o se le cambia la tasa» —Mauricio—.
     * Y lo que ya pagó, «como se pagó»: una cuota cobrada con interés sigue
     * siendo eso, y lo nuevo es para lo que falta. Por eso nunca rehace desde
     * la cuota 1 ni anula recibos: el tramo arranca en el primer mes que debe
     * algo, igual que `reestructurar()` sin `$desdeLaPrimera`.
     *
     * Es el MISMO trámite que reestructurar —el mismo plan, la misma
     * reubicación, los mismos candados—; cambia lo que se puede pactar, y el
     * acta dice que es una renegociación.
     *
     * @param list<int>|null $lotes los que entran; null, todos los que deben
     * @param bool $aunqueNazcaAtrasado dejar que nazca con fechas vencidas sin cubrir: los pagos se
     *                                  registran después con su fecha (07-oct-2026)
     *
     * @throws ReestructuracionInvalidaException
     * @throws PlanDeCuotasInvalidoException
     */
    public function renegociar(
        Venta $venta,
        CondicionesDeReestructuracion $condiciones,
        string $motivo,
        ?array $lotes = null,
        bool $aunqueNazcaAtrasado = false,
    ): Reestructuracion {
        return $this->rehacer($venta, $condiciones, $motivo, $lotes, false, $aunqueNazcaAtrasado, Reestructuracion::TIPO_RENEGOCIACION);
    }

    /**
     * El trámite de los dos: rehace el plan, reubica lo pagado y emite el acta.
     *
     * @param list<int>|null $lotes
     *
     * @throws ReestructuracionInvalidaException
     * @throws PlanDeCuotasInvalidoException
     */
    private function rehacer(
        Venta $venta,
        CondicionesDeReestructuracion $condiciones,
        string $motivo,
        ?array $lotes,
        bool $desdeLaPrimera,
        bool $aunqueNazcaAtrasado,
        string $tipo,
    ): Reestructuracion {
        $porQue = trim($motivo);

        if ($porQue === '') {
            throw ReestructuracionInvalidaException::porFaltarElMotivo();
        }

        $this->verificarQueSePuedePactar($condiciones);

        return DB::transaction(function () use ($venta, $condiciones, $porQue, $lotes, $desdeLaPrimera, $aunqueNazcaAtrasado, $tipo): Reestructuracion {
            $fresca = Venta::query()->whereKey($venta->getKey())->lockForUpdate()->firstOrFail();

            $situacion = $this->leer($fresca, bloquear: true, elegidos: $lotes, desdeLaPrimera: $desdeLaPrimera);
            $plan = $this->propuesta($situacion, $condiciones, $aunqueNazcaAtrasado);
            $reubicacion = $this->reubicar($situacion, $plan);

            // La mora que se quita, como se leía ANTES de quitarla: el acta la imprime.
            $moraQuitada = $condiciones->quitaLaMora ? $this->laMoraQueSeQuita($situacion) : null;

            $acta = $this->emitirElActa($fresca, $situacion, $condiciones, $plan, $reubicacion, $porQue, $tipo, $moraQuitada);

            $this->soltarLosRenglones($situacion);

            foreach ($situacion->lotes as $id => $lote) {
                $nuevas = $plan->porLote[$id];

                $this->reescribir($fresca, $lote, $nuevas);
                $this->asentarLaConstancia($fresca, $acta, $lote, $nuevas, $situacion->primerNumero(), $porQue, $plan->descuentos[$id] ?? Monto::cero());

                $this->pactarLaTasa($lote['compromiso'], $condiciones->tasa, $acta, $porQue);

                if ($moraQuitada !== null) {
                    $this->quitarLaMora($lote['compromiso']);
                }
            }

            $this->asentarLosPagos($situacion, $reubicacion['recibos']);

            // Con lotes afuera el día no cambia: lo exige `verificarElDiaDePago()`.
            if ($situacion->afuera === []) {
                $fresca->update(['dia_pago' => $condiciones->diaDePago()]);
            }

            // El horizonte y la cuota del contrato son un resumen de `cuotas`:
            // acaban de cambiar, y la lista de ventas tiene que decirlo.
            $this->pagos->recalcularElResumen($fresca);

            // La mora que quedó corriendo sobre el plan nuevo, ya escrito.
            $acta->update(['mora_despues' => $this->moraDeHoy($situacion)->redondeado()]);

            // Si lo pagado alcanzó para todo el plan nuevo, no debe nada.
            $fresca->liquidarSiYaNoDebe(CarbonImmutable::parse(today()->toDateString()));

            $this->asentarEnLaBitacora($fresca, $acta, $situacion, $plan, $porQue);

            return $acta;
        });
    }

    /**
     * Lo que el motor sabe armar pero este sistema todavía no sabe leer
     * después (25-oct-2026). Ver «lo que de Maya no se trajo».
     *
     * Un contrato a fechas tiene que poder recibir un abono (R21) sin volver a
     * cuotas por mes, y un capital perdonado tiene que restarse en el estado
     * de cuenta para que siga cerrando en cero. Escribirlos antes de eso
     * dejaría un expediente que no cuadra.
     *
     * @throws ReestructuracionInvalidaException
     */
    private function verificarQueSePuedePactar(CondicionesDeReestructuracion $condiciones): void
    {
        if ($condiciones->esAFechas()) {
            throw ReestructuracionInvalidaException::porCondicionQueTodaviaNoSePuede('un plan a fechas');
        }

        if (! $condiciones->descuento()->esCero()) {
            throw ReestructuracionInvalidaException::porCondicionQueTodaviaNoSePuede('un descuento de capital');
        }
    }

    /**
     * La mora que tenían los lotes antes de quitarla, en palabras y lote por
     * lote: «RAL-P-005: …». Null si ninguno cobraba mora — entonces no hay
     * nada que quitar y el acta no dice que se quitó.
     */
    private function laMoraQueSeQuita(SituacionDelPlan $situacion): ?string
    {
        $partes = [];

        foreach ($situacion->lotes as $lote) {
            $mora = $lote['compromiso']->condicionesDeMora();

            if ($mora->cobra()) {
                $partes[] = $lote['codigo'].': '.$mora->descripcion();
            }
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /**
     * Desde el acta el lote no cobra mora (18-oct-2026).
     *
     * La mora es del contrato firmado (§8.6) y se calcula al vuelo con lo que
     * dice el lote: con «ninguna», ningún atraso de acá en adelante la genera.
     * La que ya se cobró en un recibo queda como se cobró; la que corría hasta
     * hoy la perdona la reestructuración, como siempre. Lo que tenía antes lo
     * guarda el acta (`mora_anterior`).
     */
    private function quitarLaMora(Compromiso $lote): void
    {
        $lote->update([
            'mora_modalidad'   => ModalidadDeMora::Ninguna->value,
            'mora_monto'       => '0.00',
            'mora_porcentaje'  => '0.000',
            'mora_dias_gracia' => 0,
        ]);
    }

    /**
     * El asiento en la bitácora del expediente, con el acta que lo respalda.
     *
     * ═══ POR QUE (26-sep-2026) ═══
     *
     * «Acá en Actualizaciones debería estar para imprimir el acta» —Mauricio—.
     * La pestaña ya mostraba el cambio de cuota y de plazo que deja
     * `recalcularElResumen()`, pero sin decir que vino de una
     * reestructuración ni dónde está el papel. Este asiento lo dice, con el
     * motivo, y guarda el id del acta: la pestaña pone al lado el botón para
     * volver a imprimirla.
     *
     * El antes y el después van con `withChanges()`, NO con
     * `withProperties()` (§9.D22): la pestaña lee `attribute_changes`. El
     * motivo y el acta van en `properties`, como en `CambioDeTitular`.
     */
    private function asentarEnLaBitacora(Venta $venta, Reestructuracion $acta, SituacionDelPlan $situacion, PlanUnificado $plan, string $motivo): void
    {
        $antes = $situacion->mesesQueFaltan();
        $despues = $plan->meses();

        activity()
            ->performedOn($venta)
            ->causedBy(auth()->user())
            ->withChanges([
                'old' => ['plan' => sprintf(
                    '%d %s de %s',
                    $antes,
                    $antes === 1 ? 'cuota' : 'cuotas',
                    $situacion->cuotaDeHoy()?->formateado() ?? '—',
                )],
                'attributes' => ['plan' => sprintf(
                    '%d %s %s %s%s · acta %s%s',
                    $despues,
                    $despues === 1 ? 'pago' : 'pagos',
                    $acta->esAFechas() ? 'a fechas, el primero de' : 'de',
                    $plan->contrato->cuotaMensual()?->formateado() ?? '—',
                    $plan->cadaLote ? ' (cada lote con su cuota)' : '',
                    $acta->folio(),
                    $plan->descuentoTotal()->esCero() ? '' : ' · descuento '.$plan->descuentoTotal()->formateado(),
                )],
            ])
            ->withProperty('motivo', $motivo)
            ->withProperty('reestructuracion_id', $acta->getKey())
            // El mismo evento para las dos: la pestaña pone al lado el botón del acta.
            ->event('reestructurado')
            ->log($acta->esRenegociacion() ? 'Contrato renegociado' : 'Plan reestructurado');
    }

    // ─── Leer ─────────────────────────────────────────────────────────

    /**
     * Arma la foto. Con `bloquear`, los recibos y las cuotas quedan con
     * candado hasta que termine la transacción que llama.
     *
     * @param list<int>|null $elegidos
     */
    private function leer(Venta $venta, bool $bloquear, ?array $elegidos = null, bool $desdeLaPrimera = false): SituacionDelPlan
    {
        $contrato = (string) $venta->getAttribute('numero_contrato');
        $impedimentos = [];

        if ($venta->getAttribute('estado') !== EstadoVenta::Vigente) {
            $impedimentos[] = ReestructuracionInvalidaException::porVentaQueNoEstaVigente($contrato);
        }

        /*
         * Los lotes vigentes en el orden del contrato (§9.D18): de ese orden
         * sale cuál absorbe los centavos cuando dos deben lo mismo, y a cuál
         * le toca primero lo ya pagado dentro de un mismo mes.
         */
        $vigentes = Compromiso::query()
            ->where('venta_id', $venta->getKey())
            ->where('estado', EstadoCompromiso::Vigente->value)
            ->reorder()
            ->orderBy('id')
            ->with('lote')
            ->get();

        if ($elegidos === []) {
            $impedimentos[] = ReestructuracionInvalidaException::porNoElegirNingunLote();
        }

        $compromisos = $elegidos === null
            ? $vigentes
            : $vigentes->filter(static fn (Compromiso $lote): bool => in_array((int) $lote->getKey(), $elegidos, true))->values();

        $afuera = $elegidos === null ? [] : $this->losQueQuedanAfuera($vigentes->all(), $elegidos);

        if ($bloquear) {
            $this->bloquearLosRecibos($compromisos->modelKeys());
        }

        $consulta = Cuota::query()
            ->whereIn('compromiso_id', $compromisos->modelKeys())
            ->reorder()
            ->orderBy('compromiso_id')
            ->orderBy('numero');

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        $todas = $consulta->get();

        // El tramo arranca en el primer mes al que le falta algo, en
        // cualquier lote. Sin ninguno, no hay nada que reestructurar.
        $desde = null;
        $primera = null;

        foreach ($todas as $cuota) {
            $numero = (int) $cuota->getAttribute('numero');
            $primera = $primera === null ? $numero : min($primera, $numero);

            if (! $cuota->saldo()->esCero()) {
                $desde = $desde === null ? $numero : min($desde, $numero);
            }
        }

        if ($desde === null) {
            return new SituacionDelPlan($venta, 1, [], [], $impedimentos, Monto::cero(), null, null, $afuera, $primera);
        }

        // Rehacerlo entero (07-oct-2026): el tramo es el plan completo, y lo
        // pagado en los meses de antes se reubica como todo lo demás.
        if ($desdeLaPrimera && $primera !== null) {
            $desde = $primera;
        }

        $cuotasPorLote = $todas->groupBy('compromiso_id');
        $tramos = [];
        $ultimaPagada = null;

        foreach ($compromisos as $compromiso) {
            /** @var list<Cuota> $cuotas */
            $cuotas = array_values($cuotasPorLote->get((int) $compromiso->getKey())?->all() ?? []);

            // Un lote terminado de pagar —o de contado— no entra: no hay nada
            // suyo que reestructurar, y sus recibos se quedan donde están.
            if (array_all($cuotas, static fn (Cuota $cuota): bool => $cuota->saldo()->esCero())) {
                continue;
            }

            $tramo = [];

            foreach ($cuotas as $cuota) {
                if ((int) $cuota->getAttribute('numero') >= $desde) {
                    $tramo[] = $cuota;

                    continue;
                }

                $vence = SituacionDelPlan::vence($cuota);

                if ($vence instanceof CarbonImmutable && (! $ultimaPagada instanceof CarbonImmutable || $vence->greaterThan($ultimaPagada))) {
                    $ultimaPagada = $vence;
                }
            }

            $tramos[(int) $compromiso->getKey()] = ['compromiso' => $compromiso, 'tramo' => $tramo];
        }

        [$vivos, $anulados, $recibos] = $this->losRenglones($tramos, $bloquear);

        $lotes = [];
        $vencidaMasVieja = null;
        $moraCorrida = Monto::cero();

        foreach ($tramos as $id => ['compromiso' => $compromiso, 'tramo' => $tramo]) {
            $codigo = (string) $compromiso->lote?->getAttribute('codigo');
            $capital = Monto::cero();
            $conCobroAnulado = [];

            foreach ($tramo as $cuota) {
                $clave = (int) $cuota->getKey();
                $numero = (int) $cuota->getAttribute('numero');
                $capital = $capital->sumar($cuota->montoCapital());

                if (isset($anulados[$clave])) {
                    $conCobroAnulado[$numero] = $clave;
                }

                $impedimento = $this->loQueTraba($cuota, $vivos[$clave] ?? [], $codigo);

                if ($impedimento instanceof ReestructuracionInvalidaException) {
                    $impedimentos[] = $impedimento;
                }

                if ($cuota->estaVencida()) {
                    $vence = SituacionDelPlan::vence($cuota);

                    if ($vence instanceof CarbonImmutable && (! $vencidaMasVieja instanceof CarbonImmutable || $vence->lessThan($vencidaMasVieja))) {
                        $vencidaMasVieja = $vence;
                    }
                }
            }

            $moraCorrida = $moraCorrida->sumar($this->moraDe($compromiso, $tramo));

            $lotes[$id] = [
                'compromiso'      => $compromiso,
                'codigo'          => $codigo,
                'capital'         => $capital,
                'tramo'           => $tramo,
                'conCobroAnulado' => $conCobroAnulado,
            ];
        }

        // Los titulares distintos solo traban si se unifican: lo mira `propuesta()`.
        $pagos = $this->losPagos($vivos, $recibos);

        return new SituacionDelPlan($venta, $desde, $lotes, $pagos, $impedimentos, $moraCorrida, $vencidaMasVieja, $ultimaPagada, $afuera, $primera);
    }

    /**
     * Los lotes que todavía deben y no se marcaron, por código: siguen con su
     * plan, y el acta y la pantalla lo dicen.
     *
     * @param array<int, Compromiso> $vigentes
     * @param list<int> $elegidos
     *
     * @return list<string>
     */
    private function losQueQuedanAfuera(array $vigentes, array $elegidos): array
    {
        $conSaldo = array_map(intval(...), Cuota::query()
            ->whereIn('compromiso_id', array_map(static fn (Compromiso $lote): int => (int) $lote->getKey(), $vigentes))
            ->whereColumn('monto_pagado', '<', 'monto')
            ->distinct()
            ->pluck('compromiso_id')
            ->all());

        $afuera = [];

        foreach ($vigentes as $lote) {
            $id = (int) $lote->getKey();

            if (! in_array($id, $elegidos, true) && in_array($id, $conSaldo, true)) {
                $afuera[] = (string) $lote->lote?->getAttribute('codigo');
            }
        }

        return $afuera;
    }

    /**
     * Los recibos con renglones sobre las cuotas de estos lotes, bloqueados
     * en orden de id ANTES que las cuotas. Ver «el orden de los candados».
     *
     * @param array<mixed> $compromisos ids de compromiso
     */
    private function bloquearLosRecibos(array $compromisos): void
    {
        $ids = AplicacionDePago::query()
            ->whereIn('cuota_id', Cuota::query()->select('id')->whereIn('compromiso_id', $compromisos))
            ->distinct()
            ->pluck('recibo_id')
            ->all();

        if ($ids === []) {
            return;
        }

        Recibo::query()
            ->whereKey(array_map(intval(...), $ids))
            ->reorder()
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * Los renglones de recibo que cuelgan del tramo, separados en los de
     * recibos vivos —que se mueven— y los de anulados —que se quedan—.
     *
     * @param array<int, array{compromiso: Compromiso, tramo: list<Cuota>}> $tramos
     *
     * @return array{0: array<int, list<AplicacionDePago>>, 1: array<int, true>, 2: array<int, Recibo>}
     */
    private function losRenglones(array $tramos, bool $bloquear): array
    {
        $cuotas = [];

        foreach ($tramos as $tramo) {
            foreach ($tramo['tramo'] as $cuota) {
                $cuotas[] = (int) $cuota->getKey();
            }
        }

        $renglones = $cuotas === []
            ? []
            : AplicacionDePago::query()->whereIn('cuota_id', $cuotas)->reorder()->orderBy('id')->get()->all();

        $idsDeRecibos = array_values(array_unique(array_map(
            static fn (AplicacionDePago $renglon): int => (int) $renglon->getAttribute('recibo_id'),
            $renglones,
        )));

        $recibos = [];

        if ($idsDeRecibos !== []) {
            $consulta = Recibo::query()->whereKey($idsDeRecibos)->reorder()->orderBy('id');

            if ($bloquear) {
                $consulta->lockForUpdate();
            }

            foreach ($consulta->get() as $recibo) {
                $recibos[(int) $recibo->getKey()] = $recibo;
            }
        }

        $vivos = [];
        $anulados = [];

        foreach ($renglones as $renglon) {
            $recibo = $recibos[(int) $renglon->getAttribute('recibo_id')] ?? null;
            $cuota = (int) $renglon->getAttribute('cuota_id');

            if (! $recibo instanceof Recibo) {
                continue;
            }

            if ($recibo->estaAnulado()) {
                $anulados[$cuota] = true;

                continue;
            }

            $vivos[$cuota][] = $renglon;
        }

        return [$vivos, $anulados, $recibos];
    }

    /**
     * Lo que impide mover lo pagado de esta cuota, si hay algo.
     *
     * Lo que la cuota dice que tiene pagado tiene que ser exactamente lo que
     * suman sus renglones vivos —dinero, mora cobrada y mora perdonada—: es
     * lo que se va a mover. Si no coinciden, no se sabe qué recibo pagó qué.
     *
     * @param list<AplicacionDePago> $vivos
     */
    private function loQueTraba(Cuota $cuota, array $vivos, string $codigo): ?ReestructuracionInvalidaException
    {
        $numero = (int) $cuota->getAttribute('numero');

        if (! $cuota->capitalCondonado()->esCero()) {
            return ReestructuracionInvalidaException::porCuotaConDescuento($numero, $codigo);
        }

        $aLaCuota = Monto::cero();
        $mora = Monto::cero();
        $condonada = Monto::cero();

        foreach ($vivos as $renglon) {
            $aLaCuota = $aLaCuota->sumar($renglon->montoALaCuota());
            $mora = $mora->sumar($renglon->montoMora());
            $condonada = $condonada->sumar($renglon->moraCondonada());
        }

        if (! $aLaCuota->igualA($cuota->montoPagado())) {
            return ReestructuracionInvalidaException::porPagoQueNoCuadraConSusRecibos($numero, $codigo, $cuota->montoPagado(), $aLaCuota);
        }

        if (! $mora->igualA($cuota->moraPagada()) || ! $condonada->igualA($cuota->moraCondonada())) {
            return ReestructuracionInvalidaException::porPagoQueNoCuadraConSusRecibos(
                $numero,
                $codigo,
                $cuota->montoPagado()->sumar($cuota->moraResuelta()),
                $aLaCuota->sumar($mora)->sumar($condonada),
            );
        }

        return null;
    }

    /**
     * Los renglones vivos agrupados por recibo, del recibo más viejo al más
     * nuevo: por fecha del papel y, en el mismo día, por número de fila. Es
     * el orden en que el dinero entró, y es el orden en que se vuelve a poner.
     *
     * @param array<int, list<AplicacionDePago>> $vivos
     * @param array<int, Recibo> $recibos
     *
     * @return list<array{recibo: Recibo, dinero: Monto, renglones: list<AplicacionDePago>}>
     */
    private function losPagos(array $vivos, array $recibos): array
    {
        $porRecibo = [];

        foreach ($vivos as $renglones) {
            foreach ($renglones as $renglon) {
                $porRecibo[(int) $renglon->getAttribute('recibo_id')][] = $renglon;
            }
        }

        $pagos = [];

        foreach ($porRecibo as $id => $renglones) {
            $recibo = $recibos[$id] ?? null;

            if (! $recibo instanceof Recibo) {
                continue;
            }

            $dinero = Monto::cero();

            foreach ($renglones as $renglon) {
                $dinero = $dinero->sumar($renglon->montoInteres())->sumar($renglon->montoCapital());
            }

            $pagos[] = ['recibo' => $recibo, 'dinero' => $dinero, 'renglones' => $renglones];
        }

        usort($pagos, static function (array $uno, array $otro): int {
            $fechaUno = $uno['recibo']->getAttribute('fecha');
            $fechaOtro = $otro['recibo']->getAttribute('fecha');

            $porFecha = strcmp(
                $fechaUno instanceof DateTimeInterface ? $fechaUno->format('Y-m-d') : '',
                $fechaOtro instanceof DateTimeInterface ? $fechaOtro->format('Y-m-d') : '',
            );

            return $porFecha !== 0 ? $porFecha : (int) $uno['recibo']->getKey() <=> (int) $otro['recibo']->getKey();
        });

        return $pagos;
    }

    /**
     * ¿Los lotes sacan recibos a nombres distintos? La cadena vacía es «el
     * dueño del expediente», que también es un nombre — el mismo criterio que
     * separa los papeles al cobrar.
     *
     * @param array<int, array{compromiso: Compromiso, codigo: string, capital: Monto, tramo: list<Cuota>, conCobroAnulado: array<int, int>}> $lotes
     */
    private function hayTitularesDistintos(array $lotes): bool
    {
        $nombres = [];

        foreach ($lotes as $lote) {
            $nombres[$lote['compromiso']->titularDelRecibo() ?? ''] = true;
        }

        return count($nombres) > 1;
    }

    /**
     * La mora que corre hoy sobre esas cuotas, con las condiciones CONGELADAS
     * del lote. La cuenta es la de siempre (`MoraDelLote`), la misma que usa
     * el cobro.
     *
     * @param iterable<Cuota> $cuotas
     */
    private function moraDe(Compromiso $lote, iterable $cuotas): Monto
    {
        $pendientes = [];

        foreach ($cuotas as $cuota) {
            if (! $cuota->saldo()->esCero()) {
                $pendientes[] = $cuota;
            }
        }

        if ($pendientes === []) {
            return Monto::cero();
        }

        return MoraDelLote::calcular(
            $pendientes,
            $lote->condicionesDeMora(),
            CarbonImmutable::parse(today()->toDateString()),
        )->total;
    }

    /**
     * La mora que corre hoy en los lotes que entraron, releyendo las cuotas
     * ya escritas.
     */
    private function moraDeHoy(SituacionDelPlan $situacion): Monto
    {
        $total = Monto::cero();

        foreach ($situacion->lotes as $lote) {
            $cuotas = Cuota::query()
                ->where('compromiso_id', $lote['compromiso']->getKey())
                ->whereColumn('monto_pagado', '<', 'monto')
                ->reorder()
                ->orderBy('numero')
                ->get();

            $total = $total->sumar($this->moraDe($lote['compromiso'], $cuotas));
        }

        return $total;
    }

    /**
     * Las condiciones como las tiene que leer el Service (18-oct-2026).
     *
     * «A fechas» devenga desde lo que dice la foto releída con candado, no
     * desde lo que trajo la pantalla (§9.D17). Y un contrato a fechas —el que
     * se pacta ahora, o el que ya lo era— se reestructura con todos sus
     * lotes: un contrato tiene un calendario solo.
     *
     * @throws ReestructuracionInvalidaException
     */
    private function normalizar(SituacionDelPlan $situacion, CondicionesDeReestructuracion $condiciones): CondicionesDeReestructuracion
    {
        if ($condiciones->esAFechas() && $situacion->afuera !== []) {
            throw ReestructuracionInvalidaException::porFechasConLotesAfuera(
                (string) $situacion->venta->getAttribute('numero_contrato'),
                implode(', ', $situacion->afuera),
            );
        }

        return $condiciones->conDevengoDesde($situacion->devengaDesde());
    }

    /**
     * Las dos fronteras del primer vencimiento, con su porqué en cada mensaje:
     * no antes de la firma, y después de la última cuota pagada entera.
     *
     * ═══ YA NO HAY FRONTERA POR EL ATRASO (26-sep-2026) ═══
     *
     * «Si se reestructura significa que la mora no va» —Mauricio—. Hasta hoy
     * el plan no podía arrancar después de una cuota vencida, para que el
     * atraso y su mora no desaparecieran de costado. Ahora desaparecen a
     * propósito: reestructurar deja la mora en cero, y el acta escribe cuánta
     * se perdonó. Lo que sí se exige es que el plan nuevo NAZCA al día
     * (`verificarQueNoNazcaAtrasado()`): sin eso, la mora perdonada volvería
     * a correr al día siguiente sobre las cuotas nuevas.
     *
     * @throws ReestructuracionInvalidaException
     */
    private function verificarElPrimerVencimiento(SituacionDelPlan $situacion, CarbonImmutable $primero): void
    {
        $firma = $situacion->venta->getAttribute('fecha_contrato');

        if ($firma instanceof DateTimeInterface && $primero->lessThan(CarbonImmutable::parse($firma->format('Y-m-d')))) {
            throw ReestructuracionInvalidaException::porEmpezarAntesDelContrato(
                $primero->format('d/m/Y'),
                $firma->format('d/m/Y'),
            );
        }

        $pagada = $situacion->ultimaPagadaVence;

        if ($pagada instanceof CarbonImmutable && ! $primero->greaterThan($pagada)) {
            throw ReestructuracionInvalidaException::porEmpezarAntesDeLoPagado(
                $primero->format('d/m/Y'),
                $pagada->format('d/m/Y'),
            );
        }
    }

    /**
     * Toda cuota del plan nuevo que ya venció tiene que quedar pagada entera
     * con lo que el cliente ya entregó.
     *
     * Es la otra mitad de «la mora no va»: si el plan nuevo arrancara con una
     * cuota vencida y sin cubrir, esa cuota correría mora desde su fecha —de
     * nuevo, y sobre un plan que el cliente recién firmó—. Así, el plan nace
     * al día y la mora arranca en cero. Como lo pagado llena las cuotas en
     * orden, alcanza con mirar la primera a la que le falta algo.
     *
     * @throws ReestructuracionInvalidaException
     */
    private function verificarQueNoNazcaAtrasado(SituacionDelPlan $situacion, PlanUnificado $plan): void
    {
        $atraso = $this->atraso($situacion, $plan);

        if ($atraso !== null) {
            throw ReestructuracionInvalidaException::porNacerAtrasado(
                $atraso['numero'],
                $atraso['vence']->format('d/m/Y'),
                $atraso['falta'],
                $situacion->primerVencimientoDesdeHoy()->format('d/m/Y'),
            );
        }
    }

    /**
     * La primera cuota del plan nuevo que ya venció y que lo ya pagado no
     * cubre entera, con cuánto le falta. Null si el plan nace al día.
     *
     * Como lo pagado llena las cuotas en orden, alcanza con mirar la primera
     * a la que le falta algo: si esa no venció, ninguna de las que siguen.
     *
     * @return array{numero: int, vence: CarbonImmutable, falta: Monto}|null
     */
    private function atraso(SituacionDelPlan $situacion, PlanUnificado $plan): ?array
    {
        $debe = $this->cobertura($situacion, $plan)->primerMesQueDebe();

        if ($debe === null) {
            return null;
        }

        // `today()` lo genera PHP, nunca Postgres (§7.5).
        $hoy = CarbonImmutable::parse(today()->toDateString());

        foreach ($plan->contrato->cuotas as $cuota) {
            if ($cuota->numero === $debe['numero'] && $cuota->vencimiento->lessThan($hoy)) {
                return ['numero' => $debe['numero'], 'vence' => $cuota->vencimiento, 'falta' => $debe['falta']];
            }
        }

        return null;
    }

    /**
     * Con lotes afuera, el día de pago no se mueve.
     *
     * El día es del CONTRATO (`ventas.dia_pago`) y lo usan los lotes que se
     * quedan con su plan —un abono a capital, por ejemplo, rearma sus cuotas
     * con ese día—. Juntando todos, el día nuevo pasa a ser el del contrato;
     * juntando solo algunos, cambiarlo movería los vencimientos de los otros
     * sin que nadie lo pida.
     *
     * @throws ReestructuracionInvalidaException
     */
    private function verificarElDiaDePago(SituacionDelPlan $situacion, CondicionesDeReestructuracion $condiciones): void
    {
        if ($situacion->afuera === []) {
            return;
        }

        $delContrato = (int) $situacion->venta->getAttribute('dia_pago');

        if ($delContrato > 0 && $condiciones->diaDePago() !== $delContrato) {
            throw ReestructuracionInvalidaException::porDiaDePagoConLotesAfuera(
                $condiciones->diaDePago(),
                $delContrato,
                implode(', ', $situacion->afuera),
            );
        }
    }

    // ─── Reubicar lo pagado ───────────────────────────────────────────

    /**
     * Dónde cae cada recibo en el plan nuevo, sin escribir nada todavía.
     *
     * Se calcula entero ANTES de tocar una fila: si un recibo no cabe, la
     * negativa sale con el contrato como estaba y el mensaje puede decir por
     * qué.
     *
     * El dinero —interés y capital— llena el plan nuevo en orden; la mora que
     * el recibo ya había cobrado NO entra en esa cuenta y se asienta aparte
     * (`dondeQuedaLaMora()`). Un renglón que solo llevaría mora perdonada no
     * puede existir (el CHECK exige dinero en el renglón), así que ese perdón
     * se junta con el primer renglón con dinero del mismo recibo.
     *
     * @return array{recibos: list<array{recibo: Recibo, antes: list<AplicacionDePago>, piezas: list<array{lote: int, numero: int, interes: Monto, capital: Monto, mora: Monto, condonada: Monto}>, desde: string}>, cobertura: CoberturaDelPlan}
     *
     * @throws ReestructuracionInvalidaException
     */
    private function reubicar(SituacionDelPlan $situacion, PlanUnificado $plan): array
    {
        $cobertura = CoberturaDelPlan::de($plan);

        // De qué lote era cada cuota vieja del tramo.
        $dondeEstaba = [];

        foreach ($situacion->lotes as $id => $lote) {
            foreach ($lote['tramo'] as $cuota) {
                $dondeEstaba[(int) $cuota->getKey()] = $id;
            }
        }

        // Primera pasada: el dinero —interés y capital—, recibo por recibo.
        $movidos = [];

        foreach ($situacion->pagos as $pago) {
            $movidos[] = ['pago' => $pago, 'dePlata' => $this->ponerElPago($cobertura, $situacion, $plan, $pago)];
        }

        // Segunda pasada: la mora de cada recibo, ya sabiendo qué cuotas
        // quedaron pagadas enteras.
        $recibos = [];

        foreach ($movidos as ['pago' => $pago, 'dePlata' => $dePlata]) {
            $piezas = [];

            foreach ($dePlata as $pieza) {
                $piezas[$pieza['lote'].'-'.$pieza['numero']] = [...$pieza, 'mora' => Monto::cero(), 'condonada' => Monto::cero()];
            }

            $desde = null;

            foreach ($pago['renglones'] as $renglon) {
                $creado = $renglon->getAttribute('created_at');

                if ($creado instanceof DateTimeInterface && ($desde === null || $creado->format('Y-m-d H:i:s') < $desde)) {
                    $desde = $creado->format('Y-m-d H:i:s');
                }

                $mora = $renglon->montoMora();
                $condonada = $renglon->moraCondonada();
                $lote = $dondeEstaba[(int) $renglon->getAttribute('cuota_id')] ?? null;

                if ($lote === null) {
                    continue;
                }

                if ($mora->esCero() && $condonada->esCero()) {
                    continue;
                }

                $casa = $this->dondeQuedaLaMora($cobertura, $lote, $dePlata, $situacion->primerNumero());
                $clave = $casa['lote'].'-'.$casa['numero'];

                $piezas[$clave] ??= [
                    'lote'      => $casa['lote'],
                    'numero'    => $casa['numero'],
                    'interes'   => Monto::cero(),
                    'capital'   => Monto::cero(),
                    'mora'      => Monto::cero(),
                    'condonada' => Monto::cero(),
                ];

                $piezas[$clave]['mora'] = $piezas[$clave]['mora']->sumar($mora);
                $piezas[$clave]['condonada'] = $piezas[$clave]['condonada']->sumar($condonada);
            }

            $recibos[] = [
                'recibo' => $pago['recibo'],
                'antes'  => $pago['renglones'],
                'piezas' => $this->sinPerdonSuelto(array_values($piezas)),
                'desde'  => $desde ?? now()->format('Y-m-d H:i:s'),
            ];
        }

        return ['recibos' => $recibos, 'cobertura' => $cobertura];
    }

    /**
     * Un recibo puesto sobre el plan nuevo, y a dónde fue cada lempira.
     *
     * Unificados, su dinero llena el plan del contrato en orden. Con cada
     * lote por su lado (25-oct-2026), lo que pagó de cada lote llena el plan
     * de ESE lote: se separa por el lote de la cuota que pagaba
     * (`SituacionDelPlan::dineroPorLote()`).
     *
     * Lo que no cabe se niega: no hay a dónde mandarlo sin inventar un abono.
     *
     * @param array{recibo: Recibo, dinero: Monto, renglones: list<AplicacionDePago>} $pago
     *
     * @return list<array{lote: int, numero: int, interes: Monto, capital: Monto}>
     *
     * @throws ReestructuracionInvalidaException
     */
    private function ponerElPago(CoberturaDelPlan $cobertura, SituacionDelPlan $situacion, PlanUnificado $plan, array $pago): array
    {
        if (! $plan->cadaLote) {
            $resultado = $cobertura->aplicar($pago['dinero']);

            if (! $resultado['sobra']->esCero()) {
                throw ReestructuracionInvalidaException::porPagadoMayorQueElPlan($situacion->yaPagado(), $cobertura->total());
            }

            return $resultado['piezas'];
        }

        $piezas = [];

        foreach ($situacion->dineroPorLote($pago) as $lote => $dinero) {
            $resultado = $cobertura->aplicarAlLote($lote, $dinero);

            if (! $resultado['sobra']->esCero()) {
                throw ReestructuracionInvalidaException::porPagadoMayorQueElPlan($situacion->yaPagadoDe($lote), $cobertura->totalDelLote($lote));
            }

            array_push($piezas, ...$resultado['piezas']);
        }

        return $piezas;
    }

    /**
     * En qué cuota nueva se asienta la mora que un recibo ya cobró.
     *
     * ═══ «ES GANANCIA PURA» (26-sep-2026) ═══
     *
     * Mauricio: la mora que el cliente pagó no entra en la cuenta del plan
     * nuevo, «ya que pagó extra de capital e interés y es ganancia pura, no
     * que él ha dado de abono al capital». Por eso lo ya pagado que se
     * reubica es solo interés y capital, y la mora sigue siendo mora en su
     * recibo.
     *
     * Pero el renglón tiene que colgar de alguna cuota —el recibo tiene que
     * seguir cuadrando— y la mora pagada de una cuota le descuenta a la mora
     * que esa misma cuota genere después (`MoraDelLote`). Colgarla de una
     * cuota por pagar sería regalarle al cliente ese descuento sobre un
     * atraso futuro. Así que va a la última cuota PAGADA ENTERA del mismo
     * lote, donde ya no se calcula mora; si el lote no tiene ninguna, a la de
     * otro lote; y solo si no hay ninguna pagada entera, a la primera que
     * tocó el dinero del mismo recibo.
     *
     * @param list<array{lote: int, numero: int, interes: Monto, capital: Monto}> $dePlata
     *
     * @return array{lote: int, numero: int}
     */
    private function dondeQuedaLaMora(CoberturaDelPlan $cobertura, int $lote, array $dePlata, int $desde): array
    {
        $cubierta = $cobertura->ultimaCubierta($lote) ?? $cobertura->ultimaCubierta();

        if ($cubierta !== null) {
            return $cubierta;
        }

        $primera = $dePlata[0] ?? null;

        return $primera === null
            ? ['lote' => $lote, 'numero' => $desde]
            : ['lote' => $primera['lote'], 'numero' => $primera['numero']];
    }

    /**
     * El perdón que quedó en un renglón sin dinero pasa al primer renglón con
     * dinero del mismo recibo. Ver `reubicar()`.
     *
     * @param list<array{lote: int, numero: int, interes: Monto, capital: Monto, mora: Monto, condonada: Monto}> $piezas
     *
     * @return list<array{lote: int, numero: int, interes: Monto, capital: Monto, mora: Monto, condonada: Monto}>
     */
    private function sinPerdonSuelto(array $piezas): array
    {
        $suelto = Monto::cero();
        $conDinero = [];

        foreach ($piezas as $pieza) {
            $monto = $pieza['interes']->sumar($pieza['capital'])->sumar($pieza['mora']);

            if ($monto->esCero()) {
                $suelto = $suelto->sumar($pieza['condonada']);

                continue;
            }

            $conDinero[] = $pieza;
        }

        if (! $suelto->esCero() && $conDinero !== []) {
            $conDinero[0]['condonada'] = $conDinero[0]['condonada']->sumar($suelto);
        }

        return $conDinero;
    }

    // ─── Escribir ─────────────────────────────────────────────────────

    /**
     * @param array{recibos: list<array{recibo: Recibo, antes: list<AplicacionDePago>, piezas: list<array{lote: int, numero: int, interes: Monto, capital: Monto, mora: Monto, condonada: Monto}>, desde: string}>, cobertura: CoberturaDelPlan} $reubicacion
     */
    private function emitirElActa(
        Venta $venta,
        SituacionDelPlan $situacion,
        CondicionesDeReestructuracion $condiciones,
        PlanUnificado $plan,
        array $reubicacion,
        string $motivo,
        string $tipo,
        ?string $moraQuitada,
    ): Reestructuracion {
        $contrato = $plan->contrato;
        $ultima = $contrato->ultima();
        $cobertura = $reubicacion['cobertura'];

        /*
         * El número se toma con la venta bloqueada: dos reestructuraciones del
         * mismo contrato al mismo tiempo se esperan, y el índice único
         * `(venta_id, numero)` es el cinturón.
         */
        $numero = (int) Reestructuracion::query()->where('venta_id', $venta->getKey())->max('numero') + 1;

        $renglones = [];

        foreach ($contrato->cuotas as $cuota) {
            $renglones[] = [
                'numero'   => $cuota->numero,
                'vence'    => $cuota->vencimientoParaBase(),
                'monto'    => $cuota->montoParaBase(),
                'capital'  => $cuota->capitalParaBase(),
                'interes'  => $cuota->interesParaBase(),
                'cubierto' => $cobertura->cubiertoDelMes($cuota->numero)->redondeado(),
            ];
        }

        return Reestructuracion::query()->create([
            'venta_id' => $venta->getKey(),
            'numero'   => $numero,
            // Reestructuración o renegociación (18-oct-2026): el CHECK no deja que una cuadre con lo que solo se renegocia.
            'tipo'          => $tipo,
            'quita_la_mora' => $moraQuitada !== null,
            'mora_anterior' => $moraQuitada,
            // La fecha de negocio la pone PHP, nunca Postgres (§7.5).
            'fecha'              => today()->toDateString(),
            'motivo'             => $motivo,
            'fijado'             => $condiciones->fijado(),
            'interes_incluido'   => ! $condiciones->interesEncima(),
            'tasa_anterior'      => $situacion->tasaAnterior()?->paraBase(),
            'tasa_nueva'         => $condiciones->tasa->paraBase(),
            'capital'            => $situacion->capitalTotal()->redondeado(),
            'descuento'          => $plan->descuentoTotal()->redondeado(),
            'cuota_anterior'     => $situacion->cuotaDeHoy()?->redondeado(),
            'cuota_nueva'        => $contrato->cuotaMensual()?->redondeado() ?? '0.00',
            'meses_antes'        => $situacion->mesesQueFaltan(),
            'meses_despues'      => $contrato->count(),
            'interes_antes'      => $situacion->interesDelTramo()->redondeado(),
            'interes_despues'    => $contrato->totalInteres()->redondeado(),
            'termina_antes'      => $situacion->terminaEl()?->toDateString(),
            'termina_despues'    => $ultima?->vencimientoParaBase(),
            'primer_vencimiento' => $condiciones->primerVencimiento->toDateString(),
            'dia_pago_anterior'  => (int) $venta->getAttribute('dia_pago'),
            'dia_pago_nuevo'     => $condiciones->diaDePago(),
            'lotes'              => count($situacion->lotes),
            'plan_nuevo'         => $renglones,
            'ya_pagado'          => $situacion->yaPagado()->redondeado(),
            'mora_antes'         => $situacion->moraCorrida->redondeado(),
            'mora_despues'       => '0.00',
            'pagos_reubicados'   => $this->paraElActa($situacion, $reubicacion['recibos']),
            // Unificados o cada uno con su cuota (25-oct-2026), y cómo quedó cada lote.
            'agrupacion'    => $plan->cadaLote ? Reestructuracion::CADA_LOTE : Reestructuracion::UNIFICADOS,
            'lotes_detalle' => $this->comoQuedaCadaLote($situacion, $plan),
        ]);
    }

    /**
     * Cada lote del plan nuevo, como lo imprime el acta: capital, cuántas
     * cuotas, de cuánto la primera y la última, y de cuándo a cuándo. Con los
     * códigos escritos, igual que `paraElActa()`.
     *
     * @return list<array{codigo: string, capital: string, cuotas: int, cuota: string, ultima: string, desde: int, primera: string, termina: string}>
     */
    private function comoQuedaCadaLote(SituacionDelPlan $situacion, PlanUnificado $plan): array
    {
        $filas = [];

        foreach ($situacion->lotes as $id => $lote) {
            $nuevas = $plan->porLote[$id] ?? [];
            $primera = $nuevas[0] ?? null;
            $ultima = $nuevas === [] ? null : $nuevas[count($nuevas) - 1];

            $filas[] = [
                'codigo'  => $lote['codigo'],
                'capital' => $lote['capital']->restar($plan->descuentos[$id] ?? Monto::cero())->redondeado(),
                'cuotas'  => count($nuevas),
                'cuota'   => $primera?->montoParaBase() ?? '0.00',
                'ultima'  => $ultima?->montoParaBase() ?? '0.00',
                'desde'   => $situacion->primerNumero(),
                'primera' => $primera?->vencimientoParaBase() ?? '',
                'termina' => $ultima?->vencimientoParaBase() ?? '',
            ];
        }

        return $filas;
    }

    /**
     * Cada recibo que se movió, con dónde estaba y dónde quedó, como se lee
     * en el acta. Con los códigos de lote escritos: el acta se imprime igual
     * dentro de un año aunque el plan vuelva a cambiar.
     *
     * @param list<array{recibo: Recibo, antes: list<AplicacionDePago>, piezas: list<array{lote: int, numero: int, interes: Monto, capital: Monto, mora: Monto, condonada: Monto}>, desde: string}> $recibos
     *
     * @return list<array{recibo_id: int, folio: string, fecha: string, dinero: string, mora: string, antes: list<array{lote: string, numero: int, monto: string, mora: string}>, despues: list<array{lote: string, numero: int, monto: string, mora: string}>}>
     */
    private function paraElActa(SituacionDelPlan $situacion, array $recibos): array
    {
        $codigos = [];
        $dondeEstaba = [];

        foreach ($situacion->lotes as $id => $lote) {
            $codigos[$id] = $lote['codigo'];

            foreach ($lote['tramo'] as $cuota) {
                $dondeEstaba[(int) $cuota->getKey()] = ['lote' => $lote['codigo'], 'numero' => (int) $cuota->getAttribute('numero')];
            }
        }

        $filas = [];

        foreach ($recibos as $movido) {
            $dinero = Monto::cero();
            $mora = Monto::cero();
            $antes = [];

            foreach ($movido['antes'] as $renglon) {
                $origen = $dondeEstaba[(int) $renglon->getAttribute('cuota_id')] ?? ['lote' => '', 'numero' => 0];
                $aLaCuota = $renglon->montoInteres()->sumar($renglon->montoCapital());

                $dinero = $dinero->sumar($aLaCuota);
                $mora = $mora->sumar($renglon->montoMora());

                $antes[] = [
                    'lote'   => $origen['lote'],
                    'numero' => $origen['numero'],
                    'monto'  => $aLaCuota->redondeado(),
                    'mora'   => $renglon->montoMora()->redondeado(),
                ];
            }

            $despues = [];

            foreach ($movido['piezas'] as $pieza) {
                $despues[] = [
                    'lote'   => $codigos[$pieza['lote']] ?? '',
                    'numero' => $pieza['numero'],
                    'monto'  => $pieza['interes']->sumar($pieza['capital'])->redondeado(),
                    'mora'   => $pieza['mora']->redondeado(),
                ];
            }

            $fecha = $movido['recibo']->getAttribute('fecha');

            $filas[] = [
                'recibo_id' => (int) $movido['recibo']->getKey(),
                'folio'     => $movido['recibo']->folio(),
                'fecha'     => $fecha instanceof DateTimeInterface ? $fecha->format('Y-m-d') : '',
                'dinero'    => $dinero->redondeado(),
                'mora'      => $mora->redondeado(),
                'antes'     => $antes,
                'despues'   => $despues,
            ];
        }

        return $filas;
    }

    /**
     * Borra los renglones de los recibos vivos sobre el tramo. Se vuelven a
     * escribir, recibo por recibo, en `asentarLosPagos()`; los de recibos
     * anulados no se tocan.
     *
     * Va antes de borrar cuotas: `aplicaciones_de_pago.cuota_id` es
     * `restrictOnDelete`, y es la red que tiene que seguir funcionando.
     */
    private function soltarLosRenglones(SituacionDelPlan $situacion): void
    {
        $ids = [];

        foreach ($situacion->pagos as $pago) {
            foreach ($pago['renglones'] as $renglon) {
                $ids[] = (int) $renglon->getKey();
            }
        }

        if ($ids !== []) {
            AplicacionDePago::query()->whereKey($ids)->delete();
        }
    }

    /**
     * Borra las cuotas del tramo del lote y escribe las nuevas, en cero.
     *
     * ═══ POR QUE NO SE BORRAN TODAS ═══
     *
     * Una cuota que tuvo un cobro después anulado conserva el renglón de ese
     * recibo —el papel anulado sigue existiendo, y
     * `aplicaciones_de_pago.cuota_id` es `restrictOnDelete`—. Esa fila no se
     * borra: se REESCRIBE en su lugar con la cuota nueva del mismo número, y
     * vuelve a nacer en cero. Las demás se borran y se insertan de nuevo, como
     * hace el abono (R21). Lo pagado se pone después, recibo por recibo.
     *
     * @param array{compromiso: Compromiso, codigo: string, capital: Monto, tramo: list<Cuota>, conCobroAnulado: array<int, int>} $lote
     * @param list<CuotaProyectada> $nuevas
     */
    private function reescribir(Venta $venta, array $lote, array $nuevas): void
    {
        $sinRenglon = [];

        foreach ($lote['tramo'] as $cuota) {
            if (! in_array((int) $cuota->getKey(), $lote['conCobroAnulado'], true)) {
                $sinRenglon[] = (int) $cuota->getKey();
            }
        }

        if ($sinRenglon !== []) {
            Cuota::query()->whereKey($sinRenglon)->delete();
        }

        $ahora = now();
        $filas = [];

        foreach ($nuevas as $cuota) {
            $datos = [
                'fecha_vencimiento' => $cuota->vencimientoParaBase(),
                'monto'             => $cuota->montoParaBase(),
                'monto_capital'     => $cuota->capitalParaBase(),
                'monto_interes'     => $cuota->interesParaBase(),
                'monto_pagado'      => '0.00',
                'mora_pagada'       => '0.00',
                'mora_condonada'    => '0.00',
                'updated_at'        => $ahora,
            ];

            $enSuLugar = $lote['conCobroAnulado'][$cuota->numero] ?? null;

            if ($enSuLugar !== null) {
                Cuota::query()->whereKey($enSuLugar)->update([...$datos, 'capital_condonado' => '0.00']);

                continue;
            }

            $filas[] = [
                ...$datos,
                'venta_id'      => $venta->getKey(),
                'compromiso_id' => $lote['compromiso']->getKey(),
                'numero'        => $cuota->numero,
                'created_at'    => $ahora,
            ];
        }

        if ($filas !== []) {
            Cuota::query()->insert($filas);
        }
    }

    /**
     * Los recibos vuelven a pagar, ahora cuotas del plan nuevo.
     *
     * Un renglón por recibo y por cuota, con el mismo desglose que escribe un
     * cobro —mora, interés, capital— y con la fecha de los renglones que
     * reemplaza: el renglón dice cuándo entró ese dinero, no cuándo se movió.
     *
     * 🔴 Antes de terminar se verifica, recibo por recibo, que lo escrito sume
     * EXACTAMENTE lo que se borró. Es la invariante de `Recibo::cuadra()`
     * mirada desde adentro: si no da, la transacción se cae entera.
     *
     * @param list<array{recibo: Recibo, antes: list<AplicacionDePago>, piezas: list<array{lote: int, numero: int, interes: Monto, capital: Monto, mora: Monto, condonada: Monto}>, desde: string}> $recibos
     */
    private function asentarLosPagos(SituacionDelPlan $situacion, array $recibos): void
    {
        if ($recibos === []) {
            return;
        }

        $cuotas = [];

        foreach (Cuota::query()
            ->whereIn('compromiso_id', array_keys($situacion->lotes))
            ->where('numero', '>=', $situacion->primerNumero())
            ->get() as $cuota) {
            $cuotas[(int) $cuota->getAttribute('compromiso_id')][(int) $cuota->getAttribute('numero')] = $cuota;
        }

        $ahora = now();
        $filas = [];
        $acumulado = [];

        foreach ($recibos as $movido) {
            $antes = Monto::cero();

            foreach ($movido['antes'] as $renglon) {
                $antes = $antes->sumar($renglon->montoAplicado());
            }

            $despues = Monto::cero();

            foreach ($movido['piezas'] as $pieza) {
                $cuota = $cuotas[$pieza['lote']][$pieza['numero']] ?? null;

                if (! $cuota instanceof Cuota) {
                    throw new LogicException("La cuota {$pieza['numero']} del plan nuevo no quedó escrita.");
                }

                $aLaCuota = $pieza['interes']->sumar($pieza['capital']);
                $monto = $aLaCuota->sumar($pieza['mora']);
                $clave = (int) $cuota->getKey();

                $filas[] = [
                    'recibo_id'         => $movido['recibo']->getKey(),
                    'cuota_id'          => $clave,
                    'monto'             => $monto->redondeado(),
                    'monto_mora'        => $pieza['mora']->redondeado(),
                    'monto_interes'     => $pieza['interes']->redondeado(),
                    'monto_capital'     => $pieza['capital']->redondeado(),
                    'mora_condonada'    => $pieza['condonada']->redondeado(),
                    'capital_condonado' => '0.00',
                    'created_at'        => $movido['desde'],
                    'updated_at'        => $ahora,
                ];

                $previo = $acumulado[$clave] ?? ['pagado' => Monto::cero(), 'mora' => Monto::cero(), 'condonada' => Monto::cero()];

                $acumulado[$clave] = [
                    'pagado'    => $previo['pagado']->sumar($aLaCuota),
                    'mora'      => $previo['mora']->sumar($pieza['mora']),
                    'condonada' => $previo['condonada']->sumar($pieza['condonada']),
                ];

                $despues = $despues->sumar($monto);
            }

            if (! $despues->igualA($antes)) {
                throw new LogicException(sprintf(
                    'El recibo %s aplicaba %s y reubicado aplicaría %s.',
                    $movido['recibo']->folio(),
                    $antes->redondeado(),
                    $despues->redondeado(),
                ));
            }
        }

        AplicacionDePago::query()->insert($filas);

        foreach ($acumulado as $id => $pagado) {
            Cuota::query()->whereKey($id)->update([
                'monto_pagado'   => $pagado['pagado']->redondeado(),
                'mora_pagada'    => $pagado['mora']->redondeado(),
                'mora_condonada' => $pagado['condonada']->redondeado(),
                'updated_at'     => $ahora,
            ]);
        }

        foreach ($recibos as $movido) {
            $this->elLoteDelPapel($movido['recibo']);
        }
    }

    /**
     * El lote del que habla un recibo de UN solo lote, al día.
     *
     * Un recibo cobrado a un lote puede terminar pagando la cuota del otro:
     * al juntarlos, lo pagado se pone en el orden del plan nuevo. Si ahora
     * toca más de un lote, queda como queda un cobro de varios —sin
     * `compromiso_id`, y los lotes salen de sus renglones—; si toca uno solo,
     * es ese. El número, la fecha, el monto y el nombre del papel no cambian.
     */
    private function elLoteDelPapel(Recibo $recibo): void
    {
        $actual = $recibo->getAttribute('compromiso_id');

        if ($actual === null) {
            return;
        }

        $lotes = Cuota::query()
            ->whereIn('id', AplicacionDePago::query()->select('cuota_id')->where('recibo_id', $recibo->getKey()))
            ->distinct()
            ->pluck('compromiso_id')
            ->merge(Reprogramacion::query()->where('recibo_id', $recibo->getKey())->pluck('compromiso_id'))
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->unique()
            ->values()
            ->all();

        $nuevo = count($lotes) === 1 ? $lotes[0] : null;

        if ($nuevo === (int) $actual) {
            return;
        }

        // `recibos_cuelgan_de_un_compromiso_chk`: sin lote, cuelga de la venta.
        if ($nuevo === null && $recibo->getAttribute('venta_id') === null) {
            return;
        }

        Recibo::query()->whereKey($recibo->getKey())->update(['compromiso_id' => $nuevo]);
    }

    /**
     * La tasa del lote pasa a ser la del plan nuevo, si cambió.
     *
     * ═══ UNA REBAJA LLEVA SU MOTIVO, TAMBIÉN ACÁ ═══
     *
     * `compromisos_tasa_rebajada_con_motivo_chk` no deja que la tasa pactada
     * quede por debajo de la de lista sin un motivo escrito (R4): bajar la
     * tasa es regalar plata, igual que bajar el precio. Si el lote no traía
     * uno, el motivo es esta reestructuración, con su acta. Si ya traía uno
     * —la rebaja que se dio al firmar— se deja: esa historia no se pisa, y la
     * tasa de antes queda en el acta.
     */
    private function pactarLaTasa(Compromiso $lote, TasaDeInteres $tasa, Reestructuracion $acta, string $motivo): void
    {
        if ($lote->tasaDeInteres()->igualA($tasa)) {
            return;
        }

        $cambios = ['tasa_interes_anual' => $tasa->paraBase()];
        $previo = $lote->getAttribute('motivo_tasa');

        if ($tasa->menorQue($lote->tasaDeLista()) && (! is_string($previo) || trim($previo) === '')) {
            $cambios['motivo_tasa'] = sprintf(
                '%s, acta %s: %s',
                $acta->esRenegociacion() ? 'Renegociación del contrato' : 'Reestructuración del plan',
                $acta->folio(),
                $motivo,
            );
        }

        $lote->update($cambios);
    }

    /**
     * La constancia del lote: su plan viejo, para contestar «¿por qué cambió
     * mi cuota?» desde la pestaña Reprogramaciones.
     *
     * Sin recibo y sin abono —no entró plata—, y apuntando al acta. El plan
     * viejo va con sus partes, capital e interés: una reestructuración puede
     * cambiar la tasa, y sin las partes no se podría explicar el antes. Lo
     * que ya estaba pagado de él lo dice el acta, recibo por recibo.
     *
     * @param array{compromiso: Compromiso, codigo: string, capital: Monto, tramo: list<Cuota>, conCobroAnulado: array<int, int>} $lote
     * @param list<CuotaProyectada> $nuevas
     */
    private function asentarLaConstancia(Venta $venta, Reestructuracion $acta, array $lote, array $nuevas, int $desde, string $motivo, Monto $descuento): void
    {
        $viejo = [];

        foreach ($lote['tramo'] as $cuota) {
            $viejo[] = [
                'numero'  => (int) $cuota->getAttribute('numero'),
                'vence'   => SituacionDelPlan::vence($cuota)?->toDateString() ?? '',
                'monto'   => $cuota->montoTotal()->redondeado(),
                'capital' => $cuota->montoCapital()->redondeado(),
                'interes' => $cuota->montoInteres()->redondeado(),
            ];
        }

        $primeraVieja = $lote['tramo'][0] ?? null;
        $primeraNueva = $nuevas[0] ?? null;

        Reprogramacion::query()->create([
            'venta_id'            => $venta->getKey(),
            'compromiso_id'       => $lote['compromiso']->getKey(),
            'recibo_id'           => null,
            'reestructuracion_id' => $acta->getKey(),
            'modalidad'           => null,
            'motivo'              => $motivo,
            'abono_capital'       => '0.00',
            // Lo perdonado a este lote baja su saldo (18-oct-2026); el CHECK lo exige.
            'descuento'      => $descuento->redondeado(),
            'saldo_anterior' => $lote['capital']->redondeado(),
            'saldo_nuevo'    => $lote['capital']->restar($descuento)->redondeado(),
            'cuota_anterior' => $primeraVieja?->montoTotal()->redondeado(),
            'cuota_nueva'    => $primeraNueva?->montoParaBase(),
            'cuotas_antes'   => count($lote['tramo']),
            'cuotas_despues' => count($nuevas),
            'desde_numero'   => $desde,
            'plan_anterior'  => $viejo,
        ]);
    }
}
