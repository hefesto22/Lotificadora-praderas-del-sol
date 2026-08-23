<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoLote;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * El plano creció: carga lo que falta SIN tocar lo que ya se vendió.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * Un plano no se lee una sola vez. El topógrafo corrige una manzana, el
 * dueño abre una etapa nueva, o la lectura del DXF se quedó corta y nadie
 * lo nota hasta que una clienta pregunta por un lote que en el mapa no
 * está. Pasó el 22-ago-2026 con la manzana I de PRADERAS DEL SOL: el
 * archivo del plano traía la primera fila —I-1 a I-7— y le faltaba la
 * segunda entera, I-8 a I-15, que en el plano del Ing. Menjívar sí está
 * dibujada y acotada.
 *
 * Hasta hoy la única vía era volver a sembrar el plano, y **esa puerta se
 * cierra sola en cuanto se vende el primer lote**: el seeder se detiene
 * antes de borrar nada, y hace bien. O sea que el momento en que el
 * faltante aparece —con la lotificadora ya operando— es exactamente el
 * momento en que ya no se puede arreglar. Este comando es la otra puerta.
 *
 * ═══ LA REGLA, UNA SOLA ═══
 *
 * **Agrega. No borra, no renumera, no repinta.**
 *
 * Un lote que ya está en la base no se toca ni aunque el archivo diga otra
 * cosa: si difieren, se informa la diferencia y se sigue. Cambiarle el
 * área o el polígono a un lote vendido es moverle el piso a un contrato
 * firmado, y eso no lo decide un comando: lo decide alguien, mirando el
 * expediente. Un lote que está en la base y NO en el archivo tampoco se
 * borra; se avisa y ya.
 *
 * De ahí que sea idempotente: correrlo dos veces no cambia nada la
 * segunda vez.
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:completar-plano RPS database/data/praderas-plano.json --ensayo
 *   php artisan olympo:completar-plano RPS database/data/praderas-plano.json
 *
 * `--ensayo` imprime el mismo informe y no escribe una sola fila. Se corre
 * SIEMPRE primero: ese informe es la revisión.
 *
 * ═══ EL PRECIO ═══
 *
 * Los lotes nuevos heredan el precio por unidad que ya tienen sus hermanos
 * de manzana —el que más se repite—, porque un lote nuevo en una manzana
 * que ya se está vendiendo vale lo que vale esa manzana. Si la manzana
 * nace con este comando no hay de quién heredar, y entonces hay que
 * decirlo con `--precio-vara`. Nunca se inventa un número.
 *
 * ═══ EL ARCHIVO ═══
 *
 * El mismo formato que consume el seeder del plano:
 *
 *   {"lotes": [{"bloque": "I", "numero": "8", "area": 260.10,
 *               "frente": 12.00, "fondo": 23.07,
 *               "poligono": [[x, y], ...]}]}
 *
 * `frente`, `fondo` y `area_exacta` son opcionales; `poligono` puede venir
 * vacío y el lote entra igual, marcado como «sin dibujo». El área va en la
 * unidad del proyecto (ver UnidadDeArea): acá no se convierte nada.
 */
#[Description('Carga en un proyecto los lotes que el plano tiene y la base no. No borra ni modifica lo que ya existe.')]
#[Signature('olympo:completar-plano
                            {codigo : Código del proyecto, por ejemplo RPS}
                            {archivo : Ruta del JSON del plano}
                            {--ensayo : Mostrar el informe y no escribir nada}
                            {--precio-vara= : Precio por unidad de área para una manzana que nace vacía}')]
final class CompletarPlano extends Command
{
    private const string SIN_DIBUJO = 'AREA Y NUMERO EXACTOS DEL PLANO. EL POLIGONO NO SE PUDO CERRAR: NO SE DIBUJA EN EL MAPA.';

    public function handle(): int
    {
        $codigo = mb_strtoupper((string) $this->argument('codigo'));

        $proyecto = Proyecto::query()->where('codigo', $codigo)->first();

        if (! $proyecto instanceof Proyecto) {
            $this->error("No existe ningún proyecto con código {$codigo}.");

            return self::FAILURE;
        }

        $delPlano = $this->leerArchivo();

        if ($delPlano === null) {
            return self::FAILURE;
        }

        $enLaBase = $this->indiceDeLaBase($proyecto);

        $faltan = array_values(array_filter(
            $delPlano,
            static fn (array $lote): bool => ! isset($enLaBase[$lote['bloque'].'|'.$lote['numero']]),
        ));

        $this->informarLoQueNoSeToca($delPlano, $enLaBase);

        if ($faltan === []) {
            $this->info("{$codigo}: el plano y la base dicen lo mismo. No hay nada que agregar.");

            return self::SUCCESS;
        }

        $precios = $this->preciosPorManzana($proyecto, $faltan);

        if ($precios === null) {
            return self::FAILURE;
        }

        $this->informarLoQueEntra($faltan, $precios);

        if ($this->option('ensayo') === true) {
            $this->warn('Ensayo: no se escribió nada.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($proyecto, $faltan, $precios): void {
            $this->cargar($proyecto, $faltan, $precios);
        });

        $this->info(sprintf(
            '%s: %d lote(s) agregados. Los que ya estaban quedaron intactos.',
            $codigo,
            count($faltan),
        ));

        return self::SUCCESS;
    }

