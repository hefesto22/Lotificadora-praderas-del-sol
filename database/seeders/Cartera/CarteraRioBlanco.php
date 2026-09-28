<?php

declare(strict_types=1);

namespace Database\Seeders\Cartera;

use Override;

/**
 * La cartera que Residencial Río Blanco vendió ANTES de tener sistema.
 *
 * El cuaderno de Elder Dionel Pinto Molina, La Unión, Copán: un «Directorio de
 * Clientes» en la primera página y una ficha por expediente, con su historial
 * de pagos. Llegó escaneado el 28-sep-2026 —diez páginas— y se transcribió de
 * a una. La forma de cada entrada es la que documenta `ExpedientesHistoricos`;
 * acá va solo lo que este cuaderno tiene de distinto.
 *
 * ═══ LO QUE DECIDIO MAURICIO (28-sep-2026) ═══
 *
 *   · El proyecto es `RRB · RESIDENCIAL RIO BLANCO`, como firma el cuaderno
 *     sus contratos (`RRB-2025-002`). El plano se había importado como CRB;
 *     se renombra con `olympo:renombrar-proyecto` antes de cargar, porque
 *     después de numerar un contrato el código ya no se puede tocar.
 *   · Exp. 0008: los L 400.00 que faltan entre el saldo y el último pago son
 *     un DESCUENTO. Entra como pronto pago, con su motivo.
 *   · Exp. 0005: el lote A-5 entra con el área del PLANO (1,307.67 vr²) y el
 *     valor del cuaderno, aunque la ficha diga que se le restaron 300 vr².
 *
 * ═══ LO QUE SE DECIDIO AL TRANSCRIBIR ═══
 *
 *   · Los expedientes 0001 y 0006 están BORRADOS en el directorio y no tienen
 *     ficha: no se cargan. Sus números quedan sin usar.
 *   · Cuando el directorio y la ficha no coinciden —un nombre, un lote— vale
 *     la FICHA, y lo otro queda escrito en las observaciones.
 *   · El concepto «Abono» de este cuaderno es un pago a cuenta, no un abono a
 *     capital: entra como `cuota` y el sistema lo aplica a las cuotas más
 *     viejas. Como abono a capital, un contrato a doce meses que pagó la mitad
 *     en agosto amanecería atrasado desde agosto del año anterior.
 *   · «Dionel P.» es Elder Dionel Pinto Molina, el dueño que nombra la
 *     primera página. «Rosa Elena» se transcribe como está.
 *   · El cuaderno no anota número de talonario salvo en el exp. 0009.
 */
final class CarteraRioBlanco implements CarteraAnterior
{
    #[Override]
    public static function proyecto(): string
    {
        return 'RRB';
    }

    #[Override]
    public static function reservados(): array
    {
        return [];
    }

    #[Override]
    public static function modalidadDelAbono(): string
    {
        return 'acortar_plazo';
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Override]
    public static function todos(): array
    {
        return [
            // ── Exp. 0002 ──────────────────────────────────────────
            [
                'expediente' => 2,
                'fecha'      => '2025-07-03',
                'cliente'    => [
                    'nombre'   => 'JOSÉ ADOLFO POSADAS',
                    'dni'      => null,
                    'telefono' => '95276975',
                ],
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '2', 'valor' => '905000.00'],
                ],
                'prima'         => '452500.00',
                'plazo'         => 12,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                'recibo_prima'  => null,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 9. Contrato del cuaderno: RRB-2025-002. Área 795.38 vr². La ficha no anota la prima; sale del historial: L 452,500.00 por depósito el 03/07/2025.',
                'pagos'         => [
                    [
                        'recibo'        => null,
                        'fecha'         => '2025-07-30',
                        'tipo'          => 'cuota',
                        'monto'         => '47500.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: Abono. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => null,
                        'fecha'         => '2025-08-07',
                        'tipo'          => 'cuota',
                        'monto'         => '140000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: Abono. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => null,
                        'fecha'         => '2025-08-07',
                        'tipo'          => 'cuota',
                        'monto'         => '30000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: Abono, el segundo de ese día. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => null,
                        'fecha'         => '2026-04-27',
                        'tipo'          => 'cuota',
                        'monto'         => '135000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: Abono. Recibió Elder Dionel Pinto Molina. Saldo que anota: L 100,000.00.',
                    ],
                ],
            ],

