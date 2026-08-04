<?php

declare(strict_types=1);

use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

describe('Cliente — normalización de entrada', function (): void {
    test('el DNI se guarda limpio aunque se teclee con guiones', function (): void {
        $cliente = Cliente::factory()->create(['dni' => '0801-1985-01234']);

        expect($cliente->fresh()?->getAttribute('dni'))->toBe('0801198501234');
    });

    test('el RTN también se guarda limpio', function (): void {
        $cliente = Cliente::factory()->create(['rtn' => '0801-1985-012345']);

        expect($cliente->fresh()?->getAttribute('rtn'))->toBe('08011985012345');
    });

    test('un DNI inválido no llega a la base ni desde un factory', function (): void {
        expect(fn (): Cliente => Cliente::factory()->create(['dni' => '0801198501']))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('el correo se guarda en minúsculas', function (): void {
        $cliente = Cliente::factory()->create(['correo' => '  Rosa.Elena@GMAIL.com ']);

        expect($cliente->fresh()?->getAttribute('correo'))->toBe('rosa.elena@gmail.com');
    });

    /*
    | El nombre va en MAYÚSCULAS, igual que todo lo demás. Deroga la
    | excepción que el §10.4 hacía para nombres de personas — ver
    | docs/mayusculas.md. Los espacios sobrantes se siguen colapsando: son
    | los que producen duplicados invisibles al buscar.
    */
    test('el nombre se guarda en mayúsculas y colapsa espacios', function (): void {
        $cliente = Cliente::factory()->create(['nombre' => '  María  de los   Ángeles Rodríguez ']);

        expect($cliente->fresh()?->getAttribute('nombre'))->toBe('MARÍA DE LOS ÁNGELES RODRÍGUEZ');
    });

    test('el teléfono se guarda en dígitos y se formatea al mostrar', function (): void {
        $cliente = Cliente::factory()->create(['telefono' => '9988-7766']);

        expect($cliente->fresh()?->getAttribute('telefono'))->toBe('99887766');
        expect($cliente->fresh()?->telefonoFormateado())->toBe('+504 9988-7766');
    });

    test('formatea el DNI y el RTN para las pantallas', function (): void {
        $cliente = Cliente::factory()->create([
            'dni' => '0801198501234',
            'rtn' => '08011985012345',
        ]);

        expect($cliente->dniFormateado())->toBe('0801-1985-01234');
        expect($cliente->rtnFormateado())->toBe('0801-1985-012345');
    });

    test('un cliente sin identificación devuelve null en los formateadores', function (): void {
        $cliente = Cliente::factory()->sinIdentificacion()->create(['telefono' => null]);

        expect($cliente->dniFormateado())->toBeNull();
        expect($cliente->rtnFormateado())->toBeNull();
        expect($cliente->telefonoFormateado())->toBeNull();
    });
});

describe('Cliente — índices únicos parciales (§8.2)', function (): void {
    /*
    | En Postgres NULL != NULL, así que un UNIQUE normal dejaría entrar mil
    | clientes sin DNI. El índice es parcial justamente para permitirlo:
    | al apartar un lote a veces solo se tiene el nombre y un teléfono.
    */
    test('varios clientes pueden no tener DNI', function (): void {
        Cliente::factory()->sinIdentificacion()->count(3)->create();

        expect(Cliente::query()->whereNull('dni')->count())->toBe(3);
    });

    test('rechaza dos clientes vivos con el mismo DNI', function (): void {
        Cliente::factory()->create(['dni' => '0801198501234']);

        expect(fn (): Cliente => Cliente::factory()->create(['dni' => '0801198501234']))
            ->toThrow(QueryException::class);
    });

    /*
    | El corazón del asunto. Sin `deleted_at IS NULL` en el índice, un
    | cliente archivado seguiría ocupando su DNI para siempre y nadie podría
    | volver a darlo de alta — con un error que no explica nada, porque el
    | registro que estorba es invisible.
    */
    test('un cliente archivado libera su DNI', function (): void {
        $primero = Cliente::factory()->create(['dni' => '0801198501234', 'rtn' => null]);
        $primero->delete();

        $segundo = Cliente::factory()->create(['dni' => '0801198501234', 'rtn' => null]);

        expect($segundo->exists)->toBeTrue();
        expect(Cliente::withTrashed()->where('dni', '0801198501234')->count())->toBe(2);
    });
});

describe('Cliente — los CHECK de la base son el piso (§12)', function (): void {
    test('la base rechaza un DNI mal formado aunque se inserte por SQL crudo', function (): void {
        expect(fn (): bool => DB::table('clientes')->insert([
            'nombre'     => 'Prueba',
            'dni'        => '123',
            'activo'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    test('la base rechaza un teléfono que no empieza en 2, 3 o 9', function (): void {
        expect(fn (): bool => DB::table('clientes')->insert([
            'nombre'     => 'Prueba',
            'telefono'   => '11223344',
            'activo'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    test('la base rechaza un nombre en blanco', function (): void {
        expect(fn (): bool => DB::table('clientes')->insert([
            'nombre'     => '   ',
            'activo'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

describe('Cliente — PII fuera de la bitácora (§13.5)', function (): void {
    test('la auditoría registra el nombre pero NUNCA el DNI, RTN, teléfono ni correo', function (): void {
        $cliente = Cliente::factory()->create();

        $cliente->update([
            'nombre'   => 'Nombre Corregido',
            'dni'      => '0801199007777',
            'telefono' => '33445566',
            'correo'   => 'otro@ejemplo.com',
        ]);

        $actividad = Activity::query()
            ->where('subject_type', $cliente->getMorphClass())
            ->where('subject_id', $cliente->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $cambios = (array) ($actividad->attribute_changes?->get('attributes') ?? []);

        expect(array_keys($cambios))->toBe(['nombre']);
        expect(json_encode($actividad->attribute_changes?->toArray() ?? []))
            ->not->toContain('0801199007777')
            ->not->toContain('33445566')
            ->not->toContain('otro@ejemplo.com');
    });

    test('el teléfono del usuario tampoco se audita', function (): void {
        $user = User::factory()->create(['phone' => '99887766']);

        $user->update(['name' => 'Nombre Nuevo', 'phone' => '33445566']);

        $actividad = Activity::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $cambios = (array) ($actividad->attribute_changes?->get('attributes') ?? []);

        expect(array_keys($cambios))->not->toContain('phone');
        expect(array_keys($cambios))->toContain('name');
    });
});

describe('Cliente — scope', function (): void {
    test('activos deja fuera a los inactivos', function (): void {
        Cliente::factory()->count(2)->create();
        Cliente::factory()->inactivo()->create();

        expect(Cliente::query()->activos()->count())->toBe(2);
    });
});
