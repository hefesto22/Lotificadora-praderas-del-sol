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
