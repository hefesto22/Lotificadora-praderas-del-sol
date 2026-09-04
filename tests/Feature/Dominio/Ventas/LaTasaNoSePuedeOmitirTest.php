<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Nadie puede armar un plan de cuotas sin decir la tasa
|--------------------------------------------------------------------------
|
| 🔴 `PlanDeCuotas::nuevo()` recibe la tasa de ÚLTIMA y por defecto es null,
| que significa SIN INTERÉS. Omitirla no revienta, no avisa y no se ve: el
| motor devuelve un plan perfecto en el que el saldo se divide entre los meses.
|
| Dos pantallas la omitían, y las dos son la MISMA pantalla para quien atiende:
|
|   · `VerPlano::renglonesEnPantalla()` — el cuadro «Lo que se va a firmar»
|   · `VentaForm::resumen()` — el resumen del formulario de ventas
|
| Con los planes de pago al 0 % daba igual y por eso vivieron meses ahí. El
| 31-ago-2026 Mauricio puso los cuatro planes de Altamira al 12 % y la misma
| pantalla empezó a decir dos cosas: el plano cotizaba L 21,323.71 y dos clics
| después el cuadro de la firma decía L 20,000.00 —240,000 entre 12—.
|
| Lo que se guardaba nunca estuvo mal: `RegistroDeVentas` resuelve la tasa
| ausente como la del plan, así que ningún contrato salió mal cobrado. Lo que
| mentía era el número que el vendedor le lee al cliente antes de firmar, que
| es peor: no deja rastro y se descubre con el primer estado de cuenta.
|
| ═══ POR QUÉ ESTE TEST Y NO UNO POR PANTALLA ═══
|
| Porque las dos previsualizaciones son privadas y viven detrás de closures de
| Filament: probarlas de a una obliga a renderizar modales, que es frágil y no
| cubre la tercera que alguien escriba mañana. Esto mira TODAS las llamadas del
| código y exige las seis. La próxima nace fallando.
|
| Si una llamada de verdad no cobra interés, la tasa se pasa igual y en claro:
| `TasaDeInteres::cero()`. Decidir cero es una decisión; olvidarlo no.
|
*/
test('ninguna llamada a PlanDeCuotas::nuevo() puede omitir la tasa', function (): void {
    $flojas = [];

    foreach (File::allFiles(app_path()) as $archivo) {
        if ($archivo->getExtension() !== 'php') {
            continue;
        }

        $codigo = (string) file_get_contents($archivo->getPathname());
        $desde = 0;

        while (($donde = strpos($codigo, 'PlanDeCuotas::nuevo(', $desde)) !== false) {
            $abre = $donde + strlen('PlanDeCuotas::nuevo(') - 1;
            $cierra = cierreDelParentesis($codigo, $abre);
            $desde = $cierra + 1;

            $adentro = substr($codigo, $abre + 1, $cierra - $abre - 1);

            // `PlanDeCuotas::nuevo()` a secas es una mención en un comentario.
            if (trim($adentro) === '') {
                continue;
            }

            if (argumentosDeLaLlamada($adentro) >= 6) {
                continue;
            }

            $flojas[] = sprintf(
                '%s:%d',
                $archivo->getRelativePathname(),
                substr_count(substr($codigo, 0, $donde), "\n") + 1,
            );
        }
    }

    expect($flojas)->toBe([]);
});

/**
 * Dónde cierra el paréntesis que abre en $abre.
 */
function cierreDelParentesis(string $codigo, int $abre): int
{
    $nivel = 0;
    $largo = strlen($codigo);

    for ($donde = $abre; $donde < $largo; $donde++) {
        if ($codigo[$donde] === '(') {
            $nivel++;
        }

        if ($codigo[$donde] === ')') {
            $nivel--;

            if ($nivel === 0) {
                return $donde;
            }
        }
    }

    return $largo - 1;
}

/**
 * Cuántos argumentos lleva una lista, sin contar la coma final.
 *
 * Se parte por las comas de NIVEL CERO: `$renglon['valor']` y
 * `CarbonImmutable::parse(today()->toDateString())` son un argumento cada uno,
 * aunque traigan corchetes y paréntesis adentro.
 */
function argumentosDeLaLlamada(string $adentro): int
{
    $nivel = 0;
    $cuantos = 0;
    $actual = '';

    foreach (str_split($adentro) as $letra) {
        if (in_array($letra, ['(', '[', '{'], true)) {
            $nivel++;
        }

        if (in_array($letra, [')', ']', '}'], true)) {
            $nivel--;
        }

        if ($letra === ',' && $nivel === 0) {
            if (trim($actual) !== '') {
                $cuantos++;
            }

            $actual = '';

            continue;
        }

        $actual .= $letra;
    }

    return trim($actual) === '' ? $cuantos : $cuantos + 1;
}
