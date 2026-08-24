<?php

declare(strict_types=1);

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Exceptions\CorrelativoInvalidoException;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Consumo de correlativos — §8.3.6 y R7/R12
|--------------------------------------------------------------------------
| El Service se resuelve con app() y no con `new` (§9.C1): los constructores
| crecen, y el dia que este reciba una dependencia no hay que tocar veinte
| tests.
*/

beforeEach(function (): void {
    $this->correlativos = app(ConsumoDeCorrelativos::class);
    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
});

describe('Contratos', function (): void {
    test('abre la serie sola y entrega el primer numero', function (): void {
        $numero = DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));

        expect($numero)->toBe(1)
            ->and(DB::table('correlativos')->where('tipo', 'contrato')->count())->toBe(1);
    });

    test('entrega numeros consecutivos y no repite ninguno', function (): void {
        $numeros = [];

        for ($i = 0; $i < 5; $i++) {
            $numeros[] = DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));
        }

        expect($numeros)->toBe([1, 2, 3, 4, 5])
            ->and(array_unique($numeros))->toHaveCount(5);
    });

    /*
    | ADR-0002: el sistema es single-tenant pero multi-proyecto. Dos
    | desarrollos no comparten numeracion de contrato — cada uno tiene su
    | codigo y su serie.
    */
    test('cada proyecto lleva su propia serie', function (): void {
        $otro = Proyecto::factory()->create(['codigo' => 'VVE']);

        DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));
        DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));

        $primeroDelOtro = DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($otro));

        expect($primeroDelOtro)->toBe(1)
            ->and(DB::table('correlativos')->count())->toBe(2);
    });

    /*
    | R7: el secuencial NO reinicia cada anio. La serie no sabe de anios —
    | no hay columna `anio` en la tabla—, asi que el numero sigue corriendo
    | sin importar cuando se firme.
    */
    test('la serie no depende del anio', function (): void {
        DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));

        $numero = DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));

        expect($numero)->toBe(2)
            ->and($this->correlativos->numeroDeContrato($this->proyecto, $numero, 2027))
            ->toBe('RPS-2027-0002');
    });
});

describe('Recibos internos', function (): void {
    /*
    | Desde el 23-ago-2026 la serie corre POR PROYECTO: dos desarrollos ya no
    | se intercalan los numeros. Adentro de uno sigue habiendo UNA sola
    | secuencia —no una por receptor—: don Elder y don Edwin sacan del mismo
    | mostrador.
    */
    test('cada proyecto lleva su propia serie', function (): void {
        $primero = DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboInterno($this->proyecto));
        $segundo = DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboInterno($this->proyecto));

        expect([$primero, $segundo])->toBe([1, 2]);

        $otro = Proyecto::factory()->create(['codigo' => 'BAM']);
        $suyo = DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboInterno($otro));

        // Arranca en 1 aunque el otro desarrollo ya lleve dos: son dos series.
        expect($suyo)->toBe(1);
    });

    test('la serie de la cartera vieja es global y aparte', function (): void {
        DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboInterno($this->proyecto));

        $historico = DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboHistorico());

        expect($historico)->toBe(1);

        // `value()` y no `first()->proyecto_id`: el query builder devuelve
        // un `object` pelado y PHPStan no le conoce propiedades.
        $conProyecto = DB::table('correlativos')
            ->where('tipo', 'recibo_historico')
            ->whereNotNull('proyecto_id')
            ->count();

        expect($conProyecto)->toBe(0);
    });

    /*
    | El campo «desde que numero empieza a imprimir» de la pestana
    | Facturacion. Es un PISO, no una asignacion: la serie nunca retrocede.
    */
    test('el proyecto puede decir desde que numero arranca', function (): void {
        $this->proyecto->update(['proximo_recibo' => 500]);

        $primero = DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboInterno($this->proyecto->fresh()));

        expect($primero)->toBe(500);
    });

    test('bajarle el arranque NO hace retroceder la serie', function (): void {
        $this->proyecto->update(['proximo_recibo' => 500]);
        DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboInterno($this->proyecto->fresh()));

        $this->proyecto->update(['proximo_recibo' => 10]);
        $siguiente = DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboInterno($this->proyecto->fresh()));

        expect($siguiente)->toBe(501);
    });

    test('no comparte serie con los contratos', function (): void {
        DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));
        DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));

        $recibo = DB::transaction(fn (): int => $this->correlativos->siguienteDeReciboInterno($this->proyecto));

        expect($recibo)->toBe(1);
    });

    test('el numero de un recibo viene con su serie', function (): void {
        $delProyecto = DB::transaction(fn (): array => $this->correlativos->paraUnReciboNuevo($this->proyecto));
        $viejo = DB::transaction(fn (): array => $this->correlativos->paraUnReciboNuevo($this->proyecto, true));

        expect($delProyecto)->toBe(['numero' => 1, 'serie' => 'RPS'])
            ->and($viejo)->toBe(['numero' => 1, 'serie' => null]);
    });
});

