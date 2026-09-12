<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Pages\PorCobrarHoy;
use App\Filament\Widgets\ComoVaElNegocio;
use App\Filament\Widgets\ComoVanLosProyectos;
use App\Filament\Widgets\CorteDeCajaDeHoy;
use App\Filament\Widgets\EncabezadoDelEscritorio;
use App\Livewire\SelectorDeProyecto;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Gasto;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Support\ProyectoActivo;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El interruptor de proyecto — 11-sep-2026
|--------------------------------------------------------------------------
| «Cuando carguemos otros proyectos —ya hablamos con la clienta y
| posiblemente agreguemos otros dos en unos días o semanas» — Mauricio.
|
| Hasta hoy la instalación tenía UN proyecto, así que sumar todo y sumar ese
| proyecto era lo mismo. Con tres deja de serlo, y en tres lugares que no se
| notan hasta que ya están mal:
|
|  - «104 lotes disponibles de 309» mezclando tres residenciales es un número
|    con el que no se decide nada: no hay cliente al que ofrecerle esos 104.
|  - La lista de a quién llamar mezcla clientes de tres desarrollos.
|  - El encabezado rotula la pantalla con el nombre de la empresa mientras
|    las cifras hablan de un solo proyecto.
|
| DOS proyectos en el `beforeEach`, porque con uno solo el interruptor ni se
| dibuja y no habría nada que probar.
*/

beforeEach(function (): void {
    actingAsAdmin(['name' => 'Mauricio Cruz']);

    config()->set('app.name', 'INVERSIONES OLYMPO');

    $this->praderas = Proyecto::factory()->create(['codigo' => 'RPS', 'nombre' => 'Praderas del Sol']);
    $this->altamira = Proyecto::factory()->create(['codigo' => 'ALT', 'nombre' => 'Altamira']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    /*
     * ⚠️ Los lotes, la venta y el gasto NO se arman acá.
     *
     * `proyectos` tiene `restrictOnDelete` desde bloques, lotes y gastos, así
     * que un proyecto con algo colgando no se puede borrar — y dos de estos
     * tests necesitan borrar uno. Armar los datos dentro del test que los usa
     * deja a los otros con proyectos limpios, y de paso dice en cada test qué
     * datos hacen falta para lo que prueba.
     */
    $this->armar = function (): void {
        $lote = static function (Proyecto $proyecto, string $numero): Lote {
            $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

            return Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => $numero]);
        };

        // Praderas vende y cobra; Altamira solo gasta. Así cada cifra del
        // Escritorio cambia según cuál se esté mirando.
        $this->venta = app(RegistroDeVentas::class)->activar(
            proyecto: $this->praderas,
            lotes: [$lote($this->praderas, '1')],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 12,
            diaPago: 5,
        );

        $lote($this->altamira, '1');

        Gasto::factory()->delProyecto($this->altamira)->de('80000.00')->create();
    };

    $this->elegir = function (?int $id): void {
        app(ProyectoActivo::class)->elegir($id);
    };
});

test('sin elegir nada, se ve la empresa entera', function (): void {
    ($this->armar)();

    Livewire::test(ComoVaElNegocio::class)
        // La prima de Praderas.
        ->assertSee('L. 50,000.00')
        // Los dos lotes: el de Praderas está vendido, el de Altamira libre.
        ->assertSee('1 de 2');

    Livewire::test(EncabezadoDelEscritorio::class)
        ->assertSee('INVERSIONES OLYMPO');
});

/*
| 🔴 EL NUMERO QUE MAS SE NOTA. «1 de 2» sumando dos residenciales no le
| sirve a nadie: no hay un cliente al que se le puedan ofrecer esos lotes,
| porque están en desarrollos distintos.
*/
test('elegido un proyecto, el inventario es solo el suyo', function (): void {
    ($this->armar)();

    ($this->elegir)((int) $this->altamira->getKey());

    Livewire::test(ComoVaElNegocio::class)
        ->assertSee('1 de 1')
        ->assertDontSee('1 de 2')
        // Altamira no ha cobrado un centavo.
        ->assertDontSee('L. 50,000.00');
});

test('el encabezado dice el proyecto que se está mirando', function (): void {
    ($this->elegir)((int) $this->praderas->getKey());

    Livewire::test(EncabezadoDelEscritorio::class)
        // El título pasa a ser el del residencial…
        ->assertSee('PRADERAS DEL SOL')
        // …y la empresa baja al rótulo chico, sin repetirse.
        ->assertSee('INVERSIONES OLYMPO');
});

