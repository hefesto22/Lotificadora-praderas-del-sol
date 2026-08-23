<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Facturacion\ConsumoDeFacturas;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CondicionesDeMora;
use App\Domain\Ventas\PlanDeCuotas;
use App\Domain\Ventas\TasaDeInteres;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El dinero que entra, aplicado a las cuotas que lo esperan.
 *
 * ═══ FIFO, Y POR LOTE ═══
 *
 * Un pago se aplica a las cuotas pendientes MAS VIEJAS primero. No es una
 * preferencia: es lo que el cliente entiende cuando dice «vengo a pagar», y es
 * lo que hace que el atraso se vaya achicando en vez de dejar huecos en el
 * medio del plan.
 *
 * Y va contra UN lote. Desde el 5-ago-2026 cada lote del contrato tiene su
 * propio plazo y su propio plan (R21/R22), así que «pagar la cuota» sin decir
 * de cuál lote no significa nada.
 *
 * ═══ MORA → INTERES → CAPITAL, DESDE EL 8-AGO-2026 ═══
 *
 * Cuando la lotificadora cobra interes o mora (§8.5, configurable por plan de
 * pago), el orden en que se imputa un pago deja de ser un detalle: **es la
 * diferencia entre que un cliente salga de la deuda o no salga nunca**. El
 * estandar, y lo que hace este Service, es:
 *
 *  1. La MORA de la cuota mas vieja, calculada al vuelo al dia del cobro.
 *  2. El INTERES pendiente de esa cuota.
 *  3. El CAPITAL, que es lo unico que baja la deuda.
 *
 * Y recien entonces la cuota siguiente.
 *
 * Con Praderas del Sol —tasa 0 y sin mora (R1, R2)— los pasos 1 y 2 valen
 * cero en todas las cuotas y el reparto es identico al que corria antes: todo
 * a capital, FIFO. **No hay dos motores.**
 *
 * ═══ LA MORA NO ES UNA CUOTA ═══
 *
 * No se guarda como fila de `cuotas`: se calcula al cobrar (`CalculoDeMora`)
 * y se congela en el recibo, que dice cuanta se cobro y por que. Lo unico que
 * `cuotas` acumula es `mora_pagada` y `mora_condonada`, para no volver a
 * cobrar la de los mismos dias.
 *
 * ═══ UNA CUOTA SE PAGA EN VARIAS VECES (R19) ═══
 *
 * No hay nada especial que hacer: el monto se reparte hasta agotarse y la
 * última cuota tocada queda parcial. Adentro de cada cuota el orden es el de
 * arriba, asi que un pago parcial cubre interes antes que capital.
 *
 * ═══ Y EL ABONO A CAPITAL, QUE ES OTRA COSA (R21) ═══
 *
 * `cobrarCuotas()` reparte y no toca el plan. `abonarACapital()` primero pone
 * al día —lo vencido Y su mora— y después REESCRIBE las cuotas que nadie tocó
 * todavía, en una de dos formas que elige el cliente. Los dos emiten un recibo
 * y los dos son todo o nada; lo que cambia es qué pasa con el contrato después.
 *
 * ═══ TODO O NADA ═══
 *
 * El correlativo del recibo (R12) se consume con bloqueo de fila DENTRO de la
 * transacción: dos receptores cobrando al mismo tiempo desde lugares distintos
 * no pueden sacar el mismo número. Y las cuotas se releen con `FOR UPDATE`:
 * lo que decía la pantalla no vale, igual que en RegistroDeVentas.
 */
