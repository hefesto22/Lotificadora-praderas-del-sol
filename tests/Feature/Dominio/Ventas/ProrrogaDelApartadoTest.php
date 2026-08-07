<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\CompromisoInvalidoException;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;

/*
|--------------------------------------------------------------------------
| La prórroga y la seña que se devuelve — R14 completo
|--------------------------------------------------------------------------
| Las dos mitades que faltaban del apartado.
|
| La PRORROGA: la contratante autorizó una sola, de quince días. Sin un
| contador, la segunda es indistinguible de la primera y cualquiera estira un
| apartado para siempre de a quince días — que es exactamente lo que el plazo
| venía a evitar.
|
| La DEVOLUCION: R14 promete que si el apartado se cae, la plata vuelve.
| Todavía no hay módulo de egresos, así que lo que se construyó es lo mínimo
| honesto: que el sistema sepa distinguir «hay L 5,000.00 que devolverle a
| alguien» de «ya se devolvieron», y que la lista se pueda vaciar.
*/

beforeEach(function (): void {
    $this->registro = app(RegistroDeCompromisos::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
    $this->lote = fn (string $numero): Lote => Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1200.00')
        ->create(['numero' => $numero]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Rosa Elena Fuentes']);

    $this->dias = (int) config('lotificadora.apartados.dias_de_prorroga', 15);
    $this->maximas = Compromiso::prorrogasMaximas();

    /*
     * ═══ POR QUE HAY QUE VIAJAR EN EL TIEMPO ═══
     *
     * Un apartado vencido no se puede FABRICAR con fecha de hoy y
     * vencimiento de ayer: el CHECK `compromisos_vencimiento_coherente_chk`
     * exige `vence_el >= fecha`, y hace bien — un apartado que vence antes
     * de existir es un dato imposible.
     *
     * Asi que se aparta el dia que se aparto y se vuelve. Ademas es la unica
     * forma en que un apartado vencido llega a existir de verdad.
     */
    $this->vencidoHace = function (Lote $lote, int $dias): Compromiso {
        $this->travelTo(today()->subDays($dias + 15));

        $apartado = $this->registro->apartar(
            $lote,
            $this->cliente,
            venceEl: today()->addDays(15)->toDateString(),
        );

        $this->travelBack();

        return $apartado->refresh();
    };
});

describe('La prórroga', function (): void {
    test('suma los dias de R14 y queda contada', function (): void {
        $vence = today()->addDays(5);

        $apartado = $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            venceEl: $vence->toDateString(),
        );

        $this->registro->prorrogar($apartado, 'El cliente pidio unos dias mas.');

        expect($apartado->refresh()->getAttribute('vence_el')?->toDateString())
            ->toBe($vence->copy()->addDays($this->dias)->toDateString())
            ->and($apartado->prorrogasUsadas())->toBe(1);
    });

    /*
    | El corazon de la regla. Si esto no cortara, un lote se puede sacar del
    | mercado indefinidamente sin que nadie haya decidido nada.
    */
    test('la segunda prorroga no se da', function (): void {
        $apartado = $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            venceEl: today()->addDays(5)->toDateString(),
        );

        for ($i = 0; $i < $this->maximas; $i++) {
            $this->registro->prorrogar($apartado, 'La primera, autorizada.');
        }

        expect(fn () => $this->registro->prorrogar($apartado->refresh(), 'Y una mas.'))
            ->toThrow(CompromisoInvalidoException::class, 'R14 autoriza');

        expect($apartado->refresh()->prorrogasUsadas())->toBe($this->maximas)
            ->and($apartado->puedeProrrogarse())->toBeFalse();
    });

    /*
    | Si ya vencio, los quince dias corren desde HOY y no desde la fecha
    | vieja. Prorrogar «desde su vencimiento» un apartado caido hace diez
    | dias le dejaria cinco al cliente, y quien autorizo creyo estar dando
    | quince.
    */
    test('a un apartado ya vencido, los dias le corren desde hoy', function (): void {
        $apartado = ($this->vencidoHace)(($this->lote)('1'), 10);

        expect($apartado->estaVencido())->toBeTrue();

        $this->registro->prorrogar($apartado, 'Aparecio con la plata.');

        expect($apartado->refresh()->getAttribute('vence_el')?->toDateString())
            ->toBe(today()->addDays($this->dias)->toDateString());
    });

    /*
    | El motivo va a `observaciones` y no a `motivo`: ese ultimo lo escribe
    | liberar() para decir por que se solto el lote. Si se pisaran, la
    | liberacion borraria el rastro de la prorroga.
    */
    test('el motivo queda anotado sin pisar lo que ya habia', function (): void {
        $apartado = $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            venceEl: today()->addDays(5)->toDateString(),
            observaciones: 'Lo trajo el ingeniero Medina.',
        );

        $this->registro->prorrogar($apartado, 'Le falta juntar la prima.');

        $observaciones = $apartado->refresh()->getAttribute('observaciones');

        expect($observaciones)->toContain('Lo trajo el ingeniero Medina.')
            ->and($observaciones)->toContain('Le falta juntar la prima.')
            ->and($apartado->getAttribute('motivo'))->toBeNull();
    });

    test('sin motivo, aunque sean espacios, no se prorroga', function (): void {
        $apartado = $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            venceEl: today()->addDays(5)->toDateString(),
        );

        expect(fn () => $this->registro->prorrogar($apartado, '   '))
            ->toThrow(CompromisoInvalidoException::class, 'hay que escribir por que');

        expect($apartado->refresh()->prorrogasUsadas())->toBe(0);
    });
});

