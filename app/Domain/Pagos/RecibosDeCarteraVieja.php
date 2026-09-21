<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Exceptions\ReimputacionInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Models\Compromiso;
use App\Models\Recibo;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Acomodar los PAPELES de la cartera vieja a como la lotificadora los quiere
 * ver — 21-sep-2026.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * La cartera anterior al sistema entró transcribiendo un cuaderno, y después
 * se supo más: a nombre de quién sale el recibo de cada lote, o que un recibo
 * no debió quedar «anulado» porque en el cuaderno nadie anuló nada.
 * «No hay anulados, directamente se borran esas transacciones» y «los recibos
 * deben salir a estos nombres» — Mauricio.
 *
 * ═══ 🔴 SOLO CARTERA VIEJA, Y NO ES UN DETALLE ═══
 *
 * Todo lo de acá se niega con un recibo de la serie de un proyecto. Esos los
 * imprimió el sistema, el cliente tiene el papel con ese número, y la serie
 * no puede tener huecos (R12): se anulan, nunca se borran. Los de la serie
 * vieja —sin prefijo— son una transcripción: su número no está en ningún
 * papel, y el del talonario viaja en las observaciones, que se conservan.
 *
 * ═══ NINGUNA DE LAS DOS MUEVE DINERO ═══
 *
 * Borrar un anulado no cambia un saldo —un anulado ya no aplicaba nada—, y
 * partir la prima reparte el MISMO monto en un papel por nombre: la prima de
 * cada lote ya estaba congelada en su compromiso. Por eso acá no se toca una
 * sola cuota, y por eso no pasa por `RegistroDePagos`.
 *
 * El rastro de las dos queda en «Actualizaciones» del expediente.
 */
