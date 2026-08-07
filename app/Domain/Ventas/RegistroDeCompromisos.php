<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Exceptions\CompromisoInvalidoException;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Recibo;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * Los tres movimientos que puede sufrir un lote: apartarse, venderse y
 * liberarse.
 *
 * Cada uno hace DOS cosas dentro de la misma transaccion: deja el registro
 * del compromiso y mueve el estado del lote. Nunca una sin la otra — un
 * lote apartado sin saber de quien es exactamente el problema que esta
 * tabla vino a resolver.
 *
 * El area, el precio y el valor se CONGELAN en el compromiso al momento de
 * crearlo (§8.2). Despues de eso, el lote puede cambiar de precio sin que
 * la venta cerrada se entere.
 *
 * Se congelan DOS precios y no uno: `precio_vara_lista` es lo que el lote
 * valia ese dia y `precio_vara` es lo que se firmo. En un apartado son el
 * mismo numero; en una venta pueden no serlo, porque el precio se negocia
 * caso por caso (R4). Guardar solo el pactado haria imposible saber
 * despues cuanto se descontó, porque el precio de lista del lote cambia.
 *
 * ═══ Y ADEMAS NUMERA ═══
 *
 * Apartar cobra: la seña de R14 es dinero que entra, y desde el 6-ago-2026
 * sale con su recibo. Por eso este Service recibe el correlativo — el
 * numero se quema DENTRO de la misma transaccion que crea el compromiso,
 * asi que un apartado que se cae no deja un hueco en la serie.
 */