describe('Lo que no se prorroga', function (): void {
    test('una venta no vence', function (): void {
        $compromiso = $this->registro->vender(($this->lote)('1'), $this->cliente);

        expect(fn () => $this->registro->prorrogar($compromiso, 'Por probar.'))
            ->toThrow(CompromisoInvalidoException::class, 'no hay nada que prorrogar');
    });

    test('un apartado ya liberado no se reabre estirandolo', function (): void {
        $lote = ($this->lote)('1');

        $apartado = $this->registro->apartar($lote, $this->cliente, venceEl: today()->addDays(5)->toDateString());
        $this->registro->liberar($lote->refresh(), 'Se vencio el plazo.');

        expect(fn () => $this->registro->prorrogar($apartado->refresh(), 'Volvio el cliente.'))
            ->toThrow(CompromisoInvalidoException::class, 'ya no ocupa el lote');
    });

    /*
    | Los apartados cargados antes de que el sistema llevara este registro
    | (R15) vienen sin fecha. No hay plazo que correr.
    */
    test('un apartado sin fecha de vencimiento', function (): void {
        $apartado = $this->registro->apartar(($this->lote)('1'), $this->cliente);

        expect(fn () => $this->registro->prorrogar($apartado, 'Por probar.'))
            ->toThrow(CompromisoInvalidoException::class, 'no tiene fecha de vencimiento');
    });
});

