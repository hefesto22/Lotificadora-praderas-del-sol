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
| 10 | Talonario con CAI | **No se usa CAI.** Los recibos son solo de uso interno |
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

**R3 · El abono extraordinario acorta el plazo.** El monto de la cuota pactada **nunca
cambia**. Al aplicar un abono a capital se recalculan las cuotas pendientes con el mismo monto
de siempre, y la última queda por el residuo. El cliente sigue pagando lo mismo cada mes y
termina antes.

Dos bordes que hay que cerrar en el código:

- Un abono **mayor al saldo** se rechaza; el sistema topa el monto en el saldo exacto y ofrece
  cancelar la venta.
- Un abono que deja el saldo en cero **cancela el plan** y la venta queda pagada; no quedan
  cuotas de L 0.00 colgando.

**R4 · Los descuentos son manuales y con motivo.** No hay porcentaje automático por contado ni
por pronto pago. El sistema deja registrar un descuento a mano, y para eso exige **motivo
escrito** y guarda **qué usuario lo aplicó y cuándo**. Un descuento sin motivo no se graba.

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

**R9 · Una venta puede incluir varios lotes.** La venta se relaciona con lotes de muchos a
muchos (`venta_lote`), y **cada lote congela ahí su área, su precio por vara y su valor**, como
ya lo hace `compromisos`. El valor de la venta es la suma de los valores congelados.

### Dinero que entra

**R10 · No hay CAI. Los recibos son internos.** La contratante no usa talonario autorizado por
el SAR para estos cobros. Esto **saca de la Etapa 1** toda la maquinaria fiscal que estaba
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

Ninguno de estos tres puntos frena el motor de cuotas. Se anotan acá para que no se olviden
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

**Además, conviene dejar por escrito de la contratante:** que no se usa CAI (R10) y que las
ventas no llevan ISV (R17) vinieron como aclaración verbal. Son las dos respuestas con
consecuencia fiscal, y son justamente las que conviene tener firmadas.

---

## 5. Ya definido — no requiere respuesta

**Expediente digital.** El único documento que se sube al sistema por cliente es la **promesa
de venta**. Se conserva de forma indefinida, o hasta que el almacenamiento se migre a un
servicio externo. El contrato incluye 25 GB y el excedente se cobra a L 200 por GB al año; con
un solo documento por cliente, esa capacidad está holgada.

**Usuarios del sistema.** Los datos de las personas que van a usar el panel ya están
disponibles y sus cuentas se crean directamente.
