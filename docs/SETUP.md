# Setup local — Plantilla Grupo Olympo

Guía paso a paso para preparar la plantilla en una máquina nueva con macOS + Herd. Pensada para que un desarrollador junior pueda seguirla sin asumir nada.

## Pre-requisitos

| Herramienta | Versión mínima | Cómo verificar |
|---|---|---|
| Laravel Herd | 1.x | `herd --version` |
| PHP | 8.4+ | `php -v` (Herd lo trae — selecciónalo en Sites para este proyecto) |
| Composer | 2.7+ | `composer --version` |
| Node.js | 20+ | `node --version` |
| Docker Desktop | última | `docker --version` |
| Git | 2.40+ | `git --version` |
| psql client | 16+ | `psql --version` |
| redis-cli | 7+ | `redis-cli --version` |

## Decisión inicial — ¿de dónde sale Postgres y Redis?

La plantilla soporta dos escenarios. Elige el que aplique antes de seguir:

### Escenario A — Tienes contenedores Docker compartidos para varios proyectos

Es lo más común si ya trabajas con otros proyectos Olympo (Hozana, etc.). Los contenedores `*_postgres` y `*_redis` ya están corriendo y persistentes.

1. **No levantes** el `docker-compose.yml` de este proyecto.
2. Conecta el `.env` al contenedor existente:
   ```env
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_USERNAME=postgres
   DB_PASSWORD=secret    # el que tenga el contenedor compartido
   ```
3. Crea una base dedicada para este proyecto (NO uses la de otro):
   ```sql
   -- Conéctate como superusuario al contenedor compartido
   CREATE DATABASE plantilla_olympo;
   CREATE DATABASE plantilla_olympo_test;
   ```
4. Asigna un `REDIS_DB` único en `.env` (db0 está ocupado por otros, usa db5 por ejemplo):
   ```env
   REDIS_DB=5
   REDIS_CACHE_DB=6
   REDIS_QUEUE_DB=7
   REDIS_PREFIX=plantilla_olympo_
   ```

### Escenario B — Esta es tu primera plantilla, no hay nada compartido

Levanta el `docker-compose.yml` de la plantilla:

```bash
docker compose up -d
docker compose ps   # verifica que postgres y redis estén "healthy"
```

Las variables del `.env.example` ya apuntan a los puertos correctos (5432 y 6379) y la DB se crea automáticamente al iniciar el contenedor.

## Pasos comunes (cualquiera de los escenarios)

```bash
# 1. Clona la plantilla con el nombre de tu nuevo proyecto
git clone https://github.com/grupo-olympo/plantilla-laravel-filament.git mi-proyecto
cd mi-proyecto
rm -rf .git
git init

# 2. Configura el entorno
cp .env.example .env

# 3. Edita .env con tu editor preferido y completa al menos:
#    APP_NAME="Mi Proyecto"
#    APP_SLUG=mi_proyecto
#    APP_URL=http://mi-proyecto.test
#    DB_DATABASE=mi_proyecto
#    ADMIN_EMAIL=tu@email.com
#    ADMIN_PASSWORD=algo-seguro

# 4. Instala dependencias
composer install
npm install

# 5. Genera APP_KEY
php artisan key:generate

# 6. Migra la base y siembra el super-admin
php artisan migrate --seed

# 7. Compila assets
npm run build

# 8. Linkea storage para uploads públicos
php artisan storage:link
```

## Apuntar Herd al proyecto

```bash
herd link mi-proyecto
# Esto crea http://mi-proyecto.test apuntando a la carpeta actual
```

Si Herd no resuelve el dominio, reinicia el demonio:
```bash
herd restart
```

## Verificación rápida

Abre en el navegador:
- `http://mi-proyecto.test` → debe redirigir o mostrar la página por defecto
- `http://mi-proyecto.test/up` → debe mostrar el JSON de health check
- `http://mi-proyecto.test/admin` → panel admin, login con `ADMIN_EMAIL`/`ADMIN_PASSWORD`
- `http://mi-proyecto.test/horizon` → dashboard de Horizon (solo super-admin)

## Correr los tests

```bash
# Opción A: contenedores compartidos
createdb -h 127.0.0.1 -U postgres plantilla_olympo_test  # solo la primera vez
composer test

# Opción B: docker-compose local (la DB de tests se crea con `migrate:fresh`)
composer test
```

Si los tests fallan con `connection refused`, verifica que Postgres esté corriendo:
```bash
psql -h 127.0.0.1 -U postgres -l
```

## Desarrollo activo

Un solo comando levanta servidor + Horizon + Pail (logs en vivo) + Vite:

```bash
composer dev
```

## Solución de problemas comunes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `SQLSTATE[08006] could not connect to server` | Postgres no corriendo o credenciales mal | Verifica con `psql -h 127.0.0.1 -U postgres -l` |
| `Class 'Predis\Client' not found` | Falta extensión Redis o predis | `composer require predis/predis` (ya está, reinstala) |
| `Filament: ...does not exist` | Cache de config viejo | `php artisan optimize:clear` |
| Login al panel da "credenciales inválidas" | Seeder no corrió o user inactivo | `php artisan db:seed --class=AdminUserSeeder` |
| Tests no encuentran tabla | DB de testing no migrada | `php artisan migrate:fresh --env=testing` |
| `Browsershot: chromium not found` | Falta binario en producción | `apt install chromium-browser` y setea `BROWSERSHOT_CHROME_PATH` |

## Próximos pasos

1. Lee `docs/adr/0001-arquitectura.md` para entender la decisión arquitectónica de la plantilla.
2. Si tu proyecto necesita multi-tenant, activa el trait `BelongsToEmpresa` en cada modelo.
3. Si aplicas Clean Architecture, lee §6 del documento de instrucciones de Grupo Olympo y crea tu propio ADR.
4. Antes de deployar a producción, ejecuta la auditoría de §19 (estado del VPS).
