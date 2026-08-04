<?php

declare(strict_types=1);

namespace App\Domain\Correlativos;

use App\Domain\Enums\TipoCorrelativo;
use App\Domain\Exceptions\CorrelativoInvalidoException;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;

/**
 * La unica puerta por la que sale un numero que no se puede repetir.
 *
 * ═══ POR QUE NO ES `MAX(numero) + 1` ═══
 *
 * Porque no funciona. Dos receptores cobrando al mismo tiempo desde
 * lugares distintos leen el mismo maximo, los dos suman uno, y salen dos
 * recibos con el mismo numero. No es un caso teorico: es el escenario
 * normal de una lotificadora con don Elder en la oficina y don Edwin en el
 * campo, y el recibo interno tiene UNA SOLA serie para toda la
 * lotificadora (R12).
 *
 * El §8.3.6 lo resuelve con `SELECT … FOR UPDATE`: el primero que llega
 * bloquea la fila de la serie y el segundo espera. Cuando el primero
 * termina su transaccion, el segundo lee el numero ya incrementado.
 *
 * ═══ EL GUARD QUE PARECE PARANOIA Y NO LO ES ═══
 *
 * `lockForUpdate()` fuera de una transaccion **no bloquea nada**. Postgres
 * suelta el lock al terminar la sentencia, asi que el codigo se ve
 * correcto, pasa todos los tests de un solo hilo, y falla en produccion el
 * dia mas ocupado del mes.
 *
 * Por eso este Service **se niega a numerar fuera de una transaccion**. No
 * abre una propia a proposito: el numero tiene que morir junto con la
 * operacion que lo pidio. Si la venta se cae despues de numerar, el
 * correlativo se va con ella y no queda un hueco en la serie que despues
 * haya que explicarle a alguien.
 *
 * ═══ LA SERIE SE ABRE SOLA ═══
 *
 * La primera venta de un proyecto no encuentra fila. En vez de exigir un
 * seeder que la cree —y que alguien va a olvidar el dia que se agregue un
 * proyecto—, la fila se crea al vuelo con `insertOrIgnore`: si dos
 * transacciones la crean a la vez, una gana, la otra la ignora, y las dos
 * terminan bloqueando la misma fila.
 */
final readonly class ConsumoDeCorrelativos
{
    /**
     * Consume el siguiente numero de contrato del proyecto.
     *
     * Es tambien el numero de EXPEDIENTE: son la misma serie (R7). El
     * expediente 65 es el contrato RPS-2026-0065.
     *
     * @throws CorrelativoInvalidoException
     */
    public function siguienteDeContrato(Proyecto $proyecto): int
    {
        return $this->consumir(TipoCorrelativo::Contrato, $proyecto);
    }

    /**
     * Consume el siguiente numero de recibo interno.
     *
     * Sin proyecto: R12, una sola serie para toda la lotificadora. No hay
     * series por receptor.
     *
     * @throws CorrelativoInvalidoException
     */
    public function siguienteDeReciboInterno(): int
    {
        return $this->consumir(TipoCorrelativo::ReciboInterno, null);
    }

    /**
     * Arma el numero de contrato visible: `RPS-2026-0001`.
     *
     * El anio es el de la firma, no parte de la llave: el secuencial no
     * reinicia (R7), asi que dos contratos del mismo proyecto nunca
     * comparten secuencial aunque sean de anios distintos.
     *
     * @throws CorrelativoInvalidoException
     */
    public function numeroDeContrato(Proyecto $proyecto, int $secuencial, int $anio): string
    {
        $codigo = $proyecto->getAttribute('codigo');

        if (! is_string($codigo) || trim($codigo) === '') {
            throw CorrelativoInvalidoException::porProyectoSinCodigo((int) $proyecto->getKey());
        }

        $separador = $this->separador();

        return $codigo.$separador.$anio.$separador.$this->expediente($secuencial);
    }

    /**
     * El numero de expediente como se escribe: `0001`.
     *
     * Mismo ancho que el secuencial del contrato, para que se reconozcan
     * como lo que son: el mismo numero.
     */
    public function expediente(int $secuencial): string
    {
        return str_pad((string) $secuencial, $this->digitos(), '0', STR_PAD_LEFT);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @throws CorrelativoInvalidoException
     */
    private function consumir(TipoCorrelativo $tipo, ?Proyecto $proyecto): int
    {
        // Ver el docblock de la clase: sin transaccion el lock es decorativo.
        if (DB::transactionLevel() === 0) {
            throw CorrelativoInvalidoException::porFaltarTransaccion($tipo);
        }

        $proyectoId = $proyecto instanceof Proyecto ? (int) $proyecto->getKey() : null;

        $fila = $this->bloquear($tipo, $proyectoId);

        if ($fila === null) {
            $ahora = now();

            // insertOrIgnore y no firstOrCreate: si otra transaccion esta
            // creando la misma serie sin commitear todavia, este insert
            // ESPERA a que termine y despues no hace nada. Con firstOrCreate
            // saldria una violacion de unicidad.
            DB::table('correlativos')->insertOrIgnore([
                'proyecto_id'   => $proyectoId,
                'tipo'          => $tipo->value,
                'ultimo_numero' => 0,
                'created_at'    => $ahora,
                'updated_at'    => $ahora,
            ]);

            $fila = $this->bloquear($tipo, $proyectoId);
        }

        if ($fila === null) {
            throw CorrelativoInvalidoException::porSerieQueNoSePudoCrear($tipo);
        }

        $siguiente = $fila['ultimo_numero'] + 1;

        DB::table('correlativos')
            ->where('id', $fila['id'])
            ->update(['ultimo_numero' => $siguiente, 'updated_at' => now()]);

        return $siguiente;
    }

    /**
     * La fila de la serie, bloqueada hasta que termine la transaccion.
     *
     * Devuelve un array con forma declarada y no el `stdClass` que entrega
     * el query builder: `first()` esta tipado como `object|null`, y sobre
     * un `object` pelado PHPStan no reconoce ninguna propiedad. Convertir
     * aca —una sola vez, en el borde— deja tipado todo lo de adentro sin
     * prometer nada en un comentario.
     *
     * @return array{id: int, ultimo_numero: int}|null
     */
    private function bloquear(TipoCorrelativo $tipo, ?int $proyectoId): ?array
    {
        $consulta = DB::table('correlativos')->where('tipo', $tipo->value);

        // `where('proyecto_id', null)` genera `= NULL`, que en SQL no es
        // verdadero nunca. Las series globales necesitan `IS NULL`.
        $consulta = $proyectoId === null
            ? $consulta->whereNull('proyecto_id')
            : $consulta->where('proyecto_id', $proyectoId);

        $fila = $consulta->lockForUpdate()->first(['id', 'ultimo_numero']);

        if ($fila === null) {
            return null;
        }

        $datos = (array) $fila;

        return [
            'id'            => (int) ($datos['id'] ?? 0),
            'ultimo_numero' => (int) ($datos['ultimo_numero'] ?? 0),
        ];
    }

    private function separador(): string
    {
        $separador = config('lotificadora.correlativos.separador', '-');

        return is_string($separador) ? $separador : '-';
    }

    private function digitos(): int
    {
        $digitos = config('lotificadora.correlativos.digitos_contrato', 4);

        return is_int($digitos) && $digitos > 0 ? $digitos : 4;
    }
}
