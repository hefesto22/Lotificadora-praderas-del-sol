<?php

declare(strict_types=1);

use App\Http\Controllers\EstadoDeCuentaController;
use App\Http\Controllers\ImprimirReciboController;
use App\Http\Controllers\PlanoPublicoController;
use App\Http\Controllers\RegistrarInteresController;
use App\Http\Middleware\SuspensionPorMora;
use App\Http\Middleware\UsuarioActivoDelPanel;
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
| Por qué viven acá y no dentro del panel: `Panel::routes()` existe pero el
| propio paquete no lo consume en ningún lado que se pueda leer, y el prefijo
| con que nombra las rutas no está documentado. Una ruta común con su
| middleware propio hace lo mismo y se puede seguir leyendo el código.
|
| `UsuarioActivoDelPanel` y NO el `auth` de Laravel: en esta aplicación no
| existe una ruta llamada `login` —Filament usa las suyas— y `auth` intenta
| redirigir ahí, no la encuentra y termina en un error 500 que muestra la
| consulta con datos del cliente adentro. Lo agarró un test.
|
*/

Route::middleware(UsuarioActivoDelPanel::class, SuspensionPorMora::class)
    ->prefix('documentos')
    ->name('documentos.')
    ->group(function (): void {
        Route::get('recibo/{recibo}', ImprimirReciboController::class)->name('recibo');
        Route::get('estado-de-cuenta/{venta}', EstadoDeCuentaController::class)->name('estado-de-cuenta');
    });

/*
|--------------------------------------------------------------------------
| El plano que se le manda al cliente
|--------------------------------------------------------------------------
|
| SIN autenticación: son las dos únicas rutas de este sistema que abre gente
| que no conocemos. Van bajo /plano, que ningún Resource de Filament ocupa.
|
| `SuspensionPorMora` SÍ se aplica (Cl. Séptima). Si la lotificadora deja de
| pagarle a Olympo se le corta el panel, y su vidriera pública es parte del
| mismo servicio: dejarla viva sería seguir prestando el servicio suspendido.
|
| ⚠️ `UsuarioActivoDelPanel` NO va acá, obviamente. Y el límite del POST no es
| decorativo: un formulario abierto a internet sin freno es una tabla con diez
| mil filas basura a la semana. Seis por minuto y por IP alcanza de sobra para
| una persona que escribe de verdad.
|
*/
Route::middleware(SuspensionPorMora::class)
    ->prefix('plano')
    ->name('plano.')
    ->group(function (): void {
        Route::get('{slug}', PlanoPublicoController::class)->name('publico');

        Route::post('{slug}/interes', RegistrarInteresController::class)
            ->middleware('throttle:6,1')
            ->name('interes');
    });
