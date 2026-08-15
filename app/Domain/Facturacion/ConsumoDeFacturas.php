<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Domain\Exceptions\FacturacionInvalidaException;
use App\Models\AutorizacionDeImpresion;
use App\Models\Facturacion;
use App\Models\Proyecto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta por la que sale un número de factura del SAR.
 *
 * Hermana de ConsumoDeCorrelativos y por la misma razón: dos personas
 * cobrando al mismo tiempo desde lugares distintos no pueden llevarse el
 * mismo correlativo. Ahí el daño era un recibo repetido; acá es una factura
 * repetida ante el SAR, que es bastante peor.
 *
 * ⚠️ Mismo guard, misma razón: `lockForUpdate()` fuera de una transacción no
 * bloquea nada —Postgres suelta el lock al terminar la sentencia—, así que
 * este Service se niega a numerar fuera de una. No abre una propia a
 * propósito: el número tiene que morir junto con el cobro que lo pidió. Si el
 * cobro se cae después de numerar, el correlativo se va con él y no queda un
 * hueco que después haya que explicarle a un auditor.
 *
 * ═══ QUÉ PAPEL LE TOCA A CADA DESARROLLO ═══
 *
 * Cuatro casos, y los cuatro son decisiones de negocio, no accidentes:
 *
 *  1. Proyecto SIN facturación elegida → recibo interno. Es lo que hacía
 *     todo el sistema hasta hoy y lo que sigue haciendo el desarrollo que
 *     solo emite comprobante de caja.
 *  2. Facturación APAGADA → recibo interno. El toggle `activa` es
 *     justamente eso: «esta facturación ya no emite». Los proyectos que la
 *     tienen puesta no se tocan, vuelven al recibo.
 *  3. Facturación encendida CON autorización vigente → factura con CAI.
 *  4. Facturación encendida SIN autorización vigente → se planta. Ver
 *     FacturacionInvalidaException::porFaltarAutorizacionVigente(), que
 *     explica por qué plantarse es mejor que emitir el papel equivocado.
 */
