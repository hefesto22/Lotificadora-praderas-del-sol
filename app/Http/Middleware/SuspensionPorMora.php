<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cláusula Séptima: suspensión de acceso por mora mayor a 15 días.
 *
 * ═══ QUE HACE Y QUE NO ═══
 *
 * Muestra un aviso de pago y corta el acceso. **No borra un solo dato, no
 * toca la base y no desactiva a nadie.** Suspender no es rescindir: el día
 * que el cliente pague, se apaga la bandera del `.env` y todo sigue donde
 * estaba, sin migraciones ni restauraciones.
 *
 * ═══ POR QUE EL SUPER-ADMIN NUNCA QUEDA AFUERA ═══
 *
 * Por dos razones, y las dos son del contrato.
 *
 * La primera es práctica: alguien tiene que poder entrar a levantar la
 * suspensión, y si la palanca se traga al que la maneja, la única salida es
 * un servidor y una terminal.
 *
 * La segunda es una obligación: la Cláusula Décima dice que el cliente
 * puede pedir la exportación total de sus datos **bajo demanda**, y eso no
 * deja de valer porque esté atrasado en un pago. Un sistema que se cierra
 * sobre los datos de alguien para cobrarle es exactamente lo que esa
 * cláusula viene a evitar.
 *
 * ═══ POR QUE VA EN `authMiddleware` Y NO EN `middleware` ═══
 *
 * Para que la pantalla de login siga viva. Si corriera sobre todas las
 * rutas del panel, el super-admin no podría ni llegar a autenticarse — y la
 * suspensión se volvería irreversible desde el navegador.
 */
final class SuspensionPorMora
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->suspendido()) {
            return $next($request);
        }

        $usuario = $request->user();

        if ($usuario instanceof User && $usuario->hasRole(Roles::SUPER_ADMIN)) {
            return $next($request);
        }

        /*
         * 503 y no 403: esto es «vuelva más tarde», no «usted no tiene
         * permiso». La diferencia importa para quien lo lee y para
         * cualquier monitoreo que mire códigos.
         */
        abort(Response::HTTP_SERVICE_UNAVAILABLE, $this->aviso());
    }

    private function suspendido(): bool
    {
        return (bool) config('lotificadora.suspension.activa', false);
    }

    private function aviso(): string
    {
        $mensaje = config('lotificadora.suspension.mensaje');

        return is_string($mensaje) && trim($mensaje) !== ''
            ? $mensaje
            : 'El acceso al sistema está temporalmente suspendido. Comuníquese con Inversiones Olympo.';
    }
}