final readonly class RegistroDePagos
{
    public function __construct(
        private ConsumoDeCorrelativos $correlativos,
        private ConsumoDeFacturas $facturas,
    ) {}

    /**
     * Cobrar cuotas de un lote.
     *
     * El caso de un solo renglón de `cobrarVariosLotes()`, que es donde vive
     * la lógica. Sigue existiendo porque «cobrarle a un lote» es una frase que
     * el negocio dice todos los días y porque es lo que llama medio sistema.
     *
     * @throws PagoInvalidoException
     */
    public function cobrarCuotas(
        Venta $venta,
        Compromiso $lote,
        Cliente $cliente,
        Monto $monto,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
        bool $condonarMora = false,
        ?string $motivoCondonacion = null,
    ): Recibo {
        return $this->unSoloRecibo($this->cobrarVariosLotes(
            venta: $venta,
            cliente: $cliente,
            renglones: [['lote' => $lote, 'monto' => $monto]],
            forma: $forma,
            referencia: $referencia,
            fecha: $fecha,
            observaciones: $observaciones,
            condonarMora: $condonarMora,
            motivoCondonacion: $motivoCondonacion,
        ));
    }

    /**
     * Cobrar cuotas de VARIOS lotes del mismo contrato, en un solo recibo.
     *
     * ═══ POR QUE UN SOLO PAPEL ═══
     *
     * Un contrato de tres lotes tiene tres planes. Hasta hoy, pagar el mes de
     * los tres eran tres trámites y tres papeles, para un cliente que entregó
     * un solo billete. El dinero se aplica igual que siempre —lote por lote,
     * FIFO adentro de cada uno—; lo que cambia es que el documento es uno, con
     * el desglose adentro. No hizo falta migrar nada: `aplicaciones_de_pago`
     * cuelga de la CUOTA, no del lote, así que la base ya lo permitía.
     *
     * `compromiso_id` se sigue llenando cuando el cobro es de un solo lote. En
     * la enorme mayoría de los recibos la columna dice lo mismo que antes y
     * las pantallas que la leen no cambian. Con dos o más queda en NULL, que
     * es la verdad —este recibo no es de un lote— y es lo que el CHECK
     * `recibos_cuelgan_de_un_compromiso_chk` ya contemplaba: cuelga de la
     * venta (R13).
     *
     * ═══ EL ORDEN DEL BLOQUEO NO ES CASUAL ═══
     *
     * Los renglones se ordenan por id ANTES de bloquear. Dos receptores
     * cobrando los mismos dos lotes en orden distinto se traban el uno al otro
     * —el deadlock clásico de dos transacciones que toman los mismos candados
     * al revés—. Con un orden único para todo el sistema, el segundo espera y
     * sigue.
     *
     * ═══ CONDONAR LA MORA ES UN TRAMITE, NO UN CAMPO EN CERO ═══
     *
     * Perdonar la mora pasa todas las semanas en ventanilla, y por eso tiene
     * motivo obligatorio y queda escrito en el recibo con el nombre de quien
     * lo autorizo — como el descuento de R4 y la anulacion. Condona la mora de
     * TODOS los lotes de ese cobro: quien perdona esta perdonando el atraso de
     * ese cliente ese dia, no el de un renglon.
     *
     * ⚠️ Condonar no congela el reloj. Si la cuota sigue vencida, los dias que
     * pasen despues vuelven a generar mora — que es lo correcto, porque el
     * atraso siguio.
     *
     * @param list<array{lote: Compromiso, monto: Monto}> $renglones
     *
     * @return list<Recibo> un papel por cada titular de recibo
     *
     * @throws PagoInvalidoException
     */
    public function cobrarVariosLotes(
        Venta $venta,
        Cliente $cliente,
        array $renglones,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
        bool $condonarMora = false,
        ?string $motivoCondonacion = null,
    ): array {
        return $this->porCadaNombre(
            $renglones,
            fn (array $suyos): Recibo => $this->cobrarLosDeUnMismoNombre(
                $venta,
                $cliente,
                $suyos,
                $forma,
                $referencia,
                $fecha,
                $observaciones,
                $condonarMora,
                $motivoCondonacion,
            ),
        );
    }

    /**
     * El cobro de los lotes que comparten titular de recibo: UN solo papel.
     *
     * Es el cuerpo de siempre. Lo unico que cambio el 13-ago-2026 es quien lo
     * llama: antes era la puerta publica y ahora `cobrarVariosLotes()` lo
     * invoca una vez por cada nombre.
     *
     * @param list<array{lote: Compromiso, monto: Monto}> $renglones
     *
     * @throws PagoInvalidoException
     */
    private function cobrarLosDeUnMismoNombre(
        Venta $venta,
        Cliente $cliente,
        array $renglones,
        FormaDePago $forma,
        ?string $referencia,
        ?CarbonImmutable $fecha,
        ?string $observaciones,
        bool $condonarMora,
        ?string $motivoCondonacion,
    ): Recibo {
        if ($renglones === []) {
            throw PagoInvalidoException::porNoElegirNingunLote();
        }

        $vistos = [];

        foreach ($renglones as $renglon) {
            $this->verificar($venta, $renglon['lote'], $renglon['monto'], $forma, $referencia);

            $id = (int) $renglon['lote']->getKey();

            if (in_array($id, $vistos, true)) {
                throw PagoInvalidoException::porLoteRepetido($this->codigo($renglon['lote']));
            }

            $vistos[] = $id;
        }

        $cuandoSePago = $fecha ?? CarbonImmutable::parse(today()->toDateString());
        $this->verificarLaFecha($venta, $cuandoSePago);

        $porQueSePerdona = trim($motivoCondonacion ?? '');

        if ($condonarMora && $porQueSePerdona === '') {
            throw PagoInvalidoException::porFaltarElMotivoDeLaCondonacion();
        }

        // El orden del bloqueo, igual para todos. Ver el docblock.
        usort(
            $renglones,
            static fn (array $uno, array $otro): int => (int) $uno['lote']->getKey() <=> (int) $otro['lote']->getKey(),
        );

        $cuando = $cuandoSePago;
        $limpia = trim($referencia ?? '');

        return DB::transaction(function () use (
            $venta,
            $cliente,
            $renglones,
            $forma,
            $limpia,
            $cuando,
            $observaciones,
            $condonarMora,
            $porQueSePerdona
        ): Recibo {
            $total = Monto::cero();
            $moraCobrada = Monto::cero();
            $moraPerdonada = Monto::cero();
            $tandas = [];

            /*
             * 1 y 2. Las cuotas de cada lote, bloqueadas y en orden, y lo que
             * cada uno debe recién leído. La pantalla puede estar vieja: entre
             * que se pintó el modal y se apretó Guardar, el otro receptor pudo
             * cobrar el mismo lote.
             *
             * La mora se calcula ACA, con las cuotas ya bloqueadas y a la
             * fecha del cobro: la del modal era un estimado, igual que el
             * reparto FIFO que muestra la pantalla.
             */
            foreach ($renglones as $renglon) {
                $lote = $renglon['lote'];
                $monto = $renglon['monto'];

                $pendientes = $this->pendientesBloqueadas($lote);
                $saldo = $this->saldoDe($pendientes);

                $mora = MoraDelLote::calcular($pendientes, $this->condicionesDe($lote), $cuando);

                /*
                 * Lo maximo que se le puede cobrar a este lote hoy: lo que
                 * deben las cuotas MAS la mora corrida. Sin el segundo
                 * sumando, cobrar la cuota con su mora se rechazaria por
                 * «paga de mas». Si se va a condonar, la mora no se cobra y
                 * el tope vuelve a ser el saldo pelado.
                 */
                $tope = $condonarMora ? $saldo : $saldo->sumar($mora->total);

                if ($monto->mayorQue($tope)) {
                    throw PagoInvalidoException::porPagarDeMas($monto, $tope, $this->codigo($lote));
                }

                $tandas[] = ['pendientes' => $pendientes, 'monto' => $monto, 'mora' => $mora];
                $total = $total->sumar($monto);
            }

            // 3. Recién ahora se quema un número (R12). Uno solo, para todo.
            $recibo = $this->emitir(
                $venta,
                count($renglones) === 1 ? $renglones[0]['lote'] : null,
                $cliente,
                ConceptoDeRecibo::Cuota,
                $total,
                $forma,
                $limpia,
                $cuando,
                $observaciones,
                array_map(static fn (array $renglon): Compromiso => $renglon['lote'], $renglones),
            );

            // 4. Mora → interés → capital, FIFO adentro de cada lote.
            foreach ($tandas as $tanda) {
                $reparto = $this->repartir(
                    $recibo,
                    $tanda['pendientes'],
                    $tanda['monto'],
                    $tanda['mora'],
                    $condonarMora,
                );

                $moraCobrada = $moraCobrada->sumar($reparto['cobrada']);
                $moraPerdonada = $moraPerdonada->sumar($reparto['condonada']);
            }

            // 5. La mora que efectivamente entró y la que se perdonó, en el
            // papel. Se escriben despues de repartir porque hasta ahi no se
            // sabe cuanta mora alcanzo a cubrir el dinero entregado.
            $this->asentarLaMora($recibo, $moraCobrada, $moraPerdonada, $porQueSePerdona);

            // 6. Si con esto terminó de pagar todo, el expediente se cierra.
            $this->cerrarSiQuedoPagada($venta, $cuando);

            return $recibo;
        });
    }

    /**
     * Anular un recibo mal emitido.
     *
     * ═══ QUE HACE, EXACTAMENTE ═══
     *
     * Devuelve a las cuotas lo que ese recibo les había aplicado —capital,
     * interés y mora, cada uno a su columna—, marca el recibo con quién lo
     * anuló y por qué, y —si la venta se había liquidado con ese cobro— la
     * vuelve a abrir. El número NO se libera y la fila NO se borra: una serie
     * con huecos deja de servir para decir «entre el 000120 y el 000130 no
     * falta ninguno», que es lo único que hace serio a un recibo interno (R12).
     *
     * Las aplicaciones tampoco se borran: son la traza de a qué se había
     * aplicado, y sin ellas «¿por qué la cuota 5 volvió a deber?» no tiene
     * respuesta.
     *
     * ⚠️ La mora condonada en ese recibo tambien se revierte: si el cobro no
     * debio registrarse, el perdon que venia con el tampoco.
     *
     * ═══ QUE NO HACE ═══
     *
     * No devuelve dinero. Anular dice que el cobro no debió registrarse, no
     * que haya que sacar plata de la caja — eso es un egreso, y no existe
     * todavía. Si el cliente sí pagó y el error fue el monto, el camino es
     * anular y volver a cobrar con el número nuevo.
     *
     * ═══ SOLO COBROS DE CUOTA ═══
     *
     * Una prima o una seña consumieron el correlativo de un contrato o dejaron
     * un lote apartado; un abono a capital reescribió un plan. Los tres se
     * rechazan con su motivo: revertirlos es deshacer otra cosa.
     *
     * @throws PagoInvalidoException
     */
    public function anular(Recibo $recibo, string $motivo): Recibo
    {
        $porQue = trim($motivo);

        if ($porQue === '') {
            throw PagoInvalidoException::porFaltarElMotivoDeLaAnulacion();
        }

        if ($recibo->estaAnulado()) {
            throw PagoInvalidoException::porReciboYaAnulado($recibo->folio());
        }

        $concepto = $recibo->getAttribute('concepto');

        if ($concepto !== ConceptoDeRecibo::Cuota) {
            throw $concepto === ConceptoDeRecibo::AbonoCapital
                ? PagoInvalidoException::porReciboQueReprogramo($recibo->folio())
                : PagoInvalidoException::porConceptoQueNoSeAnulaAsi(
                    $concepto instanceof ConceptoDeRecibo ? $concepto->etiqueta() : 'otro concepto',
                    $recibo->folio(),
                );
        }

        return DB::transaction(function () use ($recibo, $porQue): Recibo {
            /*
             * Se relee bloqueando. Dos personas anulando el mismo recibo al
             * mismo tiempo devolverían el saldo dos veces, y la cuota quedaría
             * debiendo más de lo que vale.
             *
             * ⚠️ `whereKey()->firstOrFail()` y NO `findOrFail()`: este último
             * acepta también un arreglo de ids, así que PHPStan nivel 7 lo tipa
             * `Recibo|Collection<int, Recibo>`, y a partir de ahí cada llamada
             * sobre `$vivo` es «método indefinido en Collection». Seis errores
             * salían de esta sola línea.
             */
            $vivo = Recibo::query()
                ->whereKey($recibo->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($vivo->estaAnulado()) {
                throw PagoInvalidoException::porReciboYaAnulado($vivo->folio());
            }

            foreach ($vivo->aplicaciones()->with('cuota')->get() as $aplicacion) {
                $cuota = $aplicacion->cuota;

                if (! $cuota instanceof Cuota) {
                    continue;
                }

                /*
                 * `monto_pagado` recibe capital + interes, nunca la mora: la
                 * mora nunca entro ahi, asi que devolverla la dejaria
                 * debiendo de menos. Va a su propia columna.
                 */
                $aLaCuota = $aplicacion->montoCapital()->sumar($aplicacion->montoInteres());

                $cuota->update([
                    'monto_pagado' => $cuota->montoPagado()->restar($aLaCuota)->redondeado(),
                    'mora_pagada'  => $cuota->moraPagada()->restar($aplicacion->montoMora())->redondeado(),
                ]);
            }

            $this->revertirLaCondonacion($vivo);

            $vivo->update([
                'anulado_el'       => now(),
                'anulado_por'      => auth()->id(),
                'motivo_anulacion' => $porQue,
            ]);

            $this->reabrirSiVolvioADeber($vivo);

            return $vivo;
        });
    }

    /**
     * Abono extraordinario a capital contra UN lote, con su reprogramación (R21).
     *
     * ═══ ES UN ATAJO, Y ESO ES A PROPOSITO ═══
     *
     * Desde el 10-ago-2026 esto es un renglón solo pasado a
     * `abonarAVariosLotes()`, exactamente como `cobrarCuotas()` es un renglón
     * solo de `cobrarVariosLotes()`. **Un camino de código, no dos.** Dos
     * versiones del abono —una para un lote y otra para varios— es la forma más
     * segura de que dentro de tres meses una arregle un borde que la otra no, y
     * que dos clientes con el mismo caso reciban números distintos.
     *
     * Se conserva la firma porque la usan los tests golden del dominio, la
     * pantalla y el import de la cartera vieja: sigue siendo la manera clara de
     * decir «abono a este lote».
     *
     * ═══ CON INTERES, UN ABONO AHORRA INTERESES ═══
     *
     * Y ese pasa a ser el numero que el cliente mira para decidir. Lo calcula
     * `EfectoDelAbono::interesesAhorrados()` y la pantalla lo muestra ANTES de
     * confirmar (§10.8).
     *
     * @param string $motivo obligatorio (R21); la base también lo exige
     *
     * @throws PagoInvalidoException
     */
    public function abonarACapital(
        Venta $venta,
        Compromiso $lote,
        Cliente $cliente,
        Monto $monto,
        ModalidadDeReprogramacion $modalidad,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
    ): Recibo {
        return $this->unSoloRecibo($this->abonarAVariosLotes(
            venta: $venta,
            cliente: $cliente,
            renglones: [['lote' => $lote, 'monto' => $monto, 'modalidad' => $modalidad]],
            motivo: $motivo,
            forma: $forma,
            referencia: $referencia,
            fecha: $fecha,
            observaciones: $observaciones,
        ));
    }

    /**
     * Abonar a capital en VARIOS lotes del mismo contrato, con un solo recibo.
     *
     * ═══ QUE PIDIO MAURICIO, TEXTUAL (10-AGO-2026) ═══
     *
     * «Deberia de poderse a mas de un lote, en caso de que tenga mas el
     * cliente: ponle quiere hacer un abono a capital de 20000 al lote 1 y
     * 10000 al lote 2, todo en una sola transaccion».
     *
     * Un cliente entrega un dinero y se lleva un papel — que es lo que R21 ya
     * decia del abono y lo que `cobrarVariosLotes()` ya hacia para las cuotas.
     *
     * ═══ 🔴 QUE DICE R21, Y POR QUE ESTO NO LO CONTRADICE ═══
     *
     * R21 dice «el abono se aplica A UN LOTE, y lo elige quien recibe», y lo
     * justifica: «repartirlo entre todos recalcularia tres cuotas de golpe y le
     * moveria numeros que no pidio tocar». Lo que la contratante estaba
     * rechazando es que el SISTEMA reparta solo.
     *
     * Aca no reparte nadie: **el monto de cada lote lo teclea quien recibe**, y
     * la modalidad tambien es por lote. El sistema no adivina un centavo. Un
     * lote que no se marca no se toca.
     *
     * ⚠️ Aun asi, la letra de R21 dice «un lote» y la escribio la contratante.
     * Hay que enmendarla por escrito — esta anotado en `docs/dominio.md`.
     *
     * ═══ LA MODALIDAD ES POR LOTE, NO POR RECIBO ═══
     *
     * Decision de Mauricio el 10-ago. Es lo fiel a R21 —los dos caminos los
     * elige el cliente, y con dos lotes puede querer distinto en cada uno— y no
     * costo nada de base: `reprogramaciones.modalidad` ya era una columna POR
     * FILA desde el 6-ago.
     *
     * ═══ EL ORDEN DEL BLOQUEO NO ES CASUAL ═══
     *
     * Los renglones se ordenan por id ANTES de bloquear, igual que en
     * `cobrarVariosLotes()`. Dos personas abonando los mismos dos lotes en
     * orden distinto se traban la una a la otra — el deadlock clasico de dos
     * transacciones que toman los mismos candados al reves.
     *
     * ═══ TODO SE VERIFICA ANTES DE QUEMAR EL NUMERO ═══
     *
     * La fase 1 calcula el efecto de TODOS los lotes y rechaza lo que no se
     * puede hacer; recien la fase 2 escribe. Con dos lotes eso importa mas que
     * con uno: si el segundo se pasa del tope, el primero TAMPOCO se abona y no
     * queda medio recibo con un plan reescrito.
     *
     * ═══ UN LOTE QUE NO ALCANZA NO TUMBA A LOS OTROS ═══
     *
     * Si a un lote no le alcanza ni para lo vencido, ESE lote se registra como
     * pago normal y no se reprograma —igual que con un solo lote— mientras los
     * demas siguen su camino. No es un error: el dinero ya esta sobre el
     * mostrador y la notificacion lo explica.
     *
     * @param list<array{lote: Compromiso, monto: Monto, modalidad: ModalidadDeReprogramacion}> $renglones
     * @param string $motivo obligatorio (R21); la base tambien lo exige
     *
     * @return list<Recibo> un papel por cada titular de recibo
     *
     * @throws PagoInvalidoException
     */
    public function abonarAVariosLotes(
        Venta $venta,
        Cliente $cliente,
        array $renglones,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
    ): array {
        return $this->porCadaNombre(
            $renglones,
            fn (array $suyos): Recibo => $this->abonarEnLosDeUnMismoNombre(
                $venta,
                $cliente,
                $suyos,
                $motivo,
                $forma,
                $referencia,
                $fecha,
                $observaciones,
            ),
        );
    }

    /**
     * El abono de los lotes que comparten titular de recibo: UN solo papel.
     *
     * Es el cuerpo de siempre; lo que cambio el 13-ago-2026 es quien lo llama.
     *
     * @param list<array{lote: Compromiso, monto: Monto, modalidad: ModalidadDeReprogramacion}> $renglones
     *
     * @throws PagoInvalidoException
     */
    private function abonarEnLosDeUnMismoNombre(
        Venta $venta,
        Cliente $cliente,
        array $renglones,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia,
        ?CarbonImmutable $fecha,
        ?string $observaciones,
    ): Recibo {
        if ($renglones === []) {
            throw PagoInvalidoException::porNoElegirNingunLote();
        }

        $porQue = trim($motivo);

        if ($porQue === '') {
            throw PagoInvalidoException::porFaltarElMotivoDelAbono();
        }

        $vistos = [];
        $total = Monto::cero();

        foreach ($renglones as $renglon) {
            $this->verificar($venta, $renglon['lote'], $renglon['monto'], $forma, $referencia);

            $id = (int) $renglon['lote']->getKey();

            if (in_array($id, $vistos, true)) {
                throw PagoInvalidoException::porLoteRepetido($this->codigo($renglon['lote']));
            }

            $vistos[] = $id;
            $total = $total->sumar($renglon['monto']);
        }

        $cuando = $fecha ?? CarbonImmutable::parse(today()->toDateString());
        $this->verificarLaFecha($venta, $cuando);
        $limpia = trim($referencia ?? '');

        // El orden del bloqueo, igual para todos. Ver el docblock.
        usort(
            $renglones,
            static fn (array $uno, array $otro): int => (int) $uno['lote']->getKey() <=> (int) $otro['lote']->getKey(),
        );

        return DB::transaction(function () use (
            $venta,
            $cliente,
            $renglones,
            $porQue,
            $forma,
            $limpia,
            $cuando,
            $observaciones,
            $total
        ): Recibo {
            /*
             * FASE 1 — releer bloqueando, calcular y rechazar. Sin escribir una
             * sola fila: si algo de esto se cae, el correlativo ni se movio.
             */
            $planificados = [];
            $habraReprogramacion = false;

            foreach ($renglones as $renglon) {
                $lote = $renglon['lote'];
                $monto = $renglon['monto'];

                $pendientes = $this->pendientesBloqueadas($lote);
                $mora = MoraDelLote::calcular($pendientes, $this->condicionesDe($lote), $cuando);

                $efecto = EfectoDelAbono::calcular(
                    $pendientes,
                    $monto,
                    $renglon['modalidad'],
                    $this->diaDePago($venta),
                    $this->tasaDe($lote),
                    $mora->total,
                );

                if ($monto->mayorQue($efecto->saldoDelLote->sumar($mora->total))) {
                    throw PagoInvalidoException::porPagarDeMas(
                        $monto,
                        $efecto->saldoDelLote->sumar($mora->total),
                        $this->codigo($lote),
                    );
                }

                if ($efecto->superaElTope) {
                    throw PagoInvalidoException::porAbonoQueNoSePuedeReprogramar(
                        $monto,
                        $efecto->tope,
                        $efecto->saldoDelLote,
                        $this->codigo($lote),
                    );
                }

                $plan = $efecto->planNuevo;

                // Un pago normal no reescribe nada, asi que no necesita plan.
                if (! $efecto->esPagoNormal) {
                    if ($efecto->problema !== null || ! $plan instanceof PlanDeCuotas) {
                        throw PagoInvalidoException::porPlanQueNoSePudoArmar(
                            $efecto->problema ?? 'No se pudo armar el plan nuevo.',
                            $this->codigo($lote),
                        );
                    }

                    // Un plan que no cierra al centimo no llega nunca a la base
                    // (§8.3.4). Es la misma verificacion que hace RegistroDeVentas.
                    if (! $plan->cierraExacto()) {
                        throw PagoInvalidoException::porPlanQueNoCierra($plan->totalCapital(), $efecto->saldoNuevo);
                    }

                    $habraReprogramacion = true;
                }

                $planificados[] = [
                    'lote'       => $lote,
                    'monto'      => $monto,
                    'pendientes' => $pendientes,
                    'mora'       => $mora,
                    'efecto'     => $efecto,
                    'plan'       => $plan,
                ];
            }

            /*
             * FASE 2 — escribir. Un solo numero para todo (R12), y
             * `compromiso_id` en NULL cuando son varios: este recibo no es de
             * un lote, y el desglose es el que lo dice.
             */
            $recibo = $this->emitir(
                $venta,
                count($renglones) === 1 ? $renglones[0]['lote'] : null,
                $cliente,
                /*
                 * 🔴 EL PAPEL DICE LO QUE HIZO, NO LO QUE SE PIDIO
                 *
                 * Si a ningun lote le alcanzo para bajar capital, esto fue un
                 * cobro de cuotas y el recibo tiene que decir «cuota» — porque
                 * `anular()` rechaza los de concepto `abono_capital` por haber
                 * reescrito un plan. Marcar asi uno que no reprogramo nada lo
                 * dejaria **inanulable para siempre**, sin ninguna razon.
                 *
                 * Se sabe aca porque la fase 1 ya calculo el efecto de todos
                 * los lotes: para eso existe.
                 *
                 * ⚠️ Esta linea se perdio el 10-ago al unificar los dos caminos
                 * —el `abonarACapital` viejo emitia `Cuota` en su rama de pago
                 * normal— y la atrapo el golden test del dominio. Si alguien la
                 * simplifica a `AbonoCapital` fijo, se cae `AbonoACapitalTest`.
                 */
                $habraReprogramacion ? ConceptoDeRecibo::AbonoCapital : ConceptoDeRecibo::Cuota,
                $total,
                $forma,
                $limpia,
                $cuando,
                $observaciones,
                array_map(static fn (array $renglon): Compromiso => $renglon['lote'], $renglones),
            );

            $moraCobrada = Monto::cero();

            foreach ($planificados as $planificado) {
                $efecto = $planificado['efecto'];

                /*
                 * No alcanzo ni para lo vencido: ESTE lote es un pago normal y
                 * no se reescribe ningun plan. Los demas siguen su camino.
                 */
                if ($efecto->esPagoNormal) {
                    $reparto = $this->repartir($recibo, $planificado['pendientes'], $planificado['monto'], $planificado['mora']);
                    $moraCobrada = $moraCobrada->sumar($reparto['cobrada']);

                    continue;
                }

                $plan = $planificado['plan'];

                if (! $plan instanceof PlanDeCuotas) {
                    /*
                     * Imposible: la fase 1 ya lo verifico. Va una excepcion y no
                     * un `continue` callado, porque un plan que se perdio entre
                     * las dos fases es un error nuestro y tiene que caerse
                     * entero, no abonar a medias.
                     */
                    throw PagoInvalidoException::porPlanQueNoSePudoArmar(
                        'El plan se perdio entre la verificacion y la escritura.',
                        $this->codigo($planificado['lote']),
                    );
                }

                /*
                 * Poner al dia, FIFO, con la mora adelante. Con lo vencido
                 * cubierto por completo, esas cuotas quedan saldadas y ninguna
                 * sale parcial de este paso.
                 */
                if (! $efecto->ponerAlDia->esCero()) {
                    $reparto = $this->repartir($recibo, $planificado['pendientes'], $efecto->ponerAlDia, $planificado['mora']);
                    $moraCobrada = $moraCobrada->sumar($reparto['cobrada']);
                }

                $this->reescribirElPlan($venta, $planificado['lote'], $efecto, $plan);
                $this->asentarLaConstancia($venta, $planificado['lote'], $recibo, $efecto, $plan, $porQue);
            }

            /*
             * La mora se asienta UNA vez y no por lote: `asentarLaMora()` hace
             * un `update()` sobre el recibo, asi que llamarla adentro del bucle
             * pisaria la del lote anterior en vez de sumarla. Es la misma razon
             * por la que `cobrarVariosLotes()` acumula.
             */
            $this->asentarLaMora($recibo, $moraCobrada, Monto::cero(), '');

            // El resumen solo cambia si algun plan se reescribio.
            if ($habraReprogramacion) {
                $this->recalcularElResumen($venta);
            }

            $this->cerrarSiQuedoPagada($venta, $cuando);

            return $recibo;
        });
    }

    /**
     * Pronto pago: saldar uno o varios lotes perdonando parte del saldo.
     *
     * ═══ QUE PIDIO MAURICIO, TEXTUAL (23-AGO-2026) ═══
     *
     * «Digamos tiene 1, 2 o mas lotes y quiere pagar el restante de uno y solo
     * ha dado una cuota y quiere pagar todo el lote 2 pero pide un descuento:
     * se le coloca cuanto se le dio de descuento en ese lote y que pague el
     * resto, y ya quedaria pagado. Esto sucede en casos reales.»
     *
     * Sin tope —cuanto se descuenta lo decide la lotificadora, no el sistema—
     * pero con motivo obligatorio, igual que el descuento al vender (R4).
     *
     * ═══ NO ES UN ABONO A CAPITAL, Y POR ESO NO REPROGRAMA ═══
     *
     * Un abono baja el capital y REESCRIBE lo que falta. Un pronto pago
     * TERMINA el plan del lote: no queda nada que reamortizar, asi que no pasa
     * por `EfectoDelAbono` ni deja constancia de reprogramacion. Las cuotas se
     * quedan donde estan, saldadas — el expediente sigue mostrando el plan
     * completo y donde cayo cada leimpira.
     *
     * ═══ EL DINERO A LAS CUOTAS MAS VIEJAS; EL PERDON, A LA COLA ═══
     *
     * Es el mismo FIFO de siempre: lo que el cliente entrega salda desde la
     * cuota mas vieja, y el descuento cubre exactamente lo que quedo sin
     * alcanzar. Cualquier otro reparto —prorratear el descuento entre todas—
     * daria el mismo total con renglones que nadie puede explicar en el
     * mostrador.
     *
     * ═══ 🔴 LA CAJA RECIBE SOLO LO QUE ENTRO ═══
     *
     * `recibos.monto` es lo que el cliente entrego, ni un centavo mas. El
     * descuento no pasa por el corte de caja: se perdona, no se cobra. Vive en
     * `aplicaciones_de_pago.capital_condonado`, renglon por renglon.
     *
     * ⚠️ El recibo sale con concepto `AbonoCapital`, asi que **no se puede
     * anular**: `anular()` los rechaza porque tocaron el plan. Es a proposito y
     * es lo mismo que ya pasa con un abono. Revertir un pronto pago es otro
     * tramite con su propio motivo, y todavia no existe.
     *
     * @param list<array{lote: Compromiso, descuento: Monto}> $renglones
     * @param string $motivo obligatorio: sin el no hay descuento
     *
     * @return list<Recibo> un papel por cada titular de recibo
     *
     * @throws PagoInvalidoException
     */
    public function prontoPago(
        Venta $venta,
        Cliente $cliente,
        array $renglones,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
    ): array {
        return $this->porCadaNombre(
            $renglones,
            fn (array $suyos): Recibo => $this->saldarLosDeUnMismoNombre(
                $venta,
                $cliente,
                $suyos,
                $motivo,
                $forma,
                $referencia,
                $fecha,
                $observaciones,
            ),
        );
    }

    /**
     * Los lotes que comparten titular de recibo: UN solo papel.
     *
     * @param list<array{lote: Compromiso, descuento: Monto}> $renglones
     *
     * @throws PagoInvalidoException
     */
    private function saldarLosDeUnMismoNombre(
        Venta $venta,
        Cliente $cliente,
        array $renglones,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia,
        ?CarbonImmutable $fecha,
        ?string $observaciones,
    ): Recibo {
        if ($renglones === []) {
            throw PagoInvalidoException::porNoElegirNingunLote();
        }

        $porQue = trim($motivo);

        if ($porQue === '') {
            throw PagoInvalidoException::porFaltarElMotivoDelDescuento();
        }

        $vistos = [];

        foreach ($renglones as $renglon) {
            $id = (int) $renglon['lote']->getKey();

            if (in_array($id, $vistos, true)) {
                throw PagoInvalidoException::porLoteRepetido($this->codigo($renglon['lote']));
            }

            $vistos[] = $id;
        }

        $cuando = $fecha ?? CarbonImmutable::parse(today()->toDateString());
        $this->verificarLaFecha($venta, $cuando);
        $limpia = trim($referencia ?? '');

        // El orden del bloqueo, igual para todos. Ver `cobrarVariosLotes()`.
        usort(
            $renglones,
            static fn (array $uno, array $otro): int => (int) $uno['lote']->getKey() <=> (int) $otro['lote']->getKey(),
        );

        return DB::transaction(function () use (
            $venta,
            $cliente,
            $renglones,
            $porQue,
            $forma,
            $limpia,
            $cuando,
            $observaciones
        ): Recibo {
            /*
             * FASE 1 — releer bloqueando, calcular y rechazar. Sin escribir una
             * sola fila: si algo de esto se cae, el correlativo ni se movio.
             */
            $planificados = [];
            $aEntregar = Monto::cero();

            foreach ($renglones as $renglon) {
                $lote = $renglon['lote'];
                $descuento = $renglon['descuento'];

                $pendientes = $this->pendientesBloqueadas($lote);
                $saldo = $this->saldoDe($pendientes);

                /*
                 * Se le pasa el SALDO y no lo que el cliente entrega: con un
                 * descuento igual al saldo, lo entregado es cero y `verificar()`
                 * lo rechazaria por «monto no positivo», un mensaje que no dice
                 * nada de lo que en realidad pasa. Ese caso tiene el suyo, abajo.
                 */
                $this->verificar($venta, $lote, $saldo, $forma, $limpia);

                $mora = MoraDelLote::calcular($pendientes, $this->condicionesDe($lote), $cuando);

                if (! $mora->total->esCero()) {
                    throw PagoInvalidoException::porMoraPendienteEnProntoPago(
                        $mora->total,
                        $this->codigo($lote),
                    );
                }

                if ($descuento->mayorQue($saldo)) {
                    throw PagoInvalidoException::porDescuentoQueSuperaElSaldo(
                        $descuento,
                        $saldo,
                        $this->codigo($lote),
                    );
                }

                $planificados[] = [
                    'lote'       => $lote,
                    'pendientes' => $pendientes,
                    'enDinero'   => $saldo->restar($descuento),
                    'descuento'  => $descuento,
                ];

                $aEntregar = $aEntregar->sumar($saldo->restar($descuento));
            }

            /*
             * Perdonarlo TODO no es un pronto pago: es una donacion, y esa es
             * otra operacion con otro permiso. Ademas el CHECK
             * `recibos_monto_positivo_chk` no admite un recibo de L 0.00.
             */
            if ($aEntregar->esCero()) {
                throw PagoInvalidoException::porMontoNoPositivo();
            }

            /*
             * FASE 2 — escribir. Un solo numero para todo (R12), y
             * `compromiso_id` en NULL cuando son varios lotes.
             *
             * Concepto `AbonoCapital` porque este papel dio por terminado un
             * plan: es lo que hace que `anular()` lo rechace, igual que a un
             * abono. Ver el docblock de `prontoPago()`.
             */
            $recibo = $this->emitir(
                $venta,
                count($renglones) === 1 ? $renglones[0]['lote'] : null,
                $cliente,
                ConceptoDeRecibo::AbonoCapital,
                $aEntregar,
                $forma,
                $limpia,
                $cuando,
                $observaciones,
                array_map(static fn (array $renglon): Compromiso => $renglon['lote'], $renglones),
            );

            foreach ($planificados as $planificado) {
                $this->saldarConDescuento($recibo, $planificado['pendientes'], $planificado['enDinero']);
            }

            $this->recalcularElResumen($venta);
            $this->asentarElDescuento($venta, $recibo, $planificados, $porQue);
            $this->cerrarSiQuedoPagada($venta, $cuando);

            return $recibo;
        });
    }

    /**
     * Saldar estas cuotas: el dinero a las mas viejas, el perdon a la cola.
     *
     * Al salir, TODAS quedan en cero — es lo que promete un pronto pago. La
     * cuenta cierra sola porque `enDinero` es exactamente el saldo del lote
     * menos el descuento: lo que el dinero no alcanza a cubrir es, al centavo,
     * lo que hay que condonar.
     *
     * @param Collection<int, Cuota> $pendientes
     */
    private function saldarConDescuento(Recibo $recibo, Collection $pendientes, Monto $enDinero): void
    {
        $porRepartir = $enDinero;

        foreach ($pendientes as $cuota) {
            $falta = $cuota->saldo();

            if ($falta->esCero()) {
                continue;
            }

            $enEfectivo = $porRepartir->mayorQue($falta) ? $falta : $porRepartir;
            $porRepartir = $porRepartir->restar($enEfectivo);
            $condonado = $falta->restar($enEfectivo);

            // Interes antes que capital, el mismo orden que `repartir()`.
            $interesPendiente = $cuota->interesPendiente();
            $aInteres = $enEfectivo->mayorQue($interesPendiente) ? $interesPendiente : $enEfectivo;

            $recibo->aplicaciones()->create([
                'cuota_id'      => $cuota->getKey(),
                'monto'         => $enEfectivo->redondeado(),
                'monto_mora'    => '0.00',
                'monto_interes' => $aInteres->redondeado(),
                'monto_capital' => $enEfectivo->restar($aInteres)->redondeado(),
                /*
                 * Fuera de `monto` —no es dinero que entro— pero DENTRO de
                 * `cuotas.monto_pagado`, que es lo que dejo saldada la cuota.
                 * El porque de esa asimetria esta en `Cuota::capitalCondonado()`.
                 */
                'capital_condonado' => $condonado->redondeado(),
            ]);

            $cuota->update([
                'monto_pagado'      => $cuota->montoTotal()->redondeado(),
                'capital_condonado' => $cuota->capitalCondonado()->sumar($condonado)->redondeado(),
            ]);
        }
    }

    /**
     * El asiento en la bitacora del expediente: quien perdono cuanto, y por que.
     *
     * Va contra la VENTA y no contra el recibo, igual que `CambioDeTitular`:
     * asi sale en la pestaña «Actualizaciones» del expediente, que es donde
     * alguien va a buscar dentro de dos años por que a este cliente se le
     * descontaron esos lempiras.
     *
     * @param list<array{lote: Compromiso, pendientes: Collection<int, Cuota>, enDinero: Monto, descuento: Monto}> $planificados
     */
    private function asentarElDescuento(Venta $venta, Recibo $recibo, array $planificados, string $motivo): void
    {
        $porLote = [];
        $total = Monto::cero();

        foreach ($planificados as $planificado) {
            if ($planificado['descuento']->esCero()) {
                continue;
            }

            $porLote[$this->codigo($planificado['lote'])] = $planificado['descuento']->formateado();
            $total = $total->sumar($planificado['descuento']);
        }

        // Un pronto pago sin descuento es saldar el lote y ya: no hay nada que
        // justificar, asi que no se ensucia la bitacora con un asiento vacio.
        if ($porLote === []) {
            return;
        }

        activity()
            ->performedOn($venta)
            ->causedBy(auth()->user())
            /*
             * 🔴 `withChanges()` y NO `withProperties()`, por lo mismo que en
             * `CambioDeTitular`: la pestaña «Actualizaciones» y la bitacora
             * general leen `attribute_changes`. En `properties` el asiento
             * quedaria guardado donde nadie lo pinta.
             */
            ->withChanges([
                'old'        => ['descuento por pronto pago' => '—'],
                'attributes' => ['descuento por pronto pago' => $total->formateado()],
            ])
            ->withProperty('motivo', $motivo)
            ->withProperty('recibo', $recibo->folio())
            ->withProperty('lotes', $porLote)
            ->event('pronto_pago')
            ->log('Pronto pago con descuento');
    }

    /**
     * Cobrar cuotas de varios lotes Y abonar el sobrante a capital, con UN recibo.
     *
     * ═══ QUE PIDIO MAURICIO (10-AGO-2026) ═══
     *
     * «Aca tambien debe de poderse, la cuota de los lotes que tenga y si
     * tambien quiere hacer abono a capital en el mismo coso». Y al preguntarle
     * como reparte: «se selecciona como cuota o abono a capital; en caso de que
     * traiga para dos cuotas y sobre, se le abona como capital a **un lote
     * seleccionable**».
     *
     * ═══ SON DOS COSAS SEPARADAS, Y ESO LO DECIDIO EL ═══
     *
     * Yo habia propuesto un monto por lote que el sistema partiera solo en
     * cuota y capital. Mauricio lo corrigio y tenia razon: en el mostrador son
     * dos gestos distintos —«vengo a pagar el mes de mis tres lotes» y «y con
     * lo que sobra bajame el lote 1»— y mezclarlos en un numero obliga a quien
     * atiende a hacer cuentas de cabeza.
     *
     * Asi que:
     *
     *  - `$cuotas` son los renglones que se cobran, FIFO, lote por lote. Es
     *    exactamente lo que hace `cobrarVariosLotes()`.
     *  - `$aCapital` es el sobrante, y va contra UN lote elegido.
     *
     * 🔴 **De regalo, esto respeta la letra de R21**: el abono sigue yendo a un
     * solo lote. La enmienda R21-bis solo hace falta para `abonarAVariosLotes()`,
     * no para este camino.
     *
     * ═══ EL ORDEN NO ES NEGOCIABLE: PRIMERO SE COBRA, DESPUES SE ABONA ═══
     *
     * R21 no deja tocar una cuota pagada a medias, asi que un abono contra un
     * lote que tiene una se rechaza (`superaElTope`). Cobrando primero, esa
     * cuota queda saldada y el abono corre sobre cuotas que nadie toco — que es
     * justo el caso que este metodo existe para resolver.
     *
     * Por eso el efecto del abono se calcula **releyendo despues del cobro** y
     * no antes: cualquier otra cosa mediria un estado que ya no existe.
     *
     * ═══ POR QUE EL SOBRANTE QUE NO ALCANZA SE RECHAZA ═══
     *
     * Si despues de cobrar las cuotas el sobrante no llega a bajar capital,
     * «Ambas» no cumplio lo que promete. Se rechaza con el numero que falta en
     * vez de registrar en silencio un abono que no abono: la pantalla sigue
     * abierta y quien atiende mueve ese dinero a las cuotas, que es un campo.
     *
     * @param list<array{lote: Compromiso, monto: Monto}> $cuotas los renglones que se cobran
     * @param string $motivo obligatorio (R21); la base tambien lo exige
     *
     * @return list<Recibo> un papel por cada titular de recibo
     *
     * @throws PagoInvalidoException
     */
    public function cobrarYAbonar(
        Venta $venta,
        Cliente $cliente,
        array $cuotas,
        Compromiso $loteDelAbono,
        Monto $aCapital,
        ModalidadDeReprogramacion $modalidad,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
    ): array {
        /*
         * El abono viaja con las cuotas de SU MISMO nombre: es plata del mismo
         * lote, y separarlo en otro papel seria partir en dos algo que la
         * persona entrego junta. Las cuotas de los otros nombres salen en sus
         * propios recibos, sin abono.
         */
        $suNombre = $loteDelAbono->titularDelRecibo() ?? '';
        $grupos = $this->agruparPorNombre($cuotas);
        $recibos = [];

        return DB::transaction(function () use (
            $grupos,
            $suNombre,
            $venta,
            $cliente,
            $loteDelAbono,
            $aCapital,
            $modalidad,
            $motivo,
            $forma,
            $referencia,
            $fecha,
            $observaciones,
            $recibos
        ): array {
            foreach ($grupos as $nombre => $suyas) {
                $recibos[] = $nombre === $suNombre
                    ? $this->cobrarYAbonarEnUnMismoNombre(
                        $venta,
                        $cliente,
                        $suyas,
                        $loteDelAbono,
                        $aCapital,
                        $modalidad,
                        $motivo,
                        $forma,
                        $referencia,
                        $fecha,
                        $observaciones,
                    )
                    : $this->cobrarLosDeUnMismoNombre(
                        $venta,
                        $cliente,
                        $suyas,
                        $forma,
                        $referencia,
                        $fecha,
                        $observaciones,
                        false,
                        null,
                    );
            }

            // El abono es de un lote que no tenia ninguna cuota marcada: se va
            // solo, en su propio papel y a su propio nombre.
            if (! array_key_exists($suNombre, $grupos)) {
                $recibos[] = $this->cobrarYAbonarEnUnMismoNombre(
                    $venta,
                    $cliente,
                    [],
                    $loteDelAbono,
                    $aCapital,
                    $modalidad,
                    $motivo,
                    $forma,
                    $referencia,
                    $fecha,
                    $observaciones,
                );
            }

            return $recibos;
        });
    }

    /**
     * Las cuotas y el abono de un mismo titular de recibo: UN solo papel.
     *
     * Es el cuerpo de siempre; lo que cambio el 13-ago-2026 es quien lo llama.
     *
     * @param list<array{lote: Compromiso, monto: Monto}> $cuotas
     *
     * @throws PagoInvalidoException
     */
    private function cobrarYAbonarEnUnMismoNombre(
        Venta $venta,
        Cliente $cliente,
        array $cuotas,
        Compromiso $loteDelAbono,
        Monto $aCapital,
        ModalidadDeReprogramacion $modalidad,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia,
        ?CarbonImmutable $fecha,
        ?string $observaciones,
    ): Recibo {
        $this->verificar($venta, $loteDelAbono, $aCapital, $forma, $referencia);

        $porQue = trim($motivo);

        if ($porQue === '') {
            throw PagoInvalidoException::porFaltarElMotivoDelAbono();
        }

        $vistos = [];
        $total = $aCapital;

        foreach ($cuotas as $renglon) {
            $this->verificar($venta, $renglon['lote'], $renglon['monto'], $forma, $referencia);

            $id = (int) $renglon['lote']->getKey();

            if (in_array($id, $vistos, true)) {
                throw PagoInvalidoException::porLoteRepetido($this->codigo($renglon['lote']));
            }

            $vistos[] = $id;
            $total = $total->sumar($renglon['monto']);
        }

        $cuando = $fecha ?? CarbonImmutable::parse(today()->toDateString());
        $this->verificarLaFecha($venta, $cuando);
        $limpia = trim($referencia ?? '');

        // El orden del bloqueo, igual para todo el sistema. Ver
        // `cobrarVariosLotes()`: sin esto, dos receptores se traban entre si.
        usort(
            $cuotas,
            static fn (array $uno, array $otro): int => (int) $uno['lote']->getKey() <=> (int) $otro['lote']->getKey(),
        );

        /*
         * `compromiso_id` solo se llena cuando TODO el recibo —las cuotas y el
         * abono— es de un mismo lote. Con dos, la columna diria una mentira y
         * el desglose es el que contesta (R13).
         */
        $tocados = $vistos;

        if (! in_array((int) $loteDelAbono->getKey(), $tocados, true)) {
            $tocados[] = (int) $loteDelAbono->getKey();
        }

        return DB::transaction(function () use (
            $venta,
            $cliente,
            $cuotas,
            $loteDelAbono,
            $aCapital,
            $modalidad,
            $porQue,
            $forma,
            $limpia,
            $cuando,
            $observaciones,
            $total,
            $tocados
        ): Recibo {
            // 1. Las cuotas: releer bloqueando y rechazar lo que paga de mas.
            $tandas = [];

            foreach ($cuotas as $renglon) {
                $pendientes = $this->pendientesBloqueadas($renglon['lote']);
                $mora = MoraDelLote::calcular($pendientes, $this->condicionesDe($renglon['lote']), $cuando);
                $tope = $this->saldoDe($pendientes)->sumar($mora->total);

                if ($renglon['monto']->mayorQue($tope)) {
                    throw PagoInvalidoException::porPagarDeMas($renglon['monto'], $tope, $this->codigo($renglon['lote']));
                }

                $tandas[] = ['pendientes' => $pendientes, 'monto' => $renglon['monto'], 'mora' => $mora];
            }

            // 2. UN numero, para las dos mitades (R12).
            $recibo = $this->emitir(
                $venta,
                count($tocados) === 1 ? $loteDelAbono : null,
                $cliente,
                ConceptoDeRecibo::AbonoCapital,
                $total,
                $forma,
                $limpia,
                $cuando,
                $observaciones,
                [...array_map(static fn (array $renglon): Compromiso => $renglon['lote'], $cuotas), $loteDelAbono],
            );

            // 3. La mitad de cuota: FIFO, mora → interes → capital.
            $moraCobrada = Monto::cero();

            foreach ($tandas as $tanda) {
                $reparto = $this->repartir($recibo, $tanda['pendientes'], $tanda['monto'], $tanda['mora']);
                $moraCobrada = $moraCobrada->sumar($reparto['cobrada']);
            }

            /*
             * La mora se asienta UNA vez: `asentarLaMora()` hace un `update()`
             * sobre el recibo, asi que llamarla por lote pisaria la anterior.
             */
            $this->asentarLaMora($recibo, $moraCobrada, Monto::cero(), '');

            /*
             * 4. El abono, sobre el estado que dejo el cobro. Releer aca es el
             * corazon del metodo: la cuota que estaba a medias ya quedo saldada
             * y el lote entra al abono con cuotas que nadie toco.
             */
            $limpias = $this->pendientesBloqueadas($loteDelAbono);
            $moraDelAbono = MoraDelLote::calcular($limpias, $this->condicionesDe($loteDelAbono), $cuando);

            $efecto = EfectoDelAbono::calcular(
                $limpias,
                $aCapital,
                $modalidad,
                $this->diaDePago($venta),
                $this->tasaDe($loteDelAbono),
                $moraDelAbono->total,
            );

            if ($aCapital->mayorQue($efecto->saldoDelLote->sumar($moraDelAbono->total))) {
                throw PagoInvalidoException::porPagarDeMas(
                    $aCapital,
                    $efecto->saldoDelLote->sumar($moraDelAbono->total),
                    $this->codigo($loteDelAbono),
                );
            }

            if ($efecto->esPagoNormal) {
                throw PagoInvalidoException::porSobranteQueNoBajaCapital(
                    $aCapital,
                    $efecto->ponerAlDia,
                    $this->codigo($loteDelAbono),
                );
            }

            if ($efecto->superaElTope) {
                throw PagoInvalidoException::porAbonoQueNoSePuedeReprogramar(
                    $aCapital,
                    $efecto->tope,
                    $efecto->saldoDelLote,
                    $this->codigo($loteDelAbono),
                );
            }

            $plan = $efecto->planNuevo;

            if ($efecto->problema !== null || ! $plan instanceof PlanDeCuotas) {
                throw PagoInvalidoException::porPlanQueNoSePudoArmar(
                    $efecto->problema ?? 'No se pudo armar el plan nuevo.',
                    $this->codigo($loteDelAbono),
                );
            }

            // Un plan que no cierra al centimo no llega nunca a la base (§8.3.4).
            if (! $plan->cierraExacto()) {
                throw PagoInvalidoException::porPlanQueNoCierra($plan->totalCapital(), $efecto->saldoNuevo);
            }

            /*
             * `ponerAlDia` vale cero: el paso 4 releyo despues del cobro, asi
             * que no quedo nada vencido que poner al dia. Si algun dia dejara
             * de valer cero, `esPagoNormal` de arriba ya lo habria rechazado.
             */
            $this->reescribirElPlan($venta, $loteDelAbono, $efecto, $plan);
            $this->asentarLaConstancia($venta, $loteDelAbono, $recibo, $efecto, $plan, $porQue);
            $this->recalcularElResumen($venta);
            $this->cerrarSiQuedoPagada($venta, $cuando);

            return $recibo;
        });
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Lo que se puede verificar sin tocar la base.
     *
     * @throws PagoInvalidoException
     */
    private function verificar(
        Venta $venta,
        Compromiso $lote,
        Monto $monto,
        FormaDePago $forma,
        ?string $referencia,
    ): void {
        if ($monto->esCero()) {
            throw PagoInvalidoException::porMontoNoPositivo();
        }

        $estado = $venta->getAttribute('estado');

        if ($estado !== EstadoVenta::Vigente) {
            throw PagoInvalidoException::porVentaQueNoEstaVigente(
                $estado instanceof EstadoVenta ? $estado->value : 'desconocido'
            );
        }

        if ((int) $lote->getAttribute('venta_id') !== (int) $venta->getKey()) {
            throw PagoInvalidoException::porLoteDeOtraVenta(
                $this->codigo($lote),
                (string) $venta->getAttribute('numero_contrato'),
            );
        }

        // R22: un lote rescindido puede conservar una cuota con saldo, asi
        // que «debe» no alcanza para dejar cobrar. Ver el mensaje.
        if ($lote->getAttribute('estado') !== EstadoCompromiso::Vigente) {
            throw PagoInvalidoException::porLoteRescindido($this->codigo($lote));
        }

        // R11. La base tiene el mismo CHECK; esto es para que el mensaje lo
        // escriba alguien y no Postgres.
        if ($forma->exigeReferencia() && trim($referencia ?? '') === '') {
            throw PagoInvalidoException::porFaltarReferencia($forma->etiqueta());
        }
    }

    /**
     * Las condiciones de mora CONGELADAS de este lote.
     *
     * Del compromiso y no del plan de pago del proyecto: si mañana la
     * lotificadora sube la mora al 30 %, este contrato sigue con la que se
     * firmó. Es el mismo criterio que ya rige área, precio, plazo y prima.
     */
    private function condicionesDe(Compromiso $lote): CondicionesDeMora
    {
        return CondicionesDeMora::deBase(
            $lote->getAttribute('mora_modalidad'),
            $lote->getAttribute('mora_monto'),
            $lote->getAttribute('mora_porcentaje'),
            $lote->getAttribute('mora_dias_gracia'),
        );
    }

    /**
     * La tasa de interés CONGELADA de este lote. Cero con R1.
     */
    private function tasaDe(Compromiso $lote): TasaDeInteres
    {
        return TasaDeInteres::deBase($lote->getAttribute('tasa_interes_anual'));
    }

    /**
     * Las cuotas que todavía deben algo, bloqueadas hasta el fin de la
     * transacción.
     *
     * `orderBy('numero')` y no por fecha: dos cuotas pueden vencer el mismo
     * día si el plan se reprogramó, y el número es el que no se repite.
     *
     * @return Collection<int, Cuota>
     *
     * @throws PagoInvalidoException
     */
    private function pendientesBloqueadas(Compromiso $lote): Collection
    {
        $pendientes = Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->whereColumn('monto_pagado', '<', 'monto')
            ->orderBy('numero')
            ->lockForUpdate()
            ->get();

        if ($pendientes->isEmpty()) {
            throw PagoInvalidoException::porNoDeberNada($this->codigo($lote));
        }

        return $pendientes;
    }

    /**
     * @param Collection<int, Cuota> $pendientes
     */
    private function saldoDe(Collection $pendientes): Monto
    {
        $saldo = Monto::cero();

        foreach ($pendientes as $cuota) {
            $saldo = $saldo->sumar($cuota->saldo());
        }

        return $saldo;
    }

    /**
     * La fecha del pago tiene que ser creíble.
     *
     * ═══ POR QUE NO ALCANZA CON EL DATEPICKER ═══
     *
     * La pantalla lo limita, pero la pantalla no es el borde: el Service es la
     * única puerta y lo llama también el import de la cartera vieja. Un cobro
     * fechado el mes que viene deja una cuota que figura pagada antes de
     * haberse cobrado, y uno fechado en 2019 —el clásico error de tipear el
     * año— entra sin que nada chille.
     *
     * ⚠️ Con mora, la fecha ademas MUEVE PLATA: los dias de atraso se cuentan
     * hasta ella. Un cobro fechado un mes atras cobraria treinta dias menos de
     * mora, y eso ya no es un dato mal escrito sino dinero que no entro.
     *
     * @throws PagoInvalidoException
     */
    private function verificarLaFecha(Venta $venta, CarbonImmutable $cuando): void
    {
        $hoy = CarbonImmutable::parse(today()->toDateString());

        if ($cuando->greaterThan($hoy)) {
            throw PagoInvalidoException::porFechaFutura($cuando->format('d/m/Y'));
        }

        $firma = $venta->getAttribute('fecha_contrato');

        if ($firma instanceof CarbonInterface && $cuando->lessThan($firma->startOfDay())) {
            throw PagoInvalidoException::porFechaAnteriorAlContrato(
                $cuando->format('d/m/Y'),
                $firma->format('d/m/Y'),
            );
        }
    }

    /**
     * Un expediente que terminó de pagarse deja de estar vigente.
     *
     * No es un trámite que alguien deba acordarse de hacer: es una
     * consecuencia aritmética del último pago. `EstadoVenta::Liquidada`
     * existía desde la primera migración y **nadie lo asignaba nunca**.
     *
     * ═══ POR QUE LA REGLA YA NO ESTA ACA ═══
     *
     * Desde el 23-ago-2026 vive en `Venta::liquidarSiYaNoDebe()`, porque el
     * cobro dejó de ser el único camino que deja una venta en cero: la de
     * contado nace pagada y nunca pasa por este archivo. Este método es el
     * nombre que usan los cuatro caminos de cobro; el porqué completo —y por
     * qué se mira el saldo de las cuotas y no la mora— está en el modelo.
     */
    private function cerrarSiQuedoPagada(Venta $venta, CarbonImmutable $cuando): void
    {
        $venta->liquidarSiYaNoDebe($cuando);
    }

    /**
     * Anular el cobro que la cerró la vuelve a abrir.
     *
     * Sin esto, anular el último recibo dejaría un expediente «Liquidado» que
     * vuelve a deber dinero y sin botón para cobrarlo.
     */
    private function reabrirSiVolvioADeber(Recibo $recibo): void
    {
        $venta = $recibo->venta;

        if (! $venta instanceof Venta || $venta->getAttribute('estado') !== EstadoVenta::Liquidada) {
            return;
        }

        if ($venta->saldoPendiente()->esCero()) {
            return;
        }

        $venta->update([
            'estado'     => EstadoVenta::Vigente,
            'cerrada_el' => null,
        ]);
    }

    /**
     * El documento. Un solo lugar donde se quema un correlativo.
     *
     * `$lote` viene en null cuando el recibo cubre varios lotes del contrato:
     * `compromiso_id` no puede decir «estos tres», y ponerle uno de los tres
     * sería peor que dejarla vacía. El CHECK
     * `recibos_cuelgan_de_un_compromiso_chk` la deja pasar porque `venta_id`
     * está puesto — R13: todo pago cuelga de algo.
     *
     * @param list<Compromiso> $lotesDelRecibo los lotes que cubre este papel,
     *                                         para saber a nombre de quien sale
     */
    private function emitir(
        Venta $venta,
        ?Compromiso $lote,
        Cliente $cliente,
        ConceptoDeRecibo $concepto,
        Monto $monto,
        FormaDePago $forma,
        string $referencia,
        CarbonImmutable $cuando,
        ?string $observaciones,
        array $lotesDelRecibo = [],
    ): Recibo {
        $aNombreDe = $this->aNombreDeQuien($lotesDelRecibo);
        $factura = $this->facturas->paraElProyecto($venta->proyecto);

        return Recibo::query()->create([
            'numero'        => $this->correlativos->siguienteDeReciboInterno(),
            'venta_id'      => $venta->getKey(),
            'compromiso_id' => $lote?->getKey(),
            'cliente_id'    => $cliente->getKey(),
            /*
             * 🔴 UNA COPIA CONGELADA, NO UNA LECTURA DEL LOTE.
             *
             * A nombre de quien sale el papel lo dice la configuracion del lote
             * (`compromisos.titular_recibo`), pero el recibo se queda con su
             * propia copia — igual que el area y el precio en §8.2. Si mañana
             * se corrige ese nombre, los papeles ya entregados tienen que
             * seguir diciendo lo que decian: un recibo entregado no se corrige,
             * se anula y se emite otro.
             */
            'a_nombre_de'     => $aNombreDe['nombre'],
            'a_nombre_de_dni' => $aNombreDe['dni'],
            'concepto'        => $concepto,
            'forma_pago'      => $forma,
            'referencia'      => $referencia === '' ? null : $referencia,
            'monto'           => $monto->redondeado(),
            'fecha'           => $cuando->toDateString(),
            'observaciones'   => $observaciones,
            /*
             * ═══ LA FACTURA CON CAI, DESDE EL 14-AGO-2026 ═══
             *
             * Si el desarrollo tiene una facturación encendida, acá se consume
             * el correlativo del SAR y el papel sale como FACTURA. Si no, no
             * agrega nada y el papel sale como el recibo interno de siempre.
             *
             * El número interno de arriba NO se saltea en ninguno de los dos
             * casos: es el que cuadra la caja, y una serie con huecos deja de
             * servir para eso (R12).
             */
            ...($factura?->paraElRecibo() ?? []),
        ]);
    }

    /**
     * Un papel por cada titular de recibo, y uno solo si todos comparten.
     *
     * ═══ QUE PIDIO MAURICIO, TEXTUAL (13-AGO-2026) ═══
     *
     * «Si pagan la cuota de 3 lotes y tienen nombre de recibo distinto se
     * imprimen 3 recibos con la cuota de su lote. Si son 3 lotes sin nombre de
     * recibo se imprime uno solo. Y así sucesivamente, y en abono de capital
     * también».
     *
     * Es la respuesta correcta y es mejor que la que yo habia puesto el dia
     * anterior, que era RECHAZAR el cobro mezclado. Rechazarlo le pasaba el
     * trabajo a quien esta en ventanilla —«volvé a entrar tres veces»— cuando
     * el sistema tiene toda la informacion para partirlo solo.
     *
     * ═══ 🔴 LOS PAPELES SE EMITEN TODOS O NINGUNO ═══
     *
     * La transaccion abarca los tres. Cada uno quema su numero de la serie
     * unica (R12), y quedarse a mitad —dos recibos emitidos y el tercero
     * caido— dejaria plata cobrada sin comprobante y un hueco en el
     * correlativo. Con un solo grupo no se abre nada: el trabajador ya trae su
     * propia transaccion.
     *
     * @template TRenglon of array{lote: Compromiso}
     *
     * @param list<TRenglon> $renglones
     * @param callable(list<TRenglon>): Recibo $emitir
     *
     * @return list<Recibo>
     */
    private function porCadaNombre(array $renglones, callable $emitir): array
    {
        $grupos = $this->agruparPorNombre($renglones);

        // Con cero grupos —la lista vino vacia— tambien pasa por aca: el
        // trabajador es quien tiene el mensaje de «no elegiste ningun lote».
        if (count($grupos) <= 1) {
            return [$emitir($renglones)];
        }

        return DB::transaction(fn (): array => array_values(array_map($emitir, $grupos)));
    }

    /**
     * Los renglones repartidos por el titular de recibo de su lote.
     *
     * La clave es el NOMBRE, y la cadena vacia representa «el dueño del
     * expediente» — que tambien es un nombre distinto de «Jose». Un lote
     * configurado y otro sin configurar son dos papeles, no uno.
     *
     * @template TRenglon of array{lote: Compromiso}
     *
     * @param list<TRenglon> $renglones
     *
     * @return array<string, list<TRenglon>>
     */
    private function agruparPorNombre(array $renglones): array
    {
        $grupos = [];

        foreach ($renglones as $renglon) {
            $grupos[$renglon['lote']->titularDelRecibo() ?? ''][] = $renglon;
        }

        return $grupos;
    }

    /**
     * El unico recibo de un cobro de UN solo lote.
     *
     * `cobrarCuotas()` y `abonarACapital()` trabajan sobre un lote, asi que su
     * reparto por nombre siempre da un grupo. Devuelven `Recibo` y no una lista
     * porque su llamador pidio un papel, no un conjunto.
     *
     * @param list<Recibo> $recibos
     *
     * @throws PagoInvalidoException
     */
    private function unSoloRecibo(array $recibos): Recibo
    {
        $recibo = $recibos[0] ?? null;

        if (! $recibo instanceof Recibo) {
            throw PagoInvalidoException::porNoElegirNingunLote();
        }

        return $recibo;
    }

    /**
     * A nombre de quien sale este papel, mirando los lotes que cubre.
     *
     * ═══ POR QUE ES DEL LOTE Y NO DEL CONTRATO ═══
     *
     * Lo pidio asi Mauricio (12-ago-2026): «si son 3 lotes debe decidir a
     * nombre de quien sale el recibo de ESE lote; si no colocan ningun nombre,
     * sale a nombre del dueño del expediente». Un grupo compra junto, firma UNA
     * sola persona, y cada representado tiene su lote adentro del contrato.
     *
     * ⚠️ Para cuando esto corre, TODOS los lotes del papel comparten titular:
     * `porCadaNombre()` ya los separo. Por eso alcanza con mirar el primero —y
     * por eso no hay ninguna decision que tomar aca sobre a quien se le niega
     * el comprobante.
     *
     * @param list<Compromiso> $lotes
     *
     * @return array{nombre: ?string, dni: ?string}
     */
    private function aNombreDeQuien(array $lotes): array
    {
        $lote = $lotes[0] ?? null;

        if (! $lote instanceof Compromiso) {
            return ['nombre' => null, 'dni' => null];
        }

        return [
            'nombre' => $lote->titularDelRecibo(),
            'dni'    => $lote->dniDelTitularDelRecibo(),
        ];
    }

    /**
     * La mora cobrada y la perdonada, congeladas en el papel.
     *
     * Se escribe despues de repartir porque hasta ese momento no se sabe
     * cuanta mora alcanzo a cubrir el dinero que entro: si el cliente trajo
     * menos de lo que debe, la mora se cobra a medias y el recibo tiene que
     * decir cuanta, no cuanta se habia calculado.
     */
    private function asentarLaMora(Recibo $recibo, Monto $cobrada, Monto $perdonada, string $motivo): void
    {
        if ($cobrada->esCero() && $perdonada->esCero()) {
            return;
        }

        $recibo->update([
            'monto_mora'         => $cobrada->redondeado(),
            'mora_condonada'     => $perdonada->redondeado(),
            'motivo_condonacion' => $perdonada->esCero() ? null : $motivo,
            'condonada_por'      => $perdonada->esCero() ? null : auth()->id(),
        ]);
    }

    /**
     * Anular el recibo devuelve tambien la mora que perdonó.
     *
     * ⚠️ Se descuenta lo que perdonó ESTE recibo, renglón por renglón, y no se
     * pone la columna en cero: una cuota puede arrastrar condonaciones de dos
     * recibos distintos, y borrarlas todas volvería a cobrar una mora que ya
     * alguien perdonó por escrito. Por eso `aplicaciones_de_pago` guarda
     * `mora_condonada` propia, fuera de `monto`.
     */
    private function revertirLaCondonacion(Recibo $recibo): void
    {
        foreach ($recibo->aplicaciones()->with('cuota')->get() as $aplicacion) {
            $perdonada = $aplicacion->moraCondonada();

            if ($perdonada->esCero()) {
                continue;
            }

            $cuota = $aplicacion->cuota;

            if (! $cuota instanceof Cuota) {
                continue;
            }

            $cuota->update([
                'mora_condonada' => $cuota->moraCondonada()->restar($perdonada)->redondeado(),
            ]);
        }
    }

    /**
     * El reparto: mora → interés → capital, cuota por cuota, FIFO.
     *
     * ═══ POR QUE ESTE ORDEN, ESCRITO ACA Y NO EN EL CONTRATO SOLAMENTE ═══
     *
     * Con capital primero, un cliente atrasado ve bajar su deuda pero la mora
     * sigue corriendo sobre lo que no pagó y nunca termina. Con mora primero,
     * el atraso se limpia y el capital vuelve a bajar. Es la imputación que
     * usa cualquier crédito serio, y es la que hay que escribir en el contrato
     * con todas las letras.
     *
     * Con tasa 0 y sin mora, los dos primeros pasos valen cero y esto es
     * exactamente el FIFO a capital de siempre.
     *
     * ═══ CONDONAR ALCANZA A LAS CUOTAS QUE EL PAGO TOCA ═══
     *
     * Perdonar la mora de una cuota a la que el dinero nunca llegó seria
     * perdonar en el aire: no habria renglon donde anotarlo y anular el recibo
     * no podria deshacerlo. Se condona la mora de las cuotas que este pago
     * efectivamente alcanza, que es ademas lo que pasa en el mostrador — el
     * cliente viene a pagar la cuota y se le perdona SU mora.
     *
     * @param Collection<int, Cuota> $pendientes
     *
     * @return array{cobrada: Monto, condonada: Monto}
     */
    private function repartir(
        Recibo $recibo,
        mixed $pendientes,
        Monto $monto,
        ?MoraDelLote $mora = null,
        bool $condonar = false,
    ): array {
        $porRepartir = $monto;
        $moraCobrada = Monto::cero();
        $moraPerdonada = Monto::cero();

        foreach ($pendientes as $cuota) {
            if ($porRepartir->esCero()) {
                break;
            }

            // 1. La mora de ESTA cuota: se cobra, o se perdona entera.
            $deMora = $mora instanceof MoraDelLote ? $mora->deLaCuota($cuota) : Monto::cero();

            $aMora = $condonar
                ? Monto::cero()
                : ($porRepartir->mayorQue($deMora) ? $deMora : $porRepartir);

            $perdonada = $condonar ? $deMora : Monto::cero();

            $porRepartir = $porRepartir->restar($aMora);

            // 2 y 3. Lo que le falta a la cuota, interés antes que capital.
            $falta = $cuota->saldo();
            $aLaCuota = $porRepartir->mayorQue($falta) ? $falta : $porRepartir;

            $interesPendiente = $cuota->interesPendiente();
            $aInteres = $aLaCuota->mayorQue($interesPendiente) ? $interesPendiente : $aLaCuota;
            $aCapital = $aLaCuota->restar($aInteres);

            $total = $aMora->sumar($aLaCuota);

            /*
             * El CHECK `aplicaciones_monto_positivo_chk` no admite renglones
             * de L 0.00. Puede pasar si la mora de esta cuota es cero y el
             * pago ya se agotó: se corta y no se escribe un renglón vacío.
             */
            if ($total->esCero()) {
                continue;
            }

            $recibo->aplicaciones()->create([
                'cuota_id'      => $cuota->getKey(),
                'monto'         => $total->redondeado(),
                'monto_mora'    => $aMora->redondeado(),
                'monto_interes' => $aInteres->redondeado(),
                'monto_capital' => $aCapital->redondeado(),
                /*
                 * Fuera de `monto` a proposito: lo condonado no es dinero que
                 * entro. Va en el renglon igual porque es lo unico que le
                 * permite a `anular()` deshacer el perdon de ESTE recibo sin
                 * borrar el de otro.
                 */
                'mora_condonada' => $perdonada->redondeado(),
            ]);

            /*
             * `monto_pagado` es la suma de sus aplicaciones, SIN la mora. Se
             * guarda igual y no se deriva en cada lectura: el estado de cuenta
             * lo consulta lote por lote y hacerlo con un JOIN por cuota es
             * pagar una consulta cara por un número que no cambia solo.
             */
            $cuota->update([
                'monto_pagado'   => $cuota->montoPagado()->sumar($aLaCuota)->redondeado(),
                'mora_pagada'    => $cuota->moraPagada()->sumar($aMora)->redondeado(),
                'mora_condonada' => $cuota->moraCondonada()->sumar($perdonada)->redondeado(),
            ]);

            $moraCobrada = $moraCobrada->sumar($aMora);
            $moraPerdonada = $moraPerdonada->sumar($perdonada);
            $porRepartir = $porRepartir->restar($aLaCuota);
        }

        return ['cobrada' => $moraCobrada, 'condonada' => $moraPerdonada];
    }

    /**
     * Borra las cuotas que nadie tocó y escribe el plan nuevo (R21).
     *
     * ═══ POR QUE SE PUEDE BORRAR SIN MIEDO ═══
     *
     * Solo se borran las que tienen `monto_pagado = 0`, y una cuota sin nada
     * pagado no tiene aplicaciones de pago —el CHECK
     * `aplicaciones_monto_positivo_chk` no admite renglones de L 0.00—. Por eso
     * el `restrictOnDelete` de `aplicaciones_de_pago.cuota_id` nunca se
     * dispara acá: es la red por si algún día esta invariante se rompe, no un
     * obstáculo que haya que esquivar.
     *
     * ⚠️ Con interés, cada cuota nueva se escribe con su capital y su interés
     * ya separados: el CHECK `cuotas_partes_suman_el_monto_chk` no deja pasar
     * una fila que no cuadre, ni siquiera en un insert masivo.
     */
    private function reescribirElPlan(Venta $venta, Compromiso $lote, EfectoDelAbono $efecto, PlanDeCuotas $plan): void
    {
        Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->whereIn('numero', $efecto->numerosReemplazados)
            ->delete();

        if ($plan->cuotas === []) {
            // El abono canceló lo que quedaba: no hay cuotas nuevas y no queda
            // ninguna de L 0.00 colgando (R3).
            return;
        }

        $ahora = now();
        $filas = [];

        foreach ($plan->cuotas as $cuota) {
            $filas[] = [
                'venta_id'          => $venta->getKey(),
                'compromiso_id'     => $lote->getKey(),
                'numero'            => $cuota->numero,
                'fecha_vencimiento' => $cuota->vencimientoParaBase(),
                'monto'             => $cuota->montoParaBase(),
                'monto_capital'     => $cuota->capitalParaBase(),
                'monto_interes'     => $cuota->interesParaBase(),
                'monto_pagado'      => '0.00',
                'mora_pagada'       => '0.00',
                'mora_condonada'    => '0.00',
                'created_at'        => $ahora,
                'updated_at'        => $ahora,
            ];
        }

        Cuota::query()->insert($filas);
    }

    /**
     * La fila que contesta «¿por qué mi cuota cambió?».
     */
    private function asentarLaConstancia(
        Venta $venta,
        Compromiso $lote,
        Recibo $recibo,
        EfectoDelAbono $efecto,
        PlanDeCuotas $plan,
        string $motivo,
    ): void {
        Reprogramacion::query()->create([
            'venta_id'      => $venta->getKey(),
            'compromiso_id' => $lote->getKey(),
            'recibo_id'     => $recibo->getKey(),
            'modalidad'     => $efecto->modalidad,
            'motivo'        => $motivo,
            /*
             * Solo lo que bajó el capital. Lo que el mismo recibo usó para
             * poner al día no reprogramó nada, y meterlo acá rompería el CHECK
             * `reprogramaciones_saldo_cuadra_chk` — que es justamente para lo
             * que está.
             */
            'abono_capital'  => $efecto->aCapital->redondeado(),
            'saldo_anterior' => $efecto->saldoReprogramable->redondeado(),
            'saldo_nuevo'    => $efecto->saldoNuevo->redondeado(),
            'cuota_anterior' => $efecto->cuotaVigente?->redondeado(),
            'cuota_nueva'    => $plan->cuotaMensual()?->redondeado(),
            'cuotas_antes'   => count($efecto->numerosReemplazados),
            'cuotas_despues' => $plan->count(),
            'desde_numero'   => $efecto->desdeNumero,
            'plan_anterior'  => $efecto->planAnterior,
        ]);
    }

    /**
     * El resumen del expediente, recalculado desde las cuotas.
     *
     * `plazo_meses` es el HORIZONTE del contrato y `cuota_mensual` lo que se
     * paga el PRIMER mes: los dos son un resumen de `cuotas`, que es el
     * contrato. Acortar el plazo de un lote baja el horizonte, y dejarlo viejo
     * haría que la lista de ventas diga 48 meses cuando ya son 40.
     *
     * El `reorder()` no hace falta acá porque `Cuota::query()` no arrastra el
     * `orderBy` de la relación; el 42803 de Postgres aparece cuando se agrega
     * sobre `$venta->cuotas()`, como documenta `Venta::saldoPendiente()`.
     */
    private function recalcularElResumen(Venta $venta): void
    {
        /** @var string|int|null $horizonte */
        $horizonte = Cuota::query()
            ->where('venta_id', $venta->getKey())
            ->selectRaw('COALESCE(MAX(numero), 0) AS horizonte')
            ->value('horizonte');

        /** @var string|int|null $primera */
        $primera = Cuota::query()
            ->where('venta_id', $venta->getKey())
            ->where('numero', 1)
            ->selectRaw('COALESCE(SUM(monto), 0) AS total')
            ->value('total');

        $cuotaMensual = new Monto(is_string($primera) || is_int($primera) ? $primera : '0');

        $venta->update([
            'plazo_meses'   => is_string($horizonte) || is_int($horizonte) ? (int) $horizonte : 0,
            'cuota_mensual' => $cuotaMensual->esCero() ? null : $cuotaMensual->redondeado(),
        ]);
    }

    /**
     * El día de pago del contrato, sin inventar un default.
     *
     * Si viniera vacío, `PlanDeCuotas` lo rechaza con su propio mensaje y el
     * abono se cae explicando por qué. Poner un 1 «por si acaso» sería
     * mover todos los vencimientos de un contrato sin que nadie lo pida.
     */
    private function diaDePago(Venta $venta): int
    {
        $dia = $venta->getAttribute('dia_pago');

        if (is_int($dia)) {
            return $dia;
        }

        return is_string($dia) ? (int) $dia : 0;
    }

    private function codigo(Compromiso $lote): string
    {
        return (string) $lote->lote()->value('codigo');
    }
}
