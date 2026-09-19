<?php

declare(strict_types=1);

namespace Database\Seeders\Clientes;

use App\Domain\Enums\UnidadDeArea;
use Database\Seeders\PlanoDeclarado;
use Database\Seeders\PlanoDesdeDxfSeeder;

/**
 * LOTIFICACION LA UNION — La Union, Copan. Carga inicial del plano.
 *
 *   php artisan db:seed --class="Database\Seeders\Clientes\LotificacionLaUnionSeeder"
 *
 * Plano de distribucion de lotes del Ing. Gerson Menjivar (CICH 9293),
 * noviembre de 2025, levantado por el Top. Antonio Mejia Mendez.
 * Propietarios: Omar Leiva y Leo Mejia. El cajetin no le pone nombre
 * comercial -dice «Lotificacion en La Union Copan»-; el nombre y el codigo
 * `LLU` los confirmo Mauricio el 18-sep-2026.
 *
 * Es del mismo ingeniero que COLONIA RIO BLANCO y esta dibujado igual: sin
 * un solo lote cerrado, todo en la capa `0`, los numeros sin letra y la
 * manzana en un texto «BLOQUE X» aparte. El porque de cada opcion esta en
 * el docblock de ColoniaRioBlancoSeeder; aca va solo lo que este plano
 * tiene de distinto.
 *
 * ═══ LO QUE DICE EL PLANO ═══
 *
 * 95 lotes y 34,578.87 varas² sumando los 95 rotulos de area, con la
 * misma vara de 0.8350 m. Seis manzanas con nombre:
 *
 *   A 8 · B 21 · C 26 · D 24 · E 6 · F 6   = 91
 *
 * ⚠️ Contado leyendo los TEXTOS del archivo y mirando el dibujo, no de la
 * salida de una importacion, pero sin un plano IMPRESO de por medio.
 * Cuando llegue el PDF del ingeniero, se coteja.
 *
 * ═══ 🔴 LA MANZANA G ES PROVISIONAL ═══
 *
 * Al oriente del plano hay CUATRO lotes grandes, de 1,109.88 varas² cada
 * uno, que el ingeniero numero «1», «1», «1» y «1» y a los que no les puso
 * nombre de manzana. Asi no se pueden vender: cuatro lotes con el mismo
 * numero en la misma manzana no existen.
 *
 * Decision de Mauricio (18-sep-2026), para poder ver el plano completo:
 * entran como manzana **G**, numerados del 1 al 4 de norte a sur y de
 * oeste a este -G-1 arriba a la izquierda, G-2 arriba a la derecha, G-3 y
 * G-4 abajo-. ESOS CUATRO NUMEROS Y ESA LETRA LOS PUSO EL SISTEMA, no el
 * ingeniero. Antes de vender uno hay que preguntarle como se llaman de
 * verdad, y si la respuesta es otra se corrigen en la ficha de cada lote.
 *
 * Por eso `manzanaSinNombre`: es a donde van los lotes de una isla a la
 * que el plano no le puso nombre. Sin declararla caerian en la A -la
 * primera- y se mezclarian con los ocho de la A de verdad.
 *
 * Dos de esos cuatro -G-1 y G-3- vienen ademas partidos en dos por una
 * linea sobrante que cruza la manzana de norte a sur. El importador los
 * une porque las dos mitades de cada uno suman lo que dice su rotulo
 * (586.88 + 521.65 y 632.99 + 476.40, contra 1,109.88). Ver
 * LotesDeLineasSueltas.
 *
 * Un rotulo de area viene SIN la unidad -el A-4, que dice «260.00»-. Entra
 * igual porque su dibujo lo confirma.
 *
 * ═══ LO QUE LE FALTA ═══
 *
 * El PRECIO -los 95 entran en 0.00-, los planes de pago, y la respuesta
 * del ingeniero sobre la manzana G.
 */
final class LotificacionLaUnionSeeder extends PlanoDesdeDxfSeeder
{
    protected function plano(): PlanoDeclarado
    {
        return new PlanoDeclarado(
            codigo: 'LLU',
            nombre: 'LOTIFICACION LA UNION',
            archivo: 'database/data/lotificacion-la-union-plano.dxf',
            // Las seis series de numeros del plano, mas los cuatro lotes
            // sin manzana del oriente. La G es NUESTRA: ver el docblock.
            lotesPorBloque: [
                'A' => 8, 'B' => 21, 'C' => 26,
                'D' => 24, 'E' => 6, 'F' => 6,
                'G' => 4,
            ],
            // La suma de los 95 rotulos «... vr2» del plano.
            areaTotal: 34578.87,
            capaDeLotes: '0',
            // Numeros y areas comparten capa en este plano.
            capaDeRotulos: 'Medidas Numeracion',
            unidad: UnidadDeArea::Varas,
            precioPorUnidad: '0',
            varaEnMetros: '0.835000',
            datos: [
                'municipio'     => 'LA UNION',
                'departamento'  => 'CP',
                'direccion'     => 'LA UNION, COPAN, HONDURAS C.A.',
                'observaciones' => 'Plano de distribución de lotes, noviembre de 2025. Propietarios: OMAR LEIVA y LEO MEJIA. '.
                                   'Levantó: Top. Antonio Mejía Méndez. Dibujó y aprobó: Ing. Gerson Menjívar (CICH 9293). '.
                                   'La geometría de los 95 lotes se armó siguiendo las líneas del DXF nativo, y el área de '.
                                   'cada lote es la que el ingeniero rotuló, con su vara de 0.8350 m. '.
                                   'PROVISIONAL: la manzana G y los números 1 a 4 de sus lotes los puso el sistema; en el plano '.
                                   'esos cuatro lotes de 1,109.88 v² están todos numerados «1» y no tienen manzana. '.
                                   'Hay que confirmarlos con el ingeniero antes de vender cualquiera de los cuatro.',
            ],
            lineasSueltas: true,
            manzanaSinNombre: 'G',
        );
    }
}
