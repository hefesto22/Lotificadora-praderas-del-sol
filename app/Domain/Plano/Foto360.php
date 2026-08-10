<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Exceptions\Foto360InvalidaException;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La foto 360 del lote, lista para un telefono con datos moviles.
 *
 * ═══ 🔴 POR QUE ESTO EXISTE Y NO SE GUARDA EL ARCHIVO TAL CUAL ═══
 *
 * Una camara 360 entrega un equirectangular de 6000×3000 o 6720×3360 y entre
 * 8 y 20 MB. Ese archivo en la pagina publica no es «un poco lento»: es un
 * cliente en San Pedro Sula, con datos moviles, que cierra la pestaña antes
 * de ver el terreno. Y son 301 lotes.
 *
 * Aca entra el archivo crudo y sale uno de 4096×2048 de medio mega.
 *
 * ═══ 6144 DE ANCHO Y NO MAS DE DOS MEGAS ═══
 *
 * Las dos cosas juntas, y una paga a la otra.
 *
 * Estuvo en 4096 —el minimo que la especificacion de WebGL garantiza— y se
 * veia blando: en el visor se muestra como un cuarto del panorama a lo ancho
 * de la pantalla, o sea 1024 pixeles estirados sobre 2500. Con 6144 son 1536
 * para el mismo espacio.
 *
 * Los pixeles de mas se pagan bajando la calidad de compresion, no subiendo
 * el peso, porque en foto natural MAS PIXELES A CALIDAD MEDIA se ve mejor que
 * menos pixeles muy pulidos. Y el peso no se estima: se prueba de la mejor
 * calidad hacia abajo hasta entrar en los dos megas. Ver `CALIDADES`.
 *
 * ⚠️ 6144 NO es potencia de dos, y eso importa: en WebGL 1 una textura que no
 * lo es no admite `REPEAT`, y la esfera sale NEGRA sin ningun error. El visor
 * pide WebGL 2 —que si lo admite— y al que no lo tenga le achica la foto a
 * 4096 en el navegador. Ver el comentario de `subirTextura` en la plantilla.
 *
 * ═══ DOS ARCHIVOS, Y EL CHICO ES EL QUE SE VE PRIMERO ═══
 *
 * Junto al grande se escribe uno de 256×128 —ocho kilobytes— que el visor
 * pinta borroso mientras baja el otro. La diferencia es entre «toco y no pasa
 * nada por tres segundos» y «toco y ya estoy adentro». El chico vive al lado
 * del grande con el sufijo `-mini`.
 *
 * ═══ WEBP SI SE PUEDE, JPEG SI NO ═══
 *
 * WebP pesa como un 30 % menos con la misma calidad, pero no todo servidor
 * trae GD compilado con soporte. Se pregunta y se cae a JPEG, que anda en
 * todos lados. La extension queda en la ruta guardada, asi que la pagina sirve
 * lo que haya sin preguntar nada.
 *
 * ═══ EL EQUIRECTANGULAR ES 2:1, Y SE VERIFICA ═══
 *
 * Si alguien sube una foto normal, el visor la envuelve en la esfera y sale
 * un mamarracho estirado — sin ningun error, que es lo peor que puede pasar.
 * Se rechaza antes, con un mensaje que dice que la foto tiene que venir de
 * una camara 360.
 */
