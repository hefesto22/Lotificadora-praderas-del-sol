<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Models\Recibo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Corregir un recibo SIN tocar el dinero — 4-sep-2026.
 *
 * ═══ POR QUE EXISTE, SI `ReciboPolicy::update()` SIGUE DEVOLVIENDO FALSE ═══
 *
 * Porque «editar un recibo» son dos cosas distintas y solo una es peligrosa.
 *
 * Cambiarle el MONTO a un papel que el cliente ya se llevó firmado deja la
 * base diciendo una cosa y el papel otra, y si ese recibo ya se aplicó a
 * cuotas descuadra el plan de pagos: es exactamente el desastre del 27-ago
 * que `olympo:cuadrar-recibos` nació para encontrar. Eso sigue prohibido, y
 * para eso está anular + reemitir.
 *
 * Pero **quién recibió el dinero, la forma de pago, el número de referencia y
 * las observaciones no mueven un centavo**. Se teclean mal, y hoy la única
 * salida era anular un recibo perfectamente bueno —matando un correlativo y
 * pidiéndole al cliente que devuelva el papel— para reemitirlo igual con la
 * referencia bien escrita. Eso es peor que la enfermedad.
 *
 * El caso que lo pidió, y que pasó de verdad: el recibo RPS-00000022 salió sin
 * `recibido_por` —era una PRIMA, y esa puerta no preguntaba hasta el 31-ago—,
 * así que el corte de caja lo sumaba bajo «Sin usuario». Se arregló a mano,
 * por SSH, en producción. Esto es para que la próxima vez no haga falta.
 *
 * ═══ LA LISTA DE CAMPOS ES LA REGLA, Y ESTA ACA ═══
 *
 * `CAMPOS` no es una comodidad: es el contrato. Todo lo que NO está en esa
 * lista es intocable por esta puerta, incluidos `monto`, `concepto`, `fecha`,
 * `numero` y el cliente. Agregar un nombre a esa constante es una decisión de
 * negocio, no un refactor — la fecha, por ejemplo, decide en qué corte de caja
 * cae el dinero, así que moverla cambia el cierre de dos días.
 *
 * ═══ UN SOLO ASIENTO EN LA BITACORA, Y CON EL MOTIVO ═══
 *
 * `Recibo` ya se registra solo (`LogsActivity`), pero ese asiento automático
 * escribe nombres de columna y NO tiene dónde poner el porqué. Por eso se
 * apaga durante el `update()` y se escribe uno a mano: con las etiquetas que
 * usa la pantalla, con el antes y el después, y con el motivo que la
 * administradora tuvo que escribir. Dos asientos para un solo cambio serían
 * peor que ninguno.
 */
final class CorreccionDeRecibo
{
    /**
     * Lo único que esta puerta puede tocar. Ver el docblock: es la regla.
     *
     * @var list<string>
     */
    public const array CAMPOS = ['recibido_por', 'forma_pago', 'referencia', 'observaciones'];

