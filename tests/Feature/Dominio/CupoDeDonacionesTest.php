<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\CompromisoInvalidoException;
use App\Domain\Plano\PlanoDelProyecto;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;

/*
| Donar saca un lote del inventario sin que entre un lempira, y es el único
| compromiso que no deja rastro de plata. El cupo es la decisión escrita
| ANTES —cuántos se van a regalar— y lo que hace que el botón desaparezca
| solo cuando se cumplió. Pedido de Mauricio, 13-ago-2026.
*/

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create([
        'codigo'        => 'DD',
        'dona_lotes'    => true,
        'lotes_a_donar' => 2,
    ]);

    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
});

describe('El cupo cuenta lo que queda', function (): void {
    test('sin ninguna donación hecha, quedan todas', function (): void {
        expect($this->proyecto->lotesDonados())->toBe(0)
            ->and($this->proyecto->donacionesQueQuedan())->toBe(2)
            ->and($this->proyecto->puedeDonarOtroLote())->toBeTrue();
    });

    test('cada lote donado descuenta una', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Donado)->create();

        expect($this->proyecto->lotesDonados())->toBe(1)
            ->and($this->proyecto->donacionesQueQuedan())->toBe(1)
            ->and($this->proyecto->puedeDonarOtroLote())->toBeTrue();
    });

    test('cumplido el cupo ya no se puede donar', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Donado)->count(2)->create();

        expect($this->proyecto->donacionesQueQuedan())->toBe(0)
            ->and($this->proyecto->puedeDonarOtroLote())->toBeFalse();
    });

    /*
    | Bajar el cupo por debajo de lo entregado NO deshace nada: una
    | donación es definitiva. Quedan cero, nunca un número negativo.
    */
    test('bajar el cupo por debajo de lo donado no deshace nada', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Donado)->count(2)->create();
        $this->proyecto->update(['lotes_a_donar' => 1]);

        expect($this->proyecto->refresh()->lotesDonados())->toBe(2)
            ->and($this->proyecto->donacionesQueQuedan())->toBe(0)
            ->and($this->proyecto->puedeDonarOtroLote())->toBeFalse();
    });

    test('con las donaciones apagadas no queda ninguna, tenga el cupo que tenga', function (): void {
        $this->proyecto->update(['dona_lotes' => false]);

        expect($this->proyecto->refresh()->cupoDeDonaciones())->toBe(2)
            ->and($this->proyecto->donacionesQueQuedan())->toBe(0)
            ->and($this->proyecto->puedeDonarOtroLote())->toBeFalse();
    });
});

describe('El cupo se respeta en el dominio, no solo en el botón', function (): void {
    /*
    | La guarda vive en donar() y no en el blade porque donar tambien se
    | llama desde un seeder, desde tinker o desde la proxima pantalla que
    | alguien escriba.
    */
    test('donar de más lo rechaza el dominio', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Donado)->count(2)->create();

        $lote = Lote::factory()->enBloque($this->bloque)->create();
        $cliente = Cliente::factory()->create();

        expect(fn (): Compromiso => app(RegistroDeCompromisos::class)->donar($lote, $cliente, 'Iglesia del pueblo'))
            ->toThrow(CompromisoInvalidoException::class);

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    test('dentro del cupo, la donación pasa', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();
        $cliente = Cliente::factory()->create();

        app(RegistroDeCompromisos::class)->donar($lote, $cliente, 'Iglesia del pueblo');

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado)
            ->and($this->proyecto->refresh()->donacionesQueQuedan())->toBe(1);
    });
});

describe('El plano lleva el cupo para dibujar el botón', function (): void {
    test('el payload dice cuántas quedan', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Donado)->create();

        $donaciones = new PlanoDelProyecto()->para($this->proyecto->refresh())['donaciones'];

        expect($donaciones['activas'])->toBeTrue()
            ->and($donaciones['cupo'])->toBe(2)
            ->and($donaciones['hechas'])->toBe(1)
            ->and($donaciones['quedan'])->toBe(1)
            ->and($donaciones['puede'])->toBeTrue();
    });

    test('con el cupo lleno, el plano dice que ya no se puede', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Donado)->count(2)->create();

        $donaciones = new PlanoDelProyecto()->para($this->proyecto->refresh())['donaciones'];

        expect($donaciones['quedan'])->toBe(0)
            ->and($donaciones['puede'])->toBeFalse();
    });
});

