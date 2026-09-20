<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Enums\EstadoVenta;
use App\Domain\Exceptions\CorreccionDeValorInvalidaException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Corregir el VALOR de un lote ya vendido, cuando se cargó distinto al
 * contrato — 20-sep-2026.
 *
 * ═══ POR QUE EXISTE, SI UN LOTE VENDIDO «NO SE EDITA» ═══
 *
 * Porque esa regla protege lo que se FIRMO, y acá lo que está mal es lo que se
 * CARGO. El caso que lo pidió: un contrato de tres lotes por L 975,000.00 cuya
 * cuota —L 19,604.00 por 48 meses— no cierra: 48 cuotas dan L 940,992.00 y lo
 * financiado eran L 941,000.00. Al transcribirlo se «respetó la cuota»
 * bajándole el valor al contrato, y el cliente, con su papel en la mano,
 * preguntó por los L 8.00 que el sistema no le mostraba. Tenía razón: el valor
 * es lo que dice el contrato, y un residuo que no cabe en las cuotas va a la
 * ULTIMA (R1) — que es lo que el sistema hace desde siempre en una venta nueva.
 *
 * ═══ QUE HACE ═══
 *
 * Por cada lote nombrado mueve, con UNA sola diferencia y en el mismo sentido:
 *
 *   1. El valor congelado del lote, y su precio por unidad de área, que se
 *      deriva dividiendo (seis decimales, para que área × precio cierre exacto).
 *   2. La ULTIMA cuota del lote. La cuota mensual del cliente no cambia.
 *   3. El plan viejo que guarda cada reprogramación del lote (más abajo).
 *   4. El valor y el saldo financiado del expediente.
 *
 * ═══ QUE NO HACE, Y ES LO MAS IMPORTANTE ═══
 *
 * **No toca un recibo, ni una aplicación de pago, ni la prima.** Todo lo que el
 * cliente entregó sigue exactamente donde estaba; lo único que cambia es contra
 * cuánto se resta.
 *
 * **No reprograma.** Una diferencia más grande que una cuota ya no es un
 * residuo: cambia el plan del cliente y esa conversación no es de un comando.
 * Se niega, y de paso es la red contra un cero de más al teclear.
 *
 * **No corrige lotes con interés**: ahí mover el capital mueve el interés de
 * cada cuota que falta, y eso es reamortizar.
 *
 * 🔴 **No toca la FICHA del lote, y no puede.** La primera versión la llevaba
 * al día «si decía lo mismo que el contrato», por el query builder, para
 * esquivar `LoteInmutableException`. La base tiene la última palabra: el
 * trigger `lotes_proteger_vendido` rechaza cambiarle área, precio o valor a un
 * lote vendido venga de donde venga —«un seeder, un import o un tinker la
 * saltearían sin enterarse», dice su migración—, y tumbó la transacción entera
 * en los once tests que llegaban ahí. Está bien que gane: lo que vale para una
 * venta es lo congelado en `compromisos` (§8.2), que es lo que acá se corrige.
 * La ficha se queda con el precio con que se cargó, y si el lote vuelve al
 * plano algún día, ahí se le pone precio de nuevo.
 *
 * ═══ 🔴 POR QUE TOCA LAS REPROGRAMACIONES, QUE «SON HISTORIA» ═══
 *
 * Porque `plan_anterior` no es solo historia: **es una instrucción**.
 * `RegistroDePagos::anular()` deshace un abono a capital borrando el plan
 * vigente y reescribiendo ese plan viejo tal cual. Si el plan guardado no
 * llevara la diferencia, anular un abono el mes que viene se la volvería a
 * comer en silencio — el mismo error, por otra puerta y sin que nadie lo vea.
 *
 * Por eso la última cuota de cada plan guardado se mueve igual que la del plan
 * vigente, y los dos saldos de la constancia con ella: el CHECK
 * `reprogramaciones_saldo_cuadra_chk` exige que sigan cuadrando, y cuadran
 * porque los dos se corren lo mismo. El abono de cada constancia no se toca.
 *
 * ═══ 🔴 LA IGUALDAD QUE SE VERIFICA ANTES Y DESPUES ═══
 *
 *   valor − prima − lo pagado a cuotas − lo abonado a capital
 *                                  = lo que deben las cuotas del lote
 *
 * Es la cuenta que el cliente hace con su papel. Si no da ANTES de corregir,
 * el expediente ya traía otro problema y corregir encima lo taparía: se niega.
 * Si no da DESPUES, se cae la transacción entera y no queda nada escrito.
 *
 * ═══ UN SOLO ASIENTO EN LA BITACORA, Y CON EL MOTIVO ═══
 *
 * `Venta` y `Compromiso` se registran solos, pero ese asiento automático
 * escribe nombres de columna y no tiene dónde poner el porqué. Se apaga durante
 * los `update()` y se escribe uno a mano contra el expediente, con las palabras
 * de la pantalla. Es el mismo trato que `CorreccionDeRecibo`.
 */
