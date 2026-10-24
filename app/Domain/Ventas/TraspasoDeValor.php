<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\TraspasoDeValorInvalidoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Models\AplicacionDePago;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mover valor de un lote a otro dentro del mismo contrato — 24-oct-2026.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * Expediente 0070 de Praderas. El cuaderno lleva DOS cuentas: el O-3 por
 * L 210,000.00 con L 200,000.00 de prima, y el O-4 con el N-8 por L 605,000.00
 * con L 20,000.00 de prima — y anota que esos 605,000 son los valores
 * individuales del O-4 y del N-8 (250,000 y 345,000) MÁS los 10,000 que le
 * faltaban al O-3. Mauricio: «el O-3 queda pagado con su prima, y los otros
 * 10,000 se dividen en los otros lotes».
 *
 * La carga puso cada lote con su valor individual, así que el O-3 quedó
 * debiendo L 10,000.00 en 48 cuotas de L 208.33 que el cliente nunca pactó.
 * El total del contrato estaba bien; lo que estaba mal era CUÁNTO VALE CADA
 * LOTE.
 *
 * ═══ QUE HACE, EXACTAMENTE ═══
 *
 * Adentro de UNA transacción:
 *
 *   1. Le pone a cada lote el valor pedido (y el precio por vara que lo da).
 *      La prima de cada lote NO se toca: es lo que se entregó por él.
 *   2. Rehace el plan de cada lote desde cero, con el mismo plazo y el mismo
 *      primer vencimiento. Un lote que queda en cero de financiado se queda
 *      sin cuotas: está pagado.
 *   3. Vuelve a aplicar cada recibo de cuotas o de abono, del más viejo al
 *      más nuevo, por la puerta de `RegistroDePagos::reaplicar()`: paga las
 *      MISMAS cuotas que había pagado —a su monto nuevo— y lo que sobre va a
 *      capital en el lote al que ese recibo había mandado capital. Lo vencido
 *      se mira al día del recibo, no hoy.
 *   4. Comprueba que el expediente debe EXACTAMENTE lo mismo que antes, que
 *      cada recibo sigue aplicando lo que cobró, y lo asienta en la bitácora.
 *
 * ═══ QUE NO TOCA ═══
 *
 * **Los recibos.** Ni el número, ni el monto, ni la fecha, ni quién recibió.
 * El papel del cliente dice «recibí L 24,000.00» y eso sigue siendo verdad: lo
 * que cambia es a qué cuota cayó cada lempira, como en `olympo:recuadrar-venta`.
 * **La prima** de cada lote, **el valor total** del contrato, ni **la ficha
 * del lote** en el plano (la protege el trigger `lotes_proteger_vendido`, y lo
 * que vale para el contrato es lo congelado en `compromisos`, §8.2).
 *
 * ═══ LO QUE SE NIEGA A HACER ═══
 *
 * Todo lo que obligaría a adivinar: un total que cambia (eso es
 * `olympo:corregir-valor`), lotes con interés o mora, un pronto pago, una
 * cuota pagada en partes por dos recibos, un recibo al que le sobra dinero y
 * no tenía UN abono a donde mandarlo, rastros de recibos anulados. Cada
 * negativa dice por qué.
 *
 * ═══ EL ENSAYO NO ESTIMA ═══
 *
 * Corre todo de verdad y deshace la transacción, igual que
 * `ReimputacionDeRecibo`. Lo que imprime es lo que va a quedar.
 */
