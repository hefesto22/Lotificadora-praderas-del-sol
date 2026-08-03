# Preguntas para doña Rosa Elena

**Residencial Praderas del Sol — Sistema de Gestión Inmobiliaria**
Preparado por Inversiones Olympo · 3 de agosto de 2026

---

## Por qué existe este documento

El sistema tiene que calcular dinero: cuotas, saldos, moras, recibos. Para eso necesita saber **cómo se cobra hoy en Praderas del Sol**, no cómo se cobra en general. Dos lotificadoras vecinas pueden manejar la mora de forma distinta y las dos tienen razón.

Cada respuesta de acá abajo se convierte en una regla del sistema. Si el sistema adivina y adivina mal, el error aparece meses después en el estado de cuenta de un cliente — y ahí ya hay dinero de por medio.

**Cómo contestar:** marcá la opción que corresponde, o escribí la respuesta al lado. Si alguna no aplica o todavía no está decidida, poné *"no aplica"* o *"pendiente"* — eso también es una respuesta útil, porque me dice que no lo programe todavía.

Las preguntas del **Bloque 1** son las urgentes: sin ellas no se puede construir el motor de cuotas, que es el corazón de todo lo demás.

---

## BLOQUE 1 — Cómo se cobra (bloquea el motor de cuotas)

### 1. ¿El saldo financiado genera interés?

- [ ] **No.** El precio del lote ya incluye todo. Las cuotas son el saldo dividido entre los meses.
- [ ] **Sí**, se cobra interés de ____ % ( ) mensual ( ) anual, sobre el saldo.
- [ ] Otro arreglo: _______________________________________________

> **Por qué importa:** cambia por completo cómo se arma el plan de pagos. Si hay interés, cada cuota tiene una parte de capital y otra de interés, y el estado de cuenta tiene que mostrarlas separadas.

---

### 2. ¿Se cobra mora cuando un cliente se atrasa?

- [ ] **No se cobra mora.**
- [ ] **Sí**, se cobra: ( ) ____ % sobre la cuota atrasada ( ) L ________ fijo por cuota atrasada
- [ ] Se cobra ( ) una sola vez ( ) por cada mes de atraso ( ) por cada día de atraso

**¿Hay días de gracia antes de que empiece a correr la mora?**

- [ ] No, corre desde el día siguiente al vencimiento.
- [ ] Sí, ________ días.

> **Por qué importa:** el sistema tiene que decir el saldo exacto el día que el cliente llega a preguntar. Si la mora se calcula distinto en el sistema y en el cuaderno, uno de los dos está mal frente al cliente.

---

### 3. Si un cliente abona de más (un pago extraordinario a capital), ¿qué pasa?

- [ ] **Se acorta el plazo**: sigue pagando la misma cuota, pero termina antes.
- [ ] **Se baja la cuota**: sigue pagando los mismos meses, pero paga menos cada mes.
- [ ] **Lo decide el cliente** en el momento.
- [ ] No se aceptan abonos extraordinarios.

> **Por qué importa:** son dos cálculos completamente distintos. Y si lo decide el cliente, el sistema tiene que preguntarlo al registrar el pago.

---

### 4. ¿Hay descuento por pagar de contado o por pagar antes?

- [ ] **No.**
- [ ] **Sí, por pago de contado:** ____ % de descuento.
- [ ] **Sí, por pronto pago de la cuota** (antes de la fecha): ____ % o L ________
- [ ] Se negocia caso por caso.

> **Por qué importa:** si es caso por caso, el sistema tiene que dejar registrar el descuento a mano **y con motivo**, para que después se sepa quién lo autorizó.

---

### 5. ¿La prima se puede pagar en varios abonos?

- [ ] **No.** La prima se paga completa y ahí se firma el contrato.
- [ ] **Sí.** El cliente abona la prima de a poco, y el contrato se activa cuando termina de pagarla.
- [ ] **Sí**, y el contrato se firma desde el primer abono.

> **Por qué importa:** define en qué momento el lote pasa de *apartado* a *vendido*, cuándo se genera el plan de cuotas y cuándo se consume el número de contrato. Es la diferencia entre una venta que existe a medias y una que ya está firme.

---

