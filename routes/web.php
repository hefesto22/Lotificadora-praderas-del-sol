<?php

declare(strict_types=1);

use App\Http\Controllers\ImprimirReciboController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Filament toma control de "/" porque el panel está configurado con
| ->path('/') en AdminPanelProvider. NO definir aquí Route::get('/') —
| Filament lo perderá si la ruta web tiene mayor prioridad.
|
| Este archivo queda disponible para rutas custom adicionales (webhooks,
| callbacks OAuth, endpoints públicos puntuales) que NO conflictúen con
| las rutas de Filament.
|
| Las rutas internas del panel (/login, /dashboard, /users, /shield/roles,
| /horizon, etc.) las gestiona Filament automáticamente.
|
|--------------------------------------------------------------------------
| Documentos que se imprimen
|--------------------------------------------------------------------------
|
| Van bajo /documentos, que ningún Resource de Filament ocupa: los Resources
| toman el slug de su modelo y no hay ninguno llamado así.
|
| Por qué acá y no dentro del panel con `Panel::routes()`: ese método existe
| pero el propio paquete no lo consume en ningún lado que se pueda leer, y
| el prefijo con que nombra las rutas no está documentado. Una ruta común con
| la autorización escrita en el controlador hace lo mismo y se puede seguir
| leyendo el código.
|
*/

/*
 * SIN el middleware `auth`, y no por descuido.
 *
 * Filament maneja la autenticación con SUS nombres de ruta
 * (`filament.admin.auth.login`), así que en esta aplicación no existe ninguna
 * ruta llamada `login`. El middleware `auth` de Laravel intenta redirigir ahí
 * al invitado, no la encuentra y lanza RouteNotFoundException: una pantalla de
 * error 500 que —con APP_DEBUG en true— muestra el stack trace y la consulta,
 * con el nombre del cliente adentro. O sea, exactamente el documento que se
 * estaba protegiendo.
 *
 * El controlador resuelve las tres situaciones sin depender de un nombre de
 * ruta: invitado al panel, cuenta dada de baja 403, sin permiso 403.
 */
Route::prefix('documentos')
    ->name('documentos.')
    ->group(function (): void {
        Route::get('recibo/{recibo}', ImprimirReciboController::class)->name('recibo');
    });
