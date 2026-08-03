<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Domain\Plano\AcomodadorDelPlano;
use App\Domain\Plano\Dxf\ImportadorDeDxf;
use App\Domain\Plano\Dxf\OpcionesDeImportacion;
use App\Domain\Plano\Dxf\UnidadDxf;
use App\Domain\Plano\ParametrosDeAcomodo;
use App\Domain\Plano\PlanoDelProyecto;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Bloque;
use App\Models\Proyecto;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * El plano del proyecto: los lotes dibujados y pintados por estado.
 *
 * Es una pagina de SOLO LECTURA. Cambiar el estado de un lote desde el
 * plano exige elegir cliente, y eso vive en su propia accion —no en un
 * clic suelto sobre un poligono, que es dinero moviendose sin registro.
 */
class VerPlano extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProyectoResource::class;

    protected string $view = 'filament.resources.proyectos.pages.ver-plano';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Plano de '.$this->getRecord()->getAttribute('nombre');
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->accionDeImportar(),

            Action::make('acomodar')
                ->label('Acomodar plano')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->modalHeading('Acomodar el plano de forma esquemática')
                ->modalDescription(
                    'Dibuja los lotes que YA existen, en el orden de su código y cada uno con su área '.
                    'real. No toca número, área, precio ni estado. El resultado es un esquema, no el '.
                    'plano del topógrafo: el proyecto queda marcado como esquemático.'
                )
                ->modalSubmitActionLabel('Acomodar')
                ->schema([
                    TextInput::make('fondo')
                        ->label('Fondo de cada lote (varas)')
                        ->numeric()
                        ->required()
                        ->default('25')
                        ->helperText('El frente de cada lote sale de dividir SU área entre este fondo, '.
                                     'así que el rectángulo encierra exactamente el área cargada.'),

                    TextInput::make('filas')
                        ->label('Filas por bloque')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(20)
                        ->required()
                        ->default(2)
                        ->helperText('Dos filas es lo habitual: los lotes se dan la espalda y quedan '.
                                     'con frente a dos calles.'),

                    TextInput::make('separacionBloques')
                        ->label('Separación entre bloques (varas)')
                        ->numeric()
                        ->required()
                        ->default('10')
                        ->helperText('El espacio de calle que queda entre un bloque y el siguiente.'),
                ])
                ->action(function (array $data): void {
                    /** @var Proyecto $proyecto */
                    $proyecto = $this->getRecord();

                    $dibujados = new AcomodadorDelPlano()->acomodarProyecto($proyecto, new ParametrosDeAcomodo(
                        fondoVaras: $this->texto($data, 'fondo', '25'),
                        filas: $this->entero($data, 'filas', 2),
                        separacionBloquesVaras: $this->texto($data, 'separacionBloques', '10'),
                    ));

                    if ($dibujados === 0) {
                        Notification::make()
                            ->title('No había lotes para dibujar')
                            ->body('Este proyecto todavía no tiene lotes cargados en ningún bloque.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Se dibujaron {$dibujados} lotes")
                        ->body('El plano quedó marcado como esquemático: respeta el área de cada lote, '.
                               'pero no su ubicación real en el terreno.')
                        ->success()
                        ->send();

                    $this->redirect(ProyectoResource::getUrl('plano', ['record' => $proyecto]));
                }),

            Action::make('volver')
                ->label('Volver al proyecto')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProyectoResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * Importar el plano del topografo desde un DXF de AutoCAD.
     *
     * Las capas NO se piden en un formulario reactivo a proposito: el
     * archivo se analiza al vuelo y se sugieren solas por su nombre, con
     * el vocabulario que se usa de verdad en los planos de lotificacion.
     * Quien quiera mandar puede escribirlas; quien no, no tiene que saber
     * como se llaman las capas adentro de su propio DXF.
     */
    private function accionDeImportar(): Action
    {
        return Action::make('importarDxf')
            ->label('Importar plano DXF')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->modalHeading('Importar el plano desde AutoCAD')
            ->modalDescription(
                'Lee las polilineas cerradas del archivo y crea un lote por cada una, con su '.
                'area real y el numero que diga el rotulo de adentro. El plano deja de estar '.
                'marcado como esquematico.'
            )
            ->modalSubmitActionLabel('Importar')
            ->schema([
                FileUpload::make('archivo')
                    ->label('Archivo DXF')
                    ->required()
                    ->storeFiles(false)
                    ->maxSize(20480)
                    ->helperText('DXF en formato ASCII, hasta 20 MB. Si el plano esta en DWG, '.
                                 'hay que exportarlo a DXF desde AutoCAD primero.'),

                Select::make('bloque_id')
                    ->label('Bloque donde entran los lotes')
                    ->options(fn (): array => Bloque::query()
                        ->where('proyecto_id', $this->getRecord()->getKey())
                        ->orderBy('orden')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->required()
                    ->helperText('Si el plano trae varias manzanas, conviene importar una por vez '.
                                 'filtrando por su capa.'),

                Select::make('unidad')
                    ->label('¿En que unidad esta dibujado el plano?')
                    ->options(self::unidadesDisponibles())
                    ->default((string) UnidadDxf::Metros->value)
                    ->required()
                    ->helperText('Se pregunta siempre, aunque el archivo lo declare: en planos de '.
                                 'topografia esa variable viene sin configurar muy seguido, y de '.
                                 'este dato sale el area de cada lote.'),

                TextInput::make('precio_vara')
                    ->label('Precio por vara² para los lotes nuevos')
                    ->numeric()
                    ->required()
                    ->default('1200.00'),

                TextInput::make('capa_lotes')
                    ->label('Capa de los lotes (opcional)')
                    ->helperText('En blanco, se detecta sola.'),

                TextInput::make('capa_rotulos')
                    ->label('Capa de los numeros (opcional)'),

                TextInput::make('capa_calles')
                    ->label('Capa de las calles (opcional)'),
            ])
            ->action(function (array $data): void {
                $contenido = $this->contenidoDelArchivo($data['archivo'] ?? null);

                if ($contenido === null) {
                    Notification::make()
                        ->title('No se pudo leer el archivo')
                        ->body('La subida no llego completa. Proba de nuevo.')
                        ->danger()
                        ->send();

                    return;
                }

                $bloque = Bloque::query()->findOrFail($this->entero($data, 'bloque_id', 0));
                $importador = new ImportadorDeDxf;
                $analisis = $importador->analizar($contenido);

                $capaDeLotes = $this->texto($data, 'capa_lotes', '') ?: ($analisis->capaSugeridaDeLotes() ?? '');
                $unidadElegida = $this->texto($data, 'unidad', (string) UnidadDxf::Metros->value);

                $resultado = $importador->importar($bloque, $contenido, new OpcionesDeImportacion(
                    capaDeLotes: $capaDeLotes,
                    precioVara: $this->texto($data, 'precio_vara', '0'),
                    capaDeRotulos: $this->texto($data, 'capa_rotulos', '') ?: $analisis->capaSugeridaDeRotulos(),
                    capaDeCalles: $this->texto($data, 'capa_calles', '') ?: $analisis->capaSugeridaDeCalles(),
                    unidad: UnidadDxf::desde($unidadElegida === 'varas' ? null : (int) $unidadElegida),
                    dibujadoEnVaras: $unidadElegida === 'varas',
                    varaEnMetros: (string) config('lotificadora.area.vara_en_metros', '0.8359'),
                ));

                $cuerpo = sprintf(
                    'Capa de lotes: %s. Area total: %s varas². %s',
                    $capaDeLotes,
                    number_format($resultado->areaTotalVaras, 2),
                    $resultado->callesCreadas > 0 ? "Ademas se dibujaron {$resultado->callesCreadas} calles." : ''
                );

                Notification::make()
                    ->title("Se importaron {$resultado->lotesCreados} lotes")
                    ->body(trim($cuerpo.' '.implode(' ', $resultado->advertencias)))
                    ->success()
                    ->persistent()
                    ->send();

                $this->redirect(ProyectoResource::getUrl('plano', ['record' => $this->getRecord()]));
            });
    }

    /**
     * @return array<string, string>
     */
    private static function unidadesDisponibles(): array
    {
        $opciones = ['varas' => 'El plano ya esta dibujado en varas'];

        foreach (UnidadDxf::cases() as $unidad) {
            if ($unidad->enMetros() !== null) {
                $opciones[(string) $unidad->value] = $unidad->etiqueta();
            }
        }

        return $opciones;
    }

    /**
     * El contenido del DXF subido, sin guardarlo en disco.
     *
     * Se usa storeFiles(false) para que el archivo no quede tirado en el
     * almacenamiento del proyecto: lo que importa son los lotes que salen
     * de el, no el archivo.
     */
    private function contenidoDelArchivo(mixed $estado): ?string
    {
        $archivo = is_array($estado) ? reset($estado) : $estado;

        if (is_object($archivo) && method_exists($archivo, 'get')) {
            $contenido = $archivo->get();

            return is_string($contenido) ? $contenido : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function texto(array $data, string $campo, string $porDefecto): string
    {
        $valor = $data[$campo] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : $porDefecto;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function entero(array $data, string $campo, int $porDefecto): int
    {
        $valor = $data[$campo] ?? null;

        return is_numeric($valor) ? (int) $valor : $porDefecto;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->getRecord();

        return [
            'plano' => new PlanoDelProyecto()->para($proyecto),
        ];
    }
}
