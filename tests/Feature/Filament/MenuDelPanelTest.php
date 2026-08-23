<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoVenta;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Support\Menu;
use App\Models\Cuota;
use App\Models\Venta;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
// Con alias a propósito: `Resource` a secas es un tipo NATIVO de PHP y
// Pint lo baja a `resource` en el docblock, que ya no significa lo mismo.
use Filament\Resources\Resource as RecursoDeFilament;
use Illuminate\Contracts\Support\Htmlable;

/*
|--------------------------------------------------------------------------
| El menú de la izquierda — 22-ago-2026
|--------------------------------------------------------------------------
| Mauricio: «se ve muy seco, no se sabe qué es lo importante ni cómo se
| maneja».
|
| Lo que se rompe acá no revienta: se ve mal. Un recurso que se cae a un
| grupo fantasma sigue funcionando, y dos entradas con el mismo ícono
| tampoco tiran ningún error. Por eso estos tests: son cosas que nadie iba
| a notar hasta abrir el panel y mirarlo con calma.
*/

/**
 * Los recursos que SÍ salen en el menú.
 *
 * @return list<class-string<RecursoDeFilament>>
 */
function recursosDelMenu(): array
{
    /** @var list<class-string<RecursoDeFilament>> $todos */
    $todos = Filament::getPanel('admin')->getResources();

    return array_values(array_filter(
        $todos,
        static fn (string $recurso): bool => $recurso::shouldRegisterNavigation(),
    ));
}

/**
 * Los grupos tal como los declaró `AdminPanelProvider`.
 *
 * @return list<NavigationGroup>
 */
function gruposDeclarados(): array
{
    return array_values(array_filter(
        Filament::getPanel('admin')->getNavigationGroups(),
        static fn (mixed $grupo): bool => $grupo instanceof NavigationGroup,
    ));
}

/**
 * Deja en texto plano lo que Filament permite devolver de varias formas.
 *
 * `getNavigationGroup()` está declarado `string|UnitEnum|null`, y
 * `getNavigationIcon()`, `string|BackedEnum|Htmlable|null`. Acá todos los
 * recursos devuelven texto —por eso el resto del archivo se lee como se
 * lee—, pero la firma admite las otras formas y el análisis estático no
 * deja concatenar ni castear lo que nadie revisó.
 *
 * `BackedEnum` y `UnitEnum` son globales de PHP: en Pest no se importan.
 */
function enTextoPlano(string|UnitEnum|Htmlable|null $valor): ?string
{
    if ($valor === null) {
        return null;
    }

    // Primero el respaldado: `BackedEnum` extiende `UnitEnum` y al revés
    // nunca se llegaría al `value`, que es el que trae el texto útil.
    if ($valor instanceof BackedEnum) {
        return (string) $valor->value;
    }

    if ($valor instanceof UnitEnum) {
        return $valor->name;
    }

    if ($valor instanceof Htmlable) {
        return $valor->toHtml();
    }

    return $valor;
}

describe('Los grupos', function (): void {
    /*
    | 🔴 EL TEST QUE JUSTIFICA LA CLASE `Menu`
    |
    | El nombre del grupo es una LLAVE: Filament junta por texto exacto. Una
    | tilde perdida en un solo recurso y ese recurso se va SOLO a un grupo
    | nuevo al final del menú —Filament crea el que no conoce en vez de
    | quejarse—. Sin error, sin aviso, y nadie lo ve hasta abrir el panel.
    */
    test('ningún recurso del menú cae en un grupo que no existe', function (): void {
        actingAsAdmin();

        $fuera = [];

        foreach (recursosDelMenu() as $recurso) {
            $grupo = enTextoPlano($recurso::getNavigationGroup());

            if ($grupo !== null && ! in_array($grupo, Menu::grupos(), true)) {
                $fuera[] = $recurso.' → '.$grupo;
            }
        }

        expect($fuera)->toBe([]);
    });

    test('salen los cuatro, en el orden declarado', function (): void {
        actingAsAdmin();

        // El orden es por frecuencia de uso: quien atiende vive arriba.
        expect(array_map(
            static fn (NavigationGroup $grupo): ?string => $grupo->getLabel(),
            gruposDeclarados(),
        ))->toBe(Menu::grupos());
    });

    /*
    | Administración y Sistema nacen plegados para que el trabajo del día no
    | compita con nada.
    |
    | ⚠️ Es el DEFAULT y nada más: Filament guarda el estado real en el
    | `localStorage` del navegador (clave `collapsedGroups`), así que a quien
    | ya usó el panel no le cambia hasta que los pliegue una vez a mano.
    | Medido el 22-ago en la máquina de Mauricio.
    */
    test('los de fondo nacen plegados; los del día a día, nunca', function (): void {
        actingAsAdmin();

        $plegados = [];

        foreach (gruposDeclarados() as $grupo) {
            if ($grupo->isCollapsed()) {
                $plegados[] = $grupo->getLabel();
            }
        }

        expect($plegados)->toBe(Menu::deFondo());
    });
});

