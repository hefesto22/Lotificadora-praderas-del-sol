# Interés y mora configurables — drop del 8-ago-2026

> Implementa `docs/que-le-falta.md` §1. Lo pidió Mauricio mirando el modal
> «Crear plan de pago»: que cada plazo diga si lleva interés y de cuánto, si
> lleva mora y de cuánto, **con las cuatro modalidades disponibles para ver
> cuál aplican**, y que activar interés sea decisión de cada lotificadora.

**Praderas del Sol no cambia.** Todo nace apagado: tasa 0, mora `ninguna`.
R1 y R2 siguen siendo la configuración de fábrica, y con tasa 0 el motor corre
**exactamente el mismo código que corría el 7-ago** (ver 🔴 más abajo).

---

## 🔴 Lo primero: el análisis del §1.2 tenía un error, y era importante

`docs/que-le-falta.md` §1.2 dice que con `i = 0` la fórmula francesa

```
cuota = P × i ÷ (1 − (1+i)^−n)
```

«degenera exactamente en `P ÷ n`», y concluye que por eso habría **un solo
camino de código**. Matemáticamente el límite es correcto. **La cuenta no se
puede hacer**: con `i = 0` el numerador es `P × 0 = 0` y el denominador es
`1 − 1 = 0`. Es `0 ÷ 0`. En float daría `NAN`; en bcmath, división por cero.

Así que son **dos caminos**, y el `if` de la tasa cero no es una optimización
prescindible sino la única forma correcta. Sale de ahí una ventaja que no
estaba prevista y que a doce días del arranque vale mucho: **el cliente que
opera el 20-ago no cambia de camino de código.** El golden test del §9.C9
sigue midiendo el mismo `armar()` de siempre, sin una línea tocada.

---

## Las cuatro decisiones del §1, cómo quedaron

| | Decisión | Dónde vive |
|---|---|---|
| 1 | La tasa va en el **plan de pago** y se **congela en el compromiso** al firmar | `planes_de_pago` + `compromisos` |
| 2 | **Nominal ÷ 12**, no la efectiva equivalente | `TasaDeInteres::mensual()` |
| 3 | **Tabla congelada** al firmar, no devengado por días | `TablaDeAmortizacion` |
| 4 | Las **cuatro modalidades** de mora, ninguna por defecto | `ModalidadDeMora` |

Y dos que aparecieron construyendo:

- **Los días de gracia se cuentan en continuo.** Con 5 días de gracia, el día 6
  paga **un** día de mora, no seis. La lectura dura reintroduce exactamente el
  salto que hizo recomendar la modalidad prorrateada. Está argumentado en el
  docblock de `CondicionesDeMora`.
- **Un abono a capital ahora ahorra intereses, y ese es el número.**
  `EfectoDelAbono::interesesAhorrados()`. Con 12 % a 48 meses, abonar
  L 50,000 puede borrar más de L 30,000 de intereses futuros — eso es lo que
  hace que alguien abone, no los meses.

---

## Lo que se construyó

### Archivos nuevos (van tal cual)

| Archivo | Qué es |
|---|---|
| `app/Domain/Enums/ModalidadDeMora.php` | Las cuatro modalidades + `Ninguna` |
| `app/Domain/Ventas/TasaDeInteres.php` | Porcentaje exacto, escala 20, sin un float |
| `app/Domain/Ventas/CondicionesDeMora.php` | Las cuatro columnas de mora, viajando juntas |
| `app/Domain/Ventas/TablaDeAmortizacion.php` | La tabla francesa, pura |
| `app/Domain/Pagos/CalculoDeMora.php` | Cuánta mora debe UNA cuota |
| `app/Domain/Pagos/MoraDelLote.php` | Cuánta debe el lote entero, sin cobrar dos veces |
| `database/migrations/2026_08_08_120000_interes_y_mora_configurables.php` | El esquema |

### Archivos reescritos (reemplazan al que está)

`PlanDeCuotas` · `CuotaProyectada` · `PlanDelContrato` · `RegistroDePagos` ·
`EfectoDelAbono` · `Cuota` · `PlanDePago` · `PlanesDePagoRelationManager`

### El esquema

- **`planes_de_pago`** — `tasa_interes_anual`, `mora_modalidad`, `mora_monto`,
  `mora_porcentaje`, `mora_dias_gracia`.
- **`compromisos`** — las mismas cinco, **congeladas al firmar**.
- **`cuotas`** — `monto_capital` + `monto_interes` (suman `monto`, lo exige un
  CHECK) y `mora_pagada` + `mora_condonada`.
- **`aplicaciones_de_pago`** — `monto_mora` + `monto_interes` + `monto_capital`
  (suman `monto`) y `mora_condonada` **fuera** de la suma.
- **`recibos`** — `monto_mora`, `mora_condonada`, `motivo_condonacion`,
  `condonada_por`.

