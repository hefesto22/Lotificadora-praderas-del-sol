<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PlanDeCuotas;
use App\Models\AplicacionDePago;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recuadrar los lotes de un expediente contra el cuaderno — 8-sep-2026.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * El expediente 0085 de Praderas —cinco lotes, un solo contrato— tenía el
 * dinero bien cobrado y **mal repartido**. Los recibos cuadraban al centavo,
 * la suma de los cinco saldos daba exactamente lo que decía el cuaderno, pero
 * lote por lote los números no coincidían: al RPS-W-005 le sobraban
 * L 46,928.31 de saldo y a los otros cuatro les faltaban en total lo mismo.
 *
 * Pasó porque el 8-ago y el 7-sep alguien tecleó un reparto distinto al del
 * cuaderno, y **el papel no decía a qué lote iba cada lempira** — eso se
 * arregló el 8-sep, pero un mes de recibos ya había salido mudo.
 *
 * ═══ QUE NO HACE, Y ES LO MAS IMPORTANTE ═══
 *
 * **No toca los recibos.** Ni el número, ni la fecha, ni el monto, ni quién
 * recibió. Los papeles que el cliente tiene en la mano dicen «recibí
 * L 43,500.00» y eso es verdad: lo que estaba mal era la imputación interna.
 * Anular y reemitir quemaría correlativos y obligaría a pedirle al cliente
 * papeles que ya se llevó firmados.
 *
 * **No toca las aplicaciones a cuotas.** En el caso que lo motivó estaban
 * todas bien; lo único mal repartido eran los abonos a capital.
 *
 * **No crea ni destruye dinero.** Las tres invariantes de §VERIFICAR lo
 * imponen: si el recuadre no cuadra, no se escribe nada.
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:recuadrar-venta 82 \
 *     --lote=RPS-W-005:253116.00 --lote=RPS-F-003:218500.00 \
 *     --abono=000204:RPS-W-005:18299.67 --abono=RPS-00000057:RPS-W-005:36799.67 \
 *     --motivo="Recuadre contra el cuaderno, expediente 0085" --ensayo
 *
 * `--lote` es el saldo que el cuaderno dice que le queda a ese lote.
 * `--abono` es **el set COMPLETO** de abonos a capital de la venta: los que
 * cambian y los que no. Se pide entero a propósito — decir solo las
 * diferencias deja al operador adivinando qué pasa con lo que no nombró.
 *
 * 🔴 **Siempre `--ensayo` primero.** Imprime la misma tabla que va a escribir
 * y no toca una fila.
 *
 * ═══ 🔴 LAS TRES INVARIANTES ═══
 *
 * 1. La suma de los saldos objetivo es **igual** a la suma de los saldos de
 *    hoy. Un recuadre reparte; no perdona ni inventa deuda.
 * 2. Por cada recibo: lo aplicado a cuotas + los abonos nuevos es **igual** al
 *    monto del recibo. El papel sigue diciendo la verdad.
 * 3. Por cada lote: financiado − (lo pagado a cuotas + los abonos nuevos) es
 *    **igual** al objetivo. Esto ata las dos mitades: el operador escribe los
 *    objetivos Y los abonos, y el comando se niega si no se sostienen entre
 *    sí. Es la red que hace que un dedo mal puesto no llegue a la base.
 *
 * Las tres se verifican ANTES de escribir, y los saldos se vuelven a verificar
 * DESPUES, ya adentro de la transacción. Si algo no da, `throw` y rollback.
 */
#[Description('Recuadra los saldos por lote de una venta contra el cuaderno, sin tocar los recibos.')]
#[Signature('olympo:recuadrar-venta
    {venta : El id de la venta}
    {--lote=* : CODIGO:SALDO_OBJETIVO — uno por lote, todos los del contrato}
    {--abono=* : NUMERO_RECIBO:CODIGO_LOTE:MONTO — el set COMPLETO de abonos}
    {--motivo= : Por qué se recuadra. Obligatorio para escribir}
    {--ensayo : Imprime lo que haría y no escribe nada}')]
