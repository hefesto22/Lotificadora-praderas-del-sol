<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\LoteConsultado;
use App\Models\Prospecto;
use App\Models\Proyecto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Me interesa este lote»: se guarda el contacto y recien despues se abre
 * WhatsApp.
 *
 * ═══ POR QUE EL ORDEN IMPORTA ═══
 *
 * Lo natural seria mandar al cliente directo a WhatsApp desde un enlace. El
 * problema es que **la mitad no escribe**: abre el chat, ve que tiene que
 * redactar algo, y cierra. Ese contacto se pierde y nadie se entera de que
 * existio.
 *
 * Acá el prospecto queda guardado ANTES de la redireccion. Si escribe, la
 * administradora tiene la conversacion; si no escribe, igual tiene el nombre
 * y el telefono para llamarlo. Es la diferencia entre una vidriera y una
 * herramienta de venta.
 *
 * ═══ ESTO ESTA ABIERTO A INTERNET, ASI QUE ═══
 *
 * - **Limite por IP** en la ruta: un formulario publico sin freno es una tabla
 *   con diez mil filas basura a la semana.
 * - **Trampa (honeypot)**: un campo que el navegador esconde y una persona
 *   nunca llena. Si viene con algo, es un bot — y se le contesta como si todo
 *   hubiera salido bien, sin guardar nada. Decirle «sos un bot» solo le enseña
 *   a esquivarlo la proxima.
 * - **El lote se verifica contra el proyecto**: nadie puede mandar el id de un
 *   lote de otra lotificadora en el formulario.
 */
