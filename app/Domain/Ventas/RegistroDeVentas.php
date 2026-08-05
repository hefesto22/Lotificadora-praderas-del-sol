<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
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
            $pactados
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

            $saldo = $valorTotal->restar($prima);

            // 3. El plan, verificado ANTES de tocar la base.
            $plan = PlanDeCuotas::nuevo($valorTotal, $prima, $plazoMeses, $diaPago, $fecha);

            if (! $plan->cierraExacto()) {
                throw VentaInvalidaException::porPlanQueNoCierra($plan->total(), $saldo);
            }

            // 4. Recien ahora se quema un numero.
            $secuencial = $this->correlativos->siguienteDeContrato($proyecto);

            // 5. La venta.
            $venta = Venta::query()->create([
                'proyecto_id'       => $proyecto->getKey(),
                'numero_expediente' => $secuencial,
                'numero_contrato'   => $this->correlativos->numeroDeContrato($proyecto, $secuencial, $fecha->year),
                'fecha_contrato'    => $fecha->toDateString(),
                'estado'            => EstadoVenta::Vigente,
                'area_total'        => $areaTotal->redondeado(4),
                'valor_total'       => $valorTotal->redondeado(),
                'prima'             => $prima->redondeado(),
                'saldo_financiar'   => $saldo->redondeado(),
                'cuota_mensual'     => $plan->cuotaMensual()?->redondeado(),
                'plazo_meses'       => $plazoMeses,
                'dia_pago'          => $diaPago,
                'observaciones'     => $observaciones,
            ]);

            // 6. Los duenos, y los lotes ligados a su venta.
            $this->asentarClientes($venta, $clientes);

            foreach ($renglones as $renglon) {
                $this->compromisos->vender(
                    $renglon['lote'],
                    $titular,
                    venta: $venta,
                    precioVara: $renglon['precio'],
                    motivoDescuento: $renglon['motivo'],
                    precioVaraLista: $renglon['lista'],
                );
            }

            // 7. El plan congelado (§9.D6).
            $this->asentarCuotas($venta, $plan);

            return $venta;
        });
    }

    // ─── Interno ──────────────────────────────────────────────────────

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
     * @return list<array{lote: Lote, lista: Monto, precio: Monto, motivo: string|null, valor: Monto}>
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
                throw VentaInvalidaException::porDescuentoSinMotivo($this->codigo($lote), $lista, $precio);
            }

            $renglones[] = [
                'lote'   => $lote,
                'lista'  => $lista,
                'precio' => $precio,
                'motivo' => $motivo,
                // La MISMA expresion que usa RegistroDeCompromisos::valorDe()
                // y que exige el CHECK de la base. Si los tres no dan el
                // mismo numero, la venta no se graba — y asi tiene que ser.
                'valor' => new Monto($precio->multiplicarPor($this->decimalDe($lote, 'area_varas'))->redondeado()),
            ];
        }

        return $renglones;
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
    private function asentarCuotas(Venta $venta, PlanDeCuotas $plan): void
    {
        if ($plan->cuotas === []) {
            return;
        }

        $ahora = now();
        $filas = [];

        foreach ($plan->cuotas as $cuota) {
            $filas[] = [
                'venta_id'          => $venta->getKey(),
                'numero'            => $cuota->numero,
                'fecha_vencimiento' => $cuota->vencimientoParaBase(),
                'monto'             => $cuota->montoParaBase(),
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
