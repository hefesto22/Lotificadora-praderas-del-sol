<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Models\Compromiso;
use App\Models\Recibo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ¿Cada recibo movió exactamente lo que dice el papel?
 *
 * ═══ 🔴 POR QUE EXISTE ═══
 *
 * El 27-ago-2026 la contratante preguntó por qué un cliente que había pagado
 * L 244,000.00 de L 805,000.00 seguía debiendo L 567,979.17 y no
 * L 561,000.00. El recibo RPS-00000005 decía L 24,000.00 y solo había movido
 * L 17,020.83: L 6,979.17 se habían quedado en el aire —ver el paso 5 de
 * `RegistroDePagos::cobrarYAbonarEnUnMismoNombre()`, que es donde estaba el
 * defecto y donde está arreglado—.
 *
 * Lo que hizo peligroso ese defecto no fue su dificultad: fue que **nada en
 * el sistema comparaba el monto del recibo contra lo que aplicó**. El del 0070
 * lo cazó un cliente preguntando; el segundo —RPS-00000010, L 5,000.00 del
 * expediente 0038— no lo había reportado nadie y salió en la primera corrida
 * de este comando.
 * Las dos mitades se escribían en la misma transacción y nadie las volvía a
 * mirar juntas. Este comando es esa mirada, y corre sobre la base entera.
 *
 * La regla, entera: **todo lempira de un recibo fue a una cuota o bajó el
 * capital**. Lo que no está en `aplicaciones_de_pago` tiene que estar en
 * `reprogramaciones.abono_capital`, al céntimo.
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:cuadrar-recibos              # solo mira y lista
 *   php artisan olympo:cuadrar-recibos --reparar    # escribe lo que falta
 *
 * Sin `--reparar` no escribe una sola fila. Con `--reparar` le aplica a las
 * cuotas del lote —FIFO, con el MISMO reparto del cobro— el dinero que el
 * recibo cobró y nunca aplicó.
 *
 * Devuelve código distinto de cero si queda algún recibo sin cuadrar, así que
 * sirve encadenado y en el despliegue.
 *
 * ⚠️ NO toca primas ni señas: esas no cuelgan de ninguna cuota y para ellas la
 * resta no significa nada.
 */
#[Description('Revisa que cada recibo cuadre con lo que aplicó; con --reparar escribe el pago que se perdió.')]
#[Signature('olympo:cuadrar-recibos {--reparar : Escribe la aplicación que falta en vez de solo listarla}')]
final class CuadrarRecibos extends Command
{
    /**
     * @var list<array{recibo: Recibo, lote: Compromiso|null, motivo: string}>
     */
    private array $hallazgos = [];

    public function handle(RegistroDePagos $pagos): int
    {
        $this->buscar();

        if ($this->hallazgos === []) {
            $this->components->info('Todos los recibos cuadran: lo que cobraron es lo que aplicaron.');

            return self::SUCCESS;
        }

        $this->listar();

        if (! $this->option('reparar')) {
            $this->newLine();
            $this->components->warn(
                'Nada se escribió. Para aplicar lo que falta: php artisan olympo:cuadrar-recibos --reparar'
            );

            return self::FAILURE;
        }

        return $this->reparar($pagos);
    }

    // ─── Las tres partes ──────────────────────────────────────────────

    /**
     * ⚠️ `lazyById` y no `get()`: una lotificadora con años de operación tiene
     * decenas de miles de recibos, esto se corre en el servidor, y el `with()`
     * trae cuatro relaciones por fila. De a 200 y sin cargar la base entera en
     * memoria.
     */
    private function buscar(): void
    {
        $recibos = Recibo::query()
            ->whereIn('concepto', [ConceptoDeRecibo::Cuota->value, ConceptoDeRecibo::AbonoCapital->value])
            ->whereNull('anulado_el')
            ->with(['aplicaciones', 'reprogramaciones.compromiso.lote', 'compromiso.lote', 'venta'])
            ->lazyById(200);

        foreach ($recibos as $recibo) {
            if ($recibo->cuadra()) {
                continue;
            }

            [$lote, $motivo] = $this->aQuienLeCorresponde($recibo);

            $this->hallazgos[] = ['recibo' => $recibo, 'lote' => $lote, 'motivo' => $motivo];
        }
    }

