<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\Foto360InvalidaException;
use App\Domain\Plano\Foto360;
use App\Domain\Plano\MarcasDelLote;
use App\Filament\Schemas\Components\AreaField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Bloque;
use App\Models\Lote;
use BackedEnum;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Override;

/**
 * Los lotes del proyecto, adentro del proyecto.
 *
 * ═══ ESTA NO ES LA PANTALLA DE CARGA MASIVA ═══
 *
 * Los 301 lotes de Praderas del Sol entraron con el importador de DXF, y el
 * precio se fija de a bloques enteros con la acción del encabezado. Esta
 * tabla es para **el caso suelto**: corregir un número mal leído del plano,
 * cambiarle el precio a un lote de esquina, cancelar uno.
 *
 * Por eso el botón de crear existe pero no es el protagonista: agregar 301
 * lotes desde acá sería un error de método, no de paciencia.
 *
 * El selector de bloque solo ofrece los del proyecto de la ficha. No es
 * cortesía: la FK compuesta `(bloque_id, proyecto_id)` de la base rechaza un
 * bloque de otro proyecto, y es mejor no ofrecerlo que explicar el error
 * después de apretar guardar.
 */
class LotesRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'lotes';

    #[Override]
    protected static ?string $title = 'Lotes';

    /** Mismo tipo exacto que el padre; ver la nota en BloquesRelationManager. */
    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-squares-2x2';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('bloque_id')
                    ->label('Bloque')
                    ->options(fn (): array => Bloque::query()
                        ->where('proyecto_id', $this->getOwnerRecord()->getKey())
                        ->orderBy('orden')
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Solo los bloques de este proyecto.'),

                MayusculasField::make('numero')
                    ->label('Número de lote')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('12 o 12-A')
                    ->helperText('Único dentro del bloque.'),

                AreaField::make('area_varas', 'Área')
                    ->required()
                    ->disabled(fn (?Lote $record): bool => $this->estaVendido($record)),

                MontoField::make('precio_vara', 'Precio por vara²')
                    ->disabled(fn (?Lote $record): bool => $this->estaVendido($record))
                    ->helperText('El valor se calcula solo: área × precio.'),

                /*
                 * ═══ LA FOTO 360 DEL TERRENO ═══
                 *
                 * Sube el archivo crudo de la cámara —6000×3000, hasta veinte
                 * megas— y lo que queda guardado es de 4096×2048 y medio mega.
                 * Todo eso pasa en `Foto360`; acá solo se le entrega el
                 * archivo temporal y se guarda la ruta que devuelve.
                 *
                 * `saveUploadedFileUsing` y no un hook después de guardar: si
                 * la foto no sirve —no es 2:1, es enorme, no se puede abrir—
                 * el error tiene que salir en el campo, junto al archivo que
                 * la administradora acaba de elegir, y no como un 500 después
                 * de que el formulario dijo «guardado».
                 */
                FileUpload::make('foto360_path')
                    ->label('Foto 360 del lote')
                    ->image()
                    ->disk('public')
                    ->directory('lotes/360')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    // Lo que sale de una cámara 360 sin exportar reducido.
                    // Acompaña al tope de Livewire en config/livewire.php: si
                    // este fuera mayor, el archivo se subiría entero para que
                    // lo rechace el otro, con un mensaje que no dice nada.
                    ->maxSize(30 * 1024)
                    ->helperText(
                        'La foto tal como la exporta la cámara 360 (el doble de ancha que de alta, '
                        .'por ejemplo 6000×3000). Se reduce sola a 4096×2048 para que cargue rápido '
                        .'en el teléfono del cliente. Aparece en el plano público con el botón «Ver 360».'
                    )
                    /*
                     * ⚠️ EL PARÁMETRO SE LLAMA `$file` Y NO PUEDE LLAMARSE DE
                     * OTRA FORMA.
                     *
                     * Filament inyecta los argumentos de estos closures POR
                     * NOMBRE (`BaseFileUpload` los pasa como `['file' => ...]`).
                     * Con cualquier otro nombre no encuentra el argumento, cae
                     * a resolver `TemporaryUploadedFile` del contenedor, y
                     * revienta con «Unresolvable dependency ... $path» al
                     * guardar — no al subir, que es lo que hace que cueste
                     * relacionarlo. Ya nos pasó con `modifyRuleUsing`.
                     */
                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, ?Lote $record, FileUpload $component): string {
                        $foto = new Foto360;

                        try {
                            // Reemplazar deja huérfana a la anterior: se borra
                            // acá, que es el único lugar que sabe cuál era.
                            $foto->borrar($record?->getAttribute('foto360_path'));

                            return $foto->guardar(
                                // Sin cast: `TemporaryUploadedFile::getRealPath()`
                                // ya devuelve string y Rector lo marca (Recasting).
                                $file->getRealPath(),
                                (int) ($record?->getKey() ?? 0),
                            );
                        } catch (Foto360InvalidaException $error) {
                            /*
                             * ⚠️ Sin esto, «la foto mide 12000 y el máximo es
                             * 8192» sale como una pantalla de Internal Server
                             * Error: un mensaje escrito para ayudar, servido de
                             * la forma que más asusta.
                             *
                             * `getStatePath()` da la clave exacta de ESTE campo
                             * —adentro de un modal de relation manager es algo
                             * como `mountedActions.0.data.foto360_path`— así que
                             * el texto aparece debajo del archivo que la
                             * administradora acaba de elegir, que es donde puede
                             * hacer algo con él.
                             */
                            throw ValidationException::withMessages([
                                $component->getStatePath() => $error->getMessage(),
                            ]);
                        }
                    })
                    ->deleteUploadedFileUsing(function (string $file): void {
                        (new Foto360)->borrar($file);
                    })
                    ->columnSpanFull(),

                /*
                 * ═══ LAS MARCAS, PEGADAS DEL EDITOR ═══
                 *
                 * El contorno y los rótulos NO se queman en la foto, y esa es
                 * toda la diferencia: una línea dentro del JPG deja de ser una
                 * línea —se reduce, se realza, se comprime— y al hacer zoom el
                 * cliente agranda píxeles. Guardada como ángulos, el visor la
                 * traza como vector en cada cuadro.
                 *
                 * Se validan al guardar y otra vez al publicar (`MarcasDelLote`):
                 * este es un textarea abierto, y lo que se escriba acá llega al
                 * navegador de cualquiera que abra el link.
                 */
                Textarea::make('foto360_marcas')
                    ->label('Marcas del 360 (contorno y rótulos)')
                    ->rows(3)
                    ->placeholder('[{"tipo":"contorno", …}]')
                    ->helperText(
                        'Se pega desde el editor 360, con el botón «Copiar marcas». Las líneas se '
                        .'dibujan como vectores, así que se ven nítidas a cualquier zoom y el texto '
                        .'queda fijo donde lo dejaste. Vacío = sin marcas.'
                    )
                    /*
                     * El formulario maneja TEXTO; la columna guarda una lista.
                     *
                     * `JSON_THROW_ON_ERROR` y no un `?: null`: sin la bandera,
                     * `json_encode` puede devolver `false` —PHPStan lo marca, y
                     * con razón— y taparlo con un null dejaría el campo en
                     * blanco frente a la administradora, que apretaría guardar
                     * y borraría las marcas del lote sin enterarse. Que
                     * reviente es preferible: significa que en la columna hay
                     * algo que no debería estar ahí.
                     */
                    ->formatStateUsing(fn (mixed $state): ?string => match (true) {
                        is_array($state)  => $state === [] ? null : json_encode($state, JSON_THROW_ON_ERROR),
                        is_string($state) => $state,
                        default           => null,
                    })
                    ->dehydrateStateUsing(
                        fn (mixed $state): array => resolve(MarcasDelLote::class)
                            ->desdeElTexto(is_string($state) ? $state : null)
                    )
                    ->rule(fn (): Closure => function (string $atributo, mixed $valor, Closure $falla): void {
                        try {
                            resolve(MarcasDelLote::class)->desdeElTexto(is_string($valor) ? $valor : null);
                        } catch (Foto360InvalidaException $error) {
                            $falla($error->getMessage());
                        }
                    })
                    ->columnSpanFull(),
            ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('codigo')
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('medium'),

                TextColumn::make('bloque.nombre')
                    ->label('Bloque')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('area_varas')
                    ->label('Área')
                    ->suffix(' varas²')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('precio_vara')
                    ->label('Precio/vara²')
                    ->prefix('L ')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('valor')
                    ->label('Valor')
                    ->prefix('L ')
                    ->alignEnd()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoLote $state): string => $state->color())
                    ->formatStateUsing(fn (EstadoLote $state): string => $state->etiqueta())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('bloque_id')
                    ->label('Bloque')
                    ->options(fn (): array => Bloque::query()
                        ->where('proyecto_id', $this->getOwnerRecord()->getKey())
                        ->orderBy('orden')
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all()),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoLote::cases())
                        ->mapWithKeys(fn (EstadoLote $estado): array => [$estado->value => $estado->etiqueta()])
                        ->all())
                    ->multiple(),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo lote'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            /*
            | Por `codigo` y no por `numero`: `numero` es texto y ordenaba
            | 1, 10, 11, 12… dejando el lote 2 después del 19. El código
            | lleva el número con relleno a 3 dígitos, así que su orden
            | alfabético ES el orden correcto.
            */
            ->defaultSort('codigo')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Este proyecto todavía no tiene lotes')
            ->emptyStateDescription('Importá el plano DXF desde "Ver plano": carga los lotes con su área y su polígono.');
    }

    /**
     * §9.A2: en CREATE el schema recibe un modelo VACÍO, no null. Por eso se
     * lee el estado crudo contra el value del enum.
     */
    private function estaVendido(?Lote $record): bool
    {
        return $record?->getRawOriginal('estado') === EstadoLote::Vendido->value;
    }
}
