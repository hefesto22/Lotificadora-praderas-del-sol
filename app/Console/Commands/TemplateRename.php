<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Comando para "renombrar" la plantilla cuando se clona para un proyecto nuevo.
 *
 * Reemplaza referencias a "Plantilla Olympo" / "plantilla_olympo" en:
 *   - .env y .env.example
 *   - composer.json (name, description)
 *   - package.json (name)
 *   - README.md (título)
 *   - Cualquier referencia textual obvia
 *
 * Uso:
 *   php artisan template:rename --name="Constructora Mayap" --slug=constructora_mayap
 *   php artisan template:rename --name="Distribuidora Hozana" --slug=distribuidora_hozana --domain=hozana.test
 *
 * NO toca el dominio de la app (modelos, Resources, etc.) — solo metadata.
 * Es idempotente: puede correr múltiples veces sin romper nada.
 */
class TemplateRename extends Command
{
    protected $signature = 'template:rename
                            {--name= : Nombre amigable del proyecto (ej: "Constructora Mayap")}
                            {--slug= : Identificador snake_case (ej: constructora_mayap)}
                            {--domain= : Dominio local Herd (ej: constructora-mayap.test)}
                            {--force : Aplica los cambios sin pedir confirmación}';

    protected $description = 'Renombra la plantilla Olympo a un proyecto específico (edita .env, composer.json, README, etc.).';

    public function handle(): int
    {
        $name = (string) $this->option('name');
        $slug = (string) $this->option('slug');
        $domain = (string) ($this->option('domain') ?? '');

        // Validaciones de entrada
        if ($name === '') {
            $name = (string) $this->ask('¿Cuál es el nombre del proyecto?', 'Mi Proyecto');
        }

        if ($slug === '') {
            $slugSugerido = (string) Str::slug($name, '_');
            $slug = (string) $this->ask('¿Cuál es el slug (snake_case)?', $slugSugerido);
        }

        if ($domain === '') {
            $dominioSugerido = (string) Str::slug($name, '-').'.test';
            $domain = (string) $this->ask('¿Cuál es el dominio Herd local?', $dominioSugerido);
        }

        // Mostrar resumen y pedir confirmación
        $this->newLine();
        $this->info('Se aplicarán los siguientes cambios:');
        $this->table(
            ['Variable', 'Valor'],
            [
                ['APP_NAME', $name],
                ['APP_SLUG', $slug],
                ['APP_BRAND_NAME', $name],
                ['APP_URL', "http://{$domain}"],
                ['DB_DATABASE', $slug],
                ['composer.json name', 'grupo-olympo/'.Str::slug($slug, '-')],
                ['package.json name', Str::slug($slug, '-')],
            ]
        );

        if (! $this->option('force') && ! $this->confirm('¿Aplicar estos cambios?', true)) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        // Reemplazos en archivos de texto
        $reemplazos = [
            'Plantilla Olympo'                        => $name,
            'plantilla_olympo'                        => $slug,
            'plantilla-olympo'                        => Str::slug($slug, '-'),
            'plantilla-olympo.test'                   => $domain,
            'constructora-mayap.test'                 => $domain,
            'grupo-olympo/plantilla-laravel-filament' => 'grupo-olympo/'.Str::slug($slug, '-'),
        ];

        $archivos = [
            base_path('.env'),
            base_path('.env.example'),
            base_path('composer.json'),
            base_path('package.json'),
            base_path('README.md'),
            base_path('docker-compose.yml'),
        ];

        $cambiados = 0;

        foreach ($archivos as $archivo) {
            if (! file_exists($archivo)) {
                $this->warn('  ⊘  Saltando (no existe): '.basename($archivo));

                continue;
            }

            $original = (string) file_get_contents($archivo);
            $modificado = strtr($original, $reemplazos);

            if ($original !== $modificado) {
                file_put_contents($archivo, $modificado);
                $this->info('  ✓  Actualizado: '.basename($archivo));
                $cambiados++;
            } else {
                $this->line('  ·  Sin cambios: '.basename($archivo));
            }
        }

        // Resumen final
        $this->newLine();
        $this->info("Listo. {$cambiados} archivo(s) modificado(s).");
        $this->newLine();

        $this->warn('Próximos pasos manuales:');
        $this->line('  1. Crear la base de datos en Postgres:');
        $this->line("     docker exec -i distribuidora_hozana_postgres psql -U postgres -c \"CREATE DATABASE {$slug};\"");
        $this->line("     docker exec -i distribuidora_hozana_postgres psql -U postgres -c \"CREATE DATABASE {$slug}_test;\"");
        $this->newLine();
        $this->line('  2. Borrar el git de la plantilla y crear el repo nuevo:');
        $this->line('     rm -rf .git && git init');
        $this->newLine();
        $this->line('  3. Linkear con Herd:');
        $this->line("     herd link {$domain}");
        $this->newLine();
        $this->line('  4. Limpiar cache y migrar:');
        $this->line('     php artisan optimize:clear');
        $this->line('     php artisan migrate --seed');

        return self::SUCCESS;
    }
}
