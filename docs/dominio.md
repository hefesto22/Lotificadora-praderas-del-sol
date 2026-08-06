# Reglas de negocio — Residencial Praderas del Sol

**Estado: CONTESTADO por la contratante el 3 de agosto de 2026.**

Este documento nació como cuestionario para doña Rosa Elena España Portillo. Ya está
respondido, así que deja de ser una lista de preguntas y pasa a ser **la fuente de verdad
del comportamiento del sistema**. Lo que está acá es lo que el código tiene que hacer.

**Evidencia de las respuestas:**

| Qué | Dónde |
|---|---|
| Cuestionario marcado por la contratante | `docs/Preguntas-Praderas-del-Sol-respondido.pdf` |
| Cuestionario original, sin marcar | `docs/Preguntas-Praderas-del-Sol.pdf` |
| Aclaraciones de la contratante transmitidas por Mauricio | 3-ago-2026 — recogidas en R10, R7 y R17 |

**Regla de mantenimiento:** si mañana cambia una de estas reglas, **se cambia acá primero**
y después en el código. Una regla de negocio que solo vive en una función es una regla que
nadie puede auditar contra el contrato.

---

## 1. Las 18 respuestas, tal como vinieron

| # | Pregunta | Respuesta de la contratante |
|---|---|---|
| 1 | ¿El saldo financiado genera interés? | **No.** El precio del lote ya incluye todo |
| 2 | ¿Se cobra mora? | **No se cobra mora** (los días de gracia quedan sin efecto) |
| 3 | Abono extraordinario a capital | **Se acorta el plazo**: misma cuota, termina antes |
| 4 | ¿Descuento por contado o pronto pago? | **Se negocia caso por caso** |
| 5 | ¿La prima se paga en varios abonos? | **No.** Se paga completa y ahí se firma el contrato |
| 6 | Rescisión: ¿qué pasa con lo pagado? | **Se negocia caso por caso** |
| 6b | ¿El lote vuelve a estar disponible? | *sin respuesta* → ver §4 |
| 7 | ¿Expediente y contrato son el mismo número? | **Son el mismo.** Expediente `0001` ↔ contrato `RPS-2026-0001` |
| 8 | ¿Un lote puede tener dos dueños? | **Sí**, marido y mujer o socios en el mismo contrato |
| 8b | ¿Los dos pagan y piden estado de cuenta, o hay titular? | *sin respuesta* → ver §4 |
| 9 | ¿Una venta puede incluir varios lotes? | **Sí**, dos o tres lotes en un solo contrato |
| 10 | Talonario con CAI | **No se usa CAI.** Solo recibos de venta, de uso interno |
| 11 | Formas de pago | **Efectivo, transferencia y depósito bancario.** Cheque no. Se anota la referencia |
| 12 | Numeración de recibos internos | **Una sola numeración** para toda la lotificadora |
| 13 | ¿Se cobra sin venta asignada? | **No**, siempre hay una venta o un apartado de por medio |
| 14 | Apartados | **L 5,000.00**, dura **15 días**, prorrogable **15 días más** |
| 14b | Si el apartado vence | Lote disponible y **el dinero se devuelve** |
| 14c | Si el apartado se convierte en venta | **Cuenta como parte de la prima** |
| 15 | ¿Cómo llegan los lotes? | **En un cuaderno o listado en papel** |
| 16 | ¿Cuántos metros tiene una vara? | **0.8359 m está bien** |
| 17 | ISV en las ventas | **No aplica** — la venta de lote no lleva ISV |
| 18 | Alcance de las etapas frente al 11-sep-2026 | **Es correcto** |

---

## 2. Las reglas que el sistema implementa

Cada regla tiene un código estable. Cuando el código haga algo por una de estas razones, el
comentario cita la regla y no repite el argumento.

### Dinero y plan de cuotas

**R1 · No hay interés.** La cuota es el saldo financiado dividido entre el plazo:
`cuota = (valor de la venta − prima) ÷ plazo en meses`. No existe amortización con capital e
interés separados, ni tabla francesa, ni columna de interés en ninguna parte.

Como la división casi nunca cierra exacta, **la última cuota absorbe el residuo**: la suma de
todas las cuotas es *exactamente* igual al saldo financiado, al céntimo. Es la única forma de
que un estado de cuenta cierre en cero el último mes. Esto se prueba con un test que
reconstruye el plan desde cero y compara la suma (§8.3.4).

