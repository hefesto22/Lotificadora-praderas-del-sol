# Dónde quedamos — 6-ago-2026

Traspaso entre sesiones. Lo que hay que saber para seguir sin releer todo.

---

## Lo último: R21, el abono a capital

**Terminado y verificado el 6-ago-2026.** `bash storage/app/verificar-pagos.sh`
en verde: Pint 639 archivos, PHPStan nivel 7 243/243 sin errores, Rector sin
cambios pendientes, **537 tests / 3,090 assertions**.

Los dos caminos que elige el cliente —misma cuota con menos meses, o mismos
meses con la cuota más baja— salen del mismo motor: `PlanDeCuotas` ganó
`porPlazoFijo()` y `nuevo()` ahora delega en él, así que el residuo se reparte
igual en los tres constructores y el golden test del §9.C9 los cubre a todos.

Lo que se decidió al escribirlo, con su razón, está en `docs/dominio.md`
(R21, cuadro «Construido el 6-ago-2026»). En corto:

- **Tabla `reprogramaciones`** con el plan viejo completo en `jsonb`. `cuotas`
  sigue significando una sola cosa: el contrato de hoy.
- **Un recibo, no dos**, de concepto `abono_capital`, con sus aplicaciones por
  la parte que puso al día.
- **`Reprogramar:Venta` es de la administradora.** El receptor cobra pero no
  reescribe un plan firmado. Hay un test que prueba exactamente esa frontera:
  ve el botón de cobrar y no ve el de abonar.
- **Si no alcanza ni para lo vencido**, se registra como pago normal y la
  notificación lo dice.

**El tope del abono no es el saldo del lote**, y esto es lo más fácil de
olvidar: lo que le falta a una cuota pagada a medias queda fuera del alcance.
Respetarla significa no tocarla, ni para cobrarla de paso.

**Después de R21:** R22 (rescindir un lote, con su liquidación) comparte la
mitad de esa maquinaria — el `EfectoDelAbono` y la constancia. R20 (condonar
una cuota) quedó diferido a después del 11-sep.

---

## Cómo se viene trabajando

- **Claude edita los archivos, Mauricio corre los comandos.** Claude no toca
  git: escribe scripts en `storage/app/` y Mauricio los ejecuta.
- **La puerta de verificación** es `bash storage/app/verificar-pagos.sh`:
  migra, revisa el blade del plano, Pint, PHPStan nivel 7, Rector en dry-run,
  los tests del área y la suite entera. **Nada se da por bueno sin eso en
  verde.** Hoy: 537 tests.

  ⚠️ Pint corre ANTES que Rector en el script, y Rector deja código que Pint
  quiere reformatear. Si Rector pide algo, el orden que evita la ida y vuelta
  sigue siendo el de siempre: `rector:fix && lint && ci && rector`.
- Los commits van en tandas, con mensajes que explican **por qué**, no qué.

## Cosas que ya mordieron, para no repetirlas

- **Filament permite lo que no tiene política.** Todo modelo nuevo necesita la
  suya, y los permisos se nombran **uno por uno** en `RoleSeeder` (§9.E3) —
  nunca por patrón. Los que no salen del cruce acciones × recursos
  (`Reprogramar:Venta`, `ViewAny:Reprogramacion`) van también uno por uno en
  `tests/Pest.php`, o el super_admin de los tests no los tiene.
- **Una lista de opciones tiene que contener siempre el estado actual**, aunque
  ese valor ya no califique. Si no, Filament devuelve un conjunto vacío de
  valores permitidos y tumba el formulario entero con un mensaje que no nombra
  a nadie. Pasó dos veces: con `lotes_extra` y con `copropietarios`.
- **El dominio verde no significa la pantalla viva.** Toda acción de Filament
  necesita un test que la **dispare**, no que solo renderice la página.
- **Un número resumido en `ventas` se desactualiza solo.** `plazo_meses` y
  `cuota_mensual` los recalcula el abono desde `cuotas`; ojo con el CHECK
  `ventas_cuota_segun_plazo_chk`, que exige que los dos digan lo mismo (plazo 0
  ⟺ cuota nula). Lo mismo pasaba en la tabla de cuotas con «Cuota 9 de 12»:
  el «de M» ahora se cuenta, no se lee de `compromisos.plazo_meses`.
- **Un `if (a || b) { continue; }` adentro de un `foreach` lo rechaza Rector**
  (`ChangeOrIfContinueToMultiContinueRector`): va un `continue` por condición.
  Pasa PHPStan y Pint sin chistar, y lo agarra recién el paso 4. Ya había
  pasado en `7e1fe56`.
- Al insertar un método, cuidado de no meterlo **entre un docblock y su
  función**: se roba la anotación `@return` y PHPStan culpa a otro archivo.
- Las clases CSS propias van en `resources/views/filament/estilos-olympo.blade.php`,
  no como utilidades de Tailwind: el CSS de Filament se compila aparte y no ve
  lo que arma un `HtmlString` del lado de PHP.

## Estado, de lo que salió de la reunión del 6-ago

| | |
|---|---|
| Plazo y prima por lote, con tramos | ✅ |
| Copropietario ≠ titular | ✅ |
| Plan de cuotas en el expediente | ✅ |
| Registrar un pago (FIFO, parciales, recibo) | ✅ dominio y pantalla |
| Promesa de venta adjunta | ✅ |
| Abono a capital (R21) | ✅ dominio y pantalla |
| Rescindir un lote (R22) | ⬜ **lo próximo** |
| Condonar una cuota (R20) | ⬜ diferido |

**Pendiente sin decidir:** si el receptor puede subir documentos o solo verlos
(hoy solo ve). Es un `Create:Documento` nombrado aparte, como el de `Recibo`.

**Para el 11-sep:** el recibo impreso todavía no existe como documento.
`spatie/browsershot` ya está instalado; falta la plantilla y la reimpresión.
Cuando se haga, el recibo de un abono tiene que imprimir sus dos renglones:
lo que puso al día y lo que bajó el capital.
