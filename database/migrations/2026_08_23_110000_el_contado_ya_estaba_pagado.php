<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las ventas de contado que se quedaron «Vigentes» — 23-ago-2026
 *
 * Lo vio Mauricio en la tabla de expedientes: «los que fueron de contado
 * deberían de estar liquidados, ya fueron pagados en su totalidad así que no
 * tiene lógica que sigan vigentes».
 *
 * Tenía razón, y el agujero es viejo: `EstadoVenta::Liquidada` se asignaba
 * únicamente al cobrar (`RegistroDePagos::cerrarSiQuedoPagada()`) y una venta
 * de contado **no genera ni una cuota**, así que nunca pasaba por un cobro.
 * Se quedaba vigente para siempre: con botón de cobrar, contada entre los
 * contratos activos y en la pestaña «Vigentes» de la tabla.
 *
 * De hoy en adelante `RegistroDeVentas::activar()` la cierra al firmar
 * (`Venta::liquidarSiYaNoDebe()`). Esta migración es para las que ya estaban
 * escritas.
 *
 * ═══ EL CRITERIO NO ES «FUE DE CONTADO» ═══
 *
 * Es «no le queda saldo», la misma pregunta que contesta
 * `Venta::saldoPendiente()`: la suma de `monto - monto_pagado` de las cuotas
 * cuyo lote no está rescindido.
 *
 * Preguntar por `plazo_meses = 0` erraría por los dos lados: cerraría un
 * contrato mixto —un lote al contado y otro financiado, que todavía debe— y
 * dejaría abierto un contrato financiado que terminó de pagarse por un camino
 * que no actualizó el estado.
 *
 * ⚠️ `cerrada_el` NO es hoy: es el día en que efectivamente terminó de
 * pagarse —la fecha del último recibo vivo—, y para el contado esa fecha es
 * la de la firma. Poner la fecha de la migración diría que todos estos
 * clientes pagaron el 23-ago-2026, que es falso para todos. El CHECK
 * `ventas_cierre_segun_estado_chk` exige la fecha cuando el estado es
 * cerrado: por eso las dos columnas se escriben en el mismo UPDATE.
 *
 * Los valores van escritos a mano y no salen de `EstadoVenta`: una migración
 * aplicada no se vuelve a correr y no tiene por qué romperse el día que el
 * enum se mude de namespace.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE ventas v
               SET estado = 'liquidada',
                   cerrada_el = COALESCE(
                       (
                           SELECT MAX(r.fecha)
                             FROM recibos r
                            WHERE r.venta_id = v.id
                              AND r.anulado_el IS NULL
                       ),
                       v.fecha_contrato
                   )
             WHERE v.estado = 'vigente'
               AND COALESCE((
                       SELECT SUM(c.monto - c.monto_pagado)
                         FROM cuotas c
                        WHERE c.venta_id = v.id
                          AND NOT EXISTS (
                              SELECT 1
                                FROM compromisos co
                               WHERE co.id = c.compromiso_id
                                 AND co.estado = 'rescindido'
                          )
                   ), 0) = 0
        SQL);
    }

    /**
     * No se deshace, y no es pereza.
     *
     * Volver atrás tendría que devolver a «Vigente» exactamente las filas que
     * esta migración movió, y no hay cómo distinguirlas de las que ya estaban
     * liquidadas antes: una vez escritas quedan iguales. Reabrirlas todas
     * sería peor que no hacer nada —pondría a cobrar contratos saldados—, y
     * el esquema no cambió, así que no hay nada estructural que revertir.
     */
    public function down(): void
    {
        // A propósito, vacío. Ver arriba.
    }
};
