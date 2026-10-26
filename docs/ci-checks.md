# Validaciones continuas de AsisControl

Workflow: `.github/workflows/asiscontrol-ci.yml`
Activación: push a `main`, pull requests y ejecución manual.

## Controles que ejecuta actualmente

- `PHP syntax`: lint de todos los archivos PHP del backend.
- `TypeScript and production build`: `npm ci`, comprobación de tipos y compilación Vite con `VITE_API_REQUIRED=true`.
- Empaquetado: el directorio público incluye los recursos de frontend y API PHP, pero no directorios de migraciones, QA, scripts, storage, .env ni archivos SQL.
- `Fresh database integration`: MySQL 8 aislado, instalación de esquema limpio, migraciones registradas, datos QA ficticios y servidor PHP temporal.
- Autenticación, control de acceso por cliente/sede y rol global protegido.
- `platform_preview_smoke.sh`: modo de soporte solo lectura y auditoría.
- `industrial_structure_smoke.sh`: jerarquía y rechazo de ciclos.
- `user_quota_smoke.sh`: cuotas de usuarios y reactivación.
- `client_type_gate_smoke.sh`: aislamiento de módulos residenciales.
- `site_context_smoke.sh`: selección de sede y rechazo de escrituras fuera de alcance.
- `structured_audit_smoke.sh`: auditoría por cliente y redacción de secretos.
- `multi_tenant_membership_smoke.sh`: asignaciones para dos clientes, rol sin escalamiento, revocación y vencimiento de suscripción.
- `sector_dashboard_smoke.sh`: indicadores por tipo de cliente y visibilidad por perfil.
- `offline_context_smoke.sh`: un evento offline no puede reutilizar identidad o cliente ajeno; reintentos idempotentes.

## Interpretación de los resultados

1. Una ejecución roja bloquea la aceptación de ese commit.
2. Una ejecución verde acredita únicamente las rutas probadas en el entorno CI, **no** certifica el sistema completo.
3. El artefacto `asiscontrol-web-cpanel-qa` es para validación, **no** está certificado para producción.
4. No sustituye pruebas en navegador real, recuperación/backup, migración de base existente, pruebas de concurrencia, aceptación CA-01 a CA-23 ni auditoría completa de endpoints.
5. No desplegar mientras `docs/multiclient-implementation-status-2026-10-26.md` mantenga el estado **EN DESARROLLO**.

Ver ejecuciones en: https://github.com/oneapps-dev/asiscontrol/actions
