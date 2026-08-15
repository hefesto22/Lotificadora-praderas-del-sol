<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\ReservaInvalidaException;
use App\Domain\Lotes\RegistroDeReservas;
use App\Domain\Plano\PlanoDelProyecto;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;

/*
| Guardar un lote para la familia lo saca del mercado sin que entre un
| lempira: es la otra forma —además de donar— de que el inventario se
| achique sin una venta atrás. Por eso lleva cupo, igual que las
| donaciones. Pedido de Mauricio, 13-ago-2026: «para los reservados, estos
| son para lotes heredados, así que también hay que colocarlo».
|
| ⚠️ Adentro se dice «herencia» y afuera «reservado». El estado del lote se
| sigue llamando `reservado`; lo que cambia es la palabra que se lee según
| quién esté mirando.
*/

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create([
        'codigo'           => 'HH',
        'reserva_lotes'    => true,
        'lotes_a_reservar' => 2,
    ]);

    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    $this->registro = app(RegistroDeReservas::class);
});

describe('El cupo cuenta lo que queda', function (): void {
    test('sin ningún lote guardado, quedan todos', function (): void {
        expect($this->proyecto->lotesReservados())->toBe(0)
            ->and($this->proyecto->reservasQueQuedan())->toBe(2)
            ->and($this->proyecto->puedeReservarOtroLote())->toBeTrue();
    });

    test('cada lote guardado descuenta uno', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->create();

        expect($this->proyecto->lotesReservados())->toBe(1)
            ->and($this->proyecto->reservasQueQuedan())->toBe(1);
    });

    test('cumplido el cupo ya no se puede guardar otro', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->count(2)->create();

        expect($this->proyecto->reservasQueQuedan())->toBe(0)
            ->and($this->proyecto->puedeReservarOtroLote())->toBeFalse();
    });

    /*
    | Bajar el cupo por debajo de lo guardado no suelta ningún lote solo:
    | quedan cero, nunca un número negativo. Para soltar uno hay que ir al
    | plano y devolverlo a la venta, que es una decisión con motivo.
    */
    test('bajar el cupo no suelta ningún lote', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->count(2)->create();
        $this->proyecto->update(['lotes_a_reservar' => 1]);

        expect($this->proyecto->refresh()->lotesReservados())->toBe(2)
            ->and($this->proyecto->reservasQueQuedan())->toBe(0);
    });

    test('un desarrollo que no guarda lotes tiene cupo cero', function (): void {
        $this->proyecto->update(['reserva_lotes' => false]);

        expect($this->proyecto->refresh()->reservasQueQuedan())->toBe(0)
            ->and($this->proyecto->puedeReservarOtroLote())->toBeFalse();
    });

    /*
    | El cupo es de CADA desarrollo. Lo que Praderas guarde no le mueve el
    | número a El Bambú.
    */
    test('el cupo de un proyecto no mira los lotes del otro', function (): void {
        $otro = Proyecto::factory()->create(['codigo' => 'OTRO', 'reserva_lotes' => true, 'lotes_a_reservar' => 5]);
        $bloqueAjeno = Bloque::factory()->create(['proyecto_id' => $otro->getKey(), 'nombre' => 'Z']);
        Lote::factory()->enBloque($bloqueAjeno)->conEstado(EstadoLote::Reservado)->count(3)->create();

        expect($this->proyecto->lotesReservados())->toBe(0)
            ->and($this->proyecto->reservasQueQuedan())->toBe(2);
    });
});

