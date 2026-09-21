<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Exceptions\ReimputacionInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-imputar un recibo de la cartera vieja entre los lotes de su expediente
 * — 21-sep-2026.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * El cuaderno anota «abono a capital, L 32,500.00» y muchas veces no dice a
 * qué lote. Cuando el dato llega sin lote, la carga reparte el pago entre
 * todos los lotes del contrato según su valor, que es lo razonable cuando no
 * se sabe más. El día que el cliente o la administración SI saben más —«en el
 * lote 2 solo son los 10,000 de prima, en el otro va todo el resto»,
 * Mauricio— ese reparto quedó mal: el dinero está bien cobrado y mal
 * imputado, y cada lote muestra un saldo que no es el suyo.
 *
 * ═══ QUE HACE, EXACTAMENTE ═══
 *
 * Tres cosas, adentro de UNA transacción, y las tres por las puertas que ya
 * existen en `RegistroDePagos` — acá no se escribe una sola cuota a mano:
 *
 *   1. ANULA el recibo, con su motivo. Eso devuelve a las cuotas lo que les
 *      había aplicado y deshace las reprogramaciones que había hecho (el plan
 *      viejo de cada lote vuelve tal cual lo guardó la constancia). Es un paso
 *      INTERNO: se anula para deshacer sus efectos por la puerta que ya está
 *      probada, y al final esa fila se borra (ver abajo).
 *   2. Vuelve a REGISTRAR el mismo dinero —misma fecha, misma forma, misma
 *      referencia, misma nota, mismo receptor— contra los lotes pedidos, por
 *      la misma puerta por la que entró: un recibo de cuotas se vuelve a
 *      cobrar como cuotas, y uno de abono a capital, como abono, con la
 *      modalidad que tenía.
 *   3. COMPRUEBA que no se creó ni se perdió un centavo, y lo asienta en la
 *      bitácora del expediente con el antes y el después de cada lote.
 *
 * ═══ 🔴 EL RECIBO VIEJO SE BORRA, NO QUEDA ANULADO — 21-sep-2026 ═══
 *
 * «No hay anulados: directamente se borran esas transacciones de ahí» —
 * Mauricio, mirando el expediente con un 000089 anulado que en el cuaderno
 * nunca existió. Tiene razón, y por eso esta es la UNICA puerta del sistema
 * que borra un recibo:
 *
 *   · Un recibo de la cartera vieja es la TRANSCRIPCION de un renglón del
 *     cuaderno. En el cuaderno hay un solo pago y nadie anuló nada: un
 *     «anulado» en el estado de cuenta contaría una historia que no pasó.
 *   · Su número —la serie sin prefijo— no está impreso en ningún papel del
 *     cliente. Lo que el cliente tiene en la mano es el talonario, y ese
 *     número viaja en las observaciones, que se conservan. R12 cuida la serie
 *     que IMPRIME el sistema, y esa acá no se toca.
 *
 * El rastro no se pierde: queda en «Actualizaciones» del expediente, con el
 * folio que se fue, el que lo reemplaza, el antes y el después de cada lote y
 * el motivo.
 *
 * ⚠️ Se sigue pasando por `anular()` y no se editan las aplicaciones en el
 * lugar: eso obligaría a copiar acá el FIFO, el tope del abono y la
 * reescritura del plan — la matemática del dinero en dos lugares.
 *
 * ═══ QUE NO HACE ═══
 *
 * **Solo cartera vieja.** Un recibo que imprimió el sistema dice en el papel
 * a qué lote fue cada lempira (desde el 8-sep-2026), y el cliente se lo llevó.
 * Ese se anula desde el expediente y se cobra de nuevo, con papel nuevo.
 *
 * **No cambia el monto, la fecha ni la forma.** Para eso no es esta puerta.
 *
 * **No re-imputa un pronto pago**, ni una prima, ni una seña, ni un recibo que
 * salió junto con otros papeles, ni lotes con interés o mora: en todos esos
 * casos mover el dinero cambia algo más que el reparto.
 *
 * ═══ ⚠️ EL RESULTADO DEPENDE DEL DIA EN QUE SE CORRE ═══
 *
 * La cartera vieja pone primero al día lo VENCIDO A HOY y manda el resto a
 * capital — es la regla de `abonarAVariosLotes()` con `deLaCarteraVieja`, y es
 * la misma que usó la carga. Por eso el ensayo no es una estimación: corre
 * todo de verdad y después deshace la transacción. Lo que imprime es lo que
 * va a quedar, si se escribe el mismo día.
 */
