<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Plano\PlanoPublico;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Prospecto;
use App\Models\Proyecto;

/*
|--------------------------------------------------------------------------
| El plano que se le manda al cliente por WhatsApp
|--------------------------------------------------------------------------
|
| 🔴 ESTE ES EL ARCHIVO QUE NO SE PUEDE BORRAR.
|
| Es la única URL del sistema que abre gente que no conocemos, y la que más
| barato sale romper: basta con que alguien agregue un campo a
| `PlanoDelProyecto` —donde SÍ viajan el nombre del comprador y el valor
| pactado— y que la lista blanca de `PlanoPublico` lo deje pasar. La página
| se seguiría viendo perfecta. El que se entera es el cliente que descubre a
| qué precio le vendieron al vecino.
|
| Por eso hay dos redes y no una:
|
|  1. La ESTRUCTURAL: las claves del arreglo público son exactamente estas y
|     ninguna más. Un campo nuevo en el plano del panel hace fallar el test
|     el mismo día que se agrega, aunque nadie lo haya publicado todavía.
|  2. La de SALIDA: se pide la página de verdad y se busca el nombre y los
|     montos en el HTML crudo. Cubre lo que la estructural no puede — un
|     `$lote->valor` escrito a mano dentro de la plantilla.
|
| Y un `assertSee` que parece de adorno y no lo es: el lote DISPONIBLE sí
| publica su precio. Sin él, una plantilla vacía —o un error devuelto como
| 200— pasaría todos los `assertDontSee` con honores.
|
*/

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create([
        'codigo'        => 'RPS',
        'slug'          => 'praderas-del-sol',
        'plano_publico' => true,
        'activo'        => true,
        'whatsapp'      => '9988-7766',
    ]);

    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    /*
     * El plan con el que se cotiza lo que está a la venta: 12 meses a
     * L 1,400.00 la vara². Un lote de 250 vr² sale L 350,000.00, que es el
     * número que SÍ tiene que aparecer.
     */
    PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(12, '1400.00')->create();

    $this->libre = Lote::factory()
        ->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => '1', 'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]]]);

    /*
     * El vendido. Los números están elegidos para que no se parezcan a nada
     * más de la página: precio de lista L 666,000.00 (333 vr² × L 2,000.00) y
     * precio pactado L 591,741.00 (333 vr² × L 1,777.00), o sea CON descuento
     * —que es el caso que de verdad duele publicar (R4)—.
     */
    $this->vendido = Lote::factory()
        ->enBloque($this->bloque)
        ->conMedidas('333.0000', '2000.00')
        ->conEstado(EstadoLote::Vendido)
        ->create(['numero' => '2', 'poligono' => [[20, 0], [30, 0], [30, 25], [20, 25]]]);

    // refresh(): `valor` es columna generada por Postgres y no vuelve sola
    // al modelo en memoria después de create() (§9.C6).
    $this->vendido->refresh();

    $this->comprador = Cliente::factory()->create(['nombre' => 'Zenaida Carballo Membreño']);
    $this->comprador->refresh();

    Compromiso::factory()
        ->paraLote($this->vendido)
        ->deTipo(TipoCompromiso::Venta)
        ->conDescuento('1777.00', 'Pago de contado de la prima')
        ->create(['cliente_id' => $this->comprador->getKey()]);
});

