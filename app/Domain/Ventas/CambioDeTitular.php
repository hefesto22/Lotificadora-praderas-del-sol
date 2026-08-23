<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Models\Cliente;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * El expediente cambia de dueño. Los pagos NO se mueven.
 *
 * ═══ EL CASO, CON LAS PALABRAS DE QUIEN LO PIDIO ═══
 *
 * Mauricio, 22-ago-2026: «se hizo la promesa de venta, pero después quieren
 * cambiar la persona titular; el registro de los pagos queda y solo se
 * cambia el nombre del cliente, y que quede registro de que se cambió ese
 * nombre y la fecha en el expediente».
 *
 * En lo legal esto es una cesión de derechos y se firma en papel. Para el
 * sistema es una sola cosa: la marca de titular pasa de una fila del
 * expediente a otra.
 *
 * ═══ 🔴 LO QUE NO SE TOCA, Y POR QUE ═══
 *
 * **Ni un recibo.** Cada recibo guarda su propio `cliente_id` y su
 * `a_nombre_de`: el 0123 dice quién pagó ESE día, y eso ya pasó. Cambiarlos
 * diría que el dinero lo puso alguien que no lo puso, y el día que alguien
 * cruce la caja contra los depósitos no le va a dar. Es el mismo criterio
 * del §8.2 y de `RegistroDeRescisiones`: un papel entregado no se corrige
 * hacia atrás.
 *
 * **Ni una cuota, ni el plan, ni el valor.** Lo que se cede es el contrato
 * tal como está: quien entra recibe la deuda que hay, no una nueva.
 *
 * ═══ LO QUE SI SE MUEVE, Y POR QUE ═══
 *
 * **`compromisos.cliente_id` de los lotes VIGENTES.** Esa columna contesta
 * «¿de quién es este lote?» y es la que rotula el plano
 * (`PlanoDelProyecto::lotes()`). Dejarla congelada haría que el mapa
 * —la pantalla donde más se hace esa pregunta— siguiera mostrando al dueño
 * anterior para siempre. Un compromiso **rescindido o cerrado no se toca**:
 * ese lote fue de quien lo tuvo, y eso sí es historia.
 *
 * No hace falta asentarlo aparte: `Compromiso` ya registra `cliente_id` en
 * su propio log de actividad.
 *
 * El estado de cuenta y los recibos nuevos salen a nombre del titular de
 * hoy sin que nadie los toque: los dos lo leen en vivo.
 *
 * ═══ EL QUE SALE SE QUEDA LISTADO ═══
 *
 * Decisión de Mauricio, 22-ago-2026. No se le borra la fila: pasa a
 * `titular = false` con la fecha en `titular_hasta`. Dos razones prácticas:
 * el expediente no desaparece de su ficha de cliente de un día para otro, y
 * sus recibos —que lo siguen apuntando— no quedan colgando de una venta
 * donde ya no figura.
 *
 * ⚠️ Quien lee `$venta->clientes` para listar copropietarios tiene que
 * descartar a los que traen `titular_hasta`, o el ex-titular sale impreso
 * como copropietario actual. Ver `EstadoDeCuenta::acompanantes()`.
 *
 * ═══ EL ORDEN IMPORTA, Y NO ES ESTETICO ═══
 *
 * `venta_cliente_un_titular_uq` es un índice único parcial: Postgres lo
 * valida FILA POR FILA, no al final de la transacción. Prender la marca
 * nueva antes de apagar la vieja revienta con un 23505 aunque el resultado
 * final fuera correcto. Primero se apaga.
 *
 * ═══ DONDE QUEDA EL REGISTRO ═══
 *
 * En la bitácora, escrito a mano. `LogsActivity` de `Venta` mira columnas
 * de la tabla `ventas`, y el titular no es una columna: vive en el pivot.
 * Un cambio hecho a lo bruto sería **invisible** — que es justo lo que
 * Mauricio pidió que no pasara.
 */
