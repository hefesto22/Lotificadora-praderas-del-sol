<?php

declare(strict_types=1);

namespace App\Domain\Socios;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\ValueObjects\Monto;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Socio;
use Carbon\CarbonImmutable;

/**
 * Cuánto le tocó a cada socio de un mes.
 *
 * ═══ LAS TRES REGLAS, Y QUIEN LAS DECIDIO ═══
 *
 * Las contestó Mauricio el 13-ago-2026, y las tres son de plata, así que van
 * escritas acá y no en la cabeza de nadie:
 *
 * 1. **Se muestran los DOS números**: lo cobrado del mes y lo cobrado menos los
 *    gastos. «Ambas para reporte». El bruto dice cuánto entró; el neto, cuánto
 *    hay para repartir de verdad.
 *
 * 2. **Un gasto entra por su fecha**, la que tiene el gasto registrado — no la
 *    del comprobante ni la del pago al proveedor.
 *
 * 3. **La prima cuenta; la seña NO.** «La seña no, solo hasta que ya se
 *    formalice». Es lo correcto y no es un detalle: mientras es seña, esa plata
 *    todavía puede tener que devolverse (R14). Repartirla sería repartir dinero
 *    ajeno. Cuando el apartado se convierte en venta, esa misma plata reaparece
 *    como parte de la prima y ahí sí entra.
 *
 * ⚠️ Los recibos ANULADOS no cuentan. Un recibo anulado es plata que no entró.
 *
 * ⚠️ Los lotes RESERVADOS no aparecen por ningún lado, y así tiene que ser: no
 * se vendieron ni se van a vender —son de los herederos— así que no producen un
 * lempira que repartir.
 */
