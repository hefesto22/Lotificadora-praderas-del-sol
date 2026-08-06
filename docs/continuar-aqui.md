# Dónde quedamos — 6-ago-2026

Traspaso entre sesiones. Lo que hay que saber para seguir sin releer todo.

---

## Lo último: el estado de cuenta (Cláusula Segunda)

**Terminado y verificado el 6-ago-2026.** `bash storage/app/verificar-pagos.sh`
en verde a la primera: **595 tests / 3,296 assertions**.

Uno por contrato con una sección por lote, el plan cuota por cuota, y el mismo
HTML imprimible del recibo pero **sin registrar la salida** — no lleva
correlativo y no acredita un pago, así que dos copias no crean riesgo. La razón
de cada decisión está en `docs/dominio.md`, bajo R2.

Los totales viven en `EstadoDeCuenta` y `CuentaDelLote`, dos objetos de dominio
sin base de datos: es la parte que un cliente revisa con una calculadora en la
mano, y así se puede probar que cierra sin renderizar una línea de HTML. Hay un
test que verifica el invariante del documento — lo de arriba es la suma de lo
de abajo, y coincide con `Venta::saldoPendiente()`.

**De paso salió un middleware.** `UsuarioActivoDelPanel` reemplaza los `if` que
el controlador del recibo tenía copiados: sesión vencida al panel, cuenta dada
de baja 403. El día que aparezca el tercer documento no hay que acordarse de
copiarlos.

---

## Lo anterior: el recibo impreso (módulo h del contrato)

**Terminado y verificado el 6-ago-2026**, commiteado y pusheado en `a08c997`.
573 tests / 3,185 assertions.

Hasta hoy el sistema emitía recibos con número y **no había una sola pantalla
que los mostrara**: se cobraba, se quemaba un correlativo y no había papel que
entregar. Eso ya no.

Lo decidido, con su razón, está en `docs/dominio.md` (R10, cuadro «Construido
el 6-ago-2026»). En corto:

- **HTML imprimible, no PDF.** Sin CAI no hay requisito de formato. Cero
  dependencias nuevas y anda desde el teléfono. `spatie/browsershot` sigue en
  `composer.json` sin usarse — sacarlo o usarlo es decisión de otro día.
- **Se busca por número**, que es lo único que trae quien llega con el papel.
- **Reimprimir queda registrado** y el papel dice COPIA de la segunda vez en
  adelante. Abrir la vista imprimible ES imprimir; para solo mirar está la
  ficha del recibo, que no registra nada.
- **La cantidad va en letras.** `MontoEnLetras` es un value object puro, con su
  test: «MIL» y nunca «UN MIL», «CIEN» pelado y «CIENTO UN» en cuanto le sigue
  algo, «un millón DE lempiras» pero «dos millones quinientos mil lempiras».

**Los datos del emisor son de la contratante, no de Olympo**, y viven en
`config/lotificadora.php` bajo `emisor`. Olympo presta el software y no aparece
en ningún documento del negocio.

---

## Lo que falta de la Etapa 1, contra la Cláusula Segunda

El 11-sep es la fecha dura. Contra el contrato, esto es lo que queda:

| Módulo contratado | Estado |
|---|---|
| a) Clientes · b) Lotes · c) Ventas · d) Contratos | ✅ |
| e) Promesa de venta | ✅ |
| h) Documentos de cobro | 🟡 **incompleto** — ver abajo |
| **Estado de cuenta** | ✅ |
| **Apartados** | ⬜ **lo próximo del contrato** — ver abajo |

---

## ⚠️ Lo que falta de verdad: el dinero que no emite papel

Hallazgo del 6-ago-2026, revisando apartados. El módulo h) se dio por completo
cuando se construyó el recibo, **y no lo está**: cubre las cuotas y el abono a
capital, no el dinero de la firma.

