# Continuar acá — 31-ago-2026

> Se lee esto y `docs/dominio.md` antes de proponer nada. La puerta es
> `herd composer rector:fix && herd composer lint && herd composer ci && herd composer rector`.

## 🔴 31-ago — El recibo de la prima: sin lote, sin saldo, y con el nombre equivocado

**Lo vio Mauricio en `pruebas`, mirando el RPS-00000008:** «acá en lote aparece
solo una línea en el recibo, y también debe de decir el nombre de la persona
que recibió el dinero», y «que salga cuánto le queda por pagar, que cuando es
recibo por prima no sale».

**Tres de las cuatro cosas eran la MISMA causa.** La prima se pacta por el
CONTRATO aunque el expediente lleve tres lotes (R5), así que su recibo va sin
`compromiso_id` y sin aplicaciones — a propósito. Todo el papel preguntaba
«¿qué lotes tocó este recibo?», que ahí devuelve la lista vacía:

| Salía | Por qué |
|---|---|
| `LOTE —` | `codigosDeLotes()` vacío |
| sin «Le queda por pagar» | `saldosPorLote()` no tenía de qué lote sacarlo |
| renglón azul «Abono a capital» | `montoACapital()` es una RESTA: sin cuotas aplicadas da el papel entero |

La pregunta del papel es otra —**de qué lotes HABLA**— y ahora la contesta
`Recibo::compromisosDelPapel()`: los que tocó, y si no tocó ninguno, los
renglones vivos del contrato (los rescindidos afuera, ordenados por código).

### Lo que cambió

1. **`LOTE` dice los lotes del contrato** en el recibo de prima; con dos o más,
   el rótulo va en plural (`Recibo::nombraVariosLotes()`).
2. **«Le queda por pagar» sale también en la prima.** ⚠️ Y un lote **sin plan
   de cuotas ya no imprime la línea**: la seña de un apartado decía «le queda
   por pagar L 0.00» a alguien que debe el lote entero. Cero cuotas no es cero
   saldo.
3. **El renglón se llama como el CONCEPTO** (`Recibo::rotuloDelSobrante()`):
   «Prima», «Seña del apartado», y «Abono a capital» solo cuando de verdad lo
   es (R21).
4. **El papel dice quién recibió el dinero**: renglón «Recibido por» arriba, y
   las dos firmas con nombre — «Recibí conforme — ELDER» / «Entregué conforme
   — YOSSELIN», como ya hacía el acta de devolución.
5. 🔴 **Y ese dato ahora lo escriben TODOS los caminos.** `recibido_por` nació
   en el modal de cobro (27-ago) y ni la prima ni la seña lo llenaban: el corte
   de caja del día las sumaba bajo **«Sin usuario»**. El default se mudó al
   modelo (`Recibo::booted()`), así que el camino que se olvide queda bien
   igual. Es el molde de `Venta::liquidarSiYaNoDebe()`.

### Qué mirar en pruebas

`Recibos` → cualquiera con concepto **Prima** → «Imprimir». Tiene que decir el
lote, «Recibido por», «Prima» en el renglón azul, «Le queda por pagar», y las
dos firmas con nombre. Después uno de **Cuota** (no cambió, salvo el nombre en
las firmas) y uno de **Seña** (dice «Seña del apartado» y NO promete saldo).

## 🔴 31-ago — Y ahora las TRES puertas preguntan quién recibió el dinero

«Acá en apartar que se coloque quién recibe el dinero, y cuando se vende
también quién recibe el dinero» — Mauricio, mirando los dos modales del plano.

El campo nació el 27-ago adentro del modal de cobro. Pero **el dinero entra por
tres puertas**: la cuota, la seña de un apartado y la prima de una venta. Las
otras dos ni lo preguntaban ni lo escribían.

- Nace **`App\Filament\Support\QuienRecibeElDinero`**: la pregunta, la lista
  (quien tiene `Create:Recibo`, menos el super-admin) y el valor por defecto,
  **escritos una sola vez**. El modal de cobro pasó a usarlo — se le fueron tres
  métodos privados y tres imports.
- `RegistroDeCompromisos` y `RegistroDeVentas` estrenan **`loRecibio($id)`**,
  el mismo molde de `RegistroDePagos`: la clase es `readonly` y devuelve una
  instancia nueva.
- ⚠️ **El valor inicial va en el `fillForm()` de cada acción, NO en un
  `default()` del campo**: ese arreglo ES el estado inicial y los `default()`
  no se aplican. Está anotado en los tres lugares.
- En apartar el campo aparece **solo si hay seña que cobrar**, igual que la
  forma de pago: sin seña no hay recibo.

### La casilla de confirmar YA era obligatoria

«Si no se marca el revisé el plazo y precio no se pueda vender» — ya no se
puede desde el 14-ago: las dos casillas (vender y apartar) llevan `accepted()`
y hay dos tests que sostienen que sin tildarla **no se firma y no se aparta**
(`VenderDesdeElPlanoTest`, «sin confirmar no se firma la venta»). No se tocó
nada. Si alguna vez pasa una venta sin tildarla, eso es un hallazgo nuevo y hay
que perseguirlo.

### Al desplegar

**Sin migración y sin permisos nuevos.** Los recibos viejos de prima y de seña
siguen con `recibido_por` en NULL —el default solo corre al crear—, pero el
papel cae en quien lo tecleó (`created_by`), que es lo que la migración del
27-ago escribió en los 257 de la cartera vieja.

## 🔴🔴 27-ago — Un recibo cobró L 24,000.00 y aplicó L 17,020.83 (PRODUCCIÓN)

**Lo encontró Mauricio mirando la ficha del expediente 0070:** «por qué me
dice que aún debe L 567,979.17 si 805,000 menos 220,000 de prima y menos los
24,000 que dio». Debía L 561,000.00.

**El modo «Ambas» se comía el dinero que ponía al día.** El paso 5 de
`cobrarYAbonarEnUnMismoNombre()` tenía un comentario donde iba el código: daba
por hecho que `ponerAlDia` valía cero. Solo vale cero si los renglones de
cuota cubrieron TODO lo vencido del lote del abono. En el RPS-00000005
marcaron una cuota por lote, el RPS-N-008 tenía dos vencidas, y la segunda se
comió L 6,979.17.

**Lo que lo hacía peligroso: nadie comparaba las dos mitades del recibo.** Del
segundo caso —L 5,000.00 del expediente 0038— nadie se había dado cuenta.
De ahí sale lo nuevo — `Recibo::cuadra()`, `olympo:cuadrar-recibos
[--reparar]` y una revisión más en `olympo:verificar-produccion`.

### 🔴 En producción eran DOS, no uno

La primera corrida del comando encontró uno que nadie había reportado:

| Recibo | Contrato | Cobró | Aplicó | Faltaba | Lote |
|---|---|---|---|---|---|
| RPS-00000005 | RPS-2026-0070 | L 24,000.00 | L 17,020.83 | **L 6,979.17** | RPS-N-008 |
| RPS-00000010 | RPS-2026-0038 | L 50,000.00 | L 45,000.00 | **L 5,000.00** | RPS-H-005 |

**L 11,979.17** de dos clientes, los dos del 26-ago —el primer día que alguien
usó «Ambas» de verdad—. Reparados el 27-ago con `--reparar`. El que preguntó
fue uno solo: **un defecto de dinero se audita sobre la base entera, no sobre
el caso que llegó.**

### Al desplegar

Sin migración y sin permisos nuevos.

```
php8.5 artisan olympo:cuadrar-recibos            # lista lo que no cuadra
php8.5 artisan olympo:cuadrar-recibos --reparar  # escribe lo que falta
```

⚠️ La reimpresión del RPS-00000005 va a mostrar otro desglose —cuotas
L 19,166.67 y capital L 4,833.33— con el mismo total de L 24,000.00. El nuevo
es el verdadero; el viejo salía de una resta.

## ✅ 27-ago, 1:55 a.m. — TODO ESTO YA ESTÁ EN PRODUCCIÓN

Cinco entregas de una sola noche, desplegadas y verificadas en
`praderasdelsol.cloud`:

1. **El cuadre de los recibos** — `olympo:cuadrar-recibos`. Encontró y reparó
   **L 11,979.17 de DOS clientes** (el 0070 y el 0038).
2. **La lista de recibos por fecha** y el monto que dejó de cortarse.
3. **El orden del bloqueo en Postgres** (el centavo del residuo).
4. **«¿Quién recibió el dinero?» (R24)** y **la referencia que dejó de trabar
   el cobro (R11-bis)** — las dos escritas en `docs/dominio.md`.
5. **El respaldo, que nunca había corrido**: `config/backup.php` pedía la base
   con `config()` adentro de un archivo de config y siempre fue `null`.
   Primer respaldo real: 6.3 MB, 5,187 archivos.

`olympo:verificar-produccion` da **«Cada recibo aplicó lo que cobró — Todos
cuadran»**.

### Lo que quedó pendiente, en orden

1. 🔴 **La contraseña `12345678`** de `rosa@gmail.com`. Se cambia **desde el
   panel**, no por terminal (así no queda en el historial de bash).
2. **`BACKUP_DISKS=local`**: el respaldo vive en el mismo disco que la base.
3. **`MAIL_MAILER=log`**: si un respaldo falla, nadie se entera.
4. 🆕 **El chequeo del cron da falso positivo después de cada despliegue**: lee
   el latido de `Cache::get('health:checks:schedule:latestHeartbeatAt')` y
   `optimize:clear` lo borra. **Un detector que grita de más se deja de
   mirar** — hay que sacar el latido de esa cache o distinguir «nunca latió»
   de «se limpió recién».
5. **El CI corre 1,152 tests y la Mac 1,180.** Sin explicar.
6. Preguntarle a la contratante por R11-bis y R24 (están marcadas como
   decisión de Mauricio, pendientes de confirmar).

---

## 🔴🔴 27-ago — Un `SELECT` sin `ORDER BY` decidía a quién le tocaba el centavo

