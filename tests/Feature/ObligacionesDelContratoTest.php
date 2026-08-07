<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Las obligaciones que nacen del contrato, no del dominio
|--------------------------------------------------------------------------
| El §1.4 del documento rector las llama «no opcionales» porque no salen de
| ninguna regla del negocio: salen de cláusulas firmadas. Nadie las va a
| pedir en una reunión, nadie las va a extrañar en una demo, y el día que
| falten va a ser tarde — cuando haya que restaurar un respaldo que no
| existe, o exportarle los datos a alguien que se está yendo.
|
| Por eso tienen pruebas: son justo las que se caen sin que nadie se entere.
*/

describe('Cláusula Novena: respaldos automáticos diarios', function (): void {
    /*
    | «Respaldos automáticos diarios de base de datos y archivos, retención
    | mínima 30 días». El paquete instalado no respalda nada por su cuenta:
    | sin la tarea agendada, `config/backup.php` es decoración.
    */
    test('el respaldo y su limpieza están agendados', function (): void {
        $comandos = collect(app(Schedule::class)->events())
            ->map(static fn (Event $evento): string => (string) $evento->command)
            ->implode("\n");

        expect($comandos)->toContain('backup:run')
            ->and($comandos)->toContain('backup:clean');
    });

    test('la retención llega a los 30 días que exige el contrato', function (): void {
        expect((int) config('backup.cleanup.default_strategy.keep_daily_backups_for_days'))
            ->toBeGreaterThanOrEqual(30);
    });
});

describe('Cláusula Séptima: suspensión por mora', function (): void {
    beforeEach(function (): void {
        $this->seed(RoleSeeder::class);
    });

    test('sin suspensión, el panel funciona normal', function (): void {
        config(['lotificadora.suspension.activa' => false]);

        $this->actingAs(crearUsuarioConRol(Roles::ADMINISTRADORA))
            ->get('/ventas')
            ->assertSuccessful();
    });

    /*
    | 503 y no 403: es «vuelva más tarde», no «usted no tiene permiso». La
    | diferencia la lee una persona y también cualquier monitoreo.
    */
    test('suspendido, el acceso se corta con el aviso de pago', function (): void {
        config([
            'lotificadora.suspension.activa'  => true,
            'lotificadora.suspension.mensaje' => 'Aviso de pago de prueba.',
        ]);

        $this->actingAs(crearUsuarioConRol(Roles::ADMINISTRADORA))
            ->get('/ventas')
            ->assertStatus(503);
    });

    /*
    | El super-admin NUNCA queda afuera, y no es una comodidad: la Cláusula
    | Décima obliga a poder exportarle todo al cliente bajo demanda, y eso no
    | deja de valer porque esté atrasado. Un sistema que se cierra sobre los
    | datos de alguien para cobrarle es lo que esa cláusula viene a evitar.
    |
    | Además, si la palanca se tragara al que la maneja, levantarla exigiría
    | un servidor y una terminal.
    */
    test('el super-admin sigue entrando aunque esté suspendido', function (): void {
        config(['lotificadora.suspension.activa' => true]);

        actingAsAdmin();

        $this->get('/ventas')->assertSuccessful();
    });

    /*
    | Los documentos viven fuera del panel y tienen su propio grupo de
    | middleware. Si la suspensión no llegara ahí, un cliente suspendido
    | seguiría imprimiendo recibos.
    */
    test('los documentos también quedan suspendidos', function (): void {
        /*
         * Tiene que existir un recibo de verdad: el binding de la ruta
         * resuelve antes que este middleware, asi que pedir un id inventado
         * daria 404 y la prueba pasaria por el motivo equivocado — o
         * fallaria, como fallo la primera vez.
         *
         * Firmar una venta ya emite el recibo de la prima (R5), asi que no
         * hace falta cobrar nada aparte.
         */
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
        $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

        app(RegistroDeVentas::class)->activar(
            proyecto: $proyecto,
            lotes: [$lote],
            clientes: [Cliente::factory()->create(['nombre' => 'Leticia Romero'])],
            prima: new Monto('50000.00'),
            plazoMeses: 12,
            diaPago: 5,
        );

        $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::Prima)->firstOrFail();

        config(['lotificadora.suspension.activa' => true]);

        $this->actingAs(crearUsuarioConRol(Roles::ADMINISTRADORA))
            ->get('/documentos/recibo/'.$recibo->getKey())
            ->assertStatus(503);
    });
});

describe('Cláusula Décima: exportación total de datos', function (): void {
    beforeEach(function (): void {
        $this->salida = storage_path('app/pruebas-exportacion');

        File::deleteDirectory($this->salida);
    });

    afterEach(function (): void {
        File::deleteDirectory($this->salida);
    });

    test('exporta las tablas del negocio a CSV', function (): void {
        $this->artisan('praderas:exportar-todo', [
            '--salida'  => $this->salida,
            '--sin-zip' => true,
        ])->assertSuccessful();

        $carpetas = File::directories($this->salida);

        expect($carpetas)->toHaveCount(1);

        $archivos = collect(File::files($carpetas[0]))
            ->map(static fn ($archivo): string => $archivo->getFilename())
            ->all();

        expect($archivos)->toContain('clientes.csv')
            ->and($archivos)->toContain('ventas.csv')
            ->and($archivos)->toContain('recibos.csv')
            ->and($archivos)->toContain('lotes.csv');
    });

    /*
    | El CSV termina en un correo o en un pendrive. Una contraseña hasheada
    | ahí es una contraseña regalada, y el contrato pide los datos del
    | cliente, no las credenciales de quienes lo atienden.
    */
    test('no exporta las contraseñas', function (): void {
        $this->artisan('praderas:exportar-todo', [
            '--salida'  => $this->salida,
            '--sin-zip' => true,
        ])->assertSuccessful();

        $carpeta = File::directories($this->salida)[0];
        $cabecera = strtok(File::get($carpeta.'/users.csv'), "\n");

        expect($cabecera)->not->toContain('password')
            ->and($cabecera)->not->toContain('remember_token')
            ->and($cabecera)->toContain('email');
    });

    /*
    | Sin el BOM, Excel en Windows lee los acentos mal y el cliente recibe un
    | archivo con los nombres rotos. Es la diferencia entre entregar los
    | datos y entregar un problema.
    */
    test('los CSV llevan el BOM de UTF-8 para que Excel no rompa los acentos', function (): void {
        $this->artisan('praderas:exportar-todo', [
            '--salida'  => $this->salida,
            '--sin-zip' => true,
        ])->assertSuccessful();

        $carpeta = File::directories($this->salida)[0];

        expect(substr(File::get($carpeta.'/clientes.csv'), 0, 3))->toBe("\xEF\xBB\xBF");
    });
});

describe('Cláusula Segunda g-i: la leyenda del recibo', function (): void {
    /*
    | El contrato pide el recibo interno correlativo «con NO VÁLIDO PARA
    | CRÉDITO FISCAL». Son palabras del contrato, no una paráfrasis, y son
    | las que evitan que alguien intente presentar este papel ante el SAR.
    */
    test('el papel lleva la leyenda con las palabras del contrato', function (): void {
        $blade = File::get(resource_path('views/documentos/recibo.blade.php'));

        expect($blade)->toContain('NO VÁLIDO PARA CRÉDITO FISCAL');
    });
});
