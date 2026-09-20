<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CorreccionDeValor;
use App\Domain\Ventas\ValorCorregido;
use App\Models\Venta;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Corregir el valor de un lote vendido contra su contrato — 20-sep-2026.
 *
 * El porqué, lo que mueve y lo que se niega a hacer están en
 * `CorreccionDeValor`, que es quien hace el trabajo: este comando solo lee lo
 * que el operador pidió, muestra el antes y el después, y escribe si no es un
 * ensayo. Un camino de código, no dos: la carga de una cartera anterior al
 * sistema corrige por esa misma puerta.
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:corregir-valor ABC-2026-0028 \
 *     --lote=ABC-H-009:325000.00 --lote=ABC-H-015:325000.00 \
 *     --motivo="El contrato dice 325,000.00 por lote" --ensayo
 *
 * `--lote` es el VALOR que dice el contrato para ese lote, no la diferencia.
 * Se nombran solo los lotes que se corrigen; los demás no se tocan.
 *
 * 🔴 **Siempre `--ensayo` primero.** Imprime la misma tabla que va a escribir
 * y no toca una fila. Y lo primero que imprime es de QUIEN es el expediente:
 * un número mal copiado es el expediente de otro cliente.
 *
 * Correrlo dos veces no hace daño: un lote que ya vale lo pedido se deja como
 * está, sin un UPDATE y sin un asiento en la bitácora.
 */
#[Description('Corrige el valor de un lote vendido contra su contrato; la diferencia va a la última cuota y los recibos no se tocan.')]
#[Signature('olympo:corregir-valor
    {venta : El número de contrato (ABC-2026-0028) o el id de la venta, que es el de la URL del expediente}
    {--lote=* : CODIGO:VALOR — el valor que dice el contrato, uno por cada lote que se corrige}
    {--motivo= : Por qué se corrige. Obligatorio para escribir}
    {--ensayo : Imprime lo que haría y no escribe nada}')]
final class CorregirValor extends Command
{
    public function handle(CorreccionDeValor $correccion): int
    {
        $venta = $this->venta();

        if (! $venta instanceof Venta) {
            $this->components->error('No encontré ese expediente. Va el número de contrato tal como sale impreso, o el id que aparece en la URL del expediente.');

            return self::FAILURE;
        }

        $this->presentar($venta);

        try {
            $valores = $this->valores();
            $retratos = $correccion->ensayar($venta, $valores);
        } catch (RuntimeException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $this->imprimir($retratos);

        if (! $this->hayCambios($retratos)) {
            $this->components->info('Ya está corregido: cada lote vale lo que se pidió. No se escribió nada.');

            return self::SUCCESS;
        }

        if ($this->option('ensayo') === true) {
            $this->components->info('Ensayo: no se escribió nada.');

            return self::SUCCESS;
        }

        $motivo = $this->option('motivo');

        try {
            $correccion->corregir($venta, $valores, is_string($motivo) ? $motivo : '');
        } catch (RuntimeException $error) {
            $this->components->error('No se escribió NADA: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Corregido. El expediente quedó en %s, con %s por cobrar. Los recibos no se tocaron.',
            $venta->montoValorTotal()->formateado(),
            $venta->saldoPendiente()->formateado(),
        ));

        return self::SUCCESS;
    }

    /**
     * Por número de contrato o por id.
     *
     * El contrato es el camino bueno: es el mismo en local, en pruebas y en
     * producción, así que el comando que se ensayó en un ambiente se pega tal
     * cual en el otro. El id no —cada base numera por su cuenta—, y se acepta
     * porque es lo que uno tiene a mano mirando la URL.
     */
    private function venta(): ?Venta
    {
        /*
         * ⚠️ `is_int` también: por la terminal todo llega como texto, pero
         * `$this->artisan('…', ['venta' => 28])` —los tests, o un
         * `Artisan::call()` desde código— entrega el entero tal cual. Con solo
         * `is_string` el id se volvía cadena vacía y el comando contestaba
         * «no encontré ese expediente» a un id perfectamente bueno.
         */
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

    /**
     * @return array<string, Monto>
     */
    private function valores(): array
    {
        $valores = [];

        /** @var list<string> $crudos */
        $crudos = (array) $this->option('lote');

        foreach ($crudos as $crudo) {
            $partes = explode(':', $crudo);

            if (count($partes) !== 2) {
                throw new RuntimeException("--lote mal escrito: «{$crudo}». Va CODIGO:VALOR. Ejemplo: --lote=ABC-H-016:325000.00");
            }

            [$codigo, $valor] = $partes;

            if (preg_match('/^\d+(\.\d{1,2})?$/', trim($valor)) !== 1) {
                throw new RuntimeException("Valor mal escrito en --lote={$crudo}. Va sin comas ni símbolo. Ejemplo: 325000.00");
            }

            if (array_key_exists(trim($codigo), $valores)) {
                throw new RuntimeException('El lote '.trim($codigo).' está dos veces en el pedido.');
            }

            $valores[trim($codigo)] = new Monto(trim($valor));
        }

        return $valores;
    }

    /**
     * @param list<ValorCorregido> $retratos
     */
    private function hayCambios(array $retratos): bool
    {
        return array_any($retratos, fn (ValorCorregido $retrato): bool => $retrato->cambia());
    }

    /**
     * @param list<ValorCorregido> $retratos
     */
    private function imprimir(array $retratos): void
    {
        $filas = [];
        $saldoHoy = Monto::cero();
        $saldoCorregido = Monto::cero();

        foreach ($retratos as $retrato) {
            $saldoHoy = $saldoHoy->sumar($retrato->saldoAntes);
            $saldoCorregido = $saldoCorregido->sumar($retrato->saldoDespues);

            $filas[] = [
                $retrato->codigo,
                $retrato->valorAntes->formateado(),
                $retrato->valorDespues->formateado(),
                $retrato->saldoAntes->formateado(),
                $retrato->saldoDespues->formateado(),
                $retrato->cambia()
                    ? sprintf('n.º %d: %s → %s', $retrato->ultimaCuota, $retrato->ultimaAntes->formateado(), $retrato->ultimaDespues->formateado())
                    : 'igual',
            ];
        }

        $filas[] = ['TOTAL', '', '', $saldoHoy->formateado(), $saldoCorregido->formateado(), ''];

        $this->table(['Lote', 'Valor hoy', 'Valor corregido', 'Saldo hoy', 'Saldo corregido', 'Última cuota'], $filas);
    }
}