El CI se puso rojo con **un** test que la Mac daba verde: el reparto de una
prima de L 1,000.00 entre tres lotes iguales. `bloquearYVerificar()` leía los
lotes con `FOR UPDATE` y **sin `orderBy`**, así que el orden de los renglones
del contrato —y con él, quién recibe el centavo del residuo— lo elegía el plan
de la consulta, que no es el mismo en dos versiones de Postgres.

Se ordena por `codigo`, que es el criterio que ya usaban `apartar()` y
`FijacionDePrecios`. Vender era el único de los tres sin él. De regalo, un
orden de bloqueo consistente es lo que evita que dos ventas simultáneas se
traben.

**La regla:** si el ORDEN de un resultado decide algo, ese orden se escribe.
Y **local verde no es CI verde**: son dos Postgres distintos.

⚠️ Quedó una duda por mirar: el CI corrió **1,152** tests y la Mac **1,177**.

---

### Lo que quedó abierto de acá

- **Ver la pantalla después de reparar**: el expediente 0070 tiene que decir
  L 561,000.00 y «140 de 144».
- Preguntarle a la contratante si en «Ambas» quiere que el sistema **exija**
  marcar todo lo vencido del lote del abono, en vez de completarlo solo.

---

## 25-ago — Los dos planos de Inmobiliaria Maya entran por un seeder

**✅ LA PUERTA PASÓ Y ALTAMIRA ESTÁ CARGADO.** `composer ci` verde:
**1,161 tests** (5,238 assertions, 54.7 s), PHPStan 458/458 sin errores,
Pint y Rector limpios sobre 861 archivos. El seed dijo:

> RESIDENCIAL ALTAMIRA (RAL): 268 lotes en 16 manzanas, 64,213.77 metros².
> Calles: 0. Reparto: 35 en A, 11 en B, 28 en C, 27 en D, 17 en E, 16 en F,
> 14 en G, 14 en H, 7 en I, 17 en J, 16 en K, 25 en L, 13 en M, 15 en N,
> 8 en O, 5 en P

**El Bambú NO se recargó, y eso es la guarda haciendo su trabajo:** «Hay 5
lote(s) apartados o vendidos en el proyecto REB. No los piso.» Los 84 que
ya están son idénticos a los que cargaría el seeder —mismo archivo, mismo
importador, misma cuenta—, así que **no hay nada que recargar**. Lo único
que sobra ahí es el bloque **G vacío**, que se borra desde la pantalla.

Mauricio: «ahora cargaremos ese, es en Mts2, carguémoslo y de paso hagamos
seeder de carga de este y de El Bambú para cuando lo subamos solo correr el
seeder. Estos son dos lotificaciones de Inmobiliaria Maya».

**Los dos son de otro cliente, no de Praderas.** Van a su propia
instalación, su propia base. El Bambú estaba cargado en la base de acá
desde el 13-ago como prueba; a partir de ahora su lugar es la instalación
de Maya.

### La decisión de fondo: el seeder LEE EL DXF, no un JSON masticado

Praderas del Sol entró por `database/data/praderas-plano.json`, una
traducción nuestra del dibujo. Acá no: el seeder corre el
`ImportadorDeDxf` de producción sobre el archivo del topógrafo.

- Hay **una sola fuente de verdad** y es la que firma el ingeniero. Un
  JSON intermedio se puede editar a mano sin que nadie note la diferencia,
  que es exactamente como se pierde un lote.
- La geometría la arma `ExtractorDeGeometria`, que ya sabe de arcos, de
  `INSERT` anidados y de las siete trampas del formato. **58 de los 268
  lotes de Altamira tienen un lado curvo**; leídos como polígonos de
  líneas rectas, los diez de esquina saldrían de 200 m² en vez de 314.16.
- El día que el topógrafo mande una revisión, se reemplaza el archivo y se
  vuelve a correr.

### 🔴 O entra el plano completo, o no entra nada

`PlanoDeclarado` es lo que dice el **plano de papel**, escrito a mano
manzana por manzana. `PlanoDesdeDxfSeeder` corre todo dentro de una
transacción y exige, adentro:

| Control | Qué atrapa |
|---|---|
| El reparto por manzana, uno a uno | **La manzana I del 22-ago**: media manzana que ningún control del importador podía ver |
| El total de lotes | Un contorno de más o de menos |
| `sinRotulo === 0` | Un lote al que el sistema le **inventó** el número, que después sale impreso en un contrato |
| El área contra la suma de los rótulos, ±0.05 % | Una escala mal leída |

Si algo no cuadra, **se lanza y no queda nada cargado** — ni el proyecto.
Un plano a medias no avisa que está a medias, y el día que se descubre ya
hay contratos encima.

Y de yapa **LA RESTA**: el seeder cuenta los rótulos de área del archivo
(en la unidad del proyecto) contra los lotes cargados, y avisa si no dan
lo mismo. Es el control que faltaba el 22-ago, ahora escrito. Hoy da
268/268 y 84/84.

### Qué entró

1. **`database/seeders/PlanoDeclarado.php`** — el plano impreso como dato.
   Producto, sin nombres de cliente.
2. **`database/seeders/PlanoDesdeDxfSeeder.php`** — el seeder abstracto.
   Producto. Sirve para cualquier lotificadora que tenga un DXF.
3. **`database/seeders/Clientes/AltamiraSeeder.php`**, **`ElBambuSeeder.php`**
   y **`InmobiliariaMayaSeeder.php`** — la instalación de Maya (§4.L0).
   ⚠️ El directorio es `Clientes` con C mayúscula, no `clientes`: PSR-4 es
   sensible a mayúsculas en Linux y el `Cartera/` de la cartera histórica
   ya sentó el precedente. En el Mac funcionaría igual; en el VPS no.
4. **`database/data/altamira-plano.dxf`** y **`el-bambu-plano.dxf`** — los
   archivos del topógrafo. El de El Bambú vivía en `storage/app/_analisis/`,
   que está en `.gitignore`: no estaba versionado.
5. **`tests/Feature/Dominio/PlanoDesdeDxfSeederTest.php`** — 12 tests, y
   **`tests/Fixtures/PlanoQueNoCuadraSeeder.php`**, el caso de control:
   declara una manzana G de seis lotes que el archivo no tiene y afirma
   que la base queda **como estaba**.

### Lo que dicen los dos planos

| | RESIDENCIAL ALTAMIRA (`RAL`) | RESIDENCIAL EL BAMBU (`REB`) |
|---|---|---|
| Lotes | **268** | **84** |
| Manzanas | 16, A a P | 6, A a F — **no hay G** |
| Área | 64,214.72 m² rotulados · 64,213.77 leídos (0.0015 %) | 16,438.69 · 16,438.68 (0.00003 %) |
| Unidad | **metros²** | **metros²** |
| Capa de rótulos | `textos` | `NOMENCLATURA` |
| Capa de calles | ninguna importable | ninguna importable |
| Precio | **0.00 — falta** | **0.00 — falta** |

Altamira: planta de distribución de MAYAP CONSTRUCTORA, agosto de 2026,
escala 1:200, Santa Rosa de Copán. Propietario: Inversiones La Roca.
Dibujó Arq. Alejandra María Reyes, aprobó Ing. Bayron Huberto Peña.

El Bambú da **exactamente** la misma cuenta que la importación a mano del
13-ago: 84 lotes, 16,438.68, «36 en A, 7 en B, 8 en C, 17 en D, 8 en E,
8 en F». No es una transcripción de aquel resultado: es la misma cuenta
otra vez, sobre el mismo archivo. Y de paso se va el **bloque G vacío**
que había quedado del plano viejo de 26 lotes.

### Cómo se corre

```bash
herd php artisan db:seed --class="Database\Seeders\Clientes\InmobiliariaMayaSeeder"
```

Idempotente. Se detiene solo —sin borrar nada— en cuanto alguno de los dos
tenga un lote apartado o vendido, porque **reemplaza** el trazado. Desde
ese día el plano se corrige con `olympo:completar-plano`, que solo inserta.

### 🔴 Rector se lleva el COMENTARIO junto con el argumento

`RemoveNullNamedArgOnNullDefaultParamRector` borró `capaDeCalles: null` de
los dos seeders —correcto, el default ya es null— y **se llevó puestas las
dos líneas que explicaban por qué ese plano no tiene calles que
importar**. Nadie lo ve: `php -l`, Pint y PHPStan quedan verdes y el diff
de Rector se lee como una limpieza.

**La regla: una razón que vive pegada a un argumento se pierde el día que
el argumento sobra.** Si la explicación importa, va en el docblock de la
clase, que ningún fixer toca. Las dos están repuestas ahí.

## 🔴 25-ago — El rótulo del lote colgaba del punto equivocado

Mauricio, mirando el plano de Altamira ya cargado: «hay lotes donde no se
ve bien el número que les corresponde».

`PlanoDelProyecto::centroDe()` colgaba la etiqueta del **promedio de los
vértices**. Su propio docblock decía «para una forma irregular la etiqueta
queda igual de bien puesta sin arrastrar la fórmula completa» — y era
verdad mientras todos los lotes del sistema fueran cuadriláteros.

**Un promedio de vértices pondera por CUÁNTOS hay, no por dónde están.**
La pared curva de un lote de esquina entra teselada en 30 o 60 vértices
—`GRADOS_POR_SEGMENTO` = 3°— y se lleva el promedio con ella.

Medido sobre los 268 de Altamira: **64 rótulos corridos más de 1.5 m, y
TRES fuera de su propio lote**. Y como el rótulo se dibuja en blanco,
afuera cae sobre la calle blanca y el lote **se queda sin número**. Por eso
se veía «-26», «G-», «F-».

**Por qué vivió meses invisible:** los 309 lotes de Praderas del Sol tienen
cuatro vértices, y ahí promedio y centroide coinciden.

### Qué entró

1. **`GeometriaPlana::centroide()`** — el centro de masa por la misma
   fórmula del cordón de zapato que ya usa `area()`. Es **invariante a la
   teselación**: da igual si una pared curva entró en 4 segmentos o en 60.
   Un polígono degenerado cae al promedio en vez de dividir por cero.
