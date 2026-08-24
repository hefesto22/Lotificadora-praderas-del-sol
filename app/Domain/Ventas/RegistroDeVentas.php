<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\Facturacion\ConsumoDeFacturas;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Vendedor;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El momento en que una venta nace. Todo pasa en una sola transaccion.
 *
 * ═══ POR QUE NO HAY BORRADOR EN LA BASE ═══
 *
 * R5, contestada por la contratante: **la prima se paga completa y ahi se
 * firma el contrato**. No existe la venta a medias. Mientras el usuario
 * arma la venta —elige lotes, prueba plazos, mira el plan— eso vive en el
 * formulario (§10.8), no en la base. La primera vez que la venta toca
 * Postgres ya esta firmada.
 *
 * `EstadoVenta::Borrador` existe igual porque el §8.2 define esa maquina de
 * estados y el CHECK de la migracion la respeta; simplemente hoy nada la
 * produce. Un correlativo consumido por una venta que no se concreto es un
 * hueco en la serie que despues hay que explicarle a alguien.
 *
 * ═══ LOS SIETE PASOS, EN ORDEN, Y POR QUE ESE ORDEN ═══
 *
 *  1. Se bloquean los lotes y **se vuelve a mirar su estado** (§8.3.2).
 *     Entre que se armo el formulario y se apreto Guardar, otro receptor
 *     pudo apartar uno desde su computadora.
 *  2. Se congela el area de cada lote y se resuelve su PRECIO: el de lista
 *     que tiene el lote hoy, o el pactado si esta venta se negocio. Si el
 *     pactado baja del de lista sin motivo escrito, se corta aca (R4) —
 *     antes de quemar el correlativo del paso 4.
 *  3. Se arma el plan de cuotas y **se verifica que cierre exacto** antes
 *     de escribir nada.
 *  4. Se consume el correlativo — recien aca, con todo lo demas ya
 *     validado.
 *  5. Se crea la venta.
 *  6. Se venden los lotes, cada compromiso ligado a su venta.
 *  7. Se escriben las cuotas.
 *
 * Si algo falla en cualquier paso, se cae todo junto: el correlativo
 * vuelve, los lotes quedan como estaban y no queda media venta registrada.
 */
