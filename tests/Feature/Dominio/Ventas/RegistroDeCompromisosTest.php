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
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
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
    | Los dieciseis lotes del bloque B estan reservados para los herederos y no
    | se venden. Son DOS tests y no uno porque los dos caminos rechazan por
    | motivos distintos:
    |
    |  · `apartar()` exige que el lote este DISPONIBLE, asi que rechaza
    |    cualquier estado nuevo sin que nadie lo toque.
    |  · `vender()` lista los estados que rechaza uno por uno, asi que un
    |    estado nuevo pasa DERECHO si nadie lo agrega. Cuando se creo
    |    `reservado` el 12-ago-2026 eso fue exactamente lo que pasó: la reserva
    |    no habria servido de nada y los lotes se habrian podido vender igual.
    |
    | Por eso el de vender importa mas: es el que puede volver a romperse solo.
    */
    test('un lote reservado no se vende', function (): void {
        $this->lote->forceFill(['estado' => EstadoLote::Reservado])->save();

        expect(fn () => $this->registro->vender($this->lote->refresh(), $this->cliente))
            ->toThrow(CompromisoInvalidoException::class);
    });

    test('un lote reservado no se aparta', function (): void {
        $this->lote->forceFill(['estado' => EstadoLote::Reservado])->save();

        expect(fn () => $this->registro->apartar($this->lote->refresh(), $this->cliente))
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

describe('Donar', function (): void {
    /*
    | Una donacion es un lote que sale del inventario sin que entre un lempira.
    | Este test mira las dos mitades: que el lote se movio Y que NO se armo
    | nada de la maquinaria de cobro. La segunda es la que importa — un plan de
    | 48 cuotas de L 0.00 colgado de un contrato que nadie firmo es
    | exactamente el resultado de mandar esto por `RegistroDeVentas`.
    */
    test('el lote sale del inventario y no queda cartera detras', function (): void {
        $donacion = $this->registro->donar(
            $this->lote,
            $this->cliente,
            'Donado a la Iglesia Congregacional, acta del 12-ago-2026.',
        );

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado)
            ->and($donacion->getAttribute('tipo'))->toBe(TipoCompromiso::Donacion)
            ->and($donacion->getAttribute('estado'))->toBe(EstadoCompromiso::Vigente)
            ->and($donacion->getAttribute('cliente_id'))->toBe($this->cliente->getKey())
            ->and($donacion->getAttribute('motivo'))->toContain('Iglesia Congregacional')
            // Nada de dinero: ni expediente, ni prima, ni plazo, ni seña.
            ->and($donacion->getAttribute('venta_id'))->toBeNull()
            ->and($donacion->getAttribute('prima'))->toBeNull()
            ->and($donacion->getAttribute('plazo_meses'))->toBeNull()
            ->and($donacion->getAttribute('monto_senia'))->toBeNull()
            ->and($donacion->getAttribute('vence_el'))->toBeNull()
            // Y ningun papel: la serie de recibos (R12) no se toca.
            ->and(Cuota::query()->count())->toBe(0)
            ->and(Recibo::query()->count())->toBe(0);
    });

    /*
    | El valor se congela igual que en una venta y NO en cero: es lo que hace
    | falta para la escritura y para contestar «cuanto valia lo que se regalo».
    */
    test('congela cuanto valia lo que se regalo', function (): void {
        $donacion = $this->registro->donar($this->lote, $this->cliente, 'Area verde del proyecto.');

        expect($donacion->getAttribute('valor'))->toBe('300000.00')
            ->and($donacion->getAttribute('precio_vara'))->toBe('1200.000000')
            // Sin descuento: se dona al precio de lista, y por eso el CHECK
            // `compromisos_descuento_con_motivo_chk` no pide nada.
            ->and($donacion->getAttribute('precio_vara_lista'))->toBe('1200.000000');
    });

    /*
    | El camino normal, y la razon de que `reservado` este en la lista blanca:
    | los dieciseis lotes del bloque B estan guardados para los herederos, y
    | una iglesia se apalabra mucho antes de que haya escritura. Se reserva
    | mientras el tramite corre y se dona cuando se firma.
    */
    test('un lote reservado si se dona: es el camino de los herederos', function (): void {
        $this->lote->forceFill(['estado' => EstadoLote::Reservado])->save();

        $this->registro->donar($this->lote->refresh(), $this->cliente, 'Adjudicacion a los herederos.');

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Donado);
    });

    /*
    | Un apartado tiene una seña de por medio que hay que devolverle a alguien.
    | `liberar()` sabe hacer eso y `donar()` no, asi que mandar a liberar
    | primero es el tramite correcto y no un rodeo.
    */
    test('un lote apartado no se dona: primero hay que liberarlo', function (): void {
        $this->registro->apartar($this->lote, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        expect(fn () => $this->registro->donar($this->lote->refresh(), $this->cliente, 'Cambio de idea.'))
            ->toThrow(CompromisoInvalidoException::class);
    });

    test('un lote vendido no se dona', function (): void {
        $this->registro->vender($this->lote, $this->cliente);

        expect(fn () => $this->registro->donar($this->lote->refresh(), $this->otro, 'Regalo.'))
            ->toThrow(CompromisoInvalidoException::class);
    });

    /*
    | La otra mitad del guard: un lote ya donado tampoco vuelve al mercado.
    | Son tres tests porque son tres puertas distintas, y `vender()` es la que
    | ya se rompio sola una vez —cuando se creo `reservado` el 12-ago-2026
    | paso derecho— asi que es la que hay que vigilar.
    */
    test('un lote donado no se vende, no se aparta y no se dona de nuevo', function (): void {
        $this->registro->donar($this->lote, $this->cliente, 'Escuela del sector.');
        $lote = $this->lote->refresh();

        expect(fn () => $this->registro->vender($lote, $this->otro))
            ->toThrow(CompromisoInvalidoException::class);

        expect(fn () => $this->registro->apartar($lote, $this->otro))
            ->toThrow(CompromisoInvalidoException::class);

        expect(fn () => $this->registro->donar($lote, $this->otro, 'Otra vez.'))
            ->toThrow(CompromisoInvalidoException::class);
    });

    /*
    | De los tres compromisos, este es el que mas necesita el motivo escrito:
    | un apartado se explica solo y una venta deja recibos, pero una donacion
    | es un lote que se fue sin dejar rastro de plata. Dentro de un año alguien
    | va a preguntar por que, y la respuesta tiene que estar escrita del dia.
    */
    test('sin motivo escrito no se dona', function (string $motivo): void {
        expect(fn () => $this->registro->donar($this->lote, $this->cliente, $motivo))
            ->toThrow(CompromisoInvalidoException::class);

        expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and(Compromiso::query()->count())->toBe(0);
    })->with([[''], ['   '], ["\n\t "]]);

    /*
    | La lista de estados admitidos es BLANCA, y este test es el que lo
    | garantiza: el dia que se agregue un estado nuevo a EstadoLote, la
    | donacion va a rechazarlo sola. Al reves —listando los que se rechazan—
    | pasaria derecho, que es como se rompio `vender()` con `reservado`.
    */
    test('solo se dona lo que esta disponible o reservado', function (): void {
        $admitidos = [EstadoLote::Disponible, EstadoLote::Reservado];

        foreach (EstadoLote::cases() as $estado) {
            if (in_array($estado, $admitidos, true)) {
                continue;
            }

            $lote = Lote::factory()->enBloque($this->bloque)->create(['numero' => '90'.$estado->value]);
            $lote->forceFill(['estado' => $estado])->save();

            expect(fn () => $this->registro->donar($lote->refresh(), $this->cliente, 'Motivo cualquiera.'))
                ->toThrow(CompromisoInvalidoException::class);
        }
    });
});

describe('El tipo de compromiso manda el estado del lote', function (): void {
    /*
    | La correspondencia tipo -> estado se declara UNA vez, en
    | `TipoCompromiso::estadoDelLote()`, y `RegistroDeCompromisos::crear()` es
    | quien la aplica. Hasta el 12-ago-2026 cada metodo escribia el estado a
    | mano y ese match existia al lado sin que nadie lo llamara: dos fuentes
    | para la misma verdad.
    |
    | Este test recorre los TRES caminos de verdad —no compara el enum contra
    | si mismo— asi que se pone rojo si alguno vuelve a escribir el suyo.
    */
    test('apartar, vender y donar dejan el lote donde dice el tipo', function (): void {
        $caminos = [
            TipoCompromiso::Apartado->value => fn (Lote $lote) => $this->registro->apartar($lote, $this->cliente),
            TipoCompromiso::Venta->value    => fn (Lote $lote) => $this->registro->vender($lote, $this->cliente),
            TipoCompromiso::Donacion->value => fn (Lote $lote) => $this->registro->donar($lote, $this->cliente, 'Motivo.'),
        ];

        // Si algun dia se agrega un tipo y no un camino, esto lo dice.
        expect(array_keys($caminos))->toBe(TipoCompromiso::valores());

        foreach ($caminos as $valor => $hacerlo) {
            $tipo = TipoCompromiso::from($valor);
            $lote = Lote::factory()->enBloque($this->bloque)->create(['numero' => '80'.$valor]);

            $compromiso = $hacerlo($lote);

            expect($compromiso->getAttribute('tipo'))->toBe($tipo)
                ->and($lote->refresh()->getAttribute('estado'))->toBe($tipo->estadoDelLote());
        }
    });
});
