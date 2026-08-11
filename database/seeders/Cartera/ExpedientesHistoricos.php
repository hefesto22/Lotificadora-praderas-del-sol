<?php

declare(strict_types=1);

namespace Database\Seeders\Cartera;

/**
 * La cartera que Praderas del Sol vendió ANTES de tener sistema.
 *
 * ═══ QUE ES ESTO ═══
 *
 * Un cuaderno de contabilidad, transcrito. Cada entrada es una página del
 * cuaderno de doña Rosa Elena: el expediente, el cliente, los lotes, y el
 * historial de pagos renglón por renglón. Lo lee `CarteraHistoricaSeeder`, que
 * lo carga por los Services de siempre — no hay inserts a mano en ningún lado.
 *
 * ⚠️ **Este archivo es DATOS, no código.** Crece de a un expediente por vez, y
 * cada uno entra solo cuando sus números cuadran contra el cuaderno. No se
 * agrega nada «para probar»: el día que esto corra en producción, lo que esté
 * acá es lo que la lotificadora va a cobrar.
 *
 * ═══ TRES COSAS QUE HAY QUE ENTENDER ANTES DE AGREGAR UNA ═══
 *
 * **1. El VALOR del lote se declara, no se calcula.** Praderas cobra un precio
 * por lote redondeado, no por vara²: el lote A-1 mide 252 vr² —dos más que los
 * normales— y se cobró exactamente lo mismo, L 250,000.00. El seeder deriva el
 * precio por vara² dividiendo, con seis decimales, para que el valor cierre
 * exacto.
 *
 * **2. El NUMERO de recibo es el del talonario**, no el que le tocaría al
 * sistema. Decisión de Mauricio (11-ago-2026): el cliente llega con su papel en
 * la mano y tiene que encontrarlo. El seeder acomoda el correlativo antes de
 * cada cobro para que salga ese número.
 *
 * ⚠️ Por eso **un recibo del cuaderno es UN pago acá, aunque cubra varias
 * cuotas**. En el exp. 0022 el recibo 0090 aparece cuatro veces —julio, agosto,
 * septiembre y octubre—: fue un solo papel de L 20,000.00, y así se carga. El
 * sistema lo reparte solo entre las cuotas. `recibos.numero` es único: cargarlo
 * cuatro veces rebota contra la base.
 *
 * **3. La fecha es la del PAGO**, no la del mes que cubre. El sistema deduce
 * solo qué cuota se está pagando; lo que necesita saber es qué día entró el
 * dinero, porque de eso dependen el corte de caja y todo reporte por período.
 *
 * ═══ LA FORMA DE UNA ENTRADA ═══
 *
 *   'expediente'    → int. Es también el número de contrato (R7): el 21 se ve
 *                     `RPS-2026-0021`. El seeder acomoda el correlativo.
 *   'fecha'         → Y-m-d. La de registro del contrato.
 *   'cliente'       → nombre, dni y telefono. El DNI y el teléfono pueden ir
 *                     en null: se completan después, y así quedó acordado.
 *   'lotes'         → bloque, numero y el VALOR de ese lote.
 *   'prima'         → lo que se pagó al firmar, del contrato entero.
 *   'plazo'         → meses.
 *   'dia_pago'      → el día del mes. Por defecto, el de la fecha de registro.
 *   'observaciones' → lo que el cuaderno dice al margen. Va al expediente.
 *   'pagos'         → los renglones del historial. La prima NO va acá: la emite
 *                     el propio `activar()` al firmar la venta.
 *
 * Y cada pago:
 *
 *   'recibo'      → int, el número del talonario.
 *   'fecha'       → Y-m-d, cuándo entró el dinero.
 *   'tipo'        → 'cuota' o 'abono'. El abono reprograma el plan (R21).
 *   'monto'       → string. Nunca float (§8.3.1).
 *   'forma'       → efectivo · transferencia · deposito · tarjeta.
 *   'referencia'  → la del banco. Obligatoria en todo lo que no es efectivo.
 *   'lote'        → «bloque-numero», a qué lote se aplica. Null = a todos, en
 *                   el reparto que el sistema decida.
 *   'observaciones' → quién lo recibió, y lo que el cuaderno anote.
 */
