# Poner el sistema en un servidor

> Escrito el 8-ago-2026, cuando la auditoría encontró que **el sistema no
> estaba desplegado en ningún lado** y que `.env.example` copiado tal cual a
> producción deja `APP_DEBUG=true`, la contraseña `12345678` y Postgres en
> `trust`.
>
> **La puerta es `php artisan olympo:verificar-produccion`.** Ningún servidor
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

---

## 1. Traer el código

```bash
cd /var/www/proyectos
git clone <repo> <slug> && cd <slug>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

## 2. El `.env`

```bash
cp .env.production.example .env
# llenar los <marcadores> con un editor
php artisan key:generate
```

**No** se copia `.env.example`. Esa es la de desarrollo y por eso existe la
otra.

## 3. La base

```bash
php artisan migrate --force
php artisan db:seed --force        # roles, super-admin y branding
php artisan storage:link
```

El seeder del super-admin **se niega a correr en `production` sin
contraseña**. Si se queja, es que `APP_ENV` todavía no dice `production`.

## 4. Cachés

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan icons:cache
```

⚠️ Después de `config:cache`, `env()` deja de leer el `.env`. Cualquier valor
nuevo tiene que salir de un archivo de `config/`, no de `env()` suelto.

## 5. El cron — esto es lo que más se olvida

```bash
crontab -e
```

```
* * * * * cd /var/www/proyectos/<slug> && php artisan schedule:run >> /dev/null 2>&1
```

**Sin esta línea no hay respaldo, ni limpieza, ni monitoreo.** Y hasta el
8-ago-2026 nada avisaba que faltaba: el latido se escribía y nadie lo miraba.
Ahora `ScheduleCheck` lo lee, `/health` lo reporta y
`olympo:verificar-produccion` se niega a dar el servidor por bueno.

## 6. Las colas

`config/horizon.php` define supervisores de producción. Si no se levanta
Horizon, el check de cola de `/health` va a fallar.

```bash
sudo systemctl enable --now horizon-<slug>
```

## 7. La revisión — la puerta

```bash
php artisan olympo:verificar-produccion
```

Revisa, en este orden: entorno y depuración · llave · https · cookie segura ·
**correo real** · **alertas encendidas** · **el cron latiendo** · **el respaldo
saliendo del servidor** · respaldo cifrado · contraseñas de base y Redis ·
**que nadie haya quedado con `12345678`** · cachés · enlace de storage.

Devuelve código distinto de cero si falta algo grave, así que se puede
encadenar:

```bash
php artisan olympo:verificar-produccion && echo "listo para entregar"
```

Con `--estricto` también falla con los avisos.

## 8. Probar el respaldo de verdad, una vez

```bash
php artisan backup:run
```

Y **verificar que el archivo llegó al destino remoto**, no solo que el comando
dijo OK. Un respaldo que nunca se probó no es un respaldo.

## 9. Antes de entregar la llave

- [ ] `olympo:verificar-produccion` en verde
- [ ] `/health` responde y está **restringido por IP** en nginx al servicio de
      monitoreo — hoy expone entorno, estado de debug, base, Redis y disco
- [ ] Un respaldo probado y bajado del bucket para confirmar que abre
- [ ] `php artisan praderas:exportar-todo` corre (Cláusula Décima)
      ⚠️ exporta **toda** la base, no la de un cliente: no se le manda a nadie
      sin filtrar antes
- [ ] Entrar con el usuario del cliente y cobrar una cuota de prueba
- [ ] Imprimir un recibo y un estado de cuenta desde el dominio real

---

## Cada despliegue posterior

```bash
cd /var/www/proyectos/<slug>
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan horizon:terminate
php artisan up
php artisan olympo:verificar-produccion
```

## Lo que NO hay que hacer

- Copiar `.env.example` al servidor.
- Correr `php artisan db:seed --class=PraderasDelSolSeeder` o
  `PlanoRealPraderasSeeder` en la instalación de otro cliente: le cargan los
  301 lotes de Praderas del Sol.
- Dejar `MAIL_MAILER=log`. El sistema funciona igual y todas las alertas se
  pierden.
