<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Pagos\ReimputacionDeRecibo;
use App\Domain\Pagos\ReimputacionHecha;
use App\Domain\ValueObjects\Monto;
use App\Models\Recibo;
use App\Models\Venta;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Re-imputar un recibo de la cartera vieja entre los lotes de su expediente
 * — 21-sep-2026.
 *
 * El porqué, lo que hace y lo que se niega a hacer están en
 * `ReimputacionDeRecibo`, que es quien hace el trabajo: este comando solo lee
 * lo que el operador pidió y muestra el antes y el después.
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:reimputar-recibo ABC-2026-0031 000089 \
 *     --lote=ABC-T-001:32500.00 \
 *     --motivo="Todo el abono era del lote 1; lo confirmó el cliente" --ensayo
 *
 * `--lote` es cuánto de ESTE recibo le toca a ese lote. Se nombran solo los
 * lotes que reciben algo, y entre todos tienen que sumar el recibo exacto.
 *
 * 🔴 **Siempre `--ensayo` primero.** No es una estimación: anula, vuelve a
 * registrar, comprueba, imprime cómo quedó y deshace la transacción. Lo que
 * se ve es lo que va a quedar — incluidas las cuotas vencidas con que amanece
 * el lote que pierde el dinero, que es lo que hay que mirar antes de escribir.
 *
 * Correrlo dos veces no hace daño: la segunda encuentra el recibo ya anulado,
 * lo dice, y no escribe nada.
 */
#[Description('Re-imputa un recibo de la cartera vieja entre los lotes de su expediente: anula el viejo y registra el mismo dinero bien repartido.')]
#[Signature('olympo:reimputar-recibo
    {venta : El número de contrato del expediente, o el id de la venta}
    {recibo : El folio del recibo tal como sale en pantalla. Ejemplo: 000089}
    {--lote=* : CODIGO:MONTO — cuánto de este recibo va a ese lote. Entre todos suman el recibo}
    {--motivo= : Por qué se re-imputa. Obligatorio para escribir}
    {--ensayo : Hace todo, muestra cómo queda y lo deshace: no escribe nada}')]
