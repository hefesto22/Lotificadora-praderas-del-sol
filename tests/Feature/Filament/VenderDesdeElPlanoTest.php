<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Filament\Resources\Proyectos\Pages\VerPlano;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use App\Models\Venta;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Vender y apartar DESDE EL PLANO
|--------------------------------------------------------------------------
| Estos dos caminos no tenian ni un test, y son los que se usan todos los
| dias: el vendedor abre el plano, hace clic en un lote, cotiza en el modal
| y firma. La pantalla de Ventas → Nueva existe, pero nadie la usa cuando
| tiene el plano abierto.
|
| Renderizar la pagina no prueba nada de esto: hay que DISPARAR la accion,
| que es donde vive todo lo que se puede romper sin que PHPStan se entere.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    // La lista por plazo: de contado la vara vale menos que a 12 meses.
    PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(0, '1300.00')->create();
    PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(12, '1500.00')->create();

    $this->rosa = Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA', 'activo' => true]);
    $this->carlos = Cliente::factory()->create(['nombre' => 'CARLOS MEJIA', 'activo' => true]);

    // 250 vr² es la medida de 233 de los 301 lotes de Praderas.
    $this->lote = fn (string $numero): Lote => Lote::factory()
        ->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create([
            'numero'   => $numero,
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

    /*
    | Lo que la barra del plano le manda a la accion: una linea por lote con
    | SU plazo, SU precio y SU prima. Encabeza el primero que se marco.
    */
    $this->condicion = static fn (Lote $lote, int $plazo, string $precio, string $prima = '0.00'): array => [
        'lote'   => $lote->getKey(),
        'plazo'  => $plazo,
        'precio' => $precio,
        'prima'  => $prima,
    ];

    // Disparar la accion es lo que hay que probar, y se repite en cada
    // test: el $data es lo que el formulario pregunta y los $arguments son
    // lo que manda el modal del plano.
    $this->vender = fn (array $data, array $arguments): object => Livewire::test(
        VerPlano::class,
        ['record' => $this->proyecto->getKey()]
    )->callAction('venderLote', $data, $arguments);
});

/*
|--------------------------------------------------------------------------
| Lo que el modal cotiza es lo que se firma
|--------------------------------------------------------------------------
*/
describe('La cotizacion del modal', function (): void {
    /*
    | ═══ EL TEST QUE HAY QUE MIRAR SI ESTO SE ROMPE ═══
    |
    | Cuando la cotizacion viene del modal, el plazo, el precio y la prima
    | se OCULTAN en el formulario —ya estan decididos— y Filament no envia
    | lo oculto: isDehydrated() borra la clave del estado. Sin
    | dehydratedWhenHidden() los tres llegaban vacios y la venta se armaba
    | a L 0.00 y de contado.
    |
    | No reventaba: el Service veia un precio por debajo de la lista y
    | pedia motivo por escrito (R4), con un mensaje que no tenia nada que
    | ver con lo que estaba pasando.
    */
    test('el plazo, el precio y la prima del modal llegan enteros a la venta', function (): void {
        $lote = ($this->lote)('1');

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '75000.00'],
        )->assertHasNoActionErrors();

        $venta = Venta::query()->firstOrFail();

        // 250 vr² × L 1,500.00 = L 375,000.00 · saldo 300,000 / 12 = 25,000
        expect($venta->montoValorTotal())->toBeMonto('375000.00')
            ->and($venta->montoPrima())->toBeMonto('75000.00')
            ->and($venta->getAttribute('plazo_meses'))->toBe(12)
            ->and($venta->montoCuotaMensual())->toBeMonto('25000.00')
            ->and($venta->cuotas()->count())->toBe(12)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });

    /*
    | El precio de lista es EL DEL PLAZO. De contado la vara vale L 1,300 y
    | el lote tiene L 1,400 en su ficha: vender de contado a 1,300 es el
    | precio oficial, no un descuento, y no puede pedir motivo.
    */
    test('vender al precio del plan de contado no pide motivo', function (): void {
        $lote = ($this->lote)('1');

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            ['lote' => $lote->getKey(), 'plazo' => 0, 'precio' => '1300.00', 'prima' => '325000.00'],
        )->assertHasNoActionErrors();

        $venta = Venta::query()->firstOrFail();

        expect($venta->montoValorTotal())->toBeMonto('325000.00')
            ->and($venta->esDeContado())->toBeTrue()
            ->and($venta->cuotas()->count())->toBe(0);
    });

    test('un precio por debajo de la lista sin motivo no graba nada', function (): void {
        $lote = ($this->lote)('1');

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1200.00', 'prima' => '0'],
        );

        expect(Venta::query()->count())->toBe(0)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });
});

