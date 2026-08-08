<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Cómo entra el dinero (R11).
 *
 * **Cheque no se recibe** — la contratante lo dejó sin marcar a propósito y no
 * se agrega «por si acaso»: una forma de pago que el sistema ofrece es una
 * forma de pago que alguien va a usar.
 *
 * ═══ TARJETA (8-ago-2026) ═══
 *
 * R11 contestó tres. La cuarta la agregó Mauricio pensando en las demás
 * lotificadoras que van a usar el sistema, no en Praderas del Sol. **El recibo
 * sale por el monto entero**: lo que el POS descuenta de comisión no se
 * calcula ni se imprime, y esa fue la decisión, no un olvido. El día que la
 * comisión tenga que salir en el papel hay que preguntar antes quién la
 * absorbe — si el cliente entrega L 96,875.00 o L 96,875.00 más el 3%.
 *
 * En todo lo que no es efectivo el **número de referencia es obligatorio**:
 * sin él no hay cómo cruzar el recibo contra el estado de cuenta del banco. En
 * tarjeta esa referencia es el número de autorización del POS. En efectivo no
 * aplica, porque no hay nada que cruzar.
 *
 * ⚠️ **Agregar un `case` acá NO alcanza.** `recibos_forma_valida_chk` guarda
 * la lista dentro de la base: una instalación nueva nace bien —el CHECK se
 * arma de `valores()`— pero una que ya migró rechaza la forma nueva hasta que
 * corra un ALTER. Ver
 * `2026_08_08_100000_agregar_tarjeta_a_formas_de_pago.php`, que es el molde.
 */
enum FormaDePago: string
{
    case Efectivo = 'efectivo';
    case Transferencia = 'transferencia';
    case Deposito = 'deposito';
    case Tarjeta = 'tarjeta';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $forma): string => $forma->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo      => 'Efectivo',
            self::Transferencia => 'Transferencia',
            self::Deposito      => 'Depósito bancario',
            self::Tarjeta       => 'Tarjeta',
        };
    }

    /**
     * ¿Hace falta el número de referencia?
     */
    public function exigeReferencia(): bool
    {
        return $this !== self::Efectivo;
    }

    public function color(): string
    {
        return match ($this) {
            self::Efectivo      => 'success',
            self::Transferencia => 'info',
            self::Deposito      => 'warning',
            self::Tarjeta       => 'primary',
        };
    }
}
