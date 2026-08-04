<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración del negocio de lotificación
|--------------------------------------------------------------------------
|
| Origen único de verdad para las reglas propias del negocio de venta de
| lotes. Lo fiscal y normativo de Honduras vive en config/honduras.php.
|
| §8.3.7: el factor de conversión de varas² a m² vive acá, NUNCA
| hardcodeado en código.
|
| Los números de este archivo salen de las respuestas de la contratante del
| 3-ago-2026, documentadas en docs/dominio.md con su regla (R1…R18). Si uno
| cambia, se cambia primero allá.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Áreas
    |--------------------------------------------------------------------------
    |
    | La unidad del negocio es la VARA CUADRADA. Todas las áreas se
    | almacenan y se operan en varas²; los m² son solo presentación.
    |
    | ✅ CONFIRMADO por la contratante el 3-ago-2026 (R16): la vara del
    | plano es la vara castellana de 0.8359 m. Ningún cálculo de dinero
    | depende de este número —las áreas se guardan en varas² y los precios
    | son por vara²—, pero ya se puede imprimir en un contrato.
    |
    | Se declara como string a propósito: el factor entra a bcmath, y un
    | float acá reintroduciría el error que Monto existe para evitar.
    |
    */
    'area' => [
        'unidad'         => 'vara²',
        'unidad_plural'  => 'varas²',
        'vara_en_metros' => '0.8359',
        'decimales'      => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lotes
    |--------------------------------------------------------------------------
    |
    | Los cuatro estados son contractuales (§8.2): agregar uno requiere
    | aprobación del cliente. La lista canónica vive en el enum
    | App\Domain\Enums\EstadoLote; acá solo se configura el default.
    |
    */
    'lotes' => [
        'estado_inicial' => 'disponible',

        // Longitud máxima del identificador de lote. Admite formatos como
        // "12", "12-A" o "12B" según cómo los tenga rotulados el plano.
        'numero_max' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Apartados (R14)
    |--------------------------------------------------------------------------
    |
    | Los tres números los fijó la contratante y son iguales para todos los
    | lotes: no se negocian por venta. Viven acá y no dentro de una función
    | porque el día que cambien, cambian en un solo lugar.
    |
    | Al vencer sin que el cliente vuelva, el lote queda disponible y EL
    | DINERO SE DEVUELVE — con su documento de salida, no borrando la fila.
    | Al convertirse en venta, el monto cuenta como parte de la prima.
    |
    | `prorrogas_maximas` es 1 a propósito: un apartado que se prorroga dos
    | veces es un apartado que en realidad nunca venció.
    |
    */
    'apartados' => [
        'monto'             => '5000.00',
        'dias_de_vigencia'  => 15,
        'dias_de_prorroga'  => 15,
        'prorrogas_maximas' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ventas y plan de cuotas (R1, R3, R5)
    |--------------------------------------------------------------------------
    |
    | El saldo financiado NO genera interés y el atraso NO genera mora: la
    | cuota es (valor − prima) ÷ plazo, y el residuo de redondeo va a la
    | última cuota. Por eso acá no hay tasa de interés ni porcentaje de mora
    | que configurar — no existen en este negocio.
    |
    | El plazo y el día de pago SÍ se capturan por venta; estos son solo los
    | valores con los que el formulario llega precargado.
    |
    */
    'ventas' => [
        'dia_pago_default'    => 5,
        'plazo_meses_default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Correlativos
    |--------------------------------------------------------------------------
    |
    | El número de contrato del formato RPS-2026-0065 se compone del código
    | del proyecto, el año y un secuencial. El código sale de la columna
    | `proyectos.codigo`; acá viven el separador y el ancho del secuencial.
    |
    | El número de EXPEDIENTE es ese mismo secuencial pelado (R7): el
    | expediente 65 es el contrato RPS-2026-0065. Son una sola serie.
    |
    | ⚠️ El secuencial NO reinicia cada año (decidido el 3-ago-2026). El año
    | del número es el año de la firma, no parte de la llave: así el número
    | de expediente identifica a un cliente para siempre. Si algún día
    | reiniciara, la tabla `correlativos` necesitaría una columna `anio`.
    |
    | §8.3.6: los correlativos se consumen con SELECT ... FOR UPDATE dentro
    | de la transacción, nunca con MAX(numero)+1.
    |
    */
    'correlativos' => [
        'separador'         => '-',
        'digitos_contrato'  => 4,
        'reinicia_por_anio' => false,
    ],
];