describe('Plano público — lo que NO puede salir', function (): void {
    /*
    | El test que justifica el archivo. Si alguna vez falla, no se ajusta el
    | test: se revisa qué se publicó.
    */
    test('un lote vendido no filtra ni el comprador ni a cuánto se vendió', function (): void {
        // Tal como quedó guardado, por si algún mutador lo pasó a mayúsculas.
        $comprador = (string) $this->comprador->getAttribute('nombre');

        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))
            ->assertOk()
            /*
             * ⚠️ Este assertSee NO es de relleno. Sin él, una página en blanco
             * pasaría todo lo de abajo.
             */
            ->assertSee('350,000.00', escape: false)
            // El comprador, entero y por apellido, en las dos cajas.
            ->assertDontSee($comprador, escape: false)
            ->assertDontSee('Carballo', escape: false)
            ->assertDontSee('CARBALLO', escape: false)
            // Lo pactado: con formato y sin formato.
            ->assertDontSee('591,741.00', escape: false)
            ->assertDontSee('591741.00', escape: false)
            ->assertDontSee('1,777.00', escape: false)
            ->assertDontSee('1777.00', escape: false)
            // Y el de lista del vendido, que tampoco se publica.
            ->assertDontSee('666,000.00', escape: false)
            ->assertDontSee('666000.00', escape: false);
    });

    /*
    | La red estructural. Esta lista es la lista blanca de `PlanoPublico`
    | escrita dos veces a propósito: el día que alguien agregue un campo al
    | plano del panel y lo deje pasar sin pensarlo, este test lo dice.
    |
    | ⚠️ Agregar una clave acá no es "arreglar un test": es publicar un dato.
    */
    test('el arreglo público de un lote no tiene una sola clave de más', function (): void {
        $publico = resolve(PlanoPublico::class)->para($this->proyecto);

        expect($publico['lotes'])->toHaveCount(2);

        foreach ($publico['lotes'] as $lote) {
            expect(array_keys($lote))->toBe([
                'id', 'codigo', 'numero', 'rotulo', 'bloque', 'estado',
                'etiqueta', 'color', 'puntos', 'centro', 'area',
                'areaFormateada', 'seCotiza', 'clave',
            ]);
        }
    });

    test('el vendido viaja sin cotización y el libre con la suya', function (): void {
        $publico = resolve(PlanoPublico::class)->para($this->proyecto);

        // Los lotes salen ordenados por código, y son RPS-A-001 y RPS-A-002.
        [$libre, $vendido] = [$publico['lotes'][0], $publico['lotes'][1]];

        expect($libre['estado'])->toBe(EstadoLote::Disponible->value)
            ->and($vendido['estado'])->toBe(EstadoLote::Vendido->value)
            ->and($libre['seCotiza'])->toBeTrue()
            ->and($libre['clave'])->toBe('250_0000')
            // Sin clave no hay a qué precio mirar: el mapa no lo tiene.
            ->and($vendido['seCotiza'])->toBeFalse()
            ->and($vendido['clave'])->toBe('')
            ->and($publico['precios'])->not->toHaveKey('333_0000')
            // Se dibuja igual y con su medida. Eso no es secreto.
            ->and($vendido['areaFormateada'])->toBe('333 vr²')
            ->and($publico['disponibles'])->toBe(1)
            ->and($publico['total'])->toBe(2);
    });
});

describe('Plano público — lo que SÍ tiene que verse', function (): void {
    test('los lotes ocupados se dibujan, no se esconden', function (): void {
        /*
         * Un plano donde solo aparecen los libres miente por omisión —parece
         * que no se vendió nada— y hace que la gente pregunte por lotes que
         * ya no están.
         */
        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))
            ->assertOk()
            ->assertSee((string) $this->vendido->getAttribute('codigo'), escape: false)
            ->assertSee((string) $this->proyecto->getAttribute('nombre'), escape: false);
    });
});

describe('Plano público — quién puede abrirlo', function (): void {
    test('un slug que no existe da 404', function (): void {
        $this->get(route('plano.publico', ['slug' => 'no-existe']))->assertNotFound();
    });

    /*
    | 404 y no 403: en una página abierta a internet, un «existe pero no
    | podés verlo» le confirma a cualquiera que el proyecto existe.
    */
    test('con el plano apagado da 404, no 403', function (): void {
        $this->proyecto->update(['plano_publico' => false]);

        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))->assertNotFound();
    });

    test('un proyecto inactivo da 404 aunque tenga el plano encendido', function (): void {
        $this->proyecto->update(['activo' => false]);

        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))->assertNotFound();
    });
});

