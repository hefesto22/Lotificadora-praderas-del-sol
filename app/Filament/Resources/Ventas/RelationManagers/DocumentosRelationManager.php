<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\RelationManagers;

use App\Domain\Archivos\GuardadoDeArchivos;
use App\Domain\Enums\TipoDeDocumento;
use App\Models\Documento;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Override;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La carpeta del expediente: los papeles escaneados.
 *
 * «Para guardar la promesa de venta, debe poder guardarse en el expediente de
 * la venta» — reunión del 6-ago-2026.
 *
 * ═══ EL ARCHIVO NO SE SIRVE POR URL ═══
 *
 * Va al disco privado y se descarga con una acción que pasa por la política.
 * Una promesa firmada y una copia de identidad llevan datos personales, y una
 * URL pública se filtra sola: se pega en un chat, queda en el historial, viaja
 * en una captura. Que el nombre del archivo sea imposible de adivinar no es
 * seguridad, es suerte.
 */
class DocumentosRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'documentos';

    #[Override]
    protected static ?string $title = 'Documentos';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-paper-clip';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tipo')
                    ->label('Qué papel es')
                    ->options($this->tipos())
                    ->required()
                    ->native(false)
                    ->default(TipoDeDocumento::PromesaDeVenta->value),

                TextInput::make('nombre')
                    ->label('Nombre')
                    ->maxLength(120)
                    ->required()
                    ->helperText('Con el que se va a buscar después: «Promesa firmada — 06/08/2026».'),

                /*
                 * `preserveFilenames` NO: el nombre original puede traer
                 * acentos, espacios y el apellido del cliente. Filament le
                 * pone un uuid y el nombre legible vive en la columna.
                 */
                FileUpload::make('archivo')
                    ->label('Archivo')
                    ->disk(Documento::DISCO)
                    ->directory('documentos')
                    ->visibility('private')
                    ->required()
                    ->maxSize(10240)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                    ->helperText('PDF o foto, hasta 10 MB. Las fotos se guardan convertidas a WebP: pesan seis veces menos y abren al instante.')
                    /*
                     * 🔴 El guardado pasa por `GuardadoDeArchivos`: una foto de
                     * teléfono entra en 2–5 MB y sale en WebP de 250–400 KB,
                     * con el lado largo topado en 2,400 px. El PDF pasa
                     * intacto, y ante cualquier problema —GD sin WebP, imagen
                     * corrupta— se guarda el original: un comprobante no se
                     * pierde por optimizarlo.
                     *
                     * El PESO no se calcula acá. Lo lee el modelo del disco
                     * después de convertir, que es el único momento en que el
                     * número es cierto.
                     */
                    /*
                     * 🔴 LOS NOMBRES `$component` Y `$file` SON UN CONTRATO.
                     *
                     * Filament evalúa este closure con `evaluate($callback,
                     * ['file' => $file])`: busca los parámetros POR NOMBRE en
                     * ese arreglo y, al que no encuentra, lo pide al
                     * contenedor. Llamarle `$subido` hace que Filament intente
                     * construir un `TemporaryUploadedFile` desde cero y
                     * reviente con `BindingResolutionException:
                     * Unresolvable dependency [$path]`.
                     *
                     * Por eso van en inglés, contra el estilo del resto del
                     * repo: acá el nombre no es una decisión nuestra.
                     */
                    ->saveUploadedFileUsing(static fn (BaseFileUpload $component, TemporaryUploadedFile $file): ?string => resolve(GuardadoDeArchivos::class)->guardar($component, $file)),

                Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2),
            ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nombre')
            ->columns([
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(static fn (Documento $record): string => $record->getAttribute('tipo') instanceof TipoDeDocumento
                        ? $record->getAttribute('tipo')->color()
                        : 'gray')
                    ->formatStateUsing(static fn (Documento $record): string => $record->getAttribute('tipo') instanceof TipoDeDocumento
                        ? $record->getAttribute('tipo')->etiqueta()
                        : '—')
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('bytes')
                    ->label('Peso')
                    ->alignEnd()
                    ->color('gray')
                    ->formatStateUsing(static fn (Documento $record): string => $record->peso()),

                TextColumn::make('created_at')
                    ->label('Guardado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Guardar un papel'),
            ])
            ->recordActions([
                /*
                 * La descarga es una ACCION y no un enlace: pasa por la
                 * politica antes de abrir el archivo. Un enlace directo al
                 * disco se lo puede pegar cualquiera en el navegador.
                 */
                Action::make('descargar')
                    ->label('Descargar')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(static fn (Documento $record): bool => auth()->user()?->can('view', $record) === true)
                    ->action(static fn (Documento $record): StreamedResponse => Storage::disk(Documento::DISCO)
                        ->download((string) $record->getAttribute('archivo'), $record->nombreDeDescarga())),

                EditAction::make(),

                DeleteAction::make()
                    ->modalDescription('El archivo se borra del servidor y no se puede recuperar. Si el papel sigue existiendo en la carpeta física, mejor dejarlo.'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('El expediente no tiene papeles todavía')
            ->emptyStateDescription('Acá va la promesa de venta firmada, la copia de identidad y los comprobantes.')
            ->emptyStateIcon('heroicon-o-paper-clip');
    }

    #[Override]
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    private function tipos(): array
    {
        $opciones = [];

        foreach (TipoDeDocumento::cases() as $tipo) {
            $opciones[$tipo->value] = $tipo->etiqueta();
        }

        return $opciones;
    }
}
