# ADR-0001 — Arquitectura base: Laravel tradicional, no Clean Architecture

**Estado:** Aceptado
**Fecha:** 2026-04-26
**Decididores:** Mauricio Cruz (arquitecto técnico Grupo Olympo)
**Tipo:** Plantilla base — cada proyecto que herede de aquí puede tomar una decisión distinta y documentarla en su propio ADR-0001.

## Contexto

La plantilla Grupo Olympo será reutilizada por múltiples proyectos del grupo (Constructora Mayap, Distribuidora Hozana, Hueverías, etc.) con grados de complejidad muy distintos:

- Sistemas pequeños: panel admin con 3-5 modelos CRUD y reportes básicos.
- Sistemas medianos: facturación electrónica, inventario, integración con SAR.
- Sistemas grandes (futuros): ERP completo, multi-empresa, fotogrametría, ML.

El documento de instrucciones de Grupo Olympo (§6) describe Clean Architecture como opción y (§8) describe la estructura "Laravel tradicional" como alternativa. La sección §6.5 establece criterios para elegir entre ambas.

## Decisión

La plantilla nace con **estructura Laravel tradicional** (Services + Models + Filament Resources), NO con Clean Architecture estricta.

Sin embargo, incluye **infraestructura mínima del Domain** que permite migrar a Clean Architecture sin rehacer trabajo:

- `app/Domain/ValueObjects/` ya tiene `Monto`, `RTN`, `CAI`
- `app/Domain/Exceptions/` ya tiene `GrupoOlympoException` raíz
- `app/Providers/DomainServiceProvider.php` está listo para bindings
- El autoload incluye el namespace `Domain\` por si se usa fuera de `App\`

## Razones

**Por qué Laravel tradicional como base:**

1. **Onboarding más rápido.** Un developer junior entiende `Service + Eloquent + Filament Resource` en horas. Clean Architecture requiere días.
2. **La mayoría de proyectos del grupo son CRUDs medianos.** No justifican el overhead de mapeo Domain↔Eloquent.
3. **Filament ya impone una estructura de capas.** Los Resources actúan como "Application layer" implícita, los Models como "Persistence", los Services como "Domain". Encajar Clean Architecture estricta sobre esto duplica conceptos.
4. **Reversibilidad.** Migrar de Laravel tradicional a Clean Architecture es viable módulo por módulo. Migrar al revés es prácticamente reescribir.
5. **Costo de la indirección.** Cada caso de uso en Clean Arch implica: Use Case + DTO + Domain Entity + Mapper + Repository Interface + Repository Implementation. Para un CRUD simple eso son 6 archivos vs 1 Service.

**Por qué SÍ incluir la infraestructura del Domain desde el día 1:**

1. **Value Objects son útiles incluso en Laravel tradicional.** Validar un RTN en su constructor evita repetir reglas en 5 Form Requests distintos (§8.4.5).
2. **Excepciones tipadas reducen bugs.** `StockInsuficienteException` en un `catch` es más expresivo que `RuntimeException`.
3. **Si algún módulo evoluciona a Clean Arch, la base ya está.** El developer no tiene que crear `app/Domain/` desde cero ni discutir el namespace.

## Consecuencias

**Positivas:**
- Adoptión rápida en cualquier proyecto del grupo
- Menor curva de aprendizaje
- Compatible con toda la documentación de Filament v4
- Permite refactor progresivo a Clean Arch módulo por módulo

**Negativas:**
- Si un proyecto descubre tarde que necesita Clean Arch completo, el refactor cuesta más que haber empezado así
- Riesgo de que la lógica se filtre a Controllers/Resources si el equipo no es disciplinado con la separación Service/Model

**Mitigaciones:**
- Reglas de §22 ("Lo que nunca hago") prohíben lógica de negocio en Controllers
- PHPStan nivel 7 + Larastan ayudan a detectar acoplamiento incorrecto
- En cada nuevo módulo, el desarrollador evalúa si justifica Clean Arch (criterios §6.5) y crea ADR si la respuesta es sí

## Cuándo SÍ migrar a Clean Architecture (criterio del proyecto que herede)

Migrar el módulo o todo el proyecto a Clean Arch (§6.2) cuando aplique al menos uno:

- Lógica fiscal/financiera con &gt;5 reglas de negocio interconectadas
- Integración con APIs externas que tienen su propio modelo de dominio (SAR, bancos)
- Probable cambio de stack de persistencia en 2-3 años
- Múltiples canales de entrada al mismo caso de uso (HTTP + CLI + Job + Filament Action)
- Equipo &gt;3 developers donde el aislamiento del Domain reduce conflictos

## Referencias

- §6 del documento de instrucciones (Clean Architecture)
- §6.5 del documento (criterios para decidir)
- §7 (principios complementarios — aplican en ambas arquitecturas)
- §8 (estructura Laravel tradicional — la elegida aquí)
