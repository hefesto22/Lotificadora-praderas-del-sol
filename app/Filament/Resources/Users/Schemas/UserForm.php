<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Proyecto;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información Personal')
                    ->description('Nombre, correo electrónico y datos de contacto del usuario.')
                    ->icon('heroicon-o-user')
                    ->aside()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre completo')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-o-user')
                            ->placeholder('Ej: Juan Pérez'),
                        TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->prefixIcon('heroicon-o-envelope')
                            ->placeholder('correo@ejemplo.com'),
                        TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(20)
                            ->prefixIcon('heroicon-o-phone')
                            ->placeholder('+504 9999-9999'),
                    ]),

                Section::make('Foto de Perfil')
                    ->description('Imagen de avatar del usuario. Formato PNG o JPG, máximo 2MB.')
                    ->icon('heroicon-o-camera')
                    ->aside()
                    ->schema([
                        FileUpload::make('avatar_url')
                            ->label('')
                            ->image()
                            ->avatar()
                            ->directory('avatars')
                            ->maxSize(2048),
                    ]),

                Section::make('Seguridad')
                    ->description('Contraseña de acceso y estado de verificación del correo electrónico.')
                    ->icon('heroicon-o-lock-closed')
                    ->aside()
                    ->schema([
                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->rule(Password::default())
                            ->prefixIcon('heroicon-o-key')
                            ->placeholder(
                                fn (string $operation): string => $operation === 'edit' ? 'Dejar vacío para mantener actual' : 'Mínimo 8 caracteres'
                            ),
                        DateTimePicker::make('email_verified_at')
                            ->label('Email verificado el')
                            ->prefixIcon('heroicon-o-check-badge')
                            ->placeholder('Sin verificar'),
                    ]),

                Section::make('Roles y Permisos')
                    ->description('Define qué puede hacer este usuario dentro del sistema.')
                    ->icon('heroicon-o-shield-check')
                    ->aside()
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder('Seleccionar roles'),
                    ]),

                /*
                 * ═══ RECIBE DINERO, Y EN QUÉ PROYECTOS — 24-oct-2026 ═══
                 *
                 * «Hay que hacer un toggle para marcar si ese usuario aparece
                 * como persona que recibe dinero o no, y si no está marcado como
                 * permitido en X proyecto, entonces no aparece en ese
                 * proyecto» — Mauricio.
                 *
                 * El interruptor NO es una columna: es «tiene algún proyecto
                 * marcado». Una sola fuente —`proyecto_receptores`— y no un
                 * booleano que pueda decir que sí con la lista vacía.
                 *
                 * ⚠️ `saveRelationshipsWhenHidden()` es lo que hace que apagar
                 * el interruptor DESMARQUE todo: sin eso, Filament no guarda la
                 * relación de un campo escondido y los proyectos de antes se
                 * quedarían puestos sin que nadie los vea.
                 */
                Section::make('Recibe dinero')
                    ->description('Solo quien esté marcado aparece en «¿Quién recibió el dinero?» al cobrar, apartar o vender en ese proyecto. De ahí sale el corte de caja.')
                    ->icon('heroicon-o-banknotes')
                    ->aside()
                    ->schema([
                        Toggle::make('recibe_dinero')
                            ->label('Recibe dinero de los clientes')
                            ->onColor('success')
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(static function (Toggle $component, ?User $record): void {
                                $component->state($record instanceof User && $record->proyectosDondeRecibe()->exists());
                            })
                            ->afterStateUpdated(static function (?bool $state, Get $get, Set $set): void {
                                if ($state !== true) {
                                    $set('proyectosDondeRecibe', []);

                                    return;
                                }

                                // Con un solo proyecto no hay nada que elegir.
                                $proyectos = Proyecto::query()->pluck('id')->all();
                                $marcados = $get('proyectosDondeRecibe');

                                if (count($proyectos) === 1 && ($marcados === null || $marcados === [])) {
                                    $set('proyectosDondeRecibe', array_map(strval(...), $proyectos));
                                }
                            })
                            ->helperText('Apagado, no aparece en ningún proyecto. Los recibos que ya tiene a su nombre no cambian.'),

                        CheckboxList::make('proyectosDondeRecibe')
                            ->label('¿En qué proyectos?')
                            ->relationship('proyectosDondeRecibe', 'nombre')
                            ->bulkToggleable()
                            ->visible(static fn (Get $get): bool => $get('recibe_dinero') === true)
                            ->required(static fn (Get $get): bool => $get('recibe_dinero') === true)
                            ->validationMessages([
                                'required' => 'Marque al menos un proyecto, o apague «Recibe dinero de los clientes».',
                            ])
                            ->saveRelationshipsWhenHidden(),
                    ]),

                Section::make('Estado de la Cuenta')
                    ->description('Controla si el usuario puede acceder al sistema.')
                    ->icon('heroicon-o-power')
                    ->aside()
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Usuario activo')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger')
                            ->helperText('Si se desactiva, el usuario no podrá iniciar sesión en el panel.'),
                    ]),
            ]);
    }
}
