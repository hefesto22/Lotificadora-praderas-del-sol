<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Lotes\RegistroDeReservas;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Filament\Resources\Proyectos\Pages\VerPlano;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Filament\Resources\Ventas\VentaResource;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
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

    /*
    | Con los dos cupos de sobra. Desde el 13-ago-2026 donar exige que el
    | desarrollo haya declarado cuantos lotes va a regalar, y guardar para
    | herencia lo mismo. Este archivo prueba el camino del PLANO —el modal,
    | el motivo, el cliente—, no las reglas del cupo: esas viven en
    | CupoDeDonacionesTest y en CupoDeHerenciaTest.
    */
    $this->proyecto = Proyecto::factory()->create([
        'codigo'           => 'RPS',
        'dona_lotes'       => true,
        'lotes_a_donar'    => 50,
        'reserva_lotes'    => true,
        'lotes_a_reservar' => 50,
    ]);
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
    /*
    | ⚠️ `confirmado` va por DEFECTO en el helper, no en cada test. Desde el
    | 14-ago-2026 el modal exige tildar «revisé el plazo, el precio y la
    | cuota» antes de firmar, y esa casilla es del formulario, no del caso que
    | cada test quiere probar. Los dos tests que SÍ prueban la casilla la
    | mandan explícitamente en false.
    */
    $this->vender = fn (array $data, array $arguments): object => Livewire::test(
        VerPlano::class,
        ['record' => $this->proyecto->getKey()]
    )->callAction('venderLote', array_merge(['confirmado' => true], $data), $arguments);
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
    | ═══ 🔴 EL TEST QUE JUSTIFICA HABER MOVIDO EL CALCULO AL SERVIDOR ═══
    |
    | El cuadro del modal se calculaba en el navegador: valor dividido entre
    | los meses. Mientras ningun plan cobraba interes daba el numero correcto
    | y nadie lo noto. El dia que un plan quedo al 12 % anual, la pantalla que
    | el vendedor le muestra al cliente decia L 54,166.67 donde el contrato
    | iba a decir L 57,751.71 — tres mil quinientos ochenta y cinco lempiras
    | por mes, dichos en voz alta, con el cliente enfrente.
    |
    | Ahora las dos puntas salen del mismo `PlanDeCuotas`, y esto las compara.
    | Si alguna vez dejan de coincidir, no se ajusta el numero esperado: se
    | busca cual de las dos empezo a mentir.
    */
    test('la cuota que el modal cotiza es exactamente la que se firma', function (): void {
        PlanDePago::query()->where('meses', 12)->firstOrFail()->update(['tasa_interes_anual' => '12.000']);

        $lote = ($this->lote)('7');

        $cuadro = Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->instance()
            /*
             * ⚠️ La MISMA prima que se va a firmar mas abajo, y no cero.
             * Desde el 14-ago-2026 una venta financiada exige prima > 0, pero
             * el motivo de fondo es mejor test: cotizar con una prima y
             * firmar con otra comparaba dos planes distintos y podia dar
             * igual de casualidad. Ahora las dos puntas parten del mismo dato.
             */
            ->cotizar(['lote' => $lote->getKey(), 'prima' => '50000.00']);

        $doceMeses = ['cuota' => null, 'interes' => null];

        foreach ($cuadro as $fila) {
            if ($fila['meses'] === 12) {
                $doceMeses = $fila;
            }
        }

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '50000.00'],
        )->assertHasNoActionErrors();

        $venta = Venta::query()->firstOrFail();

        expect($doceMeses['cuota'])->toBe($venta->montoCuotaMensual()?->formateado())
            // Y el interes que la pantalla anuncia existe de verdad: sin esto,
            // dos ceros iguales harian pasar el test sin probar nada.
            ->and($doceMeses['interes'])->not->toBeNull();
    });

    /*
    | R4 aplicado al precio del dinero. Bajar la tasa regala plata igual que
    | bajar el precio del terreno, asi que se guardan LAS DOS —la pactada y
    | la que ofrecia el plan— y el motivo escrito. Sin la de lista no se puede
    | contestar «¿cuanto interes se resigno?» sin adivinar.
    */
    test('el interes negociado se congela con su motivo y con la tasa de lista', function (): void {
        PlanDePago::query()->where('meses', 12)->firstOrFail()->update(['tasa_interes_anual' => '12.000']);

        $lote = ($this->lote)('9');

        ($this->vender)(
            [
                'cliente_id'  => $this->rosa->getKey(),
                'motivo_tasa' => 'Cliente recomendado por la administracion',
            ],
            [
                'lote'   => $lote->getKey(),
                'plazo'  => 12,
                'precio' => '1500.00',
                // Con plan de cuotas la prima no puede ser cero (14-ago-2026).
                'prima' => '50000.00',
                'tasa'  => '6.000',
            ],
        )->assertHasNoActionErrors();

        $compromiso = Compromiso::query()->where('tipo', TipoCompromiso::Venta)->firstOrFail();

        expect($compromiso->tasaDeInteres()->redondeada())->toBe('6.000')
            ->and($compromiso->tasaDeLista()->redondeada())->toBe('12.000')
            ->and($compromiso->huboRebajaDeTasa())->toBeTrue()
            ->and($compromiso->getAttribute('motivo_tasa'))->toBe('Cliente recomendado por la administracion');
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

        app(RegistroDeCompromisos::class)->apartar($dos, $this->carlos);

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
                // `confirmado` es de la casilla del modal, no de lo que este
                // test prueba. Va explícito porque apartar no tiene el helper
                // que sí tiene vender: ver la nota del `$this->vender`.
                ['confirmado' => true, 'cliente_id' => $this->rosa->getKey()],
                ['lote' => $lote->getKey(), 'senia' => '5000.00', 'vence' => today()->addDays(15)->toDateString()],
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
            ->callAction('apartarLote', ['confirmado' => true, 'cliente_id' => $this->rosa->getKey()], ['lote' => $lote->getKey()])
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
            ->callAction('apartarLote', ['confirmado' => true, 'cliente_id' => $this->rosa->getKey()], [
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

        app(RegistroDeCompromisos::class)->apartar($dos, $this->carlos);

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction('apartarLote', ['confirmado' => true, 'cliente_id' => $this->rosa->getKey()], [
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

        app(RegistroDeCompromisos::class)->apartar($lote, $this->rosa);

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '75000.00'],
        )->assertHasNoActionErrors();

        expect(Venta::query()->count())->toBe(1)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });
});

