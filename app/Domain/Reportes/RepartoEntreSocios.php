<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\ValueObjects\Monto;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cuánto le toca a cada socio de un resultado, y cuánto falta entregarle.
 *
 * ═══ POR QUE ES UNA CLASE APARTE (10-OCT-2026) ═══
 *
 * Hasta el 10-oct esto vivía adentro de `CierreDelMes::de()`. Ese día nació el
 * estado general del proyecto, que reparte la utilidad de TODA la vida del
 * proyecto con la misma regla. Dos copias del reparto son dos lugares donde
 * un día se arregla una y la otra sigue repartiendo distinto. Por eso la
 * regla vive acá, una vez, y los dos reportes la usan.
 *
 * ═══ LA REGLA ═══
 *
 *  - Lo que le toca: su porcentaje de la utilidad. El sobrante de centavos va
 *    al socio de mayor parte (los socios llegan ordenados así).
 *  - A su favor, además, lo que pagó de su bolsa (10-oct-2026).
 *  - En contra: lo que ya se le entregó y su parte de lo que la caja no
 *    alcanzó a devolver a quienes pusieron (`cubre`). Esto último solo existe
 *    con pérdida, y solo hasta lo puesto por los socios del reparto: el rojo
 *    que la caja trae por sí sola no se le cobra a nadie.
 *
 * El detalle, con ejemplos, está en la cabecera de `CierreDelMes`.
 */
final readonly class RepartoEntreSocios
{
    /**
     * @param list<ParteDeUnSocio> $partes
     */
    private function __construct(
        public array $partes,
        /** Lo entregado a los socios del reparto, sumado. */
        public Monto $entregado,
        /** Lo que falta entregar, sumado. Sin restar lo entregado de más a otro. */
        public Monto $porEntregar,
    ) {}

    /**
     * Los socios que entran al reparto, en el orden en que se reparte.
     *
     * Por porcentaje descendente: así el sobrante de centavos —que
     * `repartir()` le suma al primero— cae siempre en el socio de mayor parte,
     * y de paso el papel se lee de mayor a menor.
     *
     * @return Collection<int, Socio>
     */
    public static function sociosDe(int $proyecto): Collection
    {
        return Socio::query()
            ->where('proyecto_id', $proyecto)
            ->activos()
            ->orderByDesc('porcentaje')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * @param Collection<int, Socio> $socios ordenados por porcentaje descendente
     * @param array<int, Monto> $entregas lo ya entregado, por id de socio
     * @param array<int, Monto> $puesto lo pagado de su bolsa, por id de socio
     */
    public static function armar(
        Monto $utilidad,
        Monto $perdida,
        Collection $socios,
        array $entregas,
        array $puesto,
    ): self {
        $partes = self::repartir($utilidad, $socios);

        /*
         * Lo que la caja no alcanza a devolver, y que cubren todos según su
         * parte. Es la pérdida, pero solo hasta lo que pusieron los socios del
         * reparto. Ver «Y SI LA CAJA NO ALCANZA» en `CierreDelMes`.
         */
        $aDevolver = Monto::cero();

        foreach ($socios as $socio) {
            $aDevolver = $aDevolver->sumar($puesto[(int) $socio->getKey()] ?? Monto::cero());
        }

        $cubren = self::repartir($perdida->mayorQue($aDevolver) ? $aDevolver : $perdida, $socios);

        $reparto = [];
        $entregado = Monto::cero();
        $porEntregar = Monto::cero();

        foreach ($socios as $indice => $socio) {
            $leToca = $partes[$indice] ?? Monto::cero();
            $recibido = $entregas[(int) $socio->getKey()] ?? Monto::cero();
            $puso = $puesto[(int) $socio->getKey()] ?? Monto::cero();
            $cubre = $cubren[$indice] ?? Monto::cero();

            // A su favor: su parte y lo que puso de su bolsa (10-oct-2026).
            $aFavor = $leToca->sumar($puso);

            // En contra: lo que ya se llevó y su parte de lo que no alcanzó.
            $enContra = $recibido->sumar($cubre);

            $falta = $enContra->mayorQue($aFavor) ? Monto::cero() : $aFavor->restar($enContra);

            $entregado = $entregado->sumar($recibido);
            $porEntregar = $porEntregar->sumar($falta);

            $reparto[] = new ParteDeUnSocio(
                socio: $socio,
                porcentaje: $socio->porcentaje(),
                leToca: $leToca,
                entregado: $recibido,
                porEntregar: $falta,
                entregadoDeMas: $enContra->mayorQue($aFavor) ? $enContra->restar($aFavor) : Monto::cero(),
                puso: $puso,
                cubre: $cubre,
            );
        }

        return new self($reparto, $entregado, $porEntregar);
    }

    /**
     * El total repartido entre los socios, al céntimo.
     *
     * El sobrante del redondeo va al de MAYOR porcentaje —los socios vienen
     * ordenados así— porque hay que dárselo a alguien y esa es la convención
     * que menos discusión genera. Sin esto, la suma de las partes no da el
     * total y el papel no cuadra consigo mismo.
     *
     * @param Collection<int, Socio> $socios
     *
     * @return list<Monto>
     */
    public static function repartir(Monto $total, Collection $socios): array
    {
        if ($socios->isEmpty() || $total->esCero()) {
            return array_fill(0, $socios->count(), Monto::cero());
        }

        $partes = [];
        $asignado = Monto::cero();

        foreach ($socios as $socio) {
            $parte = new Monto($socio->suParteDe($total)->redondeado());
            $partes[] = $parte;
            $asignado = $asignado->sumar($parte);
        }

        if ($total->mayorQue($asignado)) {
            $partes[0] = $partes[0]->sumar($total->restar($asignado));
        }

        return $partes;
    }

    // ─── Totales de las columnas ──────────────────────────────────────

    public function leToca(): Monto
    {
        $total = Monto::cero();

        foreach ($this->partes as $parte) {
            $total = $total->sumar($parte->leToca);
        }

        return $total;
    }

    public function puesto(): Monto
    {
        $total = Monto::cero();

        foreach ($this->partes as $parte) {
            $total = $total->sumar($parte->puso);
        }

        return $total;
    }

    public function cubierto(): Monto
    {
        $total = Monto::cero();

        foreach ($this->partes as $parte) {
            $total = $total->sumar($parte->cubre);
        }

        return $total;
    }

    public function entregadoDeMas(): Monto
    {
        $total = Monto::cero();

        foreach ($this->partes as $parte) {
            $total = $total->sumar($parte->entregadoDeMas);
        }

        return $total;
    }
}
