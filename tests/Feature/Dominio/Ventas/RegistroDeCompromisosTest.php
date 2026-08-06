<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Exceptions\CompromisoInvalidoException;
use App\Domain\Exceptions\LoteInmutableException;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
    $this->lote = Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1200.00')
        ->create(['numero' => '1']);
    $this->cliente = Cliente::factory()->create(['nombre' => 'Rosa Elena Fuentes']);
    $this->otro = Cliente::factory()->create(['nombre' => 'Carlos Medina']);
    $this->registro = app(RegistroDeCompromisos::class);
});

describe('Apartar', function (): void {
    test('deja el registro y mueve el lote, en un solo movimiento', function (): void {
        $compromiso = $this->registro->apartar($this->lote, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado)
            ->and($compromiso->getAttribute('tipo'))->toBe(TipoCompromiso::Apartado)
            ->and($compromiso->getAttribute('estado'))->toBe(EstadoCompromiso::Vigente)
            ->and($compromiso->getAttribute('cliente_id'))->toBe($this->cliente->getKey())
            ->and($compromiso->getAttribute('monto_senia'))->toBe('5000.00');
    });

    /*
    | El §8.2: el valor que vale es el congelado. Si mañana sube el precio
    | por vara del proyecto, el compromiso ya firmado conserva el suyo.
    */
    test('congela area, precio y valor al momento de apartar', function (): void {
        $compromiso = $this->registro->apartar($this->lote, $this->cliente);

        expect($compromiso->getAttribute('valor'))->toBe('300000.00');

        // El lote todavia se puede repreciar: no esta vendido.
        $this->lote->update(['precio_vara' => '2000.00']);

        expect($this->lote->refresh()->getAttribute('valor'))->toBe('500000.00')
            ->and($compromiso->refresh()->getAttribute('valor'))->toBe('300000.00');
    });

    test('un lote ya apartado no se aparta de nuevo', function (): void {
        $this->registro->apartar($this->lote, $this->cliente);

        expect(fn () => $this->registro->apartar($this->lote->refresh(), $this->otro))
            ->toThrow(CompromisoInvalidoException::class);
    });

    test('un lote vendido no se aparta', function (): void {
        $this->registro->vender($this->lote, $this->cliente);

        expect(fn () => $this->registro->apartar($this->lote->refresh(), $this->otro))
            ->toThrow(CompromisoInvalidoException::class);
    });

    /*
    | La regla mas importante de todas, y vive en la base: dos personas
    | apartando el mismo lote al mismo tiempo terminan con una violacion de
    | unicidad, no con dos clientes creyendo que el lote es suyo.
    */
    test('la base impide dos compromisos vigentes sobre el mismo lote', function (): void {
        $this->registro->apartar($this->lote, $this->cliente);

        expect(fn () => DB::table('compromisos')->insert([
            'proyecto_id' => $this->proyecto->getKey(),
            'lote_id'     => $this->lote->getKey(),
            'cliente_id'  => $this->otro->getKey(),
            'tipo'        => 'apartado',
            'estado'      => 'vigente',
            'area_varas'  => '250.0000',
            'precio_vara' => '1200.00',
            'valor'       => '300000.00',
            'fecha'       => today()->toDateString(),
        ]))->toThrow(QueryException::class);
    });

    test('los compromisos cerrados no estorban a uno nuevo', function (): void {
        $this->registro->apartar($this->lote, $this->cliente);
        $this->registro->liberar($this->lote->refresh(), 'No se concreto');
        $this->registro->apartar($this->lote->refresh(), $this->otro);

        expect(Compromiso::query()->count())->toBe(2)
            ->and(Compromiso::query()->vigentes()->count())->toBe(1);
    });
});

describe('Liberar', function (): void {
    test('devuelve el lote a disponible y cierra el compromiso', function (): void {
        $this->registro->apartar($this->lote, $this->cliente);
        $liberado = $this->registro->liberar($this->lote->refresh(), 'Se vencio el plazo');

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and($liberado->getAttribute('estado'))->toBe(EstadoCompromiso::Liberado)
            ->and($liberado->getAttribute('motivo'))->toBe('Se vencio el plazo')
            ->and($liberado->getAttribute('cerrado_el'))->not->toBeNull();
    });

    /*
    | Los lotes que ya estaban apartados cuando se cargo el sistema no
    | tienen compromiso detras. El mensaje tiene que explicarlo, no tirar
    | un error criptico a quien esta atendiendo a un cliente.
    */
    test('un lote apartado sin compromiso registrado lo explica', function (): void {
        $this->lote->update(['estado' => EstadoLote::Apartado]);

        expect(fn () => $this->registro->liberar($this->lote->refresh(), 'Prueba'))
            ->toThrow(CompromisoInvalidoException::class);
    });

    test('una venta no se libera, se rescinde', function (): void {
        $this->registro->vender($this->lote, $this->cliente);

        expect(fn () => $this->registro->liberar($this->lote->refresh(), 'Prueba'))
            ->toThrow(CompromisoInvalidoException::class);
    });
});

describe('Vender', function (): void {
    test('se puede vender un lote disponible directo', function (): void {
        $venta = $this->registro->vender($this->lote, $this->cliente);

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido)
            ->and($venta->getAttribute('tipo'))->toBe(TipoCompromiso::Venta)
            ->and($venta->getAttribute('valor'))->toBe('300000.00');
    });

    test('vender un lote apartado convierte el apartado', function (): void {
        $apartado = $this->registro->apartar($this->lote, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);
        $venta = $this->registro->vender($this->lote->refresh(), $this->cliente);

        expect($apartado->refresh()->getAttribute('estado'))->toBe(EstadoCompromiso::Convertido)
            ->and($apartado->getAttribute('cerrado_el'))->not->toBeNull()
            ->and($venta->getAttribute('estado'))->toBe(EstadoCompromiso::Vigente)
            ->and(Compromiso::query()->vigentes()->count())->toBe(1)
            ->and($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });

    test('no se le vende por encima del apartado de otro', function (): void {
        $this->registro->apartar($this->lote, $this->cliente);

        expect(fn () => $this->registro->vender($this->lote->refresh(), $this->otro))
            ->toThrow(CompromisoInvalidoException::class);

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Apartado);
    });

    test('un lote ya vendido no se vuelve a vender', function (): void {
        $this->registro->vender($this->lote, $this->cliente);

        expect(fn () => $this->registro->vender($this->lote->refresh(), $this->cliente))
            ->toThrow(CompromisoInvalidoException::class);
    });

    /*
    | Despues de vender, el trigger del §8.2 congela el lote. La venta
    | guarda su propia copia, asi que el estado de cuenta del cliente no
    | depende de que nadie toque el lote.
    */
    test('el lote vendido queda congelado y la venta conserva su copia', function (): void {
        $venta = $this->registro->vender($this->lote, $this->cliente);

        expect(fn () => $this->lote->refresh()->update(['precio_vara' => '9999.00']))
            ->toThrow(LoteInmutableException::class);

        expect($venta->refresh()->getAttribute('valor'))->toBe('300000.00');
    });
});
