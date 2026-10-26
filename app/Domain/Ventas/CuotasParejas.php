<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;

/**
 * Un saldo repartido en N cuotas parejas y REDONDAS, con el ajuste al final.
 *
 * ═══ QUE PROBLEMA RESUELVE ═══
 *
 * El boton «Repartir» del formulario de venta llena las fechas y los montos
 * de un calendario pactado. Repartiendo exacto, un saldo de L 1,833,333.25
 * entre cuatro da cuotas de L 458,333.31: un numero que nadie dicta por
 * telefono ni escribe en un contrato.
 *
 * Mauricio, 18-sep-2026: «no redondea la cuota al 100 mas cercano cuando
 * deberia de hacerlo y restarlo de la ultima cuota», y despues, precisando:
 * «redondealo al 100 mas cercano hacia arriba».
 *
 * ═══ HACIA ARRIBA, Y POR QUE IMPORTA QUE SEA HACIA ARRIBA ═══
 *
 * Subiendo las N−1 primeras, la ULTIMA queda mas chica que las demas. Es como
 * se acomoda un plan de pagos: el cliente no se encuentra con que la cuota
 * final es la mas cara de todas. Redondeando al mas cercano el ajuste caeria
 * para cualquiera de los dos lados y la ultima podria ser la mayor.
 *
 * ═══ 🔴 LA INVARIANTE: LA SUMA DA EL SALDO, SIEMPRE ═══
 *
 * `PlanDeCuotas::aFechasPactadas()` rechaza un calendario cuyos capitales no
 * sumen el saldo al centimo, y con razon: son numeros que una persona
 * escribio. Si este reparto devolviera algo que no cierra, el rechazo llegaria
 * al firmar, con el cliente enfrente.
 *
 * Por eso las dos guardas, y por eso las dos vuelven al reparto EXACTO en vez
 * de inventar un ajuste:
 *
 *  1. **La ultima quedaria en cero o en negativo.** Las N−1 primeras, ya
 *     subidas, pueden pasarse solas del saldo: L 250 en cinco pagos daria
 *     cuotas de L 100 y una ultima de −150.
 *  2. **La parte redondeada da cero.** Solo con saldo cero, que no deberia
 *     llegar hasta aca, pero un plan con cuotas de nada no es un plan.
 *
 * Con UNA sola cuota no hay nada que redondear: esa cuota ES el saldo, y
 * tocarla dejaria un plan que no cuadra.
 *
 * ⚠️ Todo en centavos ENTEROS. Ni un `ceil()` sobre una division flotante:
 * es la misma razon del §8.3.1 —un float no representa centavos exactos— y
 * ademas `intdiv` deja la cuenta legible para quien la audite.
 *
 * ⚠️ Esto NO vive en `PlanDeCuotas`, a proposito. El motor reparte con
 * `intdiv` y deja el residuo en la ultima, y ese comportamiento es el caso
 * base congelado en el golden test del §9.C9 —71 cuotas de L 3,472.22 y la
 * ultima de L 3,472.38—. Redondear ahi le reescribiria el plan a todo
 * contrato nuevo de la lotificadora que ya vende asi. Esto es un ayudante que
 * llena campos que despues se pueden corregir a mano, y eso es otra cosa.
 */