final class CambioDeTitular
{
    /**
     * Le pasa la titularidad del expediente a otra persona.
     *
     * @param Venta $venta El expediente que cambia de dueño
     * @param Cliente $nuevo Quien queda como titular
     * @param ?string $motivo Opcional: no se exige (decisión del 22-ago-2026)
     *
     * @return ?Cliente Quien era titular hasta hoy, o null si no había
     *
     * @throws VentaInvalidaException
     */
    public function cambiar(Venta $venta, Cliente $nuevo, ?string $motivo = null): ?Cliente
    {
        /** @var ?Cliente $anterior */
        $anterior = DB::transaction(function () use ($venta, $nuevo, $motivo): ?Cliente {
            /*
             * §8.3.2: el re-check va DENTRO y con el candado puesto. Entre que
             * se abrió el expediente y se apretó el botón, otro usuario pudo
             * rescindirlo desde su computadora —o pudo entrar otra cesión de
             * la misma venta, y dos que leen el mismo titular anterior apagan
             * la misma fila y prenden dos distintas: 23505—. El modelo que
             * traía la pantalla no alcanza.
             */
            $fresco = Venta::query()->whereKey($venta->getKey())->lockForUpdate()->firstOrFail();

            $anterior = $fresco->titular();

            $this->verificar($fresco, $nuevo, $anterior);

            // 🔴 Primero se APAGA. El índice único parcial valida por fila.
            if ($anterior instanceof Cliente) {
                $fresco->clientes()->updateExistingPivot($anterior->getKey(), [
                    'titular'       => false,
                    'titular_hasta' => today(),
                ]);
            }

            if ($fresco->clientes()->whereKey($nuevo->getKey())->exists()) {
                $fresco->clientes()->updateExistingPivot($nuevo->getKey(), [
                    'titular' => true,
                    // Si vuelve alguien que ya había salido, deja de ser un
                    // titular anterior: hoy es el titular.
                    'titular_hasta' => null,
                ]);
            } else {
                $fresco->clientes()->attach($nuevo->getKey(), [
                    'titular' => true,
                    'orden'   => $this->siguienteOrden($fresco),
                ]);
            }

            $this->pasarLosLotes($fresco, $nuevo);

            /*
             * 🔴 El asiento va ADENTRO. Afuera, un fallo al escribir en
             * `activity_log` —o el proceso que se muere entre el COMMIT y el
             * INSERT— deja el expediente con dueño nuevo y SIN registro: el
             * caso exacto que este Service existe para evitar. O las dos
             * cosas pasan, o no pasa ninguna.
             */
            $this->asentar($fresco, $nuevo, $anterior, $motivo);

            $venta->load('clientes');

            return $anterior;
        });

        return $anterior;
    }

    /**
     * @throws VentaInvalidaException
     */
    private function verificar(Venta $venta, Cliente $nuevo, ?Cliente $anterior): void
    {
        $estado = $venta->getAttribute('estado');

        /*
         * Un expediente que ya se cayó no cambia de dueño: no queda nada que
         * ceder. `ocupaLosLotes()` es exactamente la pregunta —«¿el lote
         * todavía es de alguien?»— y da true en vigente y en liquidada. La
         * liquidada entra a propósito: pagó todo y todavía no escritura, y
         * ahí la cesión es de lo más común.
         */
        if (! $estado instanceof EstadoVenta || ! $estado->ocupaLosLotes()) {
            throw VentaInvalidaException::porExpedienteCerrado(
                $estado instanceof EstadoVenta ? $estado->etiqueta() : 'cerrado'
            );
        }

        if ($anterior instanceof Cliente && $anterior->is($nuevo)) {
            throw VentaInvalidaException::porYaSerElTitular((string) $nuevo->getAttribute('nombre'));
        }
    }

    /**
     * Los lotes vivos del contrato pasan a nombre del titular nuevo.
     *
     * Uno por uno y por el modelo, no con un update masivo: `Compromiso`
     * registra `cliente_id` en su log de actividad, y ese asiento es el que
     * después contesta por qué el plano dice otro nombre.
     */
    private function pasarLosLotes(Venta $venta, Cliente $nuevo): void
    {
        $vigentes = $venta->compromisos()
            ->where('estado', EstadoCompromiso::Vigente->value)
            ->get();

        foreach ($vigentes as $lote) {
            $lote->update(['cliente_id' => $nuevo->getKey()]);
        }
    }

    /**
     * El último lugar de la lista de dueños. `orden` tiene un CHECK de
     * positivo, así que arranca en 1 si el expediente viniera vacío.
     */
    private function siguienteOrden(Venta $venta): int
    {
        $ultimo = (int) $venta->clientes()->max('venta_cliente.orden');

        return max($ultimo, 0) + 1;
    }

    /**
     * El asiento en la bitácora: quién, cuándo, de quién a quién.
     *
     * 🔴 `withChanges()` y NO `withProperties()`. La pantalla de Registros
     * de actividad lee `attribute_changes` —ver `ActivityLogResource`— y hay
     * un test que fija que el diff va ahí y que `properties` queda vacío.
     * Escribirlo en `properties` guarda el asiento donde nadie lo pinta: se
     * vería «Sin datos anteriores / Sin datos nuevos», que es lo mismo que
     * no haberlo registrado.
     */
    private function asentar(Venta $venta, Cliente $nuevo, ?Cliente $anterior, ?string $motivo): void
    {
        $registro = activity()
            ->performedOn($venta)
            ->causedBy(auth()->user())
            ->withChanges([
                'old'        => ['titular' => $anterior?->getAttribute('nombre') ?? '—'],
                'attributes' => ['titular' => $nuevo->getAttribute('nombre')],
            ])
            ->event('titular');

        if ($motivo !== null && trim($motivo) !== '') {
            $registro = $registro->withProperty('motivo', trim($motivo));
        }

        $registro->log('Venta cambió de titular');
    }
}
