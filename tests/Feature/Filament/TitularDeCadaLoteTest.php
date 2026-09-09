<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Proyectos\Pages\VerPlano;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| A nombre de quien sale CADA lote, dicho antes de cobrar — 9-sep-2026
|--------------------------------------------------------------------------
| «Aca que aparezca a que titular de recibo sale, para que se tenga en cuenta
| al pagar; si es el mismo en todos o no hay configurado titular de recibos
| entonces si que se vea asi (...) lo importante que diga quien es el titular
| de cada lote para que sepa que se esta pagando» — Mauricio.
|
| 🔴 NO ES COSMETICO. `RegistroDePagos::agruparPorNombre()` parte el cobro en
| UN RECIBO POR TITULAR: dos lotes con titulares distintos salen en dos
| papeles, con dos correlativos. La pantalla no lo decia en ninguna parte —el
| aviso del contrato afirmaba lo contrario, «El recibo los cubre a todos»— asi
| que quien cobraba marcaba tres casillas creyendo que emitia un papel.
|
| El contrato de prueba es el del expediente 0085 en chico: TRES lotes, dos
| titulares de recibo. 250 vr² a L 1,400.00 = L 350,000.00 cada uno.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS', 'slug' => 'praderas']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'nombre' => 'A']);

    $dibujado = static fn (Bloque $b, string $numero, int $x): Lote => Lote::factory()
        ->enBloque($b)
        ->conMedidas('250.0000', '1400.00')
        ->create([
            'numero'   => $numero,
            'poligono' => [[$x, 0], [$x + 10, 0], [$x + 10, 25], [$x, 25]],
        ]);

    $this->uno = $dibujado($bloque, '1', 0);
    $this->dos = $dibujado($bloque, '2', 20);
    $this->tres = $dibujado($bloque, '3', 40);

    // La duena del expediente: el nombre al que salen los lotes SIN titular.
    $this->cliente = Cliente::factory()->create(['nombre' => 'MARIA EVELINA CABALLERO']);

    $condicion = static fn (Lote $l): PrecioPactado => new PrecioPactado(
        loteId: (int) $l->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: 12,
        prima: new Monto('50000.00'),
    );

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $this->proyecto,
        lotes: [$this->uno, $this->dos, $this->tres],
        clientes: [$this->cliente],
        prima: new Monto('150000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [$condicion($this->uno), $condicion($this->dos), $condicion($this->tres)],
    );

    $this->renglonDe = fn (Lote $lote): Compromiso => $this->venta
        ->compromisos()
        ->where('lote_id', '=', $lote->getKey())
        ->firstOrFail();

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );

    $this->plano = fn (Lote $lote): object => Livewire::test(
        VerPlano::class,
        ['record' => $this->proyecto->getKey()],
    )->mountAction('cobrarDesdeElPlano', ['lote' => $lote->getKey()]);

    /*
     * 🔴 LAS ASERCIONES SON `assertMountedActionModal*` Y NO `assertSee()`.
     *
     * El modal de una accion NO se dibuja en el HTML del componente: Filament
     * lo manda en un `wire:partial` («action-modals») que viaja aparte, en los
     * efectos de Livewire. `assertSee()` mira el render de la pagina, asi que
     * NUNCA ve el modal —y peor: `assertDontSee()` pasa en verde por la misma
     * razon, sin haber comprobado nada—.
     *
     * Se descubrio con estos mismos tests, que fallaron los cuatro contra un
     * modal que estaba perfectamente bien. `getMountedActionModalHtml()` es el
     * que sabe leer ese partial, y estas aserciones son las que lo usan.
     */

    // La pastilla, tal cual la arma `CobrarUnPago::aQuienSaleElPapel()`.
    $this->pastilla = static fn (string $nombre): string => sprintf(
        '<span class="olympo-renglon-titular">recibo a <span class="quien">%s</span></span>',
        $nombre,
    );

    $this->conTitular = function (Lote $lote, string $nombre): void {
        ($this->renglonDe)($lote)->update(['titular_recibo' => $nombre]);
    };
});

/*
| ── Cuando NO hay nada que aclarar ────────────────────────────────────
|
| «Si es el mismo en todos o no hay configurado titular de recibos, entonces
| si que se vea asi» — el pedido, textual. Con un solo nombre el dato no
| decide nada: el recibo sale a ese nombre marque lo que marque, y repetirlo
| en cada renglon seria la advertencia permanente que se deja de leer.
*/
test('sin titulares configurados el renglon no nombra a nadie', function (): void {
    ($this->expediente)()
        ->mountAction('cobrar')
        ->assertSuccessful()
        ->assertMountedActionModalDontSeeHtml('olympo-renglon-titular');
});

