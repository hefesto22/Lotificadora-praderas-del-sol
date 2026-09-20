<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Pages\EstadoMensual;
use App\Filament\Pages\PorCobrarHoy;
use App\Filament\Resources\Apartados\ApartadoResource;
use App\Filament\Resources\Lotes\LoteResource;
use App\Filament\Resources\Lotes\Pages\ListLotes;
use App\Filament\Resources\Prospectos\ProspectoResource;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Filament\Resources\Recibos\ReciboResource;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Widgets\ComoVaElNegocio;
use App\Filament\Widgets\ComoVanLosProyectos;
use App\Filament\Widgets\CorteDeCajaDeHoy;
use App\Filament\Widgets\EncabezadoDelEscritorio;
use App\Livewire\SelectorDeProyecto;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Gasto;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Support\ProyectoActivo;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
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

        $this->loteAltamira = $lote($this->altamira, '1');

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

describe('Los listados', function (): void {
    test('Ventas muestra solo las del proyecto elegido', function (): void {
        ($this->armar)();

        ($this->elegir)((int) $this->praderas->getKey());
        expect(VentaResource::getEloquentQuery()->count())->toBe(1);

        ($this->elegir)((int) $this->altamira->getKey());
        expect(VentaResource::getEloquentQuery()->count())->toBe(0);

        ($this->elegir)(null);
        expect(VentaResource::getEloquentQuery()->count())->toBe(1);
    });

    test('Lotes muestra solo los del proyecto elegido', function (): void {
        ($this->armar)();

        ($this->elegir)((int) $this->altamira->getKey());
        expect(LoteResource::getEloquentQuery()->count())->toBe(1);

        ($this->elegir)(null);
        expect(LoteResource::getEloquentQuery()->count())->toBe(2);
    });

    test('Apartados también, y sale del proyecto_id del compromiso', function (): void {
        ($this->armar)();

        Compromiso::factory()->paraLote($this->loteAltamira)->create();

        ($this->elegir)((int) $this->altamira->getKey());
        expect(ApartadoResource::getEloquentQuery()->count())->toBe(1);

        ($this->elegir)((int) $this->praderas->getKey());
        expect(ApartadoResource::getEloquentQuery()->count())->toBe(0);
    });

    /*
    | 🔴🔴 EL TEST QUE JUSTIFICA `Recibo::delProyecto()`.
    |
    | `recibos` no tiene `proyecto_id`: llega a su proyecto por la venta. Pero
    | R13 (`recibos_cuelgan_de_un_compromiso_chk`) admite `venta_id` en NULL
    | mientras haya `compromiso_id`, y ese es el caso de la SEÑA de un
    | apartado, que todavía no tiene contrato.
    |
    | Recortar por la venta sola escondería esas señas: dinero que entró y que
    | no aparecería en ningún proyecto. El recibo existiría en la base, sumaría
    | en el corte de caja del día, y el listado de su proyecto no lo mostraría
    | nunca — que es como se pierde un papel sin que nadie lo note.
    */
    test('Recibos incluye la seña de un apartado, que no cuelga de ninguna venta', function (): void {
        ($this->armar)();

        $apartado = Compromiso::factory()->paraLote($this->loteAltamira)->create();

        Recibo::factory()->create([
            'venta_id'      => null,
            'compromiso_id' => $apartado->getKey(),
            'cliente_id'    => $this->cliente->getKey(),
            'concepto'      => ConceptoDeRecibo::Senia,
            'monto'         => '3000.00',
        ]);

        // Praderas: solo el recibo de la prima de su venta.
        ($this->elegir)((int) $this->praderas->getKey());
        expect(ReciboResource::getEloquentQuery()->count())->toBe(1);

        // Altamira: la seña, que llega por el compromiso y no por la venta.
        ($this->elegir)((int) $this->altamira->getKey());
        expect(ReciboResource::getEloquentQuery()->count())->toBe(1)
            ->and(ReciboResource::getEloquentQuery()->sum('monto'))->toEqual('3000.00');

        ($this->elegir)(null);
        expect(ReciboResource::getEloquentQuery()->count())->toBe(2);
    });
});

