<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Cláusula Décima: exportación total de datos bajo demanda del cliente.
 *
 * ═══ POR QUE EXISTE DESDE HOY Y NO EL DIA QUE LA PIDAN ═══
 *
 * Porque el día que la pidan va a ser un día malo — una discusión, un cambio
 * de proveedor, un cierre— y ahí nadie quiere descubrir que hay que escribir
 * un exportador. El documento rector lo dice con todas las letras: «desde el
 * día 1, no improvisado al final».
 *
 * Y porque es una obligación contractual que no depende de que el cliente
 * esté al día: `SuspensionPorMora` deja pasar al super-admin justamente para
 * que esto se pueda correr con el acceso suspendido.
 *
 * ═══ CSV Y NO XLSX ═══
 *
 * El contrato dice «Excel/CSV». CSV se abre en Excel, en Google Sheets, en
 * LibreOffice y en cualquier cosa que exista dentro de diez años; un .xlsx
 * generado por una librería obliga a mantener esa librería viva. Lleva BOM
 * porque sin él Excel en Windows rompe los acentos y los nombres salen como
 * «PORTILLO ESPAÃ‘A».
 *
 * ═══ LAS TABLAS ESTAN LISTADAS A MANO ═══
 *
 * No se recorre el esquema. Una tabla de cachés, de colas o de sesiones no
 * es información del cliente, y `password` no se exporta a un CSV que va a
 * terminar en un correo. Cuando se agregue una tabla del negocio hay que
 * sumarla acá — y ese olvido es preferible al contrario.
 */
#[Description('Exporta TODOS los datos del cliente a CSV (Cláusula Décima)')]
#[Signature('praderas:exportar-todo
                            {--salida= : Carpeta donde dejar el resultado (por defecto storage/app/exportaciones)}
                            {--sin-zip : Deja los CSV sueltos en vez de comprimirlos}')]
final class ExportarTodo extends Command
{
    /**
     * Las tablas del NEGOCIO, en orden de lectura: primero lo que se
     * referencia y después lo que referencia.
     *
     * @var list<string>
     */
    private const array TABLAS = [
        'proyectos',
        'bloques',
        'calles',
        'lotes',
        'planes_de_pago',
        'clientes',
        'ventas',
        'venta_cliente',
        'compromisos',
        'cuotas',
        'recibos',
        'aplicaciones_de_pago',
        'reprogramaciones',
        'impresiones_de_recibo',
        'documentos',
        'correlativos',
        'users',
        'activity_log',
    ];

    /**
     * Columnas que NO salen, por tabla.
     *
     * @var array<string, list<string>>
     */
    private const array RESERVADAS = [
        'users' => ['password', 'remember_token'],
    ];

    public function handle(): int
    {
        $sello = now()->format('Y-m-d-His');
        $base = $this->carpetaBase();
        $carpeta = $base.DIRECTORY_SEPARATOR.'praderas-'.$sello;

        if (! is_dir($carpeta) && ! mkdir($carpeta, 0o775, true) && ! is_dir($carpeta)) {
            $this->components->error("No se pudo crear la carpeta {$carpeta}.");

            return self::FAILURE;
        }

        $escritos = [];
        $filasTotales = 0;

        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) {
                // Una tabla que todavía no existe no es un error: activity_log
                // llega con un paquete y puede no estar migrada.
                $this->components->warn("Se omite «{$tabla}»: la tabla no existe.");

                continue;
            }

            $filas = $this->exportar($tabla, $carpeta);
            $filasTotales += $filas;
            $escritos[] = $tabla;