### 6. Si una venta se cae (rescisión), ¿qué pasa con lo que el cliente ya pagó?

- [ ] Se le devuelve **todo**.
- [ ] Se le devuelve **una parte**: ____ %, o se le retiene L ________ / ____ %.
- [ ] **No se devuelve nada**, se pierde a favor de la lotificadora.
- [ ] Se negocia caso por caso.

**¿El lote vuelve a quedar disponible para vender?**

- [ ] Sí, de inmediato. &nbsp;&nbsp; [ ] Sí, después de ________ &nbsp;&nbsp; [ ] Lo decide la administración.

> **Por qué importa:** es la única situación donde el sistema tendría que "deshacer" una venta. Si no está definida, el día que pase se resuelve a mano y el reporte queda mintiendo.

---

## BLOQUE 2 — Contratos y expedientes

### 7. El número de expediente y el número de contrato, ¿son el mismo número?

- [ ] **Son el mismo.** Un solo número identifica todo.
- [ ] **Son distintos.** El expediente se abre al empezar el trámite y el contrato se numera al firmar.

**Si son distintos, ¿cómo se ve cada uno?** (ejemplo real, aunque sea de un expediente viejo)

- Expediente: _______________________
- Contrato: _______________________

> **Por qué importa:** el sistema genera estos números solo y no se pueden repetir nunca. Necesito saber si son una serie o dos.

---

### 8. ¿Un lote puede tener dos dueños (copropietarios)?

- [ ] **No.** Un lote, un cliente.
- [ ] **Sí**, marido y mujer o socios pueden aparecer los dos en el contrato.

**Si es sí:** ¿los dos pueden pagar y pedir el estado de cuenta, o hay uno que es el titular?

_______________________________________________________________

> **Por qué importa:** cambia cómo se guarda la venta y a nombre de quién sale el recibo. Es mucho más barato saberlo ahora que después de cargar 500 lotes.

---

### 9. ¿Una misma venta puede incluir varios lotes?

- [ ] **Sí**, un cliente puede comprar dos o tres lotes juntos en un solo contrato.
- [ ] **No**, un contrato por lote.

> **Por qué importa:** el formulario que usan hoy dice "Bloque(s)" y "Lote(s)" en plural, así que asumí que sí — pero prefiero que quede confirmado por escrito.

---

## BLOQUE 3 — Recibos, CAI y dinero que entra

### 10. Necesito la **foto de un talonario real con CAI** (ya usado o en blanco)

Necesito ver:

- El número de CAI completo, tal como viene impreso.
- El rango autorizado (desde qué número hasta qué número).
- La fecha límite de emisión.
- Cómo se ve un recibo lleno.

> **Por qué importa:** el sistema tiene que avisar cuando el talonario se está acabando o cuando se acerca la fecha límite. Para eso necesita validar el formato exacto, y ese formato lo fija el SAR. **Adivinarlo es garantía de error.**

---

### 11. ¿Qué formas de pago se reciben?

- [ ] Efectivo &nbsp;&nbsp; [ ] Transferencia &nbsp;&nbsp; [ ] Depósito bancario &nbsp;&nbsp; [ ] Cheque
- [ ] Otra: _______________________

**Para transferencias y depósitos, ¿se anota el número de referencia?** [ ] Sí [ ] No

---

### 12. Los recibos internos, ¿llevan una sola numeración o una por receptor?

- [ ] **Una sola numeración** para toda la lotificadora.
- [ ] **Una numeración por cada receptor** (don Elder una serie, don Edwin otra).

> **Por qué importa:** el número de recibo no se puede repetir jamás. Si hay dos personas cobrando al mismo tiempo desde distintos lugares, esto define cómo se reparten los números.

---

### 13. ¿Se le puede cobrar a un cliente sin que esté asignado a una venta todavía?

Por ejemplo: alguien llega a abonar antes de firmar nada.

- [ ] **No**, siempre hay una venta o un apartado de por medio.
- [ ] **Sí**, y después se aplica a lo que corresponda.

---

## BLOQUE 4 — Apartados

### 14. ¿Cómo funciona apartar un lote?

- **Monto que se cobra por apartar:** L ________ &nbsp; o ____ % del valor del lote
- **Cuánto tiempo dura el apartado:** ________ días
- **¿Se puede prorrogar?** [ ] Sí, ________ días más [ ] No

