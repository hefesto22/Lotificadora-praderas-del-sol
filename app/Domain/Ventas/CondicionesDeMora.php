<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Enums\ModalidadDeMora;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\Monto;

/**
 * Como cobra la mora ESTA lotificadora, en ESTE plan de pago.
 *
 * ═══ LAS CUATRO COLUMNAS QUE VIAJAN JUNTAS ═══
 *
 * Modalidad, monto, porcentaje y dias de gracia no significan nada por
 * separado: «200» es doscientos lempiras o doscientos por ciento segun la
 * modalidad. Van juntas en un value object para que ninguna funcion pueda
 * recibir tres de las cuatro, y para que la regla de coherencia se escriba
 * una sola vez —acá y en el CHECK de la migracion, que dicen lo mismo—.
 *
 * ═══ EL PORCENTAJE NO SIEMPRE ES ANUAL ═══
 *
 * ⚠️ En `TasaAnual` el numero es **anual**; en `PorcentajeMensual` es
 * **mensual**. Por eso la propiedad se llama `porcentaje` y no `tasa`, y por
 * eso nadie de afuera lo lee crudo: se piden `fraccionDiaria()` o
 * `fraccionMensual()`, que fallan si se llaman sobre la modalidad
 * equivocada. Un `->mensual()` de `TasaDeInteres` sobre un 3 % que ya era
 * mensual dividiria entre doce y cobraria la cuarta parte de lo pactado, sin
 * que nada chille.
 *
 * ═══ LOS DIAS DE GRACIA, Y COMO SE CUENTAN ═══
 *
 * Casi todo contrato serio da 5 o 10 dias antes de que la mora empiece a
 * correr. La decision que hay que escribir —porque las dos existen en el
 * rubro— es que pasa el dia 6 con 5 dias de gracia:
 *
 *  - La lectura dura: se cobran los 6 dias, porque la gracia se «perdio».
 *  - La lectura continua: se cobra 1 dia, porque la mora corre DESDE que
 *    termina la gracia.
 *
 * **Acá se implementa la continua**, y no por generosidad: la razon por la
 * que se recomendo la modalidad prorrateada por dias fue que la mora no
 * saltara —que un dia de atraso costara un dia—. La lectura dura reintroduce
 * exactamente ese salto en el borde de la gracia, y con el la discusion de
 * ventanilla que se queria evitar.
 */
