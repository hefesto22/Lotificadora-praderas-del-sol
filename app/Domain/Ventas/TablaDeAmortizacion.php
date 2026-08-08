<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use Countable;

/**
 * La tabla francesa. Aritmetica pura, sin fechas, sin base de datos.
 *
 * ═══ QUE HACE, EN UNA LINEA ═══
 *
 * Reparte un capital en N cuotas iguales, cada una partida en la parte que
 * paga intereses y la parte que baja la deuda:
 *
 *     cuota = P x i ÷ (1 − (1+i)^−n)
 *
 * ═══ 🔴 LA TRAMPA QUE EL ANALISIS NO VIO ═══
 *
 * `docs/que-le-falta.md` §1.2 dice que con `i = 0` la formula «degenera
 * exactamente en P ÷ n», y **matematicamente es cierto pero
 * computacionalmente es una division por cero**: con `i = 0` el numerador es
 * `P x 0 = 0` y el denominador es `1 − 1 = 0`. El limite existe; la cuenta
 * no. `bcdiv` lo rechaza y `Monto` tambien.
 *
 * Por eso la tasa cero NO pasa por aca: `PlanDeCuotas` la atiende por su
 * camino de siempre, el que el golden test del §9.C9 congela desde el
 * 4-ago-2026. No es una optimizacion —es la unica forma correcta— y ademas
 * tiene el efecto de que **Praderas del Sol corre exactamente el mismo
 * codigo que corria antes del 8-ago**, a doce dias de arrancar.
 *
 * ═══ EL RESIDUO VA A LA ULTIMA, IGUAL QUE SIEMPRE (§8.2) ═══
 *
 * La cuota se redondea a dos decimales, asi que las 47 primeras casi nunca
 * amortizan el capital exacto. La ultima **no se calcula: se despeja**, y su
 * capital es todo el saldo que quedaba. Con eso
 *
 *     Σ capital = P, al centimo
 *
 * que es la igualdad de la que vive `PlanDeCuotas::cierraExacto()`. Lo que
 * NO cierra contra P es la suma de las cuotas: esa da P + intereses, y
 * confundir las dos es el error que rompe el formulario de venta.
 *
 * ═══ POR QUE LA CUOTA FIJA SE ITERA Y NO SE DESPEJA ═══
 *
 * El camino «misma cuota, menos meses» de R21 necesita el N que corresponde a
 * una cuota dada, y despejarlo pide logaritmos —`ln`— que bcmath no tiene.
 * Simularlo mes a mes da el mismo numero, es exacto, y esta acotado por
 * `PlanDeCuotas::PLAZO_MAXIMO_MESES`. Ademas atrapa gratis el caso imposible:
 * una cuota que no alcanza a cubrir ni el interes del mes deja la deuda
 * creciendo para siempre.
 */
