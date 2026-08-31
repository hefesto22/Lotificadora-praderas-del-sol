<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pagos\RegistroDeImpresiones;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\MontoEnLetras;
use App\Models\BrandingSetting;
use App\Models\Facturacion;
use App\Models\Proyecto;
use App\Models\Recibo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * El recibo, listo para el papel.
 *
 * ═══ POR QUE ES HTML Y NO UN PDF ═══
 *
 * Porque lo único que tiene que pasar es que salga por la impresora de la
 * ventanilla. Un HTML con hoja de estilo de impresión hace eso sin agregar una
 * sola dependencia — y **funciona desde el teléfono**, que importa porque los
 * receptores cobran desde el celular (§14).
 *
 * ⚠️ Desde el 14-ago-2026 esta misma vista imprime FACTURAS con CAI, así que
 * ya no es cierto que no haya requisito de formato: el Acuerdo 481-2017,
 * Art. 10 pide una lista de datos concreta. Lo que sigue sin haber es
 * requisito de ARCHIVO — el SAR no exige PDF—, y el formato lo cumple el
 * bloque fiscal de la plantilla.
 *
 * La alternativa era Browsershot, que está en `composer.json` sin usarse:
 * exige Chrome headless en el VPS y falla en producción con mensajes que nadie
 * puede leer con un cliente enfrente. A cinco semanas del 11-sep eso es una
 * dependencia nueva que habría que probar en el servidor real.
 *
 * El documento no es el papel: es la fila en `recibos`, con su número (R12) y
 * su detalle de aplicación. El papel es una vista de eso, reimprimible.
 *
 * ═══ ABRIR ESTA VISTA ES IMPRIMIR ═══
 *
 * Cada visita queda anotada en `impresiones_de_recibo`, y de la segunda en
 * adelante el papel dice COPIA. Si alguien abre y cancela el diálogo del
 * navegador, la fila queda igual — y está bien: lo que se registra es que una
 * persona pidió el papel, que es la pregunta que importa cuando aparecen dos
 * con el mismo número. Para solo mirar está la ficha del recibo en el panel,
 * que no imprime nada.
 *
 * ═══ QUIEN PUEDE ═══
 *
 * La sesión y la cuenta activa las verifica `UsuarioActivoDelPanel`, que es el
 * middleware de todos los documentos; acá solo queda el permiso concreto,
 * `View:Recibo`, que es lo único que cambia de un documento a otro.
 */
