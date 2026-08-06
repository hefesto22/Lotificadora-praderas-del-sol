<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\CompromisoInvalidoException;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| La seña del apartado sale con recibo — R14 + R12 + R11
|--------------------------------------------------------------------------
| El hueco que cierra este archivo: hasta el 6-ago-2026 un cliente entregaba
| L 5,000.00 para apartar un lote y se iba sin papel. El monto quedaba en la
| columna `monto_senia` del compromiso y nada mas — ni numero, ni forma de
| pago, ni nada que el cliente pudiera mostrar el dia que viniera a firmar y
| dijera «yo ya les di cinco mil».
|
| Ahora apartar emite un recibo de la MISMA serie que los pagos de cuotas
| (R12, una sola numeracion para toda la lotificadora), colgado del
| compromiso y no de una venta, porque al apartar todavia no hay expediente.
*/

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
    $this->lote = fn (string $numero): Lote => Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1200.00')
        ->create(['numero' => $numero]);
    $this->cliente = Cliente::factory()->create(['nombre' => 'Rosa Elena Fuentes']);
    $this->otro = Cliente::factory()->create(['nombre' => 'Carlos Medina']);
    $this->registro = app(RegistroDeCompromisos::class);
    $this->numeros = fn (): array => Recibo::query()
        ->orderBy('numero')
        ->pluck('numero')
        ->map(static fn (mixed $numero): int => (int) $numero)
        ->all();
});