Las filas que ya existen se rellenan con todo el monto en capital, que es la
verdad de un plan sin interés.

**El número nunca significa dos cosas.** Dos modalidades se configuran con un
monto en lempiras y dos con un porcentaje, así que son dos columnas y un CHECK
que exige que la que no corresponde esté en cero. Con una sola `mora_valor`
habría que mirar la modalidad para saber si «200» son doscientos lempiras o
doscientos por ciento.

### La imputación cambió: mora → interés → capital

Es el cambio más profundo y toca `RegistroDePagos::repartir()`. Hasta hoy el
FIFO mandaba todo a capital.

1. La **mora** de la cuota más vieja, calculada al vuelo a la fecha del cobro.
2. El **interés** pendiente de esa cuota.
3. El **capital**, que es lo único que baja la deuda.

Y recién entonces la cuota siguiente. Con capital primero, un cliente atrasado
ve bajar su deuda mientras la mora sigue corriendo y nunca sale.
**Esto hay que escribirlo en el contrato con todas las letras.**

Con tasa 0 y sin mora, los pasos 1 y 2 valen cero en todas las cuotas y el
reparto es idéntico al de siempre. No hay dos motores.

### Adentro de una cuota, el interés se paga primero

Y no hizo falta ninguna columna: con `monto_interes` y `monto_pagado` alcanza.
`Cuota::interesPendiente()` y `capitalPendiente()` lo derivan. Un dato derivado
que no se guarda es un dato que no se puede desincronizar.

### Condonar la mora

Trámite con motivo obligatorio, que queda en el recibo con el nombre de quien
lo autorizó. Alcanza a las cuotas que ese pago efectivamente toca — perdonar la
mora de una cuota a la que el dinero nunca llegó no tendría renglón donde
anotarse, y anular el recibo no podría deshacerlo.

⚠️ Condonar **no congela el reloj**: si la cuota sigue vencida, los días que
pasen después vuelven a generar mora. Es lo correcto — el atraso siguió.

---

## Los números, verificados

Calculados con la misma aritmética que usa bcmath (truncado en `bcdiv`,
half-up al exponer) y comparados contra el §1.5 del análisis. **Coinciden al
céntimo**, y `Σ capital = P` exacto en los seis casos:

**RPS-C-010 · L 700,000.00 · 48 meses · sin prima**

| Tasa | Cuota | Última | Σ intereses | Σ total |
|---:|---:|---:|---:|---:|
| 6 % | 16,439.52 | 16,439.57 | 89,097.01 | 789,097.01 |
| 8 % | 17,089.05 | 17,088.83 | 120,274.18 | 820,274.18 |
| 10 % | 17,753.81 | 17,753.72 | 152,182.79 | 852,182.79 |
| 12 % | 18,433.68 | 18,433.96 | 184,816.92 | 884,816.92 |
| 15 % | 19,481.52 | 19,481.82 | 235,113.26 | 935,113.26 |
| 18 % | 20,562.50 | 20,562.52 | 287,000.02 | 987,000.02 |

**Y el golden test §9.C9 con tasa 0, sin tocar:**
71 de L 3,472.22 + última de L 3,472.38 = **L 250,000.00 exacto**.

---

## Todo está aplicado — esto es lo que se tocó de lo que ya existía

Los retoques se aplicaron con `aplicar-interes-y-mora.py` y con dos parches
puntuales. Queda escrito acá porque un `git diff` de 26 archivos no dice **por
qué** cambió cada uno.

| Archivo | Qué se le hizo |
|---|---|
| `PlanDeCuotasInvalidoException` | 5 excepciones nuevas del motor |
| `PagoInvalidoException` | `porFaltarElMotivoDeLaCondonacion()` |
| `Compromiso` | Las 5 columnas al fillable, el cast de la modalidad, `tasaDeInteres()` y `condicionesDeMora()` |
| `Recibo` | `monto_mora`, `mora_condonada`, `motivo_condonacion`, `condonada_por` + sus lectores |
| `AplicacionDePago` | El desglose mora/interés/capital + `montoALaCuota()` |
| `RoleSeeder` | `CondonarMora:Recibo`, solo la administradora |
| `ListaDePrecios` | `planParaPlazo()` — devuelve el plan entero, no solo el precio |
| `RegistroDeCompromisos` | `vender()` recibe tasa y mora y las **congela** |
| `RegistroDeVentas` | Busca el plan del plazo, arrastra tasa y mora por los tres pasos, y **cierra contra el capital** |

### Los tres cambios de `RegistroDeVentas` que hay que mirar

**1 · El contrato cierra contra el CAPITAL.**

```php
if (! $contrato->totalCapital()->igualA($saldo)) {
```

Con interés, `total()` da capital + intereses y **toda venta financiada se
rechazaría**. Sin interés los dos números son el mismo, así que esto compara
lo que comparaba antes.

**2 · El plan del plazo se busca una sola vez.**

