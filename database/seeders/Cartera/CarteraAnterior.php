<?php

declare(strict_types=1);

namespace Database\Seeders\Cartera;

/**
 * La cartera que un desarrollo vendió ANTES de tener sistema, transcrita.
 *
 * `CarteraHistoricaSeeder` sabe cargar un cuaderno; esto es lo que le dice
 * CUAL. Nació el 28-sep-2026 con el segundo cuaderno —Residencial Río Blanco,
 * de otro dueño y con otra letra—: copiar el seeder para cambiarle el archivo
 * de datos habría dejado dos cargadores que se separan solos con el primer
 * arreglo, y los siete tropiezos de la primera carga están escritos en uno.
 *
 * Cada cartera vive en su propia clase, con la forma de entrada que documenta
 * `ExpedientesHistoricos`, y la carga su seeder: una subclase de
 * `CarteraHistoricaSeeder` que solo contesta `cartera()`.
 */
interface CarteraAnterior
{
    /**
     * El código del proyecto al que pertenece este cuaderno.
     */
    public static function proyecto(): string;

    /**
     * Los expedientes, uno por página del cuaderno.
     *
     * @return list<array<string, mixed>>
     */
    public static function todos(): array;

    /**
     * Los lotes que la lotificadora sacó del mercado sin una venta detrás.
     *
     * @return array<string, array{lotes: list<string>, motivo: string}>
     */
    public static function reservados(): array;

    /**
     * Con qué modalidad se cargan los abonos a capital (R21).
     */
    public static function modalidadDelAbono(): string;
}