    private function listar(): void
    {
        $filas = [];

        foreach ($this->hallazgos as $hallazgo) {
            $recibo = $hallazgo['recibo'];

            $filas[] = [
                $recibo->folio(),
                (string) $recibo->getAttribute('fecha')?->format('d/m/Y'),
                (string) ($recibo->venta?->getAttribute('numero_contrato') ?? '—'),
                $recibo->montoTotal()->formateado(),
                $recibo->loQueAplico()->formateado(),
                $recibo->descuadre()->formateado(),
                $hallazgo['lote'] === null
                    ? $hallazgo['motivo']
                    : (string) $hallazgo['lote']->lote?->getAttribute('codigo'),
            ];
        }

        $this->newLine();
        $this->components->error(count($filas).' recibo(s) no cuadran con lo que aplicaron.');
        $this->table(['Recibo', 'Fecha', 'Contrato', 'Cobró', 'Aplicó', 'Falta', 'Va al lote'], $filas);
    }

    private function reparar(RegistroDePagos $pagos): int
    {
        $quedan = 0;

        foreach ($this->hallazgos as $hallazgo) {
            $recibo = $hallazgo['recibo'];
            $lote = $hallazgo['lote'];

            if (! $lote instanceof Compromiso) {
                $this->components->twoColumnDetail(
                    '  <fg=red>NO</>  '.$recibo->folio(),
                    '<fg=gray>'.$hallazgo['motivo'].'</>',
                );
                $quedan++;

                continue;
            }

            try {
                $aplicado = $pagos->cuadrarElRecibo($recibo, $lote);
            } catch (PagoInvalidoException $error) {
                $this->components->twoColumnDetail(
                    '  <fg=red>NO</>  '.$recibo->folio(),
                    '<fg=gray>'.$error->getMessage().'</>',
                );
                $quedan++;

                continue;
            }

            $this->components->twoColumnDetail(
                '  <fg=green>OK</>  '.$recibo->folio(),
                '<fg=gray>'.$aplicado->formateado().' aplicados a '
                    .$lote->lote?->getAttribute('codigo').'</>',
            );
        }

        $this->newLine();

        if ($quedan > 0) {
            $this->components->error($quedan.' recibo(s) siguen sin cuadrar: hay que mirarlos uno por uno.');

            return self::FAILURE;
        }

        $this->components->info('Listo. Cada recibo aplica exactamente lo que cobró.');

        return self::SUCCESS;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * A qué lote pertenece el dinero que se perdió.
     *
     * La constancia de reprogramación dice contra qué lote se abonó, y ese es
     * el único que pudo quedarse con dinero en el aire: el defecto vivía en el
     * paso del abono. Con DOS constancias en un mismo papel la respuesta ya no
     * es única —sería acreditarle a un lote lo que entregó el otro— y se
     * devuelve el motivo para que lo mire una persona.
     *
     * @return array{Compromiso|null, string}
     */
    private function aQuienLeCorresponde(Recibo $recibo): array
    {
        $constancias = $recibo->reprogramaciones;

        if ($constancias->count() === 1) {
            $lote = $constancias->first()?->compromiso;

            if ($lote instanceof Compromiso) {
                return [$lote, ''];
            }
        }

        if ($constancias->count() > 1) {
            return [null, 'Reprogramó '.$constancias->count().' lotes: hay que decidir a mano'];
        }

        $delRecibo = $recibo->compromiso;

        if ($delRecibo instanceof Compromiso) {
            return [$delRecibo, ''];
        }

        return [null, 'Sin constancia y sin lote propio: hay que decidir a mano'];
    }
}
