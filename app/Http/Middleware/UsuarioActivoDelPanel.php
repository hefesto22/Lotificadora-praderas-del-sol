<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que Filament hace en el panel, para los documentos que viven afuera.
 *
 * ═══ POR QUE NO SE USA EL MIDDLEWARE `auth` DE LARAVEL ═══
 *
 * Filament maneja la autenticación con SUS nombres de ruta
 * (`filament.admin.auth.login`), así que en esta aplicación **no existe
 * ninguna ruta llamada `login`**. El middleware `auth` intenta redirigir ahí
 * al invitado, no la encuentra y lanza RouteNotFoundException: una pantalla de
 * error 500 que —con `APP_DEBUG` en true— muestra el stack trace y la
 * consulta, con el nombre del cliente adentro. O sea, exactamente el documento
 * que se estaba protegiendo. Lo agarró un test, no producción.
 *
 * ═══ POR QUE UN MIDDLEWARE Y NO DOS `if` EN CADA CONTROLADOR ═══
 *
 * El recibo y el estado de cuenta necesitan lo mismo, y el día que aparezca el
 * tercer documento va a necesitarlo también. Copiado en cada controlador, uno
 * se queda sin la comprobación y nadie lo nota hasta que un usuario dado de
 * baja imprime el saldo de un cliente.
 *
 * ═══ CADA SITUACION RESPONDE DISTINTO ═══
 *
 *  - **Invitado** → al panel, que sabe pedir el login. Pasa de verdad: la
 *    sesión se vence mientras alguien cobra, y mandarlo a una pared de 403 con
 *    un cliente enfrente no ayuda a nadie.
 *  - **Cuenta dada de baja** → 403. Volver a entrar no lo va a arreglar.
 *
 * El permiso concreto —`View:Recibo`, `View:Venta`— lo comprueba cada
 * controlador con su Gate: eso sí cambia según el documento.
 */
final class UsuarioActivoDelPanel
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        // No se usa el nombre de la ruta de login de Filament para no atarse
        // al id del panel: la raíz ya redirige a donde haya que identificarse.
        if (! $usuario instanceof User) {
            return redirect()->guest('/');
        }

        if ($usuario->getAttribute('is_active') !== true) {
            abort(403, 'Tu cuenta no está activa.');
        }

        return $next($request);
    }
}
