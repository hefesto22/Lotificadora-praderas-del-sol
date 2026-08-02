<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BrandingSetting;
use Illuminate\Database\Seeder;

class BrandingSettingSeeder extends Seeder
{
    public function run(): void
    {
        BrandingSetting::firstOrCreate(
            [],
            [
                'logo_path'     => null,
                'favicon_path'  => null,
                'primary_color' => '#f59e0b', // amber-500 (default Filament Olympo)
            ]
        );

        $this->command?->info('✓ Branding setting inicializado.');
    }
}
