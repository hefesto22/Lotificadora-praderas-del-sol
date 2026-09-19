<?php

declare(strict_types=1);

namespace Database\Seeders\Clientes;

use App\Domain\Enums\UnidadDeArea;
use Database\Seeders\PlanoDeclarado;
use Database\Seeders\PlanoDesdeDxfSeeder;

/**
 * COLONIA RIO BLANCO — La Union, Copan. Carga inicial del plano.
 *
 *   php artisan db:seed --class="Database\Seeders\Clientes\ColoniaRioBlancoSeeder"
 *
 * Plano de distribucion de lotes del Ing. Gerson Menjivar, agosto de 2024,
 * levantado por el Top. Antonio Mejia Mendez. Propietario: Elder Dionel
 * Pinto. El archivo que se lee es el DXF nativo, sin conversion ni
 * limpieza de por medio.
 *
 * ═══ ESTE PLANO NO TRAE UN SOLO LOTE CERRADO ═══
 *
 * Todo esta en la capa `0`, dibujado con lineas sueltas: el perimetro de
 * cada manzana es una polilinea abierta y las divisiones son LINE que
 * mueren contra el. En la misma capa viven el cajetin y el membrete. Por
 * eso `lineasSueltas`: los lotes se arman siguiendo las lineas, y es lote
 * el contorno que tiene un numero adentro. Ver LotesDeLineasSueltas.
 *
 * Los numeros NO traen la letra de su manzana -dicen «1», «2», «3»- y el
 * nombre va en un texto aparte, «BLOQUE A». La manzana de cada lote sale
 * de la vecindad: los lotes que comparten lindero son una manzana, y se
 * llama como diga el «BLOQUE X» que cayo adentro de alguno.
 *
 * ═══ SE VENDE EN VARAS², CON LA VARA DEL INGENIERO ═══
 *
 * El plano rotula en varas² y el dibujo esta en metros. Dividiendo una
 * cosa por la otra, 73 de los 83 lotes dan una vara de 0.8350 m clavada a
 * cuatro decimales, y los demas quedan a milesimas: es la vara con la que
 * el ingeniero calculo, no los 0.8359 del sistema. Se declara la suya,
 * porque con ella el dibujo y el rotulo coinciden; con la otra, los 83
 * lotes cargarian dos decimas de porciento de diferencia que no existen.
 *
 * ═══ LO QUE DICE EL PLANO ═══
 *
 * 83 lotes en 5 manzanas, A a E, numeradas de 1 a N sin saltos, y
 * 36,431.17 varas² sumando los 83 rotulos de area.
 *
 * ⚠️ Esos numeros se contaron leyendo los TEXTOS del archivo -las cinco
 * series de numeros y los 83 rotulos de area-, NO de la salida de una
 * importacion: la suma de las areas no pasa por la geometria, y el conteo
 * por manzana se hizo mirando el dibujo. Pero no hubo un plano IMPRESO de
 * por medio, que es lo que pide PlanoDeclarado. Cuando llegue el PDF del
 * ingeniero, se coteja contra estos cinco numeros.
 *
 * Cinco rotulos de area vienen SIN la unidad -«A=447.08» donde los demas
 * dicen «A=447.08v2»-: A-9, A-17, B-6, E-1 y E-11. Entran igual, porque
 * el dibujo de cada uno confirma el numero dentro de la tolerancia. Ver
 * ImportadorDeDxf::areaSinUnidadPara().
 *
 * ⚠️ **El lote E-10 tiene el plano en contra.** Su rotulo dice 312.00 v2
 * -lo mismo que los ocho lotes que tiene encima- y su dibujo mide 320.81:
 * 2.8 % de mas.
 * O el rotulo se copio del vecino, o el lindero del fondo esta corrido.
 * Entra con los 312.00 del rotulo y el sistema lo deja marcado como
 * desalineado, que es exactamente para lo que esa marca existe. La
 * respuesta la tiene el ingeniero.
 *
 * No se importan calles: el plano las dibuja con dos lineas sueltas por
 * lado, no como un area cerrada.
 *
 * ═══ LO QUE LE FALTA ═══
 *
 * El PRECIO -los 83 entran en 0.00 y un lote sin precio no se puede
 * vender- y los planes de pago del desarrollo.
 */
final class ColoniaRioBlancoSeeder extends PlanoDesdeDxfSeeder
{
    protected function plano(): PlanoDeclarado
    {
        return new PlanoDeclarado(
            codigo: 'CRB',
            nombre: 'COLONIA RIO BLANCO',
            archivo: 'database/data/colonia-rio-blanco-plano.dxf',
            // Las cinco series de numeros del plano, manzana por manzana.
            lotesPorBloque: [
                'A' => 17, 'B' => 15, 'C' => 14,
                'D' => 17, 'E' => 20,
            ],
            // La suma de los 83 rotulos «A=...v2» del plano.
            areaTotal: 36431.17,
            // Todo el dibujo esta en la capa por defecto de AutoCAD.
            capaDeLotes: '0',
            capaDeRotulos: 'NUMERO SOLAR',
            unidad: UnidadDeArea::Varas,
            precioPorUnidad: '0',
            // La vara con la que calculo el ingeniero. Ver el docblock.
            varaEnMetros: '0.835000',
            datos: [
                'municipio'     => 'LA UNION',
                'departamento'  => 'CP',
                'direccion'     => 'LA UNION, COPAN, HONDURAS C.A.',
                'observaciones' => 'Plano de distribución de lotes, agosto de 2024. Propietario: ELDER DIONEL PINTO. '.
                                   'Levantó: Top. Antonio Mejía Méndez. Dibujó y aprobó: Ing. Gerson Menjívar. '.
                                   'La geometría de los 83 lotes se armó siguiendo las líneas del DXF nativo, y el área '.
                                   'de cada lote es la que el ingeniero rotuló en el plano, con su vara de 0.8350 m. '.
                                   'Pendiente con el ingeniero: el lote E-10 dice 312.00 v² y su dibujo mide 320.81.',
            ],
            // Este dibujante puso las areas en su propia capa.
            capaDeAreas: 'areas',
            lineasSueltas: true,
        );
    }
}