| Hueco | Qué pasa hoy |
|---|---|
| **La prima no emite recibo** | El cliente entrega L 50,000.00 al firmar —el pago más grande del contrato— y se va sin papel. `ConceptoDeRecibo::Prima` existe en el enum y **no lo usa nadie** |
| **La seña tampoco** | Igual con `::Senia`. Se aparta, se anota `monto_senia` en el compromiso, y no hay recibo |
| **La seña no cuenta en la prima** | R14 dice que al convertirse en venta cuenta como parte de la prima. **No cuenta**: `monto_senia` no se lee en ningún lado, así que al firmar se cobra la prima completa otra vez |
| **No hay prórroga** | R14 la pide, única y con quién la autorizó. `config/lotificadora.php` ya tiene `prorrogas_maximas = 1` y no hay código que lo use |
| **No existe el dinero que SALE** | La devolución del apartado vencido (R14) y la liquidación de una rescisión (R6/R22) no tienen dónde registrarse |

Lo que sí está bien: `vender()` cierra el apartado vigente como `Convertido`,
así que el vínculo entre el apartado y la venta ya existe en la historia del
lote. No hay que inventarlo.

### Las tres decisiones ya tomadas (6-ago-2026)

1. **El dinero que sale va en una tabla `egresos` propia**, con su correlativo,
   su motivo, quién autorizó y su comprobante imprimible —la misma maquinaria
   del recibo, que ya está construida—. No se reusa `recibos` con un concepto
   `devolucion`: hoy «recibo» significa dinero que entró en todo el sistema (el
   estado de cuenta, `montoAplicadoACuotas()`, el arqueo), y meterle egresos
   obliga a revisar cada lugar que suma recibos. R22 va a necesitar esta misma
   tabla para la liquidación, así que se paga una vez y sirve dos veces.
2. **La seña se descuenta de lo que se COBRA, no de la prima del contrato.** El
   contrato sigue diciendo prima L 50,000.00 (R5 no cambia) y al firmar se
   muestra «prima L 50,000.00, seña aplicada L 5,000.00, a cobrar L 45,000.00».
   El expediente conserva los dos renglones para poder explicar después por qué
   entró menos dinero del que dice la prima.
3. **Prorrogar lo puede el receptor; devolver, solo la administradora.**
   Prorrogar no mueve dinero y es lo que pasa en ventanilla. Sacar plata de la
   caja lleva autorización explícita: «el dinero no debería salir de la caja
   por un vencimiento de calendario».

### 4. Un recibo POR LOTE, no uno por pago (6-ago-2026)

Alguien aparta tres lotes y entrega L 15,000.00 de una vez. Salen **tres
recibos de L 5,000.00**, uno por apartado.

Rompe con el criterio del abono a capital —«un pago, un papel»— y a propósito:
`recibos.compromiso_id` apunta a UN compromiso, y el apartado es del lote. Si
mañana se libera uno solo de los tres y hay que devolver su seña, el papel que
la respalda existe y dice exactamente L 5,000.00. Con un recibo por los
L 15,000.00 haría falta una tabla pivot `recibo_compromiso`, y la devolución
parcial habría que calcularla en vez de leerla.

### El inventario, para no volver a levantarlo

`RegistroDeCompromisos` **no tiene constructor** y hay que inyectarle
`ConsumoDeCorrelativos` para que pueda numerar el recibo. Eso rompe **seis
llamadores** que lo instancian con `new`:

| Archivo | Línea |
|---|---|
| `VerPlano.php` — apartarVarios | 284 |
| `VerPlano.php` — liberar | 566 |
| `RegistroDeCompromisosTest.php` | 30 |
| `VenderDesdeElPlanoTest.php` | 339, 442, 464 |

Todos pasan a `app(RegistroDeCompromisos::class)`, que es lo que manda el
§9.C1 de todos modos.

Y `apartar()` necesita **forma de pago y referencia** —lo exige el CHECK
`recibos_referencia_cuando_hace_falta_chk` (R11)— así que el modal del plano
suma dos campos. Ojo: **cuatro tests disparan ese modal**
(`VenderDesdeElPlanoTest`, líneas 370, 393, 413, 445) pasando solo
`cliente_id`. Si `forma_pago` queda requerido sin default en `fillForm`, los
cuatro se caen.

### Cómo conviene partirlo

- **Primero el dinero que entra**: recibo de seña al apartar, recibo de prima
  al firmar con la seña ya descontada. Es donde hoy se pierde plata de vista, y
  es el prerrequisito de la devolución —para devolver hay que saber cuánto
  entró—.
- **Después R14 completo**: prórroga, vencimiento y la tabla `egresos` con su
  comprobante.

---