/*
|--------------------------------------------------------------------------
| Donar DESDE EL PLANO
|--------------------------------------------------------------------------
| El unico camino que hay hoy para registrar una donacion, y por eso importa
| que este probado: el dominio ya rechaza lo que no corresponde, pero un
| formulario que no manda el motivo o que pierde el cliente deja el rechazo
| del lado equivocado —delante de la persona que esta atendiendo—.
*/
describe('Donar', function (): void {
    test('el lote sale del inventario y no queda cartera detras', function (): void {
        $lote = ($this->lote)('1');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'donarLote',
                [
                    'cliente_id' => $this->rosa->getKey(),
                    'motivo'     => 'Donado a la Iglesia Congregacional. Acta del 12-ago-2026.',
                ],
                ['lote' => $lote->getKey()],
            )
            ->assertHasNoActionErrors();

        $donacion = Compromiso::query()->firstOrFail();

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado)
            ->and($donacion->getAttribute('tipo'))->toBe(TipoCompromiso::Donacion)
            ->and($donacion->getAttribute('cliente_id'))->toBe($this->rosa->getKey())
            ->and($donacion->getAttribute('motivo'))->toContain('Iglesia Congregacional')
            ->and(Venta::query()->count())->toBe(0)
            ->and(Cuota::query()->count())->toBe(0);
    });

    /*
    | El camino de los herederos: el lote se guarda mientras corre el tramite
    | y se dona cuando se firma. Es la razon de que `reservado` este en la
    | lista blanca de `EstadoLote::seDona()`.
    */
    test('un lote reservado tambien se dona desde el plano', function (): void {
        $lote = ($this->lote)('2');
        $lote->forceFill(['estado' => EstadoLote::Reservado])->save();

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'donarLote',
                ['cliente_id' => $this->rosa->getKey(), 'motivo' => 'Adjudicacion a los herederos.'],
                ['lote'       => $lote->getKey()],
            )
            ->assertHasNoActionErrors();

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado);
    });

    /*
    | Sin motivo el formulario ni siquiera llega al dominio: `required()` lo
    | para antes. Se verifica que lo pare Y que no haya dejado nada a medias.
    */
    test('sin motivo no se dona y el lote no se mueve', function (): void {
        $lote = ($this->lote)('3');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction('donarLote', ['cliente_id' => $this->rosa->getKey()], ['lote' => $lote->getKey()])
            ->assertHasActionErrors(['motivo']);

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and(Compromiso::query()->count())->toBe(0);
    });

    /*
    | Quitar la donacion, que es el otro boton del mismo modal y hoy el unico
    | camino que hay: «iban a donar 5, los donaron, pero solo se donarian 3».
    | El dominio ya tiene sus reglas probadas en CupoDeDonacionesTest; lo que
    | falta ver aca es que el boton mande el motivo y no se coma el lote.
    */
    test('quitar la donacion devuelve el lote al inventario', function (): void {
        $lote = ($this->lote)('4');

        app(RegistroDeCompromisos::class)->donar($lote, $this->rosa, 'Se marcaron cinco por error.');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'deshacerDonacion',
                ['motivo' => 'La junta aprobo donar solo tres; estos dos vuelven a la venta.'],
                ['lote'   => $lote->getKey()],
            )
            ->assertHasNoActionErrors();

        $cerrado = Compromiso::query()->where('lote_id', $lote->getKey())->firstOrFail();

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and($cerrado->getAttribute('estado'))->toBe(EstadoCompromiso::Liberado)
            ->and($cerrado->getAttribute('motivo'))->toContain('solo tres')
            // Una donacion no movio plata: no hay nada que devolver.
            ->and(Venta::query()->count())->toBe(0)
            ->and(Cuota::query()->count())->toBe(0);
    });

    test('sin motivo la donacion no se quita', function (): void {
        $lote = ($this->lote)('5');

        app(RegistroDeCompromisos::class)->donar($lote, $this->rosa, 'Iglesia del sector.');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction('deshacerDonacion', [], ['lote' => $lote->getKey()])
            ->assertHasActionErrors(['motivo']);

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado);
    });
});