final readonly class CondicionesDeMora
{
    /** Tope de cordura para la gracia: mas de tres meses no es gracia. */
    public const int GRACIA_MAXIMA = 90;

    public function __construct(
        public ModalidadDeMora $modalidad,
        public Monto $monto,
        public TasaDeInteres $porcentaje,
        public int $diasDeGracia,
    ) {
        $this->verificarCoherencia();

        if ($diasDeGracia < 0 || $diasDeGracia > self::GRACIA_MAXIMA) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'mora_dias_gracia',
                valor: (string) $diasDeGracia,
                razon: 'Los dias de gracia van de 0 a '.self::GRACIA_MAXIMA.'.'
            );
        }
    }

    /**
     * La de fabrica, y la de Praderas del Sol (R2).
     */
    public static function ninguna(): self
    {
        return new self(ModalidadDeMora::Ninguna, Monto::cero(), TasaDeInteres::cero(), 0);
    }

    /**
     * Las cuatro columnas tal como vienen de `planes_de_pago` o de
     * `compromisos`, que pueden ser NULL en las filas viejas.
     */
    public static function deBase(mixed $modalidad, mixed $monto, mixed $porcentaje, mixed $diasDeGracia): self
    {
        $cual = $modalidad instanceof ModalidadDeMora
            ? $modalidad
            : ModalidadDeMora::tryFrom(is_string($modalidad) ? $modalidad : '') ?? ModalidadDeMora::Ninguna;

        if (! $cual->cobra()) {
            return self::ninguna();
        }

        return new self(
            $cual,
            new Monto(is_string($monto) || is_int($monto) ? $monto : '0'),
            TasaDeInteres::deBase($porcentaje),
            is_numeric($diasDeGracia) ? (int) $diasDeGracia : 0,
        );
    }

    public function cobra(): bool
    {
        return $this->modalidad->cobra();
    }

    /**
     * La fraccion DIARIA, para `TasaAnual`.
     *
     * @return numeric-string
     */
    public function fraccionDiaria(): string
    {
        $this->exigirModalidad(ModalidadDeMora::TasaAnual, 'fraccionDiaria');

        return $this->porcentaje->diaria();
    }

    /**
     * La fraccion MENSUAL, para `PorcentajeMensual`. No divide entre doce:
     * el numero pactado YA es mensual.
     *
     * @return numeric-string
     */
    public function fraccionMensual(): string
    {
        $this->exigirModalidad(ModalidadDeMora::PorcentajeMensual, 'fraccionMensual');

        return $this->porcentaje->fraccion();
    }

    /**
     * Como se lee en el contrato y en el estado de cuenta.
     */
    public function descripcion(): string
    {
        $frase = match ($this->modalidad) {
            ModalidadDeMora::Ninguna           => 'Sin mora por atraso',
            ModalidadDeMora::FijaPorCuota      => $this->monto->formateado().' por cuota atrasada',
            ModalidadDeMora::FijaPorMes        => $this->monto->formateado().' por mes de atraso',
            ModalidadDeMora::PorcentajeMensual => $this->porcentaje->formateada().' mensual sobre la cuota',
            ModalidadDeMora::TasaAnual         => $this->porcentaje->formateada().' anual sobre el saldo vencido, por dias',
        };

        if (! $this->cobra() || $this->diasDeGracia === 0) {
            return $frase;
        }

        return $frase.', con '.$this->diasDeGracia.' '.($this->diasDeGracia === 1 ? 'dia' : 'dias').' de gracia';
    }

    /**
     * Las cuatro columnas, listas para `create()` o `update()`.
     *
     * Se arman acá y no en cada Service: `planes_de_pago` y `compromisos`
     * guardan lo mismo con los mismos nombres, y congelarlo al firmar es
     * copiar este arreglo.
     *
     * @return array{mora_modalidad: string, mora_monto: string, mora_porcentaje: string, mora_dias_gracia: int}
     */
    public function paraBase(): array
    {
        return [
            'mora_modalidad'   => $this->modalidad->value,
            'mora_monto'       => $this->monto->redondeado(),
            'mora_porcentaje'  => $this->porcentaje->paraBase(),
            'mora_dias_gracia' => $this->diasDeGracia,
        ];
    }

    public function igualA(self $otra): bool
    {
        return $this->modalidad === $otra->modalidad
            && $this->monto->igualA($otra->monto)
            && $this->porcentaje->igualA($otra->porcentaje)
            && $this->diasDeGracia === $otra->diasDeGracia;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * La misma regla que el CHECK `planes_de_pago_mora_coherente_chk`.
     *
     * Se verifica en los dos lados a proposito: la base impide que una fila
     * mienta y el value object impide que un calculo use un numero que no
     * corresponde. Postgres no puede dar un mensaje util; esto si.
     */
    private function verificarCoherencia(): void
    {
        if (! $this->modalidad->cobra()) {
            if (! $this->monto->esCero() || ! $this->porcentaje->esCero()) {
                throw ValueObjectInvalidoException::paraCampo(
                    campo: 'mora_modalidad',
                    valor: $this->modalidad->value,
                    razon: 'Sin mora no puede haber ni monto ni porcentaje cargados.'
                );
            }

            return;
        }

        if ($this->modalidad->usaMonto() && ($this->monto->esCero() || ! $this->porcentaje->esCero())) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'mora_monto',
                valor: $this->monto->redondeado(),
                razon: '«'.$this->modalidad->etiqueta().'» se configura con un monto en lempiras, no con un porcentaje.'
            );
        }

        if ($this->modalidad->usaTasa() && ($this->porcentaje->esCero() || ! $this->monto->esCero())) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'mora_porcentaje',
                valor: $this->porcentaje->redondeada(),
                razon: '«'.$this->modalidad->etiqueta().'» se configura con un porcentaje, no con un monto.'
            );
        }
    }

    private function exigirModalidad(ModalidadDeMora $esperada, string $metodo): void
    {
        if ($this->modalidad === $esperada) {
            return;
        }

        throw ValueObjectInvalidoException::paraCampo(
            campo: 'mora_modalidad',
            valor: $this->modalidad->value,
            razon: "{$metodo}() solo tiene sentido con «{$esperada->etiqueta()}»."
        );
    }
}
