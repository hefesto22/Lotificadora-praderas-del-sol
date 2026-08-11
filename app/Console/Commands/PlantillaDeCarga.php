<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoLote;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Saca la lista de lotes para que el cliente le ponga los precios.
 *
 *   php artisan olympo:plantilla-de-carga RPS
 *
 * ═══ QUE PROBLEMA RESUELVE ═══
 *
 * R15: «los lotes llegan en papel». La geometría ya está resuelta por el
 * importador DXF —bloques, números y áreas están cargados—, pero **la parte
 * comercial no**: el precio por vara² de cada lote y, donde ya hay dueño, a
 * quién se le vendió y cuánto lleva pagado.
 *
 * Ese dato lo tiene la lotificadora, no nosotros, y hay que pedírselo. Pedirlo
 * como «mandanos los precios» produce una hoja distinta cada vez y una
 * conversación de ida y vuelta que a nadie le sobra. Este comando manda la
 * pregunta ya hecha: **la lista completa de lotes, con su código y su área
 * real, y una columna vacía**. Quien contesta solo escribe números.
 *
 * ═══ POR QUE CSV Y NO XLSX ═══
 *
 * Porque no vale una dependencia. El CSV lo abre Excel, Numbers y Google
 * Sheets, y de ahí sale el archivo bonito que se le manda al cliente. El día
 * que esto tenga que salir formateado desde acá, se agrega
 * `phpoffice/phpspreadsheet` y se cambia solo la escritura.
 *
 * ⚠️ **BOM UTF-8 al inicio.** Sin él, Excel en Windows abre el archivo en
 * la codificación del sistema y «Terracería» sale «TerracerÃ­a». Son tres
 * bytes que evitan que alguien devuelva la plantilla con los nombres rotos.
 *
 * ═══ ESTO ES DEL PRODUCTO ═══
 *
 * Toda lotificadora que instale Olympo pasa por el mismo momento: el plano
 * cargado y los precios en la cabeza del dueño. El comando no sabe nada de
 * Praderas del Sol — recibe el código del proyecto y trabaja con lo que haya.
 */
#[Description('Exporta los lotes de un proyecto para que el cliente cargue precios y cartera')]
#[Signature('olympo:plantilla-de-carga
                            {codigo : Código del proyecto, por ejemplo RPS}
                            {--salida=plantillas : Carpeta dentro de storage/app donde se escriben los CSV}')]
final class PlantillaDeCarga extends Command
{
    public function handle(): int
    {
        $codigo = mb_strtoupper((string) $this->argument('codigo'));

        $proyecto = Proyecto::query()->where('codigo', $codigo)->first();

        if (! $proyecto instanceof Proyecto) {
            $this->error("No existe ningún proyecto con código {$codigo}.");

            return self::FAILURE;
        }

        $carpeta = storage_path('app/'.trim((string) $this->option('salida'), '/'));

        if (! is_dir($carpeta) && ! mkdir($carpeta, 0755, true) && ! is_dir($carpeta)) {
            $this->error("No se pudo crear la carpeta {$carpeta}.");

            return self::FAILURE;
        }

        $lotes = $this->lotes($proyecto);

        if ($lotes === []) {
            $this->error("El proyecto {$codigo} no tiene lotes cargados. Importá el plano primero.");

            return self::FAILURE;
        }

        $precios = $carpeta.'/'.mb_strtolower($codigo).'-precios.csv';

        $this->escribir($precios, [
            'bloque', 'lote', 'codigo', 'area_varas', 'estado_en_el_sistema',
            'precio_por_vara', 'precio_total_del_lote', 'notas',
        ], $lotes);

        $this->info("✓ Precios:  {$precios}  ({$this->contar($lotes)} lotes)");

        /*
         * La cartera vieja va en un archivo APARTE y VACIO, con solo la fila de
         * encabezados. No se pre-llena con los lotes: son los pocos que ya
         * tienen dueño, y una hoja de 301 renglones para escribir en cinco
         * invita a llenarla entera «por si acaso».
         */
        $cartera = $carpeta.'/'.mb_strtolower($codigo).'-cartera.csv';

        $this->escribir($cartera, [
            'bloque', 'lote', 'nombre_del_cliente', 'dni', 'telefono',
            'fecha_del_contrato', 'precio_total_pactado', 'prima_pagada',
            'plazo_en_meses', 'dia_de_pago', 'total_abonado_a_la_fecha', 'notas',
        ], []);

        $this->info("✓ Cartera:  {$cartera}  (vacío, solo encabezados)");

        $this->newLine();
        $this->comment('Los dos se abren con Excel, Numbers o Google Sheets.');
        $this->comment('El de precios ya trae los lotes: solo falta la columna «precio_por_vara».');

        return self::SUCCESS;
    }

    /**
     * Los lotes del proyecto, ordenados como se caminan: bloque y número.
     *
     * El número se ordena como NUMERO y no como texto —de ahí el cast—:
     * alfabéticamente el 10 va antes que el 2, y quien revisa la hoja contra
     * el plano en la mano pierde la cuenta.
     *
     * @return list<array<int, string>>
     */
    private function lotes(Proyecto $proyecto): array
    {
        $filas = [];

        $lotes = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->with('bloque')
            ->orderByRaw('(SELECT nombre FROM bloques WHERE bloques.id = lotes.bloque_id)')
            ->orderByRaw('NULLIF(regexp_replace(numero, \'\D\', \'\', \'g\'), \'\')::int')
            ->orderBy('numero')
            ->get();

        foreach ($lotes as $lote) {
            $estado = $lote->getAttribute('estado');

            $filas[] = [
                (string) ($lote->getAttribute('bloque')?->getAttribute('nombre') ?? ''),
                (string) $lote->getAttribute('numero'),
                (string) $lote->getAttribute('codigo'),
                (string) $lote->getAttribute('area_varas'),
                $estado instanceof EstadoLote ? $estado->etiqueta() : '',
                '',  // precio_por_vara — lo llena el cliente
                '',  // precio_total_del_lote — sale solo en la hoja
                '',  // notas
            ];
        }

        return $filas;
    }

    /**
     * @param list<string> $encabezados
     * @param list<array<int, string>> $filas
     */
    private function escribir(string $ruta, array $encabezados, array $filas): void
    {
        $puntero = fopen($ruta, 'wb');

        if ($puntero === false) {
            $this->error("No se pudo escribir {$ruta}.");

            return;
        }

        // El BOM: sin esto Excel en Windows rompe los acentos.
        fwrite($puntero, "\xEF\xBB\xBF");

        fputcsv($puntero, $encabezados, escape: '');

        foreach ($filas as $fila) {
            fputcsv($puntero, $fila, escape: '');
        }

        fclose($puntero);
    }

    /**
     * @param list<array<int, string>> $filas
     */
    private function contar(array $filas): int
    {
        return count($filas);
    }
}
