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
