<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Como se cobra el atraso, cuando la lotificadora decide cobrarlo.
 *
 * ═══ POR QUE HAY CUATRO Y NO UNA ═══
 *
 * Olympo es un producto: cada lotificadora ya tiene su contrato escrito y su
 * costumbre de ventanilla, y ninguna va a cambiarla porque el sistema solo
 * sepa hacer una. Las cuatro que estan aca son las que se usan de verdad en
 * el rubro. La quinta opcion —`Ninguna`— es la de fabrica, y es la de
 * Praderas del Sol (R2, contestada por la contratante el 3-ago-2026).
 *
 * ═══ QUE HACE CADA UNA, SOBRE UNA CUOTA DE L 14,583.33 ═══
 *
 *   dias   fija/cuota   fija/mes    3 % mensual   24 % anual x dias
 *   (L 200)      (L 200)   sobre la cuota
 *      1      200.00      200.00        437.50            9.59
 *      5      200.00      200.00        437.50           47.95
 *     30      200.00      200.00        437.50          287.67
 *     60      200.00      400.00        875.00          575.34
 *     90      200.00      600.00      1,312.50          863.01
 *
 * `TasaAnual` es la que recomiende y por dos razones concretas: **escala con
 * el monto** —una cuota de 14,583 y otra de 96,875 no pueden pagar lo mismo—
 * y **no salta**: con las modalidades "por mes", atrasarse un dia cuesta lo
 * mismo que atrasarse veintinueve, lo que genera discusiones en ventanilla e
 * incentiva justo lo contrario de lo que se busca.
 *
 * ═══ EL NUMERO NUNCA SIGNIFICA DOS COSAS ═══
 *
 * Dos modalidades se configuran con un MONTO en lempiras y dos con una TASA
 * en porcentaje. Por eso `planes_de_pago` tiene dos columnas separadas y un
 * CHECK que exige que la que no corresponde este en cero: una sola columna
 * `mora_valor` obligaria a mirar la modalidad para saber si "200" son
 * doscientos lempiras o doscientos por ciento.
 *
 * La lista es la fuente de verdad: la migracion arma su CHECK a partir de
 * `valores()`, igual que `FormaDePago` y `ConceptoDeRecibo`.
 */
enum ModalidadDeMora: string
{
    /** La de fabrica, y la de Praderas del Sol (R2). El atraso no cuesta. */
    case Ninguna = 'ninguna';

    /** Un monto fijo, una sola vez por cuota atrasada. */
    case FijaPorCuota = 'fija_por_cuota';

    /** Un monto fijo por cada mes —o fraccion— de atraso. */
    case FijaPorMes = 'fija_por_mes';

    /** Un porcentaje de la cuota por cada mes —o fraccion— de atraso. */
    case PorcentajeMensual = 'porcentaje_mensual';

    /** Una tasa anual sobre el saldo vencido, prorrateada por dias reales. */
    case TasaAnual = 'tasa_anual';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $modalidad): string => $modalidad->value, self::cases());
    }

    /**
     * Las que efectivamente cobran algo.
     *
     * @return list<self>
     */
    public static function queCobran(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $modalidad): bool => $modalidad->cobra()));
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ninguna           => 'Sin mora',
            self::FijaPorCuota      => 'Monto fijo por cuota atrasada',
            self::FijaPorMes        => 'Monto fijo por mes de atraso',
            self::PorcentajeMensual => 'Porcentaje mensual sobre la cuota',
            self::TasaAnual         => 'Tasa anual sobre el saldo vencido, por dias',
        };
    }

    /**
     * La frase que se lee debajo del campo en el formulario.
     */
    public function ayuda(): string
    {
        return match ($this) {
            self::Ninguna           => 'El atraso no genera ningun cargo. Es lo que hace el sistema hoy.',
            self::FijaPorCuota      => 'Se cobra una sola vez por cuota, sin importar cuanto dure el atraso.',
            self::FijaPorMes        => 'Se cobra por cada mes de atraso. Una fraccion de mes cuenta como mes entero.',
            self::PorcentajeMensual => 'Un porcentaje de lo que se debe de esa cuota, por cada mes de atraso.',
            self::TasaAnual         => 'La mas justa de las cuatro: crece dia por dia y en proporcion a lo que se debe.',
        };
    }

    public function cobra(): bool
    {
        return $this !== self::Ninguna;
    }

    /**
     * ¿Se configura con un monto en lempiras?
     */
    public function usaMonto(): bool
    {
        return $this === self::FijaPorCuota || $this === self::FijaPorMes;
    }

    /**
     * ¿Se configura con un porcentaje?
     */
    public function usaTasa(): bool
    {
        return $this === self::PorcentajeMensual || $this === self::TasaAnual;
    }

    /**
     * ¿El cargo crece con el tiempo?
     *
     * La unica que no: `FijaPorCuota`. Sirve para avisar en pantalla que
     * dejar pasar tres meses cuesta lo mismo que pagar manana.
     */
    public function creceConElTiempo(): bool
    {
        return $this !== self::Ninguna && $this !== self::FijaPorCuota;
    }

    public function color(): string
    {
        return match ($this) {
            self::Ninguna           => 'gray',
            self::FijaPorCuota      => 'info',
            self::FijaPorMes        => 'warning',
            self::PorcentajeMensual => 'warning',
            self::TasaAnual         => 'success',
        };
    }
}