final readonly class Foto360
{
    /**
     * Mas resolucion de la que WebGL GARANTIZA, y a proposito.
     *
     * El minimo que la especificacion asegura es 4096, pero cualquier telefono
     * de 2017 en adelante soporta 8192 o 16384. Quedarse en el minimo era
     * pagarle a todos el precio del aparato mas viejo: en el visor se ve como
     * un cuarto del panorama a lo ancho de la pantalla, o sea 4096÷4 = 1024
     * pixeles estirados sobre una pantalla de 2500. Se agrandaba al doble.
     *
     * Con 6144 son 1536 pixeles para el mismo espacio: 50 % mas de detalle
     * real. Y el que no llegue no se queda sin foto — el visor la achica solo
     * en el navegador antes de subirla a la GPU.
     */
    public const int ANCHO = 6144;

    public const int ALTO = 3072;

    /** La miniatura borrosa que se ve mientras baja la grande. */
    private const int ANCHO_MINI = 256;

    private const int ALTO_MINI = 128;

    public const string SUFIJO_MINI = '-mini';

    private const string CARPETA = 'lotes/360';

    /**
     * ═══ EL PESO ES UNA PROMESA, NO UNA ESTIMACION ═══
     *
     * Se prueba de la mejor calidad hacia abajo y se queda la primera que
     * entra en el presupuesto. Un lote pelado —cielo, tierra, poco detalle—
     * termina en 80 y pesa la mitad; una foto llena de hojas, que es lo que
     * de verdad hay en Praderas, baja hasta donde haga falta.
     *
     * Fijar una calidad y esperar que alcance es lo que hace que un dia
     * aparezca un lote de 4 MB y nadie se entere hasta que un cliente en
     * datos moviles cierra la pestaña.
     *
     * Para foto natural, MAS PIXELES A CALIDAD MEDIA se ve mejor que menos
     * pixeles muy pulidos: por eso el salto a 6144 se paga bajando esto y no
     * subiendo el peso.
     */
    private const int PESO_MAXIMO = 2097152;

    /** @var list<int> */
    private const array CALIDADES = [80, 72, 64, 56, 48];

    /**
     * Y si NI ASI entra, se achica.
     *
     * Bajar calidad tiene fondo: pasado cierto punto deja de ser una foto con
     * menos detalle y pasa a ser papilla con bloques. Ahi conviene resignar
     * pixeles antes que seguir apretando — es el mismo argumento de arriba
     * leido al reves.
     *
     * El ultimo de la lista es potencia de dos a proposito: si algo llega a
     * caer hasta el fondo, que caiga en la medida que WebGL 1 tambien admite.
     *
     * @var list<int>
     */
    private const array ANCHOS = [self::ANCHO, 4096];

    private const int CALIDAD_MINI = 60;

    /**
     * Mas ancho que esto no se acepta.
     *
     * GD decodifica el JPEG entero en memoria a 4 bytes por pixel: 12000×6000
     * son 288 MB, y de ahi sale el techo — no de una preferencia.
     *
     * ⚠️ Estuvo en 8192 y estaba MAL. Se eligio mirando las camaras de mano
     * (la Theta Z1 da 6720, la Insta360 X3 da 6080) sin mirar la que de verdad
     * usa Praderas: el dron. Un panorama de DJI Fly sale en 12000×6000, asi
     * que la primera foto real del proyecto se rechazo.
     *
     * La leccion no es el numero: es que el limite salio de una lista de
     * especificaciones y no del archivo que alguien iba a subir.
     */
    public const int ANCHO_MAXIMO = 12288;

    /** Un equirectangular es 2:1. Se deja aire para redondeos de camara. */
    private const float RATIO_MINIMO = 1.8;

    private const float RATIO_MAXIMO = 2.2;

    /**
     * Lo que se le presta a GD mientras dura la conversión.
     *
     * Un 8192×4096 son 134 MB solo la imagen decodificada, más el JPEG crudo
     * en memoria y el lienzo de salida. 512 alcanzaba justo; 1 GB deja aire
     * para que no explote con la foto más grande que aceptamos.
     */
    private const string MEMORIA = '1024M';

    public static function disponible(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    /**
     * Procesa el archivo subido y devuelve la ruta relativa del grande.
     *
     * El `token` en el nombre no es decoracion: sin el, reemplazar la foto de
     * un lote deja el nombre igual y el navegador del cliente sigue mostrando
     * la vieja por horas. Con el, la URL cambia y no hay cache que valga.
     *
     * @throws Foto360InvalidaException si el archivo no sirve como 360
     */
    public function guardar(string $rutaAbsoluta, int $loteId): string
    {
        if (! self::disponible()) {
            throw Foto360InvalidaException::porqueEsteServidorNoPuede();
        }

        [$ancho, $alto] = $this->medidasDe($rutaAbsoluta);

        /*
         * El préstamo de memoria cubre la conversión ENTERA y no solo el
         * `abrir()`: el original decodificado sigue vivo mientras se remuestrea
         * —288 MB en un panorama de dron— y devolver el límite antes de tiempo
         * hace que reviente al pedir el lienzo de salida, no al abrir.
         */
        $memoria = ini_get('memory_limit');
        ini_set('memory_limit', self::MEMORIA);

        try {
            return $this->convertir($rutaAbsoluta, $ancho, $alto, $loteId);
        } finally {
            if (is_string($memoria)) {
                ini_set('memory_limit', $memoria);
            }
        }
    }

    /**
     * @throws Foto360InvalidaException
     */
    private function convertir(string $rutaAbsoluta, int $ancho, int $alto, int $loteId): string
    {
        $origen = $this->abrir($rutaAbsoluta);

        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $base = self::CARPETA.'/'.$loteId.'-'.Str::lower(Str::random(8)).'.'.$extension;

        /*
         * Sin `imagedestroy` ni `try/finally`: desde PHP 8, GD devuelve un
         * objeto `GdImage` y el recolector lo libera cuando la variable sale
         * de alcance. Llamarlo a mano ya no libera nada antes, y Rector lo
         * marca (RemoveFuncCall) — que es la razón por la que este comentario
         * existe, para que nadie lo agregue de nuevo «por las dudas».
         */
        $this->escribirPrincipal($origen, $ancho, $alto, $base);
        $this->escribir($origen, $ancho, $alto, self::ANCHO_MINI, self::ALTO_MINI, [self::CALIDAD_MINI], self::mini($base), false);

        return $base;
    }

    /**
     * La ruta de la miniatura que acompaña a una foto.
     *
     * Por convencion y no por una segunda columna: las dos nacen y mueren
     * juntas, y una columna que solo puede tener un valor derivado de otra es
     * una columna que algun dia va a estar desincronizada.
     */
    public static function mini(string $ruta): string
    {
        $punto = strrpos($ruta, '.');

        return $punto === false
            ? $ruta.self::SUFIJO_MINI
            : substr($ruta, 0, $punto).self::SUFIJO_MINI.substr($ruta, $punto);
    }

    /**
     * Borra las dos. Se llama al reemplazar y al quitar la foto.
     */
    public function borrar(?string $ruta): void
    {
        if (! is_string($ruta) || trim($ruta) === '') {
            return;
        }

        $disco = Storage::disk('public');

        foreach ([$ruta, self::mini($ruta)] as $archivo) {
            if ($disco->exists($archivo)) {
                $disco->delete($archivo);
            }
        }
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @return array{int, int}
     *
     * @throws Foto360InvalidaException
     */
    private function medidasDe(string $ruta): array
    {
        // `getimagesize` lee solo el encabezado: se sabe si sirve ANTES de
        // meter veinte megas en memoria.
        $datos = @getimagesize($ruta);

        if ($datos === false) {
            throw Foto360InvalidaException::porqueNoSePuedeLeer();
        }

        [$ancho, $alto] = [$datos[0], $datos[1]];

        if ($ancho > self::ANCHO_MAXIMO) {
            throw Foto360InvalidaException::porDemasiadoAncha($ancho, self::ANCHO_MAXIMO);
        }

        if ($alto <= 0) {
            throw Foto360InvalidaException::porqueNoSePuedeLeer();
        }

        $ratio = $ancho / $alto;

        if ($ratio < self::RATIO_MINIMO || $ratio > self::RATIO_MAXIMO) {
            throw Foto360InvalidaException::porqueNoEsEquirectangular($ancho, $alto);
        }

        return [$ancho, $alto];
    }

    /**
     * @throws Foto360InvalidaException
     */
    private function abrir(string $ruta): GdImage
    {
        $crudo = @file_get_contents($ruta);
        $imagen = $crudo === false ? false : @imagecreatefromstring($crudo);

        if (! $imagen instanceof GdImage) {
            throw Foto360InvalidaException::porqueNoSePuedeLeer();
        }

        return $imagen;
    }

    /**
     * La grande: se prueba medida por medida y calidad por calidad, y se queda
     * la PRIMERA combinacion que entra en el presupuesto.
     *
     * El orden importa: primero se agota la calidad en la medida grande, y
     * recien despues se resigna medida. Asi una foto normal se queda en 6144
     * y solo lo verdaderamente incompresible baja a 4096.
     */
    private function escribirPrincipal(GdImage $origen, int $anchoOrigen, int $altoOrigen, string $destino): void
    {
        foreach (self::ANCHOS as $indice => $ancho) {
            $ultima = $indice === count(self::ANCHOS) - 1;

            if ($this->escribir($origen, $anchoOrigen, $altoOrigen, $ancho, intdiv($ancho, 2), self::CALIDADES, $destino, true, ! $ultima)) {
                return;
            }
        }
    }

    /**
     * @param list<int> $calidades de mejor a peor; se queda la primera que entra
     * @param bool $exigirPresupuesto si es false, escribe la ultima aunque se pase
     *
     * @return bool si logro escribir algo
     */
    private function escribir(
        GdImage $origen,
        int $anchoOrigen,
        int $altoOrigen,
        int $ancho,
        int $alto,
        array $calidades,
        string $destino,
        bool $afilar,
        bool $exigirPresupuesto = false,
    ): bool {
        // `max(1, ...)` no es paranoia de PHPStan: las medidas vienen de
        // constantes, pero el tipo `int<1, max>` que exige GD hay que
        // demostrarlo, y demostrarlo es más barato que discutirlo.
        $lienzo = imagecreatetruecolor(max(1, $ancho), max(1, $alto));

        /*
         * `imagecopyresampled` y no `imagecopyresized`: el segundo no
         * interpola y deja escalones en los bordes, que en una imagen que se
         * mira envuelta en una esfera se notan como costuras.
         */
        imagecopyresampled($lienzo, $origen, 0, 0, 0, 0, $ancho, $alto, $anchoOrigen, $altoOrigen);

        if ($afilar) {
            /*
             * Bajar de 12000 a 6144 ablanda por definicion: cada pixel de
             * salida promedia cuatro de entrada. Un realce leve le devuelve
             * el borde a las hojas y al filo del terreno, que es justo lo que
             * el cliente mira para entender donde termina el lote.
             *
             * Suave a proposito. Un afilado fuerte marca halos en el cielo y
             * ademas engorda el archivo: el detalle que inventa tambien hay
             * que codificarlo.
             */
            imageconvolution($lienzo, [
                [-0.1, -0.1, -0.1],
                [-0.1, 1.8, -0.1],
                [-0.1, -0.1, -0.1],
            ], 1.0, 0.0);
        }

        $webp = function_exists('imagewebp') && str_ends_with($destino, '.webp');
        $binario = '';
        $entro = false;

        foreach ($calidades as $calidad) {
            ob_start();

            if ($webp) {
                imagewebp($lienzo, null, $calidad);
            } else {
                imagejpeg($lienzo, null, $calidad);
            }

            $binario = (string) ob_get_clean();

            if (strlen($binario) <= self::PESO_MAXIMO) {
                $entro = true;

                break;
            }
        }

        // No entro y todavia queda una medida mas chica por probar: no se
        // escribe nada y decide quien llamo.
        if (! $entro && $exigirPresupuesto) {
            return false;
        }

        Storage::disk('public')->put($destino, $binario);

        return true;
    }
}
