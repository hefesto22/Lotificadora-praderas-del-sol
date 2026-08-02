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
    | ⚠️ PENDIENTE DE CONFIRMAR con la contratante: el valor de la vara
    | varía según la fuente (0.8359 m es la vara castellana). Ningún
    | cálculo de dinero depende de este número — las áreas se guardan en
    | varas² y los precios son por vara² — así que solo afecta la
    | conversión informativa a m². Confirmar antes de imprimirlo en un
    | contrato o en una escritura.
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
    | Correlativos
    |--------------------------------------------------------------------------
    |
    | El número de contrato del formato RPS-2026-0065 se compone del código
    | del proyecto, el año y un secuencial. El código sale de la columna
    | `proyectos.codigo`; acá viven el separador y el ancho del secuencial.
    |
    | §8.3.6: los correlativos se consumen con SELECT ... FOR UPDATE dentro
    | de la transacción, nunca con MAX(numero)+1.
    |
    */
    'correlativos' => [
        'separador'        => '-',
        'digitos_contrato' => 4,
    ],
];
