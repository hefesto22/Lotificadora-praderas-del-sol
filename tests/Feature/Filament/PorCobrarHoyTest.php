<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\ResultadoDeGestion;
use App\Filament\Pages\PorCobrarHoy;
use App\Models\Cuota;
use App\Models\GestionDeCobro;
use App\Models\User;
use App\Models\Venta;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| La lista de llamadas del día — 23-ago-2026
|--------------------------------------------------------------------------
| Mauricio: «que ahí se vean las personas que llevan cuota atrasada o les
| toca pago ese día, así evitamos las notificaciones… le llaman de que le
| toca cuota y marcan que ya se contactaron con él».
|
| Lo que estos tests cuidan no es que la tabla dibuje: es QUIÉN aparece y
| QUIÉN NO. Una pantalla de cobranza que esconde a un deudor es peor que no
| tenerla, y una que muestra a quien ya se llamó hace que se deje de mirar.
|
| ⚠️ Casi todo se prueba contra `PorCobrarHoy::porCobrar()` y no contra la
| pantalla: es una consulta pública, y una consulta se puede afirmar. Lo que
| pasa por Livewire es solo lo que Livewire tiene que hacer —montar y
| ejecutar la acción—.
*/

/**
 * Un expediente vigente que debe algo hoy o desde antes.
 */
function expedientePorCobrar(int $vencidas = 1, bool $venceHoy = false): Venta
{
    static $numero = 0;
    $numero++;

    $venta = Venta::factory()->vigente($numero)->create(['estado' => EstadoVenta::Vigente]);

    for ($i = 1; $i <= $vencidas; $i++) {
        Cuota::factory()->deLaVenta($venta)->vencida(30 * $i)->create(['numero' => $i]);
    }

    if ($venceHoy) {
        Cuota::factory()->deLaVenta($venta)->create([
            'numero'            => $vencidas + 1,
            'fecha_vencimiento' => today(),
        ]);
    }

    return $venta;
}

/**
 * Los expedientes que la pantalla va a mostrar, ordenados para poder
 * afirmarlos: sin un ORDER BY explícito el arreglo depende del plan de
 * Postgres y el test pasa o no según el día.
 *
 * @return list<int>
 */
function laListaDeHoy(): array
{
    /** @var list<mixed> $ids */
    $ids = PorCobrarHoy::porCobrar()->reorder()->orderBy('id')->pluck('id')->all();

    return array_map(static fn (mixed $id): int => (int) $id, $ids);
}

describe('Quién entra a la lista', function (): void {
    test('el que tiene una cuota vencida', function (): void {
        $venta = expedientePorCobrar(vencidas: 2);

        expect(laListaDeHoy())->toBe([(int) $venta->getKey()]);
    });

    /*
    | «o les toca pago ese día»: el que vence HOY todavía no está atrasado y
    | es justamente al que conviene llamar — antes de que se atrase.
    */
    test('el que le toca pagar hoy, aunque no deba nada de antes', function (): void {
        $venta = expedientePorCobrar(vencidas: 0, venceHoy: true);

        expect(laListaDeHoy())->toBe([(int) $venta->getKey()]);
    });

    test('el que solo tiene cuotas futuras no aparece', function (): void {
        $venta = expedientePorCobrar(vencidas: 0);
        Cuota::factory()->deLaVenta($venta)->create([
            'numero'            => 1,
            'fecha_vencimiento' => today()->addMonth(),
        ]);

        expect(laListaDeHoy())->toBe([]);
    });

    /*
    | Una venta liquidada no debe nada aunque le queden cuotas de un lote
    | rescindido colgando. Es el mismo filtro que usa todo el resto del
    | sistema, y acá significa no llamar a alguien que ya terminó de pagar.
    */
    test('un expediente que ya no está vigente no se llama', function (): void {
        $venta = expedientePorCobrar(vencidas: 3);
        $venta->update(['estado' => EstadoVenta::Liquidada, 'cerrada_el' => today()]);

        expect(laListaDeHoy())->toBe([]);
    });
});