describe('La seña que hay que devolver', function (): void {
    test('un apartado liberado con seña queda pendiente', function (): void {
        $lote = ($this->lote)('1');

        $apartado = $this->registro->apartar(
            $lote,
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        $this->registro->liberar($lote->refresh(), 'Se vencio el plazo.');

        expect($apartado->refresh()->seniaPorDevolver())->toBeMonto('5000.00')
            ->and(Compromiso::query()->conSeniaPorDevolver()->count())->toBe(1)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    test('marcarla devuelta la saca de la lista', function (): void {
        $lote = ($this->lote)('1');

        $apartado = $this->registro->apartar(
            $lote,
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        $this->registro->liberar($lote->refresh(), 'Se vencio el plazo.');
        $this->registro->devolverLaSenia($apartado->refresh(), 'En efectivo en la oficina.');

        expect($apartado->refresh()->getAttribute('senia_devuelta_el')?->toDateString())
            ->toBe(today()->toDateString())
            ->and($apartado->seniaPorDevolver())->toBeNull()
            ->and(Compromiso::query()->conSeniaPorDevolver()->count())->toBe(0)
            ->and($apartado->getAttribute('observaciones'))->toContain('En efectivo en la oficina.');
    });

    test('no se devuelve dos veces', function (): void {
        $lote = ($this->lote)('1');

        $apartado = $this->registro->apartar(
            $lote,
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        $this->registro->liberar($lote->refresh(), 'Se vencio.');
        $this->registro->devolverLaSenia($apartado->refresh());

        expect(fn () => $this->registro->devolverLaSenia($apartado->refresh()))
            ->toThrow(CompromisoInvalidoException::class, 'no tiene una seña pendiente');
    });

    /*
    | Mientras el apartado sigue vigente, la seña es de la lotificadora: el
    | lote esta reservado y el trato sigue en pie.
    */
    test('un apartado vigente no tiene nada que devolver', function (): void {
        $apartado = $this->registro->apartar(
            ($this->lote)('1'),
            $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        expect($apartado->seniaPorDevolver())->toBeNull()
            ->and(fn () => $this->registro->devolverLaSenia($apartado))
            ->toThrow(CompromisoInvalidoException::class);
    });

    test('un apartado sin seña no deja nada pendiente al liberarse', function (): void {
        $lote = ($this->lote)('1');

        $apartado = $this->registro->apartar($lote, $this->cliente);
        $this->registro->liberar($lote->refresh(), 'Se vencio.');

        expect($apartado->refresh()->getAttribute('estado'))->toBe(EstadoCompromiso::Liberado)
            ->and($apartado->seniaPorDevolver())->toBeNull()
            ->and(Compromiso::query()->conSeniaPorDevolver()->count())->toBe(0);
    });
});

describe('Los que la pantalla tiene que encontrar', function (): void {
    /*
    | Es la consulta que justifica la pantalla entera: sin ella, un apartado
    | vencido deja el lote reservado hasta que alguien se acuerde de mirar.
    */
    test('vencidos trae solo los que se pasaron de fecha y siguen ocupando', function (): void {
        $vencido = ($this->lote)('1');
        $vigente = ($this->lote)('2');
        $liberado = ($this->lote)('3');

        ($this->vencidoHace)($vencido, 1);
        $this->registro->apartar($vigente, $this->cliente, venceEl: today()->addDays(10)->toDateString());
        ($this->vencidoHace)($liberado, 30);
        $this->registro->liberar($liberado->refresh(), 'Se cayo hace rato.');

        $encontrados = Compromiso::query()->vencidos()->pluck('lote_id')->all();

        expect($encontrados)->toBe([$vencido->getKey()]);
    });

    test('por vencer trae los de los proximos dias, no los de hoy para atras', function (): void {
        $manana = ($this->lote)('1');
        $lejano = ($this->lote)('2');
        $ayer = ($this->lote)('3');

        $this->registro->apartar($manana, $this->cliente, venceEl: today()->addDay()->toDateString());
        $this->registro->apartar($lejano, $this->cliente, venceEl: today()->addDays(10)->toDateString());
        ($this->vencidoHace)($ayer, 1);

        $encontrados = Compromiso::query()->porVencer(3)->pluck('lote_id')->all();

        expect($encontrados)->toBe([$manana->getKey()]);
    });

    test('el que vence hoy todavia cuenta como por vencer, no como vencido', function (): void {
        $hoy = ($this->lote)('1');

        $apartado = $this->registro->apartar($hoy, $this->cliente, venceEl: today()->toDateString());

        expect($apartado->estaVencido())->toBeFalse()
            ->and($apartado->diasParaVencer())->toBe(0)
            ->and(Compromiso::query()->vencidos()->count())->toBe(0)
            ->and(Compromiso::query()->porVencer(3)->count())->toBe(1);
    });
});
