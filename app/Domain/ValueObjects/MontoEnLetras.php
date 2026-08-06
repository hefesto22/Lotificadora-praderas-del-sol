<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * El monto escrito con palabras, como se escribe en un recibo.
 *
 *     new Monto('35000.50')  →  «TREINTA Y CINCO MIL LEMPIRAS CON 50/100»
 *
 * ═══ PARA QUE SIRVE, QUE NO ES DECORACION ═══
 *
 * Un numero se altera con un trazo: a «L 1,000.00» se le agrega un cero y
 * nadie lo nota. La cantidad en letras al lado hace que las dos versiones
 * tengan que coincidir, y por eso se escribe en todos los recibos de este
 * pais desde antes de que existieran las computadoras. Es la unica proteccion
 * real de un documento que se entrega impreso.
 *
 * ═══ LOS CENTAVOS VAN EN NUMEROS ═══
 *
 * «CON 50/100» y no «con cincuenta centavos»: es la forma usual y no deja
 * lugar a la duda de si son 50 centavos o 5.
 *
 * ═══ SIN FLOAT, COMO TODO EL DINERO ═══
 *
 * Recibe un `Monto` y lo lee de su `redondeado()`, que es una cadena exacta
 * (§8.3.1). Nunca hay una division ni un casteo a float de por medio.
 */
final readonly class MontoEnLetras
{
    /**
     * Hasta donde sabe contar: novecientos noventa y nueve millones.
     *
     * Un lote de esta lotificadora vale unos cientos de miles, asi que el
     * tope sobra. Por encima, `de()` devuelve el numero formateado en vez de
     * mentir con palabras — un recibo con una cantidad incompleta es peor que
     * uno sin cantidad en letras.
     */
    private const int TOPE = 999999999;

    /**
     * @var array<int, string>
     */
    private const array UNIDADES = [
        0  => '', 1 => 'uno', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco',
        6  => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez',
        11 => 'once', 12 => 'doce', 13 => 'trece', 14 => 'catorce', 15 => 'quince',
        16 => 'dieciséis', 17 => 'diecisiete', 18 => 'dieciocho', 19 => 'diecinueve',
        20 => 'veinte', 21 => 'veintiuno', 22 => 'veintidós', 23 => 'veintitrés',
        24 => 'veinticuatro', 25 => 'veinticinco', 26 => 'veintiséis',
        27 => 'veintisiete', 28 => 'veintiocho', 29 => 'veintinueve',
    ];

    /**
     * @var array<int, string>
     */
    private const array DECENAS = [
        3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta',
        7 => 'setenta', 8 => 'ochenta', 9 => 'noventa',
    ];

    /**
     * @var array<int, string>
     */
    private const array CENTENAS = [
        1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos',
        5 => 'quinientos', 6 => 'seiscientos', 7 => 'setecientos',
        8 => 'ochocientos', 9 => 'novecientos',
    ];

    /**
     * La cantidad completa, en mayusculas, lista para el papel.
     */
    public static function de(Monto $monto, string $moneda = 'LEMPIRA'): string
    {
        $partes = explode('.', $monto->redondeado());
        $enteros = (int) $partes[0];
        $centavos = $partes[1] ?? '00';

        if ($enteros > self::TOPE) {
            // Antes que una cantidad en letras incompleta, el numero.
            return mb_strtoupper($monto->formateado().' CON '.$centavos.'/100');
        }

        // «UN LEMPIRA», no «UN LEMPIRAS». Con cero y con todo lo demas va el
        // plural: «CERO LEMPIRAS CON 50/100».
        $unidad = $enteros === 1 ? $moneda : $moneda.'S';
        $letras = self::enPalabras($enteros);

        /*
         * «Un millón DE lempiras», pero «dos millones quinientos mil
         * lempiras». El «de» aparece solo cuando millon o millones es la
         * ultima palabra antes de la moneda; si algo le sigue, se cae. Es la
         * clase de detalle por el que un papel se lee escrito por una persona
         * o por una maquina.
         */
        $conector = str_ends_with($letras, 'millón') || str_ends_with($letras, 'millones')
            ? ' de '
            : ' ';

        return mb_strtoupper($letras.$conector.$unidad.' CON '.$centavos.'/100');
    }

    // ─── Interno ──────────────────────────────────────────────────────

    private static function enPalabras(int $numero): string
    {
        if ($numero === 0) {
            return 'cero';
        }

        $millones = intdiv($numero, 1000000);
        $resto = $numero % 1000000;
        $miles = intdiv($resto, 1000);
        $unidades = $resto % 1000;

        $partes = [];

        if ($millones === 1) {
            $partes[] = 'un millón';
        } elseif ($millones > 1) {
            // «veintiún millones», no «veintiuno millones».
            $partes[] = self::menorQueMil($millones).' millones';
        }

        if ($miles === 1) {
            // «mil», nunca «un mil».
            $partes[] = 'mil';
        } elseif ($miles > 1) {
            $partes[] = self::menorQueMil($miles).' mil';
        }

        if ($unidades > 0) {
            $partes[] = self::menorQueMil($unidades);
        }

        return implode(' ', $partes);
    }

    /**
     * De 1 a 999.
     *
     * ═══ EL UNO SIEMPRE SE APOCOPA ═══
     *
     * «Veintiún mil», «ciento un lempiras», «treinta y un lempiras». La regla
     * del castellano es que `uno` pierde la o final cuando va delante de un
     * sustantivo masculino, y acá SIEMPRE va delante de uno: o de «mil», o de
     * «millones», o de «lempiras». Por eso no hay caso en que convenga
     * `uno` y el apocope no necesita interruptor.
     */
    private static function menorQueMil(int $numero): string
    {
        // «cien» exacto; «ciento uno» en cuanto le sigue algo.
        if ($numero === 100) {
            return 'cien';
        }

        $centenas = intdiv($numero, 100);
        $resto = $numero % 100;

        $palabras = [];

        if ($centenas > 0) {
            $palabras[] = self::CENTENAS[$centenas];
        }

        if ($resto > 0 && $resto < 30) {
            $palabras[] = self::apocope(self::UNIDADES[$resto]);
        } elseif ($resto >= 30) {
            $decenas = intdiv($resto, 10);
            $unidad = $resto % 10;

            $palabras[] = $unidad === 0
                ? self::DECENAS[$decenas]
                : self::DECENAS[$decenas].' y '.self::apocope(self::UNIDADES[$unidad]);
        }

        return implode(' ', $palabras);
    }

    private static function apocope(string $palabra): string
    {
        return match ($palabra) {
            'uno'       => 'un',
            'veintiuno' => 'veintiún',
            default     => $palabra,
        };
    }
}
