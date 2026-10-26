<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\TasaDeInteres;
use App\Models\Proyecto;
use App\Models\Reestructuracion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * El acta de una reestructuración del plan, lista para la impresora
 * (traída de Maya, 25-oct-2026).
 *
 * Es el papel que firma el cliente cuando su plan cambia: qué debía, cómo lo
 * pagaba y cómo lo va a pagar, con la tasa y el interés dichos con todas las
 * letras. Sin él, el contrato firmado dice una cosa y el sistema cobra otra.
 *
 * ═══ QUIEN PUEDE ═══
 *
 * Quien puede ver el expediente, igual que el acta de rescisión: se autoriza
 * sobre la venta, que es lo que tiene dueño. La sesión y la cuenta activa las
 * verifica `UsuarioActivoDelPanel`, el middleware de todos los documentos.
 *
 * ═══ DE DONDE SALEN LOS NUMEROS ═══
 *
 * Del acta y no de las cuotas de hoy: `reestructuraciones` guarda el antes, el
 * después y el plan pactado renglón por renglón. Un abono posterior reescribe
 * cuotas, pero no puede cambiar lo que el cliente firmó ese día.
 */
final readonly class ImprimirReestructuracionController
{
    public function __invoke(Reestructuracion $reestructuracion): View
    {
        $reestructuracion->load(['venta.proyecto', 'reprogramaciones.compromiso.lote']);

        $venta = $reestructuracion->venta;

        if ($venta instanceof Venta) {
            Gate::authorize('view', $venta);
        }

        $proyecto = $venta?->proyecto;
        $plan = $this->planParaElPapel($reestructuracion);
        $pagos = $this->pagosParaElPapel($reestructuracion);

        return view('documentos.reestructuracion', [
            'acta'     => $reestructuracion,
            'contrato' => $venta?->getAttribute('numero_contrato'),
            'titular'  => $venta?->titular()?->getAttribute('nombre'),
            'dni'      => $venta?->titular()?->getAttribute('dni'),
            'lotes'    => $this->codigos($reestructuracion),
            'emisor'   => $this->emisorDe($proyecto),
            'logo'     => $proyecto?->logoUrl(),
            'plan'     => $plan,
            'totales'  => $this->totales($plan),
            'pagos'    => $pagos,
            // La mora que esos recibos ya habían cobrado: no entra en la
            // cuenta del plan nuevo, es ganancia del atraso (26-sep-2026).
            'moraCobrada' => array_reduce(
                $pagos,
                static fn (Monto $suma, array $pago): Monto => $suma->sumar($pago['mora']),
                Monto::cero(),
            ),
            'mensual' => $this->mensualDe($reestructuracion->tasaNueva()),
            'firmado' => $this->firmaDe($venta),
            // Cada lote con su cuota (25-oct-2026): cómo quedó cada uno.
            'porLote' => $this->lotesParaElPapel($reestructuracion),
            // Lo que vale el contrato y lo que dio de prima (26-sep-2026): el
            // cliente que firma un plan nuevo pregunta primero por lo que ya
            // entregó al principio.
            'valor' => $venta?->montoValorTotal(),
            'prima' => $venta?->montoPrima(),
        ]);
    }

    /**
     * El plan pactado, con la fecha ya escrita como la lee una persona.
     *
     * @return list<array{numero: int, fecha: string, monto: Monto, capital: Monto, interes: Monto, cubierto: Monto}>
     */
    private function planParaElPapel(Reestructuracion $acta): array
    {
        $renglones = [];

        foreach ($acta->planNuevo() as $renglon) {
            $renglones[] = [
                'numero'   => $renglon['numero'],
                'fecha'    => CarbonImmutable::parse($renglon['vence'])->format('d/m/Y'),
                'monto'    => $renglon['monto'],
                'capital'  => $renglon['capital'],
                'interes'  => $renglon['interes'],
                'cubierto' => $renglon['cubierto'],
            ];
        }

        return $renglones;
    }

    /**
     * Los recibos que se movieron, con la fecha como se lee y cada lado en
     * una línea: «P-1 cuota 1: L 98,400.00 · P-2 cuota 1: L 1,600.00».
     *
     * @return list<array{folio: string, fecha: string, dinero: Monto, mora: Monto, antes: string, despues: string}>
     */
    private function pagosParaElPapel(Reestructuracion $acta): array
    {
        $filas = [];

        foreach ($acta->pagosReubicados() as $pago) {
            $filas[] = [
                'folio'   => $pago['folio'],
                'fecha'   => $pago['fecha'] === '' ? '' : CarbonImmutable::parse($pago['fecha'])->format('d/m/Y'),
                'dinero'  => $pago['dinero'],
                'mora'    => $pago['mora'],
                'antes'   => $this->comoSeLee($pago['antes']),
                'despues' => $this->comoSeLee($pago['despues']),
            ];
        }

        return $filas;
    }

    /**
     * @param list<array{lote: string, numero: int, monto: Monto, mora: Monto}> $renglones
     */
    private function comoSeLee(array $renglones): string
    {
        $partes = [];

        foreach ($renglones as $renglon) {
            $parte = sprintf('%s cuota %d: %s', $renglon['lote'], $renglon['numero'], $renglon['monto']->formateado());

            if (! $renglon['mora']->esCero()) {
                $parte .= sprintf(' + mora %s', $renglon['mora']->formateado());
            }

            $partes[] = $parte;
        }

        return implode(' · ', $partes);
    }

    /**
     * Las tres columnas sumadas: capital + interés = lo que se paga, y el
     * capital tiene que dar el que se reestructuró. Si el papel no cuadra
     * consigo mismo, nadie le cree el resto.
     *
     * @param list<array{numero: int, fecha: string, monto: Monto, capital: Monto, interes: Monto, cubierto: Monto}> $plan
     *
     * @return array{monto: Monto, capital: Monto, interes: Monto, cubierto: Monto}
     */
    private function totales(array $plan): array
    {
        $totales = ['monto' => Monto::cero(), 'capital' => Monto::cero(), 'interes' => Monto::cero(), 'cubierto' => Monto::cero()];

        foreach ($plan as $renglon) {
            $totales = [
                'monto'    => $totales['monto']->sumar($renglon['monto']),
                'capital'  => $totales['capital']->sumar($renglon['capital']),
                'interes'  => $totales['interes']->sumar($renglon['interes']),
                'cubierto' => $totales['cubierto']->sumar($renglon['cubierto']),
            ];
        }

        return $totales;
    }

    /**
     * La tasa del mes, en porcentaje: «6.2 % anual, equivalente a 0.5167 %
     * mensual» es como lo tiene que decir el papel (§8.5), para que nadie
     * discuta después cuál de las dos se pactó.
     *
     * Con bcmath y half-up, nunca float: es un número que sale impreso.
     */
    private function mensualDe(TasaDeInteres $tasa): string
    {
        $crudo = bcadd(bcmul($tasa->mensual(), '100', 10), '0.00005', 4);

        return str_contains($crudo, '.') ? rtrim(rtrim($crudo, '0'), '.').' %' : $crudo.' %';
    }

    /**
     * Cómo quedó cada lote, con las fechas como se leen (25-oct-2026). El
     * acta de un plan unificado también lo trae —cuánto paga cada lote de la
     * cuota del contrato—; la de antes de esa fecha no, y sale vacía.
     *
     * @return list<array{codigo: string, capital: Monto, cuotas: int, cuota: Monto, ultima: Monto, desde: int, primera: string, termina: string}>
     */
    private function lotesParaElPapel(Reestructuracion $acta): array
    {
        $filas = [];

        foreach ($acta->lotesDetalle() as $lote) {
            $filas[] = [
                ...$lote,
                'primera' => $lote['primera'] === '' ? '' : CarbonImmutable::parse($lote['primera'])->format('d/m/Y'),
                'termina' => $lote['termina'] === '' ? '' : CarbonImmutable::parse($lote['termina'])->format('d/m/Y'),
            ];
        }

        return $filas;
    }

    /**
     * La fecha en que se firmó el contrato, como se lee.
     */
    private function firmaDe(?Venta $venta): ?string
    {
        $firma = $venta?->getAttribute('fecha_contrato');

        return $firma instanceof DateTimeInterface ? $firma->format('d/m/Y') : null;
    }

    /**
     * Los lotes que entraron, en el orden del contrato.
     */
    private function codigos(Reestructuracion $acta): string
    {
        $codigos = [];

        foreach ($acta->reprogramaciones->sortBy('compromiso_id') as $constancia) {
            $codigo = $constancia->compromiso?->lote?->getAttribute('codigo');

            if (is_string($codigo)) {
                $codigos[] = $codigo;
            }
        }

        return implode(' · ', $codigos);
    }

    /**
     * Quién firma, como sale arriba del papel. El mismo criterio que el acta
     * de rescisión: primero el desarrollo, la config solo de respaldo.
     *
     * @return array<string, string|null>
     */
    private function emisorDe(?Proyecto $proyecto): array
    {
        if ($proyecto instanceof Proyecto) {
            $propio = $proyecto->comoEmisor();

            if ($propio['residencial'] !== null) {
                return $propio;
            }
        }

        $datos = config('lotificadora.emisor');

        if (! is_array($datos)) {
            return [];
        }

        $limpio = [];

        foreach (['nombre', 'rtn', 'residencial', 'direccion', 'telefono'] as $clave) {
            $valor = $datos[$clave] ?? null;
            $limpio[$clave] = is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
        }

        return $limpio;
    }
}
