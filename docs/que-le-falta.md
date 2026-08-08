# Qué le falta al sistema — análisis del 8-ago-2026

> Escrito a pedido de Mauricio. Son **dos listas distintas** y confundirlas cuesta días:
> lo que le falta a **Praderas del Sol** para operar el 20-ago (dentro del contrato), y lo
> que le falta al **producto** para vendérselo a otra lotificadora. Cada punto dice a cuál
> pertenece.

---

## 1. Interés y mora en el plan de pago — el cambio más grande

### 1.1 Lo que hay hoy, y por qué no es un olvido

R1 (sin interés) y R2 (sin mora) no son valores por defecto: están **cableados en el motor**.
`PlanDeCuotas` lo dice en su propio docblock:

> «No hay tabla francesa, no hay capital e interés separados, no hay columna de interés en
> ninguna parte. Y por R2 tampoco hay mora.»

`planes_de_pago` tiene cuatro columnas —`meses`, `precio_vara`, `activo`, `etiqueta`— y
`cuotas` guarda `monto` y `monto_pagado`, sin desglose. La cuota es `(valor − prima) ÷ plazo`
y la última absorbe el residuo.

### 1.2 La buena noticia: hacerlo configurable NO rompe R1 ni R2

La fórmula de amortización francesa

```
cuota = P × i ÷ (1 − (1+i)^−n)
```

con `i = 0` **degenera exactamente en `P ÷ n`**, que es lo que el sistema hace hoy. Es decir:
un solo camino de código sirve para las dos cosas, y Praderas del Sol pasa a ser el caso
particular *tasa 0 %, mora ninguna*. No hay que mantener dos motores ni arriesgar que el de
Praderas se rompa al agregar el de los demás.

### 1.3 Decisión 1 — dónde vive la tasa

**En el plan de pago**, que es donde estabas parado. Un mismo proyecto puede ofrecer 12 meses
sin interés y 48 meses al 12 %, que es exactamente cómo se vende: el plazo largo se paga con
intereses.

Y **se congela en el `compromiso` al firmar**, igual que ya se congelan área, precio, plazo y
prima. Si mañana el proyecto sube la tasa al 15 %, los contratos viejos siguen al 12 %. Sin
esto, subir una tasa reescribiría en silencio contratos ya firmados.

### 1.4 Decisión 2 — el interés anual, cómo se convierte a mensual

Vos capturás **anual**, como pediste. Hay dos formas de bajarlo a mensual:

| | Fórmula | Cuota de L 700,000 a 48 meses, 12 % |
|---|---|---|
| **Nominal ÷ 12** (recomendado) | `i = anual ÷ 12` | L 18,433.68 |
| Efectiva equivalente | `i = (1+anual)^(1/12) − 1` | ≈ L 17,930 |

La segunda es más correcta matemáticamente y **no la usa nadie en este rubro**. Todo el
mercado hondureño de lotificaciones, y los bancos, dicen «12 % anual» y dividen entre 12.
Recomiendo nominal ÷ 12, y que el contrato diga «12 % anual sobre saldos, equivalente a 1 %
mensual» para que no haya discusión.

### 1.5 Lo que cuesta el interés, con tus números reales

**RPS-C-010 · L 700,000.00 · 48 meses · sin prima**

| Tasa anual | Cuota | Total pagado | Intereses | Sobre el precio |
|---:|---:|---:|---:|---:|
| 0 % (hoy) | 14,583.33 | 700,000.00 | — | — |
| 6 % | 16,439.52 | 789,096.96 | 89,096.96 | +12.7 % |
| 8 % | 17,089.05 | 820,274.40 | 120,274.40 | +17.2 % |
| 10 % | 17,753.81 | 852,182.88 | 152,182.88 | +21.7 % |
| 12 % | 18,433.68 | 884,816.64 | 184,816.64 | +26.4 % |
| 15 % | 19,481.52 | 935,112.96 | 235,112.96 | +33.6 % |
| 18 % | 20,562.50 | 987,000.00 | 287,000.00 | +41.0 % |

