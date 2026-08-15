<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use DateTimeImmutable;
use Stringable;

/**
 * Clave de Autorización de Impresión (CAI) del SAR de Honduras.
 *
 * ═══ 🔴 EL FORMATO NO SE VALIDA, Y ESA ES LA DECISIÓN ═══
 *
 * Hasta el 13-ago-2026 esta clase exigía
 * `XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX` en hexadecimal. **Ese patrón no
 * está publicado en ninguna fuente oficial.** El Reglamento del Régimen de
 * Facturación —Acuerdo 481-2017, Art. 4, num. 7— dice apenas: «serie
 * alfanumérica generada electrónicamente por la Administración
 * Tributaria». Ni el articulado, ni el formulario SAR-925 —donde se teclea
 * la CAI—, ni la documentación del SAR dicen cuántos caracteres lleva, ni
 * cómo se agrupa, ni con qué alfabeto.
 *
 * Se sacó porque **el riesgo va en la dirección peor**: una validación
 * inventada rechaza una CAI de verdad, y la persona que la está cargando
 * no tiene forma de saber que el equivocado es el sistema. Guardarla como
 * texto no rompe nada; rechazarla sí.
 *
 * Lo único que se exige es que no venga vacía y que las fechas cierren.
 *
 * ⚠️ El día que se vea una autorización real emitida por el SAR, ahí se
 * decide si vale la pena validar la forma. Antes no.
 *
 * ═══ LO QUE SÍ DICE LA NORMA ═══
 *
 * La autorización se otorga **por punto de emisión y por tipo de
 * documento** (Art. 59), con su rango de correlativos, y dura **un año
 * como máximo** (Art. 62) — eran dos bajo el Acuerdo 189-2014, que está
 * derogado. Vencida la fecha límite, los correlativos que sobraron ya no
 * se pueden usar y hay que reportar la no utilización dentro de los diez
 * días hábiles siguientes (Art. 42).
 */
final readonly class CAI implements Stringable
{
    /**
     * Lo más largo que se ha visto documentado, con margen. No es un
     * requisito de la norma: es un tope de sanidad para que un pegado
     * accidental de media pantalla no entre a la base.
     */
    private const int LARGO_MAXIMO = 100;

    public function __construct(
        public string $valor,
        public DateTimeImmutable $vigenteDesde,
        public DateTimeImmutable $vigenteHasta,
    ) {
        $limpio = mb_strtoupper(trim($valor));

        if ($limpio === '') {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'cai',
                valor: $valor,
                razon: 'La CAI no puede ir vacía: es lo que autoriza el rango de facturas.'
            );
        }

        if (mb_strlen($limpio) > self::LARGO_MAXIMO) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'cai',
                valor: $valor,
                razon: 'La CAI es demasiado larga. Copiala tal como sale en la autorización del SAR.'
            );
        }

        if ($vigenteHasta <= $vigenteDesde) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'vigencia',
                valor: $vigenteDesde->format('Y-m-d').' → '.$vigenteHasta->format('Y-m-d'),
                razon: 'La fecha límite de emisión debe ser posterior a la fecha en que se autorizó.'
            );
        }
    }

    public function estaVigente(?DateTimeImmutable $referencia = null): bool
    {
        $hoy = $referencia ?? new DateTimeImmutable;

        return $hoy >= $this->vigenteDesde && $hoy <= $this->vigenteHasta;
    }

    public function diasParaVencer(?DateTimeImmutable $referencia = null): int
    {
        $hoy = $referencia ?? new DateTimeImmutable;

        $diff = $hoy->diff($this->vigenteHasta);

        return $hoy > $this->vigenteHasta
            ? -1 * (int) $diff->days
            : (int) $diff->days;
    }

    public function __toString(): string
    {
        return $this->valor;
    }
}