describe('Guardar un lote para herencia', function (): void {
    test('el lote sale del mercado y el motivo queda escrito', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();

        $this->registro->reservar($lote, 'Guardado para los herederos de la familia Mejía.');

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Reservado)
            ->and($lote->getAttribute('observaciones'))->toContain('herederos de la familia Mejía')
            ->and($lote->getAttribute('observaciones'))->toContain('Guardado para herencia');
    });

    /*
    | Lo que ya estaba escrito NO se pisa: la anotación nueva va arriba y lo
    | viejo queda debajo. La ficha del lote tiene que contar toda la
    | historia, no solo lo último.
    */
    test('lo que ya estaba anotado no se pierde', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create([
            'observaciones' => 'Da a la quebrada. Verificar el retiro antes de vender.',
        ]);

        $this->registro->reservar($lote, 'Para los herederos.');

        expect($lote->refresh()->getAttribute('observaciones'))
            ->toContain('Da a la quebrada')
            ->toContain('Para los herederos.');
    });

    test('sin motivo no se guarda', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();

        expect(fn (): Lote => $this->registro->reservar($lote, '   '))
            ->toThrow(ReservaInvalidaException::class);

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    test('lleno el cupo, el dominio lo rechaza', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->count(2)->create();
        $lote = Lote::factory()->enBloque($this->bloque)->create();

        expect(fn (): Lote => $this->registro->reservar($lote, 'Para los herederos.'))
            ->toThrow(ReservaInvalidaException::class);

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    /*
    | Guardar es solo para el DISPONIBLE. Un apartado o un vendido tienen
    | plata de por medio: primero se deshace eso, con su devolución o su
    | rescisión, y recién después se guarda.
    */
    test('solo se guarda un lote disponible', function (): void {
        foreach ([EstadoLote::Apartado, EstadoLote::Vendido, EstadoLote::Donado, EstadoLote::Cancelado] as $estado) {
            $lote = Lote::factory()->enBloque($this->bloque)->conEstado($estado)->create();

            expect(fn (): Lote => $this->registro->reservar($lote, 'Para los herederos.'))
                ->toThrow(ReservaInvalidaException::class);
        }
    });
});

describe('Devolver a la venta un lote guardado', function (): void {
    test('vuelve a estar disponible y libera el cupo', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();
        $this->registro->reservar($lote, 'Para los herederos.');

        expect($this->proyecto->refresh()->reservasQueQuedan())->toBe(1);

        $this->registro->deshacerReserva($lote, 'La familia decidió no quedárselo.');

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and($this->proyecto->refresh()->lotesReservados())->toBe(0)
            ->and($this->proyecto->reservasQueQuedan())->toBe(2);
    });

    /*
    | Las dos mitades quedan escritas: por qué se había guardado y por qué
    | volvió. Que un lote haya estado fuera del mercado es exactamente lo
    | que alguien va a querer entender dentro de un año.
    */
    test('el motivo viejo y el nuevo conviven', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();

        $this->registro->reservar($lote, 'Para los herederos de la familia Mejía.');
        $this->registro->deshacerReserva($lote, 'La junta aprobó ponerlo a la venta.');

        expect($lote->refresh()->getAttribute('observaciones'))
            ->toContain('Para los herederos de la familia Mejía.')
            ->toContain('La junta aprobó ponerlo a la venta.');
    });

    test('sin motivo no se devuelve', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();
        $this->registro->reservar($lote, 'Para los herederos.');

        expect(fn (): Lote => $this->registro->deshacerReserva($lote, '  '))
            ->toThrow(ReservaInvalidaException::class);

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Reservado);
    });

    test('un lote que no está guardado no se puede devolver', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();

        expect(fn (): Lote => $this->registro->deshacerReserva($lote, 'Motivo cualquiera.'))
            ->toThrow(ReservaInvalidaException::class);
    });
});

describe('Lo que el plano necesita saber', function (): void {
    test('el cupo viaja en el payload del plano', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->create();

        $herencia = new PlanoDelProyecto()->para($this->proyecto)['herencia'];

        expect($herencia)->toBe([
            'activa'    => true,
            'cupo'      => 2,
            'guardados' => 1,
            'quedan'    => 1,
            'puede'     => true,
        ]);
    });

    /*
    | El botón se dibuja con dos condiciones: que el lote se pueda guardar y
    | que quede cupo. La primera viaja por lote, la segunda por proyecto.
    */
    test('cada lote dice si se puede guardar y si se puede devolver', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'numero'   => '1',
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->create([
            'numero'   => '2',
            'poligono' => [[20, 0], [30, 0], [30, 25], [20, 25]],
        ]);

        $lotes = new PlanoDelProyecto()->para($this->proyecto)['lotes'];

        expect($lotes[0]['seReserva'])->toBeTrue()
            ->and($lotes[0]['seDeshaceReserva'])->toBeFalse()
            ->and($lotes[1]['seReserva'])->toBeFalse()
            ->and($lotes[1]['seDeshaceReserva'])->toBeTrue();
    });

    /*
    | «Herencia» adentro, «Reservado» afuera: decisión de Mauricio. El plano
    | de administración usa etiquetaInterna(); el público, etiqueta().
    */
    test('el plano de adentro dice Herencia', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Reservado)->create([
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        $lote = new PlanoDelProyecto()->para($this->proyecto)['lotes'][0];

        expect($lote['etiqueta'])->toBe('Herencia')
            ->and(EstadoLote::Reservado->etiqueta())->toBe('Reservado');
    });
});
