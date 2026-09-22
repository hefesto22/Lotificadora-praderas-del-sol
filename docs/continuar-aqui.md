# Continuar acá — 21-sep-2026

> Se lee esto y `docs/dominio.md` antes de proponer nada. La puerta es
> `herd composer rector:fix && herd composer lint && herd composer ci && herd composer rector`.

## 21-sep — Exp. 0031: re-imputar un recibo, sin anulados, y la prima por titular

**El pedido.** RPS-2026-0031 (dos lotes T, L 250,000.00 c/u, prima L 10,000.00
c/u). El abono del talonario 377 (L 32,500.00, 05/08) llegó sin lote y la carga
lo partió a medias. «En el lote 2 solo son 10,000 de prima, en el otro va todo
el resto». Después: «no hay anulados, directamente se borran esas
transacciones» y «los recibos deben salir a estos nombres» (el titular de
recibo de cada lote). Y aparte: el papel del pronto pago salía en tres páginas.

**Cómo quedó producción (verificado con psql):** tres recibos, ninguno anulado
— prima 000259 T-002 L 10,000 · prima 000260 T-001 L 10,000 · abono 000258
T-001 L 32,500 (15,000 a cuotas 1–3 + 17,500 a capital), cada uno a nombre del
titular de su lote. T-001 debe L 207,500.00 y T-002 L 240,000.00 (447,500 en
total, igual que antes). «Todos los recibos cuadran».
⚠️ **El T-002 quedó con 3 cuotas vencidas (L 15,000.00)** y sale en «Por
cobrar hoy»: es lo que dice el reparto nuevo. Avisarle a la administración.

**Lo que entró (commits `3ae31fe`, `6ab9d23`, `879df2d`, `b17b855`):**

- **`olympo:reimputar-recibo`** (`ReimputacionDeRecibo`): anula por dentro —para
  deshacer por la puerta probada—, vuelve a registrar el mismo dinero (misma
  fecha, forma, referencia, nota y receptor) contra los lotes pedidos, comprueba
  que no se creó ni se perdió un centavo, asienta en «Actualizaciones» y **borra
  el recibo viejo**. `--ensayo` corre todo y deshace la transacción: no estima.
- **`olympo:acomodar-recibos-viejos`** (`RecibosDeCarteraVieja`):
  `--borrar-anulado=FOLIO` y `--partir-prima` (un recibo de prima por titular
  de recibo, con la prima congelada de cada compromiso). No mueve saldos.
- 🔴 **El límite que no se negocia:** borrar es SOLO para la serie vieja (la
  transcripción del cuaderno; su número no está en ningún papel). Un recibo que
  imprimió el sistema no se borra ni estando anulado (R12). Hay test.
- **El papel del pronto pago**: una línea por lote (debía · descuento · pagó ·
  «queda pagado en su totalidad»). El total sigue siendo lo que entró.
  `Recibo::prontoPagoPorLote()`.
- `ExpedientesHistoricos`: el pago del 0031 dice `'lote' => 'T-1'`. ⚠️ Una carga
  nueva NO parte la prima por titular: los nombres no están en el dato.

**🔴 Dos bugs viejos que destapó el ensayo en pruebas, ya arreglados.** Un
recibo ANULADO devuelve el dinero pero conserva sus aplicaciones como traza, así
que una cuota en cero puede seguir referenciada. `reescribirElPlanViejo()`
(anular un abono) y `reescribirElPlan()` (abonar a capital) BORRABAN cuotas y
reventaban con el error crudo de llave foránea. Se alcanzaba desde el mostrador:
cobrar, anular ese cobro, abonar a capital. Ahora las cuotas que existen en los
dos planes **se pisan en el lugar** y conservan su id.

**Deuda que queda (L4):** si la cuota que el abono ELIMINA (la cola) guarda traza
de un recibo anulado, hoy se frena con mensaje en castellano que sugiere «bajar
la cuota». Pasa en PRUEBAS en el T-001 del 0031 (un pronto pago de prueba anulado
dejó traza en todas sus cuotas), por eso ahí el ensayo no corre. La solución de
fondo pide migración (`aplicaciones_de_pago.cuota_id` nullable + guardar el
número de cuota en la traza): se decide aparte.

**Las dos mejoras que aprobó Mauricio el mismo día, ya construidas:**

1. **La pestaña Recibos del expediente marca los anulados**: folio en rojo,
   «ANULADO — motivo» debajo y el monto tachado. El texto sale de
   `Recibo::rotuloDeAnulado()`, que ahora también usa la lista general: dos
   tablas armando el mismo texto es cómo una se quedó sin decirlo.
2. **La prima de una venta NUEVA sale en un recibo por titular de recibo**
   (`RegistroDeVentas::papelesDeLaPrima()`), igual que cuotas y abonos desde el
   13-ago. Cada papel lleva la parte de la prima de SUS lotes menos SUS señas;
   cuelga de su lote si ese nombre tiene uno solo, y si tiene varios queda en
   la venta y `Recibo::compromisosDelPapel()` los encuentra por el nombre.
   **Sigue saliendo UN papel** cuando todos comparten nombre (ahora lo lleva),
   cuando a algún nombre sus señas le superan su parte, o si las partes no
   sumaran la prima. La ficha del expediente nombra todos los papeles de prima.

## 20-sep — Los L 8.00 del expediente 0028: nace `olympo:corregir-valor`

**El reclamo.** El cliente del RPS-2026-0028 (tres lotes H de 337.50 vr²) pagó
hoy L 20,000.00 y preguntó por qué el sistema le mostraba L 8.00 menos que el
cuaderno. Tenía razón.

**La causa.** El cuaderno dice valor **L 975,000.00** (L 325,000.00 por lote —
el mismo precio que ya se había confirmado el 11-ago para los de 337.50) y
cuota **L 19,604.00** a 48 meses. La cuota no cierra: 48 × 19,604 = 940,992 y
lo financiado son 941,000. Al cargar la cartera se «respetó la cuota»
**bajándole el valor al contrato** a L 974,992.00. Era al revés: el valor es el
dato del contrato y el residuo va a la última cuota (R1), que es lo que el
sistema hace en toda venta nueva.

**Se auditó la cartera entera**, no solo el caso que llegó: de los 114
expedientes, el 0028 es el único al que se le tocó el valor. (El 0066 tuvo el
caso inverso —cuota del cuaderno L 13,605.00 contra L 13,604.17 del valor— y
ahí sí se respetó el valor.)

**Lo que entró:**

- `App\Domain\Ventas\CorreccionDeValor` (Service) + `ValorCorregido` +
  `CorreccionDeValorInvalidaException`, y el comando **`olympo:corregir-valor`**
  con `--ensayo`. Acepta el **número de contrato** (igual en local, pruebas y
  producción) o el id de la URL.
- Mueve UNA diferencia por lote: valor congelado y precio por vara² (seis
  decimales) · la **última cuota** · valor y saldo financiado del expediente.
  **No toca recibos, aplicaciones ni la prima**, y la cuota mensual del cliente
  no cambia.
- 🔴 **Tampoco toca la FICHA del lote, y no puede.** La primera versión la
  llevaba al día por el query builder para esquivar `LoteInmutableException`, y
  la puerta devolvió **11 tests en rojo, todos «exit code 1»**: la base tiene
  el trigger `lotes_proteger_vendido` (migración `create_lotes_table`), que
  rechaza cambiarle área, precio o valor a un lote vendido venga de donde
  venga. Yo había leído el guard del MODELO y grepeado los `_chk`; un trigger
  no aparece en ninguno de los dos. Está bien que gane: lo que vale para una
  venta es lo congelado en `compromisos` (§8.2). **Consecuencia visible:** en
  el listado de Lotes, H-9, H-15 y H-16 siguen mostrando 324,997.33 / .33 / .34
  como valor de ficha; el expediente, el estado de cuenta y los recibos dicen
  325,000.00.
- El otro rojo de esa vuelta: el comando leía el argumento con `is_string` y
  los tests lo pasan como ENTERO (`ArrayInput` no lo convierte) → «no encontré
  ese expediente». Por la terminal nunca habría fallado; desde un
  `Artisan::call()` sí.
- 🔴 **También corre el `plan_anterior` de cada reprogramación** (y sus dos
  saldos, que el CHECK obliga a mover juntos). No es prolijidad:
  `RegistroDePagos::anular()` deshace un abono REESCRIBIENDO ese plan tal cual,
  así que sin esto anular un abono se volvería a comer la diferencia en
  silencio. `plan_anterior` no es solo historia: es una instrucción.
- Se niega si: la diferencia es más grande que una cuota (la red contra un cero
  de más), el lote lleva interés, el expediente no está vigente, el lote ya
  terminó de pagarse, o **el lote ya no cuadraba desde antes** (valor − prima −
  pagado a cuotas − abonos a capital = lo que deben sus cuotas). Esa igualdad
  se verifica antes y después, adentro de la transacción.
- Un solo asiento en la bitácora (evento `correccion`, con el motivo): se ve en
  la pestaña **Actualizaciones** del expediente.
- `ExpedientesHistoricos`: la nota del 0028 dice la verdad, y la clave nueva
  `correccion_de_valor` hace que el seeder repita el camino de producción —
  entra como entró y se corrige por la misma puerta—. Poner 325,000.00 en el
  dato le calcularía a una carga nueva OTRA cuota (6,534.72 en vez de 6,534.67).

**✅ APLICADO EN PRODUCCIÓN el 20-sep, 11:40 a.m.** Commit `46572e5`, CI #64
verde (5m 25s). Primero en pruebas —ensayo, corrección, mirar la pantalla— y
después en producción con el mismo comando, pidiéndolo por número de contrato:

    olympo:corregir-valor RPS-2026-0028 --lote=RPS-H-009:325000.00 --lote=RPS-H-015:325000.00 --lote=RPS-H-016:325000.00 --motivo="…"

El ensayo de producción dio EXACTAMENTE lo calculado de antemano contra la foto
de la base (865,992.00 → 866,000.00). Después: «Todos los recibos cuadran» y
`olympo:verificar-produccion` con los 3 FALTA de montaje de siempre (mail en
log, respaldo en disco local, usuario con `12345678`). La reimpresión del
RPS-00000106 ya dice «total L. 866,000.00», que es el papel para el cliente.
Respaldos en el servidor: `/root/praderas_produccion_2026-09-20_1739.sql.gz`
(antes del deploy) y `…_1740_antes_del_0028.sql.gz` (justo antes de escribir).

