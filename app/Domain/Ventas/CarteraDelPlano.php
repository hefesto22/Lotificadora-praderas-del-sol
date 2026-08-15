<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
use App\Models\Cuota;
use Illuminate\Support\Carbon;

/**
 * Lo que debe cada contrato del proyecto, para el panel del plano.
 *
 * Lo pidió Mauricio el 13-ago-2026: «cuando ya esté vendido, que aparezca
 * para pagar la cuota desde acá, o abonar a capital; así se maneja mejor
 * todo desde acá y ya se tiene toda la información del comprador». Un
 * botón de cobrar sin saber cuánto debe es apretar a ciegas, así que
 * primero hacen falta los números.
 *
 * ═══ POR QUE LOS NUMEROS SON DEL CONTRATO Y NO DEL LOTE ═══
 *
 * Porque el recibo es del contrato. Una venta puede llevar varios lotes y
 * se cobra en UNO solo —eso ya estaba resuelto y por eso
 * `Recibo::compromiso_id` queda vacío a propósito—. Si el panel de un lote
 * mostrara «saldo del lote» y el botón de al lado cobrara el contrato
 * entero, los dos números de la misma pantalla no querrían decir lo mismo,
 * y el que se equivoca es el que está atendiendo.
 *
 * Asi que se muestra el saldo DEL CONTRATO, dicho con esas palabras, y
 * cuando el contrato lleva más de un lote el panel lo avisa. En la mayoría
 * —un lote por contrato— las dos cosas son la misma y no hay nada que
 * aclarar.
 *
 * Y de paso esquiva un agujero real: `cuotas.compromiso_id` es NULLABLE
 * —una venta puede tener su plan sin repartir por lote— asi que el saldo
 * por lote sencillamente no existe para todos los contratos. El de la
 * venta sí, siempre.
 *
 * ═══ DOS CONSULTAS, NO TRESCIENTAS ═══
 *
 * Praderas tiene 301 lotes. Preguntarle el saldo a cada venta con
 * `Venta::saldoPendiente()` serían 301 consultas —ese método usa el query
 * builder y no la relación cargada, asi que precargar no lo salva—. Acá
 * son dos agregados sobre `cuotas` para TODO el proyecto: uno suma y
 * cuenta, el otro saca la próxima cuota de cada contrato con el
 * `DISTINCT ON` de Postgres.
 */
final readonly class CarteraDelPlano
{
    /**
     * @param list<int> $ventas los contratos que aparecen en el plano
     *
     * @return array<int, array{saldo: string, vencidas: int, proximaCuota: string|null, alDia: bool}>
     */
    public static function de(array $ventas): array
    {
        if ($ventas === []) {
            return [];
        }

        $proximas = self::proximasCuotas($ventas);
        $cartera = [];

        foreach (self::saldos($ventas) as $venta => $fila) {
            $cartera[$venta] = [
                'saldo'        => new Monto($fila['saldo'])->formateado(),
                'vencidas'     => $fila['vencidas'],
                'proximaCuota' => $proximas[$venta] ?? null,
                'alDia'        => $fila['vencidas'] === 0,
            ];
        }

        return $cartera;
    }

    /**
     * ¿Se le puede cobrar a este contrato?
     *
     * Un expediente liquidado, rescindido o anulado no recibe dinero. Es la
     * misma pregunta que `CobrarUnPago` hace antes de dibujar su botón, y
     * vive acá para que el plano no la conteste por su cuenta.
     */
    public static function seCobra(?EstadoVenta $estado): bool
    {
        return $estado === EstadoVenta::Vigente;
    }

    /**
     * Saldo y cuotas vencidas de cada contrato, en una sola consulta.
     *
     * ⚠️ `reorder()` no es decorativo: cualquier `orderBy` heredado sobre
     * una columna que no está en el GROUP BY hace que Postgres tire el
     * error 42803. Ya mordió antes, y está anotado en `Venta::saldoPendiente()`.
     *
     * @param list<int> $ventas
     *
     * @return array<int, array{saldo: numeric-string, vencidas: int}>
     */
    private static function saldos(array $ventas): array
    {
        $filas = Cuota::query()
            ->reorder()
            ->deLotesVivos()
            ->whereIn('venta_id', $ventas)
            ->groupBy('venta_id')
            ->selectRaw('venta_id')
            ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0) AS saldo')
            ->selectRaw('COUNT(*) FILTER (WHERE monto_pagado < monto AND fecha_vencimiento < CURRENT_DATE) AS vencidas')
            ->get();

        $saldos = [];

        foreach ($filas as $fila) {
            $saldo = (string) $fila->getAttribute('saldo');

            $saldos[(int) $fila->getAttribute('venta_id')] = [
                'saldo'    => is_numeric($saldo) ? $saldo : '0',
                'vencidas' => (int) $fila->getAttribute('vencidas'),
            ];
        }

        return $saldos;
    }

    /**
     * La próxima cuota por pagar de cada contrato, ya escrita para leer.
     *
     * `DISTINCT ON` es de Postgres y trae la PRIMERA fila de cada grupo
     * según el `ORDER BY`: una consulta en vez de una por contrato. Se
     * ordena por vencimiento y no por número porque un contrato puede
     * llevar plazos distintos por lote, y entonces la cuota 3 de un lote
     * vence después que la 7 de otro.
     *
     * @param list<int> $ventas
     *
     * @return array<int, string>
     */
    private static function proximasCuotas(array $ventas): array
    {
        $filas = Cuota::query()
            ->reorder()
            // Sin esto, la cuota que sobrevive a una rescision puede salir
            // elegida como «la proxima a pagar»: es la mas vieja sin pagar.
            ->deLotesVivos()
            ->whereIn('venta_id', $ventas)
            ->whereColumn('monto_pagado', '<', 'monto')
            ->selectRaw('DISTINCT ON (venta_id) venta_id')
            ->selectRaw('fecha_vencimiento')
            ->selectRaw('monto - monto_pagado AS falta')
            ->orderBy('venta_id')
            ->orderBy('fecha_vencimiento')
            ->orderBy('numero')
            ->get();

        $proximas = [];

        foreach ($filas as $fila) {
            $falta = (string) $fila->getAttribute('falta');
            $vence = $fila->getAttribute('fecha_vencimiento');

            $proximas[(int) $fila->getAttribute('venta_id')] = sprintf(
                '%s · %s',
                $vence instanceof Carbon ? $vence->format('d/m/Y') : (string) $vence,
                new Monto(is_numeric($falta) ? $falta : '0')->formateado(),
            );
        }

        return $proximas;
    }
}
