# Mayúsculas

**Decisión del 3 de agosto de 2026.** En este sistema todo se guarda y se
muestra en MAYÚSCULAS.

## Qué alcanza

| Campo | Antes | Ahora |
|---|---|---|
| `proyectos.codigo` | ya en mayúsculas | igual |
| `proyectos.nombre` | como se escribía | **MAYÚSCULAS** |
| `proyectos.municipio` | como se escribía | **MAYÚSCULAS** |
| `bloques.nombre` | ya en mayúsculas | igual |
| `lotes.numero` | ya en mayúsculas | igual |
| `calles.nombre` | como se escribía | **MAYÚSCULAS** |
| `clientes.nombre` | como se escribía | **MAYÚSCULAS** |
| `clientes.direccion` | como se escribía | **MAYÚSCULAS** |

Se aplica en el **mutador del modelo**, no en el formulario. Eso significa
que también entra en mayúsculas cuando el dato viene de un seeder, de un
import o de tinker — es la tercera defensa del §10.4, la única que nadie
puede saltear.

## Qué NO alcanza, y por qué

**`clientes.correo`.** Sigue en minúsculas. `Rosa@Gmail.com` y
`rosa@gmail.com` son la misma casilla, y guardarlas distinto rompe
cualquier búsqueda o deduplicación. Es una razón técnica, no estética.

**Los campos `observaciones`.** Son prosa libre, a veces de varios
párrafos. Un párrafo entero en mayúsculas es más difícil de leer que uno
normal, y no es un dato que se use para buscar ni para cruzar.

Si alguno de estos dos tiene que cambiar, se cambia — pero conviene que
sea una decisión tomada a propósito y no por arrastre.

## Qué deroga

El §10.4 del documento rector traía una excepción explícita:

> el auto-mayúsculas NO aplica a nombres de personas. "María de los
> Ángeles" no es un código de catálogo.

**Esa excepción queda sin efecto** por pedido expreso. La nota que la
citaba en `ClienteForm` fue reemplazada, porque un comentario que dice lo
contrario de lo que hace el código es peor que no tener comentario.

Vale dejar registrado el argumento que había del otro lado, por si algún
día se revisa: en un contrato impreso, un nombre en mayúsculas se lee
distinto que el resto del texto, y hay quien lo percibe como énfasis. Si
los nombres van a salir impresos en escrituras, conviene mirarlo con la
contratante antes de que se firme la primera.