test('con el MISMO titular en todos los lotes tampoco se nombra', function (): void {
    foreach ([$this->uno, $this->dos, $this->tres] as $lote) {
        ($this->conTitular)($lote, 'JOSE ANTONIO MEJIA');
    }

    ($this->expediente)()
        ->mountAction('cobrar')
        ->assertSuccessful()
        ->assertMountedActionModalDontSeeHtml('olympo-renglon-titular');
});

/*
| ── El caso del pedido ────────────────────────────────────────────────
*/
test('con titulares distintos cada renglon dice el suyo', function (): void {
    ($this->conTitular)($this->uno, 'JOSE ANTONIO MEJIA');
    ($this->conTitular)($this->tres, 'ROSA ELENA MEJIA');

    ($this->expediente)()
        ->mountAction('cobrar')
        ->assertSuccessful()
        ->assertMountedActionModalSeeHtml(($this->pastilla)('JOSE ANTONIO MEJIA'))
        ->assertMountedActionModalSeeHtml(($this->pastilla)('ROSA ELENA MEJIA'));
});

/*
| 🔴 El lote SIN titular tambien se nombra, con el dueno del expediente.
|
| Dejarlo en blanco mientras el de al lado dice un nombre haria preguntar si
| el blanco es un error de carga. Y para `agruparPorNombre()` «el dueno» es un
| titular tan distinto como cualquier otro: tambien se lleva su propio recibo.
*/
test('el lote sin titular sale a nombre del dueno del expediente', function (): void {
    ($this->conTitular)($this->uno, 'JOSE ANTONIO MEJIA');

    ($this->expediente)()
        ->mountAction('cobrar')
        ->assertSuccessful()
        ->assertMountedActionModalSeeHtml(($this->pastilla)('JOSE ANTONIO MEJIA'))
        ->assertMountedActionModalSeeHtml(($this->pastilla)('MARIA EVELINA CABALLERO'));
});

/*
| ── El aviso de arriba, que decia lo contrario ────────────────────────
*/
describe('El aviso del contrato', function (): void {
    test('con un solo titular sigue diciendo que el recibo los cubre a todos', function (): void {
        ($this->plano)($this->uno)
            ->assertSuccessful()
            ->assertMountedActionModalSee('El recibo los cubre a todos.');
    });

    /*
    | 🔴 La correccion. Decia «El recibo los cubre a todos» en singular y sin
    | condiciones: verdad en el caso comun, MENTIRA justo en el caso que este
    | aviso existe para cubrir.
    */
    test('con dos titulares dice cuantos recibos salen', function (): void {
        ($this->conTitular)($this->uno, 'JOSE ANTONIO MEJIA');

        ($this->plano)($this->uno)
            ->assertSuccessful()
            ->assertMountedActionModalDontSee('El recibo los cubre a todos.')
            ->assertMountedActionModalSee('2 titulares de recibo distintos')
            ->assertMountedActionModalSee('2 recibos');
    });
});

/*
| ── El segundo pedido del mismo mensaje: que no se tarde ──────────────
|
| «Se tarda como un segundo o mas en contestar al dar clic en algun check, hay
| que mejorar eso tambien» — Mauricio.
|
| 🔴 QUE MIDE ESTE TEST. Cada casilla es `->live()`, asi que un clic vuelve a
| armar el schema entero; y armarlo llamaba a `lotesQueDeben()` una docena de
| veces, con una consulta por lote CADA vez. Con tres lotes eso pasaba de
| sesenta consultas para dibujar un modal que no cobro nada todavia.
|
| Se cuentan SOLO las consultas a `cuotas` y no todas las del request: el
| total lo mueve cualquier version de Filament y el test se volveria un
| estorbo, mientras que este numero mide exactamente lo que se rompio.
|
| Con la relacion cargada de una sola vez, abrir el modal pregunta las cuotas
| UNA vez —la traiga el contrato de tres lotes o el de treinta—; preguntando
| lote por lote eran mas de cincuenta. El techo deja lugar para lo que redibuje
| el expediente al montarse la accion y sigue estando a un orden de magnitud de
| la regresion: lo que se cuida es que el numero no CREZCA CON LOS LOTES.
*/
test('dibujar el modal no vuelve a preguntar el saldo lote por lote', function (): void {
    // La pantalla ya montada: lo que se mide es ABRIR EL MODAL, no cargarla.
    $pantalla = ($this->expediente)();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $pantalla->mountAction('cobrar')->assertSuccessful();

    $registro = DB::getQueryLog();
    DB::disableQueryLog();

    $aLasCuotas = array_filter(
        $registro,
        static fn (array $consulta): bool => str_contains((string) $consulta['query'], 'cuotas'),
    );

    expect(count($aLasCuotas))->toBeLessThanOrEqual(8);
});
