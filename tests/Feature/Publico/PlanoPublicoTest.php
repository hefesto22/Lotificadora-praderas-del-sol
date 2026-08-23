<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Plano\PlanoPublico;
use App\Domain\Plano\SelloDelPlano;
use App\Http\Controllers\PlanoImagenController;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\LoteConsultado;
use App\Models\PlanDePago;
use App\Models\Prospecto;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

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
                // 10-ago: la foto 360. Se agregó A PROPÓSITO y no para que
                // el test dejara de fallar: es una foto del terreno, no dice
                // precio ni comprador. Ver el comentario en `PlanoPublico`.
                'foto360', 'foto360Mini',
                // 10-ago: el contorno y los rótulos del 360, como ángulos.
                // Se agregó A PROPÓSITO: es la forma de un dibujo, no dice
                // precio ni comprador. Ver `MarcasDelLote`.
                'foto360Marcas',
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

/*
|--------------------------------------------------------------------------
| La vidriera se cachea, pero no se queda vieja
|--------------------------------------------------------------------------
|
| 🔴 La clave de la caché era el `updated_at` del PROYECTO, y vender un lote
| no toca esa fila. Cambiar el precio de un plan tampoco. Así que la
| administradora vendía un lote, abría el link para comprobar, y seguía
| viendo el lote verde. Cinco minutos.
|
| Cinco minutos no suenan a nada hasta que alguien le manda el link a un
| cliente en ese rato.
|
| El arreglo NO fue sacar la caché —es la única URL que abre gente que no
| conocemos, y un link en un grupo de WhatsApp son cien aperturas en un
| minuto—: fue que la clave mire de verdad lo que la página muestra. Eso es
| `SelloDelPlano`.
|
| ⚠️ ESTOS DOS TESTS CORREN DENTRO DEL MISMO SEGUNDO, Y ES A PROPÓSITO.
|
| El primer intento de arreglo miraba `MAX(updated_at)` de `lotes` y de
| `planes_de_pago`, y fallaba acá mismo. El motivo no se ve leyendo el
| código: `$table->timestamps()` usa `Blueprint::defaultTimePrecision()`, que
| vale 0, así que en Postgres la columna es `timestamp(0)` — **segundos
| enteros**. Armar la página y vender el lote en el mismo segundo deja el
| `MAX` clavado y la caché vieja se sirve los cinco minutos completos.
|
| Por eso la huella se saca del CONTENIDO y no del reloj. Y por eso, si algún
| día esto falla, el arreglo NO es meterle un `travel()->seconds(1)` al test:
| eso esconde exactamente el segundo que hay que cubrir.
|
*/
describe('Plano público — se actualiza al instante', function (): void {
    test('vender un lote se ve en la página sin esperar la caché', function (): void {
        $url = route('plano.publico', ['slug' => 'praderas-del-sol']);

        // 250 vr² × L 1,400.00 del plan a 12 meses. Está libre: se cotiza.
        $this->get($url)->assertOk()->assertSee('350,000.00', escape: false);

        /*
         * Exactamente lo que hace una venta: cambia el estado del LOTE. La
         * fila del proyecto no se toca — y esa era toda la clave vieja.
         */
        $this->libre->update(['estado' => EstadoLote::Vendido]);

        // Un lote vendido deja de cotizarse, así que su medida ya no tiene
        // precio publicado. Antes de `SelloDelPlano` seguía ahí.
        $this->get($url)->assertOk()->assertDontSee('350,000.00', escape: false);
    });

    test('cambiar el precio de la vara² se ve en la página sin esperar la caché', function (): void {
        $url = route('plano.publico', ['slug' => 'praderas-del-sol']);

        $this->get($url)->assertOk()->assertSee('350,000.00', escape: false);

        PlanDePago::query()
            ->where('proyecto_id', $this->proyecto->getKey())
            ->update(['precio_vara' => '1500.00']);

        // 250 vr² × L 1,500.00.
        $this->get($url)->assertOk()->assertSee('375,000.00', escape: false);
    });

    /*
    | ⚠️ El contrapeso, y no es de adorno: los dos tests de arriba pasarían
    | igual de bien con la caché borrada de raíz, que es justo lo que no hay
    | que hacer. Sin este, el día que alguno falle el arreglo fácil es sacar
    | el `Cache::remember` y dejar la página armando 301 lotes por visita.
    */
    test('y la caché sigue puesta, que para eso está', function (): void {
        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))->assertOk();

        $sello = resolve(SelloDelPlano::class)->para($this->proyecto);

        expect(Cache::has('plano-publico:'.$this->proyecto->getKey().':'.$sello))->toBeTrue();
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

        /** @var LoteConsultado $consulta */
        $consulta = LoteConsultado::query()->sole();

        expect($prospecto->getAttribute('nombre'))->toBe('Marlon Andrés Zelaya')
            ->and((int) $prospecto->getAttribute('proyecto_id'))->toBe((int) $this->proyecto->getKey())
            ->and($prospecto->estaAtendido())->toBeFalse()
            // El lote vive en su propia fila desde el 23-ago: el prospecto es
            // la persona, y por cuales lotes pregunto es lo que le cuelga.
            ->and((int) $consulta->getAttribute('prospecto_id'))->toBe((int) $prospecto->getKey())
            ->and((int) $consulta->getAttribute('lote_id'))->toBe((int) $this->libre->getKey())
            ->and($consulta->getAttribute('plazo_meses'))->toBe(12)
            ->and($consulta->getAttribute('veces'))->toBe(1);

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
    | 🔴 23-ago-2026, visto por Mauricio en la lista con tres filas y dos
    | personas: «si la misma persona contacta no hay necesidad de hacer 2,
    | solo que aparezca por cuales lotes fue que contacto».
    |
    | Y el problema era peor que la repeticion: con una fila por consulta,
    | «ya lo llame» quedaba marcado en UNA y las otras seguian pidiendo
    | llamada.
    */
    test('la misma persona por dos lotes es UN prospecto con dos consultas', function (): void {
        $otro = $this->libre;

        $pregunta = fn (int $lote, string $telefono): mixed => $this->post(
            route('plano.interes', ['slug' => 'praderas-del-sol']),
            ['nombre' => 'Marlon Zelaya', 'telefono' => $telefono, 'plazo' => 12, 'lote_id' => $lote],
        );

        $pregunta($otro->getKey(), '9911-2233');

        // El MISMO numero, escrito distinto: la clave son solo los digitos.
        $pregunta($otro->getKey(), '99112233');

        expect(Prospecto::query()->count())->toBe(1)
            ->and(LoteConsultado::query()->count())->toBe(1)
            // Preguntar dos veces por el mismo lote no duplica: cuenta.
            ->and(LoteConsultado::query()->sole()->getAttribute('veces'))->toBe(2);
    });

    test('el nombre que queda es el ultimo que tecleo', function (): void {
        $pregunta = fn (string $nombre): mixed => $this->post(
            route('plano.interes', ['slug' => 'praderas-del-sol']),
            ['nombre' => $nombre, 'telefono' => '9911-2233', 'lote_id' => $this->libre->getKey()],
        );

        $pregunta('marlon');
        $pregunta('Marlon Andrés Zelaya');

        expect(Prospecto::query()->sole()->getAttribute('nombre'))->toBe('Marlon Andrés Zelaya');
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

/*
|--------------------------------------------------------------------------
| La tarjeta que llega por WhatsApp
|--------------------------------------------------------------------------
|
| Es lo unico de esta pagina cuyo trabajo es que alguien haga clic. Un link
| sin `og:image` llega a un grupo de WhatsApp como una linea de texto azul y
| no lo abre nadie.
|
*/
describe('Plano público — la tarjeta de WhatsApp', function (): void {
    test('la página publica su miniatura', function (): void {
        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))
            ->assertOk()
            ->assertSee('og:image', escape: false)
            ->assertSee(route('plano.imagen', ['slug' => 'praderas-del-sol']), escape: false);
    })->skip(! PlanoImagenController::disponible(), 'Este servidor no tiene GD.');

    test('la miniatura es un PNG de 1200×630', function (): void {
        $respuesta = $this->get(route('plano.imagen', ['slug' => 'praderas-del-sol']));

        $respuesta->assertOk();

        expect($respuesta->headers->get('Content-Type'))->toBe('image/png');

        $medidas = getimagesizefromstring((string) $respuesta->getContent());

        expect($medidas)->toBeArray();

        // El ternario y no un `@var`: `getimagesizefromstring()` ya viene
        // tipada, y un docblock que la contradice es una promesa que PHPStan
        // no puede verificar — y con razón.
        expect(is_array($medidas) ? [$medidas[0], $medidas[1], $medidas['mime']] : null)
            ->toBe([1200, 630, 'image/png']);
    })->skip(! PlanoImagenController::disponible(), 'Este servidor no tiene GD.');

    /*
    | La miniatura sale del MISMO armador con lista blanca que la pagina. El
    | dia que alguien la dibuje desde `PlanoDelProyecto` para escribirle
    | encima «68 disponibles», tendria el nombre del comprador a mano.
    */
    test('con el plano apagado la miniatura tampoco se sirve', function (): void {
        $this->proyecto->update(['plano_publico' => false]);

        $this->get(route('plano.imagen', ['slug' => 'praderas-del-sol']))->assertNotFound();
    })->skip(! PlanoImagenController::disponible(), 'Este servidor no tiene GD.');

    /*
    | Un proyecto cargado pero sin dibujar daria un rectangulo vacio, y una
    | tarjeta con un rectangulo blanco adentro se ve peor que una tarjeta sin
    | imagen. Por eso el `og:image` ni se emite.
    */
    test('un proyecto sin dibujar no promete una miniatura que no tiene', function (): void {
        $pelado = Proyecto::factory()->create([
            'codigo'        => 'SND',
            'slug'          => 'sin-dibujar',
            'plano_publico' => true,
        ]);

        Bloque::factory()->create(['proyecto_id' => $pelado->getKey(), 'nombre' => 'A']);

        $this->get(route('plano.publico', ['slug' => 'sin-dibujar']))
            ->assertOk()
            ->assertDontSee(route('plano.imagen', ['slug' => 'sin-dibujar']), escape: false);

        $this->get(route('plano.imagen', ['slug' => 'sin-dibujar']))->assertNotFound();
    });
});

/*
|--------------------------------------------------------------------------
| Cómo llegar
|--------------------------------------------------------------------------
|
| La segunda pregunta que hace todo el mundo después del precio.
|
*/
describe('Plano público — cómo llegar', function (): void {
    test('con coordenadas aparecen los dos botones', function (): void {
        $this->proyecto->update(['latitud' => '14.5896412', 'longitud' => '-88.9302517']);

        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))
            ->assertOk()
            ->assertSee('Cómo llegar', escape: false)
            /*
             * Los formatos que arrancan la APLICACION. Un link de los que
             * empiezan con `maps.app.goo.gl` abre la pagina de Google Maps
             * adentro del navegador del telefono, que no es lo mismo.
             */
            ->assertSee('google.com/maps/search/?api=1', escape: false)
            ->assertSee('waze.com/ul?ll=', escape: false)
            ->assertSee('14.5896412', escape: false);
    });

    test('sin coordenadas la sección no existe', function (): void {
        /*
         * Se busca el MARCADO y no el texto «Cómo llegar»: el CSS de la
         * página lleva comentarios, y un `assertDontSee` sobre una frase
         * suelta se rompe el día que alguien la escribe adentro de uno.
         */
        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))
            ->assertOk()
            ->assertDontSee('<section class="llegar">', escape: false)
            ->assertDontSee('waze.com', escape: false)
            ->assertDontSee('google.com/maps', escape: false);
    });

    /*
    | Media coordenada no apunta «casi» al proyecto: una latitud sin longitud
    | cae en el meridiano cero, y el (0, 0) del mundo está en el Golfo de
    | Guinea. El formulario lo avisa; la base lo impide.
    */
    test('media coordenada no entra ni por tinker', function (): void {
        expect(fn (): bool => $this->proyecto->update(['latitud' => '14.5896412']))
            ->toThrow(QueryException::class);
    });
});