2. **`PlanoDelProyecto::centroDe()`** lo usa. Es el único llamador.
3. **5 tests** en `GeometriaPlanaTest`, con el caso de control adentro: el
   promedio del cuarto de disco **se sigue moviendo** cuando se tesela más
   fino (11.15 → 12.36 → 12.61) mientras el centroide converge a 4r/3π =
   8.4883. Y en la figura en L que ese test ya usaba, el promedio cae en el
   hueco y el centroide no.

**Medido antes de tocar nada:** con el centroide, de los 268 de Altamira no
queda **ninguno** afuera, y el más apretado tiene 4.60 m libres hasta su
lindero contra los ~1.2 m que mide de alto el rótulo. En Praderas la
mediana del movimiento es **cero**.

⚠️ `GeometriaPlana::centro()` **no se tocó**: el importador lo usa para
otra cosa —decidir cuál de los rótulos que caen adentro de un contorno es
el número del lote— y ahí funciona. Su docblock ahora dice para qué NO
sirve.

### Y el croquis del modal tenía los DOS problemas

Mauricio, después del primer arreglo: «en muchos el total no se ve centrado
y muchas líneas». El croquis del lote (`figura()` en el blade) calculaba su
propio centro **también con el promedio de vértices**, y encima cotaba
**lado por lado**:

1. **El área escrita contra un lindero.** Mismo bug del rótulo. Y peor: las
   normales de las cotas se orientan mirando ese punto, así que con el
   centro corrido salían disparadas para cualquier lado. Ahora es el
   centroide de área, la misma cuenta que `GeometriaPlana::centroide()`.
2. **Un lado curvo cotado 34 veces.** El arco llega teselado a 3°, así que
   una esquina redondeada son ~35 segmentos de 91 cm y el croquis se
   llenaba de «0.91 m» que se salían del recuadro. Ahora los segmentos que
   giran menos de **10°** entre sí son el mismo lado: se juntan y se cota
   su **desarrollo**, que es el número que el topógrafo escribe sobre un
   arco.

**El cierre circular importa:** el contorno puede arrancar a mitad de un
arco. En el RAL-E-008 de la captura, el arco de 90° venía partido en dos
tramos (24.13 + 8.63) y sin unirlos habrían quedado dos cotas de un mismo
lindero. Ahora da **32.76 m · 20.00 m · 20.00 m**: tres cotas para un lote
que tenía 34.

**Verificado corriendo el JS de verdad.** Se extrae el getter `figura()`
del blade y se corre con node contra los polígonos reales de los dos
planos:

| | ALTAMIRA (268) | PRADERAS (309) |
|---|---|---|
| Cotas por lote | max **34 → 5** · promedio 5.4 → 3.8 | max 6 → **6**, promedio 4.1 → **4.1** |
| El área cae fuera del lote | **0** | **0** |
| Cotas escritas encima del dibujo | **0** | **0** |

Praderas no se mueve, que es lo que había que probar. Diez grados y no más:
con quince empiezan a fundirse linderos quebrados de verdad de Praderas.

### 🔴🔴 Y en el camino dejé la pantalla EN BLANCO

El comentario que escribí para explicar todo esto citaba «0.91 m» **con
comillas dobles**. Ese JS vive adentro del atributo `x-data`, que va entre
comillas dobles: la comilla **cierra el atributo**, el navegador se come el
resto del componente y la página sale en blanco.

**Lo caro no es el error, es que mi verificación no podía verlo.** Había
corrido `node --check` sobre el JS *extraído* del atributo — y extraer el
contenido es exactamente borrar la capa donde estaba el bug.

> **Verificar el contenido de algo por separado no verifica la frontera.**
> Si para revisar una cosa hay que sacarla de su envase, lo que falta
> revisar es el envase.

Ninguno de los escalones de siempre lo ve: `php -l` pasa (el blade es PHP
válido), Pint pasa, PHPStan no mira vistas, Pest tampoco.

**Nace el séptimo detector**, `storage/app/_analisis/alpine.py`: toma el
contenido de cada atributo `x-*`, `:*` y `wire:*` hasta la comilla que lo
cierra y cuenta el balance de `()`, `{}` y `[]`. **Un atributo cortado no
balancea; uno sano sí.** Con su caso de control al lado —dos `x-data`
seguidos, uno con «» y otro con `""`— y cero falsos positivos sobre los 21
blades del repo.

```bash
python3 storage/app/_analisis/alpine.py $(find resources/views -name '*.blade.php')
```

**Los cinco escalones que ahora corren sobre ese archivo:** `php -l`, el
detector nuevo, `node --check` del componente entero, el getter corrido
contra los polígonos reales, y Pint. Los cinco verdes.

## 🔴🔴 25-ago — El área tiene que ser la del PLANO, no la del dibujo

Mauricio, con el PDF del topógrafo al lado de la pantalla: «no está dando
medidas exactas; ejemplo, ese es 314.16 la medida real, tiene que ser
exacto».

| Lote | Dice el plano | Decía el sistema |
|---|---|---|
| G-7 | **314.16 m²** | 314.02 |
| J-1 | **296.72 m²** | 296.78 |
| G-11 | **382.29 m²** | 382.33 |
| I-1 | **507.06 m²** | 507.11 |

El área salía del **contorno**, y un contorno con lado curvo llega teselado
a 3°: una poligonal inscrita encierra menos que el arco. El propio
`GRADOS_POR_SEGMENTO` lo advertía y daba el 0.036 % por «debajo del
redondeo». **No lo está**: ese número multiplica al precio y sale impreso
en la escritura.

### La salida ya era la doctrina del repo

Praderas del Sol carga **el área que escribió el topógrafo**, no la
calculada — `docs/plano-real.md`: «acá no se calcula NADA que el plano ya
diga». Altamira trae sus 268 rótulos `A=…m2` y El Bambú sus 84. Ahora el
importador los lee.

1. **`RotuloDxf::areaRotulada(array $sufijos)`** — el número tal como está
   escrito, y como **string**: «314.16» es exactamente 314.16 y el float
   no. Entra a bcmath sin haber sido float nunca (§8.3.1).
2. **`OpcionesDeImportacion::$sufijosDeArea`** — vacío (el default) calcula
   del contorno, que es lo único posible cuando el plano no la rotula. Con
   `['m2']` o `['v2','vr2']` manda el rótulo. **Nada cambia para quien no
   lo pida.**
3. **`ImportadorDeDxf`** — misma regla que el número de lote: el rótulo
   tiene que caer **adentro** del contorno y gana el más cercano al centro.
   Cuenta los que no encuentra en `ResultadoDeImportacion::$sinAreaRotulada`
   y avisa.
4. **`PlanoDeclarado`** pasa `unidadesDelRotulo()`, que ya existía para la
   resta.

### 🔴 Se piden las UNIDADES, no un booleano

Porque el plano rotula **las dos áreas del mismo lote** —«A=200.00m2»
arriba y «286.85v2» abajo—. Leer la que no es deja cada lote con el área de
la otra unidad: un error del 43 % pasando por exacto. Ese es el test que
importa de los cinco nuevos en `LecturaDeDxfTest`.

### Verificado sobre los dos planos, lote por lote

| | ALTAMIRA | EL BAMBÚ |
|---|---|---|
| Lotes con área del plano | **268 / 268** | **84 / 84** |
| Suma contra el papel | **64,214.72 = 64,214.72** | **16,438.69 = 16,438.69** |
| Diferencia | **0.00000 %** | **0.00000 %** |
| `sinAreaRotulada` · advertencias | 0 · ninguna | 0 · ninguna |

La asignación **por contención sola alcanza para los 268**: no hace falta
ninguna heurística de cercanía, que es justo lo que no se quiere cuando el
número termina en una escritura.

⚠️ La mayor discrepancia entre el dibujo y el rótulo queda en **0.046 %**,
muy por debajo del 2 % de `Lote::TOLERANCIA_DE_AREA`: **ningún lote sale
marcado como desalineado**.

**Los tests dejaron de tener tolerancia.** `expect(areaTotalDe($proyecto))
->toBe(64214.72)` y `areaDelLote($proyecto, 'G', '7')->toBe('314.1600')`.
O es el número del plano, o no es.

### Qué falta

- 🔴 **Correr la puerta otra vez**, por el arreglo del rótulo:
  `herd composer rector:fix && herd composer lint && herd composer ci && herd composer rector`.
- **Mirar el plano de Altamira de nuevo** y confirmar que los 268 números
  se leen. Es la única verificación que cuenta.
- 🔴 **El precio de los dos desarrollos.** Los 352 lotes entran en 0.00 y
  un lote sin precio no se puede vender. Y ninguno de los dos tiene planes
  de pago cargados.
- 🔴 **El Bambú ya existe en la base con código `REB`** (7 bloques: los 6
  del plano más el G vacío del plano viejo). El seeder lo declara con ESE
  código, así que lo **actualiza** en vez de duplicarlo, y de paso se lleva
  el bloque G. No hay que eliminar nada.
- **Verificar en pantalla.** La última verificación de un plano es mirar
  el mapa contra el PDF del topógrafo.

### 💡 La mejora que propongo (L5)

**Que la pantalla de importación de DXF muestre LA RESTA.** Hoy ese número
—cuántos rótulos de área trae el archivo contra cuántos lotes se
crearon— existe solo adentro de este seeder. Puesto en el aviso de
`ResultadoDeImportacion`, cualquier lotificadora que importe un plano
desde el panel vería el 22-ago-2026 el mismo día y no quince después. Son
unas quince líneas en `ImportadorDeDxf` y una fila más en el aviso.

## 22-ago — El expediente cambia de titular (R23)

**SIN CORRER LA PUERTA TODAVÍA**, igual que lo de la manzana I.