    // ─── Leer ─────────────────────────────────────────────────────────

    /**
     * @return list<array{bloque: string, numero: string, area: float, poligono: list<array{float, float}>}>|null
     */
    private function leerArchivo(): ?array
    {
        $pedida = (string) $this->argument('archivo');
        $ruta = is_file($pedida) ? $pedida : base_path($pedida);

        if (! is_file($ruta)) {
            $this->error("No encuentro el plano en {$pedida}.");

            return null;
        }

        $crudo = file_get_contents($ruta);

        if ($crudo === false) {
            $this->error("No pude leer {$ruta}.");

            return null;
        }

        try {
            /** @var mixed $datos */
            $datos = json_decode($crudo, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("El archivo no es JSON válido: {$e->getMessage()}");

            return null;
        }

        $crudos = is_array($datos) && isset($datos['lotes']) && is_array($datos['lotes']) ? $datos['lotes'] : [];

        if ($crudos === []) {
            $this->error('El archivo no trae ningún lote bajo la clave "lotes".');

            return null;
        }

        $lotes = [];

        foreach ($crudos as $posicion => $lote) {
            if (! is_array($lote) || ! isset($lote['bloque'], $lote['numero'], $lote['area'])) {
                $this->error("El lote en la posición {$posicion} no trae bloque, número o área.");

                return null;
            }

            /** @var list<array{float, float}> $poligono */
            $poligono = isset($lote['poligono']) && is_array($lote['poligono']) ? array_values($lote['poligono']) : [];

            $lotes[] = [
                'bloque'   => mb_strtoupper((string) $lote['bloque']),
                'numero'   => (string) $lote['numero'],
                'area'     => (float) $lote['area'],
                'poligono' => $poligono,
            ];
        }

        return $lotes;
    }

    /**
     * Los lotes que el proyecto YA tiene, indexados por «MANZANA|NUMERO».
     *
     * @return array<string, Lote>
     */
    private function indiceDeLaBase(Proyecto $proyecto): array
    {
        /** @var array<int, string> $manzanas */
        $manzanas = Bloque::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->pluck('nombre', 'id')
            ->all();

        $indice = [];

        foreach (Lote::query()->where('proyecto_id', $proyecto->getKey())->get() as $lote) {
            $manzana = $manzanas[(int) $lote->getAttribute('bloque_id')] ?? '';
            $indice[$manzana.'|'.$lote->getAttribute('numero')] = $lote;
        }

        return $indice;
    }

    // ─── Informar ─────────────────────────────────────────────────────

    /**
     * Lo que el comando NO va a tocar, dicho en voz alta. Un faltante que
     * se carga en silencio junto a una diferencia que se calla es peor que
     * no haber corrido nada.
     *
     * @param list<array{bloque: string, numero: string, area: float, poligono: list<array{float, float}>}> $delPlano
     * @param array<string, Lote> $enLaBase
     */
    private function informarLoQueNoSeToca(array $delPlano, array $enLaBase): void
    {
        $enElPlano = [];
        $conOtraArea = [];

        foreach ($delPlano as $lote) {
            $clave = $lote['bloque'].'|'.$lote['numero'];
            $enElPlano[$clave] = true;
            $viejo = $enLaBase[$clave] ?? null;

            if (! $viejo instanceof Lote) {
                continue;
            }

            $area = (float) $viejo->getAttribute('area_varas');

            if (abs($area - $lote['area']) > 0.005) {
                $conOtraArea[] = sprintf(
                    '%s (base %s, plano %s)',
                    (string) $viejo->getAttribute('codigo'),
                    number_format($area, 2),
                    number_format($lote['area'], 2),
                );
            }
        }

        if ($conOtraArea !== []) {
            $this->warn('Ya existen y el archivo les da otra área. NO se tocan:');
            $this->line('  '.implode(', ', $conOtraArea));
        }

        $sobran = array_keys(array_diff_key($enLaBase, $enElPlano));

        if ($sobran !== []) {
            $this->warn(sprintf(
                '%d lote(s) están en la base y no en el archivo. NO se borran: %s',
                count($sobran),
                implode(', ', array_map(static fn (string $c): string => str_replace('|', '-', $c), $sobran)),
            ));
        }
    }

    /**
     * @param list<array{bloque: string, numero: string, area: float, poligono: list<array{float, float}>}> $faltan
     * @param array<string, string> $precios
     */
    private function informarLoQueEntra(array $faltan, array $precios): void
    {
        $this->table(
            ['Lote', 'Área', 'Dibujo', 'Precio por unidad'],
            array_map(static fn (array $lote): array => [
                $lote['bloque'].'-'.$lote['numero'],
                number_format($lote['area'], 2),
                $lote['poligono'] === [] ? 'sin dibujo' : count($lote['poligono']).' vértices',
                number_format((float) $precios[$lote['bloque']], 2),
            ], $faltan),
        );
    }

    // ─── El precio ────────────────────────────────────────────────────

    /**
     * Con qué precio entra cada manzana: el que más se repite entre los
     * lotes que ya tiene. Devuelve null —y dice cuál falta— si una manzana
     * nace vacía y nadie dijo con qué precio.
     *
     * @param list<array{bloque: string, numero: string, area: float, poligono: list<array{float, float}>}> $faltan
     *
     * @return array<string, string>|null
     */
    private function preciosPorManzana(Proyecto $proyecto, array $faltan): ?array
    {
        $pedido = $this->option('precio-vara');
        $pedido = is_string($pedido) && is_numeric($pedido) ? $pedido : null;

        $precios = [];
        $huerfanas = [];

        foreach (array_unique(array_column($faltan, 'bloque')) as $manzana) {
            $heredado = $this->precioQueMasSeRepite($proyecto, $manzana);

            if ($heredado !== null) {
                $precios[$manzana] = $heredado;

                continue;
            }

            if ($pedido === null) {
                $huerfanas[] = $manzana;

                continue;
            }

            $precios[$manzana] = $pedido;
        }

        if ($huerfanas !== []) {
            $this->error('Estas manzanas no tienen ningún lote del que heredar el precio: '.implode(', ', $huerfanas));
            $this->line('Corré de nuevo agregando --precio-vara=1500 (o el que corresponda).');

            return null;
        }

        return $precios;
    }

    private function precioQueMasSeRepite(Proyecto $proyecto, string $manzana): ?string
    {
        $bloque = Bloque::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('nombre', $manzana)
            ->first();

        if (! $bloque instanceof Bloque) {
            return null;
        }

        /** @var list<string> $precios */
        $precios = Lote::query()
            ->where('bloque_id', $bloque->getKey())
            ->pluck('precio_vara')
            ->map(static fn (mixed $precio): string => (string) $precio)
            ->all();

        if ($precios === []) {
            return null;
        }

        $veces = array_count_values($precios);
        arsort($veces);

        return array_key_first($veces);
    }

    // ─── Escribir ─────────────────────────────────────────────────────

    /**
     * @param list<array{bloque: string, numero: string, area: float, poligono: list<array{float, float}>}> $faltan
     * @param array<string, string> $precios
     */
    private function cargar(Proyecto $proyecto, array $faltan, array $precios): void
    {
        /** @var array<string, Bloque> $manzanas */
        $manzanas = [];

        foreach ($faltan as $lote) {
            $nombre = $lote['bloque'];
            $manzanas[$nombre] ??= $this->manzana($proyecto, $nombre);
            $dibujado = count($lote['poligono']) >= 3;

            Lote::query()->create([
                'proyecto_id'   => $proyecto->getKey(),
                'bloque_id'     => $manzanas[$nombre]->getKey(),
                'numero'        => $lote['numero'],
                'area_varas'    => number_format($lote['area'], 2, '.', ''),
                'precio_vara'   => $precios[$nombre],
                'estado'        => EstadoLote::Disponible,
                'poligono'      => $dibujado ? $lote['poligono'] : null,
                'observaciones' => $dibujado ? null : self::SIN_DIBUJO,
            ]);
        }

        foreach ($manzanas as $manzana) {
            $this->recontar($manzana);
        }
    }

    private function manzana(Proyecto $proyecto, string $nombre): Bloque
    {
        $bloque = Bloque::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('nombre', $nombre)
            ->first();

        if ($bloque instanceof Bloque) {
            return $bloque;
        }

        $ultimo = (int) Bloque::query()->where('proyecto_id', $proyecto->getKey())->max('orden');

        $this->line("Se crea la manzana {$nombre}.");

        return Bloque::query()->create([
            'proyecto_id'        => $proyecto->getKey(),
            'nombre'             => $nombre,
            'area_total_varas'   => '0.00',
            'lotes_planificados' => 0,
            'orden'              => $ultimo + 1,
        ]);
    }

    /**
     * `lotes_planificados` y `area_total_varas` son el DECLARADO del plano
     * (ver Bloque), no un caché. Si el plano creció, el declarado creció
     * con él: se recalcula desde los lotes que la manzana tiene AHORA.
     */
    private function recontar(Bloque $manzana): void
    {
        $antes = (int) $manzana->getAttribute('lotes_planificados');
        $lotes = Lote::query()->where('bloque_id', $manzana->getKey());
        $ahora = $lotes->clone()->count();

        $manzana->update([
            'lotes_planificados' => $ahora,
            'area_total_varas'   => number_format((float) $lotes->clone()->sum('area_varas'), 2, '.', ''),
        ]);

        $this->line("Manzana {$manzana->getAttribute('nombre')}: {$antes} → {$ahora} lotes planificados.");
    }
}
