<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;

/**
 * Que le cuesta ESTE lote a cada plazo, con el precio y la tasa que se estan
 * negociando en este momento.
 *
 * ═══ 🔴 POR QUE NO SE CALCULA EN EL NAVEGADOR ═══
 *
 * Porque durante meses se calculo ahi, y estaba mal.
 *
 * El cuadro del plano dividia el valor entre los meses y listo. Mientras
 * ningun plan cobraba interes eso daba el numero correcto y nadie lo noto.
 * El dia que un plan de Praderas quedo al 12 % anual, la misma pantalla que
 * el vendedor le muestra al cliente decia **L 54,166.67** donde el contrato
 * iba a decir **L 57,751.71**. Tres mil quinientos ochenta y cinco lempiras
 * por mes, dichos en voz alta, con el cliente enfrente.
 *
 * Y no se arregla escribiendo la formula francesa en JavaScript: ahi solo hay
 * `float`, que es exactamente lo que el §8.3.1 prohibe en el camino del
 * dinero. `(1+i)^-n` acumula error y la cuota sale con centavos que el
 * contrato no va a tener.
 *
 * Asi que se calcula aca, con `PlanDeCuotas` — el MISMO motor que arma el
 * plan al firmar. Si algun dia uno cambia, cambian los dos, y el numero que
 * el vendedor dice de pie sigue siendo el que sale impreso.
 *
 * ═══ CADA RENGLON SE COTIZA SOLO ═══
 *
 * Un plazo que no da plan —un saldo demasiado chico para 60 cuotas, por
 * ejemplo— no puede tumbar el cuadro entero: se marca ese renglon y los otros
 * cuatro se cotizan igual. Quien esta atendiendo necesita ver las opciones que
 * SI existen.
 */
final readonly class CotizacionDelLote
{
    /**
     * @param list<array{meses: int, etiqueta: string, precio: Monto, precioLista: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres}> $planes
     *
     * @return list<array{meses: int, etiqueta: string, precio: string, precioLista: string, tasa: string, tasaLista: string, rebajado: bool, rebajada: bool, valor: string, cuota: string|null, interes: string|null, total: string, error: string|null}>
     */
    public function para(string $areaVaras, Monto $prima, array $planes, CarbonImmutable $fecha, int $diaPago): array
    {
        $filas = [];

        foreach ($planes as $plan) {
            $filas[] = $this->renglon($areaVaras, $prima, $plan, $fecha, $diaPago);
        }

        return $filas;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param array{meses: int, etiqueta: string, precio: Monto, precioLista: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres} $plan
     *
     * @return array{meses: int, etiqueta: string, precio: string, precioLista: string, tasa: string, tasaLista: string, rebajado: bool, rebajada: bool, valor: string, cuota: string|null, interes: string|null, total: string, error: string|null}
     */
    private function renglon(string $areaVaras, Monto $prima, array $plan, CarbonImmutable $fecha, int $diaPago): array
    {
        /*
         * La MISMA expresion que usa RegistroDeCompromisos::valorDe() y que
         * exige el CHECK de la base. Los tres tienen que dar el mismo numero
         * o la venta no se graba — y asi tiene que ser.
         */
        $valor = new Monto($plan['precio']->multiplicarPor($areaVaras)->redondeado());

        $base = [
            'meses'       => $plan['meses'],
            'etiqueta'    => $plan['etiqueta'],
            'precio'      => $plan['precio']->redondeado(),
            'precioLista' => $plan['precioLista']->redondeado(),
            'tasa'        => $plan['tasa']->redondeada(),
            'tasaLista'   => $plan['tasaLista']->redondeada(),
            // Para que la pantalla pinte lo que se toco sin volver a comparar.
            'rebajado' => $plan['precio']->menorQue($plan['precioLista']),
            'rebajada' => $plan['tasa']->menorQue($plan['tasaLista']),
            'valor'    => $valor->formateado(),
        ];

        // Contado: no hay cuota ni interes que mostrar, solo el precio.
        if ($plan['meses'] === 0) {
            return [...$base, 'cuota' => null, 'interes' => null, 'total' => $valor->formateado(), 'error' => null];
        }

        try {
            $cuotas = PlanDeCuotas::nuevo($valor, $prima, $plan['meses'], $diaPago, $fecha, $plan['tasa']);
        } catch (GrupoOlympoException $error) {
            /*
             * Se devuelve el mensaje del dominio tal cual: dice «el saldo es
             * demasiado chico para 60 meses», que es exactamente lo que quien
             * atiende necesita leer para proponer otra cosa.
             */
            return [...$base, 'cuota' => null, 'interes' => null, 'total' => $valor->formateado(), 'error' => $error->getMessage()];
        }

        $interes = $cuotas->totalInteres();

        return [
            ...$base,
            'cuota' => $cuotas->cuotaMensual()?->formateado(),
            // Null cuando no cobra: la pantalla no muestra «L 0.00», que
            // haria pensar que hay interes y es cero por hoy.
            'interes' => $interes->esCero() ? null : $interes->formateado(),
            'total'   => $cuotas->total()->sumar($prima)->formateado(),
            'error'   => null,
        ];
    }
}