**Y el contrato completo que tenés en pantalla, si le pusieras 12 % a los tres lotes:**

| Lote | Plazo | Hoy | Al 12 % | Diferencia |
|---|---:|---:|---:|---:|
| RPS-C-012 | 12 meses | 54,166.67 | 57,751.71 | +3,585.04 |
| RPS-C-011 | 24 meses | 28,125.00 | 31,774.59 | +3,649.59 |
| RPS-C-010 | 48 meses | 14,583.33 | 18,433.68 | +3,850.35 |
| **Primer mes** | | **96,875.00** | **107,959.98** | **+11,084.98** |

Fijate en el patrón: a igual tasa, **el plazo largo es el que más interés paga en total**
(41 % del precio del lote a 18 %/48 meses). Es el argumento de venta y también el riesgo
reputacional: hay que imprimirlo claro en el contrato, no esconderlo en la cuota.

### 1.6 Decisión 3 — tabla congelada o interés devengado por días

| | Cómo funciona | Para quién sirve |
|---|---|---|
| **Congelada (recomendado)** | Al firmar se genera la tabla completa: cuota 1 a N, cada una con su capital y su interés. Pagar tarde no cambia esos números — el atraso se cobra aparte, como mora. | Lotificaciones. El cliente firma un papel con la tabla y sabe exactamente qué debe cada mes. |
| Devengado por días | El interés se calcula sobre saldo insoluto por días reales. Pagar tarde genera más interés automáticamente. | Bancos. El cliente no puede saber de antemano cuánto paga. |

Recomiendo **congelada**, y no solo por simplicidad: encaja con lo que ya está construido.
`cuotas` es un snapshot inmutable del contrato de hoy, `PlanDeCuotas` es un motor puro que
arma la tabla al firmar, y el estado de cuenta imprime esa tabla. Con devengado por días
habría que recalcular la tabla entera en cada consulta.

### 1.7 Decisión 4 — cómo funciona la mora (esto es lo que preguntaste)

Hay cuatro modalidades que se usan de verdad. **Sobre una cuota de L 14,583.33:**

| Días de atraso | Fija por cuota (L 200) | Fija por mes (L 200) | 3 % mensual sobre la cuota | 24 % anual × días |
|---:|---:|---:|---:|---:|
| 1 | 200.00 | 200.00 | 437.50 | **9.59** |
| 5 | 200.00 | 200.00 | 437.50 | **47.95** |
| 15 | 200.00 | 200.00 | 437.50 | **143.84** |
| 30 | 200.00 | 200.00 | 437.50 | **287.67** |
| 60 | 200.00 | 400.00 | 875.00 | **575.34** |
| 90 | 200.00 | 600.00 | 1,312.50 | **863.01** |

**Recomiendo la cuarta: tasa anual sobre el saldo vencido, prorrateada por días.** Dos razones
concretas:

1. **Escala con el monto.** Una cuota de L 14,583 y una de L 96,875 no pueden pagar la misma
   mora fija. Con monto fijo, al cliente grande la mora no le duele y al chico lo hunde.
2. **No salta.** Con las modalidades «por mes», quien se atrasa **un día** paga lo mismo que
   quien se atrasa veintinueve. Eso genera discusiones en ventanilla todas las semanas, y
   además incentiva lo contrario de lo que querés: si ya me cobraron el mes, ¿para qué pago
   hoy?

Las otras tres quedan igual como opciones configurables, porque otras lotificadoras las usan
y el sistema es un producto.

**Y va con días de gracia.** Casi todo contrato serio da 5 o 10 días antes de que la mora
empiece a correr. Sin eso, el cliente que paga el día 6 en vez del 5 llega con un reclamo.

### 1.8 Las cinco cosas que la mora arrastra y que nadie ve venir