final class ReimputarRecibo extends Command
{
    public function handle(ReimputacionDeRecibo $reimputacion): int
    {
        $venta = $this->venta();

        if (! $venta instanceof Venta) {
            $this->components->error('No encontré ese expediente. Va el número de contrato completo, o el id que sale en la URL.');

            return self::FAILURE;
        }

        $this->presentar($venta);

        $recibo = $this->recibo($venta);

        if (! $recibo instanceof Recibo) {
            $this->components->error('No encontré ese recibo en este expediente. Va el folio tal como sale en la pestaña Recibos. Ejemplo: 000089');

            return self::FAILURE;
        }

        $this->presentarElRecibo($recibo);

        $ensayo = $this->option('ensayo') === true;
        $motivo = $this->option('motivo');

        try {
            $pedido = $this->pedido();

            $hecha = $ensayo
                ? $reimputacion->ensayar($venta, $recibo, $pedido)
                : $reimputacion->reimputar($venta, $recibo, $pedido, is_string($motivo) ? $motivo : '');
        } catch (RuntimeException $error) {
            $this->components->error('No se escribió NADA: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->imprimir($hecha);

        if ($ensayo) {
            $this->components->info('Ensayo: no se escribió nada. El número del recibo nuevo se confirma al escribir.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Re-imputado. El %s quedó anulado con su motivo y lo reemplaza el %s.',
            $hecha->folioAnulado,
            implode(', ', $hecha->foliosNuevos),
        ));

        return self::SUCCESS;
    }

    /**
     * Por número de contrato o por id.
     *
     * El contrato es el camino bueno: es el mismo en local, en pruebas y en
     * producción, así que el comando que se ensayó en un ambiente se pega tal
     * cual en el otro. El id no —cada base numera por su cuenta—.
     *
     * ⚠️ `is_int` también: `$this->artisan('…', ['venta' => 31])` entrega el
     * entero tal cual, y con solo `is_string` el id se volvía cadena vacía.
     */
    private function venta(): ?Venta
    {
        $pedido = $this->argument('venta');
        $pedido = is_string($pedido) || is_int($pedido) ? trim((string) $pedido) : '';

        if ($pedido === '') {
            return null;
        }

        return ctype_digit($pedido)
            ? Venta::query()->whereKey((int) $pedido)->first()
            : Venta::query()->where('numero_contrato', mb_strtoupper($pedido))->first();
    }

    /**
     * El recibo por su folio, y SOLO adentro de este expediente.
     *
     * Se busca por folio y no por id porque el folio es lo que se ve en
     * pantalla, y adentro de la venta porque así un folio mal copiado no
     * encuentra el recibo de otro cliente: no encuentra nada.
     *
     * `000089` es de la serie vieja —sin prefijo—; `ABC-00000057` es de la
     * serie de un proyecto. Se acepta el segundo para poder contestarle con
     * el mensaje que explica por qué ese no se re-imputa por acá.
     */
    private function recibo(Venta $venta): ?Recibo
    {
        $pedido = $this->argument('recibo');
        $pedido = is_string($pedido) || is_int($pedido) ? mb_strtoupper(trim((string) $pedido)) : '';

        $corte = strrpos($pedido, '-');
        $serie = $corte === false ? null : substr($pedido, 0, $corte);
        $numero = $corte === false ? $pedido : substr($pedido, $corte + 1);

        if (! ctype_digit($numero)) {
            return null;
        }

        $consulta = Recibo::query()
            ->where('venta_id', $venta->getKey())
            ->where('numero', (int) $numero);

        return $serie === null
            ? $consulta->whereNull('serie')->first()
            : $consulta->where('serie', $serie)->first();
    }

    /**
     * De quién es el expediente, antes que cualquier número.
     */
    private function presentar(Venta $venta): void
    {
        $contrato = $venta->getAttribute('numero_contrato');
        $titular = $venta->titular()?->getAttribute('nombre');

        $this->components->twoColumnDetail(
            is_string($contrato) && $contrato !== '' ? $contrato : sprintf('Venta %d', (int) $venta->getKey()),
            is_string($titular) && $titular !== '' ? $titular : 'sin titular',
        );
    }

    private function presentarElRecibo(Recibo $recibo): void
    {
        $fecha = $recibo->getAttribute('fecha');
        $concepto = $recibo->getAttribute('concepto');

        $this->components->twoColumnDetail(
            sprintf(
                'Recibo %s · %s',
                $recibo->folio(),
                $concepto instanceof ConceptoDeRecibo ? $concepto->etiqueta() : 'sin concepto',
            ),
            sprintf(
                '%s · %s',
                $recibo->montoTotal()->formateado(),
                $fecha instanceof DateTimeInterface ? $fecha->format('d/m/Y') : 'sin fecha',
            ),
        );
    }

    /**
     * @return array<string, Monto>
     */
    private function pedido(): array
    {
        $pedido = [];

        /** @var list<string> $crudos */
        $crudos = (array) $this->option('lote');

        foreach ($crudos as $crudo) {
            $partes = explode(':', $crudo);

            if (count($partes) !== 2) {
                throw new RuntimeException("--lote mal escrito: «{$crudo}». Va CODIGO:MONTO. Ejemplo: --lote=ABC-T-001:32500.00");
            }

            [$codigo, $monto] = $partes;
            $codigo = mb_strtoupper(trim($codigo));

            if (preg_match('/^\d+(\.\d{1,2})?$/', trim($monto)) !== 1) {
                throw new RuntimeException("Monto mal escrito en --lote={$crudo}. Va sin comas ni símbolo. Ejemplo: 32500.00");
            }

            if (array_key_exists($codigo, $pedido)) {
                throw new RuntimeException("El lote {$codigo} está dos veces en el pedido.");
            }

            $pedido[$codigo] = new Monto(trim($monto));
        }

        return $pedido;
    }

    private function imprimir(ReimputacionHecha $hecha): void
    {
        $filas = [];
        $saldoAntes = Monto::cero();
        $saldoAhora = Monto::cero();

        foreach ($hecha->lotes as $lote) {
            $saldoAntes = $saldoAntes->sumar($lote->saldoAntes);
            $saldoAhora = $saldoAhora->sumar($lote->saldoDespues);

            $filas[] = [
                $lote->codigo,
                $lote->imputadoAntes->formateado(),
                $lote->imputadoDespues->formateado(),
                $lote->aCuotas->formateado(),
                $lote->aCapital->formateado(),
                $lote->saldoAntes->formateado(),
                $lote->saldoDespues->formateado(),
                sprintf('%d → %d', $lote->vencidasAntes, $lote->vencidasDespues),
            ];
        }

        $filas[] = ['TOTAL', $hecha->monto->formateado(), $hecha->monto->formateado(), '', '', $saldoAntes->formateado(), $saldoAhora->formateado(), ''];

        $this->table(
            ['Lote', 'Del recibo, hoy', 'Del recibo, corregido', '→ a cuotas', '→ a capital', 'Saldo hoy', 'Saldo corregido', 'Cuotas vencidas'],
            $filas,
        );

        $this->line(sprintf(
            '  Se anula el %s y lo reemplaza el %s, con la misma fecha, forma, referencia y nota.',
            $hecha->folioAnulado,
            implode(', ', $hecha->foliosNuevos),
        ));

        foreach ($hecha->lotes as $lote) {
            if ($lote->vencidasDespues > $lote->vencidasAntes) {
                $this->components->warn(sprintf(
                    'El lote %s queda con %d cuota(s) vencida(s) por %s: va a salir en la cobranza. Es lo que dice el reparto nuevo, pero conviene avisarle a la administración.',
                    $lote->codigo,
                    $lote->vencidasDespues,
                    $lote->debeVencidoDespues->formateado(),
                ));
            }
        }
    }
}
