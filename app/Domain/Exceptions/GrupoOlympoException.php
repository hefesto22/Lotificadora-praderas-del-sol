<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Excepción raíz del dominio Grupo Olympo.
 *
 * Toda excepción específica del dominio (facturación, inventario,
 * fiscal, etc.) debe heredar de aquí — nunca usar excepciones
 * genéricas de PHP para errores de negocio (§7.7 del documento).
 *
 * Uso:
 *   throw new StockInsuficienteException(productoId: 42, ...)
 * en lugar de:
 *   throw new \RuntimeException("Stock insuficiente")
 */
abstract class GrupoOlympoException extends RuntimeException {}