/*
|--------------------------------------------------------------------------
| Guardar para HERENCIA desde el plano
|--------------------------------------------------------------------------
| El otro camino que achica el inventario sin una venta atras, y el unico
| que hay hoy para marcar un lote como reservado sin editarle el estado a
| mano desde su ficha. Pedido de Mauricio el 13-ago-2026: «estos son para
| lotes heredados».
|
| Las reglas del cupo viven en CupoDeHerenciaTest; aca se prueba que el
| boton mande el motivo y mueva el lote.
*/
describe('Guardar para herencia', function (): void {
    test('el lote sale del mercado con su motivo escrito', function (): void {
        $lote = ($this->lote)('6');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'reservarLote',
                ['motivo' => 'Guardado para los herederos de la familia Mejia. Acta del 12-ago-2026.'],
                ['lote'   => $lote->getKey()],
            )
            ->assertHasNoActionErrors();

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Reservado)
            ->and($lote->getAttribute('observaciones'))->toContain('herederos de la familia Mejia')
            // Guardar no vende nada ni ata a nadie: no hay cartera detras.
            ->and(Venta::query()->count())->toBe(0)
            ->and(Compromiso::query()->count())->toBe(0);
    });

    test('sin motivo no se guarda y el lote no se mueve', function (): void {
        $lote = ($this->lote)('7');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction('reservarLote', [], ['lote' => $lote->getKey()])
            ->assertHasActionErrors(['motivo']);

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    test('devolver a la venta lo deja disponible otra vez', function (): void {
        $lote = ($this->lote)('8');

        app(RegistroDeReservas::class)->reservar($lote, 'Para los herederos.');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'deshacerReserva',
                ['motivo' => 'La familia decidio no quedarselo.'],
                ['lote'   => $lote->getKey()],
            )
            ->assertHasNoActionErrors();

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            // Las dos mitades quedan escritas, no se pisan.
            ->and($lote->getAttribute('observaciones'))->toContain('Para los herederos.')
            ->and($lote->getAttribute('observaciones'))->toContain('La familia decidio no quedarselo.');
    });

    /*
    | El camino largo: se guarda mientras corre el tramite del heredero y se
    | dona cuando se firma. Es la razon de que `reservado` este en la lista
    | blanca de EstadoLote::seDona().
    */
    test('un lote guardado se puede donar sin devolverlo antes', function (): void {
        $lote = ($this->lote)('9');

        app(RegistroDeReservas::class)->reservar($lote, 'Para los herederos, mientras corre el tramite.');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'donarLote',
                ['cliente_id' => $this->rosa->getKey(), 'motivo' => 'Se firmo la adjudicacion.'],
                ['lote'       => $lote->getKey()],
            )
            ->assertHasNoActionErrors();

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado);
    });
});