> **Por qué importa:** ahorra el módulo más caro de todo el sistema. Sin interés, el plan de
> cuotas es aritmética exacta y se puede verificar a mano contra el cuaderno.

**R2 · No hay mora.** Un cliente atrasado debe exactamente lo mismo que debía el día del
vencimiento. El estado de cuenta **muestra los días de atraso y las cuotas vencidas** —eso es
información que la administración necesita— pero **no genera ningún cargo**. No hay columna
de mora, ni tarea programada que la calcule cada noche, ni acumulación silenciosa.

**R3 · El abono extraordinario acorta el plazo…** El monto de la cuota pactada **nunca
cambia**. Al aplicar un abono a capital se recalculan las cuotas pendientes con el mismo monto
de siempre, y la última queda por el residuo. El cliente sigue pagando lo mismo cada mes y
termina antes.

> ⚠️ **…pero desde el 6-ago-2026 no es la única opción.** La contratante agregó en la reunión
> que el cliente **elige**: acortar el plazo (esto) o bajar la cuota manteniendo los meses. Los
> dos caminos viven en R21, que es el que manda.
>
> R3 se queda escrito porque sigue siendo el **default** y porque explica los dos bordes de
> abajo, que valen para cualquiera de los dos caminos. Lo que dejó de ser cierto es el «nunca
> cambia»: cambia si el cliente lo pide.

Dos bordes que hay que cerrar en el código:

- Un abono **mayor al saldo** se rechaza; el sistema topa el monto en el saldo exacto y ofrece
  cancelar la venta.
- Un abono que deja el saldo en cero **cancela el plan** y la venta queda pagada; no quedan
  cuotas de L 0.00 colgando.

**R4 · Los descuentos son manuales y con motivo.** No hay porcentaje automático por contado ni
por pronto pago. El sistema deja registrar un descuento a mano, y para eso exige **motivo
escrito** y guarda **qué usuario lo aplicó y cuándo**. Un descuento sin motivo no se graba.

Construido el 5-ago-2026. El descuento no es un monto aparte: es un **precio por vara² menor**,
que se teclea lote por lote al armar la venta y queda congelado junto al de lista (ver R9). «Un
descuento sin motivo no se graba» es literal — lo hace cumplir el CHECK
`compromisos_descuento_con_motivo_chk`, no una validación de formulario. El quién y el cuándo
los traen `created_by` y `created_at`.

**Quién puede descontar:** cualquiera que pueda crear ventas, receptores incluidos. El control
es posterior —queda el registro— y no previo. Decidido el 5-ago-2026; si la contratante
prefiere que solo la administración pueda, es un permiso, no una migración.

> **Por qué importa:** "caso por caso" significa que la decisión es de la administración, no
> del sistema. Lo que el sistema aporta es que después se pueda saber quién autorizó qué.

**R5 · La prima se paga completa, y ahí nace el contrato.** No existe la venta a medias. La
secuencia es una sola:

1. El lote se aparta (R14) y queda en estado `apartado`.
2. El cliente paga **la prima completa**. Si venía de un apartado, ya tiene L 5,000.00 a favor
   y paga la diferencia.
3. **En ese momento** —y no antes— se firma el contrato, se consume el número correlativo (R7),
   el lote pasa a `vendido` y se genera el plan de cuotas.

Mientras la prima no esté pagada completa, **no hay número de contrato consumido**. Un
correlativo que se quema en una venta que no se concretó es un hueco en la serie que después
hay que explicar.

**R6 · La rescisión se liquida a mano.** El sistema **no calcula** cuánto se le devuelve al
cliente: eso se negocia caso por caso. Lo que sí hace es registrar la liquidación —monto
devuelto, monto retenido, motivo y quién autorizó—, cerrar el compromiso como `rescindido` y
dejarlo en la historia del lote. El estado `Rescindido` ya existe en `EstadoCompromiso`.

La reunión del 6-ago-2026 precisó **el alcance**: se rescinde un lote, no el contrato entero
(R22). R6 sigue diciendo *cómo* se liquida; R22 dice *sobre qué*.

### Contratos y expedientes

**R7 · Un solo correlativo para expediente y contrato.** Son el mismo número visto de dos
formas. El ejemplo real que dio la contratante:

```
Leticia Romero  →  expediente 0001  →  contrato RPS-2026-0001
```