Mauricio: «se hizo la promesa de venta, pero después quieren cambiar la persona
titular; el registro de los pagos queda y solo se cambia el nombre del cliente».
Es una **cesión de derechos**, y hasta hoy no había por dónde: la venta se crea y
se consulta, no se edita.

**La regla, en una línea: mueve la marca; no reasigna un solo recibo.**

### Qué entró

1. **`R23` en `docs/dominio.md`**, con la tabla de qué se toca y qué no.
2. **Migración** `venta_cliente.titular_hasta` (DATE) + CHECK
   `NOT (titular AND titular_hasta IS NOT NULL)`: el titular de hoy no puede
   tener fecha de salida. Complementa al índice parcial que ya existía.
3. **`App\Models\Pivots\DuenoDelExpediente`** — un Pivot propio con casts, y no
   es prolijidad: `withCasts()` sobre la relación **NO castea el pivot**, así que
   sin esta clase `titular_hasta` sale string, todo `instanceof Carbon` da false
   y **la fecha nunca se imprime, en silencio**.
4. **`App\Domain\Ventas\CambioDeTitular`** — apaga la marca vieja, prende la
   nueva (en ese orden: el índice parcial valida fila por fila), pasa los
   `compromisos.cliente_id` **vigentes** al nuevo —si no, el plano se queda con
   el nombre viejo para siempre— y asienta en la bitácora **dentro de la misma
   transacción**, con `lockForUpdate` y re-check adentro (§8.3.2).
5. **`CambiarTitular:Venta`**, permiso propio: solo la administradora.
6. Botón **«Cambiar titular»** en el expediente, «Fue de …» en la ficha, y
   `EstadoDeCuenta::acompanantes()` filtrado — sin eso el ex-titular salía
   IMPRESO como copropietario en el papel que se le entrega al cliente.
7. **20 tests** entre dominio y pantalla. El que más importa: «los recibos ya
   emitidos NO cambian de dueño».

### 🔴 Cuatro cosas que la revisión encontró, y valen para todo el repo

1. **`withProperties()` guarda el asiento donde nadie lo pinta.** La bitácora lee
   `attribute_changes`; el helper correcto es **`withChanges()`**. Con el otro,
   Registros de actividad muestra «Sin datos anteriores / Sin datos nuevos».
2. **`withCasts()` no castea un pivot.** Hace falta `->using()` con una clase.
3. **Un rol de prueba armado a mano no verifica la matriz de permisos**: pasa
   siempre. Va `$this->seed(RoleSeeder::class)` + `crearUsuarioConRol(Roles::…)`.
4. **`Livewire::test()` dos veces son dos páginas distintas**: un test así nunca
   ejerce el `refresh()` de la acción.

### Qué falta

- 🔴 `herd php artisan migrate` y **`herd composer ci`**.
- 🔴 `herd php artisan db:seed --class=RoleSeeder`, para que Rosa Elena reciba el
  permiso nuevo (usa `syncPermissions`: no arrastra nada viejo).
- **Decidir si el documento de cesión se exige.** Hoy no; el expediente digital
  ya guarda documentos y volverlo obligatorio es un cambio chico.

## 🔴 22-ago — La manzana I estaba a medias, y nadie lo podía notar

**SIN CORRER LA PUERTA TODAVÍA.** Lo de este día quedó escrito y verificado
contra el DXF, pero `composer ci` no se ha corrido: la sesión no tenía PHP
a mano. **Es lo primero que hay que hacer.**

Mauricio comparó el mapa contra el PDF del topógrafo y vio que la manzana I
terminaba en el I-7. En el plano tiene **quince** lotes. Faltaban los ocho
de atrás —**I-8 a I-15**, 2,648.45 vr²— y el plano pasa de 301 a **309**
lotes, de 85,310.81 a **87,959.26 vr²**.

**La lección, que es de método:** ningún control lo podía atrapar. Todos
comparan el dibujo de un lote contra **su propio rótulo**, y un lote que no
se leyó no tiene rótulo que comparar. El control que faltaba es una resta:
**los rótulos `vr2` del DXF contra los lotes cargados**. Vale para
cualquier lectura de plano, no solo para esta.

### Qué entró

1. **`database/data/praderas-plano.json`** — los ocho lotes, reconstruidos
   con el mismo método del resto (caras del grafo de linderos, área del
   texto impreso, frente y fondo del rectángulo mínimo). El diff es
   **puramente aditivo**: 220 líneas agregadas, cero modificadas. Error
   máximo contra el área impresa **0.0093 %**, por debajo del percentil 90
   de los 301 que ya estaban. Cero traslapes, y los ocho comparten vértice
   con la fila del frente.

2. **`olympo:completar-plano`** (`app/Console/Commands/CompletarPlano.php`)
   — la puerta que faltaba. El seeder del plano **reemplaza**, y por eso se
   detiene en cuanto hay un lote vendido: o sea que el momento en que
   aparece un faltante es exactamente el momento en que ya no se puede
   arreglar. Este comando **solo inserta**. No borra, no renumera, no
   repinta; las diferencias las informa y las deja. Es idempotente y tiene
   `--ensayo`. El precio lo hereda de los hermanos de manzana.

3. **`tests/Feature/Dominio/CompletarPlanoTest.php`** — 10 tests. El que
   importa es «NO toca un lote que ya existe, aunque el archivo le dé otra
   área»: el archivo dice 999 vr² sobre un lote vendido y el lote no se
   mueve.

4. `PlanoRealPraderasSeederTest` sube a 309 y suma «la manzana I entra con
   sus dos filas, no con una». `docs/plano-real.md` tiene la sección nueva
   con la reconstrucción y los residuos.

### Qué falta

- 🔴 **Correr la puerta completa.** `herd composer ci` — el Test del
  seeder toca 309 lotes y ninguna de estas líneas se ejecutó nunca.
- 🔴 **Cargarlos en la base de Mauricio**, que ya tiene la cartera:
  ```bash
  php artisan olympo:completar-plano RPS database/data/praderas-plano.json --ensayo
  php artisan olympo:completar-plano RPS database/data/praderas-plano.json
  ```
  El `--ensayo` primero: ese informe es la revisión.
- **El calco no se tocó.** `rps-fondo.json` es el dibujo del topógrafo y ya
  traía la manzana I entera; lo que faltaba eran los polígonos que se
  clickean, no el fondo.
- **Las manzanas `A-1` a `F-1`** del plano siguen sin cargarse, a propósito.

## ✅ Todo lo del 13 y 14 está commiteado y pusheado

`c67991a` — **122 archivos, 11,610 líneas.** 1,014 tests verdes (4,743
assertions), PHPStan 411/411 nivel 7, Pint y Rector limpios sobre 814
archivos. El árbol quedó limpio por primera vez desde el 11-ago.

🧹 Quedan para tirar a mano en `storage/app/` (gitignoreados, ya no sirven):
`diagnostico-factura.php`, `puerta-14ago.sh`, `puerta-14ago.log`,
`commit-14ago.sh`, `mensaje-14ago.txt`.

## 🔴 LA LECCIÓN DEL DÍA, Y ES LA MÁS CARA

**El bug más grave del día no lo encontró ninguno de los 1,014 tests. Lo
encontró abrir el sistema y cobrar una cuota como lo va a hacer Rosa Elena.**

Cobrar desde la **tabla de Ventas** emitía **recibo interno** en un desarrollo
que factura con CAI. Sin error, sin aviso, sin consumir correlativo: el papel
equivocado, entregado, y nadie se entera hasta una fiscalización.

La causa: `VentasTable` cargaba `'proyecto:id,nombre,codigo'` —sin
`facturacion_id`, porque la tabla no lo necesita— y el `belongsTo` de la
facturación buscaba por una llave que no estaba en memoria.

**La regla que queda: un Service del dominio NO puede confiar en las columnas
que trajo la pantalla.** Si una decisión depende de una columna, se relee de
la base. `ConsumoDeFacturas::facturacionDe()` es ahora el único lugar donde se
decide si un cobro factura, y relee. Mismo criterio que
`RegistroDeVentas::bloquearYVerificar()`: *lo que decía la pantalla no vale*.

**Y el patrón de test que faltaba:** `FacturarConElProyectoAMediasTest` carga
el proyecto **a propósito** con las columnas exactas de la tabla. El test
tiene que reproducir cómo carga la PANTALLA, no cómo carga un test.

## Qué entró

### 1. La rescisión por lote — R22

«Dio la prima, pagó dos meses y ya no quiere el lote». El lote suelta sus
cuotas pendientes, vuelve al plano, y queda el acta con los tres montos:
cuánto entró, cuánto se devolvió, cuánto quedó retenido. Se rescinde un LOTE,
no el contrato. **Lo retenido NO vuelve a sumar en caja** — ya entró el día
que se cobró.

🔴 Tres cosas que estaban vivas y salieron revisando:

1. **`anular()` devuelve `monto_pagado` a cero y DEJA viva la aplicación de
   pago.** Como la FK es `restrictOnDelete`, borrar esa cuota reventaba con un
   23503 de Postgres a mitad de la transacción. La pregunta que manda es
   `whereDoesntHave('aplicaciones')`.
2. **La cuota que sobrevive conserva saldo**, y ese saldo seguía contando como
   deuda en OCHO pantallas. Lo resuelve el scope `Cuota::deLotesVivos()`.
   🔴 Toda suma nueva de `monto - monto_pagado` tiene que llevarlo.
3. **Se le podía cobrar a un lote rescindido**: el dominio miraba el estado de
   la venta y nunca el del compromiso.

De paso entró el **comprobante imprimible del egreso**, pendiente desde el
10-ago: una sola vista para la devolución de seña y para el acta.

### 2. La facturación con CAI, de punta a punta

Configuración (13-ago) + emisión (14-ago) + **la alerta de agotamiento** que
el contrato pide por nombre (Cláusula Segunda, g-ii). El aviso sale en el
Escritorio y en el momento del cobro, y **cuando no hay nada que avisar no se
dibuja nada** — a propósito.