final readonly class RepartoDelMes
{
    /**
     * @param list<ParteDelSocio> $partes
     */
    private function __construct(
        public CarbonImmutable $desde,
        public CarbonImmutable $hasta,
        public Monto $cobrado,
        public Monto $gastos,
        /*
         * ⚠️ El neto va en VALOR ABSOLUTO y el signo lo dice `$enRojo`.
         *
         * `Monto` es no negativo por diseño —es el value object del dinero de
         * la cartera, donde un saldo negativo es un error— y un neto mensual SÍ
         * puede quedar debajo de cero: un mes flojo con una factura grande.
         *
         * Se resolvió con magnitud + signo y NO relajando `Monto`: esa
         * restricción es lo que hace que un saldo mal calculado explote en vez
         * de aparecer como un número raro en el estado de cuenta de alguien.
         */
        public Monto $neto,
        public bool $enRojo,
        public array $partes,
        public bool $repartoCompleto,
    ) {}

    /**
     * El neto listo para leer, con su signo: «-L 15,000.00» cuando está en rojo.
     */
    public function netoFormateado(): string
    {
        return ($this->enRojo ? '-' : '').$this->neto->formateado();
    }

    public static function para(Proyecto $proyecto, CarbonImmutable $mes): self
    {
        $desde = $mes->startOfMonth();
        $hasta = $mes->endOfMonth();

        $cobrado = self::loCobrado($proyecto, $desde, $hasta);
        $gastos = self::losGastos($proyecto, $desde, $hasta);

        /*
         * El neto puede quedar EN ROJO —un mes flojo con una factura grande— y
         * se muestra así. Recortarlo a cero escondería justamente el mes que hay
         * que mirar, y el socio que pone plata en un mes malo la pone igual.
         */
        $enRojo = $gastos->mayorQue($cobrado);
        $neto = $enRojo ? $gastos->restar($cobrado) : $cobrado->restar($gastos);

        /*
         * `array_values()` porque `->all()` de una Collection devuelve un array
         * con claves y `repartir()` pide una LISTA: le importa el ORDEN —el
         * primero es el de mayor parte— y un array con huecos no lo garantiza.
         */
        $socios = array_values($proyecto->socios()->activos()->get()->all());

        return new self(
            desde: $desde,
            hasta: $hasta,
            cobrado: $cobrado,
            gastos: $gastos,
            neto: $neto,
            enRojo: $enRojo,
            partes: self::repartir($socios, $cobrado, $neto),
            repartoCompleto: $proyecto->elRepartoCierra() && $socios !== [],
        );
    }

    /**
     * Lo que entró por este proyecto en el mes.
     *
     * Por la fecha del RECIBO y no por la de la cuota: lo que se reparte es
     * plata que entró, no plata que se debía.
     */
    private static function loCobrado(Proyecto $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): Monto
    {
        $recibos = Recibo::query()
            ->whereNull('anulado_el')
            // La seña no entra hasta que el apartado se formalice.
            ->where('concepto', '!=', ConceptoDeRecibo::Senia->value)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->whereHas('venta', fn ($consulta) => $consulta->where('proyecto_id', $proyecto->getKey()))
            ->pluck('monto');

        return self::sumar(array_values($recibos->all()));
    }

    private static function losGastos(Proyecto $proyecto, CarbonImmutable $desde, CarbonImmutable $hasta): Monto
    {
        $gastos = Gasto::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->pluck('monto');

        return self::sumar(array_values($gastos->all()));
    }

    /**
     * 🔴 EL REPARTO NO PIERDE UN CENTAVO.
     *
     * Redondear la parte de cada socio por separado y sumarlas casi nunca da el
     * total: dos socios al 50% de L 100.01 se llevan L 50.01 y L 50.01, que son
     * L 100.02 — un centavo que no existe. Al revés pasa lo mismo y queda uno
     * sin dueño.
     *
     * La salida no es calcular todas las partes y después parchear la
     * diferencia, sino calcular las de TODOS MENOS UNO y darle al que queda **lo
     * que resta del total**. Así suman exacto POR CONSTRUCCIÓN: no hay
     * diferencia que medir ni que preguntarse si sobra o falta.
     *
     * El que absorbe es el de MAYOR porcentaje —el primero, porque
     * `Proyecto::socios()` viene ordenado de mayor a menor—. No es arbitrario:
     * si alguien tiene que quedarse con un centavo de más o de menos, que sea el
     * que más pone y más se lleva.
     *
     * @param list<Socio> $socios
     *
     * @return list<ParteDelSocio>
     */
    private static function repartir(array $socios, Monto $cobrado, Monto $neto): array
    {
        if ($socios === []) {
            return [];
        }

        $partes = [];
        $brutoDeLosDemas = Monto::cero();
        $netoDeLosDemas = Monto::cero();

        foreach (array_slice($socios, 1) as $socio) {
            $suBruto = new Monto($socio->suParteDe($cobrado)->redondeado());
            $suNeto = new Monto($socio->suParteDe($neto)->redondeado());

            $brutoDeLosDemas = $brutoDeLosDemas->sumar($suBruto);
            $netoDeLosDemas = $netoDeLosDemas->sumar($suNeto);

            $partes[] = new ParteDelSocio($socio, $suBruto, $suNeto);
        }

        array_unshift($partes, new ParteDelSocio(
            $socios[0],
            self::loQueResta($cobrado, $brutoDeLosDemas),
            self::loQueResta($neto, $netoDeLosDemas),
        ));

        return $partes;
    }

    /**
     * Lo que le queda al de mayor parte: el total menos lo de los demás.
     *
     * El piso en cero es un borde teórico —haría falta un total de centavos con
     * muchos socios para que el redondeo de los demás se pase del total— pero
     * `Monto` no admite negativos y un reparto no puede caerse por eso.
     */
    private static function loQueResta(Monto $total, Monto $deLosDemas): Monto
    {
        return $deLosDemas->mayorQue($total) ? Monto::cero() : $total->restar($deLosDemas);
    }

    /**
     * @param list<mixed> $valores
     */
    private static function sumar(array $valores): Monto
    {
        $total = Monto::cero();

        foreach ($valores as $valor) {
            if (! is_string($valor) && ! is_int($valor)) {
                continue;
            }

            $total = $total->sumar(new Monto($valor));
        }

        return $total;
    }
}
