<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\ValueObjects\Monto;
use Illuminate\Support\HtmlString;

/**
 * Los dos cuadros que arma PHP: los lotes del contrato y la escalera de cuotas.
 *
 * ═══ POR QUE EXISTEN DOS VECES EN LA PANTALLA ═══
 *
 * Se ven en el modal del plano —antes de firmar, con lo que se está cotizando—
 * y en la ficha del expediente —después, con lo que quedó guardado—. Son el
 * mismo cuadro contando dos momentos, así que se arman en un solo lugar: si
 * cada pantalla tuviera el suyo, algún día uno diría 48 cuotas y el otro 47.
 *
 * ═══ HTML A MANO, CON CLASES PROPIAS ═══
 *
 * Las clases NO son utilidades de Tailwind. El CSS de Filament se compila
 * aparte y no ve las clases que arma un `HtmlString` del lado de PHP: escritas
 * como utilidades salen sin un solo margen. Viven en el partial
 * `filament.estilos-olympo`, que el panel inyecta en el <head> de todas las
 * páginas.
 */
final class Cuadros
{
    /**
     * La tabla de lotes: uno por renglón, con su plazo y su cuota.
     *
     * @param list<array{codigo: string, area: string, plazo: int, precio: Monto, valor: Monto, prima: Monto, cuota: Monto|null}> $renglones
     */
    public static function lotes(array $renglones, string $vacio = 'Todavía no hay ningún lote marcado.'): HtmlString
    {
        if ($renglones === []) {
            return new HtmlString('<p class="olympo-vacio">'.e($vacio).'</p>');
        }

        $filas = '';

        foreach ($renglones as $renglon) {
            $deContado = $renglon['plazo'] === 0;

            $filas .= sprintf(
                '<tr>'
                .'<td class="lote">%s</td>'
                .'<td class="apagado">%s</td>'
                .'<td>%s</td>'
                .'<td><span class="olympo-pill%s">%s</span></td>'
                .'<td>%s</td>'
                .'<td class="apagado">%s</td>'
                .'<td class="fuerte">%s</td>'
                .'</tr>',
                e($renglon['codigo']),
                e(self::conMiles(new Monto($renglon['area'])->redondeado())),
                e($renglon['precio']->formateado()),
                $deContado ? ' contado' : '',
                e($deContado ? 'Contado' : $renglon['plazo'].' meses'),
                e($renglon['valor']->formateado()),
                e($renglon['prima']->esCero() ? '—' : $renglon['prima']->formateado()),
                e($renglon['cuota'] instanceof Monto ? $renglon['cuota']->formateado() : '—'),
            );
        }

        /*
         * ═══ POR QUE VA ADENTRO DE UN ENVOLTORIO CON SCROLL ═══
         *
         * Son siete columnas y las siete van `nowrap`: un valor partido en
         * dos renglones no es un valor. Cuando no entran, la tarjeta de
         * Filament NO ofrece scroll —RECORTA—, y «L. 54,166.67» se lee
         * «L. 54,1» sin que nada avise. Pasó en la ficha del expediente, y
         * el mismo cuadro se ve en el modal del plano, que es más angosto.
         *
         * Que se corra de lado es feo. Que un número mienta, no se puede.
         */
        return new HtmlString(
            '<div class="olympo-scroll"><table class="olympo-tabla"><thead><tr>'
            .'<th>Lote</th><th>vr²</th><th>Precio vr²</th><th>Plazo</th>'
            .'<th>Valor</th><th>Prima</th><th>Cuota</th>'
            .'</tr></thead><tbody>'.$filas.'</tbody></table></div>'
        );
    }

