# AsisControl — control de identidades en contexto de sede

Fecha: 2026-10-26. Rama: `main`.

## Cambio aplicado

Las rutas heredadas `GET /users`, `POST /users` y `PATCH /users/{id}` ahora requieren simultáneamente el permiso `settings.manage` y el contexto de cliente completo mediante `require_tenant_wide_scope()`. Se impide que un actor limitado a una sede, o un administrador que haya seleccionado temporalmente una sede, consulte el directorio global o modifique cuentas desde ese contexto. El control se ejecuta en el servidor antes de leer o modificar datos.

## Evidencia y límites

Se ampliaron las pruebas `backend/tests/site_context_smoke.sh` con verificaciones de rechazo HTTP 403 para listado, alta y edición de usuarios desde una selección de sede. La suite está conectada al workflow de GitHub Actions. Los resultados definitivos dependen de la ejecución del commit actual.

No se han certificado todavía todas las mutaciones de alcances, delegaciones y permisos. Revisar también escenarios de concurrencia, compatibilidad funcional con usuarios de varias sedes y perfiles que necesitan administrar únicamente su propio personal.

**Despliegue no autorizado:** continúan pendientes CA-01 a CA-23, regresión integral, base restaurada y prueba en BanaHosting.
