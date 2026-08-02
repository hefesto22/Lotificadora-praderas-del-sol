<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // Limpia el contador de queries antes de cada test.
    DB::flushQueryLog();
    DB::enableQueryLog();
});

test('getDescendantIds devuelve árbol vacío para usuario sin hijos', function (): void {
    $user = User::factory()->create();

    expect($user->getDescendantIds())->toBe([]);
});

test('getDescendantIds devuelve hijos directos', function (): void {
    $padre = User::factory()->create();
    $hijos = User::factory()->count(3)->createdBy($padre)->create();

    $ids = $padre->getDescendantIds();

    sort($ids);
    $esperados = $hijos->pluck('id')->sort()->values()->all();

    expect($ids)->toBe($esperados);
});

test('getDescendantIds devuelve árbol completo (5 niveles) en UNA sola query', function (): void {
    $nivel0 = User::factory()->create();
    $nivel1 = User::factory()->createdBy($nivel0)->create();
    $nivel2 = User::factory()->createdBy($nivel1)->create();
    $nivel3 = User::factory()->createdBy($nivel2)->create();
    $nivel4 = User::factory()->createdBy($nivel3)->create();

    DB::flushQueryLog();
    $ids = $nivel0->getDescendantIds();

    expect(count($ids))->toBe(4)
        ->and($ids)->toContain($nivel1->id, $nivel2->id, $nivel3->id, $nivel4->id);

    // El fix con CTE recursivo debe ser UNA sola query — no N como antes.
    expect(count(DB::getQueryLog()))->toBe(1);
});

test('getDescendantIds excluye usuarios con soft-delete', function (): void {
    $padre = User::factory()->create();
    $vivo = User::factory()->createdBy($padre)->create();
    $muerto = User::factory()->createdBy($padre)->create();
    $muerto->delete();

    $ids = $padre->getDescendantIds();

    expect($ids)->toContain($vivo->id)
        ->and($ids)->not->toContain($muerto->id);
});