El expediente es **el secuencial**; el número de contrato es ese mismo secuencial con el
código del proyecto y el año adelante. El formato ya está en `config/lotificadora.php`
(`separador` y `digitos_contrato = 4`) y el código del proyecto sale de `proyectos.codigo`.

El correlativo se consume con `SELECT … FOR UPDATE` dentro de la transacción, nunca con
`MAX(numero) + 1` (§8.3.6).

**El secuencial no reinicia nunca** (decidido el 3-ago-2026). En 2027 sigue corriendo:
`RPS-2027-0132` → expediente `0132`. Así el número de expediente identifica a un cliente
**para siempre** y no necesita cargar el año encima, que es lo único consistente con "son el
mismo número". El año del contrato es el año en que se firmó, no una llave.

**R8 · Una venta puede tener varios clientes.** Marido y mujer, o socios, aparecen los dos en
el mismo contrato. La venta se relaciona con clientes **de muchos a muchos**, y uno de ellos
va marcado como **titular**.

Mientras no llegue la respuesta de §4 punto 2, el sistema arranca con el criterio más
conservador y reversible: **cualquiera de los copropietarios puede pagar**, el recibo sale a
nombre de quien paga con mención del contrato, y **el estado de cuenta sale a nombre del
titular** con los demás listados. Cambiarlo después es una regla de presentación, no una
migración.

**R9 · Una venta puede incluir varios lotes.** Cada lote congela **su área, sus dos precios
por vara y su valor**, y el valor de la venta es la suma de esos valores congelados.

Dónde se congela: en `compromisos`, no en una tabla `venta_lote`. La primera redacción de esta
regla decía `venta_lote`; al implementarla se vio que `compromisos` ya congela exactamente eso
al momento de vender, y que agregar una segunda tabla sería tener dos lugares donde el dinero
puede discrepar. Los lotes de una venta son sus compromisos de tipo `venta` (5-ago-2026).

**Los precios son dos, no uno** (esto sale de R4, y se decidió el 5-ago-2026):

| Columna | Qué guarda |
|---|---|
| `precio_vara_lista` | Lo que el lote valía en la lista **ese día** |
| `precio_vara` | Lo que efectivamente **se firmó** |
| `motivo_descuento` | Obligatorio si el segundo es menor que el primero |

En un apartado los dos coinciden. En una venta pueden no coincidir, porque el precio se
negocia caso por caso. Guardar solo el pactado haría imposible contestar «¿cuánto se descontó
este mes?», porque el precio de lista del lote cambia con el tiempo.

El lote **no se toca**: conserva su precio de lista. Si la venta se rescinde, el lote vuelve a
la vitrina al precio del proyecto y no al que se le hizo a un cliente puntual.

### Dinero que entra

**R10 · No hay CAI. Solo recibos de venta, de uso interno.** La contratante no usa talonario
autorizado por el SAR para estos cobros: el único documento que se entrega es el recibo de
venta, interno. Esto **saca de la Etapa 1** toda la maquinaria fiscal que estaba
prevista: validación del formato del CAI, rango autorizado, fecha límite de emisión y las
alertas de talonario por agotarse.

Lo que sí se construye: **recibo interno**, con su numeración correlativa (R12), su detalle de
aplicación y su reimpresión.

Se deja la puerta abierta sin construir nada: el documento de cobro lleva una columna de
**tipo de documento**, de modo que el día que aparezca un talonario con CAI se agregue el tipo
sin tocar la tabla ni migrar los recibos ya emitidos. La clase `App\Domain\ValueObjects\CAI`
se queda donde está, sin cablearse a ningún formulario.

> **Por qué importa:** es la baja de alcance más grande que trajeron estas respuestas, y llega
> justo cuando el calendario lo necesitaba. Queda registrada acá porque la autorización para
> no construirlo es la respuesta de la contratante, no una decisión de Olympo.

**R11 · Tres formas de pago.** Efectivo, transferencia y depósito bancario. **Cheque no se
recibe** (quedó sin marcar) y no se llenó ninguna otra forma. En transferencia y depósito el
**número de referencia es obligatorio**; en efectivo no aplica.

**R12 · Una sola numeración de recibos para toda la lotificadora.** No hay series por receptor.
Don Elder y don Edwin consumen números de la misma secuencia, y por eso el correlativo se
consume con bloqueo de fila dentro de la transacción (§8.3.6): dos personas cobrando al mismo
tiempo desde lugares distintos **no pueden** sacar el mismo número.

