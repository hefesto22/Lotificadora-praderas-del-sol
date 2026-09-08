<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Support\ModoDeCobro;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| «Ambas»: el sobrante repartido entre varios lotes — 8-sep-2026
|--------------------------------------------------------------------------
| «En "a qué lote va el sobrante" agreguemos que se pueda elegir cuánto va a
| cada lote o repartir en partes iguales, ya que es algo que la dueña dijo que
| sí necesitaba» — Mauricio.
|
| Hasta hoy el sobrante iba contra UN lote elegido en un Select, y estaba
| escrito en el código que eso era una decisión y no una simplificación
| pendiente. Con dos lotes en el mismo contrato, eso obligaba a la dueña a
| partir el pago en dos recibos para bajarle capital a los dos.
|
| 🔴 LO QUE ESTOS TESTS CUIDAN no es que el reparto funcione: es que el recibo
| siga saliendo por lo que el cliente entregó. Un reparto que suma de menos
| saca un papel más chico que el billete, y **nadie se entera**: el recibo
| cuadra consigo mismo, así que `olympo:cuadrar-recibos` no lo ve.
|
| Dos lotes de 250 vr² a L 1,400.00: L 350,000.00 cada uno, L 50,000.00 de
| prima. El primero a 12 meses da cuotas de L 25,000.00; el segundo a 24 da
| L 12,500.00. Saldo del contrato: L 600,000.00.
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

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );

    /*
     * L 137,500.00: la cuota del mes de los dos lotes (25,000 + 12,500) y
     * L 100,000.00 de sobrante. El sobrante NO se teclea — sale de restar.
     */
    $this->pago = fn (array $extra = []): array => array_merge([
        'modo'        => ModoDeCobro::Ambas->value,
        'monto_total' => '137500.00',

        'cobrar_'.$this->primerLote->getKey()  => true,
        'monto_'.$this->primerLote->getKey()   => '25000.00',
        'cobrar_'.$this->segundoLote->getKey() => true,
        'monto_'.$this->segundoLote->getKey()  => '12500.00',

        'reparto_sobrante'                                => 'iguales',
        'capital_'.$this->primerLote->getKey()            => true,
        'capital_modalidad_'.$this->primerLote->getKey()  => ModalidadDeReprogramacion::AcortarPlazo->value,
        'capital_'.$this->segundoLote->getKey()           => true,
        'capital_modalidad_'.$this->segundoLote->getKey() => ModalidadDeReprogramacion::AcortarPlazo->value,

        'forma_pago' => FormaDePago::Efectivo->value,
        'fecha'      => today()->toDateString(),
        'motivo'     => 'Pagó los dos meses y repartió el resto',
    ], $extra);

    $this->deCadaLote = fn (): array => Reprogramacion::query()
        ->get()
        ->mapWithKeys(static fn (Reprogramacion $constancia): array => [
            (int) $constancia->getAttribute('compromiso_id') => $constancia->montoAbonado(),
        ])
        ->all();
});

/*
| El test del pedido: un billete, un papel, y capital abajo en los DOS lotes.
*/
test('en partes iguales el sobrante baja el capital de los dos lotes', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)())
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole();

    expect($recibo->montoTotal())->toBeMonto('137500.00')
        // 600,000 − 137,500
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('462500.00')
        // Una constancia por lote, con la mitad del sobrante cada una.
        ->and(Reprogramacion::query()->count())->toBe(2);

    $porLote = ($this->deCadaLote)();

    expect($porLote[$this->primerLote->getKey()])->toBeMonto('50000.00')
        ->and($porLote[$this->segundoLote->getKey()])->toBeMonto('50000.00');
});

/*
| 🔴🔴 LA INVARIANTE, con el detector de verdad.
|
| `olympo:cuadrar-recibos` nació el 27-ago porque nadie comparaba
| `recibos.monto` contra lo aplicado, y por eso un recibo se comió L 6,979.17
| de un cliente. Repartir el sobrante multiplica las oportunidades de que eso
| vuelva a pasar, así que el detector corre acá y no en un test aparte.
*/
test('el recibo aplica exactamente lo que cobró', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)())
        ->assertHasNoActionErrors();

    $this->artisan('olympo:cuadrar-recibos')
        ->expectsOutputToContain('Todos los recibos cuadran')
        ->assertSuccessful();
});

/*
| R21 dice que los dos caminos los elige el cliente, y con dos lotes puede
| querer terminar antes el que va a construir y bajarle la cuota al otro. Si
| esto falla, la modalidad se está aplicando por recibo y no por lote.
*/
test('cada lote se lleva su propia modalidad', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)([
            'capital_modalidad_'.$this->segundoLote->getKey() => ModalidadDeReprogramacion::BajarCuota->value,
        ]))
        ->assertHasNoActionErrors();

    // Con `get()` y no con `pluck()`: se lee por el modelo, así el cast a enum
    // sí se aplica y la comparación no depende de cómo devuelva la columna.
    $constancias = Reprogramacion::query()->get()->keyBy('compromiso_id');

    expect($constancias[$this->primerLote->getKey()]->getAttribute('modalidad'))
        ->toBe(ModalidadDeReprogramacion::AcortarPlazo)
        ->and($constancias[$this->segundoLote->getKey()]->getAttribute('modalidad'))
        ->toBe(ModalidadDeReprogramacion::BajarCuota);
});

