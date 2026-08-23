<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\RelationManagers;

use App\Models\Cliente;
use App\Support\Roles;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Override;
use Spatie\Activitylog\Models\Activity;

/**
 * Qué se le tocó a este expediente, cuándo y quién — 22-ago-2026.
 *
 * ═══ DE DONDE SALIO ═══
 *
 * Mauricio corrigió un apellido mal escrito —ORTIZ por ORTIS— y quedó la
 * pregunta obvia: mañana, ¿cómo se sabe que el nombre del contrato se
 * cambió, cuándo y quién lo hizo? El dato YA estaba guardado: `Cliente` y
 * `Venta` registran actividad desde siempre. Lo que no había era dónde
 * mirarlo sin salir del expediente: la bitácora general está en
 * Administración, mezcla los 116 expedientes y no se filtra por uno.
 *
 * ═══ SOLO SE MIRA, Y NI EL SUPER_ADMIN LO EDITA ═══
 *
 * Sin crear, sin editar, sin borrar. Un historial que se puede editar no
 * prueba nada: ni ante un cliente que reclama que su nombre estaba bien, ni
 * ante uno mismo dentro de dos años. Que no haya botones no es un descuido
 * de esta clase — es la única forma en que sirve.
 *
 * ═══ POR QUE POR ROL Y NO POR PERMISO ═══
 *
 * `canViewForRecord()` pregunta por el rol `super_admin` y no por un
 * permiso de Shield. Es a propósito: un permiso se le puede dar a un rol
 * nuevo sin querer —Shield los genera solo y se asignan en lote— y esta
 * pestaña dice quién tocó qué, que es información sobre las personas que
 * usan el sistema, no sobre los lotes. El día que haya que abrirla a otro
 * rol, se abre acá, a mano y a propósito.
 */
class ActualizacionesRelationManager extends RelationManager
{
    /**
     * ⚠️ `Venta::actualizaciones()` es un HasMany armado con
     * `Relation::noConstraints()` — leer el porqué allá antes de tocarlo.
     *
     * Lo que importa acá: sigue siendo una relación de verdad —Filament lo
     * exige aunque su firma diga `Relation|Builder`— pero su condición no
     * cuelga de una llave foránea, así que attach, associate y reordenar no
     * significan nada sobre ella. No hay ninguno, y no puede haberlo.
     */
    #[Override]
    protected static string $relationship = 'actualizaciones';

    #[Override]
    protected static ?string $title = 'Actualizaciones';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    /**
     * Quién ve la pestaña.
     *
     * Reemplaza al camino por defecto del padre, que autoriza mirando la
     * policy del modelo relacionado. Acá no serviría: la policy sería la de
     * `Activity`, la misma que gobierna la bitácora general de
     * Administración, y esta pestaña se abre a menos gente que aquella.
     */
    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->hasRole(Roles::SUPER_ADMIN) === true;
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        // No se edita nada, pero el contrato del padre pide el método.
        return $schema->components([]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                /*
                 * El renglón contesta «¿en qué?» antes que «¿qué?»: un
                 * cambio de nombre y uno de estado se leen igual si no se
                 * sabe primero sobre qué cayeron.
                 */
                TextColumn::make('subject_type')
                    ->label('En qué')
                    ->badge()
                    ->state(static fn (Activity $record): string => self::esDeUnCliente($record)
                        ? 'Dueño'
                        : 'Expediente')
                    ->color(static fn (Activity $record): string => self::esDeUnCliente($record)
                        ? 'gray'
                        : 'primary')
                    ->description(static fn (Activity $record): ?string => self::esDeUnCliente($record)
                        ? self::nombreDelSujeto($record)
                        : null),

                /*
                 * Un renglón por campo, «antes → ahora», que es como se lee
                 * en voz alta cuando alguien pregunta qué pasó.
                 */
                TextColumn::make('attribute_changes')
                    ->label('Qué cambió')
                    ->state(static fn (Activity $record): array => self::comoLineas($record))
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->wrap()
                    // El motivo, cuando lo hay: `CambioDeTitular` lo guarda
                    // en `properties` porque no es un campo que cambió.
                    ->description(static fn (Activity $record): ?string => self::motivo($record)),

                TextColumn::make('causer.name')
                    ->label('Quién')
                    // Sin causer = lo hizo el sistema: un seeder, un comando
                    // o una importación. No es un hueco, es una respuesta.
                    ->placeholder('El sistema'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nadie le ha cambiado nada')
            ->emptyStateDescription('Acá van a ir apareciendo, solas, las modificaciones al expediente y a los datos de sus dueños.')
            ->emptyStateIcon('heroicon-o-clock');
    }

    private static function esDeUnCliente(Activity $registro): bool
    {
        return $registro->getAttribute('subject_type') === Cliente::class;
    }

    private static function nombreDelSujeto(Activity $registro): ?string
    {
        $nombre = $registro->subject?->getAttribute('nombre');

        return is_string($nombre) && $nombre !== '' ? $nombre : null;
    }

    private static function motivo(Activity $registro): ?string
    {
        $motivo = $registro->properties->get('motivo');

        return is_string($motivo) && trim($motivo) !== '' ? 'Motivo: '.trim($motivo) : null;
    }

    /**
     * El diff, un renglón por campo.
     *
     * Si no hay diff —un alta, una baja— cae en la descripción del asiento,
     * que para esos casos es lo único que hay y alcanza.
     *
     * @return list<string>
     */
    private static function comoLineas(Activity $registro): array
    {
        $ahora = $registro->attribute_changes?->get('attributes');
        $antes = $registro->attribute_changes?->get('old');

        if (! is_array($ahora) || $ahora === []) {
            return [(string) $registro->getAttribute('description')];
        }

        $lineas = [];

        foreach ($ahora as $campo => $nuevo) {
            $viejo = is_array($antes) ? ($antes[$campo] ?? null) : null;

            $lineas[] = sprintf(
                '%s: %s → %s',
                str_replace('_', ' ', (string) $campo),
                self::enTexto($viejo),
                self::enTexto($nuevo),
            );
        }

        return $lineas;
    }

    /**
     * ⚠️ El orden de los brazos importa: un booleano TAMBIEN es escalar, y
     * con `is_scalar` arriba `false` se imprimiría como cadena vacía — un
     * «activo: → » que no dice nada.
     */
    private static function enTexto(mixed $valor): string
    {
        return match (true) {
            $valor === null   => '—',
            $valor === true   => 'sí',
            $valor === false  => 'no',
            is_scalar($valor) => (string) $valor,
            default           => json_encode($valor, JSON_UNESCAPED_UNICODE) ?: '—',
        };
    }
}