final readonly class ImprimirReciboController
{
    public function __construct(private RegistroDeImpresiones $impresiones) {}

    public function __invoke(Request $request, Recibo $recibo): View
    {
        Gate::authorize('view', $recibo);

        /*
         * `venta.compromisos.lote` no estaba, y desde el 31-ago hace falta: el
         * papel de la prima nombra los lotes del CONTRATO, porque la prima no
         * toca ninguno (ver `Recibo::compromisosDelPapel()`). Sin esto, cada
         * llamada del rótulo volvía a la base.
         *
         * `recibidoPor` y `createdBy`, por el renglón «Recibido por» y por la
         * firma.
         */
        $recibo->load([
            'cliente', 'venta', 'venta.compromisos.lote', 'compromiso.lote',
            'aplicaciones.cuota.compromiso.lote', 'facturacion', 'recibidoPor', 'createdBy',
        ]);

        $saldos = $this->saldosPorLote($recibo);

        // `variosLotes`: con un solo lote el rótulo del papel va en singular
        // y el detalle no repite el código en cada renglón. Se pregunta por
        // los lotes que el papel NOMBRA —no solo los que tocó—, que es lo que
        // hace que el recibo de la prima diga «Lotes» cuando son tres.
        return view('documentos.recibo', [
            'recibo'          => $recibo,
            'impresion'       => $this->impresiones->registrar($recibo),
            'emisor'          => $this->emisorDe($recibo, $this->proyectoDe($recibo)),
            'enLetras'        => MontoEnLetras::de($recibo->montoTotal()),
            'aCapital'        => $recibo->montoACapital(),
            'variosLotes'     => $recibo->nombraVariosLotes(),
            'recibio'         => $recibo->nombreDeQuienRecibio(),
            'saldos'          => $saldos,
            'saldoTotal'      => $this->totalDe($saldos),
            'logo'            => $this->logo(),
            'logoDelProyecto' => $this->proyectoDe($recibo)?->logoUrl(),

            /*
             * Solo en la factura, y sale del RECIBO y no del proyecto: es la
             * facturacion con la que ese papel se emitio, que puede no ser la
             * que el desarrollo tiene puesta hoy.
             */
            'facturacion' => $this->facturacionDe($recibo),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El desarrollo al que pertenece este recibo: de ahí salen su logo y
     * su membrete.
     *
     * Por la venta y de ahí al proyecto. Un recibo sin venta detrás —una
     * seña de apartado— llega igual por su compromiso.
     */
    private function proyectoDe(Recibo $recibo): ?Proyecto
    {
        /*
         * `->` y no `?->` a la izquierda del `??`: el null coalescing ya
         * silencia el acceso sobre null en toda la cadena de la izquierda,
         * asi que el `?->` ahi es ruido —y PHPStan lo marca como tal
         * (nullsafe.neverNull)—. A la derecha si hace falta.
         */
        $proyecto = $recibo->compromiso->proyecto
            ?? $recibo->venta?->compromisos()->with('proyecto')->first()?->proyecto;

        return $proyecto instanceof Proyecto ? $proyecto : null;
    }

    /**
     * Quién emite, tal como sale impreso arriba del papel.
     *
     * PRIMERO la facturación del desarrollo, la config solo de respaldo.
     * Hasta el 14-ago-2026 esto era únicamente `config/lotificadora.php`,
     * que es UNO para toda la instalación: con dos urbanizaciones —cada una
     * con su nombre, sus teléfonos y su dirección impresos en su propio
     * talonario— el mismo membrete salía en los dos papeles. Lo pidió
     * Mauricio mandando la foto del talonario de Praderas.
     *
     * El respaldo no es adorno: un proyecto sin facturación elegida sigue
     * imprimiendo igual que ayer.
     *
     * @return array<string, string|null>
     */
    private function emisorDe(Recibo $recibo, ?Proyecto $proyecto): array
    {
        /*
         * 🔴 EN UNA FACTURA MANDA EL RECIBO, NO EL PROYECTO.
         *
         * El desarrollo puede haber cambiado de facturacion despues de emitir
         * —o habersela quitado— y la copia de una factura vieja tiene que
         * salir con el emisor que llevaba impreso. Es lo mismo que se congela
         * en las columnas de la CAI, por la misma razon.
         */
        $emitida = $recibo->facturacion;

        if ($recibo->esFactura() && $emitida instanceof Facturacion) {
            return $emitida->comoEmisor();
        }

        $facturacion = $proyecto?->facturacion;

        /*
         * Tres fuentes mas, en este orden y por esta razon (la primera de
         * todas es la de arriba: si es factura, manda el recibo):
         *
         *  1. La FACTURACION del desarrollo. Ahi la
         *     direccion impresa es la del establecimiento, que es la del
         *     lugar desde donde se emite — no siempre donde esta el terreno.
         *  2. El PROYECTO, cuando emite recibo interno. Su nombre, su
         *     direccion de la pestaña Ubicacion y los telefonos que se le
         *     cargaron. Lo enderezo Mauricio el 14-ago-2026: un recibo de
         *     caja no necesita pasar por una facturacion.
         *  3. La CONFIG, de respaldo. Un proyecto al que todavia no le
         *     cargaron nada sigue imprimiendo como hasta ayer.
         */
        if ($facturacion instanceof Facturacion) {
            return $facturacion->comoEmisor();
        }

        if ($proyecto instanceof Proyecto) {
            $propio = $proyecto->comoEmisor();

            // Si el proyecto no tiene ni nombre util, no vale la pena: cae
            // en la config, que al menos trae el RTN de la lotificadora.
            if ($propio['residencial'] !== null) {
                return $propio;
            }
        }

        return $this->emisor();
    }

    /**
     * La facturacion con la que salio este papel, solo si es factura.
     *
     * Da los datos que la config y el proyecto no tienen y que el Art. 10
     * exige impresos: las DOS direcciones —casa matriz y establecimiento— y
     * quien imprime el documento.
     */
    private function facturacionDe(Recibo $recibo): ?Facturacion
    {
        $facturacion = $recibo->facturacion;

        return $recibo->esFactura() && $facturacion instanceof Facturacion ? $facturacion : null;
    }

    /**
     * Los datos de la lotificadora, que es quien entrega el recibo.
     *
     * @return array<string, string|null>
     */
    private function emisor(): array
    {
        $datos = config('lotificadora.emisor');

        if (! is_array($datos)) {
            return [];
        }

        $limpio = [];

        foreach (['nombre', 'rtn', 'residencial', 'direccion', 'telefono'] as $clave) {
            $valor = $datos[$clave] ?? null;
            $limpio[$clave] = is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
        }

        return $limpio;
    }

    /**
     * Lo que le queda por pagar de CADA lote, uno por uno.
     *
     * ═══ 🔴 ANTES ERA UNO SOLO, Y EL DE VARIOS LOTES NO IMPRIMIA NINGUNO ═══
     *
     * «Que diga cuánto le queda de x lote o lotes que él tiene, ya que les
     * gusta saber cuánto les resta de pagar» — Mauricio, 27-ago-2026, mirando
     * el RPS-00000013: dos lotes cobrados en un papel, y la línea del saldo
     * sin aparecer.
     *
     * La causa: esto leía `$recibo->compromiso`, que en un cobro de varios
     * lotes queda vacío a propósito (R13). Ahora la pregunta se la hace al
     * modelo, que es donde vive esa regla.
     *
     * ═══ Y EL DE LA PRIMA TAMPOCO LO IMPRIMIA — 31-ago-2026 ═══
     *
     * «Y también que salga cuánto le queda por pagar, que cuando es recibo por
     * prima no sale» — Mauricio. Por lo mismo: la prima no toca ningún lote,
     * así que no había ninguno del cual sacar el saldo. La pregunta pasa a ser
     * `compromisosDelPapel()`: los lotes de los que el papel HABLA.
     *
     * ⚠️ UN LOTE SIN PLAN DE CUOTAS NO IMPRIME LINEA. La seña de un apartado
     * cuelga de un compromiso que todavía no tiene cuotas, y sumar cero ahí
     * daría «le queda por pagar L 0.00» a alguien que debe el lote entero. Lo
     * mismo en una venta de contado, que se paga toda al firmar. Cero cuotas
     * no es cero saldo: es que todavía no hay plan del cual hablar.
     *
     * ═══ ES EL SALDO DE HOY, Y EL PAPEL LO DICE ═══
     *
     * No se congela: el recibo acredita **lo que se recibió**, no un saldo. Una
     * copia sacada tres meses después va a mostrar otro número, y por eso la
     * línea lleva la fecha de impresión al lado — sin ella parecería que el
     * recibo cambió.
     *
     * @return list<array{codigo: string, saldo: Monto}>
     */
    private function saldosPorLote(Recibo $recibo): array
    {
        $saldos = [];

        foreach ($recibo->compromisosDelPapel() as $lote) {
            $cuotas = $lote->cuotas()->get();

            if ($cuotas->isEmpty()) {
                continue;
            }

            $saldo = Monto::cero();

            foreach ($cuotas as $cuota) {
                $saldo = $saldo->sumar($cuota->saldo());
            }

            $saldos[] = [
                'codigo' => (string) ($lote->lote?->getAttribute('codigo') ?? 'el lote'),
                'saldo'  => $saldo,
            ];
        }

        return $saldos;
    }

    /**
     * Y la suma, que es lo que el cliente pregunta cuando tiene varios.
     *
     * @param list<array{codigo: string, saldo: Monto}> $saldos
     */
    private function totalDe(array $saldos): Monto
    {
        $total = Monto::cero();

        foreach ($saldos as $renglon) {
            $total = $total->sumar($renglon['saldo']);
        }

        return $total;
    }

    /**
     * El logo del branding, solo si el archivo existe de verdad.
     *
     * Un `<img>` roto en un documento que se entrega se ve peor que no tener
     * logo, así que se comprueba antes en vez de confiar en la columna.
     */
    private function logo(): ?string
    {
        $ruta = BrandingSetting::current()->getAttribute('logo_path');

        if (! is_string($ruta) || trim($ruta) === '') {
            return null;
        }

        $disco = Storage::disk('public');

        return $disco->exists($ruta) ? $disco->url($ruta) : null;
    }
}
