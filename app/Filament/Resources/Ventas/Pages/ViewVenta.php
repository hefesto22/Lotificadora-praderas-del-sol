<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Pagos\EfectoDelAbono;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Support\ImprimirRecibo;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as Cuotas;
use Illuminate\Support\HtmlString;
use Override;

/**
 * La ficha del expediente.
 *
 * Dos acciones, y las dos mueven dinero: COBRAR una cuota, que es lo que se
 * hace todos los días, y ABONAR A CAPITAL, que además reescribe el plan.
 * Editar una venta firmada no es una acción genérica (ver el docblock de
 * `VentaResource`): rescindir, liquidar e imprimir el contrato entran acá
 * cuando se construya cada trámite, cada uno con su nombre y su motivo.
 */
class ViewVenta extends ViewRecord
{
    #[Override]
    protected static string $resource = VentaResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->estadoDeCuentaAction(),
            $this->cobrarAction(),
            $this->abonarACapitalAction(),
        ];
    }

    /**
     * El estado de cuenta del expediente, listo para el papel.
     *
     * Sin `visible()`: quien está parado en esta página ya tiene `View:Venta`,
     * que es exactamente el permiso que pide el documento. Repetir la
     * comprobación acá sería una condición más que mantener sincronizada con
     * el controlador, para la misma decisión.
     *
     * Pestaña nueva, como el recibo: quien atiende no pierde el expediente.
     */
    private function estadoDeCuentaAction(): Action
    {
        return Action::make('estado_de_cuenta')
            ->label('Estado de cuenta')
            ->icon(Heroicon::OutlinedDocumentChartBar)
            ->color('gray')
            ->url(fn (): string => route('documentos.estado-de-cuenta', $this->venta()))
            ->openUrlInNewTab();
    }

    /**
     * Registrar un pago.
     *
     * ═══ SE MUESTRA EL REPARTO ANTES DE CONFIRMAR ═══
     *
     * §10.8: «el usuario debe ver el número de cuota antes de confirmar, no
     * después». Quien atiende tiene un cliente enfrente preguntando «¿y con
     * esto qué me queda?», y la respuesta no puede llegar después de apretar
     * el botón.
     *
     * El cuadro es un estimado que se calcula con las MISMAS reglas que
     * después persisten —FIFO, la cuota más vieja primero—, pero el que manda
     * es el Service: relee las cuotas con `FOR UPDATE` dentro de la
     * transacción, porque entre que se pintó la pantalla y se apretó Guardar
     * el otro receptor pudo cobrar lo mismo.
     */
    private function cobrarAction(): Action
    {
        return Action::make('cobrar')
            ->label('Registrar un pago')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (): bool => $this->venta()->getAttribute('estado') === EstadoVenta::Vigente
                && auth()->user()?->can('create', Recibo::class) === true)
            ->modalHeading('Registrar un pago')
            ->modalDescription('Se aplica a las cuotas más viejas primero, y queda su recibo con número.')
            ->modalSubmitActionLabel('Cobrar y emitir el recibo')
            ->modalWidth('2xl')
            ->fillForm(fn (): array => [
                'compromiso_id' => $this->primerLoteConSaldo()?->getKey(),
                'fecha'         => today()->toDateString(),
                'forma_pago'    => FormaDePago::Efectivo->value,
            ])
            ->schema([
                Select::make('compromiso_id')
                    ->label('¿A qué lote?')
                    ->options(fn (): array => $this->lotesConSaldo())
                    ->required()
                    ->live()
                    ->native(false)
                    ->helperText('Cada lote tiene su propio plan: un pago va contra uno.'),

                MontoField::make('monto', 'Monto recibido')
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('Puede ser menos que la cuota: lo que falte se arrastra, sin recargo (R2).'),

                Select::make('forma_pago')
                    ->label('Forma de pago')
                    ->options(fn (): array => $this->formasDePago())
                    ->required()
                    ->live()
                    ->native(false),

                TextInput::make('referencia')
                    ->label('Número de referencia')
                    ->maxLength(60)
                    ->visible(fn (Get $get): bool => $this->exigeReferencia($get))
                    ->required(fn (Get $get): bool => $this->exigeReferencia($get))
                    ->helperText('Es lo único que después permite cruzar este recibo contra el estado de cuenta del banco (R11).'),

                /*
                 * §10.8: «el usuario debe ver el número de cuota antes de
                 * confirmar, no después». Quien atiende tiene un cliente
                 * enfrente preguntando «¿y con esto qué me queda?».
                 */
                Placeholder::make('reparto')
                    ->label('Cómo se va a repartir')
                    ->columnSpanFull()
                    ->content(fn (Get $get): HtmlString => $this->repartoEstimado($get)),

                DatePicker::make('fecha')
                    ->label('Fecha del pago')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y'),

                Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                try {
                    $recibo = app(RegistroDePagos::class)->cobrarCuotas(
                        venta: $this->venta(),
                        lote: Compromiso::query()->findOrFail($data['compromiso_id']),
                        cliente: $this->venta()->titular() ?? $this->venta()->clientes()->firstOrFail(),
                        monto: new Monto((string) ($data['monto'] ?? '0')),
                        forma: FormaDePago::from((string) $data['forma_pago']),
                        referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
                        fecha: CarbonImmutable::parse((string) $data['fecha']),
                        observaciones: is_string($data['observaciones'] ?? null) ? $data['observaciones'] : null,
                    );
                } catch (GrupoOlympoException $error) {
                    // El mensaje del dominio ya está escrito para quien atiende.
                    $this->avisarDelError($error);

                    return;
                }

                /*
                 * El botón de imprimir va acá y no solo en la lista: el flujo
                 * de ventanilla es cobrar y entregar el papel, y hacer que
                 * quien atiende vaya a buscarlo a otra pantalla es la forma
                 * más segura de que el cliente se vaya sin recibo.
                 *
                 * Persistente por lo mismo: una notificación que se desvanece
                 * a los cinco segundos se lleva el botón con ella.
                 */
                Notification::make()
                    ->title("Recibo {$recibo->folio()}")
                    ->body(sprintf(
                        '%s aplicados a %d %s.',
                        $recibo->montoTotal()->formateado(),
                        $recibo->aplicaciones()->count(),
                        $recibo->aplicaciones()->count() === 1 ? 'cuota' : 'cuotas',
                    ))
                    ->success()
                    ->persistent()
                    ->actions([ImprimirRecibo::enNotificacion($recibo)])
                    ->send();
            });
    }

    /**
     * Abono extraordinario a capital (R21).
     *
     * ═══ POR QUE ES UNA ACCION APARTE, Y NO UNA CASILLA EN «COBRAR» ═══
     *
     * Un pago normal deja el contrato como estaba. Este lo reescribe: borra
     * las cuotas que nadie tocó y escribe otras. Son dos trámites distintos,
     * con dos permisos distintos —cobrar es del receptor, reprogramar es de la
     * administradora— y esconder el segundo detrás de una casilla del primero
     * sería regalar el permiso más caro.
     *
     * ═══ EL EFECTO SE MUESTRA ANTES DE CONFIRMAR ═══
     *
     * Y acá importa más que en un cobro: el cliente está decidiendo entre
     * terminar antes o pagar menos por mes, y esa decisión se toma mirando los
     * dos números. El cuadro sale del MISMO `EfectoDelAbono` que después
     * persiste el Service, así que lo que se ve es lo que se guarda.
     */
    private function abonarACapitalAction(): Action
    {
        return Action::make('abonar_a_capital')
            ->label('Abonar a capital')
            ->icon(Heroicon::OutlinedArrowTrendingDown)
            ->color('primary')
            ->visible(fn (): bool => $this->venta()->getAttribute('estado') === EstadoVenta::Vigente
                && auth()->user()?->can('reprogramar', Venta::class) === true)
            ->modalHeading('Abonar a capital')
            ->modalDescription('Primero pone al día lo vencido; el sobrante baja el saldo y reescribe el plan del lote.')
            ->modalSubmitActionLabel('Abonar y reprogramar')
            ->modalWidth('2xl')
            ->fillForm(fn (): array => [
                'compromiso_id' => $this->primerLoteConSaldo()?->getKey(),
                'fecha'         => today()->toDateString(),
                'forma_pago'    => FormaDePago::Efectivo->value,
                'modalidad'     => ModalidadDeReprogramacion::AcortarPlazo->value,
            ])
            ->schema([
                Select::make('compromiso_id')
                    ->label('¿A qué lote?')
                    ->options(fn (): array => $this->lotesConSaldo())
                    ->required()
                    ->live()
                    ->native(false)
                    ->helperText('El abono va contra UN lote: el que el cliente quiere terminar de pagar primero (R21).'),

                MontoField::make('monto', 'Monto recibido')
                    ->required()
                    ->live(onBlur: true),

                Radio::make('modalidad')
                    ->label('¿Qué hacemos con lo que falta?')
                    ->options(fn (): array => $this->modalidades())
                    ->required()
                    ->live()
                    ->helperText('Lo elige el cliente, no el sistema: los dos caminos son correctos (R21).'),

                Select::make('forma_pago')
                    ->label('Forma de pago')
                    ->options(fn (): array => $this->formasDePago())
                    ->required()
                    ->live()
                    ->native(false),

                TextInput::make('referencia')
                    ->label('Número de referencia')
                    ->maxLength(60)
                    ->visible(fn (Get $get): bool => $this->exigeReferencia($get))
                    ->required(fn (Get $get): bool => $this->exigeReferencia($get))
                    ->helperText('Es lo único que después permite cruzar este recibo contra el estado de cuenta del banco (R11).'),

                Placeholder::make('efecto')
                    ->label('Cómo queda el lote')
                    ->columnSpanFull()
                    ->content(fn (Get $get): HtmlString => $this->efectoEstimado($get)),

                DatePicker::make('fecha')
                    ->label('Fecha del pago')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y'),

                Textarea::make('motivo')
                    ->label('¿Por qué?')
                    ->required()
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('Abono a capital solicitado por el cliente')
                    ->helperText('Queda con tu usuario y la fecha. El mes que viene alguien va a preguntar por qué cambió el número (R21).'),

                Textarea::make('observaciones')
                    ->label('Observaciones')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                try {
                    $recibo = app(RegistroDePagos::class)->abonarACapital(
                        venta: $this->venta(),
                        lote: Compromiso::query()->findOrFail($data['compromiso_id']),
                        cliente: $this->venta()->titular() ?? $this->venta()->clientes()->firstOrFail(),
                        monto: new Monto((string) ($data['monto'] ?? '0')),
                        modalidad: ModalidadDeReprogramacion::from((string) $data['modalidad']),
                        motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                        forma: FormaDePago::from((string) $data['forma_pago']),
                        referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
                        fecha: CarbonImmutable::parse((string) $data['fecha']),
                        observaciones: is_string($data['observaciones'] ?? null) ? $data['observaciones'] : null,
                    );
                } catch (GrupoOlympoException $error) {
                    $this->avisarDelError($error);

                    return;
                }

                $this->avisarDelAbono($recibo);
            });
    }

    // ─── Interno ──────────────────────────────────────────────────────

    private function venta(): Venta
    {
        /** @var Venta $venta */
        $venta = $this->getRecord();

        return $venta;
    }

    /**
     * Los lotes del contrato que todavía deben, con cuánto.
     *
     * @return array<int, string>
     */
    private function lotesConSaldo(): array
    {
        $opciones = [];

        foreach ($this->venta()->compromisos as $renglon) {
            $saldo = $this->saldoDe($renglon);

            if ($saldo->esCero()) {
                continue;
            }

            $opciones[(int) $renglon->getKey()] = sprintf(
                '%s — debe %s',
                (string) $renglon->lote?->getAttribute('codigo'),
                $saldo->formateado(),
            );
        }

        return $opciones;
    }

    private function primerLoteConSaldo(): ?Compromiso
    {
        foreach ($this->venta()->compromisos as $renglon) {
            if (! $this->saldoDe($renglon)->esCero()) {
                return $renglon;
            }
        }

        return null;
    }

    /**
     * Cómo caería este pago, con las mismas reglas que después persisten.
     *
     * Es un ESTIMADO. El que manda es el Service: relee las cuotas con
     * `FOR UPDATE` dentro de la transacción, porque entre que se pintó esta
     * pantalla y se apretó Guardar, el otro receptor pudo cobrar lo mismo.
     */
    private function repartoEstimado(Get $get): HtmlString
    {
        $lote = Compromiso::query()->find($get('compromiso_id'));
        $porRepartir = $this->montoTecleado($get);

        if (! $lote instanceof Compromiso || ! $porRepartir instanceof Monto) {
            return new HtmlString('<p class="olympo-vacio">Elegí el lote y escribí el monto.</p>');
        }

        $filas = '';
        $tocadas = 0;

        foreach ($this->pendientesDe($lote) as $cuota) {
            if ($porRepartir->esCero()) {
                break;
            }

            $falta = $cuota->saldo();
            $leToca = $porRepartir->mayorQue($falta) ? $falta : $porRepartir;
            $queda = $falta->restar($leToca);
            $tocadas++;

            $filas .= sprintf(
                '<li><span class="meses">Cuota %d — vence %s%s</span><span class="monto">%s</span></li>',
                (int) $cuota->getAttribute('numero'),
                e($cuota->getAttribute('fecha_vencimiento')?->format('d/m/Y') ?? '—'),
                $queda->esCero() ? '' : e(sprintf(' · le quedan %s', $queda->formateado())),
                e($leToca->formateado()),
            );

            $porRepartir = $porRepartir->restar($leToca);
        }

        if ($tocadas === 0) {
            return new HtmlString('<p class="olympo-vacio">Este lote no debe nada.</p>');
        }

        $sobra = $porRepartir->esCero()
            ? ''
            : '<p class="olympo-nota">Sobran '.e($porRepartir->formateado())
                .': el pago supera lo que debe este lote y se va a rechazar. Cobrá el saldo exacto.</p>';

        return new HtmlString('<ul class="olympo-escalera">'.$filas.'</ul>'.$sobra);
    }

    /**
     * El antes y el después del lote, con el MISMO objeto que después
     * persiste el Service.
     */
    private function efectoEstimado(Get $get): HtmlString
    {
        $lote = Compromiso::query()->find($get('compromiso_id'));
        $abono = $this->montoTecleado($get);
        // En una variable y no dos llamadas a `$get`: el is_string() de la
        // primera no le dice nada a PHPStan sobre lo que devuelve la segunda.
        $elegida = $get('modalidad');
        $modalidad = is_string($elegida) ? ModalidadDeReprogramacion::tryFrom($elegida) : null;

        if (! $lote instanceof Compromiso || ! $abono instanceof Monto || ! $modalidad instanceof ModalidadDeReprogramacion) {
            return new HtmlString('<p class="olympo-vacio">Elegí el lote, escribí el monto y decidí qué pasa con la cuota.</p>');
        }

        $pendientes = $this->pendientesDe($lote);

        if ($pendientes->isEmpty()) {
            return new HtmlString('<p class="olympo-vacio">Este lote no debe nada.</p>');
        }

        $efecto = EfectoDelAbono::calcular(
            $pendientes,
            $abono,
            $modalidad,
            (int) $this->venta()->getAttribute('dia_pago'),
        );

        if ($abono->mayorQue($efecto->saldoDelLote)) {
            return new HtmlString('<p class="olympo-nota">El abono supera lo que debe el lote ('
                .e($efecto->saldoDelLote->formateado()).'). Se va a rechazar.</p>');
        }

        if ($efecto->esPagoNormal) {
            return new HtmlString('<p class="olympo-nota">No alcanza para poner al día lo vencido ('
                .e($efecto->ponerAlDia->formateado()).'), así que <strong>no hay reprogramación</strong>: '
                .'se registra como un pago normal y el plan queda como está. '
                .'Para que baje el capital hace falta más de eso.</p>');
        }

        if ($efecto->superaElTope) {
            return new HtmlString('<p class="olympo-nota">Acá se puede abonar hasta '
                .e($efecto->tope->formateado()).'. La diferencia es lo que le falta a una cuota que ya está '
                .'pagada a medias, y esa cuota no se toca. Para cancelar el lote son '
                .e($efecto->saldoDelLote->formateado()).' por «Registrar un pago».</p>');
        }

        if ($efecto->problema !== null) {
            return new HtmlString('<p class="olympo-nota">'.e($efecto->problema).'</p>');
        }

        return new HtmlString(
            $this->repartoDelAbono($efecto)
            .$this->antesYDespues($efecto)
            .$this->notaDelAbono($efecto)
        );
    }

    /**
     * En qué se divide el dinero: lo que pone al día y lo que baja el capital.
     */
    private function repartoDelAbono(EfectoDelAbono $efecto): string
    {
        $filas = '';

        if ($efecto->aplicaciones !== []) {
            $numeros = array_map(
                static fn (array $fila): string => (string) $fila['numero'],
                $efecto->aplicaciones,
            );

            $filas .= sprintf(
                '<li><span class="meses">Pone al día — %s %s</span><span class="monto">%s</span></li>',
                count($numeros) === 1 ? 'cuota' : 'cuotas',
                e(implode(', ', $numeros)),
                e($efecto->ponerAlDia->formateado()),
            );
        }

        $filas .= sprintf(
            '<li><span class="meses">Baja el capital</span><span class="monto">%s</span></li>',
            e($efecto->aCapital->formateado()),
        );

        return '<ul class="olympo-escalera">'.$filas.'</ul>';
    }

    /**
     * Las dos columnas que el cliente compara para decidir.
     */
    private function antesYDespues(EfectoDelAbono $efecto): string
    {
        $plan = $efecto->planNuevo;
        $antes = count($efecto->numerosReemplazados);
        $despues = $plan?->count() ?? 0;

        $cuotaNueva = $efecto->cuotaNueva();
        /*
         * Indexada, no con `end()`: esa funcion recibe el arreglo POR
         * REFERENCIA y `planAnterior` es una propiedad readonly, asi que
         * pasarsela tira «Cannot modify readonly property» en pleno modal.
         */
        $ultimaAntes = $efecto->planAnterior === []
            ? null
            : $efecto->planAnterior[count($efecto->planAnterior) - 1]['vence'];
        $ultimaDespues = $plan?->ultima()?->vencimiento;

        $renglon = static fn (string $que, string $antes, string $despues): string => sprintf(
            '<tr><td>%s</td><td class="apagado">%s</td><td class="fuerte">%s</td></tr>',
            e($que),
            e($antes),
            e($despues),
        );

        $filas = $renglon(
            'Saldo por reprogramar',
            $efecto->saldoReprogramable->formateado(),
            $efecto->saldoNuevo->formateado(),
        );

        $filas .= $renglon(
            'Cuota',
            $efecto->cuotaVigente?->formateado() ?? '—',
            $cuotaNueva?->formateado() ?? '—',
        );

        $filas .= $renglon(
            'Cuotas que faltan',
            (string) $antes,
            (string) $despues,
        );

        $filas .= $renglon(
            'Termina de pagar',
            is_string($ultimaAntes) ? CarbonImmutable::parse($ultimaAntes)->format('m/Y') : '—',
            $ultimaDespues?->format('m/Y') ?? '—',
        );

        return '<table class="olympo-tabla"><thead><tr><th></th><th>Hoy</th><th>Después del abono</th></tr></thead>'
            .'<tbody>'.$filas.'</tbody></table>';
    }

    private function notaDelAbono(EfectoDelAbono $efecto): string
    {
        if ($efecto->cancelaElPlan()) {
            return '<p class="olympo-nota">Con este abono el lote queda sin cuotas pendientes.</p>';
        }

        $meses = $efecto->mesesAhorrados();

        if ($meses > 0) {
            return sprintf(
                '<p class="olympo-nota">Sigue pagando lo mismo cada mes y termina %d %s antes.</p>',
                $meses,
                $meses === 1 ? 'mes' : 'meses',
            );
        }

        return '<p class="olympo-nota">Termina el mismo mes que tenía pactado, pagando menos cada mes.</p>';
    }

    /**
     * Las cuotas del lote que todavía deben algo, en el mismo orden que el
     * FIFO del Service.
     *
     * @return Cuotas<int, Cuota>
     */
    private function pendientesDe(Compromiso $lote): Cuotas
    {
        return Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->whereColumn('monto_pagado', '<', 'monto')
            ->orderBy('numero')
            ->get();
    }

    /**
     * El monto del formulario, o null si todavía no hay uno usable.
     *
     * ═══ POR QUE SE VALIDA EL FORMATO ACA ═══
     *
     * `Monto` rechaza todo lo que no sea un decimal en notación simple —con
     * razón, es el borde del dinero— y el campo es `live`, así que mientras el
     * usuario teclea llega «1,500», «12.» o vacío ANTES de que corra la
     * validación del formulario. Construir un Monto con eso revienta el modal
     * con el cliente enfrente. Acá no hay nada que avisar: todavía no terminó
     * de escribir.
     */
    private function montoTecleado(Get $get): ?Monto
    {
        $monto = $get('monto');

        if (! is_string($monto) || preg_match('/^\d+(\.\d{1,2})?$/', trim($monto)) !== 1) {
            return null;
        }

        $valor = new Monto(trim($monto));

        return $valor->esCero() ? null : $valor;
    }

    private function saldoDe(Compromiso $renglon): Monto
    {
        $saldo = Monto::cero();

        foreach ($renglon->cuotas()->get() as $cuota) {
            $saldo = $saldo->sumar($cuota->saldo());
        }

        return $saldo;
    }

    /**
     * @return array<string, string>
     */
    private function formasDePago(): array
    {
        $opciones = [];

        foreach (FormaDePago::cases() as $forma) {
            $opciones[$forma->value] = $forma->etiqueta();
        }

        return $opciones;
    }

    /**
     * @return array<string, string>
     */
    private function modalidades(): array
    {
        $opciones = [];

        foreach (ModalidadDeReprogramacion::cases() as $modalidad) {
            $opciones[$modalidad->value] = $modalidad->etiqueta().' — '.$modalidad->explicacion();
        }

        return $opciones;
    }

    private function exigeReferencia(Get $get): bool
    {
        $forma = $get('forma_pago');

        return is_string($forma)
            && FormaDePago::tryFrom($forma)?->exigeReferencia() === true;
    }

    /**
     * El mensaje del dominio ya está escrito para quien atiende. Lo que NO
     * puede pasar es una pantalla de error 500 con el cliente enfrente.
     */
    private function avisarDelError(GrupoOlympoException $error): void
    {
        Notification::make()
            ->title('No se registró el movimiento')
            ->body($error->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * Un abono puede terminar de tres formas y las tres se avisan distinto.
     */
    private function avisarDelAbono(Recibo $recibo): void
    {
        $constancia = $recibo->reprogramacion()->first();

        if (! $constancia instanceof Reprogramacion) {
            Notification::make()
                ->title("Recibo {$recibo->folio()}")
                ->body(sprintf(
                    '%s aplicados a las cuotas vencidas. NO hubo reprogramación: el abono no alcanzó '.
                    'a bajar el capital, así que el plan quedó como estaba.',
                    $recibo->montoTotal()->formateado(),
                ))
                ->warning()
                ->persistent()
                ->actions([ImprimirRecibo::enNotificacion($recibo)])
                ->send();

            return;
        }

        if ($constancia->cancelaElLote()) {
            Notification::make()
                ->title("Recibo {$recibo->folio()}")
                ->body('El lote quedó sin cuotas pendientes.')
                ->success()
                ->persistent()
                ->actions([ImprimirRecibo::enNotificacion($recibo)])
                ->send();

            return;
        }

        $meses = $constancia->mesesAhorrados();

        Notification::make()
            ->title("Recibo {$recibo->folio()}")
            ->body($meses > 0
                ? sprintf(
                    'Abonados %s a capital. Termina %d %s antes, con la misma cuota de %s.',
                    $constancia->montoAbonado()->formateado(),
                    $meses,
                    $meses === 1 ? 'mes' : 'meses',
                    $constancia->montoCuotaNueva()?->formateado() ?? '—',
                )
                : sprintf(
                    'Abonados %s a capital. La cuota baja de %s a %s, con los mismos meses.',
                    $constancia->montoAbonado()->formateado(),
                    $constancia->montoCuotaAnterior()?->formateado() ?? '—',
                    $constancia->montoCuotaNueva()?->formateado() ?? '—',
                ))
            ->success()
            ->persistent()
            ->actions([ImprimirRecibo::enNotificacion($recibo)])
            ->send();
    }
}
