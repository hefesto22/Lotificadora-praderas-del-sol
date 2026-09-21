<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pagos\RecibosDeCarteraVieja;
use App\Models\Recibo;
use App\Models\Venta;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Acomodar los papeles de la cartera vieja de un expediente — 21-sep-2026.
 *
 * El porqué y los límites están en `RecibosDeCarteraVieja`. Hace dos cosas, y
 * ninguna mueve un centavo de ningún saldo:
 *
 *   --borrar-anulado=000089   borra ese recibo, que ya está anulado
 *   --partir-prima            un recibo de prima por cada titular de recibo
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:acomodar-recibos-viejos ABC-2026-0031 \
 *     --borrar-anulado=000089 --partir-prima \
 *     --motivo="Los recibos salen a nombre de cada lote" --ensayo
 *
 * 🔴 **Siempre `--ensayo` primero.** Partir la prima se ensaya de verdad —se
 * hace y se deshace—; el borrado solo se anuncia.
 *
 * Correrlo dos veces no hace daño: lo que ya está hecho lo dice y no escribe.
 */
#[Description('Cartera vieja: borra un recibo anulado y/o parte la prima en un recibo por titular. No mueve ningún saldo.')]
#[Signature('olympo:acomodar-recibos-viejos
    {venta : El número de contrato del expediente, o el id de la venta}
    {--borrar-anulado=* : El folio de un recibo de la cartera vieja que ya está anulado. Ejemplo: 000089}
    {--partir-prima : Deja un recibo de prima por cada titular de recibo de los lotes}
    {--motivo= : Por qué se acomoda. Obligatorio para escribir}
    {--ensayo : Muestra lo que haría y no escribe nada}')]
final class AcomodarRecibosViejos extends Command
{
    public function handle(RecibosDeCarteraVieja $papeles): int
    {
        $venta = $this->venta();

        if (! $venta instanceof Venta) {
            $this->components->error('No encontré ese expediente. Va el número de contrato completo, o el id que sale en la URL.');

            return self::FAILURE;
        }

        $contrato = $venta->getAttribute('numero_contrato');
        $titular = $venta->titular()?->getAttribute('nombre');

        $this->components->twoColumnDetail(
            is_string($contrato) && $contrato !== '' ? $contrato : sprintf('Venta %d', (int) $venta->getKey()),
            is_string($titular) && $titular !== '' ? $titular : 'sin titular',
        );

        $ensayo = $this->option('ensayo') === true;
        $crudo = $this->option('motivo');
        $motivo = is_string($crudo) ? $crudo : '';

        /** @var list<string> $folios */
        $folios = (array) $this->option('borrar-anulado');

        if ($folios === [] && $this->option('partir-prima') !== true) {
            $this->components->error('No se pidió nada. Va --borrar-anulado=FOLIO, --partir-prima, o las dos.');

            return self::FAILURE;
        }

        try {
            foreach ($folios as $folio) {
                $this->borrar($papeles, $venta, $folio, $motivo, $ensayo);
            }

            if ($this->option('partir-prima') === true) {
                $this->partir($papeles, $venta, $motivo, $ensayo);
            }
        } catch (RuntimeException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        if ($ensayo) {
            $this->components->info('Ensayo: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    private function borrar(RecibosDeCarteraVieja $papeles, Venta $venta, string $folio, string $motivo, bool $ensayo): void
    {
        $recibo = $this->recibo($venta, $folio);

        if (! $recibo instanceof Recibo) {
            $this->components->warn("El recibo {$folio} ya no está en este expediente: no hay nada que borrar.");

            return;
        }

        if ($ensayo) {
            $this->line(sprintf(
                '  Se borraría el %s · %s · %s.',
                $recibo->folio(),
                $recibo->montoTotal()->formateado(),
                $recibo->estaAnulado() ? 'anulado' : '🔴 VIGENTE: el comando real se va a negar',
            ));

            return;
        }

        $papeles->borrarElAnulado($venta, $recibo, $motivo);

        $this->components->info(sprintf('Se borró el %s. El rastro quedó en «Actualizaciones» del expediente.', $recibo->folio()));
    }

    private function partir(RecibosDeCarteraVieja $papeles, Venta $venta, string $motivo, bool $ensayo): void
    {
        $nuevos = $papeles->partirLaPrima($venta, $motivo, escribir: ! $ensayo);

        $this->table(
            ['Recibo de prima', 'Lote', 'A nombre de', 'Monto'],
            array_map(
                static fn (array $papel): array => [$papel['folio'], $papel['lotes'], $papel['nombre'], $papel['monto']->formateado()],
                $nuevos,
            ),
        );

        if (! $ensayo) {
            $this->components->info('La prima quedó en un recibo por titular. El recibo único se borró; el rastro quedó en «Actualizaciones».');
        }
    }

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
     * Solo la serie vieja —folio sin prefijo— y solo adentro del expediente.
     */
    private function recibo(Venta $venta, string $folio): ?Recibo
    {
        $numero = trim($folio);

        if (! ctype_digit($numero)) {
            return null;
        }

        return Recibo::query()
            ->where('venta_id', $venta->getKey())
            ->where('numero', (int) $numero)
            ->whereNull('serie')
            ->first();
    }
}