describe('Transaccionalidad', function (): void {
    /*
    | LA RAZON DE SER DE TODO ESTO. Si la venta se cae despues de numerar,
    | el correlativo se va con ella: el siguiente que llegue se lleva el
    | mismo numero y la serie no queda con un hueco que despues haya que
    | explicarle a alguien.
    */
    test('si la operacion se cae, el numero no se quema', function (): void {
        $primero = DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));

        try {
            DB::transaction(function (): void {
                $this->correlativos->siguienteDeContrato($this->proyecto);

                throw new RuntimeException('la venta se cayo despues de numerar');
            });
        } catch (RuntimeException) {
            // Esperado: lo que importa es lo que quedo en la serie.
        }

        $siguiente = DB::transaction(fn (): int => $this->correlativos->siguienteDeContrato($this->proyecto));

        expect($primero)->toBe(1)
            ->and($siguiente)->toBe(2);
    });

    /*
    | `lockForUpdate()` fuera de una transaccion no bloquea nada, y el
    | codigo igual se ve correcto. El Service se planta antes de dejar
    | pasar eso.
    |
    | Para llegar a nivel 0 hay que salirse de la transaccion con la que
    | RefreshDatabase envuelve cada test. Es seguro aca porque este test NO
    | ESCRIBE NADA: el guard corta antes de tocar la base y el proyecto va
    | sin guardar. `rollBack()` en el tearDown a nivel 0 es un no-op.
    */
    test('se niega a numerar fuera de una transaccion', function (): void {
        DB::rollBack();

        expect(DB::transactionLevel())->toBe(0);

        expect(fn (): int => $this->correlativos->siguienteDeContrato(Proyecto::factory()->make(['codigo' => 'RPS'])))
            ->toThrow(CorrelativoInvalidoException::class, 'fuera de una transaccion');
    });
});

describe('Formato del numero', function (): void {
    /*
    | R7, con el ejemplo real que dio la contratante:
    | expediente 0001 ↔ contrato RPS-2026-0001.
    */
    test('arma el numero de contrato con el codigo, el anio y el secuencial', function (): void {
        expect($this->correlativos->numeroDeContrato($this->proyecto, 1, 2026))->toBe('RPS-2026-0001')
            ->and($this->correlativos->numeroDeContrato($this->proyecto, 65, 2026))->toBe('RPS-2026-0065')
            ->and($this->correlativos->numeroDeContrato($this->proyecto, 1234, 2026))->toBe('RPS-2026-1234');
    });

    test('el expediente es el mismo secuencial, con el mismo ancho', function (): void {
        expect($this->correlativos->expediente(1))->toBe('0001')
            ->and($this->correlativos->expediente(65))->toBe('0065');
    });

    /*
    | Un secuencial mas largo que el ancho configurado NO se recorta: se
    | escribe entero. Recortarlo produciria dos contratos con el mismo
    | numero impreso, que es exactamente lo que esta tabla existe para
    | evitar.
    */
    test('un secuencial mas largo que el ancho no se recorta', function (): void {
        expect($this->correlativos->expediente(123456))->toBe('123456');
    });

    test('un proyecto sin codigo no puede numerar', function (): void {
        expect(fn (): string => $this->correlativos->numeroDeContrato(
            Proyecto::factory()->make(['codigo' => null]),
            1,
            2026,
        ))->toThrow(CorrelativoInvalidoException::class);
    });
});
