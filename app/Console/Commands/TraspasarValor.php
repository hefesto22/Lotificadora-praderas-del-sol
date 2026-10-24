<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\TraspasoDeValor;
use App\Domain\Ventas\TraspasoHecho;
use App\Models\Venta;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Mover valor entre los lotes de un mismo contrato — 24-oct-2026.
 *
 * El porqué, lo que mueve y lo que se niega a hacer están en
 * `TraspasoDeValor`, que es quien hace el trabajo: este comando solo lee lo
 * que el operador pidió, muestra el antes y el después, y escribe si no es un
 * ensayo.
 *
 * ═══ EN QUÉ SE DIFERENCIA DE `olympo:corregir-valor` ═══
 *
 * `corregir-valor` cambia lo que el contrato VALE: el total sube o baja y la
 * diferencia va a la última cuota. Este NO cambia el total: reparte el mismo
 * valor de otra forma entre los lotes, rehace sus planes y vuelve a aplicar
 * los recibos. Si los valores pedidos no suman lo mismo que hoy, se niega y
 * manda al otro comando.
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:traspasar-valor 70 \
 *     --lote=RPS-O-003:200000.00 --lote=RPS-O-004:255000.00 --lote=RPS-N-008:350000.00 \
 *     --motivo="Cuaderno pág. 147: el O-3 queda pagado con su prima" --ensayo
 *
 * `--lote` es el VALOR NUEVO de cada lote, y van TODOS los del contrato: así
 * quien lo escribe tiene que haber hecho la suma, y la máquina la comprueba.
 *
 * 🔴 **Siempre `--ensayo` primero.** No estima: corre todo de verdad y
 * deshace la transacción, así que lo que imprime es lo que va a quedar.
 */
#[Description('Mueve valor entre los lotes de un mismo contrato sin cambiar el total; rehace los planes y vuelve a aplicar los recibos sin tocar el papel.')]
#[Signature('olympo:traspasar-valor
    {venta : El número de contrato (RPS-2026-0070) o el id de la venta, que es el de la URL del expediente}
    {--lote=* : CODIGO:VALOR — el valor nuevo de cada lote; van todos los del contrato}
    {--motivo= : Por qué se traspasa. Obligatorio para escribir}
    {--ensayo : Imprime lo que haría y no escribe nada}')]
final class TraspasarValor extends Command
{
    public function handle(TraspasoDeValor $traspaso): int
    {
        $venta = $this->venta();

        if (! $venta instanceof Venta) {
            $this->components->error('No encontré ese expediente. Va el número de contrato tal como sale impreso, o el id que aparece en la URL del expediente.');

            return self::FAILURE;
        }

        $this->presentar($venta);

        try {
            $valores = $this->valores();
            $hecho = $traspaso->ensayar($venta, $valores);
        } catch (RuntimeException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $this->imprimir($hecho);

        if ($this->option('ensayo') === true) {
            $this->components->info('Ensayo: no se escribió nada.');

            return self::SUCCESS;
        }

        $motivo = $this->option('motivo');

        try {
            $traspaso->traspasar($venta, $valores, is_string($motivo) ? $motivo : '');
        } catch (RuntimeException $error) {
            $this->components->error('No se escribió NADA: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Traspasado. El expediente sigue valiendo %s y debiendo %s. Los recibos no se tocaron: solo a qué cuota cayó cada lempira.',
            $venta->montoValorTotal()->formateado(),
            $venta->saldoPendiente()->formateado(),
        ));

        return self::SUCCESS;
    }

    /**
     * Por número de contrato o por id. Mismo criterio que
     * `olympo:corregir-valor`: el contrato se pega igual en todos los
     * ambientes; el id es lo que uno tiene a mano mirando la URL.
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
     * De quién es el expediente, antes que cualquier número: un id mal
     * copiado es el expediente de otro cliente.
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

    /**
     * @return array<string, Monto>
     */
    private function valores(): array
    {
        $valores = [];

        /** @var list<string> $crudos */
        $crudos = (array) $this->option('lote');

        if ($crudos === []) {
            throw new RuntimeException('Falta --lote. Va uno por cada lote del contrato. Ejemplo: --lote=RPS-O-003:200000.00');
        }

        foreach ($crudos as $crudo) {
            $partes = explode(':', $crudo);

            if (count($partes) !== 2) {
                throw new RuntimeException("--lote mal escrito: «{$crudo}». Va CODIGO:VALOR. Ejemplo: --lote=RPS-O-003:200000.00");
            }

            [$codigo, $valor] = $partes;
            $codigo = mb_strtoupper(trim($codigo));

            if (preg_match('/^\d+(\.\d{1,2})?$/', trim($valor)) !== 1) {
                throw new RuntimeException("Valor mal escrito en --lote={$crudo}. Va sin comas ni símbolo. Ejemplo: 200000.00");
            }

            if (array_key_exists($codigo, $valores)) {
                throw new RuntimeException('El lote '.$codigo.' está dos veces en el pedido.');
            }

            $valores[$codigo] = new Monto(trim($valor));
        }

        return $valores;
    }

    private function imprimir(TraspasoHecho $hecho): void
    {
        $filas = [];

        foreach ($hecho->lotes as $lote) {
            $filas[] = [
                $lote->codigo,
                $lote->valorAntes->formateado(),
                $lote->valorDespues->formateado(),
                $lote->saldoAntes->formateado(),
                $lote->saldoDespues->formateado(),
                $this->cuotas($lote->cuotasAntes, $lote->cuotaAntes),
                $this->cuotas($lote->cuotasDespues, $lote->cuotaDespues),
            ];
        }

        $filas[] = ['TOTAL', '', '', $hecho->saldoAntes()->formateado(), $hecho->saldoDespues()->formateado(), '', ''];

        $this->table(['Lote', 'Valor hoy', 'Valor nuevo', 'Saldo hoy', 'Saldo nuevo', 'Cuotas hoy', 'Cuotas nuevas'], $filas);

        if ($hecho->recibos === []) {
            $this->components->info('No hay recibos de cuotas ni de abonos que volver a aplicar.');

            return;
        }

        $papeles = [];

        foreach ($hecho->recibos as $papel) {
            $papeles[] = [
                $papel['folio'],
                $papel['monto']->formateado(),
                TraspasoDeValor::repartoEscrito($papel['antes']),
                TraspasoDeValor::repartoEscrito($papel['despues']),
            ];
        }

        $this->table(['Recibo', 'Monto', 'Se aplicó', 'Se va a aplicar'], $papeles);
    }

    private function cuotas(int $cuantas, ?Monto $primera): string
    {
        if ($cuantas === 0 || ! $primera instanceof Monto) {
            return 'pagado';
        }

        return sprintf('%d de %s', $cuantas, $primera->formateado());
    }
}
