# Dónde quedamos — 6-ago-2026

Traspaso entre sesiones. Lo que hay que saber para seguir sin releer todo.

---

## Lo último: el recibo impreso (módulo h del contrato)

**Terminado y verificado el 6-ago-2026.** `bash storage/app/verificar-pagos.sh`
en verde: Pint 656 archivos, PHPStan nivel 7 258/258 sin errores, Rector sin
cambios pendientes, **573 tests / 3,185 assertions**.

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
| h) Documentos de cobro | ✅ |
| **Estado de cuenta** | ⬜ **no existe ni una línea — lo próximo** |
| **Apartados: prórroga y devolución** | ⬜ el dominio aparta y libera; falta la prórroga única con autorización y la devolución con su documento de salida (R14) |

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
  verde.** Hoy: 573 tests.

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
