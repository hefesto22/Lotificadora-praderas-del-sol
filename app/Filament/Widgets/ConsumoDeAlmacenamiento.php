<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Documento;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Override;

/**
 * Cuánto del almacenamiento incluido se lleva usado. Cláusula Novena.
 *
 * ═══ POR QUE ESTO ES UN NUMERO Y NO UNA CURIOSIDAD ═══
 *
 * El contrato incluye 25 GB y cobra el excedente a L 200/GB/año. Nadie mira
 * el disco de un servidor por gusto: sin este cuadro, el día que el
 * expediente digital se llene de fotos de escrituras, el excedente lo paga
 * Olympo — durante meses, hasta que alguien lea una factura con atención.
 *
 * El aviso salta al 80% y no al 100% a propósito: al 100% ya se está
 * pagando. Al 80% todavía hay tiempo de hablarlo con la contratante y
 * decidir si se amplía o si se limpia.
 *
 * ═══ POR QUE SE SUMA `documentos.bytes` Y NO SE MIDE EL DISCO ═══
 *
 * Porque lo que el contrato cuenta es lo que el CLIENTE guardó, no lo que
 * pesa el sistema. Un `du` del volumen incluiría vendor, los respaldos, los
 * logs y las cachés — cosas que Olympo no le factura a nadie. La columna
 * `bytes` se llena al subir cada archivo y es exactamente la cifra del
 * contrato.
 *
 * Se lee de la base y no del disco también por una razón práctica: en un
 * servidor con el almacenamiento en S3, recorrer los archivos para pesarlos
 * cuesta una llamada por archivo cada vez que alguien abre el escritorio.
 */
class ConsumoDeAlmacenamiento extends StatsOverviewWidget
{
    #[Override]
    protected ?string $pollingInterval = null;

    #[Override]
    protected static ?int $sort = 5;

    /**
     * Solo lo ve quien puede hacer algo al respecto.
     *
     * Un receptor cobrando desde el teléfono no necesita saber cuánto disco
     * queda, y un número que nadie puede accionar es ruido en la pantalla
     * más cargada del sistema.
     */
    #[Override]
    public static function canView(): bool
    {
        return auth()->user()?->can('ViewAny:Documento') === true;
    }

    /**
     * @return array<int, Stat>
     */
    #[Override]
    protected function getStats(): array
    {
        $usados = $this->bytesGuardados();
        $incluidos = $this->bytesIncluidos();
        $porcentaje = $incluidos > 0 ? $usados / $incluidos : 0.0;

        return [
            Stat::make('Almacenamiento usado', $this->enGigas($usados))
                ->description($this->leyenda($porcentaje, $incluidos))
                ->descriptionIcon($this->icono($porcentaje))
                ->color($this->color($porcentaje)),
        ];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    private function bytesGuardados(): int
    {
        return (int) Documento::query()->sum('bytes');
    }

    private function bytesIncluidos(): int
    {
        $gigas = config('lotificadora.almacenamiento.incluido_gb', 25);

        return (is_int($gigas) && $gigas > 0 ? $gigas : 25) * 1024 ** 3;
    }

    private function alertaEn(): float
    {
        $umbral = config('lotificadora.almacenamiento.alerta_en', 0.80);

        return is_float($umbral) && $umbral > 0 && $umbral <= 1 ? $umbral : 0.80;
    }

    /**
     * Dos decimales: en 25 GB, un solo decimal esconde 50 MB por escalón y
     * el número deja de moverse aunque alguien esté subiendo archivos.
     */
    private function enGigas(int $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 2).' GB';
    }

    private function leyenda(float $porcentaje, int $incluidos): string
    {
        $texto = sprintf('%s%% de %s incluidos', number_format($porcentaje * 100, 1), $this->enGigas($incluidos));

        if ($porcentaje >= 1) {
            $precio = (string) config('lotificadora.almacenamiento.precio_gb_anio', '200.00');

            return $texto.' — pasado del incluido, el excedente se cobra a L '.$precio.'/GB al año';
        }

        if ($porcentaje >= $this->alertaEn()) {
            return $texto.' — conviene hablarlo con la contratante antes de pasarse';
        }

        return $texto;
    }

    private function color(float $porcentaje): string
    {
        return match (true) {
            $porcentaje >= 1                 => 'danger',
            $porcentaje >= $this->alertaEn() => 'warning',
            default                          => 'success',
        };
    }

    private function icono(float $porcentaje): string
    {
        return $porcentaje >= $this->alertaEn()
            ? 'heroicon-m-exclamation-triangle'
            : 'heroicon-m-circle-stack';
    }
}
