<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\ServicioDelProyecto;
use App\Domain\Ventas\CotizacionPorPlazo;
use App\Models\Proyecto;

/**
 * El plano tal como lo ve alguien que NO trabaja en la lotificadora.
 *
 * ═══ 🔴 LISTA BLANCA, NO FILTRO ═══
 *
 * `PlanoDelProyecto::para()` arma el dibujo para el panel, y su arreglo de
 * lotes trae **el nombre del comprador y el valor al que se vendio**. Esta
 * clase no lo filtra: **construye un arreglo nuevo, campo por campo**, y solo
 * copia los que estan escritos aca abajo.
 *
 * La diferencia no es de estilo. Con un filtro —«sacale estas dos claves»— el
 * dia que alguien agregue un campo al plano del panel, ese campo aparece
 * publicado y nadie se entera hasta que un cliente ve el nombre de otro. Con
 * lista blanca, un campo nuevo simplemente **no llega**: hay que venir aca y
 * escribirlo a mano.
 *
 * Por eso tampoco se pasa el modelo `Lote` a la vista. Un `$lote->valor` en
 * una plantilla publica es una consulta que nadie ve venir.
 *
 * ═══ EL PRECIO SOLO EXISTE PARA LO QUE ESTA A LA VENTA ═══
 *
 * Un lote vendido sale con su codigo, su medida y su color, y nada mas. No
 * porque el precio de lista sea secreto —esta publicado para los disponibles—
 * sino porque el precio de un lote VENDIDO es el que se pacto, y ese pudo
 * llevar descuento (R4). Publicarlo seria contarle a cada cliente nuevo que
 * al anterior se lo dejaron mas barato.
 *
 * ═══ SE MUESTRAN IGUAL, PINTADOS ═══
 *
 * Los vendidos y apartados se dibujan. Un plano donde solo se ven los libres
 * miente por omision —parece que no se vendio nada— y ademas hace que la
 * gente pregunte por lotes que ya no estan. Verlos ocupados es la mejor
 * prueba de que el proyecto se mueve.
 */