final readonly class CorreccionDeValor
{
    public function __construct(private RegistroDePagos $pagos) {}

    /**
     * Lo que haría `corregir()`, sin escribir nada.
     *
     * @param array<string, Monto> $valores código del lote => el valor que dice el contrato
     *
     * @return list<ValorCorregido> uno por lote nombrado, cambie o no
     *
     * @throws CorreccionDeValorInvalidaException
     */
    public function ensayar(Venta $venta, array $valores): array
    {
        return $this->retratos($venta, $valores, bloquear: false);
    }

    /**
     * Aplica la corrección y la asienta. Volver a correrla no cambia nada:
     * un lote que ya vale lo que se pide se deja como está.
     *
     * @param array<string, Monto> $valores código del lote => el valor que dice el contrato
     *
     * @return list<ValorCorregido> uno por lote nombrado, cambie o no
     *
     * @throws CorreccionDeValorInvalidaException
     */
    public function corregir(Venta $venta, array $valores, string $motivo): array
    {
        $porQue = trim($motivo);

        if ($porQue === '') {
            throw CorreccionDeValorInvalidaException::porFaltarElMotivo();
        }

        return DB::transaction(function () use ($venta, $valores, $porQue): array {
            /*
             * Se relee adentro de la transacción: el estado con el que se
             * decide tiene que ser el de AHORA, no el del objeto que alguien
             * cargó antes de llamar (§8.3.2).
             *
             * ⚠️ `whereKey()->firstOrFail()` y NO `findOrFail()`: este último
             * está tipado `Venta|Collection` en nivel 7.
             */
            $viva = Venta::query()->whereKey($venta->getKey())->firstOrFail();

            $retratos = $this->retratos($viva, $valores, bloquear: true);

            $cambios = array_values(array_filter(
                $retratos,
                static fn (ValorCorregido $retrato): bool => $retrato->cambia(),
            ));

            if ($cambios === []) {
                // Ya estaba corregido. Ni un UPDATE ni un asiento: correr esto
                // dos veces no puede ensuciar la bitácora.
                return $retratos;
            }

            foreach ($cambios as $cambio) {
                $this->escribir($cambio);
            }

            $this->moverElResumen($viva, $cambios);
            $this->asentar($viva, $cambios, $porQue);
            $this->comprobar($cambios);

            $venta->refresh();

            return $retratos;
        });
    }

    // ─── El retrato: todo lo que se decide, se decide ANTES de escribir ───

    /**
     * @param array<string, Monto> $valores
     *
     * @return list<ValorCorregido>
     */
    private function retratos(Venta $venta, array $valores, bool $bloquear): array
    {
        if ($valores === []) {
            throw CorreccionDeValorInvalidaException::porNoNombrarLotes();
        }

        if (! $venta->estaVigente()) {
            $estado = $venta->getAttribute('estado');

            throw CorreccionDeValorInvalidaException::porExpedienteQueNoEstaVigente(
                $estado instanceof EstadoVenta ? mb_strtolower($estado->etiqueta()) : 'cerrado',
            );
        }

        /*
         * 🔴 Por id, SIEMPRE (§9.D15). El orden en que se recorren los lotes
         * es el orden en que se bloquean sus cuotas, y es el mismo en que las
         * bloquea un cobro. Dos procesos que bloquean en orden distinto se
         * traban entre sí.
         */
        $lotes = Compromiso::query()
            ->where('venta_id', $venta->getKey())
            ->with('lote')
            ->orderBy('id')
            ->get();

        $porCodigo = [];

        foreach ($lotes as $lote) {
            $porCodigo[$this->codigoDe($lote)] = $lote;
        }

        foreach (array_keys($valores) as $codigo) {
            if (! array_key_exists($codigo, $porCodigo)) {
                throw CorreccionDeValorInvalidaException::porLoteQueNoEsDeLaVenta($codigo);
            }
        }

        $retratos = [];

        foreach ($porCodigo as $codigo => $lote) {
            if (array_key_exists($codigo, $valores)) {
                $retratos[] = $this->retratoDe($lote, $codigo, $valores[$codigo], $bloquear);
            }
        }

        return $retratos;
    }

    private function retratoDe(Compromiso $lote, string $codigo, Monto $nuevo, bool $bloquear): ValorCorregido
    {
        if (! $lote->estaVigente()) {
            throw CorreccionDeValorInvalidaException::porLoteQueYaNoEstaVivo($codigo);
        }

        $cuotas = $this->cuotasDe($lote, $bloquear);
        $antes = $lote->montoValor();
        $saldo = $this->saldoDe($cuotas);

        $precioAntes = $this->textoDe($lote, 'precio_vara');
        $listaAntes = $this->textoDe($lote, 'precio_vara_lista');

        $ultima = $cuotas->last();

        if ($nuevo->igualA($antes)) {
            $monto = $ultima instanceof Cuota ? $ultima->montoTotal() : Monto::cero();

            return new ValorCorregido(
                compromisoId: (int) $lote->getKey(),
                codigo: $codigo,
                valorAntes: $antes,
                valorDespues: $nuevo,
                precioAntes: $precioAntes,
                precioDespues: $precioAntes,
                listaDespues: $listaAntes,
                saldoAntes: $saldo,
                saldoDespues: $saldo,
                ultimaCuota: 0,
                ultimaAntes: $monto,
                ultimaDespues: $monto,
            );
        }

        if ($nuevo->esCero()) {
            throw CorreccionDeValorInvalidaException::porValorEnCero($codigo);
        }

        if (! $lote->tasaDeInteres()->esCero()) {
            throw CorreccionDeValorInvalidaException::porLlevarInteres($codigo);
        }

        $prima = new Monto($this->textoDe($lote, 'prima'));

        if ($prima->mayorQue($nuevo)) {
            throw CorreccionDeValorInvalidaException::porPrimaMayorAlValor($codigo, $prima, $nuevo);
        }

        if (! $ultima instanceof Cuota) {
            throw CorreccionDeValorInvalidaException::porLoteSinCuotas($codigo);
        }

        /*
         * 🔴 EL PRECIO Y EL VALOR SALEN DEL MISMO NUMERO REDONDEADO.
         *
         * `compromisos_valor_es_area_por_precio_chk` compara el valor contra
         * área × precio GUARDADO. Se redondea el precio una vez, a los seis
         * decimales de la columna, y se comprueba acá que ese precio devuelve
         * exactamente el valor pedido — antes de que lo compruebe Postgres,
         * que lo diría peor.
         */
        $area = $this->textoDe($lote, 'area_varas');
        $precio = new Monto($nuevo->dividirPor($area)->redondeado(Lote::DECIMALES_DEL_PRECIO));
        $daria = new Monto($precio->multiplicarPor($area)->redondeado());

        if (! $daria->igualA($nuevo)) {
            throw CorreccionDeValorInvalidaException::porValorQueNoCierraConElArea($codigo, $nuevo, $daria);
        }

        /*
         * El de lista acompaña al pactado SOLO si eran el mismo número: ahí no
         * hubo descuento y no tiene que aparecer uno por corregir. Si eran
         * distintos hubo un precio negociado, y el de lista —lo que el lote
         * costaba— no es lo que se cargó mal.
         */
        $sinDescuento = new Monto($listaAntes)->igualA(new Monto($precioAntes));
        $lista = $sinDescuento ? $precio : new Monto($listaAntes);

        if ($precio->menorQue($lista) && trim($this->textoDe($lote, 'motivo_descuento', '')) === '') {
            throw CorreccionDeValorInvalidaException::porQuedarBajoElPrecioDeListaSinMotivo($codigo);
        }

        $retrato = new ValorCorregido(
            compromisoId: (int) $lote->getKey(),
            codigo: $codigo,
            valorAntes: $antes,
            valorDespues: $nuevo,
            precioAntes: $precioAntes,
            precioDespues: $precio->redondeado(Lote::DECIMALES_DEL_PRECIO),
            listaDespues: $lista->redondeado(Lote::DECIMALES_DEL_PRECIO),
            saldoAntes: $saldo,
            saldoDespues: $saldo,
            ultimaCuota: (int) $ultima->getAttribute('numero'),
            ultimaAntes: $ultima->montoTotal(),
            ultimaDespues: $ultima->montoTotal(),
        );

        $this->verificarQueSeaUnResiduo($retrato, $cuotas);
        $this->verificarQueLaUltimaAbsorba($retrato, $ultima);
        $this->verificarLosPlanesViejos($retrato, $lote);
        $this->verificarQueCuadre($retrato, $lote, $cuotas, $antes->restar($prima), $saldo);

        return new ValorCorregido(
            compromisoId: $retrato->compromisoId,
            codigo: $codigo,
            valorAntes: $antes,
            valorDespues: $nuevo,
            precioAntes: $precioAntes,
            precioDespues: $retrato->precioDespues,
            listaDespues: $retrato->listaDespues,
            saldoAntes: $saldo,
            saldoDespues: $retrato->mover($saldo),
            ultimaCuota: $retrato->ultimaCuota,
            ultimaAntes: $retrato->ultimaAntes,
            ultimaDespues: $retrato->mover($retrato->ultimaAntes),
        );
    }

    // ─── Las guardas ──────────────────────────────────────────────────

    /**
     * Una diferencia más grande que una cuota no es un residuo.
     *
     * @param Collection<int, Cuota> $cuotas
     */
    private function verificarQueSeaUnResiduo(ValorCorregido $retrato, Collection $cuotas): void
    {
        $mayor = Monto::cero();

        foreach ($cuotas as $cuota) {
            if ($cuota->montoTotal()->mayorQue($mayor)) {
                $mayor = $cuota->montoTotal();
            }
        }

        if (! $retrato->diferencia()->menorQue($mayor)) {
            throw CorreccionDeValorInvalidaException::porDiferenciaMasGrandeQueUnaCuota(
                $retrato->codigo,
                $retrato->diferencia(),
                $mayor,
            );
        }
    }

    /**
     * La última cuota tiene que poder llevar la diferencia, y seguir debiendo.
     *
     * Para arriba: si ya está pagada, el lote terminó de pagarse y subirle el
     * valor sería inventarle una cuota. Para abajo: tiene que quedar debiendo
     * ALGO — si quedara en cero el lote pasaría a estar pagado, y eso lo cierra
     * un cobro (que liquida el expediente y lo fecha), no una corrección.
     */
    private function verificarQueLaUltimaAbsorba(ValorCorregido $retrato, Cuota $ultima): void
    {
        $debe = $ultima->saldo();

        if ($retrato->sube()) {
            if ($debe->esCero()) {
                throw CorreccionDeValorInvalidaException::porLoteQueYaNoDebe($retrato->codigo);
            }

            return;
        }

        if (! $retrato->diferencia()->menorQue($debe)) {
            throw CorreccionDeValorInvalidaException::porUltimaCuotaQueNoAbsorbe(
                $retrato->codigo,
                $retrato->diferencia(),
                $debe,
            );
        }
    }

    /**
     * Lo mismo, para la última cuota de cada plan viejo que se va a mover.
     * Solo puede fallar bajando el valor.
     */
    private function verificarLosPlanesViejos(ValorCorregido $retrato, Compromiso $lote): void
    {
        if ($retrato->sube()) {
            return;
        }

        foreach ($this->constanciasDe($lote, bloquear: false) as $constancia) {
            $ultima = $this->ultimaDelPlanViejo($constancia);

            if ($ultima === null) {
                continue;
            }

            if (! $retrato->diferencia()->menorQue(new Monto($ultima['monto']))) {
                throw CorreccionDeValorInvalidaException::porPlanViejoQueNoAbsorbe(
                    $retrato->codigo,
                    (int) $constancia->getKey(),
                );
            }
        }
    }

    /**
     * La igualdad del docblock de la clase, ANTES de tocar nada.
     *
     * @param Collection<int, Cuota> $cuotas
     */
    private function verificarQueCuadre(
        ValorCorregido $retrato,
        Compromiso $lote,
        Collection $cuotas,
        Monto $financiado,
        Monto $saldo,
    ): void {
        $entregado = $this->pagadoACuotas($cuotas)->sumar($this->abonadoACapital($lote));

        /*
         * Se compara sumando y no restando: `Monto` no admite negativos, y un
         * expediente descuadrado es justo el caso donde la resta podría dar
         * uno. Así el que avisa es este mensaje y no un «monto negativo».
         */
        if (! $saldo->sumar($entregado)->igualA($financiado)) {
            $deberia = $financiado->mayorQue($entregado) ? $financiado->restar($entregado) : Monto::cero();

            throw CorreccionDeValorInvalidaException::porExpedienteQueYaNoCuadraba($retrato->codigo, $deberia, $saldo);
        }
    }

    // ─── Escribir ─────────────────────────────────────────────────────

    private function escribir(ValorCorregido $cambio): void
    {
        $lote = Compromiso::query()->whereKey($cambio->compromisoId)->firstOrFail();

        // 1. El valor congelado. El asiento automático se apaga: va uno solo,
        //    a mano, contra el expediente (ver el docblock de la clase).
        $lote->disableLogging();

        $lote->update([
            'valor'             => $cambio->valorDespues->redondeado(),
            'precio_vara'       => $cambio->precioDespues,
            'precio_vara_lista' => $cambio->listaDespues,
        ]);

        $lote->enableLogging();

        // 2. La última cuota. Sin interés todo el monto es capital, así que
        //    las dos columnas se mueven juntas (`cuotas_partes_suman_el_monto_chk`).
        $ultima = Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->where('numero', $cambio->ultimaCuota)
            ->firstOrFail();

        $ultima->update([
            'monto'         => $cambio->mover($ultima->montoTotal())->redondeado(),
            'monto_capital' => $cambio->mover($ultima->montoCapital())->redondeado(),
        ]);

        // 3. El plan viejo de cada constancia, que `anular()` reescribe tal cual.
        foreach ($this->constanciasDe($lote, bloquear: true) as $constancia) {
            $this->moverElPlanViejo($constancia, $cambio);
        }
    }

    /**
     * Corre la última cuota del plan guardado y los dos saldos de la constancia.
     *
     * ⚠️ Se trabaja sobre el arreglo CRUDO y no sobre `planAnterior()`: ese
     * método filtra las filas a una forma conocida, y reescribir la columna con
     * lo filtrado borraría cualquier clave que otra versión haya guardado ahí.
     */
    private function moverElPlanViejo(Reprogramacion $constancia, ValorCorregido $cambio): void
    {
        $ultima = $this->ultimaDelPlanViejo($constancia);

        if ($ultima === null) {
            // No reemplazó ninguna cuota: no hay plan que `anular()` pueda
            // devolver, así que no hay nada que llevar al día.
            return;
        }

        $plan = (array) $constancia->getAttribute('plan_anterior');
        $fila = (array) $plan[$ultima['indice']];

        $fila['monto'] = $cambio->mover(new Monto($ultima['monto']))->redondeado();
        $plan[$ultima['indice']] = $fila;

        $constancia->update([
            'plan_anterior'  => $plan,
            'saldo_anterior' => $cambio->mover($constancia->montoSaldoAnterior())->redondeado(),
            'saldo_nuevo'    => $cambio->mover($constancia->montoSaldoNuevo())->redondeado(),
        ]);
    }

    /**
     * La cuota de número más alto del plan guardado, y dónde está.
     *
     * @return array{indice: int|string, monto: string}|null
     */
    private function ultimaDelPlanViejo(Reprogramacion $constancia): ?array
    {
        $plan = $constancia->getAttribute('plan_anterior');

        if (! is_array($plan)) {
            return null;
        }

        $ultima = null;
        $numeroMasAlto = 0;

        foreach ($plan as $indice => $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $numero = $fila['numero'] ?? null;
            $monto = $fila['monto'] ?? null;

            if (! is_int($numero)) {
                continue;
            }

            if (! is_string($monto)) {
                continue;
            }

            if (! is_numeric($monto)) {
                continue;
            }

            if ($numero > $numeroMasAlto) {
                $numeroMasAlto = $numero;
                $ultima = ['indice' => $indice, 'monto' => $monto];
            }
        }

        return $ultima;
    }

    /**
     * El valor y el saldo financiado del expediente, en UN solo `update()`:
     * `ventas_saldo_cuadra_chk` exige que los dos se muevan juntos.
     *
     * @param list<ValorCorregido> $cambios
     */
    private function moverElResumen(Venta $venta, array $cambios): void
    {
        $valor = $venta->montoValorTotal();
        $saldo = $venta->montoSaldoFinanciar();

        // Primero todo lo que sube y después lo que baja: con el orden al
        // revés, un contrato donde un lote baja y otro sube podría pasar por
        // un negativo a mitad de camino sin que el resultado lo sea.
        foreach ($cambios as $cambio) {
            if ($cambio->sube()) {
                $valor = $cambio->mover($valor);
                $saldo = $cambio->mover($saldo);
            }
        }

        foreach ($cambios as $cambio) {
            if (! $cambio->sube()) {
                $valor = $cambio->mover($valor);
                $saldo = $cambio->mover($saldo);
            }
        }

        $venta->disableLogging();

        $venta->update([
            'valor_total'     => $valor->redondeado(),
            'saldo_financiar' => $saldo->redondeado(),
        ]);

        $venta->enableLogging();

        // La cuota del primer mes y el horizonte son un resumen de `cuotas`.
        // Casi nunca se mueven acá —solo si la última cuota es también la
        // primera— pero se recalculan por la misma puerta que usa un cobro.
        $this->pagos->recalcularElResumen($venta);
    }

    /**
     * El asiento: qué cambió, de cuánto a cuánto, y por qué.
     *
     * 🔴 `withChanges()` y NO `withProperties()`: la pestaña «Actualizaciones»
     * del expediente pinta `attribute_changes`. Las claves son las palabras de
     * la pantalla —lo va a leer la administradora, no un programador—.
     *
     * @param list<ValorCorregido> $cambios
     */
    private function asentar(Venta $venta, array $cambios, string $motivo): void
    {
        $viejos = [];
        $nuevos = [];

        foreach ($cambios as $cambio) {
            $viejos['valor del lote '.$cambio->codigo] = $cambio->valorAntes->formateado();
            $nuevos['valor del lote '.$cambio->codigo] = $cambio->valorDespues->formateado();

            $viejos[sprintf('cuota %d del lote %s', $cambio->ultimaCuota, $cambio->codigo)] = $cambio->ultimaAntes->formateado();
            $nuevos[sprintf('cuota %d del lote %s', $cambio->ultimaCuota, $cambio->codigo)] = $cambio->ultimaDespues->formateado();
        }

        $saldoAntes = Monto::cero();
        $saldoDespues = Monto::cero();

        foreach ($cambios as $cambio) {
            $saldoAntes = $saldoAntes->sumar($cambio->saldoAntes);
            $saldoDespues = $saldoDespues->sumar($cambio->saldoDespues);
        }

        $viejos['saldo de los lotes corregidos'] = $saldoAntes->formateado();
        $nuevos['saldo de los lotes corregidos'] = $saldoDespues->formateado();

        activity()
            ->performedOn($venta)
            ->withChanges(['old' => $viejos, 'attributes' => $nuevos])
            ->withProperty('motivo', $motivo)
            ->event('correccion')
            ->log('Valor corregido contra el contrato');
    }

    /**
     * La última red, ya adentro de la transacción y leyendo de la BASE: el
     * saldo de cada lote quedó donde se dijo, y la igualdad sigue valiendo con
     * el valor nuevo. Si algo no da, se cae todo y no queda nada escrito.
     *
     * @param list<ValorCorregido> $cambios
     */
    private function comprobar(array $cambios): void
    {
        foreach ($cambios as $cambio) {
            $lote = Compromiso::query()->whereKey($cambio->compromisoId)->firstOrFail();
            $cuotas = $this->cuotasDe($lote, bloquear: false);

            $quedo = $this->saldoDe($cuotas);

            if (! $quedo->igualA($cambio->saldoDespues)) {
                throw CorreccionDeValorInvalidaException::porNoQuedarDondeDebia($cambio->codigo, $quedo, $cambio->saldoDespues);
            }

            $financiado = $lote->montoValor()->restar(new Monto($this->textoDe($lote, 'prima')));
            $entregado = $this->pagadoACuotas($cuotas)->sumar($this->abonadoACapital($lote));

            if (! $quedo->sumar($entregado)->igualA($financiado)) {
                throw CorreccionDeValorInvalidaException::porNoQuedarDondeDebia(
                    $cambio->codigo,
                    $quedo,
                    $financiado->mayorQue($entregado) ? $financiado->restar($entregado) : Monto::cero(),
                );
            }
        }
    }

    // ─── Menudencias ──────────────────────────────────────────────────

    /**
     * Las cuotas del lote, por número. Bloqueadas cuando se va a escribir.
     *
     * @return Collection<int, Cuota>
     */
    private function cuotasDe(Compromiso $lote, bool $bloquear): Collection
    {
        $consulta = Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->orderBy('numero');

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return $consulta->get();
    }

    /**
     * @return Collection<int, Reprogramacion>
     */
    private function constanciasDe(Compromiso $lote, bool $bloquear): Collection
    {
        $consulta = Reprogramacion::query()
            ->where('compromiso_id', $lote->getKey())
            ->orderBy('id');

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return $consulta->get();
    }

    /**
     * @param Collection<int, Cuota> $cuotas
     */
    private function saldoDe(Collection $cuotas): Monto
    {
        $saldo = Monto::cero();

        foreach ($cuotas as $cuota) {
            $saldo = $saldo->sumar($cuota->saldo());
        }

        return $saldo;
    }

    /**
     * @param Collection<int, Cuota> $cuotas
     */
    private function pagadoACuotas(Collection $cuotas): Monto
    {
        $pagado = Monto::cero();

        foreach ($cuotas as $cuota) {
            $pagado = $pagado->sumar($cuota->montoPagado());
        }

        return $pagado;
    }

    /**
     * Lo que bajó el capital de este lote. Un abono anulado no cuenta: al
     * anularlo su constancia se borra.
     */
    private function abonadoACapital(Compromiso $lote): Monto
    {
        $abonado = Monto::cero();

        foreach ($this->constanciasDe($lote, bloquear: false) as $constancia) {
            $abonado = $abonado->sumar($constancia->montoAbonado());
        }

        return $abonado;
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
}
