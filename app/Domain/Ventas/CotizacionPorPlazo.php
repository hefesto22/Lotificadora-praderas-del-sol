<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use Carbon\CarbonImmutable;

/**
 * Que le cuesta al cliente un lote de tal medida, a cada plazo que se ofrece.
 *
 * ═══ POR QUE SE CALCULA EN EL SERVIDOR Y NO EN EL NAVEGADOR ═══
 *
 * Sería mucho más simple mandarle al navegador el precio de la vara² y que
 * multiplique. Pero JavaScript solo tiene `float`, que es exactamente lo que
 * el §8.3.1 prohíbe en el camino del dinero: sobre 300.000 pares realistas de
 * área × precio, medimos 42 resultados equivocados por un centavo. Y la cuota
 * es peor —la fórmula francesa acumula el error en las 48 cuotas—.
 *
 * El cliente que abre el plano y después firma tiene que ver **el mismo
 * número las dos veces**. Por eso acá se usa `PlanDeCuotas`, el mismo motor
 * que arma el plan del contrato: si algún día uno cambia, cambian los dos.
 *
 * ═══ SE COTIZA POR MEDIDA, NO POR LOTE ═══
 *
 * En Praderas del Sol **233 de los 301 lotes miden 250 vr²**. Cotizar lote por
 * lote serían 301 × 5 planes = 1.505 planes de hasta 48 cuotas cada uno, o sea
 * unas 50.000 filas armadas para mostrar cinco números distintos. Agrupando
 * por medida son ~10 × 5 = 50 cálculos.
 *
 * La clave del mapa es la medida normalizada a cuatro decimales, que es la
 * escala de `lotes.area_varas`.
 *
 * ═══ LA CUOTA QUE SE PUBLICA ES SIN PRIMA, Y ASI SE DICE ═══
 *
 * La prima se negocia caso por caso, así que cualquier número que se elija acá
 * sería inventado. Sin prima la cuota sale **más alta**, que es el lado
 * correcto del error: nadie llega al mostrador y se entera de que era más cara
 * de lo que decía la página. La plantilla lo aclara con todas las letras.
 */
final readonly class CotizacionPorPlazo
{
    /** Escala de `lotes.area_varas`. */
    private const int DECIMALES_DE_AREA = 4;

    /**
     * La clave con la que una medida entra al mapa de precios.
     *
     * Con guion bajo y no con punto: el mapa viaja a la página como objeto de
     * JavaScript, y una clave con punto obliga a `precios["250.0000"]` en vez
     * de leerse de corrido. Además normaliza —«250», «250.0» y «250.0000» son
     * la misma medida y tienen que dar la misma clave.
     */
    public static function clave(string $areaVaras): string
    {
        $limpio = trim($areaVaras);

        if (! is_numeric($limpio)) {
            return 'x';
        }

        return str_replace(['.', '-'], ['_', 'm'], bcadd($limpio, '0', self::DECIMALES_DE_AREA));
    }

    /**
     * @param list<string> $areas las medidas de los lotes que están a la venta
     *
     * @return array{
     *     planes: list<array{meses: int, nombre: string, tasa: string|null}>,
     *     precios: array<string, array<int, array{valor: string, cuota: string|null, total: string, interes: string|null}>>
     * }
     */
    public function para(Proyecto $proyecto, array $areas): array
    {
        /** @var list<PlanDePago> $planes */
        $planes = PlanDePago::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->activos()
            ->orderBy('meses')
            ->get()
            ->all();

        if ($planes === []) {
            return ['planes' => [], 'precios' => []];
        }

        // Las medidas distintas, una sola vez cada una. Ver el docblock.
        $unicas = [];

        foreach ($areas as $area) {
            $clave = self::clave($area);

            if ($clave !== 'x' && ! array_key_exists($clave, $unicas)) {
                $unicas[$clave] = $area;
            }
        }

        $precios = [];

        foreach ($unicas as $clave => $area) {
            $porPlazo = [];

            foreach ($planes as $plan) {
                $renglon = $this->cotizar($plan, $area);

                if ($renglon !== null) {
                    $porPlazo[(int) $plan->getAttribute('meses')] = $renglon;
                }
            }

            if ($porPlazo !== []) {
                $precios[$clave] = $porPlazo;
            }
        }

        return [
            'planes'  => $this->comoLista($planes),
            'precios' => $precios,
        ];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @return array{valor: string, cuota: string|null, total: string, interes: string|null}|null
     */
    private function cotizar(PlanDePago $plan, string $area): ?array
    {
        $limpio = trim($area);

        if (! is_numeric($limpio)) {
            return null;
        }

        $valor = new Monto($plan->montoPrecioVara()->multiplicarPor($limpio)->redondeado());

        if ($valor->esCero()) {
            return null;
        }

        $meses = (int) $plan->getAttribute('meses');

        // Contado: no hay cuota ni interés que mostrar, solo el precio.
        if ($meses === 0) {
            return [
                'valor'   => $valor->formateado(),
                'cuota'   => null,
                'total'   => $valor->formateado(),
                'interes' => null,
            ];
        }

        try {
            /*
             * Prima en cero a propósito: ver el docblock de la clase. El día
             * de pago y la fecha no cambian ningún monto —solo los
             * vencimientos, que acá no se publican—, así que van fijos.
             */
            $cuotas = PlanDeCuotas::nuevo(
                $valor,
                Monto::cero(),
                $meses,
                1,
                CarbonImmutable::parse(today()->toDateString()),
                $plan->tasaDeInteres(),
            );
        } catch (GrupoOlympoException) {
            /*
             * Un lote de medida absurda con un plazo largo puede no tener plan
             * posible. Se omite ese renglón en vez de tumbar la página: el
             * resto de los plazos se cotiza igual.
             */
            return null;
        }

        $interes = $cuotas->totalInteres();

        return [
            'valor'   => $valor->formateado(),
            'cuota'   => $cuotas->cuotaMensual()?->formateado(),
            'total'   => $cuotas->total()->formateado(),
            'interes' => $interes->esCero() ? null : $interes->formateado(),
        ];
    }

    /**
     * @param list<PlanDePago> $planes
     *
     * @return list<array{meses: int, nombre: string, tasa: string|null}>
     */
    private function comoLista(array $planes): array
    {
        $lista = [];

        foreach ($planes as $plan) {
            $tasa = $plan->tasaDeInteres();

            $lista[] = [
                'meses'  => (int) $plan->getAttribute('meses'),
                'nombre' => $plan->nombre(),
                // Null cuando no cobra: la página no muestra «0 %», que
                // haría pensar que hay interés y es cero por hoy.
                'tasa' => $tasa->esCero() ? null : $tasa->formateada().' anual',
            ];
        }

        return $lista;
    }
}