/*
|--------------------------------------------------------------------------
| Lo que faltaba traer de Maya — 18-sep-2026
|--------------------------------------------------------------------------
| Mauricio, con las dos instalaciones abiertas una al lado de la otra: «falta
| eso del plano y lo del filtro global». Acá el interruptor ya recortaba
| Lotes, Ventas, Recibos y el Escritorio, pero tres lugares seguían sin
| enterarse de qué proyecto se estaba mirando:
|
|  - la lista de Proyectos mostraba los tres aunque hubiera uno elegido;
|  - al plano había que llegar pasando por esa lista y buscando el botón;
|  - el Estado mensual abría siempre en el proyecto más viejo.
*/
describe('la lista de Proyectos, el atajo al plano y el Estado mensual', function (): void {
    test('la lista de Proyectos muestra solo el elegido, y en «Todos» los dos', function (): void {
        ($this->elegir)((int) $this->altamira->getKey());

        Livewire::test(ListProyectos::class)
            ->assertCanSeeTableRecords([$this->altamira])
            ->assertCanNotSeeTableRecords([$this->praderas]);

        ($this->elegir)(null);

        Livewire::test(ListProyectos::class)
            ->assertCanSeeTableRecords([$this->praderas, $this->altamira]);
    });

    /*
    | 🔴 EL TEST QUE DICE POR QUE EL RECORTE VA EN LA TABLA.
    |
    | El resource resuelve con `getEloquentQuery()` el record de TODAS sus
    | páginas. Recortar ahí esconde de la lista, sí, pero también hace que el
    | plano o la ficha de un proyecto den 404 en cuanto el elegido es otro: un
    | enlace guardado, una pestaña que quedó abierta, el botón «Atrás». En Maya
    | pasó exactamente eso y se corrigió mudando el recorte a la tabla.
    */
    test('el plano de OTRO proyecto se sigue abriendo: se recorta la lista, no el recurso', function (): void {
        ($this->elegir)((int) $this->altamira->getKey());

        expect(ProyectoResource::getEloquentQuery()->count())->toBe(2);

        $this->get(ProyectoResource::getUrl('plano', ['record' => $this->praderas]))
            ->assertOk();
    });

    test('el atajo «Plano» aparece solo con un proyecto elegido, y abre el plano de ese', function (): void {
        $atajo = static function (): NavigationItem {
            foreach (Filament::getPanel('admin')->getNavigationItems() as $item) {
                if ($item->getLabel() === 'Plano') {
                    return $item;
                }
            }

            throw new RuntimeException('El panel no tiene el atajo «Plano».');
        };

        // En «Todos» no hay UN plano que abrir: el atajo no se dibuja.
        expect($atajo()->isVisible())->toBeFalse();

        ($this->elegir)((int) $this->altamira->getKey());

        $planoDeAltamira = ProyectoResource::getUrl('plano', ['record' => $this->altamira]);

        expect($atajo()->isVisible())->toBeTrue()
            ->and($atajo()->getUrl())->toBe($planoDeAltamira);

        // Y no es solo el objeto: está en el menú de una página de verdad. Se
        // mira desde el plano de PRADERAS, que por su cuenta no enlaza al de
        // Altamira: si la dirección aparece, la puso el menú.
        $this->get(ProyectoResource::getUrl('plano', ['record' => $this->praderas]))
            ->assertOk()
            ->assertSee($planoDeAltamira, escape: false);

        ($this->elegir)((int) $this->praderas->getKey());

        expect($atajo()->getUrl())->toBe(ProyectoResource::getUrl('plano', ['record' => $this->praderas]));
    });

    test('el Estado mensual abre en el proyecto elegido, y sin elegir en el primero', function (): void {
        Livewire::test(EstadoMensual::class)
            ->assertSet('data.proyecto', $this->praderas->getKey());

        ($this->elegir)((int) $this->altamira->getKey());

        Livewire::test(EstadoMensual::class)
            ->assertSet('data.proyecto', $this->altamira->getKey());
    });
});