/*
| Despues de FIRMAR, el plano deja de ser el lugar. Lo que sigue —imprimir el
| contrato, mirar el plan, cobrar— pasa en el expediente, y quedarse en el
| mapa obliga a ir a buscar el que uno acaba de crear. Pedido de Mauricio el
| 14-ago-2026.
|
| Los otros cuatro movimientos del plano —apartar, donar, liberar, guardar
| para herencia— siguen volviendo al plano, y eso es a proposito: ahi quien
| atiende sigue trabajando sobre el mapa.
*/
test('despues de firmar, la pantalla salta al expediente', function (): void {
    $lote = ($this->lote)('21');

    ($this->vender)(
        ['cliente_id' => $this->rosa->getKey()],
        ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '50000.00'],
    )
        ->assertHasNoActionErrors()
        ->assertRedirect(VentaResource::getUrl('view', [
            'record' => Venta::query()->latest('id')->firstOrFail(),
        ]));
});

test('apartar sigue devolviendo al plano', function (): void {
    $lote = ($this->lote)('22');

    Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
        ->callAction('apartarLote', ['confirmado' => true, 'cliente_id' => $this->rosa->getKey()], ['lote' => $lote->getKey()])
        ->assertHasNoActionErrors()
        ->assertRedirect(ProyectoResource::getUrl('plano', ['record' => $this->proyecto]));
});

/*
|--------------------------------------------------------------------------
| La última puerta antes de quemar un correlativo — 14-ago-2026
|--------------------------------------------------------------------------
| «Que aparezca una alerta cuando le dé vender, para que confirmen que están
| seguros de esa venta, en ese plazo y con ese precio por vara y cuota
| mensual» — Mauricio.
|
| La tabla con esos números ya estaba en el modal. Lo que faltaba no era el
| dato: era el ACTO de confirmarlo. Sin la casilla tildada no se firma, y eso
| es lo que estos dos tests sostienen.
*/
test('sin confirmar no se firma la venta', function (): void {
    $lote = ($this->lote)('31');

    ($this->vender)(
        ['cliente_id' => $this->rosa->getKey(), 'confirmado' => false],
        ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '50000.00'],
    )->assertHasActionErrors(['confirmado']);

    expect(Venta::query()->count())->toBe(0)
        ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
});

test('sin confirmar no se aparta el lote', function (): void {
    $lote = ($this->lote)('32');

    Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
        ->callAction('apartarLote', [
            'confirmado' => false,
            'cliente_id' => $this->rosa->getKey(),
        ], ['lote' => $lote->getKey()])
        ->assertHasActionErrors(['confirmado']);

    expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
});

