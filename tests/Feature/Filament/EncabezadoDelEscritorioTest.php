<?php

declare(strict_types=1);

use App\Filament\Widgets\EncabezadoDelEscritorio;
use App\Support\Roles;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El encabezado del Escritorio — 11-sep-2026
|--------------------------------------------------------------------------
| «Ese bienvenido debería quitarse y hay que hacerlo más profesional y
| empresarial» —Mauricio—.
|
| El `AccountWidget` de fábrica ocupaba el lugar de más peso de la pantalla
| —arriba del todo, ancho completo— para decir «Bienvenida/o» y ofrecer un
| botón de salir que ya vive en el menú del usuario. En su lugar va lo que
| sí identifica al sistema: de quién es y de qué día habla.
|
| 🔴 LO QUE ESTOS TESTS CUIDAN es que el encabezado no tumbe el Escritorio.
| Un widget que lanza se lleva la página entera, y este lee la marca —una
| tabla que en una instalación nueva puede no estar—. Por eso la lectura va
| con `try`/`catch` y por eso hay un test que entra sin marca cargada.
*/

beforeEach(function (): void {
    actingAsAdmin(['name' => 'Mauricio Cruz']);

    config()->set('app.name', 'RESIDENCIAL PRADERAS DEL SOL');
});

test('dice de qué sistema es y de qué día habla', function (): void {
    Livewire::test(EncabezadoDelEscritorio::class)
        ->assertSee('RESIDENCIAL PRADERAS DEL SOL')
        ->assertSee('Sistema de lotificación')
        // `fechaLarga(..., conDiaSemana: true)`: «viernes, 11 de septiembre de 2026».
        ->assertSee(fechaLarga(now(), conDiaSemana: true));
});

/*
| El nombre de quien entró no se va: se achica. Pasa de titular a pie de
| línea, que es la diferencia entre un tablero personal y el sistema de una
| empresa.
*/
test('el nombre de quien entró queda, pero sin saludo', function (): void {
    Livewire::test(EncabezadoDelEscritorio::class)
        ->assertSee('Mauricio Cruz')
        ->assertDontSee('Bienvenida')
        ->assertDontSee('Bienvenido');
});

test('dice con qué rol entró', function (): void {
    $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR, ['name' => 'Edwin Fúnez']));

    Livewire::test(EncabezadoDelEscritorio::class)
        ->assertSee('Edwin Fúnez')
        ->assertSee('receptor');
});

/*
| 🔴 El encabezado lo ve TODO el mundo, a diferencia del cuadro de costos.
| No dice ninguna cifra: es un rótulo. Si se le pusiera un permiso, el
| receptor abriría un Escritorio sin encabezado y con los cuadros flotando.
*/
test('el receptor también lo ve', function (): void {
    $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR));

    expect(EncabezadoDelEscritorio::canView())->toBeTrue();
});

/*
| Una instalación recién levantada todavía no tiene logo cargado. El blade
| pregunta antes de dibujar, así que no queda ni un hueco ni un ícono de
| imagen rota — y sobre todo, no revienta.
*/
test('sin logo cargado sale igual, con el nombre solo', function (): void {
    Livewire::test(EncabezadoDelEscritorio::class)
        ->assertOk()
        ->assertSee('RESIDENCIAL PRADERAS DEL SOL');
});
