<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProspectoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * Alguien que vio el plano publico y dejo su telefono.
 *
 * ═══ NO ES UN CLIENTE, Y NO TIENE QUE SERLO ═══
 *
 * Un prospecto no tiene DNI, no firmo nada, y puede ser un numero equivocado
 * o alguien probando. Un `Cliente` es con quien se emite un contrato.
 * Mezclarlos ensuciaria el padron del que salen los expedientes, y el dia que
 * alguien busque «María» tendria que adivinar cual de las dos es.
 *
 * Cuando el prospecto compra se le crea su cliente, y esta fila queda como la
 * traza de por donde llego — que es justo lo que hace falta para saber si el
 * plano publico sirve o no.
 *
 * ═══ ⚠️ SON DATOS PERSONALES DE GENTE QUE NO ES CLIENTE ═══
 *
 * Nombre y telefono de alguien que solo miro una pagina. Va con su policy y
 * su permiso propio; el receptor no los ve. Y `ip` no se muestra en pantalla:
 * esta para el anti-spam y para saber si tres «prospectos» distintos son la
 * misma persona probando el formulario.
 */
#[Fillable([
    'proyecto_id',
    'nombre',
    'telefono',
    'ip',
    'atendido_el',
    'atendido_por',
    'nota',
])]
class Prospecto extends Model
{
    /** @use HasFactory<ProspectoFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'atendido_el' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * Por cuales lotes pregunto, del mas reciente al mas viejo.
     *
     * Antes era un `lote_id` y una fila por consulta: la misma persona
     * preguntando por tres lotes eran tres prospectos, y «ya lo llame»
     * quedaba marcado en uno solo mientras los otros dos seguian pidiendo
     * llamada. Ver la migracion del 23-ago-2026.
     *
     * @return HasMany<LoteConsultado, $this>
     */
    public function consultas(): HasMany
    {
        return $this->hasMany(LoteConsultado::class)->orderByDesc('ultima_vez');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function atendidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendido_por');
    }

    public function estaAtendido(): bool
    {
        return $this->getAttribute('atendido_el') !== null;
    }

    /**
     * Los codigos de los lotes por los que pregunto, para una celda.
     *
     * Se lee de la relacion ya cargada: la tabla la precarga con `with()`, y
     * asi la lista de prospectos no hace una consulta por fila.
     */
    public function lotesEnUnaLinea(): string
    {
        $codigos = $this->consultas
            ->map(static fn (LoteConsultado $consulta): ?string => $consulta->lote?->getAttribute('codigo'))
            ->filter(static fn (?string $codigo): bool => is_string($codigo) && $codigo !== '')
            ->all();

        return $codigos === [] ? '—' : implode(' · ', $codigos);
    }

    /**
     * Los que todavia no llamo nadie. Usa el indice parcial de la migracion.
     *
     * @param Builder<Prospecto> $query
     *
     * @return Builder<Prospecto>
     */
    #[Scope]
    protected function sinAtender(Builder $query): Builder
    {
        return $query->whereNull('atendido_el');
    }
}