final class RecuadrarVenta extends Command
{
    public function handle(RegistroDePagos $pagos): int
    {
        $venta = Venta::query()
            ->with(['compromisos.lote', 'compromisos.cuotas'])
            ->findOrFail((int) $this->argument('venta'));

        try {
            $objetivos = $this->objetivos($venta);
            $abonos = $this->abonos($venta);
            $lotes = $this->retratos($venta, $objetivos, $abonos);

            $this->verificar($venta, $lotes, $abonos);
        } catch (RuntimeException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $this->imprimir($lotes, $abonos);

        if ($this->option('ensayo') === true) {
            $this->components->info('Ensayo: no se escribió nada.');

            return self::SUCCESS;
        }

        $motivo = trim((string) $this->option('motivo'));

        if ($motivo === '') {
            $this->components->error('Falta --motivo. Un recuadre sin motivo es un saldo que cambió sin que nadie tenga que explicarlo.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($venta, $lotes, $abonos, $motivo, $pagos): void {
                $this->escribir($venta, $lotes, $abonos, $motivo);
                $pagos->recalcularElResumen($venta);
                $this->comprobar($lotes);
            });
        } catch (RuntimeException $error) {
            $this->components->error('No se escribió NADA: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Recuadre aplicado. Los recibos no se tocaron.');

        return self::SUCCESS;
    }

    // ─── Leer lo que el operador pidió ────────────────────────────────

    /**
     * @return array<string, Monto>
     */
    private function objetivos(Venta $venta): array
    {
        $codigos = $this->codigosDe($venta);
        $objetivos = [];

        /** @var list<string> $crudos */
        $crudos = (array) $this->option('lote');

        foreach ($crudos as $crudo) {
            $partes = explode(':', $crudo);

            if (count($partes) !== 2) {
                throw new RuntimeException("--lote mal escrito: «{$crudo}». Va CODIGO:SALDO.");
            }

            [$codigo, $saldo] = $partes;

            if (! in_array($codigo, $codigos, true)) {
                throw new RuntimeException("El lote {$codigo} no es de esta venta.");
            }

            $objetivos[$codigo] = $this->monto($saldo, "--lote={$crudo}");
        }

        foreach ($codigos as $codigo) {
            if (! array_key_exists($codigo, $objetivos)) {
                throw new RuntimeException("Falta el objetivo del lote {$codigo}. Se piden TODOS los del contrato.");
            }
        }

        return $objetivos;
    }

    /**
     * Los abonos nuevos, agrupados por recibo y por lote.
     *
     * @return array<string, array<string, Monto>>
     */
    private function abonos(Venta $venta): array
    {
        $codigos = $this->codigosDe($venta);
        $abonos = [];

        /** @var list<string> $crudos */
        $crudos = (array) $this->option('abono');

        foreach ($crudos as $crudo) {
            $partes = explode(':', $crudo);

            if (count($partes) !== 3) {
                throw new RuntimeException("--abono mal escrito: «{$crudo}». Va RECIBO:LOTE:MONTO.");
            }

            [$folio, $codigo, $monto] = $partes;

            if (! in_array($codigo, $codigos, true)) {
                throw new RuntimeException("El lote {$codigo} del abono no es de esta venta.");
            }

            $abonos[$folio][$codigo] = $this->monto($monto, "--abono={$crudo}");
        }

        return $abonos;
    }

    // ─── El retrato de cada lote ──────────────────────────────────────

    /**
     * @param array<string, Monto> $objetivos
     * @param array<string, array<string, Monto>> $abonos
     *
     * @return list<array{lote: Compromiso, codigo: string, financiado: Monto, cuotas: Monto, abonos: Monto, saldoHoy: Monto, objetivo: Monto}>
     */
    private function retratos(Venta $venta, array $objetivos, array $abonos): array
    {
        $retratos = [];

        foreach ($venta->compromisos as $lote) {
            $codigo = (string) ($lote->lote?->getAttribute('codigo') ?? '');

            if ($codigo === '') {
                continue;
            }

            $delLote = Monto::cero();

            foreach ($abonos as $porLote) {
                if (array_key_exists($codigo, $porLote)) {
                    $delLote = $delLote->sumar($porLote[$codigo]);
                }
            }

            $retratos[] = [
                'lote'       => $lote,
                'codigo'     => $codigo,
                'financiado' => $this->financiadoDe($lote),
                'cuotas'     => $this->pagadoACuotas($lote),
                'abonos'     => $delLote,
                'saldoHoy'   => $this->saldoDe($lote),
                'objetivo'   => $objetivos[$codigo],
            ];
        }

        usort($retratos, static fn (array $uno, array $otro): int => strcmp($uno['codigo'], $otro['codigo']));

        return $retratos;
    }

    private function financiadoDe(Compromiso $lote): Monto
    {
        $valor = new Monto((string) ($lote->getAttribute('valor') ?? '0'));
        $prima = new Monto((string) ($lote->getAttribute('prima') ?? '0'));

        return $valor->restar($prima);
    }

    private function pagadoACuotas(Compromiso $lote): Monto
    {
        $total = Monto::cero();

        foreach ($lote->cuotas as $cuota) {
            $total = $total->sumar($cuota->montoPagado());
        }

        return $total;
    }

    private function saldoDe(Compromiso $lote): Monto
    {
        $total = Monto::cero();

        foreach ($lote->cuotas as $cuota) {
            $total = $total->sumar($cuota->saldo());
        }

        return $total;
    }

    // ─── 🔴 VERIFICAR: las tres invariantes, antes de escribir ────────

    /**
     * @param list<array{lote: Compromiso, codigo: string, financiado: Monto, cuotas: Monto, abonos: Monto, saldoHoy: Monto, objetivo: Monto}> $lotes
     * @param array<string, array<string, Monto>> $abonos
     */
    private function verificar(Venta $venta, array $lotes, array $abonos): void
    {
        // 1. Un recuadre reparte: no perdona ni inventa deuda.
        $hoy = Monto::cero();
        $objetivo = Monto::cero();

        foreach ($lotes as $renglon) {
            $hoy = $hoy->sumar($renglon['saldoHoy']);
            $objetivo = $objetivo->sumar($renglon['objetivo']);
        }

        if (! $hoy->igualA($objetivo)) {
            throw new RuntimeException(sprintf(
                'La suma de los objetivos (%s) no es la suma de los saldos de hoy (%s). Un recuadre reparte, no perdona ni inventa deuda.',
                $objetivo->formateado(),
                $hoy->formateado(),
            ));
        }

        // 2. El papel sigue diciendo la verdad: cuotas + abonos = monto.
        foreach ($abonos as $folio => $porLote) {
            $recibo = $this->reciboDe($venta, $folio);
            $suma = $recibo->montoAplicadoACuotas();

            foreach ($porLote as $monto) {
                $suma = $suma->sumar($monto);
            }

            if (! $suma->igualA($recibo->montoTotal())) {
                throw new RuntimeException(sprintf(
                    'El recibo %s cobró %s y los abonos nuevos más sus cuotas suman %s. El papel tiene que seguir diciendo la verdad.',
                    $recibo->folio(),
                    $recibo->montoTotal()->formateado(),
                    $suma->formateado(),
                ));
            }
        }

        // 3. Los objetivos y los abonos se sostienen entre sí.
        foreach ($lotes as $renglon) {
            $daria = $renglon['financiado']->restar($renglon['cuotas']->sumar($renglon['abonos']));

            if (! $daria->igualA($renglon['objetivo'])) {
                throw new RuntimeException(sprintf(
                    'El lote %s: financiado %s − cuotas %s − abonos %s = %s, y el objetivo dice %s. No se sostienen entre sí.',
                    $renglon['codigo'],
                    $renglon['financiado']->formateado(),
                    $renglon['cuotas']->formateado(),
                    $renglon['abonos']->formateado(),
                    $daria->formateado(),
                    $renglon['objetivo']->formateado(),
                ));
            }
        }
    }

    private function reciboDe(Venta $venta, string $folio): Recibo
    {
        foreach (Recibo::query()->where('venta_id', $venta->getKey())->get() as $recibo) {
            if ($recibo->folio() === $folio || (string) $recibo->getAttribute('numero') === ltrim($folio, '0')) {
                return $recibo;
            }
        }

        throw new RuntimeException("No encontré el recibo «{$folio}» en esta venta.");
    }

    // ─── Lo que se imprime, en ensayo y de verdad ─────────────────────

    /**
     * @param list<array{lote: Compromiso, codigo: string, financiado: Monto, cuotas: Monto, abonos: Monto, saldoHoy: Monto, objetivo: Monto}> $lotes
     * @param array<string, array<string, Monto>> $abonos
     */
    private function imprimir(array $lotes, array $abonos): void
    {
        $filas = [];

        foreach ($lotes as $renglon) {
            $filas[] = [
                $renglon['codigo'],
                $renglon['saldoHoy']->formateado(),
                $renglon['objetivo']->formateado(),
                $renglon['saldoHoy']->igualA($renglon['objetivo']) ? '=' : 'cambia',
            ];
        }

        $this->table(['Lote', 'Saldo hoy', 'Objetivo', ''], $filas);

        $this->components->info('Abonos a capital que quedan escritos:');

        foreach ($abonos as $folio => $porLote) {
            foreach ($porLote as $codigo => $monto) {
                $this->line(sprintf('  %s · %s : %s', $folio, $codigo, $monto->formateado()));
            }
        }
    }

    // ─── Escribir ─────────────────────────────────────────────────────

    /**
     * @param list<array{lote: Compromiso, codigo: string, financiado: Monto, cuotas: Monto, abonos: Monto, saldoHoy: Monto, objetivo: Monto}> $lotes
     * @param array<string, array<string, Monto>> $abonos
     */
    private function escribir(Venta $venta, array $lotes, array $abonos, string $motivo): void
    {
        $porCodigo = [];

        foreach ($lotes as $renglon) {
            $porCodigo[$renglon['codigo']] = $renglon;
        }

        /*
         * 1. A qué fila le toca cada abono. Se REUSAN las filas que ya existen
         *    —emparejadas por recibo, en orden— en vez de borrarlas y crear
         *    otras: así el `plan_anterior` de cada una y su `created_at`
         *    sobreviven. Una constancia borrada es historia que nadie puede
         *    volver a leer.
         */
        $destino = [];

        foreach ($abonos as $folio => $porLote) {
            $recibo = $this->reciboDe($venta, $folio);

            /** @var list<Reprogramacion> $existentes */
            $existentes = Reprogramacion::query()
                ->where('recibo_id', $recibo->getKey())
                ->orderBy('id')
                ->get()
                ->all();

            if (count($existentes) !== count($porLote)) {
                throw new RuntimeException(sprintf(
                    'El recibo %s tiene %d constancia(s) y le estás pidiendo %d abono(s). Eso cambia la forma del recibo y este comando no lo hace.',
                    $recibo->folio(),
                    count($existentes),
                    count($porLote),
                ));
            }

            $codigos = array_keys($porLote);
            sort($codigos);

            foreach ($codigos as $puesto => $codigo) {
                $destino[(int) $recibo->getKey()][$codigo] = $existentes[$puesto];
            }
        }

        /*
         * 2. 🔴 LOS SALDOS DE CADA CONSTANCIA SE REPLICAN, NO SE DEJAN.
         *
         * La base tiene `reprogramaciones_saldo_cuadra_chk`:
         * `saldo_nuevo = saldo_anterior - abono_capital`, y su migración lo
         * dice sin rodeos — «un centavo de diferencia tumba la transacción
         * entera».
         *
         * ⚠️ Y tumbó la primera corrida en producción, el 9-sep-2026: la
         * versión inicial de este comando cambiaba `abono_capital` y dejaba
         * los dos saldos como estaban. El CHECK la paró antes de escribir una
         * fila. **La constancia no es un renglón suelto: es la aritmética de
         * un momento**, así que cambiar el abono obliga a recalcular el antes
         * y el después.
         *
         * Se replica la historia del lote: se arranca del financiado y se
         * caminan los recibos en orden de id, restando lo que cada uno aplicó
         * a las cuotas de ESE lote y después su abono. Es la misma cuenta que
         * hizo el sistema el día del cobro, rehecha con los montos del
         * cuaderno.
         */
        $aplicado = $this->aplicadoACuotas($venta);

        foreach ($lotes as $renglon) {
            $codigo = $renglon['codigo'];
            $cuota = $this->cuotaDe($renglon['lote']);
            $saldo = $renglon['financiado'];

            foreach ($this->recibosEnOrden($venta) as $recibo) {
                $id = (int) $recibo->getKey();

                $saldo = $saldo->restar($aplicado[$id][$codigo] ?? Monto::cero());

                $constancia = $destino[$id][$codigo] ?? null;

                if (! $constancia instanceof Reprogramacion) {
                    continue;
                }

                $antes = $saldo;
                $saldo = $saldo->restar($abonos[$recibo->folio()][$codigo]);

                $constancia->update([
                    'compromiso_id'  => $renglon['lote']->getKey(),
                    'abono_capital'  => $abonos[$recibo->folio()][$codigo]->redondeado(),
                    'saldo_anterior' => $antes->redondeado(),
                    'saldo_nuevo'    => $saldo->redondeado(),
                    'cuotas_antes'   => $this->cuantasCuotas($antes, $cuota),
                    'cuotas_despues' => $this->cuantasCuotas($saldo, $cuota),
                ]);
            }

            /*
             * La comprobación constructiva: si la réplica no termina en el
             * objetivo, los abonos que se pidieron no cuentan esta historia.
             * La invariante 3 ya lo verificó por suma; esto lo verifica por
             * camino.
             */
            if (! $saldo->igualA($renglon['objetivo'])) {
                throw new RuntimeException(sprintf(
                    'Replicando la historia del lote %s se llega a %s y el objetivo es %s.',
                    $codigo,
                    $saldo->formateado(),
                    $renglon['objetivo']->formateado(),
                ));
            }
        }

        // 3. El plan de cada lote, con la MISMA cuota de siempre.
        foreach ($lotes as $renglon) {
            $this->reescribir($venta, $renglon['lote'], $renglon['objetivo']);
            $this->asentar($venta, $renglon, $motivo);
        }
    }

    /**
     * Lo que cada recibo aplicó a las cuotas de cada lote.
     *
     * @return array<int, array<string, Monto>> id del recibo => [código => monto]
     */
    private function aplicadoACuotas(Venta $venta): array
    {
        $porRecibo = [];

        $aplicaciones = AplicacionDePago::query()
            ->whereIn('recibo_id', Recibo::query()->where('venta_id', $venta->getKey())->select('id'))
            ->with('cuota.compromiso.lote')
            ->get();

        foreach ($aplicaciones as $aplicacion) {
            $codigo = (string) ($aplicacion->cuota?->compromiso?->lote?->getAttribute('codigo') ?? '');

            if ($codigo === '') {
                continue;
            }

            $id = (int) $aplicacion->getAttribute('recibo_id');
            $antes = $porRecibo[$id][$codigo] ?? Monto::cero();

            $porRecibo[$id][$codigo] = $antes->sumar($aplicacion->montoAplicado());
        }

        return $porRecibo;
    }

    /**
     * Los recibos de la venta, en el orden en que ocurrieron.
     *
     * ⚠️ Por id y no por fecha: dos recibos del mismo día se aplicaron en el
     * orden en que se emitieron, y ese orden decide de qué saldo parte cada
     * abono. Si el orden decide dinero, ese orden se escribe.
     *
     * @return list<Recibo>
     */
    private function recibosEnOrden(Venta $venta): array
    {
        /** @var list<Recibo> $recibos */
        $recibos = Recibo::query()
            ->where('venta_id', $venta->getKey())
            ->orderBy('id')
            ->get()
            ->all();

        return $recibos;
    }

    /**
     * La cuota mensual de un lote: la de su primera cuota pendiente.
     */
    private function cuotaDe(Compromiso $lote): Monto
    {
        $primera = Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->orderBy('numero')
            ->get()
            ->first(static fn (Cuota $cuota): bool => ! $cuota->saldo()->esCero());

        if (! $primera instanceof Cuota) {
            throw new RuntimeException(sprintf(
                'El lote %s no tiene cuotas pendientes de las que leer la cuota mensual.',
                (string) ($lote->lote?->getAttribute('codigo') ?? '?'),
            ));
        }

        return $primera->montoTotal();
    }

    /**
     * Cuántas cuotas de ese tamaño hacen falta para ese saldo.
     *
     * Redondea para ARRIBA porque la última puede ser más chica — es como
     * arma el plan `PlanDeCuotas::porCuotaFija()`, y es lo que ya dicen las
     * constancias que escribió el sistema.
     */
    private function cuantasCuotas(Monto $saldo, Monto $cuota): int
    {
        if ($saldo->esCero() || $cuota->esCero()) {
            return 0;
        }

        return (int) ceil($saldo->enCentavos() / $cuota->enCentavos());
    }

    /**
     * Reescribe las cuotas PENDIENTES de un lote para que su saldo sea el
     * objetivo, con la misma cuota mensual y el mismo calendario.
     *
     * ⚠️ Solo se borran las cuotas con `monto_pagado` en cero. Una cuota
     * pagada a medias es dinero que alguien entregó: si aparece una, el
     * comando se cae en vez de decidir qué hacer con ella.
     */
    private function reescribir(Venta $venta, Compromiso $lote, Monto $objetivo): void
    {
        $pendientes = Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->orderBy('numero')
            ->get()
            ->filter(static fn (Cuota $cuota): bool => ! $cuota->saldo()->esCero());

        foreach ($pendientes as $cuota) {
            if (! $cuota->montoPagado()->esCero()) {
                throw new RuntimeException(sprintf(
                    'El lote %s tiene la cuota %s pagada a medias. Este comando no decide qué hacer con eso.',
                    (string) ($lote->lote?->getAttribute('codigo') ?? '?'),
                    (string) $cuota->getAttribute('numero'),
                ));
            }
        }

        $primera = $pendientes->first();

        if (! $primera instanceof Cuota) {
            throw new RuntimeException(sprintf(
                'El lote %s no tiene cuotas pendientes que reescribir.',
                (string) ($lote->lote?->getAttribute('codigo') ?? '?'),
            ));
        }

        $cuotaMensual = $primera->montoTotal();
        $desde = (int) $primera->getAttribute('numero');
        $vence = $primera->getAttribute('fecha_vencimiento');
        $desdeCuando = $vence instanceof DateTimeInterface
            ? CarbonImmutable::parse($vence->format('Y-m-d'))
            : CarbonImmutable::parse(today()->toDateString());

        $plan = PlanDeCuotas::porCuotaFija(
            $objetivo,
            $cuotaMensual,
            (int) $venta->getAttribute('dia_pago'),
            $desdeCuando,
            $desde,
        );

        if (! $plan->cierraExacto()) {
            throw new RuntimeException(sprintf(
                'El plan nuevo del lote %s no cierra al céntimo.',
                (string) ($lote->lote?->getAttribute('codigo') ?? '?'),
            ));
        }

        Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->whereIn('numero', $pendientes->map(static fn (Cuota $c): int => (int) $c->getAttribute('numero'))->all())
            ->delete();

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

        if ($filas !== []) {
            Cuota::query()->insert($filas);
        }
    }

    /**
     * @param array{lote: Compromiso, codigo: string, financiado: Monto, cuotas: Monto, abonos: Monto, saldoHoy: Monto, objetivo: Monto} $renglon
     */
    private function asentar(Venta $venta, array $renglon, string $motivo): void
    {
        activity()
            ->performedOn($venta)
            ->withChanges([
                'old'        => ['saldo '.$renglon['codigo'] => $renglon['saldoHoy']->formateado()],
                'attributes' => ['saldo '.$renglon['codigo'] => $renglon['objetivo']->formateado()],
            ])
            ->withProperty('motivo', $motivo)
            ->withProperty('lote', $renglon['codigo'])
            ->event('recuadre')
            ->log('Recuadre contra el cuaderno');
    }

    /**
     * La última red, ya adentro de la transacción: si un solo lote no quedó en
     * su objetivo, se cae todo y no queda nada escrito.
     *
     * @param list<array{lote: Compromiso, codigo: string, financiado: Monto, cuotas: Monto, abonos: Monto, saldoHoy: Monto, objetivo: Monto}> $lotes
     */
    private function comprobar(array $lotes): void
    {
        foreach ($lotes as $renglon) {
            $quedo = Monto::cero();

            foreach (Cuota::query()->where('compromiso_id', $renglon['lote']->getKey())->get() as $cuota) {
                $quedo = $quedo->sumar($cuota->saldo());
            }

            if (! $quedo->igualA($renglon['objetivo'])) {
                throw new RuntimeException(sprintf(
                    'El lote %s quedó en %s y el objetivo era %s.',
                    $renglon['codigo'],
                    $quedo->formateado(),
                    $renglon['objetivo']->formateado(),
                ));
            }
        }
    }

    // ─── Menudencias ──────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function codigosDe(Venta $venta): array
    {
        $codigos = [];

        foreach ($venta->compromisos as $lote) {
            $codigo = (string) ($lote->lote?->getAttribute('codigo') ?? '');

            if ($codigo !== '') {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }

    private function monto(string $crudo, string $donde): Monto
    {
        if (preg_match('/^\d+(\.\d{1,2})?$/', trim($crudo)) !== 1) {
            throw new RuntimeException("Monto mal escrito en {$donde}: «{$crudo}».");
        }

        return new Monto(trim($crudo));
    }
}
