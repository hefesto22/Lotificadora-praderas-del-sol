# Poner el sistema en un servidor

> Escrito el 8-ago-2026, cuando la auditoría encontró que **el sistema no
> estaba desplegado en ningún lado** y que `.env.example` copiado tal cual a
> producción deja `APP_DEBUG=true`, la contraseña `12345678` y Postgres en
> `trust`.
>
> **La puerta es `php8.5 artisan olympo:verificar-produccion`.** Ningún servidor
> se entrega sin ese comando en verde. No es burocracia: revisa exactamente
> las cosas que, cuando fallan, no se notan.

---

## 0. Lo que se necesita antes de empezar

| | |
|---|---|
| Servidor | Ubuntu 24.04, PHP 8.5 con FPM, nginx |
| Base | PostgreSQL 18 con usuario y contraseña propios de este proyecto |
| Cache/colas | Redis 8 **con contraseña** |
| Dominio | Con certificado TLS ya emitido |
| Correo | Un SMTP real (no `log`) |
| Respaldo remoto | Un bucket S3 o compatible, con sus llaves |

⚠️ El VPS de Olympo es compartido entre varios proyectos
(`docs/vps-state.template.md`). Base y Redis van con contraseña propia: el
aislamiento no puede depender de que nadie se equivoque de prefijo.

### 🔴 El binario de PHP NO se llama `php`

Costó un despliegue entero el 25-ago-2026.

En un VPS compartido, el `php` del PATH es el que necesitan **los otros**
proyectos. En el de Olympo es 8.4, y este sistema pide `^8.5`. La consecuencia
es tramposa: el sitio anda perfecto en el navegador —FPM sí corre con 8.5— y
**todo lo que se teclea en la terminal muere**, con un error que no menciona la
palabra «versión» hasta la tercera línea:

```
Composer detected issues in your platform:
Your Composer dependencies require a PHP version ">= 8.5.0". You are running 8.4.24.
```

Y no falla parejo: `git pull` sí entra, pero `composer install` se niega y
`migrate` no corre. **El despliegue queda a mitad de camino**, con el código
nuevo puesto y la base sin migrar — que es la peor combinación posible. Lo
único que lo salva de ser un desastre es que `artisan down` falla también, así
que el sitio nunca llega a entrar en mantenimiento.

**Todo comando de este proyecto lleva el binario explícito:**

```bash
php8.5 artisan …
php8.5 $(command -v composer) install --no-dev --optimize-autoloader
```

El cron también. Antes de escribir la primera línea: `ls -1 /usr/bin/php*`.

---

## 1. Traer el código

```bash
cd /var/www
git clone <repo> <slug> && cd <slug>
php8.5 $(command -v composer) install --no-dev --optimize-autoloader
npm ci && npm run build
```

## 2. El `.env`

```bash
cp .env.production.example .env
# llenar los <marcadores> con un editor
php8.5 artisan key:generate
```

**No** se copia `.env.example`. Esa es la de desarrollo y por eso existe la
otra.

## 3. La base

```bash
php8.5 artisan migrate --force
php8.5 artisan db:seed --force        # roles, super-admin y branding
php8.5 artisan storage:link
```

El seeder del super-admin **se niega a correr en `production` sin
contraseña**. Si se queja, es que `APP_ENV` todavía no dice `production`.

## 4. Cachés

```bash
php8.5 artisan config:cache
php8.5 artisan route:cache
php8.5 artisan view:cache
php8.5 artisan icons:cache
```

⚠️ Después de `config:cache`, `env()` deja de leer el `.env`. Cualquier valor
nuevo tiene que salir de un archivo de `config/`, no de `env()` suelto.

## 5. El cron — esto es lo que más se olvida

```bash
crontab -e
```

```
* * * * * cd /var/www/<slug> && /usr/bin/php8.5 artisan schedule:run >> /dev/null 2>&1
```

⚠️ **La ruta del binario, completa, y no `php`.** El cron corre con un PATH
mínimo que no es el de la sesión interactiva, y su salida va a `/dev/null`: si
se equivoca de PHP, esto falla cada minuto durante meses sin que nadie se
entere. Es el peor lugar del sistema para dar por sentado qué `php` hay.