    /**
     * La escalera: cuánto paga por mes, y hasta cuándo.
     *
     * Con un lote a 12 meses, otro a 24 y otro a 48, la pregunta del cliente
     * no tiene una sola respuesta: paga los tres juntos hasta el mes 12, dos
     * hasta el 24 y uno hasta el 48. Contestar con el primer número sería
     * exacto por doce meses y falso por treinta y seis.
     *
     * @param list<array{desde: int, hasta: int, monto: Monto}> $tramos
     */
    public static function escalera(array $tramos): HtmlString
    {
        $legibles = self::tramosLegibles($tramos);

        if ($legibles === []) {
            return new HtmlString('<p class="olympo-vacio">De contado, sin cuotas.</p>');
        }

        $filas = '';
        $ajustes = [];

        foreach ($legibles as $tramo) {
            $filas .= sprintf(
                '<li><span class="meses">%s</span><span class="monto">%s</span></li>',
                e($tramo['desde'] === $tramo['hasta']
                    ? sprintf('Mes %d', $tramo['desde'])
                    : sprintf('Meses %d al %d', $tramo['desde'], $tramo['hasta'])),
                e($tramo['monto']->formateado()),
            );

            if ($tramo['ajuste'] instanceof Monto) {
                $ajustes[] = sprintf('el mes %d son %s', $tramo['hasta'], $tramo['ajuste']->formateado());
            }
        }

        $notas = [];

        if (count($legibles) > 1) {
            $notas[] = 'La cuota baja sola: cada vez que un lote termina de pagarse, deja de sumar.';
        }

        if ($ajustes !== []) {
            $notas[] = 'La última cuota de cada lote absorbe el residuo del redondeo — '
                .implode(', ', $ajustes).'.';
        }

        return new HtmlString(
            '<ul class="olympo-escalera">'.$filas.'</ul>'
            .($notas === [] ? '' : '<p class="olympo-nota">'.e(implode(' ', $notas)).'</p>')
        );
    }

    /**
     * Los tramos, para leer.
     *
     * El cálculo exacto corta un tramo cada vez que cambia un céntimo: la
     * última cuota de cada lote absorbe el residuo del redondeo, así que un
     * contrato de tres lotes sale con cinco tramos y dos de ellos duran un mes
     * y difieren en centavos. Es cierto y es ilegible.
     *
     * Acá esos meses sueltos se pegan al tramo de arriba y el céntimo se cuenta
     * aparte, en una nota. No se toca el cálculo: lo que está en `cuotas` sigue
     * siendo lo exacto.
     *
     * @param list<array{desde: int, hasta: int, monto: Monto}> $tramos
     *
     * @return list<array{desde: int, hasta: int, monto: Monto, ajuste: Monto|null}>
     */
    public static function tramosLegibles(array $tramos): array
    {
        /*
         * El tramo en curso vive en una VARIABLE y se reemplaza entero, no se
         * le tocan las claves de a una. Escribir `$lista[$i]['hasta'] = ...`
         * le ensancha el tipo a PHPStan —no puede probar que ese indice exista,
         * asi que contempla que la asignacion cree un elemento suelto— y a
         * partir de ahi `$lista[$i]['monto']` deja de ser un Monto.
         */
        $legibles = [];
        $actual = null;

        foreach ($tramos as $tramo) {
            if ($actual === null) {
                $actual = [
                    'desde'  => $tramo['desde'],
                    'hasta'  => $tramo['hasta'],
                    'monto'  => $tramo['monto'],
                    'ajuste' => null,
                ];

                continue;
            }

            $esUnMesSuelto = $tramo['desde'] === $tramo['hasta'];
            $porCentavos = abs($tramo['monto']->enCentavos() - $actual['monto']->enCentavos()) < 100;

            // Un solo ajuste por tramo: dos meses sueltos seguidos son otra
            // cosa y merecen su propio renglon.
            if ($esUnMesSuelto && $porCentavos && ! $actual['ajuste'] instanceof Monto) {
                $actual = [
                    'desde'  => $actual['desde'],
                    'hasta'  => $tramo['hasta'],
                    'monto'  => $actual['monto'],
                    'ajuste' => $tramo['monto'],
                ];

                continue;
            }

            $legibles[] = $actual;
            $actual = [
                'desde'  => $tramo['desde'],
                'hasta'  => $tramo['hasta'],
                'monto'  => $tramo['monto'],
                'ajuste' => null,
            ];
        }

        if ($actual !== null) {
            $legibles[] = $actual;
        }

        return $legibles;
    }

    /**
     * Un decimal con separador de miles.
     *
     * El área sale de la base con cuatro decimales (250.0000) y así no se le
     * enseña a nadie: en pantalla son dos, en todos lados.
     */
    public static function conMiles(string $decimal): string
    {
        [$entera, $fraccion] = array_pad(explode('.', $decimal, 2), 2, '00');

        return number_format((int) $entera).'.'.$fraccion;
    }
}