`congelarPrecios()` ya recibía el proyecto y ya leía el precio de lista de ese
plazo. Ahora lee el plan **entero** —precio, tasa y mora— y lo congela junto.
Buscarlo una vez es lo que impide que alguien copie el precio y se olvide de
la tasa.

**3 · Las cuotas nacen partidas.**

`asentarCuotas()` escribe `monto_capital` y `monto_interes`. El CHECK
`cuotas_partes_suman_el_monto_chk` no deja pasar una fila que no cuadre, ni
siquiera en un insert masivo de 120.

---

## Cómo se corre

```bash
herd php artisan migrate
herd composer ci && bash storage/app/verificar-pagos.sh
```

⚠️ El seeder de roles hay que volver a correrlo para que exista
`CondonarMora:Recibo`.

⚠️ Quedó `storage/app/_analisis/interes-y-mora-8-ago.zip` (gitignoreado) con
el drop entero, por si algo hay que revertir. Se puede borrar.

---

## Lo que hay que verificar, en este orden

1. 🔴 **El golden test del §9.C9 tiene que pasar sin tocarlo.** Si no pasa, algo
   del camino sin interés se movió y eso es lo único que puede afectar a
   Praderas del Sol el 20-ago. Es la prueba de que este drop no lo tocó.
2. **Los 618 tests.** Varios asumen «sin interés» y algunos van a fallar por
   la firma de `CuotaProyectada` —ahora pide capital e interés— y por
   `cierraExacto()`, que compara capital. Los dos cambios son correctos; las
   llamadas viejas se arreglan con `CuotaProyectada::sinInteres(...)`.
3. **PHPStan nivel 7.** Miré una por una las trampas conocidas (nada de
   `findOrFail()`, nada de `config()` sin castear, `first()` tipado). El
   `array{cobrada: Monto, condonada: Monto}` de `repartir()` está anotado.
### `numeric-string` se pierde al cruzar un parámetro `string`

PHPStan nivel 7 tiró 4 errores por esto, todos del mismo molde. `TasaDeInteres::mensual()`
promete `numeric-string`, pero el parámetro que lo recibe está declarado `string` —el
tipo de PHP no sabe decir otra cosa— y ahí la garantía se cae. Cada llamada a bcmath
aguas abajo pasa a ser «expects numeric-string, string given».

Se arregla con `@param numeric-string` en el docblock, que es exactamente lo que hace
`Monto::$valor` con su `@var`. Y donde el valor viene de afuera —la regla del formulario—
va un `is_numeric()`, que **no valida de más**: es lo único que estrecha el tipo.

4. **Pint.** Los `=>` se alinean al key más largo del grupo y un comentario
   parte el grupo en dos; los comentarios de array quedaron arriba.
5. **La línea de Filament que hay que mirar.** `PlanesDePagoRelationManager`
   importa `Filament\Schemas\Components\Utilities\Get`. Lo confirmé contra la
   documentación de Filament 4; si en 5.7 se movió, es el único import que
   cambia.

⚠️ **No pude correr nada de esto.** Packagist está bloqueado desde el
contenedor y el contenedor no tiene bcmath, así que verifiqué **sintaxis** con
`php -l` en los 15 archivos y **la aritmética** con un modelo aparte que imita
las semánticas de bcmath. La cadena completa la corrés vos.

---

## 🔴 El tope legal sigue abierto

Busqué y **no hay un número que se pueda citar**. La **Ley de Créditos
Usurarios de Honduras (Decreto 100-62)** no fija un porcentaje en su texto:
delega en la Secretaría de Finanzas el máximo no bancario —que no puede
exceder en seis puntos el máximo de las operaciones bancarias— y además habla
de contratos de **préstamo**, no de compraventa a plazo. O sea que ni siquiera
está claro que aplique a una lotificación.

El CHECK de la migración tiene un tope de **120 %**, y es **de cordura**: frena
un 1200 donde iba 12.00. No dice que 119 % sea legal.

**Antes de que cualquier lotificadora ofrezca una tasa, hay que preguntarle a
un abogado.** Ponerlo mal no es un bug: es una cláusula impugnable. Yo no soy
abogado y esto no es asesoría legal.

---

## Lo que este drop NO hace

- **Condonar mora sin cobrar nada.** Hoy se condona dentro de un cobro, que es
  el caso del mostrador. Perdonar la mora de un cliente que no viene a pagar
  necesita su propia acción — y como un recibo no puede ser de L 0.00, necesita
  también decidir qué documento la respalda.
- **El estado de cuenta y el recibo impreso todavía no muestran las columnas
  de capital, interés y mora.** Los datos están y son correctos; falta la
  presentación. Con tasa 0 se ven igual que hoy, así que **no bloquea el
  20-ago**. Es el siguiente drop, y es corto.
- **Avisos de mora al cliente.** Fuera de alcance, como estaba.
- **El tope legal.** Ver arriba.