            $this->components->twoColumnDetail($tabla, number_format($filas).' fila(s)');
        }

        if ($escritos === []) {
            $this->components->error('No se exportó ninguna tabla.');

            return self::FAILURE;
        }

        $destino = $this->option('sin-zip') === true
            ? $carpeta
            : $this->comprimir($carpeta, $escritos);

        $this->newLine();
        $this->components->info(sprintf(
            '%s tabla(s) y %s fila(s) exportadas a %s',
            count($escritos),
            number_format($filasTotales),
            $destino,
        ));

        return self::SUCCESS;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Vuelca una tabla entera a su CSV y devuelve cuántas filas salieron.
     *
     * Va por `lazy()` y no por `get()`: el día que `cuotas` tenga cien mil
     * filas —quinientos lotes por doce a setenta y dos cuotas cada uno— un
     * `get()` se trae todo a memoria de una vez y el comando muere en el
     * servidor justo cuando más falta hace.
     */
    private function exportar(string $tabla, string $carpeta): int
    {
        $columnas = $this->columnasDe($tabla);
        $ruta = $carpeta.DIRECTORY_SEPARATOR.$tabla.'.csv';

        $archivo = fopen($ruta, 'wb');

        if ($archivo === false) {
            throw new RuntimeException("No se pudo escribir {$ruta}.");
        }

        // El BOM de UTF-8. Sin esto Excel en Windows lee los acentos mal y
        // el cliente recibe un archivo con la ñ rota.
        fwrite($archivo, "\xEF\xBB\xBF");
        fputcsv($archivo, $columnas, escape: '\\');

        $consulta = DB::table($tabla)->select($columnas);

        if (Schema::hasColumn($tabla, 'id')) {
            $consulta = $consulta->orderBy('id');
        }

        $filas = 0;

        foreach ($consulta->lazy() as $fila) {
            $datos = (array) $fila;
            $renglon = [];

            foreach ($columnas as $columna) {
                $valor = $datos[$columna] ?? null;

                /*
                 * Los NUMERIC de Postgres ya llegan como string y así salen:
                 * pasarlos por un float para «formatearlos» es exactamente lo
                 * que el §8.3.1 prohíbe, y en un CSV además no aporta nada.
                 */
                $renglon[] = match (true) {
                    $valor === null   => '',
                    is_bool($valor)   => $valor ? '1' : '0',
                    is_scalar($valor) => (string) $valor,
                    default           => json_encode($valor, JSON_UNESCAPED_UNICODE) ?: '',
                };
            }

            fputcsv($archivo, $renglon, escape: '\\');
            $filas++;
        }

        fclose($archivo);

        return $filas;
    }

    /**
     * @return list<string>
     */
    private function columnasDe(string $tabla): array
    {
        $columnas = Schema::getColumnListing($tabla);
        $reservadas = self::RESERVADAS[$tabla] ?? [];

        return array_values(array_filter(
            $columnas,
            static fn (string $columna): bool => ! in_array($columna, $reservadas, true),
        ));
    }

    /**
     * @param list<string> $tablas
     */
    private function comprimir(string $carpeta, array $tablas): string
    {
        if (! class_exists(ZipArchive::class)) {
            // Sin ext-zip no se falla: los CSV ya están escritos y sirven
            // igual. Se avisa y se devuelve la carpeta.
            $this->components->warn('ext-zip no está disponible; los CSV quedan sueltos.');

            return $carpeta;
        }

        $destino = $carpeta.'.zip';
        $zip = new ZipArchive;

        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->components->warn("No se pudo crear {$destino}; los CSV quedan sueltos.");

            return $carpeta;
        }

        foreach ($tablas as $tabla) {
            $zip->addFile($carpeta.DIRECTORY_SEPARATOR.$tabla.'.csv', $tabla.'.csv');
        }

        $zip->close();

        // La carpeta se deja: borrar el original mientras alguien mira el
        // listado es la clase de comando que asusta. Se limpia a mano.
        return $destino;
    }

    private function carpetaBase(): string
    {
        $salida = $this->option('salida');

        return is_string($salida) && trim($salida) !== ''
            ? rtrim($salida, DIRECTORY_SEPARATOR)
            : storage_path('app'.DIRECTORY_SEPARATOR.'exportaciones');
    }
}
