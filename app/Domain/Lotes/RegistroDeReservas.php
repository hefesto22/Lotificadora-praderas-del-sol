<?php

declare(strict_types=1);

namespace App\Domain\Lotes;

use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\ReservaInvalidaException;
use App\Models\Lote;
use App\Models\Proyecto;

/**
 * Guardar un lote para la familia, y soltarlo.
 *
 * Lo pidio Mauricio el 13-ago-2026: «para los reservados, estos son para
 * lotes heredados, asi que tambien hay que colocarlo; como reservados o
 * herencia, que se configuran y se marcan al inicio del proyecto».
 *
 * ═══ POR QUE NO VIVE EN RegistroDeCompromisos ═══
 *
 * Porque una reserva NO es un compromiso, y el repo lo tiene escrito en
 * tres lados: `EstadoLote::estaComprometido()` devuelve false para el
 * reservado, `TipoCompromiso` tiene tres casos y ninguno es este, y el
 * docblock de `EstadoLote` dice la razon en una linea —«ahi no hay nadie
 * del otro lado»—. Un apartado tiene un cliente, una seña y un
 * vencimiento; una venta tiene una escritura; una donacion tiene a quien
 * se le entrego. Un lote guardado para herencia no tiene nada de eso
 * TODAVIA: la lotificadora lo saco del mercado y punto.
 *
 * Meterlo en `RegistroDeCompromisos` obligaria a inventar un cuarto tipo
 * de compromiso sin cliente —el CHECK de la tabla pide `cliente_id`— o a
 * dejar la columna en null y romper la unica cosa que esa tabla promete.
 * Vive aca, escribe una fila, y cuando el tramite del heredero se cierra
 * el camino sigue por `RegistroDeCompromisos::donar()`, que si arma el
 * compromiso con la persona adentro. Ese salto ya esta permitido:
 * `EstadoLote::seDona()` acepta al reservado a proposito.
 *
 * ═══ DONDE QUEDA EL PORQUE ═══
 *
 * En `lotes.observaciones`, que es donde el sistema ya lo buscaba —lo dice
 * `EstadoLote::porQueNoSeVende()` desde antes de que existiera este
 * archivo—. No se pisa lo que hubiera: la anotacion nueva va ARRIBA, con
 * su fecha, y lo viejo queda debajo. El quien y el cuando exactos los
 * guarda el registro de actividad, que ya vigila esta tabla.
 */
final class RegistroDeReservas
{
    /**
     * Saca el lote del mercado y lo guarda para la familia.
     *
     * @param string $motivo por que se guarda. Obligatorio.
     *
     * @throws ReservaInvalidaException
     */
    public function reservar(Lote $lote, string $motivo): Lote
    {
        $estado = $this->estadoDe($lote);
        $codigo = $this->codigoDe($lote);

        if (! $estado->seReserva()) {
            throw ReservaInvalidaException::porGuardarLoQueNoEstaLibre($codigo, $estado->etiquetaInterna());
        }

        /*
         * El cupo del desarrollo, verificado ACA y no solo en el boton del
         * plano: reservar tambien se llama desde un seeder, desde tinker o
         * desde la proxima pantalla que alguien escriba, y cuantos lotes
         * se guardan para la familia es una decision de la lotificadora y
         * no del camino por el que se entra.
         */
        $proyecto = $lote->proyecto;

        if ($proyecto instanceof Proyecto && ! $proyecto->puedeReservarOtroLote()) {
            throw ReservaInvalidaException::porCupoDeHerenciaLleno(
                $codigo,
                $proyecto->cupoDeReservas(),
                $proyecto->lotesReservados(),
            );
        }

        $porQue = trim($motivo);

        if ($porQue === '') {
            throw ReservaInvalidaException::porGuardarSinMotivo($codigo);
        }

        /*
         * Sin transaccion, y no por descuido: es UNA fila. `donar()` la
         * necesita porque escribe el compromiso y el estado del lote, y un
         * lote donado sin donacion registrada es un agujero. Aca no hay
         * segunda fila que pueda quedar sin la primera.
         */
        $lote->update([
            'estado'        => EstadoLote::Reservado,
            'observaciones' => $this->anotar($lote, "Guardado para herencia: {$porQue}"),
        ]);

        return $lote;
    }

    /**
     * Devuelve el lote a la venta.
     *
     * Tan simple como se ve, y por la misma razon que corregir una
     * donacion: guardarlo no movio un lempira. No hay seña que devolver,
     * ni recibos que anular, ni cartera que recalcular.
     *
     * ⚠️ Lo que se habia escrito NO se borra. La anotacion nueva se suma
     * arriba, asi que la ficha del lote cuenta las dos mitades: por que se
     * habia guardado y por que volvio. Que un lote haya estado fuera del
     * mercado es exactamente lo que alguien va a querer entender despues.
     *
     * @param string $motivo por que vuelve a la venta. Obligatorio.
     *
     * @throws ReservaInvalidaException
     */
    public function deshacerReserva(Lote $lote, string $motivo): Lote
    {
        $estado = $this->estadoDe($lote);
        $codigo = $this->codigoDe($lote);

        if (! $estado->seDeshaceLaReserva()) {
            throw ReservaInvalidaException::porSacarLoQueNoEstaGuardado($codigo, $estado->etiquetaInterna());
        }

        $porQue = trim($motivo);

        if ($porQue === '') {
            throw ReservaInvalidaException::porSacarSinMotivo($codigo);
        }

        $lote->update([
            'estado'        => EstadoLote::Disponible,
            'observaciones' => $this->anotar($lote, "Sale de herencia y vuelve a la venta: {$porQue}"),
        ]);

        return $lote;
    }

    /**
     * La anotacion nueva arriba, con su fecha, y lo que hubiera abajo.
     *
     * Arriba y no abajo porque lo que explica el estado de HOY es lo
     * ultimo que paso, y es lo primero que se lee al abrir la ficha.
     */
    private function anotar(Lote $lote, string $linea): string
    {
        $anterior = trim((string) $lote->getAttribute('observaciones'));
        $fechada = sprintf('[%s] %s', today()->format('d/m/Y'), $linea);

        return $anterior === '' ? $fechada : $fechada."\n\n".$anterior;
    }

    private function estadoDe(Lote $lote): EstadoLote
    {
        $estado = $lote->getAttribute('estado');

        return $estado instanceof EstadoLote ? $estado : EstadoLote::Disponible;
    }

    private function codigoDe(Lote $lote): string
    {
        return (string) $lote->getAttribute('codigo');
    }
}
