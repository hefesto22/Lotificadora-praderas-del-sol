<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Los tres movimientos que salen del mismo modal.
 *
 * ═══ POR QUE UN TOGGLE Y NO TRES BOTONES ═══
 *
 * Lo pidió Mauricio el 10-ago-2026: «que en el modal aparezca un toggle si es
 * pago de cuota o abono a capital o ambas». El motivo es de mostrador — quien
 * atiende no sabe cuál de los tres es hasta que el cliente termina de hablar, y
 * con tres botones separados hay que cerrar el modal equivocado y volver a
 * empezar con el cliente esperando.
 *
 * ═══ POR QUE VIVE EN `Filament\Support` Y NO EN `Domain\Enums` ═══
 *
 * Porque no es una regla del negocio: es la pregunta de un formulario. El
 * dominio no tiene un «modo» — tiene tres operaciones con nombre propio
 * (`cobrarVariosLotes`, `abonarACapital`, `cobrarYAbonar`) y ninguna sabe que
 * existe este enum. Si mañana la pantalla se arma distinto, esto se borra y el
 * dominio no se entera. Nada de esto llega a la base: por eso no hay CHECK ni
 * `valores()` como en `ModalidadDeReprogramacion`.
 *
 * ═══ 🔴 `reprograma()` ES UNA FRONTERA DE PERMISO, NO UNA ETIQUETA ═══
 *
 * R21: el receptor cobra (`Create:Recibo`) pero NO reescribe un plan firmado —
 * eso es `Reprogramar:Venta`, de la administradora. Como el toggle mete los
 * tres caminos en un solo modal, este método es lo que decide qué opciones se
 * ofrecen Y se vuelve a preguntar en el servidor antes de ejecutar. Esconder el
 * permiso más caro detrás de un campo del formulario sería regalarlo: un campo
 * se falsifica, un permiso no.
 */
enum ModoDeCobro: string
{
    case Cuota = 'cuota';
    case Abono = 'abono';
    case Ambas = 'ambas';
    case ProntoPago = 'pronto_pago';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cuota      => 'Cuota',
            self::Abono      => 'Abono a capital',
            self::Ambas      => 'Ambas',
            self::ProntoPago => 'Pronto pago',
        };
    }

    /**
     * ⚠️ La segunda frase de «Abono» no es un adorno: desde el 24-ago-2026 el
     * abono se RECHAZA si el lote tiene una cuota vencida, y quien atiende tiene
     * que saberlo ANTES de teclear el monto, no después de que el modal le
     * devuelva un error con el cliente enfrente.
     */
    public function explicacion(): string
    {
        return match ($this) {
            self::Cuota      => 'Se aplica a las cuotas más viejas primero. El plan no se toca.',
            self::Abono      => 'Baja el capital del lote y reescribe las cuotas que faltan. Solo en lotes al día: si hay cuotas vencidas, se cobran primero o se usa «Ambas».',
            self::Ambas      => 'Primero termina de pagar las cuotas que están a medias; el sobrante baja el capital.',
            self::ProntoPago => 'Salda el lote entero: se escribe el descuento y el cliente entrega el resto.',
        };
    }

    /**
     * ¿Este camino reescribe un plan firmado?
     *
     * Es la pregunta que separa lo que puede hacer un receptor de lo que solo
     * puede hacer la administradora (R21).
     *
     * ⚠️ El pronto pago dice que NO, y no es un descuido: no reescribe nada,
     * TERMINA el plan del lote. Tiene su propio permiso —`ProntoPago:Venta`—
     * porque perdona plata, que es una llave distinta de repartir la misma
     * deuda de otra forma. Ver `perdonaSaldo()`.
     */
    public function reprograma(): bool
    {
        return $this === self::Abono || $this === self::Ambas;
    }

    /**
     * ¿Este camino perdona parte del saldo? (23-ago-2026)
     *
     * La otra frontera de permiso del modal, y la más cara: reprogramar no le
     * cuesta nada a la lotificadora; descontar sí.
     */
    public function perdonaSaldo(): bool
    {
        return $this === self::ProntoPago;
    }

    /**
     * ¿Hace falta escribir por qué?
     *
     * Todo lo que no es cobrar la cuota del mes deja al expediente distinto de
     * como lo firmaron, así que lleva motivo. Va aparte de `reprograma()`
     * desde que existe el pronto pago: los dos exigen motivo, pero por
     * permisos distintos.
     */
    public function exigeMotivo(): bool
    {
        return $this !== self::Cuota;
    }
}
