<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\UnidadDeArea;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\TasaDeInteres;

/**
 * No se puede comprometer ese lote de esa manera.
 *
 * Los mensajes explican QUE hacer, no solo que fallo: quien los lee es
 * alguien atendiendo a un cliente en ventanilla, no un programador.
 */
final class CompromisoInvalidoException extends GrupoOlympoException
{
    public static function porLoteNoDisponible(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} esta {$estado} y solo se puede apartar un lote disponible. ".
            'Si el apartado anterior se cayo, primero hay que liberarlo.'
        );
    }

    public static function porLoteYaVendido(string $codigo): self
    {
        return new self(
            "El lote {$codigo} ya esta vendido. Deshacer una venta es una rescision, ".
            'no se hace cambiandole el estado al lote (§8.2).'
        );
    }

    public static function porLoteCancelado(string $codigo): self
    {
        return new self(
            "El lote {$codigo} esta cancelado. Hay que reactivarlo desde su ficha antes ".
            'de comprometerlo con alguien.'
        );
    }

    /**
     * Un lote que la lotificadora saco del mercado a proposito.
     *
     * El mensaje dice donde mirar el porque —las observaciones del lote— en
     * lugar de repetirlo aca: quien reservo el lote escribio su razon ahi, y
     * copiarla al mensaje seria tener dos versiones de la misma cosa.
     */
    public static function porLoteReservado(string $codigo): self
    {
        return new self(
            "El lote {$codigo} esta reservado y no se vende. El motivo esta en las ".
            'observaciones del lote; si ya no aplica, hay que liberarlo desde su ficha.'
        );
    }

    /**
     * El lote quedo comprometido antes de que existiera esta tabla.
     *
     * Es el caso de los lotes que ya estaban apartados o vendidos cuando
     * se cargo el sistema: el estado dice que estan comprometidos pero no
     * hay registro de con quien.
     */
    public static function porFaltarCompromisoVigente(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} figura como {$estado} pero no tiene un compromiso registrado. ".
            'Suele pasar con los lotes que ya estaban comprometidos antes de que el sistema '.
            'llevara este registro: hay que cargar el compromiso desde la ficha del lote, '.
            'con el cliente y la fecha que correspondan.'
        );
    }

    public static function porClienteDistinto(string $codigo, string $clienteDelApartado): self
    {
        return new self(
            "El lote {$codigo} esta apartado a nombre de {$clienteDelApartado}. ".
            'Para venderselo a otra persona hay que liberar el apartado primero, y eso '.
            'deberia quedar conversado con quien lo tenia.'
        );
    }

    /**
     * Se dona lo que esta libre, y nada mas.
     *
     * Es una lista BLANCA y no una de rechazos: se admiten `disponible` y
     * `reservado` —el camino normal de los herederos y de las iglesias, que se
     * guardan mientras corre el tramite y se donan cuando se firma— y todo lo
     * demas se contesta con esta frase. Un estado nuevo cae del lado del no,
     * que es el lado seguro.
     */
    public static function porDonarLoQueNoEstaLibre(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} esta {$estado} y solo se puede donar un lote disponible o ".
            'reservado. Si estaba apartado, primero hay que liberarlo y devolver la seña; '.
            'si ya esta vendido o donado, tiene dueño y esto no lo cambia.'
        );
    }

    /**
     * Una donacion sin motivo escrito no se graba.
     *
     * De los tres compromisos, este es el que MAS lo necesita. Un apartado se
     * explica solo y una venta deja recibos; una donacion es un lote que salio
     * del inventario sin que entrara un lempira, y dentro de un año alguien
     * —un socio, un heredero, un contador— va a preguntar por que. La
     * respuesta tiene que estar escrita el dia que se hizo, no reconstruida
     * despues.
     */
    public static function porDonarSinMotivo(string $codigo): self
    {
        return new self(
            "Para donar el lote {$codigo} hay que escribir por que y a titulo de que. Queda ".
            'anotado con tu usuario y la fecha, y es lo unico que despues explica por que ese '.
            'lote no genero ningun ingreso.'
        );
    }

    /**
     * Un compromiso cerrado es historia, y la historia no se edita.
     */
    public static function porTocarUnCompromisoCerrado(string $codigo): self
    {
        return new self(
            "El compromiso del lote {$codigo} ya esta cerrado —se libero, se convirtio o se rescindio— ".
            'y lo que quedo es su historia. Si el lote se volvio a vender, hay que cambiarlo en el '.
            'compromiso vigente.'
        );
    }

    public static function porVentaNoSeLibera(string $codigo): self
    {
        return new self(
            "El lote {$codigo} tiene una venta registrada, no un apartado. Una venta no se ".
            'libera: se rescinde, y ese tramite todavia no esta en el sistema.'
        );
    }

    /**
     * Solo se corrige lo que esta donado.
     */
    public static function porDeshacerLoQueNoEsDonacion(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} esta {$estado}, no donado. Corregir una donacion es sacarle ".
            'la marca a un lote que quedo registrado como donado por error; para cualquier otro '.
            'estado hay un camino propio.'
        );
    }

    /**
     * Corregir una donacion sin decir por que.
     */
    public static function porDeshacerDonacionSinMotivo(string $codigo): self
    {
        return new self(
            "Hay que escribir por que se le quita la donacion al lote {$codigo}. Es lo unico ".
            'que despues explica por que un lote figuro como regalado y volvio al inventario.'
        );
    }

    /**
     * El cupo de donaciones del desarrollo ya se cumplio.
     *
     * Es una guarda del DOMINIO y no solo del boton: el plano esconde el
     * boton cuando el cupo se llena, pero donar tambien se puede llamar
     * desde un seeder, desde tinker o desde la proxima pantalla que
     * alguien escriba. Lo que decide cuantos lotes se regalan es una
     * decision de la lotificadora, no el camino por el que se entra.
     */
    public static function porCupoDeDonacionesLleno(string $codigo, int $cupo, int $hechas): self
    {
        return new self(
            "El lote {$codigo} no se puede donar: este desarrollo declaro {$cupo} donaciones ".
            "y ya lleva {$hechas}. Si de verdad se va a regalar otro, primero hay que subir el ".
            'numero en la ficha del proyecto, pestaña Estado, seccion Donaciones.'
        );
    }

    /**
     * R4: un descuento sin motivo no se graba.
     *
     * La contratante contesto «se negocia caso por caso», y lo que aporta
     * el sistema es que despues se pueda saber quien autorizo que. Sin
     * motivo escrito, el descuento es indistinguible de un error de tipeo.
     */
    public static function porDescuentoSinMotivo(string $codigo, Monto $lista, Monto $pactado, UnidadDeArea $unidad = UnidadDeArea::Varas): self
    {
        return new self(
            "El lote {$codigo} se esta vendiendo a {$pactado->formateado()} {$unidad->porUnidad()} cuando ".
            "el precio de lista es {$lista->formateado()}. Un precio menor se puede registrar, ".
            'pero hay que escribir el motivo: queda anotado con el usuario y la fecha.'
        );
    }

    /**
     * R4, aplicado al precio del dinero.
     *
     * Bajar la tasa regala plata igual que bajar el precio: en un lote de
     * 250 vr² a 12 meses son mas de L 40,000 de intereses. Se puede hacer,
     * pero se escribe por que.
     */
    public static function porTasaSinMotivo(string $codigo, TasaDeInteres $lista, TasaDeInteres $pactada): self
    {
        return new self(
            "El lote {$codigo} se esta vendiendo con un interes de {$pactada->formateada()} anual ".
            "cuando el plan de ese plazo ofrece {$lista->formateada()}. Una tasa menor se puede ".
            'registrar, pero hay que escribir el motivo: queda anotado con el usuario y la fecha.'
        );
    }

    /**
     * R11: la seña es dinero, y el dinero entra de una forma conocida.
     *
     * No se asume efectivo. Un apartado pagado por transferencia y grabado
     * como efectivo es un recibo que nunca va a cruzar contra el banco, y el
     * error se descubre meses despues, cuando ya nadie se acuerda de como
     * fue.
     */
    public static function porSeniaSinFormaDePago(string $codigo, Monto $senia): self
    {
        return new self(
            "La seña de {$senia->formateado()} del lote {$codigo} no dice como entro. Hay que ".
            'elegir efectivo, transferencia o deposito: es lo que va impreso en el recibo que '.
            'se lleva el cliente.'
        );
    }

    /**
     * R11: sin numero de referencia no hay con que cruzarlo contra el banco.
     */
    public static function porSeniaSinReferencia(string $codigo, FormaDePago $forma): self
    {
        return new self(
            "La seña del lote {$codigo} entro por ".mb_strtolower($forma->etiqueta()).' y falta el '.
            'numero de referencia. Es lo unico que despues permite encontrar ese movimiento en '.
            'el estado de cuenta del banco; en efectivo no hace falta.'
        );
    }

    /**
     * R14: **una sola prorroga**, y la autoriza la administracion.
     *
     * Sin este tope, un apartado se estira para siempre de a quince dias y
     * el lote queda fuera del mercado sin que nadie haya decidido nada. Es
     * exactamente lo que la contratante quiso evitar cuando puso el plazo.
     */
    public static function porProrrogaAgotada(string $codigo, int $usadas, int $maximas): self
    {
        return new self(
            "El apartado del lote {$codigo} ya lleva {$usadas} prorroga(s) y R14 autoriza ".
            "{$maximas}. Si el cliente necesita mas tiempo hay que liberar el lote y volver a ".
            'apartarlo, que deja la decision escrita con su fecha.'
        );
    }

    /**
     * Una prorroga es una decision de la administracion, no un tramite. Sin
     * el motivo escrito, dentro de dos meses nadie puede decir por que ese
     * lote estuvo un mes fuera del mercado.
     */
    public static function porProrrogaSinMotivo(string $codigo): self
    {
        return new self(
            "Para prorrogar el apartado del lote {$codigo} hay que escribir por que. Queda ".
            'anotado con el usuario y la fecha, que es lo que despues permite revisarlo.'
        );
    }

    public static function porProrrogarLoQueNoEsApartado(string $codigo): self
    {
        return new self(
            "El lote {$codigo} tiene una venta registrada, no un apartado. Una venta no vence, ".
            'asi que no hay nada que prorrogar.'
        );
    }

    public static function porProrrogarUnApartadoCerrado(string $codigo, string $estado): self
    {
        return new self(
            "El apartado del lote {$codigo} esta {$estado} y ya no ocupa el lote. Prorrogar algo ".
            'cerrado no lo reabre: si el cliente volvio, hay que apartarlo de nuevo.'
        );
    }

    /**
     * Un apartado sin fecha de vencimiento es de los que se cargaron antes
     * de que el sistema llevara este registro (R15). No hay plazo que correr.
     */
    public static function porProrrogarSinVencimiento(string $codigo): self
    {
        return new self(
            "El apartado del lote {$codigo} no tiene fecha de vencimiento, asi que no hay plazo ".
            'que correr. Ponele una fecha desde la ficha del apartado y despues se prorroga.'
        );
    }

    /**
     * No se devuelve una seña que no existe, ni dos veces la misma.
     */
    public static function porDevolverLoQueNoSeDebe(string $codigo): self
    {
        return new self(
            "El apartado del lote {$codigo} no tiene una seña pendiente de devolver: o no dejo ".
            'seña, o el apartado sigue vigente, o ya se devolvio.'
        );
    }

    /**
     * No sale de la caja mas de lo que entro.
     *
     * El borde mas caro de un egreso: devolver de mas no se descubre hasta el
     * corte, y para entonces la persona ya se fue con el dinero.
     */
    public static function porDevolverDeMas(Monto $devuelto, Monto $recibido, string $codigo): self
    {
        return new self(
            "Sobre el lote {$codigo} entraron {$recibido->formateado()} de seña y se estan devolviendo ".
            "{$devuelto->formateado()}. No se puede devolver mas de lo que se recibio."
        );
    }

    /**
     * Una salida de caja sin motivo escrito no se graba.
     *
     * Mismo trato que el descuento de R4 y el abono de R21, y por la misma
     * razon: el mes que viene alguien va a preguntar por que salieron esos
     * L 5,000, y la respuesta tiene que estar en el papel.
     */
    public static function porFaltarElMotivoDeLaDevolucion(): self
    {
        return new self(
            'Para devolver una seña hace falta escribir por que. Queda en el comprobante, con tu '.
            'usuario y la fecha.'
        );
    }

    /**
     * R11, del lado de la salida.
     */
    public static function porFaltarLaReferencia(string $forma): self
    {
        return new self(
            "Una devolucion por {$forma} necesita el numero de referencia: es lo unico que despues ".
            'permite cruzar esta salida contra el estado de cuenta del banco (R11).'
        );
    }
}