/*
|--------------------------------------------------------------------------
| 🔴 Los contadores también — 18-sep-2026 (§9.E6)
|--------------------------------------------------------------------------
| Mauricio, con un desarrollo recién cargado elegido y la lista de Ventas
| vacía: «no debería de aparecer Ventas 95 si no son de ese proyecto».
|
| Tenía razón, y no era solo ese. El 11-sep se recortaron los LISTADOS y
| cuatro contadores quedaron contando la empresa entera: el de Ventas, el de
| Apartados y el de Prospectos en el menú, y las pestañas de Lotes. Un
| número rojo que no coincide con la lista a la que lleva manda a buscar
| algo que no está ahí — y la segunda vez ya nadie le cree al número.
|
| «Por cobrar hoy» y las pestañas de Ventas y de Recibos ya estaban bien.
*/
describe('los contadores cuentan lo mismo que la lista a la que llevan', function (): void {
    test('el de Ventas: los atrasados del proyecto elegido, no los de todos', function (): void {
        ($this->armar)();

        // La venta de Praderas es a doce meses: en tres ya debe cuotas.
        $this->travel(3)->months();

        ($this->elegir)((int) $this->altamira->getKey());
        expect(VentaResource::getNavigationBadge())->toBeNull();

        ($this->elegir)((int) $this->praderas->getKey());
        expect(VentaResource::getNavigationBadge())->toBe('1');

        ($this->elegir)(null);
        expect(VentaResource::getNavigationBadge())->toBe('1');
    });

    test('el de Apartados: los vencidos del proyecto elegido', function (): void {
        ($this->armar)();

        // §9.C11: un apartado vencido solo existe viajando al día en que se
        // apartó. El CHECK `vence_el >= fecha` no deja fabricarlo desde hoy.
        $this->travelTo(today()->subDays(30));

        Compromiso::factory()->paraLote($this->loteAltamira)->create([
            'vence_el' => today()->addDays(15)->toDateString(),
        ]);

        $this->travelBack();

        ($this->elegir)((int) $this->praderas->getKey());
        expect(ApartadoResource::getNavigationBadge())->toBeNull();

        ($this->elegir)((int) $this->altamira->getKey());
        expect(ApartadoResource::getNavigationBadge())->toBe('1');

        ($this->elegir)(null);
        expect(ApartadoResource::getNavigationBadge())->toBe('1');
    });

    test('el de Prospectos: los que esperan una llamada en el proyecto elegido', function (): void {
        Prospecto::factory()->create(['proyecto_id' => $this->altamira->getKey()]);

        ($this->elegir)((int) $this->praderas->getKey());
        expect(ProspectoResource::getNavigationBadge())->toBeNull();

        ($this->elegir)((int) $this->altamira->getKey());
        expect(ProspectoResource::getNavigationBadge())->toBe('1');

        ($this->elegir)(null);
        expect(ProspectoResource::getNavigationBadge())->toBe('1');
    });

    test('las pestañas de Lotes cuentan los lotes del proyecto elegido', function (): void {
        ($this->armar)();

        ($this->elegir)((int) $this->altamira->getKey());

        $pestanas = app(ListLotes::class)->getTabs();

        // Altamira tiene UN lote y está libre; el vendido es de Praderas.
        expect($pestanas['todos']->getBadge())->toBe('1')
            ->and($pestanas['disponible']->getBadge())->toBe('1')
            ->and($pestanas['vendido']->getBadge())->toBe('0');

        ($this->elegir)(null);

        $pestanas = app(ListLotes::class)->getTabs();

        expect($pestanas['todos']->getBadge())->toBe('2')
            ->and($pestanas['vendido']->getBadge())->toBe('1');
    });
});