            // ── Exp. 0003 ──────────────────────────────────────────
            [
                'expediente' => 3,
                'fecha'      => '2025-07-16',
                'cliente'    => [
                    'nombre'   => 'WILFREDO POSADAS',
                    'dni'      => '0412196300021',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '3', 'valor' => '905000.00'],
                ],
                'prima'         => '455000.00',
                'plazo'         => 12,
                'dia_pago'      => 16,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                'recibo_prima'  => null,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 11. Contrato del cuaderno: RRB-2025-003. La ficha anota 800 vr² y L 1,131.25 por vr²; el plano dice 799.76 vr². El contrato es del 16/07/2025 y la prima entró el 17/07/2025, por depósito.',
                'pagos'         => [
                    [
                        'recibo'        => null,
                        'fecha'         => '2026-07-20',
                        'tipo'          => 'cuota',
                        'monto'         => '250000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: Abono. Recibió Elder Dionel Pinto Molina. Saldo que anota: L 200,000.00.',
                    ],
                ],
            ],

            // ── Exp. 0004 ──────────────────────────────────────────
            [
                'expediente' => 4,
                'fecha'      => '2025-07-28',
                'cliente'    => [
                    'nombre'   => 'RONY OBDULIO PÉREZ',
                    'dni'      => '0412196900063',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '4', 'valor' => '2225000.00'],
                ],
                'prima'         => '575000.00',
                'plazo'         => 12,
                'dia_pago'      => 28,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                'recibo_prima'  => null,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 13. Contrato del cuaderno: RRB-2025-004. Área 2,130.59 vr². La ficha anota «Precio por vara L 1,009.38»; con el valor y el área sale L 1,044.31.',
                'pagos'         => [
                    [
                        'recibo'        => null,
                        'fecha'         => '2025-08-06',
                        'tipo'          => 'cuota',
                        'monto'         => '1000000.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: Abono. Recibió Elder Dionel Pinto Molina. Saldo que anota: L 650,000.00.',
                    ],
                ],
            ],

            // ── Exp. 0005 ──────────────────────────────────────────
            [
                'expediente' => 5,
                'fecha'      => '2025-08-05',
                'cliente'    => [
                    'nombre'   => 'RONY JOSSUE PÉREZ POSADAS',
                    'dni'      => '0412199800221',
                    'telefono' => '88883551',
                ],
                /*
                 * La ficha dice «Área 1007 vr² (le restó 300 vr por falta de
                 * pago)» y L 1,117.00 por vr². El plano sigue dibujando el
                 * A-5 entero, 1,307.67 vr². Mauricio, 28-sep-2026: «dejalo
                 * como está en el plano y con ese valor de 1,125,000».
                 */
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '5', 'valor' => '1125000.00'],
                ],
                'prima'         => '562500.00',
                'plazo'         => 12,
                'dia_pago'      => 5,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                'recibo_prima'  => null,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 15. Contrato del cuaderno: RRB-2025-005. Nota de la ficha: «Área 1007 vr² (le restó 300 vr por falta de pago)», valor por vr² L 1,117.00. Se cargó con el área del plano, 1,307.67 vr², y el valor del cuaderno. El nombre va como lo corrigió la ficha sobre la línea, «Jossue»; el directorio dice «Josué».',
                'pagos'         => [],
            ],

            // ── Exp. 0007 ──────────────────────────────────────────
            [
                'expediente' => 7,
                'fecha'      => '2025-10-15',
                'cliente'    => [
                    'nombre'   => 'OSCAR YOVANY ALONZO BARRERA',
                    'dni'      => '0412198500519',
                    'telefono' => null,
                ],
                /*
                 * La ficha anota POR GRUPO: A-006 = 614.65 vr² por L 700,000;
                 * C-13 y 14 = 627 vr² por L 724,400; D-1, 2 y 3 = 1,065 vr² por
                 * L 1,278,000. Y una prima por grupo, el mismo día.
                 *
                 * El reparto por lote es el único que cierra con precios
                 * redondos: C-13 a L 1,100 por vr² (280 → 308,000) y C-14 a
                 * L 1,200 (347 → 416,400); las D a L 1,200 sobre las áreas de la
                 * ficha (441 + 312 + 312). La prima de cada grupo va en
                 * proporción al valor de sus lotes, el residuo al último.
                 *
                 * ⚠️ La ficha anota para C-13 y 14 un saldo de L 424,000.00, y
                 * 724,400 − 300,000 son L 424,400.00. Vale el valor.
                 */
                'lotes' => [
                    ['bloque' => 'A', 'numero' => '6', 'valor' => '700000.00', 'prima' => '350000.00'],
                    ['bloque' => 'C', 'numero' => '13', 'valor' => '308000.00', 'prima' => '127553.84'],
                    ['bloque' => 'C', 'numero' => '14', 'valor' => '416400.00', 'prima' => '172446.16'],
                    ['bloque' => 'D', 'numero' => '1', 'valor' => '529200.00', 'prima' => '244309.86'],
                    ['bloque' => 'D', 'numero' => '2', 'valor' => '374400.00', 'prima' => '172845.07'],
                    ['bloque' => 'D', 'numero' => '3', 'valor' => '374400.00', 'prima' => '172845.07'],
                ],
                'prima'         => '1240000.00',
                'plazo'         => 18,
                'dia_pago'      => 15,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => null,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 19. Contrato del cuaderno: RRB-2025-007. La ficha anota por grupo: A-006 = 614.65 vr² L 700,000; C-13 y 14 = 627 vr² L 724,400; D-1, 2 y 3 = 1,065 vr² L 1,278,000. Primas del 15/10/2025 en efectivo: A-6 L 350,000; C-13 y 14 L 300,000; D-1, 2 y 3 L 590,000. Para C-13 y 14 anota saldo L 424,000.00; valor menos prima da L 424,400.00, y vale el valor. El directorio escribe «Oscar Yovani Alonso Barrera».',
                'pagos'         => [],
            ],

            // ── Exp. 0008 ──────────────────────────────────────────
            [
                'expediente' => 8,
                'fecha'      => '2025-12-05',
                'cliente'    => [
                    'nombre'   => 'YONI ALEXANDER MORENO ALVARADO',
                    'dni'      => '1415199300037',
                    'telefono' => null,
                ],
                /*
                 * La ficha da un valor para los dos lotes juntos —L 592,400.00
                 * por 280.00 + 284.84 vr²— y se reparte en proporción al área.
                 */
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '6', 'valor' => '293661.92'],
                    ['bloque' => 'C', 'numero' => '7', 'valor' => '298738.08'],
                ],
                'prima'         => '180000.00',
                'plazo'         => 12,
                'dia_pago'      => 5,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => null,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 21. Contrato del cuaderno: RRB-2025-008. Valor L 592,400.00 por los lotes 6 (280.00 vr²) y 7 (284.84 vr²) del bloque C, repartido en proporción al área. La ficha no anota el plazo: se cargó a 12 meses. Estado en la ficha: «Pagado». El directorio pone también a su nombre B-009, B-010, B-011 y B-012, sin ficha en el cuaderno: no se cargaron.',
                'pagos'         => [
                    [
                        'recibo'        => null,
                        'fecha'         => '2025-12-05',
                        'tipo'          => 'cuota',
                        'monto'         => '100000.00',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: Abono, el mismo día de la prima. Recibió Elder Dionel Pinto Molina.',
                    ],
                    [
                        'recibo'        => null,
                        'fecha'         => '2026-05-25',
                        'tipo'          => 'pronto_pago',
                        'monto'         => '312000.00',
                        'descuento'     => '400.00',
                        'motivo'        => 'Descuento de L 400.00 al cancelar: el cuaderno anota saldo de L 312,400.00, un pago de L 312,000.00 y el expediente «Pagado». Tomado como descuento, 28/09/2026.',
                        'forma'         => 'efectivo',
                        'referencia'    => null,
                        'lote'          => null,
                        'observaciones' => 'Cuaderno: Abono de L 312,000.00, saldo 0.00. Recibió Elder Dionel Pinto Molina.',
                    ],
                ],
            ],

            // ── Exp. 0009 ──────────────────────────────────────────
            [
                'expediente' => 9,
                'fecha'      => '2026-06-02',
                'cliente'    => [
                    'nombre'   => 'GLORIA YESENIA CASTILLO PINEDA',
                    'dni'      => '0412197900594',
                    'telefono' => '96336743',
                ],
                /*
                 * 🔴 ESTE VALOR NO ES EL DEL CUADERNO, A PROPOSITO — el mismo
                 * camino que el exp. 0028 de Praderas. La ficha dice
                 * L 336,000.00 con cuota de L 9,055.00 a 36 meses, y 36 × 9,055
                 * son L 325,980.00: no cierra. Se carga con el valor que
                 * respeta la cuota y `correccion_de_valor` lo lleva a los
                 * 336,000 por `CorreccionDeValor`: la cuota queda en 9,055.00 y
                 * los L 20.00 van a la última, como manda R1.
                 *
                 * Con la cuota del sistema —9,055.56— los tres pagos de 9,055
                 * habrían dejado L 1.68 vencidos, y la clienta está al día.
                 */
                'lotes' => [
                    ['bloque' => 'B', 'numero' => '13', 'valor' => '335980.00'],
                ],
                'correccion_de_valor' => [
                    'motivo' => 'El cuaderno de Río Blanco (pág. 23) dice L 336,000.00 con cuota de L 9,055.00 a 36 meses: 36 cuotas de L 9,055.00 dan L 325,980.00. Vale el valor del cuaderno, y los L 20.00 van en la última cuota.',
                    'lotes'  => ['B-13' => '336000.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 36,
                'dia_pago'      => 2,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => '00000001',
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 23. Contrato del cuaderno: RRB-2026-009. Cuota mensual del cuaderno: L 9,055.00; los L 20.00 que no caben en 36 cuotas van en la última. La prima es el recibo 00000001 del talonario. El directorio le pone el lote C-013; la ficha dice lote 13 del bloque B, y el C-13 es del exp. 0007: vale la ficha.',
                'pagos'         => [
                    [
                        'recibo'        => '00000002',
                        'fecha'         => '2026-07-02',
                        'tipo'          => 'cuota',
                        'monto'         => '9055.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ROSA ELENA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000002 del talonario. Cuaderno: cuota #1. Recibió Rosa Elena.',
                    ],
                    [
                        'recibo'        => '00000003',
                        'fecha'         => '2026-08-04',
                        'tipo'          => 'cuota',
                        'monto'         => '9055.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ROSA ELENA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000003 del talonario. Cuaderno: cuota #2. Recibió Rosa Elena.',
                    ],
                    [
                        'recibo'        => '00000004',
                        'fecha'         => '2026-09-16',
                        'tipo'          => 'cuota',
                        'monto'         => '9055.00',
                        'forma'         => 'deposito',
                        'referencia'    => 'DEPÓSITO — ROSA ELENA',
                        'lote'          => null,
                        'observaciones' => 'Recibo 00000004 del talonario. Cuaderno: cuota #3. Recibió Rosa Elena. Saldo que anota: L 298,835.00.',
                    ],
                ],
            ],

            // ── Exp. 0010 ──────────────────────────────────────────
            [
                'expediente' => 10,
                'fecha'      => '2026-09-09',
                'cliente'    => [
                    'nombre'   => 'MARÍA LILIAN VILLANUEVA',
                    'dni'      => '0412197900614',
                    'telefono' => null,
                ],
                'lotes' => [
                    ['bloque' => 'C', 'numero' => '9', 'valor' => '302500.00'],
                ],
                'prima'         => '10000.00',
                'plazo'         => 39,
                'dia_pago'      => 9,
                'forma_prima'   => 'efectivo',
                'ref_prima'     => 'RECIBIÓ DIONEL PINTO',
                'recibo_prima'  => null,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 25. Contrato del cuaderno: RRB-2026-010. Área 280.00 vr². Cuota mensual del cuaderno: L 7,500.00. El directorio escribe «Mario Lilian Villanueva».',
                'pagos'         => [],
            ],

            // ── Exp. 0011 ──────────────────────────────────────────
            [
                'expediente' => 11,
                'fecha'      => '2026-03-03',
                'cliente'    => [
                    'nombre'   => 'WILMAN ALFREDO YANES MANCÍA',
                    'dni'      => '0412198300176',
                    'telefono' => null,
                ],
                /*
                 * La ficha: los dos lotes, 648 vr² por L 777,600.00 —L 1,200 por
                 * vr²—. Por lote, sobre sus áreas: 312 → 374,400 y 336 → 403,200.
                 */
                'lotes' => [
                    ['bloque' => 'D', 'numero' => '16', 'valor' => '374400.00'],
                    ['bloque' => 'D', 'numero' => '17', 'valor' => '403200.00'],
                ],
                'prima'         => '300000.00',
                'plazo'         => 18,
                'dia_pago'      => 3,
                'forma_prima'   => 'deposito',
                'ref_prima'     => 'DEPÓSITO — ELDER DIONEL PINTO MOLINA',
                'recibo_prima'  => null,
                'observaciones' => 'Cartera anterior al sistema. Cuaderno de Residencial Río Blanco, pág. 27. Contrato del cuaderno: RRB-2026-011. Lotes 16 y 17 del bloque D, 648 vr² por L 777,600.00. La ficha no anota la cuota; la prima y el plazo están escritos en otra tinta.',
                'pagos'         => [],
            ],
        ];
    }
}