1. **Cambia el orden en que se aplica un pago.** Hoy el FIFO reparte todo a capital. Con
   interés y mora, el estándar es **mora → interés → capital**, y hay que decidirlo y
   escribirlo en el contrato: es la diferencia entre que un cliente salga de la deuda o no
   salga nunca.
2. **La mora no se guarda como una cuota más.** Es un derivado del tiempo: si se guardara,
   habría que recalcularla todos los días. Se calcula al vuelo al momento de cobrar y se
   **congela en el recibo** como un renglón aparte. Eso además da la traza: el recibo dice
   cuánta mora se cobró ese día y por cuántos días.
3. **La mora no genera mora.** Anatocismo — interés sobre interés. Tiene que ser una regla
   explícita del motor, no una consecuencia accidental de dónde se sume el número.
4. **Alguien va a querer perdonarla.** Pasa todas las semanas en ventanilla. Necesita ser un
   trámite con permiso propio y motivo obligatorio, como el descuento de R4 y la condonación
   de R20 — no un campo que se deje en cero.
5. **Hay un tope legal y yo no soy abogado.** Antes de ofrecer 24 % de mora hay que verificar
   el máximo aplicable en Honduras a compraventa de inmuebles a plazo. Ponerlo mal no es un
   bug: es una cláusula impugnable.

### 1.9 Qué toca en el código, y cuánto cuesta

| Qué | Por qué |
|---|---|
| `planes_de_pago` + `compromisos` (migración) | La tasa, la modalidad de mora y los días de gracia; congelados al firmar |
| `cuotas` (migración) | `monto_capital` y `monto_interes`; hoy solo hay `monto` |
| `PlanDeCuotas` | Los tres constructores (`nuevo`, `porCuotaFija`, `porPlazoFijo`) tienen que aceptar tasa |
| `RegistroDePagos` | Imputación mora → interés → capital, y el cálculo de mora al cobrar |
| `EfectoDelAbono` (R21) | Con interés, un abono a capital **ahorra intereses**: ese número pasa a ser el que el cliente mira para decidir |
| Estado de cuenta y recibo | Columnas de capital, interés y mora |
| Formulario de venta y vista previa | Lo que se ve tiene que seguir siendo lo que se guarda |
| Tests | Varios de los 618 asumen «sin interés» |

**Costo honesto: 3 a 4 días**, y toca el camino del dinero de punta a punta. **Praderas del
Sol no lo usa** (R1 y R2 son respuestas de la contratante). Es trabajo de **producto**, no del
20-ago.

---

## 2. Huecos de ventanilla — lo que no tiene camino en el sistema

Auditoría contra `app/Filament/Resources/**`, `app/Domain/**` y `app/Policies/**`.

### 2.1 Los que van a doler la primera semana de uso real

| Trámite | Estado | Evidencia |
|---|---|---|
| **Anular o corregir un recibo mal emitido** | ❌ No existe | `ReciboPolicy.php:49-57` — `update()` y `delete()` devuelven `false`. El comentario dice «cuando se construya tendrá su permiso `Anular:Recibo`»; ese permiso no existe en `RoleSeeder` |
| **Liquidar / cerrar un contrato pagado** | ❌ No existe | `EstadoVenta::Liquidada` está definido y **nadie lo asigna nunca**. Una venta que termina de pagarse se queda «Vigente» para siempre |
| **Rescindir una venta (R6, módulo del contrato)** | ❌ No existe | `EstadoVenta::Rescindida` definido, nadie lo escribe. `RegistroDeCompromisos` no tiene `rescindir()` |
| **Control de la fecha del pago** | ❌ No existe | `ViewVenta.php:147-151` — el `DatePicker` solo es `required()`. Se puede registrar un cobro con fecha de 2019 o de 2030, y `RegistroDePagos::verificar()` nunca mira la fecha |

El primero es el más urgente. Un receptor se va a equivocar tecleando un monto en la primera
semana, y hoy **no hay ninguna forma de corregirlo**: ni editar, ni anular, ni borrar.