    /**
     * Aplica la corrección y la asienta. Devuelve `false` si no cambió nada.
     *
     * @param array<string, mixed> $datos
     */
    public function corregir(Recibo $recibo, array $datos, string $motivo): bool
    {
        $porQue = trim($motivo);

        if ($porQue === '') {
            throw PagoInvalidoException::porFaltarElMotivoDeLaCorreccion();
        }

        if ($recibo->estaAnulado()) {
            throw PagoInvalidoException::porCorregirUnReciboAnulado($recibo->folio());
        }

        $quien = $this->quien($datos);
        $forma = $this->forma($datos, $recibo);
        $referencia = $this->texto($datos['referencia'] ?? null);
        $observaciones = $this->texto($datos['observaciones'] ?? null);

        /*
         * 🔴 LA REFERENCIA NO SE EXIGE ACA, Y NO ES UN OLVIDO.
         *
         * El modal de cobro dejó de exigirla el 27-ago-2026: llega una
         * transferencia, el cliente está enfrente y el número todavía no lo
         * tiene nadie. Exigirla en la corrección sería peor todavía —haría
         * IMPOSIBLE corregir justo los recibos que salieron sin referencia,
         * que son los que más falta hace corregir—. Esta pantalla es
         * precisamente donde se teclea el número que ese día no se tenía.
         */

        if ($quien !== null && ! User::query()->whereKey($quien)->exists()) {
            throw PagoInvalidoException::porQuienRecibioQueNoExiste();
        }

        return DB::transaction(function () use ($recibo, $quien, $forma, $referencia, $observaciones, $porQue): bool {
            /*
             * Se relee bloqueando, igual que `anular()`: dos correcciones a la
             * vez dejarían el asiento contando un cambio que ya no es cierto.
             *
             * ⚠️ `whereKey()->firstOrFail()` y NO `findOrFail()` — este último
             * lo tipa `Recibo|Collection` en nivel 7. La razón larga está en
             * `RegistroDePagos::anular()`.
             */
            $vivo = Recibo::query()
                ->whereKey($recibo->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($vivo->estaAnulado()) {
                throw PagoInvalidoException::porCorregirUnReciboAnulado($vivo->folio());
            }

            $antes = $this->retrato($vivo);

            // Ver el docblock: el asiento automático se apaga para escribir uno solo.
            $vivo->disableLogging();

            $vivo->update([
                'recibido_por'  => $quien,
                'forma_pago'    => $forma,
                'referencia'    => $referencia,
                'observaciones' => $observaciones,
            ]);

            $vivo->enableLogging();

            $despues = $this->retrato($vivo);

            if ($antes === $despues) {
                return false;
            }

            $this->asentar($vivo, $antes, $despues, $porQue);

            $recibo->refresh();

            return true;
        });
    }

    /**
     * Quién recibió el dinero, o nadie.
     *
     * @param array<string, mixed> $datos
     */
    private function quien(array $datos): ?int
    {
        $elegido = $datos['recibido_por'] ?? null;

        return is_numeric($elegido) ? (int) $elegido : null;
    }

    /**
     * La forma de pago elegida; la que ya tenía si el formulario no la mandó.
     *
     * @param array<string, mixed> $datos
     */
    private function forma(array $datos, Recibo $recibo): FormaDePago
    {
        $elegida = $datos['forma_pago'] ?? null;

        if ($elegida instanceof FormaDePago) {
            return $elegida;
        }

        if (is_string($elegida) && FormaDePago::tryFrom($elegida) instanceof FormaDePago) {
            return FormaDePago::from($elegida);
        }

        $actual = $recibo->getAttribute('forma_pago');

        return $actual instanceof FormaDePago ? $actual : FormaDePago::Efectivo;
    }

    /**
     * Un texto limpio, o NULL si quedó vacío. Nunca la cadena vacía: la base
     * distingue «no hay referencia» de «hay una referencia en blanco».
     */
    private function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = trim($valor);

        return $limpio === '' ? null : $limpio;
    }

    /**
     * Cómo se ve el recibo ahora mismo, con las palabras de la pantalla.
     *
     * Las claves son las etiquetas que la administradora ve en el modal —no
     * los nombres de columna— porque el asiento lo va a leer ella dentro de
     * seis meses, no un programador.
     *
     * @return array<string, string>
     */
    private function retrato(Recibo $recibo): array
    {
        $forma = $recibo->getAttribute('forma_pago');

        return [
            'quién recibió el dinero' => $this->nombre($recibo->getAttribute('recibido_por')),
            'forma de pago'           => $forma instanceof FormaDePago ? $forma->etiqueta() : '—',
            'referencia'              => $this->oRaya($recibo->getAttribute('referencia')),
            'observaciones'           => $this->oRaya($recibo->getAttribute('observaciones')),
        ];
    }

    /**
     * El nombre del usuario, leído de la base y no de la relación cargada:
     * el segundo retrato se toma sobre el mismo objeto, y una relación en
     * memoria seguiría contestando el nombre viejo.
     */
    private function nombre(mixed $id): string
    {
        if (! is_numeric($id)) {
            return '—';
        }

        $usuario = User::query()->find((int) $id);

        return $usuario instanceof User ? (string) $usuario->getAttribute('name') : '—';
    }

    private function oRaya(mixed $valor): string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : '—';
    }

    /**
     * El asiento: qué cambió, de qué a qué, y por qué.
     *
     * 🔴 `withChanges()` y NO `withProperties()`. La pantalla de Registros de
     * actividad lee `attribute_changes`; en `properties` el asiento quedaría
     * guardado donde nadie lo pinta. Es la misma nota que en
     * `CambioDeTitular::asentar()`, y ya costó verlo una vez.
     *
     * Solo van los campos que de verdad cambiaron: un asiento que repite los
     * cuatro campos obliga a comparar a ojo para encontrar el único que se
     * movió.
     *
     * @param array<string, string> $antes
     * @param array<string, string> $despues
     */
    private function asentar(Recibo $recibo, array $antes, array $despues, string $motivo): void
    {
        $viejos = [];
        $nuevos = [];

        foreach ($despues as $campo => $valor) {
            if (($antes[$campo] ?? '—') === $valor) {
                continue;
            }

            $viejos[$campo] = $antes[$campo] ?? '—';
            $nuevos[$campo] = $valor;
        }

        activity()
            ->performedOn($recibo)
            ->causedBy(auth()->user())
            ->withChanges(['old' => $viejos, 'attributes' => $nuevos])
            ->withProperty('motivo', $motivo)
            ->withProperty('recibo', $recibo->folio())
            ->event('correccion')
            ->log('Recibo corregido');
    }
}