**Si el apartado vence y el cliente no vuelve:**

- [ ] El lote queda disponible y **el dinero se pierde**.
- [ ] El lote queda disponible y **el dinero se devuelve**.
- [ ] Lo decide la administración caso por caso.

**Cuando el apartado sí se convierte en venta:**

- [ ] El monto del apartado **cuenta como parte de la prima**.
- [ ] Es aparte, no cuenta.

---

## BLOQUE 5 — Datos para cargar el sistema

### 15. ¿Cómo me van a entregar la información de los lotes?

- [ ] En un **archivo de Excel** con todos los lotes.
- [ ] En el **plano** (PDF o impreso) y alguien los digita.
- [ ] En un cuaderno o listado en papel.
- [ ] Otra forma: _______________________

> **Por qué importa:** si viene en Excel, escribo algo que los carga todos de una vez. Si hay que digitarlos a mano, son unas cuantas horas de alguien y hay que preverlo en el calendario. Son unos 500 lotes.

---

### 16. ¿Cuántos metros tiene una vara?

En el sistema está puesto **0.8359 m** por vara (vara castellana), pero el valor varía según la fuente.

- [ ] Ese valor está bien.
- [ ] Usamos otro: ________ m por vara.
- [ ] No sabría decir — preguntar al topógrafo o al abogado.

> **Por qué importa:** ningún cálculo de dinero depende de esto, porque las áreas se guardan en varas² y los precios son por vara². Solo afecta la conversión a metros² que se muestra en pantalla. Pero **si ese número va a salir impreso en un contrato o en una escritura, tiene que ser el correcto.**

---

## BLOQUE 6 — Para confirmar por escrito

### 17. ISV en las ventas

Entiendo que **la venta de un lote no lleva ISV** por ser un bien inmueble.

- [ ] Confirmado por el contador.
- [ ] Hay que consultarlo.

> **Por qué importa:** si el sistema imprime un ISV que no corresponde —o deja de imprimir uno que sí— el problema es con el SAR, no conmigo.

---

### 18. Las dos etapas y el plazo de entrega

El contrato fija el sistema **en operación el viernes 11 de septiembre de 2026** (día hábil 30 desde el 1 de agosto).

Mi entendimiento es que ese plazo corresponde a la **Etapa 1**: clientes, lotes, ventas, contratos, promesa de venta, apartados, documentos de cobro y estado de cuenta. La **Etapa 2** —gastos, arqueo por receptor, expediente digital, libro maestro y reportes— viene después.

- [ ] Es correcto.
- [ ] No es lo que entendí. Lo que se acordó fue: _______________________

> **Por qué importa:** es la diferencia entre entregar a tiempo y no entregar. Prefiero que quede aclarado ahora y no el 11 de septiembre.

---

## Ya definido — no requiere respuesta

Se deja por escrito para que quede constancia.

**Expediente digital.** El único documento que se sube al sistema por cliente es la **promesa de venta**. Se conserva de forma indefinida, o hasta que el almacenamiento se migre a un servicio externo. El contrato incluye 25 GB y el excedente se cobra a L 200 por GB al año; con un solo documento por cliente, esa capacidad está holgada.

**Usuarios del sistema.** Los datos de las personas que van a usar el panel ya están disponibles y sus cuentas se crean directamente, sin necesidad de consultarlo acá.

---

## Resumen de lo que necesito

| # | Qué | Urgencia |
|---|---|---|
| 1 a 6 | Cómo se cobra: interés, mora, abonos, descuentos, prima, rescisión | **Bloquea el motor de cuotas** |
| 7 a 9 | Numeración de contratos, copropietarios, varios lotes por venta | Alta |
| 10 | **Foto de un talonario con CAI** | Alta |
| 11 a 14 | Formas de pago, numeración de recibos, apartados | Media |
| 15 y 16 | Cómo llegan los lotes, factor de la vara | Media |
| 17 y 18 | ISV y alcance de las etapas | Confirmación por escrito |

---

*Las respuestas se guardan en este mismo documento y pasan a ser la fuente de verdad del sistema. Si algo cambia después, se cambia acá primero.*
