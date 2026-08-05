<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Exceptions\CompromisoInvalidoException;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
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
 */
final readonly class RegistroDeCompromisos
{
    /**
     * Aparta un lote disponible a nombre de un cliente.
     */
    public function apartar(
        Lote $lote,
        Cliente $cliente,
        ?string $montoSenia = null,
        ?string $venceEl = null,
        ?string $observaciones = null,
    ): Compromiso {
        $estado = $this->estadoDe($lote);

        if ($estado !== EstadoLote::Disponible) {
            throw CompromisoInvalidoException::porLoteNoDisponible($this->codigoDe($lote), $estado->etiqueta());
        }

        return DB::transaction(function () use ($lote, $cliente, $montoSenia, $venceEl, $observaciones): Compromiso {
            $compromiso = $this->crear($lote, $cliente, TipoCompromiso::Apartado, [
                // Monto valida el tipo y rechaza negativos; de paso deja el
                // string con los dos decimales de la columna.
                'monto_senia'   => $montoSenia === null ? null : new Monto($montoSenia)->redondeado(),
                'vence_el'      => $venceEl,
                'observaciones' => $observaciones,
            ]);

            $lote->update(['estado' => EstadoLote::Apartado]);

            return $compromiso;
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
     */
    public function vender(
        Lote $lote,
        Cliente $cliente,
        ?string $observaciones = null,
        ?Venta $venta = null,
        ?Monto $precioVara = null,
        ?string $motivoDescuento = null,
        ?Monto $precioVaraLista = null,
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
            $motivo
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
                'motivo_descuento'  => $motivo === '' ? null : $motivo,
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