**Las notas de crédito quedaron como interruptor opcional, apagado**: facturar
y emitir NC son dos permisos distintos del SAR y la mayoría no tiene el
segundo. Apagado no bloquea nada; el acta le avisa al contador.

Y el modal de cobro ahora dice **qué papel va a salir antes de cobrar**, en
rojo cuando el desarrollo está configurado para facturar y hoy no puede.

⚠️ De paso: el schema del modal se armaba en **dos lugares** —el del plano
traía los avisos y el de la tabla no—, así que la alerta del talonario no se
veía por donde se cobra todos los días. Unificado.

### 3. La unidad del área, los cupos y el membrete

Varas² o metros² por proyecto, trabado al vender el primer lote. Cupos de
donación y herencia. Búsqueda de clientes sin acentos. Y el recibo interno
toma logo, nombre, dirección y teléfonos **del proyecto**, con la config solo
de respaldo: con dos urbanizaciones, un membrete para toda la instalación
dejó de alcanzar.

## Lo verificado EN PANTALLA, y lo que sigue sin verificar

✅ Los expedientes **0066, 0067 y 0068** de la cartera anterior cuadran contra
el cuaderno. En el 0068 el cuaderno lleva **dos saldos en paralelo por pares
de lotes** (480,000 + 470,000) y el sistema uno solo de 950,000 — la misma
plata contada de dos formas. No es una diferencia.

⚠️ En el **0066** el cuaderno dice cuota **L 13,605.00** y el sistema
**L 13,604.17** (653,000 ÷ 48 = 13,604.1666…). El sistema tiene razón y la
última cuota absorbe el residuo, pero **Rosa Elena tiene que saberlo** antes
de que un cliente compare los dos papeles.

❌ **La rescisión NO se probó en pantalla.** El botón aparece y el dominio
tiene 17 tests, pero nadie abrió ese modal todavía. Es lo primero del próximo
ensayo.

## Lo que sigue, en este orden

1. 🔴 **Pedirle a Rosa Elena los precios reales de los 301 lotes y la cartera
   vendida vieja.** Es el único bloqueante que no depende de nosotros y está
   anotado desde el 8-ago. Sin eso, el 20 hay un sistema impecable y vacío.
2. **Repetir el ensayo de punta a punta** con los tres expedientes reales,
   incluida una rescisión. El del 14-ago destapó tres bugs en una tarde.
3. Recién después: la nota de crédito completa, o costo contra ingreso en el
   Escritorio.

**Contra el contrato no falta nada**: la Cláusula Segunda está completa,
incluido el módulo g-ii que era el último con deuda. Lo que queda es puesta en
marcha.

---

# Continuar acá — 11-ago-2026

> Se lee esto y `docs/dominio.md` antes de proponer nada. La puerta es
> `bash storage/app/verificar-pagos.sh`: **nada se da por bueno sin eso en verde.**

## ✅ La puerta ya pasó — 805 tests

| | |
|---|---|
| Tests | **805 verdes** (4,138 assertions), 14 procesos, 30s |
| PHPStan | **341/341**, nivel 7, sin errores |
| Pint / Rector | limpios (741 archivos) |

**Falta correr la migración en `praderas_dev`** para verlo en el navegador —
Pest corre las suyas sobre `praderas_test`:

```bash
herd php artisan migrate
```

⚠️ **El CLI de este proyecto va con `herd php artisan`, NO con `herd artisan`.**
Herd contesta `Command "artisan" is not defined.` y es fácil leerlo como un
problema de Laravel. Está en `docs/` y en la memoria del entorno.

Van 12 archivos: 6 nuevos, 6 parcheados, más 2 de tests, más la migración
`2026_08_11_130000_create_gastos_table.php`.

### 🔴 Los 5 errores de PHPStan que costó, y que se repiten

1. **activitylog v5 movió las dos clases** (3 de los 5). Va
   `Spatie\Activitylog\Models\Concerns\LogsActivity` y
   `Spatie\Activitylog\Support\LogOptions` — **no** `Traits\LogsActivity` ni
   `Spatie\Activitylog\LogOptions`, que es la forma de la v4 y la que sale de
   memoria. Se copia de `Proyecto` o de `Recibo`, que las tienen bien.
2. **`selectRaw()` está tipado `literal-string`**: una expresión con una
   variable interpolada no pasa. Se arma en el llamador con la cadena
   completa escrita.
3. **`pluck('x')->first()`** lo marca `larastan.noUnnecessaryCollectionCall`.
   Va `value('x')` — y no pisa el `select` si ya hay columnas puestas.

⚠️ En el árbol seguía sin commitear el trabajo de **medidas del plano**
(`2026_08_11_120000_medidas_del_plano.php` y compañía). Los dos temas están
mezclados en el `git status`: al commitear, enumerar los dos en el mensaje.

## Lo que se construyó el 11-ago: los gastos del proyecto

Lo pidió Mauricio con la pantalla del proyecto abierta: «que ahí donde está
bloques, lotes y planes de pago haya uno que sea gastos de proyecto, y ahí se
puedan ir registrando los gastos, los totales y el motivo de en qué se gastó».

Cierra un hueco que era la mitad del negocio: Olympo sabía contestar **cuánto
he cobrado** y no sabía contestar **cuánto me ha costado**.

### Las cuatro decisiones, todas contestadas por Mauricio

| Qué | Cómo quedó, y por qué |
|---|---|
| El motivo | **Catálogo + detalle**, las dos obligatorias. `CategoriaDeGasto` tiene 18 casos y es del PRODUCTO (Ley L0), no de Praderas. El detalle es texto libre porque «Materiales — L 48,000» no le dice nada a nadie dentro de un año |
| El corte de caja | **Sí resta.** Un gasto en efectivo baja lo que tiene que estar en la gaveta. De paso entró la **devolución de seña**, que estaba anotada como pendiente desde el 10-ago |
| Quién entra | **Solo la administradora.** El receptor no ve ni la pestaña: lo que el desarrollo cuesta es información del dueño. Misma línea con la que hoy no ve prospectos |
| El alcance | Pestaña, formulario, totales por categoría y **comprobante escaneado** en disco privado. Con número propio `G-000001` desde el día uno |

### Dónde hacer clic para verlo

`Proyectos → Praderas del Sol → pestaña **Gastos**`, al lado de Planes de
pago. El cuadro de totales está arriba de la tabla y **respeta los filtros**:
filtrás por «Terracería» y el total es el de terracería.

### Tres cosas que conviene no re-discutir

1. **El gasto cuelga del PROYECTO, no del lote.** Así se gasta: la
   retroexcavadora no entra a un lote, abre la calle de un bloque entero.
   Repartirlo por lote es un prorrateo —decisión de contabilidad— y se puede
   calcular desde acá el día que haga falta.
2. **El número es serie propia y GLOBAL** (`TipoCorrelativo::Gasto`). Propia
   por lo mismo que la devolución (R12 promete que en la serie de recibos no
   falta ninguno); global aunque el gasto sea de un proyecto, porque el
   comprobante lo emite la lotificadora y una serie por proyecto se rompe el
   día que alguien corrija a qué desarrollo iba cargada una factura.
3. **Un gasto SÍ se puede editar y borrar**, a diferencia de una devolución.
   Una devolución la firmó el cliente y se llevó el papel; un gasto es un
   asiento interno cuyo respaldo es la factura del proveedor. Lo que lo
   mantiene auditable es la bitácora, que `Gasto` escribe en cada cambio.

### 🔴 La trampa de `correlativos`, otra vez

Igual que con `devoluciones`: los dos CHECKs de esa tabla tienen la lista de
tipos **congelada en su migración**. Agregar el caso al enum no alcanza y la
migración de `gastos` los recrea. **Cualquier serie nueva tiene que hacer lo
mismo.**

### Lo que NO entró, y es lo siguiente

1. **El comprobante de egreso imprimible.** El número ya se emite y los datos
   ya se guardan; falta la ruta, el controlador y la vista, con el patrón de
   `ImprimirReciboController`. Es exactamente el mismo pendiente que arrastra
   la devolución de la seña — **se resuelven juntos, en un solo drop.**
2. **Costo contra ingreso en el Escritorio.** Hoy hay que sumar a mano lo
   cobrado y lo gastado para saber cómo va el proyecto.
3. **La separación inversión / gasto operativo.** `CategoriaDeGasto` no la
   hace a propósito: dónde caen exactamente la mano de obra, lo legal y los
   impuestos es una decisión de contabilidad, no de programación. El día que
   un contador la conteste entra como un método más del enum, y ninguna fila
   guardada cambia.
4. **Filtro por rango de fechas.** Hoy hay «Solo este mes» y nada más.

## Lo segundo del 11-ago: las imágenes se guardan en WebP

`App\Domain\Archivos\GuardadoDeArchivos`, enganchado en los DOS lugares donde
se sube algo: el comprobante del gasto y los papeles del expediente. Calidad
82, lado largo topado en 2,400 px. Una foto de teléfono de 2–5 MB queda en
250–400 KB.

### No se hizo por el disco

Mauricio preguntó si convenía mandar los archivos a Drive. Los números dicen
que no hace falta: el VPS es un **Hostinger KVM 4, 200 GB NVMe**, y descontando
sistema, base, respaldos y logs quedan ~188 GB — **unas 540,000 imágenes en
WebP**. La razón real es la pantalla: quien abre un expediente en Cucuyagua
espera por una conexión que no es la de la oficina.

**Drive quedó descartado**: paquete de comunidad, OAuth por lotificadora, y el
refresh token vence a los 7 días si la app no está publicada en Google Cloud —
el expediente dejaría de abrir sin que nadie tocara nada. Si algún día el disco
aprieta, la salida es S3: el disco `s3` YA está configurado con `endpoint`, así
que sirve Cloudflare R2 (10 GB gratis, egreso $0).

### La regla que manda

🔴 **Un comprobante NUNCA se pierde por optimizarlo.** GD sin WebP, imagen
corrupta, más de 40 millones de píxeles, o un WebP que pese más que el
original: en todos esos casos se guarda el archivo tal como llegó.

