<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración fiscal y normativa de Honduras
|--------------------------------------------------------------------------
|
| Origen único de verdad para reglas del dominio hondureño que se
| usan en toda la aplicación. NUNCA hardcodear estos valores en
| código — siempre vía config('honduras.*').
|
| Referencias normativas:
|  - ISV: Decreto 51-2003 y reformas
|  - RTN: 14 dígitos numéricos según SAR
|  - CAI: Código de Autorización de Impresión, vigencia 12 meses
|  - DAI: Derechos Arancelarios a la Importación
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Impuestos
    |--------------------------------------------------------------------------
    */
    'impuestos' => [

        // ISV — Impuesto Sobre Ventas
        'isv' => [
            'tasa_general'        => (float) env('ISV_TASA_GENERAL', 0.15),
            'tasa_alcohol_tabaco' => (float) env('ISV_TASA_ALCOHOL_TABACO', 0.18),
            'exento'              => 0.0,
        ],

        // ISR — Impuesto Sobre la Renta (retenciones más comunes)
        'isr' => [
            'tasa_servicios_profesionales' => (float) env('ISR_TASA_SERVICIOS_PROFESIONALES', 0.125),
            'tasa_dividendos'              => (float) env('ISR_TASA_DIVIDENDOS', 0.10),
            'tasa_alquileres'              => (float) env('ISR_TASA_ALQUILERES', 0.10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SAR — Servicio de Administración de Rentas
    |--------------------------------------------------------------------------
    */
    'sar' => [
        'cai_dias_validez'  => (int) env('SAR_CAI_DIAS_VALIDEZ', 365),
        'rtn_longitud'      => 14,
        'rtn_regex'         => '/^\d{14}$/',
        'factura_serie_max' => 11,
    ],

    /*
    |--------------------------------------------------------------------------
    | Moneda
    |--------------------------------------------------------------------------
    */
    'moneda' => [
        'principal'         => env('MONEDA_PRINCIPAL', 'HNL'),
        'simbolo'           => env('MONEDA_SIMBOLO', 'L.'),
        'decimales'         => (int) env('MONEDA_DECIMALES', 2),
        'separador_miles'   => ',',
        'separador_decimal' => '.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Localización
    |--------------------------------------------------------------------------
    */
    'localizacion' => [
        'timezone'      => env('APP_TIMEZONE', 'America/Tegucigalpa'),
        'pais_iso'      => 'HN',
        'codigo_pais'   => '504',
        'departamentos' => [
            'AT' => 'Atlántida',
            'CH' => 'Choluteca',
            'CL' => 'Colón',
            'CM' => 'Comayagua',
            'CP' => 'Copán',
            'CR' => 'Cortés',
            'EP' => 'El Paraíso',
            'FM' => 'Francisco Morazán',
            'GD' => 'Gracias a Dios',
            'IN' => 'Intibucá',
            'IB' => 'Islas de la Bahía',
            'LP' => 'La Paz',
            'LE' => 'Lempira',
            'OC' => 'Ocotepeque',
            'OL' => 'Olancho',
            'SB' => 'Santa Bárbara',
            'VA' => 'Valle',
            'YO' => 'Yoro',
        ],
    ],
];