final readonly class ConsumoDeFacturas
{
    /**
     * El número que le toca al documento de este desarrollo, o null si acá
     * se emite recibo interno.
     *
     * @throws FacturacionInvalidaException
     */
    public function paraElProyecto(?Proyecto $proyecto): ?NumeroDeFactura
    {
        /*
         * ═══ EL GUARD VA PRIMERO, ANTES DE TOCAR LA BASE ═══
         *
         * Porque es un requisito del METODO, no del resultado: quien pide un
         * numero de factura va a escribir una fila, y sin transaccion el
         * `lockForUpdate()` de mas abajo no bloquea nada —Postgres suelta el
         * lock al terminar la sentencia— y dos cobros simultaneos se llevarian
         * el mismo numero ante el SAR.
         *
         * Preguntarlo aca ahorra ademas la consulta de `facturacionDe()`
         * cuando el llamador se olvido de abrir la transaccion: falla mas
         * temprano y con el mensaje que explica que hacer.
         *
         * ⚠️ El guard NO puede vivir dentro de `facturacionDe()`: ese metodo
         * es tambien la PREVISUALIZACION del modal de cobro, que pregunta
         * «¿que papel va a salir?» fuera de toda transaccion y a proposito.
         */
        if (DB::transactionLevel() === 0) {
            throw FacturacionInvalidaException::porFaltarTransaccion();
        }

        $facturacion = $this->facturacionDe($proyecto);

        if (! $facturacion instanceof Facturacion) {
            return null;
        }

        return $this->consumir($facturacion);
    }

    /**
     * Con qué facturación emite este desarrollo HOY, releída de la base.
     *
     * ═══ 🔴 POR QUE SE RELEE Y NO SE LE PREGUNTA AL MODELO ═══
     *
     * Porque el modelo que llega puede venir a medias. El 14-ago-2026 un
     * ensayo en pantalla destapó esto: `VentasTable` carga el proyecto con
     * `'proyecto:id,nombre,codigo'` —tres columnas, sin `facturacion_id`—
     * porque la tabla no necesita más. Al cobrar DESDE LA TABLA, el
     * `belongsTo` de la facturación buscaba por una llave que no estaba en
     * memoria, devolvía null, y **el papel salía como recibo interno en
     * silencio** en un desarrollo que factura con CAI.
     *
     * Ningún test lo agarraba: en un test la venta se carga entera. Y arreglar
     * el `select` de esa tabla habría tapado ESE caso y dejado el próximo
     * —cualquier pantalla futura que acote columnas por rendimiento vuelve a
     * romperlo, y nadie relaciona una cosa con la otra—.
     *
     * Es el mismo criterio que `RegistroDeVentas::bloquearYVerificar()`: «lo
     * que decía la pantalla no vale». Una consulta más por cobro es barata;
     * entregarle a un cliente el documento equivocado, no.
     *
     * ⚠️ NO consume correlativo: se puede preguntar para PREVISUALIZAR qué
     * papel va a salir, que es justo lo que hace el modal de cobro.
     */
    public function facturacionDe(?Proyecto $proyecto): ?Facturacion
    {
        if (! $proyecto instanceof Proyecto) {
            return null;
        }

        $facturacionId = Proyecto::query()
            ->whereKey($proyecto->getKey())
            ->value('facturacion_id');

        if (! is_int($facturacionId)) {
            return null;
        }

        $facturacion = Facturacion::query()->find($facturacionId);

        if (! $facturacion instanceof Facturacion) {
            return null;
        }

        // Caso 2: apagada es «ya no emite», no «está rota».
        return (bool) $facturacion->getAttribute('activa') ? $facturacion : null;
    }

    /**
     * Bloquea las autorizaciones de esta facturación y se lleva un número.
     *
     * ═══ POR QUÉ SE BLOQUEAN TODAS Y NO SOLO LA VIGENTE ═══
     *
     * Porque cuál es «la vigente» depende de datos que otra transacción puede
     * estar cambiando ahora mismo: la que tenía un correlativo libre se acaba
     * de agotar y la que sigue pasa a ser la buena. Elegir primero y bloquear
     * después deja esa ventana abierta.
     *
     * Son un puñado de filas —una autorización por año— así que bloquearlas
     * todas es barato. El ORDER BY fijo es lo que evita el interbloqueo: dos
     * transacciones que piden las mismas filas en el mismo orden hacen cola,
     * no se traban.
     *
     * @throws FacturacionInvalidaException
     */
    private function consumir(Facturacion $facturacion): NumeroDeFactura
    {
        /*
         * Redundante con el de `paraElProyecto()`, y se queda: este metodo es
         * el que de verdad bloquea filas y quema el numero. El dia que alguien
         * le agregue otra puerta de entrada, la red ya esta puesta.
         */
        if (DB::transactionLevel() === 0) {
            throw FacturacionInvalidaException::porFaltarTransaccion();
        }

        $autorizaciones = $facturacion->autorizaciones()
            ->reorder()
            ->orderBy('fecha_limite_emision')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($autorizaciones->isEmpty()) {
            throw FacturacionInvalidaException::porFaltarAutorizacionVigente($facturacion);
        }

        /*
         * La que vence primero de las que sirven: se gasta la vieja antes de
         * estrenar la nueva, porque los correlativos que sobran al vencerse
         * se pierden. Es el mismo criterio de Facturacion::autorizacionVigente(),
         * repetido acá adentro del lock porque afuera del lock no vale.
         */
        $elegida = null;

        foreach ($autorizaciones as $autorizacion) {
            if ($autorizacion->sirveHoy()) {
                $elegida = $autorizacion;

                break;
            }
        }

        if (! $elegida instanceof AutorizacionDeImpresion) {
            throw FacturacionInvalidaException::porFaltarAutorizacionVigente($facturacion);
        }

        $correlativo = (int) $elegida->getAttribute('proximo_correlativo');

        /*
         * El UPDATE va por query builder y no por `$elegida->save()`: el
         * modelo trae `updated_by` del trait de auditoría y un save completo
         * reescribiría columnas que este cobro no tocó. Acá se mueve UNA
         * cosa —el próximo número— y se mueve sola.
         */
        DB::table('autorizaciones_de_impresion')
            ->where('id', $elegida->getKey())
            ->update(['proximo_correlativo' => $correlativo + 1, 'updated_at' => now()]);

        return new NumeroDeFactura(
            facturacionId: (int) $facturacion->getKey(),
            autorizacionId: (int) $elegida->getKey(),
            numero: $facturacion->numeroCompleto($correlativo),
            correlativo: $correlativo,
            cai: (string) $elegida->getAttribute('cai'),
            rangoDesde: (int) $elegida->getAttribute('correlativo_desde'),
            rangoHasta: (int) $elegida->getAttribute('correlativo_hasta'),
            fechaLimiteEmision: $this->fecha($elegida),
        );
    }

    /**
     * La fecha límite como inmutable, que es lo que viaja por el dominio.
     *
     * El cast `date` del modelo entrega un Carbon mutable; dejarlo pasar tal
     * cual sería regalar una referencia que cualquiera puede mover.
     */
    private function fecha(AutorizacionDeImpresion $autorizacion): CarbonImmutable
    {
        $limite = $autorizacion->getAttribute('fecha_limite_emision');

        return $limite instanceof Carbon
            ? CarbonImmutable::parse($limite->toDateString())
            : CarbonImmutable::parse(today()->toDateString());
    }
}