final readonly class ReimputacionDeRecibo
{
    public function __construct(private RegistroDePagos $pagos) {}

    /**
     * Todo igual que `reimputar()`, y al final se deshace: no queda nada.
     *
     * @param array<string, Monto> $pedido código del lote → cuánto de este recibo le toca
     *
     * @throws ReimputacionInvalidaException
     * @throws PagoInvalidoException
     */
    public function ensayar(Venta $venta, Recibo $recibo, array $pedido): ReimputacionHecha
    {
        return $this->correr($venta, $recibo, $pedido, 'Ensayo: esto no se escribe.', escribir: false);
    }

    /**
     * @param array<string, Monto> $pedido código del lote → cuánto de este recibo le toca
     *
     * @throws ReimputacionInvalidaException
     * @throws PagoInvalidoException
     */
    public function reimputar(Venta $venta, Recibo $recibo, array $pedido, string $motivo): ReimputacionHecha
    {
        $porQue = trim($motivo);

        if ($porQue === '') {
            throw ReimputacionInvalidaException::porFaltarElMotivo();
        }

        return $this->correr($venta, $recibo, $pedido, $porQue, escribir: true);
    }

    /**
     * La transacción a mano, y es a propósito.
     *
     * `DB::transaction()` confirma siempre que la función termina bien, y el
     * ensayo necesita lo contrario: terminar bien y deshacer. Para no salir
     * con una excepción inventada, se abre y se cierra acá. Las transacciones
     * de los Services quedan anidadas adentro como savepoints.
     *
     * @param array<string, Monto> $pedido
     */
    private function correr(Venta $venta, Recibo $recibo, array $pedido, string $motivo, bool $escribir): ReimputacionHecha
    {
        DB::beginTransaction();

        try {
            $hecha = $this->mover($venta, $recibo, $pedido, $motivo, $escribir);
        } catch (Throwable $error) {
            DB::rollBack();

            throw $error;
        }

        if ($escribir) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        return $hecha;
    }

    /**
     * @param array<string, Monto> $pedido
     */
    private function mover(Venta $venta, Recibo $recibo, array $pedido, string $motivo, bool $escribir): ReimputacionHecha
    {
        // Se relee bloqueando: dos personas re-imputando el mismo recibo lo
        // anularían una y registrarían el dinero las dos.
        $vivo = Recibo::query()->whereKey($recibo->getKey())->lockForUpdate()->first();

        if (! $vivo instanceof Recibo) {
            throw ReimputacionInvalidaException::porReciboQueYaNoExiste($recibo->folio());
        }

        $this->verificarElRecibo($venta, $vivo);

        $lotes = $this->lotesDe($venta);
        $imputadoAntes = $this->imputadoPorLote($vivo);
        $destinos = $this->destinos($vivo, $lotes, $pedido);

        $this->verificarLosLotes($lotes, $imputadoAntes, $destinos);
        $this->verificarQueCambie($vivo, $imputadoAntes, $destinos);

        // Se leen ANTES de anular: `anular()` borra las constancias.
        $modalidades = $this->modalidadesDe($vivo);
        $motivoDelAbono = $this->motivoDelAbonoDe($vivo);

        $antes = $this->fotos($lotes);

        $this->pagos->anular($vivo, 'Re-imputación entre lotes: '.$motivo);

        // Si ese recibo había liquidado el expediente, `anular()` lo reabrió,
        // y el cobro que sigue mira el estado de ESTA instancia.
        $venta->refresh();

        $nuevos = $this->registrarDeNuevo($venta, $vivo, $destinos, $modalidades, $motivoDelAbono);

        $despues = $this->fotos($lotes);
        $imputadoDespues = [];
        $capitalDespues = [];

        foreach ($nuevos as $papel) {
            $imputadoDespues = $this->sumarPorLote($imputadoDespues, $this->imputadoPorLote($papel));
            $capitalDespues = $this->sumarPorLote($capitalDespues, $this->capitalPorLote($papel));
        }

        $this->comprobar($vivo, $nuevos, $destinos, $imputadoDespues, $antes, $despues);

        $hecha = new ReimputacionHecha(
            escrita: $escribir,
            folioViejo: $vivo->folio(),
            foliosNuevos: array_map(static fn (Recibo $papel): string => $papel->folio(), $nuevos),
            monto: $vivo->montoTotal(),
            lotes: $this->retratos($lotes, $imputadoAntes, $imputadoDespues, $capitalDespues, $antes, $despues),
        );

        $this->asentar($venta, $hecha, $motivo);

        // Ver el docblock de la clase: la fila anulada era un paso interno.
        // Sus aplicaciones y sus impresiones se van con ella (cascade).
        $vivo->delete();

        return $hecha;
    }

    // ─── Lo que se niega a hacer ──────────────────────────────────────

    private function verificarElRecibo(Venta $venta, Recibo $recibo): void
    {
        $folio = $recibo->folio();

        if ((int) $recibo->getAttribute('venta_id') !== (int) $venta->getKey()) {
            throw ReimputacionInvalidaException::porReciboDeOtroExpediente(
                $folio,
                (string) $venta->getAttribute('numero_contrato'),
            );
        }

        if ($recibo->estaAnulado()) {
            $motivo = $recibo->getAttribute('motivo_anulacion');

            throw ReimputacionInvalidaException::porReciboYaAnulado($folio, is_string($motivo) ? $motivo : 'sin motivo');
        }

        if (! $recibo->esDeLaCarteraVieja()) {
            throw ReimputacionInvalidaException::porNoSerDeLaCarteraVieja($folio);
        }

        $concepto = $recibo->getAttribute('concepto');

        if ($concepto !== ConceptoDeRecibo::Cuota && $concepto !== ConceptoDeRecibo::AbonoCapital) {
            throw ReimputacionInvalidaException::porConceptoQueNoSeReimputa(
                $folio,
                $concepto instanceof ConceptoDeRecibo ? $concepto->etiqueta() : 'otro concepto',
            );
        }

        if ($recibo->tuvoDescuento()) {
            throw ReimputacionInvalidaException::porSerUnProntoPago($folio);
        }

        if ($recibo->salioConOtros()) {
            throw ReimputacionInvalidaException::porHaberSalidoConOtros($folio);
        }
    }

    /**
     * Interés y mora: mover el pago cambia cuánto corrió en cada lote.
     *
     * Se miran solo los lotes que pierden o reciben dinero: un tercer lote del
     * contrato que nadie toca no tiene por qué frenar la corrección.
     *
     * @param array<string, Compromiso> $lotes
     * @param array<int, Monto> $imputadoAntes
     * @param list<array{lote: Compromiso, monto: Monto}> $destinos
     */
    private function verificarLosLotes(array $lotes, array $imputadoAntes, array $destinos): void
    {
        $tocados = array_keys($imputadoAntes);

        foreach ($destinos as $destino) {
            $tocados[] = (int) $destino['lote']->getKey();
        }

        foreach ($lotes as $codigo => $lote) {
            if (! in_array((int) $lote->getKey(), $tocados, true)) {
                continue;
            }

            if ($lote->cobraInteres() || $lote->cobraMora()) {
                throw ReimputacionInvalidaException::porLlevarInteresOMora($codigo);
            }
        }
    }

    /**
     * @param array<int, Monto> $imputadoAntes
     * @param list<array{lote: Compromiso, monto: Monto}> $destinos
     */
    private function verificarQueCambie(Recibo $recibo, array $imputadoAntes, array $destinos): void
    {
        if (count($imputadoAntes) !== count($destinos)) {
            return;
        }

        foreach ($destinos as $destino) {
            $tenia = $imputadoAntes[(int) $destino['lote']->getKey()] ?? null;

            if (! $tenia instanceof Monto || ! $tenia->igualA($destino['monto'])) {
                return;
            }
        }

        throw ReimputacionInvalidaException::porYaEstarImputadoAsi($recibo->folio());
    }

    // ─── Lo que se pidió ──────────────────────────────────────────────

    /**
     * Los lotes del expediente, por su código.
     *
     * @return array<string, Compromiso>
     */
    private function lotesDe(Venta $venta): array
    {
        $lotes = [];

        $compromisos = Compromiso::query()
            ->where('venta_id', $venta->getKey())
            ->with('lote')
            ->orderBy('id')
            ->get();

        foreach ($compromisos as $compromiso) {
            $lotes[$this->codigoDe($compromiso)] = $compromiso;
        }

        return $lotes;
    }

    /**
     * El pedido, ya contra los lotes de verdad y sumando lo que dice el papel.
     *
     * ⚠️ `array-key` y no `string` en el pedido, y el `(string)` de abajo no
     * sobra: PHP convierte en entero toda clave que parezca un número, y un
     * lote cuyo código fuera «12» llegaría acá como `int`. Con `string` en el
     * docblock, Rector quita el cast y `trim()` revienta con tipos estrictos.
     *
     * @param array<string, Compromiso> $lotes
     * @param array<array-key, Monto> $pedido
     *
     * @return list<array{lote: Compromiso, monto: Monto}>
     */
    private function destinos(Recibo $recibo, array $lotes, array $pedido): array
    {
        if ($pedido === []) {
            throw ReimputacionInvalidaException::porNoNombrarLotes();
        }

        $destinos = [];
        $vistos = [];
        $total = Monto::cero();

        foreach ($pedido as $codigo => $monto) {
            $codigo = mb_strtoupper(trim((string) $codigo));
            $lote = $lotes[$codigo] ?? null;

            if (! $lote instanceof Compromiso) {
                throw ReimputacionInvalidaException::porLoteQueNoEsDeLaVenta($codigo);
            }

            if (in_array($codigo, $vistos, true)) {
                throw ReimputacionInvalidaException::porLoteRepetido($codigo);
            }

            if (! $lote->estaVigente()) {
                throw ReimputacionInvalidaException::porLoteQueYaNoEstaVivo($codigo);
            }

            if ($monto->esCero()) {
                throw ReimputacionInvalidaException::porMontoEnCero($codigo);
            }

            $vistos[] = $codigo;
            $total = $total->sumar($monto);
            $destinos[] = ['lote' => $lote, 'monto' => $monto];
        }

        if (! $total->igualA($recibo->montoTotal())) {
            throw ReimputacionInvalidaException::porNoSumarElRecibo($recibo->folio(), $total, $recibo->montoTotal());
        }

        return $destinos;
    }

    // ─── Lo que el recibo había hecho ─────────────────────────────────

    /**
     * Cuánto de este recibo fue a cada lote: lo aplicado a sus cuotas más lo
     * que le bajó de capital. Por id de compromiso.
     *
     * ⚠️ Del recibo viejo se lee ANTES de anularlo: `anular()` conserva las
     * aplicaciones —son la traza— pero borra las constancias.
     *
     * @return array<int, Monto>
     */
    private function imputadoPorLote(Recibo $recibo): array
    {
        $porLote = [];

        foreach ($recibo->aplicaciones()->with('cuota')->get() as $aplicacion) {
            $cuota = $aplicacion->cuota;

            if (! $cuota instanceof Cuota) {
                continue;
            }

            $id = (int) $cuota->getAttribute('compromiso_id');
            $porLote[$id] = ($porLote[$id] ?? Monto::cero())->sumar($aplicacion->montoAplicado());
        }

        return $this->sumarPorLote($porLote, $this->capitalPorLote($recibo));
    }

    /**
     * Lo que este recibo bajó de capital en cada lote: sus constancias.
     *
     * @return array<int, Monto>
     */
    private function capitalPorLote(Recibo $recibo): array
    {
        $porLote = [];

        foreach ($recibo->reprogramaciones()->get() as $constancia) {
            $id = (int) $constancia->getAttribute('compromiso_id');
            $porLote[$id] = ($porLote[$id] ?? Monto::cero())->sumar($constancia->montoAbonado());
        }

        return $porLote;
    }

    /**
     * @param array<int, Monto> $uno
     * @param array<int, Monto> $otro
     *
     * @return array<int, Monto>
     */
    private function sumarPorLote(array $uno, array $otro): array
    {
        foreach ($otro as $id => $monto) {
            $uno[$id] = ($uno[$id] ?? Monto::cero())->sumar($monto);
        }

        return $uno;
    }

    /**
     * La modalidad con que cada lote había reprogramado, por id de compromiso.
     *
     * @return array<int, ModalidadDeReprogramacion>
     */
    private function modalidadesDe(Recibo $recibo): array
    {
        $modalidades = [];

        foreach ($recibo->reprogramaciones()->orderBy('id')->get() as $constancia) {
            $modalidad = $constancia->modalidadElegida();

            if ($modalidad instanceof ModalidadDeReprogramacion) {
                $modalidades[(int) $constancia->getAttribute('compromiso_id')] = $modalidad;
            }
        }

        return $modalidades;
    }

    /**
     * El motivo que llevaba el abono, para que la constancia nueva diga lo
     * mismo que decía la vieja. El de la re-imputación va en la anulación y en
     * la bitácora, que es donde alguien lo va a buscar.
     */
    private function motivoDelAbonoDe(Recibo $recibo): string
    {
        foreach ($recibo->reprogramaciones()->orderBy('id')->get() as $constancia) {
            $motivo = $constancia->getAttribute('motivo');

            if (is_string($motivo) && trim($motivo) !== '') {
                return trim($motivo);
            }
        }

        return 'Abono a capital registrado antes del sistema.';
    }

    // ─── Volver a registrar ───────────────────────────────────────────

    /**
     * El mismo dinero, por la misma puerta por la que entró.
     *
     * @param list<array{lote: Compromiso, monto: Monto}> $destinos
     * @param array<int, ModalidadDeReprogramacion> $modalidades
     *
     * @return list<Recibo>
     */
    private function registrarDeNuevo(
        Venta $venta,
        Recibo $viejo,
        array $destinos,
        array $modalidades,
        string $motivoDelAbono,
    ): array {
        $folio = $viejo->folio();

        $cliente = $viejo->cliente;
        $forma = $viejo->getAttribute('forma_pago');
        $fecha = $viejo->getAttribute('fecha');

        if (! $cliente instanceof Cliente) {
            throw ReimputacionInvalidaException::porReciboSinDatos($folio, 'cliente');
        }

        if (! $forma instanceof FormaDePago) {
            throw ReimputacionInvalidaException::porReciboSinDatos($folio, 'forma de pago');
        }

        if (! $fecha instanceof DateTimeInterface) {
            throw ReimputacionInvalidaException::porReciboSinDatos($folio, 'fecha');
        }

        $referencia = $viejo->getAttribute('referencia');
        $recibio = $viejo->getAttribute('recibido_por');

        // Quien recibió el dinero no cambia porque cambie el reparto.
        $pagos = $this->pagos->loRecibio(is_int($recibio) ? $recibio : null);
        $cuando = CarbonImmutable::parse($fecha->format('Y-m-d'));
        $nota = $this->notaDe($viejo);

        if ($viejo->getAttribute('concepto') === ConceptoDeRecibo::AbonoCapital) {
            // Si el lote no había reprogramado con este recibo, hereda la
            // modalidad de los que sí; y si no hay ninguna, la histórica (R3).
            $porDefecto = array_first($modalidades) ?? ModalidadDeReprogramacion::AcortarPlazo;

            return $pagos->abonarAVariosLotes(
                venta: $venta,
                cliente: $cliente,
                renglones: array_map(
                    static fn (array $destino): array => [
                        ...$destino,
                        'modalidad' => $modalidades[(int) $destino['lote']->getKey()] ?? $porDefecto,
                    ],
                    $destinos,
                ),
                motivo: $motivoDelAbono,
                forma: $forma,
                referencia: is_string($referencia) ? $referencia : null,
                fecha: $cuando,
                observaciones: $nota,
                deLaCarteraVieja: true,
            );
        }

        return $pagos->cobrarVariosLotes(
            venta: $venta,
            cliente: $cliente,
            renglones: $destinos,
            forma: $forma,
            referencia: is_string($referencia) ? $referencia : null,
            fecha: $cuando,
            observaciones: $nota,
            deLaCarteraVieja: true,
        );
    }

    /**
     * La nota del recibo viejo, tal cual.
     *
     * Es donde viaja el número del talonario de papel —lo que el cliente tiene
     * en la mano—, así que pasa entera al recibo nuevo. No se le agrega de
     * dónde sale: el viejo se borra, y una nota que nombre un recibo que ya no
     * existe manda a buscarlo a quien la lea. Ese rastro vive en la bitácora.
     */
    private function notaDe(Recibo $viejo): ?string
    {
        $nota = $viejo->getAttribute('observaciones');

        return is_string($nota) && trim($nota) !== '' ? trim($nota) : null;
    }

    // ─── El antes y el después ────────────────────────────────────────

    /**
     * Cómo está cada lote, leído de la base.
     *
     * @param array<string, Compromiso> $lotes
     *
     * @return array<string, array{saldo: Monto, vencidas: int, debeVencido: Monto}>
     */
    private function fotos(array $lotes): array
    {
        $fotos = [];

        foreach ($lotes as $codigo => $lote) {
            $saldo = Monto::cero();
            $debeVencido = Monto::cero();
            $vencidas = 0;

            $cuotas = Cuota::query()
                ->where('compromiso_id', $lote->getKey())
                ->orderBy('numero')
                ->get();

            foreach ($cuotas as $cuota) {
                $saldo = $saldo->sumar($cuota->saldo());

                if ($cuota->estaVencida()) {
                    $vencidas++;
                    $debeVencido = $debeVencido->sumar($cuota->saldo());
                }
            }

            $fotos[$codigo] = ['saldo' => $saldo, 'vencidas' => $vencidas, 'debeVencido' => $debeVencido];
        }

        return $fotos;
    }

    /**
     * Un retrato por cada lote que sigue vivo o que el recibo había tocado.
     *
     * @param array<string, Compromiso> $lotes
     * @param array<int, Monto> $imputadoAntes
     * @param array<int, Monto> $imputadoDespues
     * @param array<int, Monto> $capitalDespues
     * @param array<string, array{saldo: Monto, vencidas: int, debeVencido: Monto}> $antes
     * @param array<string, array{saldo: Monto, vencidas: int, debeVencido: Monto}> $despues
     *
     * @return list<LoteReimputado>
     */
    private function retratos(
        array $lotes,
        array $imputadoAntes,
        array $imputadoDespues,
        array $capitalDespues,
        array $antes,
        array $despues,
    ): array {
        $retratos = [];

        foreach ($lotes as $codigo => $lote) {
            $id = (int) $lote->getKey();
            $tenia = $imputadoAntes[$id] ?? Monto::cero();
            $tiene = $imputadoDespues[$id] ?? Monto::cero();

            if (! $lote->estaVigente() && $tenia->esCero() && $tiene->esCero()) {
                continue;
            }

            $aCapital = $capitalDespues[$id] ?? Monto::cero();

            $retratos[] = new LoteReimputado(
                codigo: $codigo,
                imputadoAntes: $tenia,
                imputadoDespues: $tiene,
                aCuotas: $tiene->restar($aCapital),
                aCapital: $aCapital,
                saldoAntes: $antes[$codigo]['saldo'],
                saldoDespues: $despues[$codigo]['saldo'],
                vencidasAntes: $antes[$codigo]['vencidas'],
                vencidasDespues: $despues[$codigo]['vencidas'],
                debeVencidoDespues: $despues[$codigo]['debeVencido'],
            );
        }

        return $retratos;
    }

    // ─── La red ───────────────────────────────────────────────────────

    /**
     * Las tres cosas que tienen que ser verdad antes de dejar nada escrito.
     * Se leen de la BASE, ya con todo hecho: si una no da, se cae la
     * transacción entera y el expediente queda como estaba.
     *
     * @param list<Recibo> $nuevos
     * @param list<array{lote: Compromiso, monto: Monto}> $destinos
     * @param array<int, Monto> $imputadoDespues
     * @param array<string, array{saldo: Monto, vencidas: int, debeVencido: Monto}> $antes
     * @param array<string, array{saldo: Monto, vencidas: int, debeVencido: Monto}> $despues
     */
    private function comprobar(
        Recibo $viejo,
        array $nuevos,
        array $destinos,
        array $imputadoDespues,
        array $antes,
        array $despues,
    ): void {
        $monto = $viejo->montoTotal();

        // 1. Los papeles nuevos dicen, entre todos, lo que decía el viejo.
        $emitido = Monto::cero();

        foreach ($nuevos as $papel) {
            $emitido = $emitido->sumar($papel->montoTotal());
        }

        if (! $emitido->igualA($monto)) {
            throw ReimputacionInvalidaException::porNoQuedarDondeDebia('Los recibos nuevos', $emitido, $monto);
        }

        // 2. Cada lote recibió lo que se pidió, y entre todos recibieron el
        // recibo entero — o sea que ningún otro lote recibió nada.
        $imputado = Monto::cero();

        foreach ($imputadoDespues as $suyo) {
            $imputado = $imputado->sumar($suyo);
        }

        if (! $imputado->igualA($monto)) {
            throw ReimputacionInvalidaException::porNoQuedarDondeDebia('Lo imputado entre todos los lotes', $imputado, $monto);
        }

        foreach ($destinos as $destino) {
            $recibio = $imputadoDespues[(int) $destino['lote']->getKey()] ?? Monto::cero();

            if (! $recibio->igualA($destino['monto'])) {
                throw ReimputacionInvalidaException::porNoQuedarDondeDebia(
                    'Lo imputado al lote '.$this->codigoDe($destino['lote']),
                    $recibio,
                    $destino['monto'],
                );
            }
        }

        // 3. Re-imputar reparte: la deuda del expediente es la misma.
        $debiaAntes = Monto::cero();
        $debeAhora = Monto::cero();

        foreach ($antes as $codigo => $foto) {
            $debiaAntes = $debiaAntes->sumar($foto['saldo']);
            $debeAhora = $debeAhora->sumar($despues[$codigo]['saldo']);
        }

        if (! $debeAhora->igualA($debiaAntes)) {
            throw ReimputacionInvalidaException::porNoQuedarDondeDebia('El saldo del expediente', $debeAhora, $debiaAntes);
        }
    }

    /**
     * El asiento en la bitácora del expediente, con el antes y el después.
     *
     * Va contra la VENTA, igual que `CorreccionDeValor`: sale en la pestaña
     * «Actualizaciones», que es donde alguien va a buscar dentro de dos años
     * por qué el lote 2 amaneció sin pagos.
     */
    private function asentar(Venta $venta, ReimputacionHecha $hecha, string $motivo): void
    {
        $viejos = ['recibo' => $hecha->folioViejo];
        $nuevos = ['recibo' => implode(', ', $hecha->foliosNuevos)];

        foreach ($hecha->lotes as $lote) {
            $viejos['imputado al lote '.$lote->codigo] = $lote->imputadoAntes->formateado();
            $nuevos['imputado al lote '.$lote->codigo] = $lote->imputadoDespues->formateado();

            $viejos['saldo del lote '.$lote->codigo] = $lote->saldoAntes->formateado();
            $nuevos['saldo del lote '.$lote->codigo] = $lote->saldoDespues->formateado();
        }

        activity()
            ->performedOn($venta)
            ->withChanges(['old' => $viejos, 'attributes' => $nuevos])
            ->withProperty('motivo', $motivo)
            ->event('reimputacion')
            ->log('Recibo re-imputado entre lotes');
    }

    private function codigoDe(Compromiso $lote): string
    {
        $codigo = $lote->lote?->getAttribute('codigo');

        return is_string($codigo) && $codigo !== '' ? $codigo : 'lote sin código';
    }
}
