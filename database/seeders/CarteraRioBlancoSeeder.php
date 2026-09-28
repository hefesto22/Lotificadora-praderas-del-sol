<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Cartera\CarteraRioBlanco;
use Override;

/**
 * Carga la cartera de Residencial Río Blanco anterior al sistema.
 *
 *   php artisan db:seed --class=CarteraRioBlancoSeeder
 *
 * El cargador es el de Praderas del Sol, entero: la revisión de antes de
 * cargar, los Services, la serie vieja de recibos, la idempotencia. Lo único
 * que cambia es el cuaderno — ver `CarteraRioBlanco`.
 *
 * Pide que el proyecto ya se llame RRB: el plano entró como CRB y se renombra
 * con `olympo:renombrar-proyecto CRB RRB` ANTES de esto.
 */
final class CarteraRioBlancoSeeder extends CarteraHistoricaSeeder
{
    #[Override]
    protected function cartera(): string
    {
        return CarteraRioBlanco::class;
    }
}