final readonly class TraspasoDeValor
{
    public function __construct(private RegistroDePagos $pagos) {}

    /**
     * @param array<string, Monto> $valores el valor nuevo de CADA lote, por código
     */
    public function ensayar(Venta $venta, array $valores): TraspasoHecho
    {
        return $this->correr($venta, $valores, 'Ensayo del traspaso', escribir: false);
    }

    /**
     * @param array<string, Monto> $valores el valor nuevo de CADA lote, por código
     */
    public function traspasar(Venta $venta, array $valores, string $motivo): TraspasoHecho
    {
        if (trim($motivo) === '') {
            throw TraspasoDeValorInvalidoException::porFaltarElMotivo();
        }

        return $this->correr($venta, $valores, trim($motivo), escribir: true);
    }

    /**
     * @param array<string, Monto> $valores
     */
    private function correr(Venta $venta, array $valores, string $motivo, bool $escribir): TraspasoHecho
    {
        DB::beginTransaction();

        try {
            $hecho = $this->mover($venta, $valores, $motivo);
        } catch (Throwable $error) {
            DB::rollBack();

            throw $error;
        }

        if ($escribir) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        return $hecho;
    }

    /**
     * @param array<string, Monto> $valores
     */
    private function mover(Venta $venta, array $valores, string $motivo): TraspasoHecho
    {
        $venta = Venta::query()->whereKey($venta->getKey())->lockForUpdate()->firstOrFail();

        $estado = $venta->getAttribute('estado');

        if ($estado !== EstadoVenta::Vigente) {
            throw TraspasoDeValorInvalidoException::porExpedienteQueNoEstaVigente(
                $estado instanceof EstadoVenta ? $estado->value : 'sin estado',
            );
        }

        $lotes = $this->lotesDe($venta);
        $nuevos = $this->valoresNuevos($lotes, $valores);
        $recibos = $this->recibosDe($venta, $lotes);

        $antes = $this->fotos($lotes);
        $repartoAntes = $this->repartos($recibos, $lotes);
        $intenciones = $this->intenciones($recibos, $lotes);
        $primeras = $this->primerosVencimientos($lotes);

        $this->borrarLoAplicado($venta, $lotes, $recibos);

        foreach ($lotes as $codigo => $lote) {
            $this->revalorizar($lote, $nuevos[$codigo]);
            $this->replanificar($venta, $lote, $codigo, $primeras[$codigo] ?? null);
        }

        foreach ($intenciones as $intencion) {
            $this->volverAAplicar($venta, $lotes, $intencion);
        }

        $this->pagos->recalcularElResumen($venta);

        $frescos = $this->lotesDe($venta);
        $hecho = $this->armar($antes, $this->fotos($frescos), $repartoAntes, $this->repartos($recibos, $frescos), $recibos);

        $this->comprobar($hecho, $frescos, $recibos);
        $this->asentar($venta, $hecho, $motivo);

        return $hecho;
    }

    // ─── Leer y verificar ─────────────────────────────────────────────

    /**
     * Los lotes del contrato, por código, bloqueados.
     *
     * @return array<string, Compromiso>
     */
    private function lotesDe(Venta $venta): array
    {
        $compromisos = Compromiso::query()
            ->where('venta_id', $venta->getKey())
            ->with('lote')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lotes = [];

        foreach ($compromisos as $compromiso) {
            $codigo = $this->codigoDe($compromiso);

            if ($compromiso->getAttribute('estado') !== EstadoCompromiso::Vigente) {
                throw TraspasoDeValorInvalidoException::porLoteQueNoEstaVivo($codigo);
            }

            if ($compromiso->cobraInteres() || $compromiso->cobraMora()) {
                throw TraspasoDeValorInvalidoException::porLoteConInteresOMora($codigo);
            }

            $lotes[$codigo] = $compromiso;
        }

        return $lotes;
    }

    /**
     * El valor, el precio por vara y el plazo que va a llevar cada lote.
     *
     * @param array<string, Compromiso> $lotes
     * @param array<string, Monto> $valores
     *
     * @return array<string, array{valor: Monto, precio: string, lista: string, plazo: int}>
     */
    private function valoresNuevos(array $lotes, array $valores): array
    {
        foreach (array_keys($valores) as $codigo) {
            if (! array_key_exists($codigo, $lotes)) {
                throw TraspasoDeValorInvalidoException::porLoteQueNoEsDeLaVenta($codigo);
            }
        }

        $antes = Monto::cero();
        $despues = Monto::cero();
        $cambia = false;
        $nuevos = [];

        foreach ($lotes as $codigo => $lote) {
            if (! array_key_exists($codigo, $valores)) {
                throw TraspasoDeValorInvalidoException::porFaltarUnLote($codigo);
            }

            $nuevo = $valores[$codigo];
            $actual = $lote->montoValor();
            $prima = new Monto($this->textoDe($lote, 'prima'));

            if ($nuevo->esCero()) {
                throw TraspasoDeValorInvalidoException::porValorEnCero($codigo);
            }

            if ($prima->mayorQue($nuevo)) {
                throw TraspasoDeValorInvalidoException::porPrimaMayorAlValor($codigo, $prima, $nuevo);
            }

            $plazo = $this->plazoDe($lote, $codigo);
            $financiado = $nuevo->restar($prima);

            if ($plazo === 0 && ! $financiado->esCero()) {
                throw TraspasoDeValorInvalidoException::porLoteDeContadoQueVolveriaADeber($codigo);
            }

            /*
             * El precio y el valor salen del mismo número redondeado: el CHECK
             * `compromisos_valor_es_area_por_precio_chk` compara el valor
             * contra área × precio GUARDADO. Misma regla que
             * `CorreccionDeValor`.
             */
            $area = $this->textoDe($lote, 'area_varas');
            $precio = new Monto($nuevo->dividirPor($area)->redondeado(Lote::DECIMALES_DEL_PRECIO));
            $daria = new Monto($precio->multiplicarPor($area)->redondeado());

            if (! $daria->igualA($nuevo)) {
                throw TraspasoDeValorInvalidoException::porValorQueNoCierraConElArea($codigo, $nuevo, $daria);
            }

            // El de lista acompaña al pactado solo si eran el mismo número.
            $pactadoAntes = new Monto($this->textoDe($lote, 'precio_vara'));
            $listaAntes = new Monto($this->textoDe($lote, 'precio_vara_lista'));
            $lista = $listaAntes->igualA($pactadoAntes) ? $precio : $listaAntes;

            if ($precio->menorQue($lista) && trim($this->textoDe($lote, 'motivo_descuento', '')) === '') {
                throw TraspasoDeValorInvalidoException::porQuedarBajoElPrecioDeListaSinMotivo($codigo);
            }

            $nuevos[$codigo] = [
                'valor'  => $nuevo,
                'precio' => $precio->redondeado(Lote::DECIMALES_DEL_PRECIO),
                'lista'  => $lista->redondeado(Lote::DECIMALES_DEL_PRECIO),
                // Un lote que queda pagado con su prima es de contado.
                'plazo' => $financiado->esCero() ? 0 : $plazo,
            ];

            $antes = $antes->sumar($actual);
            $despues = $despues->sumar($nuevo);
            $cambia = $cambia || ! $actual->igualA($nuevo);
        }

        if (! $antes->igualA($despues)) {
            throw TraspasoDeValorInvalidoException::porNoSumarLoMismo($antes, $despues);
        }

        if (! $cambia) {
            throw TraspasoDeValorInvalidoException::porNoCambiarNada();
        }

        return $nuevos;
    }

    /**
     * Los recibos que pagaron cuotas o capital del contrato, del más viejo al
     * más nuevo. La prima y la seña no cuelgan de ninguna cuota: no se tocan.
     *
     * @param array<string, Compromiso> $lotes
     *
     * @return list<Recibo>
     */
    private function recibosDe(Venta $venta, array $lotes): array
    {
        $vivos = [];

        $todos = Recibo::query()
            ->where('venta_id', $venta->getKey())
            ->whereNull('anulado_el')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        foreach ($todos as $recibo) {
            $concepto = $recibo->getAttribute('concepto');

            if ($concepto === ConceptoDeRecibo::Prima) {
                continue;
            }

            if ($concepto === ConceptoDeRecibo::Senia) {
                continue;
            }

            $folio = $recibo->folio();

            if ($recibo->tuvoDescuento()) {
                throw TraspasoDeValorInvalidoException::porReciboQueNoSeSabeMover($folio, 'es un pronto pago con descuento.');
            }

            if (! $recibo->montoMora()->esCero()) {
                throw TraspasoDeValorInvalidoException::porReciboQueNoSeSabeMover($folio, 'cobró mora.');
            }

            if (! $recibo->cuadra()) {
                throw TraspasoDeValorInvalidoException::porReciboQueNoCuadra($folio);
            }

            $vivos[] = $recibo;
        }

        $ids = array_map(static fn (Recibo $recibo): int => (int) $recibo->getKey(), $vivos);

        /*
         * Nada más puede colgar de estas cuotas ni de estos lotes: ni el
         * rastro de un recibo anulado ni una reprogramación de otro papel.
         * Las cuotas se van a rehacer, y lo que cuelgue de ellas se perdería.
         */
        $ajeno = AplicacionDePago::query()
            ->whereIn('cuota_id', Cuota::query()->select('id')->whereIn('compromiso_id', $this->idsDe($lotes)))
            ->whereNotIn('recibo_id', $ids)
            ->value('recibo_id');

        $ajeno ??= Reprogramacion::query()
            ->where('venta_id', $venta->getKey())
            ->whereNotIn('recibo_id', $ids)
            ->value('recibo_id');

        /*
         * Una reprogramación sin recibo (la columna lo admite) no es de ningún
         * papel que se pueda volver a aplicar: borrarla perdería su abono.
         */
        if (Reprogramacion::query()->where('venta_id', $venta->getKey())->whereNull('recibo_id')->exists()) {
            throw TraspasoDeValorInvalidoException::porReciboQueNoSeSabeMover(
                'sin número',
                'hay una reprogramación del expediente que no cuelga de ningún recibo.',
            );
        }

        if ($ajeno !== null) {
            $otro = Recibo::query()->whereKey($this->entero($ajeno))->first();

            throw TraspasoDeValorInvalidoException::porRastroDeUnAnulado(
                $otro instanceof Recibo ? $otro->folio() : (string) $this->entero($ajeno),
            );
        }

        return $vivos;
    }

    /**
     * Cómo está cada lote: valor, saldo, cuántas cuotas y de cuánto la primera.
     *
     * @param array<string, Compromiso> $lotes
     *
     * @return array<string, array{valor: Monto, saldo: Monto, cuotas: int, cuota: Monto|null}>
     */
    private function fotos(array $lotes): array
    {
        $fotos = [];

        foreach ($lotes as $codigo => $lote) {
            $cuotas = Cuota::query()->where('compromiso_id', $lote->getKey())->orderBy('numero')->get();
            $saldo = Monto::cero();

            foreach ($cuotas as $cuota) {
                $saldo = $saldo->sumar($cuota->saldo());
            }

            $primera = $cuotas->first();

            $fotos[$codigo] = [
                'valor'  => $lote->montoValor(),
                'saldo'  => $saldo,
                'cuotas' => $cuotas->count(),
                'cuota'  => $primera instanceof Cuota ? $primera->montoTotal() : null,
            ];
        }

        return $fotos;
    }

    /**
     * Cuánto le tocó a cada lote de cada recibo: cuotas más capital.
     *
     * @param list<Recibo> $recibos
     * @param array<string, Compromiso> $lotes
     *
     * @return array<int, array<string, Monto>> por id de recibo, y adentro por código
     */
    private function repartos(array $recibos, array $lotes): array
    {
        $codigos = $this->codigosPorId($lotes);
        $repartos = [];

        foreach ($recibos as $recibo) {
            $id = (int) $recibo->getKey();
            $porLote = [];

            foreach ($this->aplicacionesDe($recibo) as $aplicacion) {
                $codigo = $codigos[$aplicacion['compromiso']] ?? null;

                if ($codigo !== null) {
                    $porLote[$codigo] = ($porLote[$codigo] ?? Monto::cero())->sumar($aplicacion['aplicado']);
                }
            }

            foreach (Reprogramacion::query()->where('recibo_id', $id)->get() as $constancia) {
                $codigo = $codigos[(int) $constancia->getAttribute('compromiso_id')] ?? null;

                if ($codigo !== null) {
                    $porLote[$codigo] = ($porLote[$codigo] ?? Monto::cero())->sumar($constancia->montoAbonado());
                }
            }

            ksort($porLote);
            $repartos[$id] = $porLote;
        }

        return $repartos;
    }

    /**
     * Lo que cada recibo quiso pagar, dicho de forma que sobreviva a que las
     * cuotas cambien de monto: QUÉ números pagó en cada lote, y a qué lote
     * mandó capital.
     *
     * @param list<Recibo> $recibos
     * @param array<string, Compromiso> $lotes
     *
     * @return list<array{recibo: Recibo, cuotas: array<int, list<int>>, abonos: array<int, array{modalidad: ModalidadDeReprogramacion, motivo: string}>}>
     */
    private function intenciones(array $recibos, array $lotes): array
    {
        $codigos = $this->codigosPorId($lotes);
        $intenciones = [];

        foreach ($recibos as $recibo) {
            // Por cuota y no por aplicación: un mismo recibo puede pagar una
            // cuota en dos tramos (la mitad de cuota y lo que el abono pone
            // al día) y eso sigue siendo pagarla entera.
            $porCuota = [];

            foreach ($this->aplicacionesDe($recibo) as $aplicacion) {
                $clave = $aplicacion['compromiso'].':'.$aplicacion['numero'];

                $porCuota[$clave] ??= [
                    'compromiso' => $aplicacion['compromiso'],
                    'numero'     => $aplicacion['numero'],
                    'aplicado'   => Monto::cero(),
                    'cuota'      => $aplicacion['cuota'],
                ];

                $porCuota[$clave]['aplicado'] = $porCuota[$clave]['aplicado']->sumar($aplicacion['aplicado']);
            }

            $cuotas = [];

            foreach ($porCuota as $pagada) {
                if (! $pagada['aplicado']->igualA($pagada['cuota'])) {
                    throw TraspasoDeValorInvalidoException::porCuotaPagadaEnPartes(
                        $codigos[$pagada['compromiso']] ?? 'sin código',
                        $pagada['numero'],
                    );
                }

                $cuotas[$pagada['compromiso']][] = $pagada['numero'];
            }

            $abonos = [];

            foreach (Reprogramacion::query()->where('recibo_id', $recibo->getKey())->orderBy('id')->get() as $constancia) {
                $motivo = $constancia->getAttribute('motivo');

                $abonos[(int) $constancia->getAttribute('compromiso_id')] = [
                    'modalidad' => $constancia->modalidadElegida() ?? ModalidadDeReprogramacion::AcortarPlazo,
                    'motivo'    => is_string($motivo) ? $motivo : '',
                ];
            }

            $intenciones[] = ['recibo' => $recibo, 'cuotas' => $cuotas, 'abonos' => $abonos];
        }

        return $intenciones;
    }

    /**
     * El primer vencimiento de cada lote: el plan nuevo arranca el mismo día.
     *
     * @param array<string, Compromiso> $lotes
     *
     * @return array<string, CarbonImmutable>
     */
    private function primerosVencimientos(array $lotes): array
    {
        $primeras = [];

        foreach ($lotes as $codigo => $lote) {
            /*
             * ⚠️ `value()` de Eloquent pasa por los casts del modelo: la fecha
             * llega como Carbon, no como texto. Preguntar solo por `is_string`
             * la descartaba en silencio, y sin primer vencimiento ningún plan
             * se podía rehacer — el traspaso entero se negaba.
             */
            $fecha = Cuota::query()
                ->where('compromiso_id', $lote->getKey())
                ->where('numero', 1)
                ->value('fecha_vencimiento');

            if ($fecha instanceof DateTimeInterface) {
                $primeras[$codigo] = CarbonImmutable::parse($fecha->format('Y-m-d'))->startOfDay();
            } elseif (is_string($fecha) && $fecha !== '') {
                $primeras[$codigo] = CarbonImmutable::parse(substr($fecha, 0, 10))->startOfDay();
            }
        }

        return $primeras;
    }

    // ─── Escribir ─────────────────────────────────────────────────────

    /**
     * Lo que se va a volver a escribir: las aplicaciones de estos recibos, sus
     * reprogramaciones y las cuotas de los lotes. Los recibos se quedan.
     *
     * @param array<string, Compromiso> $lotes
     * @param list<Recibo> $recibos
     */
    private function borrarLoAplicado(Venta $venta, array $lotes, array $recibos): void
    {
        $ids = array_map(static fn (Recibo $recibo): int => (int) $recibo->getKey(), $recibos);

        if ($ids !== []) {
            AplicacionDePago::query()->whereIn('recibo_id', $ids)->delete();
        }

        Reprogramacion::query()->where('venta_id', $venta->getKey())->delete();
        Cuota::query()->whereIn('compromiso_id', $this->idsDe($lotes))->delete();
    }

    /**
     * @param array{valor: Monto, precio: string, lista: string, plazo: int} $nuevo
     */
    private function revalorizar(Compromiso $lote, array $nuevo): void
    {
        // Todo en UNA sentencia: el CHECK de valor = área × precio se mira
        // por fila al final de la sentencia, con los dos ya cambiados.
        Compromiso::query()->whereKey($lote->getKey())->update([
            'valor'             => $nuevo['valor']->redondeado(),
            'precio_vara'       => $nuevo['precio'],
            'precio_vara_lista' => $nuevo['lista'],
            'plazo_meses'       => $nuevo['plazo'],
        ]);
    }

    /**
     * El plan del lote desde cero: mismo plazo, mismo primer vencimiento.
     */
    private function replanificar(Venta $venta, Compromiso $lote, string $codigo, ?CarbonImmutable $primera): void
    {
        $fresco = Compromiso::query()->whereKey($lote->getKey())->firstOrFail();
        $financiado = $fresco->montoValor()->restar(new Monto($this->textoDe($fresco, 'prima')));

        // Pagado con su prima: no lleva cuotas.
        if ($financiado->esCero()) {
            return;
        }

        if (! $primera instanceof CarbonImmutable) {
            throw TraspasoDeValorInvalidoException::porLoteDeContadoQueVolveriaADeber($codigo);
        }

        $plan = PlanDeCuotas::porPlazoFijo(
            $financiado,
            $this->plazoDe($fresco, $codigo),
            $this->diaDePago($venta),
            $primera,
        );

        if (! $plan->cierraExacto()) {
            throw TraspasoDeValorInvalidoException::porNoQuedarDondeDebia('El plan del lote '.$codigo, $plan->totalCapital(), $financiado);
        }

        $empieza = $plan->cuotas[0]->vencimiento ?? null;

        if (! $empieza instanceof CarbonImmutable || ! $empieza->isSameDay($primera)) {
            throw TraspasoDeValorInvalidoException::porPrimeraCuotaDistinta(
                $codigo,
                $primera->format('d/m/Y'),
                $empieza instanceof CarbonImmutable ? $empieza->format('d/m/Y') : 'ninguna',
            );
        }

        $ahora = now();
        $filas = [];

        foreach ($plan->cuotas as $cuota) {
            $filas[] = [
                'venta_id'          => $venta->getKey(),
                'compromiso_id'     => $fresco->getKey(),
                'numero'            => $cuota->numero,
                'fecha_vencimiento' => $cuota->vencimientoParaBase(),
                'monto'             => $cuota->montoParaBase(),
                'monto_capital'     => $cuota->capitalParaBase(),
                'monto_interes'     => $cuota->interesParaBase(),
                'monto_pagado'      => '0.00',
                'created_at'        => $ahora,
                'updated_at'        => $ahora,
            ];
        }

        Cuota::query()->insert($filas);
    }

    /**
     * Un recibo, otra vez, sobre las cuotas nuevas.
     *
     * Paga las MISMAS cuotas que había pagado, a su monto nuevo. Lo que sobre
     * va a capital en el lote al que el recibo había mandado capital. Si no
     * alcanza, o si sobra y no hay un solo lote a donde mandarlo, se niega:
     * adivinar sería acreditarle a un lote lo que se entregó por otro.
     *
     * @param array<string, Compromiso> $lotes
     * @param array{recibo: Recibo, cuotas: array<int, list<int>>, abonos: array<int, array{modalidad: ModalidadDeReprogramacion, motivo: string}>} $intencion
     */
    private function volverAAplicar(Venta $venta, array $lotes, array $intencion): void
    {
        $recibo = $intencion['recibo'];
        $folio = $recibo->folio();
        $porId = [];

        foreach ($lotes as $lote) {
            $porId[(int) $lote->getKey()] = $lote;
        }

        $aCuotas = [];
        $total = Monto::cero();

        foreach ($intencion['cuotas'] as $compromisoId => $numeros) {
            $monto = Monto::cero();

            foreach (Cuota::query()->where('compromiso_id', $compromisoId)->whereIn('numero', $numeros)->get() as $cuota) {
                $monto = $monto->sumar($cuota->montoTotal());
            }

            if (! $monto->esCero()) {
                $aCuotas[$compromisoId] = $monto;
                $total = $total->sumar($monto);
            }
        }

        $cobrado = $recibo->montoTotal();

        if ($total->mayorQue($cobrado)) {
            throw TraspasoDeValorInvalidoException::porReciboQueNoAlcanza($folio, $cobrado, $total);
        }

        $sobra = $cobrado->restar($total);
        $aCapital = [];

        if (! $sobra->esCero()) {
            $destinos = array_values(array_filter(
                array_keys($intencion['abonos']),
                $this->leQuedaSaldo(...),
            ));

            if (count($destinos) !== 1) {
                throw TraspasoDeValorInvalidoException::porSobranteSinLote($folio, $sobra);
            }

            $aCapital[$destinos[0]] = $sobra;
        }

        $ids = array_values(array_unique([...array_keys($aCuotas), ...array_keys($aCapital)]));
        sort($ids);

        $fecha = $recibo->getAttribute('fecha');
        $cuando = CarbonImmutable::parse($fecha instanceof DateTimeInterface ? $fecha->format('Y-m-d') : today()->toDateString());

        foreach ($ids as $id) {
            $abono = $intencion['abonos'][$id] ?? null;

            $this->pagos->reaplicar(
                venta: $venta,
                recibo: $recibo,
                lote: $porId[$id],
                aCuotas: $aCuotas[$id] ?? Monto::cero(),
                aCapital: $aCapital[$id] ?? Monto::cero(),
                modalidad: $abono['modalidad'] ?? ModalidadDeReprogramacion::AcortarPlazo,
                motivoDelAbono: $abono['motivo'] ?? '',
                cuando: $cuando,
            );
        }
    }

    // ─── Comprobar y asentar ──────────────────────────────────────────

    /**
     * @param array<string, array{valor: Monto, saldo: Monto, cuotas: int, cuota: Monto|null}> $antes
     * @param array<string, array{valor: Monto, saldo: Monto, cuotas: int, cuota: Monto|null}> $despues
     * @param array<int, array<string, Monto>> $repartoAntes
     * @param array<int, array<string, Monto>> $repartoDespues
     * @param list<Recibo> $recibos
     */
    private function armar(array $antes, array $despues, array $repartoAntes, array $repartoDespues, array $recibos): TraspasoHecho
    {
        $lotes = [];

        foreach ($antes as $codigo => $foto) {
            $luego = $despues[$codigo] ?? $foto;

            $lotes[] = new LoteTraspasado(
                codigo: $codigo,
                valorAntes: $foto['valor'],
                valorDespues: $luego['valor'],
                saldoAntes: $foto['saldo'],
                saldoDespues: $luego['saldo'],
                cuotasAntes: $foto['cuotas'],
                cuotasDespues: $luego['cuotas'],
                cuotaAntes: $foto['cuota'],
                cuotaDespues: $luego['cuota'],
            );
        }

        $papeles = [];

        foreach ($recibos as $recibo) {
            $id = (int) $recibo->getKey();

            $papeles[] = [
                'folio'   => $recibo->folio(),
                'monto'   => $recibo->montoTotal(),
                'antes'   => $repartoAntes[$id] ?? [],
                'despues' => $repartoDespues[$id] ?? [],
            ];
        }

        return new TraspasoHecho($lotes, $papeles);
    }

    /**
     * La última red, ya adentro de la transacción y leyendo de la BASE. Si
     * algo no da, se cae todo y no queda nada escrito.
     *
     * @param array<string, Compromiso> $lotes
     * @param list<Recibo> $recibos
     */
    private function comprobar(TraspasoHecho $hecho, array $lotes, array $recibos): void
    {
        // 1. El expediente debe exactamente lo mismo: un traspaso reparte.
        if (! $hecho->saldoDespues()->igualA($hecho->saldoAntes())) {
            throw TraspasoDeValorInvalidoException::porNoQuedarDondeDebia('El saldo del expediente', $hecho->saldoDespues(), $hecho->saldoAntes());
        }

        // 2. Cada recibo sigue aplicando lo que cobró.
        foreach ($recibos as $recibo) {
            $fresco = Recibo::query()->whereKey($recibo->getKey())->firstOrFail();

            if (! $fresco->cuadra()) {
                throw TraspasoDeValorInvalidoException::porReciboQueNoCuadra($fresco->folio());
            }
        }

        // 3. Cada lote: financiado = pagado a cuotas + abonado a capital + saldo.
        foreach ($lotes as $codigo => $lote) {
            $financiado = $lote->montoValor()->restar(new Monto($this->textoDe($lote, 'prima')));
            $cuenta = Monto::cero();

            foreach (Cuota::query()->where('compromiso_id', $lote->getKey())->get() as $cuota) {
                $cuenta = $cuenta->sumar($cuota->montoTotal());
            }

            foreach (Reprogramacion::query()->where('compromiso_id', $lote->getKey())->get() as $constancia) {
                $cuenta = $cuenta->sumar($constancia->montoAbonado());
            }

            if (! $cuenta->igualA($financiado)) {
                throw TraspasoDeValorInvalidoException::porNoQuedarDondeDebia('Lo financiado del lote '.$codigo, $cuenta, $financiado);
            }
        }
    }

    /**
     * El asiento en «Actualizaciones» del expediente: qué cambió, de cuánto a
     * cuánto, y por qué. Las claves son las palabras de la pantalla.
     */
    private function asentar(Venta $venta, TraspasoHecho $hecho, string $motivo): void
    {
        $viejos = [];
        $nuevos = [];

        foreach ($hecho->lotes as $lote) {
            $viejos['valor del lote '.$lote->codigo] = $lote->valorAntes->formateado();
            $nuevos['valor del lote '.$lote->codigo] = $lote->valorDespues->formateado();

            $viejos['saldo del lote '.$lote->codigo] = $lote->saldoAntes->formateado();
            $nuevos['saldo del lote '.$lote->codigo] = $lote->saldoDespues->formateado();

            $viejos['cuota del lote '.$lote->codigo] = $lote->cuotaAntes?->formateado() ?? 'sin cuotas';
            $nuevos['cuota del lote '.$lote->codigo] = $lote->cuotaDespues?->formateado() ?? 'sin cuotas';
        }

        foreach ($hecho->recibos as $papel) {
            $viejos['reparto del recibo '.$papel['folio']] = self::repartoEscrito($papel['antes']);
            $nuevos['reparto del recibo '.$papel['folio']] = self::repartoEscrito($papel['despues']);
        }

        activity()
            ->performedOn($venta)
            ->withChanges(['old' => $viejos, 'attributes' => $nuevos])
            ->withProperty('motivo', $motivo)
            ->event('traspaso')
            ->log('Valor traspasado entre los lotes del contrato');
    }

    /**
     * «RPS-O-004 L. 5,104.17 · RPS-N-008 L. 18,895.83».
     *
     * @param array<string, Monto> $porLote
     */
    public static function repartoEscrito(array $porLote): string
    {
        if ($porLote === []) {
            return '—';
        }

        $partes = [];

        foreach ($porLote as $codigo => $monto) {
            $partes[] = $codigo.' '.$monto->formateado();
        }

        return implode(' · ', $partes);
    }

    // ─── Menudencias ──────────────────────────────────────────────────

    /**
     * Las aplicaciones de un recibo, con el lote y el número de su cuota.
     *
     * @return list<array{compromiso: int, numero: int, aplicado: Monto, cuota: Monto}>
     */
    private function aplicacionesDe(Recibo $recibo): array
    {
        $filas = DB::table('aplicaciones_de_pago')
            ->join('cuotas', 'cuotas.id', '=', 'aplicaciones_de_pago.cuota_id')
            ->where('aplicaciones_de_pago.recibo_id', $recibo->getKey())
            ->orderBy('cuotas.compromiso_id')
            ->orderBy('cuotas.numero')
            ->get(['cuotas.compromiso_id', 'cuotas.numero', 'cuotas.monto AS de_la_cuota', 'aplicaciones_de_pago.monto AS aplicado']);

        $lista = [];

        foreach ($filas as $fila) {
            $datos = (array) $fila;

            $lista[] = [
                'compromiso' => $this->entero($datos['compromiso_id'] ?? 0),
                'numero'     => $this->entero($datos['numero'] ?? 0),
                'aplicado'   => $this->monto($datos['aplicado'] ?? '0'),
                'cuota'      => $this->monto($datos['de_la_cuota'] ?? '0'),
            ];
        }

        return $lista;
    }

    private function leQuedaSaldo(int $compromisoId): bool
    {
        return Cuota::query()
            ->where('compromiso_id', $compromisoId)
            ->whereColumn('monto_pagado', '<', 'monto')
            ->exists();
    }

    /**
     * El plazo con que se FIRMÓ el lote.
     *
     * No se cae al de la venta: `ventas.plazo_meses` es el horizonte de HOY,
     * y un abono que acortó el plazo ya lo bajó (`recalcularElResumen`).
     * Rehacer el plan con ese número le quitaría meses al lote sin que nadie
     * los haya pagado. Un lote vendido siempre trae el suyo; si no lo trae, se
     * pregunta antes de adivinar.
     */
    private function plazoDe(Compromiso $lote, string $codigo): int
    {
        $propio = $lote->getAttribute('plazo_meses');

        if ($propio === null || $propio === '') {
            throw TraspasoDeValorInvalidoException::porLoteSinPlazo($codigo);
        }

        return $this->entero($propio);
    }

    private function diaDePago(Venta $venta): int
    {
        return $this->entero($venta->getAttribute('dia_pago'));
    }

    /**
     * @param array<string, Compromiso> $lotes
     *
     * @return list<int>
     */
    private function idsDe(array $lotes): array
    {
        return array_values(array_map(static fn (Compromiso $lote): int => (int) $lote->getKey(), $lotes));
    }

    /**
     * @param array<string, Compromiso> $lotes
     *
     * @return array<int, string>
     */
    private function codigosPorId(array $lotes): array
    {
        $codigos = [];

        foreach ($lotes as $codigo => $lote) {
            $codigos[(int) $lote->getKey()] = $codigo;
        }

        return $codigos;
    }

    private function codigoDe(Compromiso $lote): string
    {
        $codigo = $lote->lote?->getAttribute('codigo');

        return is_string($codigo) && $codigo !== '' ? $codigo : 'lote sin código';
    }

    /**
     * Un NUMERIC tal como lo entrega Postgres: texto, sin pasar por float
     * (§8.3.1).
     */
    private function textoDe(Compromiso $lote, string $columna, string $siFalta = '0'): string
    {
        $valor = $lote->getAttribute($columna);

        return is_string($valor) || is_int($valor) ? (string) $valor : $siFalta;
    }

    private function monto(mixed $valor): Monto
    {
        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    private function entero(mixed $valor): int
    {
        if (is_int($valor)) {
            return $valor;
        }

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : 0;
    }
}