Los PDF no se tocan. Lo que ya viene en WebP tampoco.

### De paso: el peso lo dice el disco

`Gasto` y `Documento` leen `Storage::size()` en un hook `saving()`. Antes se
guardaba el tamaño del archivo subido, que después de convertir mentía por
seis.

### 🔴 La trampa que costó 5 tests

**En los closures de Filament el NOMBRE del parámetro es la llave.**
`saveUploadedFileUsing` se evalúa con `evaluate($callback, ['file' => $file])`:
al parámetro que no encuentra por nombre se lo pide al contenedor. Llamarle
`$subido` en vez de `$file` reventó con `BindingResolutionException:
Unresolvable dependency [$path]` en los cinco tests de archivos.

Por eso esos dos parámetros van **en inglés** (`$component`, `$file`), contra el
estilo del repo. Es la misma familia que la trampa de `$arguments` en los
campos de un schema.

---

# Continuar acá — 10-ago-2026

> Se lee esto y `docs/dominio.md` antes de proponer nada. La puerta es
> `bash storage/app/verificar-pagos.sh`: **nada se da por bueno sin eso en verde.**

## 🔴 LO PRIMERO AL RETOMAR

```bash
herd composer ci && bash storage/app/verificar-pagos.sh
```

**El drop del 10-ago está escrito y NO pasó por la puerta todavía.** No trae
migración. Van 8 archivos: 3 nuevos, 3 reescritos, 2 tests nuevos.

⚠️ **Lo primero que puede caerse, y es a propósito.** El modal se mudó a una
clase compartida y sus closures reciben `$record` inyectado. En una fila de
tabla eso está probado; en una página de registro (`ViewVenta`) el código
viejo usaba `$this->venta()` y nunca dependió de la inyección. Si Filament no
la hace ahí, **`CobrarDesdeElExpedienteTest` y `AbonarACapitalTest` se caen
enteros** — que es exactamente para lo que se les conservaron los nombres
`cobrar` y `abonar_a_capital`. El arreglo, si pasa, es una línea: que las
closures de `CobrarUnPago::modal()` tomen la venta del Livewire en vez del
parámetro.

🧹 Queda para tirar a mano: `storage/app/_analisis/cobrar-desde-la-tabla.zip`
(el vehículo; desde el puente no se puede borrar).

## Lo que se construyó el 10-ago: cobrar desde la tabla, y el toggle

Cierra el pedido de Mauricio, que fueron tres cosas dichas en el mismo rato:
el botón de pagar en la tabla, que abra en modal, y **que no saque a nadie de
la pantalla donde está** — «siempre en la vista de cliente ahí debe de abrirse
el modal».

### 🔴 Se encontró una regresión sin commitear, y estaba viva

El botón que se había puesto el día anterior **borró el
`SelectFilter::make('cliente')` de `VentasTable`**. `ListadoDelCliente::ventas()`
sigue armando `?filters[cliente][value]=…`, así que el atajo desde la ficha del
cliente abría el listado **ENTERO, sin avisar de nada**. Restaurado, con la
cicatriz escrita en el docblock. Lo agarra `QueTieneElClienteTest`.

### El modal vive en `App\Filament\Support\CobrarUnPago`

`ViewVenta` pasó de **996 líneas a 84**. No fue estética: un modal que vive en
una página no se puede abrir desde una fila, y copiarlo habría dejado dos
modales de dinero que hay que mantener iguales. Mismo argumento que
`ImprimirRecibo`, que ya estaba en el repo.

Las dos acciones conservan sus nombres —`cobrar` y `abonar_a_capital`— y son
**el mismo modal con otro valor inicial del toggle**. Por eso los tests viejos
sirven de red sin tocarles una línea.

Se borró `VentasTable::cobrar()`, el atajo `?action=cobrar` que redirigía.

### El toggle: cuota · abono a capital · ambas

`App\Filament\Support\ModoDeCobro`, con un `reprograma()` que **es una frontera
de permiso, no una etiqueta**: «abono» y «ambas» solo aparecen con
`Reprogramar:Venta` (R21) **y se vuelve a preguntar en el servidor** antes de
ejecutar. Un campo del formulario se falsifica; un permiso no.

Al receptor el toggle ni se le dibuja —una sola opción es ruido— y como
Filament no deshidrata un campo oculto, el modo cae solo en «Cuota».

### `RegistroDePagos::cobrarYAbonar()` — lo que «Ambas» ejecuta

El caso que **hasta hoy no tenía solución**: el lote tiene una cuota pagada a
medias y el cliente llega con dinero para terminarla y bajar el capital con el
resto. `abonarACapital()` lo rechaza —R21 respeta esa cuota, así que lo que le
falta queda fuera del tope— y `cobrarVariosLotes()` se lo come entero sin
reprogramar. Eran dos trámites y dos papeles para un solo billete.

**Un monto, una transacción, un recibo.** La raya la pone
`paraDestrabarElAbono()`: se cobra lo mínimo para que **ninguna** cuota quede
tocada a medias, y el sobrante baja capital. No es arbitraria — es la misma
raya que R21 ya había dibujado. Después de ese cobro todas las pendientes
tienen `monto_pagado = 0`, así que **`EfectoDelAbono` corre con las reglas de
siempre sobre un plan limpio: no hay una segunda versión del abono.**

Tres decisiones que conviene no re-discutir:

| Qué | Cómo quedó, y por qué |
|---|---|
| El concepto del recibo | `abono_capital`, porque reescribió un plan. `anular()` rechaza los recibos que reprogramaron; si dijera «cuota» se podría anular dejando un plan nuevo pagado con dinero que ya no entró |
| Sin sobrante | **Se rechaza**, con el número que falta. Registrarlo dejaría una constancia de reprogramación que no reprogramó nada, con su motivo y todo |
| `abonarACapital()` | **No se tocó ni una línea.** Los ayudantes privados se comparten; la secuencia no. A diez días de arrancar, refactorizar el camino que ya está en producción no valía |

### La previsualización, que era media función

§10.8 manda mostrar el reparto ANTES de confirmar, y por eso
`paraDestrabarElAbono()` es **pública**: la pantalla calcula la raya con el
MISMO método que después ejecuta el cobro. El día que uno de los dos cambie, el
cliente no puede firmar un número y la base guardar otro.

⚠️ **La mora va en cero en las tres previsualizaciones** de esa pantalla —ya
era así antes de este drop—. Se calcula adentro de la transacción, con las
cuotas bloqueadas. Con R2 (Praderas no cobra mora) los dos números son el
mismo; el día que una lotificadora la active, mostrarla antes de confirmar
entra con el drop de presentación de mora.

## 🔵 El drop siguiente, ya decidido: el abono repartido entre varios lotes

Lo pidió Mauricio el 10-ago y **se pospuso a propósito**, no se descartó.

**Qué:** que un abono pueda ir a más de un lote del contrato, **con el monto de
cada lote tecleado por quien recibe** (decisión suya: no partes iguales
automáticas). Un botón «partes iguales» puede rellenar los campos como atajo,
pero el número que se guarda es el que quedó en pantalla.

**🔴 Choca con R21, y hay que resolverlo antes de escribir código.**
`docs/dominio.md` dice textual: «El abono se aplica **a un lote**, y lo elige
quien recibe», y lo justifica —«repartirlo entre todos recalcularía tres cuotas
de golpe y le movería números que no pidió tocar»—. Con el monto tecleado lote
por lote el sistema no adivina nada, así que el espíritu se respeta; pero **la
letra la escribió la contratante** y hay que enmendarla con su firma, no por
decisión de Olympo.

**Lo que cuesta, ya medido el 10-ago:**

- ✅ **La base ya lo aguanta.** `reprogramaciones.recibo_id` **no tiene unique**:
  un recibo admite varias constancias hoy mismo, sin migración.
- 🔴 `Recibo::reprogramacion()` es **`hasOne`** → tiene que ser `hasMany`. Con
  eso caen `CobrarUnPago::avisarDelAbono()` y todo lo que lea esa relación.
- El Service: un método que recorra los lotes, cada uno con su
  `EfectoDelAbono`, su `reescribirElPlan` y su `asentarLaConstancia`, todo
  adentro de la MISMA transacción y con UN recibo.
- La previsualización con N tablas de «antes y después», que es la parte que
  más hay que explicar con un cliente enfrente.
- Decidir si la modalidad (bajar cuota / acortar plazo) es **una para todo el
  recibo o una por lote**. R21 dice que la elige el cliente; con tres lotes
  puede querer distinto en cada uno.

---

# Continuar acá — 8-ago-2026

> Se lee esto y `docs/dominio.md` antes de proponer nada. La puerta es
> `bash storage/app/verificar-pagos.sh`: **nada se da por bueno sin eso en verde.**

## 🔴 La fecha cambió: 20 de agosto de 2026

Mauricio la adelantó el 6-ago. El contrato decía 11 de septiembre y la contratante lo
confirmó (R18); **manda el 20-ago igual**. Si aparece el 11-sep escrito en otro `docs/`,
está viejo.

Son **14 días** desde el 6-ago.

## Estado al cerrar el día

| | |
|---|---|
| Tests | **685 verdes** (3572 assertions), cadena completa después de interés y mora |
| PHPStan | 271/271, nivel 7 |
| Pint / Rector | limpios |
| Plano real | **cargado: 301 lotes, 0 sin dibujar** |

## Lo que se construyó el 8-ago, tarde: interés y mora configurables

Implementa `docs/que-le-falta.md` §1, el drop más grande del producto. **El
detalle completo está en `docs/interes-y-mora.md`** — acá van solo las tres
cosas que hay que saber antes de tocar nada.

### 🔴 El §1.2 del análisis tenía un error, y cambió el diseño

Decía que con `i = 0` la fórmula francesa «degenera exactamente en P ÷ n» y
que por eso habría **un solo camino de código**. El límite es correcto, pero
**la cuenta es `0 ÷ 0`**: numerador `P × 0`, denominador `1 − 1`. bcmath la
rechaza.