**R13 · No se cobra sin compromiso.** Todo pago cuelga de un apartado o de una venta. No existe
el "saldo a favor sin aplicar" ni el cliente con dinero flotando. Si alguien llega a abonar
antes de firmar, primero se aparta el lote (R14) y el abono entra contra ese apartado.

> **Por qué importa:** elimina de raíz la conciliación de pagos huérfanos, que es donde estos
> sistemas se ensucian con los años.

**R19 · Una cuota se paga en varias veces.** *(reunión del 6-ago-2026)* No siempre pagan la
cuota completa de un tirón: pagan un mes en dos o tres partes, dentro del mismo mes. El sistema
registra cada pago por separado y los va acumulando contra la cuota; lo que falta se arrastra.

No hay mora (R2), así que una cuota parcialmente pagada no genera ningún cargo: el cliente debe
exactamente lo que le falta. `monto_pagado` sube, `estado` no existe como columna — se calcula
(§9.D5).

**R20 · Una cuota a la que le falta poco se puede dar por cerrada, con motivo.**
*(reunión del 6-ago-2026 — regla confirmada, construcción diferida)*

> El comportamiento base es R19: lo que falta **queda debiendo, sin cargo**, y nadie lo persigue.
> Eso es lo que se construye primero. La condonación de abajo es un añadido que cuelga del
> módulo de pagos y entra cuando ese esté andando: sin pagos registrados no hay saldo chico que
> perdonar. Faltan L 15.00 y no se le van a cobrar. El receptor cierra la cuota
y **escribe por qué**; queda con su usuario y su fecha, igual que un descuento (R4).

Es una **condonación**, no un redondeo automático: el sistema no perdona plata solo. Se decidió
así sobre dos alternativas peores — dejar el saldo colgando para siempre (el estado de cuenta
nunca llega a cero y quien lo lea va a preguntar) o un tope automático (el sistema perdonando
sin que una persona lo decida y sin que quede quién fue).

**R21 · Abono a capital, y el cliente elige qué pasa con la cuota.**
*(reunión del 6-ago-2026)* Un abono extraordinario baja el saldo, y ahí hay dos caminos y los
elige el cliente:

- **misma cantidad de cuotas, cuota más baja** — se reparte el saldo nuevo en los meses que
  quedaban;
- **misma cuota, menos meses** — se termina antes.

El abono se aplica **a un lote**, y lo elige quien recibe. Con plazos distintos por lote (R22)
no hay forma de adivinarlo: el cliente que abona está pensando en un lote concreto, casi
siempre el que quiere terminar de pagar primero. Repartirlo entre todos recalcularía tres
cuotas de golpe y le movería números que no pidió tocar.

Recalcular **reescribe el plan de ese lote**: las cuotas ya pagadas no se tocan, las pendientes
se reemplazan. Queda registrado que hubo una reprogramación, con su motivo.

**Dos detalles que decidió Mauricio el 6-ago-2026, porque cambian el número:**

- **Con cuotas vencidas, el abono primero pone al día.** Cubre lo vencido en FIFO y solo el
  sobrante va a capital. Si no, quedaría alguien «con capital abonado» y moroso al mismo
  tiempo — dos verdades sobre el mismo contrato. Si el abono no alcanza ni para lo vencido, es
  un pago normal y no hay reprogramación: no se reescribe un plan por algo que no bajó el
  capital.
- **La cuota pagada a medias se respeta.** Si la 5 tiene L 12,500.00 de L 25,000.00, esa cuota
  queda tal cual y el plan nuevo empieza en la 6. Es lo que ya decía R21 —lo pagado no se
  toca— y además mantiene el recibo viejo apuntando a una cuota que sigue existiendo. La
  alternativa (absorber el parcial y recalcular todo) deja aplicaciones de pago colgando de
  cuotas borradas, y ahí «¿por qué la 5 aparece a medias?» deja de tener respuesta.

**Construido el 6-ago-2026.** Lo que se decidió al escribirlo:

