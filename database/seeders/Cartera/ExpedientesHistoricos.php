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
 * 🔴 **LOS CEROS SON PARTE DEL NUMERO. `recibo` es un STRING, no un int.**
 *
 * El cuaderno lleva DOS talonarios: uno de primas, con números de cuatro
 * dígitos —0018, 0049, 0075—, y uno de cuotas, con ocho —00000049,
 * 00000314—. **El 0049 corto y el 00000049 largo NO son el mismo recibo.** Lo
 * dijo Mauricio el 23-ago-2026: «si tiene 00001 es un número, si tiene 0001 es
 * otro; fueron distintos talonarios».
 *
 * Convertirlos a entero los volvía iguales y fabricaba **62 choques que no
 * existen**. Contra el texto exacto quedan **11**, y esos sí son repeticiones
 * del propio cuaderno —dos páginas con el mismo número—, que se dejan como
 * están: es lo que dice el papel. `convertir_cartera.py` los lista al generar
 * el archivo para que alguien los mire.
 *
 * Guardarlo como string tiene un segundo premio: entran sin pelea los que no
 * son enteros —«0075-1», «0111-1», «0114-1»—. Antes se quedaban sin número por
 * no poder representarse, y quitarles el guión los habría inventado: «0075-1»
 * sin guiones es 751, que es el recibo de otra persona.
 *
 * 🔴 Nada de esto toca la base. El seeder **no escribe este número** en
 * `recibos.numero` —numera de corrido—, así que un repetido acá no rebota
 * contra el índice único. Es transcripción, no instrucción.
 *
 * ⚠️ Lo que sigue sin resolverse es un número ILEGIBLE del talonario largo —el
 * exp. 0048 tiene dos—: ahí hace falta mirar el papel.
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
 *   'recibo'      → string|null, el número del talonario TAL COMO SE ESCRIBE:
 *                   «00000053», «0049», «0075-1». Los ceros van porque son
 *                   parte del número. En null solo si el cuaderno no lo anotó.
 *   'fecha'       → Y-m-d, cuándo entró el dinero.
 *   'tipo'        → 'cuota' o 'abono'. El abono reprograma el plan (R21).
 *   'monto'       → string. Nunca float (§8.3.1).
 *   'forma'       → efectivo · transferencia · deposito · tarjeta.
 *   'referencia'  → la del banco. Obligatoria en todo lo que no es efectivo
 *                   (R11), así que **nunca va en null ahí**. Cuando el cuaderno
 *                   no trae el número —117 de 142 pagos— dice lo que el papel
 *                   sí dice: «DEPÓSITO — ELDER DIONEL PINTO MOLINA», o
 *                   «REMESA — SIN NÚMERO DE CONTROL EN EL CUADERNO» si nadie
 *                   quedó anotado. **No se inventa un número de banco**, y
 *                   nadie lo puede confundir con uno. Es la misma forma que
 *                   usaba la transcripción vieja, cuando esa columna del
 *                   cuaderno traía las dos cosas juntas; la plantilla nueva las
 *                   partió en «Referencia del banco» y «Quién lo recibió», y el
 *                   conversor las vuelve a juntar. El día que aparezcan los
 *                   comprobantes se reemplazan por los de verdad.
 *   'lote'        → «bloque-numero», a qué lote se aplica. Null = a todos, en
 *                   el reparto que el sistema decida.
 *   'lotes'       → cuando el pago va a VARIOS lotes pero no a todos. Dos
 *                   formas: `['J-1', 'J-2']` dice CUALES —y el monto se reparte
 *                   proporcional entre esos— y `['J-1' => '5000.00']` dice
 *                   CUANTO a cada uno, y manda. La primera es la que sale del
 *                   cuaderno; la columna «¿A qué lote?» dice «1 y 14 N».
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
            // ── Exp. 0001 ──────────────────────────────────────────
            [
                'expediente' => 1,
                'fecha'      => '2026-08-07',
                'cliente'    => [
                    'nombre'   => 'MARIA EMORGELIA RODRIGUEZ NAJERA',
                    'dni'      => '0412196000165',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'L', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 7,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000389',
                'observaciones' => '',
                'pagos'         => [],
            ],

            // ── Exp. 0002 ──────────────────────────────────────────
            [
                'expediente' => 2,
                'fecha'      => '2026-06-04',
                'cliente'    => [
                    'nombre'   => 'CARLOS OBED DÍAZ',
                    'dni'      => '0412199600261',
                    'telefono' => '89191396',
                ],
                'lotes' => [
                    ['bloque' => 'L', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 4,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0003',
                'vendedor'      => 'ABIGAIL ORELLANA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 11.',
                'pagos'         => [
                    [
                        'recibo'        => '00000001',
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000001 del talonario. Cuaderno: Cuota julio. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000053',
                        'fecha'         => '2026-08-09',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000053 del talonario. Cuaderno: Cuota agosto. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0003 ──────────────────────────────────────────
            [
                'expediente' => 3,
                'fecha'      => '2026-06-05',
                'cliente'    => [
                    'nombre'   => 'ANDY FRANGKIN AGUILAR MANCÍA',
                    'dni'      => '0412199400237',
                    'telefono' => '99292324',
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '12', 'valor' => '250000.00'],
                    ['bloque' => 'O', 'numero' => '13', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 5,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0004',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => '',
                'pagos'         => [
                    [
                        'recibo'        => '00000005',
                        'fecha'         => '2026-06-05',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000005 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000067',
                        'fecha'         => '2026-06-16',
                        'tipo'          => 'abono',
                        'monto'         => '50000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000067 del talonario. Cuaderno: Abono a capital. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0004 ──────────────────────────────────────────
            [
                'expediente' => 4,
                'fecha'      => '2026-06-05',
                'cliente'    => [
                    'nombre'   => 'JOSÉ FRANCISCO MELGAR LÓPEZ',
                    'dni'      => '0105199100428',
                    'telefono' => '96915342',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'N', 'numero' => '14', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'J', 'numero' => '7', 'valor' => '436000.00', 'prima' => '18400.00'],
                ],
                'prima'         => '38400.00',
                'plazo'         => 48,
                'dia_pago'      => 5,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0006',
                'vendedor'      => 'ABIGAIL ORELLANA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 15. Observación del cuaderno: «Adquirió un 2º lote en Bloque J - Lote 7, valor L 436,000.00». Entró el 06/07 con su propia prima de L 18,400.00 (recibo 0104); el sistema lleva una sola fecha por venta, así que los tres quedan fechados el 05/06.',
                'pagos'         => [
                    [
                        'recibo'        => '0000385',
                        'fecha'         => '2026-08-06',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => '2608060118852000',
                        'lotes'         => ['N-1', 'N-14'],
                        'observaciones' => 'Recibo 0000385 del talonario. Cuaderno: Cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000386',
                        'fecha'         => '2026-08-06',
                        'tipo'          => 'cuota',
                        'monto'         => '8700.00',
                        'forma'         => 'deposito',
                        'referencia'    => '2608060118852000',
                        'lote'          => 'J-7',
                        'observaciones' => 'Recibo 00000386 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0005 ──────────────────────────────────────────
            [
                'expediente' => 5,
                'fecha'      => '2026-06-06',
                'cliente'    => [
                    'nombre'   => 'CATALINO DÍAZ',
                    'dni'      => '0306198100683',
                    'telefono' => '98784674',
                ],
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 6,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0007',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 17.',
                'pagos'         => [
                    [
                        'recibo'        => '00000096',
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000096 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000380',
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000380 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0006 ──────────────────────────────────────────
            [
                'expediente' => 6,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'DUBLAS JOSSUÉ ESTÉVEZ LÓPEZ',
                    'dni'      => '0412199900174',
                    'telefono' => '95668958',
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '1', 'valor' => '235000.00', 'valor_lista' => '250000.00', 'motivo' => 'POR ABONO DE 100000 INICIAL'],
                    ['bloque' => 'O', 'numero' => '2', 'valor' => '235000.00', 'valor_lista' => '250000.00', 'motivo' => 'POR ABONO DE 100000 INICIAL'],
                ],
                'prima'         => '100000.00',
                'plazo'         => 24,
                'dia_pago'      => 8,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0008',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 19. Observación del cuaderno: «Por prima inicial de L 100,000 se autoriza descuento de L 30,000.00. El valor original de L 500,000.00 se ajusta a L 470,000».',
                'pagos'         => [
                    [
                        'recibo'        => '00000010',
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '8046.00',
                        'forma'         => 'remesa',
                        'referencia'    => 'REMESA — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000010 del talonario. Cuaderno: Cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000060',
                        'fecha'         => '2026-08-11',
                        'tipo'          => 'abono',
                        'monto'         => '18767.00',
                        'forma'         => 'remesa',
                        'referencia'    => 'REMESA — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000060 del talonario. Cuaderno: ABONO A CAPITAL. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0007 ──────────────────────────────────────────
            [
                'expediente' => 7,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'RUFINO AGUILAR RODRÍGUEZ',
                    'dni'      => '0412196300121',
                    'telefono' => '93813709',
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '11', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'P', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 8,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0009',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 21. El cuaderno anota los dos lotes por separado: el O-11 el 08/06 y el P-1 el 13/06, con su propia prima cada uno (recibos 0009 y 0020). El sistema emite un solo recibo de prima.',
                'pagos'         => [
                    [
                        'recibo'        => '0098',
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => 'P-1',
                        'observaciones' => 'Recibo 0098 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '0102',
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => 'O-11',
                        'observaciones' => 'Recibo 0102 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000374',
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => 'P-1',
                        'observaciones' => 'Recibo 00000374 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000383',
                        'fecha'         => '2026-08-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => 'O-11',
                        'observaciones' => 'Recibo 00000383 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0008 ──────────────────────────────────────────
            [
                'expediente' => 8,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'PABLO ANTONIO GARCÍA',
                    'dni'      => '0412196000069',
                    'telefono' => '99393675',
                ],
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '9', 'valor' => '250000.00'],
                    ['bloque' => 'C', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 8,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0010',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 23.',
                'pagos'         => [
                    [
                        'recibo'        => '0097',
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0097 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000398',
                        'fecha'         => '2026-08-08',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000398 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0009 ──────────────────────────────────────────
            [
                'expediente' => 9,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'SANTIAGO GARCÍA MELGAR',
                    'dni'      => '0412198700279',
                    'telefono' => '33142897',
                ],
                'lotes' => [
                    ['bloque' => 'L', 'numero' => '4', 'valor' => '250000.00'],
                    ['bloque' => 'L', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ ADONAY ESPINOZA — RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0011',
                'vendedor'      => 'JONY GERSON GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 25.',
                'pagos'         => [
                    [
                        'recibo'        => '0109',
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0109 del talonario. Cuaderno: Cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0010 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0012',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 27.',
                'pagos'         => [
                    [
                        'recibo'        => '0110',
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0110 del talonario. Cuaderno: Cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0011 ──────────────────────────────────────────
            [
                'expediente' => 11,
                'fecha'      => '2026-06-05',
                'cliente'    => [
                    'nombre'   => 'KAREN YESSENIA BRIZUELA',
                    'dni'      => '0412198400267',
                    'telefono' => '96707951',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 5,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0013',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 29.',
                'pagos'         => [
                    [
                        'recibo'        => '0103',
                        'fecha'         => '2026-07-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0103 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000381',
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000381 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0012 ──────────────────────────────────────────
            [
                'expediente' => 12,
                'fecha'      => '2026-06-11',
                'cliente'    => [
                    'nombre'   => 'BESSY ONDINA LANDAVERDE',
                    'dni'      => '0412197900249',
                    'telefono' => '93597661',
                ],
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 11,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0014',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 31.',
                'pagos'         => [
                    [
                        'recibo'        => '00000011',
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000011 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000052',
                        'fecha'         => '2026-08-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000052 del talonario. Cuaderno: Cuota agosto. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0013 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0015',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 33.',
                'pagos'         => [
                    [
                        'recibo'        => '00000004',
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000004 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000063',
                        'fecha'         => '2026-08-11',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000063 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0014 ──────────────────────────────────────────
            [
                'expediente' => 14,
                'fecha'      => '2026-06-11',
                'cliente'    => [
                    'nombre'   => 'SERGIO DAVID TRIGUEROS TORRES',
                    'dni'      => '0412199900208',
                    'telefono' => '98272669',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '3', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 11,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0016',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => '',
                'pagos'         => [
                    [
                        'recibo'        => '0035',
                        'fecha'         => '2026-07-11',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 0035 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0015 ──────────────────────────────────────────
            [
                'expediente' => 15,
                'fecha'      => '2026-06-11',
                'cliente'    => [
                    'nombre'   => 'ERICK GEOVANY MEJÍA PÉREZ',
                    'dni'      => '0501200214566',
                    'telefono' => '87313699',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 11,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0017',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 37.',
                'pagos'         => [
                    [
                        'recibo'        => '00000327',
                        'fecha'         => '2026-07-21',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000327 del talonario. Cuaderno: Cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0016 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0019',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 39. El K-6 es irregular (428.9800 vr²): L 997.72 por vara². Recibió Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '00000006',
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000006 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000375',
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000375 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0017 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0021',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 41. Prima recibida por Adonay Peña.',
                'pagos'         => [
                    [
                        'recibo'        => '00000045',
                        'fecha'         => '2026-07-15',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000045 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000088',
                        'fecha'         => '2026-08-17',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000088 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0018 ──────────────────────────────────────────
            [
                'expediente' => 18,
                'fecha'      => '2026-06-13',
                'cliente'    => [
                    'nombre'   => 'MILEYDI GARCÍA',
                    'dni'      => '0412198100052',
                    'telefono' => '33608761',
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
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0022',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 43. Cuatro lotes seguidos del bloque D. Prima recibida por Adonay Espinoza',
                'pagos'         => [
                    [
                        'recibo'        => '00000027',
                        'fecha'         => '2026-07-10',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000027 del talonario. Cuaderno: Cuota julio. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000067',
                        'fecha'         => '2026-08-12',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000067 del talonario. Cuaderno: Cuota agosto. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0019 ──────────────────────────────────────────
            [
                'expediente' => 19,
                'fecha'      => '2026-06-14',
                'cliente'    => [
                    'nombre'   => 'ERLIN ADONAY RAMÍREZ RAMOS',
                    'dni'      => '0412199800325',
                    'telefono' => '31467673',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 14,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => '0023',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 45. Prima recibida por Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '0101',
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0101 del talonario. Cuaderno: Cuota julio. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000363',
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000363 del talonario. Cuaderno: Cuota. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0020 ──────────────────────────────────────────
            [
                'expediente' => 20,
                'fecha'      => '2026-06-14',
                'cliente'    => [
                    'nombre'   => 'ISIS YOSMERI BRIZUELA GUERRA',
                    'dni'      => '0412200700202',
                    'telefono' => '96971348',
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 14,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0024',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 47.',
                'pagos'         => [
                    [
                        'recibo'        => '00000039',
                        'fecha'         => '2026-07-15',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000039 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000081',
                        'fecha'         => '2026-08-16',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000081 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0021 ──────────────────────────────────────────
            [
                'expediente' => 21,
                'fecha'      => '2026-06-14',
                'cliente'    => [
                    'nombre'   => 'ERIN BELTRÁN GUERRA ACEVEDO',
                    'dni'      => '0412199500018',
                    'telefono' => '98806628',
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '1', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 14,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0025',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 49.',
                'pagos'         => [
                    [
                        'recibo'        => '00000029',
                        'fecha'         => '2026-07-12',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000029 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000054',
                        'fecha'         => '2026-08-10',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000054 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0022 ──────────────────────────────────────────
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
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0026',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 51. Observación: 15,000 : 0090 = 10,000 Adonay, 5,000 Dionel',
                'pagos'         => [
                    [
                        'recibo'        => '0090',
                        'fecha'         => '2026-07-03',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY ESPINOZA',
                        'lote'          => 'K-2',
                        'observaciones' => 'Recibo 0090 del talonario. Cuaderno: cuota julio, cuota agosto, cuota sept., cuota oct., L 5,000.00 cada una.',
                    ],
                    [
                        'recibo'        => '00000357',
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY ESPINOZA',
                        'lote'          => 'K-2',
                        'observaciones' => 'Recibo 00000357 del talonario. Cuaderno: cuota nov., cuota dic., L 5,000.00 cada una.',
                    ],
                ],
            ],

            // ── Exp. 0023 ──────────────────────────────────────────
            [
                'expediente' => 23,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'GREIDY FABIOLA ARANDA REYES',
                    'dni'      => null,
                    'telefono' => '88107508',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0027',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 53.',
                'pagos'         => [
                    [
                        'recibo'        => '0091',
                        'fecha'         => '2026-07-03',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => 'K-11',
                        'observaciones' => 'Recibo 0091 del talonario. Cuaderno: cuota julio, cuota agost., L 5,000.00 cada una. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000373',
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'abono',
                        'monto'         => '15000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'K-11',
                        'observaciones' => 'Recibo 00000373 del talonario. Cuaderno: ABONO A CAPITAL. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0024 ──────────────────────────────────────────
            [
                'expediente' => 24,
                'fecha'      => '2026-06-03',
                'cliente'    => [
                    'nombre'   => 'YANNIRIS PAOLA GARCÍA LÓPEZ',
                    'dni'      => '0412199800121',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '12', 'valor' => '210000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento autorizado de L 40,000.00 por pago al contado.'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0028',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 55. Observación: El valor 250,000 cambia a 210,000.00 al contado, por pago al contado. OBSERVACIÓN: Por descuento autorizado de L. 40,000.00 por pago al contado, el valor original de la venta de L. 250,000.00 se ajusta a un valor final de 210,000L. Los pagos realizados suman L. 210,000. Cuenta cancelada en su totalidad el 07/07/2026. Saldo 0.00',
                'pagos'         => [
                    [
                        'recibo'        => '0036',
                        'fecha'         => '2026-07-03',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'K-12',
                        'observaciones' => 'Recibo 0036 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000005',
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '195000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => 'K-12',
                        'observaciones' => 'Recibo 00000005 del talonario. Cuaderno: abono pago final. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0025 ──────────────────────────────────────────
            [
                'expediente' => 25,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'JOSÉ WILMAN RIVERA HENRÍQUEZ',
                    'dni'      => '0406198000055',
                    'telefono' => '94564029',
                ],
                'lotes' => [
                    ['bloque' => 'R', 'numero' => '1', 'valor' => '250000.00'],
                    ['bloque' => 'R', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 15,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0056',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 57.',
                'pagos'         => [
                    [
                        'recibo'        => '00000049',
                        'fecha'         => '2026-07-15',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'remesa',
                        'referencia'    => 'REMESA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000049 del talonario. Cuaderno: cuota de julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000087',
                        'fecha'         => '2026-08-17',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000087 del talonario. Cuaderno: Cuota de agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0026 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0057',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 59.',
                'pagos'         => [
                    [
                        'recibo'        => '00000372',
                        'fecha'         => '2026-07-16',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000372 del talonario. Cuaderno: cuota de julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000080',
                        'fecha'         => '2026-08-15',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000080 del talonario. Cuaderno: Cuota de agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0027 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0058',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 61.',
                'pagos'         => [
                    [
                        'recibo'        => '00000313',
                        'fecha'         => '2026-07-16',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'D-14',
                        'observaciones' => 'Recibo 00000313 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000079',
                        'fecha'         => '2026-08-15',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'D-14',
                        'observaciones' => 'Recibo 00000079 del talonario. Cuaderno: Cuota  agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0028 ──────────────────────────────────────────
            [
                'expediente' => 28,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'HUMBERT JOSSUÉ ZOLA PORTILLO',
                    'dni'      => '0406200200046',
                    'telefono' => '87459973',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '9', 'valor' => '324997.33'],
                    ['bloque' => 'H', 'numero' => '15', 'valor' => '324997.33'],
                    ['bloque' => 'H', 'numero' => '16', 'valor' => '324997.34'],
                ],
                'prima'         => '34000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0044',
                'vendedor'      => 'WILIAM LOPEZ',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 63. Nota al margen: fecha 20 de cada mes serà la fecha de pago por solicitud del cliente.Área 1012.50 vr². Observaciones: Se unificaron los lotes. Fecha 07/07/2026. Se conserva la validez de los recibos y pagos anteriores. Detalle de pagos anteriores unificados: Primas L. 34,000.00. Cuotas de julio:13,000.00 L segun recibo 0047  Total aplicado a cuenta unificada L. 47,000.00. Saldo unificado L. 928,000.00. ⚠️ El cuaderno anota valor L 975,000.00 (valor redondeado) y cuota L 19,604.00; se respetó la CUOTA, así que el valor cargado es L 974,992.00 y los saldos quedan 8 lempiras por debajo de los del papel. ⚠️ La prima se registro en fechas distintas por lo que se extendieron recibos con numeraciòn 0044,0046 y 0048  corresponden a los talonarios de las tres cuentas previas a la unificación.',
                'pagos'         => [
                    [
                        'recibo'        => '00000002',
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '13000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000002 del talonario. Cuaderno: cuotas julio unificados. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000012',
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'abono',
                        'monto'         => '11500.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000012 del talonario. Cuaderno: abono  a capital. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000370',
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'abono',
                        'monto'         => '30500.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000370 del talonario. Cuaderno: abono a capital. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0029 ──────────────────────────────────────────
            [
                'expediente' => 29,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'CONCEPCIÓN ESTEVEZ',
                    'dni'      => '0412196400157',
                    'telefono' => '98561664',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '12', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 8,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0059',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 65.',
                'pagos'         => [
                    [
                        'recibo'        => '0105',
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => 'N-12',
                        'observaciones' => 'Recibo 0105 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000346',
                        'fecha'         => '2026-07-27',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => '46015413',
                        'lote'          => 'N-12',
                        'observaciones' => 'Recibo 00000346 del talonario. Cuaderno: cuota agost., cuota sept., L 5,000.00 cada una. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0030 ──────────────────────────────────────────
            [
                'expediente' => 30,
                'fecha'      => '2026-06-08',
                'cliente'    => [
                    'nombre'   => 'YOLANY LISSETH MALDONADO',
                    'dni'      => null,
                    'telefono' => '87590875',
                ],
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '1', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 8,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0060',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 67.',
                'pagos'         => [
                    [
                        'recibo'        => '00000303',
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => 'A-1',
                        'observaciones' => 'Recibo 00000303 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000394',
                        'fecha'         => '2026-08-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => '004300 y 004303',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000394 del talonario. Cuaderno: cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0031 ──────────────────────────────────────────
            [
                'expediente' => 31,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'MELVIN NAHUN RODRÍGUEZ VARGAS',
                    'dni'      => '0418200000130',
                    'telefono' => '94329099',
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '1', 'valor' => '250000.00'],
                    ['bloque' => 'T', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0042',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 69. ⚠️el cliente pagó la prima en 2 partes, motivo por el cual se extienden 2 recibos pero se unificó  el recibo n° 0042 con el 0079 , al momento de pasar al libro maestro sin anulacion a uno a peticion del mismo',
                'pagos'         => [
                    [
                        'recibo'        => '00000377',
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'abono',
                        'monto'         => '32500.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000377 del talonario. Cuaderno: abono a capital. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0032 ──────────────────────────────────────────
            [
                'expediente' => 32,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'SANDRA LILIANA MIRANDA',
                    'dni'      => '0412200000297',
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0061',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 71. El cuaderno dejó el campo Estado en blanco.',
                'pagos'         => [
                    [
                        'recibo'        => '00000321',
                        'fecha'         => '2026-07-18',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000321 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0033 ──────────────────────────────────────────
            [
                'expediente' => 33,
                'fecha'      => '2026-06-15',
                'cliente'    => [
                    'nombre'   => 'DEVER ADONAY LÓPEZ',
                    'dni'      => '0412199500106',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '5', 'valor' => '250000.00'],
                    ['bloque' => 'O', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 12,
                'dia_pago'      => 15,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0062',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 73. El lote O-6 es irregular (437.3700 vr²) y su precio se dedujo restando: PENDIENTE de confirmar con la contratante. SEGÚN PLANO EN FISICO EL AREA DE LOTE O-6 FUE DIVIDIDO Y DE LE VENDIERON SOLO 250 VARAS, Y POR CONVENIO DE PAGAR EN UN PAZO DE 1 AÑO EL PRECIO SE REDUCE A 440, 000 SEGUN PROMESA DE VENTA, DESCUENTO QUE SE APLICARA AL SER EFECTUADO EL PAGO TOTAL',
                'pagos'         => [
                    [
                        'recibo'        => '00000320',
                        'fecha'         => '2026-07-18',
                        'tipo'          => 'cuota',
                        'monto'         => '20000.00',
                        'forma'         => 'remesa',
                        'referencia'    => 'REMESA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000320 del talonario. Cuaderno: cuota julio. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000084',
                        'fecha'         => '2026-08-18',
                        'tipo'          => 'abono',
                        'monto'         => '20000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000084 del talonario. Cuaderno: Abono a capital. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0034 ──────────────────────────────────────────
            [
                'expediente' => 34,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'ELSI ROXANA ARANDA LÓPEZ',
                    'dni'      => '0412198500508',
                    'telefono' => '97642749',
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0063',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 75.',
                'pagos'         => [
                    [
                        'recibo'        => '00000309',
                        'fecha'         => '2026-07-16',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => '2685691',
                        'lote'          => 'E-6',
                        'observaciones' => 'Recibo 00000309 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000085',
                        'fecha'         => '2026-08-16',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => '5129996',
                        'lote'          => 'E-6',
                        'observaciones' => 'Recibo 00000085 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0035 ──────────────────────────────────────────
            [
                'expediente' => 35,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'ERICK HUMBERTO REYES ORELLANA',
                    'dni'      => null,
                    'telefono' => '93843808',
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0064',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 77. El cuaderno anota el DNI 04121980000039, que tiene 14 dígitos en vez de 13. Se cargó sin DNI: hay que revisarlo contra el papel.',
                'pagos'         => [
                    [
                        'recibo'        => '00000315',
                        'fecha'         => '2026-06-17',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'deposito',
                        'referencia'    => '2962428',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000315 del talonario. Cuaderno: cuota julio. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0036 ──────────────────────────────────────────
            [
                'expediente' => 36,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'LILIAN YESENIA MANCÍA ESTÉVEZ',
                    'dni'      => '0412197900512',
                    'telefono' => '99233485',
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '15', 'valor' => '250000.00'],
                    ['bloque' => 'G', 'numero' => '16', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0065',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 79.',
                'pagos'         => [
                    [
                        'recibo'        => '00000359',
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => '762153',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000359 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0037 ──────────────────────────────────────────
            [
                'expediente' => 37,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'DANIA ARELY TRIGUEROS MANCÍA',
                    'dni'      => '0412199800185',
                    'telefono' => '88114614',
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '1', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0066',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => '',
                'pagos'         => [
                    [
                        'recibo'        => '00000036',
                        'fecha'         => '2026-07-13',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000036 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000358',
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => '762153',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000358 del talonario. Cuaderno: cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0038 ──────────────────────────────────────────
            [
                'expediente' => 38,
                'fecha'      => '2026-06-16',
                'cliente'    => [
                    'nombre'   => 'KARELIA NICOL TRIGUEROS TORRES',
                    'dni'      => '0412200600084',
                    'telefono' => '97945620',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 16,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => '0068',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 83.',
                'pagos'         => [],
            ],

            // ── Exp. 0039 ──────────────────────────────────────────
            [
                'expediente' => 39,
                'fecha'      => '2026-06-17',
                'cliente'    => [
                    'nombre'   => 'SULMY KORIXA MANCÍA TORRES',
                    'dni'      => '0412200100409',
                    'telefono' => '97827802',
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 17,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0069',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 85.',
                'pagos'         => [
                    [
                        'recibo'        => '00000314',
                        'fecha'         => '2026-07-16',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000314 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000075',
                        'fecha'         => '2026-08-13',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000075 del talonario. Cuaderno: cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0040 ──────────────────────────────────────────
            [
                'expediente' => 40,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'OBDULIA MARÍA GÓMEZ MORENO',
                    'dni'      => '0405199200296',
                    'telefono' => '95504277',
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0071',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 87.',
                'pagos'         => [
                    [
                        'recibo'        => '00000330',
                        'fecha'         => '2026-07-22',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000330 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0041 ──────────────────────────────────────────
            [
                'expediente' => 41,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'ELKIN JAVIER TORRES ESTÉVEZ',
                    'dni'      => '0412200400186',
                    'telefono' => '95434227',
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0072',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 89.',
                'pagos'         => [
                    [
                        'recibo'        => '00000360',
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000360 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza.',
                    ],
                ],
            ],

            // ── Exp. 0042 ──────────────────────────────────────────
            [
                'expediente' => 42,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'MAVIS YADANI GARCÍA SOLÍS',
                    'dni'      => '0412198900344',
                    'telefono' => '94418185',
                ],
                'lotes' => [
                    ['bloque' => 'R', 'numero' => '16', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0073',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 91.',
                'pagos'         => [
                    [
                        'recibo'        => '00000023',
                        'fecha'         => '2026-07-10',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000023 del talonario. Cuaderno: cuota mes julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0043 ──────────────────────────────────────────
            [
                'expediente' => 43,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'HÉCTOR EMILIO CRUZ MOLINA',
                    'dni'      => '1604200000565',
                    'telefono' => '88993983',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0074',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 93.',
                'pagos'         => [
                    [
                        'recibo'        => '00000019',
                        'fecha'         => '2026-07-09',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => '973143',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000019 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000056',
                        'fecha'         => '2026-08-10',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => '3328041',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000056 del talonario. Cuaderno: cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0044 ──────────────────────────────────────────
            [
                'expediente' => 44,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'MAYCOL EFRAÍN PERDOMO BRIZUELA',
                    'dni'      => '0412199600064',
                    'telefono' => '89945267',
                ],
                'lotes' => [
                    ['bloque' => 'M', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0075',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 95.',
                'pagos'         => [
                    [
                        'recibo'        => '00000336',
                        'fecha'         => '2026-07-23',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000336 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0045 ──────────────────────────────────────────
            [
                'expediente' => 45,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'MARÍA BRIZUELA',
                    'dni'      => '0412194800054',
                    'telefono' => '93969737',
                ],
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '15', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0075-1',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 97. El cuaderno numera el recibo de la prima como «0075-1», DEBIDO A UNA CONFUSION EL RECIBO SE REPITIO Y PARA NO ANULAR E IDENTIFICAR SE ENUMERÒ CON GUION 1',
                'pagos'         => [
                    [
                        'recibo'        => '00000323',
                        'fecha'         => '2026-07-20',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000323 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0046 ──────────────────────────────────────────
            [
                'expediente' => 46,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'CARLOS JOSÉ BRIZUELA',
                    'dni'      => '0412200700263',
                    'telefono' => '94227330',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '3', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0076',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 99.',
                'pagos'         => [
                    [
                        'recibo'        => '00000324',
                        'fecha'         => '2026-07-20',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000324 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0047 ──────────────────────────────────────────
            [
                'expediente' => 47,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'LESTER JOSUÉ TORRES ESTÉVEZ',
                    'dni'      => '0412200700293',
                    'telefono' => '93726020',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0077',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 101.',
                'pagos'         => [
                    [
                        'recibo'        => '00000325',
                        'fecha'         => '2026-07-20',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRFANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000325 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Frfanco.',
                    ],
                ],
            ],

            // ── Exp. 0048 ──────────────────────────────────────────
            [
                'expediente' => 48,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'JULIO CÉSAR VARGAS',
                    'dni'      => '0421199400584',
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0078',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 103. ⚠️ PENDIENTE: el cuaderno anota un tercer renglón —25/08/2026, cuota agosto, L 10,000.00, recibo 00000339, efectivo, recibió Dionel P.— con fecha POSTERIOR a hoy. No se cargó: un recibo no se emite por adelantado. Por eso el saldo acá es L 470,000.00 y el del cuaderno L 460,000.00. ⚠️ Los recibos del 25-jul y del 25-ago se leen igual en el escaneo; el de julio se cargó como 00000329. PENDIENTE de confirmar.',
                'pagos'         => [
                    [
                        'recibo'        => '00000329',
                        'fecha'         => '2026-07-25',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000329 del talonario. Cuaderno: cuota julio. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0049 ──────────────────────────────────────────
            [
                'expediente' => 49,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'FRANCISCA DUBÓN',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'I', 'numero' => '1', 'valor' => '220000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 105.', 'prima' => '220000.00', 'plazo' => 0],
                    ['bloque' => 'I', 'numero' => '2', 'valor' => '220000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 105.', 'prima' => '220000.00', 'plazo' => 0],
                    ['bloque' => 'I', 'numero' => '3', 'valor' => '220000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 105.'],
                ],
                'prima'         => '440000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — L 190,000 ADONAY E. Y L 250,000 DIONEL P.',
                'recibo_prima'  => '0031',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 105. Observación del cuaderno: « El cuaderno pone el lote I-3 al contado (plazo 0), pero su prima no cubre el valor: la diferencia entró después, por recibo. Se cargó financiado al plazo del contrato y los pagos lo cancelan.',
                'pagos'         => [
                    [
                        'recibo'        => '0032',
                        'fecha'         => '2026-06-19',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'I-3',
                        'observaciones' => 'Recibo 0032 del talonario. Cuaderno: prima del lote 3 del bloque I. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '0122',
                        'fecha'         => '2026-07-01',
                        'tipo'          => 'cuota',
                        'monto'         => '210000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => 'I-3',
                        'observaciones' => 'Recibo 0122 del talonario. Cuaderno: pago total del lote 3 del bloque I. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0050 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0030',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 107. El cuaderno lleva una cuenta por lote: K-7 de L 400,000.00 con prima L 16,000.00 y cuota L 8,000.00; K-8 de L 250,000.00 con prima L 10,000.00 y cuota L 5,000.00. ⚠️ La prima entró en DOS recibos: el 0030 el 22/06 por L 16,000.00 y el 0083 el 27/06 por L 10,000.00. El sistema emite uno solo por contrato, así que se cargó el 0030 por los L 26,000.00. Recibió Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '00000332',
                        'fecha'         => '2026-07-22',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'K-7',
                        'observaciones' => 'Recibo 00000332 del talonario. Cuaderno: cuota julio, bloque K lote 7. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000333',
                        'fecha'         => '2026-07-22',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'K-8',
                        'observaciones' => 'Recibo 00000333 del talonario. Cuaderno: cuota julio, bloque K lote 8. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0051 ──────────────────────────────────────────
            [
                'expediente' => 51,
                'fecha'      => '2026-06-22',
                'cliente'    => [
                    'nombre'   => 'MARTA BRIZUELA',
                    'dni'      => '0401196400288',
                    'telefono' => '33538688',
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '1', 'valor' => '250000.00'],
                    ['bloque' => 'J', 'numero' => '2', 'valor' => '250000.00'],
                    ['bloque' => 'J', 'numero' => '11', 'valor' => '250000.00'],
                    ['bloque' => 'J', 'numero' => '12', 'valor' => '250000.00'],
                    ['bloque' => 'E', 'numero' => '8', 'valor' => '250000.00'],
                    ['bloque' => 'E', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '60000.00',
                'plazo'         => 48,
                'dia_pago'      => 22,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0033',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 109. ⚠️ La prima entró en dos recibos el mismo día: el 0033 por L 40,000.00 de los cuatro lotes del bloque J y el 0034 por L 20,000.00 de los dos del bloque E. El sistema emite uno solo, cargado como 0033 por L 60,000.00.',
                'pagos'         => [
                    [
                        'recibo'        => '00000328',
                        'fecha'         => '2026-07-21',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO ATLÁNTIDA — DIONEL PINTO',
                        'lotes'         => ['J-1', 'J-2', 'J-11'],
                        'observaciones' => 'Recibo 00000328 del talonario. Cuaderno: cuota julio de los lotes 1, 2 y 11 del bloque J. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000334',
                        'fecha'         => '2026-07-23',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO ATLÁNTIDA — DIONEL P.',
                        'lote'          => 'J-12',
                        'observaciones' => 'Recibo 00000334 del talonario. Cuaderno: cuota julio del lote 12 del bloque J. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000335',
                        'fecha'         => '2026-07-23',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO ATLÁNTIDA — DIONEL P.',
                        'lote'          => 'E-8',
                        'observaciones' => 'Recibo 00000335 del talonario. Cuaderno: cuota julio del lote 8 del bloque E. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000350',
                        'fecha'         => '2026-07-28',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO BANCO ATLÁNTIDA — DIONEL P.',
                        'lote'          => 'E-9',
                        'observaciones' => 'Recibo 00000350 del talonario. Cuaderno: cuota julio del lote 9 del bloque E. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0052 ──────────────────────────────────────────
            [
                'expediente' => 52,
                'fecha'      => '2026-06-22',
                'cliente'    => [
                    'nombre'   => 'ELDA KARINA MARTÍNEZ',
                    'dni'      => '0501198511757',
                    'telefono' => '33538688',
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '3', 'valor' => '250000.00'],
                    ['bloque' => 'J', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 22,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0045',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 111.',
                'pagos'         => [
                    [
                        'recibo'        => '00000052',
                        'fecha'         => '2026-07-22',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000052 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0053 ──────────────────────────────────────────
            [
                'expediente' => 53,
                'fecha'      => '2026-06-22',
                'cliente'    => [
                    'nombre'   => 'SAÚL MOISÉS DUBÓN',
                    'dni'      => '0412197000191',
                    'telefono' => '99612432',
                ],
                'lotes' => [
                    ['bloque' => 'I', 'numero' => '4', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 22,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0038',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 113. Lote 4 del bloque I. Prima recibida por Adonay Espinoza.',
                'pagos'         => [
                    [
                        'recibo'        => '00000344',
                        'fecha'         => '2026-07-27',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => '2607274196781300',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000344 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0054 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0040',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 115. El cuaderno anota «Área = 200 Vr²»; el plano da 424.9000 vr² para el L-6. Manda el área del plano con el valor del papel: L 200,000.00, que dan L 470.70 por vara² para este lote.',
                'pagos'         => [],
            ],

            // ── Exp. 0055 ──────────────────────────────────────────
            [
                'expediente' => 55,
                'fecha'      => '2026-06-12',
                'cliente'    => [
                    'nombre'   => 'MARÍA ANTONIA MANCIA',
                    'dni'      => '0412197100023',
                    'telefono' => '94794818',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '1', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 12,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0018',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 117. Prima recibida por Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '0114',
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0114 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000382',
                        'fecha'         => '2026-08-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000382 del talonario. Cuaderno: CUOTA AGOSTO 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0056 ──────────────────────────────────────────
            [
                'expediente' => 56,
                'fecha'      => '2026-06-28',
                'cliente'    => [
                    'nombre'   => 'NERIN YOVANY ARANDA LÓPEZ',
                    'dni'      => '0412198400050',
                    'telefono' => '97358001',
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '6', 'valor' => '380000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 28,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0053',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 119. El J-6 es un lote irregular de 380.7200 vr²: L 998.11 por vara².  Prima recibida por Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '00000387',
                        'fecha'         => '2026-08-06',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'deposito',
                        'referencia'    => '000054500117',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000387 del talonario. Cuaderno: CUOTA JULIO Y AGOSTO. Recibió Elder Dionel Pìnto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0057 ──────────────────────────────────────────
            [
                'expediente' => 57,
                'fecha'      => '2026-06-24',
                'cliente'    => [
                    'nombre'   => 'ANABEL MEJÍA BRIZUELA',
                    'dni'      => '0412197400173',
                    'telefono' => '96210236',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '3', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 24,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0049',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 121.',
                'pagos'         => [
                    [
                        'recibo'        => '0050',
                        'fecha'         => '2026-07-24',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 0050 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000384',
                        'fecha'         => '2026-08-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000384 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0058 ──────────────────────────────────────────
            [
                'expediente' => 58,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'DORA ANGÉLICA MEJÍA',
                    'dni'      => '0412195000021',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '4', 'valor' => '210000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 123.', 'prima' => '210000.00', 'plazo' => 0],
                ],
                'prima'         => '210000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0054',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 123. Venta al contado: pago total de L 210,000.00 el día de firmar, saldo 0.00. Estado del cuaderno: «Pagado (Escritura 29/septiembre/2026)». Recibió Adonay Espinoza.',
                'pagos'         => [],
            ],

            // ── Exp. 0059 ──────────────────────────────────────────
            [
                'expediente' => 59,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'DANILO EDGARDO MEJÍA',
                    'dni'      => '0412197400041',
                    'telefono' => '98287671',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'H', 'numero' => '2', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'J', 'numero' => '5', 'valor' => '210000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 125.'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0051',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 125. Observación del cuaderno: «Fecha 6 de Julio adquirió Lote 5 Bloque J, valor: 210,000 al contado». ⚠️ Ese lote entró una semana después de firmar, pero el sistema lleva una sola fecha por venta: el J-5 queda fechado el 29/06.',
                'pagos'         => [
                    [
                        'recibo'        => '0108',
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '210000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => 'J-5',
                        'observaciones' => 'Recibo 0108 del talonario. Cuaderno: cancelación total del lote 5 del bloque J, al contado. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000310',
                        'fecha'         => '2026-07-16',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lotes'         => ['H-1', 'H-2'],
                        'observaciones' => 'Recibo 00000310 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000086',
                        'fecha'         => '2026-08-17',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lotes'         => ['H-1', 'H-2'],
                        'observaciones' => 'Recibo 00000086 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0060 ──────────────────────────────────────────
            [
                'expediente' => 60,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'HÉCTOR GUSTAVO DUBÓN MELGAR',
                    'dni'      => '0412198700119',
                    'telefono' => '99612432',
                ],
                'lotes' => [
                    ['bloque' => 'I', 'numero' => '5', 'valor' => '225000.00'],
                    ['bloque' => 'I', 'numero' => '6', 'valor' => '225000.00'],
                ],
                'prima'         => '300000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0055',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 127. Los dos lotes del bloque I a L 225,000.00 cada uno; el cuaderno no anota motivo, así que entran como precio negociado y no como descuento.',
                'pagos'         => [
                    [
                        'recibo'        => '00000343',
                        'fecha'         => '2026-07-27',
                        'tipo'          => 'abono',
                        'monto'         => '30000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000343 del talonario. Cuaderno: abono a capital. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0061 ──────────────────────────────────────────
            [
                'expediente' => 61,
                'fecha'      => '2026-06-30',
                'cliente'    => [
                    'nombre'   => 'NIXON JAVIER ORELLANA TORRES',
                    'dni'      => '0412200700306',
                    'telefono' => '89549290',
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 30,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0086',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 129. Prima recibida por Dionel Pinto, recibo 0086 del talonario.',
                'pagos'         => [
                    [
                        'recibo'        => '00000361',
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000361 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0062 ──────────────────────────────────────────
            [
                'expediente' => 62,
                'fecha'      => '2026-06-24',
                'cliente'    => [
                    'nombre'   => 'AMARILIS MELANIA GARCÍA SANTOS',
                    'dni'      => '0412199500158',
                    'telefono' => '96768013',
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 24,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0111-1',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 131. La prima es el recibo «0111-1» del talonario, un bis. Recibió Dionel P.',
                'pagos'         => [
                    [
                        'recibo'        => '00000040',
                        'fecha'         => '2026-07-15',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000040 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0063 ──────────────────────────────────────────
            [
                'expediente' => 63,
                'fecha'      => '2026-06-27',
                'cliente'    => [
                    'nombre'   => 'MARÍA TEODOSA SERRANO CARTAGENA',
                    'dni'      => '0410198800196',
                    'telefono' => '97261325',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0114-1',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 133. La prima es el recibo «0114-1» del talonario, un bis. Recibió Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '00000351',
                        'fecha'         => '2026-07-29',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000351 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0064 ──────────────────────────────────────────
            [
                'expediente' => 64,
                'fecha'      => '2026-06-27',
                'cliente'    => [
                    'nombre'   => 'JOSÉ MAREL SERRANO CARTAGENA',
                    'dni'      => '0410199400082',
                    'telefono' => '97261325',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '7', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0115',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 135. Prima recibida por Dionel Pinto, recibo 0115 del talonario.',
                'pagos'         => [
                    [
                        'recibo'        => '00000352',
                        'fecha'         => '2026-07-29',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000352 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0065 ──────────────────────────────────────────
            [
                'expediente' => 65,
                'fecha'      => '2026-06-27',
                'cliente'    => [
                    'nombre'   => 'MARÍA ISABEL AGUILAR LÓPEZ',
                    'dni'      => '0412195800177',
                    'telefono' => '93898131',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '2', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '3', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '4', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '12', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '13', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '14', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '15', 'valor' => '250000.00'],
                    ['bloque' => 'S', 'numero' => '16', 'valor' => '250000.00'],
                ],
                'prima'         => '80000.00',
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0095',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 137. Vendido por: Jony Gerson García Melgar.',
                'pagos'         => [
                    [
                        'recibo'        => '00000365',
                        'fecha'         => '2026-08-01',
                        'tipo'          => 'cuota',
                        'monto'         => '40000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000365 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0066 ──────────────────────────────────────────
            [
                'expediente' => 66,
                'fecha'      => '2026-06-27',
                'cliente'    => [
                    'nombre'   => 'RENÉ ARTURO VARGAS',
                    'dni'      => '0412198200004',
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0117',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 139. El M-7 es irregular (413.6800 vr²) y su precio se dedujo restando el del M-8: L 413,000.00, que son L 998.36 por vara². ⚠️ El cuaderno anota cuota de L 13,605.00; el valor da L 13,604.17. Se respetó el valor. Prima recibida por Dionel.',
                'pagos'         => [
                    [
                        'recibo'        => '00000064',
                        'fecha'         => '2026-08-13',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => 'M-8',
                        'observaciones' => 'Recibo 00000064 del talonario. Cuaderno: PRIMA POR LOTE 8. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0067 ──────────────────────────────────────────
            [
                'expediente' => 67,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'GERSON NOÉ TORRES MEJÍA',
                    'dni'      => '0412200300308',
                    'telefono' => '88462307',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0118',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 141. Prima recibida por Dionel Pinto, recibo 0118 del talonario.',
                'pagos'         => [
                    [
                        'recibo'        => '00000326',
                        'fecha'         => '2026-07-21',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000326 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0068 ──────────────────────────────────────────
            [
                'expediente' => 68,
                'fecha'      => '2026-07-01',
                'cliente'    => [
                    'nombre'   => 'FREDY EDGARDO LÓPEZ SAAVEDRA',
                    'dni'      => '0412198200106',
                    'telefono' => '96842285',
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'F', 'numero' => '2', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'F', 'numero' => '15', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'F', 'numero' => '16', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '40000.00',
                'plazo'         => 48,
                'dia_pago'      => 1,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0120',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 143. Vendido por: Jony Gerson García. ⚠️ La prima entró en dos recibos: el 0120 el 01/07 por L 20,000.00 y el 00000032 el 13/07 por otros L 20,000.00, anotado «Prima Inic.». El sistema emite uno solo, cargado por los L 40,000.00.',
                'pagos'         => [
                    [
                        'recibo'        => '00000356',
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lotes'         => ['F-1', 'F-16'],
                        'observaciones' => 'Recibo 00000356 del talonario. Cuaderno: cuota agosto. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000066',
                        'fecha'         => '2026-08-14',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lotes'         => ['F-2', 'F-15'],
                        'observaciones' => 'Recibo 00000066 del talonario. Cuaderno: cuota agosto. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0069 ──────────────────────────────────────────
            [
                'expediente' => 69,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'WALTER AGUILAR LÓPEZ',
                    'dni'      => '0412199000081',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '7', 'valor' => '350000.00'],
                ],
                'prima'         => '14000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0119',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 145. Vendido por: Yoni García. El T-7 es irregular (351.7600 vr²): L 994.99 por vara². Prima recibida por Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '00000089',
                        'fecha'         => '2026-08-17',
                        'tipo'          => 'cuota',
                        'monto'         => '7000.00',
                        'forma'         => 'deposito',
                        'referencia'    => '373200229',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000089 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0070 ──────────────────────────────────────────
            [
                'expediente' => 70,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'CARLOS VARGAS',
                    'dni'      => '0414198000023',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'O', 'numero' => '3', 'valor' => '210000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago casi al contado. Cuaderno pág. 147.', 'prima' => '200000.00'],
                    ['bloque' => 'O', 'numero' => '4', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'N', 'numero' => '8', 'valor' => '345000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '220000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0080',
                'vendedor'      => 'WILIAM LOPEZ',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 147. El cuaderno lleva dos cuentas: el O-3 por L 210,000.00 con prima de L 200,000.00 (recibo 0080), y el O-4 con el N-8 por L 605,000.00 con prima de L 20,000.00 (recibo 0081). ⚠️ El precio de  los lotes 4 y 8 es el resultado del valor individual de 250,000 y 345,000 mas los 10000 restantes del valor del lote 3',
                'pagos'         => [],
            ],

            // ── Exp. 0071 ──────────────────────────────────────────
            [
                'expediente' => 71,
                'fecha'      => '2026-06-20',
                'cliente'    => [
                    'nombre'   => 'JUAN RAMÓN RODRÍGUEZ VARGAS',
                    'dni'      => '0421199200063',
                    'telefono' => '94329099',
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '3', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 20,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0082',
                'vendedor'      => 'WILIAM  LOPEZ',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 149. Prima recibida por Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '00000378',
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000378 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0072 ──────────────────────────────────────────
            [
                'expediente' => 72,
                'fecha'      => '2026-07-02',
                'cliente'    => [
                    'nombre'   => 'SANTOS ISRAEL ROQUE AGUIRRE',
                    'dni'      => '1412197200077',
                    'telefono' => '97885299',
                ],
                'lotes' => [
                    ['bloque' => 'U', 'numero' => '9', 'valor' => '216666.66', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 151.', 'prima' => '216666.66', 'plazo' => 0],
                    ['bloque' => 'U', 'numero' => '10', 'valor' => '216666.67', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 151.', 'prima' => '216666.67', 'plazo' => 0],
                    ['bloque' => 'U', 'numero' => '11', 'valor' => '216666.67', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 151.', 'prima' => '216666.67', 'plazo' => 0],
                    ['bloque' => 'D', 'numero' => '16', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '660000.00',
                'plazo'         => 48,
                'dia_pago'      => 2,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — L 325,000 ADONAY E. Y L 325,000 DIONEL P.',
                'recibo_prima'  => '0084',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 151. Los lotes 9, 10 y 11 del bloque U se pagaron al contado el día de firmar: L 650,000.00 contra L 750,000.00 de lista, L 100,000.00 de descuento. El lote 16 del bloque D queda activo a 48 meses. La prima entró en dos recibos: el 0084 por el pago total y el 0085 por los L 10,000.00 del D-16.',
                'pagos'         => [
                    [
                        'recibo'        => '00000362',
                        'fecha'         => '2026-07-31',
                        'tipo'          => 'abono',
                        'monto'         => '20000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ADONAY E.',
                        'lote'          => 'D-16',
                        'observaciones' => 'Recibo 00000362 del talonario. Cuaderno: abono a capital del lote 16 del bloque D. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0073 ──────────────────────────────────────────
            [
                'expediente' => 73,
                'fecha'      => '2026-06-28',
                'cliente'    => [
                    'nombre'   => 'CARLOS CHACÓN ARÉVALO',
                    'dni'      => '0410200100281',
                    'telefono' => '92115565',
                ],
                'lotes' => [
                    ['bloque' => 'P', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 28,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0043',
                'vendedor'      => 'WILIAM LOPEZ',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 153. Prima recibida por Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '00000059',
                        'fecha'         => '2026-08-10',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000059 del talonario. Cuaderno: Cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0074 ──────────────────────────────────────────
            [
                'expediente' => 74,
                'fecha'      => '2026-06-29',
                'cliente'    => [
                    'nombre'   => 'JOSÉ MARÍA SAAVEDRA MIRANDA',
                    'dni'      => '0412198500463',
                    'telefono' => '94864320',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 29,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0087',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 155. Vendido por: Dago Aguilar.',
                'pagos'         => [
                    [
                        'recibo'        => '00000355',
                        'fecha'         => '2026-07-30',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000355 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0075 ──────────────────────────────────────────
            [
                'expediente' => 75,
                'fecha'      => '2026-07-03',
                'cliente'    => [
                    'nombre'   => 'OTILIA HENRÍQUEZ',
                    'dni'      => '0406197000068',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'U', 'numero' => '3', 'valor' => '215000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 157.', 'prima' => '215000.00', 'plazo' => 0],
                    ['bloque' => 'U', 'numero' => '4', 'valor' => '215000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 157.', 'prima' => '215000.00', 'plazo' => 0],
                    ['bloque' => 'H', 'numero' => '8', 'valor' => '215000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 157.', 'prima' => '215000.00', 'plazo' => 0],
                    ['bloque' => 'W', 'numero' => '1', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'W', 'numero' => '2', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'U', 'numero' => '2', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '675000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0088',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 157. Dos cuentas: los lotes 3 y 4 del bloque U con el lote 8 del bloque H se pagaron al contado por L 645,000.00 (recibo 0094), y los lotes 1 y 2 del bloque W con el lote 2 del bloque U quedan a 48 meses por L 750,000.00 con prima de L 30,000.00 (recibo 0088).',
                'pagos'         => [
                    [
                        'recibo'        => '00000372',
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL P.',
                        'lotes'         => ['W-1', 'W-2', 'U-2'],
                        'observaciones' => 'Recibo 00000372 del talonario. Cuaderno: cuota agosto de los lotes 1 y 2 del bloque W y el lote 2 del bloque U. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0076 ──────────────────────────────────────────
            [
                'expediente' => 76,
                'fecha'      => '2026-07-03',
                'cliente'    => [
                    'nombre'   => 'DAVID REYES',
                    'dni'      => '1701197901281',
                    'telefono' => '97403325',
                ],
                'lotes' => [
                    ['bloque' => 'W', 'numero' => '4', 'valor' => '400000.00'],
                ],
                'prima'         => '16000.00',
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0092',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 159. Vendido por: Jony Gerson García. El W-4 es irregular (403.7500 vr²): L 990.71 por vara².',
                'pagos'         => [
                    [
                        'recibo'        => '00000366',
                        'fecha'         => '2026-08-01',
                        'tipo'          => 'cuota',
                        'monto'         => '8000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000366 del talonario. Cuaderno: cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0077 ──────────────────────────────────────────
            [
                'expediente' => 77,
                'fecha'      => '2026-07-02',
                'cliente'    => [
                    'nombre'   => 'MARÍA MARILÉ RODRÍGUEZ ALVARADO',
                    'dni'      => '0406198100067',
                    'telefono' => '96728647',
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '5', 'valor' => '200000.00'],
                    ['bloque' => 'V', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '18000.00',
                'plazo'         => 48,
                'dia_pago'      => 2,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0089',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 161. El V-5 es irregular (200.5600 vr²): L 997.21 por vara².',
                'pagos'         => [
                    [
                        'recibo'        => '00000368',
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'cuota',
                        'monto'         => '9000.00',
                        'forma'         => 'deposito',
                        'referencia'    => '3.0820261008326e+20',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000368 del talonario. Cuaderno: cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0078 ──────────────────────────────────────────
            [
                'expediente' => 78,
                'fecha'      => '2026-07-04',
                'cliente'    => [
                    'nombre'   => 'ROBERTH JOSÉ TRIGUEROS MEJÍA',
                    'dni'      => '0412200500272',
                    'telefono' => '95495599',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 4,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0099',
                'vendedor'      => 'DIONEL PINTO',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 163.',
                'pagos'         => [
                    [
                        'recibo'        => '00000100',
                        'fecha'         => '2026-07-04',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000100 del talonario. Cuaderno: cuota julio, pagada el mismo día de firmar. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000376',
                        'fecha'         => '2026-08-05',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000376 del talonario. Cuaderno: cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0082 ──────────────────────────────────────────
            [
                'expediente' => 82,
                'fecha'      => '2026-07-01',
                'cliente'    => [
                    'nombre'   => 'MARLON JOEL GARCÍA SANTOS',
                    'dni'      => '0412198700180',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 1,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0123',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 171.',
                'pagos'         => [
                    [
                        'recibo'        => '00000364',
                        'fecha'         => '2026-08-01',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000364 del talonario. Cuaderno: cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0083 ──────────────────────────────────────────
            [
                'expediente' => 83,
                'fecha'      => '2026-07-01',
                'cliente'    => [
                    'nombre'   => 'JESÚS ANTONIO ARANDA',
                    'dni'      => '0412198700199',
                    'telefono' => '98132550',
                ],
                'lotes' => [
                    ['bloque' => 'J', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 1,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0124',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 173.',
                'pagos'         => [
                    [
                        'recibo'        => '00000367',
                        'fecha'         => '2026-08-03',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000367 del talonario. Cuaderno: cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0084 ──────────────────────────────────────────
            [
                'expediente' => 84,
                'fecha'      => '2026-06-18',
                'cliente'    => [
                    'nombre'   => 'YUNIBEX MALDONADO ESTÉVEZ',
                    'dni'      => '0412199000228',
                    'telefono' => '98561664',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '13', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 18,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0106',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 175. ⚠️ El cuaderno no numera el recibo de la prima.',
                'pagos'         => [
                    [
                        'recibo'        => '00000107',
                        'fecha'         => '2026-07-06',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000107 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => '00000347',
                        'fecha'         => '2026-07-27',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000347 del talonario. Cuaderno: cuota agosto y cuota septiembre, L 10,000.00 que cubren dos meses. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0085 ──────────────────────────────────────────
            [
                'expediente' => 85,
                'fecha'      => '2026-07-07',
                'cliente'    => [
                    'nombre'   => 'MARÍA EVELINA CABALLERO',
                    'dni'      => '0412197000099',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'W', 'numero' => '5', 'valor' => '337000.00', 'prima' => '15384.00'],
                    ['bloque' => 'F', 'numero' => '3', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'F', 'numero' => '14', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'G', 'numero' => '3', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'G', 'numero' => '14', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '55384.00',
                'plazo'         => 48,
                'dia_pago'      => 7,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0111',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 177. Dos cuentas: el lote 5 del bloque W por L 337,000.00 con prima de L 15,384.00 (recibo 0111), y los lotes 3 y 14 de los bloques F y G por L 1,000,000.00 con prima de L 40,000.00 (recibo 0112). El sistema emite un solo recibo de prima, cargado por los L 55,384.00.',
                'pagos'         => [
                    [
                        'recibo'        => '00000093',
                        'fecha'         => '2026-08-08',
                        'tipo'          => 'abono',
                        'monto'         => '59000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000093 del talonario. Cuaderno: Abono a capital. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0086 ──────────────────────────────────────────
            [
                'expediente' => 86,
                'fecha'      => '2026-07-07',
                'cliente'    => [
                    'nombre'   => 'WILMER VALERIO SANTOS',
                    'dni'      => '0412200100095',
                    'telefono' => '96128357',
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '5', 'valor' => '250000.00'],
                    ['bloque' => 'T', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 7,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0016',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 179. ⚠️ El cuaderno deja el valor de la venta en blanco; se dedujo L 500,000.00 del saldo y de la cuota. Recibió Adonay E.',
                'pagos'         => [
                    [
                        'recibo'        => '00000062',
                        'fecha'         => '2026-08-11',
                        'tipo'          => 'abono',
                        'monto'         => '30000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000062 del talonario. Cuaderno: abono a capital. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0087 ──────────────────────────────────────────
            [
                'expediente' => 87,
                'fecha'      => '2026-06-19',
                'cliente'    => [
                    'nombre'   => 'KEVIN AGUILAR LÓPEZ',
                    'dni'      => '0412199500189',
                    'telefono' => '93064313',
                ],
                'lotes' => [
                    ['bloque' => 'N', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA — DIONEL PINTO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000014',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 181. ⚠️ El cuaderno cobra la prima el 22/06, tres días después de firmar; el sistema la fecha el día del contrato.',
                'pagos'         => [
                    [
                        'recibo'        => '00000015',
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000015 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0088 ──────────────────────────────────────────
            [
                'expediente' => 88,
                'fecha'      => '2026-07-04',
                'cliente'    => [
                    'nombre'   => 'YEYSON ANDONI TRIGUEROS HERNÁNDEZ',
                    'dni'      => '0412199800384',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'E', 'numero' => '5', 'valor' => '210000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 183.', 'prima' => '200000.00'],
                ],
                'prima'         => '200000.00',
                'plazo'         => 48,
                'dia_pago'      => 4,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO L 170,000 Y EFECTIVO L 30,000 — DIONEL P.',
                'recibo_prima'  => '00000003',
                'vendedor'      => 'ABIGAIL ORELLANA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 183. ⚠️ La prima entró en dos formas: L 170,000.00 por depósito y L 30,000.00 en efectivo. El sistema lleva una forma por recibo; se cargó como depósito.',
                'pagos'         => [
                    [
                        'recibo'        => '00000038',
                        'fecha'         => '2026-07-14',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — DIONEL PINTO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000038 del talonario. Cuaderno: cancelación total. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0089 ──────────────────────────────────────────
            [
                'expediente' => 89,
                'fecha'      => '2026-07-09',
                'cliente'    => [
                    'nombre'   => 'JOSÉ LUIS ESTÉVEZ AGUILAR',
                    'dni'      => '0412197500034',
                    'telefono' => '94468030',
                ],
                'lotes' => [
                    ['bloque' => 'U', 'numero' => '7', 'valor' => '325000.00'],
                ],
                'prima'         => '17800.00',
                'plazo'         => 48,
                'dia_pago'      => 9,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000017',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 185. El U-7 es irregular (325.5100 vr²): L 998.43 por vara². Recibió Dionel Pinto.',
                'pagos'         => [
                    [
                        'recibo'        => '00000057',
                        'fecha'         => '2026-08-10',
                        'tipo'          => 'cuota',
                        'monto'         => '6400.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000057 del talonario. Cuaderno: Cuota mensual agosto. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0090 ──────────────────────────────────────────
            [
                'expediente' => 90,
                'fecha'      => '2026-07-25',
                'cliente'    => [
                    'nombre'   => 'ÁNGELA RAMÍREZ ACOSTA',
                    'dni'      => '0406197600292',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'U', 'numero' => '8', 'valor' => '230000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 187.', 'prima' => '230000.00', 'plazo' => 0],
                ],
                'prima'         => '230000.00',
                'plazo'         => 48,
                'dia_pago'      => 25,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL',
                'recibo_prima'  => '00000340',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 187. Cancelación total del lote 8 del bloque U el día de firmar.',
                'pagos'         => [],
            ],

            // ── Exp. 0091 ──────────────────────────────────────────
            [
                'expediente' => 91,
                'fecha'      => '2026-07-09',
                'cliente'    => [
                    'nombre'   => 'CARLOS JOSÉ MANCÍA GONZÁLES',
                    'dni'      => '0412200100400',
                    'telefono' => '93728872',
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '11', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 9,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '00000028',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 189. El lote de este expediente es el T-11, no el T-4: el T-4 es del exp. 0114. Lo corrigió Mauricio el 23-ago-2026 al aparecer los dos pidiendo el mismo lote.',
                'pagos'         => [],
            ],

            // ── Exp. 0092 ──────────────────────────────────────────
            [
                'expediente' => 92,
                'fecha'      => '2026-07-10',
                'cliente'    => [
                    'nombre'   => 'HÉCTOR PÉREZ',
                    'dni'      => '1406195700030',
                    'telefono' => '92118543',
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '5', 'valor' => '230000.00', 'valor_lista' => '250000.00', 'motivo' => 'Descuento por pago al contado. Cuaderno pág. 191.', 'prima' => '230000.00', 'plazo' => 0],
                    ['bloque' => 'G', 'numero' => '6', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'G', 'numero' => '11', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'G', 'numero' => '12', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '260000.00',
                'plazo'         => 48,
                'dia_pago'      => 10,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'TRANSFERENCIA BANCO DE OCCIDENTE — DIONEL P.',
                'recibo_prima'  => '0021',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 191. Observación del cuaderno: «El lote #5 Bloque G pagado en su totalidad». La prima entró en dos recibos: el 00000021 por el pago total del G-5 y el 00000022 por los L 30,000.00 de los otros tres.',
                'pagos'         => [
                    [
                        'recibo'        => '00000055',
                        'fecha'         => '2026-08-10',
                        'tipo'          => 'cuota',
                        'monto'         => '15000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lotes'         => ['G-6', 'G-11', 'G-12'],
                        'observaciones' => 'Recibo 00000055 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina. El cuaderno manda este pago a «todos los lotes»; se reparte entre los que deben, sin G-5, que se pagó al contado y no tiene cuotas.',
                    ],
                ],
            ],

            // ── Exp. 0093 ──────────────────────────────────────────
            [
                'expediente' => 93,
                'fecha'      => '2026-07-10',
                'cliente'    => [
                    'nombre'   => 'MIRIAN YOLANDA RECINOS PÉREZ',
                    'dni'      => '1406198500022',
                    'telefono' => '93815174',
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '7', 'valor' => '250000.00'],
                    ['bloque' => 'F', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 10,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '00000025',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 193.',
                'pagos'         => [
                    [
                        'recibo'        => '00000058',
                        'fecha'         => '2026-08-10',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000058 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0094 ──────────────────────────────────────────
            [
                'expediente' => 94,
                'fecha'      => '2026-07-10',
                'cliente'    => [
                    'nombre'   => 'IRIS YOLANDA PEÑA SOL',
                    'dni'      => '0412198200200',
                    'telefono' => '93279153',
                ],
                'lotes' => [
                    ['bloque' => 'S', 'numero' => '5', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 10,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000026',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 195.',
                'pagos'         => [
                    [
                        'recibo'        => '00000065',
                        'fecha'         => '2026-08-11',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000065 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0095 ──────────────────────────────────────────
            [
                'expediente' => 95,
                'fecha'      => '2026-07-18',
                'cliente'    => [
                    'nombre'   => 'ERNESTO CÁRDENAS',
                    'dni'      => '0406198500177',
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000317',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 197. Recibió Dionel P.',
                'pagos'         => [],
            ],

            // ── Exp. 0096 ──────────────────────────────────────────
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
                'plazo'         => 48,
                'dia_pago'      => 1,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => null,
                'recibo_prima'  => '0121',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 199.',
                'pagos'         => [],
            ],

            // ── Exp. 0097 ──────────────────────────────────────────
            [
                'expediente' => 97,
                'fecha'      => '2026-06-24',
                'cliente'    => [
                    'nombre'   => 'MARÍA ANTONIA AGUILAR',
                    'dni'      => '0412197900065',
                    'telefono' => '99015427',
                ],
                'lotes' => [
                    ['bloque' => 'R', 'numero' => '14', 'valor' => '250000.00'],
                    ['bloque' => 'R', 'numero' => '15', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 24,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0115',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 201.',
                'pagos'         => [
                    [
                        'recibo'        => '00000337',
                        'fecha'         => '2026-07-23',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000337 del talonario. Cuaderno: cuota julio 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0098 ──────────────────────────────────────────
            [
                'expediente' => 98,
                'fecha'      => '2026-07-14',
                'cliente'    => [
                    'nombre'   => 'GELMI HUMBERTO ORELLANA ESTÉVEZ',
                    'dni'      => '0412197700082',
                    'telefono' => '97591091',
                ],
                'lotes' => [
                    ['bloque' => 'H', 'numero' => '7', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 14,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL P. — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000046',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 203. Observación del cuaderno: «Representa a Iglesia Congregacional».',
                'pagos'         => [
                    [
                        'recibo'        => '00000090',
                        'fecha'         => '2026-08-17',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000090 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0099 ──────────────────────────────────────────
            [
                'expediente' => 99,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'SANDRA MARITZA MEJÍA BRIZUELA',
                    'dni'      => '0412197200124',
                    'telefono' => '99153985',
                ],
                'lotes' => [
                    ['bloque' => 'K', 'numero' => '10', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000030',
                'vendedor'      => 'DIONEL PINTO',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 205.',
                'pagos'         => [
                    [
                        'recibo'        => '00000078',
                        'fecha'         => '2026-08-15',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000078 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0100 ──────────────────────────────────────────
            [
                'expediente' => 100,
                'fecha'      => '2026-07-10',
                'cliente'    => [
                    'nombre'   => 'MIGUEL ÁNGEL LÓPEZ TORRES',
                    'dni'      => '0412196700167',
                    'telefono' => '93705718',
                ],
                'lotes' => [
                    ['bloque' => 'M', 'numero' => '6', 'valor' => '466000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 10,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — RECIBIÓ DIONEL PINTO — RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000044',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 207. El M-6 es irregular (466.7700 vr²): L 998.35 por vara².',
                'pagos'         => [
                    [
                        'recibo'        => '00000061',
                        'fecha'         => '2026-08-11',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000061 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0101 ──────────────────────────────────────────
            [
                'expediente' => 101,
                'fecha'      => '2026-07-07',
                'cliente'    => [
                    'nombre'   => 'OMAR YOVANY MARTÍNEZ CHÉVEZ',
                    'dni'      => '1617198400424',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '2', 'valor' => '250000.00', 'prima' => '10000.00'],
                    ['bloque' => 'E', 'numero' => '12', 'valor' => '250000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 7,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0059',
                'vendedor'      => 'YOLANI MALDONADO',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 209. ⚠️ El cuaderno fecha las dos primas el 19/06 y el 08/06, antes del contrato del 07/07. Se cargaron con la fecha del contrato. PENDIENTE de aclarar. Uno de los dos recibos no trae número («N/A»). Recibió Adonay Espinoza. Las fechas de las primas no coinciden con el de registro porque la persona que los comprò tuvo problemas economicos y los vendio al cliente Omar Yovany de manera hablada, puesto que aun no se firmaba promesa de venta',
                'pagos'         => [
                    [
                        'recibo'        => '00000305',
                        'fecha'         => '2026-07-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => 'A-2',
                        'observaciones' => 'Recibo 00000305 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000301',
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => 'E-12',
                        'observaciones' => 'Recibo 00000301 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000051',
                        'fecha'         => '2026-08-08',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000051 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0102 ──────────────────────────────────────────
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
                'plazo'         => 48,
                'dia_pago'      => 19,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0121',
                'vendedor'      => 'YUNIBEX MALDONADO',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 211.',
                'pagos'         => [
                    [
                        'recibo'        => '00000050',
                        'fecha'         => '2026-07-07',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000050 del talonario. Cuaderno: cuota julio 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                    [
                        'recibo'        => '00000095',
                        'fecha'         => '2026-08-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — EDWIN ADONAY ESPINOZA FRANCO',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000095 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0103 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000311',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 213.',
                'pagos'         => [
                    [
                        'recibo'        => '00000082',
                        'fecha'         => '2026-08-16',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000082 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0104 ──────────────────────────────────────────
            [
                'expediente' => 104,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'JONATAN RAMÍREZ ACOSTA',
                    'dni'      => '0412198700077',
                    'telefono' => '96269788',
                ],
                'lotes' => [
                    ['bloque' => 'U', 'numero' => '6', 'valor' => '226000.00', 'prima' => '10000.00'],
                    ['bloque' => 'W', 'numero' => '3', 'valor' => '226000.00', 'prima' => '10000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '00000031',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 215. El W-3 se adquirió el 28/07, quince días después de firmar, con su propia prima de L 10,000.00 (recibo 00000348). El sistema lleva una sola fecha por venta, así que los dos quedan fechados el 13/07.',
                'pagos'         => [],
            ],

            // ── Exp. 0105 ──────────────────────────────────────────
            [
                'expediente' => 105,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'AMINTA RODRÍGUEZ GUTIÉRREZ',
                    'dni'      => '0607198900226',
                    'telefono' => '95339683',
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '1', 'valor' => '250000.00'],
                    ['bloque' => 'V', 'numero' => '8', 'valor' => '250000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000033',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 217.',
                'pagos'         => [
                    [
                        'recibo'        => '00000068',
                        'fecha'         => '2026-08-13',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'transferencia',
                        'referencia'    => 'TRANSFERENCIA — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000068 del talonario. Cuaderno: Cuota agosto 2026. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0106 ──────────────────────────────────────────
            [
                'expediente' => 106,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'ELDER ISAÚ PERDOMO PAZ',
                    'dni'      => '1620198200178',
                    'telefono' => '92631651',
                ],
                'lotes' => [
                    ['bloque' => 'F', 'numero' => '9', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '00000034',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 219. Recibió Adonay E.',
                'pagos'         => [
                    [
                        'recibo'        => '00000397',
                        'fecha'         => '2026-08-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000397 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0107 ──────────────────────────────────────────
            [
                'expediente' => 107,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'JOSÉ HUMBERTO GARCÍA ZELAYA',
                    'dni'      => '1406196100040',
                    'telefono' => '94906818',
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '0035',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 221. Recibió Adonay E.',
                'pagos'         => [
                    [
                        'recibo'        => '00000396',
                        'fecha'         => '2026-08-08',
                        'tipo'          => 'cuota',
                        'monto'         => '5000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000396 del talonario. Cuaderno: Cuota agosto 2026. Recibió Edwin Adonay Espinoza Franco.',
                    ],
                ],
            ],

            // ── Exp. 0108 ──────────────────────────────────────────
            [
                'expediente' => 108,
                'fecha'      => '2026-07-13',
                'cliente'    => [
                    'nombre'   => 'LEDY DAMARY MARTÍNEZ LEONOR',
                    'dni'      => '0402200200260',
                    'telefono' => '97758860',
                ],
                'lotes' => [
                    ['bloque' => 'G', 'numero' => '2', 'valor' => '250000.00'],
                ],
                'prima'         => '50000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '00000037',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 223. Prima de L 50,000.00 —cinco veces la de siempre—, así que la cuota baja a L 4,166.67.',
                'pagos'         => [],
            ],

            // ── Exp. 0109 ──────────────────────────────────────────
            [
                'expediente' => 109,
                'fecha'      => '2026-07-21',
                'cliente'    => [
                    'nombre'   => 'OSCAR MIGUEL PORTILLO MEDINA',
                    'dni'      => '1627200500029',
                    'telefono' => '98273241',
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '7', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 21,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000329',
                'vendedor'      => 'DAGOBERTO AGUILAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 225.',
                'pagos'         => [],
            ],

            // ── Exp. 0110 ──────────────────────────────────────────
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
                'plazo'         => 48,
                'dia_pago'      => 25,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000341',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 227. Los dos lotes del bloque H son de 337.5000 vr² y van a L 337,000.00 cada uno: L 998.52 por vara².',
                'pagos'         => [],
            ],

            // ── Exp. 0111 ──────────────────────────────────────────
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
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000342',
                'vendedor'      => 'JONY GARCIA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 229. Recibió Dionel P.',
                'pagos'         => [],
            ],

            // ── Exp. 0112 ──────────────────────────────────────────
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
                'plazo'         => 48,
                'dia_pago'      => 27,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY ESPINOZA',
                'recibo_prima'  => '00000345',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 231. El T-8 es irregular (450.4700 vr²): L 998.96 por vara².',
                'pagos'         => [],
            ],

            // ── Exp. 0113 ──────────────────────────────────────────
            [
                'expediente' => 113,
                'fecha'      => '2026-07-24',
                'cliente'    => [
                    'nombre'   => 'ANGIE KAROLINA AGUILAR RAMÍREZ',
                    'dni'      => '0501200500243',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '6', 'valor' => '250000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 48,
                'dia_pago'      => 24,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'RECIBIÓ ADONAY E.',
                'recibo_prima'  => '00000349',
                'vendedor'      => 'JONY GERSON GARCÍA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 233. La cuota mensual del cuaderno es L 5,000.00.',
                'pagos'         => [],
            ],

            // ── Exp. 0114 ──────────────────────────────────────────
            [
                'expediente' => 114,
                'fecha'      => '2026-07-29',
                'cliente'    => [
                    'nombre'   => 'WALTER UZIEL ESQUIVEL RAMÍREZ',
                    'dni'      => '0404198800755',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'T', 'numero' => '4', 'valor' => '260000.00'],
                    ['bloque' => 'T', 'numero' => '10', 'valor' => '260000.00'],
                ],
                'prima'         => '20000.00',
                'plazo'         => 50,
                'dia_pago'      => 29,
                'forma_prima'   => 'transferencia',
                'ref_prima'     => 'RECIBIÓ ADONAY E.',
                'recibo_prima'  => '00000353',
                'vendedor'      => 'JONY GERSON GARCÍA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 235. El cuaderno anota: «A petición del cliente se cambiará promesa de venta a nombre de Walter Uziel Esquivel Ramírez y cualquier otro registro a la fecha». La página anterior estaba a nombre de Maybeline Nicolle Palencia Machorro; el cambio se hizo antes del sistema, así que el expediente entra directo a nombre de Walter. Cuota mensual del cuaderno: L 10,000.00 por los dos lotes. El T-4 figuraba también en el exp. 0091, que era el que estaba mal.',
                'pagos'         => [
                    [
                        'recibo'        => '00000108',
                        'fecha'         => '2026-08-22',
                        'tipo'          => 'cuota',
                        'monto'         => '10000.00',
                        'forma'         => 'remesa',
                        'referencia'    => 'REMESA — ADONAY E.',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000108 del talonario. Cuaderno: cuota agosto. Recibió Adonay E..',
                    ],
                ],
            ],

            // ── Exp. 0115 ──────────────────────────────────────────
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
                'plazo'         => 48,
                'dia_pago'      => 3,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '0070',
                'vendedor'      => 'JONY GERSON GARCÍA MELGAR',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 237. Observación del cuaderno: «La Prima se generó fecha 18 de junio a nombre de Jony Gerson García Melgar, por acuerdo interno se registra fecha 03/08/2026 al señor Víctor Manuel». El recibo 0070 del talonario está fechado el 18/06/2026; el sistema lo registra el día del contrato.',
                'pagos'         => [],
            ],

            // ── Exp. 0116 ──────────────────────────────────────────
            [
                'expediente' => 116,
                'fecha'      => '2026-08-13',
                'cliente'    => [
                    'nombre'   => 'MARÍA ALATORRE',
                    'dni'      => null,
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'V', 'numero' => '4', 'valor' => '352000.00'],
                ],
                'prima'         => '17000.00',
                'plazo'         => 48,
                'dia_pago'      => 13,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ ADONAY E.',
                'recibo_prima'  => '00000074',
                'vendedor'      => 'JONY GERSON GARCÍA',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 247. El cuaderno anota área 351.79 vr² —la misma del plano— y cuota mensual L 6,979.00. Falta el DNI.',
                'pagos'         => [],
            ],

            // ── Exp. 0117 ──────────────────────────────────────────
            [
                'expediente' => 117,
                'fecha'      => '2026-08-13',
                'cliente'    => [
                    'nombre'   => 'WILMER JEOVANNY FLORES VÁSQUEZ',
                    'dni'      => '1314198500140',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'I', 'numero' => '12', 'valor' => '337000.00', 'prima' => '337000.00', 'plazo' => 0],
                ],
                'prima'         => '337000.00',
                'plazo'         => 0,
                'dia_pago'      => 13,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000073',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno pág. 249. «Estado: Pagado»: pago único de L 337,000.00 el mismo día de firmar, recibo 00000073. El cuaderno no anota vendedor.',
                'pagos'         => [],
            ],
        ];
    }
}
