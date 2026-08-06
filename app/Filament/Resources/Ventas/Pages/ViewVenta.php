<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Ventas\VentaResource;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Recibo;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Override;

/**
 * La ficha del expediente.
 *
 * La única acción es COBRAR, que es lo que se hace todos los días. Editar una
 * venta firmada no es una acción genérica (ver el docblock de
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
        return [$this->cobrarAction()];
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
                    Notification::make()
                        ->title('No se registró el pago')
                        ->body($error->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Recibo {$recibo->folio()}")
                    ->body(sprintf(
                        '%s aplicados a %d %s.',
                        $recibo->montoTotal()->formateado(),
                        $recibo->aplicaciones()->count(),
                        $recibo->aplicaciones()->count() === 1 ? 'cuota' : 'cuotas',
                    ))
                    ->success()
                    ->send();
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
        $monto = $get('monto');

        if (! $lote instanceof Compromiso || ! is_string($monto) || trim($monto) === '') {
            return new HtmlString('<p class="olympo-vacio">Elegí el lote y escribí el monto.</p>');
        }

        $porRepartir = new Monto($monto);

        if ($porRepartir->esCero()) {
            return new HtmlString('<p class="olympo-vacio">Elegí el lote y escribí el monto.</p>');
        }

        $pendientes = Cuota::query()
            ->where('compromiso_id', $lote->getKey())
            ->whereColumn('monto_pagado', '<', 'monto')
            ->orderBy('numero')
            ->get();

        $filas = '';
        $tocadas = 0;

        foreach ($pendientes as $cuota) {
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

    private function exigeReferencia(Get $get): bool
    {
        $forma = $get('forma_pago');

        return is_string($forma)
            && FormaDePago::tryFrom($forma)?->exigeReferencia() === true;
    }
}
