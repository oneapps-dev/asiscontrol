# Fase 1 - MVP operativo

Objetivo: convertir Asiscontrol en un sistema funcional para un primer cliente piloto con control de accesos, trazabilidad y despliegue en cPanel.

## Alcance

- Autenticacion por sesion.
- Modelo multi-tenant con validacion de `tenant_id` en backend.
- Administracion basica de cliente, sede y usuarios.
- Inicio y cierre de turno de guardia.
- Registro de personas con documento manual y soporte para lector PDF417 en modo teclado.
- Registro de visitas, entrada y salida.
- Registro de vehiculos, entrada y salida.
- Registro y entrega de paquetes.
- Restricciones y alertas por documento.
- Incidentes y bitacora.
- Dashboard operativo.
- Reportes y exportacion CSV/PDF.
- Auditoria de acciones importantes.
- Tema claro y oscuro.
- Build React que incluya backend y SQL para publicar.

## Entregables actuales

- Frontend MVP en `frontend/src/App.tsx`.
- Tema claro/oscuro en `frontend/src/index.css`.
- Backend base en `backend/api`.
- Esquema MySQL en `backend/database/schema.sql`.
- Seed demo en `backend/database/seed.sql`.
- Script de build que copia backend y docs a `frontend/dist`.

## Estado de cierre

La fase MVP está implementada y conectada al backend PHP/MySQL. Las rutas productivas aplican sesión, CSRF, permisos por tenant, evidencias WebP, auditoría y fallback offline controlado.

## Pendiente operativo externo

- Ejecutar el smoke test contra el dominio de producción con las credenciales del `.env`.
- Validar el cron de backups/notificaciones en el cPanel del cliente piloto.
- Homologar los dispositivos físicos seleccionados cuando se confirme el modelo.

## Criterios de aceptacion

- Un guardia puede iniciar turno, registrar ingreso, registrar salida y cerrar turno.
- Todo acceso queda asociado a usuario, turno y tenant.
- Un administrador puede ver dashboard y reportes del tenant sin datos de otros tenants.
- El build de React genera `dist` con frontend, backend, SQL y documentos de fase.