test('la lista de a quién llamar se recorta al proyecto', function (): void {
    ($this->armar)();

    // Una cuota vencida en Praderas para que el expediente entre a la lista.
    $this->travel(2)->months();

    ($this->elegir)((int) $this->praderas->getKey());
    expect(PorCobrarHoy::porCobrar()->count())->toBe(1);

    ($this->elegir)((int) $this->altamira->getKey());
    expect(PorCobrarHoy::porCobrar()->count())->toBe(0);

    ($this->elegir)(null);
    expect(PorCobrarHoy::porCobrar()->count())->toBe(1);
});

test('el cuadro del proyecto muestra solo el elegido', function (): void {
    ($this->armar)();

    ($this->elegir)((int) $this->altamira->getKey());

    Livewire::test(ComoVanLosProyectos::class)
        ->assertSee('El proyecto')
        ->assertDontSee('Los proyectos')
        // Lo invertido en Altamira, sin lo de Praderas (que es cero igual).
        ->assertSee('L. 80,000.00');
});

test('en «Todos» el cuadro se llama en plural', function (): void {
    ($this->armar)();

    Livewire::test(ComoVanLosProyectos::class)
        ->assertSee('Los proyectos');
});

/*
| 🔴 LA GAVETA ES UNA. El corte de caja NO se recorta por proyecto: «lo que
| tiene que estar en la caja al cerrar» solo es verdad si suma lo que entró
| por todos los desarrollos, porque los billetes están en el mismo cajón.
*/
test('el corte de caja NO se recorta: la gaveta es una sola', function (): void {
    ($this->armar)();

    app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->venta->compromisos()->firstOrFail(),
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    ($this->elegir)((int) $this->altamira->getKey());

    Livewire::test(CorteDeCajaDeHoy::class)
        // 50,000 de prima + 25,000 de cuota, aunque se esté mirando Altamira.
        ->assertSee('L. 75,000.00');
});

describe('El interruptor', function (): void {
    /*
    | 🔴 LOS NOMBRES SALEN EN MAYUSCULAS, y no es cosa del selector.
    |
    | `Proyecto::nombre()` tiene un mutador que hace `mb_strtoupper` al
    | guardar —la decisión del 3-ago-2026, `docs/mayusculas.md`: «se aplica en
    | el mutador del modelo, no en el formulario», así que también entra en
    | mayúsculas desde una factory—. Se crean «Praderas del Sol» y «Altamira»,
    | y en la base quedan «PRADERAS DEL SOL» y «ALTAMIRA».
    |
    | Esta aserción se escribió como se teclea el nombre y falló. Es la
    | SEGUNDA vez en el día: pasó igual en `ComoVanLosProyectosTest`. Queda
    | anotado en los dos lados.
    */
    test('con varios proyectos se dibuja, y con uno solo no', function (): void {
        Livewire::test(SelectorDeProyecto::class)
            ->assertSee('PRADERAS DEL SOL')
            ->assertSee('ALTAMIRA')
            ->assertSee('Todos');

        /*
         * Queda UNO —Praderas—, que es la situación de hoy y la que el
         * interruptor no dibuja. Borrar los dos probaría el caso de cero
         * proyectos, que no le pasa a nadie.
         *
         * Sin lotes ni gastos colgando —ver el `beforeEach`— el borrado pasa.
         */
        $this->altamira->delete();

        Livewire::test(SelectorDeProyecto::class)
            ->assertDontSee('PRADERAS DEL SOL')
            ->assertDontSee('Todos');
    });

    test('elegir uno lo deja guardado para la próxima pantalla', function (): void {
        Livewire::test(SelectorDeProyecto::class)
            ->set('elegido', (string) $this->praderas->getKey());

        expect(session()->get(ProyectoActivo::CLAVE))->toBe((int) $this->praderas->getKey());
    });

    test('volver a «Todos» lo olvida', function (): void {
        ($this->elegir)((int) $this->praderas->getKey());

        Livewire::test(SelectorDeProyecto::class)->set('elegido', '');

        expect(session()->has(ProyectoActivo::CLAVE))->toBeFalse();
    });

    /*
    | Un proyecto borrado no puede dejar la pantalla mostrando un listado
    | vacío que nadie sabe explicar: vuelve a «Todos» sola.
    */
    test('si el proyecto elegido ya no existe, vuelve a todos', function (): void {
        ($this->elegir)((int) $this->altamira->getKey());

        $this->altamira->delete();

        expect(app(ProyectoActivo::class)->id())->toBeNull()
            ->and(app(ProyectoActivo::class)->hayUno())->toBeFalse();
    });
});
