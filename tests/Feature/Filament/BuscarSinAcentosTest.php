<?php

declare(strict_types=1);

use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Models\Cliente;
use App\Support\SinAcentos;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Buscar un nombre sin pelear con la tilde
|--------------------------------------------------------------------------
| Pedido de Mauricio, 13-ago-2026: «ya que todos los clientes se guardan en
| mayúsculas, no debería haber acentuación, ya que cuando los busco no
| coinciden».
|
| 🔴 Lo que NO se hizo, y este archivo lo deja escrito: quitarle la tilde al
| dato. Ese nombre se imprime en el contrato y en la escritura, y no se
| puede deshacer. Lo que se dobla es la BÚSQUEDA, de las dos puntas: la
| columna generada guarda el nombre sin acentos y acá se dobla lo tecleado.
|
| La cartera vieja tiene las dos formas —quien la cargó a veces puso la
| tilde y a veces no—, así que los dos sentidos tienen que funcionar.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->conTilde = Cliente::factory()->create(['nombre' => 'Adela Díaz Hernández']);
    $this->sinTilde = Cliente::factory()->create(['nombre' => 'Rosa Diaz Mejia']);
    $this->ajeno = Cliente::factory()->create(['nombre' => 'Carlos Chacón Arévalo']);

    $this->buscar = static fn (string $texto) => Livewire::test(ListClientes::class)
        ->set('tableSearch', $texto);
});

describe('El nombre guardado no se toca', function (): void {
    /*
    | El acento sigue ahí, en mayúsculas y todo. Es lo que va impreso.
    */
    test('el nombre conserva su tilde', function (): void {
        expect($this->conTilde->getAttribute('nombre'))->toBe('ADELA DÍAZ HERNÁNDEZ');
    });

    test('la columna de búsqueda la calcula Postgres y va sin tildes', function (): void {
        expect($this->conTilde->refresh()->getAttribute('nombre_busqueda'))->toBe('ADELA DIAZ HERNANDEZ');
    });

    /*
    | Generada quiere decir que no se puede desincronizar: al cambiar el
    | nombre, la columna se recalcula sola. Ni un seeder ni un tinker la
    | pueden dejar vieja.
    */
    test('al cambiar el nombre la columna se recalcula sola', function (): void {
        $this->conTilde->update(['nombre' => 'Adela Peña Güendel']);

        expect($this->conTilde->refresh()->getAttribute('nombre_busqueda'))->toBe('ADELA PENA GUENDEL');
    });
});

describe('Buscar en los dos sentidos', function (): void {
    test('sin tilde encuentra al que la tiene', function (): void {
        ($this->buscar)('DIAZ')
            ->assertCanSeeTableRecords([$this->conTilde, $this->sinTilde])
            ->assertCanNotSeeTableRecords([$this->ajeno]);
    });

    test('con tilde encuentra al que no la tiene', function (): void {
        ($this->buscar)('DÍAZ')
            ->assertCanSeeTableRecords([$this->conTilde, $this->sinTilde])
            ->assertCanNotSeeTableRecords([$this->ajeno]);
    });

    /*
    | La gente busca por el apellido, y el apellido va al final del nombre:
    | el comodín tiene que ir de los dos lados.
    */
    test('el apellido solo alcanza, aunque esté al final', function (): void {
        ($this->buscar)('chacon')->assertCanSeeTableRecords([$this->ajeno]);
    });

    test('lo que no está sigue sin aparecer', function (): void {
        ($this->buscar)('ZURITA')->assertCanNotSeeTableRecords([$this->conTilde, $this->sinTilde, $this->ajeno]);
    });
});

describe('El doblador de acentos', function (): void {
    test('dobla las vocales, la ñ y la diéresis', function (): void {
        expect(SinAcentos::de('ÁÉÍÓÚ'))->toBe('AEIOU')
            ->and(SinAcentos::de('Peña'))->toBe('Pena')
            ->and(SinAcentos::de('Güendel'))->toBe('Guendel')
            ->and(SinAcentos::de('Gonçalves'))->toBe('Goncalves');
    });

    /*
    | No toca la caja: de eso se encarga el ILIKE. Si acá se pasara todo a
    | mayúsculas, la comparación seguiría funcionando pero el día que
    | alguien use este helper para otra cosa se llevaría una sorpresa.
    */
    test('no cambia mayúsculas por minúsculas', function (): void {
        expect(SinAcentos::de('Díaz'))->toBe('Diaz');
    });

    /*
    | Las dos listas tienen que medir lo mismo, o `array_combine` revienta
    | y —peor— el TRANSLATE de la migración doblaría distinto que esto.
    */
    test('las dos listas de letras miden igual', function (): void {
        expect(mb_strlen(SinAcentos::ACENTOS))->toBe(mb_strlen(SinAcentos::LLANAS));
    });
});
