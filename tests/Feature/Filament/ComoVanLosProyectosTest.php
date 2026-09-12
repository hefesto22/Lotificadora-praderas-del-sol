<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeRescisiones;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Widgets\ComoVanLosProyectos;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Gasto;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Support\Roles;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Costo contra ingreso — 11-sep-2026
|--------------------------------------------------------------------------
| «Hoy hay que sumar a mano lo cobrado y lo gastado para saber cómo va el
| proyecto.» Los dos números existían en pantallas distintas desde el 11-ago.
|
| 🔴 LO QUE ESTE ARCHIVO CUIDA DE VERDAD es que el tablero no reviente. `Monto`
| no admite negativos —`restar()` lanza— y un proyecto que todavía no recuperó
| lo invertido es el caso NORMAL de cualquier lotificadora a mitad de plazo.
| Escrito de la forma obvia, este widget tumbaba el Escritorio el primer día
| que alguien cargara un gasto más grande que lo cobrado, que es el primer día.
|
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a 12 meses, que dan cuotas de L 25,000.00. La prima emite
| su recibo, así que el proyecto arranca con L 50,000.00 recuperados.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->pagos = app(RegistroDePagos::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS', 'nombre' => 'Praderas del Sol']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'nombre' => 'A']);
    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $this->proyecto,
        lotes: [$lote],
        clientes: [$this->cliente],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $this->renglon = $this->venta->compromisos()->firstOrFail();

    $this->cobrar = fn (string $monto): Recibo => $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto($monto),
        forma: FormaDePago::Efectivo,
    );

    $this->gastar = fn (string $monto): Gasto => Gasto::factory()
        ->delProyecto($this->proyecto)
        ->de($monto)
        ->create();
});

/*
| 🔴🔴 EL TEST QUE JUSTIFICA EL ARCHIVO.
|
| Gastó más de lo que lleva cobrado, que es donde está cualquier proyecto en
| sus primeros años. Si la resta se escribe `recuperado->restar(invertido)`,
| `Monto` lanza `ValueObjectInvalidoException` y el Escritorio entero queda en
| blanco — no este cuadro: el Escritorio, porque un widget que revienta se
| lleva la página.
*/
test('cuando todavía no se recupera lo invertido, lo dice sin reventar', function (): void {
    ($this->gastar)('200000.00');
    ($this->cobrar)('25000.00');

    Livewire::test(ComoVanLosProyectos::class)
        ->assertSee('Invertido')
        ->assertSee('L. 200,000.00')
        // La prima (50,000) más la cuota que se acaba de cobrar (25,000).
        ->assertSee('Recuperado')
        ->assertSee('L. 75,000.00')
        ->assertSee('Falta por recuperar')
        ->assertSee('L. 125,000.00')
        /*
         * Con un proyecto solo el pie explica el número en vez de repetir el
         * nombre del residencial: el encabezado del Escritorio ya lo dice, y
         * decirlo dos veces en la misma pantalla ensucia en vez de informar.
         * El nombre sí se comprueba en el test de dos proyectos, que es donde
         * el desglose lo necesita.
         */
        ->assertSee('Lo recuperado menos lo invertido');
});

/*
| 🔴🔴 ESTE TEST NACIO DE MIRAR LA PANTALLA, NO DE LEER EL CODIGO.
|
| En pruebas, el mismo día que entró, el cuadro decía «Ya se recuperó, y
| sobra L. 7,810,997.00» —en verde, con su palomita— sobre un proyecto donde
| nadie había cargado un solo gasto. La resta era correcta: 7,810,997 menos
| cero. La conclusión era falsa.
|
| Es la peor clase de número en un tablero: uno que está bien calculado y
| dice algo que no es cierto, porque ese se cree.
*/
test('sin un solo gasto cargado no declara ningún resultado', function (): void {
    Livewire::test(ComoVanLosProyectos::class)
        ->assertSee('Sin gastos cargados')
        ->assertDontSee('Ya se recuperó, y sobra')
        ->assertDontSee('Falta por recuperar')
        // Ni promete un cierre que no tiene contra qué cerrar.
        ->assertDontSee('el proyecto cierra en');
});

test('cuando ya se recuperó, cambia el rótulo y no el signo', function (): void {
    ($this->gastar)('30000.00');

    Livewire::test(ComoVanLosProyectos::class)
        ->assertSee('Ya se recuperó, y sobra')
        // 50,000 de prima menos 30,000 de gasto.
        ->assertSee('L. 20,000.00')
        ->assertDontSee('Falta por recuperar');
});