describe('El recibo de la seña', function (): void {
    test('apartar con seña deja el papel, colgado del compromiso', function (): void {
        $compromiso = $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        $recibo = Recibo::query()->firstOrFail();

        expect(Recibo::query()->count())->toBe(1)
            ->and($recibo->getAttribute('concepto'))->toBe(ConceptoDeRecibo::Senia)
            ->and($recibo->getAttribute('forma_pago'))->toBe(FormaDePago::Efectivo)
            ->and($recibo->montoTotal())->toBeMonto('5000.00')
            ->and($recibo->getAttribute('cliente_id'))->toBe($this->cliente->getKey())
            ->and($recibo->getAttribute('compromiso_id'))->toBe($compromiso->getKey())
            // No hay expediente todavia: la venta se firma despues, o no se
            // firma nunca. R13 se conforma con el compromiso.
            ->and($recibo->getAttribute('venta_id'))->toBeNull()
            ->and($recibo->getAttribute('referencia'))->toBeNull();
    });

    /*
    | La relacion existe para que el expediente y el estado de cuenta puedan
    | leer la seña sin tener que saber que nacio en un apartado.
    */
    test('el compromiso encuentra su recibo', function (): void {
        $compromiso = $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        expect($compromiso->recibos()->count())->toBe(1)
            ->and($compromiso->recibos()->firstOrFail()->montoTotal())->toBeMonto('5000.00');
    });

    /*
    | Son el mismo hecho del mismo dia. Dos llamadas a today() a los dos
    | lados de la medianoche dejarian el recibo fechado un dia despues del
    | apartado que documenta.
    */
    test('el recibo lleva la fecha del compromiso', function (): void {
        $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        expect(DB::table('recibos')->value('fecha'))
            ->toBe(DB::table('compromisos')->value('fecha'));
    });

    /*
    | R12: una sola serie para toda la lotificadora, y es la MISMA que usan
    | los pagos de cuotas. El recibo de una seña y el de una cuota no pueden
    | compartir numero.
    */
    test('cada seña saca su numero de la serie unica', function (): void {
        $this->registro->apartar(($this->lote)('1'), $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);
        $this->registro->apartar(($this->lote)('2'), $this->otro, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        expect(($this->numeros)())->toBe([1, 2]);
    });
});

describe('Cuando NO hay papel', function (): void {
    /*
    | Apartar sin adelanto es legitimo: pasa cuando el cliente vuelve al dia
    | siguiente con la plata. Lo que no puede pasar es un recibo por nada.
    */
    test('apartar sin seña no emite recibo', function (): void {
        $this->registro->apartar(($this->lote)('1'), $this->cliente);

        expect(Compromiso::query()->count())->toBe(1)
            ->and(Recibo::query()->count())->toBe(0);
    });

    /*
    | El CHECK `recibos_monto_positivo_chk` no admite L 0.00, y un papel por
    | cero no le sirve a nadie. Sin este caso, cargar un apartado con la seña
    | en cero reventaria con un SQLSTATE en la cara del vendedor.
    */
    test('una seña de cero tampoco', function (): void {
        $this->registro->apartar(($this->lote)('1'), $this->cliente, montoSenia: '0.00');

        expect(Compromiso::query()->count())->toBe(1)
            ->and(Recibo::query()->count())->toBe(0);
    });
});

describe('R11: como entro la plata', function (): void {
    /*
    | No se asume efectivo. Un apartado pagado por transferencia y grabado
    | como efectivo es un recibo que nunca va a cruzar contra el banco.
    */
    test('seña sin forma de pago no se graba, y no aparta nada', function (): void {
        $lote = ($this->lote)('1');

        expect(fn () => $this->registro->apartar($lote, $this->cliente, montoSenia: '5000.00'))
            ->toThrow(CompromisoInvalidoException::class, 'no dice como entro');

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and(Compromiso::query()->count())->toBe(0)
            ->and(Recibo::query()->count())->toBe(0);
    });

    test('transferencia sin referencia tampoco', function (): void {
        $lote = ($this->lote)('1');

        expect(fn () => $this->registro->apartar(
            $lote,
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Transferencia,
        ))->toThrow(CompromisoInvalidoException::class, 'falta el numero de referencia');

        expect($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    /*
    | Un espacio en blanco no es una referencia: el CHECK de la base mira
    | `btrim(referencia) <> ''`, y si el dominio lo dejara pasar el error
    | llegaria como SQLSTATE y no como frase.
    */
    test('un espacio en blanco no cuenta como referencia', function (): void {
        expect(fn () => $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Deposito,
            referencia: '   ',
        ))->toThrow(CompromisoInvalidoException::class, 'falta el numero de referencia');
    });

    test('con referencia, el deposito queda cruzable contra el banco', function (): void {
        $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Deposito,
            referencia: '  0102-998877  ',
        );

        $recibo = Recibo::query()->firstOrFail();

        expect($recibo->getAttribute('forma_pago'))->toBe(FormaDePago::Deposito)
            // Recortada: el CHECK mira el btrim y el papel no lleva espacios.
            ->and($recibo->getAttribute('referencia'))->toBe('0102-998877');
    });

    test('en efectivo la referencia no hace falta', function (): void {
        $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        expect(Recibo::query()->count())->toBe(1);
    });
});

describe('Apartar varios', function (): void {
    /*
    | Tres lotes son tres compromisos de L 5,000.00 —la seña es POR LOTE— y
    | por lo tanto TRES recibos. El dia que uno de los tres se libere hay que
    | devolver una seña entera, no un tercio de un papel.
    |
    | La referencia, en cambio, es la misma en los tres: una sola
    | transferencia que cubre las tres señas es el caso normal, y por eso
    | `recibos.referencia` no lleva indice unico.
    */
    test('tres lotes, tres recibos, una sola transferencia', function (): void {
        $this->registro->apartarVarios(
            [($this->lote)('1'), ($this->lote)('2'), ($this->lote)('3')],
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Transferencia,
            referencia: 'TRF-4471',
        );

        expect(Recibo::query()->count())->toBe(3)
            ->and(($this->numeros)())->toBe([1, 2, 3])
            ->and(Recibo::query()->distinct()->pluck('referencia')->all())->toBe(['TRF-4471'])
            ->and(Recibo::query()->distinct()->count('compromiso_id'))->toBe(3)
            ->and((string) Recibo::query()->sum('monto'))->toBe('15000.00');
    });

    /*
    | Todo o nada, y eso incluye la serie. Si al tercer lote se lo llevaron
    | mientras se armaba la pantalla, el numero que ya habian consumido los
    | dos primeros se va con la transaccion — si no, la serie quedaria con un
    | hueco que despues alguien tendria que explicar.
    */
    test('si el apartado se cae, el correlativo no deja hueco', function (): void {
        $uno = ($this->lote)('1');
        $dos = ($this->lote)('2');
        $tres = ($this->lote)('3');

        // El ultimo por codigo (RPS-A-003), que es el orden en que
        // apartarVarios relee: los dos primeros ya numeraron cuando este
        // revienta.
        $this->registro->apartar($tres, $this->otro);

        expect(fn () => $this->registro->apartarVarios(
            [$uno, $dos, $tres],
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        ))->toThrow(CompromisoInvalidoException::class);

        expect(Recibo::query()->count())->toBe(0)
            ->and($uno->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and($dos->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);

        // Y el siguiente apartado arranca en 1, no en 3.
        $this->registro->apartar($uno, $this->cliente, montoSenia: '5000.00', forma: FormaDePago::Efectivo);

        expect(($this->numeros)())->toBe([1]);
    });
});
