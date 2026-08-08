<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Cobrar desde el expediente
|--------------------------------------------------------------------------
| El dominio ya tiene sus once tests. Estos son de la PANTALLA, que es otra
| cosa: dos veces en esta semana el Service estuvo verde y el modal roto
| —$arguments que no se inyecta en un componente del schema, un campo oculto
| que Filament no deshidrata—. Renderizar no alcanza: hay que disparar la
| acción.
|
| Dos lotes de 250 vr² a L 1,400.00: L 350,000.00 cada uno. El primero a 12
| meses con L 50,000.00 de prima da cuotas de L 25,000.00 exactas; el segundo
| a 24 meses con la misma prima da L 12,500.00.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $uno = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
    $dos = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '2']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $condicion = static fn (Lote $lote, int $meses): PrecioPactado => new PrecioPactado(
        loteId: (int) $lote->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: $meses,
        prima: new Monto('50000.00'),
    );

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$uno, $dos],
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [$condicion($uno, 12), $condicion($dos, 24)],
    );

    $this->primerLote = $this->venta->compromisos()->orderBy('lote_id')->firstOrFail();
    $this->segundoLote = $this->venta->compromisos()->orderByDesc('lote_id')->firstOrFail();

    /*
     * El modal abre con los DOS lotes marcados y la cuota del mes de cada uno
     * ya escrita. Para cobrar uno solo hay que desmarcar el otro, igual que en
     * la ventanilla — por eso los tests de un lote mandan el `false`.
     */
    $this->soloElPrimero = fn (string $monto, array $mas = []): array => array_merge([
        'cobrar_'.$this->primerLote->getKey()  => true,
        'monto_'.$this->primerLote->getKey()   => $monto,
        'cobrar_'.$this->segundoLote->getKey() => false,
        'forma_pago'                           => FormaDePago::Efectivo->value,
        'fecha'                                => today()->toDateString(),
    ], $mas);

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );
});

test('el pago entra por la pantalla y se reparte FIFO', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElPrimero)('60000.00'))
        ->assertHasNoActionErrors();

    $pagadas = Cuota::query()
        ->where('compromiso_id', $this->primerLote->getKey())
        ->orderBy('numero')
        ->limit(3)
        ->pluck('monto_pagado')
        ->all();

    // 25,000 + 25,000 + 10,000 = 60,000
    expect($pagadas)->toBe(['25000.00', '25000.00', '10000.00'])
        ->and(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(1)
        ->and(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->firstOrFail()->aplicaciones()->count())->toBe(3);
});

/*
| R11. La referencia es obligatoria en transferencia y depósito, y el campo
| aparece solo. El Service también la exige —esto prueba que la pantalla no
| deja llegar hasta allá con las manos vacías.
*/
test('una transferencia sin referencia no pasa del formulario', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElPrimero)('25000.00', [
            'forma_pago' => FormaDePago::Transferencia->value,
        ]))
        ->assertHasActionErrors(['referencia']);

    expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0);
});

test('con referencia, la transferencia se registra', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElPrimero)('25000.00', [
            'forma_pago' => FormaDePago::Transferencia->value,
            'referencia' => 'TRF-88120',
        ]))
        ->assertHasNoActionErrors();

    expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->firstOrFail()->getAttribute('referencia'))->toBe('TRF-88120');
});

/*
| El error del dominio se muestra como notificación y el formulario queda
| como estaba. Lo que NO puede pasar es una pantalla de error 500 con el
| cliente enfrente.
*/
test('un pago mayor a lo que se debe no rompe la pantalla', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElPrimero)('999999.00'))
        ->assertHasNoActionErrors();

    expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('600000.00');
});

/*
| Lo que pidió Mauricio el 8-ago-2026: un contrato de varios lotes se pagaba
| lote por lote, y eran tres trámites y tres papeles para un cliente que
| entregó un solo billete. El modal abre con todo marcado y la cuota del mes
| de cada lote ya escrita, así que el caso de todos los días es abrir y
| confirmar.
*/
test('el modal propone el mes de los dos lotes y sale UN recibo', function (): void {
    ($this->expediente)()
        ->callAction('cobrar')
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->sole();

    // 25,000 del lote a 12 meses + 12,500 del lote a 24.
    expect($recibo->montoTotal())->toBeMonto('37500.00')
        ->and($recibo->aplicaciones()->count())->toBe(2)
        // Un recibo de varios lotes no es de ninguno: la columna queda vacía
        // y el desglose es el que lo dice.
        ->and($recibo->getAttribute('compromiso_id'))->toBeNull()
        ->and($recibo->codigosDeLotes())->toHaveCount(2);
});

/*
| Un solo lote marcado sigue llenando `compromiso_id`. Es la enorme mayoría de
| los recibos, y las pantallas que leen esa columna no cambian.
*/
test('con un solo lote marcado, el recibo sigue apuntando a ese lote', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElPrimero)('25000.00'))
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->sole();

    expect($recibo->getAttribute('compromiso_id'))->toBe($this->primerLote->getKey())
        ->and($recibo->tocaVariosLotes())->toBeFalse();
});

/*
| Todo o nada. Si el segundo renglón paga de más, el primero TAMPOCO se cobra
| y el correlativo no se movió: medio recibo no existe.
*/
test('si un lote paga de más, no se cobra ninguno', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', [
            'cobrar_'.$this->primerLote->getKey()  => true,
            'monto_'.$this->primerLote->getKey()   => '25000.00',
            'cobrar_'.$this->segundoLote->getKey() => true,
            'monto_'.$this->segundoLote->getKey()  => '999999.00',
            'forma_pago'                           => FormaDePago::Efectivo->value,
            'fecha'                                => today()->toDateString(),
        ])
        ->assertHasNoActionErrors();

    expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
        ->and(Cuota::query()->where('compromiso_id', $this->primerLote->getKey())->sum('monto_pagado'))->toBe('0.00')
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('600000.00');
});

describe('Quién ve el botón', function (): void {
    test('quien puede cobrar lo ve', function (): void {
        ($this->expediente)()->assertActionVisible('cobrar');
    });

    /*
    | §9.E3 en la práctica: `Create:Recibo` se le nombra al receptor uno por
    | uno, y quien no lo tiene no cobra.
    |
    | El usuario tiene que poder VER el expediente: uno sin ningún permiso no
    | pasa de la puerta y la página ni se monta, así que no probaría nada
    | sobre el botón. Se le dan las dos lecturas de Venta y nada más.
    */
    test('quien puede ver el expediente pero no cobrar, no ve el botón', function (): void {
        $soloMira = rol('solo_mira');
        $soloMira->syncPermissions(['ViewAny:Venta', 'View:Venta']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($soloMira);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->expediente)()->assertActionHidden('cobrar');
    });

    /*
    | Un expediente cerrado no recibe dinero. El Service lo rechaza igual,
    | pero ofrecer el botón sería invitar a un movimiento que no se puede
    | hacer.
    */
    test('un expediente rescindido no muestra el botón', function (): void {
        $this->venta->update([
            'estado'     => EstadoVenta::Rescindida,
            'cerrada_el' => today(),
        ]);

        ($this->expediente)()->assertActionHidden('cobrar');
    });
});