**Fuera del contrato, pedido por la contratante el 6-ago:** R22 (rescindir un
lote) y R20 (condonar una cuota, diferido a después del 11-sep). R22 reusa
`EfectoDelAbono` y la tabla `reprogramaciones` — `recibo_id` quedó nullable
justo para eso, porque una rescisión no tiene dinero entrando.

**Riesgo de calendario decidido el 6-ago:** los ~500 lotes llegan en papel
(R15) y **se acordó construir la pantalla de carga rápida antes del 11-sep** —
teclado, sin mouse, sin recargar entre lote y lote. Todavía no está en el plan
de ninguna sesión.

---

## Cómo se viene trabajando

- **Claude edita los archivos, Mauricio corre los comandos.** Claude no toca
  git: escribe scripts en `storage/app/` y Mauricio los ejecuta.
- **La puerta de verificación** es `bash storage/app/verificar-pagos.sh`:
  migra, revisa el blade del plano, Pint, PHPStan nivel 7, Rector en dry-run,
  los tests del área y la suite entera. **Nada se da por bueno sin eso en
  verde.** Hoy: 595 tests.

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
- **Un `if (a || b) { continue; }` adentro de un `foreach` lo rechaza Rector**
  (`ChangeOrIfContinueToMultiContinueRector`): va un `continue` por condición.
  Pasa PHPStan y Pint sin chistar, y lo agarra recién el paso 4.
- **`end()` sobre una propiedad `readonly` es un error de ejecución**, no un
  warning: recibe el arreglo por referencia. Indexar con `count($x) - 1`.
- **La misma llamada a método dos veces con un `instanceof` en el medio no
  narrowea.** `$get('campo')` y `$record->getAttribute('x')` van a una
  variable, o se les hace un helper en el modelo.
- **Una lista de opciones tiene que contener siempre el estado actual**, aunque
  ese valor ya no califique. Si no, Filament devuelve un conjunto vacío de
  valores permitidos y tumba el formulario entero.
- **El dominio verde no significa la pantalla viva.** Toda acción de Filament
  necesita un test que la **dispare**, no que solo renderice la página.
- **Un número resumido en `ventas` se desactualiza solo.** `plazo_meses` y
  `cuota_mensual` los recalcula el abono desde `cuotas`; ojo con el CHECK
  `ventas_cuota_segun_plazo_chk` (plazo 0 ⟺ cuota nula).
- **La ruta del recibo vive FUERA del panel**, así que no hereda el
  `Authenticate` de Filament: la cuenta activa y `View:Recibo` se comprueban a
  mano en el controlador. Es el único lugar del sistema donde la autorización
  no la pone Filament, y tiene sus dos tests.
- **`User` usa SoftDeletes**, así que `delete()` NO dispara el `nullOnDelete`
  de las claves que lo apuntan. Para probar ese comportamiento hace falta
  `forceDelete()`; con el borrado suave lo que pasa es que la relación deja de
  resolver el nombre y la pantalla muestra «usuario dado de baja».
- **Los nombres van en MAYÚSCULAS por mutador** (docs/mayusculas.md). Un test
  que compare contra lo que se tecleó falla; hay que comparar contra lo que la
  base guardó.
- **Rector cachea y solo procesa lo que cambió.** Un archivo que pasó en una
  corrida no se vuelve a mirar, así que «3/3 files» no significa que revisó el
  repo. Y ojo con quitar un import a mano: si solo se usa en un docblock
  (`@return array<int, Action>`) PHPStan lo necesita igual.
- Al insertar un método, cuidado de no meterlo **entre un docblock y su
  función**: se roba la anotación `@return` y PHPStan culpa a otro archivo.
- Las clases CSS propias van en `resources/views/filament/estilos-olympo.blade.php`.
  El recibo es la excepción y lleva su CSS adentro: no vive dentro del panel y
  no debe depender de que un build de assets haya corrido.

## Pendiente sin decidir

- Si el receptor puede subir documentos o solo verlos (hoy solo ve). Es un
  `Create:Documento` nombrado aparte, como el de `Recibo`.
- El tamaño de papel del recibo no se consultó con la contratante. Hoy sale en
  una columna angosta arriba de la hoja, que imprime bien en carta y en A4 y
  se corta por la línea de puntos para media carta.
- `spatie/browsershot` quedó en `composer.json` sin usarse.
