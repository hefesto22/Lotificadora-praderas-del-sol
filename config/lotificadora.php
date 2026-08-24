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
    | La unidad del negocio la elige CADA PROYECTO desde el 13-ago-2026:
    | hay desarrollos en varas² y desarrollos en metros² (ver el enum
    | UnidadDeArea y la columna `proyectos.unidad_area`). Lo que queda acá
    | es con cuál nace un proyecto nuevo en ESTA instalación —en Honduras
    | la costumbre es la vara²— y cuánto mide la vara cuando el topógrafo
    | no dice otra cosa.
    |
    | ⚠️ La unidad es una ETIQUETA, no una conversión: el área siempre se
    | guarda en la columna `area_varas`, medida en la unidad del proyecto.
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
    /*
    |--------------------------------------------------------------------------
    | Quién emite los recibos
    |--------------------------------------------------------------------------
    |
    | El recibo lo entrega LA LOTIFICADORA al cliente, no Olympo: Olympo es el
    | prestador del software y no aparece en ningún documento del negocio. Los
    | datos salen del contrato firmado el 29-jul-2026.
    |
    | Viven acá y no en una tabla porque son uno solo y no cambian: una tabla
    | de un renglón es una pantalla de administración que alguien tiene que
    | mantener para un dato que se toca una vez cada varios años. El día que la
    | contratante cambie de dirección o de teléfono, se cambia acá y se
    | despliega — que es exactamente lo que pasaría con una migración.
    |
    | R10: NO hay CAI. Estos recibos son de uso interno y no llevan pie de
    | imprenta, rango autorizado ni fecha límite de emisión.
    |
    */

    'emisor' => [
        'nombre'      => env('EMISOR_NOMBRE', 'Rosa Elena España Portillo'),
        'rtn'         => env('EMISOR_RTN', '14121983000249'),
        'residencial' => env('EMISOR_RESIDENCIAL', 'Residencial Praderas del Sol'),
        'direccion'   => env('EMISOR_DIRECCION', 'Cucuyagua, Copán'),
        'telefono'    => env('EMISOR_TELEFONO'),
    ],

    'area' => [
        // Con cuál nace un proyecto nuevo. Lo lee el selector de la
        // pestaña Identificación; cada proyecto guarda después el suyo.
        'unidad_por_defecto' => 'varas',
        'vara_en_metros'     => '0.8359',
        'decimales'          => 4,
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
    /*
    |--------------------------------------------------------------------------
    | Suspension por mora — Clausula Septima
    |--------------------------------------------------------------------------
    | «Suspension de acceso por mora mayor a 15 dias». Va por .env y no por
    | una tabla del negocio a proposito: es una palanca de Olympo, no un dato
    | del cliente, y tiene que poder levantarse en diez segundos desde el
    | servidor el dia que el cliente pague.
    |
    | NO borra datos ni bloquea al super-admin: la Clausula Decima obliga a
    | poder exportarle todo al cliente aunque el acceso este suspendido.
    */
    'suspension' => [
        'activa' => env('PRADERAS_SUSPENDIDO', false),

        'mensaje' => env(
            'PRADERAS_SUSPENDIDO_MENSAJE',
            'El acceso al sistema está temporalmente suspendido. Sus datos están intactos y el '.
            'acceso se restablece apenas se regularice el pago. Comuníquese con Inversiones Olympo.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento incluido — Clausula Novena
    |--------------------------------------------------------------------------
    | 25 GB incluidos; el excedente se cobra a L 200/GB/año. El medidor avisa
    | al 80% porque pasarse sin enterarse significa que el excedente lo paga
    | Olympo hasta que alguien revise una factura.
    */
    'almacenamiento' => [
        'incluido_gb'    => 25,
        'alerta_en'      => 0.80,
        'precio_gb_anio' => '200.00',
    ],

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
    | El precio de la vara según el plazo — NO vive acá
    |--------------------------------------------------------------------------
    |
    | «No es el mismo precio de vara a 1 año que a 4 años» —Mauricio,
    | 5-ago-2026—. Estuvo unas horas en este archivo y fue un error: quien
    | decide esos números es la administración, y tiene que poder cambiarlos
    | desde el panel sin tocar código ni esperar un despliegue.
    |
    | Viven en la tabla `planes_de_pago`, por proyecto. Se cargan en la ficha
    | del proyecto, pestaña «Planes de pago».
    |
    | Ojo con leerlo como interés: no lo es, y por eso R1 sigue en pie. No
    | hay amortización, ni capital e interés separados, ni saldo que devengue
    | nada. Son precios de lista distintos según el plazo; elegido el plazo,
    | el precio queda fijo y la cuota sigue siendo (valor − prima) ÷ meses.
    |
    */

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
