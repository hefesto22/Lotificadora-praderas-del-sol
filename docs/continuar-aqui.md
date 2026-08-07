# Continuar acá — 6-ago-2026, cierre del día

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
| Tests | 618 verdes antes del último drop (R14 + obligaciones sin verificar todavía) |
| PHPStan | 271/271, nivel 7 |
| Pint / Rector | limpios |
| Plano real | **cargado: 301 lotes, 0 sin dibujar** |

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
bash storage/app/verificar-pagos.sh
```

El drop 4 nunca pasó por la puerta. Todo lo demás está commiteado y pusheado.

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

## 🟡 La decisión que espera a Mauricio: el CAI

**El contrato y la contratante se contradicen, y nadie lo había notado.**

- **Cláusula Segunda, módulo g-ii**, Etapa 1: «documentos con **CAI**: registro de CAI vigente,
  rangos, fecha límite, control de talonario manual y **alertas de agotamiento**» → tablas
  `cais`, `rangos_cai`.
- **R10** (contestada por la contratante el 3-ago): **no se usa CAI**, los recibos son internos.

Hoy no hay ni una línea de eso, y **no se construyó a propósito**: R10 es una respuesta explícita
de la clienta y levantar un módulo de CAI sobre una regla contradicha sería inventar. Pero el
contrato es el documento que obliga.

**Con 14 días encima esto es lo primero que hay que resolver.** Las salidas posibles:
que la contratante ratifique R10 por escrito y quede como alcance reducido, o que el CAI entre
y haya que hacerle lugar en el calendario.

## Lo que queda, contra el contrato

| Módulo | Etapa | Estado |
|---|---|---|
| a Clientes · b Lotes · c Ventas · d Contratos | 1 | ✅ |
| e Promesa de venta | 1 | ✅ `documentos` + relation manager |
| f Apartados con recibo y control de vigencia | 1 | ✅ (drop 4) |
| g-i Recibo interno correlativo | 1 | ✅ |
| **g-ii CAI** | 1 | 🟡 **decisión pendiente, ver arriba** |
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

1. **El CAI** — ver arriba. Es el que corre.
2. Si los 301 lotes ya tienen sus **precios reales**, y si la cartera vendida vieja se va a
   cargar (R15). Los 3 vendidos y 1 apartado de la captura son pruebas nuestras.
3. Si el receptor puede subir documentos o solo verlos (hoy solo ve).
4. El tamaño de papel del recibo no se consultó con la contratante.
5. `APP_DEBUG=true` — en local está bien; antes de salir a un servidor tiene que ser `false`,
   o un error cualquiera le muestra la consulta con datos del cliente a quien esté mirando.
6. El README todavía describe la plantilla, no el proyecto.
