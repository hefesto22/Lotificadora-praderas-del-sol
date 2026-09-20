<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;

/**
 * Ese valor no se puede corregir así.
 *
 * Los mensajes le hablan a quien está corrigiendo un expediente contra el
 * contrato firmado: dicen qué está mal en lo que pidió y qué mirar, no que
 * «falló». Ver `CorreccionDeValor`.
 */
final class CorreccionDeValorInvalidaException extends GrupoOlympoException
{
    public static function porFaltarElMotivo(): self
    {
        return new self(
            'Falta el motivo. Corregir un valor le cambia el saldo a un cliente: tiene que quedar '
            .'escrito por qué, con las palabras de quien lo pidió.'
        );
    }

    public static function porNoNombrarLotes(): self
    {
        return new self(
            'No se nombró ningún lote. Va uno por cada lote que se corrige, con el valor que dice '
            .'el contrato. Ejemplo: --lote=ABC-H-016:325000.00'
        );
    }

    public static function porExpedienteQueNoEstaVigente(string $estado): self
    {
        return new self(
            "Este expediente figura como {$estado}. Solo se corrige el valor de un expediente vigente: "
            .'en uno liquidado, rescindido o anulado ya no hay cuotas que puedan llevar la diferencia.'
        );
    }

    public static function porLoteQueNoEsDeLaVenta(string $codigo): self
    {
        return new self("El lote {$codigo} no es de este expediente. Revisar el código y el id de la venta.");
    }

    public static function porLoteQueYaNoEstaVivo(string $codigo): self
    {
        return new self(
            "El lote {$codigo} ya no está vigente en este expediente (se rescindió o se liberó). "
            .'Su valor quedó escrito en el acta y no se corrige por acá.'
        );
    }

    public static function porValorEnCero(string $codigo): self
    {
        return new self("El valor nuevo del lote {$codigo} es cero. Un lote vendido no puede valer L 0.00.");
    }

    /**
     * Con interés, mover el capital mueve también el interés de cada cuota.
     */
    public static function porLlevarInteres(string $codigo): self
    {
        return new self(
            "El lote {$codigo} se vendió con interés. Cambiarle el valor cambia el capital, y con él "
            .'el interés de todas las cuotas que faltan: eso es una reamortización, no un residuo, y '
            .'esta corrección no la hace.'
        );
    }

    public static function porPrimaMayorAlValor(string $codigo, Monto $prima, Monto $valor): self
    {
        return new self(
            "El lote {$codigo} ya tiene {$prima->formateado()} de prima y el valor nuevo sería "
            ."{$valor->formateado()}: la prima no puede superar lo que se está comprando."
        );
    }

    /**
     * El precio por unidad de área se guarda con seis decimales, y el valor
     * tiene que ser exactamente área × precio: lo exige un CHECK de la base.
     */
    public static function porValorQueNoCierraConElArea(string $codigo, Monto $pedido, Monto $daria): self
    {
        return new self(
            "El lote {$codigo}: con su área, el valor {$pedido->formateado()} no se puede representar "
            ."exacto —el más cercano es {$daria->formateado()}—. No se escribió nada."
        );
    }

    public static function porQuedarBajoElPrecioDeListaSinMotivo(string $codigo): self
    {
        return new self(
            "El lote {$codigo}: con el valor nuevo el precio quedaría por debajo del de lista, y ese "
            .'expediente no tiene motivo de descuento (R4). Eso es un descuento, no una corrección.'
        );
    }

    public static function porLoteSinCuotas(string $codigo): self
    {
        return new self(
            "El lote {$codigo} no tiene cuotas: se vendió de contado. No hay plan que pueda llevar la "
            .'diferencia, y cobrarla o devolverla es otro trámite.'
        );
    }

    /**
     * La red contra el dedo mal puesto: 3250000.00 en vez de 325000.00.
     */
    public static function porDiferenciaMasGrandeQueUnaCuota(string $codigo, Monto $diferencia, Monto $cuota): self
    {
        return new self(
            "El lote {$codigo}: la diferencia es de {$diferencia->formateado()} y su cuota es de "
            ."{$cuota->formateado()}. Esta corrección manda la diferencia a la última cuota, que es donde "
            .'va un residuo; una diferencia más grande que una cuota ya no es un residuo y cambia el plan '
            .'del cliente. Revisar el valor escrito; si de verdad es ese, se decide caso por caso.'
        );
    }

    public static function porLoteQueYaNoDebe(string $codigo): self
    {
        return new self(
            "El lote {$codigo} ya tiene pagada su última cuota. Subirle el valor sería inventarle una "
            .'cuota a un lote que terminó de pagarse: eso se habla con el cliente, no se corrige por acá.'
        );
    }

    public static function porUltimaCuotaQueNoAbsorbe(string $codigo, Monto $diferencia, Monto $debe): self
    {
        return new self(
            "El lote {$codigo}: hay que bajarle {$diferencia->formateado()} y a su última cuota solo le "
            ."falta {$debe->formateado()}. La última cuota tiene que quedar debiendo algo; si no, el "
            .'lote quedaría pagado y eso se cierra por «Registrar un pago», no por acá.'
        );
    }

    public static function porPlanViejoQueNoAbsorbe(string $codigo, int $constancia): self
    {
        return new self(
            "El lote {$codigo}: la reprogramación {$constancia} guarda un plan viejo cuya última cuota "
            .'es más chica que la diferencia. No se puede corregir sin dejar ese plan en negativo.'
        );
    }

    /**
     * La igualdad que contesta «¿por qué debo esto?»: valor − prima − lo
     * pagado a cuotas − lo abonado a capital = lo que deben sus cuotas.
     */
    public static function porExpedienteQueYaNoCuadraba(string $codigo, Monto $segunElContrato, Monto $segunLasCuotas): self
    {
        return new self(
            "El lote {$codigo} no cuadra desde ANTES de corregir: por el contrato y sus pagos debería "
            ."deber {$segunElContrato->formateado()} y sus cuotas suman {$segunLasCuotas->formateado()}. "
            .'Primero se mira eso (olympo:cuadrar-recibos y olympo:recuadrar-venta); corregir el valor '
            .'encima taparía la diferencia.'
        );
    }

    public static function porNoQuedarDondeDebia(string $codigo, Monto $quedo, Monto $esperado): self
    {
        return new self(
            "El lote {$codigo} quedó debiendo {$quedo->formateado()} y tenía que quedar en "
            ."{$esperado->formateado()}. No se escribió NADA. Es un error del cálculo, no de los datos: "
            .'reportarlo.'
        );
    }
}
