<?php

declare(strict_types=1);

use App\Domain\Exceptions\Foto360InvalidaException;
use App\Domain\Plano\Foto360;
use App\Domain\Plano\PlanoPublico;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| La foto 360 del lote
|--------------------------------------------------------------------------
|
| Lo que se cuida acá es el PESO. Una cámara 360 entrega 6000×3000 y quince
| megas; si eso llega tal cual a la página pública, el cliente con datos
| móviles cierra la pestaña antes de ver el terreno. Y son 301 lotes.
|
| El otro cuidado es 4096: no es un número elegido a ojo, es el techo de
| textura que garantiza WebGL. Por encima hay teléfonos donde la esfera
| simplemente no se dibuja —sin error visible—, así que si alguien sube ese
| límite «para que se vea mejor», rompe la función en medio Honduras.
|
*/

/**
 * Un equirectangular de mentira, con la proporción que importa.
 */
function equirectangular(int $ancho, int $alto): string
{
    $imagen = imagecreatetruecolor(max(1, $ancho), max(1, $alto));

    $celeste = imagecolorallocate($imagen, 120, 170, 220);
    imagefill($imagen, 0, 0, $celeste === false ? 0 : $celeste);

    ob_start();
    imagejpeg($imagen, null, 85);
    $binario = (string) ob_get_clean();

    $ruta = tempnam(sys_get_temp_dir(), 'e360').'.jpg';
    file_put_contents($ruta, $binario);

    return $ruta;
}

/**
 * Como la de arriba pero llena de detalle fino: un color plano se comprime a
 * nada y no probaria el presupuesto de peso.
 */
function ruidosa(int $ancho, int $alto): string
{
    $imagen = imagecreatetruecolor(max(1, $ancho), max(1, $alto));

    for ($y = 0; $y < $alto; $y += 2) {
        for ($x = 0; $x < $ancho; $x += 2) {
            $color = imagecolorallocate($imagen, ($x * 7) % 256, ($y * 13) % 256, ($x * $y) % 256);
            imagefilledrectangle($imagen, $x, $y, $x + 1, $y + 1, $color === false ? 0 : $color);
        }
    }

    ob_start();
    imagejpeg($imagen, null, 95);
    $binario = (string) ob_get_clean();

    $ruta = tempnam(sys_get_temp_dir(), 'r360').'.jpg';
    file_put_contents($ruta, $binario);

    return $ruta;
}