Así que son dos caminos, y el `if` de la tasa cero es obligatorio. De regalo:
**Praderas del Sol corre exactamente el mismo código que corría el 7-ago**, a
doce días de arrancar. El golden test del §9.C9 mide el mismo `armar()` de
siempre, sin una línea tocada — y si falla, eso es lo único de este drop que
puede afectar el 20-ago.

### La imputación de pagos cambió: mora → interés → capital

Es lo más profundo que se tocó. Con tasa 0 y sin mora los dos primeros pasos
valen cero y el reparto es el FIFO a capital de siempre, así que Praderas no
se entera. **Hay que escribirlo en el contrato** de la lotificadora que sí
cobre: con capital primero, un cliente atrasado nunca sale de la deuda.

Adentro de cada cuota el interés se paga antes que el capital, y no hizo falta
ninguna columna: se deriva de `monto_interes` y `monto_pagado`.

### Todo nace apagado

Tasa 0, mora `ninguna`. R1 y R2 pasaron de estar **cableados** a ser la
**configuración de fábrica**. Las cuatro modalidades de mora están disponibles
para que cada lotificadora vea cuál le aplica, como pidió Mauricio.

**26 archivos**: 7 nuevos, 8 reescritos, 9 parcheados, 1 migración.

### La cadena pasó entera, y lo que costó

`lint` → `ci` → 685 verdes. Tres vueltas, y las tres fueron del mismo tipo de
error mío, ninguno de lógica:

1. **Pint** — alineé cinco `=>` contra un key que estaba **del otro lado de un
   comentario**; el comentario parte el grupo. Y metí dos `use` de clases del
   **propio namespace** (`App\Domain\Ventas` dentro de `App\Domain\Ventas`).
2. **PHPStan** — `numeric-string` se pierde al cruzar un parámetro declarado
   `string`. Cuatro errores de un solo molde. Va `@param numeric-string` en el
   docblock, y `is_numeric()` cuando el valor viene de afuera.
3. **Rector** — `private static` que solo se llama desde adentro va de
   instancia. Ojo: `modalidadDe()` y `comoModalidad()` **se quedan static** a
   propósito porque viven en closures `static fn`, donde no hay `$this`.

⚠️ **Los tests no necesitaron ni un cambio.** Se esperaban fallos por la firma
nueva de `CuotaProyectada`, y no hubo: nadie la construye fuera de
`PlanDeCuotas`, y con tasa 0 `cierraExacto()` compara los mismos dos números
que comparaba antes. Es la mejor prueba de que el camino de Praderas quedó
intacto.

### 🔴 Sigue abierto: el tope legal

No hay número que citar. La **Ley de Créditos Usurarios (Decreto 100-62)** no
fija un porcentaje: delega en la Secretaría de Finanzas el máximo no bancario
y habla de contratos de **préstamo**, no de compraventa a plazo. El tope de
120 % del CHECK es **de cordura** —frena un 1200 donde iba 12.00—, no legal.
Antes de que una lotificadora ofrezca una tasa, va un abogado.

### Lo que no hace

Condonar mora sin cobrar nada; el estado de cuenta y el recibo impreso
todavía no muestran las columnas de capital, interés y mora —los datos están,
falta la presentación, y con tasa 0 se ven igual que hoy, así que **no bloquea
el 20-ago**—; avisos de mora al cliente.

---

## Lo que se construyó el 6-ago

Cuatro drops, los tres primeros verdes y pusheados:

1. **R21 — abono a capital**, con sus dos modalidades y la constancia en `reprogramaciones`.
2. **El recibo impreso** (módulo g-i) y **el estado de cuenta** (módulo h), los dos HTML fuera del panel.
3. **La seña del apartado emite recibo** (R14 + R12 + R11), uno por lote, de la serie única.
   **La prima emite recibo** por `prima − señas`, colgado del expediente; la seña queda ligada
   a la venta sin perder su `compromiso_id`.
4. **R14 completo + las obligaciones del contrato** ← *sin verificar, es lo primero que hay que correr*

## ⚠️ Lo primero al retomar

```bash
herd composer ci && bash storage/app/verificar-pagos.sh
```

**Lo del 8-ago no pasó por la puerta todavía, y trae una migración nueva**
(`2026_08_08_100000_agregar_tarjeta_a_formas_de_pago.php`), así que va
`herd php artisan migrate` antes. El drop 4 del 6-ago quedó commiteado.

## Lo que entró en el drop 4

### R14 completo

- **Migración `2026_08_06_140000`** — `compromisos.prorrogas` y `compromisos.senia_devuelta_el`,
  dos CHECKs y un índice **parcial** sobre `(vence_el) WHERE tipo = 'apartado' AND estado = 'vigente'`,
  que es la consulta de la pantalla nueva.
- **`RegistroDeCompromisos::prorrogar()`** — una sola prórroga, motivo obligatorio.
  Los días corren **desde el vencimiento si no llegó y desde hoy si ya pasó**: prorrogar
  «desde su vencimiento» un apartado caído hace diez días le dejaría cinco días, y quien
  autorizó creyó estar dando quince.
- **`RegistroDeCompromisos::devolverLaSenia()`** — marca la devolución para que la lista de
  pendientes se pueda vaciar. **No es un egreso**: eso se decidió dejar para después.
- **Pantalla de Apartados** (`app/Filament/Resources/Apartados/`) — ordenada por lo que vence
  primero, con contador rojo en el menú y tres filtros: vencidos, por vencer, con seña por devolver.
- **Dos permisos nuevos**, nombrados uno por uno (§9.E3): `Prorrogar:Compromiso` y
  `DevolverSenia:Compromiso`, solo para la administradora.

### Las obligaciones del §1.4 que no estaban

Auditoría contra la Cláusula Segunda, no contra el traspaso. Faltaban cuatro:

- **Leyenda del contrato en el recibo** — decía «No es comprobante fiscal» y el contrato exige
  literalmente **«NO VÁLIDO PARA CRÉDITO FISCAL»**. Corregido.
- **Kill-switch por mora** (Cl. Séptima) — `App\Http\Middleware\SuspensionPorMora`, por
  `PRADERAS_SUSPENDIDO` en `.env`. Corta panel y documentos, **no borra nada** y **no bloquea al
  super-admin**: la Cl. Décima obliga a poder exportarle los datos al cliente aunque esté suspendido.
- **`praderas:exportar-todo`** (Cl. Décima) — CSV con BOM, zip, tablas listadas a mano.
  No exporta `password` ni `remember_token`.
- **Medidor de almacenamiento** (Cl. Novena) — widget del escritorio, suma `documentos.bytes`
  contra los 25 GB incluidos y avisa al 80%. Se mide lo que el CLIENTE guardó, no el disco:
  un `du` incluiría vendor, respaldos y logs, que no se le facturan a nadie.

Los **respaldos diarios ya estaban agendados** en `routes/console.php` con retención de 30 días.
Casi los duplico por leer solo las primeras 30 líneas del archivo — el `assert` lo atajó.

## Lo que se construyó el 8-ago

### Cobrar varios lotes en UN recibo

Lo pidió Mauricio mirando el modal: «si quiere pagar la cuota de dos o de los tres sería
uno por uno, no lo veo factible». Eran tres trámites y tres papeles para un cliente que
entregó un solo billete.

- **`RegistroDePagos::cobrarVariosLotes()`** — bloquea las cuotas de cada lote **ordenando
  por id antes de bloquear** (sin ese orden fijo, dos receptores cobrando los mismos dos
  lotes al revés se traban entre sí), verifica todos los renglones y **recién entonces**
  quema un correlativo. `cobrarCuotas()` pasó a ser el caso de un renglón y delega.
- **Sin migración.** `aplicaciones_de_pago` cuelga de la CUOTA, no del lote, y
  `recibos_cuelgan_de_un_compromiso_chk` solo pide venta O compromiso.
- **`compromiso_id` se llena con un lote y queda NULL con dos o más.** Las pantallas leen
  `Recibo::codigosDeLotes()`, que cae a las aplicaciones cuando la columna está vacía.
- **El modal abre con todo marcado** y la cuota del mes de cada lote ya escrita. El
  desglose muestra cuota por cuota agrupado por lote, con el **total** abajo (§10.8).

### Tarjeta, cuarta forma de pago

R11 contestó tres y descartó cheque; tarjeta ni se preguntó. La agregó Mauricio pensando
en **las demás lotificadoras que van a usar el sistema**. El recibo sale por el monto
entero: **la comisión del POS no se calcula ni se imprime**, y esa fue la decisión.

⚠️ Agregar un `case` a `FormaDePago` **no alcanza**: la lista también vive en el CHECK
`recibos_forma_valida_chk`. La migración del 8-ago es el molde para la próxima.

### El cuadro de lotes de la ficha ya no recorta números

La tarjeta de Filament no ofrece scroll: **recorta**. Con siete columnas `nowrap` en media
pantalla, «L. 54,166.67» se leía «L. 54,1». Van las dos cosas juntas: `columnSpanFull()` en
la Section y el envoltorio `.olympo-scroll`.

### Que el servidor no pueda fallar en silencio

La auditoría encontró **tres fallas que solas son medias y juntas son graves**: el respaldo
no salía del servidor (`s3` comentado), el cron había que instalarlo a mano y nadie avisaba
si faltaba, y `MAIL_MAILER=log` hacía que la alerta de «el respaldo falló» no llegara a
nadie. Lo peligroso no es que existan: es que **se ven exactamente igual que un servidor
sano**.

- **`php artisan olympo:verificar-produccion`** — la puerta. Trece revisiones: entorno,
  depuración, llave, https, cookie segura, correo real, alertas encendidas, **el latido del
  cron**, el respaldo saliendo del servidor, respaldo cifrado, contraseñas de base y Redis,
  **que nadie haya quedado con «12345678»** (comprobado contra el HASH, no contra el `.env`,
  porque con la config cacheada `env()` devuelve null), cachés y enlace de storage. Devuelve
  código ≠ 0 si falta algo grave. `--estricto` falla también con los avisos.
  **No se agrega a `composer ci`**: en local falla casi todo, y está bien.