| Qué | Cómo quedó |
|---|---|
| Dónde queda la constancia | Tabla `reprogramaciones`, con el plan viejo completo en una columna `jsonb`. Se descartó archivar las cuotas viejas en la misma tabla —obligaba a tocar el índice único, el FIFO y todo scope que lee `cuotas` sin filtro— y se descartó dejarlo solo en la bitácora, que guarda propiedades sueltas sin forma declarada |
| El motivo | **Obligatorio**, y lo hace cumplir un CHECK, igual que el descuento de R4 |
| El recibo | **Uno solo**, de concepto `abono_capital`. Lleva sus aplicaciones por la parte que puso al día; lo que bajó el capital es `monto − suma de aplicaciones`. El cliente entregó un dinero y se lleva un papel |
| Quién puede | Solo la administradora, con permiso propio `Reprogramar:Venta`. El receptor cobra (`Create:Recibo`) pero no reescribe un plan firmado |
| Si no alcanza para lo vencido | Se registra igual **como pago normal** y una notificación explica que no hubo reprogramación. El dinero ya está sobre el mostrador |

**El tope del abono no es el saldo del lote.** Solo se puede abonar hasta *lo vencido + lo que
se puede reprogramar*. Lo que le falta a una cuota pagada a medias queda afuera: respetarla
significa no tocarla, ni siquiera para cobrarla de paso. Cancelar el lote entero se hace por
«Registrar un pago», que cubre todo FIFO sin reescribir nada.

**El calendario no se mueve.** La primera cuota nueva vence el día en que vencía la primera que
se reemplaza, y la numeración sigue desde ahí (`cuotas_numero_por_lote_uidx` no admitiría
empezar de nuevo en 1, y el recibo viejo quedaría apuntando a una cuota 5 que ahora significa
otra cosa). Lo que cambia es cuánto o cuántas, nunca cuándo.

**R22 · La rescisión es POR LOTE, no por contrato.** *(reunión del 6-ago-2026)* «Dio la prima,
pagó dos meses y ya no quiere el lote». Si el contrato lleva tres lotes y devuelve uno, se
rescinde **ese lote**: sus cuotas pendientes se cancelan, vuelve a estar disponible en el plano
y el expediente sigue vivo con los otros dos, con su saldo recalculado.

Con un solo lote equivale a anular el contrato entero, así que el mismo trámite cubre los dos
casos. La alternativa —anular todo siempre— obligaría a rehacer a mano el contrato del cliente
que se queda con dos lotes, y con un número nuevo.

Al rescindir **se pregunta cuánto se le devolvió**, y la respuesta puede ser cero. No se calcula
solo: cuánto se devuelve lo decide la administración caso por caso, y lo que el sistema tiene
que hacer es dejar constancia de cuánto entró, cuánto salió y quién lo autorizó.

### Apartados

**R14 · El apartado tiene números fijos.**

| Concepto | Valor |
|---|---|
| Monto | **L 5,000.00** |
| Duración | **15 días** |
| Prórroga | **Sí, 15 días más** |
| Si vence y el cliente no vuelve | El lote queda disponible y **el dinero se devuelve** |
| Si se convierte en venta | El monto **cuenta como parte de la prima** |

Los tres números viven en `config/lotificadora.php`, nunca escritos dentro de una función.
El vencimiento se calcula al crear el apartado (`vence_el = fecha + 15 días`), que es la
columna que la tabla `compromisos` ya tiene.

La prórroga es **una sola** y queda registrada: quién la autorizó y cuándo. Un apartado que se
prorroga dos veces es un apartado que en realidad nunca venció.

La devolución del apartado vencido **genera un movimiento de salida con su documento**, no una
fila que se borra. El dinero entró con un recibo; tiene que salir con un respaldo.

### Datos y unidades

**R15 · Los lotes llegan en papel.** No hay Excel. La geometría del residencial ya está
resuelta por el importador DXF, así que lo que falta digitar es **la parte comercial**: precio
por vara de cada lote, estado actual y, donde ya hay dueño, a quién se le vendió y cuánto
lleva pagado.

Eso es tiempo de una persona sentada digitando, y hay que preverlo en el calendario del
11-sep. La pantalla de carga tiene que estar pensada para eso: teclado, sin mouse, sin
recargar entre lote y lote.

**R16 · La vara mide 0.8359 m — confirmado.** Se le quita la marca de *pendiente* a
`config/lotificadora.php`. Ningún cálculo de dinero depende de este número (las áreas se
guardan en varas² y los precios son por vara²), pero ya se puede imprimir en un contrato.

**R17 · Las ventas no llevan ISV.** Confirmado por la contratante. El sistema no imprime ISV
en ningún documento ni lo separa en ningún total.