**Sin esta línea no hay respaldo, ni limpieza, ni monitoreo.** Y hasta el
8-ago-2026 nada avisaba que faltaba: el latido se escribía y nadie lo miraba.
Ahora `ScheduleCheck` lo lee, `/health` lo reporta y
`olympo:verificar-produccion` se niega a dar el servidor por bueno.

## 6. Las colas

**Depende de qué diga `QUEUE_CONNECTION`, y no son intercambiables.**

`config/horizon.php` define supervisores de producción, pero **Horizon solo
sabe de colas Redis**. En una instalación con `QUEUE_CONNECTION=database` —como
Praderas del Sol, que además tiene `CACHE_STORE=file` y `SESSION_DRIVER=file`—
Horizon no procesa nada, y `horizon:terminate` se cae con
`Class "Redis" not found` si el PHP de la terminal no trae la extensión.

| `QUEUE_CONNECTION` | Se levanta | Se recarga con |
|---|---|---|
| `redis` | Horizon | `php8.5 artisan horizon:terminate` |
| `database` | `queue:work` bajo supervisor | `php8.5 artisan queue:restart` |

```bash
sudo systemctl enable --now horizon-<slug>   # solo con colas Redis
```

## 7. La revisión — la puerta

```bash
php8.5 artisan olympo:verificar-produccion
```

Revisa, en este orden: entorno y depuración · llave · https · cookie segura ·
**correo real** · **alertas encendidas** · **el cron latiendo** · **el respaldo
saliendo del servidor** · respaldo cifrado · contraseñas de base y Redis ·
**que nadie haya quedado con `12345678`** · cachés · enlace de storage.

Devuelve código distinto de cero si falta algo grave, así que se puede
encadenar:

```bash
php8.5 artisan olympo:verificar-produccion && echo "listo para entregar"
```

Con `--estricto` también falla con los avisos.

## 8. Probar el respaldo de verdad, una vez

```bash
php8.5 artisan backup:run
```

Y **verificar que el archivo llegó al destino remoto**, no solo que el comando
dijo OK. Un respaldo que nunca se probó no es un respaldo.

## 9. Antes de entregar la llave

- [ ] `olympo:verificar-produccion` en verde
- [ ] `/health` responde y está **restringido por IP** en nginx al servicio de
      monitoreo — hoy expone entorno, estado de debug, base, Redis y disco
- [ ] Un respaldo probado y bajado del bucket para confirmar que abre
- [ ] `php8.5 artisan praderas:exportar-todo` corre (Cláusula Décima)
      ⚠️ exporta **toda** la base, no la de un cliente: no se le manda a nadie
      sin filtrar antes
- [ ] Entrar con el usuario del cliente y cobrar una cuota de prueba
- [ ] Imprimir un recibo y un estado de cuenta desde el dominio real

---

## Cada despliegue posterior

```bash
cd /var/www/<slug>
php8.5 artisan down --render="errors::503"
git pull
php8.5 $(command -v composer) install --no-dev --optimize-autoloader
php8.5 artisan migrate --force
php8.5 artisan config:cache && php8.5 artisan route:cache && php8.5 artisan view:cache
php8.5 artisan queue:restart        # con colas Redis: horizon:terminate
php8.5 artisan up
php8.5 artisan olympo:verificar-produccion
```

`npm ci && npm run build` **solo si el cambio tocó `resources/css`,
`resources/js` o algo que Vite compile.** Los documentos imprimibles —recibo,
estado de cuenta, estado mensual— llevan su CSS adentro del propio HTML
justamente para no depender de un build; un despliegue que solo los toca a
ellos no necesita Node.

## Lo que NO hay que hacer

- Copiar `.env.example` al servidor.
- Correr `php8.5 artisan db:seed --class=PraderasDelSolSeeder` o
  `PlanoRealPraderasSeeder` en la instalación de otro cliente: le cargan los
  301 lotes de Praderas del Sol.
- Dejar `MAIL_MAILER=log`. El sistema funciona igual y todas las alertas se
  pierden.