/*
|--------------------------------------------------------------------------
| Lo que la calle NO tiene por qué distinguir
|--------------------------------------------------------------------------
| Pedido de Mauricio, 13-ago-2026: «que reservado y donado solo aparezcan
| como venta, para que el mundo no lo sepa». Cuántos lotes se guardó la
| familia y a quién se le regalaron son datos que le dicen a la competencia
| —y al vecino— cómo se administra el desarrollo, y no venden ni un lote.
|
| El cancelado entra en la misma bolsa: antes se pintaba de rojo propio y
| después se lo borraba de la leyenda, o sea un color sin nombre, que era
| justo lo que había que evitar.
|
| ⚠️ Es SOLO la pintura. Los tests de abajo verifican también que la verdad
| no se mueva: el contador de disponibles y el `seCotiza` siguen leyendo el
| estado real.
*/
describe('Plano público — el disfraz de la vidriera', function (): void {
    test('reservado, donado y cancelado salen como vendidos', function (): void {
        $numero = 2;

        foreach ([EstadoLote::Reservado, EstadoLote::Donado, EstadoLote::Cancelado] as $estado) {
            $numero++;

            Lote::factory()
                ->enBloque($this->bloque)
                ->conEstado($estado)
                ->create([
                    'numero'   => (string) $numero,
                    'poligono' => [[$numero * 20, 0], [$numero * 20 + 10, 0], [$numero * 20 + 10, 25], [$numero * 20, 25]],
                ]);
        }

        $lotes = resolve(PlanoPublico::class)->para($this->proyecto)['lotes'];
        $disfrazados = array_slice($lotes, 2);

        expect($disfrazados)->toHaveCount(3);

        foreach ($disfrazados as $lote) {
            expect($lote['estado'])->toBe(EstadoLote::Vendido->value)
                ->and($lote['etiqueta'])->toBe('Vendido')
                ->and($lote['color'])->toBe(EstadoLote::Vendido->colorHex())
                // Y ninguno lleva precio: no están a la venta de verdad.
                ->and($lote['seCotiza'])->toBeFalse();
        }
    });

    /*
    | El disfraz es de pintura y nada más. Si el contador se dejara engañar,
    | la página diría «2 disponibles de 5» y estaría mintiendo hacia el otro
    | lado —ofreciendo lotes que no existen—.
    */
    test('el contador de disponibles no se deja disfrazar', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->create([
            'numero'   => '3',
            'poligono' => [[60, 0], [70, 0], [70, 25], [60, 25]],
        ]);

        $publico = resolve(PlanoPublico::class)->para($this->proyecto);

        expect($publico['disponibles'])->toBe(1)
            ->and($publico['total'])->toBe(3);
    });

    /*
    | Tres tonos y no seis. De esta lista sale la leyenda Y la clase CSS de
    | cada lote, así que un color de más sería un lote pintado distinto sin
    | nombre que lo explique.
    */
    test('la leyenda tiene tres colores y ninguno se llama reservado', function (): void {
        $colores = resolve(PlanoPublico::class)->para($this->proyecto)['colores'];

        expect(array_column($colores, 'estado'))->toBe([
            EstadoLote::Disponible->value,
            EstadoLote::Apartado->value,
            EstadoLote::Vendido->value,
        ]);

        expect(array_column($colores, 'etiqueta'))->toBe(['Disponible', 'Apartado', 'Vendido']);
    });

    /*
    | La red de salida: la palabra no puede aparecer en el HTML crudo, ni en
    | la leyenda, ni en el `<title>` del polígono, ni en una clase CSS.
    */
    test('la página no dice reservado ni donado en ninguna parte', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->create([
            'numero'   => '3',
            'poligono' => [[60, 0], [70, 0], [70, 25], [60, 25]],
        ]);
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Donado)->create([
            'numero'   => '4',
            'poligono' => [[80, 0], [90, 0], [90, 25], [80, 25]],
        ]);

        $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))
            ->assertOk()
            // Sin esto, una página en blanco pasaría los tres de abajo.
            ->assertSee('Vendido')
            ->assertDontSee('Reservado')
            ->assertDontSee('Donado')
            ->assertDontSee('e-reservado', escape: false)
            ->assertDontSee('e-donado', escape: false);
    });
});