**R18 · La Etapa 1 es lo que se entrega el 11 de septiembre de 2026.** Confirmado por escrito:
clientes, lotes, ventas, contratos, promesa de venta, apartados, documentos de cobro y estado
de cuenta. Gastos, arqueo por receptor, expediente digital, libro maestro y reportes son
Etapa 2.

---

## 3. Lo que estas respuestas cambiaron en el alcance

**Sale del alcance de la Etapa 1** (autorizado por las respuestas de la contratante):

- Motor de intereses y amortización con capital/interés separados — R1
- Cálculo, acumulación y cobro de mora, y su tarea programada — R2
- Toda la maquinaria de CAI: formato, rango autorizado, fecha límite y alertas — R10
- Descuentos automáticos por contado o pronto pago — R4
- Registro de cheques — R11
- Pagos sin venta asignada y su conciliación posterior — R13
- ISV — R17

**Entra al alcance, y no estaba previsto así:**

- Devolución del dinero de un apartado vencido, con su documento de respaldo — R14
- Prórroga de apartado, con autorización registrada — R14
- Liquidación manual de rescisión: devuelto, retenido, motivo y quién autorizó — R6
- Venta con varios clientes y titular — R8
- Pantalla de carga rápida para digitar los ~500 lotes desde papel — R15

> El saldo es claramente favorable al calendario: lo que sale es más caro que lo que entra, y
> lo que sale era justamente lo que tenía más superficie de error.

---

## 4. Lo que sigue abierto

Ninguno de los tres primeros frena el motor de cuotas; el cuarto solo frena el cuadro de
planes del plano. Se anotan acá para que no se olviden
y para que quede claro qué módulo bloquea cada uno.

**1. Copropietarios: ¿los dos pueden todo, o hay un titular con más facultades?** — *no bloquea*

El sistema arranca con el criterio de R8. Si la contratante prefiere otra cosa, es un cambio
de presentación.

**2. Rescisión: ¿el lote vuelve a estar disponible de inmediato?** — *no bloquea*

Quedó sin marcar. El sistema arranca con **lo decide la administración**: la rescisión libera
el lote solo cuando alguien lo indica expresamente. Es la opción que no toma decisiones por
nadie.

**3. Apartado vencido: ¿quién autoriza la devolución y en qué plazo?** — *no bloquea*

La respuesta dice que el dinero se devuelve, pero no dice si es automático al vencer o si
alguien tiene que autorizarlo. El sistema arranca exigiendo **autorización explícita**, porque
el dinero no debería salir de la caja por un vencimiento de calendario.

**4. El precio de la vara según el plazo: falta la lista.** — *bloquea el cuadro de planes*

Dato nuevo, del 5-ago-2026, que no venía en el cuestionario: el precio de la vara no es el
mismo a 1 año que a 4. **No es interés** —R1 sigue en pie, no hay amortización ni saldo que
devengue— sino precios de lista distintos según el plazo; elegido el plazo, el precio queda
fijo y la cuota sigue siendo (valor − prima) ÷ meses.

Falta la tabla por escrito: plazo → precio por vara². Hasta que llegue, `financiamiento.planes`
en `config/lotificadora.php` va vacío y el plano no muestra ninguna cuota, porque un precio
inventado en esa pantalla es un precio que un vendedor le cotiza a un cliente.

Cuando llegue hay que decidir además de dónde toma el formulario el precio precargado: hoy
trae el de lista del lote, y con lista por plazo tendría que traer el del plan elegido.

**Nada más queda abierto de los bloques 3 y 6.** Que no se usa CAI (R10) y que las ventas no
llevan ISV (R17) **son respuestas de la contratante igual que las marcadas en el PDF**:
llegaron junto con él, el 3 de agosto de 2026. No son una suposición de Olympo ni están
pendientes de confirmar.

---

## 5. Ya definido — no requiere respuesta

**Expediente digital.** El único documento que se sube al sistema por cliente es la **promesa
de venta**. Se conserva de forma indefinida, o hasta que el almacenamiento se migre a un
servicio externo. El contrato incluye 25 GB y el excedente se cobra a L 200 por GB al año; con
un solo documento por cliente, esa capacidad está holgada.

**Usuarios del sistema.** Los datos de las personas que van a usar el panel ya están
disponibles y sus cuentas se crean directamente.
