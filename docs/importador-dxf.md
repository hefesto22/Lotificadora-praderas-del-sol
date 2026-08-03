# Importador de planos DXF

Convierte el plano de AutoCAD del topógrafo en lotes del sistema. Es la
respuesta a la **pregunta 15** de `dominio.md` —cómo llegan los ~500
lotes— cuando la respuesta es "en el plano".

---

## Por qué existe

El sistema tenía dos formas de meter geometría, y a ninguna le servía un
plano real:

| Herramienta | Qué hace | Para qué sirve |
|---|---|---|
| `GeneradorDeLotes` | **Crea** lotes nuevos con forma rectangular | Trazar un proyecto que todavía no existe |
| `AcomodadorDelPlano` | **Dibuja** lotes que ya existen, en fila | Ver algo mientras no hay plano; es un esquema |
| `ImportadorDeDxf` | **Lee el plano legal** y crea los lotes con su forma real | Lo que se usa cuando el plano llega |

Es también la única operación que **apaga** la marca de
`plano_esquematico`: un plano importado viene del documento del topógrafo,
no de una aproximación del sistema.

---

## Las siete trampas del formato DXF

Cada una está resuelta en el código y cubierta por un test. Se documentan
porque son la diferencia entre leer un plano y leer basura, y porque
ninguna es evidente leyendo el código sin contexto.

1. **Se lee estrictamente en pares desde el primer byte.** Buscar líneas
   iguales a `"0"` para encontrar entidades produce entidades fantasma: hay
   *valores* que son la cadena `"0"` —el color, las banderas, una
   coordenada en el origen— y el escáner toma la línea siguiente como
   nombre de entidad.

2. **`trim()` del código y del valor.** La especificación obliga a escribir
   los códigos en un campo de tres caracteres justificado a la derecha
   (`"  0"`, `" 10"`, `"100"`), y los valores numéricos también vienen
   rellenados (`"    76"`).

3. **CRLF y LF.** AutoCAD escribe CRLF. Un `\r` pegado al final convierte
   la capa `LOTES` en `LOTES\r`, que no coincide con nada.

4. **El bulge (código 42) pertenece al vértice anterior** y sólo aparece
   cuando no es cero. Indexarlo en paralelo a los vértices pone los arcos
   en el lado equivocado.

5. **En `POLYLINE` las coordenadas no están en la entidad** sino en las
   entidades `VERTEX` que la siguen hasta `SEQEND`. Los `10/20` de la
   `POLYLINE` son un punto ficticio que vale siempre cero. Este formato
   viejo sigue apareciendo porque mucho software de topografía exporta a
   DXF R12 por compatibilidad.

6. **En `TEXT`, si hay justificación (`72` o `73` distintos de cero), la
   posición real es `11/21`,** no `10/20`. Los números de lote casi siempre
   se rotulan centrados, así que leer siempre `10/20` desubica
   prácticamente todas las etiquetas del plano.

7. **En `MTEXT` los trozos del código `3` vienen antes del código `1`.**
   Quedarse con el `1` devuelve sólo el final del texto.

Además: las capas que vienen de una referencia externa se guardan como
`plano$0$LOTES`, y hay que quitar el prefijo o ninguna capa de xref
coincide jamás con lo que eligió el usuario.

---

## Las tres conversiones

Se aplican con **una sola transformación global**, calculada sobre lotes y
calles juntos. Si cada capa usara la suya, el plano quedaría despedazado.

1. **El eje Y se invierte.** En CAD la Y crece hacia el norte; en SVG crece
   hacia abajo. Sin invertir, el lote de la esquina noreste aparece en la
   sureste.
2. **Las coordenadas se llevan al origen.** Un plano en UTM tiene abscisas
   de seis o siete dígitos.
3. **Las unidades se pasan a varas.**

---

## La unidad se pregunta siempre

Aunque el archivo declare `$INSUNITS`, el importador pregunta. Motivo: en
planos de topografía esa variable viene sin configurar con muchísima
frecuencia, y *"seguro son metros"* es exactamente la clase de suposición
que después sale impresa como un área equivocada en una escritura.

> **Esto cambia el peso de la pregunta 16.** Hasta ahora el factor de la
> vara era cosmético: las áreas venían del documento legal ya en varas² y
> el factor sólo afectaba la conversión informativa a m². **Al importar un
> DXF el factor entra al camino del dinero**, porque el área nace de la
> geometría en metros. Un error del 1 % en el factor es un error del 1 % en
> el valor de cada lote importado.
>
> Por eso existe la opción **"el plano ya está dibujado en varas"**: si el
> topógrafo dibuja en varas —que en Honduras no es raro— no hay conversión
> que hacer y el factor no toca nada.

---

## Precisión de los arcos

Una poligonal inscrita siempre encierra **menos** área que el arco. Medido
sobre un semicírculo de radio 5:

| Densidad | Error de área |
|---|---|
| 12° por segmento | 0.73 % |
| **3° por segmento (el que se usa)** | **0.036 %** |

A 3° el error queda por debajo del redondeo de los 4 decimales con que se
guardan las áreas. El peso del dibujo es barato; un lote mal medido no.

---

## El plano de prueba

`tests/Fixtures/valle-verde.dxf` — 78 lotes. No es decorativo: cada rasgo
ejercita una trampa.

| Rasgo | Qué prueba |
|---|---|
| 72 lotes rectangulares y trapezoidales | Caso normal y linderos diagonales |
| 6 lotes en abanico en una cul-de-sac | Arcos por bulge |
| 1 lote en formato `POLYLINE/VERTEX/SEQEND` | El formato viejo |
| Rótulos con `72=1`, `73=2` y `10/20` desplazado | La trampa de la justificación |
| 1 rótulo en `MTEXT` con `\H` y `\C` | Limpieza de códigos de formato |
| 4 calles como polígonos de área | Calles importadas |

Se regenera con `python3 tests/Fixtures/valle-verde.py`.

---

## Cómo se usa

**Desde el panel:** Proyectos → abrir el proyecto → *Ver lotes* →
**Importar plano DXF**. Las capas se detectan solas por su nombre; se
pueden escribir a mano si el plano usa nombres raros.

**El proyecto de demostración:**

```
php artisan db:seed --class=PlanoDemoSeeder
```

Crea *Residencial Valle Verde* importando el plano de prueba. No toca
Praderas del Sol.

---

## Lo que todavía no hace

- **No expande bloques (`INSERT`).** Si la geometría del plano vive dentro
  de bloques de AutoCAD, se reporta cuántos hay pero no se importan: sus
  coordenadas son locales al bloque y traerlas como si fueran del mundo
  pondría los lotes en el lugar equivocado.
- **No lee DXF binario ni DWG.** Hay que exportar a DXF ASCII.
- **No detecta a qué manzana pertenece cada lote.** Todos entran al bloque
  que se elija. Con un plano de varias manzanas conviene importar una por
  vez, filtrando por capa.
- **La convención de signo del bulge** está derivada de la definición del
  formato y verificada como autoconsistente, pero no contra un plano real
  con una curva conocida. Si al importar un plano de verdad una cul-de-sac
  aparece curvada hacia el lado contrario, se invierte un signo en
  `GeometriaPlana::arcoPorBulge()`.
