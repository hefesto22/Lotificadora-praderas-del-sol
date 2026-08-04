<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Que clase de compromiso ata a un cliente con un lote.
 *
 * Son dos y se corresponden con los dos estados comprometidos de
 * EstadoLote: un apartado deja el lote en `apartado`, una venta lo deja
 * en `vendido`. La correspondencia no es casual y hay un test que la
 * verifica: si algun dia se agrega un tipo, tiene que decidirse tambien
 * en que estado deja al lote.
 */
enum TipoCompromiso: string
{
    case Apartado = 'apartado';
    case Venta = 'venta';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Apartado => 'Apartado',
            self::Venta    => 'Venta',
        };
    }

    /**
     * En que estado queda el lote mientras este compromiso este vigente.
     */
    public function estadoDelLote(): EstadoLote
    {
        return match ($this) {
            self::Apartado => EstadoLote::Apartado,
            self::Venta    => EstadoLote::Vendido,
        };
    }

    /**
     * ¿Se puede soltar el lote sin rescindir un contrato?
     *
     * Un apartado se libera y listo. Una venta no: el §8.2 congela el
     * valor y deshacerla es una rescision, que es otro tramite y
     * probablemente otra conversacion con la contratante.
     */
    public function seLibera(): bool
    {
        return $this === self::Apartado;
    }
}
