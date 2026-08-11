<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Compromiso;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Qué se hizo con la seña, preguntado en un solo lugar.
 *
 * ═══ POR QUE UNA CLASE Y NO LOS CAMPOS COPIADOS ═══
 *
 * La misma pregunta aparece en TRES pantallas: liberar desde la tabla de
 * apartados, liberar desde el plano, y el trámite suelto de «devolver la
 * seña» cuando el cliente vuelve al día siguiente. Es el mismo argumento de
 * `CobrarUnPago`: esto mueve dinero, y tres copias de un formulario de dinero
 * son dos copias que algún día se separan y una le miente a un cliente.
 *
 * ═══ LAS TRES RESPUESTAS, Y POR QUE LA TERCERA EXISTE ═══
 *
 * Se le devolvió todo · se le devolvió una parte · todavía no.
 *
 * La tercera es la que hace que el resto sea honesto. Decisión de Mauricio el
 * 10-ago: el lote tiene que volver a estar disponible YA, pero el cliente
 * puede no estar ahí para recibir su dinero. Sin esa opción, alguien iba a
 * marcar «devuelto» con tal de poder soltar el lote — y la caja del día
 * cerraría con una salida que nunca pasó.
 *
 * En el trámite suelto la tercera no se ofrece: si el cliente vino a buscar
 * su dinero, «todavía no» no es una respuesta.
 */
final readonly class DevolverLaSenia
{
    public const string TODO = 'todo';

    public const string PARTE = 'parte';

    public const string TODAVIA_NO = 'todavia_no';

    /**
     * Los campos, listos para meter en el `schema()` de una acción.
     *
     * @param bool $puedeDiferir en `liberar()` sí; en el trámite suelto no
     *
     * @return list<Component>
     */
    public static function campos(bool $puedeDiferir = true): array
    {
        return [
            ToggleButtons::make('que_paso')
                ->label('¿Qué se hizo con la seña?')
                ->options(self::opciones($puedeDiferir))
                ->grouped()
                ->live()
                ->required()
                ->default($puedeDiferir ? self::TODAVIA_NO : self::TODO)
                ->extraAttributes(['class' => 'olympo-modo'])
                ->helperText('Si el apartado no dejó seña, dejalo en «Todavía no»: no se registra ninguna salida.'),

            /*
             * Solo en «una parte»: en «todo» el monto es el de la seña y
             * teclearlo sería una oportunidad de equivocarse sin ganar nada.
             */
            MontoField::make('monto_devuelto', 'Cuánto se le devolvió')
                ->required()
                ->live(onBlur: true)
                ->visible(static fn (Get $get): bool => $get('que_paso') === self::PARTE)
                ->helperText('Lo que no se le devuelva queda a favor del proyecto. El sistema no deja devolver más de lo que entregó.'),

            Select::make('forma_devolucion')
                ->label('¿Cómo se le devolvió?')
                ->options(static fn (): array => self::formas())
                ->required()
                ->live()
                ->native(false)
                ->default(FormaDePago::Efectivo->value)
                ->visible(static fn (Get $get): bool => $get('que_paso') !== self::TODAVIA_NO),

            TextInput::make('referencia_devolucion')
                ->label('Número de referencia')
                ->maxLength(60)
                ->visible(static fn (Get $get): bool => self::exigeReferencia($get))
                ->required(static fn (Get $get): bool => self::exigeReferencia($get))
                ->helperText('Es lo único que después permite cruzar esta salida contra el estado de cuenta del banco (R11).'),
        ];
    }

    /**
     * Lo tecleado, en lo que pide el Service.
     *
     * Devuelve `null` en el monto cuando NO hay que registrar nada —no había
     * seña, o se difirió— y ahí `liberar()` deja el pendiente vivo.
     *
     * @param array<string, mixed> $data
     *
     * @return array{devuelto: ?Monto, forma: ?FormaDePago, referencia: ?string}
     */
    public static function loTecleado(array $data, ?Compromiso $apartado): array
    {
        $vacio = ['devuelto' => null, 'forma' => null, 'referencia' => null];
        $senia = self::senia($apartado);

        if (! $senia instanceof Monto) {
            return $vacio;
        }

        $quePaso = is_string($data['que_paso'] ?? null) ? $data['que_paso'] : self::TODAVIA_NO;

        if ($quePaso === self::TODAVIA_NO) {
            return $vacio;
        }

        $forma = is_string($data['forma_devolucion'] ?? null)
            ? FormaDePago::tryFrom($data['forma_devolucion'])
            : null;

        if (! $forma instanceof FormaDePago) {
            return $vacio;
        }

        return [
            // En «todo» el monto es la seña entera y no se teclea.
            'devuelto'   => $quePaso === self::TODO ? $senia : self::tecleado($data),
            'forma'      => $forma,
            'referencia' => is_string($data['referencia_devolucion'] ?? null)
                ? $data['referencia_devolucion']
                : null,
        ];
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private static function opciones(bool $puedeDiferir): array
    {
        $opciones = [
            self::TODO  => 'Se le devolvió todo',
            self::PARTE => 'Solo una parte',
        ];

        if ($puedeDiferir) {
            $opciones[self::TODAVIA_NO] = 'Todavía no';
        }

        return $opciones;
    }

    /**
     * @return array<string, string>
     */
    private static function formas(): array
    {
        $opciones = [];

        foreach (FormaDePago::cases() as $forma) {
            $opciones[$forma->value] = $forma->etiqueta();
        }

        return $opciones;
    }

    private static function exigeReferencia(Get $get): bool
    {
        $forma = $get('forma_devolucion');

        return $get('que_paso') !== self::TODAVIA_NO
            && is_string($forma)
            && FormaDePago::tryFrom($forma)?->exigeReferencia() === true;
    }

    /**
     * Lo que quedó por devolverle a esta persona.
     *
     * ⚠️ En `liberar()` el apartado TODAVIA está vigente cuando se pinta el
     * formulario, y `seniaPorDevolver()` solo contesta sobre los liberados. Por
     * eso acá se mira el monto de la seña directamente: la pregunta es «¿hay
     * dinero de por medio?», no «¿está pendiente?».
     */
    private static function senia(?Compromiso $record): ?Monto
    {
        if (! $record instanceof Compromiso) {
            return null;
        }

        if ($record->getAttribute('senia_devuelta_el') !== null) {
            return null;
        }

        $senia = $record->getAttribute('monto_senia');

        if (! is_string($senia) && ! is_int($senia)) {
            return null;
        }

        $monto = new Monto((string) $senia);

        return $monto->esCero() ? null : $monto;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function tecleado(array $data): ?Monto
    {
        $crudo = $data['monto_devuelto'] ?? null;
        $texto = is_string($crudo) ? trim($crudo) : '';

        return preg_match('/^\d+(\.\d{1,2})?$/', $texto) === 1 ? new Monto($texto) : null;
    }
}