/*
|--------------------------------------------------------------------------
| El mismo lote a nombre de dos personas
|--------------------------------------------------------------------------
| «se le puede vender a un cliente y a su esposa, o sea el mismo lote a
| nombre de dos personas» — Mauricio, 5-ago-2026.
*/
describe('Copropietarios', function (): void {
    test('el lote queda a nombre de los dos, con un solo titular', function (): void {
        $lote = ($this->lote)('1');

        ($this->vender)(
            [
                'cliente_id'     => $this->rosa->getKey(),
                'copropietarios' => [$this->carlos->getKey()],
            ],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '75000.00'],
        )->assertHasNoActionErrors();

        $venta = Venta::query()->firstOrFail();

        expect($venta->clientes()->count())->toBe(2)
            ->and($venta->titular()?->getKey())->toBe($this->rosa->getKey())
            ->and($venta->clientes()->wherePivot('titular', true)->count())->toBe(1);
    });

    /*
    | Elegir a la misma persona en los dos campos es un error de dedo, no
    | una venta invalida. El indice unico (venta_id, cliente_id) del pivot
    | lo rechazaria con un error de base que no le dice nada a nadie.
    */
    test('el titular elegido tambien como copropietario no se duplica', function (): void {
        $lote = ($this->lote)('1');

        ($this->vender)(
            [
                'cliente_id'     => $this->rosa->getKey(),
                'copropietarios' => [$this->rosa->getKey(), $this->carlos->getKey()],
            ],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '75000.00'],
        )->assertHasNoActionErrors();

        expect(Venta::query()->firstOrFail()->clientes()->count())->toBe(2);
    });
});