La nota de Observaciones se cambió en las dos instalaciones con un tinker que
lee el texto nuevo del propio `ExpedientesHistoricos` —así no viaja un acento
por la terminal— y solo escribe si encuentra la nota vieja.

**La puerta, en verde a la segunda vuelta:** 1370 tests / 6125 assertions
(18 nuevos en `CorregirValorTest`), PHPStan 502/502 sin errores, Pint 905
archivos, Rector sin cambios pendientes. Sin migración y sin permisos nuevos.

**Cómo queda el 0028:** valor 975,000.00 · financiado 941,000.00 · cuota 48 de
cada lote 1,273.19 / 1,273.19 / 877.18 (mes 48: 3,415.56 → 3,423.56) · saldo
**L 866,000.00**, que es lo que da el cuaderno (928,000 − 11,500 − 30,500 −
20,000). Al reimprimir el RPS-00000106 el saldo ya sale bien: el papel lo lee
de las cuotas.

⚠️ **Las observaciones del expediente NO se editan desde el panel.** La nota
vieja («se respetó la CUOTA…») hay que cambiarla en producción con un tinker
que lee el texto nuevo del propio `ExpedientesHistoricos`. Propuesta L5 abajo.

**Propuestas esperando decisión (L5):**

1. **«Editar observaciones» en la ficha del expediente** — hoy no hay forma de
   corregir una nota sin SSH. Una acción chica en `ViewVenta`, con su asiento.
   Le sirve a la administradora; es del producto. ~2 horas.
2. **Que la carga de una cartera vieja AVISE cuando valor − prima no es
   múltiplo de la cuota del papel**, en vez de decidir sola a quién creerle. El
   0028 se habría cargado bien el primer día. Es del producto (todo cliente
   nuevo trae cuaderno). ~1 hora, en `revisarTodoAntesDeCargar()`.

## 19-sep — Desplegado a pruebas y a PRODUCCIÓN, con Río Blanco y La Unión cargados en las dos

