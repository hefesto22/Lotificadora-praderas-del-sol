<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\LogRecord;

/**
 * "Tap" de Monolog que redacta PII antes de persistir logs.
 *
 * Se aplica al canal `stack` en config/logging.php. Filtra:
 *  - RTN (14 dígitos consecutivos)
 *  - Tarjetas de crédito (16 dígitos consecutivos)
 *  - Passwords y tokens en formato key=value
 *  - Authorization headers tipo Bearer
 *
 * Cumple §15.1 — nunca loguear datos sensibles.
 */
final class FilterSensitiveData
{
    /** @var array<string, string> */
    private const PATTERNS = [
        // RTN: 14 dígitos consecutivos
        '/\b\d{14}\b/' => '[RTN_REDACTADO]',
        // Tarjetas: 16 dígitos consecutivos (con o sin espacios/guiones)
        '/\b(?:\d[ -]*?){13,19}\d\b/' => '[TARJETA_REDACTADA]',
        // Passwords en JSON o query strings
        '/("?password"?\s*[:=]\s*"?)([^",\s}]+)/' => '$1[REDACTADO]',
        // Tokens/secrets
        '/("?(?:api_token|access_token|secret|api_key)"?\s*[:=]\s*"?)([^",\s}]+)/' => '$1[REDACTADO]',
        // Authorization headers
        '/(Bearer\s+)([A-Za-z0-9._\-]+)/' => '$1[REDACTADO]',
    ];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            // Solo handlers que implementan ProcessableHandlerInterface
            // soportan pushProcessor. Los demás (NullHandler, etc.) se saltan.
            if (! $handler instanceof ProcessableHandlerInterface) {
                continue;
            }

            $handler->pushProcessor(static function (LogRecord $record): LogRecord {
                $contextoJson = (string) json_encode($record->context, JSON_UNESCAPED_UNICODE);
                $mensaje = $record->message;

                foreach (self::PATTERNS as $pattern => $replacement) {
                    $contextoJson = (string) preg_replace($pattern, $replacement, $contextoJson);
                    $mensaje = (string) preg_replace($pattern, $replacement, $mensaje);
                }

                /** @var array<string, mixed>|null $contexto */
                $contexto = json_decode($contextoJson, true);

                // LogRecord es readonly — usamos with() para crear una nueva instancia.
                return $record->with(
                    message: $mensaje,
                    context: $contexto ?? [],
                );
            });
        }
    }
}
