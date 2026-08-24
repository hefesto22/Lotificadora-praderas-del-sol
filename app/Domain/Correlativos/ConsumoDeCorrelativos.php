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
 * campo, y los dos sacan del mismo mostrador.
 *
 * ⚠️ Desde el 23-ago-2026 la serie de recibos corre POR PROYECTO, no una
 * sola para toda la lotificadora. Eso no cambia nada de lo de arriba: don
 * Elder y don Edwin siguen cobrando del mismo desarrollo al mismo tiempo, y
 * el bloqueo sigue siendo lo unico que los separa. Lo que cambia es que
 * dos DESARROLLOS ya no se intercalan los numeros.
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
     * Consume el siguiente numero de recibo interno DEL PROYECTO.
     *
     * Por proyecto desde el 23-ago-2026: el numero se ve `RPS-00000001`, y
     * cada desarrollo cuadra su caja mirando una serie sin huecos. Sigue sin
     * haber series por receptor —don Elder y don Edwin sacan del mismo
     * mostrador— y por eso el consumo sigue yendo con `SELECT … FOR UPDATE`.
     *
     * @throws CorrelativoInvalidoException
     */
    public function siguienteDeReciboInterno(Proyecto $proyecto): int
    {
        return $this->consumir(TipoCorrelativo::ReciboInterno, $proyecto, $this->arranqueDe($proyecto));
    }

    /**
     * Consume el siguiente numero de la serie VIEJA, la de antes del sistema.
     *
     * Global y congelada: la usa `CarteraHistoricaSeeder` y nadie mas. Los
     * recibos que emite van con `recibos.serie` en null, se ven sin prefijo
     * —`000001`— y una recarga los reproduce exactamente iguales.
     *
     * @throws CorrelativoInvalidoException
     */
    public function siguienteDeReciboHistorico(): int
    {
        return $this->consumir(TipoCorrelativo::ReciboHistorico, null);
    }

    /**
     * El numero Y la serie con los que nace un recibo.
     *
     * Las dos cosas salen juntas porque son la misma decision: de que serie
     * se numera es lo que despues imprime `Recibo::folio()`. Separarlas
     * invita a que alguien consuma de una y etiquete la otra.
     *
     * `$deLaCarteraVieja` lo pasa **solo** `CarteraHistoricaSeeder`. Todo lo
     * demas —la pantalla, un cobro, una prima— numera del proyecto.
     *
     * @return array{numero: int, serie: string|null}
     *
     * @throws CorrelativoInvalidoException
     */
    public function paraUnReciboNuevo(Proyecto $proyecto, bool $deLaCarteraVieja = false): array
    {
        if ($deLaCarteraVieja) {
            return ['numero' => $this->siguienteDeReciboHistorico(), 'serie' => null];
        }

        $codigo = $proyecto->getAttribute('codigo');

        if (! is_string($codigo) || trim($codigo) === '') {
            throw CorrelativoInvalidoException::porProyectoSinCodigo((int) $proyecto->getKey());
        }

        return [
            'numero' => $this->siguienteDeReciboInterno($proyecto),
            'serie'  => trim($codigo),
        ];
    }

    /**
     * Consume el siguiente numero de comprobante de devolucion.
     *
     * Serie PROPIA, no la de recibos. R12 promete que entre el 000120 y el
     * 000130 no falta ninguno; si en esa serie se colaran documentos que no
     * son cobros, esa promesa deja de servir para auditar la caja. Un
     * comprobante de salida es otra cosa y lleva su propia numeracion.
     *
     * Sin proyecto, igual que el recibo: una sola serie para toda la
     * lotificadora.
     *
     * @throws CorrelativoInvalidoException
     */
    public function siguienteDeDevolucion(): int
    {
        return $this->consumir(TipoCorrelativo::Devolucion, null);
    }

    /**
     * Consume el siguiente numero de comprobante de egreso.
     *
     * Serie PROPIA y GLOBAL. Propia por lo mismo que la devolucion: R12
     * promete que en la serie de recibos no falta ninguno, y meter ahi
     * documentos que no son cobros rompe esa promesa.
     *
     * GLOBAL aunque el gasto pertenezca a un proyecto, que es la parte que
     * sorprende. El comprobante lo emite la lotificadora, no el desarrollo, y
     * una serie por proyecto se rompe sola el dia que alguien corrija a que
     * proyecto iba cargada una factura: el numero tendria que cambiar, y un
     * numero de comprobante que cambia no sirve para nada.
     *
     * @throws CorrelativoInvalidoException
     */
    public function siguienteDeGasto(): int
    {
        return $this->consumir(TipoCorrelativo::Gasto, null);
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
    private function consumir(TipoCorrelativo $tipo, ?Proyecto $proyecto, int $piso = 0): int
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

        /*
         * ═══ EL PISO, Y POR QUE NO ES UN `update` AL GUARDAR EL PROYECTO ═══
         *
         * `proyectos.proximo_recibo` dice desde que numero imprime este
         * desarrollo. Se podria sincronizar el correlativo cada vez que se
         * guarda el proyecto, pero eso es una verdad viviendo en dos lugares:
         * el dia que alguien cambie el campo por otra puerta —un seeder, una
         * importacion, la consola— la serie queda diciendo otra cosa.
         *
         * Asi el piso se lee en el momento de numerar, que es el unico
         * momento en que importa. Y es un `max`, no una asignacion: una serie
         * **nunca retrocede**, aunque le bajen el numero de arranque.
         */
        $siguiente = max($fila['ultimo_numero'] + 1, $piso);

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

    /**
     * Desde que numero imprime este desarrollo, o 0 si no se dijo.
     */
    private function arranqueDe(Proyecto $proyecto): int
    {
        $arranque = $proyecto->getAttribute('proximo_recibo');

        return is_numeric($arranque) && (int) $arranque > 0 ? (int) $arranque : 0;
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