final class ExpedientesHistoricos
{
    /**
     * El código del proyecto al que pertenece esta cartera.
     */
    public const string PROYECTO = 'RPS';

    /**
     * La modalidad con la que se cargan los abonos a capital.
     *
     * «Misma cuota, menos meses» — es lo que la contratante contestó en R3 y lo
     * que se venía haciendo en el cuaderno. Lo confirma el exp. 0023: después
     * del abono de L 15,000.00 la cuota siguió siendo de L 5,000.00.
     */
    public const string MODALIDAD_DEL_ABONO = 'acortar_plazo';

    /**
     * @return list<array<string, mixed>>
     */
    public static function todos(): array
    {
        return [

            // ── Exp. 0021 — página 49 del cuaderno ───────────────────
            [
                'expediente' => 21,
                'fecha'      => '2026-06-14',
                'cliente'    => [
                    'nombre'   => 'ERIN BELTRÁN GUERRA ACEVEDO',
                    'dni'      => '0412199500018',
                    'telefono' => '9880-6628',
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '1', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 14,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 25,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 49.',
                'pagos'         => [
                    [
                        'recibo'        => 29,
                        'fecha'         => '2026-07-12',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'D-1',
                        'observaciones' => 'Recibo 00000029 del talonario. Cuaderno: cuota julio. Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0022 — página 51 del cuaderno ───────────────────
            //
            // 🔴 DOS COSAS QUE NO SE CARGAN COMO ESTÁN ESCRITAS, Y POR QUÉ.
            //
            // 1. UN RECIBO, UN PAGO. El 0090 aparece en cuatro renglones y el
            //    00000357 en dos. `recibos.numero` es único: fue UN papel por
            //    L 20,000.00 y otro por L 10,000.00. Se cargan así y el sistema
            //    reparte solo entre las cuotas, que es lo que pasó de verdad.
            //
            // 2. LA FECHA ES LA DEL PRIMER RENGLÓN de cada recibo. El cuaderno
            //    fecha los renglones con el MES QUE CUBREN —hay dos en
            //    septiembre y octubre, que todavía no llegaron— y eso se ve en
            //    que «cuota nov.» y «cuota dic.» están fechadas el 31/07, antes
            //    que las de septiembre. Un pago con fecha futura rompe el corte
            //    de caja y todo reporte por período.
            //
            // ⚠️ Queda por confirmar contra el papel: la observación del
            // cuaderno dice «15,000 : 0090» pero los cuatro renglones suman
            // 20,000. El saldo final de 210,000 solo cierra con 20,000.
            [
                'expediente' => 22,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'ANYI ALEJANDRA GARCÍA',
                    'dni'      => '1801200600283',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'        => '10000.00',
                'plazo'        => 48,
                'dia_pago'     => 3,
                'forma_prima'  => 'deposito',
                'ref_prima'    => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima' => 26,
                // La observación va COMO ELLA LA ESCRIBIÓ. No se corrige ni se
                // interpreta: si algún día ese número se discute, lo que vale es
                // lo que dice el cuaderno, no lo que nosotros entendimos.
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 51. '
                    .'Observación: 15,000 : 0090 = 10,000 Adonay, 5,000 Dionel',
                'pagos' => [
                    [
                        'recibo'        => 90,
                        'fecha'         => '2026-07-03',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY ESPINOZA',
                        'lote'          => 'K-2',
                        'observaciones' => 'Recibo 0090 del talonario. Cuaderno: cuota julio, cuota agosto, '
                            .'cuota sept., cuota oct., L 5,000.00 cada una. Recibió Adonay Espinoza.',
                    ],
                    [
                        'recibo'        => 357,
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY ESPINOZA',
                        'lote'          => 'K-2',
                        'observaciones' => 'Recibo 00000357 del talonario. Cuaderno: cuota nov., cuota dic., '
                            .'L 5,000.00 cada una. Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0023 — página 53 del cuaderno ───────────────────
            //
            // El primero con ABONO A CAPITAL. El recibo 00000373 por L 15,000.00
            // baja el saldo y reescribe el plan pendiente (R21). Va con la
            // modalidad de `MODALIDAD_DEL_ABONO` —misma cuota, menos meses— y
            // el resultado cierra redondo: 215,000 ÷ 5,000 = 43 cuotas justas.
            //
            // El recibo 0091 aparece dos veces en el cuaderno —julio y agosto—:
            // fue un papel de L 10,000.00, igual que el 0090 del 0022.
            //
            // ⚠️ El número 00000373 aparece TAMBIÉN en el exp. 0027, con otro
            // monto y otra fecha. Uno de los dos está mal anotado y falta
            // revisar el talonario. Acá se carga como lo dice esta página.
            [
                'expediente' => 23,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'GREIDY FABIOLA ARANDA REYES',
                    'dni'      => null,
                    'telefono' => '8810-7508',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 27,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 53.',
                'pagos'         => [
                    [
                        'recibo'        => 91,
                        'fecha'         => '2026-07-03',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY ESPINOZA',
                        'lote'          => 'K-11',
                        'observaciones' => 'Recibo 0091 del talonario. Cuaderno: cuota julio, cuota agost., '
                            .'L 5,000.00 cada una. Recibió Adonay Espinoza.',
                    ],
                    [
                        'recibo'        => 373,
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'abono',
                        'monto'         => '15000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'K-11',
                        'observaciones' => 'Recibo 00000373 del talonario. Cuaderno: abono cap. Recibió Adonay.',
                    ],
                ],
            ],

            // ── Exp. 0024 — página 55 del cuaderno ───────────────────
            //
            // 🔴 EL UNICO CON DESCUENTO DE VERDAD, Y EL UNICO YA PAGADO.
            //
            // El lote costaba L 250,000.00 y se vendió en L 210,000.00: cuarenta
            // mil de descuento autorizado por pago al contado. Por eso lleva
            // `valor_lista` y `motivo` — el sistema mide el descuento contra el
            // precio del lote y exige el motivo escrito (R4), que es justo lo
            // que la contratante anotó en el cuaderno.
            //
            // ⚠️ LOS SALDOS INTERMEDIOS NO VAN A COINCIDIR CON EL PAPEL. El
            // cuaderno los calculó sobre 250,000 (240,000 y 235,000); el sistema
            // parte de 210,000 y muestra 200,000 y 195,000. Lo que sí coincide,
            // y es lo que importa, es el final: **saldo 0.00 y cuenta liquidada
            // el 07/07/2026**.
            //
            // El plan de 48 cuotas de L 5,000.00 que dice el papel nunca llegó a
            // usarse: la cuenta se canceló al mes siguiente de firmar.
            [
                'expediente' => 24,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'YANNIRIS PAOLA GARCÍA LÓPEZ',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    [
                        'bloque'      => 'K',
                        'numero'      => '12',
                        'valor'       => '210000.00',
                        'valor_lista' => '250000.00',
                        'motivo'      => 'Descuento autorizado de L 40,000.00 por pago al contado.',
                    ],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 28,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 55. '
                    .'Observación: El valor 250,000 cambia a 210,000.00 al contado, por pago al contado. '
                    .'OBSERVACIÓN: Por descuento autorizado de L. 40,000.00 por pago al contado, el valor '
                    .'original de la venta de L. 250,000.00 se ajusta a un valor final de 210,000L. '
                    .'Los pagos realizados suman L. 210,000. Cuenta cancelada en su totalidad el '
                    .'07/07/2026. Saldo 0.00',
                'pagos' => [
                    [
                        'recibo'        => 36,
                        'fecha'         => '2026-07-03',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'K-12',
                        'observaciones' => 'Recibo 0036 del talonario. Cuaderno: cuota julio. Recibió Dionel Pinto.',
                    ],
                    [
                        'recibo'        => 5,
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '195000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY',
                        'lote'          => 'K-12',
                        'observaciones' => 'Recibo 00000005 del talonario. Cuaderno: abono pago final. '
                            .'Recibió Adonay. Cancela la cuenta.',
                    ],
                ],
            ],

            // ── Exp. 0025 — página 57 del cuaderno ───────────────────
            //
            // 🔴 EL PRIMERO DE DOS LOTES, Y EL PRIMERO POR REMESA.
            //
            // El pago de julio NO declara lote: se reparte entre R-1 y R-2 en
            // proporción a su valor, que acá es mitad y mitad. El cuaderno
            // tampoco lo desglosa —anota «cuota de julio L 10,000.00» y punto—,
            // así que pedirle al dato una precisión que el papel no tiene sería
            // inventarla.
            //
            // La REMESA no existía como forma de pago. Se agregó al enum el
            // 11-ago con su migración: no es un depósito, porque el dinero no
            // entra por el banco de la lotificadora sino por una casa de cambio,
            // y anotarla mal haría que el cuadre bancario nunca cierre.
            [
                'expediente' => 25,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'JOSÉ WILMAN RIVERA HENRÍQUEZ',
                    'dni'      => '0406198000055',
                    'telefono' => '9456-4029',
                ],
                'lotes' => [
                    ['bloque' => 'R', 'numero' => '1', 'valor' => '250000.00'],
                    ['bloque' => 'R', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 15,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 56,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 57.',
                'pagos'         => [
                    [
                        'recibo'        => 49,
                        'fecha'         => '2026-07-15',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'remesa',
                        'referencia'    => 'REMESA — SIN NÚMERO DE CONTROL EN EL CUADERNO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000049 del talonario. Cuaderno: cuota de julio. '
                            .'Forma de pago: remesa. Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0026 — página 59 del cuaderno ───────────────────
            //
            // Sin novedades: dos lotes del bloque D, todo en efectivo, y la
            // cuota de julio se reparte sola entre los dos.
            [
                'expediente' => 26,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'ROXANA MILEXSY GARCÍA',
                    'dni'      => '0412199300151',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '3', 'valor' => '250000.00'],
                    ['bloque' => 'D', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 15,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 57,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 59.',
                'pagos'         => [
                    [
                        'recibo'        => 372,
                        'fecha'         => '2026-07-16',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000372 del talonario. Cuaderno: cuota de julio. '
                            .'Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0027 — página 61 del cuaderno ───────────────────
            //
            // El recibo es el **00000313**, no el 373: lo confirmó Mauricio
            // contra el talonario el 11-ago. En la foto el 1 y el 7 se
            // confunden, y el 373 es el del exp. 0023.
            [
                'expediente' => 27,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'MARÍA DOLORES MIRANDA',
                    'dni'      => '0412196600021',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '14', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 15,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 58,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 61.',
                'pagos'         => [
                    [
                        'recibo'        => 313,
                        'fecha'         => '2026-07-16',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'D-14',
                        'observaciones' => 'Recibo 00000313 del talonario. Cuaderno: cuota mensual. '
                            .'Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0028 — página 63 del cuaderno ───────────────────
            //
            // 🔴 EL MAS ENREDADO DE LA TANDA. Tres lotes que tenían cuenta
            // separada y se unificaron el 07/07/2026 en una sola de L 975,000.00.
            //
            // ═══ DOS DECISIONES DE MAURICIO, 11-ago-2026 ═══
            //
            // **1. La cuota manda sobre el valor.** El cuaderno dice cuota de
            // L 19,604.00 y valor de L 975,000.00, y las dos no pueden ser
            // ciertas a la vez: 941,000 ÷ 48 da 19,604.17. Se eligió respetar la
            // CUOTA, así que el valor entra como **L 974,992.00** —ocho lempiras
            // menos— y las 48 cuotas salen de L 19,604.00 clavadas.
            //
            // ⚠️ Consecuencia: los cuatro saldos del cuaderno quedan 8 lempiras
            // arriba de los del sistema (941,000 vs 940,992, y así). Es el precio
            // de que la cuota sea la del papel.
            //
            // **2. Los dos primeros renglones no traen número de recibo.** El
            // cuaderno dice «se conserva la validez de los recibos y pagos
            // anteriores»: esos papeles existen, pero con la numeración de las
            // tres cuentas viejas, que no están en esta página. Se usan dos
            // números libres de la serie —el 1 y el 2— y queda escrito en las
            // observaciones que NO son los del talonario. Se corrigen el día que
            // aparezcan las páginas de las cuentas previas.
            //
            // El valor por lote son 974,992 ÷ 3, con el centavo del residuo en el
            // último para que la suma cierre exacta.
            [
                'expediente' => 28,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'HUMBERT JOSSUÉ ZOLA PORTILLO',
                    'dni'      => '0406200200046',
                    'telefono' => '8745-9973',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '9',  'valor' => '324997.33'],
                    ['bloque' => 'H', 'numero' => '15', 'valor' => '324997.33'],
                    ['bloque' => 'H', 'numero' => '16', 'valor' => '324997.34'],
                ],
                'prima'         => '34000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 1,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 63. '
                    .'Nota al margen: fecha 20 de cada mes. Área 1012.50 vr². '
                    .'Observaciones: Se unificaron los lotes. Fecha 07/07/2026. '
                    .'Se conserva la validez de los recibos y pagos anteriores. '
                    .'Detalle de pagos anteriores unificados: Primas L. 34,000.00. '
                    .'Cuotas de julio: 13,000.00 L. Total aplicado a cuenta unificada L. 47,000.00. '
                    .'Saldo unificado L. 928,000.00. '
                    .'⚠️ El cuaderno anota valor L 975,000.00 y cuota L 19,604.00; se respetó la CUOTA, '
                    .'así que el valor cargado es L 974,992.00 y los saldos quedan 8 lempiras por debajo '
                    .'de los del papel. '
                    .'⚠️ La prima y las cuotas de julio NO traen número de recibo en el cuaderno: '
                    .'corresponden a los talonarios de las tres cuentas previas a la unificación. '
                    .'Se usaron los números libres 1 y 2 para poder emitirlos.',
                'pagos' => [
                    [
                        'recibo'        => 2,
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '13000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: cuotas julio unificados. Recibió Dionel P. '
                            .'⚠️ SIN número de recibo en el cuaderno — el 2 es un número libre de la serie, '
                            .'no el del talonario. Corresponde a los recibos de las cuentas previas.',
                    ],
                    [
                        'recibo'        => 12,
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '11500.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000012 del talonario. Cuaderno: abono. Recibió Dionel P. '
                            .'Va como cuota y no como abono a capital: el cuaderno no dice «a capital» '
                            .'y el saldo baja igual.',
                    ],
                    [
                        'recibo'        => 370,
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'abono',
                        'monto'         => '30500.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000370 del talonario. Cuaderno: abono a capital. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0029 — página 65 del cuaderno ───────────────────
            //
            // El recibo 00000346 dice «cuota agost / cuota sept.»: un solo
            // papel de L 10,000.00 que cubre dos cuotas. El sistema lo reparte
            // solo, igual que el 0090 del exp. 0022.
            [
                'expediente' => 29,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'CONCEPCIÓN ESTEVEZ',
                    'dni'      => '0412196400157',
                    'telefono' => '9856-1664',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '12', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 8,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 59,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 65.',
                'pagos'         => [
                    [
                        'recibo'        => 105,
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO B.P.',
                        'lote'          => 'N-12',
                        'observaciones' => 'Recibo 0105 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel Pinto B.P.',
                    ],
                    [
                        'recibo'        => 346,
                        'fecha'         => '2026-07-27',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => 'N-12',
                        'observaciones' => 'Recibo 00000346 del talonario. Cuaderno: cuota agost., cuota sept., '
                            .'L 5,000.00 cada una. Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0030 — página 67 del cuaderno ───────────────────
            //
            // 🔴 EL LOTE QUE DESTAPO TODO. El A-1 mide **252 vr²** —dos más que
            // los normales— y se cobró exactamente lo mismo: L 250,000.00. Eso
            // fue lo que dejó sin discusión que la lotificadora cobra por LOTE
            // y no por vara²; a precio de vara habría costado L 252,000.00.
            //
            // El precio por vara² que sale es 992.063492…, y solo cierra con
            // los seis decimales de `Lote::DECIMALES_DEL_PRECIO`. Con dos, el
            // valor daría L 249,999.12 y el CHECK rebotaría.
            [
                'expediente' => 30,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'YOLANY LISSETH MALDONADO',
                    'dni'      => null,
                    'telefono' => '8759-0875',
                ],
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '1', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 8,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY PEÑA',
                'recibo_prima'  => 60,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 67.',
                'pagos'         => [
                    [
                        'recibo'        => 303,
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => 'A-1',
                        'observaciones' => 'Recibo 00000303 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0031 — página 69 del cuaderno ───────────────────
            //
            // Lotes **1 y 2 del bloque T** (confirmado por Mauricio el 11-ago;
            // el cuaderno se lee «Lote 1-2» y el valor de L 500,000.00 lo
            // corrobora).
            //
            // 🔴 EL RENGLON 0079 «INICIAL» NO SE CARGA COMO PAGO, y la razón
            // está en la aritmética del propio cuaderno: no trae monto, ni
            // fecha, ni forma de pago, **y los saldos cierran sin él**
            // —480,000 − 32,500 = 447,500 exacto—. Si hubiera sido dinero, el
            // saldo lo mostraría.
            //
            // Lo más probable es que sea el segundo número de la misma prima
            // —«inicial» es como se le dice— o un recibo anulado. Queda escrito
            // en las observaciones, que es donde va lo que no se entiende:
            // inventarle un monto sería peor que dejarlo anotado.
            [
                'expediente' => 31,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'MELVIN NAHUN RODRÍGUEZ VARGAS',
                    'dni'      => '0418200000130',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '1', 'valor' => '250000.00'],
                    ['bloque' => 'T', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 42,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 69. '
                    .'⚠️ El cuaderno anota un renglón «0079 Inicial» sin monto, sin fecha y sin forma '
                    .'de pago. Los saldos cierran sin él, así que NO se cargó como pago. Puede ser el '
                    .'segundo número de la misma prima o un recibo anulado; falta revisar el talonario.',
                'pagos' => [
                    [
                        'recibo'        => 377,
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'abono',
                        'monto'         => '32500.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000377 del talonario. Cuaderno: abono a cap. '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0032 — página 71 del cuaderno ───────────────────
            //
            // El último de la primera tanda. El recibo 00000321 dice «cuota
            // julio» pero es de L 20,000.00 — el doble de la cuota mensual—:
            // cubre dos meses. El sistema lo reparte solo.
            //
            // El cuaderno dejó el ESTADO en blanco; por los pagos está activo,
            // y así queda: una venta con saldo es una venta vigente.
            [
                'expediente' => 32,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'SANDRA LILIANA MIRANDA',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '8', 'valor' => '250000.00'],
                    ['bloque' => 'O', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 15,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 61,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 71. '
                    .'El cuaderno dejó el campo Estado en blanco.',
                'pagos' => [
                    [
                        'recibo'        => 321,
                        'fecha'         => '2026-07-18',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000321 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel Pinto. Son L 20,000.00, el doble de la cuota mensual: '
                            .'cubre dos meses.',
                    ],
                ],
            ],

            // ── Exp. 0033 — página 73 del cuaderno ───────────────────
            //
            // 🔴 EL PRIMERO CON UN LOTE IRREGULAR ADENTRO. El O-5 mide
            // 250.0000 vr² y va al precio confirmado de L 250,000.00; el O-6
            // mide 437.3700 vr² y es uno de los 49 sin precio conocido. Los
            // dos juntos se vendieron en L 500,000.00, así que al O-6 le queda
            // L 250,000.00 — el mismo precio que un lote de 250 vr², midiendo
            // casi el doble. ⚠️ Es el único reparto defendible (el precio del
            // O-5 está confirmado por decenas de expedientes) pero está
            // PENDIENTE de que la contratante lo confirme.
            //
            // Y es también la primera REMESA de toda la cartera: la cuota de
            // julio entró por remesa, la forma de pago que no existía en el
            // sistema hasta esta carga.
            //
            // El recibo 00000320 dice «cuota julio» pero es de L 20,000.00
            // —el doble de la cuota mensual—: cubre dos meses. Mismo caso que
            // el exp. 0032, y el sistema lo reparte solo.
            [
                'expediente' => 33,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'DEVER ADONAY LÓPEZ',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '5', 'valor' => '250000.00'],
                    ['bloque' => 'O', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 15,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 62,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 73. '
                    .'El lote O-6 es irregular (437.3700 vr²) y su precio se '
                    .'dedujo restando: PENDIENTE de confirmar con la contratante.',
                'pagos' => [
                    [
                        'recibo'        => 320,
                        'fecha'         => '2026-07-18',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'remesa',
                        'referencia'    => 'REMESA — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000320 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel Pinto. Son L 20,000.00, el doble de la cuota '
                            .'mensual: cubre dos meses.',
                    ],
                ],
            ],

            // ── Exp. 0034 — página 75 del cuaderno ───────────────────
            //
            // El más simple de la tanda: un lote, la prima, y nada más. El
            // cuaderno escribió la fecha con letra —«16 de junio de 2026»—.
            [
                'expediente' => 34,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'ELSI ROXANA ARANDA LÓPEZ',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 63,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 75.',
                'pagos'         => [],
            ],

            // ── Exp. 0035 — página 77 del cuaderno ───────────────────
            //
            // Tres lotes seguidos del bloque D, los tres de 250 vr² al precio
            // confirmado. La cuota de julio se pagó el 17 de JUNIO, un día
            // después de firmar: un mes adelantada.
            [
                'expediente' => 35,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'ERICK HUMBERTO REYES ORELLANA',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '11', 'valor' => '250000.00'],
                    ['bloque' => 'D', 'numero' => '12', 'valor' => '250000.00'],
                    ['bloque' => 'D', 'numero' => '13', 'valor' => '250000.00'],
                ],
                'prima'         => '30000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 64,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 77.',
                'pagos'         => [
                    [
                        'recibo'        => 315,
                        'fecha'         => '2026-06-17',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000315 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel Pinto. Pagada el día siguiente de firmar.',
                    ],
                ],
            ],

            // ── Exp. 0036 — página 79 del cuaderno ───────────────────
            //
            // ⚠️ Debajo del último renglón hay marcas tenues de un renglón
            // BORRADO. No se carga: no se alcanza a leer ni monto ni recibo, y
            // los saldos del cuaderno cierran sin él.
            [
                'expediente' => 36,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'LILIAN YESENIA MANCÍA ESTÉVEZ',
                    'dni'      => '0412197900512',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '15', 'valor' => '250000.00'],
                    ['bloque' => 'G', 'numero' => '16', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY PEÑA',
                'recibo_prima'  => 65,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 79. '
                    .'Debajo del último renglón hay marcas de un renglón borrado, '
                    .'ilegible; los saldos cierran sin él.',
                'pagos' => [
                    [
                        'recibo'        => 359,
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000359 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0037 — página 81 del cuaderno ───────────────────
            //
            // ⚠️ EL NUMERO DEL RECIBO DEL 13-JUL NO SE LEE. En el escaneo se
            // ven cuatro o cinco dígitos borrosos. Se cargó como 00000316
            // porque es lo que la serie permite —el 00000315 es del 17-jun y el
            // 00000320 del 18-jul—, pero está PENDIENTE de mirar el papel.
            [
                'expediente' => 37,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'DANIA ARELY TRIGUEROS MANCÍA',
                    'dni'      => '0412199800185',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '1', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY PEÑA',
                'recibo_prima'  => 66,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 81. '
                    .'El número del recibo del 13-jul no se lee en el escaneo: '
                    .'se cargó como 00000316, PENDIENTE de confirmar contra el papel.',
                'pagos' => [
                    [
                        'recibo'        => 316,
                        'fecha'         => '2026-07-13',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY ESPINOZA',
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: cuota julio. Recibió Adonay Espinoza. '
                            .'El número del talonario no se lee en el escaneo.',
                    ],
                    [
                        'recibo'        => 358,
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000358 del talonario. Cuaderno: cuota agosto. '
                            .'Recibió Adonay E. Pagada adelantada.',
                    ],
                ],
            ],

            // ── Exp. 0038 — página 83 del cuaderno ───────────────────
            //
            // ⚠️ El recibo de prima salta del 0066 al 0068: el 0067 no
            // aparece en ninguna página del cuaderno.
            [
                'expediente' => 38,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'KARELIA NICOL TRIGUEROS TORRES',
                    'dni'      => '0412200600084',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 68,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 83.',
                'pagos'         => [],
            ],

            // ── Exp. 0039 — página 85 del cuaderno ───────────────────
            //
            // ⚠️ El recibo 00000314 es del 16-jul y el 00000315 del 17-JUN
            // (exp. 0035): el talonario largo NO se usó en orden. Se cargan
            // como están, que es la regla.
            [
                'expediente' => 39,
                'fecha'      => '2026-06-17',
                'cliente'    => [
                    'nombre'   => 'SULMY KARIXA MANCÍA TORRES',
                    'dni'      => '0412200100409',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 17,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 69,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 85.',
                'pagos'         => [
                    [
                        'recibo'        => 314,
                        'fecha'         => '2026-07-16',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000314 del talonario. Cuaderno: cuota mensual. '
                            .'Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0040 — página 87 del cuaderno ───────────────────
            //
            // 🔴 EL PRIMERO VENDIDO POR ALGUIEN QUE NO ES LA LOTIFICADORA:
            // el cuaderno anota «Vendido por: Jony García». Los 19 anteriores
            // no traen vendedor. Va a observaciones porque el sistema todavía
            // no tiene dónde guardar quién vendió — y eso es una comisión que
            // alguien va a querer cobrar.
            //
            // ⚠️ El recibo de prima salta del 0069 al 0071: falta el 0070.
            [
                'expediente' => 40,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'OBDULIA MARÍA GÓMEZ MORENO',
                    'dni'      => '0405199200296',
                    'telefono' => '9550-4277',
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 71,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 87. '
                    .'Vendido por: Jony García.',
                'pagos' => [
                    [
                        'recibo'        => 330,
                        'fecha'         => '2026-07-22',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000330 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0041 — página 89 del cuaderno ───────────────────
            [
                'expediente' => 41,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'ELKIN JAVIER TORRES ESTÉVEZ',
                    'dni'      => '0412200400186',
                    'telefono' => '9543-4227',
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 72,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 89.',
                'pagos'         => [
                    [
                        'recibo'        => 360,
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000360 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0042 — página 91 del cuaderno ───────────────────
            //
            // ⚠️ El recibo 00000023 es de ocho dígitos como los del talonario
            // largo, pero de un número bajísimo para un pago del 10-jul. El
            // exp. 0043 tiene el mismo caso (00000019). Se cargan tal cual.
            [
                'expediente' => 42,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'MAVIS YADANI GARCÍA SOLÍS',
                    'dni'      => '0412198900344',
                    'telefono' => '9441-8185',
                ],
                'lotes' => [
                    ['bloque' => 'R', 'numero' => '16', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 73,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 91.',
                'pagos'         => [
                    [
                        'recibo'        => 23,
                        'fecha'         => '2026-07-10',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY ESPINOZA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000023 del talonario. Cuaderno: cuota mes julio. '
                            .'Recibió Adonay Espinoza.',
                    ],
                ],
            ],

            // ── Exp. 0043 — página 93 del cuaderno ───────────────────
            //
            // 🔴 LA FECHA DEL CUADERNO ES IMPOSIBLE. El renglón de la cuota
            // dice «09/06/2026», DIEZ DÍAS ANTES de que se firmara el contrato
            // (19/06/2026). Un pago no puede existir antes de la venta que
            // paga, así que se cargó como 09/07/2026 —el 6 y el 7 se parecen
            // en esa letra, y el concepto dice «cuota julio»—.
            // ⚠️ PENDIENTE de confirmar contra el recibo 00000019.
            [
                'expediente' => 43,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'HÉCTOR EMILIO CRUZ MOLINA',
                    'dni'      => '1604200000565',
                    'telefono' => '8899-3983',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY PEÑA',
                'recibo_prima'  => 74,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 93. '
                    .'El cuaderno fecha la cuota el 09/06/2026, diez días antes del '
                    .'contrato: se cargó como 09/07/2026, PENDIENTE de confirmar.',
                'pagos' => [
                    [
                        'recibo'        => 19,
                        'fecha'         => '2026-07-09',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000019 del talonario. Cuaderno: cuota julio, '
                            .'fechada 09/06/2026 (imposible: anterior al contrato). '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0044 — página 95 del cuaderno ───────────────────
            //
            // El último del PDF. Con este se cierra la cartera anterior al
            // sistema: 24 expedientes, 0021 a 0044.
            [
                'expediente' => 44,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'MAYCOL EFRAÍN PERDOMO BRIZUELA',
                    'dni'      => '0412199600064',
                    'telefono' => '8994-5267',
                ],
                'lotes' => [
                    ['bloque' => 'M', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 75,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 95.',
                'pagos'         => [
                    [
                        'recibo'        => 336,
                        'fecha'         => '2026-07-23',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000336 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel Pinto.',
                    ],
                ],
            ],

        ];
    }
}
