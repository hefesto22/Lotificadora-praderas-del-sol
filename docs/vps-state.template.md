# Estado del VPS — `<NOMBRE_PROYECTO>`

**Fecha de auditoría:** YYYY-MM-DD
**Servidor:** `<host.dominio>` (Hostinger KVM4 / otro)
**Auditor:** `<nombre>`

> Plantilla derivada de §19 del documento de instrucciones de Grupo Olympo.
> Copiar este archivo a `docs/vps-state.md` (sin `.template`) y completar para cada proyecto.

## 1. Información del sistema

| Item | Valor |
|---|---|
| OS | Ubuntu 24.04 LTS |
| Kernel | `<uname -r>` |
| RAM total | 16 GB |
| RAM libre | `<free -h>` |
| Disco total | 200 GB NVMe |
| Disco usado | `<df -h>` |
| Núcleos | 4 |
| Carga (load avg) | `<uptime>` |

## 2. Proyectos y sitios desplegados

Lista de proyectos productivos en este VPS al momento de auditar (NO TOCAR):

| Proyecto | Ruta | Stack | Notas |
|---|---|---|---|
| `<nombre>` | `/var/www/proyectos/<nombre>` | PHP 8.4 / Postgres `<db>` / Redis db`<n>` | Cliente activo |

## 3. PHP

| Versión | Estado | Pool FPM | Notas |
|---|---|---|---|
| 8.4.x | Por defecto | `<pool>` | Compartido con `<otros proyectos>` |

Extensiones cargadas: `pdo_pgsql, redis, gd, mbstring, xml, bcmath, intl, zip, curl, openssl` ✓

## 4. PostgreSQL

| Item | Valor |
|---|---|
| Versión | 16.x |
| Servicio | `systemctl status postgresql` |
| Data directory | `<SHOW data_directory>` |
| Config file | `<SHOW config_file>` |
| `shared_buffers` actual | `<valor>` |
| `work_mem` actual | `<valor>` |
| `max_connections` actual | `<valor>` |

Bases existentes (NO modificar las de otros proyectos):

| DB | Owner | Proyecto | Notas |
|---|---|---|---|
| `postgres` | `postgres` | sistema | — |
| `<db_existente>` | `<user>` | `<otro_proyecto>` | NO TOCAR |
| **`<db_proyecto_nuevo>`** | **`<user_proyecto_nuevo>`** | **este proyecto** | Creada para esta plantilla |

## 5. Redis

| Item | Valor |
|---|---|
| Versión | 7.x |
| Servicio | `systemctl status redis-server` |
| `maxmemory` | `<valor>` |
| `maxmemory-policy` | `<valor>` |
| `requirepass` configurado | sí/no |

Asignación de DBs (de 0-15):

| DB index | Proyecto | Notas |
|---|---|---|
| 0 | `<otro_proyecto>` | NO TOCAR |
| 1 | `<otro_proyecto>` cache | NO TOCAR |
| **5** | **este proyecto** | Asignado en `.env` `REDIS_DB=5` |
| **6** | **este proyecto** | `REDIS_CACHE_DB=6` |
| **7** | **este proyecto** | `REDIS_QUEUE_DB=7` |

## 6. Nginx

Sitios activos (NO modificar otros):

| Dominio | Proyecto | Cert SSL |
|---|---|---|
| `<dominio>` | `<otro_proyecto>` | Let's Encrypt válido hasta `<fecha>` |
| **`<este_dominio>`** | **este proyecto** | Pendiente provisionamiento |

## 7. Node.js / Chromium

| Item | Valor |
|---|---|
| Node | `<node --version>` |
| npm | `<npm --version>` |
| chromium-browser | instalado en `/usr/bin/chromium-browser` |
| puppeteer global | sí/no |

## 8. Supervisor

Procesos supervisados al momento de auditoría (NO TOCAR los de otros proyectos):

| Proceso | Proyecto | Estado |
|---|---|---|
| `<otro_proyecto>-horizon` | `<otro_proyecto>` | RUNNING |
| **`<este_proyecto>-horizon`** | **este proyecto** | Pendiente configurar |

## 9. Seguridad y crons

- `ufw status`: `<reglas>`
- `fail2ban-client status`: `<jails activos>`
- Crons existentes (NO BORRAR):
  ```
  <crontab -l>
  ```

## 10. Plan de instalación para este proyecto

Lo que SÍ vamos a instalar/configurar:
- [ ] Crear DB `<db_proyecto_nuevo>` y user dedicado en Postgres
- [ ] Asignar DBs Redis 5, 6, 7
- [ ] Crear pool FPM dedicado en `/etc/php/8.4/fpm/pool.d/<proyecto>.conf`
- [ ] Crear vhost Nginx en `/etc/nginx/sites-available/<dominio>.conf`
- [ ] Provisionar cert SSL Let's Encrypt para `<dominio>`
- [ ] Configurar Supervisor para Horizon de este proyecto
- [ ] Agregar cron para `php artisan schedule:run` (cada minuto)
- [ ] Configurar backups con S3 hacia `<bucket>`

Lo que **NO** vamos a tocar:
- Servicio PostgreSQL (otros proyectos lo usan)
- Servicio Redis (otros proyectos lo usan)
- Configuración global de Nginx (`nginx.conf`)
- Cert SSL ni vhosts de otros proyectos
- Pools FPM de otros proyectos
- Supervisores de otros proyectos
- Reglas de UFW existentes (solo agregar, no borrar)

## 11. Cambios aplicados al VPS

Bitácora de cada modificación al VPS, con fecha y propósito:

| Fecha | Acción | Justificación |
|---|---|---|
| YYYY-MM-DD | `CREATE DATABASE <db>` | Setup inicial proyecto |

---

**Última actualización:** YYYY-MM-DD por `<nombre>`.
