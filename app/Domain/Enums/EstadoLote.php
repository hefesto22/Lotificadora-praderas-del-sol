<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Estados contractuales de un lote (§8.2).
 *
 * Son EXACTAMENTE estos SEIS. Agregar uno requiere aprobación, porque el
 * estado del lote aparece en reportes que la contratante usa para decidir.
 * Esta lista es la fuente de verdad: la migración genera su CHECK constraint
 * a partir de valores(), así que la base y el código no pueden divergir.
 *
 * ═══ RESERVADO SE AGREGO EL 12-AGO-2026, Y POR QUE ═══
 *
 * Lo autorizó Mauricio ese día. Lo pidió el cuaderno de la cartera vieja: el
 * exp. 0080 dice «Herederos — Bloque B lotes 1 al 16» y nada más. Esos
 * dieciséis lotes NO están a la venta, pero tampoco están apartados ni
 * vendidos ni cancelados, así que figuraban como DISPONIBLES —y el plano
 * público los estaba ofreciendo—.
 *
 * `Apartado` no servía: es el de R14, con seña, vencimiento y prórroga.
 * Meter ahí a los herederos sería inventar un apartado que nadie firmó y que
 * vence en quince días. `Cancelado` tampoco: eso es algo que se cayó.
 *
 * Reservado es lo que de verdad pasa: **la lotificadora lo sacó del mercado
 * por decisión propia**. Cubre a los herederos, a las iglesias y a cualquier
 * lote que ella quiera guardar. El porqué va en `lotes.observaciones`.
 *
 * ═══ DONADO SE AGREGO EL 12-AGO-2026, Y POR QUE ═══
 *
 * Lo autorizó Mauricio ese día, el mismo en que se decidió que el Excel de la
 * contratante iba a ofrecer «DONACIÓN» como tipo de operación. Ofrecerlo en la
 * planilla y no tenerlo en el sistema era prometer algo que después no se iba
 * a poder cargar.
 *
 * Una donación **no es una venta de L 0.00**, y esa es toda la razón de que
 * sea un estado propio y no `Vendido`:
 *
 *   · Un lote vendido genera cartera. Uno donado no genera ninguna, y
 *     mezclarlos hace que «183 vendidos» deje de significar «183 lotes que
 *     traen plata» — que es exactamente para lo que ella mira ese número.
 *   · La diferencia tendría que recordarla cada reporte, filtrando por el
 *     `tipo` del compromiso. El primero que se olvide da un número que nadie
 *     va a poder explicar tres meses después.
 *
 * `Reservado` tampoco servía: ahí no hay nadie del otro lado. En una donación
 * sí lo hay —una iglesia, una escuela, la municipalidad, un heredero—, tiene
 * fecha y tiene escritura. De hecho el camino normal es de uno al otro: el
 * lote se reserva primero y se dona cuando el trámite se cierra.
 */