beforeEach(function (): void {
    Storage::fake('public');

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS', 'slug' => 'praderas-del-sol', 'plano_publico' => true]);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $this->proyecto = $proyecto;
    $this->lote = Lote::factory()
        ->enBloque($bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => '1', 'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]]]);
});

describe('Lo que entra pesado sale liviano', function (): void {
    test('una foto de cámara queda en la medida del visor, con su miniatura al lado', function (): void {
        $ruta = (new Foto360)->guardar(equirectangular(6000, 3000), (int) $this->lote->getKey());

        $disco = Storage::disk('public');

        expect($disco->exists($ruta))->toBeTrue()
            ->and($disco->exists(Foto360::mini($ruta)))->toBeTrue();

        $medidas = getimagesizefromstring((string) $disco->get($ruta));
        $chica = getimagesizefromstring((string) $disco->get(Foto360::mini($ruta)));

        expect(is_array($medidas) ? [$medidas[0], $medidas[1]] : [])->toBe([Foto360::ANCHO, Foto360::ALTO])
            ->and(is_array($chica) ? $chica[0] : 0)->toBe(256);
    });

    /*
    | 🔴 La medida es 2:1 y el ancho tiene consecuencias fuera de este archivo.
    |
    | Si alguien la cambia, el visor tiene que acompañarla: por encima de 4096
    | hace falta WebGL 2 —una textura que no es potencia de dos no admite
    | `REPEAT` en WebGL 1— y la esfera sale NEGRA sin ningún error. El respaldo
    | vive en `subirTextura`, en `publico/plano.blade.php`.
    */
    test('la medida es equirectangular, el doble de ancha que de alta', function (): void {
        expect(Foto360::ANCHO)->toBe(6144)
            ->and(Foto360::ALTO)->toBe(Foto360::ANCHO / 2);
    });

    /*
    | 🔴 EL PRESUPUESTO ES UNA PROMESA, Y ESTA ES LA PRUEBA DE QUE SE CUMPLE.
    |
    | La imagen de este test es RUIDO: prácticamente incompresible, mucho peor
    | que cualquier fotografía real. Es a propósito — si el peor caso posible
    | entra, la foto de un lote entra seguro.
    |
    | Y fue el que encontró el hueco. La primera versión solo bajaba calidad, y
    | 19 megapíxeles de ruido no entran en 2 MB por más que se apriete: pasado
    | cierto punto deja de ser una foto con menos detalle y pasa a ser papilla.
    | Por eso ahora, cuando la calidad se agota, se resigna MEDIDA.
    |
    | Si alguna vez falla, el arreglo no es subir el techo de 2 MB: es que del
    | otro lado hay alguien en datos móviles esperando ver un terreno.
    */
    test('ninguna foto pasa de dos megas, ni siquiera una incompresible', function (): void {
        $ruta = (new Foto360)->guardar(ruidosa(6000, 3000), (int) $this->lote->getKey());

        $peso = strlen((string) Storage::disk('public')->get($ruta));
        $medidas = getimagesizefromstring((string) Storage::disk('public')->get($ruta));

        expect($peso)->toBeLessThanOrEqual(2 * 1024 * 1024)
            // Y resignó medida en vez de destruir la imagen a fuerza de
            // compresión: sigue siendo una foto, solo que más chica.
            ->and(is_array($medidas) ? $medidas[0] : 0)->toBeLessThan(Foto360::ANCHO);
    });

    test('la miniatura vive al lado de la grande, con el mismo formato', function (): void {
        expect(Foto360::mini('lotes/360/7-abcd1234.webp'))->toBe('lotes/360/7-abcd1234-mini.webp')
            ->and(Foto360::mini('lotes/360/7-abcd1234.jpg'))->toBe('lotes/360/7-abcd1234-mini.jpg');
    });
});

describe('Lo que no sirve se rechaza antes, con un motivo', function (): void {
    /*
    | Una foto normal envuelta en la esfera sale estirada y sin ningún error,
    | que es lo peor que puede pasar: nadie se entera hasta que un cliente lo
    | ve. Se corta acá, con un mensaje que dice qué subir.
    */
    test('una foto que no es 2:1 no entra', function (): void {
        expect(fn (): string => (new Foto360)->guardar(equirectangular(1600, 1200), 1))
            ->toThrow(Foto360InvalidaException::class);

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    /*
    | Una tira de 13000×10 y no un 13000×6500 de verdad: comprobar el techo no
    | puede costar 338 MB de RAM en catorce procesos en paralelo. Vale porque
    | el ancho se revisa ANTES que la proporción — si algún día se invierte ese
    | orden, este test se cae y hay que mirarlo, no ajustarlo.
    */
    test('y una más ancha de lo que GD puede abrir sin reventar, tampoco', function (): void {
        expect(fn (): string => (new Foto360)->guardar(equirectangular(13000, 10), 1))
            ->toThrow(Foto360InvalidaException::class);
    });

    /*
    | 🔴 El techo tiene que dejar pasar la cámara que de verdad se usa. Estuvo
    | en 8192 —sacado de una lista de especificaciones de cámaras de mano— y
    | rechazó la primera foto real del proyecto: un panorama de DJI Fly de
    | 12000×6000.
    |
    | Se afirma la CONSTANTE y no se sube una foto de esa medida, porque
    | construirla son 288 MB y este archivo ya tumbó la suite una vez por
    | exactamente eso. El rechazo por encima del techo lo cubre el test de
    | arriba con una tira barata; acá solo se cuida el número.
    */
    test('el techo deja pasar el panorama de un dron', function (): void {
        expect(Foto360::ANCHO_MAXIMO)->toBeGreaterThanOrEqual(12000);
    });
});

describe('En el plano público', function (): void {
    test('el lote con foto la publica, y el que no tiene sale en null', function (): void {
        $sinFoto = resolve(PlanoPublico::class)->para($this->proyecto);

        expect($sinFoto['lotes'][0]['foto360'])->toBeNull();

        $ruta = (new Foto360)->guardar(equirectangular(6000, 3000), (int) $this->lote->getKey());
        $this->lote->update(['foto360_path' => $ruta]);

        $conFoto = resolve(PlanoPublico::class)->para($this->proyecto->fresh() ?? $this->proyecto);

        expect($conFoto['lotes'][0]['foto360'])->toBeString()
            ->and($conFoto['lotes'][0]['foto360Mini'])->toContain(Foto360::SUFIJO_MINI);
    });

    /*
    | La página tiene que abrir igual de rápido con foto y sin ella: lo que
    | viaja en el HTML es una URL, no la imagen. El visor y la foto bajan
    | recién cuando alguien toca «Ver el terreno en 360°».
    */
    test('la página no descarga la foto: solo lleva el enlace', function (): void {
        $ruta = (new Foto360)->guardar(equirectangular(6000, 3000), (int) $this->lote->getKey());
        $this->lote->update(['foto360_path' => $ruta]);

        $html = (string) $this->get(route('plano.publico', ['slug' => 'praderas-del-sol']))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('Ver el terreno en 360')
            // El <canvas> existe pero arranca vacío: sin <img> que dispare
            // una descarga al abrir la página.
            ->and($html)->toContain('visor360-lienzo')
            ->and(substr_count($html, '<img'))->toBeLessThan(3);
    });
});
