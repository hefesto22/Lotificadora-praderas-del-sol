<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\VentaResource;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * ═══ POR QUE ESTA PAGINA NO USA EL CAMINO NORMAL DE FILAMENT ═══
 *
 * `CreateRecord` por defecto hace `Model::create($data)`. Eso escribiria una
 * venta directamente en la base, salteando el Service — y con el, el
 * correlativo, el plan de cuotas, el bloqueo de los lotes y la transaccion
 * que los mantiene juntos. Quedaria una venta sin numero, sin cuotas y con
 * los lotes todavia disponibles para que otro los venda.
 *
 * El §9.D2 no admite eso: **toda escritura de negocio pasa por un Service**.
 * Por eso se reemplaza `handleRecordCreation`, que es el punto exacto donde
 * Filament deja meter mano.
 *
 * ═══ LOS ERRORES DEL DOMINIO SE MUESTRAN, NO SE ESTRELLAN ═══
 *
 * `RegistroDeVentas` lanza excepciones con mensajes escritos para quien
 * atiende en ventanilla: "el lote A-12 ya no esta disponible, alguien lo
 * movio mientras se armaba esta venta". Dejarlas subir mostraria una
 * pantalla de error 500 y perderia el formulario lleno. Se atrapan, se
 * muestran como notificacion y el formulario queda como estaba.
 */
class CreateVenta extends CreateRecord
{
    #[Override]
    protected static string $resource = VentaResource::class;

    /**
     * @param array<string, mixed> $data
     */
    #[Override]
    protected function handleRecordCreation(array $data): Model
    {
        $registro = app(RegistroDeVentas::class);

        try {
            return $registro->activar(
                proyecto: Proyecto::query()->findOrFail($data['proyecto_id']),
                lotes: $this->lotes($data),
                clientes: $this->clientes($data),
                prima: new Monto((string) ($data['prima'] ?? '0')),
                plazoMeses: (int) ($data['plazo_meses'] ?? 0),
                diaPago: (int) ($data['dia_pago'] ?? 1),
                fechaContrato: CarbonImmutable::parse((string) $data['fecha_contrato']),
                observaciones: is_string($data['observaciones'] ?? null) ? $data['observaciones'] : null,
                precios: $this->precios($data),
            );
        } catch (GrupoOlympoException $e) {
            Notification::make()
                ->title('No se pudo registrar la venta')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    /**
     * Los lotes elegidos, en el orden en que están en el formulario.
     *
     * @param array<string, mixed> $data
     *
     * @return list<Lote>
     */
    private function lotes(array $data): array
    {
        $ids = array_column($this->filas($data), 'lote_id');

        /** @var list<Lote> $lotes */
        $lotes = Lote::query()->whereIn('id', $ids)->get()->all();

        return $lotes;
    }

    /**
     * El precio que se tecleó para cada lote, con su motivo si lo lleva.
     *
     * Se manda SIEMPRE, aunque sea igual al de lista: el Service compara
     * contra el precio del lote recién bloqueado, no contra el que traía la
     * pantalla, y ahí decide si hace falta motivo. Filtrarlo acá sería
     * decidir con datos viejos.
     *
     * @param array<string, mixed> $data
     *
     * @return list<PrecioPactado>
     */
    private function precios(array $data): array
    {
        $precios = [];

        foreach ($this->filas($data) as $fila) {
            $precios[] = new PrecioPactado(
                loteId: $fila['lote_id'],
                precioVara: new Monto($fila['precio_vara']),
                motivo: $fila['motivo_descuento'],
            );
        }

        return $precios;
    }

    /**
     * Las filas del repetidor, limpias.
     *
     * El estado de un `Repeater` es un mapa con claves uuid, no una lista;
     * el orden de inserción es el orden en pantalla. Una fila sin lote es
     * alguien que apretó «Agregar» y no llenó: se descarta.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{lote_id: int, precio_vara: string, motivo_descuento: string|null}>
     */
    private function filas(array $data): array
    {
        $detalle = $data['detalle'] ?? null;

        if (! is_array($detalle)) {
            return [];
        }

        $filas = [];

        foreach ($detalle as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['lote_id'] ?? null)) {
                continue;
            }

            $precio = $fila['precio_vara'] ?? null;
            $motivo = $fila['motivo_descuento'] ?? null;

            $filas[] = [
                'lote_id'          => (int) $fila['lote_id'],
                'precio_vara'      => is_string($precio) || is_int($precio) ? (string) $precio : '0',
                'motivo_descuento' => is_string($motivo) && trim($motivo) !== '' ? trim($motivo) : null,
            ];
        }

        return $filas;
    }

    /**
     * El titular primero; después los copropietarios, sin repetirlo.
     *
     * Si alguien elige a la misma persona como titular y copropietaria, el
     * índice único `(venta_id, cliente_id)` del pivot lo rechazaría con un
     * error de base. Se filtra acá, que es donde se puede explicar.
     *
     * @param array<string, mixed> $data
     *
     * @return list<Cliente>
     */
    private function clientes(array $data): array
    {
        $titular = Cliente::query()->findOrFail($data['titular_id']);

        $otros = is_array($data['copropietarios'] ?? null)
            ? array_diff(array_map(intval(...), $data['copropietarios']), [(int) $titular->getKey()])
            : [];

        /** @var list<Cliente> $copropietarios */
        $copropietarios = $otros === []
            ? []
            : Cliente::query()->whereIn('id', $otros)->get()->all();

        return [$titular, ...$copropietarios];
    }

    /**
     * Después de firmar, a la ficha del expediente: es lo que hay que
     * imprimir y lo que el cliente va a preguntar.
     */
    #[Override]
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    #[Override]
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Venta registrada';
    }
}