### 2.2 Los que pueden esperar

Cambiar titular o copropietarios después de firmar (no existe); traspasar un lote a otro
cliente (no existe); imprimir el contrato o la promesa desde el sistema (solo se sube el
escaneo); arqueo de caja (el contrato lo pone en Etapa 2); devolución de sobrepago o nota de
crédito (el sobrepago hoy se **rechaza**, que es una salida válida); condonación de saldo
chico (R20, diferida); avisos de mora a clientes (R2: no hay mora).

### 2.3 Uno que sí importa y no estaba en ninguna lista

**Editar una venta firmada** — no existe página de edición, y es a propósito. Pero eso incluye
corregir una **fecha de contrato** o un **día de pago** mal tecleados. Hoy la única salida es
borrar y rehacer, lo que quema un correlativo. Merece un trámite chico con motivo.

---

## 3. Reportes — el hueco más visible para quien manda

**El Escritorio hoy tiene dos cosas: la bienvenida de Filament y un medidor de disco.** Nada
más. No existe grupo de navegación «Reportes».

| Lo que no hay | Por qué se nota |
|---|---|
| **Corte de caja del día** | Cuánto se cobró hoy, por forma de pago y por receptor. Es lo primero que pregunta un dueño. `recibos.created_by` existe en la base y **ni siquiera se muestra** en la lista |
| **Cartera vencida / mora** | `diasDeAtraso()` existe pero solo se ve abriendo el expediente de un cliente a la vez. No hay una pantalla que liste lo vencido de toda la cartera |
| **Proyección de ingresos** | Cuánto debería entrar cada mes según los planes vigentes. No existe |
| **Inventario valorizado** | Cuánto vale lo disponible, lo vendido y lo apartado. La tabla de lotes muestra el valor por fila pero no suma nada |
| **Exportar a Excel** | Ninguna tabla tiene acción de exportación. Lo único que exporta es un comando de consola |
| **Filtro por fecha en Ventas y Recibos** | El único rango de fechas de todo el sistema está en la bitácora de auditoría |

Para «lo más profesional posible», esto es lo que más se ve y lo que menos cuesta: cuatro
números en el Escritorio y un corte de caja cambian por completo la impresión en una demo.

---

## 4. Qué tan «producto» es hoy

**Lo que ya está bien:** el modelo soporta **varios proyectos de verdad** (`proyecto_id` desde
la primera migración, filtros por proyecto, correlativo por proyecto); las reglas de negocio
—monto de apartado, días de vigencia, prórrogas— están **centralizadas en `config`** y se leen
desde ahí, no duplicadas; el precio por plazo vive en la base y se edita desde el panel; un
`migrate --seed` limpio **no** deja basura de Praderas.

**Lo que falta:**

| Qué | Impacto |
|---|---|
| **No hay multi-empresa.** El emisor del recibo es una config global; los correlativos son una serie única para toda la instalación | Cada lotificadora nueva = otra base y otro `.env`. Es una decisión válida, pero conviene asumirla explícitamente |
| **El emisor sale del `.env`, no del panel** | Para cambiar el nombre o el RTN que sale en el recibo hay que entrar por SSH. Un cliente no puede |
| **`'L '`, `'vara²'`, `'d/m/Y'` y `'LEMPIRA'` cableados en ~30 archivos** | Cambiar moneda, unidad o formato de fecha es trabajo de código, no de configuración |
| **`praderas:exportar-todo` y `PRADERAS_SUSPENDIDO`** | El nombre del primer cliente adentro del producto |
| **Los seeders de Praderas siguen en el árbol principal** | Alguien corre uno a mano en la instalación nueva y le carga 301 lotes ajenos |
| **`RTN.php` ignora `config('honduras.sar.*')`** | Tiene sus propias constantes; otro país es tocar la clase |