final readonly class RegistroDeCompromisos
{
    public function __construct(private ConsumoDeCorrelativos $correlativos) {}

    /**
     * Aparta un lote disponible a nombre de un cliente.
     *
     * ═══ LA SEÑA SE LLEVA SU PAPEL ═══
     *
     * Hasta ahora el cliente entregaba L 5,000.00 y se iba con las manos
     * vacias: el monto quedaba en `monto_senia` y nada mas. Ahora, si hay
     * seña, se emite un recibo de concepto `senia` colgado de ESTE
     * compromiso — que es lo que despues deja reconocer esa plata como parte
     * de la prima al firmar (R14).
     *
     * Sin seña no hay recibo, y no es un olvido: el CHECK
     * `recibos_monto_positivo_chk` no admite un recibo de L 0.00, y un papel
     * por cero no le sirve a nadie.
     *
     * Con seña, la forma de pago es OBLIGATORIA. No se asume efectivo: R11
     * pide saber como entro cada lempira, y adivinarlo es exactamente lo que
     * despues no deja cruzar el recibo contra el banco.
     *
     * @throws CompromisoInvalidoException
     */
    public function apartar(
        Lote $lote,
        Cliente $cliente,
        ?string $montoSenia = null,
        ?string $venceEl = null,
        ?string $observaciones = null,
        ?FormaDePago $forma = null,
        ?string $referencia = null,
    ): Compromiso {
        $estado = $this->estadoDe($lote);
        $codigo = $this->codigoDe($lote);

        if ($estado !== EstadoLote::Disponible) {
            throw CompromisoInvalidoException::porLoteNoDisponible($codigo, $estado->etiqueta());
        }

        // Monto valida el tipo y rechaza negativos; de paso deja el string
        // con los dos decimales de la columna.
        $senia = $montoSenia === null ? null : new Monto($montoSenia);
        $limpia = trim($referencia ?? '');

        // Antes de abrir la transaccion: una llamada incompleta no tiene por
        // que llegar a tocar la base.
        $this->verificarLaSenia($codigo, $senia, $forma, $limpia);

        return DB::transaction(function () use (
            $lote,
            $cliente,
            $senia,
            $venceEl,
            $observaciones,
            $forma,
            $limpia
        ): Compromiso {
            $compromiso = $this->crear($lote, $cliente, TipoCompromiso::Apartado, [
                'monto_senia'   => $senia?->redondeado(),
                'vence_el'      => $venceEl,
                'observaciones' => $observaciones,
            ]);

            $lote->update(['estado' => EstadoLote::Apartado]);

            /*
             * El numero es lo ULTIMO que se quema: para cuando se pide, el
             * compromiso ya existe y el lote ya se movio.
             *
             * Los tres `instanceof` se repiten aca aunque `verificarLaSenia()`
             * ya los haya mirado: el analisis estatico no cruza el borde de un
             * closure, y de paso el metodo queda leible por si solo.
             */
            if ($senia instanceof Monto && ! $senia->esCero() && $forma instanceof FormaDePago) {
                $this->emitirLaSenia($compromiso, $cliente, $senia, $forma, $limpia, $observaciones);
            }

            return $compromiso;
        });
    }

    /**
     * Aparta VARIOS lotes de una sola vez, al mismo cliente.
     *
     * ═══ POR QUE NO ES UN FOREACH EN LA PANTALLA ═══
     *
     * Cada lote sigue teniendo SU compromiso —su seña, su vencimiento, su
     * historial— porque un apartado es de un lote y no de un grupo. Pero el
     * gesto es uno solo: la persona aparta los tres o no aparta ninguno. Si
     * el segundo se lo llevaron mientras se armaba la pantalla, dejar el
     * primero apartado y el tercero libre es dejar a medias algo que nadie
     * pidio a medias.
     *
     * Por eso la transaccion vive ACA y no en el llamador, y los lotes se
     * releen con FOR UPDATE adentro: lo que decia la pantalla no vale.
     * Es la misma decision que toma RegistroDeVentas::activar().
     *
     * OJO con la seña: es POR LOTE. Apartar tres lotes con la seña de R14
     * son tres compromisos de L 5,000.00, no L 5,000.00 repartidos. Al
     * firmar, los tres cuentan como parte de la prima.
     *
     * Y por lo tanto son TRES RECIBOS, cada uno con su numero. Es lo que
     * pidio la contratante: el papel es del lote, y el dia que uno de los
     * tres se libere hay que devolver una seña y no un tercio de otra cosa.
     *
     * La referencia, en cambio, puede ser la misma en los tres — una sola
     * transferencia que cubre las tres señas es un caso normal, y por eso
     * `recibos.referencia` no lleva indice unico.
     *
     * @param list<Lote> $lotes
     *
     * @return list<Compromiso>
     *
     * @throws CompromisoInvalidoException
     */
    public function apartarVarios(
        array $lotes,
        Cliente $cliente,
        ?string $montoSenia = null,
        ?string $venceEl = null,
        ?string $observaciones = null,
        ?FormaDePago $forma = null,
        ?string $referencia = null,
    ): array {
        if ($lotes === []) {
            return [];
        }

        $ids = array_map(static fn (Lote $lote): int => (int) $lote->getKey(), $lotes);

        return DB::transaction(function () use (
            $ids,
            $cliente,
            $montoSenia,
            $venceEl,
            $observaciones,
            $forma,
            $referencia
        ): array {
            $frescos = Lote::query()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->orderBy('codigo')
                ->get();

            $compromisos = [];

            foreach ($frescos as $lote) {
                $compromisos[] = $this->apartar(
                    $lote,
                    $cliente,
                    $montoSenia,
                    $venceEl,
                    $observaciones,
                    $forma,
                    $referencia,
                );
            }

            return $compromisos;
        });
    }

    /**
     * Vende un lote, venga de estar disponible o de estar apartado.
     *
     * Si venia apartado, el apartado se cierra como CONVERTIDO y tiene que
     * ser del mismo cliente: venderle a otro por encima de un apartado
     * vigente es, casi siempre, un error de quien carga.
     *
     * `$venta` es opcional y va al final a proposito, por dos razones:
     *
     * 1. Los lotes que ya estaban vendidos ANTES de que existiera el
     *    sistema se cargan sin expediente: tienen dueno y valor, pero no
     *    prima ni plan de cuotas (R15, los datos llegan en papel).
     * 2. Al final no rompe a ningun llamador posicional que ya exista.
     *
     * Cuando la venta viene, la pasa `RegistroDeVentas::activar()` desde
     * adentro de su transaccion, y el compromiso queda ligado a su
     * expediente.
     *
     * ═══ EL PRECIO PACTADO ═══
     *
     * `$precioVara` es lo que se firmo, cuando no es el precio de lista. Si
     * viene null se usa el del lote, que es el caso normal. Si viene y es
     * MENOR que el de lista, R4 exige motivo escrito y aca se rechaza sin
     * motivo — antes de que lo rechace el CHECK de la base, para que quien
     * esta atendiendo lea una frase y no una violacion de constraint.
     *
     * El `valor` se recalcula: es area × precio pactado, no el valor que
     * traia el lote. Si no, el descuento quedaria en el precio pero no en
     * el total, que es el numero que va al contrato.
     *
     * ═══ EL PLAZO Y LA PRIMA TAMBIEN SON DEL RENGLON ═══
     *
     * Desde el 5-ago-2026 cada lote de un contrato puede ir a su propio
     * plazo. Los dos llegan de `RegistroDeVentas`, que es quien reparte la
     * prima del contrato entre los lotes y arma un plan de cuotas por cada
     * uno. Vendiendo un lote suelto van en null: no hay plan que armar.
     */
    public function vender(
        Lote $lote,
        Cliente $cliente,
        ?string $observaciones = null,
        ?Venta $venta = null,
        ?Monto $precioVara = null,
        ?string $motivoDescuento = null,
        ?Monto $precioVaraLista = null,
        ?int $plazoMeses = null,
        ?Monto $prima = null,
    ): Compromiso {
        $estado = $this->estadoDe($lote);
        $codigo = $this->codigoDe($lote);

        if ($estado === EstadoLote::Vendido) {
            throw CompromisoInvalidoException::porLoteYaVendido($codigo);
        }

        if ($estado === EstadoLote::Cancelado) {
            throw CompromisoInvalidoException::porLoteCancelado($codigo);
        }

        $vigente = $this->vigenteDe($lote);

        if ($estado === EstadoLote::Apartado) {
            if (! $vigente instanceof Compromiso) {
                throw CompromisoInvalidoException::porFaltarCompromisoVigente($codigo, $estado->etiqueta());
            }

            if ((int) $vigente->getAttribute('cliente_id') !== (int) $cliente->getKey()) {
                throw CompromisoInvalidoException::porClienteDistinto(
                    $codigo,
                    (string) $vigente->cliente()->value('nombre')
                );
            }
        }

        /*
         * El precio de lista puede venir de afuera: cuando la venta se firma
         * con un PLAN, la lista es la del plazo elegido y no la de la ficha
         * del lote. Sin esto, el precio de contado de la lista contaria como
         * descuento y pediria motivo (R4) sin haberlo.
         *
         * Vendiendo un lote suelto —sin expediente— no hay plazo del que
         * hablar, y manda el del lote.
         */
        $lista = $precioVaraLista ?? new Monto($this->decimalDe($lote, 'precio_vara'));
        $pactado = $precioVara ?? $lista;
        $motivo = trim($motivoDescuento ?? '');

        if (PrecioPactado::exigeMotivo($lista, $pactado, $motivo)) {
            throw CompromisoInvalidoException::porDescuentoSinMotivo($codigo, $lista, $pactado);
        }

        return DB::transaction(function () use (
            $lote,
            $cliente,
            $observaciones,
            $vigente,
            $venta,
            $lista,
            $pactado,
            $motivo,
            $plazoMeses,
            $prima
        ): Compromiso {
            /*
             * El apartado se cierra ANTES de crear la venta. El indice
             * unico parcial solo admite un compromiso vigente por lote, y
             * se verifica en el momento del insert: al reves, la venta
             * chocaria contra el apartado que todavia esta abierto.
             */
            $vigente?->update([
                'estado'     => EstadoCompromiso::Convertido,
                'cerrado_el' => today(),
            ]);

            $compromiso = $this->crear($lote, $cliente, TipoCompromiso::Venta, [
                'observaciones'     => $observaciones,
                'venta_id'          => $venta?->getKey(),
                'precio_vara_lista' => $lista->redondeado(),
                'precio_vara'       => $pactado->redondeado(),
                'valor'             => $this->valorDe($lote, $pactado),
                /*
                 * El plazo y la prima DE ESTE LOTE. Un contrato puede llevar
                 * el primero a 12 meses y el tercero a 48, asi que el plan de
                 * cuotas ya no es del expediente sino del renglon.
                 *
                 * Null en un lote suelto —sin expediente— y en los apartados:
                 * ahi no hay plazo del que hablar todavia.
                 */
                'plazo_meses'      => $plazoMeses,
                'prima'            => $prima?->redondeado(),
                'motivo_descuento' => $motivo === '' ? null : $motivo,
            ]);

            $lote->update(['estado' => EstadoLote::Vendido]);

            return $compromiso;
        });
    }

    /**
     * Suelta un apartado y devuelve el lote a disponible.
     */
    public function liberar(Lote $lote, string $motivo): Compromiso
    {
        $estado = $this->estadoDe($lote);
        $codigo = $this->codigoDe($lote);
        $vigente = $this->vigenteDe($lote);

        if (! $vigente instanceof Compromiso) {
            throw CompromisoInvalidoException::porFaltarCompromisoVigente($codigo, $estado->etiqueta());
        }

        $tipo = $vigente->getAttribute('tipo');

        if (! $tipo instanceof TipoCompromiso || ! $tipo->seLibera()) {
            throw CompromisoInvalidoException::porVentaNoSeLibera($codigo);
        }

        return DB::transaction(function () use ($lote, $vigente, $motivo): Compromiso {
            $vigente->update([
                'estado'     => EstadoCompromiso::Liberado,
                'cerrado_el' => today(),
                'motivo'     => $motivo,
            ]);

            $lote->update(['estado' => EstadoLote::Disponible]);

            return $vigente;
        });
    }

    /**
     * Le da a un apartado los dias de prorroga de R14. UNA sola vez.
     *
     * ═══ DESDE CUANDO CORREN LOS QUINCE DIAS ═══
     *
     * Desde el vencimiento si todavia no llego, y desde HOY si ya paso. Es
     * la unica lectura que no le regala menos de lo prometido a nadie: un
     * apartado que vencio hace diez dias y se prorroga «desde su
     * vencimiento» le dejaria al cliente cinco dias, no quince, y el que
     * autorizo la prorroga creyo estar dando quince.
     *
     * ═══ EL MOTIVO VA A OBSERVACIONES Y NO A `motivo` ═══
     *
     * `motivo` es del cierre: lo escribe `liberar()` para decir por que se
     * solto el lote. Si la prorroga lo pisara, la liberacion posterior
     * borraria el rastro de la prorroga, o al reves. Van a campos distintos
     * porque son dos decisiones distintas, y las dos tienen que sobrevivir.
     *
     * @throws CompromisoInvalidoException
     */
    public function prorrogar(Compromiso $apartado, string $motivo): Compromiso
    {
        $codigo = (string) $apartado->lote()->value('codigo');
        $tipo = $apartado->getAttribute('tipo');
        $estado = $apartado->getAttribute('estado');

        if (! $tipo instanceof TipoCompromiso || $tipo !== TipoCompromiso::Apartado) {
            throw CompromisoInvalidoException::porProrrogarLoQueNoEsApartado($codigo);
        }

        if (! $apartado->estaVigente()) {
            $etiqueta = $estado instanceof EstadoCompromiso ? $estado->etiqueta() : 'cerrado';

            throw CompromisoInvalidoException::porProrrogarUnApartadoCerrado($codigo, $etiqueta);
        }

        $maximas = Compromiso::prorrogasMaximas();
        $usadas = $apartado->prorrogasUsadas();

        if ($usadas >= $maximas) {
            throw CompromisoInvalidoException::porProrrogaAgotada($codigo, $usadas, $maximas);
        }

        $vence = $apartado->getAttribute('vence_el');

        if ($vence === null) {
            throw CompromisoInvalidoException::porProrrogarSinVencimiento($codigo);
        }

        $limpio = trim($motivo);

        if ($limpio === '') {
            throw CompromisoInvalidoException::porProrrogaSinMotivo($codigo);
        }

        return DB::transaction(function () use ($apartado, $vence, $usadas, $limpio): Compromiso {
            $desde = $vence->lessThan(today()) ? today() : $vence;
            $nuevo = $desde->copy()->addDays($this->diasDeProrroga());

            $apartado->update([
                'vence_el'      => $nuevo,
                'prorrogas'     => $usadas + 1,
                'observaciones' => $this->anotar(
                    $apartado,
                    sprintf('Prorroga al %s: %s', $nuevo->format('d/m/Y'), $limpio),
                ),
            ]);

            return $apartado;
        });
    }

    /**
     * Marca que la seña de un apartado caido ya se le devolvio al cliente.
     *
     * ═══ POR QUE ESTO NO ES UN EGRESO ═══
     *
     * Porque todavia no hay modulo de egresos, y se decidio el 6-ago-2026
     * dejarlo para despues. Lo que esto resuelve es mas chico y mas urgente:
     * que la lista de «plata que hay que devolver» se pueda vaciar. Una
     * lista que no se vacia se deja de mirar a la semana, y entonces R14
     * queda escrita en el contrato y en ningun otro lado.
     *
     * Cuando exista el egreso con su comprobante, este metodo pasa a
     * llamarlo y la fecha se sigue escribiendo igual.
     *
     * @throws CompromisoInvalidoException
     */
    public function devolverLaSenia(Compromiso $apartado, ?string $observacion = null): Compromiso
    {
        if (! $apartado->seniaPorDevolver() instanceof Monto) {
            throw CompromisoInvalidoException::porDevolverLoQueNoSeDebe(
                (string) $apartado->lote()->value('codigo')
            );
        }

        $limpia = trim($observacion ?? '');

        return DB::transaction(function () use ($apartado, $limpia): Compromiso {
            $hoy = today();

            $apartado->update([
                'senia_devuelta_el' => $hoy,
                'observaciones'     => $this->anotar(
                    $apartado,
                    sprintf(
                        'Seña devuelta el %s%s',
                        $hoy->format('d/m/Y'),
                        $limpia === '' ? '.' : ': '.$limpia,
                    ),
                ),
            ]);

            return $apartado;
        });
    }

    /**
     * El compromiso que hoy ocupa el lote, si hay alguno.
     */
    public function vigenteDe(Lote $lote): ?Compromiso
    {
        return Compromiso::query()
            ->where('lote_id', $lote->getKey())
            ->vigentes()
            ->first();
    }

    /**
     * Agrega un renglon al historial escrito del compromiso, sin pisar lo
     * que ya habia.
     *
     * Las prorrogas y las devoluciones son pocas y se leen en orden; un
     * renglon por linea alcanza y se entiende sin abrir la bitacora.
     */
    private function anotar(Compromiso $compromiso, string $renglon): string
    {
        $previas = $compromiso->getAttribute('observaciones');
        $texto = is_string($previas) ? trim($previas) : '';

        return $texto === '' ? $renglon : $texto."\n".$renglon;
    }

    private function diasDeProrroga(): int
    {
        $dias = config('lotificadora.apartados.dias_de_prorroga', 15);

        return is_int($dias) && $dias > 0 ? $dias : 15;
    }

    /**
     * Lo que se puede rechazar sin tocar la base.
     *
     * Sin seña no hay nada que verificar: apartar sin adelanto es legitimo
     * —pasa cuando el cliente vuelve al dia siguiente con la plata— y no
     * emite recibo.
     *
     * @throws CompromisoInvalidoException
     */
    private function verificarLaSenia(
        string $codigo,
        ?Monto $senia,
        ?FormaDePago $forma,
        string $referencia,
    ): void {
        if (! $senia instanceof Monto || $senia->esCero()) {
            return;
        }

        if (! $forma instanceof FormaDePago) {
            throw CompromisoInvalidoException::porSeniaSinFormaDePago($codigo, $senia);
        }

        if ($forma->exigeReferencia() && $referencia === '') {
            throw CompromisoInvalidoException::porSeniaSinReferencia($codigo, $forma);
        }
    }

    /**
     * El recibo de la seña, con su numero de la serie unica (R12).
     *
     * Cuelga del COMPROMISO y no de una venta, porque al apartar todavia no
     * hay expediente. El CHECK `recibos_cuelgan_de_un_compromiso_chk` se
     * conforma con eso: lo que no admite es un recibo que no cuelgue de
     * ninguno de los dos (R13).
     *
     * La fecha es la del compromiso y no `today()` otra vez: son el mismo
     * hecho, y dos llamadas a `today()` a los dos lados de la medianoche
     * dejarian un recibo fechado un dia despues del apartado que documenta.
     *
     * La referencia vacia se guarda como null y no como cadena vacia: el
     * CHECK de R11 mira `btrim(referencia) <> ''`, asi que un espacio en
     * blanco no cuenta como referencia.
     */
    private function emitirLaSenia(
        Compromiso $compromiso,
        Cliente $cliente,
        Monto $senia,
        FormaDePago $forma,
        string $referencia,
        ?string $observaciones,
    ): void {
        Recibo::query()->create([
            'numero'        => $this->correlativos->siguienteDeReciboInterno(),
            'compromiso_id' => $compromiso->getKey(),
            'cliente_id'    => $cliente->getKey(),
            'concepto'      => ConceptoDeRecibo::Senia,
            'forma_pago'    => $forma,
            'referencia'    => $referencia === '' ? null : $referencia,
            'monto'         => $senia->redondeado(),
            'fecha'         => $compromiso->getAttribute('fecha'),
            'observaciones' => $observaciones,
        ]);
    }

    /**
     * Los defaults valen para un apartado, que siempre va al precio de
     * lista. `vender()` pisa precio y valor cuando hubo negociacion.
     *
     * @param array<string, mixed> $extra
     */
    private function crear(Lote $lote, Cliente $cliente, TipoCompromiso $tipo, array $extra): Compromiso
    {
        return Compromiso::query()->create(array_merge([
            'proyecto_id' => $lote->getAttribute('proyecto_id'),
            'lote_id'     => $lote->getKey(),
            'cliente_id'  => $cliente->getKey(),
            'tipo'        => $tipo,
            'estado'      => EstadoCompromiso::Vigente,
            // Copias, no referencias: es lo que congela el §8.2.
            'area_varas'  => $lote->getAttribute('area_varas'),
            'precio_vara' => $lote->getAttribute('precio_vara'),
            // El de lista del dia, para poder medir el descuento despues:
            // el del lote cambia y este ya no.
            'precio_vara_lista' => $lote->getAttribute('precio_vara'),
            'valor'             => $lote->getAttribute('valor'),
            'fecha'             => today(),
        ], $extra));
    }

    /**
     * area × precio, redondeado a dos como la columna.
     *
     * Es la misma cuenta que hace `Lote::calcularValor()` y la misma que
     * exige el CHECK `valor = ROUND(area_varas * precio_vara, 2)`. Va por
     * Monto —bcmath— y nunca por float (§8.3.1).
     */
    private function valorDe(Lote $lote, Monto $precioVara): string
    {
        return $precioVara->multiplicarPor($this->decimalDe($lote, 'area_varas'))->redondeado();
    }

    /**
     * Un decimal del lote como string, que es lo unico que Monto acepta.
     *
     * Las columnas NUMERIC de Postgres llegan como string, pero un cast o
     * un factory podrian dejar un int. Lo que no puede pasar es que entre
     * un float al camino del dinero.
     */
    private function decimalDe(Lote $lote, string $campo): string
    {
        $valor = $lote->getAttribute($campo);

        return is_string($valor) || is_int($valor) ? (string) $valor : '0';
    }

    private function estadoDe(Lote $lote): EstadoLote
    {
        $estado = $lote->getAttribute('estado');

        return $estado instanceof EstadoLote ? $estado : EstadoLote::Disponible;
    }

    private function codigoDe(Lote $lote): string
    {
        return (string) $lote->getAttribute('codigo');
    }
}
