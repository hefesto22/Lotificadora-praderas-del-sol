# De dónde sale la geometría de Praderas del Sol

> **22-ago-2026 — la manzana I estaba a medias.** La primera lectura dejó
> 301 lotes; el plano tiene 309. Faltaba la segunda fila entera de la
> manzana I (I-8 a I-15). Ver [La manzana I](#la-manzana-i-el-faltante-que-no-se-veía)
> al final. Los números de este documento ya están corregidos.

Los 309 lotes que dibuja `VerPlano` salen del **DXF nativo de Civil 3D**
con el que el Ing. Gerson Menjívar levantó el plano (`PLANO DIONEL
CORPUS.dxf`, abril 2026). Este documento explica cómo se leyó, **qué número
es exacto y cuál es derivado**, y qué queda marcado para revisar.

Importa porque el área multiplica al precio por vara (§8.3.1). Un área
inventada no es un detalle cosmético: termina en un contrato.

## Los tres archivos que llegaron, y cuál sirve

Se probaron tres. Vale la pena dejar escrito en qué se distinguen, para no
volver a pedir el equivocado.

| | PDF | DXF convertido | **DXF nativo** |
|---|---|---|---|
| Archivo | `PLANO LEONEL CORPUS 220626.pdf` | `salida.dxf` | **`PLANO DIONEL CORPUS.dxf`** |
| Versión | — | `AC1009` (R12) | **`AC1027`** (AutoCAD 2013) |
| Capas | — | una sola, `0` | **200+, de plantilla Civil 3D** |
| Texto seleccionable | **0** | **0** | **705** (652 `TEXT` + 53 `MTEXT`) |
| Coordenadas | puntos de hoja | pulgadas de hoja | **metros de campo** |
| Lotes que se pudieron cerrar | 265 | 281 | **309** |
| Error contra el área impresa | 0.4–0.5 % | 0.01–0.15 % | **0.006 % mediana** |

Los dos primeros son la misma impresión: alguien abrió el ploteo y lo
guardó como DXF. Los linderos son trazos sueltos que *parecen* lotes al
mirarlos, y los números son line art del tipo SHX, no caracteres.

**Cómo saber en diez segundos si un DXF sirve:** si al abrirlo tiene **una
sola capa llamada `0`** y **ningún texto seleccionable**, es una conversión
del impreso y no aporta nada. El bueno trae los rótulos como texto y las
coordenadas del levantamiento.

## La escala

El dibujo está en **metros**; el plano se rotula en **varas**. La
conversión no se estimó, viene escrita en el encabezado del archivo:

```
$INSUNITS  = 6                    metros
$DIMLFAC   = 1.197604790419161    factor de las cotas
```

De ahí **1 vara = 1 / 1.1976 = 0.835 m**, que es la vara castellana que se
usa en Honduras. Con esa constante el área calculada de cada polígono cae
sobre el área **impresa** en el plano.

## Cómo se leen los lotes

1. **Expandir los `INSERT`.** La geometría de los lotes no está suelta en
   `ENTITIES`: vive dentro de bloques de AutoCAD, referenciados por 38
   `INSERT`. Cada uno lleva su traslación, su escala y su rotación, y hay
   que aplicarlas para tener el trazo en coordenadas de campo. Sin esto el
   plano se lee medio vacío.

2. **Extraer las caras del grafo planar.** Un lote es literalmente una cara
   acotada de la red de linderos. Con el archivo nativo la red ya cierra:
   **cero extensiones**, ningún *snap* global, ningún lindero movido.

3. **El rótulo manda.** Una cara es un lote **si y solo si contiene
   exactamente un rótulo de área**. No hay rangos, ni heurísticas de forma,
   ni numeración por posición. El plano trae 305 rótulos `vr2` y cada uno
   cayó dentro de una sola cara.

   Cuatro de esos rótulos no son lotes y no se cargan: las dos áreas
   verdes (4,668.94 y 2,436.33 vr²) y los dos restos de finca (17,198.06 y
   12,213.06).

4. **El número y el bloque se leen, no se deducen.** Los números y las 24
   letras `BLOQUE` son texto real. La numeración serpentina por posición
   que usaba la versión anterior ya no existe.

Resultado: **309 lotes en 24 bloques, de la A a la X**, 87,959.26 vr² en
total, 233 de ellos el lote tipo de 12.50 V × 20.00 V = 250.00 vr².

| Dibujo contra texto impreso | |
|---|---|
| Mediana | **0.006 %** |
| Percentil 90 | 0.027 % |
| Percentil 99 | 0.032 % |
| Máximo | 2.10 % (el X-15, ver abajo) |

## La polilínea vieja que sobraba

El topógrafo dejó dibujado el **límite viejo del área verde**: una
polilínea punteada —las dos únicas entidades `DASHED2` del archivo, 190.1
varas en total— que pasa por el fondo de los bloques A y P partiendo cada
lote en dos, y de paso cruza en diagonal la calle entre P y O.

No es lindero de nada actual. Los diez lotes que atraviesa —**A-1 a A-7 y
P-1 a P-3**— se venden enteros, y sus áreas impresas lo demuestran. Así
que se quita **antes** de armar las caras, no después: con eso los rótulos
caen cada uno dentro de una cara entera y **no queda una sola cara que
reparar**.

Cómo se encontró, para poder repetirlo: el interior de un lote está vacío,
así que **cualquier trazo que lo cruce por dentro no es lindero de nadie**.
De los ~12,000 segmentos del dibujo, con ese criterio salen exactamente
dos entidades — y son esas. Y como el trazo es uno solo, si un pedazo no
es lindero, el resto tampoco: se quita completo, no solo la parte que cae
dentro del lote.

Detalles del criterio, que importan:

- Para **decidir** si un trazo sobra se mide contra el lote encogido
  0.6 varas, para que el lindero propio y el temblor del trazo no cuenten.
  Se marca solo si mete más de 3 varas adentro.
- El encogido va **lote por lote y después la unión**. Al revés —encoger
  la unión ya hecha— los linderos compartidos quedan por dentro y se
  descarta medio plano.

El filtro sigue puesto en el generador del calco como red: hoy no quita
nada, y si mañana aparece otro trazo así, lo avisa.

## El X-15, el único marcado

Su cara da **461.78 vr²** contra las **471.68** que dice el plano: le
faltan 6.89 m² que en el dibujo quedaron del lado de la calzada, y no hay
ninguna pieza suelta que sumarle (la cara de la calle mide 1,493 vr²). Sus
dos vecinos rotulados cuadran exactos con lo suyo.

Se carga con **el área del plano**, que es la que se vende, y con **el
dibujo que hay**, que es el que se ve. La diferencia no se esconde: el lote
sale marcado en el mapa por `poligonoDesalineado()` (§8.2,
`TOLERANCIA_DE_AREA`), y hay un test que se pone rojo si aparece un
segundo.

## Qué es exacto y qué no

**Exacto**

- El **área** de los 309 lotes: es el literal del rótulo del plano.
- El **número** de lote y la **letra de bloque**: son el texto del plano.
- La **forma y la posición** de cada lote, salvo el X-15.

**Derivado**

- El **frente** y el **fondo**: salen del rectángulo mínimo que envuelve al
  polígono. Sirven para la ficha, no para escriturar.
- El **polígono del X-15**, corto en 6.89 m² y marcado como tal.

Las calles **no se cargan como registros**. El calco las dibuja con sus
nombres y sus anchos reales, que es mejor que un polígono deducido.

## El calco

El dibujo del topógrafo va **de fondo** bajo los polígonos, en
`public/planos/rps-fondo.json`: sus mismos trazos —linderos, calles, áreas
verdes, la cancha, el norte, los perfiles— en las **mismas coordenadas en
varas** que los lotes de la base. Son 3,594 polilíneas y 52 rótulos, 195 KB.

El reparto es claro:

- **El calco** es lo que se ve: el plano tal cual.
- **Los polígonos** son lo que se pinta por estado y lo que se clickea.

Con el calco encendido se ocultan nuestros números y se leen los del
topógrafo. El botón `Plano / Lotes` alterna entre las dos vistas.

El calco sale del mismo trazo del que salen los lotes, ya sin la polilínea
vieja, así que lo que se ve y lo que se clickea cuentan la misma historia:
ningún trazo del calco cruza el interior de un lote.

## Cómo se carga

```bash
PRECIO_VARA=1500 php artisan db:seed --class=PlanoRealPraderasSeeder
```

Reemplaza el trazado anterior del mismo proyecto (`RPS`). Si algún lote ya
está apartado o vendido, **no borra nada** y se detiene.

**Con la base ya operando esa puerta está cerrada**, y es justo cuando
aparecen los faltantes. Para eso está `olympo:completar-plano`, que lee
este mismo archivo y solo **inserta** lo que no existe:

```bash
php artisan olympo:completar-plano RPS database/data/praderas-plano.json --ensayo
php artisan olympo:completar-plano RPS database/data/praderas-plano.json
```

No borra, no renumera y no repinta: un lote que ya está en la base no se
toca ni aunque el archivo diga otra cosa. Las diferencias las informa y
las deja para que las mire una persona.

## La manzana I: el faltante que no se veía

**22-ago-2026.** Mauricio comparó el mapa contra el PDF del topógrafo y
notó que la manzana I terminaba en el I-7. En el plano tiene **quince**
lotes, en dos filas:

| | Lotes | Medida | Área |
|---|---|---|---|
| Fila del frente | I-1 a I-6 | 12.50 V × 20.00 V | 250.00 vr² |
| Esquina del frente | I-7 | 19.81 V × 20.00 V | 330.79 vr² |
| La cuña | I-8 | 12.00 V × 23.07 V | 260.10 vr² |
| | I-9 | 15.00 V × 24.92 V | 363.35 vr² |
| Fila de atrás | I-10 a I-15 | 12.50 V × 27.00 V | 337.50 vr² |

Los ocho que faltaban suman **2,648.45 vr²**, y la manzana pasa de
1,830.79 a **4,479.24 vr²**.

**Por qué no se vio antes.** Siete lotes seguidos, numerados del 1 al 7,
no se leen como media manzana: se leen como una manzana chica. El faltante
no deja hueco en el mapa —la fila de atrás simplemente no está dibujada— y
ningún control lo podía atrapar, porque todos los controles comparan el
dibujo contra el **rótulo del propio lote**, y un lote que no se leyó no
tiene rótulo que comparar.

**Lo que sí lo habría atrapado**, y queda anotado para la próxima lectura
de un DXF: **contar los rótulos `vr2` del archivo y compararlos contra los
lotes cargados.** El plano trae más rótulos de área que lotes tiene la
base; esa resta es la lista de lo que la lectura dejó afuera.

**Cómo se reconstruyeron.** Con el mismo método del resto del documento
—caras del grafo de linderos, rótulo adentro, área del texto impreso— y la
transformación de campo a varas resuelta contra el calco, que ya está en
coordenadas de varas: 25 rótulos con nombre único (`BLOQUE A` … `CALLE
PUBLICA.`) dan la escala y el traslado por mínimos cuadrados, con
**0.006 varas de residuo máximo**.

El control es el de siempre, el área dibujada contra la impresa:

| Los ocho nuevos | |
|---|---|
| Error máximo | **0.0093 %** (I-13 e I-15) |
| Error mínimo | 0.0006 % (I-9) |

Están por debajo del percentil 90 de los 301 que ya estaban, así que
ninguno sale marcado por `poligonoDesalineado()` y el X-15 sigue siendo el
único. Los ocho polígonos **comparten vértice** con los lotes de la fila
del frente que ya estaban cargados —la manzana cierra— y ninguno se
traslapa con ningún otro lote del plano.

**Lo que el plano tiene y el sistema sigue sin cargar:** las manzanas de la
segunda serie, `A-1` a `F-1`, que en el DXF están dibujadas y rotuladas
igual que las demás. Quedan afuera a propósito —22-ago-2026, Mauricio:
«solo esos hacen falta»—, no por un tropiezo de la lectura. Si algún día
entran, entran por `olympo:completar-plano`.