final class RegistrarInteresController
{
    public function __invoke(Request $request, string $slug): RedirectResponse
    {
        $proyecto = Proyecto::query()
            ->where('slug', $slug)
            ->where('activo', true)
            ->where('plano_publico', true)
            ->first();

        if (! $proyecto instanceof Proyecto) {
            abort(404);
        }

        $datos = $request->validate([
            'nombre'   => ['required', 'string', 'min:3', 'max:120'],
            'telefono' => ['required', 'string', 'min:8', 'max:20', 'regex:/^[\d\s()+-]+$/'],
            'mensaje'  => ['nullable', 'string', 'max:500'],
            'plazo'    => ['nullable', 'integer', 'min:0', 'max:600'],
            'lote_id'  => [
                'nullable',
                'integer',
                // El lote tiene que ser de ESTE proyecto. Sin esto, el id de
                // un lote ajeno entraría por el formulario.
                Rule::exists('lotes', 'id')->where('proyecto_id', $proyecto->getKey()),
            ],
            // La trampa. Ver el docblock.
            'sitio_web' => ['nullable', 'string', 'max:0'],
        ]);

        /*
         * Al bot se le contesta igual que a una persona. Un mensaje distinto
         * —o un error— le dice exactamente qué campo esquivar la próxima vez.
         */
        if (($datos['sitio_web'] ?? '') !== '') {
            return $this->despedir($proyecto, null, null);
        }

        $lote = $this->loteDe($datos['lote_id'] ?? null, $proyecto);

        $nombre = is_string($datos['nombre']) ? $datos['nombre'] : '';
        $telefono = is_string($datos['telefono']) ? $datos['telefono'] : '';

        $prospecto = $this->personaDe($proyecto, $nombre, $telefono, $request->ip());

        $this->anotarLaConsulta(
            $prospecto,
            $lote,
            is_int($datos['plazo'] ?? null) ? $datos['plazo'] : null,
            is_string($datos['mensaje'] ?? null) ? $datos['mensaje'] : null,
        );

        return $this->despedir($proyecto, $lote, $nombre === '' ? null : $nombre);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * La PERSONA, buscada por su teléfono.
     *
     * ═══ POR QUE NO ES SIEMPRE UNA FILA NUEVA ═══
     *
     * Lo pidió Mauricio el 23-ago viendo la lista: «si la misma persona
     * contacta no hay necesidad de hacer 2, solo que aparezca por cuáles
     * lotes fue que contactó; sería identificado por el número de teléfono».
     *
     * Y el problema era peor que la repetición: con una fila por consulta,
     * «ya lo llamé» quedaba marcado en UNA y las otras seguían pidiendo
     * llamada. Se terminaba llamando dos veces a la misma persona.
     *
     * ⚠️ Se busca por `telefono_clave` —solo los dígitos, columna GENERADA
     * por Postgres— así que «3301-2827» y «33012827» encuentran a la misma
     * persona. El teléfono se guarda igual como lo escribió, que es como lo
     * va a reconocer quien lo lea.
     */
    private function personaDe(Proyecto $proyecto, string $nombre, string $telefono, ?string $ip): Prospecto
    {
        $clave = preg_replace('/\D+/', '', $telefono) ?? '';

        $prospecto = Prospecto::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('telefono_clave', $clave)
            ->first();

        if ($prospecto instanceof Prospecto) {
            /*
             * Gana el nombre del ÚLTIMO: es el que la persona acaba de
             * teclear. Si la primera vez puso «juan» y ahora «Juan Pérez», el
             * segundo es el bueno. La marca de atendido NO se toca — que haya
             * vuelto a escribir no deshace la llamada que ya le hicieron.
             */
            $prospecto->update(['nombre' => $nombre, 'telefono' => $telefono]);

            return $prospecto;
        }

        return Prospecto::query()->create([
            'proyecto_id' => $proyecto->getKey(),
            'nombre'      => $nombre,
            'telefono'    => $telefono,
            'ip'          => $ip,
        ]);
    }

    /**
     * El lote por el que preguntó, sumado a lo que ya sabíamos de él.
     *
     * Volver a preguntar por el MISMO lote no agrega una fila: suma una vez y
     * corre la fecha. Una persona que preguntó tres veces por el mismo lote
     * es un dato —está decidida— y tres filas iguales no lo dicen mejor.
     *
     * El plazo y el mensaje se pisan con los últimos: si antes miraba contado
     * y ahora 48 meses, lo que importa para la llamada es lo de ahora.
     */
    private function anotarLaConsulta(Prospecto $prospecto, ?Lote $lote, ?int $plazo, ?string $mensaje): void
    {
        $busca = LoteConsultado::query()->where('prospecto_id', $prospecto->getKey());

        // `where('lote_id', null)` no encuentra nunca: en SQL nada es igual a
        // NULL. La consulta sin lote se busca con `whereNull`.
        if ($lote instanceof Lote) {
            $busca->where('lote_id', $lote->getKey());
        } else {
            $busca->whereNull('lote_id');
        }

        $consulta = $busca->first();
        $ahora = now();

        if ($consulta instanceof LoteConsultado) {
            $veces = $consulta->getAttribute('veces');

            $consulta->update([
                'veces'       => (is_int($veces) ? $veces : 1) + 1,
                'ultima_vez'  => $ahora,
                'plazo_meses' => $plazo ?? $consulta->getAttribute('plazo_meses'),
                'mensaje'     => $mensaje ?? $consulta->getAttribute('mensaje'),
            ]);

            return;
        }

        LoteConsultado::query()->create([
            'prospecto_id' => $prospecto->getKey(),
            'lote_id'      => $lote?->getKey(),
            'plazo_meses'  => $plazo,
            'mensaje'      => $mensaje,
            'veces'        => 1,
            'primera_vez'  => $ahora,
            'ultima_vez'   => $ahora,
        ]);
    }

    private function loteDe(mixed $id, Proyecto $proyecto): ?Lote
    {
        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        return Lote::query()
            ->whereKey($id)
            ->where('proyecto_id', $proyecto->getKey())
            ->first();
    }

    /**
     * A WhatsApp con el mensaje ya escrito, o de vuelta al plano.
     *
     * El mensaje viene redactado a propósito: quien llega hasta acá ya
     * decidió preguntar, y obligarlo a redactar es el último lugar donde se
     * puede arrepentir.
     */
    private function despedir(Proyecto $proyecto, ?Lote $lote, ?string $nombre): RedirectResponse
    {
        $numero = $this->whatsapp($proyecto);

        if ($numero === null) {
            return to_route('plano.publico', ['slug' => $proyecto->getAttribute('slug')])
                ->with('gracias', 'Gracias, ya tenemos tus datos. Te vamos a llamar.');
        }

        $texto = 'Hola';

        if ($nombre !== null) {
            $texto .= ', soy '.$nombre;
        }

        $texto .= '. Vi el plano de '.$proyecto->getAttribute('nombre');

        if ($lote instanceof Lote) {
            $texto .= ' y me interesa el lote '.$lote->getAttribute('codigo');
        }

        $texto .= '.';

        return redirect()->away('https://wa.me/'.$numero.'?text='.rawurlencode($texto));
    }

    /**
     * Mismo criterio que `PlanoPublicoController`: solo dígitos, y un número
     * hondureño de ocho se completa con el 504.
     */
    private function whatsapp(Proyecto $proyecto): ?string
    {
        $crudo = $proyecto->getAttribute('whatsapp');

        if (! is_string($crudo)) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $crudo) ?? '';

        if ($digitos === '') {
            return null;
        }

        return strlen($digitos) === 8 ? '504'.$digitos : $digitos;
    }
}