/*
|--------------------------------------------------------------------------
| 🔴 Quién recibió el dinero, también acá — 31-ago-2026
|--------------------------------------------------------------------------
| «Acá en apartar que se coloque quién recibe el dinero, y cuando se vende
| también quién recibe el dinero» — Mauricio.
|
| El campo existía desde el 27-ago, pero solo en el modal de cobro. La seña y
| la prima —las otras dos puertas por donde entra dinero— no lo preguntaban ni
| lo escribían, así que el corte de caja del día las sumaba bajo «Sin usuario».
|
| Cada test cubre el cable entero: el campo del modal tiene que llegar hasta la
| fila del recibo.
*/
describe('Quién recibió el dinero (R24)', function (): void {
    beforeEach(function (): void {
        $this->enLaCaseta = User::factory()->create(['name' => 'Elder Martínez', 'is_active' => true]);

        /*
         * ⚠️ El permiso no es decorado: el `Select` de Filament arma solo una
         * regla `in` con sus opciones, y las opciones de este campo son «quien
         * puede cobrar». Sin esto el valor se rechaza por inválido — que es
         * justo lo que tiene que pasar con alguien que no cobra.
         */
        $this->enLaCaseta->givePermissionTo('Create:Recibo');
    });

    test('la seña del apartado queda a nombre de quien la recibió', function (): void {
        $lote = ($this->lote)('40');

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction('apartarLote', [
                'confirmado'   => true,
                'cliente_id'   => $this->rosa->getKey(),
                'monto_senia'  => '5000.00',
                'forma_pago'   => FormaDePago::Efectivo->value,
                'recibido_por' => $this->enLaCaseta->getKey(),
            ], ['lote' => $lote->getKey()])
            ->assertHasNoActionErrors();

        $senia = Recibo::query()->where('concepto', '=', ConceptoDeRecibo::Senia->value)->firstOrFail();

        // Quién lo recibió y quién lo tecleó son dos preguntas, y las dos se guardan.
        expect((int) $senia->getAttribute('recibido_por'))->toBe((int) $this->enLaCaseta->getKey())
            ->and((int) $senia->getAttribute('created_by'))->toBe((int) auth()->id());
    });

    test('la prima de la venta queda a nombre de quien la recibió', function (): void {
        $lote = ($this->lote)('41');

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey(), 'recibido_por' => $this->enLaCaseta->getKey()],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '50000.00'],
        )->assertHasNoActionErrors();

        $prima = Recibo::query()->where('concepto', '=', ConceptoDeRecibo::Prima->value)->firstOrFail();

        expect((int) $prima->getAttribute('recibido_por'))->toBe((int) $this->enLaCaseta->getKey())
            ->and((int) $prima->getAttribute('created_by'))->toBe((int) auth()->id());
    });

    /*
    | La otra mitad, y la razón de que el campo NO sea obligatorio: si nadie
    | elige a nadie, el recibo dice quien tecleó. Es lo que el sistema hizo
    | siempre y nunca es mentira — lo escribe `Recibo::booted()`.
    */
    test('sin elegir a nadie, el recibo dice quien tecleó', function (): void {
        $lote = ($this->lote)('42');

        ($this->vender)(
            ['cliente_id' => $this->rosa->getKey()],
            ['lote' => $lote->getKey(), 'plazo' => 12, 'precio' => '1500.00', 'prima' => '50000.00'],
        )->assertHasNoActionErrors();

        $prima = Recibo::query()->where('concepto', '=', ConceptoDeRecibo::Prima->value)->firstOrFail();

        expect((int) $prima->getAttribute('recibido_por'))->toBe((int) auth()->id());
    });
});

/**
 * Un desarrollo recien creado: con lotes dibujados y SIN un solo plan de pago.
 *
 * @return array{proyecto: Proyecto, lote: Lote}
 */