describe('Corregir una donación registrada por error', function (): void {
    /*
    | El caso de Mauricio, 13-ago-2026: «iban a donar 5, los donaron, pero
    | hubo un error, así que solo se donarían 3; esos 2 deben quedar
    | disponibles para la venta». Lo que lo hace simple es que una donación
    | no movió un lempira: no hay seña, ni recibos, ni cuotas.
    */
    test('el lote vuelve a estar disponible para la venta', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();
        $cliente = Cliente::factory()->create();
        $registro = app(RegistroDeCompromisos::class);

        $registro->donar($lote, $cliente, 'Iglesia del pueblo');
        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado);

        $registro->deshacerDonacion($lote, 'Se marcaron cinco por error, solo eran tres.');

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            // Y la donación deja de contar contra el cupo.
            ->and($this->proyecto->refresh()->lotesDonados())->toBe(0)
            ->and($this->proyecto->donacionesQueQuedan())->toBe(2);
    });

    /*
    | El compromiso NO se borra: se cierra con su motivo. Que el lote haya
    | figurado como donado y haya vuelto es lo que alguien va a querer
    | entender dentro de un año.
    */
    test('el compromiso queda cerrado con el motivo, no borrado', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();
        $cliente = Cliente::factory()->create();
        $registro = app(RegistroDeCompromisos::class);

        $registro->donar($lote, $cliente, 'Iglesia del pueblo');
        $cerrado = $registro->deshacerDonacion($lote, 'Se marcaron cinco por error.');

        expect($cerrado->getAttribute('estado'))->toBe(EstadoCompromiso::Liberado)
            ->and($cerrado->getAttribute('motivo'))->toBe('Se marcaron cinco por error.')
            ->and($cerrado->getAttribute('cerrado_el'))->not->toBeNull()
            ->and(Compromiso::query()->where('lote_id', $lote->getKey())->count())->toBe(1);
    });

    test('sin motivo no se quita', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();
        $registro = app(RegistroDeCompromisos::class);
        $registro->donar($lote, Cliente::factory()->create(), 'Iglesia del pueblo');

        expect(fn (): Compromiso => $registro->deshacerDonacion($lote, '   '))
            ->toThrow(CompromisoInvalidoException::class);

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado);
    });

    test('un lote que no está donado no se puede corregir', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)->create();

        expect(fn (): Compromiso => app(RegistroDeCompromisos::class)->deshacerDonacion($lote, 'Motivo cualquiera.'))
            ->toThrow(CompromisoInvalidoException::class);
    });

    /*
    | El caso completo, tal como lo contó: cinco marcados, dos corregidos,
    | tres donados de verdad y el cupo bajado a tres.
    */
    test('cinco marcados, dos corregidos: quedan tres donados y dos a la venta', function (): void {
        $this->proyecto->update(['lotes_a_donar' => 5]);
        $registro = app(RegistroDeCompromisos::class);
        $cliente = Cliente::factory()->create();

        $lotes = Lote::factory()->enBloque($this->bloque)->count(5)->create();

        foreach ($lotes as $lote) {
            $registro->donar($lote, $cliente, 'Entrega a la comunidad.');
        }

        expect($this->proyecto->refresh()->lotesDonados())->toBe(5);

        foreach ([$lotes[3], $lotes[4]] as $lote) {
            $registro->deshacerDonacion($lote, 'Solo se donarían tres.');
        }

        $this->proyecto->update(['lotes_a_donar' => 3]);

        expect($this->proyecto->refresh()->lotesDonados())->toBe(3)
            ->and($this->proyecto->donacionesQueQuedan())->toBe(0)
            ->and($this->proyecto->puedeDonarOtroLote())->toBeFalse()
            ->and(Lote::query()->where('proyecto_id', $this->proyecto->getKey())
                ->where('estado', EstadoLote::Disponible->value)->count())->toBe(2);
    });
});