/*
|--------------------------------------------------------------------------
| Varios lotes en un solo contrato
|--------------------------------------------------------------------------
| «ademas de eso se le o les puede vender mas de un lote a esa o esas
| personas» — el mismo mensaje.
*/
describe('Varios lotes', function (): void {
    test('los tres lotes entran al mismo expediente y suman su valor', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');
        $tres = ($this->lote)('3');

        ($this->vender)(
            [
                'cliente_id'     => $this->rosa->getKey(),
                'copropietarios' => [$this->carlos->getKey()],
            ],
            [
                'lote'        => $uno->getKey(),
                'condiciones' => [
                    ($this->condicion)($uno, 12, '1500.00', '125000.00'),
                    ($this->condicion)($dos, 12, '1500.00'),
                    ($this->condicion)($tres, 12, '1500.00'),
                ],
            ],
        )->assertHasNoActionErrors();

        $venta = Venta::query()->firstOrFail();

        // 3 × 250 vr² × L 1,500.00 = L 1,125,000.00
        expect(Venta::query()->count())->toBe(1)
            ->and($venta->montoValorTotal())->toBeMonto('1125000.00')
            ->and($venta->compromisos()->count())->toBe(3)
            ->and($venta->clientes()->count())->toBe(2)
            ->and($uno->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido)
            ->and($dos->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido)
            ->and($tres->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });

    /*
    | ═══ EL CAMINO DE VERDAD ═══
    |
    | Nadie busca lotes en un desplegable de 301 teniendo el mapa delante.
    | El vendedor los marca clickeando y la barra del contrato manda el
    | primero en `lote` y el resto en `extra`. El selector del formulario es
    | la red por si marco uno de mas, no el lugar donde se eligen.
    */
    test('los lotes marcados en el plano llegan puestos, sin tocar el selector', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');
        $tres = ($this->lote)('3');

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            [
                'lote'        => $uno->getKey(),
                'condiciones' => [
                    ($this->condicion)($uno, 12, '1500.00', '125000.00'),
                    ($this->condicion)($dos, 12, '1500.00'),
                    ($this->condicion)($tres, 12, '1500.00'),
                ],
            ],
        )->assertHasNoActionErrors();

        $venta = Venta::query()->firstOrFail();

        expect($venta->compromisos()->count())->toBe(3)
            ->and($venta->montoValorTotal())->toBeMonto('1125000.00')
            ->and($tres->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });

    /*
    | El lote del plano ya viene por $arguments. Si ademas llegara entre los
    | extra —dos clics, un back del navegador, una barra que manana se arme
    | mal— seria el mismo lote dos veces en la misma venta y el dominio la
    | rechaza.
    |
    | Se resuelve ANTES de que se vea: el lote abierto no es una opcion del
    | selector de otros lotes, asi que dejarlo en el estado no daria un lote
    | repetido sino un formulario invalido entero, por la regla `in` que
    | Filament arma con las opciones.
    */
    test('el lote del plano repetido entre los extra no se duplica', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            [
                'lote'        => $uno->getKey(),
                'condiciones' => [
                    ($this->condicion)($uno, 12, '1500.00', '50000.00'),
                    ($this->condicion)($uno, 12, '1500.00'),
                    ($this->condicion)($dos, 12, '1500.00'),
                ],
            ],
        )->assertHasNoActionErrors();

        $venta = Venta::query()->firstOrFail();

        expect($venta->compromisos()->count())->toBe(2)
            ->and($venta->montoValorTotal())->toBeMonto('750000.00');
    });

    /*
    | «1 lote a 12 meses, segundo lote a 24 y tercer lote a 48, todo en un
    | mismo contrato» — desde el plano, que es donde se marca cada uno.
    |
    | 250 vr² × L 1,500.00 = L 375,000.00 por lote; con L 75,000.00 de prima
    | quedan L 300,000.00 a financiar, que a 12, 24 y 48 meses dan L 25,000,
    | L 12,500 y L 6,250. El primer mes paga los tres: L 43,750.00.
    */
    test('cada lote se firma con el plazo que se le marco en el plano', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');
        $tres = ($this->lote)('3');

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            [
                'lote'        => $uno->getKey(),
                'condiciones' => [
                    ($this->condicion)($uno, 12, '1500.00', '75000.00'),
                    ($this->condicion)($dos, 24, '1500.00', '75000.00'),
                    ($this->condicion)($tres, 48, '1500.00', '75000.00'),
                ],
            ],
        )->assertHasNoActionErrors();

        $venta = Venta::query()->firstOrFail();

        expect($venta->compromisos()->orderBy('lote_id')->pluck('plazo_meses')->all())->toBe([12, 24, 48])
            ->and($venta->montoValorTotal())->toBeMonto('1125000.00')
            ->and($venta->montoPrima())->toBeMonto('225000.00')
            // El horizonte del contrato y lo que paga el primer mes.
            ->and($venta->getAttribute('plazo_meses'))->toBe(48)
            ->and($venta->montoCuotaMensual())->toBeMonto('43750.00')
            ->and(Cuota::query()->count())->toBe(84);
    });

    test('un lote apartado a otra persona tumba la venta entera', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');

        new RegistroDeCompromisos()->apartar($dos, $this->carlos);

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            [
                'lote'        => $uno->getKey(),
                'condiciones' => [
                    ($this->condicion)($uno, 12, '1500.00', '50000.00'),
                    ($this->condicion)($dos, 12, '1500.00'),
                ],
            ],
        );

        // Todo o nada: ni el lote libre se mueve.
        expect(Venta::query()->count())->toBe(0)
            ->and($uno->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and($dos->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado);
    });
});