final readonly class PlanoPublico
{
    public function __construct(
        private PlanoDelProyecto $plano,
        private CotizacionPorPlazo $cotizacion,
    ) {}

    /**
     * @return array{
     *     servicios: list<array{etiqueta: string, trazo: string}>,
     *     colores: list<array{estado: string, etiqueta: string, relleno: string, borde: string, enLeyenda: bool}>,
     *     ubicacion: array{lat: string, lng: string, googleMaps: string, waze: string}|null,
     *     proyecto: array{nombre: string, municipio: string|null, departamento: string|null},
     *     viewBox: string,
     *     calco: string|null,
     *     hayGeometria: bool,
     *     esquematico: bool,
     *     disponibles: int,
     *     total: int,
     *     lotes: list<array{id: int, codigo: string, numero: string, rotulo: string, bloque: string, estado: string, etiqueta: string, color: string, puntos: string, centro: array{float, float}, area: string, areaFormateada: string, seCotiza: bool, clave: string, foto360: string|null, foto360Mini: string|null, foto360Marcas: list<array<string, mixed>>}>,
     *     calles: list<array{nombre: string|null, tipo: string, etiqueta: string, ancho: float, esArea: bool, puntos: string}>,
     *     planes: list<array{meses: int, nombre: string, tasa: string|null}>,
     *     precios: array<string, array<int, array{valor: string, cuota: string|null, total: string, interes: string|null}>>
     * }
     */
    public function para(Proyecto $proyecto): array
    {
        $completo = $this->plano->para($proyecto);

        $lotes = [];
        $areasACotizar = [];
        $disponibles = 0;

        foreach ($completo['lotes'] as $lote) {
            $estaLibre = $lote['estado'] === EstadoLote::Disponible->value;

            if ($estaLibre) {
                $disponibles++;
                $areasACotizar[] = $lote['areaVaras'];
            }

            /*
             * ⚠️ Acá se escribe el arreglo público, clave por clave. Lo que no
             * esté en esta lista NO sale de la lotificadora. En particular
             * `cliente` y `valor`, que sí vienen en `$lote`.
             */
            $lotes[] = [
                'id'             => $lote['id'],
                'codigo'         => $lote['codigo'],
                'numero'         => $lote['numero'],
                'rotulo'         => $lote['rotulo'],
                'bloque'         => $lote['bloque'],
                'estado'         => $lote['estado'],
                'etiqueta'       => $lote['etiqueta'],
                'color'          => $lote['color'],
                'puntos'         => $lote['puntos'],
                'centro'         => $lote['centro'],
                'area'           => $lote['areaVaras'],
                'areaFormateada' => $this->comoArea($lote['areaVaras']),
                // Solo lo que está a la venta lleva precio. Ver el docblock.
                'seCotiza' => $estaLibre,
                'clave'    => $estaLibre ? CotizacionPorPlazo::clave($lote['areaVaras']) : '',
                /*
                 * La foto 360 SÍ sale, y también la de los lotes vendidos.
                 * Es una foto del terreno mirando alrededor: no dice a qué
                 * precio se vendió ni quién lo compró, que es lo único que
                 * esta lista blanca existe para retener. Y un plano donde los
                 * vendidos no se pueden mirar hace pensar que hay algo que
                 * esconder justo donde no lo hay.
                 */
                'foto360'     => $lote['foto360'],
                'foto360Mini' => $lote['foto360Mini'],
                /*
                 * El contorno y los rótulos, como ángulos sobre la esfera. El
                 * visor los traza como vectores en cada cuadro: nítidos a
                 * cualquier zoom, y el rótulo queda exactamente donde y como
                 * se fijó. Ver `MarcasDelLote`, que es la lista blanca de lo
                 * que cada marca puede traer.
                 */
                'foto360Marcas' => $lote['foto360Marcas'],
            ];
        }

        $cotizacion = $this->cotizacion->para($proyecto, $areasACotizar);

        return [
            'servicios' => $this->serviciosDe($proyecto),
            'colores'   => $this->colores(),
            'ubicacion' => $this->ubicacionDe($proyecto),
            'proyecto'  => [
                'nombre'       => (string) $proyecto->getAttribute('nombre'),
                'municipio'    => $this->textoOpcional($proyecto->getAttribute('municipio')),
                'departamento' => $this->textoOpcional($proyecto->getAttribute('departamento')),
            ],
            'viewBox'      => $completo['viewBox'],
            'calco'        => $completo['calco'],
            'hayGeometria' => $completo['hayGeometria'],
            'esquematico'  => $completo['esquematico'],
            'disponibles'  => $disponibles,
            'total'        => count($lotes),
            'lotes'        => $lotes,
            // Las calles no tienen nada reservado: son el dibujo de la calle.
            'calles'  => $completo['calles'],
            'planes'  => $cotizacion['planes'],
            'precios' => $cotizacion['precios'],
        ];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * «250.0000» se lee «250 vr²», y «312.5000» se lee «312.5 vr²».
     *
     * Los ceros de relleno son ruido para quien mira un lote en el teléfono:
     * hacen parecer que la medida tiene una precisión que no le importa a
     * nadie. Los decimales que sí valen se conservan.
     */
    private function comoArea(string $varas): string
    {
        $texto = $varas;

        if (str_contains($texto, '.')) {
            $texto = rtrim(rtrim($texto, '0'), '.');
        }

        return ($texto === '' ? '0' : $texto).' vr²';
    }

    /**
     * Los cuatro estados con sus dos colores, para que la pagina no los
     * escriba a mano.
     *
     * Antes vivian en el CSS de la plantilla Y en la leyenda Y en el PNG de
     * WhatsApp. Tres listas que tienen que coincidir terminan no coincidiendo:
     * ahora salen del enum, que es donde se decide.
     *
     * @return list<array{estado: string, etiqueta: string, relleno: string, borde: string, enLeyenda: bool}>
     */
    private function colores(): array
    {
        return array_map(static fn (EstadoLote $estado): array => [
            'estado'    => $estado->value,
            'etiqueta'  => $estado->etiqueta(),
            'relleno'   => $estado->relleno(),
            'borde'     => $estado->borde(),
            'enLeyenda' => $estado->enLeyendaPublica(),
        ], EstadoLote::cases());
    }

    /**
     * Donde queda, en las dos formas que abren la aplicacion y no el sitio.
     *
     * Los formatos son los que documenta cada una. Pegar en su lugar un link
     * copiado de la barra del navegador —de esos que empiezan con
     * `maps.app.goo.gl`— abre la PAGINA de Google Maps adentro del navegador
     * del telefono, que es exactamente lo que no se quiere: lo que sirve es
     * que arranque la app con la ruta puesta.
     *
     * Null cuando no hay coordenadas cargadas, y entonces la seccion entera no
     * aparece. Mandar a alguien a un punto equivocado es peor que no decirle
     * como llegar.
     *
     * @return array{lat: string, lng: string, googleMaps: string, waze: string}|null
     */
    private function ubicacionDe(Proyecto $proyecto): ?array
    {
        $lat = $proyecto->getAttribute('latitud');
        $lng = $proyecto->getAttribute('longitud');

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $par = $lat.','.$lng;

        return [
            'lat'        => (string) $lat,
            'lng'        => (string) $lng,
            'googleMaps' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($par),
            'waze'       => 'https://waze.com/ul?ll='.rawurlencode($par).'&navigate=yes',
        ];
    }

    /**
     * Los servicios marcados, ya resueltos a etiqueta e icono.
     *
     * Se resuelven ACÁ y no en la plantilla: la vista pública no tiene por
     * qué conocer el catálogo ni qué hacer con un valor que quedó guardado y
     * después se sacó del enum — eso último se descarta en silencio, que es
     * lo correcto para una vidriera.
     *
     * @return list<array{etiqueta: string, trazo: string}>
     */
    private function serviciosDe(Proyecto $proyecto): array
    {
        $guardados = $proyecto->getAttribute('servicios');

        if (! is_array($guardados)) {
            return [];
        }

        $lista = [];

        foreach ($guardados as $valor) {
            $servicio = is_string($valor) ? ServicioDelProyecto::tryFrom($valor) : null;

            if ($servicio instanceof ServicioDelProyecto) {
                $lista[] = ['etiqueta' => $servicio->etiqueta(), 'trazo' => $servicio->trazo()];
            }
        }

        return $lista;
    }

    private function textoOpcional(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = trim($valor);

        return $limpio === '' ? null : $limpio;
    }
}
