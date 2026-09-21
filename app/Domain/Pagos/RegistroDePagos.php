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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        private ?int $recibidoPor = null,
    ) {}

    /**
     * ═══ QUIEN RECIBIO EL DINERO, CUANDO NO ES QUIEN TECLEA ═══
     *
     * «Que la administradora y yo podamos seleccionar quien recibio el dinero,
     * y tambien los receptores» — Mauricio, 27-ago-2026. La administradora
     * registra un pago que recibio don Elder en la caseta: el billete lo tiene
     * el, y el arqueo del dia es de quien lo tiene en la mano.
     *
     * Se lee asi en el llamador, que es donde tiene que leerse:
     *
     *     $pagos->loRecibio($id)->cobrarVariosLotes(...)
     *
     * ═══ POR QUE ASI Y NO UN PARAMETRO MAS ═══
     *
     * Meterlo como parametro obligaria a atravesar SEIS metodos publicos,
     * cuatro privados y sus closures —el molde exacto del
     * `Undefined variable` que ya mordio dos veces— para un dato que no
     * participa de ningun calculo: solo se escribe en la fila.
     *
     * Y el modo de fallar es el correcto: **un camino que se olvide de
     * llamarlo escribe `auth()->id()`**, que es exactamente lo que el sistema
     * hacia hasta hoy. Un parametro olvidado tambien caeria ahi, pero costando
     * veinte ediciones en el archivo del dinero.
     *
     * La clase es `readonly`: esto devuelve una instancia NUEVA y no muta
     * nada, asi que dos cobros en la misma peticion no se pisan.
     */
    public function loRecibio(?int $usuario): self
    {
        return new self($this->correlativos, $this->facturas, $usuario);
    }

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
        bool $deLaCarteraVieja = false,
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
                $deLaCarteraVieja,
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
        bool $deLaCarteraVieja = false,
    ): Recibo {
        if ($renglones === []) {
            throw PagoInvalidoException::porNoElegirNingunLote();
        }

        $vistos = [];

        foreach ($renglones as $renglon) {
            $this->verificar($venta, $renglon['lote'], $renglon['monto']);

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
            $porQueSePerdona,
            $deLaCarteraVieja
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
                $deLaCarteraVieja,
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
     * ⚠️ Lo que ese recibo PERDONO tambien se revierte —la mora condonada y
     * el capital condonado de un pronto pago—: si el cobro no debio
     * registrarse, el perdon que venia con el tampoco.
     *
     * ═══ QUE NO HACE ═══
     *
     * No devuelve dinero. Anular dice que el cobro no debió registrarse, no
     * que haya que sacar plata de la caja — eso es un egreso, y no existe
     * todavía. Si el cliente sí pagó y el error fue el monto, el camino es
     * anular y volver a cobrar con el número nuevo.
     *
     * ═══ CUOTA Y ABONO A CAPITAL — LA PRIMA Y LA SEÑA NO ═══
     *
     * El abono a capital entró el 11-sep-2026, y con él el pronto pago, que
     * comparte concepto. Los dos que siguen afuera lo están por la misma
     * razón: una prima consumió el correlativo de un contrato y una seña dejó
     * un lote apartado, así que revertirlas es deshacer la venta o el
     * apartado del que salieron —otro trámite, con otras consecuencias—, no
     * devolverle saldo a una cuota.
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

        /*
         * 🔴 EL ABONO A CAPITAL YA SE ANULA — 11-sep-2026.
         *
         * «Ocurrió lo que temíamos: se equivocó y era de otra manera el hacer
         * los pagos de cuota o abono a capital (…) muy seguramente volverá a
         * pasar» — Mauricio.
         *
         * Hasta hoy se rechazaba porque ese recibo reescribió el plan del lote
         * y devolverle sus cuotas «todavía no está construido». Ya lo está:
         * `deshacerLasReprogramaciones()`.
         *
         * La prima y la seña siguen afuera, y eso NO cambió: revertirlas es
         * deshacer la venta o el apartado del que salieron, que es otro
         * trámite con otras consecuencias.
         */
        if ($concepto !== ConceptoDeRecibo::Cuota && $concepto !== ConceptoDeRecibo::AbonoCapital) {
            throw PagoInvalidoException::porConceptoQueNoSeAnulaAsi(
                $concepto instanceof ConceptoDeRecibo ? $concepto->etiqueta() : 'otro concepto',
                $recibo->folio(),
            );
        }

        /*
         * 🔴🔴 EL PRONTO PAGO YA SE ANULA — 11-sep-2026, la tarde.
         *
         * Un pronto pago se emite como `AbonoCapital` —dio por terminado un
         * plan— así que al abrir el abono, en la mañana, este quedó abierto
         * también sin querer. Lo agarró `ProntoPagoTest` en la primera
         * corrida y se cerró con una puerta que miraba `tuvoDescuento()`: un
         * abono mueve dinero que entró, y un pronto pago ADEMÁS perdona
         * saldo, y lo que `anular()` sabía devolver era la mora condonada, no
         * el capital perdonado.
         *
         * Esa puerta ya no está, porque lo que faltaba ya existe: el bucle de
         * abajo devuelve el capital condonado junto con el dinero. Era el
         * ÚNICO movimiento sin vuelta atrás que quedaba en el sistema, y el
         * pronto pago es justo el que más caro sale mal — da por terminado un
         * plan entero de una sola vez.
         *
         * ⚠️ Y NO reprograma nada: `saldarConDescuento()` no borra ni crea
         * cuotas, deja las que había en cero. Por eso
         * `deshacerLasReprogramaciones()` no encuentra constancia y no hace
         * nada — anular un pronto pago es más simple que anular un abono, no
         * más complicado.
         */

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

            /*
             * 🔴 EL PLAN PRIMERO, LAS APLICACIONES DESPUES.
             *
             * Deshacer la reprogramación borra las cuotas que ese abono creó y
             * devuelve las que borró. Las aplicaciones del MISMO recibo apuntan
             * a cuotas anteriores —las vencidas que puso al día; una cuota con
             * pago no es reemplazable, por el tope de `EfectoDelAbono`—, así
             * que ninguna de las dos pisa a la otra. El orden igual importa:
             * si algo estorba, se descubre antes de haber tocado un centavo.
             */
            $this->deshacerLasReprogramaciones($vivo);

            /*
             * Lo que este papel había PERDONADO y acaba de volver a deberse.
             * Se acumula del mismo bucle que lo devuelve, así el asiento de la
             * bitácora dice el número exacto que se revirtió y no uno
             * recalculado aparte que podría discrepar.
             */
            $devuelto = Monto::cero();

            foreach ($vivo->aplicaciones()->with('cuota')->get() as $aplicacion) {
                $cuota = $aplicacion->cuota;

                if (! $cuota instanceof Cuota) {
                    continue;
                }

                /*
                 * `monto_pagado` recibe capital + interes, nunca la mora: la
                 * mora nunca entro ahi, asi que devolverla la dejaria
                 * debiendo de menos. Va a su propia columna.
                 *
                 * ➕ EL PERDON TAMBIEN SE DEVUELVE — 11-sep-2026.
                 *
                 * `saldarConDescuento()` sube `monto_pagado` hasta el total de
                 * la cuota: el dinero MÁS lo condonado. Restar solo el dinero
                 * dejaba la cuota diciendo que todavía tenía pagado el
                 * descuento, y el lote se quedaba con la rebaja sin el recibo
                 * que la explicaba. En un recibo normal `capitalCondonado()`
                 * es cero y esto no cambia nada.
                 */
                $aLaCuota = $aplicacion->montoCapital()->sumar($aplicacion->montoInteres());
                $perdonado = $aplicacion->capitalCondonado();

                /*
                 * 🔴 LAS DOS COLUMNAS EN EL MISMO `UPDATE`, Y NO ES ESTILO.
                 *
                 * `cuotas_condonado_cabe_en_lo_pagado_chk` exige
                 * `capital_condonado <= monto_pagado`, y un CHECK de Postgres
                 * se evalúa por sentencia. Bajar `monto_pagado` en una y
                 * `capital_condonado` en otra deja un instante donde el
                 * perdon es mayor que lo pagado: la primera sentencia revienta
                 * y la anulación entera se cae. Van juntas, o no van.
                 *
                 * Por eso esto NO sigue el molde de `revertirLaCondonacion()`,
                 * que sí es un método aparte: `mora_condonada` no tiene CHECK
                 * cruzado contra `mora_pagada`.
                 */
                $cuota->update([
                    'monto_pagado'      => $cuota->montoPagado()->restar($aLaCuota)->restar($perdonado)->redondeado(),
                    'mora_pagada'       => $cuota->moraPagada()->restar($aplicacion->montoMora())->redondeado(),
                    'capital_condonado' => $cuota->capitalCondonado()->restar($perdonado)->redondeado(),
                ]);

                $devuelto = $devuelto->sumar($perdonado);
            }

            $this->revertirLaCondonacion($vivo);

            $vivo->update([
                'anulado_el'       => now(),
                'anulado_por'      => auth()->id(),
                'motivo_anulacion' => $porQue,
            ]);

            $this->reabrirSiVolvioADeber($vivo);
            $this->asentarQueVolvioElDescuento($vivo, $devuelto, $porQue);

            /*
             * El horizonte y la cuota del contrato son un resumen de `cuotas`,
             * y las cuotas acaban de cambiar: sin esto la lista de ventas
             * seguiría diciendo el plazo que dejó el abono anulado.
             */
            $venta = $vivo->venta;

            if ($venta instanceof Venta) {
                $this->recalcularElResumen($venta);
            }

            return $vivo;
        });
    }

    /**
     * 🔴 Anular TODOS los papeles de un mismo cobro — 11-sep-2026.
     *
     * «Cuando tiene más de un titular de recibo y a cada uno se le hizo un
     * abono o pago de cuota y generó varios recibos, ¿cómo se maneja eso?»
     * —Mauricio—.
     *
     * Un cobro sale en un recibo por titular, así que el contrato con cuatro
     * representados emite cuatro papeles de un solo pago. Se podían anular de a
     * uno —cada papel toca sus propios lotes, así que no se estorban entre sí—
     * y ese es justamente el problema: cuatro veces el mismo trámite, cuatro
     * veces el motivo, y quien anula tres y se olvida del cuarto deja el
     * expediente a medias sin que nadie se entere hasta que no cuadra el mes.
     *
     * ═══ 🔴 TODOS O NINGUNO ═══
     *
     * Una sola transacción. Si uno de los cuatro no se puede anular —porque
     * después entró un cobro sobre su lote— se cae la operación entera y no se
     * anula ninguno. Media anulación es peor que ninguna: deja el contrato en
     * un estado que no es ni el de antes ni el de después, y que nadie pidió.
     *
     * El mensaje del que falló es el que sale, con sus folios: dice qué anular
     * primero, que es lo único accionable.
     *
     * ⚠️ Del más NUEVO al más viejo. Entre hermanos no hace falta —cada uno
     * tiene sus lotes— pero el orden es el mismo que manda la regla general, y
     * tener dos órdenes distintos según el caso es cómo se aprende mal una
     * regla.
     *
     * @return list<Recibo> los papeles anulados, en el orden en que se emitieron
     *
     * @throws PagoInvalidoException
     */
    public function anularElCobro(Recibo $recibo, string $motivo): array
    {
        $delCobro = [$recibo, ...$recibo->hermanosDeEmision()->all()];

        usort(
            $delCobro,
            static fn (Recibo $uno, Recibo $otro): int => (int) $otro->getKey() <=> (int) $uno->getKey(),
        );

        return DB::transaction(function () use ($delCobro, $motivo): array {
            $anulados = [];

            foreach ($delCobro as $papel) {
                $anulados[] = $this->anular($papel, $motivo);
            }

            return array_reverse($anulados);
        });
    }

    /**
     * 🔴 Devolverle al lote las cuotas que el abono le borró — 11-sep-2026.
     *
     * ═══ SE PUEDE PORQUE EL PLAN VIEJO ESTA GUARDADO ═══
     *
     * Cada reprogramación conserva en `plan_anterior` las cuotas que reemplazó
     * —número, vencimiento y monto— y en `desde_numero` desde dónde reescribió.
     * Eso alcanza para reconstruir el plan exactamente como estaba: se borran
     * las cuotas que el abono creó y se vuelven a escribir las que borró.
     *
     * ═══ SOLO SI ES EL ULTIMO MOVIMIENTO DEL LOTE ═══
     *
     * El porqué largo está en `porMovimientosPosterioresAlAbono()`. Acá alcanza
     * con la regla: se deshace de atrás para adelante, como un libro contable.
     *
     * ⚠️ Y esa regla es SOLO del abono, a propósito. Anular un recibo de CUOTA
     * no exige nada parecido: no devuelve ningún plan, devuelve lo pagado a
     * cuotas que siguen existiendo —una cuota con pago NUNCA se reemplaza, por
     * el tope de `EfectoDelAbono`— así que un abono posterior no la estorba y
     * el saldo del lote queda coherente.
     *
     * Se descubrió probando: el primer intento de test puso un abono después de
     * un cobro de cuotas esperando que lo bloqueara, y no lo bloqueó. Queda
     * escrito para que nadie «arregle» esa asimetría creyéndola un olvido.
     *
     * ⚠️ LA CONSTANCIA SE BORRA, y es la única cosa de este repo que se borra
     * en vez de marcarse. La regla de `Reprogramacion` —«es historia, no se
     * edita ni se borra»— vale para una reprogramación que OCURRIO. Esta no
     * ocurrió: el plan volvió a ser el de antes, y una constancia que siga
     * diciendo «tu cuota cambió por este abono» le mentiría al estado de
     * cuenta, que se reconstruye leyendo justamente estas filas.
     *
     * Lo que pasó no se pierde: queda el recibo anulado, con su motivo, quién
     * lo anuló y cuándo — que es donde se busca «¿qué pasó con este número?».
     *
     * @throws PagoInvalidoException
     */
    private function deshacerLasReprogramaciones(Recibo $recibo): void
    {
        $constancias = $recibo->reprogramaciones()->with('compromiso')->get();

        foreach ($constancias as $constancia) {
            $lote = $constancia->compromiso;

            if (! $lote instanceof Compromiso) {
                continue;
            }

            $plan = $constancia->planAnterior();

            if ($plan === []) {
                // No reemplazó ninguna cuota: no hay plan que devolver. Pasa
                // cuando el abono canceló lo que quedaba del lote y no quedó
                // ninguna cuota pendiente que reescribir.
                $constancia->delete();

                continue;
            }

            $this->comprobarQueSePuedeDeshacer($recibo, $constancia, $lote);

            $desde = (int) $constancia->getAttribute('desde_numero');

            $this->reescribirElPlanViejo($lote, $plan, $desde);

            $constancia->delete();
        }
    }

    /**
     * Las dos cosas que impiden devolver el plan, dichas con nombre y apellido.
     *
     * ⚠️ Se preguntan ANTES de borrar una sola cuota. Con la transacción
     * alcanzaría para no dejar el lote a medias, pero el mensaje tiene que
     * poder decir QUE estorba, y para eso hay que mirarlo antes de tocarlo.
     *
     * @throws PagoInvalidoException
     */
    private function comprobarQueSePuedeDeshacer(
        Recibo $recibo,
        Reprogramacion $constancia,
        Compromiso $lote,
    ): void {
        /*
         * Con interés, `plan_anterior` no alcanza: guarda el monto de cada
         * cuota pero no cuánto era capital y cuánto interés. Ver
         * `porPlanViejoSinDesgloseDeInteres()`. En Praderas nunca pasa (R1).
         */
        if (! $lote->tasaDeInteres()->esCero()) {
            throw PagoInvalidoException::porPlanViejoSinDesgloseDeInteres($recibo->folio());
        }

        $folios = $this->loQueVinoDespues($recibo, $constancia, $lote);

        if ($folios !== []) {
            throw PagoInvalidoException::porMovimientosPosterioresAlAbono($recibo->folio(), $folios);
        }
    }

    /**
     * Los recibos que tocaron este lote DESPUES de este abono.
     *
     * Son de dos clases y las dos estorban por la misma razón —el plan viejo
     * no tiene dónde ponerlos—:
     *
     * 1. Otra reprogramación posterior: su `plan_anterior` es el plan que este
     *    abono escribió, y devolverle a este el suyo lo dejaría apuntando a
     *    cuotas que dejan de existir.
     * 2. Cualquier cobro sobre las cuotas que este abono creó. Nacieron en
     *    cero, así que un solo centavo ahí entró después.
     *
     * Se devuelven del más NUEVO al más viejo, que es el orden en que hay que
     * anularlos.
     *
     * @return list<string>
     */
    private function loQueVinoDespues(Recibo $recibo, Reprogramacion $constancia, Compromiso $lote): array
    {
        $folios = [];

        $posteriores = Reprogramacion::query()
            ->where('compromiso_id', $lote->getKey())
            ->where('id', '>', $constancia->getKey())
            ->with('recibo')
            ->orderByDesc('id')
            ->get();

        foreach ($posteriores as $otra) {
            $suRecibo = $otra->recibo;

            if ($suRecibo instanceof Recibo && ! $suRecibo->estaAnulado()) {
                $folios[] = $suRecibo->folio();
            }
        }

        $cobrados = Recibo::query()
            ->whereNull('anulado_el')
            ->whereKeyNot($recibo->getKey())
            ->whereHas('aplicaciones.cuota', static function (Builder $consulta) use ($lote, $constancia): void {
                $consulta
                    ->where('compromiso_id', $lote->getKey())
                    ->where('numero', '>=', (int) $constancia->getAttribute('desde_numero'));
            })
            ->orderByDesc('id')
            ->get();

        foreach ($cobrados as $otro) {
            $folios[] = $otro->folio();
        }

        return array_values(array_unique($folios));
    }

    /**
     * Las cuotas viejas, escritas de vuelta tal como estaban.
     *
     * Todo el monto va a capital porque el lote no lleva interés —lo comprueba
     * `comprobarQueSePuedeDeshacer()` antes de llegar acá—, así que no hay nada
     * que repartir y no se inventa nada.
     *
     * Nacen sin un centavo pagado, y tiene que ser así: lo que este recibo
     * había aplicado se devuelve aparte, cuota por cuota, en `anular()`.
     *
     * ═══ 🔴 SE PISAN EN EL LUGAR, NO SE BORRAN — 21-sep-2026 ═══
     *
     * Hasta hoy esto era «borrar las cuotas del plan nuevo y escribir las
     * viejas». Reventó en pruebas con un error crudo de llave foránea: sobre
     * una cuota del plan nuevo se había hecho un cobro que DESPUES SE ANULO.
     * `loQueVinoDespues()` hace bien en no contarlo —un recibo anulado no
     * estorba—, pero su aplicación se conserva como traza (ver `anular()`) y
     * sigue apuntando a esa cuota: Postgres no deja borrarla.
     *
     * La cuota que existe en los dos planes —mismo lote, mismo número— se
     * actualiza con los datos del plan viejo y conserva su id, así que la traza
     * del recibo anulado sigue apuntando a una cuota que existe. Solo se
     * insertan las que el abono había quitado del final, y solo se borran las
     * que el plan viejo no tenía.
     *
     * Es seguro pisarlas porque ninguna tiene un centavo vivo: si lo tuviera,
     * `comprobarQueSePuedeDeshacer()` ya habría frenado todo.
     *
     * ⚠️ Por el constructor de consultas y no por el modelo: son decenas de
     * cuotas, y cada `save()` dejaría un asiento en la bitácora por un cambio
     * que ya está contado en la anulación del recibo.
     *
     * @param list<array{numero: int, vence: string, monto: string}> $plan
     */
    private function reescribirElPlanViejo(Compromiso $lote, array $plan, int $desde): void
    {
        $ahora = now();

        /** @var array<int, int> $existentes numero → id */
        $existentes = Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->where('numero', '>=', $desde)
            ->pluck('id', 'numero')
            ->all();

        $filas = [];
        $conservadas = [];

        foreach ($plan as $cuota) {
            $datos = [
                'fecha_vencimiento' => $cuota['vence'],
                'monto'             => $cuota['monto'],
                'monto_capital'     => $cuota['monto'],
                'monto_interes'     => '0.00',
                'monto_pagado'      => '0.00',
                'mora_pagada'       => '0.00',
                'mora_condonada'    => '0.00',
            ];

            $id = $existentes[$cuota['numero']] ?? null;

            if ($id !== null) {
                // Todas las columnas en UNA sentencia: los CHECK de `cuotas`
                // se evalúan por sentencia (monto = capital + interés).
                Cuota::query()->whereKey($id)->update($datos);
                $conservadas[] = $id;

                continue;
            }

            $filas[] = [
                'venta_id'      => $lote->getAttribute('venta_id'),
                'compromiso_id' => $lote->getKey(),
                'numero'        => $cuota['numero'],
                ...$datos,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        // Las que el plan viejo no tenía. Con «acortar plazo» y con «bajar
        // cuota» no hay ninguna: el plan viejo nunca es más corto que el nuevo.
        Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->where('numero', '>=', $desde)
            ->whereNotIn('id', $conservadas)
            ->delete();

        if ($filas !== []) {
            Cuota::query()->insert($filas);
        }
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
     * ═══ 🔴 PIDE EL LOTE AL DIA (24-ago-2026) ═══
     *
     * Con una sola cuota vencida esto se rechaza. El porque completo esta en
     * `PagoInvalidoException::porCuotasVencidasAntesDelAbono()`.
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
     * ═══ 🔴 UN LOTE ATRASADO TUMBA EL RECIBO ENTERO (24-ago-2026) ═══
     *
     * «Que no pueda hacer abono a capital si tiene cuotas pendientes okey»
     * —Mauricio—. Si CUALQUIERA de los lotes marcados tiene una cuota vencida,
     * no se abona ninguno: un recibo se emite entero o no se emite, igual que
     * cuando un lote se pasa del tope.
     *
     * Hasta hoy ese lote se registraba como pago normal y los demas seguian su
     * camino. Se cambio a proposito: la mitad de un recibo que dice «abono» y
     * no abono nada es justamente el papel que esta regla vino a evitar. Lo que
     * hay que hacer con esa plata esta en el mensaje —«Cuota» o «Ambas»— y son
     * dos clics con el cliente enfrente, no un tramite.
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
        bool $deLaCarteraVieja = false,
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
                $deLaCarteraVieja,
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
        bool $deLaCarteraVieja = false,
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
            $this->verificar($venta, $renglon['lote'], $renglon['monto']);

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
            $total,
            $deLaCarteraVieja
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

                /*
                 * ═══ 🔴 UN LOTE ATRASADO NO RECIBE ABONO (24-ago-2026) ═══
                 *
                 * «Que no pueda hacer abono a capital si tiene cuotas
                 * pendientes okey» — Mauricio.
                 *
                 * Se verifica ACA, con las cuotas ya bloqueadas: leerlo antes de
                 * la transaccion es leer un estado que otra caja puede cambiar
                 * en el medio. Y antes de emitir, asi que el correlativo no se
                 * mueve.
                 *
                 * ⚠️ La cartera vieja NO pasa por esto: transcribe papel que ya
                 * existe —un abono de 2019 con cuotas atrasadas de 2019— y
                 * rechazarlo no lo desharia, solo dejaria el expediente
                 * incompleto. La regla es para el mostrador de hoy.
                 */
                $vencidas = $this->lasVencidas($pendientes);

                if (! $deLaCarteraVieja && $vencidas->isNotEmpty()) {
                    throw PagoInvalidoException::porCuotasVencidasAntesDelAbono(
                        $vencidas->count(),
                        $this->saldoDe($vencidas),
                        $this->codigo($lote),
                    );
                }

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
                $deLaCarteraVieja,
            );

            $moraCobrada = Monto::cero();

            foreach ($planificados as $planificado) {
                $efecto = $planificado['efecto'];

                /*
                 * No alcanzo ni para lo vencido: ESTE lote es un pago normal y
                 * no se reescribe ningun plan. Los demas siguen su camino.
                 *
                 * ⚠️ Desde el 24-ago-2026 esto SOLO lo alcanza la cartera vieja:
                 * con una cuota vencida la fase 1 ya rechazo el abono, y sin
                 * nada vencido `ponerAlDia` vale cero y cualquier monto lo
                 * supera. Se conserva porque el import lo sigue usando —papel de
                 * 2019 con cuotas atrasadas de 2019— y porque un `else` que no
                 * existe es la forma de que el dia que la regla se afloje esto
                 * escriba silenciosamente un plan que nadie pidio.
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
                $this->verificar($venta, $lote, $saldo);

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
     * Anular el pronto pago deja su propio asiento — 11-sep-2026.
     *
     * El descuento se asentó contra la VENTA, no contra el recibo, porque es
     * ahí donde alguien lo va a buscar dentro de dos años. Devolverlo tiene
     * que dejar rastro en el mismo lugar: sin esto, la pestaña
     * «Actualizaciones» seguiría diciendo que a este cliente se le
     * descontaron L X y nada diría que volvió a deberlos.
     *
     * Solo cuando hubo perdón. Un recibo de cuota corriente no ensucia la
     * bitácora con un asiento de descuento en cero, por lo mismo que
     * `asentarElDescuento()` no lo hace al emitir.
     */
    private function asentarQueVolvioElDescuento(Recibo $recibo, Monto $devuelto, string $motivo): void
    {
        if ($devuelto->esCero()) {
            return;
        }

        $venta = $recibo->venta;

        if (! $venta instanceof Venta) {
            return;
        }

        activity()
            ->performedOn($venta)
            ->causedBy(auth()->user())
            // `withChanges()` y no `withProperties()`: es lo que pinta la
            // pestaña. El porqué completo está en `asentarElDescuento()`.
            ->withChanges([
                'old'        => ['descuento por pronto pago' => $devuelto->formateado()],
                'attributes' => ['descuento por pronto pago' => '—'],
            ])
            ->withProperty('motivo', $motivo)
            ->withProperty('recibo', $recibo->folio())
            ->event('pronto_pago_anulado')
            ->log('Se anuló el pronto pago: el descuento volvió a deberse');
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
     *  - `$abonos` es a donde va el sobrante: un renglon por lote, con SU monto
     *    y SU modalidad.
     *
     * ═══ 🔴 EL SOBRANTE SE REPARTE — 8-SEP-2026 ═══
     *
     * Hasta hoy `$aCapital` era un solo monto contra UN lote elegido, y estaba
     * escrito aca que eso «no es una simplificacion pendiente». **Lo pidio la
     * duena**: con dos lotes en el mismo contrato, mandar todo el sobrante a
     * uno la obligaba a partir el pago en dos recibos para bajarle capital a
     * los dos.
     *
     * Es el mismo movimiento que hizo `abonarAVariosLotes()` el 10-ago, con el
     * mismo argumento y el mismo cuidado: **el sistema no reparte solo**. Los
     * montos y las modalidades llegan tecleados desde la pantalla —o repartidos
     * en partes iguales porque alguien lo pidio en la pantalla, con los numeros
     * a la vista antes de confirmar—, y un lote que no se marca no se toca.
     *
     * ⚠️ Con esto, este camino **tambien** necesita la enmienda R21-bis que ya
     * necesitaba `abonarAVariosLotes()`: la letra de R21 dice «un lote» y la
     * escribio la contratante. Esta anotado en `docs/dominio.md`.
     *
     * ═══ EL ORDEN NO ES NEGOCIABLE: PRIMERO SE COBRA, DESPUES SE ABONA ═══
     *
     * R21 no deja tocar una cuota pagada a medias, asi que un abono contra un
     * lote que tiene una se rechaza (`superaElTope`). Cobrando primero, esa
     * cuota queda saldada y el abono corre sobre cuotas que nadie toco — que es
     * justo el caso que este metodo existe para resolver.
     *
     * Por eso el efecto del abono se calcula **releyendo despues del cobro** y
     * no antes: cualquier otra cosa mediria un estado que ya no existe. Con
     * varios lotes se relee UNO POR UNO, cada uno despues del cobro.
     *
     * ═══ POR QUE EL SOBRANTE QUE NO ALCANZA SE RECHAZA ═══
     *
     * Si despues de cobrar las cuotas el sobrante no llega a bajar capital,
     * «Ambas» no cumplio lo que promete. Se rechaza con el numero que falta en
     * vez de registrar en silencio un abono que no abono: la pantalla sigue
     * abierta y quien atiende mueve ese dinero a las cuotas, que es un campo.
     *
     * 🔴 Repartido entre varios, eso se vuelve mas facil de encontrarse: un
     * sobrante que alcanzaba para UN lote puede no alcanzar partido en tres. Se
     * rechaza **entero** —ningun lote se abona a medias— y el mensaje nombra al
     * lote que no llego.
     *
     * @param list<array{lote: Compromiso, monto: Monto}> $cuotas los renglones que se cobran
     * @param list<array{lote: Compromiso, monto: Monto, modalidad: ModalidadDeReprogramacion}> $abonos a donde va el sobrante
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
        array $abonos,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia = null,
        ?CarbonImmutable $fecha = null,
        ?string $observaciones = null,
    ): array {
        if ($abonos === []) {
            throw PagoInvalidoException::porNoElegirNingunLote();
        }

        /*
         * El abono viaja con las cuotas de SU MISMO nombre: es plata del mismo
         * lote, y separarlo en otro papel seria partir en dos algo que la
         * persona entrego junta. Las cuotas de los otros nombres salen en sus
         * propios recibos, sin abono.
         *
         * Repartido entre varios lotes la regla no cambia — cambia cuantas
         * veces se aplica: cada nombre se lleva SUS cuotas y SUS abonos, en un
         * papel.
         */
        $grupos = $this->agruparPorNombre($cuotas);
        $porNombre = $this->agruparPorNombre($abonos);

        return DB::transaction(function () use (
            $grupos,
            $porNombre,
            $venta,
            $cliente,
            $motivo,
            $forma,
            $referencia,
            $fecha,
            $observaciones
        ): array {
            $recibos = [];

            foreach ($grupos as $nombre => $suyas) {
                $recibos[] = array_key_exists($nombre, $porNombre)
                    ? $this->cobrarYAbonarEnUnMismoNombre(
                        $venta,
                        $cliente,
                        $suyas,
                        $porNombre[$nombre],
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

            // Los abonos de un nombre que no tenia ninguna cuota marcada: se van
            // solos, en su propio papel y a su propio nombre.
            foreach ($porNombre as $nombre => $suyos) {
                if (array_key_exists($nombre, $grupos)) {
                    continue;
                }

                $recibos[] = $this->cobrarYAbonarEnUnMismoNombre(
                    $venta,
                    $cliente,
                    [],
                    $suyos,
                    $motivo,
                    $forma,
                    $referencia,
                    $fecha,
                    $observaciones,
                );
            }

            return $this->marcarLaEmision($recibos);
        });
    }

    /**
     * Las cuotas y los abonos de un mismo titular de recibo: UN solo papel.
     *
     * Es el cuerpo de siempre; lo que cambio el 13-ago-2026 es quien lo llama,
     * y el 8-sep-2026 que el sobrante puede ir a VARIOS lotes.
     *
     * ═══ POR QUE EL ABONO NO TIENE FASE 1 COMO `abonarEnLosDeUnMismoNombre` ═══
     *
     * Alla se calcula TODO antes de emitir, para que el correlativo ni se mueva
     * si algo se cae. Aca no se puede: el efecto de cada abono depende de las
     * cuotas que este mismo recibo acaba de saldar, asi que se relee **despues**
     * de cobrar. Es la razon de ser de «Ambas» y esta explicada arriba.
     *
     * Lo que si se conserva es lo que importa: todo ocurre dentro de UNA
     * transaccion, asi que un abono rechazado deshace tambien el cobro. Nunca
     * queda un recibo que cobro mas de lo que aplico — que es exactamente el
     * agujero que `olympo:cuadrar-recibos` busca desde el 27-ago.
     *
     * @param list<array{lote: Compromiso, monto: Monto}> $cuotas
     * @param list<array{lote: Compromiso, monto: Monto, modalidad: ModalidadDeReprogramacion}> $abonos
     *
     * @throws PagoInvalidoException
     */
    private function cobrarYAbonarEnUnMismoNombre(
        Venta $venta,
        Cliente $cliente,
        array $cuotas,
        array $abonos,
        string $motivo,
        FormaDePago $forma,
        ?string $referencia,
        ?CarbonImmutable $fecha,
        ?string $observaciones,
    ): Recibo {
        if ($abonos === []) {
            throw PagoInvalidoException::porNoElegirNingunLote();
        }

        $porQue = trim($motivo);

        if ($porQue === '') {
            throw PagoInvalidoException::porFaltarElMotivoDelAbono();
        }

        $total = Monto::cero();
        $delAbono = [];

        foreach ($abonos as $renglon) {
            $this->verificar($venta, $renglon['lote'], $renglon['monto']);

            $id = (int) $renglon['lote']->getKey();

            if (in_array($id, $delAbono, true)) {
                throw PagoInvalidoException::porLoteRepetido($this->codigo($renglon['lote']));
            }

            $delAbono[] = $id;
            $total = $total->sumar($renglon['monto']);
        }

        $vistos = [];

        foreach ($cuotas as $renglon) {
            $this->verificar($venta, $renglon['lote'], $renglon['monto']);

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

        /*
         * El orden del bloqueo, igual para todo el sistema. Ver
         * `cobrarVariosLotes()`: sin esto, dos receptores se traban entre si.
         *
         * 🔴 Y los abonos tambien, desde que son varios: el orden en que se
         * releen decide a quien le toca el centavo del residuo cuando el
         * sobrante se repartio en partes iguales. Si el ORDEN decide algo, ese
         * orden se escribe — es la leccion del `FOR UPDATE` sin `ORDER BY` del
         * 27-ago.
         */
        $porId = static fn (array $uno, array $otro): int => (int) $uno['lote']->getKey() <=> (int) $otro['lote']->getKey();

        usort($cuotas, $porId);
        usort($abonos, $porId);

        /*
         * `compromiso_id` solo se llena cuando TODO el recibo —las cuotas y el
         * abono— es de un mismo lote. Con dos, la columna diria una mentira y
         * el desglose es el que contesta (R13).
         */
        $tocados = $vistos;

        foreach ($delAbono as $id) {
            if (! in_array($id, $tocados, true)) {
                $tocados[] = $id;
            }
        }

        return DB::transaction(function () use (
            $venta,
            $cliente,
            $cuotas,
            $abonos,
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
                count($tocados) === 1 ? $abonos[0]['lote'] : null,
                $cliente,
                ConceptoDeRecibo::AbonoCapital,
                $total,
                $forma,
                $limpia,
                $cuando,
                $observaciones,
                [
                    ...array_map(static fn (array $renglon): Compromiso => $renglon['lote'], $cuotas),
                    ...array_map(static fn (array $renglon): Compromiso => $renglon['lote'], $abonos),
                ],
            );

            // 3. La mitad de cuota: FIFO, mora → interes → capital.
            $moraCobrada = Monto::cero();

            foreach ($tandas as $tanda) {
                $reparto = $this->repartir($recibo, $tanda['pendientes'], $tanda['monto'], $tanda['mora']);
                $moraCobrada = $moraCobrada->sumar($reparto['cobrada']);
            }

            /*
             * 4. Los abonos, sobre el estado que dejo el cobro. Releer aca es el
             * corazon del metodo: la cuota que estaba a medias ya quedo saldada
             * y el lote entra al abono con cuotas que nadie toco.
             *
             * Uno por uno y en orden de id: cada lote se relee bloqueado, se
             * verifica entero y recien despues se escribe su plan. Si el tercero
             * no llega a bajar capital, la transaccion se cae y los dos primeros
             * TAMPOCO se abonan: un reparto a medias dejaria al cliente con un
             * papel que promete algo que la base no hizo.
             */
            foreach ($abonos as $renglon) {
                $lote = $renglon['lote'];
                $aCapital = $renglon['monto'];

                $limpias = $this->pendientesBloqueadas($lote);
                $moraDelAbono = MoraDelLote::calcular($limpias, $this->condicionesDe($lote), $cuando);

                $efecto = EfectoDelAbono::calcular(
                    $limpias,
                    $aCapital,
                    $renglon['modalidad'],
                    $this->diaDePago($venta),
                    $this->tasaDe($lote),
                    $moraDelAbono->total,
                );

                if ($aCapital->mayorQue($efecto->saldoDelLote->sumar($moraDelAbono->total))) {
                    throw PagoInvalidoException::porPagarDeMas(
                        $aCapital,
                        $efecto->saldoDelLote->sumar($moraDelAbono->total),
                        $this->codigo($lote),
                    );
                }

                if ($efecto->esPagoNormal) {
                    throw PagoInvalidoException::porSobranteQueNoBajaCapital(
                        $aCapital,
                        $efecto->ponerAlDia,
                        $this->codigo($lote),
                    );
                }

                if ($efecto->superaElTope) {
                    throw PagoInvalidoException::porAbonoQueNoSePuedeReprogramar(
                        $aCapital,
                        $efecto->tope,
                        $efecto->saldoDelLote,
                        $this->codigo($lote),
                    );
                }

                $plan = $efecto->planNuevo;

                if ($efecto->problema !== null || ! $plan instanceof PlanDeCuotas) {
                    throw PagoInvalidoException::porPlanQueNoSePudoArmar(
                        $efecto->problema ?? 'No se pudo armar el plan nuevo.',
                        $this->codigo($lote),
                    );
                }

                // Un plan que no cierra al centimo no llega nunca a la base (§8.3.4).
                if (! $plan->cierraExacto()) {
                    throw PagoInvalidoException::porPlanQueNoCierra($plan->totalCapital(), $efecto->saldoNuevo);
                }

                /*
                 * === 5. LO QUE EL ABONO USA PARA PONER AL DIA, SE ESCRIBE ===
                 *
                 * Hasta el 27-ago-2026 aca habia un comentario afirmando que
                 * `ponerAlDia` valia cero «porque el paso 4 releyo despues del
                 * cobro». Vale cero SOLO si los renglones de cuota cubrieron todo
                 * lo vencido del lote del abono, y nada obliga a eso.
                 *
                 * Lo que costo: recibo RPS-00000005 de Praderas, L 24,000.00 en
                 * el expediente 0070. Marcaron una cuota por lote y el N-008
                 * tenia dos vencidas; `EfectoDelAbono` le resto la segunda al
                 * abono —bajaron capital L 4,833.33 en vez de L 11,812.50— y
                 * nadie escribio ese pago: L 6,979.17 del cliente desaparecieron
                 * y la cuota 2 le siguio saliendo pendiente.
                 *
                 * El modal SI se lo mostraba antes de confirmar
                 * (`repartoDelAbono()`: «Pone al dia — cuota 2 … L 6,979.17»).
                 * Esto es lo que faltaba para que la base diga lo que la pantalla
                 * prometio, y es exactamente lo que `abonarEnLosDeUnMismoNombre()`
                 * ya hacia en su propio camino.
                 */
                if (! $efecto->ponerAlDia->esCero()) {
                    $reparto = $this->repartir($recibo, $limpias, $efecto->ponerAlDia, $moraDelAbono);
                    $moraCobrada = $moraCobrada->sumar($reparto['cobrada']);
                }

                $this->reescribirElPlan($venta, $lote, $efecto, $plan);
                $this->asentarLaConstancia($venta, $lote, $recibo, $efecto, $plan, $porQue);
            }

            /*
             * La mora se asienta UNA vez y al final: `asentarLaMora()` hace un
             * `update()` sobre el recibo, asi que asentarla adentro del bucle
             * pisaria la del lote anterior con la del siguiente en vez de
             * sumarlas. Con un solo abono ya era asi; con varios, la unica
             * diferencia es que ahora se nota.
             */
            $this->asentarLaMora($recibo, $moraCobrada, Monto::cero(), '');

            $this->recalcularElResumen($venta);
            $this->cerrarSiQuedoPagada($venta, $cuando);

            return $recibo;
        });
    }

    /**
     * ═══ LE ESCRIBE AL RECIBO EL PAGO QUE COBRO Y NUNCA APLICO ═══
     *
     * Nace el 27-ago-2026 para reparar lo que dejo el defecto del paso 5 de
     * `cobrarYAbonarEnUnMismoNombre()` —el caso que lo pidio esta en su
     * docblock—: un recibo cuyo monto no cuadra con lo que aplico es dinero
     * del cliente que no le bajo el saldo.
     *
     * Reusa `repartir()`, el MISMO FIFO del cobro, en vez de escribir la
     * aplicacion a mano: una segunda forma de repartir un pago seria una
     * segunda respuesta a «cuanto le tocó a cada cuota».
     *
     * Quien decide a que lote va el dinero es el que llama —lo sabe la
     * constancia de reprogramacion del recibo— y no este metodo: con dos lotes
     * reprogramados en un mismo papel la respuesta no es unica y adivinarla
     * seria acreditarle a uno lo que entrego el otro.
     *
     * Devuelve lo que aplico; cero si el recibo ya cuadraba.
     *
     * @throws PagoInvalidoException
     */
    public function cuadrarElRecibo(Recibo $recibo, Compromiso $lote, ?CarbonImmutable $fecha = null): Monto
    {
        $falta = $recibo->descuadre();

        if ($falta->esCero()) {
            return Monto::cero();
        }

        $cuando = $fecha ?? CarbonImmutable::parse(today()->toDateString());

        return DB::transaction(function () use ($recibo, $lote, $falta, $cuando): Monto {
            $pendientes = $this->pendientesBloqueadas($lote);
            $mora = MoraDelLote::calcular($pendientes, $this->condicionesDe($lote), $cuando);
            $tope = $this->saldoDe($pendientes)->sumar($mora->total);

            /*
             * Si al lote ya no le queda tanto por deber, el dinero perdido no
             * es de este lote: se rechaza entero en vez de acreditar una parte
             * y dejar el resto flotando.
             */
            if ($falta->mayorQue($tope)) {
                throw PagoInvalidoException::porPagarDeMas($falta, $tope, $this->codigo($lote));
            }

            $reparto = $this->repartir($recibo, $pendientes, $falta, $mora);

            /*
             * La mora se SUMA a la que el recibo ya tenia asentada.
             * `asentarLaMora()` no sirve aca: escribe tambien
             * `mora_condonada` y `motivo_condonacion`, y borraria un perdon
             * que alguien firmo.
             */
            if (! $reparto['cobrada']->esCero()) {
                $recibo->update([
                    'monto_mora' => $recibo->montoMora()->sumar($reparto['cobrada'])->redondeado(),
                ]);
            }

            /*
             * El resumen NO se recalcula: `plazo_meses` y `cuota_mensual`
             * salen de `numero` y `monto`, y un cuadre solo toca
             * `monto_pagado`. Lo que si puede cambiar es el estado — si esto
             * termina de pagar el expediente, queda Liquidado —, y ese es el
             * unico camino que no se puede olvidar (`anular-liquidar-fecha`).
             */
            $venta = $recibo->venta;

            if ($venta instanceof Venta) {
                $this->cerrarSiQuedoPagada($venta, $cuando);
            }

            return $falta;
        });
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Lo que se puede verificar sin tocar la base.
     *
     * ═══ 🔴 LA REFERENCIA YA NO TRABA EL COBRO (27-ago-2026) ═══
     *
     * Hasta hoy este metodo recibia tambien la forma de pago y la referencia,
     * y aplicaba la mitad de R11: sin numero de referencia, una transferencia
     * no se podia registrar. En el mostrador eso significa que llega el
     * cliente, el numero todavia no lo tiene nadie, y **el cobro no se
     * registra** — bastante peor que registrarlo sin la referencia.
     *
     * Se fueron el freno, el CHECK de la base y los dos parametros: un
     * parametro que nadie lee es una firma que miente. El campo sigue en la
     * pantalla y se sigue guardando, con su ayuda diciendo para que sirve.
     *
     * ⚠️ Solo en los recibos. `gastos`, `devoluciones` y `entregas_a_socios`
     * conservan su CHECK a proposito: ahi la plata SALE y el comprobante es la
     * unica defensa.
     *
     * @throws PagoInvalidoException
     */
    private function verificar(
        Venta $venta,
        Compromiso $lote,
        Monto $monto,
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
     * Las que ya pasaron su fecha y siguen debiendo algo.
     *
     * La regla la tiene la Cuota —`estaVencida()`— y no se copia acá: es la
     * MISMA que usa la mora, la lista de cobranza y el modal. Dos definiciones
     * de «vencida» serían dos respuestas a la pregunta de si el cliente está al
     * día, y las dos saldrían impresas.
     *
     * @param Collection<int, Cuota> $pendientes
     *
     * @return Collection<int, Cuota>
     */
    private function lasVencidas(Collection $pendientes): Collection
    {
        return $pendientes->filter(static fn (Cuota $cuota): bool => $cuota->estaVencida())->values();
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
     * `$deLaCarteraVieja` lo pasa SOLO `CarteraHistoricaSeeder`, y hace que el
     * papel salga de la serie vieja —sin prefijo— en vez de la del proyecto.
     * Ver `ConsumoDeCorrelativos::paraUnReciboNuevo()`.
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
        bool $deLaCarteraVieja = false,
    ): Recibo {
        $aNombreDe = $this->aNombreDeQuien($lotesDelRecibo);
        $factura = $this->facturas->paraElProyecto($venta->proyecto);
        $delTalonario = $this->correlativos->paraUnReciboNuevo($venta->proyecto, $deLaCarteraVieja);

        return Recibo::query()->create([
            'numero'        => $delTalonario['numero'],
            'serie'         => $delTalonario['serie'],
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
            /*
             * Quien recibio, y por defecto quien teclea — que es lo que el
             * sistema dio por sentado hasta el 27-ago-2026. Ver `loRecibio()`.
             */
            'recibido_por'  => $this->recibidoPor ?? auth()->id(),
            'referencia'    => $referencia === '' ? null : $referencia,
            'monto'         => $monto->redondeado(),
            'fecha'         => $cuando->toDateString(),
            'observaciones' => $observaciones,
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

        return DB::transaction(fn (): array => $this->marcarLaEmision(array_values(array_map($emitir, $grupos))));
    }

    /**
     * 🔴 Los papeles de un mismo cobro, marcados como lo que son — 11-sep-2026.
     *
     * «Cuando tiene más de un titular de recibo y a cada uno se le hizo un
     * abono o pago de cuota y generó varios recibos, ¿cómo se maneja eso?»
     * —Mauricio—. Si ese cobro estuvo mal hay que anular los cuatro, y hasta
     * hoy no había forma de saber que eran cuatro: compartían contrato, fecha y
     * quién los emitió, que es exactamente lo que también comparten un cobro de
     * la mañana y otro de la tarde.
     *
     * ⚠️ SOLO CUANDO SON VARIOS. Un recibo solo no salió «junto» con nadie, y
     * darle una emisión propia haría que la pantalla ofrezca «anular todo el
     * cobro» para anular exactamente uno.
     *
     * Va acá y no en `emitir()` a propósito: `emitir()` hace UN papel y no
     * sabe —ni tiene por qué— si es el único. Quien arma la lista sí.
     *
     * @param list<Recibo> $recibos
     *
     * @return list<Recibo>
     */
    private function marcarLaEmision(array $recibos): array
    {
        if (count($recibos) < 2) {
            return $recibos;
        }

        $emision = (string) Str::uuid();

        foreach ($recibos as $recibo) {
            $recibo->update(['emision_id' => $emision]);
        }

        return $recibos;
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
     *
     * ⚠️ `public` desde el 8-sep-2026, para `olympo:recuadrar-venta`: un
     * recuadre reescribe los planes de varios lotes y tiene que dejar el
     * resumen de la venta al día igual que un cobro. Duplicar estas quince
     * líneas en el comando sería la forma segura de que dentro de tres meses
     * una de las dos aprenda algo que la otra no. Desde el 11-sep lo llama
     * también `anular()`: deshacer un abono también cambia el plan.
     */
    public function recalcularElResumen(Venta $venta): void
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
