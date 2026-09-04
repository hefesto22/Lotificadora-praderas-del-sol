<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Models\Lote;
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
     * @param list<array{meses: int, etiqueta: string, precio: Monto, precioLista: Monto, prima: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres}> $planes
     *
     * @return list<array{meses: int, etiqueta: string, precio: string, precioLista: string, tasa: string, tasaLista: string, rebajado: bool, rebajada: bool, valor: string, valorCrudo: string, valorLista: string, descuento: string|null, descuentoCrudo: string|null, prima: string|null, cuota: string|null, interes: string|null, total: string, error: string|null}>
     */
    public function para(string $areaVaras, array $planes, CarbonImmutable $fecha, int $diaPago): array
    {
        $filas = [];

        foreach ($planes as $plan) {
            $filas[] = $this->renglon($areaVaras, $plan, $fecha, $diaPago);
        }

        return $filas;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param array{meses: int, etiqueta: string, precio: Monto, precioLista: Monto, prima: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres} $plan
     *
     * @return array{meses: int, etiqueta: string, precio: string, precioLista: string, tasa: string, tasaLista: string, rebajado: bool, rebajada: bool, valor: string, valorCrudo: string, valorLista: string, descuento: string|null, descuentoCrudo: string|null, prima: string|null, cuota: string|null, interes: string|null, total: string, error: string|null}
     */
    private function renglon(string $areaVaras, array $plan, CarbonImmutable $fecha, int $diaPago): array
    {
        /*
         * ═══ LA PRIMA ES DE CADA RENGLON, NO DEL CUADRO ═══
         *
         * Hasta el 31-ago-2026 habia UNA prima para los cuatro plazos, en una
         * casilla arriba. Lo cambio Mauricio: la prima y el descuento se
         * escriben ADENTRO del renglon que se marca, porque son de la oferta
         * que se esta armando y no de la pantalla.
         *
         * Los renglones que no estan marcados llegan con prima cero y precio
         * de lista: son la lista de precios, no la negociacion. Quien arma
         * pasa de uno a otro y solo el marcado lleva lo que se negocio.
         */
        $prima = $plan['prima'];

        /*
         * La MISMA expresion que usa RegistroDeCompromisos::valorDe() y que
         * exige el CHECK de la base. Los tres tienen que dar el mismo numero
         * o la venta no se graba — y asi tiene que ser.
         */
        $valor = new Monto($plan['precio']->multiplicarPor($areaVaras)->redondeado());

        /*
         * Lo que costaría SIN tocar nada, y la diferencia entre los dos.
         *
         * Sale de acá y no de una resta en el navegador porque es el número
         * que quien atiende le dice al cliente —«te estoy bajando L 37,130»—
         * y tiene que ser el descuento de VERDAD: el que resulta del precio
         * por vara² con el que se firma, ya redondeado, y no el que alguien
         * tecleó. Entre los dos puede haber un lempira de diferencia, y ese
         * lempira aparece en el contrato.
         *
         * `restar()` revienta con un negativo, así que un precio POR ENCIMA
         * de la lista —que el cuadro permite— devuelve null y no rompe la
         * pantalla: no es un descuento y no se muestra como tal.
         */
        $valorLista = new Monto($plan['precioLista']->multiplicarPor($areaVaras)->redondeado());

        $base = [
            'meses'    => $plan['meses'],
            'etiqueta' => $plan['etiqueta'],
            /*
             * 🔴 SEIS DECIMALES, NO DOS. El precio por vara² es un FACTOR, no
             * dinero: la columna lleva seis desde el 11-ago-2026 justo para
             * que un precio de lote redondo se pueda escribir. Recortarlo a
             * dos acá le devolvia el problema a la pantalla —pedir 300,000
             * cerrados daba 299,999.31— y de paso mandaba al formulario de
             * venta un precio distinto del que se cotizo.
             *
             * El DINERO sigue con dos: `valor`, `cuota` y `total` de abajo.
             * Lo que gana precision es el factor, nunca el resultado.
             */
            'precio'      => $plan['precio']->redondeado(Lote::DECIMALES_DEL_PRECIO),
            'precioLista' => $plan['precioLista']->redondeado(Lote::DECIMALES_DEL_PRECIO),
            'tasa'        => $plan['tasa']->redondeada(),
            'tasaLista'   => $plan['tasaLista']->redondeada(),
            // Para que la pantalla pinte lo que se toco sin volver a comparar.
            'rebajado'   => $plan['precio']->menorQue($plan['precioLista']),
            'rebajada'   => $plan['tasa']->menorQue($plan['tasaLista']),
            'valor'      => $valor->formateado(),
            'valorLista' => $valorLista->formateado(),
            'descuento'  => $valor->menorQue($valorLista) ? $valorLista->restar($valor)->formateado() : null,

            /*
             * Los mismos dos numeros, pelados: sin el simbolo y sin comas.
             *
             * Desde el 31-ago-2026 el valor y el descuento no solo se
             * muestran, tambien SE ESCRIBEN: quien atiende puede teclear
             * cualquiera de los dos y el otro se completa solo. Una casilla
             * necesita un numero que se pueda volver a leer, y «L 1,048,002.91»
             * no lo es.
             *
             * Se sirven aparte en vez de despedazar el formateado en el
             * navegador: ahi hay que adivinar donde termina el simbolo y ese
             * es el tipo de adivinanza que le come un cero a un descuento.
             */
            'valorCrudo'     => $valor->redondeado(),
            'descuentoCrudo' => $valor->menorQue($valorLista) ? $valorLista->restar($valor)->redondeado() : null,

            /*
             * La prima, en su columna, para que cada renglón se lea como una
             * oferta entera: «a 24 meses vale tanto, con tanto de prima, y la
             * cuota es tanto». Lo pidió Mauricio el 31-ago-2026.
             *
             * Es la MISMA para los cuatro plazos —se escribe una sola vez
             * arriba— y aun así se repite en cada renglón: quien atiende lee
             * de izquierda a derecha una fila, no sube a buscar el dato.
             *
             * Null en dos casos, y en los dos la columna muestra una raya:
             * de contado no hay prima que dar —se paga todo— y en cero no hay
             * nada que decir. «L 0.00» cuatro veces es ruido que hay que
             * descartar con la vista.
             */
            'prima' => $plan['meses'] === 0 || $prima->esCero() ? null : $prima->formateado(),
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
