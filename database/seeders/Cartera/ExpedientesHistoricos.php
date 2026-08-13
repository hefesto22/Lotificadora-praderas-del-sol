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
 * **2. Los `recibo` y `recibo_prima` son la TRANSCRIPCION DEL PAPEL, no la
 * serie del sistema.** El seeder no los usa para numerar: están acá porque son
 * lo que el cuaderno dice, y perderlos sería perder el único rastro de qué
 * papel documenta cada cobro.
 *
 * El sistema numera sus recibos de corrido y después se le dice desde qué
 * número seguir — es `OLYMPO_PROXIMO_RECIBO` en el `.env` del servidor, el
 * próximo número en blanco del talonario de papel. Es la decisión de Mauricio
 * del 11-ago-2026, y es la que hace que los números repetidos entre los dos
 * talonarios, los ilegibles y el «0075-1» del exp. 0045 dejen de importar.
 *
 * 🔴 **SALVO CUANDO EL NUMERO NO SE PUEDE USAR.** El cuaderno lleva DOS
 * talonarios: uno de primas, con números de cuatro dígitos —0018, 0049, 0075—,
 * y uno de cuotas, con ocho —00000049, 00000314—. El sistema lleva UNA sola
 * serie de enteros (R12), así que el 0049 corto y el 00000049 largo son el
 * mismo número y no entran los dos.
 *
 * La regla la dio Mauricio el 11-ago-2026: **el del talonario CORTO se queda
 * sin número**. No se busca cuál era el bueno ni se inventa uno parecido — el
 * recibo se emite igual (la plata entró y sin papel no hay cobro que
 * registrar), pero con el número que le toque al sistema, y la observación
 * dice de dónde salió. Vale para los tres casos que aparecieron: el número
 * repetido, el «0075-1» que no es un entero, y los renglones que el cuaderno
 * dejó sin numerar.
 *
 * ⚠️ Lo que NO resuelve es un número ILEGIBLE del talonario largo —el exp.
 * 0048 tiene dos—: ahí sigue haciendo falta mirar el papel.
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
 *   'vendedor'      → el nombre del vendedor externo, si el cuaderno anota
 *                     uno. Se crea solo la primera vez. Sin esta clave, la
 *                     venta la cerró la lotificadora.
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
     * Los lotes que la lotificadora sacó del mercado.
     *
     * ═══ DE DONDE SALEN ═══
     *
     * Del propio cuaderno. El exp. 0080 dice «Herederos — Bloque B lotes 1 al
     * 16» y nada más: sin fecha, sin valor, sin pagos. No es una venta ni un
     * apartado, así que no puede entrar como expediente — pero esos dieciséis
     * lotes tampoco pueden seguir figurando como disponibles, porque el plano
     * público los estaba ofreciendo.
     *
     * El seeder los pone en `reservado` y les escribe el motivo en las
     * observaciones. `olympo:limpiar-cartera` los devuelve a disponible, así
     * que borrar y recargar sigue funcionando igual que siempre.
     *
     * ⚠️ Esto NO registra a quién le toca cada lote. Cuando aparezcan los
     * datos de los herederos —nombres, qué lote a quién— eso se carga como
     * expediente y el lote pasa de reservado a vendido. Mientras tanto, lo
     * único que importa es que nadie los venda por error.
     *
     * @var array<string, array{lotes: list<string>, motivo: string}>
     */
    public const array RESERVADOS = [
        'herederos' => [
            'lotes' => [
                'B-1', 'B-2', 'B-3', 'B-4', 'B-5', 'B-6', 'B-7', 'B-8',
                'B-9', 'B-10', 'B-11', 'B-12', 'B-13', 'B-14', 'B-15', 'B-16',
            ],
            'motivo' => 'Reservado para los herederos. Cuaderno pág. 167, expediente 0080: '
                .'«Herederos — Bloque B lotes 1 al 16». El cuaderno no anota fecha, valor ni '
                .'pagos, así que no hay venta que registrar todavía.',
        ],
    ];

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
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
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

            // ═══════════════════════════════════════════════════════════
            // SEGUNDO CUADERNO — páginas 97 en adelante
            // ═══════════════════════════════════════════════════════════

            // ── Exp. 0045 — página 97 del cuaderno ───────────────────
            //
            // EL RECIBO DE LA PRIMA NO ES UN NUMERO: el cuaderno dice
            // «0075-1». El 0075 pelado ya lo usó el exp. 0044 el mismo día, y
            // entre el 0075 y el 0076 (exp. 0046) no queda ningún entero
            // libre, así que es un bis del talonario corto.
            //
            // Va por la regla del punto 2 del docblock: el talonario corto se
            // queda sin número. El recibo se emite con el que le toca al
            // sistema —el 3— y la observación dice qué decía el papel. No hace
            // falta preguntar nada.
            [
                'expediente' => 45,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'MARÍA BRIZUELA',
                    'dni'      => '0412194800054',
                    'telefono' => '9396-9737',
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '15', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 3,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 97. '
                    .'El cuaderno numera el recibo de la prima como «0075-1», que no es un '
                    .'entero de la serie: el recibo lleva el número que le puso el sistema, '
                    .'no el del talonario.',
                'pagos' => [
                    [
                        'recibo'        => 323,
                        'fecha'         => '2026-07-20',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000323 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0046 — página 99 del cuaderno ───────────────────
            [
                'expediente' => 46,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'CARLOS JOSÉ BRIZUELA',
                    'dni'      => '0412200700263',
                    'telefono' => '9422-7330',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '3', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 76,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 99.',
                'pagos'         => [
                    [
                        'recibo'        => 324,
                        'fecha'         => '2026-07-20',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000324 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0047 — página 101 del cuaderno ──────────────────
            [
                'expediente' => 47,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'LESTER JOSUÉ TORRES ESTÉVEZ',
                    'dni'      => '0412200700293',
                    'telefono' => '9372-6020',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 77,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 101.',
                'pagos'         => [
                    [
                        'recibo'        => 325,
                        'fecha'         => '2026-07-20',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000325 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay E. La prima la recibió Adonay Espinoza.',
                    ],
                ],
            ],

            // ── Exp. 0048 — página 103 del cuaderno ──────────────────
            //
            // 🔴 EL CUADERNO TIENE UN PAGO FECHADO EN EL FUTURO, Y NO SE
            // CARGA. El tercer renglón dice «25/08/2026 · cuota agosto ·
            // L 10,000.00 · recibo 00000339», y hoy es 11-ago-2026: son
            // catorce días adelante.
            //
            // `RegistroDePagos` lo rechaza a propósito —«un recibo no se emite
            // por adelantado: si el cliente paga hoy, la fecha es hoy»— y esa
            // regla está bien: un recibo con fecha futura mete plata en un
            // corte de caja que todavía no pasó. Inventarle una fecha para
            // esquivar el guard sería exactamente lo que el guard evita.
            //
            // ⚠️ CONSECUENCIA: el saldo queda en L 470,000.00 y el cuaderno
            // dice L 460,000.00. Se corrige en cuanto se sepa la fecha real
            // —o sola, el 25 de agosto, si ese pago todavía no entró—.
            //
            // ⚠️ Y los dos recibos del historial SE LEEN IGUAL en el escaneo,
            // cosa que no pueden ser: `recibos.numero` es único. El de julio
            // se cargó como 00000329. PENDIENTE de confirmar los dos.
            [
                'expediente' => 48,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'JULIO CÉSAR VARGAS',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '12', 'valor' => '250000.00'],
                    ['bloque' => 'T', 'numero' => '13', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 78,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 103. '
                    .'⚠️ PENDIENTE: el cuaderno anota un tercer renglón —25/08/2026, cuota '
                    .'agosto, L 10,000.00, recibo 00000339, efectivo, recibió Dionel P.— con '
                    .'fecha POSTERIOR a hoy. No se cargó: un recibo no se emite por adelantado. '
                    .'Por eso el saldo acá es L 470,000.00 y el del cuaderno L 460,000.00. '
                    .'⚠️ Los recibos del 25-jul y del 25-ago se leen igual en el escaneo; el de '
                    .'julio se cargó como 00000329. PENDIENTE de confirmar.',
                'pagos' => [
                    [
                        'recibo'        => 329,
                        'fecha'         => '2026-07-25',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: cuota julio. Recibió Dionel P. '
                            .'El número del talonario no se lee con certeza.',
                    ],
                ],
            ],

            // ── Exp. 0049 — página 105 del cuaderno ──────────────────
            //
            // 🔴 EL PRIMERO CANCELADO, Y CON DOS DESCUENTOS POR PAGO AL
            // CONTADO. La página lleva tres lotes del bloque I y dos cuentas
            // que corren en paralelo:
            //
            //   · I-1 e I-2 se pagaron enteros el día de firmar: L 440,000.00
            //     con el recibo 0031, que son L 220,000.00 cada uno contra un
            //     precio de lista de L 250,000.00.
            //   · I-3 pagó prima de L 10,000.00 (recibo 0032) y el 01/07 se
            //     canceló con L 219,000.00 (recibo 0122).
            //
            // ═══ POR QUE EL VALOR CARGADO ES L 669,000.00 Y NO 690,000.00 ═══
            //
            // El cuaderno declara L 690,000.00 y después escribe que la cuenta
            // quedó CANCELADA con saldo 0.00. Las dos no pueden ser ciertas:
            // 440,000 + 10,000 + 219,000 son L 669,000.00.
            //
            // Se respetó el SALDO CERO, porque es lo que le pasa al cliente:
            // pagó y no debe nada. Cargar los 690,000 dejaría a Praderas
            // persiguiendo L 21,000.00 a alguien que está a mano. La
            // diferencia entra donde R4 dice que tiene que entrar —en el
            // precio pactado, con el motivo escrito— y el sistema la VE como
            // descuento en lugar de perderla.
            //
            // ⚠️ La observación de ella dice «descuento de 20,000.00» pero la
            // cuenta cierra con 21,000.00. Se transcribe tal cual y se carga
            // lo que hace cerrar los números. PENDIENTE de preguntarle.
            [
                'expediente' => 49,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'FRANCISCA DUBÓN',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    [
                        'bloque'      => 'I',
                        'numero'      => '1',
                        'valor'       => '220000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '220000.00',
                        // Al contado: la prima cubre el valor entero, no hay nada que financiar.
                        'plazo'  => 0,
                        'motivo' => 'Descuento por pago al contado. Cuaderno pág. 105.',
                    ],
                    [
                        'bloque'      => 'I',
                        'numero'      => '2',
                        'valor'       => '220000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '220000.00',
                        // Al contado: la prima cubre el valor entero, no hay nada que financiar.
                        'plazo'  => 0,
                        'motivo' => 'Descuento por pago al contado. Cuaderno pág. 105.',
                    ],
                    [
                        'bloque'      => 'I',
                        'numero'      => '3',
                        'valor'       => '229000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '0.00',
                        'motivo'      => 'Descuento por pago al contado. Cuaderno pág. 105.',
                    ],
                ],
                'prima'         => '440000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — L 190,000 ADONAY E. Y L 250,000 DIONEL P.',
                'recibo_prima'  => 31,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 105. '
                    .'Observación del cuaderno: «Se le aplicó el descuento de 20,000.00 por pago '
                    .'al contado, el valor original de 240,000.00 se ajusta a valor final de '
                    .'219,000.00. Cuenta cancelada en su totalidad — Saldo 0.00». '
                    .'Estado del cuaderno: lotes 1-2 del bloque I pagados, lote 3 activo. '
                    .'⚠️ El cuaderno declara valor L 690,000.00, pero lo cobrado suma '
                    .'L 669,000.00 y la cuenta cierra en cero: se cargó el valor que hace '
                    .'cerrar el saldo, con el descuento en el precio pactado (R4). '
                    .'⚠️ La observación dice 20,000.00 de descuento y la cuenta pide 21,000.00.',
                'pagos' => [
                    [
                        'recibo'        => 32,
                        'fecha'         => '2026-06-19',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'I-3',
                        'observaciones' => 'Recibo 0032 del talonario. Cuaderno: prima del lote 3 '
                            .'del bloque I. Recibió Dionel Pinto.',
                    ],
                    [
                        'recibo'        => 122,
                        'fecha'         => '2026-07-01',
                        'tipo'          => 'cuota',
                        'monto'         => '219000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => 'I-3',
                        'observaciones' => 'Recibo 0122 del talonario. Cuaderno: pago total del lote 3 '
                            .'del bloque I. Recibió Dionel Pinto. Cancela la cuenta.',
                    ],
                ],
            ],

            // ── Exp. 0050 — página 107 del cuaderno ──────────────────
            //
            // 🔴 CADA LOTE CON SU PRIMA Y SU CUOTA, Y EL SEGUNDO PRECIO DE UN
            // LOTE IRREGULAR.
            //
            // El K-7 mide 401.7500 vr² —uno de los 49 sin precio— y se vendió
            // en L 400,000.00: L 995.64 por vara². El K-8 es normal, 250 vr² a
            // L 250,000.00.
            //
            // ⚠️ Esto MATA la hipótesis del exp. 0033. Allá el O-6 (437.37 vr²)
            // se vendió en L 250,000.00 —L 571.60 la vara²— y acá el K-7, que
            // es MAS CHICO, se vendió a casi el doble por vara². Los
            // irregulares no siguen ninguna regla de tamaño: se cotizan uno
            // por uno, y hay que preguntarle los 47 que faltan.
            //
            // ═══ LOS DOS RECIBOS DE PRIMA ═══
            //
            // El cuaderno cobra la prima en dos papeles y en dos días: el 0030
            // por L 16,000.00 del K-7 el 22/06, y el 0083 por L 10,000.00 del
            // K-8 el 27/06. El sistema emite UN recibo de prima por contrato,
            // así que se cargó el 0030 por los L 26,000.00 juntos.
            //
            // Se eligió perder el número 0083 y NO la cuota, porque la cuota
            // se repite 47 veces más: sin la prima por lote, el reparto
            // proporcional le daría al K-8 una cuota de L 5,208.33 en vez de
            // los L 5,000.00 del papel. ⚠️ Anotado para corregirlo si aparece.
            [
                'expediente' => 50,
                'fecha'      => '2026-06-22',
                'cliente'    => [
                    'nombre'   => 'MARÍA ÁNGELA ALVARADO',
                    'dni'      => '0402197400040',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '7', 'valor' => '400000.00', 'prima' => '16000.00'],
                    ['bloque' => 'K', 'numero' => '8', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '26000.00',
                'plazo'         => 48,
                'dia_pago'      => 22,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 30,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 107. '
                    .'El cuaderno lleva una cuenta por lote: K-7 de L 400,000.00 con prima '
                    .'L 16,000.00 y cuota L 8,000.00; K-8 de L 250,000.00 con prima L 10,000.00 '
                    .'y cuota L 5,000.00. '
                    .'⚠️ La prima entró en DOS recibos: el 0030 el 22/06 por L 16,000.00 y el '
                    .'0083 el 27/06 por L 10,000.00. El sistema emite uno solo por contrato, '
                    .'así que se cargó el 0030 por los L 26,000.00. Recibió Dionel Pinto.',
                'pagos' => [
                    [
                        'recibo'        => 332,
                        'fecha'         => '2026-07-22',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'K-7',
                        'observaciones' => 'Recibo 00000332 del talonario. Cuaderno: cuota julio, '
                            .'bloque K lote 7.',
                    ],
                    [
                        'recibo'        => 333,
                        'fecha'         => '2026-07-22',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'K-8',
                        'observaciones' => 'Recibo 00000333 del talonario. Cuaderno: cuota julio, '
                            .'bloque K lote 8.',
                    ],
                ],
            ],

            // ── Exp. 0051 — página 109 del cuaderno ──────────────────
            //
            // 🔴 EL MAS GRANDE DE TODA LA CARTERA: seis lotes, L 1,500,000.00.
            // Cuatro del bloque J y dos del E, con una cuota total de
            // L 30,000.00 que son seis de L 5,000.00.
            //
            // ⚠️ EL CUADERNO TIENE UN NUMERO MAL. Después de la prima de los
            // cuatro lotes del bloque J escribe un saldo de L 460,000.00, y
            // tiene que ser L 960,000.00 —1,000,000 menos 40,000—. Lo confirma
            // el renglón siguiente: 945,000 después de cobrar 15,000, que sale
            // de 960,000 y no de 460,000. Se cargó el número correcto.
            //
            // Y el recibo 00000328 es el que estrenó el cobro de VARIOS lotes
            // en un papel: L 15,000.00 para los lotes 1, 2 y 11 del bloque J,
            // cinco mil cada uno, sin tocar los otros tres del contrato.
            //
            // ⚠️ La prima también vino en dos recibos el mismo día —0033 por
            // los cuatro del J y 0034 por los dos del E—. Se cargó el 0033 por
            // los L 60,000.00; los seis lotes valen lo mismo, así que el
            // reparto proporcional da los L 10,000.00 por lote del papel.
            [
                'expediente' => 51,
                'fecha'      => '2026-06-22',
                'cliente'    => [
                    'nombre'   => 'MARTA BRIZUELA',
                    'dni'      => '0401196400288',
                    'telefono' => '3353-8688',
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '1',  'valor' => '250000.00'],
                    ['bloque' => 'J', 'numero' => '2',  'valor' => '250000.00'],
                    ['bloque' => 'J', 'numero' => '11', 'valor' => '250000.00'],
                    ['bloque' => 'J', 'numero' => '12', 'valor' => '250000.00'],
                    ['bloque' => 'E', 'numero' => '8',  'valor' => '250000.00'],
                    ['bloque' => 'E', 'numero' => '9',  'valor' => '250000.00'],
                ],
                'prima'         => '60000.00',
                'plazo'         => 48,
                'dia_pago'      => 22,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 33,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 109. '
                    .'⚠️ La prima entró en dos recibos el mismo día: el 0033 por L 40,000.00 de '
                    .'los cuatro lotes del bloque J y el 0034 por L 20,000.00 de los dos del '
                    .'bloque E. El sistema emite uno solo, cargado como 0033 por L 60,000.00. '
                    .'⚠️ El cuaderno anota un saldo de L 460,000.00 después de la primera prima; '
                    .'son L 960,000.00, como lo confirman los renglones siguientes.',
                'pagos' => [
                    [
                        'recibo'        => 328,
                        'fecha'         => '2026-07-21',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO ATLÁNTIDA — DIONEL PINTO',
                        'lotes'         => ['J-1' => '5000.00', 'J-2' => '5000.00', 'J-11' => '5000.00'],
                        'observaciones' => 'Recibo 00000328 del talonario. Cuaderno: cuota julio de los '
                            .'lotes 1, 2 y 11 del bloque J. Recibió Dionel Pinto, Banco Atlántida.',
                    ],
                    [
                        'recibo'        => 334,
                        'fecha'         => '2026-07-23',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO ATLÁNTIDA — DIONEL P.',
                        'lote'          => 'J-12',
                        'observaciones' => 'Recibo 00000334 del talonario. Cuaderno: cuota julio del '
                            .'lote 12 del bloque J.',
                    ],
                    [
                        'recibo'        => 335,
                        'fecha'         => '2026-07-23',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO ATLÁNTIDA — DIONEL P.',
                        'lote'          => 'E-8',
                        'observaciones' => 'Recibo 00000335 del talonario. Cuaderno: cuota julio del '
                            .'lote 8 del bloque E.',
                    ],
                    [
                        'recibo'        => 350,
                        'fecha'         => '2026-07-28',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO ATLÁNTIDA — DIONEL P.',
                        'lote'          => 'E-9',
                        'observaciones' => 'Recibo 00000350 del talonario. Cuaderno: cuota julio del '
                            .'lote 9 del bloque E.',
                    ],
                ],
            ],

            // ── Exp. 0052 — página 111 del cuaderno ──────────────────
            //
            // El cuaderno escribe «Lotes: 3-10 Bloque J». Son DOS lotes, el 3
            // y el 10, no los ocho del 3 al 10: el valor de L 500,000.00 y la
            // cuota de L 10,000.00 solo cierran con dos.
            [
                'expediente' => 52,
                'fecha'      => '2026-06-22',
                'cliente'    => [
                    'nombre'   => 'ELDA KARINA MARTÍNEZ',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '3',  'valor' => '250000.00'],
                    ['bloque' => 'J', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 22,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 45,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 111. '
                    .'El cuaderno escribe «Lotes: 3-10 Bloque J»: son el 3 y el 10, dos lotes, '
                    .'que es lo único que cierra con el valor y la cuota.',
                'pagos' => [
                    [
                        'recibo'        => 52,
                        'fecha'         => '2026-07-22',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0052 del talonario, de la serie corta. '
                            .'Cuaderno: cuota julio. Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0053 — página 113 del cuaderno ──────────────────
            //
            // El lote es el 4 del bloque **I**, confirmado por Mauricio el
            // 11-ago-2026. En el escaneo la «I» y la «J» de esa letra se
            // parecen, y el exp. 0058 tiene el lote 4 del bloque J: por un
            // momento pareció que un lote se había vendido dos veces. No: son
            // dos lotes distintos de dos bloques distintos.
            //
            // La deducción también cerraba sola —el exp. 0049 se llevó I-1,
            // I-2 e I-3 y el exp. 0060 los I-5 e I-6, así que el I-4 era el
            // único hueco del bloque— pero se confirmó contra el papel.
            [
                'expediente' => 53,
                'fecha'      => '2026-06-22',
                'cliente'    => [
                    'nombre'   => 'SAÚL MOISÉS DUBÓN',
                    'dni'      => null,
                    'telefono' => '9961-2432',
                ],
                'lotes' => [
                    ['bloque' => 'I', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 22,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 38,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 113. '
                    .'Lote 4 del bloque I. Prima recibida por Adonay Espinoza.',
                'pagos' => [
                    [
                        'recibo'        => 341,
                        'fecha'         => '2026-07-27',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000341 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0054 — página 115 del cuaderno ──────────────────
            //
            // 🔴 EL CUADERNO DECLARA UN AREA QUE NO ES LA DEL PLANO, Y NO
            // IMPORTA. La página anota «Área = 200 Vr²» y cobra L 200,000.00
            // —mil por vara, el precio parejo—, pero el L-6 del plano mide
            // **424.9000 vr²**: más del doble.
            //
            // Manda el ÁREA DEL PLANO con el VALOR DEL PAPEL. Lo resolvió
            // Mauricio el 11-ago-2026, y vale como regla general:
            //
            //   «no importa si en el papel dice que se le vendió a un precio,
            //    el precio de la vara será ese para ese lote»
            //
            // O sea: el área que ella escribió al margen es una cuenta suya,
            // no un dato del terreno. Lo que se cobró es lo que se cobró, y el
            // precio por vara² de ESTE lote es el que sale de dividir —acá
            // L 470.70— aunque ella tuviera L 1,000.00 en la cabeza. Es la
            // misma regla del precio por lote: la vara² es un resultado.
            [
                'expediente' => 54,
                'fecha'      => '2026-06-26',
                'cliente'    => [
                    'nombre'   => 'YENI LISSETH CONTRERAS LÓPEZ',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'L', 'numero' => '6', 'valor' => '200000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 26,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 40,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 115. '
                    .'El cuaderno anota «Área = 200 Vr²»; el plano da 424.9000 vr² para el L-6. '
                    .'Manda el área del plano con el valor del papel: L 200,000.00, que dan '
                    .'L 470.70 por vara² para este lote.',
                'pagos' => [],
            ],

            // ── Exp. 0055 — página 117 del cuaderno ──────────────────
            //
            // El contrato más viejo de toda la cartera: 12 de junio, dos días
            // antes del exp. 0021. El cuaderno no los numeró en orden de fecha.
            //
            // ⚠️ El apellido de la clienta no se lee con certeza en el
            // escaneo: puede ser «Manuea», «Manueles» o «Mancía».
            [
                'expediente' => 55,
                'fecha'      => '2026-06-12',
                'cliente'    => [
                    'nombre'   => 'MARÍA ANTONIA MANUEA',
                    'dni'      => '0412197100023',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '1', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 12,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 18,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 117. '
                    .'El contrato más antiguo de la cartera. '
                    .'⚠️ El apellido no se lee con certeza: «Manuea», «Manueles» o «Mancía». '
                    .'PENDIENTE de confirmar. Prima recibida por Dionel Pinto.',
                'pagos' => [
                    [
                        'recibo'        => 114,
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0114 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0056 — página 119 del cuaderno ──────────────────
            //
            // 🔴 EL TERCER LOTE IRREGULAR CON PRECIO, y el que rompe la teoría
            // del exp. 0033. El J-6 mide 380.7200 vr² y se vendió en
            // L 380,000.00: **L 998.11 por vara²**, casi el precio parejo.
            //
            // Con el K-7 a L 995.64 son DOS irregulares cerca de los mil, y el
            // O-6 del exp. 0033 —a L 571.60— queda como la excepción. O ese
            // expediente lleva un descuento que no está escrito, o el reparto
            // que le hice entre O-5 y O-6 no es el que ella hizo.
            //
            // ⚠️ Debajo del renglón de la prima hay marcas tenues de otro
            // renglón. No se carga: no se lee.
            [
                'expediente' => 56,
                'fecha'      => '2026-06-28',
                'cliente'    => [
                    'nombre'   => 'NERIN YOVANY ARANDA LÓPEZ',
                    'dni'      => '0412198400050',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '6', 'valor' => '380000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 28,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 53,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 119. '
                    .'El J-6 es un lote irregular de 380.7200 vr²: L 998.11 por vara². '
                    .'⚠️ Debajo del renglón de la prima hay marcas de otro renglón, ilegible; '
                    .'los saldos cierran sin él. Prima recibida por Dionel Pinto.',
                'pagos' => [],
            ],

            // ── Exp. 0057 — página 121 del cuaderno ──────────────────
            //
            // 🔴 ACA SE VIO QUE EL CUADERNO LLEVA DOS TALONARIOS Y EL SISTEMA
            // UNA SOLA SERIE.
            //
            // La prima de esta página es el recibo «0049», de cuatro dígitos,
            // del talonario de primas. Pero el exp. 0025 ya tiene un
            // «00000049» de ocho dígitos, del talonario grande de cuotas. Son
            // dos papeles distintos con el mismo número, y `recibos.numero` es
            // un entero único (R12): no entran los dos.
            //
            // Manda la regla del punto 2 del docblock: el del talonario
            // CORTO se queda sin número. Este recibo se emite con el que le
            // puso el sistema —el 4— y el 00000049 del exp. 0025 conserva el
            // suyo.
            //
            // ⚠️ Los dos talonarios comparten el rango bajo y ya hay varios de
            // cuatro dígitos cargados (0005, 0012, 0018, 0050, 0052, 0108,
            // 0114, 0122), así que esto puede volver a pasar. Con la regla
            // puesta, no hace falta preguntar cada vez.
            [
                'expediente' => 57,
                'fecha'      => '2026-06-24',
                'cliente'    => [
                    'nombre'   => 'ANABEL MEJÍA BRIZUELA',
                    'dni'      => '0412197400173',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '3', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 24,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => 4,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 121. '
                    .'El cuaderno numera esta prima como «0049», pero ese número ya lo tiene '
                    .'el recibo 00000049 del exp. 0025, de otro talonario. Este recibo lleva '
                    .'el número que le puso el sistema, no el del talonario. '
                    .'Recibió Adonay Espinoza.',
                'pagos' => [
                    [
                        'recibo'        => 50,
                        'fecha'         => '2026-07-24',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY ESPINOZA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0050 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay Espinoza.',
                    ],
                ],
            ],

            // ── Exp. 0058 — página 123 del cuaderno ──────────────────
            //
            // Venta AL CONTADO: el mismo día de firmar entraron los
            // L 210,000.00 completos y la cuenta quedó en cero. El cuaderno no
            // anota plazo ni cuota, porque no hay.
            //
            // El lote vale L 250,000.00 de lista y se cobró L 210,000.00: son
            // L 40,000.00 de descuento por pago al contado, el mismo que el
            // exp. 0024. Va en el precio pactado, con su motivo (R4).
            [
                'expediente' => 58,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'DORA ANGÉLICA MEJÍA',
                    'dni'      => '0412195000021',
                    'telefono' => null,
                ],
                'lotes' => [
                    [
                        'bloque'      => 'J',
                        'numero'      => '4',
                        'valor'       => '210000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '210000.00',
                        // Al contado: la prima cubre el valor entero.
                        'plazo'  => 0,
                        'motivo' => 'Descuento por pago al contado. Cuaderno pág. 123.',
                    ],
                ],
                'prima'         => '210000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 54,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 123. '
                    .'Venta al contado: pago total de L 210,000.00 el día de firmar, saldo 0.00. '
                    .'Estado del cuaderno: «Pagado (Escritura 29/septiembre/2026)». '
                    .'Recibió Adonay Espinoza.',
                'pagos' => [],
            ],

            // ── Exp. 0059 — página 125 del cuaderno ──────────────────
            //
            // 🔴 UN LOTE QUE SE AGREGO DESPUES DE FIRMAR. El contrato del 29 de
            // junio es por los lotes 1 y 2 del bloque H a 48 meses, y la
            // observación dice: «Fecha 6 de Julio adquirió Lote 5 Bloque J /
            // valor: 210,000 al contado».
            //
            // Los tres van en la misma venta porque es el mismo expediente y
            // el mismo contrato. El J-5 entra con prima cero y se cancela con
            // el recibo 0108 del 06/07 —los L 210,000.00 dirigidos a ESE
            // lote—, así que el saldo del contrato baja de 690,000 a 480,000,
            // que es justo el saldo que el cuaderno lleva para los dos lotes
            // del bloque H.
            //
            // ⚠️ El J-5 queda con fecha de contrato 29/06 y no 06/07: el
            // sistema tiene una fecha por venta, no una por lote.
            [
                'expediente' => 59,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'DANILO EDGARDO MEJÍA',
                    'dni'      => '0412197400041',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'H', 'numero' => '2', 'valor' => '250000.00', 'prima' => '10000.00'],
                    [
                        'bloque'      => 'J',
                        'numero'      => '5',
                        'valor'       => '210000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '0.00',
                        'motivo'      => 'Descuento por pago al contado. Cuaderno pág. 125.',
                    ],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 51,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 125. '
                    .'Observación del cuaderno: «Fecha 6 de Julio adquirió Lote 5 Bloque J, '
                    .'valor: 210,000 al contado». '
                    .'⚠️ Ese lote entró una semana después de firmar, pero el sistema lleva una '
                    .'sola fecha por venta: el J-5 queda fechado el 29/06.',
                'pagos' => [
                    [
                        'recibo'        => 108,
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '210000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO DE OCCIDENTE — ADONAY ESPINOZA',
                        'lote'          => 'J-5',
                        'observaciones' => 'Recibo 0108 del talonario. Cuaderno: cancelación total del '
                            .'lote 5 del bloque J, al contado. Recibió Adonay Espinoza.',
                    ],
                ],
            ],

            // ── Exp. 0060 — página 127 del cuaderno ──────────────────
            //
            // La prima más grande de la cartera: L 300,000.00 sobre un valor de
            // L 450,000.00, dos tercios por adelantado. Los dos lotes del
            // bloque I van a L 225,000.00 cada uno.
            //
            // Y el segundo renglón es un ABONO A CAPITAL —«Abono a C.»—, no una
            // cuota: acorta el plazo dejando la cuota igual (R3).
            [
                'expediente' => 60,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'HÉCTOR GUSTAVO DUBÓN MELGAR',
                    'dni'      => '0412198700119',
                    'telefono' => '9961-2432',
                ],
                'lotes' => [
                    ['bloque' => 'I', 'numero' => '5', 'valor' => '225000.00'],
                    ['bloque' => 'I', 'numero' => '6', 'valor' => '225000.00'],
                ],
                'prima'         => '300000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 55,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 127. '
                    .'Los dos lotes del bloque I a L 225,000.00 cada uno; el cuaderno no anota '
                    .'motivo, así que entran como precio negociado y no como descuento.',
                'pagos' => [
                    [
                        'recibo'        => 343,
                        'fecha'         => '2026-07-27',
                        'tipo'          => 'abono',
                        'monto'         => '30000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000343 del talonario. Cuaderno: abono a capital. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0061 — página 129 del cuaderno ──────────────────
            [
                'expediente' => 61,
                'fecha'      => '2026-06-30',
                'cliente'    => [
                    'nombre'   => 'NIXON JAVIER ORELLANA TORRES',
                    'dni'      => '0412200900306',
                    'telefono' => '8954-9290',
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 30,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 86,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 129. '
                    .'Prima recibida por Dionel Pinto, recibo 0086 del talonario.',
                'pagos' => [
                    [
                        'recibo'        => 361,
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000361 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0062 — página 131 del cuaderno ──────────────────
            //
            // Otro recibo con bis: el cuaderno numera la prima «0111-1». Ya no
            // importa —la carga no lleva número de talonario— pero queda
            // anotado por si aparece el papel.
            [
                'expediente' => 62,
                'fecha'      => '2026-06-24',
                'cliente'    => [
                    'nombre'   => 'AMARIS GARCÍA',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 24,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 111,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 131. '
                    .'La prima es el recibo «0111-1» del talonario, un bis. Recibió Dionel P.',
                'pagos' => [
                    [
                        'recibo'        => 40,
                        'fecha'         => '2026-07-15',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000040 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0063 — página 133 del cuaderno ──────────────────
            //
            // Comparte teléfono con el exp. 0064 (José Marel Serrano
            // Cartagena): son familia y compraron el mismo día.
            [
                'expediente' => 63,
                'fecha'      => '2026-06-27',
                'cliente'    => [
                    'nombre'   => 'MARÍA TEODOSA SERRANO CARTAGENA',
                    'dni'      => null,
                    'telefono' => '9726-1325',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 114,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 133. '
                    .'La prima es el recibo «0114-1» del talonario, un bis. Recibió Dionel Pinto.',
                'pagos' => [
                    [
                        'recibo'        => 351,
                        'fecha'         => '2026-07-29',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000351 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0064 — página 135 del cuaderno ──────────────────
            [
                'expediente' => 64,
                'fecha'      => '2026-06-27',
                'cliente'    => [
                    'nombre'   => 'JOSÉ MAREL SERRANO CARTAGENA',
                    'dni'      => null,
                    'telefono' => '9726-1325',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '7', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 115,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 135. '
                    .'Prima recibida por Dionel Pinto, recibo 0115 del talonario.',
                'pagos' => [
                    [
                        'recibo'        => 352,
                        'fecha'         => '2026-07-29',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000352 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0066 — página 139 del cuaderno ──────────────────
            //
            // 🔴 EL CUARTO LOTE IRREGULAR CON PRECIO, y el que termina de
            // aislar al O-6 como la excepción.
            //
            // El M-7 mide 413.6800 vr² —de los 49 sin precio— y el M-8 es
            // normal, 250 vr². Los dos juntos se vendieron en L 663,000.00.
            // Con el M-8 a su precio confirmado de L 250,000.00, al M-7 le
            // quedan L 413,000.00: **L 998.36 por vara²**, en la misma línea
            // que el K-7 (995.64) y el J-6 (998.11).
            //
            // ⚠️ La cuota del cuaderno son L 13,605.00 y el saldo de
            // L 653,000.00 a 48 meses da L 13,604.17. Acá manda el VALOR y no
            // la cuota —al revés que en el exp. 0028— porque el valor se
            // descompone exacto en dos precios de lote y la cuota parece
            // redondeada a mano. La diferencia son 83 centavos al mes.
            [
                'expediente' => 66,
                'fecha'      => '2026-06-27',
                'cliente'    => [
                    'nombre'   => 'RENÉ ARTURO VARGAS',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'M', 'numero' => '7', 'valor' => '413000.00'],
                    ['bloque' => 'M', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 117,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 139. '
                    .'El M-7 es irregular (413.6800 vr²) y su precio se dedujo restando el del '
                    .'M-8: L 413,000.00, que son L 998.36 por vara². '
                    .'⚠️ El cuaderno anota cuota de L 13,605.00; el valor da L 13,604.17. '
                    .'Se respetó el valor. Prima recibida por Dionel.',
                'pagos' => [],
            ],

            // ── Exp. 0067 — página 141 del cuaderno ──────────────────
            [
                'expediente' => 67,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'GERSON NOÉ TORRES MEJÍA',
                    'dni'      => null,
                    'telefono' => '8846-2307',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 118,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 141. '
                    .'Prima recibida por Dionel Pinto, recibo 0118 del talonario.',
                'pagos' => [
                    [
                        'recibo'        => 326,
                        'fecha'         => '2026-07-21',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000326 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay Espinoza.',
                    ],
                ],
            ],

            // ── Exp. 0065 — página 137 del cuaderno ──────────────────
            //
            // 🔴 EL CONTRATO MAS GRANDE DE LA CARTERA: ocho lotes del bloque S
            // por L 2,000,000.00, con una cuota de L 40,000.00 al mes.
            //
            // Los ocho los confirmó Mauricio el 11-ago-2026 contra el papel, y
            // los números lo respaldan por tres lados: el valor da 8 lotes a
            // L 250,000.00, la prima 8 de L 10,000.00 y la cuota 8 de
            // L 5,000.00.
            //
            // ⚠️ El cuaderno anota «Área: 1750 Vr²», que son SIETE lotes de
            // 250. Es una cuenta mal hecha al margen, no un dato del terreno:
            // ocho lotes de 250 vr² son 2,000 vr². Vale igual que en el exp.
            // 0054 — la medida la pone el plano, no la anotación.
            [
                'expediente' => 65,
                'fecha'      => '2026-06-27',
                'cliente'    => [
                    'nombre'   => 'MARÍA ISABEL AGUILAR LÓPEZ',
                    'dni'      => null,
                    'telefono' => '9389-8131',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '2',  'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '3',  'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '4',  'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '12', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '13', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '14', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '15', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '16', 'valor' => '250000.00'],
                ],
                'prima'         => '80000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 95,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 137. '
                    .'Vendido por: Jony Gerson García Melgar. '
                    .'⚠️ El cuaderno anota «Área: 1750 Vr²», que son siete lotes de 250; los ocho '
                    .'miden 2,000 vr². La anotación del margen está mal, no el plano. '
                    .'Prima recibida por Dionel Pinto.',
                'pagos' => [
                    [
                        'recibo'        => 365,
                        'fecha'         => '2026-08-01',
                        'tipo'          => 'cuota',
                        'monto'         => '40000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000365 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0068 — página 143 del cuaderno ──────────────────
            //
            // Cuatro lotes del bloque F —el 1, el 2, el 15 y el 16, confirmados
            // por Mauricio el 11-ago-2026— por L 1,000,000.00.
            //
            // ═══ LA PRIMA VINO EN DOS PAPELES Y EN DOS FECHAS ═══
            //
            // El recibo 0120 del 01/07 por L 20,000.00 y el 00000032 del 13/07
            // —anotado «Prima Inic.»— por otros L 20,000.00. El cuaderno lleva
            // las dos mitades como cuentas paralelas de L 480,000.00 cada una.
            //
            // Se cargó la prima entera de L 40,000.00 al firmar, diez mil por
            // lote, que es lo que hace salir la cuota de L 5,000.00 por lote y
            // los L 20,000.00 del contrato. La alternativa —media prima ahora y
            // media el 13/07— dejaría a dos lotes con cuota de L 5,208.33, y
            // esa diferencia se repite 47 veces. Misma decisión que en el exp.
            // 0050.
            //
            // ⚠️ El cuaderno acredita la cuota del 31/07 a DOS de los cuatro
            // lotes; acá se reparte entre los cuatro. El saldo del contrato es
            // el mismo —L 950,000.00— y lo que cambia es a cuál lote se le
            // acredita. Se empareja solo con los pagos que vengan.
            [
                'expediente' => 68,
                'fecha'      => '2026-07-01',
                'cliente'    => [
                    'nombre'   => 'FREDY EDGARDO LÓPEZ SAAVEDRA',
                    'dni'      => '0412198200106',
                    'telefono' => '9684-2285',
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '1',  'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'F', 'numero' => '2',  'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'F', 'numero' => '15', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'F', 'numero' => '16', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '40000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 1,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 120,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 143. '
                    .'Vendido por: Jony Gerson García. '
                    .'⚠️ La prima entró en dos recibos: el 0120 el 01/07 por L 20,000.00 y el '
                    .'00000032 el 13/07 por otros L 20,000.00, anotado «Prima Inic.». El sistema '
                    .'emite uno solo, cargado por los L 40,000.00. '
                    .'⚠️ La cuota del 31/07 el cuaderno la acredita a dos de los cuatro lotes; '
                    .'acá se reparte entre los cuatro y el saldo del contrato es el mismo. '
                    .'Recibió Dionel Pinto.',
                'pagos' => [
                    [
                        'recibo'        => 356,
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000356 del talonario. Cuaderno: cuota agosto. '
                            .'Recibió Adonay E.',
                    ],
                ],
            ],

            // ═══════════════════════════════════════════════════════════
            // TERCER CUADERNO — páginas 145 en adelante
            // ═══════════════════════════════════════════════════════════

            // ── Exp. 0069 — página 145 del cuaderno ──────────────────
            //
            // 🔴 QUINTO LOTE IRREGULAR CON PRECIO. El T-7 mide 351.7600 vr² y
            // se vendió en L 350,000.00: **L 994.99 por vara²**, otra vez
            // pegado a los mil.
            [
                'expediente' => 69,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'WALTER AGUILAR LÓPEZ',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '7', 'valor' => '350000.00'],
                ],
                'prima'         => '14000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 179,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 145. '
                    .'Vendido por: Yoni García. '
                    .'El T-7 es irregular (351.7600 vr²): L 994.99 por vara². '
                    .'Prima recibida por Dionel Pinto.',
                'pagos' => [],
            ],

            // ── Exp. 0070 — página 147 del cuaderno ──────────────────
            //
            // 🔴 DOS CUENTAS EN UNA PAGINA, Y EL PRIMER IRREGULAR POR ENCIMA
            // DE LOS MIL.
            //
            //   · El O-3 se vendió en L 210,000.00 y entró casi entero el día
            //     de firmar: prima de L 200,000.00, saldo L 10,000.00.
            //   · El O-4 y el N-8 van juntos en L 605,000.00 con prima de
            //     L 20,000.00 — saldo L 585,000.00, que a 48 meses da los
            //     L 12,187.50 de cuota que anota el cuaderno.
            //
            // ⚠️ EL REPARTO DEL SEGUNDO PAR ES DEDUCCION MIA. El O-4 mide 250
            // vr² y va a su precio confirmado de L 250,000.00, así que al N-8
            // —irregular, 345.0900 vr²— le quedan L 355,000.00: **L 1,028.72
            // por vara²**. Es el primero de los seis irregulares que pasa de
            // los mil; los otros cinco caen entre 990 y 999. PENDIENTE de
            // confirmar con la contratante.
            [
                'expediente' => 70,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'CARLOS VARGAS',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    [
                        'bloque'      => 'O',
                        'numero'      => '3',
                        'valor'       => '210000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '200000.00',
                        'motivo'      => 'Descuento por pago casi al contado. Cuaderno pág. 147.',
                    ],
                    ['bloque' => 'O', 'numero' => '4', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'N', 'numero' => '8', 'valor' => '355000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '220000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 80,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 147. '
                    .'El cuaderno lleva dos cuentas: el O-3 por L 210,000.00 con prima de '
                    .'L 200,000.00 (recibo 0080), y el O-4 con el N-8 por L 605,000.00 con prima '
                    .'de L 20,000.00 (recibo 0081). '
                    .'⚠️ El reparto de los L 605,000.00 entre O-4 y N-8 es deducción: el O-4 va a '
                    .'su precio confirmado y al N-8 le quedan L 355,000.00, que son L 1,028.72 por '
                    .'vara². Es el único irregular por encima de los mil. PENDIENTE de confirmar.',
                'pagos' => [],
            ],

            // ── Exp. 0071 — página 149 del cuaderno ──────────────────
            [
                'expediente' => 71,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'JUAN RAMÓN RODRÍGUEZ VARGAS',
                    'dni'      => '0421199200063',
                    'telefono' => '9432-9099',
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '3', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 82,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 149. '
                    .'Prima recibida por Dionel Pinto.',
                'pagos' => [
                    [
                        'recibo'        => 378,
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000378 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Adonay.',
                    ],
                ],
            ],

            // ── Exp. 0072 — página 151 del cuaderno ──────────────────
            //
            // 🔴 TRES LOTES AL CONTADO Y UNO FINANCIADO, EN EL MISMO CONTRATO.
            //
            //   · Los U-9, U-10 y U-11 se pagaron enteros el día de firmar:
            //     L 650,000.00 en un solo recibo, mitad por Adonay Espinoza y
            //     mitad por Dionel Pinto. Valen L 750,000.00 de lista, así que
            //     son L 100,000.00 de descuento por pago al contado (R4).
            //   · El D-16 va a 48 meses con prima de L 10,000.00.
            //
            // El abono a capital del 31/07 es del D-16: acorta el plazo (R3).
            [
                'expediente' => 72,
                'fecha'      => '2026-07-02',
                'cliente'    => [
                    'nombre'   => 'SANTOS ISRAEL ROQUE AGUIRRE',
                    'dni'      => '1412197200077',
                    'telefono' => '9788-5299',
                ],
                'lotes' => [
                    [
                        'bloque'      => 'U',
                        'numero'      => '9',
                        'valor'       => '216666.66',
                        'valor_lista' => '250000.00',
                        'prima'       => '216666.66',
                        // Al contado: la prima cubre el valor entero.
                        'plazo'  => 0,
                        'motivo' => 'Descuento por pago al contado. Cuaderno pág. 151.',
                    ],
                    [
                        'bloque'      => 'U',
                        'numero'      => '10',
                        'valor'       => '216666.67',
                        'valor_lista' => '250000.00',
                        'prima'       => '216666.67',
                        'plazo'       => 0,
                        'motivo'      => 'Descuento por pago al contado. Cuaderno pág. 151.',
                    ],
                    [
                        'bloque'      => 'U',
                        'numero'      => '11',
                        'valor'       => '216666.67',
                        'valor_lista' => '250000.00',
                        'prima'       => '216666.67',
                        'plazo'       => 0,
                        'motivo'      => 'Descuento por pago al contado. Cuaderno pág. 151.',
                    ],
                    ['bloque' => 'D', 'numero' => '16', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '660000.00',
                'plazo'         => 48,
                'dia_pago'      => 2,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — L 325,000 ADONAY E. Y L 325,000 DIONEL P.',
                'recibo_prima'  => 84,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 151. '
                    .'Los lotes 9, 10 y 11 del bloque U se pagaron al contado el día de firmar: '
                    .'L 650,000.00 contra L 750,000.00 de lista, L 100,000.00 de descuento. '
                    .'El lote 16 del bloque D queda activo a 48 meses. '
                    .'La prima entró en dos recibos: el 0084 por el pago total y el 0085 por los '
                    .'L 10,000.00 del D-16.',
                'pagos' => [
                    [
                        'recibo'        => 362,
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'abono',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => 'D-16',
                        'observaciones' => 'Recibo 00000362 del talonario. Cuaderno: abono a capital '
                            .'del lote 16 del bloque D. Recibió Adonay E.',
                    ],
                ],
            ],

            // ── Exp. 0073 — página 153 del cuaderno ──────────────────
            //
            // El P-2 es uno de los diez lotes de 252.0000 vr² que se venden al
            // mismo precio que los de 250: L 250,000.00. Es el caso que destapó
            // que el precio es por lote y no por vara².
            [
                'expediente' => 73,
                'fecha'      => '2026-06-28',
                'cliente'    => [
                    'nombre'   => 'CARLOS CHACÓN ARÉVALO',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'P', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 28,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 43,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 153. '
                    .'Prima recibida por Dionel Pinto.',
                'pagos' => [],
            ],

            // ── Exp. 0074 — página 155 del cuaderno ──────────────────
            //
            // Cuarto vendedor externo del cuaderno: Dago Aguilar.
            [
                'expediente' => 74,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'JOSÉ MARÍA SAAVEDRA MIRANDA',
                    'dni'      => '0412198500463',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 87,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 155. '
                    .'Vendido por: Dago Aguilar.',
                'pagos' => [
                    [
                        'recibo'        => 355,
                        'fecha'         => '2026-07-30',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000355 del talonario. Cuaderno: cuota julio. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0075 — página 157 del cuaderno ──────────────────
            //
            // 🔴 SEIS LOTES, DOS CUENTAS Y CUATRO BLOQUES DISTINTOS.
            //
            //   · Los U-3, U-4 y H-8 se pagaron enteros el 03/07:
            //     L 645,000.00 contra L 750,000.00 de lista, L 105,000.00 de
            //     descuento por pago al contado.
            //   · Los W-1, W-2 y U-2 van a 48 meses por L 750,000.00, con
            //     prima de L 30,000.00 y cuota de L 15,000.00.
            //
            // Los dos recibos de prima —el 0088 por los L 30,000.00 y el 0094
            // por el pago total— se cargan como una sola prima de
            // L 675,000.00, que es lo que hace salir la cuota del papel.
            [
                'expediente' => 75,
                'fecha'      => '2026-07-03',
                'cliente'    => [
                    'nombre'   => 'OTILIA HENRÍQUEZ',
                    'dni'      => '0406197000068',
                    'telefono' => null,
                ],
                'lotes' => [
                    [
                        'bloque'      => 'U',
                        'numero'      => '3',
                        'valor'       => '215000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '215000.00',
                        // Al contado: la prima cubre el valor entero.
                        'plazo'  => 0,
                        'motivo' => 'Descuento por pago al contado. Cuaderno pág. 157.',
                    ],
                    [
                        'bloque'      => 'U',
                        'numero'      => '4',
                        'valor'       => '215000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '215000.00',
                        'plazo'       => 0,
                        'motivo'      => 'Descuento por pago al contado. Cuaderno pág. 157.',
                    ],
                    [
                        'bloque'      => 'H',
                        'numero'      => '8',
                        'valor'       => '215000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '215000.00',
                        'plazo'       => 0,
                        'motivo'      => 'Descuento por pago al contado. Cuaderno pág. 157.',
                    ],
                    ['bloque' => 'W', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'W', 'numero' => '2', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'U', 'numero' => '2', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '675000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 88,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 157. '
                    .'Dos cuentas: los lotes 3 y 4 del bloque U con el lote 8 del bloque H se '
                    .'pagaron al contado por L 645,000.00 (recibo 0094), y los lotes 1 y 2 del '
                    .'bloque W con el lote 2 del bloque U quedan a 48 meses por L 750,000.00 con '
                    .'prima de L 30,000.00 (recibo 0088).',
                'pagos' => [
                    [
                        'recibo'        => 372,
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lotes'         => ['W-1' => '5000.00', 'W-2' => '5000.00', 'U-2' => '5000.00'],
                        'observaciones' => 'Recibo 00000372 del talonario. Cuaderno: cuota agosto de '
                            .'los lotes 1 y 2 del bloque W y el lote 2 del bloque U. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0076 — página 159 del cuaderno ──────────────────
            //
            // 🔴 SEXTO LOTE IRREGULAR CON PRECIO. El W-4 mide 403.7500 vr² y se
            // vendió en L 400,000.00: **L 990.71 por vara²**.
            [
                'expediente' => 76,
                'fecha'      => '2026-07-03',
                'cliente'    => [
                    'nombre'   => 'DAVID REYES',
                    'dni'      => '1701197901281',
                    'telefono' => '9740-3325',
                ],
                'lotes' => [
                    ['bloque' => 'W', 'numero' => '4', 'valor' => '400000.00'],
                ],
                'prima'         => '16000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 92,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 159. '
                    .'Vendido por: Jony Gerson García. '
                    .'El W-4 es irregular (403.7500 vr²): L 990.71 por vara².',
                'pagos' => [
                    [
                        'recibo'        => 366,
                        'fecha'         => '2026-08-01',
                        'tipo'          => 'cuota',
                        'monto'         => '8000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000366 del talonario. Cuaderno: cuota agosto. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0077 — página 161 del cuaderno ──────────────────
            //
            // 🔴 SEPTIMO LOTE IRREGULAR CON PRECIO. El V-5 mide 200.5600 vr²
            // —el segundo más chico del plano— y con el V-6 a su precio
            // confirmado le quedan L 200,000.00: **L 997.21 por vara²**, otra
            // vez en la banda de los mil.
            //
            // ⚠️ El cuaderno fecha los dos pagos en 2025 y el contrato en
            // 2026. Es un lapsus de la mano: se cargan en 2026, que es lo
            // único posible.
            [
                'expediente' => 77,
                'fecha'      => '2026-07-02',
                'cliente'    => [
                    'nombre'   => 'MARÍA MARILÉ RODRÍGUEZ ALVARADO',
                    'dni'      => '0406198100067',
                    'telefono' => '9672-8647',
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '5', 'valor' => '200000.00'],
                    ['bloque' => 'V', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '18000.00',
                'plazo'         => 48,
                'dia_pago'      => 2,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 89,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 161. '
                    .'El V-5 es irregular (200.5600 vr²): L 997.21 por vara². '
                    .'⚠️ El cuaderno fecha los pagos en 2025 y el contrato en 2026; '
                    .'se cargaron en 2026.',
                'pagos' => [
                    [
                        'recibo'        => 368,
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'cuota',
                        'monto'         => '9000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000368 del talonario. Cuaderno: cuota agosto. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0078 — página 163 del cuaderno ──────────────────
            //
            // El cuaderno anota como vendedor a Dionel Pinto, que es también
            // quien recibe la plata casi siempre. No es contradicción: acá
            // vendió él.
            [
                'expediente' => 78,
                'fecha'      => '2026-07-04',
                'cliente'    => [
                    'nombre'   => 'ROBERTH JOSÉ TRIGUEROS MEJÍA',
                    'dni'      => '0412200500272',
                    'telefono' => '9549-5599',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DIONEL PINTO',
                'plazo'         => 48,
                'dia_pago'      => 4,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA — DIONEL PINTO',
                'recibo_prima'  => 99,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 163.',
                'pagos'         => [
                    [
                        'recibo'        => 100,
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0100 del talonario. Cuaderno: cuota julio, pagada '
                            .'el mismo día de firmar.',
                    ],
                    [
                        'recibo'        => 376,
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000376 del talonario. Cuaderno: cuota agosto.',
                    ],
                ],
            ],

            // ── Exp. 0082 — página 171 del cuaderno ──────────────────
            [
                'expediente' => 82,
                'fecha'      => '2026-07-01',
                'cliente'    => [
                    'nombre'   => 'MARLON JOEL GARCÍA SANTOS',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 1,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => 123,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 171.',
                'pagos'         => [
                    [
                        'recibo'        => 364,
                        'fecha'         => '2026-08-01',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000364 del talonario. Cuaderno: cuota agosto. '
                            .'Recibió Dionel P.',
                    ],
                ],
            ],

            // ── Exp. 0083 — página 173 del cuaderno ──────────────────
            //
            // El lote es el 9 del bloque **J**, confirmado por Mauricio el
            // 11-ago-2026: en el escaneo esa letra podía leerse como J o
            // como D, y los dos lotes estaban libres.
            [
                'expediente' => 83,
                'fecha'      => '2026-07-01',
                'cliente'    => [
                    'nombre'   => 'JESÚS ANTONIO ARANDA',
                    'dni'      => '0412198700199',
                    'telefono' => '9813-2550',
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 1,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => 124,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 173.',
                'pagos'         => [
                    [
                        'recibo'        => 367,
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000367 del talonario. Cuaderno: cuota agosto. '
                            .'Recibió Dionel.',
                    ],
                ],
            ],

            // ── Exp. 0084 — página 175 del cuaderno ──────────────────
            //
            // El cuaderno escribe «Dagoberto Aguilar»; el exp. 0074 escribe
            // «Dago Aguilar». Entra con el nombre completo, que es el mismo
            // criterio que se usó con Jony Gerson García.
            //
            // El recibo 00000347 cubre DOS cuotas —agosto y septiembre— con
            // L 10,000.00: el sistema lo reparte solo.
            [
                'expediente' => 84,
                'fecha'      => '2026-06-18',
                'cliente'    => [
                    'nombre'   => 'YUNIBEX MALDONADO ESTÉVEZ',
                    'dni'      => '0412199000228',
                    'telefono' => '9856-1664',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '13', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 18,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO BANCO DE OCCIDENTE — ADONAY',
                'recibo_prima'  => 106,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 175. '
                    .'⚠️ El cuaderno no numera el recibo de la prima.',
                'pagos' => [
                    [
                        'recibo'        => 107,
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0107 del talonario. Cuaderno: cuota julio 2026.',
                    ],
                    [
                        'recibo'        => 347,
                        'fecha'         => '2026-07-27',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000347 del talonario. Cuaderno: cuota agosto y '
                            .'cuota septiembre, L 10,000.00 que cubren dos meses.',
                    ],
                ],
            ],

            // ── Exp. 0085 — página 177 del cuaderno ──────────────────
            //
            // 🔴 CINCO LOTES, DOS CUENTAS Y EL PRECIO DE UN 337.50 QUE NO ES
            // EL DEL EXP. 0028.
            //
            // El W-5 mide 337.5000 vr² —del mismo grupo que los H-9, H-15 y
            // H-16 del exp. 0028, que se vendieron a L 325,000.00— y acá se
            // vendió en **L 337,000.00**: L 998.52 por vara². O sea que ni
            // siquiera los lotes de la misma medida tienen un precio único:
            // cada uno se negocia. Es la misma lección del precio por lote,
            // llevada un paso más lejos.
            //
            // ⚠️ El cuaderno anota un saldo de L 460,000.00 después de la
            // segunda prima; son L 960,000.00 —1,000,000 menos 40,000— y la
            // cuota de L 20,000.00 lo confirma. Es el mismo lapsus del 4 por
            // el 9 que ya tuvo el exp. 0051.
            [
                'expediente' => 85,
                'fecha'      => '2026-07-07',
                'cliente'    => [
                    'nombre'   => 'MARÍA EVELINA CABALLERO',
                    'dni'      => '0412197000099',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'W', 'numero' => '5',  'valor' => '337000.00', 'prima' => '15384.00'],
                    ['bloque' => 'F', 'numero' => '3',  'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'F', 'numero' => '14', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'G', 'numero' => '3',  'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'G', 'numero' => '14', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '55384.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 7,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 111,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 177. '
                    .'Dos cuentas: el lote 5 del bloque W por L 337,000.00 con prima de '
                    .'L 15,384.00 (recibo 0111), y los lotes 3 y 14 de los bloques F y G por '
                    .'L 1,000,000.00 con prima de L 40,000.00 (recibo 0112). El sistema emite '
                    .'un solo recibo de prima, cargado por los L 55,384.00. '
                    .'⚠️ El cuaderno anota un saldo de L 460,000.00 donde van L 960,000.00. '
                    .'Recibió Adonay Espinoza.',
                'pagos' => [],
            ],

            // ── Exp. 0086 — página 179 del cuaderno ──────────────────
            //
            // ⚠️ El cuaderno deja el VALOR DE LA VENTA en blanco. Se dedujo de
            // sus propios números: prima de L 20,000.00 con saldo de
            // L 480,000.00 dan L 500,000.00, que son los dos lotes a su precio
            // confirmado, y la cuota de L 10,000.00 lo confirma.
            [
                'expediente' => 86,
                'fecha'      => '2026-07-07',
                'cliente'    => [
                    'nombre'   => 'WILMER VALERIO SANTOS',
                    'dni'      => '0412200100095',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '5', 'valor' => '250000.00'],
                    ['bloque' => 'T', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 7,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 16,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 179. '
                    .'⚠️ El cuaderno deja el valor de la venta en blanco; se dedujo L 500,000.00 '
                    .'del saldo y de la cuota. Recibió Adonay E.',
                'pagos' => [],
            ],

            // ── Exp. 0087 — página 181 del cuaderno ──────────────────
            //
            // ⚠️ La prima entró el 22/06, tres días después de firmar. El
            // sistema la fecha el día del contrato: tiene una sola fecha para
            // la prima y es la de la venta (R5).
            [
                'expediente' => 87,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'KEVIN AGUILAR LÓPEZ',
                    'dni'      => '0412199500189',
                    'telefono' => '9306-4313',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA — DIONEL PINTO',
                'recibo_prima'  => 14,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 181. '
                    .'⚠️ El cuaderno cobra la prima el 22/06, tres días después de firmar; el '
                    .'sistema la fecha el día del contrato.',
                'pagos' => [
                    [
                        'recibo'        => 15,
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000015 del talonario. Cuaderno: cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0088 — página 183 del cuaderno ──────────────────
            //
            // Cancelado en diez días: L 200,000.00 al firmar y los L 10,000.00
            // que faltaban el 14 de julio. El lote vale L 250,000.00 de lista
            // y se cobró L 210,000.00 — L 40,000.00 de descuento por pago al
            // contado, el mismo que los exp. 0024 y 0058.
            //
            // ⚠️ LA PRIMA ENTRO EN DOS FORMAS DE PAGO: L 170,000.00 por
            // depósito y L 30,000.00 en efectivo. El sistema lleva una forma
            // por recibo, así que se cargó como depósito —el grueso— y el
            // detalle queda escrito.
            //
            // Y aparece un vendedor nuevo: Abigail Orellana.
            [
                'expediente' => 88,
                'fecha'      => '2026-07-04',
                'cliente'    => [
                    'nombre'   => 'YEYSON ANDONI TRIGUEROS HERNÁNDEZ',
                    'dni'      => '0412199800384',
                    'telefono' => null,
                ],
                'lotes' => [
                    [
                        'bloque'      => 'E',
                        'numero'      => '5',
                        'valor'       => '210000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '200000.00',
                        'motivo'      => 'Descuento por pago al contado. Cuaderno pág. 183.',
                    ],
                ],
                'prima'         => '200000.00',
                'vendedor'      => 'ABIGAIL ORELLANA',
                'plazo'         => 48,
                'dia_pago'      => 4,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO L 170,000 Y EFECTIVO L 30,000 — DIONEL P.',
                'recibo_prima'  => 3,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 183. '
                    .'⚠️ La prima entró en dos formas: L 170,000.00 por depósito y L 30,000.00 '
                    .'en efectivo. El sistema lleva una forma por recibo; se cargó como depósito.',
                'pagos' => [
                    [
                        'recibo'        => 38,
                        'fecha'         => '2026-07-14',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000038 del talonario. Cuaderno: cancelación total. '
                            .'Recibió Dionel Pinto.',
                    ],
                ],
            ],

            // ── Exp. 0089 — página 185 del cuaderno ──────────────────
            //
            // 🔴 OCTAVO LOTE IRREGULAR CON PRECIO. El U-7 mide 325.5100 vr² y
            // se vendió en L 325,000.00: **L 998.43 por vara²**.
            [
                'expediente' => 89,
                'fecha'      => '2026-07-09',
                'cliente'    => [
                    'nombre'   => 'JOSÉ LUIS ESTÉVEZ AGUILAR',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'U', 'numero' => '7', 'valor' => '325000.00'],
                ],
                'prima'         => '17800.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 9,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 17,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 185. '
                    .'El U-7 es irregular (325.5100 vr²): L 998.43 por vara². '
                    .'Recibió Dionel Pinto.',
                'pagos' => [],
            ],

            // ── Exp. 0090 — página 187 del cuaderno ──────────────────
            //
            // Venta al contado: L 230,000.00 el mismo día de firmar, saldo
            // cero. El lote vale L 250,000.00 de lista, así que son
            // L 20,000.00 de descuento con su motivo (R4).
            [
                'expediente' => 90,
                'fecha'      => '2026-07-25',
                'cliente'    => [
                    'nombre'   => 'ÁNGELA RAMÍREZ ACOSTA',
                    'dni'      => '0406197600292',
                    'telefono' => null,
                ],
                'lotes' => [
                    [
                        'bloque'      => 'U',
                        'numero'      => '8',
                        'valor'       => '230000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '230000.00',
                        // Al contado: la prima cubre el valor entero.
                        'plazo'  => 0,
                        'motivo' => 'Descuento por pago al contado. Cuaderno pág. 187.',
                    ],
                ],
                'prima'         => '230000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 25,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL',
                'recibo_prima'  => 340,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 187. '
                    .'Cancelación total del lote 8 del bloque U el día de firmar.',
                'pagos' => [],
            ],

            // ── Exp. 0091 — página 189 del cuaderno ──────────────────
            [
                'expediente' => 91,
                'fecha'      => '2026-07-09',
                'cliente'    => [
                    'nombre'   => 'CARLOS JOSÉ MANCÍA GONZÁLEZ',
                    'dni'      => '0412200100400',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 9,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA — ADONAY E.',
                'recibo_prima'  => 25,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 189.',
                'pagos'         => [],
            ],

            // ── Exp. 0092 — página 191 del cuaderno ──────────────────
            //
            // Un lote al contado y tres financiados. El G-5 se pagó entero el
            // día de firmar —L 230,000.00 contra L 250,000.00 de lista— y el
            // cuaderno lo anota al margen: «El lote #5 Bloque G pagado en su
            // totalidad». Los otros tres van a 48 meses.
            [
                'expediente' => 92,
                'fecha'      => '2026-07-10',
                'cliente'    => [
                    'nombre'   => 'HÉCTOR PÉREZ',
                    'dni'      => '1406195700030',
                    'telefono' => null,
                ],
                'lotes' => [
                    [
                        'bloque'      => 'G',
                        'numero'      => '5',
                        'valor'       => '230000.00',
                        'valor_lista' => '250000.00',
                        'prima'       => '230000.00',
                        // Al contado: la prima cubre el valor entero.
                        'plazo'  => 0,
                        'motivo' => 'Descuento por pago al contado. Cuaderno pág. 191.',
                    ],
                    ['bloque' => 'G', 'numero' => '6',  'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'G', 'numero' => '11', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'G', 'numero' => '12', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '260000.00',
                'plazo'         => 48,
                'dia_pago'      => 10,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA BANCO DE OCCIDENTE — DIONEL P.',
                'recibo_prima'  => 21,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 191. '
                    .'Observación del cuaderno: «El lote #5 Bloque G pagado en su totalidad». '
                    .'La prima entró en dos recibos: el 00000021 por el pago total del G-5 y el '
                    .'00000022 por los L 30,000.00 de los otros tres.',
                'pagos' => [],
            ],

            // ── Exp. 0093 — página 193 del cuaderno ──────────────────
            //
            // ⚠️ El DNI del cuaderno tiene 12 dígitos —«1406 1985 0022»— y el
            // hondureño lleva 13. Queda pendiente de ingresar.
            [
                'expediente' => 93,
                'fecha'      => '2026-07-10',
                'cliente'    => [
                    'nombre'   => 'MIRIAN YOLANDA RECINOS PÉREZ',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '7', 'valor' => '250000.00'],
                    ['bloque' => 'F', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 10,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 25,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 193.',
                'pagos'         => [
                ],
            ],

            // ── Exp. 0094 — página 195 del cuaderno ──────────────────
            [
                'expediente' => 94,
                'fecha'      => '2026-07-10',
                'cliente'    => [
                    'nombre'   => 'IRIS YOLANDA PEÑA SOL',
                    'dni'      => '0412198200200',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 10,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 26,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 195.',
                'pagos'         => [
                ],
            ],

            // ── Exp. 0095 — página 197 del cuaderno ──────────────────
            //
            // Dos lotes de 337.5000 vr² a L 324,000.00 cada uno —L 960.00 por
            // vara²—, con una prima de casi la mitad del valor. El grupo de
            // 337.50 ya lleva tres precios distintos: L 325,000.00 en el exp.
            // 0028, L 337,000.00 en el 0085 y L 324,000.00 acá.
            [
                'expediente' => 95,
                'fecha'      => '2026-07-18',
                'cliente'    => [
                    'nombre'   => 'ERNESTO CÁRDENAS',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '12', 'valor' => '324000.00'],
                    ['bloque' => 'H', 'numero' => '13', 'valor' => '324000.00'],
                ],
                'prima'         => '312000.00',
                'plazo'         => 48,
                'dia_pago'      => 18,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 317,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 197. '
                    .'Recibió Dionel P.',
                'pagos' => [],
            ],

            // ── Exp. 0096 — página 199 del cuaderno ──────────────────
            [
                'expediente' => 96,
                'fecha'      => '2026-07-01',
                'cliente'    => [
                    'nombre'   => 'MARÍA CANDELARIA ALVARADO',
                    'dni'      => '0402198000180',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 1,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 121,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 199.',
                'pagos'         => [
                ],
            ],

            // ── Exp. 0097 — página 201 del cuaderno ──────────────────
            [
                'expediente' => 97,
                'fecha'      => '2026-06-24',
                'cliente'    => [
                    'nombre'   => 'MARÍA ANTONIA AGUILAR',
                    'dni'      => '0412197900065',
                    'telefono' => '9901-5427',
                ],
                'lotes' => [
                    ['bloque' => 'R', 'numero' => '14', 'valor' => '250000.00'],
                    ['bloque' => 'R', 'numero' => '15', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 24,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 115,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 201.',
                'pagos'         => [
                    [
                        'recibo'        => 337,
                        'fecha'         => '2026-07-23',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000337 del talonario. Cuaderno: cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0098 — página 203 del cuaderno ──────────────────
            //
            // 🟢 ESTE RESUELVE EL EXP. 0081. La página 169 decía «Iglesia
            // Congregacional» y nada más; acá aparece con nombre, lote y
            // valor: la representa Gelmi Humberto Orellana Estévez y compró el
            // lote 7 del bloque H a 48 meses. No es una donación ni un
            // apartado: es una venta normal a nombre de una persona.
            [
                'expediente' => 98,
                'fecha'      => '2026-07-14',
                'cliente'    => [
                    'nombre'   => 'GELMI HUMBERTO ORELLANA ESTÉVEZ',
                    'dni'      => '0412197700082',
                    'telefono' => '9759-1091',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '7', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 14,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL P.',
                'recibo_prima'  => 46,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 203. '
                    .'Observación del cuaderno: «Representa a Iglesia Congregacional». '
                    .'Es el expediente que le da contenido al 0081, que estaba en blanco.',
                'pagos' => [],
            ],

            // ── Exp. 0099 — página 205 del cuaderno ──────────────────
            [
                'expediente' => 99,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'SANDRA MARITZA MEJÍA BRIZUELA',
                    'dni'      => '0412197200124',
                    'telefono' => '9915-3985',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DIONEL PINTO',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL P.',
                'recibo_prima'  => 30,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 205.',
                'pagos'         => [
                ],
            ],

            // ── Exp. 0100 — página 207 del cuaderno ──────────────────
            //
            // 🔴 NOVENO LOTE IRREGULAR CON PRECIO. El M-6 mide 466.7700 vr² y
            // se vendió en L 466,000.00: **L 998.35 por vara²**.
            //
            // ⚠️ El cuaderno escribe «Lote #6 Bloque M/N 6/11» y más abajo
            // «Bloque N/11 pendiente»: el N-11 todavía NO se compró. Solo
            // entra el M-6, y el valor de L 466,000.00 es de ese lote solo —lo
            // confirma que la cuota de L 9,500.00 sale de él—.
            [
                'expediente' => 100,
                'fecha'      => '2026-07-10',
                'cliente'    => [
                    'nombre'   => 'MIGUEL ÁNGEL LÓPEZ TORRES',
                    'dni'      => '0412196700167',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'M', 'numero' => '6', 'valor' => '466000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 10,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 44,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 207. '
                    .'El M-6 es irregular (466.7700 vr²): L 998.35 por vara². '
                    .'⚠️ El cuaderno anota «Bloque N/11 pendiente»: ese lote todavía no se '
                    .'compró y no entra en la venta.',
                'pagos' => [],
            ],

            // ── Exp. 0101 — página 209 del cuaderno ──────────────────
            //
            // Dos lotes de bloques distintos, cada uno con su cuenta de
            // L 250,000.00 y su prima de L 10,000.00.
            //
            // ⚠️ LAS DOS PRIMAS ESTAN FECHADAS ANTES DEL CONTRATO: el 19/06 y
            // el 08/06, contra un contrato del 07/07. Se cargan con la fecha
            // del contrato, que es lo único que el sistema admite (R5: la
            // prima se paga y ahí se firma). PENDIENTE de aclarar.
            [
                'expediente' => 101,
                'fecha'      => '2026-07-07',
                'cliente'    => [
                    'nombre'   => 'OMAR YOVANY MARTÍNEZ CHÉVEZ',
                    'dni'      => '1617198400424',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '2',  'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'E', 'numero' => '12', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '20000.00',
                'vendedor'      => 'YOLANI MALDONADO',
                'plazo'         => 48,
                'dia_pago'      => 7,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 59,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 209. '
                    .'⚠️ El cuaderno fecha las dos primas el 19/06 y el 08/06, antes del contrato '
                    .'del 07/07. Se cargaron con la fecha del contrato. PENDIENTE de aclarar. '
                    .'Uno de los dos recibos no trae número («N/A»). Recibió Adonay Espinoza. '
                    .'⚠️ La vendedora Yolani Maldonado NO es Yunibex Maldonado, del exp. 0102: '
                    .'son dos personas distintas.',
                'pagos' => [
                    [
                        'recibo'        => 305,
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => 'A-2',
                        'observaciones' => 'Recibo 00000305 del talonario. Cuaderno: cuota julio.',
                    ],
                    [
                        'recibo'        => 301,
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => 'E-12',
                        'observaciones' => 'Recibo 00000301 del talonario. Cuaderno: cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0102 — página 211 del cuaderno ──────────────────
            //
            // ⚠️ YUNIBEX MALDONADO NO ES YOLANI MALDONADO. Comparten apellido y
            // las dos venden, pero son dos personas distintas — lo confirmó
            // Mauricio el 12-ago-2026. No unirlas.
            //
            // Y Yunibex es además la CLIENTA del exp. 0084: compra y vende.
            //
            // El cuaderno anota el recibo de la prima como «N/A», así que va
            // sin número del talonario.
            [
                'expediente' => 102,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'RUDY NORMAN ALVARADO BONILLA',
                    'dni'      => '0413197800668',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'YUNIBEX MALDONADO',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 5000,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 211.',
                'pagos'         => [
                    [
                        'recibo'        => 50,
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000050 del talonario. Cuaderno: cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0103 — página 213 del cuaderno ──────────────────
            [
                'expediente' => 103,
                'fecha'      => '2026-07-16',
                'cliente'    => [
                    'nombre'   => 'EDIN GERARDO PÉREZ GARCÍA',
                    'dni'      => '1406200400001',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 311,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 213.',
                'pagos'         => [
                ],
            ],

            // ── Exp. 0104 — página 215 del cuaderno ──────────────────
            //
            // 🔴 DECIMO Y UNDECIMO LOTES IRREGULARES CON PRECIO, y son casi
            // gemelos: el U-6 mide 226.8100 vr² y el W-3 226.8200, y los dos
            // se vendieron en L 226,000.00 — L 996.43 y L 996.38 por vara².
            //
            // El segundo lote se compró QUINCE DIAS DESPUES de firmar, y el
            // cuaderno lo anota al margen: «Adquirió 2º lote en Bloque W lote
            // #3». Va en la misma venta, con su propia prima.
            [
                'expediente' => 104,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'JONATAN RAMÍREZ ACOSTA',
                    'dni'      => '0412198700077',
                    'telefono' => '9626-9788',
                ],
                'lotes' => [
                    ['bloque' => 'U', 'numero' => '6', 'valor' => '226000.00', 'prima' => '10000.00'],
                    ['bloque' => 'W', 'numero' => '3', 'valor' => '226000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '20000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA — ADONAY E.',
                'recibo_prima'  => 31,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 215. '
                    .'El W-3 se adquirió el 28/07, quince días después de firmar, con su propia '
                    .'prima de L 10,000.00 (recibo 00000348). El sistema lleva una sola fecha '
                    .'por venta, así que los dos quedan fechados el 13/07.',
                'pagos' => [],
            ],

            // ── Exp. 0105 — página 217 del cuaderno ──────────────────
            [
                'expediente' => 105,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'AMINTA RODRÍGUEZ GUTIÉRREZ',
                    'dni'      => '0607198900226',
                    'telefono' => '9533-9683',
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '1', 'valor' => '250000.00'],
                    ['bloque' => 'V', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA — DIONEL P.',
                'recibo_prima'  => 33,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 217.',
                'pagos'         => [
                ],
            ],

            // ── Exp. 0106 — página 219 del cuaderno ──────────────────
            [
                'expediente' => 106,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'ELDER ISAÚ PERDOMO PAZ',
                    'dni'      => '1620198200178',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 34,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 219. Recibió Adonay E.',
                'pagos'         => [],
            ],

            // ── Exp. 0107 — página 221 del cuaderno ──────────────────
            [
                'expediente' => 107,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'JOSÉ HUMBERTO GARCÍA ZELAYA',
                    'dni'      => '1406196100040',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 35,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 221. Recibió Adonay E.',
                'pagos'         => [],
            ],

            // ── Exp. 0108 — página 223 del cuaderno ──────────────────
            [
                'expediente' => 108,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'LEDY DAMARY MARTÍNEZ LEONOR',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '50000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA — ADONAY E.',
                'recibo_prima'  => 37,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 223. Prima de L 50,000.00 —cinco veces la de siempre—, así que la cuota baja a L 4,166.67. El cuaderno no anota el DNI.',
                'pagos'         => [],
            ],

            // ── Exp. 0109 — página 225 del cuaderno ──────────────────
            [
                'expediente' => 109,
                'fecha'      => '2026-07-21',
                'cliente'    => [
                    'nombre'   => 'OSCAR MIGUEL PORTILLO MEDINA',
                    'dni'      => '1627200500029',
                    'telefono' => '9827-3241',
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '7', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 21,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — DIONEL P.',
                'recibo_prima'  => 329,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 225.',
                'pagos'         => [],
            ],

            // ── Exp. 0110 — página 227 del cuaderno ──────────────────
            //
            // Dos lotes de 337.5000 vr² a L 337,000.00 cada uno: L 998.52 por
            // vara², exactamente el mismo precio que el W-5 del exp. 0085.
            //
            // Con este, el grupo de 337.50 lleva CUATRO precios distintos:
            // L 325,000.00 (exp. 0028), L 324,000.00 (0095) y L 337,000.00
            // (0085 y este). Cada lote se negocia, y ni siquiera la medida
            // manda.
            [
                'expediente' => 110,
                'fecha'      => '2026-07-25',
                'cliente'    => [
                    'nombre'   => 'ELVA MARINA ORTIZ SANTAMARÍA',
                    'dni'      => '0402198700162',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '10', 'valor' => '337000.00'],
                    ['bloque' => 'H', 'numero' => '11', 'valor' => '337000.00'],
                ],
                'prima'         => '60000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 25,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA — DIONEL P.',
                'recibo_prima'  => 341,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 227. '
                    .'Los dos lotes del bloque H son de 337.5000 vr² y van a L 337,000.00 cada '
                    .'uno: L 998.52 por vara².',
                'pagos' => [],
            ],

            // ── Exp. 0111 — página 229 del cuaderno ──────────────────
            [
                'expediente' => 111,
                'fecha'      => '2026-07-25',
                'cliente'    => [
                    'nombre'   => 'DANIS NOHEMÍ GARCÍA SANTOS',
                    'dni'      => '0412198900308',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '13', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 25,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 342,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 229. Recibió Dionel P.',
                'pagos'         => [],
            ],

            // ── Exp. 0112 — página 231 del cuaderno ──────────────────
            //
            // 🔴 DECIMOSEGUNDO LOTE IRREGULAR CON PRECIO. El T-8 mide 450.4700
            // vr² y se vendió en L 450,000.00: **L 998.96 por vara²**.
            //
            // ⚠️ EL CUADERNO USA EL NUMERO 0112 DOS VECES: acá y en la página
            // 233 (Angie Karolina Aguilar Ramírez). Y no hay ningún 0114. Este
            // se cargó como 0112 porque es el primero de los dos en el
            // cuaderno; el de la página 233 queda afuera hasta saber cuál es
            // su número, porque dos contratos no pueden llevar el mismo.
            [
                'expediente' => 112,
                'fecha'      => '2026-07-27',
                'cliente'    => [
                    'nombre'   => 'OBDULIA MORENO PAZ',
                    'dni'      => '0412195400060',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '8', 'valor' => '450000.00'],
                ],
                'prima'         => '18000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 345,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 231. '
                    .'El T-8 es irregular (450.4700 vr²): L 998.96 por vara². '
                    .'⚠️ El cuaderno usa el número 0112 dos veces: acá y en la página 233. '
                    .'PENDIENTE de aclarar cuál de los dos es el 0114, que no aparece.',
                'pagos' => [],
            ],

            // ── Exp. 0115 — página 237 del cuaderno ──────────────────
            //
            // ⚠️ LA PRIMA ES ANTERIOR AL CONTRATO, Y EL CUADERNO EXPLICA POR
            // QUE. Dice en rojo: «La prima se generó fecha 18 de junio a
            // nombre de Jony Gerson García Melgar; por acuerdo interno se
            // registra fecha 03/08/2026 al señor Víctor Manuel».
            //
            // O sea que el lote lo tenía el vendedor y se le traspasó al
            // cliente. El sistema no sabe representar eso: lleva una fecha por
            // venta y es la del contrato, así que la prima queda fechada el
            // 03/08. La explicación va completa en las observaciones, que es
            // donde alguien va a buscar por qué el recibo 0070 dice junio.
            [
                'expediente' => 115,
                'fecha'      => '2026-08-03',
                'cliente'    => [
                    'nombre'   => 'VÍCTOR MANUEL MEJÍA',
                    'dni'      => '0412195200139',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '7', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 70,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 237. '
                    .'Observación del cuaderno: «La Prima se generó fecha 18 de junio a nombre de '
                    .'Jony Gerson García Melgar, por acuerdo interno se registra fecha 03/08/2026 '
                    .'al señor Víctor Manuel». El recibo 0070 del talonario está fechado el '
                    .'18/06/2026; el sistema lo registra el día del contrato.',
                'pagos' => [],
            ],

            // ═══════════════════════════════════════════════════════════
            // EL PRIMER CUADERNO — expedientes 0001 a 0015
            //
            // ⚠️ Estos quince entran TAL CUAL los escribió el cuaderno, con
            // sus contradicciones adentro. Decisión de Mauricio del
            // 12-ago-2026: la contratante los va a corregir al final sobre la
            // planilla, así que cargar es más útil que discutir. Lo único que
            // se detiene es un número de expediente repetido, porque eso la
            // base no lo admite.
            // ═══════════════════════════════════════════════════════════

            // ── Exp. 0001 — página 9 del cuaderno ──────────────────
            //
            // ⚠️ El L-7 es irregular (435.8800 vr²). Repartido en mitades da
            // L 573.55 por vara², muy por debajo de la banda de los mil donde
            // caen los otros doce irregulares. Igual que el O-6 del exp. 0033
            // y el N-8 del 0070: los tres salen de despejar un total.
            [
                'expediente' => 1,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'LETICIA ROMERO',
                    'dni'      => '1607196500216',
                    'telefono' => '9398-1534',
                ],
                'lotes' => [
                    ['bloque' => 'L', 'numero' => '7', 'valor' => '250000.00'],
                    ['bloque' => 'L', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'vendedor'      => 'ABIGAIL ORELLANA',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 1,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 9. El cuaderno anota área 500 vr² pero el L-7 mide 435.8800 vr² en el plano y el L-8 mide 250: son 685.88. El reparto de L 250,000.00 por lote es deducción, PENDIENTE de confirmar.',
                'pagos'         => [
                ],
            ],

            // ── Exp. 0002 — página 11 del cuaderno ──────────────────
            [
                'expediente' => 2,
                'fecha'      => '2026-06-04',
                'cliente'    => [
                    'nombre'   => 'CARLOS OBED DÍAZ',
                    'dni'      => '0412198600261',
                    'telefono' => '8919-1396',
                ],
                'lotes' => [
                    ['bloque' => 'L', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'ABIGAIL ORELLANA',
                'plazo'         => 48,
                'dia_pago'      => 4,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 3,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 11.',
                'pagos'         => [
                    [
                        'recibo'        => 1,
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000001 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0003 — página 13 del cuaderno ──────────────────
            [
                'expediente' => 3,
                'fecha'      => '2026-06-05',
                'cliente'    => [
                    'nombre'   => 'ANDY FRANGKIN AGUILAR MANCÍA',
                    'dni'      => '0412199400237',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '12', 'valor' => '250000.00'],
                    ['bloque' => 'O', 'numero' => '13', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 5,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 4,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 13. El tercer renglón del historial no trae concepto: son L 50,000.00 que se cargaron como abono a capital.',
                'pagos'         => [
                    [
                        'recibo'        => 5,
                        'fecha'         => '2026-06-05',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 0005 del talonario. Cuota julio, pagada el mismo día de firmar.',
                    ],
                    [
                        'recibo'        => 67,
                        'fecha'         => '2026-06-16',
                        'tipo'          => 'abono',
                        'monto'         => '50000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 0067 del talonario. El cuaderno no anota concepto; por el monto es un abono a capital.',
                    ],
                ],
            ],

            // ── Exp. 0004 — página 15 del cuaderno ──────────────────
            //
            // 🔴 DECIMOTERCER LOTE IRREGULAR CON PRECIO. El J-7 mide 436.9700
            // vr² y se vendió en L 436,000.00: **L 997.78 por vara²**, otra vez
            // en la banda. Y este SI lo escribió ella, no lo deduje yo.
            [
                'expediente' => 4,
                'fecha'      => '2026-06-05',
                'cliente'    => [
                    'nombre'   => 'JOSÉ FRANCISCO MELGAR LÓPEZ',
                    'dni'      => '0105199100428',
                    'telefono' => '9691-5342',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'N', 'numero' => '14', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'J', 'numero' => '7', 'valor' => '436000.00', 'prima' => '18400.00'],
                ],
                'prima'         => '38400.00',
                'vendedor'      => 'ABIGAIL ORELLANA',
                'plazo'         => 48,
                'dia_pago'      => 5,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 6,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 15. Observación del cuaderno: «Adquirió un 2º lote en Bloque J - Lote 7, valor L 436,000.00». Entró el 06/07 con su propia prima de L 18,400.00 (recibo 0104); el sistema lleva una sola fecha por venta, así que los tres quedan fechados el 05/06.',
                'pagos'         => [
                ],
            ],

            // ── Exp. 0005 — página 17 del cuaderno ──────────────────
            [
                'expediente' => 5,
                'fecha'      => '2026-06-06',
                'cliente'    => [
                    'nombre'   => 'CATALINO DÍAZ',
                    'dni'      => '0306198100683',
                    'telefono' => '9878-4674',
                ],
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'plazo'         => 48,
                'dia_pago'      => 6,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 7,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 17.',
                'pagos'         => [
                    [
                        'recibo'        => 96,
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0096 del talonario. Cuota julio.',
                    ],
                    [
                        'recibo'        => 380,
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000380 del talonario. Cuota agosto.',
                    ],
                ],
            ],

            // ── Exp. 0006 — página 19 del cuaderno ──────────────────
            //
            // 🔴 EL PRIMER DESCUENTO POR PRIMA GRANDE, Y EL PRIMER PLAZO DE 24
            // MESES. El cuaderno tacha los 48 y escribe «24 meses / 1 año».
            //
            // ⚠️ Y su cuota está mal calculada: L 19,583.00 es 470,000 ÷ 24,
            // o sea el valor sin restarle la prima. El saldo de verdad son
            // L 370,000.00, que a 24 meses dan L 15,416.67. Se cargó el valor
            // y el sistema saca la cuota correcta.
            [
                'expediente' => 6,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'DUBLAS JOSSUÉ ESTÉVEZ LÓPEZ',
                    'dni'      => '0412199900174',
                    'telefono' => '9566-8958',
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '1', 'valor' => '235000.00'],
                    ['bloque' => 'O', 'numero' => '2', 'valor' => '235000.00'],
                ],
                'prima'         => '100000.00',
                'plazo'         => 24,
                'dia_pago'      => 8,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 8,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 19. Observación del cuaderno: «Por prima inicial de L 100,000 se autoriza descuento de L 30,000.00. El valor original de L 500,000.00 se ajusta a L 470,000». ⚠️ El cuaderno anota cuota de L 19,583.00, que sale de dividir el VALOR entre 24 sin restar la prima; el saldo real de L 370,000.00 a 24 meses da L 15,416.67.',
                'pagos'         => [
                    [
                        'recibo'        => 10,
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '8046.00',
                        'forma'         => 'remesa',
                        'referencia'    => 'REMESA — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000010 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0007 — página 21 del cuaderno ──────────────────
            [
                'expediente' => 7,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'RUFINO AGUILAR RODRÍGUEZ',
                    'dni'      => '0412196300121',
                    'telefono' => '9381-3709',
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '11', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'P', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 8,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 9,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 21. El cuaderno anota los dos lotes por separado: el O-11 el 08/06 y el P-1 el 13/06, con su propia prima cada uno (recibos 0009 y 0020). El sistema emite un solo recibo de prima.',
                'pagos'         => [
                    [
                        'recibo'        => 98,
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => 'P-1',
                        'observaciones' => 'Recibo 0098 del talonario. Cuota julio del lote 1 del bloque P.',
                    ],
                    [
                        'recibo'        => 102,
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO DE OCCIDENTE — DIONEL PINTO',
                        'lote'          => 'O-11',
                        'observaciones' => 'Recibo 0102 del talonario. Cuota julio del lote 11 del bloque O.',
                    ],
                    [
                        'recibo'        => 374,
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => 'P-1',
                        'observaciones' => 'Recibo 00000374 del talonario. Cuota agosto del lote 1 del bloque P.',
                    ],
                ],
            ],

            // ── Exp. 0008 — página 23 del cuaderno ──────────────────
            [
                'expediente' => 8,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'PABLO ANTONIO GARCÍA',
                    'dni'      => '0412196000069',
                    'telefono' => '9642-5153',
                ],
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '9', 'valor' => '250000.00'],
                    ['bloque' => 'C', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 8,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 10,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 23.',
                'pagos'         => [
                    [
                        'recibo'        => 97,
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0097 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0009 — página 25 del cuaderno ──────────────────
            [
                'expediente' => 9,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'SANTIAGO GARCÍA MELGAR',
                    'dni'      => '0412198700279',
                    'telefono' => '3314-2897',
                ],
                'lotes' => [
                    ['bloque' => 'L', 'numero' => '4', 'valor' => '250000.00'],
                    ['bloque' => 'L', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => 11,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 25.',
                'pagos'         => [
                    [
                        'recibo'        => 109,
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0109 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0010 — página 27 del cuaderno ──────────────────
            [
                'expediente' => 10,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'LUIS DAVID TRIGUEROS MANCÍA',
                    'dni'      => '0412200300155',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'L', 'numero' => '10', 'valor' => '250000.00'],
                    ['bloque' => 'L', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY PEÑA',
                'recibo_prima'  => 12,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 27.',
                'pagos'         => [
                    [
                        'recibo'        => 110,
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ADONAY ESPINOZA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0110 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0011 — página 29 del cuaderno ──────────────────
            [
                'expediente' => 11,
                'fecha'      => '2026-06-05',
                'cliente'    => [
                    'nombre'   => 'KAREN YESSENIA BRIZUELA',
                    'dni'      => '0412198400267',
                    'telefono' => '9670-7951',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 5,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 13,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 29.',
                'pagos'         => [
                    [
                        'recibo'        => 103,
                        'fecha'         => '2026-07-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0103 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0012 — página 31 del cuaderno ──────────────────
            [
                'expediente' => 12,
                'fecha'      => '2026-06-11',
                'cliente'    => [
                    'nombre'   => 'BESSY ONDINA LANDAVERDE',
                    'dni'      => '0412197900249',
                    'telefono' => '9359-7661',
                ],
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 11,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 14,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 31.',
                'pagos'         => [
                    [
                        'recibo'        => 11,
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000011 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0013 — página 33 del cuaderno ──────────────────
            [
                'expediente' => 13,
                'fecha'      => '2026-06-11',
                'cliente'    => [
                    'nombre'   => 'ADELA DÍAZ HERNÁNDEZ',
                    'dni'      => '0306197300260',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '5', 'valor' => '250000.00'],
                    ['bloque' => 'D', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 11,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 15,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 33.',
                'pagos'         => [
                    [
                        'recibo'        => 4,
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000004 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0014 — página 35 del cuaderno ──────────────────
            [
                'expediente' => 14,
                'fecha'      => '2026-06-11',
                'cliente'    => [
                    'nombre'   => 'SERGIO DAVID TRIGUEROS TORRES',
                    'dni'      => '0412199900208',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '3', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 11,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 16,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 35. ⚠️ El cuaderno fecha la cuota el 11/09/2026, que todavía no llegó. Se cargó como 11/07/2026: el concepto dice «cuota julio» y el sistema no admite un recibo con fecha futura.',
                'pagos'         => [
                    [
                        'recibo'        => 35,
                        'fecha'         => '2026-07-11',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 0035 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0015 — página 37 del cuaderno ──────────────────
            [
                'expediente' => 15,
                'fecha'      => '2026-06-11',
                'cliente'    => [
                    'nombre'   => 'ERICK GEOVANY MEJÍA PÉREZ',
                    'dni'      => '0501200214566',
                    'telefono' => '8731-3699',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 11,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 17,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 37.',
                'pagos'         => [
                    [
                        'recibo'        => 327,
                        'fecha'         => '2026-07-21',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000327 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0016 — página 39 del cuaderno ──────────────────
            //
            // 🔴 DECIMOCUARTO LOTE IRREGULAR CON PRECIO. El K-6 mide 428.9800
            // vr² y se vendió en L 428,000.00: **L 997.72 por vara²**. Otro
            // más en la banda, y otro que escribió ella directo.
            //
            // Y es el primer plazo de CUARENTA meses: L 400,000.00 de saldo
            // entre 40 dan los L 10,000.00 de cuota que anota el cuaderno,
            // clavados. Ya van tres plazos distintos —24, 40 y 48—, así que
            // el plazo tampoco es una constante del negocio.
            [
                'expediente' => 16,
                'fecha'      => '2026-06-05',
                'cliente'    => [
                    'nombre'   => 'ARNULFO TRIGUEROS SAAVEDRA',
                    'dni'      => '0412196500153',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '6', 'valor' => '428000.00'],
                ],
                'prima'         => '28000.00',
                'plazo'         => 40,
                'dia_pago'      => 5,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 19,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 39. El K-6 es irregular (428.9800 vr²): L 997.72 por vara². Recibió Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => 6,
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000006 del talonario. Cuota julio.',
                    ],
                    [
                        'recibo'        => 375,
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000375 del talonario. Cuota agosto.',
                    ],
                ],
            ],

            // ── Exp. 0017 — página 41 del cuaderno ──────────────────
            [
                'expediente' => 17,
                'fecha'      => '2026-06-13',
                'cliente'    => [
                    'nombre'   => 'BRENDA YANETH RIVERA R.',
                    'dni'      => '0406198200022',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '7', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 21,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 41. Prima recibida por Adonay Peña.',
                'pagos'         => [
                    [
                        'recibo'        => 5,
                        'fecha'         => '2026-07-15',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000005 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0018 — página 43 del cuaderno ──────────────────
            [
                'expediente' => 18,
                'fecha'      => '2026-06-13',
                'cliente'    => [
                    'nombre'   => 'MILEYDI GARCÍA',
                    'dni'      => '0412198100052',
                    'telefono' => '3360-8761',
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '7', 'valor' => '250000.00'],
                    ['bloque' => 'D', 'numero' => '8', 'valor' => '250000.00'],
                    ['bloque' => 'D', 'numero' => '9', 'valor' => '250000.00'],
                    ['bloque' => 'D', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '40000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 22,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 43. Cuatro lotes seguidos del bloque D. Prima recibida por Adonay Peña.',
                'pagos'         => [
                    [
                        'recibo'        => 27,
                        'fecha'         => '2026-07-10',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000027 del talonario. Cuota julio.',
                    ],
                ],
            ],

            // ── Exp. 0019 — página 45 del cuaderno ──────────────────
            [
                'expediente' => 19,
                'fecha'      => '2026-06-14',
                'cliente'    => [
                    'nombre'   => 'ERLIN ADONAY RAMÍREZ RAMOS',
                    'dni'      => '0412199800325',
                    'telefono' => '3146-7673',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 14,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => 23,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 45. Prima recibida por Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => 101,
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA BANCO DE OCCIDENTE — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0101 del talonario. Cuota julio.',
                    ],
                    [
                        'recibo'        => 363,
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000363 del talonario. Cuota agosto.',
                    ],
                ],
            ],

            // ── Exp. 0020 — página 47 del cuaderno ──────────────────
            [
                'expediente' => 20,
                'fecha'      => '2026-06-14',
                'cliente'    => [
                    'nombre'   => 'ISIS YOSMERI BRIZUELA GUERRA',
                    'dni'      => '0412200700202',
                    'telefono' => '9697-1348',
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 14,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => 24,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 47.',
                'pagos'         => [
                    [
                        'recibo'        => 29,
                        'fecha'         => '2026-07-15',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000029 del talonario. Cuota julio.',
                    ],
                ],
            ],

        ];
    }
}