**Lo más barato con más impacto: mover los datos del emisor a la base y editarlos desde el
panel.** Es medio día y es lo primero que cualquier cliente nuevo quiere cambiar.

---

## 5. Seguridad y operación — lo que hay que cerrar antes de subirlo

Ordenado por gravedad **real**, no teórica.

1. 🔴 **El respaldo nunca sale del VPS.** `config/backup.php:86-89` solo tiene el disco
   `local`; la línea de S3 está comentada. Si muere el disco, se pierden la base **y** todos
   los respaldos al mismo tiempo.
2. 🔴 **El cron hay que instalarlo a mano y nadie avisa si falta.** `routes/console.php:23-28`
   lo advierte: sin `schedule:run` en el crontab no corre ni el respaldo ni el monitor.
3. 🔴 **`MAIL_MAILER=log`.** La notificación de «el respaldo falló» no llega a nadie. **Los
   tres juntos son una cadena de fallo silencioso**: el sistema puede dejar de respaldar,
   llenarse el disco y nadie se entera hasta que un cliente reclame.
4. 🔴 **`.env.example` copiado tal cual a producción es un desastre**: `APP_DEBUG=true`,
   `APP_ENV=local`, `ADMIN_PASSWORD=12345678`, `COMPOSE_POSTGRES_AUTH=trust`,
   `REDIS_PASSWORD=null` — en un VPS compartido con otros proyectos de Olympo.
5. 🟠 **`praderas:exportar-todo` exporta TODA la base.** Si se usa para responderle a un
   cliente que pide sus datos (Cláusula Décima), le mandás los de todos los demás y los del
   personal. Sí excluye contraseñas.
6. 🟠 **La autorización de los documentos es por permiso, no por pertenencia.**
   `ReciboPolicy::view()` y `VentaPolicy::view()` solo miran `can('View:Recibo')` — nunca
   comparan contra el registro. Cualquier usuario del panel abre el recibo o el estado de
   cuenta de cualquier cliente cambiando el id en la URL, y las rutas no tienen `throttle`
   para frenar la enumeración. **Hoy todos los usuarios son de la lotificadora**, así que no
   es una fuga hacia afuera — pero es exactamente lo que se rompe el día que exista el portal
   del cliente que está en el roadmap. Y ya contradice lo que dice `Roles::RECEPTOR`: «no ve
   el arqueo de otro receptor».
7. 🟡 Sin 2FA · contraseña mínima de 8 sin exigir complejidad · `SESSION_SECURE_COOKIE` no
   definida en ningún lado.
8. 🟡 **`AplicacionDePago` sin policy y sin bitácora.** Es el eslabón que dice a qué cuota fue
   cada lempira, y es el único modelo del camino del dinero sin ninguno de los dos.
9. 🟡 `/health` sin restricción de IP; las notificaciones de salud están apagadas
   (`config/health.php:37`), así que aunque falle la base nadie se entera.

---

## 6. Lo que yo haría, en orden

**Con 12 días para el 20-ago:**

1. **`herd composer ci`** sobre lo del 8-ago. Trae migración nueva.
2. **Cerrar la cadena de fallo silencioso y subirlo.** Respaldo fuera del servidor, cron
   instalado, SMTP real, `.env` de producción. Sin esto no hay «sistema en operación».
3. **Anular recibo + liquidar contrato + control de fecha del pago.** Es lo que va a doler la
   primera semana de uso real, y son tres trámites chicos.
4. **Escritorio con cuatro números y corte de caja del día.** Barato y es lo que se ve.
5. **Cargar la cartera vendida que ya existe en papel.**

**Después del 20-ago, como producto:**

6. **Interés y mora configurables** (sección 1) — 3 a 4 días.
7. **Emisor editable desde el panel** y limpiar lo cableado — medio día lo primero.
8. **Pago mixto** (efectivo + transferencia + tarjeta en un recibo) — un día.
9. Rescisión, traspaso, cambio de titular, arqueo.
