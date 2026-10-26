# AsisControl — control de exportaciones de auditoría y trabajos globales

Fecha: 2026-10-26. Estado: en validación CI, no autorizado para despliegue.

## Cambio

Las rutas `GET /audit.csv`, `GET /audit.html`, `GET /jobs/imports` y `GET /jobs/exports` conservan sus permisos específicos y ahora requieren `require_tenant_wide_scope()`. Su información no está acotada por sede en SQL; por eso se rechaza la consulta desde una sesión con sede seleccionada o con alcance operativo limitado. Este comportamiento coincide con el control ya vigente en `GET /audit`.

## Pruebas

Se amplió `backend/tests/site_context_smoke.sh` para exigir HTTP 403 en los cuatro endpoints desde un contexto de sede seleccionado. Continúan pendientes la evaluación de otras exportaciones y los casos E2E por rol, tenant y sede.

## Limitación

No se declara completo el plan ni certificadas las fases 0 a 9 con estas pruebas. Faltan criterios CA-01 a CA-23 y pruebas de migración histórica, restauración, carga y despliegue real.