`c645816` está en `pruebas.praderasdelsol.cloud` y en `praderasdelsol.cloud`
(«Nothing to migrate», «Todos cuadran», CI #63 verde antes de producción).

**CRB y LLU se cargaron también en producción** —decisión de Mauricio— con sus
seeders de `Database\Seeders\Clientes\…`: 83 lotes / 36,431.17 v² y 95 lotes /
34,578.87 v², los mismos números que en local y en pruebas. Desde hoy Rosa
Elena y los receptores ven el interruptor con tres proyectos.

🔴 **Lo que eso deja abierto EN PRODUCCIÓN, con datos reales al lado:**

- Los 178 lotes nuevos están en **L 0.00 y sin plan de pago**: no se pueden
  vender, pero **sí apartar**. Faltan los precios y los planes de los dos.
- La **manzana G de LLU es provisional** (letra y números 1–4 puestos por el
  sistema) y el **E-10 de CRB** dice 312.00 v² contra 320.81 del dibujo. Las
  dos respuestas son del Ing. Gerson Menjívar. Nadie debería apartar un lote de
  la G hasta tenerlas.

**Lo que se aprendió desplegando** (detalle en la memoria del proyecto,
`desplegar-praderas`):

- A la cadena se le antepuso una guarda que frena ANTES del `down` si el repo
  del servidor está sucio. La primera vez frenó por los `.gitignore` de
  `storage/`: era el bit de permisos del `chmod -R` del montaje. Se curó con
  `git config core.fileMode false`, local a cada carpeta (pruebas y producción).
- En producción, antes del `down`: lista de commits que entran + `pg_dump` con
  `pipefail` y su `ls`. El volcado pesa **163 KB** comprimido; los archivos
  llevan fecha UTC (el servidor está en UTC: a las 8:24 p.m. de acá ya es 20-sep).
- **El deploy mueve código, no datos**, y con un solo proyecto el interruptor no
  se dibuja: pruebas se veía idéntica hasta correr los seeders.
- La puerta marcó «el cron nunca latió» y un minuto después «hace 0 minutos»:
  el falso positivo de siempre tras `optimize:clear`. Quedan 3 FALTA de
  montaje: `MAIL_MAILER=log`, `BACKUP_DISKS=local` y un usuario con `12345678`
  (Mauricio: «eso se cambia después»).

**L4 — deuda nueva:** la ayuda de `olympo:verificar-produccion` sugiere la línea
de cron `cd … && php artisan schedule:run`, que en este servidor correría como
root y con PHP 8.4: exactamente la causa del 500 del 27-ago. Tiene que proponer
`sudo -u www-data` y la ruta completa del PHP.

**Propuestas esperando decisión (L5):** el test guardián de contadores
(§9.E6), y que el atajo «Plano» aparezca también con UN solo proyecto.

## 18-sep — El interruptor de proyecto quedó como el de Maya: diseño, atajo al plano y filtro global

Dos pedidos de Mauricio el mismo día, con las dos instalaciones abiertas una al
lado de la otra: «que el filtrado de proyecto tenga el mismo diseño que tiene
la Inmobiliaria Maya» y, al verlo, «falta eso del plano y lo del filtro
global». Todo se trajo del repo de Maya —sus commits `c674833`, `a664e9d` y
`73420ae`—, no se adivinó de las capturas.

### El diseño

Nació acá el 11-sep colgado de `TOPBAR_START`, y quedaba a la izquierda de
TODO, antes incluso del botón que pliega el menú: lo primero que se leía de la
pantalla era un filtro, y el logo quedaba en el medio de la barra.

- `AdminPanelProvider`: `TOPBAR_START` → **`TOPBAR_LOGO_AFTER`**.
- `tema-olympo.blade.php` §18: `margin-left: 1.5rem` en `.olympo-selector`,
  para que la píldora no quede pegada al logo. Con eso la sección 18 del CSS
  queda idéntica a la de Maya.

⚠️ La cabecera de la barra lateral NO sirve para esto: con la lateral
colapsable y una barra superior presente, Filament esconde `.fi-sidebar-header`
(`display: none`) y el selector se arma con tamaño cero.

### Lo que faltaba del filtro global

Acá el interruptor YA recortaba Lotes, Bloques, Ventas, Apartados, Recibos,
Prospectos y el Escritorio (eso llegó con la historia compartida). Tres lugares
seguían sin enterarse de qué proyecto se estaba mirando:

1. **La lista de Proyectos** mostraba todos. Ahora muestra solo el elegido.
   🔴 El recorte va en la **tabla** (`ProyectosTable`, `modifyQueryUsing`) y
   NO en `ProyectoResource::getEloquentQuery()`: con esa consulta el resource
   resuelve el record de TODAS sus páginas, y recortarla ahí hace que el plano
   o la ficha de un proyecto den **404** en cuanto el elegido es otro. En Maya
   pasó exactamente eso. Hay un test que lo dice con ese nombre.
2. **El atajo «Plano»** en el menú, arriba de «Proyectos» en *El desarrollo*
   (`->navigationItems()` del panel). Solo se dibuja con un proyecto elegido:
   en «Todos» no hay UN plano que abrir. Lo ven todos los roles operativos —el
   receptor tiene `ViewAny`/`View` de Proyecto y cobra desde el plano—.
3. **El Estado mensual** abría siempre en el proyecto más viejo. Ahora abre en
   el elegido, y sin elegir sigue como antes.

Y un cuarto que es consecuencia del primero: **cambiar de proyecto estando en
el PLANO de otro salta al plano del nuevo** (`SelectorDeProyecto::
updatedElegido`). La decisión la toma el navegador —la petición de Livewire no
sabe en qué ruta está la pestaña— comparando el `pathname` contra
`/proyectos/{id}/plano`. El resto de las pantallas se recargan y se recortan
solas. ⚠️ Ese patrón está escrito en el JS: si algún día cambia el `path` del
panel o el slug del recurso, el salto deja de dispararse y vuelve a ser una
recarga común — no rompe, pero deja de saltar.

Tests: cuatro nuevos en `SelectorDeProyectoTest`. El salto NO tiene
test propio —habría que afirmar sobre el JS que Livewire manda, y eso se rompe
con cada versión—; los tests viejos que hacen `set('elegido', …)` pasan por ese
código y lo cubren de que no reviente.

### 🔴 Los contadores también (§9.E6) — y esto Maya NO lo tiene

Mauricio, con LLU elegida y la lista de Ventas vacía: «no debería de aparecer
Ventas 95 si no son de ese proyecto». El 11-sep se recortaron los LISTADOS y
cuatro contadores quedaron contando la empresa entera:

| Contador | Antes | Ahora |
|---|---|---|
| Menú · Ventas (expedientes atrasados) | `Venta::query()` | `ProyectoActivo::recortar(...)` sobre la subconsulta de ventas |
| Menú · Apartados (vencidos) | `Compromiso::query()->vencidos()` | recortado por `compromisos.proyecto_id` |
| Menú · Prospectos (sin atender) | `Prospecto::query()->sinAtender()` | recortado por `prospectos.proyecto_id` |
| Pestañas de Lotes | `Lote::query()` | `LoteResource::getEloquentQuery()` — la misma del listado |

Ya estaban bien: «Por cobrar hoy» y las pestañas de Ventas y de Recibos, que
cuentan con el `getEloquentQuery()` de su resource.

La regla, para el próximo contador: **un badge cuenta con la MISMA consulta
que la lista a la que lleva.** El docblock de `ListLotes` ya lo decía desde
agosto y el código no lo cumplía; nadie lo vio porque con un solo proyecto
recortar y no recortar dan el mismo número.

⚠️ **Maya tiene el mismo bug** en los cuatro (su `VentaResource` es idéntico).
Cuando se toque Maya, son estos cuatro parches tal cual.

Tests: otros cuatro en `SelectorDeProyectoTest`, uno por contador.

### Cómo se trae algo de Maya (para la próxima)

El diálogo para conectar la carpeta de Maya a la sesión no llegó nunca (tres
intentos). Lo que funcionó: Mauricio corre un `cp` / `git show` que deja los
archivos de Maya en `storage/app/_analisis/maya/` —gitignoreado— y desde ahí se
comparan con `diff`. Los dos repos comparten historia, así que casi siempre el
`diff` de un archivo son SOLO los cambios que se buscan.

## 🔴 18-sep — El importador aprendió a leer planos dibujados con líneas sueltas

Llegaron dos planos de La Unión, Copán, del mismo ingeniero (Gerson Menjívar):
**COLONIA RÍO BLANCO** (`CRB`, 83 lotes, de Elder Dionel Pinto) y
**LOTIFICACIÓN LA UNIÓN** (`LLU`, 95 lotes, de Omar Leiva y Leo Mejía). Con el
importador de ese día entraban **cero lotes**.

### Las tres cosas que ese ingeniero hace distinto

1. **No hay un solo lote cerrado.** Todo está en la capa `0` —lotes, calles,
   cajetín y hasta el carrito de la sección típica—: el perímetro de la manzana
   es una polilínea ABIERTA y las divisiones son `LINE` sueltas.
2. **El número no trae letra** («1», «2», «3») y la manzana va en un texto
   aparte, «BLOQUE A».
3. **Seis áreas sin unidad** («A=447.08», «260.00»).

### Cómo quedó (producto, no parche)

- `ArmadorDeContornos` — las caras de un grafo plano: corta cada tramo donde
  otro lo toca, funde extremos a menos de **3 cm**, poda lo que cuelga y
  recorre. Uno de los dos planos tenía huecos de 5 a 10 mm; con tolerancia
  cero se fundían lotes vecinos.
- `LotesDeLineasSueltas` — **es lote la cara que tiene un número adentro**, y
  nada más. La manzana sale de la VECINDAD: los lotes que comparten lindero
  son una isla, y se llama como el «BLOQUE X» que cayó adentro. Y funde las
  dos mitades de un lote partido por una línea sobrante, **solo si juntas
  suman lo que dice el rótulo**.
- `OpcionesDeImportacion`: `armarContornos` y `capaDeAreas`, al FINAL de la
  firma. `PlanoDeclarado`: `lineasSueltas`, `capaDeAreas`, `manzanaSinNombre`.
- Un área sin unidad entra **solo si el dibujo la confirma** dentro de
  `Lote::TOLERANCIA_DE_AREA`; «17.40» no pasa por área de ningún lote.
- Aviso nuevo, para cualquier plano: los lotes cuyo dibujo contradice a su
  rótulo en más del 2 %, con nombre y los dos números.
- Panel: interruptor **«El plano está dibujado con líneas sueltas»** en
  Proyecto → Ver plano → Importar plano DXF.

### 🔴 Dos bugs viejos que destapó, y que NO eran de hoy

- **La capa «0» tumbaba el análisis.** PHP guarda como ENTERO toda clave de
  texto que parezca número, así que `$capas['0']` queda con la clave `0`, y con
  `strict_types` pasarla a `normalizar(string)` es un TypeError: un 500 al
  subir el archivo. Vivió escondido porque en los planos anteriores la «0» no
  tenía ni contornos ni textos. Las claves son `int|string` y se leen por
  `AnalisisDeDxf::nombre()`.
- **`importarDxf` no atajaba las excepciones del dominio.** El mensaje de «esa
  capa no tiene contornos» estaba escrito para el usuario y no lo leía nadie:
  salía un 500. Ahora sale como aviso rojo, y dice qué opción probar.

### Lo que dice cada plano, verificado contra el importador de producción

| | `CRB` | `LLU` |
|---|---|---|
| Lotes | **83** — A 17 · B 15 · C 14 · D 17 · E 20 | **95** — A 8 · B 21 · C 26 · D 24 · E 6 · F 6 · **G 4** |
| Área | **36,431.17 v²** = suma de los 83 rótulos | **34,578.87 v²** = suma de los 95 |
| Vara | **0.8350 m**, la del ingeniero (no 0.8359) | la misma |

⚠️ Los conteos salieron de leer los TEXTOS del archivo, no de un plano
impreso. Cuando llegue el PDF del ingeniero, se cotejan.

### Lo que hay que preguntarle al ingeniero

- **`LLU`, manzana G — PROVISIONAL.** Cuatro lotes de 1,109.88 v² numerados
  «1», «1», «1» y «1», sin nombre de manzana. Entraron como G-1…G-4 (norte a
  sur, oeste a este) por decisión de Mauricio, para ver el plano completo.
  **Esa letra y esos números los puso el sistema.** No se vende ninguno sin
  confirmarlos.
- **`CRB`, lote E-10.** El rótulo dice 312.00 v² y el dibujo mide 320.81
  (2.8 %). Entró con 312.00 y queda marcado como desalineado.

### Falta

1. Precio y planes de pago de los dos.
2. Mirar los dos mapas contra el PDF del ingeniero cuando llegue.
3. Altamira (`RAL`) y El Bambú (`REB`) se borraron de la base LOCAL a pedido
   de Mauricio («no es necesario»). Sus seeders, sus DXF y
   `PlanoDesdeDxfSeederTest` **siguen en el repo**: si también se van, esos 12
   tests se reescriben sobre los planos de La Unión.

```bash
herd php artisan db:seed --class="Database\Seeders\Clientes\ColoniaRioBlancoSeeder"
herd php artisan db:seed --class="Database\Seeders\Clientes\LotificacionLaUnionSeeder"
```

## 🔴 15-sep — Se fue el sello «COPIA» del recibo

«Eso de copias de impresión hay que quitarlas, no nos aporta en nada»
—Mauricio, mirando un recibo reimpreso en producción—.

### Por qué nació, y por qué el mostrador lo desmintió

El sello es del 6-ago y la razón era buena: dos papeles con el mismo número no
pueden hacerse pasar por dos cobros, que es justo lo que un correlativo viene
a evitar.

Lo que no se vio entonces es que **reimprimir es rutina**: se traba la
impresora, el papel sale torcido, el cliente pide otra copia. El sello rojo
convertía un papel perfectamente normal en uno que parece sospechoso, y eso
sale a manos del cliente.

### Qué se quitó, y qué NO

**Se fue lo que se MUESTRA**, en los tres lados donde salía:

- El sello `COPIA · N.ª impresión` del papel impreso, y su CSS.
- La columna «Impreso» de la pestaña de recibos del expediente —«nunca»,
  «original», «1 copia»— y su `withCount('impresiones')`.
- El renglón debajo del folio en el listado general —«sin imprimir», «N
  copias»— y su subquery `impresiones_count`.

**Se queda el REGISTRO.** `impresiones_de_recibo` se sigue escribiendo en cada
impresión, y el historial completo —quién y cuándo— sigue en la sección
«Impresiones» de la ficha del recibo, que es donde alguien iría a preguntar si
algún día hace falta. Borrar la tabla no ahorraba nada y sí era irreversible.

### ⚠️ Cuatro textos quedaban mintiendo

La descripción de esa sección decía literalmente «de la segunda vez en
adelante el papel dice COPIA», y tres docblocks —`Recibo::impresiones()`,
`ImpresionDeRecibo` y `ImprimirRecibosController`— afirmaban lo mismo.
Corregidos los cuatro: un comentario que describe un comportamiento que ya no
existe es peor que no tener comentario.

`esCopia()` y `numero_de_impresion` siguen existiendo —los usa el historial—
pero ya no marcan nada.

## 🔴 12-sep — Todas las tarjetas del Escritorio miden lo mismo

«No hay consistencia de tamaños, todos deberían tener el mismo tamaño, que se
vea súper empresarial y profesional» — Mauricio. Dos causas, y ninguna era el
CSS que ya estaba puesto.

### 1. Los ANCHOS: Filament elegía las columnas solo

`StatsOverviewWidget::getColumns()` decide por la CANTIDAD de cifras: menos de
tres, o un múltiplo que no deje resto 1, dan **tres** columnas; cuatro dan
**cuatro**. Con los cuatro tableros uno debajo del otro eso significaba que
«La caja de hoy» (tres cifras) tenía tarjetas más anchas que «El mes»
(cuatro), y que nada se alineaba en vertical.

Peor: el corte de caja dibuja un cuarto cuadro **solo los días que hubo
egreso**, así que el ancho de sus tarjetas cambiaba de un día para otro.

Los cuatro widgets ahora fijan `$columns = 4`. Un renglón con tres deja la
cuarta celda vacía, y eso se lee como una rejilla — no como un error.

⚠️ **El tipo va igual que en el padre: `int|array|null`.** PHP no deja
estrechar el tipo de una propiedad heredada, así que `int|array` es un error
FATAL al cargar la clase, no un aviso. Se encontró antes de entregarlo.

### 2. 🔴🔴 Las ALTURAS: tres intentos a ciegas, y la lección del día

El culpable es **`align-self: start` en `.fi-grid-col`**. Con eso cada celda
mide lo que mide su contenido, y un `height: 100%` más adentro no tiene contra
qué medir: es el 100 % de un padre que ya se encogió.

La cadena real, leída del DOM:

```
.fi-section-content.fi-grid   ← display: grid
  └ .fi-grid-col              ← ⚠️ align-self: start
      └ .fi-sc-component
          └ .fi-wi-stats-overview-stat
```

**Se intentó TRES veces adivinando esa cadena y las tres fallaron**, porque el
envoltorio no se llama `fi-grid-ctn` —esa clase existe en Filament, pero no en
este contexto—, así que el selector no casaba con nada. Cada intento costó
puerta, commit y despliegue.

Se resolvió abriendo pruebas en el navegador, leyendo el DOM y **midiendo**:
antes 161 · 141 · 141 · 141; con el arreglo puesto en vivo, 161 en las cuatro.
Recién ahí se escribió en el archivo.

⚠️ **LA REGLA QUE SALE DE ACA:** para cualquier cosa visual, mirar la pantalla
ANTES de escribir CSS. Inferir el HTML que genera un framework es adivinar, y
cada adivinanza cuesta un despliegue. El navegador estaba disponible desde el
principio.

El `min-height` es para el otro caso: un renglón de UNA sola cifra —«El
sistema»— no tiene contra quién estirarse. Con él, las doce tarjetas del
Escritorio miden lo mismo, estén donde estén.

De paso se acortaron los dos pies más largos, que eran los que estiraban el
renglón entero.

## 🔴 12-sep — El encabezado salía a media pantalla, y la propiedad estaba bien

«Eso que está seleccionado se ve muy feo» — Mauricio, con el encabezado
marcado en la captura.

Y no era el CSS. El bloque ocupaba **una de las dos columnas** del Escritorio,
así que la fecha se envolvía debajo del logo en vez de irse a la derecha y
medio ancho de pantalla quedaba vacío.

### La clase declaraba `columnSpan = 'full'`. Nadie lo leía.

`<x-filament-widgets::widget>` es el componente que aplica
`gridColumn($this->getColumnSpan())`. Sin él, la propiedad se declara y no
llega a ningún lado: el widget se dibuja con la colocación por defecto.

`EncabezadoDelEscritorio` es **el único widget de la casa que escribe su blade
a mano** — los otros cuatro extienden `StatsOverviewWidget`, que ya trae ese
envoltorio en su propia vista. Por eso es el único que podía olvidarlo, y por
eso no había forma de aprenderlo mirando a los demás.

Tiene test: `assertSee('fi-wi-widget')`. ⚠️ Si Filament renombra esa clase, lo
que hay que hacer NO es cambiar la cadena del test: es abrir el Escritorio y
mirar si el encabezado sigue ocupando el ancho completo.

## 🔴 11-sep, noche — El interruptor de proyecto, antes de que hagan falta tres

«Cuando carguemos otros proyectos —ya hablamos con la clienta y posiblemente
agreguemos otros dos en unos días o semanas» — Mauricio. Se hace **ahora**,
con un solo proyecto, porque hoy nada se puede romper: con un proyecto el
comportamiento nuevo y el viejo son idénticos.

### Qué se rompía con tres desarrollos

- **«104 lotes disponibles de 309»** sumando tres residenciales. No hay un
  cliente al que se le puedan ofrecer esos 104: están en tres desarrollos.
- **La lista de a quién llamar** mezclaría clientes de los tres, y quien cobra
  trabaja uno a la vez.
- **El encabezado** rotularía la pantalla con el nombre de la empresa mientras
  las cifras de abajo hablan de un proyecto.
- **«El proyecto»**, en singular, sobre la suma de tres.

### Cómo funciona

`SelectorDeProyecto` en `TOPBAR_START`: «Todos» y un renglón por proyecto. Lo
elegido vive en `ProyectoActivo`, que lo guarda en la **sesión**.

⚠️ **Con UN solo proyecto no se dibuja.** Un interruptor de una sola posición
es ruido en la barra, y esa es la situación de Praderas hasta que entren los
que vienen. Por eso hoy, en pantalla, no se ve nada nuevo.

### 🔴 POR QUE NO ES UN `addGlobalScope`

Un global scope filtraría todo solo, con una línea, y sería la peor decisión
del repo: **un recorte invisible sobre consultas de dinero es cómo se llega a
«el número está mal y nadie sabe por qué»**. Cada lugar que recorta lo dice en
su propia línea y `grep ProyectoActivo` los encuentra a todos.

De paso, eso resuelve gratis el caso que un global scope habría arruinado: los
comandos —`olympo:verificar-produccion`, `olympo:cuadrar-recibos`, los
seeders— corren sin sesión y ven **todo**. Un comando que cuadra la cartera no
puede estar mirando un pedazo.

### 🔴 EL CORTE DE CAJA **NO** SE RECORTA, Y ES A PROPOSITO

La gaveta es UNA. «En efectivo: es lo que tiene que estar en la caja al
cerrar» solo es verdad si suma lo que entró por los tres proyectos, porque los
billetes están todos en el mismo cajón. Recortarlo daría un número menor que
el efectivo real y quien cuenta encontraría de más, buscando un error que no
existe. Ese cuadro se divide por PERSONA —un receptor ve lo que cobró él—, que
es la división que sí corresponde a un arqueo. Tiene su test.

### Dos trampas del camino

- **`Recibo::delProyecto()` mira DOS caminos.** R13 admite `venta_id` en NULL
  mientras haya `compromiso_id`: es la seña de un apartado. Preguntar solo por
  la venta dejaría esas señas fuera —dinero que entró y no aparecería en
  ningún proyecto—, el mismo agujero que tapa el `COALESCE` de
  `ComoVanLosProyectos`.
- **`ProyectoActivo` memoriza en la INSTANCIA, no en un `static`.** La clase se
  resuelve del contenedor, que Pest rehace por test; un `static` sobreviviría
  de un test al siguiente y el segundo vería el proyecto del primero. Es el
  mismo molde que la memoria del modal de cobro del 9-sep.
- **Los nombres de proyecto salen en MAYUSCULAS** (mutador del 3-ago). Me lo
  comí DOS veces el mismo día, en este test y en el de
  `ComoVanLosProyectos`. Anotado en los dos.

### El segundo pase: los listados

Entró el mismo día. **Ventas, Recibos, Lotes, Apartados, Prospectos y
Bloques** respetan el interruptor.

**Va en `getEloquentQuery()` del Resource y NO en la tabla.** Así recorta
también la ficha, el buscador global y cualquier pantalla que salga de ese
recurso. Un listado recortado con una ficha que no lo está deja abrir por
búsqueda un expediente de otro proyecto, y ahí el interruptor mentiría.

**Recibos usa `Recibo::delProyecto()`, no `ProyectoActivo::recortar()`**, por
lo mismo de siempre: esa tabla no tiene `proyecto_id`. Tiene su test, y el
test arma el caso raro —una seña con `venta_id` en NULL— porque es el único
que distingue las dos implementaciones.

**Se quitaron los filtros de proyecto de Ventas y Lotes.** Dejar los dos era
peor que redundante: con un proyecto elegido arriba, ese desplegable seguiría
ofreciendo los otros, y elegir uno daría una tabla vacía sin decir por qué.

**Clientes queda global a propósito.** Una persona puede comprar en dos
desarrollos; recortarla escondería la mitad de su historia, y la ficha del
cliente es justamente donde uno va a ver todo lo suyo.

## 🔴 11-sep, noche — Lo que encontró MIRAR la pantalla, y no leer el código

Se desplegó el Escritorio nuevo a pruebas y Mauricio contestó: «se ve feo, no
tiene orden ni tamaños correctos, se deforma todo». Tenía razón en tres cosas
distintas, y ninguna de las tres la habría encontrado un test.

### 1. 🔴🔴 «Ya se recuperó, y sobra L. 7,810,997.00» sobre un proyecto SIN GASTOS

El peor de los tres, y no es visual. Con `invertido` en cero la resta da todo
lo cobrado, así que el cuadro declaraba un triunfo —en verde, con palomita—
sobre un proyecto donde **nadie había cargado un solo gasto todavía**.

La cifra estaba bien calculada. La conclusión era falsa. **Es la peor clase de
número en un tablero: uno que está bien y dice algo que no es cierto, porque
ese se cree.**

Ahora, sin gastos cargados, el cuadro dice «Sin gastos cargados» en gris, con
un pie que explica que no hay contra qué comparar; y «falta por cobrar» deja
de prometer un cierre. Tiene su test, y el test dice de dónde salió.

### 2. El número se partía en dos renglones

`L. 40,711,995.00` salía con el tamaño fijo de Filament —1.875rem— y en cuatro
columnas no entra: el navegador cortaba después de «L.» y dejaba el monto
abajo. **Una cifra partida en dos líneas no se lee como una cifra: se lee como
un error de la pantalla** — y es justo el número que hay que mirar.

`white-space: nowrap` y el tamaño con `clamp()`, que baja a 1.125rem en
columnas angostas y sube a 1.875rem cuando sobra lugar. Mejor una cifra más
chica y entera que una grande y rota.

### 3. Las tarjetas terminaban en escalera

El pie de cada cuadro tiene largo distinto —uno dice «en 73 expedientes» y
otro tres renglones—, así que cada tarjeta medía lo que medía su texto. Ahora
estiran todas a la altura de la más alta y el pie se apoya abajo con un
`margin-top: auto`, que es lo que hace que una fila se lea como una fila.

### De paso: «Viernes, 11 De Septiembre De 2026»

El `text-transform: capitalize` del encabezado ponía mayúscula en CADA palabra
—los «De» incluidos—, que es exactamente lo contrario de verse cuidado. Se
cambió por `::first-letter`, que es lo que pide el español.

### La lección, otra vez

Es la misma que el documento repite desde el 14-ago: **el bug más grave del
día no lo encontró ninguno de los 1,292 tests. Lo encontró abrir la pantalla.**
Los tests cuidaban que la aritmética cerrara, y cerraba. Lo que no podían ver
es que el resultado correcto estaba diciendo una mentira.

## 🔴🔴 11-sep, noche — LA CACHE DE COMPONENTES SE COMIO UN WIDGET ENTERO

El widget de costo contra ingreso se desplegó a pruebas y **no apareció**. El
`git pull` lo trajo, el archivo estaba en el servidor, el permiso existía y el
usuario lo tenía. El Escritorio simplemente no lo mostraba.

**Era `bootstrap/cache/filament/`**, del 27 de agosto. Filament guarda ahí la
lista de recursos, páginas y widgets que descubrió, y mientras ese archivo
exista `discoverWidgets()` **no vuelve a mirar la carpeta**. `config:cache`,
`route:cache` y `view:cache` no la tocan: es otra caché, con su propio comando.

Es el mismo síntoma que ya había mordido dos veces en agosto con los permisos
—R23 y pronto pago—: una función que se entrega, no falla, no tira error, no
sale en ningún log, y no está en la pantalla.

`docs/DESPLIEGUE.md` ahora lleva las dos líneas en la secuencia fija:

```bash
php8.5 artisan filament:clear-cached-components && php8.5 artisan filament:cache-components
php8.5 artisan olympo:sembrar-permisos
```

Las dos van en TODO despliegue, aunque la entrega «solo toque un blade».

## 🔴 11-sep, noche — El Escritorio dejó de saludar

«Ese bienvenido debería quitarse y hay que hacerlo más profesional y
empresarial» — Mauricio.

Tenía razón por algo concreto: el `AccountWidget` de fábrica ocupaba **el lugar
de más peso de la pantalla** —arriba del todo, ancho completo— para decir
«Bienvenida/o» y ofrecer un botón de salir que ya vive en el menú del usuario.

### Lo que entró

- `EncabezadoDelEscritorio` (`sort = -10`) — logo de la marca, el nombre del
  residencial de `config('app.name')`, la fecha larga en español, y el nombre y
  rol de quien entró **como pie de línea, no como titular**. La diferencia no
  es de estilo: un tablero que empieza con un saludo se lee como una aplicación
  personal; uno que empieza diciendo de qué residencial es y de qué día habla
  se lee como el sistema de una empresa.
- `AdminPanelProvider` — fuera `->widgets([AccountWidget::class])`.
- Título de sección en los cuatro cuadros de cifras: «El mes», «La caja de
  hoy», «El proyecto», «El sistema». Hasta hoy eran tres filas del mismo
  tamaño y el mismo peso, así que nada parecía más importante que lo demás.
  `StatsOverviewWidget` ya pinta `$heading`; no hubo que dibujar nada.
- El CSS va en `filament/tema-olympo`, como todo el chasis visual. **Ni una
  clase de Tailwind**: una clase que Vite no compiló no existe en el panel, y
  acá no hay build que la compile.

### ⚠️ Dos trampas que quedaron escritas en el código

- `config('app.name')` y **nunca** `env()`: con `config:cache` puesto —y en el
  servidor lo está— `env()` devuelve null y el encabezado saldría vacío.
- La lectura de la marca va con `try`/`catch`, igual que
  `AdminPanelProvider::brandingValue()`. Sin la tabla —instalación nueva,
  migración a medias— tiene que devolver null y dejar abrir el panel. **Un
  widget que lanza se lleva la página entera**, no solo su cuadro.

### Qué mirar en pruebas

El encabezado arriba del todo con el logo y el nombre del residencial, y
debajo los cuadros con su título de sección. Entrar con el receptor: el
encabezado **sí** lo ve —no dice ninguna cifra, es un rótulo—; el cuadro de
«El proyecto», no.

## 🔴 11-sep, tarde — Costo contra ingreso: el Escritorio ya resta

«Hoy hay que sumar a mano lo cobrado y lo gastado para saber cómo va el
proyecto» estaba anotado desde el 11-ago. Los dos números existían —los gastos
en la pestaña del proyecto, lo cobrado en el Escritorio— pero en pantallas
distintas, así que la resta la hacía alguien con una calculadora cuando se
acordaba.

Widget nuevo: **`ComoVanLosProyectos`** (`sort = 3`, debajo del arqueo del día).
Cuatro cuadros: Invertido · Recuperado · Falta por recuperar (o «Ya se
recuperó, y sobra») · Falta por cobrar.

### 🔴🔴 `Monto` NO ADMITE NEGATIVOS, Y ACÁ ESO CASI CUESTA EL ESCRITORIO

`Monto::restar()` **lanza** cuando el resultado daría menos de cero — es a
propósito, en este dominio el dinero nunca es negativo. Pero un proyecto que
todavía no recuperó lo invertido es el caso **normal**: es el estado de casi
cualquier lotificadora a mitad de plazo.

Escrito de la forma obvia —`recuperado->restar(invertido)`— este widget tumbaba
el Escritorio **el primer día que alguien cargara un gasto mayor que lo
cobrado**, que es el primer día. Y no solo el cuadro: un widget que revienta se
lleva la página entera.

Se encontró leyendo `Monto` antes de entregar, no corriendo la puerta: el error
habría salido en pantalla, no en un test, si el test no hubiera existido.

**La regla, y está escrita en el docblock de la clase:** cada resta va siempre
del mayor al menor, se pregunta antes con `menorQue()`, y **el signo lo pone el
rótulo**, no el número. Son cuatro lugares: el resultado total, el desglose por
proyecto, «si entra todo» y el empate.

### Por qué es un widget aparte y no cuatro stats más en `ComoVaElNegocio`

1. `ComoVaElNegocio` lo ve **quien puede ver expedientes**, y eso incluye al
   receptor. Cuánto costó el desarrollo y cuánto se lleva recuperado es
   información del dueño, no de la ventanilla. Este se cuelga de
   `ViewAny:Gasto`, que es el permiso que ya separa esa frontera.
2. El propio docblock de `ComoVaElNegocio` dice «son cuatro y no diez a
   propósito: un tablero con veinte cifras no se lee, se ignora».

### Esto es CAJA, no utilidad contable

Un proyecto recién comprado y sin vender sale en rojo, y **está bien**: la
pregunta que contesta es «¿ya recuperé lo que puse?», no «¿cuánta utilidad
devengué?». Para lo segundo hay que repartir el costo del terreno entre los
lotes vendidos y los que no, que es otra cuenta y pide un contador.

Por eso existe el cuarto cuadro: sin «falta por cobrar», un proyecto sano a
mitad de plazo se ve igual que uno que no vendió nada — los dos en rojo, porque
los dos gastaron más de lo que cobraron.

### Las decisiones de qué cuenta y qué no

- **Las entregas a socios NO son costo**, y eso ya estaba decidido: lo dice
  `EntregaASocio` —«un gasto es lo que el desarrollo costó y se resta antes de
  saber cuánto hay para repartir; esto sale de esa utilidad ya calculada»—.
  Sumarlas lo restaría dos veces.
- **Las devoluciones SÍ se restan** de lo recuperado: es dinero que volvió al
  cliente. Misma cuenta que hace `CorteDeCajaDeHoy` con el egreso del día.
- **Los recibos anulados no cuentan**, igual que en `ComoVaElNegocio`.
- **Las cuotas de lotes rescindidos tampoco**: `deLotesVivos()`. Esa cuota no se
  va a pagar nunca, y contarla prometería un dinero que ya no va a entrar.

### ⚠️ El recibo de una seña no cuelga de una venta

R13 (`recibos_cuelgan_de_un_compromiso_chk`) admite `venta_id` en NULL mientras
haya `compromiso_id`: es la seña de un apartado, que todavía no tiene contrato.
Un `join` contra `ventas` se las comería en silencio —dinero que entró y no
aparecería en ningún proyecto—, así que el proyecto sale de
`COALESCE(ventas.proyecto_id, compromisos.proyecto_id)`. Lo mismo en
`devoluciones`, que tiene las dos columnas igual de nullables.

### Qué mirar en pruebas

Entrar al Escritorio con la administradora: el cuadro nuevo aparece debajo del
arqueo. Con un solo proyecto el desglose dice su nombre; con dos, el saldo de
cada uno. Entrar con el receptor: **no tiene que aparecer**.

## 🔴 11-sep, tarde — El PRONTO PAGO ya se anula: era el último sin vuelta atrás

Mauricio preguntó qué más convenía mejorar, y de la lista eligió tres. Esta es la
primera.

Por la mañana se abrió la anulación del abono a capital, y eso abrió el pronto
pago también sin querer —comparten el concepto `AbonoCapital`—. Lo agarró
`ProntoPagoTest` en la primera corrida y se cerró con una puerta que miraba
`Recibo::tuvoDescuento()`. Esa puerta ya no está: lo que le faltaba a `anular()`
ahora existe.

### 🔴 Lo que faltaba era devolver el PERDÓN, no el dinero

`saldarConDescuento()` sube `cuotas.monto_pagado` hasta el total de la cuota: el
dinero que entró **más** lo condonado. El bucle de `anular()` restaba solo
`monto_capital + monto_interes` —el dinero—, así que la cuota se quedaba
diciendo que todavía tenía pagado el descuento.

Y eso **no se ve en pantalla**: el expediente queda cuadrado consigo mismo,
debiendo de menos, con el recibo que explicaba la rebaja marcado como anulado.
Es la clase de error que solo aparece el día que el cliente llega con su papel.

Ahora el bucle resta las dos cosas y pone `cuotas.capital_condonado` de vuelta.

### 🔴🔴 LAS DOS COLUMNAS VAN EN EL MISMO `UPDATE`, Y NO ES ESTILO

`cuotas_condonado_cabe_en_lo_pagado_chk` exige `capital_condonado <=
monto_pagado`, y un CHECK de Postgres se evalúa **por sentencia**. Bajar
`monto_pagado` en un `update()` y `capital_condonado` en otro deja un instante
donde el perdón es mayor que lo pagado: la primera sentencia revienta y la
anulación entera se cae.

Cuota de 1,000 con 600 de dinero y 400 de perdón: `monto_pagado` baja a 0
mientras `capital_condonado` todavía vale 400. 400 <= 0 es falso.

Por eso esto **no** sigue el molde de `revertirLaCondonacion()`, que sí es un
método aparte: `mora_condonada` no tiene CHECK cruzado contra `mora_pagada`. Si
alguien «ordena» esto sacándolo a su propio método, lo rompe.

### Lo que NO hay que deshacer

Un pronto pago **no reprograma nada**: `saldarConDescuento()` no borra ni crea
cuotas, deja las que había en cero. Así que `deshacerLasReprogramaciones()` no
encuentra constancia y no hace nada. **Anular un pronto pago es más simple que
anular un abono**, no más complicado — y esa asimetría confunde si no se dice.

### Lo que entró

- `RegistroDePagos::anular()` — se fue la puerta de `tuvoDescuento()`; el bucle
  devuelve el capital condonado junto con el dinero, en un solo `update()`.
- `RegistroDePagos::asentarQueVolvioElDescuento()` — asiento `pronto_pago_anulado`
  contra la VENTA, con motivo y folio. El descuento se asentó ahí al darlo;
  devolverlo tenía que dejar rastro en el mismo lugar, o «Actualizaciones»
  seguiría diciendo que a ese cliente se le descontaron esos lempiras.
- `PagoInvalidoException::porProntoPagoQueNoSeAnula()` — **borrada**.
- `RecibosTable::loQueAdemasPasa()` — el aviso del modal ahora tiene tres textos:
  pronto pago (el perdón se revierte), abono a capital (vuelve el plan viejo) y
  cuota corriente (nada extra). ⚠️ El pronto pago se reconoce por el capital
  condonado y **no** por el concepto: sale como `AbonoCapital` igual que el abono,
  y preguntar por el concepto le daría el texto del plan a un papel que no
  reprogramó nada.
- `ProntoPagoTest` — el test «un pronto pago no se anula» se convirtió en el
  describe «Anular el pronto pago», con cinco: vuelve el dinero y vuelve el
  descuento, el lote de al lado no se toca, el expediente liquidado vuelve a
  vigente, el asiento queda con su motivo, y sin descuento se anula igual y sin
  asiento.

### Qué mirar en pruebas

Un expediente con dos lotes. Pronto pago de uno con descuento → el lote queda
saldado. Anular ese recibo → el saldo del lote vuelve **al número exacto** de
antes, no a uno parecido; el otro lote no se movió; y si el expediente se había
liquidado, vuelve a decir «Vigente» con su botón de cobrar.

### Lo que sigue de esta tanda

1. Costo contra ingreso en el Escritorio — widget aparte, solo para quien ve
   gastos. `ComoVaElNegocio` no se toca: lo ve el receptor, y el margen no es
   información de ventanilla.
2. Pago mixto en los cobros. Solo `recibos`; gastos y devoluciones siguen con
   una sola forma.

## 🔴 11-sep — El abono a capital YA SE ANULA

«Ocurrió lo que temíamos: se equivocó y era de otra manera el hacer los pagos de
cuota o abono a capital, y quiere cancelar recibos (…) muy seguramente volverá a
pasar» — Mauricio.

Los tres errores que trae la ventanilla —el monto mal, el abono que era cuota, la
cuota que era abono— se arreglan todos igual: **anular y volver a cobrar bien**.
Lo que faltaba era poder anular el abono, que hasta el 9-sep se rechazaba.

⚠️ **«Editar el recibo» no sirve para esto y no se construyó.** Cambiar un recibo
de cuota a abono a capital no es corregir un campo: el abono reescribe el plan de
cuotas del lote y la cuota no toca nada de eso. Son dos movimientos con efectos
distintos sobre la deuda, y deshacer uno y hacer el otro deja rastro de los dos —
que es lo que hace falta cuando alguien pregunta seis meses después.

### Cómo funciona

Cada reprogramación guardaba ya el plan viejo entero: `plan_anterior` tiene las
cuotas que borró —número, vencimiento y monto— y `desde_numero` dice desde dónde
reescribió. Deshacer es borrar las cuotas que el abono creó y volver a escribir
las que borró (`RegistroDePagos::deshacerLasReprogramaciones()`).

### 🔴 Solo si es el ULTIMO movimiento del lote

Si después del abono se cobró una cuota del plan nuevo, o hubo otro abono encima,
se rechaza **nombrando los folios** que hay que anular primero, del más nuevo al
más viejo. No es un callejón: hay un test que prueba que deshaciendo en orden se
llega igual al plan original.

Se descartó anular en cadena: un clic tumbaría cinco papeles y algunos estaban
bien —el cliente sí pagó su cuota de septiembre—, reemitirlos cuesta más de lo que
la cadena ahorra, y cada reemisión quema otro correlativo.

### 🔴🔴 EL PRONTO PAGO SALE CON CONCEPTO `AbonoCapital` Y NO ES LO MISMO

Abrir el abono le abrió la puerta al pronto pago **sin querer**, porque comparten
concepto. Lo agarró `ProntoPagoTest` en la primera corrida.

No era un detalle: un pronto pago **perdona saldo**. `anular()` sabe devolver la
mora condonada, no el capital perdonado — el descuento habría quedado regalado con
el recibo que lo explicaba marcado como anulado. La puerta ahora mira
`Recibo::tuvoDescuento()` —el capital condonado— y no el concepto, que es lo que
de verdad los separa. Revertir un descuento sigue sin existir.

⚠️ **La constancia de la reprogramación SE BORRA**, y es lo único de este repo que
se borra en vez de marcarse. La regla de `Reprogramacion` —«es historia, no se
edita ni se borra»— vale para una reprogramación que OCURRIO. Esta no ocurrió: el
plan volvió a ser el de antes, y una constancia que siga diciendo «tu cuota cambió
por este abono» le mentiría al estado de cuenta, que se reconstruye leyendo esas
filas. Lo que pasó queda en el recibo anulado, con su motivo y quién lo anuló.

⚠️ **Con interés no se puede**: `plan_anterior` guarda el monto de cada cuota pero
no cuánto era capital y cuánto interés, y repartirlo a ojo es inventar el plan del
cliente. En Praderas no pasa (R1, sin interés) y se rechaza con su mensaje.

## 🔴 11-sep — Activos y anulados separados, y la señal del cobro

«Esos anulados deben de estar en un toggle que sea activos y anulados para
cambiar entre ellos y que no se amontonen» — Mauricio, el mismo día en que
anular empezó a usarse de verdad.

Tiene razón por una razón que no se veía hasta hoy: hasta el 11-sep un recibo
anulado era raro, y ahora un cobro mal registrado tacha CUATRO papeles de una
vez. `ListRecibos` gana pestañas —**Activos** (por defecto), **Anulados** con el
conteo, **Todos**— y el filtro ternario que hacía lo mismo escondido en el
embudo se fue.

⚠️ Se pierde algo, y se acepta: la lista mostraba TODO sin filtrar a propósito,
porque «la búsqueda es por número y quien llega con el papel tiene que
encontrarlo». Con «Activos» por defecto, un folio ANULADO no aparece buscándolo.
Por eso «Todos» no es decorativa —es dónde se busca— y por eso «Anulados» lleva
el conteo a la vista.

⚠️ Y `ListadoDelCliente::recibos()` ahora pasa `'tab' => ListRecibos::TODOS`,
por lo mismo que `ventas()` desde el 22-ago: el contador de la ficha cuenta
todos, y sin eso el cliente con un recibo anulado muestra «Recibos 3» y al
entrar aparecen dos (§9.E6).

### 🔴🔴 EL PARAMETRO DE `modifyQueryUsing` SE LLAMA `$query`

**Costó CUATRO vueltas de la puerta.** `Tab::modifyQuery()` inyecta el builder
POR NOMBRE —pasa `['query' => $query]`— y usa el valor de retorno como query de
la tabla. Se escribió `$consulta`: Filament no lo encontró por nombre, cayó a
resolverlo por TIPO y entregó otro builder, uno **sin modelo**. Ese huérfano pasó
a ser el query de la tabla.

Y el síntoma nunca apuntó acá:

1. Primero culpó a `withCount('impresiones')`, que llevaba dos semanas sin
   tocarse — `withCount` resuelve la relación con `$query->getModel()->…`.
2. Al quitarlo, culpó a los filtros (`where(Closure)` hace
   `$this->model->newQueryWithoutRelationships()`).

Se mueve porque la causa está arriba de los dos. La comparación con `ListVentas`
—que tiene pestañas desde el 22-ago y usa `$query`— era el primer lugar donde
había que mirar.

⚠️ De rebote quedó una regla que conviene respetar igual: los dos conteos de
`RecibosTable` son **subqueries escritos a mano** y no `withCount()`. Funcionan
aunque el builder no traiga modelo, y el aviso está arriba del closure.

### La señal de que un papel no vino solo

Se propuso mostrar el cobro de varios recibos como UNA fila. Se descartó, y está
escrito en `RecibosTable::conCuantosSalio()`: rompía el libro de correlativos
(R12), la búsqueda por número y la línea de cada titular. En su lugar, cada fila
dice debajo del folio **«4 papeles del mismo cobro»**.

⚠️ Se cuenta con un subquery que se cuenta a sí mismo, para que se lea como se
habla. Un `emision_id` en null no cuenta ni consigo mismo —en SQL `null = null`
no es verdadero— así que los recibos de antes del 11-sep y los cobros de un solo
papel no dicen nada, que es el 99 % de las filas.

## 🔴 11-sep — Anular TODO el cobro, cuando salió en varios papeles

«Cuando tiene más de un titular de recibo y a cada uno se le hizo un abono o
pago de cuota y generó varios recibos, ¿cómo se maneja eso?» — Mauricio, el
mismo día y unas horas después.

Se podían anular de a uno —cada papel toca sus propios lotes, así que **no se
estorban entre sí**— y ese era justamente el problema: cuatro veces el mismo
trámite, cuatro veces el motivo, y quien anula tres y se olvida del cuarto deja
el expediente a medias sin que nadie se entere hasta que no cuadra el mes.

### La columna que faltaba

Hasta hoy los papeles hermanos no tenían NADA que dijera que salieron juntos:
compartían contrato, fecha y quién los emitió, que es exactamente lo que también
comparten un cobro de la mañana y otro de la tarde. `recibos.emision_id` lo
resuelve, y se llena en `RegistroDePagos::marcarLaEmision()`.

⚠️ **Solo cuando el cobro sale en VARIOS papeles.** Un recibo solo no salió
«junto» con nadie, y darle emisión propia haría que la pantalla ofrezca «anular
todo el cobro» para anular exactamente uno.

⚠️ **Nullable, y se queda así.** Lo emitido antes del 11-sep no tiene emisión y
no se le puede inventar — no hay forma de saber cuáles salieron juntos—. Esos se
siguen anulando de a uno. Rellenarlo a ojo sería escribir un dato falso en la
base para que una pantalla se vea más completa.

### 🔴 Todos o ninguno

`anularElCobro()` es una sola transacción. Si uno de los cuatro no se puede
anular —porque después entró un cobro sobre su lote— se cae la operación entera
y no se anula ninguno. Media anulación deja el contrato en un estado que no es
ni el de antes ni el de después, y que nadie pidió. El test verifica el ESTADO
después del error, no solo que lance: una transacción mal puesta lanzaría igual
habiendo dejado el primero anulado.

### ⚠️ La asimetría es a propósito

Anular una CUOTA no exige ser el último movimiento del lote; anular un ABONO sí.
No es un olvido: la cuota no devuelve ningún plan, devuelve lo pagado a cuotas
que siguen existiendo —una cuota con pago nunca se reemplaza, por el tope de
`EfectoDelAbono`— así que un abono posterior no la estorba.

Se descubrió probando: el primer intento de test puso un abono después de un
cobro de cuotas esperando que lo bloqueara, y no lo bloqueó. Queda escrito en
`anular()` para que nadie lo «arregle».

## 🔴 9-sep-2026 — lo anterior

> Se lee esto y `docs/dominio.md` antes de proponer nada. La puerta es
> `herd composer rector:fix && herd composer lint && herd composer ci && herd composer rector`.

## 🔴 9-sep — El diálogo de impresión sale solo al cobrar

«Al pagar debería de abrirse de una la ventana para imprimir los recibos»
— Mauricio, mirando **cuatro** notificaciones apiladas después de UN cobro.

Un cobro sale en un recibo por titular, así que el contrato con varios
representados emite varios papeles de un solo pago: cuatro clics y cuatro
diálogos que cerrar, con el cliente enfrente.

### 🔴 Las dos trampas de esto, y son las dos que importan

**No se abre una ventana.** Un `window.open()` que no nace de un clic —y este
nace de una respuesta de Livewire— es exactamente lo que Chrome bloquea, y
bloquearlo se ve como una barrita arriba que nadie mira: quedaría PEOR que el
botón, porque además nadie se enteraría. Se usa `window.olympoImprimir()`, que
ya existía desde el 14-ago: carga el documento en un iframe escondido y manda a
imprimir ahí. Eso no es un pop-up, así que no hay nada que bloquear.

**Una sola llamada, siempre.** `olympoImprimir()` tiene UN iframe y lo reemplaza
en cada llamada: llamarla una vez por recibo no imprime cuatro papeles, imprime
el último —o una hoja en blanco—. Por eso nació `documentos.recibos`, que apila
**una hoja por recibo** en un solo documento. Dos titulares nunca comparten
hoja: es el papel de otra persona.

### Lo que se movió

- `PapelDelRecibo` (nuevo, en `app/Domain/Documentos`) prepara los datos de una
  hoja. Salió de `ImprimirReciboController` porque ahora los comparten los dos
  documentos; preparados en dos lugares se separan solos.
- `documentos/partes/recibo-estilos.blade.php` y `recibo-hoja.blade.php`: el CSS
  y el cuerpo, para que el documento de varios los repita sin copiarlos.
- `ImprimirRecibosController` + ruta `documentos.recibos?recibos=12,13,14`.
  **Sirve igual para uno**, a propósito: quien cobra no decide nada según
  cuántos salieron.
- `AbrirLaImpresion::de($url, $pantalla)` manda el JS; `ImprimirRecibo::alEmitir()`
  es su versión para recibos. `CobrarUnPago` recibe la pantalla por constructor.
- El acta de rescisión también sale sola, y su botón dejó de abrir una pestaña.

⚠️ **El permiso se pregunta por TODOS antes de preparar ninguno.** Autorizar
sobre la marcha dejaría anotada la impresión de los primeros y recién ahí
cortaría: filas escritas por un documento que nunca se entregó, y papeles que
desde entonces dicen COPIA sin que nadie los haya impreso.

⚠️ **Los botones de las notificaciones se quedan.** Esto es JavaScript, y el
papel que el cliente espera del otro lado del mostrador no puede depender de que
el JavaScript haya cargado.

### Lo que NO entró

La **prima al firmar** no se auto-imprime: `CreateVenta` redirige al expediente
cuando termina, y un `js()` no sobrevive a la redirección. Hacerlo pide otra
cosa —guardar el pendiente en sesión y dispararlo al montar la página—, y eso
merece su propio pase, no un agregado al final de este.

## 🔴 9-sep — El titular de recibo de CADA lote, y el segundo que se tardaba

«Acá que aparezca a qué titular de recibo sale, para que se tenga en cuenta al
pagar; si es el mismo en todos o no hay configurado titular de recibos entonces
sí que se vea así. Además se tarda como un segundo o más en contestar al dar clic
en algún check, hay que mejorar eso también, pero **lo importante que diga quién
es el titular de cada lote para que sepa que se está pagando**» — Mauricio,
mirando el expediente 0085 recién cuadrado.

### 🔴 Lo primero no era cosmético: la pantalla decía lo contrario

`RegistroDePagos::agruparPorNombre()` parte el cobro en **un recibo por
titular**. Dos lotes con titulares distintos salen en dos papeles, con dos
correlativos. Eso ya era así desde el 13-ago y estaba bien; lo que faltaba era
que alguien pudiera saberlo **antes** de apretar el botón.

Y el aviso de arriba afirmaba lo contrario, en singular y sin condiciones:
«El recibo los cubre a todos». Verdad en el caso común, mentira justo en el caso
que ese aviso existe para cubrir. Quien cobraba marcaba cinco casillas creyendo
que emitía un papel, y se enteraba al ver las notificaciones —con los papeles ya
emitidos—.

Ahora:

1. **Cada renglón de lote lleva una pastilla** «recibo a NOMBRE»
   (`CobrarUnPago::aQuienSaleElPapel()`), en cuotas, en abono y en el reparto del
   sobrante. Es una pastilla y no un tercer renglón gris porque la pregunta que
   contesta no es «cuánto» sino «¿son la misma persona o no?», y esa se contesta
   comparando de un vistazo, no leyendo.
2. **Solo cuando el contrato tiene más de un titular.** Con uno solo el dato no
   decide nada y sería la advertencia permanente que se deja de leer. Es el
   pedido textual: «si es el mismo en todos… entonces sí que se vea así».
3. **El lote sin titular configurado también se nombra**, con el dueño del
   expediente. Dejarlo en blanco al lado de uno con nombre haría preguntar si el
   blanco es un error de carga —y para `agruparPorNombre()` «el dueño» es un
   titular tan distinto como cualquier otro: también se lleva su propio recibo.
4. **El aviso del contrato dice cuántos papeles salen**
   (`cuantosPapelesSalen()`), en vez de prometer uno solo.

⚠️ El conjunto que decide es el de los lotes **con saldo**, no todos los del
contrato: un lote ya pagado no entra en ningún cobro de hoy, así que su titular
no cambia cuántos recibos salen. Por eso el aviso de arriba y las etiquetas de
abajo nunca se contradicen.

⚠️ **El renglón del sobrante arma su etiqueta aparte**, y hubo que tocarlo dos
veces. No repite la cuota ni el saldo a propósito —son cifras que ya están
arriba, y releerlas para descubrir que son las mismas es trabajo de más—, pero
sí repite el titular: la cifra es un dato que se compara con lo que se teclea, y
el titular es la IDENTIDAD del renglón. «En "cómo se reparte" no dice de quién
es» —Mauricio, con la primera versión ya en producción—. Y el «ya está arriba»
no le alcanza a este dato: esa sección queda lo bastante abajo como para que
«¿qué viene a pagar?» salga de la pantalla. Un nombre que hay que ir a buscar
con scroll, con el cliente enfrente, no está.

### 🔴 El segundo que se tardaba era la base de datos, no el navegador

Cada casilla es `->live()`, así que un clic vuelve a armar el schema **entero**.
Y armarlo llamaba a `lotesQueDeben()` una docena de veces —los renglones de
cuota, los de abono, los de pronto pago, los del sobrante y cada
previsualización—, y `lotesQueDeben()` preguntaba el saldo **lote por lote, con
una consulta nueva cada vez**. Sumando `pendientesDe()`, que hacía lo mismo en
cuatro lugares más, un solo clic pasaba de cien consultas en el contrato de cinco
lotes.

### 🔴 La memoria va en los MODELOS, no en la instancia

⚠️ **`CobrarUnPago` es `final readonly`: no puede guardar nada en una
propiedad.** El primer intento agregó cuatro propiedades memo y PHPStan lo
rechazó con doce errores (`readOnlyDefaultValue` y `readOnlyAssignNotInConstructor`).
No hay que volver a intentarlo: la memoria correcta no es esa.

1. **Las cuotas se cargan sobre los modelos** (`compromisosEnOrden()` hace UN
   `loadMissing(['compromisos.lote', 'compromisos.cuotas'])`), y esos modelos se
   comparten: `$record` es el mismo objeto durante todo el request aunque
   `new self($record)` se construya tres veces —`fillForm()`, `schema()` y
   `->action()`—. La consulta ocurre **una vez por request**, no una por
   instancia. Por eso funciona sin propiedades.
2. **`quienPaga()` va por la relación `titulares`** y no por `titular()`, que
   —lo dice su propio docblock— consulta cada vez que se lo llama. Ahora lo
   pregunta cada renglón de lote.
3. **Lo que se recalcula** —ordenar, filtrar, sumar saldos— es aritmética en
   memoria sobre un puñado de modelos. Eso nunca fue el problema.

`pendientesDe()` filtra la relación ya cargada en vez de consultar; el filtro es
el mismo que hacía el SQL, porque `saldo()` es `monto - monto_pagado`.

⚠️ **Quien lea esas cuotas no puede modificarlas**: son los mismos objetos que ve
el resto de la pantalla. `comoQuedanTrasCobrar()` ya lo respeta —proyecta sobre
un `clone`— y así tiene que seguir. `EfectoDelAbono` no toca ninguna, se
verificó.

⚠️ **Las relaciones se sueltan al final de `registrar()`** (`olvidarLoCargado()`:
`unsetRelation('compromisos')` y `unsetRelation('titulares')`), y al final y no
antes de los avisos: los avisos hablan del cobro que acaba de pasar, y para eso
los lotes que valen son los que estaban marcados en la pantalla. Leído después de
escribir, un lote que el abono terminó de pagar ya no está en `lotesQueDeben()` y
su recibo se anunciaría como una cuota común.

El guardián es `tests/Feature/Filament/TitularDeCadaLoteTest.php`: cuenta solo las
consultas a `cuotas` al abrir el modal —no todas las del request, que las mueve
cualquier versión de Filament—. Lo que se cuida es que ese número **no crezca con
los lotes**.

## 🔴 8-sep — «Ambas»: el sobrante se reparte entre los lotes

«En "a qué lote va el sobrante" agreguemos que se pueda elegir cuánto va a cada
lote o repartir en partes iguales, **ya que es algo que la dueña dijo que sí
necesitaba**» — Mauricio.

Hasta hoy el sobrante de «Ambas» iba contra UN lote, elegido en un Select. Con
dos lotes en el mismo contrato eso obligaba a partir el pago en dos recibos para
bajarle capital a los dos.

⚠️ **Había una decisión escrita en contra**, en `CobrarUnPago` y en
`docs/dominio.md`: «Ambas sigue contra UN lote… no es una simplificación
pendiente». La pidió la contratante, así que se cambió y se anotó por qué en los
dos lugares.

### Lo que cambió

1. **El Select murió.** En su lugar van los MISMOS renglones de «Abono a
   capital» —marcar, elegir qué pasa con lo que falta, y (solo a mano) escribir
   cuánto—. Mandar todo a un lote es marcar uno solo, **que es como abre el
   formulario**: quien ya usaba la pantalla no tiene que aprender nada.
2. **Un interruptor «Partes iguales» / «Yo escribo cuánto a cada uno»**, que se
   esconde cuando el contrato tiene un solo lote.
3. **La modalidad es por lote**, como en «Abono a capital» (R21: los dos caminos
   los elige el cliente).
4. **El dominio**: `cobrarYAbonar()` y `cobrarYAbonarEnUnMismoNombre()` reciben
   `$abonos` —una lista de `{lote, monto, modalidad}`— en vez de
   `$loteDelAbono` + `$aCapital` + `$modalidad`. Cada abono se relee bloqueado
   **después** del cobro, uno por uno y en orden de id. Si el tercero no llega a
   bajar capital, la transacción se cae y **los dos primeros tampoco se abonan**.

### 🔴 Los dos bordes que hay que entender antes de tocar esto

**El recibo sale por lo aplicado, no por el «Monto total recibido».** Un reparto
manual que no suma el sobrante entero sacaría un papel más chico que el billete,
y **nadie se enteraría**: el recibo cuadra consigo mismo, así que
`olympo:cuadrar-recibos` no lo ve. Por eso `elRepartoQueNoCuadra()` lo corta
antes de guardar, con el número que falta, y la previsualización ya lo avisa
mientras se teclea. El corte vive en la pantalla porque **es el único lugar donde
están los dos números**: el dominio no sabe cuánto entregó el cliente.

**El centavo del residuo tiene dueño.** «Partes iguales» divide `enCentavos()`
con `intdiv()` y reparte el resto de a un centavo entre los primeros **por id
ascendente** —el mismo orden con el que el dominio toma los candados—. Dividir y
redondear perdería el centavo, que es un recibo que cobró de más en chiquito. Es
la lección del `FOR UPDATE` sin `ORDER BY` del 27-ago.

### Lo que salió de mirarlo en pantalla, el mismo día

**1. El modal era un muro** («se ve muy engorroso a la vista»). La modalidad
se pregunta una vez POR LOTE, y con el `Radio` de dos opciones largas eran
siete líneas de texto repetido por lote. Pasa a un segmentado corto
—`.olympo-modo-fino`, el mismo riel del toggle de arriba en chico y a la
izquierda— y la explicación se dice UNA vez en la descripción de la sección.
Lo mismo en «Abono a capital», que tenía el mismo muro.

**2. El renglón de un lote gana jerarquía.** Era `RPS-D-003 — debe L. 230,000.00`,
todo del mismo peso. Ahora el código va solo y en negrita, y debajo en gris
`cuota X · saldo Y`: el saldo total no es el número que se usa —quien atiende
necesita la cuota del mes— y el código estaba compitiendo con una cifra de seis
dígitos. En «Ambas» el renglón del sobrante ya no repite el saldo, porque ese
lote aparece tres centímetros más arriba.

⚠️ El CSS vive en `tema-olympo.blade.php` y entra por `renderHook`: **no lleva
build**.

**3. 🔴 La nota del abono se contradecía con su propia tabla.** `notaDelAbono()`
caía a «termina el mismo mes, pagando menos cada mes» siempre que
`mesesAhorrados()` daba cero. Pero `AcortarPlazo` arma el plan con
`porCuotaFija()`: **la cuota no baja nunca**. La pantalla decía
«Cuota L 5,000.00 → L 5,000.00» y dos renglones abajo prometía pagar menos.
Ahora se ramifica por modalidad. Es un bug viejo, pero repartir el sobrante lo
hace mucho más frecuente: a cada lote le toca una fracción, así que quitar un
mes entero es más difícil.

**4. El recibo dice cuánto a qué lote.** `Recibo::capitalPorLote()` sale de las
CONSTANCIAS —una fila por lote con lo que se le abonó—, no de una cuenta. El
papel imprime un renglón por lote (`RPS-D-003 · Abono a capital`) en vez de uno
solo, y la ficha del panel usa el mismo criterio; de paso la ficha pone el
código adelante de cada cuota, porque cada plan numera desde 1 y «Cuota 3» dos
veces no decía nada.

🔴 `capitalPorLote()` devuelve **vacío** si no cuadra contra `montoACapital()`
—que es una resta sobre el total del papel, otra fuente— y ahí el recibo vuelve
al renglón único. Es preferible menos detalle que partes que no sumen el total
impreso abajo: un cliente que suma con el dedo y no le da tiene razón en
desconfiar del papel entero.

### Qué mirar en pruebas

Un expediente de **dos lotes** → «Registrar un pago» → **Ambas**. Escribí un
total que sobre, marcá las cuotas, y abajo marcá los dos lotes: la
previsualización tiene que decir «Baja el capital de RPS-…» **una línea por
lote**, con la mitad cada una. Después pasá a «Yo escribo cuánto», poné números
que NO sumen el sobrante y mirá el aviso; guardar tiene que estar bloqueado.
Y por último dejá marcado un solo lote: es el comportamiento de siempre.

Después **imprimí el recibo**: tiene que traer un renglón `CÓDIGO · Abono a
capital` por cada lote, con su monto, y los cuatro sumando el total recibido.

## 🔴 4-sep — «Corregir»: editar un recibo sin poder tocar el dinero

**Salió de producción.** El recibo **RPS-00000022** —una PRIMA del 29-ago, de
EVELYN JANETH CRUZ MOLINA— quedó con `recibido_por` en **NULL**: nació antes de
que la prima preguntara quién recibía el dinero (ver 31-ago, más abajo), así que
el corte de caja lo sumaba bajo «Sin usuario». Hubo que entrar por SSH a
producción y arreglarlo a mano con `artisan tinker`.

⚠️ **El tinker de `www-data` no arranca solo.** `HOME=/var/www` no es
escribible, psysh intenta escribir `/var/www/.config/psysh`, avisa y **muere sin
ejecutar nada** — parece que corrió y no corrió. Va con `env` adelante:

    sudo -u www-data env HOME=/tmp XDG_CONFIG_HOME=/tmp /usr/bin/php8.5 artisan tinker --execute="..."

**Y de ahí el pedido:** «que pueda editar los recibos la administradora, solo
los recibos» — Mauricio.

### La decisión: editar un recibo son DOS cosas, y solo una es peligrosa

`ReciboPolicy::update()` **sigue devolviendo `false`**, y eso no es una
contradicción. El `Update` genérico de Filament abre el formulario entero —el
monto, el concepto, la fecha—, y ahí está el desastre: el papel que el cliente
ya se llevó diría una cosa y la base otra, y si el recibo se aplicó a cuotas
queda descuadrado el plan (es lo que `olympo:cuadrar-recibos` busca desde el
27-ago). Para un error de plata sigue estando **anular + reemitir**.

`Corregir:Recibo` abre **cuatro campos que no mueven un centavo**:

| Se corrige | No se toca |
|---|---|
| quién recibió el dinero · forma de pago · referencia · observaciones | monto · concepto · **fecha** · correlativo · cliente |

La fecha quedó afuera a propósito: decide en qué corte de caja cae el dinero,
así que moverla cambia el cierre de **dos** días.

La lista vive en `CorreccionDeRecibo::CAMPOS` y **es la regla, no una
comodidad**: agregarle un nombre es una decisión de negocio. Hay un test que se
pone rojo si alguien mete `monto` ahí.

### Lo que entró

1. **`App\Domain\Pagos\CorreccionDeRecibo`** — relee con `lockForUpdate()`
   como `anular()`, exige motivo, y deja **UN** asiento en la bitácora: apaga
   el log automático (`disableLogging()`) para que no queden dos filas por un
   mismo cambio —la automática con nombres de columna y sin el porqué, y la
   buena—. El asiento va con `withChanges()` (§ lo de siempre: `properties` es
   donde nadie lo pinta) y con las **etiquetas de la pantalla**, no las
   columnas: lo va a leer la administradora, no un programador.
2. **`ReciboPolicy::corregir()`** con `Corregir:Recibo`, nombrado uno por uno
   (§9.E3). **El receptor NO lo hereda**: a nombre de quién quedó un cobro es
   justo lo que quien cobró no debería poder cambiar solo. Y no se corrige un
   recibo **anulado**.
3. **`App\Filament\Support\CorregirRecibo`** — el modal, al lado de
   `ImprimirRecibo` porque va en **dos** pantallas: la lista y la ficha.
4. **Motivo obligatorio**, como en Anular. No se guarda en el recibo —el papel
   del cliente no cambia—: queda en Registros de actividad con el antes, el
   después y el usuario.

🔴 **La referencia NO se exige, y no es un olvido.** El cobro dejó de exigirla
el 27-ago (llega la transferencia y el número todavía no lo tiene nadie).
Exigirla acá haría **imposible corregir justo los recibos que salieron sin
referencia**, que son los que más falta hace corregir. Esta pantalla es
precisamente donde se teclea ese número.

### Qué mirar en pruebas

`Recibos` → abrir cualquiera → botón **Corregir** (ámbar, al lado de Imprimir).
Cambiar «quién recibió el dinero», guardar con motivo, y verlo en
**Registros de actividad**: un solo asiento, con el antes y el después.
Después entrar como **receptor**: el botón no tiene que estar.

### 🔴 Al desplegar

    sudo -u www-data /usr/bin/php8.5 artisan olympo:sembrar-permisos

Sin eso `Corregir:Recibo` no existe en la base y **el botón no se dibuja**: es
exactamente el caso que hizo nacer ese comando.

### Queda pendiente

- **El manual de la administradora** (`docs/manuales/*.pdf`) no menciona
  Corregir. El PDF no tiene fuente en el repo, así que se actualiza aparte.

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

### 🔴 Y el campo pasa a ser OBLIGATORIO

«Quién recibió el dinero que sea obligatorio» — Mauricio, mirando el modal de
la venta con el campo **vacío**. Y estaba vacío por una buena razón: él es el
**super-admin**, la única cuenta que la lista no ofrece (27-ago: «Mauricio Cruz
no debe de aparecer ahí»), así que a él no se le puede preseleccionar nadie.
Sin exigirlo, ese recibo salía a nombre suyo — lo escribe `Recibo::booted()`.

⚠️ **Obligatorio cuando hay a quién elegir.** Con la lista vacía —una
instalación recién montada, o una lotificadora que todavía no le dio
`Create:Recibo` a nadie— un `Select` requerido sin una sola opción es un
formulario que nadie puede mandar. Ahí no se exige y el papel sigue cayendo en
quien teclea.

💡 Eso además es lo que deja el suite en verde: casi todos los tests corren
como super-admin y sin cajeros creados, así que la lista está vacía. Los que sí
crean uno actúan COMO él, y ahí `fillForm` llega con el campo lleno.

### La casilla de confirmar ya era obligatoria — ahora además se ve

«Si no se marca el revisé el plazo y precio no se pueda vender» — no se puede
desde el 14-ago: las dos casillas (vender y apartar) llevan `accepted()` y hay
dos tests que sostienen que sin tildarla **no se firma y no se aparta**. Esa
regla no se tocó.

Lo que se cambió es cómo se ve: era un campo más de la lista y quien scrolleaba
de largo la pasaba sin verla —el modal le contestaba con un error recién al
apretar—. Ahora es una **banda ámbar** arriba del botón que **se pone verde**
apenas se tilda (`.olympo-confirmar` en `filament/estilos-olympo`, con `:has()`
y sin una línea de JS). El CSS del panel entra por `renderHook`, así que **no
hace falta compilar nada**.

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