describe('Plano público — el formulario de interés', function (): void {
    test('el prospecto se guarda ANTES de mandar a WhatsApp', function (): void {
        $respuesta = $this->post(route('plano.interes', ['slug' => 'praderas-del-sol']), [
            'nombre'   => 'Marlon Andrés Zelaya',
            'telefono' => '9911-2233',
            'plazo'    => 12,
            'lote_id'  => $this->libre->getKey(),
        ]);

        $respuesta->assertStatus(302);

        /** @var Prospecto $prospecto */
        $prospecto = Prospecto::query()->sole();

        expect($prospecto->getAttribute('nombre'))->toBe('Marlon Andrés Zelaya')
            ->and((int) $prospecto->getAttribute('lote_id'))->toBe((int) $this->libre->getKey())
            ->and((int) $prospecto->getAttribute('proyecto_id'))->toBe((int) $this->proyecto->getKey())
            ->and($prospecto->getAttribute('plazo_meses'))->toBe(12)
            ->and($prospecto->estaAtendido())->toBeFalse();

        /*
         * El número del proyecto, no el del cliente: ocho dígitos hondureños
         * se completan con el 504. Y el mensaje ya viene redactado con el
         * código del lote — quien llegó hasta acá ya decidió preguntar, y
         * obligarlo a escribir es el último lugar donde se arrepiente.
         */
        expect($respuesta->headers->get('Location'))
            ->toContain('wa.me/50499887766')
            ->toContain(rawurlencode((string) $this->libre->getAttribute('codigo')));
    });

    /*
    | Al bot se le contesta igual que a una persona: un error o un mensaje
    | distinto le dice exactamente qué campo esquivar la próxima vez.
    */
    test('un bot que cae en la trampa se va contento y sin dejar fila', function (): void {
        $this->post(route('plano.interes', ['slug' => 'praderas-del-sol']), [
            'nombre'    => 'Comprador Genuino',
            'telefono'  => '99887766',
            'sitio_web' => 'http://spam.example',
        ])->assertStatus(302);

        expect(Prospecto::query()->count())->toBe(0);
    });

    test('el id de un lote de otra lotificadora no entra', function (): void {
        $ajeno = Proyecto::factory()->create(['codigo' => 'OTRO']);
        $bloqueAjeno = Bloque::factory()->create(['proyecto_id' => $ajeno->getKey(), 'nombre' => 'Z']);
        $loteAjeno = Lote::factory()->enBloque($bloqueAjeno)->create();

        $this->post(route('plano.interes', ['slug' => 'praderas-del-sol']), [
            'nombre'   => 'Quien Sea',
            'telefono' => '99887766',
            'lote_id'  => $loteAjeno->getKey(),
        ])->assertSessionHasErrors('lote_id');

        expect(Prospecto::query()->count())->toBe(0);
    });

    test('con el plano apagado el formulario tampoco recibe', function (): void {
        $this->proyecto->update(['plano_publico' => false]);

        $this->post(route('plano.interes', ['slug' => 'praderas-del-sol']), [
            'nombre'   => 'Quien Sea',
            'telefono' => '99887766',
        ])->assertNotFound();

        expect(Prospecto::query()->count())->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| La direccion de la pagina
|--------------------------------------------------------------------------
|
| El slug es la URL que se manda por WhatsApp. Cuando se agrego la columna
| quedo NOT NULL y el campo del panel obligatorio, y eso volteo 418 tests de
| una sola vez: cada `Proyecto::factory()` del sistema inserta sin slug.
|
| El arreglo no fue tocar los factories — fue que el modelo lo derive del
| nombre. Estos tests son para que el arreglo no se pierda.
|
*/
describe('Plano público — la dirección de la página', function (): void {
    test('un proyecto nuevo sale con su dirección puesta, sin que nadie la escriba', function (): void {
        $nuevo = Proyecto::factory()->create(['nombre' => 'Residencial La Cañada', 'codigo' => 'LC1']);

        // Str::slug sabe de la ñ; un strtolower() no.
        expect($nuevo->getAttribute('slug'))->toBe('residencial-la-canada');
    });

    /*
    | `nombre` es único en la base, así que el choque real no es por nombre
    | repetido: es por tilde. «Cañada Verde» y «Canada Verde» son dos
    | proyectos distintos que dan la misma dirección. Desempata el código.
    */
    test('dos nombres que dan la misma dirección no chocan', function (): void {
        $uno = Proyecto::factory()->create(['nombre' => 'Cañada Verde', 'codigo' => 'CV1']);
        $dos = Proyecto::factory()->create(['nombre' => 'Canada Verde', 'codigo' => 'CV2']);

        expect($uno->getAttribute('slug'))->toBe('canada-verde')
            ->and($dos->getAttribute('slug'))->toBe('canada-verde-cv2');
    });

    /*
    | 🔴 Lo más importante de este archivo después del test de privacidad: un
    | slug que se recalcula porque alguien corrigió una tilde del nombre rompe
    | TODOS los links ya mandados por WhatsApp, y nadie relaciona una cosa con
    | la otra.
    */
    test('corregir el nombre NO cambia la dirección ya publicada', function (): void {
        $this->proyecto->update(['nombre' => 'Residencial Praderas del Sol II']);

        expect($this->proyecto->refresh()->getAttribute('slug'))->toBe('praderas-del-sol');
    });

    test('borrar la dirección a mano la vuelve a armar en vez de tumbar el guardado', function (): void {
        $this->proyecto->update(['slug' => '']);

        // El mismo regex que el CHECK `proyectos_slug_con_forma_chk`.
        expect($this->proyecto->refresh()->getAttribute('slug'))
            ->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
    });
});