/*
| Dos entradas con el mismo símbolo se leen como una sola. Pasó hasta el
| 22-ago con Clientes/Usuarios y con Ventas/Facturación: cuatro de once
| entradas se veían de a pares, y era la mitad del «no se entiende».
*/
test('ningún par de entradas del menú comparte ícono', function (): void {
    actingAsAdmin();

    $porIcono = [];

    foreach (recursosDelMenu() as $recurso) {
        $clave = enTextoPlano($recurso::getNavigationIcon());

        if ($clave === null) {
            continue;
        }

        $porIcono[$clave][] = $recurso::getNavigationLabel();
    }

    expect(array_filter($porIcono, static fn (array $quienes): bool => count($quienes) > 1))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| El contador de Ventas
|--------------------------------------------------------------------------
| De todo lo que se puede contar, es lo único que le pide algo a alguien
| HOY: son los clientes a los que hay que llamar.
*/
describe('El contador de vencidos', function (): void {
    beforeEach(function (): void {
        // Una venta vigente con su número de expediente propio: `vigente()`
        // arma el número de contrato con él y dos iguales chocan.
        $this->expediente = 0;

        $this->venta = function (EstadoVenta $estado = EstadoVenta::Vigente): Venta {
            $this->expediente++;

            $venta = Venta::factory()->vigente($this->expediente)->create();

            if ($estado !== EstadoVenta::Vigente) {
                // El CHECK `ventas_cierre_segun_estado_chk` exige la fecha.
                $venta->update(['estado' => $estado, 'cerrada_el' => today()]);
            }

            return $venta;
        };
    });

    test('no sale nada cuando nadie está atrasado', function (): void {
        Cuota::factory()->deLaVenta(($this->venta)())->create(['numero' => 1]);

        expect(VentaResource::getNavigationBadge())->toBeNull();
    });

    test('cuenta EXPEDIENTES, no cuotas', function (): void {
        $venta = ($this->venta)();

        // Tres cuotas atrasadas del mismo contrato son UNA llamada.
        foreach ([1, 2, 3] as $numero) {
            Cuota::factory()->deLaVenta($venta)->vencida()->create(['numero' => $numero]);
        }

        expect(VentaResource::getNavigationBadge())->toBe('1');
    });

    test('dos expedientes atrasados son dos', function (): void {
        Cuota::factory()->deLaVenta(($this->venta)())->vencida()->create(['numero' => 1]);
        Cuota::factory()->deLaVenta(($this->venta)())->vencida()->create(['numero' => 1]);

        expect(VentaResource::getNavigationBadge())->toBe('2');
    });

    test('un expediente liquidado no debe nada', function (): void {
        Cuota::factory()->deLaVenta(($this->venta)(EstadoVenta::Liquidada))->vencida()->create(['numero' => 1]);

        expect(VentaResource::getNavigationBadge())->toBeNull();
    });

    test('la cuota saldada no cuenta, aunque haya vencido', function (): void {
        Cuota::factory()->deLaVenta(($this->venta)())->vencida()->pagada()->create(['numero' => 1]);

        expect(VentaResource::getNavigationBadge())->toBeNull();
    });

    test('sale en rojo, que es lo que lo hace mirar', function (): void {
        expect(VentaResource::getNavigationBadgeColor())->toBe('danger');
    });
});