final readonly class CuotasParejas
{
    /**
     * A cuanto se redondea cada cuota, en centavos. L 100.
     */
    public const int PASO = 10_000;

    /**
     * Cada cuántos meses, como mucho, se puede pactar un pago.
     *
     * Vivía en el plano como tope de la casilla «Cada» del generador; se mudó
     * acá el 18-oct-2026 porque «Reestructurar el plan» tiene el mismo
     * generador, y dos números escritos a mano algún día no coinciden.
     */
    public const int CADA_MAXIMO_MESES = 60;

    /**
     * Las N cuotas, en orden. La suma da exactamente `$saldo`.
     *
     * @param int $cuantas cuantas cuotas, 1 o mas
     * @param int $paso a que multiplo se redondea, en centavos
     *
     * @return list<Monto>
     */
    public static function repartir(Monto $saldo, int $cuantas, int $paso = self::PASO): array
    {
        if ($cuantas < 1) {
            return [];
        }

        $centavos = $saldo->enCentavos();
        $parte = self::parte($centavos, $cuantas, $paso);
        $cuotas = [];

        for ($i = 0; $i < $cuantas; $i++) {
            $cuotas[] = Monto::deCentavos(
                $i === $cuantas - 1
                    ? $centavos - $parte * ($cuantas - 1)
                    : $parte,
            );
        }

        return $cuotas;
    }

    /**
     * Los capitales que hacen que el cliente ENTREGUE numeros redondos.
     *
     * ═══ QUE PROBLEMA RESUELVE, Y POR QUE NO ES `repartir()` ═══
     *
     * `repartir()` redondea el CAPITAL. Con interes eso no alcanza: el cliente
     * no entrega el capital, entrega capital MAS el interes de ese tramo, y
     * un capital redondo de L 220,000.00 con L 4,339.73 de interes se paga
     * como L 224,339.73. Mauricio, 18-sep-2026: «las cuotas deben de
     * redondearse al 100 mas cercano hacia arriba, para no estar pagando
     * centavos».
     *
     * Entonces se redondea **lo que se entrega** y el capital se despeja hacia
     * atras. Son el mismo numero visto de dos lados y NO pueden ser redondos
     * los dos: con esta funcion la columna «Cancela del saldo» deja de verse
     * pareja, y es a proposito.
     *
     * ═══ SE RESUELVE FECHA POR FECHA, Y TIENE QUE SER ASI ═══
     *
     * El interes de una fecha depende del capital vivo, que depende de los
     * capitales de las fechas anteriores. No hay formula cerrada: se recorre
     * en orden, y en cada paso se sabe el interes ANTES de decidir el capital
     * —porque el interes de ese tramo se devenga sobre el saldo que venia,
     * no sobre lo que se va a pagar—.
     *
     * ⚠️ **La ultima NO se redondea.** Paga el sobrante, que es lo que cierra
     * el saldo al centimo; forzarla redonda dejaria un plan que
     * `PlanDeCuotas::aFechasPactadas()` rechaza al firmar. Mauricio: «en la
     * ultima cuota pues ahi solo se paga el sobrante que queda».
     *
     * ⚠️ El interes lo calcula `PlanDeCuotas::devengo()`, el MISMO que va a
     * usar el motor al firmar. Escribir la formula otra vez aca seria tener
     * dos versiones de la cuenta (§9.D10), y la que miente siempre es la que
     * nadie mira.
     *
     * La guarda: si el capital despejado saliera cero, negativo, o no dejara
     * al menos un centavo para cada fecha que falta, esa fecha cae al reparto
     * parejo. Cerrar contra el saldo manda sobre verse lindo.
     *
     * @param list<CarbonImmutable> $fechas en el orden en que se van a pagar
     *
     * @return list<PagoPactado> un capital por fecha; su suma da `$saldo`
     */
    public static function conInteres(
        array $fechas,
        Monto $saldo,
        CarbonImmutable $fechaContrato,
        TasaDeInteres $tasa,
        int $paso = self::PASO,
    ): array {
        $cuantas = count($fechas);

        if ($cuantas === 0) {
            return [];
        }

        $vivo = $saldo->enCentavos();
        $anterior = $fechaContrato;
        $pagos = [];

        foreach ($fechas as $i => $fecha) {
            $faltan = $cuantas - $i - 1;

            if ($faltan === 0) {
                // La ultima: el sobrante, sin redondear. Es lo que cierra.
                $capital = $vivo;
            } else {
                $interes = PlanDeCuotas::devengo(
                    Monto::deCentavos($vivo),
                    $tasa,
                    $anterior,
                    $fecha,
                )->enCentavos();

                // El reparto parejo de lo que QUEDA, recalculado en cada paso:
                // asi el ajuste no se acumula todo en la ultima.
                $parejo = intdiv($vivo, $faltan + 1);
                $capital = self::haciaArriba($parejo + $interes, $paso) - $interes;

                // Tiene que quedar algo para cada fecha que falta.
                if ($capital <= 0 || $vivo - $capital < $faltan) {
                    $capital = $parejo;
                }
            }

            $pagos[] = new PagoPactado($fecha, Monto::deCentavos($capital));

            $vivo -= $capital;
            $anterior = $fecha;
        }

        return $pagos;
    }

    /**
     * Con interés y CUOTA FIJA: la misma entrega en cada fecha, redonda, y la
     * última paga el sobrante.
     *
     * ═══ POR QUE EXISTE (02-oct-2026) ═══
     *
     * `conInteres()` reparte el capital parejo y le suma el interés del
     * tramo, así que con interés las entregas BAJAN solas: el interés corre
     * sobre lo que queda. Con cuotas mensuales casi no se nota; con cuotas
     * cada tres meses al 12 %, sí: L 10,000,000.00 en 20 cuotas iban de
     * L 802,500.00 la primera a L 514,934.77 la última, y la pantalla decía
     * «20 cuotas de L 802,500.00».
     *
     * Mauricio eligió cuota fija: es lo que el cliente espera y lo que ya hace
     * la venta a plazos. Las mismas 20 cuotas quedan en 19 de L 672,300.00 y
     * la última de L 671,778.37.
     *
     * ═══ COMO SE BUSCA ═══
     *
     * Con fechas a días reales no hay fórmula cerrada —cada tramo devenga sus
     * propios días—, así que se busca: la cuota redonda MÁS CHICA con la que,
     * pagándola en cada fecha, lo que queda para la última no la supera. Es
     * monótono —una cuota mayor deja menos para el final— y por eso alcanza
     * con una búsqueda binaria.
     *
     * El interés de cada tramo lo calcula `PlanDeCuotas::devengo()`, el mismo
     * que firma: el capital que sale de acá es exactamente el que el motor va
     * a encontrar.
     *
     * Si con cuotas redondas no se puede —un saldo de centavos en muchas
     * fechas— se busca al centavo; y si tampoco, se reparte como
     * `conInteres()`. Cerrar contra el saldo manda sobre verse lindo.
     *
     * ⚠️ El «tampoco» existe: con tasas muy altas y tramos largos —30 % y
     * cuotas cada 6 meses durante 25 años— el interés de un tramo de 184
     * días supera la cuota que alcanzaría, y cualquier cuota que lo cubra
     * termina de pagar antes de la última fecha. No hay cuota fija posible y
     * las entregas bajan; la pantalla lo dice («de X la primera a Y la
     * última») en vez de anunciar una cuota pareja que no existe.
     *
     * @param list<CarbonImmutable> $fechas en el orden en que se van a pagar
     *
     * @return list<PagoPactado> un capital por fecha; su suma da `$saldo`
     */
    public static function conCuotaFija(
        array $fechas,
        Monto $saldo,
        CarbonImmutable $fechaContrato,
        TasaDeInteres $tasa,
        int $paso = self::PASO,
    ): array {
        // Con una sola fecha, esa fecha paga todo.
        if (count($fechas) < 2) {
            return self::conInteres($fechas, $saldo, $fechaContrato, $tasa, $paso);
        }

        /*
         * Sin interés la entrega ES el capital, y `repartir()` ya da cuotas
         * iguales con el sobrante en la última. NO `conInteres()`: con tasa
         * cero recalcula el parejo en cada fecha y, al final de un plan
         * largo, la cuota baja L 100 sin que nadie lo pidiera.
         */
        if ($tasa->esCero()) {
            $pagos = [];

            foreach (self::repartir($saldo, count($fechas), $paso) as $i => $capital) {
                $pagos[] = new PagoPactado($fechas[$i], $capital);
            }

            return $pagos;
        }

        foreach ([$paso, 1] as $intento) {
            $pagos = self::fijaAlPaso($fechas, $saldo->enCentavos(), $fechaContrato, $tasa, max(1, $intento));

            if ($pagos !== null) {
                return $pagos;
            }
        }

        return self::conInteres($fechas, $saldo, $fechaContrato, $tasa, $paso);
    }

    /**
     * Con una cuota DADA: cuántas hacen falta, como mínimo, y la última paga
     * el sobrante.
     *
     * ═══ POR QUE EXISTE (02-oct-2026) ═══
     *
     * Mauricio: «debería de haber para poner de cuánto quiere que sea la
     * cuota y que en automático calcule el mínimo de cuotas que debe hacer».
     * Es la pregunta al revés: el cliente dice cuánto puede pagar, no en
     * cuántas veces.
     *
     * Las fechas salen de la primera, una cada `$cadaMeses`, con el mismo
     * desplazamiento creciente que usa el resto del sistema. Se paga la
     * cuota en cada fecha hasta que lo que queda —con su interés— entra en
     * una sola: esa es la última, y paga solo eso.
     *
     * @return list<PagoPactado> un capital por fecha; su suma da `$saldo`
     *
     * @throws PlanDeCuotasInvalidoException si la cuota es cero, si no cubre
     *                                       el interés, o si harían falta más
     *                                       fechas de las que admite un plan
     *                                       pactado
     */
    public static function conEntrega(
        Monto $saldo,
        Monto $entrega,
        CarbonImmutable $primera,
        int $cadaMeses,
        CarbonImmutable $fechaContrato,
        TasaDeInteres $tasa,
    ): array {
        $cuota = $entrega->enCentavos();

        if ($cuota < 1) {
            throw PlanDeCuotasInvalidoException::porCuotaEnCero();
        }

        $vivo = $saldo->enCentavos();
        $anterior = $fechaContrato;
        $pagos = [];

        for ($i = 0; $i < PlanDeCuotas::PAGOS_PACTADOS_MAXIMOS; $i++) {
            $fecha = $primera->addMonthsNoOverflow($i * $cadaMeses);
            $interes = PlanDeCuotas::devengo(Monto::deCentavos($vivo), $tasa, $anterior, $fecha)->enCentavos();

            // Lo que queda entra en esta: es la última, y paga solo eso.
            if ($vivo + $interes <= $cuota) {
                $pagos[] = new PagoPactado($fecha, Monto::deCentavos($vivo));

                return $pagos;
            }

            $capital = $cuota - $interes;

            if ($capital < 1) {
                throw PlanDeCuotasInvalidoException::porEntregaQueNoCubreElInteres(
                    $entrega,
                    Monto::deCentavos($interes),
                    $tasa,
                );
            }

            $pagos[] = new PagoPactado($fecha, Monto::deCentavos($capital));
            $vivo -= $capital;
            $anterior = $fecha;
        }

        throw PlanDeCuotasInvalidoException::porEntregaQueNecesitaDemasiadasFechas($entrega, $cadaMeses);
    }

    /**
     * La cuota fija redonda a `$paso`, o null si a ese paso no se puede.
     *
     * @param list<CarbonImmutable> $fechas
     *
     * @return list<PagoPactado>|null
     */
    private static function fijaAlPaso(
        array $fechas,
        int $saldo,
        CarbonImmutable $fechaContrato,
        TasaDeInteres $tasa,
        int $paso,
    ): ?array {
        $primera = reset($fechas);

        if (! $primera instanceof CarbonImmutable) {
            return null;
        }

        // Techo: pagar todo en la primera fecha, con su interés. Con eso el
        // saldo se acaba antes de la última, que es «alcanza» de sobra.
        $techo = $saldo + PlanDeCuotas::devengo(Monto::deCentavos($saldo), $tasa, $fechaContrato, $primera)->enCentavos();
        $bajo = 1;
        $alto = intdiv($techo + $paso - 1, $paso);

        while ($bajo < $alto) {
            $medio = intdiv($bajo + $alto, 2);

            if (self::alcanza(self::conEsaCuota($fechas, $saldo, $fechaContrato, $tasa, $medio * $paso), $medio * $paso)) {
                $alto = $medio;
            } else {
                $bajo = $medio + 1;
            }
        }

        $prueba = self::conEsaCuota($fechas, $saldo, $fechaContrato, $tasa, $bajo * $paso);

        return $prueba['estado'] === 'cierra' && $prueba['ultima'] <= $bajo * $paso ? $prueba['pagos'] : null;
    }

    /**
     * ¿Con esa cuota el plan termina sin que la última la supere?
     *
     * «Sobra» también alcanza: el saldo se acabaría antes de la última fecha,
     * que es justamente lo que dice que la cuota es de más.
     *
     * @param array{estado: string, pagos: list<PagoPactado>, ultima: int} $prueba
     */
    private static function alcanza(array $prueba, int $cuota): bool
    {
        return $prueba['estado'] === 'sobra'
            || ($prueba['estado'] === 'cierra' && $prueba['ultima'] <= $cuota);
    }

    /**
     * Paga esa cuota en cada fecha y dice cómo termina.
     *
     * - `falta`: la cuota no cubre el interés de algún tramo.
     * - `sobra`: el saldo se acaba antes de la última fecha.
     * - `cierra`: llega a la última; `ultima` es lo que esa paga, con su interés.
     *
     * @param list<CarbonImmutable> $fechas
     *
     * @return array{estado: string, pagos: list<PagoPactado>, ultima: int}
     */
    private static function conEsaCuota(
        array $fechas,
        int $saldo,
        CarbonImmutable $fechaContrato,
        TasaDeInteres $tasa,
        int $cuota,
    ): array {
        $vivo = $saldo;
        $anterior = $fechaContrato;
        $pagos = [];
        $cuantas = count($fechas);

        foreach ($fechas as $i => $fecha) {
            $interes = PlanDeCuotas::devengo(Monto::deCentavos($vivo), $tasa, $anterior, $fecha)->enCentavos();
            $faltan = $cuantas - $i - 1;

            if ($faltan === 0) {
                $pagos[] = new PagoPactado($fecha, Monto::deCentavos($vivo));

                return ['estado' => 'cierra', 'pagos' => $pagos, 'ultima' => $vivo + $interes];
            }

            $capital = $cuota - $interes;

            if ($capital < 1) {
                return ['estado' => 'falta', 'pagos' => [], 'ultima' => 0];
            }

            // Tiene que quedar al menos un centavo para cada fecha que falta.
            if ($vivo - $capital < $faltan) {
                return ['estado' => 'sobra', 'pagos' => [], 'ultima' => 0];
            }

            $pagos[] = new PagoPactado($fecha, Monto::deCentavos($capital));
            $vivo -= $capital;
            $anterior = $fecha;
        }

        return ['estado' => 'falta', 'pagos' => [], 'ultima' => 0];
    }

    /**
     * El multiplo de `$paso` inmediatamente superior o igual, en enteros.
     */
    private static function haciaArriba(int $centavos, int $paso): int
    {
        if ($paso < 2 || $centavos <= 0) {
            return $centavos;
        }

        return intdiv($centavos + $paso - 1, $paso) * $paso;
    }

    /**
     * Las fechas de N cuotas, una cada tantos meses desde la primera.
     *
     * Cada fecha se calcula desde la PRIMERA con un desplazamiento creciente
     * (`$i * $cada`) y no sumándole meses a la anterior: encadenar arrastra:
     * un 31 de enero cae al 28 de febrero y de ahí en adelante todas quedan
     * el 28. Es el mismo molde que usa `PlanDeCuotas` para los vencimientos.
     *
     * Vivía en el plano (`VerPlano`); se mudó acá el 18-oct-2026 porque
     * «Reestructurar el plan» arma el mismo calendario, y dos copias de la
     * misma cuenta terminan diciendo dos cosas (§9.D10).
     *
     * @return list<CarbonImmutable>
     */
    public static function fechasCada(CarbonImmutable $primera, int $cuantas, int $cada): array
    {
        $fechas = [];

        for ($i = 0; $i < $cuantas; $i++) {
            $fechas[] = $primera->addMonthsNoOverflow($i * $cada);
        }

        return $fechas;
    }

    /**
     * El saldo repartido en esas fechas, con o sin interés.
     *
     * Hay DOS repartos, porque redondear no significa lo mismo con interés
     * que sin él (18-sep y 02-oct-2026):
     *
     * - **Sin interés**, capital y entrega son el mismo número: se redondea
     *   el capital y listo (`repartir()`).
     * - **Con interés**, lo parejo es lo que el cliente entrega, capital más
     *   el interés del tramo, y el capital se despeja hacia atrás
     *   (`conCuotaFija()`).
     *
     * En los dos casos la última paga el sobrante: es lo que cierra el saldo
     * al céntimo, y sin eso el motor rechaza el plan al firmar.
     *
     * ⚠️ Lo llaman el botón «Repartir» y el trato del plano, y «Repartir» de
     * «Reestructurar el plan» (18-oct-2026). Cuál de los dos repartos toca se
     * decide SOLO acá.
     *
     * @param list<CarbonImmutable> $fechas
     *
     * @return list<PagoPactado> un capital por fecha; su suma da `$saldo`
     */
    public static function enLasFechas(array $fechas, Monto $saldo, TasaDeInteres $tasa, CarbonImmutable $firma): array
    {
        if (! $tasa->esCero()) {
            return self::conCuotaFija($fechas, $saldo, $firma, $tasa);
        }

        $pagos = [];

        foreach (self::repartir($saldo, count($fechas)) as $i => $capital) {
            $pagos[] = new PagoPactado($fechas[$i], $capital);
        }

        return $pagos;
    }

    /**
     * ¿El redondeo se pudo aplicar, o hubo que caer al reparto exacto?
     *
     * Lo pregunta la pantalla para decirlo: una persona que pidio cuotas
     * redondas y recibe L 33.33 merece saber por que, en vez de pensar que el
     * boton no funciono.
     */
    public static function seRedondeo(Monto $saldo, int $cuantas, int $paso = self::PASO): bool
    {
        if ($cuantas < 2) {
            return false;
        }

        $centavos = $saldo->enCentavos();

        return self::parte($centavos, $cuantas, $paso) !== intdiv($centavos, $cuantas);
    }

    /**
     * Lo que se lleva cada cuota menos la ultima, en centavos.
     */
    private static function parte(int $centavos, int $cuantas, int $paso): int
    {
        $exacta = intdiv($centavos, $cuantas);

        if ($cuantas < 2 || $paso < 2) {
            return $exacta;
        }

        $redondeada = self::haciaArriba($exacta, $paso);

        if ($redondeada <= 0) {
            return $exacta;
        }

        // Lo que le quedaria a la ultima con ese redondeo.
        if ($centavos - $redondeada * ($cuantas - 1) <= 0) {
            return $exacta;
        }

        return $redondeada;
    }
}