- **`ScheduleCheck` registrado** en `HealthServiceProvider`. `health:schedule-check-heartbeat`
  ya escribía el latido cada minuto y **nadie lo leía**. Ahora `/health` lo reporta.
- **`config/health.php`: notificaciones encendidas** (`HEALTH_NOTIFICATIONS`, por defecto
  `true`) y `CheckFailedNotification` registrada. Estaban en `false`.
- **`config/backup.php`: los destinos salen del `.env`** (`BACKUP_DISKS=local,s3`). Estaba
  cableado a `local` con el `s3` comentado.
- **`.env.production.example`** — la plantilla del servidor, separada de la de desarrollo.
- **`docs/DESPLIEGUE.md`** — el runbook, con la línea del crontab y la lista de lo que hay
  que verificar antes de entregar la llave.

⚠️ El prefijo del comando nuevo es **`olympo:`**, no `praderas:`. Es del producto, no del
primer cliente. `praderas:exportar-todo` se llama así por herencia y habrá que renombrarlo.

### Anular · liquidar · la fecha del pago

Los tres huecos de ventanilla que la auditoría marcó como «lo que va a doler la primera
semana». Migración `2026_08_08_110000_anular_recibos.php`.

- **`RegistroDePagos::anular($recibo, $motivo)`** — devuelve a las cuotas lo que ese recibo
  aplicó, marca quién y por qué, y reabre la venta si ese cobro la había liquidado. **El
  número no se libera y la fila no se borra**: una serie con huecos deja de servir para decir
  «entre el 000120 y el 000130 no falta ninguno». Las aplicaciones tampoco se borran — son la
  traza de «¿por qué la cuota 3 volvió a deber?».
  Solo cobros de **cuota**: una prima o una seña consumieron un correlativo de contrato o
  dejaron un lote apartado, y un abono a capital reescribió un plan. Los tres se rechazan con
  su motivo.
  **No devuelve dinero**: anular dice que el cobro no debió registrarse, no que haya que sacar
  plata de la caja. Eso es un egreso y sigue sin existir.
- **Permiso `Anular:Recibo`, solo administradora.** Nombrado uno por uno (§9.E3) y
  deliberadamente fuera del receptor: quien cobra no debería poder borrar su propio cobro del
  estado de cuenta.
- **`EstadoVenta::Liquidada` por fin se asigna.** Estaba definido desde la primera migración y
  **nadie lo escribía nunca**: una venta pagada al último centavo se quedaba «Vigente» para
  siempre. Ahora `cerrarSiQuedoPagada()` la cierra al terminar de repartir —en el cobro y en
  el abono— y `reabrirSiVolvioADeber()` la reabre si se anula el cobro que la cerró.
- **La fecha del pago se valida en el Service**, no solo en el DatePicker: el Service es la
  única puerta y lo va a llamar también el import de la cartera vieja. Nada futuro, nada
  anterior a la firma del contrato (el clásico error de tipear el año).
- El recibo impreso de un anulado sale con el sello **ANULADO**, su fecha y su motivo. La
  lista lo muestra con badge rojo y el motivo en el tooltip; el filtro nuevo deja verlos
  todos por defecto, porque quien llega con el papel busca por número y tiene que encontrarlo.

## 🟢 El sistema es un PRODUCTO, no un trabajo a medida

Mauricio, 8-ago: «hay que agregarle cosas para que sea lo más profesional posible ya que lo
venderemos a más personas, no solo a esa lotificadora».

Cambia el criterio con que se cierra una discusión: **las reglas de la contratante pasan a
ser el mínimo, no el techo.** Lo que se agregue de más va detrás de configuración, no
cableado. La fecha del **20-ago es de Praderas del Sol**; el trabajo de producto va después,
salvo lo que sea más barato ahora — tocar el esquema del dinero no cuesta lo mismo hoy, sin
datos de producción, que en octubre.

### 🟡 Pendiente que salió de acá: el pago mixto

Parte en efectivo, parte en transferencia, parte con tarjeta. **Hoy no se puede**:
`recibos.forma_pago` y `recibos.referencia` son columnas simples con CHECK.

Forma propuesta, la misma que se usó con los lotes: tabla `formas_del_recibo`
(recibo_id, forma, referencia, monto) + CHECK de que la suma cuadre con `recibos.monto`;
`forma_pago` se sigue llenando cuando hay una sola. De regalo, la referencia pasa a ser
**por instrumento**, que es lo que R11 quiere para cruzar contra el banco. ~15 archivos,
un día. Quedó detrás del 20-ago.

## ✅ El CAI: resuelto el 6-ago, y el motivo importa

El contrato (Cláusula Segunda, g-ii) pide CAI en Etapa 1 y R10 dice que no se usa. **Lo
resolvió Mauricio el mismo 6-ago:**

> «Se dejará lo de facturas con CAI, pero se usará solo recibo interno por el momento ya que
> **no están afiliados al SAR**, pero se dejará para un futuro emitir facturas con CAI.»

R10 no era una preferencia: es un hecho de la situación fiscal del cliente. Praderas del Sol
no puede emitir un documento con CAI hoy aunque el sistema se lo permitiera, así que
construir el módulo ahora sería construir algo inusable. **Y por eso el día que se afilien,
hace falta: es alcance diferido, no descartado.**

La puerta ya está abierta y no cuesta nada mantenerla: `recibos.tipo_documento` existe con un
solo valor en la práctica, y `correlativos` maneja series por tipo, así que una serie de
facturas con CAI no chocaría con la de recibos internos (R12). **No hay tablas `cais` ni
`rangos_cai`, y está bien que no las haya.**

🟡 **Lo único que sigue abierto: la constancia por escrito.** Un módulo contratado que no se
entrega debería tener un WhatsApp o correo de Rosa Elena confirmando que no están afiliados al
SAR. No es desconfianza — dentro de un año nadie se va a acordar de esta conversación y el
contrato va a seguir diciendo que el CAI era Etapa 1. **Mauricio no confirmó si lo pidió.**

## Lo que queda, contra el contrato

| Módulo | Etapa | Estado |
|---|---|---|
| a Clientes · b Lotes · c Ventas · d Contratos | 1 | ✅ |
| e Promesa de venta | 1 | ✅ `documentos` + relation manager |
| f Apartados con recibo y control de vigencia | 1 | ✅ (drop 4) |
| g-i Recibo interno correlativo | 1 | ✅ |
| **g-ii CAI** | 1 | ⏸️ **diferido**: el cliente no está afiliado al SAR (6-ago) |
| h Balance y estado de cuenta | 1 | ✅ |
| i Registro del receptor | 1 | ✅ `recibos.created_by` (el arqueo es Etapa 2) |
| m Usuarios, roles y bitácora | Base | ✅ |
| j Gastos · k Expediente digital · l Libro maestro | 2 | fuera de Etapa 1 |

**Egresos / devolución formal de la seña**: decidido el 6-ago dejarlo para después. Hoy el
sistema **avisa** cuánto hay que devolver y deja marcar la devolución; el comprobante de salida
es otro drop.

**R20 y R22 NO son módulos del contrato**: los pidió la contratante en la reunión del 6-ago.
El traspaso viejo decía «lo próximo es R22» y contra el contrato no lo era.

## Trampas que mordieron hoy

1. **No cachear modelos Eloquent en Redis.** `BrandingSetting::current()` guardaba el objeto
   entero; el nombre de la clase queda dentro del blob y al deshidratarlo volvía
   `__PHP_Incomplete_Class`, tumbando el estado de cuenta con un 500. Se cachea el **array de
   atributos**. El panel lo tapaba con un try/catch, así que solo se veía en los documentos.
2. **Un test que cuenta `Recibo::query()->count()` cuenta también el de la prima**, porque
   `activar()` ahora emite el suyo. Filtrar por concepto.
3. **`compromisos_vencimiento_coherente_chk` exige `vence_el >= fecha`.** Un apartado vencido no
   se fabrica con fecha de hoy y vencimiento de ayer: hay que **viajar en el tiempo** al día en
   que se apartó. Es la única forma en que uno vencido llega a existir de verdad.
4. **Los scopes del modelo no se resuelven sobre el `Builder<Model>` genérico de Filament.**
   La salida NO es copiar las condiciones en la tabla —eso deja la regla en dos lugares—: un
   `whereIn` contra un subquery que sí llama al scope deja una sola fuente de verdad.
5. **`Roles` vive en `App\Support`, no en `App\Domain\Enums`.**
6. **`->money()` de Filament pasa por float.** Prohibido en dinero (§8.3.1). Va
   `->formatStateUsing(fn () => $monto->formateado())`, como en `RecibosTable`.
7. **Leer un archivo con `head -30` y sacar conclusiones.** Los respaldos ya estaban agendados
   en la línea 30 y casi los duplico.

## Pendientes de decisión (no de código)

1. 🔴 **El sistema sigue sin desplegar**, pero ya no falta el CÓMO: está
   `docs/DESPLIEGUE.md` con el runbook, `.env.production.example` con la plantilla y
   `olympo:verificar-produccion` como puerta. Lo que falta es el servidor, el dominio con
   TLS, el SMTP y el bucket del respaldo — todo eso necesita las credenciales de Mauricio.
2. 🔴 Si los 301 lotes ya tienen sus **precios reales**, y si la cartera vendida vieja se va a
   cargar (R15). Los 3 vendidos y 1 apartado de la captura son pruebas nuestras.
3. La **constancia por escrito** de que no están afiliados al SAR (ver el CAI, arriba).
4. Si el receptor puede subir documentos o solo verlos (hoy solo ve).
4. El tamaño de papel del recibo no se consultó con la contratante.
5. `APP_DEBUG=true` — en local está bien; antes de salir a un servidor tiene que ser `false`,
   o un error cualquiera le muestra la consulta con datos del cliente a quien esté mirando.
6. El README todavía describe la plantilla, no el proyecto.
