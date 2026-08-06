# Dónde quedamos — 6-ago-2026

Traspaso entre sesiones. Lo que hay que saber para seguir sin releer todo.

---

## Lo próximo: R21, abono a capital

Está **sin empezar**. La regla completa está en `docs/dominio.md` (R21), con los
dos detalles que se decidieron el 6-ago y que cambian la aritmética:

- **Con cuotas vencidas, el abono primero pone al día** (FIFO), y solo el
  sobrante va a capital. Si no alcanza ni para lo vencido, es un pago normal y
  **no hay reprogramación**.
- **La cuota pagada a medias se respeta.** El plan nuevo empieza en la
  siguiente. Lo ya pagado no se toca nunca, y así el recibo viejo sigue
  apuntando a una cuota que existe.

Los dos caminos los elige el cliente: **misma cuota, menos meses** (default
histórico, R3) o **mismos meses, cuota más baja**.

Falta decidir al escribirlo: dónde queda constancia de la reprogramación. La
idea era una tabla propia con motivo, quién y el plan anterior — porque hay que
poder explicar por qué el número cambió.

**Después de R21:** R22 (rescindir un lote, con su liquidación) comparte la
mitad de esa maquinaria. R20 (condonar una cuota) quedó diferido a después del
11-sep.

---

## Cómo se viene trabajando

- **Claude edita los archivos, Mauricio corre los comandos.** Claude no toca
  git: escribe scripts en `storage/app/` y Mauricio los ejecuta.
- **La puerta de verificación** es `bash storage/app/verificar-pagos.sh`:
  migra, revisa el blade del plano, Pint, PHPStan nivel 7, Rector en dry-run,
  los tests del área y la suite entera. **Nada se da por bueno sin eso en
  verde.** Hoy: 496 tests.
- Los commits van en tandas, con mensajes que explican **por qué**, no qué.

## Cosas que ya mordieron, para no repetirlas

- **Filament permite lo que no tiene política.** Todo modelo nuevo necesita la
  suya, y los permisos se nombran **uno por uno** en `RoleSeeder` (§9.E3) —
  nunca por patrón.
- **Una lista de opciones tiene que contener siempre el estado actual**, aunque
  ese valor ya no califique. Si no, Filament devuelve un conjunto vacío de
  valores permitidos y tumba el formulario entero con un mensaje que no nombra
  a nadie. Pasó dos veces: con `lotes_extra` y con `copropietarios`.
- **El dominio verde no significa la pantalla viva.** Toda acción de Filament
  necesita un test que la **dispare**, no que solo renderice la página.
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
| Abono a capital (R21) | ⬜ **lo próximo** |
| Rescindir un lote (R22) | ⬜ |
| Condonar una cuota (R20) | ⬜ diferido |

**Pendiente sin decidir:** si el receptor puede subir documentos o solo verlos
(hoy solo ve). Es un `Create:Documento` nombrado aparte, como el de `Recibo`.

**Para el 11-sep:** el recibo impreso todavía no existe como documento.
`spatie/browsershot` ya está instalado; falta la plantilla y la reimpresión.