final readonly class RecibosDeCarteraVieja
{
    public function __construct(private ConsumoDeCorrelativos $correlativos) {}

    /**
     * Borra un recibo de la cartera vieja que YA está anulado.
     *
     * @throws ReimputacionInvalidaException
     */
    public function borrarElAnulado(Venta $venta, Recibo $recibo, string $motivo): void
    {
        $porQue = trim($motivo);

        if ($porQue === '') {
            throw ReimputacionInvalidaException::porFaltarElMotivo();
        }

        DB::transaction(function () use ($venta, $recibo, $porQue): void {
            $vivo = Recibo::query()->whereKey($recibo->getKey())->lockForUpdate()->first();

            if (! $vivo instanceof Recibo) {
                throw ReimputacionInvalidaException::porReciboQueYaNoExiste($recibo->folio());
            }

            $this->verificarQueSeaDeEstaCartera($venta, $vivo);

            if (! $vivo->estaAnulado()) {
                throw ReimputacionInvalidaException::porNoEstarAnulado($vivo->folio());
            }

            $this->quitarLaMencion($venta, $vivo);

            activity()
                ->performedOn($venta)
                ->withProperties([
                    'motivo'            => $porQue,
                    'recibo'            => $vivo->folio(),
                    'monto'             => $vivo->montoTotal()->formateado(),
                    'motivo_de_anulado' => $vivo->getAttribute('motivo_anulacion'),
                ])
                ->event('borrado')
                ->log(sprintf('Se borró el recibo anulado %s de la cartera vieja', $vivo->folio()));

            // Sus aplicaciones —la traza— y sus impresiones se van con él.
            $vivo->delete();
        });
    }

    /**
     * Parte el recibo de la prima en uno por cada titular de recibo.
     *
     * Con `$escribir` en falso hace todo, arma el retrato y lo deshace.
     *
     * @return list<array{folio: string, lotes: string, nombre: string, monto: Monto}> los papeles nuevos
     *
     * @throws ReimputacionInvalidaException
     */
    public function partirLaPrima(Venta $venta, string $motivo, bool $escribir): array
    {
        $porQue = trim($motivo);

        if ($escribir && $porQue === '') {
            throw ReimputacionInvalidaException::porFaltarElMotivo();
        }

        DB::beginTransaction();

        try {
            $papeles = $this->partir($venta, $porQue);
        } catch (Throwable $error) {
            DB::rollBack();

            throw $error;
        }

        if ($escribir) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        return $papeles;
    }

    /**
     * @return list<array{folio: string, lotes: string, nombre: string, monto: Monto}>
     */
    private function partir(Venta $venta, string $motivo): array
    {
        $primas = Recibo::query()
            ->where('venta_id', $venta->getKey())
            ->where('concepto', ConceptoDeRecibo::Prima->value)
            ->whereNull('anulado_el')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($primas->count() !== 1) {
            throw ReimputacionInvalidaException::porNoTenerUnaSolaPrima($primas->count());
        }

        $vieja = $primas->firstOrFail();

        $this->verificarQueSeaDeEstaCartera($venta, $vieja);

        $grupos = $this->lotesPorNombre($venta);

        if (count($grupos) < 2) {
            throw ReimputacionInvalidaException::porTenerUnSoloNombre();
        }

        $deLosLotes = Monto::cero();

        foreach ($grupos as $lotes) {
            $deLosLotes = $deLosLotes->sumar($this->primaDe($lotes));
        }

        if (! $deLosLotes->igualA($vieja->montoTotal())) {
            throw ReimputacionInvalidaException::porPrimaQueNoSuma($vieja->montoTotal(), $deLosLotes);
        }

        $papeles = [];
        $emitido = Monto::cero();

        foreach ($grupos as $lotes) {
            $primero = $lotes[0];
            $monto = $this->primaDe($lotes);
            $delTalonario = $this->correlativos->paraUnReciboNuevo($venta->proyecto, true);

            $nuevo = Recibo::query()->create([
                'numero'          => $delTalonario['numero'],
                'serie'           => $delTalonario['serie'],
                'venta_id'        => $venta->getKey(),
                'compromiso_id'   => count($lotes) === 1 ? $primero->getKey() : null,
                'cliente_id'      => $vieja->getAttribute('cliente_id'),
                'a_nombre_de'     => $primero->titularDelRecibo(),
                'a_nombre_de_dni' => $primero->dniDelTitularDelRecibo(),
                'concepto'        => ConceptoDeRecibo::Prima,
                'forma_pago'      => $vieja->getAttribute('forma_pago'),
                'recibido_por'    => $vieja->getAttribute('recibido_por'),
                'referencia'      => $vieja->getAttribute('referencia'),
                'monto'           => $monto->redondeado(),
                'fecha'           => $vieja->getAttribute('fecha'),
                'observaciones'   => $vieja->getAttribute('observaciones'),
            ]);

            $emitido = $emitido->sumar($monto);

            $papeles[] = [
                'folio'  => $nuevo->folio(),
                'lotes'  => implode(', ', array_map($this->codigoDe(...), $lotes)),
                'nombre' => $primero->titularDelRecibo() ?? 'el titular del expediente',
                'monto'  => $monto,
            ];
        }

        if (! $emitido->igualA($vieja->montoTotal())) {
            throw ReimputacionInvalidaException::porNoQuedarDondeDebia('Los recibos de prima nuevos', $emitido, $vieja->montoTotal());
        }

        $nuevos = [];

        foreach ($papeles as $papel) {
            $nuevos['prima de '.$papel['lotes']] = sprintf('%s · %s · %s', $papel['folio'], $papel['monto']->formateado(), $papel['nombre']);
        }

        activity()
            ->performedOn($venta)
            ->withChanges([
                'old'        => ['recibo de la prima' => sprintf('%s · %s', $vieja->folio(), $vieja->montoTotal()->formateado())],
                'attributes' => ['recibo de la prima' => 'un papel por titular de recibo', ...$nuevos],
            ])
            ->withProperty('motivo', $motivo)
            ->event('prima_partida')
            ->log('La prima se partió en un recibo por titular');

        $vieja->delete();

        return $papeles;
    }

    // ─── Piezas ───────────────────────────────────────────────────────

    private function verificarQueSeaDeEstaCartera(Venta $venta, Recibo $recibo): void
    {
        if ((int) $recibo->getAttribute('venta_id') !== (int) $venta->getKey()) {
            throw ReimputacionInvalidaException::porReciboDeOtroExpediente(
                $recibo->folio(),
                (string) $venta->getAttribute('numero_contrato'),
            );
        }

        if (! $recibo->esDeLaCarteraVieja()) {
            throw ReimputacionInvalidaException::porNoSerDeLaCarteraVieja($recibo->folio());
        }
    }

    /**
     * Los lotes vivos del expediente, agrupados por a nombre de quién sale su
     * recibo. Es el mismo criterio de `RegistroDePagos`: los que comparten
     * nombre —o no tienen ninguno— van en un mismo papel.
     *
     * @return list<non-empty-list<Compromiso>>
     */
    private function lotesPorNombre(Venta $venta): array
    {
        $grupos = [];

        $compromisos = Compromiso::query()
            ->where('venta_id', $venta->getKey())
            ->with('lote')
            ->orderBy('id')
            ->get();

        foreach ($compromisos as $compromiso) {
            if (! $compromiso->estaVigente()) {
                continue;
            }

            $grupos[$compromiso->titularDelRecibo() ?? ''][] = $compromiso;
        }

        return array_values($grupos);
    }

    /**
     * @param non-empty-list<Compromiso> $lotes
     */
    private function primaDe(array $lotes): Monto
    {
        $prima = Monto::cero();

        foreach ($lotes as $lote) {
            $suya = $lote->getAttribute('prima');
            $prima = $prima->sumar(new Monto(is_string($suya) || is_int($suya) ? $suya : '0'));
        }

        return $prima;
    }

    /**
     * El recibo que reemplazó al anulado lo nombraba en su nota. El anulado se
     * va, así que la mención también: una nota que nombra un recibo que no
     * existe manda a buscarlo a quien la lea.
     */
    private function quitarLaMencion(Venta $venta, Recibo $anulado): void
    {
        $mencion = sprintf('Reemplaza al recibo %s, anulado al re-imputarlo entre lotes.', $anulado->folio());

        $conMencion = Recibo::query()
            ->where('venta_id', $venta->getKey())
            ->where('observaciones', 'like', '%'.$mencion.'%')
            ->get();

        foreach ($conMencion as $recibo) {
            $nota = trim(str_replace($mencion, '', (string) $recibo->getAttribute('observaciones')));

            $recibo->update(['observaciones' => $nota === '' ? null : $nota]);
        }
    }

    private function codigoDe(Compromiso $lote): string
    {
        $codigo = $lote->lote?->getAttribute('codigo');

        return is_string($codigo) && $codigo !== '' ? $codigo : 'lote sin código';
    }
}
