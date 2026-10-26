# AsisControl — Estado verificado de implementación multicliente
Fecha: 2026-10-26

## Estado

**EN DESARROLLO — NO DESPLEGAR EN PRODUCCIÓN**.
El alcance de las fases 0–9 del plan del 24-10-2026 **NO** está completo.
Este archivo es un registro de progreso comprobable y no equivale a certificación de QA.

## Cambios incorporados en main

1. Inicio de Fase 0: `superadmin` ya no concede acceso al panel global sin registro activo en `platform_admins`. La creación/edición ordinaria de usuarios no puede asignar ese rol. Se verifica estado de cuenta, rol y suscripción al usar una sesión autenticada. Una sede asignada a un usuario debe pertenecer al tenant.
2. Inicio de Fase 1: migración 015 de `client_types`, jerarquía inicial de `tenants`, relaciones operativas y `tenant_features`. No se han habilitado todavía los endpoints de delegación ni la selección multicliente.
3. Semillas aisladas de QA en `backend/database/testing/multitenant_demo.sql`. No ejecutarlas en una instalación productiva.

## Pendiente para declarar entrega completa

- Fase 0: revisar todos los endpoints y los casos de escritura/importación; pruebas de sesión y de escalamiento.
- Fases 1–2: API de clientes secundarios y motor central de autorización por recurso y alcance.
- Fase 3: capacidades y límites transaccionales de planes.
- Fases 4–5: estructuras empresariales y dashboards diferenciados.
- Fase 6: selector de contexto y vista de soporte solo lectura.
- Fases 7–8: auditoría estructurada y administración de roles, alcances y usuarios.
- Fase 9: pruebas automatizadas exhaustivas, actualización de frontend, validación de SQL en MySQL, compilación y empaquetado seguros para cPanel.

## Requisitos de verificación antes del despliegue

- Ejecutar PHP lint, TypeScript, build y pruebas automatizadas del backend.
- Instalar `install.sql` sobre base vacía de QA y comprobar migración 015.
- Probar actualización desde un esquema existente en copia respaldada.
- Ejecutar el SQL de datos de QA solo en la base de pruebas (no en producción).
- Ejecutar pruebas de autorización negativa cross-tenant, cross-site y roles.
- Confirmar que el directorio público no exponga fuentes PHP, SQL, documentación ni archivos sensibles.

No se ha conectado al servidor de BanaHosting ni se ha probado el dominio público.

## Decisiones de producto adoptadas

- Datos de cada cliente permanecen en su tenant; la empresa de seguridad usa delegaciones explícitas.
- Límites comerciales editables desde CREAMOX.
- Varios operadores técnicos por empresa; usuarios pueden recibir varios alcances autorizados.
- Vista «Ver como» solo lectura.
- Auditoría sin borrado automático hasta formalizar retención.
- Estructura corporativa configurable; permisos descendentes solo pueden reducirse.
