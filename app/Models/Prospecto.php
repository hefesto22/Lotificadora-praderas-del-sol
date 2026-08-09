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
    'lote_id',
    'nombre',
    'telefono',
    'mensaje',
    'plazo_meses',
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
            'plazo_meses' => 'integer',
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
     * Por cual lote preguntaba. Null si escribio por el proyecto en general.
     *
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
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
     * Como se lee el plazo que miraba cuando escribio.
     *
     * Es media conversacion: quien miraba 48 meses no quiere lo mismo que
     * quien miraba contado, y saberlo antes de marcar el telefono cambia como
     * arranca la llamada.
     */
    public function plazoEnPalabras(): string
    {
        $meses = $this->getAttribute('plazo_meses');

        if (! is_int($meses)) {
            return 'No indicó';
        }

        return $meses === 0 ? 'Contado' : $meses.' meses';
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
