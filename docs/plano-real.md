# De dónde sale la geometría de Praderas del Sol

> **2-oct-2026 — entró la segunda etapa.** 81 lotes más, en las manzanas
> A-1 a G-1: el plano pasa de 309 a **390 lotes** en 31 manzanas. Ver
> [La segunda etapa](#la-segunda-etapa-a-1-a-g-1) al final. Lo que sigue
> hasta ahí habla de la primera etapa, y sus números siguen valiendo.

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

Las manzanas de la segunda serie quedaron afuera ese día a propósito
—22-ago-2026, Mauricio: «solo esos hacen falta»—. Entraron el 2-oct, por
`olympo:completar-plano`, como se había dejado escrito.

## La segunda etapa: A-1 a G-1

**2-oct-2026.** Mauricio, con el plano impreso al lado: «hay que agregar
los de la segunda etapa, los lotes de esos bloques». Entran **81 lotes en
siete manzanas, 21,615.06 vr²**.

| Manzana | Lotes | Área | |
|---|---|---|---|
| A-1 | 4 | 1,389.69 vr² | pegada a la X, del lado de abajo |
| B-1 | 16 | 4,000.00 vr² | todos de 250.00 |
| C-1 | 17 | 4,246.56 vr² | |
| D-1 | 11 | 3,314.28 vr² | la cuña contra la calle de la orilla |
| E-1 | 16 | 4,000.00 vr² | todos de 250.00 |
| F-1 | 15 | 4,092.59 vr² | |
| G-1 | 2 | 571.94 vr² | ⚠️ en el plano dice «BLOQUE F-1» (ver abajo) |

Los conteos **no se copiaron de la lectura: los dictó Mauricio** contra el
impreso, y así están en `PlanoRealPraderasSeederTest`. Si el JSON pierde o
gana un lote, el test se pone rojo; la lectura sola no se puede auditar a
sí misma (ver la manzana I, arriba).

### Los dos archivos

- **DXF**: el mismo `PLANO DIONEL CORPUS.dxf`, en la versión que el
  ingeniero guardó el **22-ago-2026** (ya trae la segunda etapa y la fila
  de atrás de la manzana I).
- **Impreso**: `LOTIFICACION CORPUS REVISADO.pdf`, ploteado el
  **29-ago-2026**. Es una semana más nuevo que el DXF, y se nota en dos
  rótulos (abajo).

**Cómo se sabe que la lectura es la misma de siempre:** con el mismo
método y la misma transformación campo → varas, **307 de los 309 lotes ya
cargados salen idénticos** —mismo número, misma área, polígono a menos de
5 mm—. Los otros dos, Q-3 y Q-4, rotulan con coma de miles
(`1,200.57 vr2`) y esta lectura no los buscó; no son de la segunda etapa.

### Lo que no se lee solo

1. **«BLOQUE F-1» está escrito dos veces.** Una vez en la manzana de
   quince lotes de abajo, y otra en la manzana chica de dos lotes que queda
   debajo de la A-1, contra la orilla. Dos manzanas no pueden llamarse
   igual; la chica entra como **G-1** (Mauricio, 2-oct: «bloque G son 2»).
   ⚠️ **Falta que el ingeniero corrija el rótulo** del plano.
2. **Dos rótulos del DXF quedaron viejos y el impreso los corrigió.**
   Manda el impreso —es el que tiene el comprador en la mano— y el dibujo
   le da la razón en los dos:

   | Lote | DXF (22-ago) | Impreso (29-ago) | Dibujo |
   |---|---|---|---|
   | D-1-8 | 388.84 — el rótulo del D-1-1, repetido | **250.00** | 249.99 |
   | F-1-11 | 189.50 | **271.69** | 271.81 |

3. **Trazos nuevos que no son linderos.** El DXF trae la línea verde que
   encierra la «2DA ETAPA» (corre por linderos y cruza calles) y los ejes
   rojos punteados de las calles. Se sacan **antes** de armar las caras,
   igual que la polilínea vieja del área verde.

Dibujo contra rótulo, los 81: mediana **0.006 %**, máximo **0.043 %**
(el F-1-11). Ninguno sale marcado por `poligonoDesalineado()`: el X-15
sigue siendo el único.

### El calco creció con ellos

Al final del trazo (`obra`) de `public/planos/rps-fondo.json` se agregó lo
que el DXF dibuja y el calco no tenía: los linderos y los bordes de calle
de la segunda etapa y, de paso, **la fila de atrás de la manzana I**, que
tampoco estaba — el calco es de abril y el I-8 a I-15 entró después. Lo
que ya estaba no se tocó: el archivo solo crece al final.

### Cómo se carga

Con la base operando, por la puerta que agrega y no toca nada:

```bash
herd php artisan olympo:completar-plano RPS database/data/praderas-plano.json --precio-vara=1000 --ensayo
herd php artisan olympo:completar-plano RPS database/data/praderas-plano.json --precio-vara=1000
```

Las siete manzanas nacen vacías y no tienen de quién heredar el precio.
**L 1,000.00 por vara²** —un lote tipo de 250 vr² queda en
L 250,000.00, como los de la primera etapa— lo decidió Mauricio el 2-oct.
El precio de cada lote se afina al venderlo
(ver `precio-por-lote-no-por-vara`).

El script de la lectura quedó en `storage/app/_analisis/segunda-etapa/`
(no viaja con el repo; el LEEME dice en qué orden se corre).