enum EstadoLote: string
{
    case Disponible = 'disponible';
    case Apartado = 'apartado';
    case Vendido = 'vendido';
    case Cancelado = 'cancelado';
    case Reservado = 'reservado';
    case Donado = 'donado';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Apartado   => 'Apartado',
            self::Vendido    => 'Vendido',
            self::Cancelado  => 'Cancelado',
            self::Reservado  => 'Reservado',
            self::Donado     => 'Donado',
        };
    }

    /**
     * Color del badge en Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::Disponible => 'success',
            self::Apartado   => 'warning',
            self::Vendido    => 'info',
            self::Cancelado  => 'danger',
            // Gris: no es bueno ni malo ni un problema. Está guardado.
            self::Reservado => 'gray',
            /*
             * Verde azulado. No es un color de Filament de fábrica: lo
             * registra el panel en `->colors()`, y ahí está el porqué de que
             * no se haya reusado ninguno de los seis que vienen.
             */
            self::Donado => 'teal',
        };
    }

    /**
     * Color del relleno en el plano, en hexadecimal.
     *
     * Vive pegado a color() a proposito: son la misma decision vista en
     * dos superficies —el badge de la tabla y el poligono del plano— y
     * separarlas es garantizar que algun dia el lote vendido sea azul en
     * un lado y rojo en el otro.
     *
     * Se sigue el enum, NO la convencion de los portales de venta (donde
     * vendido suele ser rojo). Adentro del panel, rojo significa problema
     * y una venta cerrada no es un problema: es el objetivo.
     */
    public function colorHex(): string
    {
        return match ($this) {
            self::Disponible => '#16a34a',
            self::Apartado   => '#d97706',
            self::Vendido    => '#2563eb',
            self::Cancelado  => '#dc2626',
            self::Reservado  => '#7c3aed',
            self::Donado     => '#0d9488',
        };
    }

    /**
     * El relleno con el que el lote se pinta en la vidriera publica.
     *
     * Distinto de `colorHex()` a proposito. Adentro del panel el color es una
     * etiqueta —verde bueno, azul cerrado— y un relleno saturado se lee bien
     * porque quien mira sabe que esta mirando. En el plano publico son 301
     * poligonos pegados uno al lado del otro: con relleno saturado la pagina
     * se ve como un tablero de ajedrez y deja de leerse la FORMA del terreno,
     * que es para lo que el cliente abrio el link. Pastel adentro y saturado
     * en el borde dice lo mismo sin gritar.
     *
     * ⚠️ Vive aca y no en el CSS de `publico/plano.blade.php` porque los usan
     * TRES superficies: ese CSS, la leyenda de la pagina y el PNG que ve
     * WhatsApp. Tres listas de colores que tienen que coincidir terminan no
     * coincidiendo.
     */
    public function relleno(): string
    {
        return match ($this) {
            self::Disponible => '#b8ead0',
            self::Apartado   => '#fbdcab',
            self::Vendido    => '#f7b8b3',
            self::Cancelado  => '#e4e4e7',
            self::Reservado  => '#ddd0f7',
            self::Donado     => '#bfe6e0',
        };
    }

    /**
     * El borde, que es lo que de verdad distingue un lote del vecino cuando
     * el plano esta alejado y cada uno mide tres pixeles.
     */
    public function borde(): string
    {
        return match ($this) {
            self::Disponible => '#4eb37e',
            self::Apartado   => '#dfa04a',
            self::Vendido    => '#e0736a',
            self::Cancelado  => '#a1a1aa',
            self::Reservado  => '#9b7fd4',
            self::Donado     => '#4fb3a8',
        };
    }

    /**
     * Como se ve este lote DESDE AFUERA, en el plano publico.
     *
     * Tres de los seis estados salen a la calle disfrazados de VENDIDO:
     * reservado, donado y cancelado. Lo pidio Mauricio el 13-ago-2026:
     * «que reservado y donado solo aparezcan como venta, para que el mundo
     * no lo sepa; solo que aparezca como venta y ya».
     *
     * ═══ POR QUE ═══
     *
     * Porque el plano publico es una vidriera, y de un lote que no esta a
     * la venta lo unico que le sirve saber al que pasa es eso: que no esta.
     * El POR QUE no esta es asunto de adentro. Cuantos lotes se guardo la
     * familia y a quien se le regalaron son datos que le dicen a la
     * competencia —y al vecino— como se administra el desarrollo, y no
     * ayudan a vender ni un lote.
     *
     * Los tres se juntan en «vendido» y no en un estado propio porque
     * cualquier color con nombre nuevo es una pregunta nueva. Un plano con
     * tres tonos —libre, apartado, vendido— no deja nada que interpretar.
     *
     * ⚠️ El cancelado entra en la lista y esto REEMPLAZA a la vieja
     * `enLeyendaPublica()`, que lo pintaba de rojo propio y despues lo
     * borraba de la leyenda. Eso dejaba lotes «de un color sin nombre»,
     * que era justo lo que aquel metodo decia querer evitar. Ahora no hay
     * color sin nombre porque no hay color de mas.
     *
     * ⚠️ ES SOLO LA PINTURA. Nada de esto toca la verdad: el contador de
     * disponibles, el `seCotiza` que decide quien lleva precio, los
     * reportes, la ficha del lote y el plano de administracion siguen
     * viendo los seis estados como son. Un lote donado que afuera se lee
     * «Vendido» adentro sigue sin generar un lempira de cartera.
     */
    public function comoSeVeAfuera(): self
    {
        return match ($this) {
            self::Disponible, self::Apartado, self::Vendido => $this,
            self::Reservado, self::Donado, self::Cancelado  => self::Vendido,
        };
    }

    /**
     * ¿El lote está comprometido con un cliente?
     *
     * Un lote apartado o vendido no puede volver a apartarse ni venderse
     * a otra persona sin pasar antes por una rescisión o un vencimiento.
     *
     * Un lote DONADO también: la donación tiene a alguien del otro lado, su
     * fecha y su escritura. Que no haya entrado plata no la hace menos
     * definitiva — al contrario, es la más difícil de deshacer.
     *
     * ⚠️ Un lote RESERVADO no está comprometido y esto devuelve false a
     * propósito: no hay cliente del otro lado. Que no se pueda vender es otra
     * cosa y la contesta `seVende()`.
     */
    public function estaComprometido(): bool
    {
        return in_array($this, [self::Apartado, self::Vendido, self::Donado], true);
    }

    /**
     * ¿Este lote se le puede vender a alguien hoy?
     *
     * Disponible sí, obviamente. Apartado también: al cliente que lo apartó,
     * que es el camino normal de R14 —`vender()` verifica que sea el mismo—.
     *
     * Los otros cuatro no, cada uno por su razón: el vendido ya tiene dueño,
     * el cancelado está fuera por un problema, el reservado lo sacó del
     * mercado la lotificadora, y el donado ya se entregó. Los cuatro se
     * contestan igual: no.
     */
    public function seVende(): bool
    {
        return $this === self::Disponible || $this === self::Apartado;
    }

    /**
     * ¿Este lote se puede donar hoy?
     *
     * Lista BLANCA de dos, y por eso no se escribe como «todos menos». El
     * disponible es obvio. El RESERVADO es el camino normal: los dieciséis
     * lotes del bloque B están guardados para los herederos y una iglesia se
     * apalabra mucho antes de que haya escritura — se reserva mientras corre
     * el trámite y se dona cuando se firma.
     *
     * Un lote APARTADO no, aunque sea de la misma persona: ese apartado tiene
     * una seña que hay que devolver, y eso lo sabe hacer `liberar()`.
     *
     * ⚠️ La usan tres lugares —`RegistroDeCompromisos::donar()`, el plano y el
     * panel del lote—. Que sean tres es justamente el motivo de que la
     * pregunta viva acá y no repetida en cada uno.
     */
    public function seDona(): bool
    {
        return $this === self::Disponible || $this === self::Reservado;
    }

    /**
     * ¿Se puede CORREGIR la donación de este lote?
     *
     * Solo un lote DONADO, y la palabra importa: esto no es «devolver» una
     * donación —para cuando una donación de verdad quedó registrada, lo
     * normal es que la escritura ya esté firmada a nombre de otro—. Esto
     * es deshacer un REGISTRO que no debió existir.
     *
     * El caso que lo pidió, de Mauricio el 13-ago-2026: «iban a donar 5,
     * los donaron, pero hubo un error, así que solo se donarían 3; esos 2
     * deben quedar disponibles para la venta». Y lo que lo hace simple es
     * que una donación **no mueve un lempira**: no hay seña que devolver,
     * ni recibos que anular, ni plan de cuotas que desarmar. Soltar el
     * lote es todo lo que hay que hacer.
     */
    public function seDeshaceLaDonacion(): bool
    {
        return $this === self::Donado;
    }

    /**
     * ¿Este lote se puede guardar para herencia hoy?
     *
     * Solo el DISPONIBLE, y es una lista de uno a proposito. Guardar un
     * lote no le devuelve una seña a nadie ni rescinde nada: si esta
     * apartado o vendido, primero hay que deshacer ESO —que es un tramite
     * con plata de por medio— y recien despues guardarlo. Un cancelado se
     * reactiva desde su ficha, y uno donado ya se entrego.
     */
    public function seReserva(): bool
    {
        return $this === self::Disponible;
    }

    /**
     * ¿Se puede sacar de herencia este lote?
     *
     * Solo uno RESERVADO, y es tan simple como se ve: guardarlo no movio
     * un lempira, asi que soltarlo tampoco. Vuelve a disponible y listo.
     *
     * ⚠️ No es la unica salida que tiene. Un lote reservado tambien
     * `seDona()`: el camino normal del heredero es guardarlo mientras
     * corre el tramite y donarlo cuando se firma la escritura.
     */
    public function seDeshaceLaReserva(): bool
    {
        return $this === self::Reservado;
    }

    /**
     * La misma etiqueta, pero para las pantallas de ADENTRO.
     *
     * Cambia UNA sola: `Reservado` se lee «Herencia». Decision de Mauricio
     * el 13-ago-2026 —«herencia adentro, reservado afuera»— y las dos
     * palabras estan bien, cada una para su lector:
     *
     *  · Adentro se administra un cupo de lotes guardados para la familia.
     *    «Reservado» no dice para quien ni por que, y quien configura el
     *    desarrollo o atiende en ventanilla necesita justamente eso.
     *  · Afuera —`PlanoPublico`— sigue diciendo «Reservado», que en bienes
     *    raices se entiende sola y CIERRA la conversacion. «Herencia» le
     *    cuenta al que pasa por la web que hay una familia dueña detras,
     *    que no es asunto de una vidriera.
     *
     * ⚠️ El unico lugar que se queda con `etiqueta()` es el plano publico.
     * Ante una pantalla nueva, la pregunta es a quien se le muestra.
     */
    public function etiquetaInterna(): string
    {
        return $this === self::Reservado ? 'Herencia' : $this->etiqueta();
    }

    /**
     * La frase que se le muestra a quien abrió el lote y no lo puede vender.
     *
     * Vive pegada a `seVende()` por la misma razón que `relleno()` vive pegado
     * a `color()`: son la misma decisión vista en otra superficie. El panel del
     * plano preguntaba `estado !== 'vendido'` y escribía la frase del vendido a
     * mano, así que un lote RESERVADO ofrecía «Vender este lote» igual que
     * cualquier disponible — el dominio lo rechazaba después, pero recién
     * después de que la persona llenara el formulario delante del cliente.
     *
     * Dice lo mismo que `CompromisoInvalidoException`, en corto: allá se
     * explica un rechazo que ya pasó, acá se evita que pase.
     */
    public function porQueNoSeVende(): ?string
    {
        return match ($this) {
            self::Disponible, self::Apartado => null,
            self::Vendido                    => 'Lote vendido. Deshacer una venta es una rescisión y ese trámite todavía no está en el sistema.',
            self::Cancelado                  => 'Lote cancelado. Hay que reactivarlo desde su ficha antes de comprometerlo con alguien.',
            self::Reservado                  => 'Lote guardado para herencia: la lotificadora lo sacó del mercado. El motivo está en las observaciones del lote.',
            self::Donado                     => 'Lote donado. Ya tiene dueño y no vuelve al inventario.',
        };
    }

    /**
     * ¿Se pueden editar área, precio y valor?
     *
     * §8.2: "Un lote vendido no se edita en precio ni área — el valor que
     * vale es el congelado en venta_lote". La regla se hace cumplir en tres
     * capas: acá, en el modelo Lote y en un trigger de PostgreSQL, para que
     * ni un seeder ni un import ni un tinker puedan saltearla.
     *
     * ⚠️ Un lote DONADO sí se puede corregir, y no es un olvido. Lo que la
     * regla protege es el estado de cuenta de un cliente: si el precio del
     * lote se moviera, el saldo que él tiene firmado dejaría de cuadrar. Una
     * donación no tiene saldo ni cuotas, y el valor con el que se declaró ya
     * quedó congelado en su compromiso — corregir después el precio de lista
     * del lote no le mueve nada a nadie.
     */
    public function permiteEditarValores(): bool
    {
        return $this !== self::Vendido;
    }
}