function unDesarrolloSinPlanes(): array
{
    $proyecto = Proyecto::factory()->create(['codigo' => 'SPL']);

    $bloque = Bloque::factory()->create([
        'proyecto_id' => $proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    $lote = Lote::factory()
        ->enBloque($bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create([
            'numero'   => '1',
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

    return ['proyecto' => $proyecto, 'lote' => $lote];
}

/*
|--------------------------------------------------------------------------
| Sin plan de pago no se vende — 23-ago-2026
|--------------------------------------------------------------------------
| Pedido de Mauricio, mirando el plano de un desarrollo sin planes cargados:
| el botón «Vender este lote» estaba HABILITADO. La condición del blade era
| `planes.length > 0 && ! hayPlan`, que con CERO planes da false — o sea que
| el botón se bloqueaba en todos los casos MENOS en el único donde no hay ni
| precio por vara² ni plazo con que armar el contrato. Se vendía tecleando
| todo a mano, y ese precio no queda en ninguna lista.
|
| 🔴 Vender y apartar NO son lo mismo acá: vender firma un precio y un plazo
| por cuatro años; apartar reserva y cobra una seña que se devuelve. Por eso
| apartar sigue abierto — y esa es la mitad de la regla que hay que cuidar,
| porque es la que deja retener a un cliente mientras se arma la lista.
|
| ⚠️ NO se afirma con `assertHasActionErrors`. `conElLote()` atrapa toda
| `GrupoOlympoException` y la convierte en Notification roja, así que la
| acción termina «sin errores» (Regla 1-sexies de [[como-verifico-antes-de-entregar]]).
| Lo único que prueba algo es la BASE: que no quedó venta.
*/
describe('Sin plan de pago no se vende', function (): void {
    test('el proyecto sabe si tiene con que vender', function (): void {
        ['proyecto' => $sinPlanes] = unDesarrolloSinPlanes();

        expect($sinPlanes->tieneConQueVender())->toBeFalse()
            ->and($this->proyecto->tieneConQueVender())->toBeTrue();
    });

    /*
    | Un plan APAGADO no cuenta. Es el caso de la lista vieja que se
    | desactiva al subir precios: mientras no haya una nueva, no se vende.
    */
    test('un plan desactivado no habilita la venta', function (): void {
        ['proyecto' => $proyecto] = unDesarrolloSinPlanes();

        PlanDePago::factory()->delProyecto($proyecto)->aPlazo(12, '1500.00')->create(['activo' => false]);

        expect($proyecto->tieneConQueVender())->toBeFalse();
    });

    /*
    | Y un plan en L 0.00 tampoco (24-ago-2026). Es el estado en el que queda
    | un desarrollo al que todavía no le cargaron la lista: existe la fila, no
    | existe el precio. Lo vio Mauricio en el plano de pruebas, con los cinco
    | plazos ofreciéndose en cero.
    */
    test('un plan en cero no habilita la venta', function (): void {
        ['proyecto' => $proyecto] = unDesarrolloSinPlanes();

        PlanDePago::factory()->delProyecto($proyecto)->aPlazo(12, '0.00')->create();

        expect($proyecto->tieneConQueVender())->toBeFalse();

        PlanDePago::factory()->delProyecto($proyecto)->aPlazo(24, '1500.00')->create();

        // Con uno solo que tenga precio, ya hay con qué armar un contrato.
        expect($proyecto->refresh()->tieneConQueVender())->toBeTrue();
    });

    /*
    | 🔴 EL BORDE DE VERDAD: el botón se deshabilita en la pantalla, pero la
    | acción se monta desde JS con `$wire.mountAction(...)`, y eso se dispara
    | desde la consola del navegador en diez segundos. Acá se llama derecho,
    | como lo haría cualquiera con las herramientas de desarrollador abiertas.
    */
    test('llamar la accion a mano tampoco vende', function (): void {
        ['proyecto' => $proyecto, 'lote' => $lote] = unDesarrolloSinPlanes();

        Livewire::test(VerPlano::class, ['record' => $proyecto->getKey()])
            ->callAction('venderLote', [
                'confirmado' => true,
                'cliente_id' => $this->rosa->getKey(),
            ], [
                'lote'   => $lote->getKey(),
                'plazo'  => 12,
                'precio' => '1500.00',
                'prima'  => '10000.00',
            ])
            /*
             * 🔴 ESTA LINEA ES LA QUE HACE QUE EL TEST PRUEBE ALGO.
             *
             * Sin ella, «no se creó la venta» también sería cierto si el
             * formulario se hubiera caído por validación —un campo que falta,
             * una fecha mal— y el test pasaría sin ejercer la guarda ni una
             * vez. Exigir que NO haya errores de acción deja una sola
             * explicación posible: el formulario validó, la acción corrió, y
             * lo que frenó la venta fue la guarda.
             */
            ->assertHasNoActionErrors();

        expect(Venta::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(0)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    /*
    | La mitad útil de la regla, y la que Mauricio pidió explícitamente:
    | «que no se pueda vender si no hay planes, en apartar sí».
    */
    test('pero apartar SI se puede, y cobra la seña', function (): void {
        ['proyecto' => $proyecto, 'lote' => $lote] = unDesarrolloSinPlanes();

        Livewire::test(VerPlano::class, ['record' => $proyecto->getKey()])
            ->callAction('apartarLote', ['confirmado' => true, 'cliente_id' => $this->rosa->getKey()], ['lote' => $lote->getKey()])
            ->assertHasNoActionErrors();

        expect(Compromiso::query()->where('lote_id', $lote->getKey())->count())->toBe(1)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado);
    });
});
