<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Horizon\Horizon;

/**
 * Qué piezas de infraestructura usa ESTA instalación — 27-ago-2026.
 *
 * ═══ 🔴 POR QUE EXISTE: DOS COMANDOS AGENDADOS QUE NO PODIAN FUNCIONAR ═══
 *
 * Praderas del Sol corre **a propósito sin Redis y sin Horizon**: caché en
 * archivo, sesión en archivo, cola en base de datos. Pero la agenda mandaba
 * `health:check` cada minuto y `horizon:snapshot` cada cinco, y los dos
 * reventaban siempre con `Class "Redis" not found`.
 *
 * Lo que costó no fue el CPU. Fue que **escribieron 8 MB de log en un día**, y
 * el 26-ago un error de verdad —un 500 que dejó al cliente sin poder entrar—
 * quedó sepultado miles de líneas más arriba: un `tail -80` solo alcanzaba
 * para ver dos minutos de basura.
 *
 * > Un aviso que grita siempre es un aviso que nadie mira.
 *
 * ═══ SE LE PREGUNTA AL `.env`, NO AL `composer.json` ═══
 *
 * Los paquetes están instalados en todas las instalaciones —vienen en el
 * `composer.json` del producto—, así que `class_exists()` no distingue nada.
 * Lo que cambia de una lotificadora a otra es **qué drivers eligió cada una**,
 * y eso es exactamente lo que estas preguntas leen (Ley L0: nada específico de
 * un cliente vive en el código).
 *
 * ⚠️ Se mira el DRIVER de la conexión, no su nombre: una instalación puede
 * llamarle `principal` a un store que por dentro es Redis.
 */
final class Infraestructura
{
    /**
     * ¿Hay un Redis del que este sistema dependa?
     *
     * Basta con que UNA de las cuatro piezas lo use: si la sesión va por Redis
     * y Redis se cae, el sistema se cae igual aunque la caché sea de archivo.
     */
    public static function usaRedis(): bool
    {
        $cache = (string) config('cache.default');
        $emision = (string) config('broadcasting.default');

        if (self::laColaVaPorRedis()) {
            return true;
        }

        if (config("cache.stores.{$cache}.driver") === 'redis') {
            return true;
        }

        if (config('session.driver') === 'redis') {
            return true;
        }

        return config("broadcasting.connections.{$emision}.driver") === 'redis';
    }

    public static function laColaVaPorRedis(): bool
    {
        $cola = (string) config('queue.default');

        return config("queue.connections.{$cola}.driver") === 'redis';
    }

    /**
     * Horizon solo existe sobre una cola de Redis.
     *
     * Las dos condiciones hacen falta: `class_exists()` sin la cola diría que
     * sí en Praderas —el paquete está instalado— y la cola sin la clase diría
     * que sí en una instalación que lo haya sacado del `composer.json`.
     */
    public static function usaHorizon(): bool
    {
        return self::laColaVaPorRedis() && class_exists(Horizon::class);
    }
}