final readonly class TablaDeAmortizacion implements Countable
{
    /**
     * @param list<array{capital: Monto, interes: Monto}> $renglones
     */
    private function __construct(
        public array $renglones,
        public Monto $capitalFinanciado,
        public Monto $cuotaNivelada,
    ) {}

    /**
     * N conocido: se calcula la cuota.
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function porPlazo(Monto $capital, TasaDeInteres $tasa, int $plazoMeses): self
    {
        self::verificarEntrada($capital, $tasa, $plazoMeses);

        $mensual = $tasa->mensual();
        $cuota = self::cuotaNiveladaDe($capital, $mensual, $plazoMeses);

        $renglones = [];
        $saldo = $capital;

        for ($k = 1; $k <= $plazoMeses; $k++) {
            $interes = self::interesDe($saldo, $mensual);

            /*
             * La ultima no se calcula: se despeja. Todo el saldo que quedaba
             * es su capital, y con eso Σ capital = P al centimo.
             */
            if ($k === $plazoMeses) {
                $renglones[] = ['capital' => $saldo, 'interes' => $interes];
                $saldo = Monto::cero();

                break;
            }

            if (! $cuota->mayorQue($interes)) {
                throw PlanDeCuotasInvalidoException::porCuotaQueNoCubreElInteres($cuota, $interes, $tasa);
            }

            $porCapital = $cuota->restar($interes);

            /*
             * Amortizo antes de tiempo. Pasa solo con redondeos extremos —una
             * cuota de centavos— pero si pasara, seguir generaria cuotas de
             * L 0.00 y el CHECK `cuotas_monto_positivo_chk` reventaria en la
             * cara del usuario en vez de decirle que hacer.
             */
            if (! $saldo->mayorQue($porCapital)) {
                throw PlanDeCuotasInvalidoException::porSaldoDemasiadoChicoParaElPlazo($capital, $plazoMeses);
            }

            $renglones[] = ['capital' => $porCapital, 'interes' => $interes];
            $saldo = $saldo->restar($porCapital);
        }

        return self::armada($renglones, $capital, $cuota);
    }

    /**
     * La cuota es dato: se calcula cuantas hacen falta (R21, acortar plazo).
     *
     * @throws PlanDeCuotasInvalidoException
     */
    public static function porCuota(Monto $capital, TasaDeInteres $tasa, Monto $cuota, int $plazoMaximo): self
    {
        self::verificarEntrada($capital, $tasa, 1);

        if ($cuota->esCero()) {
            throw PlanDeCuotasInvalidoException::porCuotaEnCero();
        }

        $mensual = $tasa->mensual();
        $renglones = [];
        $saldo = $capital;

        for ($k = 1; $k <= $plazoMaximo; $k++) {
            $interes = self::interesDe($saldo, $mensual);

            if (! $cuota->mayorQue($interes)) {
                throw PlanDeCuotasInvalidoException::porCuotaQueNoCubreElInteres($cuota, $interes, $tasa);
            }

            $porCapital = $cuota->restar($interes);

            // Esta cuota termina de pagar: absorbe lo que quede y se corta.
            if (! $saldo->mayorQue($porCapital)) {
                $renglones[] = ['capital' => $saldo, 'interes' => $interes];

                return self::armada($renglones, $capital, $cuota);
            }

            $renglones[] = ['capital' => $porCapital, 'interes' => $interes];
            $saldo = $saldo->restar($porCapital);
        }

        /*
         * Se acabo el tope y todavia debe. Con la verificacion de arriba esto
         * no deberia poder pasar —cada cuota baja capital— pero si el tope es
         * chico para el capital, el mensaje tiene que decirlo y no devolver
         * una tabla que no paga la deuda.
         */
        throw PlanDeCuotasInvalidoException::porPlazoInvalido($plazoMaximo + 1);
    }

    /**
     * La suma del capital. Tiene que dar exactamente `capitalFinanciado`.
     */
    public function totalCapital(): Monto
    {
        $total = Monto::cero();

        foreach ($this->renglones as $renglon) {
            $total = $total->sumar($renglon['capital']);
        }

        return $total;
    }

    /**
     * Lo que el interes le agrega al precio. Es el numero que hay que
     * imprimir en el contrato con todas las letras, no esconderlo en la cuota.
     */
    public function totalInteres(): Monto
    {
        $total = Monto::cero();

        foreach ($this->renglones as $renglon) {
            $total = $total->sumar($renglon['interes']);
        }

        return $total;
    }

    public function count(): int
    {
        return count($this->renglones);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param list<array{capital: Monto, interes: Monto}> $renglones
     *
     * @throws PlanDeCuotasInvalidoException
     */
    private static function armada(array $renglones, Monto $capital, Monto $cuota): self
    {
        $tabla = new self($renglones, $capital, $cuota);

        /*
         * La igualdad que sostiene todo, verificada acá y no solo en un test:
         * una tabla que no cierra no debe poder existir, ni siquiera para
         * mostrarse en pantalla.
         */
        if (! $tabla->totalCapital()->igualA($capital)) {
            throw PlanDeCuotasInvalidoException::porTablaQueNoCierra($tabla->totalCapital(), $capital);
        }

        return $tabla;
    }

    /**
     * `cuota = P x i ÷ (1 − (1+i)^−n)`, redondeada a dos decimales.
     *
     * Se calcula con bcmath crudo a escala 20 y no con `Monto`, que trabaja a
     * 12: la mensual de una tasa como el 10 % es periodica y truncarla antes
     * de multiplicarla por el capital arrastra el error a las 48 cuotas.
     *
     * El tipo de PHP no sabe decir `numeric-string`, asi que el docblock es lo
     * unico que conserva la garantia que da `TasaDeInteres::mensual()`. Sin
     * el, PHPStan nivel 7 rechaza cada llamada a bcmath de aca abajo — es la
     * misma razon por la que `Monto::$valor` esta anotado.
     *
     * @param numeric-string $mensual
     */
    private static function cuotaNiveladaDe(Monto $capital, string $mensual, int $plazoMeses): Monto
    {
        $escala = TasaDeInteres::ESCALA;

        $potencia = bcpow(bcadd('1', $mensual, $escala), (string) (-$plazoMeses), $escala);
        $factor = bcsub('1', $potencia, $escala);

        /*
         * Con `i > 0` y `n >= 1`, `(1+i)^−n` es estrictamente menor que 1, asi
         * que el factor es positivo. Se verifica igual: si alguna vez entrara
         * una tasa cero por acá, la division seria por cero y el mensaje de
         * bcmath no le diria nada a nadie.
         */
        if (bccomp($factor, '0', $escala) <= 0) {
            throw PlanDeCuotasInvalidoException::porTasaQueNoAmortiza();
        }

        return self::aDosDecimales(bcdiv(bcmul($capital->valor, $mensual, $escala), $factor, $escala));
    }

    /**
     * El interes del mes: saldo x tasa mensual, redondeado a dos decimales.
     *
     * Redondeado acá y no al final: lo que el cliente ve en la tabla del
     * contrato es este numero, y si el sistema guardara mas decimales, la
     * suma de la columna no daria el total impreso.
     *
     * @param numeric-string $mensual
     */
    private static function interesDe(Monto $saldo, string $mensual): Monto
    {
        return self::aDosDecimales(bcmul($saldo->valor, $mensual, TasaDeInteres::ESCALA));
    }

    /**
     * Un resultado crudo de bcmath, convertido a un `Monto` de dos decimales.
     *
     * Va en dos pasos a proposito: el primer `Monto` normaliza la cadena y
     * hereda su validacion —nada negativo, nada en notacion cientifica— y
     * `redondeado()` es el unico half-up del sistema (§8.3.1). Escribirlo
     * suelto seria una tercera implementacion del redondeo.
     */
    private static function aDosDecimales(string $crudo): Monto
    {
        /** @var numeric-string $crudo */
        return new Monto(new Monto($crudo)->redondeado());
    }

    /**
     * @throws PlanDeCuotasInvalidoException
     */
    private static function verificarEntrada(Monto $capital, TasaDeInteres $tasa, int $plazoMeses): void
    {
        if ($tasa->esCero()) {
            // Ver el docblock de la clase: esto no es una preferencia.
            throw PlanDeCuotasInvalidoException::porTasaCeroEnLaTablaFrancesa();
        }

        if ($capital->esCero()) {
            throw PlanDeCuotasInvalidoException::porSaldoDemasiadoChicoParaElPlazo($capital, $plazoMeses);
        }
    }
}