describe('Marcar que ya se contactó', function (): void {
    test('sin promesa: se va hoy y vuelve mañana', function (): void {
        actingAsAdmin();
        $venta = expedientePorCobrar();

        Livewire::test(PorCobrarHoy::class)
            ->callAction(TestAction::make('contactar')->table($venta), [
                'resultado' => ResultadoDeGestion::NoContesta->value,
                'nota'      => 'Timbró y nadie atendió',
            ])
            ->assertHasNoActionErrors();

        expect(laListaDeHoy())->toBe([]);

        $this->travelTo(today()->addDay());

        expect(laListaDeHoy())->toBe([(int) $venta->getKey()]);
    });

    /*
    | 🔴 El caso que decide si la pantalla sirve. Prometió pagar en cinco
    | días: no tiene que aparecer en el medio —ya se lo llamó— pero SÍ el día
    | prometido. Los que prometen y no cumplen son los que se pierden con un
    | «contactado» que dura para siempre.
    */
    test('con promesa: se va hasta el día que dijo, y ese día vuelve', function (): void {
        actingAsAdmin();
        $venta = expedientePorCobrar();
        $prometido = today()->addDays(5);

        Livewire::test(PorCobrarHoy::class)
            ->callAction(TestAction::make('contactar')->table($venta), [
                'resultado'  => ResultadoDeGestion::Prometio->value,
                'promesa_el' => $prometido->toDateString(),
            ])
            ->assertHasNoActionErrors();

        expect(laListaDeHoy())->toBe([]);

        $this->travelTo($prometido->copy()->subDay());
        expect(laListaDeHoy())->toBe([]);

        $this->travelTo($prometido);
        expect(laListaDeHoy())->toBe([(int) $venta->getKey()]);
    });

    /*
    | ⚠️ La ÚLTIMA manda. Prometió para dentro de diez días, pero hoy se lo
    | volvió a llamar y no atendió: la promesa vieja ya no vale y vuelve
    | mañana. Con un «existe alguna gestión que lo tape» se quedaría
    | escondido los diez días.
    */
    test('una promesa vieja no sobrevive a una llamada nueva', function (): void {
        $venta = expedientePorCobrar();
        $usuario = User::factory()->create();

        GestionDeCobro::query()->create([
            'venta_id'      => $venta->getKey(),
            'user_id'       => $usuario->getKey(),
            'resultado'     => ResultadoDeGestion::Prometio,
            'contactado_el' => today()->subDay()->toDateString(),
            'promesa_el'    => today()->addDays(10)->toDateString(),
        ]);

        GestionDeCobro::query()->create([
            'venta_id'      => $venta->getKey(),
            'user_id'       => $usuario->getKey(),
            'resultado'     => ResultadoDeGestion::NoContesta,
            'contactado_el' => today()->toDateString(),
        ]);

        expect(laListaDeHoy())->toBe([]);

        $this->travelTo(today()->addDay());
        expect(laListaDeHoy())->toBe([(int) $venta->getKey()]);
    });

    /*
    | El CHECK de la base es la última llave: una fecha prometida colgando de
    | un «no contesta» escondería al cliente hasta un día que nadie prometió.
    | La acción la descarta antes; esto prueba que si algo se cuela, Postgres
    | tampoco la deja pasar.
    */
    test('una promesa sin «prometió» la rechaza la base', function (): void {
        $venta = expedientePorCobrar();
        $usuario = User::factory()->create();

        expect(fn (): mixed => GestionDeCobro::query()->create([
            'venta_id'      => $venta->getKey(),
            'user_id'       => $usuario->getKey(),
            'resultado'     => ResultadoDeGestion::NoContesta,
            'contactado_el' => today()->toDateString(),
            'promesa_el'    => today()->addDays(3)->toDateString(),
        ]))->toThrow(QueryException::class);
    });
});

/*
| §9.E6: un contador que no dice lo mismo que la pantalla que abre miente, y
| a la semana nadie le cree a ninguno de los dos.
*/
test('el número del menú dice lo mismo que la lista', function (): void {
    actingAsAdmin();

    expedientePorCobrar(vencidas: 2);
    expedientePorCobrar(vencidas: 0, venceHoy: true);
    expedientePorCobrar(vencidas: 0);

    expect(PorCobrarHoy::getNavigationBadge())->toBe('2')
        ->and(laListaDeHoy())->toHaveCount(2);
});

test('sin nadie a quien llamar el menú no dibuja el número', function (): void {
    actingAsAdmin();

    expect(PorCobrarHoy::getNavigationBadge())->toBeNull();
});

describe('Quién puede abrirla', function (): void {
    test('la administradora y el receptor, que son los que llaman', function (): void {
        actingAsAdmin();

        expect(PorCobrarHoy::canAccess())->toBeTrue();
    });

    test('un usuario sin el permiso no la ve', function (): void {
        $this->actingAs(User::factory()->create());

        expect(PorCobrarHoy::canAccess())->toBeFalse();
    });
});
