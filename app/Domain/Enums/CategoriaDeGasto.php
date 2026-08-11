<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * En qué se fue el dinero.
 *
 * ═══ POR QUE UN CATALOGO Y NO TEXTO LIBRE ═══
 *
 * Mismo argumento que `ServicioDelProyecto`: «terracería» escrito a mano sale
 * también como «Terraceria», «terraceria bloque H» y «movimiento de tierra».
 * Con texto libre la misma lotificadora termina con cuatro formas de decir lo
 * mismo, ninguna se puede filtrar y la pregunta que importa —«¿cuánto llevamos
 * gastado en calles?»— no tiene respuesta.
 *
 * Por eso el formulario pide las dos cosas: la CATEGORIA sale de esta lista y
 * se puede sumar; el DETALLE es texto libre, obligatorio, y es donde va
 * «cunetas del bloque H, segunda etapa».
 *
 * ═══ ESTO ES DEL PRODUCTO, NO DE PRADERAS DEL SOL (Ley L0) ═══
 *
 * La lista tiene que servirle a cualquier lotificadora que compre el sistema,
 * así que son las cuentas que aparecen en un desarrollo de lotes en general y
 * no las que Praderas usó el mes pasado. `Otro` existe a propósito y con
 * detalle obligatorio: forzar a clasificar mal es peor que dejar una puerta.
 *
 * ═══ LO QUE ESTA LISTA A PROPOSITO NO HACE ═══
 *
 * No separa inversión de gasto operativo. La separación existe y sirve —una
 * cosa es lo que se incorpora al terreno y otra lo que se consume en el mes—
 * pero dónde cae exactamente la mano de obra, lo legal y los impuestos es una
 * decisión de contabilidad, no de programación. El día que un contador la
 * conteste se agrega acá como un método más, y ninguna fila guardada cambia.
 *
 * La lista es la fuente de verdad: la migración arma su CHECK a partir de
 * `valores()`, así que la base y el código no pueden divergir.
 *
 * ⚠️ Agregar un `case` acá NO alcanza para una instalación que ya migró:
 * `gastos_categoria_valida_chk` guarda la lista dentro de la base y hay que
 * recrearlo con un ALTER, igual que hizo `FormaDePago` con la tarjeta.
 */
enum CategoriaDeGasto: string
{
    // ── Lo que se incorpora al terreno ────────────────────────────────
    case Terreno = 'terreno';
    case Terraceria = 'terraceria';
    case Calles = 'calles';
    case Agua = 'agua';
    case Energia = 'energia';
    case Drenaje = 'drenaje';
    case Topografia = 'topografia';
    case Materiales = 'materiales';
    case ManoDeObra = 'mano_de_obra';
    case Maquinaria = 'maquinaria';

    // ── Lo que cuesta operar y vender ─────────────────────────────────
    case Legal = 'legal';
    case Impuestos = 'impuestos';
    case Publicidad = 'publicidad';
    case Comisiones = 'comisiones';
    case Administracion = 'administracion';
    case Mantenimiento = 'mantenimiento';
    case Financiero = 'financiero';
    case Otro = 'otro';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $categoria): string => $categoria->value, self::cases());
    }

    /**
     * Para el Select del formulario y el SelectFilter de la tabla.
     *
     * `Select::options()` exige `array<string>` — nada de enums adentro.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $categoria) {
            $opciones[$categoria->value] = $categoria->etiqueta();
        }

        return $opciones;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Terreno        => 'Compra del terreno',
            self::Terraceria     => 'Terracería y movimiento de tierra',
            self::Calles         => 'Calles y pavimento',
            self::Agua           => 'Agua potable',
            self::Energia        => 'Energía eléctrica y alumbrado',
            self::Drenaje        => 'Drenajes y cunetas',
            self::Topografia     => 'Topografía y planos',
            self::Materiales     => 'Materiales',
            self::ManoDeObra     => 'Mano de obra y planilla',
            self::Maquinaria     => 'Maquinaria, combustible y transporte',
            self::Legal          => 'Legal, notarial y registro',
            self::Impuestos      => 'Impuestos y permisos',
            self::Publicidad     => 'Publicidad',
            self::Comisiones     => 'Comisiones de venta',
            self::Administracion => 'Administración y oficina',
            self::Mantenimiento  => 'Mantenimiento y vigilancia',
            self::Financiero     => 'Intereses y gastos financieros',
            self::Otro           => 'Otro',
        };
    }

    /**
     * Color del badge en Filament.
     *
     * Son dieciocho categorías y la paleta del panel tiene seis colores, así
     * que el color agrupa en vez de identificar: obra en `warning`, servicios
     * en `info`, lo que sale por vender en `success`, lo que se le paga al
     * Estado o al abogado en `danger`, y la operación en `gray`. Quien mira la
     * tabla distingue de un vistazo la naturaleza del gasto; el nombre exacto
     * lo dice la etiqueta al lado.
     */
    public function color(): string
    {
        return match ($this) {
            self::Terreno, self::Terraceria, self::Calles, self::Materiales,
            self::ManoDeObra, self::Maquinaria => 'warning',

            self::Agua, self::Energia, self::Drenaje, self::Topografia => 'info',

            self::Publicidad, self::Comisiones => 'success',

            self::Legal, self::Impuestos, self::Financiero => 'danger',

            self::Administracion, self::Mantenimiento, self::Otro => 'gray',
        };
    }
}
