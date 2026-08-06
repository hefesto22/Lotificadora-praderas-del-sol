# Residencial Praderas del Sol — Sistema de Gestión Inmobiliaria

[![CI](https://github.com/hefesto22/Lotificadora-praderas-del-sol/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/hefesto22/Lotificadora-praderas-del-sol/actions/workflows/ci.yml)

Sistema de gestión inmobiliaria y control de lotificaciones para **Residencial
Praderas del Sol** (Cucuyagua, Copán), desarrollado por Inversiones Olympo.

Nació de la plantilla Grupo Olympo y se actualizó al stack de abajo antes de
escribir la primera migración del dominio.

- **Reglas del negocio:** `docs/dominio.md` — R1 a R22, contestadas por la
  contratante y ampliadas en reunión. Es la fuente de verdad del comportamiento.
- **Dónde vamos:** `docs/continuar-aqui.md` — el traspaso entre sesiones.
- **Documento rector:** `docs/INSTRUCCIONES.md`.

## Stack

| Capa | Tecnología | Versión |
|---|---|---|
| Lenguaje | PHP | 8.5 |
| Framework | Laravel | 13 |
| Panel admin | Filament | v5 (Schemas + Livewire 4) |
| Base de datos | PostgreSQL | 18 |
| Cache / Sesión / Queue | Redis | 7 |
| Procesamiento de colas | Laravel Horizon | última estable |
| Documentos impresos | HTML con hoja de estilo de impresión | — |
| Excel | maatwebsite/excel | 3.1 |
| Permisos | bezhansalleh/filament-shield + spatie/laravel-permission | — |
| Auditoría | spatie/laravel-activitylog | 5.x |
| Backups | spatie/laravel-backup | 9.3 |
| Health checks | spatie/laravel-health | 1.34 |
| Observabilidad | sentry/sentry-laravel | 4.13 |
| Tests | Pest | 4 |
| Análisis estático | Larastan (PHPStan + Laravel) | nivel 7 |
| Code style | Laravel Pint | 1.24 |
| Modernización | Rector | 2.0 |

## Características incluidas

**Dominio Honduras:**
- `config/honduras.php`: ISV, ISR, RTN, CAI, departamentos, monedas
- Value Objects inmutables: `Monto`, `RTN`, `CAI` con validación en constructor
- `BaseFormRequest` con reglas reutilizables: `rtnRule()`, `montoRule()`, `telefonoHondurasRule()`, `fechaHistoricaRule()`
- Componentes Filament reutilizables: `MontoField`, `RTNField`, `TelefonoHondurasField`

**Multi-tenant opcional:**
- Trait `BelongsToEmpresa` listo para activar en proyectos multi-empresa
- No activo por defecto (la plantilla es single-tenant)

**Seguridad:**
- Rate limiters preconfigurados: `api`, `login`, `exports`, `pdfs`
- Filtro de PII en logs (`FilterSensitiveData`) — redacta RTN, tarjetas, passwords, tokens
- Headers de seguridad listos para activar en Nginx
- Bloqueo de usuarios inactivos al panel admin
- Super-admin parametrizable por `.env` (no hardcoded)

**Observabilidad:**
- Sentry integrado (DSN vía `.env`)
- Stack de logs `daily,sentry` con filtro de PII
- Horizon con supervisores diferenciados por tipo de carga (default, pdfs, exports, notifications)
- Activity Log de Spatie configurado en User

**Performance:**
- Cache, sesiones y colas en Redis
- Índices compuestos en `users` para queries comunes
- `getDescendantIds()` resuelto con CTE recursivo de Postgres (1 query vs N anteriores)

**Calidad:**
- Suite de tests Pest 3 con cobertura de Value Objects, modelos y rutas
- CI en GitHub Actions: Pint + PHPStan + Pest sobre Postgres + Redis reales
- Larastan nivel 7
- Rector con sets de PHP 8.4, dead code, code quality, type declarations

## Setup local con Herd + Docker

Ver [docs/SETUP.md](docs/SETUP.md) para el flujo completo. Resumen:

```bash
git clone https://github.com/grupo-olympo/plantilla-laravel-filament.git mi-proyecto
cd mi-proyecto
cp .env.example .env
# Edita .env: APP_NAME, APP_SLUG, DB_DATABASE, ADMIN_EMAIL, ADMIN_PASSWORD
composer install
npm install
docker compose up -d        # SOLO si no tienes Postgres/Redis ya corriendo
php artisan key:generate
php artisan migrate --seed
npm run build
```

Accede a `http://localhost:8000/admin` con las credenciales definidas en `ADMIN_EMAIL` y `ADMIN_PASSWORD`.

## Estructura del proyecto

```
app/
├── Domain/                  # Value Objects, excepciones, contratos
│   ├── Exceptions/
│   └── ValueObjects/
├── Filament/
│   ├── Resources/
│   └── Schemas/Components/  # Campos reutilizables (MontoField, RTNField...)
├── Http/Requests/
│   └── BaseFormRequest.php
├── Logging/
│   └── FilterSensitiveData.php
├── Models/
│   ├── Concerns/            # Traits para modelos (BelongsToEmpresa)
│   └── User.php
├── Providers/
│   ├── DomainServiceProvider.php
│   └── Filament/AdminPanelProvider.php
└── Traits/                  # HasAuditFields

config/
└── honduras.php             # Origen único de verdad fiscal

docs/
├── SETUP.md
├── adr/
│   └── 0001-arquitectura.md
└── vps-state.template.md

tests/
├── Pest.php                 # Hooks y custom expectations
├── Unit/Domain/             # Tests de Value Objects (sin DB)
└── Feature/                 # Tests con DB real (Postgres testing)
```

## Comandos útiles

```bash
composer dev                 # Inicia servidor + Horizon + Pail + Vite
composer test                # Pest paralelo
composer lint                # Pint (fix)
composer lint:check          # Pint (verifica sin modificar)
composer stan                # PHPStan nivel 7
composer rector              # Rector dry-run (sin aplicar)
composer rector:fix          # Rector aplica cambios
composer ci                  # Lint + Stan + Test (lo que corre CI)
```

## Decisiones arquitectónicas

Ver [docs/adr/0001-arquitectura.md](docs/adr/0001-arquitectura.md) — la plantilla nace con **Laravel tradicional** (Services + Models). Cada proyecto que la consuma decide en su propio ADR si necesita migrar a Clean Architecture (§6 del documento de instrucciones de Grupo Olympo).

## Compatibilidad con VPS compartido

Si despliegas a un VPS donde ya conviven otros proyectos de Olympo:
1. Ejecuta primero la auditoría de §19 del documento de instrucciones
2. Reutiliza Postgres y Redis existentes — crea DB y user dedicados
3. Asigna `REDIS_DB` único al proyecto (db0..db15)
4. Usa `REDIS_PREFIX` y `CACHE_PREFIX` con el `APP_SLUG` para aislar keys
5. Crea pool PHP-FPM dedicado y vhost Nginx separado

Ver `docs/vps-state.template.md` para documentar el estado del VPS al iniciar.

## Licencia

MIT — uso interno de Grupo Olympo / Inversiones Olympo.