final readonly class RegistroDeVentas
{
    public function __construct(
        private ConsumoDeCorrelativos $correlativos,
        private ConsumoDeFacturas $facturas,
        private RegistroDeCompromisos $compromisos,
        private ListaDePrecios $lista,
    ) {}

    /**
     * Registra una venta firme y devuelve el expediente ya numerado.
     *
     * @param list<Lote> $lotes los lotes que entran al contrato
     * @param list<Cliente> $clientes duenos; **el primero queda como titular** (R8)
     * @param list<PrecioPactado> $precios precios negociados, para los lotes que no van al de lista
     *
     * ═══ LA FORMA DE PAGO DE LA PRIMA ═══
     *
     * Trae default y los otros montos del sistema no: la seña es opcional
     * —se puede apartar sin adelanto— y por eso ahi la forma se exige. La
     * prima no lo es. R5: **se paga completa y ahi se firma**, asi que no
     * existe la venta sin prima entrada, y efectivo es como llega en el
     * mostrador. La pantalla la pregunta igual, y la referencia se sigue
     * exigiendo en transferencia y deposito (R11).
     *
     * @throws VentaInvalidaException
     */
    public function activar(
        Proyecto $proyecto,
        array $lotes,
        array $clientes,
        Monto $prima,
        int $plazoMeses,
        int $diaPago,
        ?CarbonImmutable $fechaContrato = null,
        ?string $observaciones = null,
        array $precios = [],
        FormaDePago $formaPrima = FormaDePago::Efectivo,
        ?string $referenciaPrima = null,
        ?Vendedor $vendedor = null,
        bool $deLaCarteraVieja = false,
    ): Venta {
        $this->verificarConjuntos($proyecto, $lotes, $clientes);

        $fecha = $fechaContrato ?? CarbonImmutable::parse(today()->toDateString());
        $titular = $clientes[0];

        // Un precio de un lote que no esta en la venta no es un error del
        // usuario: es una fila que quedo en el formulario. Se ignora.
        $pactados = [];

        foreach ($precios as $precio) {
            $pactados[$precio->loteId] = $precio;
        }

        return DB::transaction(function () use (
            $proyecto,
            $lotes,
            $clientes,
            $titular,
            $prima,
            $plazoMeses,
            $diaPago,
            $fecha,
            $observaciones,
            $vendedor,
            $pactados,
            $formaPrima,
            $referenciaPrima,
            $deLaCarteraVieja
        ): Venta {
            // 1. Bloquear y re-mirar. Lo que decia la pantalla no vale.
            $frescos = $this->bloquearYVerificar($lotes, $titular);

            // 2. Congelar area y valor, AL PRECIO QUE SE FIRMA.
            $renglones = $this->congelarPrecios($proyecto, $frescos, $pactados, $plazoMeses);
            $areaTotal = $this->sumarAreas($frescos);
            $valorTotal = $this->sumarValores($renglones);

            if ($prima->mayorQue($valorTotal)) {
                throw VentaInvalidaException::porPrimaMayorAlValor($prima, $valorTotal);
            }

            /*
             * Los apartados que se van a convertir, leidos AHORA porque
             * `vender()` los cierra: despues de la conversion `vigenteDe()`
             * devuelve el compromiso de la venta y la seña queda invisible.
             *
             * R14: esa plata cuenta como parte de la prima, asi que la prima
             * no puede ser menor de lo que el cliente ya entrego.
             */
            $apartados = $this->apartadosDe($frescos);
            $senias = $this->sumarSenias($apartados);

            if ($senias->mayorQue($prima)) {
                throw VentaInvalidaException::porSeniaMayorALaPrima($senias, $prima);
            }

            $saldo = $valorTotal->restar($prima);

            /*
             * 3. La prima del contrato, repartida entre los lotes.
             *
             * Una cuota no se puede calcular sin saber cuanto se adelanto por
             * ESE lote. Si la pantalla no lo dice, se reparte en proporcion
             * al valor de cada uno.
             */
            $renglones = $this->repartirPrima($renglones, $pactados, $prima);

            /*
             * 4. UN PLAN POR LOTE, verificados ANTES de tocar la base.
             *
             * Desde el 5-ago-2026 el plan ya no es del expediente: el primer
             * lote puede ir a 12 meses y el tercero a 48. Lo que el cliente
             * paga por mes es la suma de las cuotas vivas, y baja cada vez
             * que un lote se termina de pagar.
             */
            $renglones = $this->planificar($renglones, $diaPago, $fecha);
            $contrato = $this->planDelContrato($renglones);

            /*
             * ⚠️ CAPITAL, no la suma de las cuotas. Con interes la segunda da
             * capital + intereses y toda venta financiada se rechazaria. Sin
             * interes los dos numeros son el mismo y esto compara lo que
             * comparaba antes.
             */
            if (! $contrato->totalCapital()->igualA($saldo)) {
                throw VentaInvalidaException::porPlanQueNoCierra($contrato->totalCapital(), $saldo);
            }

            // 5. Recien ahora se quema un numero.
            $secuencial = $this->correlativos->siguienteDeContrato($proyecto);

            // 6. La venta.
            $venta = Venta::query()->create([
                'proyecto_id' => $proyecto->getKey(),
                /*
                 * Quien cerro la venta. Null es lo normal —la vendio la
                 * lotificadora— y no es un hueco: ver `Vendedor`.
                 */
                'vendedor_id'       => $vendedor?->getKey(),
                'numero_expediente' => $secuencial,
                'numero_contrato'   => $this->correlativos->numeroDeContrato($proyecto, $secuencial, $fecha->year),
                'fecha_contrato'    => $fecha->toDateString(),
                'estado'            => EstadoVenta::Vigente,
                'area_total'        => $areaTotal->redondeado(4),
                'valor_total'       => $valorTotal->redondeado(),
                'prima'             => $prima->redondeado(),
                'saldo_financiar'   => $saldo->redondeado(),
                /*
                 * Con plazos mezclados estos dos cambian de significado y no
                 * de tipo: `plazo_meses` es el HORIZONTE del contrato —el
                 * plazo mas largo— y `cuota_mensual` es lo que se paga el
                 * PRIMER mes, que es el numero mas alto. Con todos los lotes
                 * al mismo plazo dan exactamente lo que daban antes.
                 *
                 * El detalle mes a mes vive en `cuotas`, que es el contrato;
                 * estos dos son el resumen que se lee en una tabla.
                 */
                'cuota_mensual' => $contrato->primeraCuota()?->redondeado(),
                'plazo_meses'   => $contrato->plazoMaximo(),
                'dia_pago'      => $diaPago,
                'observaciones' => $observaciones,
            ]);

            // 7. Los duenos, y los lotes ligados a su venta con SU plan.
            $this->asentarClientes($venta, $clientes);

            foreach ($renglones as $renglon) {
                $compromiso = $this->compromisos->vender(
                    $renglon['lote'],
                    $titular,
                    venta: $venta,
                    precioVara: $renglon['precio'],
                    motivoDescuento: $renglon['motivo'],
                    precioVaraLista: $renglon['lista'],
                    plazoMeses: $renglon['plazo'],
                    prima: $renglon['prima'],
                    tasa: $renglon['tasa'],
                    mora: $renglon['mora'],
                    tasaLista: $renglon['tasaLista'],
                    motivoTasa: $renglon['motivoTasa'],
                    titularRecibo: $renglon['titularRecibo'],
                    dniTitularRecibo: $renglon['dniTitularRecibo'],
                );

                // 8. El plan congelado (§9.D6), el de ESTE lote.
                $this->asentarCuotas($venta, $compromiso, $renglon['plan']);
            }

            // 9. El papel de la prima, por lo que el cliente pone HOY.
            $this->cobrarLaPrima(
                $venta,
                $titular,
                $prima,
                $senias,
                $formaPrima,
                $referenciaPrima,
                $fecha,
                $apartados,
                $deLaCarteraVieja,
            );

            /*
             * 10. La venta que nace pagada se cierra el mismo dia.
             *
             * Al contado la prima ES el valor: `planificar()` no dejo ni una
             * cuota y el expediente no debe nada. Sin esta linea se quedaba
             * «Vigente» —con boton de cobrar, en el conteo de contratos
             * activos y en la pestaña de vigentes— sobre un contrato saldado.
             *
             * No pregunta si fue de contado: le pregunta a la venta si le
             * queda saldo, que es la unica forma de no cerrar un contrato
             * mixto —un lote al contado y otro financiado— que si debe.
             */
            $venta->liquidarSiYaNoDebe($fecha);

            return $venta;
        });
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El recibo de la prima, por lo que el cliente entrega el dia que firma.
     *
     * ═══ POR QUE NO ES LA PRIMA ENTERA ═══
     *
     * R14: la seña del apartado cuenta como parte de la prima. Si el cliente
     * ya dejo L 5,000.00 para reservar, hoy pone la prima MENOS eso, y el
     * papel tiene que decir esa cifra —la que efectivamente entrego—. Un
     * recibo por la prima entera, sumado al de la seña, daria de mas: la
     * misma plata contada dos veces.
     *
     * Si las señas cubren la prima exacta no se emite recibo: hoy no entro
     * dinero, y el CHECK `recibos_monto_positivo_chk` tampoco admite L 0.00.
     *
     * ═══ Y POR QUE CUELGA DE LA VENTA Y NO DE UN LOTE ═══
     *
     * La prima es del CONTRATO: se pacta una sola vez aunque el expediente
     * lleve tres lotes. `repartirPrima()` la divide entre los renglones para
     * poder calcular las cuotas, pero eso es aritmetica interna — el cliente
     * pago una prima, no tres. Por eso `compromiso_id` va nulo y el CHECK de
     * R13 se conforma con el `venta_id`.
     *
     * @param list<Compromiso> $apartados
     *
     * @throws VentaInvalidaException
     */
    private function cobrarLaPrima(
        Venta $venta,
        Cliente $titular,
        Monto $prima,
        Monto $senias,
        FormaDePago $forma,
        ?string $referencia,
        CarbonImmutable $fecha,
        array $apartados,
        bool $deLaCarteraVieja = false,
    ): void {
        $aCobrar = $prima->restar($senias);
        $limpia = trim($referencia ?? '');

        if (! $aCobrar->esCero() && $forma->exigeReferencia() && $limpia === '') {
            throw VentaInvalidaException::porPrimaSinReferencia($forma);
        }

        $this->ligarLasSenias($venta, $apartados);

        if ($aCobrar->esCero()) {
            return;
        }

        $factura = $this->facturas->paraElProyecto($venta->proyecto);
        $delTalonario = $this->correlativos->paraUnReciboNuevo($venta->proyecto, $deLaCarteraVieja);

        Recibo::query()->create([
            'numero'        => $delTalonario['numero'],
            'serie'         => $delTalonario['serie'],
            'venta_id'      => $venta->getKey(),
            'cliente_id'    => $titular->getKey(),
            'concepto'      => ConceptoDeRecibo::Prima,
            'forma_pago'    => $forma,
            'referencia'    => $limpia === '' ? null : $limpia,
            'monto'         => $aCobrar->redondeado(),
            'fecha'         => $fecha->toDateString(),
            'observaciones' => $senias->esCero() ? null : sprintf(
                'Prima del contrato %s, menos %s ya recibidos en señas de apartado.',
                $prima->formateado(),
                $senias->formateado(),
            ),
            /*
             * ═══ LA FACTURA CON CAI, DESDE EL 14-AGO-2026 ═══
             *
             * Si el desarrollo tiene una facturación encendida, acá se consume
             * el correlativo del SAR y el papel sale como FACTURA. Si no, no
             * agrega nada y el papel sale como el recibo interno de siempre.
             *
             * El número interno de arriba NO se saltea en ninguno de los dos
             * casos: es el que cuadra la caja, y una serie con huecos deja de
             * servir para eso (R12).
             */
            ...($factura?->paraElRecibo() ?? []),
        ]);
    }

    /**
     * Los recibos de seña quedan colgados del expediente.
     *
     * Se les pone el `venta_id` y NO se les toca el `compromiso_id`: el papel
     * sigue siendo del apartado que lo genero —ahi nacio y ahi se devuelve si
     * el trato se cae—, pero ahora el estado de cuenta lo encuentra sin tener
     * que saber que hubo un apartado antes.
     *
     * @param list<Compromiso> $apartados
     */
    private function ligarLasSenias(Venta $venta, array $apartados): void
    {
        if ($apartados === []) {
            return;
        }

        $ids = array_map(static fn (Compromiso $apartado): int => (int) $apartado->getKey(), $apartados);

        Recibo::query()
            ->whereIn('compromiso_id', $ids)
            ->where('concepto', ConceptoDeRecibo::Senia)
            ->update(['venta_id' => $venta->getKey()]);
    }

    /**
     * Los apartados vigentes de estos lotes, antes de convertirlos.
     *
     * @param list<Lote> $lotes
     *
     * @return list<Compromiso>
     */
    private function apartadosDe(array $lotes): array
    {
        $apartados = [];

        foreach ($lotes as $lote) {
            $vigente = $this->compromisos->vigenteDe($lote);

            if (! $vigente instanceof Compromiso) {
                continue;
            }

            if ($vigente->getAttribute('tipo') !== TipoCompromiso::Apartado) {
                continue;
            }

            $apartados[] = $vigente;
        }

        return $apartados;
    }

    /**
     * Lo que el cliente ya puso en señas por estos lotes.
     *
     * Sale de `compromisos.monto_senia` y no de los recibos, a proposito: los
     * apartados que se cargaron antes de que el sistema emitiera papel tienen
     * el monto registrado y no tienen recibo, y esa plata se recibio igual.
     *
     * @param list<Compromiso> $apartados
     */
    private function sumarSenias(array $apartados): Monto
    {
        $total = Monto::cero();

        foreach ($apartados as $apartado) {
            $senia = $apartado->getAttribute('monto_senia');

            if (! is_string($senia) && ! is_int($senia)) {
                continue;
            }

            $total = $total->sumar(new Monto($senia));
        }

        return $total;
    }

    /**
     * Lo que se puede verificar sin tocar la base.
     *
     * @param list<Lote> $lotes
     * @param list<Cliente> $clientes
     *
     * @throws VentaInvalidaException
     */
    private function verificarConjuntos(Proyecto $proyecto, array $lotes, array $clientes): void
    {
        if ($lotes === []) {
            throw VentaInvalidaException::porNoTenerLotes();
        }

        if ($clientes === []) {
            throw VentaInvalidaException::porNoTenerClientes();
        }

        $vistos = [];

        foreach ($lotes as $lote) {
            $id = (int) $lote->getKey();

            if (isset($vistos[$id])) {
                throw VentaInvalidaException::porLoteRepetido($this->codigo($lote));
            }

            $vistos[$id] = true;

            if ((int) $lote->getAttribute('proyecto_id') !== (int) $proyecto->getKey()) {
                throw VentaInvalidaException::porLoteDeOtroProyecto($this->codigo($lote));
            }
        }
    }

    /**
     * Relee los lotes con `FOR UPDATE` y confirma que se pueden vender.
     *
     * El bloqueo dura hasta el final de la transaccion: si otro proceso
     * intenta apartar uno de estos lotes mientras tanto, espera.
     *
     * @param list<Lote> $lotes
     *
     * @return list<Lote>
     *
     * @throws VentaInvalidaException
     */
    private function bloquearYVerificar(array $lotes, Cliente $titular): array
    {
        $ids = array_map(static fn (Lote $lote): int => (int) $lote->getKey(), $lotes);

        /** @var list<Lote> $frescos */
        $frescos = Lote::query()
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->all();

        foreach ($frescos as $lote) {
            $estado = $lote->getAttribute('estado');
            $codigo = $this->codigo($lote);

            if ($estado === EstadoLote::Disponible) {
                continue;
            }

            if ($estado !== EstadoLote::Apartado) {
                throw VentaInvalidaException::porLoteNoDisponible(
                    $codigo,
                    $estado instanceof EstadoLote ? $estado->etiqueta() : 'desconocido',
                );
            }

            // Apartado: solo sirve si es del mismo cliente. El apartado se
            // convierte y su monto cuenta como parte de la prima (R14).
            $vigente = $this->compromisos->vigenteDe($lote);

            if (! $vigente instanceof Compromiso || (int) $vigente->getAttribute('cliente_id') !== (int) $titular->getKey()) {
                throw VentaInvalidaException::porApartadoDeOtroCliente(
                    $codigo,
                    $vigente instanceof Compromiso ? (string) $vigente->cliente()->value('nombre') : 'otra persona',
                );
            }
        }

        return $frescos;
    }

    /**
     * Resuelve el precio de cada lote y arma su renglon del contrato.
     *
     * El precio de LISTA se lee del lote recien bloqueado, no del que traia
     * el formulario: entre que se armo la pantalla y se apreto Guardar,
     * alguien pudo re-precificar el bloque entero.
     *
     * El valor del renglon es area × precio PACTADO. No se lee `lotes.valor`
     * porque ese es el valor de lista, y con un descuento serian dos
     * numeros distintos diciendo ser el mismo.
     *
     * @param list<Lote> $lotes
     * @param array<int, PrecioPactado> $pactados por id de lote
     *
     * @return list<array{lote: Lote, lista: Monto, precio: Monto, motivo: string|null, plazo: int, valor: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres, motivoTasa: string|null, mora: CondicionesDeMora, titularRecibo: string|null, dniTitularRecibo: string|null}>
     *
     * @throws VentaInvalidaException
     */
    private function congelarPrecios(Proyecto $proyecto, array $lotes, array $pactados, int $plazoMeses): array
    {
        $renglones = [];

        foreach ($lotes as $lote) {
            $id = (int) $lote->getKey();

            /*
             * El precio de lista es EL DEL PLAZO QUE SE ELIGIO, no el de la
             * ficha del lote. Si no, vender de contado a L 1,300 con el lote
             * fijado en L 1,500 contaria como descuento y pediria motivo —
             * por un precio de lista oficial.
             */
            $lista = $this->lista->deListaPara($proyecto, $lote, $plazoMeses);

            $acuerdo = $pactados[$id] ?? null;

            // `->` y no `?->`: el `??` ya absorbe el acceso sobre null, y con
            // el nullsafe delante PHPStan lo marca como redundante. En la
            // linea de abajo si hace falta, porque ahi hay una llamada.
            $precio = $acuerdo->precioVara ?? $lista;
            $motivo = $acuerdo?->motivoLimpio();

            if (PrecioPactado::exigeMotivo($lista, $precio, $motivo)) {
                throw VentaInvalidaException::porDescuentoSinMotivo($this->codigo($lote), $lista, $precio, $proyecto->unidadDeArea());
            }

            /*
             * El plan de ESE plazo, entero: de ahi salen el precio de lista,
             * la tasa y la mora, y las tres se congelan juntas en el
             * compromiso. Buscarlo una vez y pasarlo es lo que impide que
             * alguien copie el precio y se olvide de la tasa.
             */
            $plazo = $acuerdo->plazoMeses ?? $plazoMeses;
            $delPlazo = $this->lista->planParaPlazo($proyecto, $plazo);

            /*
             * ═══ Y EL PRECIO DEL DINERO, con la misma regla ═══
             *
             * La tasa de LISTA es la del plan de ese plazo; la pactada es la
             * que el vendedor negocio, o la misma si no negocio nada. Bajarla
             * regala plata igual que bajar el precio del terreno —mas de
             * L 40,000 en un lote de 250 vr² a 12 meses— asi que R4 vale para
             * las dos, y falla ACA, antes de quemar un correlativo.
             */
            $tasaLista = $delPlazo instanceof PlanDePago ? $delPlazo->tasaDeInteres() : TasaDeInteres::cero();
            $tasa = $acuerdo->tasa ?? $tasaLista;
            $motivoTasa = $acuerdo?->motivoDeTasaLimpio();

            if (PrecioPactado::exigeMotivoDeTasa($tasaLista, $tasa, $motivoTasa)) {
                throw VentaInvalidaException::porTasaSinMotivo($this->codigo($lote), $tasaLista, $tasa);
            }

            $renglones[] = [
                'lote'   => $lote,
                'lista'  => $lista,
                'precio' => $precio,
                'motivo' => $motivo,
                // El plazo de ESTE lote. Sin acuerdo propio, el del contrato:
                // es el caso normal y el unico que existia antes.
                'plazo' => $plazo,
                // La MISMA expresion que usa RegistroDeCompromisos::valorDe()
                // y que exige el CHECK de la base. Si los tres no dan el
                // mismo numero, la venta no se graba — y asi tiene que ser.
                'valor' => new Monto($precio->multiplicarPor($this->decimalDe($lote, 'area_varas'))->redondeado()),
                /*
                 * Sin plan cargado para ese plazo —la lista vacia— van en
                 * cero y sin mora, que es exactamente lo que hacia el sistema
                 * antes de que el interes existiera (R1, R2).
                 */
                'tasa'       => $tasa,
                'tasaLista'  => $tasaLista,
                'motivoTasa' => $motivoTasa,
                /*
                 * A nombre de quien salen los recibos de ESTE lote. Null es el
                 * caso normal —el dueño del expediente— y se llena cuando un
                 * grupo compra junto y firma una sola persona.
                 */
                'titularRecibo'    => $acuerdo?->titularReciboLimpio(),
                'dniTitularRecibo' => $acuerdo?->dniTitularReciboLimpio(),
                'mora'             => $delPlazo instanceof PlanDePago ? $delPlazo->condicionesDeMora() : CondicionesDeMora::ninguna(),
            ];
        }

        return $renglones;
    }

    /**
     * La prima del contrato, repartida entre los lotes.
     *
     * ═══ POR QUE HAY QUE REPARTIRLA ═══
     *
     * La cuota de un lote es (su valor − su prima) ÷ sus meses. Con un solo
     * plazo eso se podia hacer una vez para todo el contrato; con plazos
     * distintos hace falta saber cuanto se adelanto POR CADA LOTE, o no hay
     * cuota que calcular.
     *
     * Si la pantalla manda la prima de cada lote, manda eso y la suma tiene
     * que dar la del contrato: dos numeros que dicen ser el mismo y no lo
     * son dejarian un expediente que no cuadra. Si no la manda, se reparte en
     * proporcion al valor de cada lote — que es la unica regla que no le
     * cobra a un lote la prima de otro.
     *
     * ═══ EN CENTAVOS ENTEROS, Y EL ULTIMO SE LLEVA EL RESIDUO ═══
     *
     * `intdiv` trunca, asi que la suma de las partes NUNCA se pasa y el
     * ultimo se lleva exactamente lo que falta. Repartir con redondeo
     * dejaria, con muchos lotes y una prima chica, una ultima parte negativa
     * — y Monto rechaza negativos, con razon.
     *
     * @param list<array{lote: Lote, lista: Monto, precio: Monto, motivo: string|null, plazo: int, valor: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres, motivoTasa: string|null, mora: CondicionesDeMora, titularRecibo: string|null, dniTitularRecibo: string|null}> $renglones
     * @param array<int, PrecioPactado> $pactados
     *
     * @return list<array{lote: Lote, lista: Monto, precio: Monto, motivo: string|null, plazo: int, valor: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres, motivoTasa: string|null, mora: CondicionesDeMora, titularRecibo: string|null, dniTitularRecibo: string|null, prima: Monto}>
     *
     * @throws VentaInvalidaException
     */
    private function repartirPrima(array $renglones, array $pactados, Monto $prima): array
    {
        /*
         * Las primas se resuelven en un mapa aparte y la lista se arma DE UNA
         * SOLA PIEZA al final.
         *
         * No es estilo: escribir `$lista[$i]['prima'] = ...` sobre una
         * `list<array{...}>` le ensancha el tipo a una union —PHPStan no puede
         * probar que ese indice exista, asi que contempla que la asignacion
         * cree un `array{prima: Monto}` suelto— y a partir de ahi 'valor'
         * "podria no existir". El mapa es un array<int, Monto|null> y no tiene
         * forma que romper.
         */
        $propias = Monto::cero();
        $suyas = [];
        $sinPropia = [];

        foreach ($renglones as $indice => $renglon) {
            $suya = $pactados[(int) $renglon['lote']->getKey()]->prima ?? null;
            $suyas[$indice] = $suya;

            if ($suya instanceof Monto) {
                $propias = $propias->sumar($suya);

                continue;
            }

            $sinPropia[] = $indice;
        }

        if ($propias->mayorQue($prima)) {
            throw VentaInvalidaException::porPrimasQueNoSuman($propias, $prima);
        }

        $resto = $prima->restar($propias);

        if ($sinPropia === []) {
            if (! $resto->esCero()) {
                throw VentaInvalidaException::porPrimasQueNoSuman($propias, $prima);
            }
        } else {
            $repartible = Monto::cero();

            foreach ($sinPropia as $indice) {
                $repartible = $repartible->sumar($renglones[$indice]['valor']);
            }

            $restoCentavos = $resto->enCentavos();
            $repartibleCentavos = $repartible->enCentavos();
            $asignado = 0;
            $ultimo = count($sinPropia) - 1;

            foreach ($sinPropia as $puesto => $indice) {
                if ($puesto === $ultimo) {
                    $suyas[$indice] = Monto::deCentavos($restoCentavos - $asignado);

                    break;
                }

                $parte = $repartibleCentavos === 0
                    ? 0
                    : intdiv($restoCentavos * $renglones[$indice]['valor']->enCentavos(), $repartibleCentavos);

                $asignado += $parte;
                $suyas[$indice] = Monto::deCentavos($parte);
            }
        }

        $conPrima = [];

        foreach ($renglones as $indice => $renglon) {
            $conPrima[] = [
                'lote'             => $renglon['lote'],
                'lista'            => $renglon['lista'],
                'precio'           => $renglon['precio'],
                'motivo'           => $renglon['motivo'],
                'plazo'            => $renglon['plazo'],
                'valor'            => $renglon['valor'],
                'tasa'             => $renglon['tasa'],
                'tasaLista'        => $renglon['tasaLista'],
                'motivoTasa'       => $renglon['motivoTasa'],
                'mora'             => $renglon['mora'],
                'titularRecibo'    => $renglon['titularRecibo'],
                'dniTitularRecibo' => $renglon['dniTitularRecibo'],
                'prima'            => $suyas[$indice] ?? Monto::cero(),
            ];
        }

        return $conPrima;
    }

    /**
     * El plan de cuotas de cada lote, verificado antes de escribir nada.
     *
     * El mensaje del dominio se conserva pero se le antepone el codigo del
     * lote: con tres plazos distintos, «el saldo es demasiado chico para 60
     * meses» obliga a adivinar cual de los tres es.
     *
     * @param list<array{lote: Lote, lista: Monto, precio: Monto, motivo: string|null, plazo: int, valor: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres, motivoTasa: string|null, mora: CondicionesDeMora, titularRecibo: string|null, dniTitularRecibo: string|null, prima: Monto}> $renglones
     *
     * @return list<array{lote: Lote, lista: Monto, precio: Monto, motivo: string|null, plazo: int, valor: Monto, tasa: TasaDeInteres, tasaLista: TasaDeInteres, motivoTasa: string|null, mora: CondicionesDeMora, titularRecibo: string|null, dniTitularRecibo: string|null, prima: Monto, plan: PlanDeCuotas}>
     *
     * @throws VentaInvalidaException
     */
    private function planificar(array $renglones, int $diaPago, CarbonImmutable $fecha): array
    {
        $conPlan = [];

        foreach ($renglones as $renglon) {
            try {
                $plan = PlanDeCuotas::nuevo(
                    $renglon['valor'],
                    $renglon['prima'],
                    $renglon['plazo'],
                    $diaPago,
                    $fecha,
                    $renglon['tasa'],
                );
            } catch (GrupoOlympoException $error) {
                throw VentaInvalidaException::porElLote($this->codigo($renglon['lote']), $error->getMessage());
            }

            if (! $plan->cierraExacto()) {
                throw VentaInvalidaException::porPlanQueNoCierra($plan->total(), $plan->saldoFinanciado);
            }

            $conPlan[] = [
                'lote'             => $renglon['lote'],
                'lista'            => $renglon['lista'],
                'precio'           => $renglon['precio'],
                'motivo'           => $renglon['motivo'],
                'plazo'            => $renglon['plazo'],
                'valor'            => $renglon['valor'],
                'tasa'             => $renglon['tasa'],
                'tasaLista'        => $renglon['tasaLista'],
                'motivoTasa'       => $renglon['motivoTasa'],
                'mora'             => $renglon['mora'],
                'titularRecibo'    => $renglon['titularRecibo'],
                'dniTitularRecibo' => $renglon['dniTitularRecibo'],
                'prima'            => $renglon['prima'],
                'plan'             => $plan,
            ];
        }

        return $conPlan;
    }

    /**
     * Los planes de todos los lotes, vistos como un solo contrato.
     *
     * @param list<array{lote: Lote, plan: PlanDeCuotas, ...}> $renglones
     */
    private function planDelContrato(array $renglones): PlanDelContrato
    {
        return new PlanDelContrato(array_map(
            fn (array $renglon): array => [
                'etiqueta' => $this->codigo($renglon['lote']),
                'plan'     => $renglon['plan'],
            ],
            $renglones,
        ));
    }

    /**
     * @param list<Lote> $lotes
     */
    private function sumarAreas(array $lotes): Monto
    {
        $total = Monto::cero();

        foreach ($lotes as $lote) {
            $total = $total->sumar($this->montoDe($lote, 'area_varas'));
        }

        return $total;
    }

    /**
     * @param list<array{lote: Lote, lista: Monto, precio: Monto, motivo: string|null, valor: Monto}> $renglones
     */
    private function sumarValores(array $renglones): Monto
    {
        $total = Monto::cero();

        foreach ($renglones as $renglon) {
            $total = $total->sumar($renglon['valor']);
        }

        return $total;
    }

    /**
     * El primero es el titular; los demas van en orden de aparicion.
     *
     * La base garantiza que no haya dos titulares con un indice unico
     * parcial; que haya AL MENOS uno no cabe en un CHECK y se impone aca.
     *
     * @param list<Cliente> $clientes
     */
    private function asentarClientes(Venta $venta, array $clientes): void
    {
        $filas = [];

        foreach ($clientes as $posicion => $cliente) {
            $filas[(int) $cliente->getKey()] = [
                'titular' => $posicion === 0,
                'orden'   => $posicion + 1,
            ];
        }

        $venta->clientes()->attach($filas);
    }

    /**
     * Escribe el plan de una sola vez.
     *
     * `insert` masivo y no `create()` por cuota: son hasta 120 filas y no
     * hay nada que un evento de modelo tenga que hacer con ellas. El plan
     * es un snapshot: nace completo y no se toca mas (§9.D6).
     */
    private function asentarCuotas(Venta $venta, Compromiso $compromiso, PlanDeCuotas $plan): void
    {
        if ($plan->cuotas === []) {
            return;
        }

        $ahora = now();
        $filas = [];

        foreach ($plan->cuotas as $cuota) {
            $filas[] = [
                'venta_id'          => $venta->getKey(),
                'compromiso_id'     => $compromiso->getKey(),
                'numero'            => $cuota->numero,
                'fecha_vencimiento' => $cuota->vencimientoParaBase(),
                'monto'             => $cuota->montoParaBase(),
                'monto_capital'     => $cuota->capitalParaBase(),
                'monto_interes'     => $cuota->interesParaBase(),
                'monto_pagado'      => '0.00',
                'created_at'        => $ahora,
                'updated_at'        => $ahora,
            ];
        }

        Cuota::query()->insert($filas);
    }

    private function montoDe(Lote $lote, string $columna): Monto
    {
        return new Monto($this->decimalDe($lote, $columna));
    }

    /**
     * Un decimal del lote como string, que es lo unico que Monto acepta.
     *
     * Postgres devuelve NUMERIC como string, pero un factory o un cast
     * podrian dejar un int. Lo que no puede entrar al camino del dinero es
     * un float (§8.3.1).
     */
    private function decimalDe(Lote $lote, string $columna): string
    {
        $valor = $lote->getAttribute($columna);

        return is_string($valor) || is_int($valor) ? (string) $valor : '0';
    }

    private function codigo(Lote $lote): string
    {
        return (string) $lote->getAttribute('codigo');
    }
}