/*
| «Que se pueda elegir cuánto va a cada lote»: 70,000 al primero y 30,000 al
| segundo, tecleados a mano.
*/
test('a mano, cada lote recibe lo que se le escribió', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)([
            'reparto_sobrante'                            => 'manual',
            'capital_monto_'.$this->primerLote->getKey()  => '70000.00',
            'capital_monto_'.$this->segundoLote->getKey() => '30000.00',
        ]))
        ->assertHasNoActionErrors();

    $porLote = ($this->deCadaLote)();

    expect(Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole()->montoTotal())
        ->toBeMonto('137500.00')
        ->and($porLote[$this->primerLote->getKey()])->toBeMonto('70000.00')
        ->and($porLote[$this->segundoLote->getKey()])->toBeMonto('30000.00');
});

/*
| 🔴 EL BORDE QUE JUSTIFICA TODO ESTO.
|
| 40,000 + 30,000 son 70,000 y el sobrante es 100,000. Si esto pasara, el
| recibo saldría por L 107,500.00 cuando el cliente entregó L 137,500.00 — y
| cuadraría consigo mismo, así que ningún detector lo encontraría después.
| No se guarda NADA.
*/
test('un reparto que no suma el sobrante no registra nada', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)([
            'reparto_sobrante'                            => 'manual',
            'capital_monto_'.$this->primerLote->getKey()  => '40000.00',
            'capital_monto_'.$this->segundoLote->getKey() => '30000.00',
        ]));

    expect(Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->count())->toBe(0)
        ->and(Reprogramacion::query()->count())->toBe(0)
        // El plan quedó intacto: 600,000 menos nada.
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('600000.00');
});

/*
| El mismo borde del otro lado: repartir de más tampoco pasa.
*/
test('un reparto que se pasa del sobrante tampoco registra nada', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)([
            'reparto_sobrante'                            => 'manual',
            'capital_monto_'.$this->primerLote->getKey()  => '80000.00',
            'capital_monto_'.$this->segundoLote->getKey() => '30000.00',
        ]));

    expect(Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->count())->toBe(0)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('600000.00');
});

/*
| 🔴 EL CENTAVO DEL RESIDUO TIENE DUEÑO.
|
| L 100,000.01 entre dos no da exacto. Si el reparto redondeara, la suma de las
| partes sería L 100,000.00 y el centavo se perdería — que es un recibo que
| cobró más de lo que aplicó, en chiquito. Se parte en centavos y el resto va
| al PRIMERO por id, que es el mismo orden con el que el dominio toma los
| candados.
*/
test('el centavo que no se puede partir cae en el primero, y la suma cierra', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)(['monto_total' => '137500.01']))
        ->assertHasNoActionErrors();

    $porLote = ($this->deCadaLote)();
    $primero = $porLote[$this->primerLote->getKey()];
    $segundo = $porLote[$this->segundoLote->getKey()];

    expect($primero->sumar($segundo))->toBeMonto('100000.01')
        ->and(Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole()->montoTotal())
        ->toBeMonto('137500.01');
});

/*
| 🔴 EL PAPEL TIENE QUE DECIRLO.
|
| «Acá en el recibo debería especificar abono a capital cuánto a qué lote, o
| sea que se vea más información detallada» — Mauricio, mirando un papel que
| decía «Abono a capital … L 2,000.00» sin decir que eran mil a cada uno.
|
| Se leen los renglones de capital del HTML y no un `assertSee` suelto: el
| monto aparece también en otros lados del papel, así que ver «L 50,000.00» no
| prueba que esté EN SU RENGLON, que es lo que se pidió.
*/
test('el recibo impreso dice cuánto bajó el capital de cada lote', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)())
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole();

    $papel = (string) $this->get(route('documentos.recibo', $recibo))->assertOk()->getContent();

    preg_match_all('/<tr class="capital">.*?<\/tr>/s', $papel, $renglones);

    $uno = (string) $this->primerLote->lote?->getAttribute('codigo');
    $dos = (string) $this->segundoLote->lote?->getAttribute('codigo');

    expect($renglones[0])->toHaveCount(2)
        ->and($renglones[0][0])->toContain($uno)->toContain('L. 50,000.00')
        ->and($renglones[0][1])->toContain($dos)->toContain('L. 50,000.00');
});

/*
| Y con el sobrante entero a un lote, el papel dice A CUAL. Es un recibo que
| cobró cuotas de DOS lotes, así que sin el código el renglón dejaría abierta
| la única pregunta que importa.
*/
test('con el sobrante en un solo lote, el papel dice cuál', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)([
            'capital_'.$this->segundoLote->getKey() => false,
        ]))
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole();

    $papel = (string) $this->get(route('documentos.recibo', $recibo))->assertOk()->getContent();

    preg_match_all('/<tr class="capital">.*?<\/tr>/s', $papel, $renglones);

    expect($renglones[0])->toHaveCount(1)
        ->and($renglones[0][0])->toContain((string) $this->primerLote->lote?->getAttribute('codigo'))
        ->and($renglones[0][0])->toContain('L. 100,000.00')
        ->and($renglones[0][0])->not->toContain((string) $this->segundoLote->lote?->getAttribute('codigo'));
});

/*
| Y lo de siempre sigue andando: marcar UN solo lote manda el sobrante entero
| ahí, que es exactamente lo que hacía el Select hasta el 8-sep-2026.
*/
test('marcar un solo lote es el comportamiento de antes', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)([
            'capital_'.$this->segundoLote->getKey() => false,
        ]))
        ->assertHasNoActionErrors();

    expect(Reprogramacion::query()->count())->toBe(1)
        ->and(Reprogramacion::query()->sole()->getAttribute('compromiso_id'))->toBe($this->primerLote->getKey())
        ->and(Reprogramacion::query()->sole()->montoAbonado())->toBeMonto('100000.00');
});