/*
|--------------------------------------------------------------------------
| Apartar desde el plano
|--------------------------------------------------------------------------
*/
describe('Apartar', function (): void {
    test('el apartado queda con la seña y el vencimiento del modal', function (): void {
        $lote = ($this->lote)('1');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'apartarLote',
                ['cliente_id' => $this->rosa->getKey()],
                ['lote'       => $lote->getKey(), 'senia' => '5000.00', 'vence' => today()->addDays(15)->toDateString()],
            )
            ->assertHasNoActionErrors();

        $compromiso = Compromiso::query()->firstOrFail();

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado)
            ->and($compromiso->getAttribute('tipo'))->toBe(TipoCompromiso::Apartado)
            ->and($compromiso->getAttribute('cliente_id'))->toBe($this->rosa->getKey())
            ->and($compromiso->getAttribute('vence_el')?->toDateString())
            ->toBe(today()->addDays(15)->toDateString());
    });

    /*
    | R14: L 5,000.00 y 15 dias los fijo la contratante. Sin que el modal
    | mande nada, el formulario propone esos, no vacio.
    */
    test('sin numeros del modal, el apartado sale con los de R14', function (): void {
        $lote = ($this->lote)('1');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction('apartarLote', ['cliente_id' => $this->rosa->getKey()], ['lote' => $lote->getKey()])
            ->assertHasNoActionErrors();

        expect(Compromiso::query()->firstOrFail()->getAttribute('vence_el')?->toDateString())
            ->toBe(today()->addDays((int) config('lotificadora.apartados.dias_de_vigencia', 15))->toDateString());
    });

    /*
    | «y si quiere apartar mas de un lote? asi como para vender» — Mauricio.
    |
    | La seña es POR LOTE: son tres compromisos de L 5,000.00, no L 5,000.00
    | repartidos entre tres. Cada uno tiene su vencimiento y su historial, y
    | los tres cuentan despues como parte de la prima.
    */
    test('se apartan varios lotes de un tiron, con su seña cada uno', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');
        $tres = ($this->lote)('3');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction('apartarLote', ['cliente_id' => $this->rosa->getKey()], [
                'lote'  => $uno->getKey(),
                'extra' => [$dos->getKey(), $tres->getKey()],
                'senia' => '5000.00',
                'vence' => today()->addDays(15)->toDateString(),
            ])
            ->assertHasNoActionErrors();

        expect(Compromiso::query()->count())->toBe(3)
            ->and((string) Compromiso::query()->sum('monto_senia'))->toBe('15000.00')
            ->and($uno->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado)
            ->and($dos->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado)
            ->and($tres->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado);
    });

    /*
    | Todo o nada, igual que la venta. Si a uno se lo llevaron mientras se
    | armaba la pantalla, apartar los otros dos seria dejar a medias algo
    | que nadie pidio a medias — y el vendedor se iria creyendo que aparto
    | tres.
    |
    | Ojo con el camino: el lote que YA esta apartado tiene que seguir
    | apareciendo en el selector aunque no califique, o Filament tumba el
    | formulario entero por la regla `in` y el mensaje no nombra a nadie.
    */
    test('si a uno ya se lo llevaron, no se aparta ninguno', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');

        new RegistroDeCompromisos()->apartar($dos, $this->carlos);

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction('apartarLote', ['cliente_id' => $this->rosa->getKey()], [
                'lote'  => $uno->getKey(),
                'extra' => [$dos->getKey()],
            ])
            ->assertHasNoActionErrors();

        // El de Carlos sigue siendo de Carlos, y el libre sigue libre.
        expect(Compromiso::query()->count())->toBe(1)
            ->and($uno->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and($dos->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado);
    });

    /*
    | Apartado y despues vendido al MISMO cliente: el apartado se convierte
    | y su seña cuenta como parte de la prima (R14). Es el camino normal.
    */
    test('el lote apartado se le vende a la misma persona', function (): void {
        $lote = ($this->lote)('1');

        new RegistroDeCompromisos()->apartar($lote, $this->rosa);

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '75000.00'],
        )->assertHasNoActionErrors();

        expect(Venta::query()->count())->toBe(1)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });
});
