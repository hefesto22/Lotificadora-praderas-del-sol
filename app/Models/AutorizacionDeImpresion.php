<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Una autorización del SAR: la CAI, su rango de correlativos y hasta cuándo.
 *
 * Son VARIAS por facturación a lo largo del tiempo, no una. Dura un año
 * como máximo (Acuerdo 481-2017, Art. 62) y cuando se agota el rango se
 * pide otra, con CAI nueva y rango nuevo — pero **la numeración no
 * reinicia**: sigue de largo hasta 99999999 (Art. 10, num. 7).
 *
 * ⚠️ Las viejas NO se borran. Con qué autorización se emitió cada factura
 * es lo primero que pregunta una fiscalización, y una factura reimpresa
 * tiene que salir con la CAI que llevaba impresa, no con la de hoy.
 */
#[Fillable([
    'facturacion_id',
    'cai',
    'correlativo_desde',
    'correlativo_hasta',
    'proximo_correlativo',
    'autorizada_el',
    'fecha_limite_emision',
    'observaciones',
])]
#[Table(name: 'autorizaciones_de_impresion')]
class AutorizacionDeImpresion extends Model
{
    use HasAuditFields;
    use LogsActivity;

    /**
     * Cuándo empieza a avisar que se está por vencer o por agotar.
     *
     * Dos meses de aviso porque es EXACTAMENTE la ventana en la que el
     * reglamento deja pedir la siguiente: «dentro de los dos (2) meses
     * previos a la fecha límite de emisión» (Art. 59). Avisar antes sería
     * ruido; avisar después, tarde.
     */
    public const int DIAS_DE_AVISO = 60;

    /** Con menos de esto por delante, el rango ya es un problema. */
    public const int DOCUMENTOS_DE_AVISO = 50;

    /**
     * @return BelongsTo<Facturacion, $this>
     */
    public function facturacion(): BelongsTo
    {
        return $this->belongsTo(Facturacion::class);
    }

    /**
     * ¿Se puede emitir con esta autorización hoy?
     *
     * Las dos condiciones a la vez, porque cualquiera de las dos la mata:
     * una CAI vencida no sirve aunque sobren correlativos, y un rango
     * agotado no sirve aunque falten meses.
     */
    public function sirveHoy(): bool
    {
        return ! $this->estaVencida() && $this->quedanDocumentos() > 0;
    }

    public function estaVencida(): bool
    {
        $limite = $this->getAttribute('fecha_limite_emision');

        return $limite instanceof Carbon && $limite->isBefore(today());
    }

    /**
     * Cuántas facturas quedan por emitir. Nunca negativo.
     */
    public function quedanDocumentos(): int
    {
        $hasta = (int) $this->getAttribute('correlativo_hasta');
        $proximo = (int) $this->getAttribute('proximo_correlativo');

        return max(0, $hasta - $proximo + 1);
    }

    public function totalDocumentos(): int
    {
        return (int) $this->getAttribute('correlativo_hasta')
            - (int) $this->getAttribute('correlativo_desde')
            + 1;
    }

    /**
     * Días que faltan para la fecha límite. Negativo si ya pasó.
     */
    public function diasParaVencer(): int
    {
        $limite = $this->getAttribute('fecha_limite_emision');

        return $limite instanceof Carbon ? (int) today()->diffInDays($limite, false) : 0;
    }

    /**
     * ¿Hay que empezar a tramitar la siguiente?
     *
     * Por cualquiera de los dos lados: porque se acaba el tiempo o porque
     * se acaban los números. Es lo que dibuja el aviso en pantalla.
     */
    public function convieneRenovar(): bool
    {
        if ($this->diasParaVencer() <= self::DIAS_DE_AVISO) {
            return true;
        }

        return $this->quedanDocumentos() <= self::DOCUMENTOS_DE_AVISO;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'correlativo_desde'    => 'integer',
            'correlativo_hasta'    => 'integer',
            'proximo_correlativo'  => 'integer',
            'autorizada_el'        => 'date',
            'fecha_limite_emision' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['cai', 'correlativo_desde', 'correlativo_hasta', 'proximo_correlativo', 'autorizada_el', 'fecha_limite_emision'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento): string => "Autorizacion de impresion {$evento}");
    }
}
