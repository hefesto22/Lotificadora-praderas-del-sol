<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\BrandingSetting;
use App\Support\ImageOptimizer;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Página de configuración del branding del sistema.
 *
 * Permite al super-admin (o a quien tenga permiso) editar:
 *  - Logo: imagen visible en la barra superior del panel
 *  - Favicon: ícono de la pestaña del navegador
 *  - Color primario: tono base de botones y acentos
 *
 * Singleton — siempre edita la única fila de branding_settings.
 *
 * @property Schema $form
 */
class BrandingSettingsPage extends Page
{
    protected string $view = 'filament.pages.branding-settings';

    public function getTitle(): string
    {
        return 'Configuración del Sistema';
    }

    public static function getNavigationLabel(): string
    {
        return 'Configuración';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Sistema';
    }

    public static function getNavigationSort(): ?int
    {
        return 99;
    }

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $setting = BrandingSetting::current();

        $this->form->fill([
            'logo_path'     => $setting->logo_path,
            'favicon_path'  => $setting->favicon_path,
            'primary_color' => $setting->primary_color,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Identidad visual')
                    ->description('Logo y favicon que aparecen en el panel y en la pestaña del navegador.')
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->helperText('Sube cualquier imagen (PNG, JPG, SVG, WebP). Se convertirá automáticamente a WebP optimizado. Máximo 5 MB.')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->maxSize(5120)
                            ->acceptedFileTypes([
                                'image/png',
                                'image/jpeg',
                                'image/svg+xml',
                                'image/webp',
                                'image/gif',
                            ])
                            ->saveUploadedFileUsing(static fn (TemporaryUploadedFile $file): string => ImageOptimizer::toWebp($file, 'branding')),

                        FileUpload::make('favicon_path')
                            ->label('Favicon')
                            ->helperText('Sube PNG/JPG/WebP (se convierte a PNG cuadrado 64x64) o un .ico/.svg (se guarda tal cual). Máximo 1 MB.')
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->maxSize(1024)
                            ->acceptedFileTypes([
                                'image/png',
                                'image/jpeg',
                                'image/webp',
                                'image/x-icon',
                                'image/vnd.microsoft.icon',
                                'image/svg+xml',
                            ])
                            ->saveUploadedFileUsing(static fn (TemporaryUploadedFile $file): string => ImageOptimizer::toFavicon($file, 'branding')),
                    ])
                    ->columns(2),

                Section::make('Color del panel')
                    ->description('Tono base de botones, acentos y elementos interactivos.')
                    ->schema([
                        ColorPicker::make('primary_color')
                            ->label('Color primario')
                            ->required()
                            ->helperText('Selecciona el color principal del sistema. Filament generará automáticamente la paleta completa de tonos.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $datos = $this->form->getState();

        BrandingSetting::current()->update([
            'logo_path'     => $datos['logo_path'] ?? null,
            'favicon_path'  => $datos['favicon_path'] ?? null,
            'primary_color' => $datos['primary_color'] ?? '#f59e0b',
        ]);

        Notification::make()
            ->title('Configuración guardada')
            ->body('Los cambios visuales se aplicarán al recargar la página.')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'hasRole')
            && $user->hasRole(Utils::getSuperAdminName());
    }
}
