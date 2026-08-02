<?php

declare(strict_types=1);
use App\Domain\ValueObjects\Monto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Expectation;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Asigna a qué TestCase apunta cada carpeta. Feature usa el TestCase
| que corre con RefreshDatabase + Laravel app boot. Unit usa el base
| (sin DB) — más rápido, ideal para Value Objects y lógica pura.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Feature/Filament');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
| Custom expectations específicas del dominio Olympo.
*/

expect()->extend('toBeMonto', function (float $valor, string $moneda = 'HNL'): Expectation {
    /** @var Monto $monto */
    $monto = $this->value;

    expect($monto)->toBeInstanceOf(Monto::class);
    expect($monto->valor)->toBe($valor);
    expect($monto->moneda)->toBe($moneda);

    return $this;
});

expect()->extend('toBeValidRTN', function (): Expectation {
    expect((string) $this->value)->toMatch('/^\d{14}$/');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function actingAsAdmin(): User
{
    /** @var User $user */
    $user = User::factory()->create([
        'is_active' => true,
    ]);

    test()->actingAs($user);

    return $user;
}