/*
| El empate tiene su propio rótulo porque «y sobra L. 0.00» es una cifra
| correcta y una frase tonta.
*/
test('el empate exacto se llama por su nombre', function (): void {
    ($this->gastar)('50000.00');

    Livewire::test(ComoVanLosProyectos::class)
        ->assertSee('Va justo a la par')
        ->assertDontSee('Ya se recuperó, y sobra');
});

/*
| Lo mismo que cuida `EscritorioTest`: un recibo anulado conserva su fila y su
| número —la serie no puede tener huecos— pero su dinero volvió a deberse.
| Sumarlo diría que el proyecto recuperó una plata que no recuperó.
*/
test('un recibo anulado no cuenta como recuperado', function (): void {
    ($this->gastar)('200000.00');
    $recibo = ($this->cobrar)('25000.00');

    $this->pagos->anular($recibo, 'Se tecleó el monto equivocado');

    Livewire::test(ComoVanLosProyectos::class)
        // Queda solo la prima.
        ->assertSee('L. 50,000.00')
        ->assertSee('L. 150,000.00');
});

/*
| Una devolución es dinero que volvió al cliente. Es la misma cuenta que hace
| `CorteDeCajaDeHoy` con el egreso del día: si no se restara, el proyecto
| diría que recuperó algo que ya devolvió.
*/
test('la devolución se resta de lo recuperado', function (): void {
    ($this->gastar)('200000.00');

    app(RegistroDeRescisiones::class)->rescindir(
        lote: $this->renglon,
        devuelto: new Monto('20000.00'),
        forma: FormaDePago::Efectivo,
        motivo: 'El cliente desistió y se acordó devolverle una parte.',
    );

    Livewire::test(ComoVanLosProyectos::class)
        // 50,000 de prima menos 20,000 devueltos.
        ->assertSee('L. 30,000.00')
        ->assertSee('L. 170,000.00');
});

/*
| Sin este cuadro, un proyecto sano a mitad de plazo se ve igual que uno que no
| vendió nada: los dos en rojo, porque los dos gastaron más de lo que cobraron.
| La diferencia está en lo que falta por entrar.
*/
test('dice en cuánto cierra el proyecto si entra todo lo pendiente', function (): void {
    ($this->gastar)('200000.00');

    Livewire::test(ComoVanLosProyectos::class)
        ->assertSee('Falta por cobrar')
        ->assertSee('L. 300,000.00')
        // 50,000 cobrados + 300,000 por cobrar − 200,000 invertidos.
        ->assertSee('Si entra todo, el proyecto cierra en +L. 150,000.00');
});

/*
| Con un proyecto solo, repetir su nombre debajo de una cifra que ya es la suya
| no agrega nada. Con dos, la suma no significa nada sin el desglose.
|
| 🔴 EL NOMBRE SALE EN MAYUSCULAS, Y NO ES COSA DEL WIDGET.
|
| `Proyecto::nombre()` tiene un mutador que hace `mb_strtoupper` al guardar —la
| decisión del 3-ago-2026, `docs/mayusculas.md`: «se aplica en el mutador del
| modelo, no en el formulario», así que también entra en mayúsculas desde un
| seeder, un import o una factory—. Se crea «Praderas del Sol» y en la base
| queda «PRADERAS DEL SOL».
|
| Esta aserción se escribió primero como se teclea el nombre y falló. Queda en
| mayúsculas y con este comentario para que el próximo no repita el viaje.
*/
test('con dos proyectos, el desglose dice el saldo de cada uno', function (): void {
    ($this->gastar)('200000.00');

    $otro = Proyecto::factory()->create(['codigo' => 'ALT', 'nombre' => 'Altamira']);
    Gasto::factory()->delProyecto($otro)->de('10000.00')->create();

    Livewire::test(ComoVanLosProyectos::class)
        ->assertSee('PRADERAS DEL SOL')
        ->assertSee('ALTAMIRA')
        // Praderas: 50,000 cobrados contra 200,000 invertidos.
        ->assertSee('L. 150,000.00')
        // Altamira: gastó y no ha cobrado nada.
        ->assertSee('L. 10,000.00');
});

/*
| 🔴 Cuánto costó el desarrollo y cuánto se lleva recuperado es información del
| dueño, no de la ventanilla. `ComoVaElNegocio` lo ve el receptor; este no, y
| por eso es un widget aparte y no cuatro cuadros más en aquel.
*/
test('el receptor no ve el costo del proyecto', function (): void {
    $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR));

    expect(ComoVanLosProyectos::canView())->toBeFalse();
});

test('la administradora sí lo ve', function (): void {
    expect(ComoVanLosProyectos::canView())->toBeTrue();
});
