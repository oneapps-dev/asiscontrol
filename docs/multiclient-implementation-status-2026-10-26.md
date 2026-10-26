# AsisControl — Registro de implementación multicliente
Fecha de revisión: 2026-10-26
Rama de trabajo autorizada: `main`

## Estado real
**EN DESARROLLO. NO PUBLICAR EN EL DOMINIO DE PRODUCCIÓN.** El plan de fases 0–9 NO está completo.

### Implementado y publicado
- Endurecimiento de `superadmin`, creación de usuarios y revalidación de sesiones y suscripciones.
- Migración 015: tipos de clientes, jerarquía, raíz y relaciones delegadas.
- Migración 016: roles maestros, techos de permisos, restricciones de cliente y alcances basados en `user_scopes`.
- Migración 017: límites de planes normalizados y controles transaccionales en creación de sedes, puntos, unidades y usuarios.
- Migración 018: permiso específico `clients.manage` y creación autorizada de clientes secundarios bajo empresas de seguridad.
- Endpoints de consulta de contexto/permisos, asignación de alcances, restricciones descendentes, lectura/actualización de límites, creación de secundarios y consulta del catálogo de tipos.
- React: selección de tipo de cliente, gestión de clientes secundarios por operador y separación inicial del Superpanel CREAMOX.
- Semillas QA aisladas con empresas, propiedad horizontal, industria, usuarios, sedes, alcances, turnos, visitantes, vehículos, paquetes, incidentes y dispositivos.
- Workflow GitHub Actions: PHP syntax, TypeScript, Vite build, MySQL 8 instalación limpia, migraciones, semillas y comprobaciones básicas de endpoints.

### Sin terminar (bloqueos para entrega end-to-end)
- Aplicación exhaustiva de filtros de alcance a TODAS las consultas y mutaciones, incluidos endpoints existentes y la carga `/bootstrap`.
- Compatibilidad completa de delegación multicliente para usuarios con varios alcances y selector de contexto de sesión.
- Catálogos y formularios específicos de empresas industriales, propiedad horizontal y seguridad privada.
- Dashboards especializados por tipo de cliente y rol con métricas filtradas en backend.
- Vista «Ver como» de CREAMOX en modo lectura, expiración y auditoría.
- Auditoría estructurada completa, incluidos cambios de valor y contexto autorizado.
- Matriz CA-01 a CA-23 y pruebas negativas de todos los endpoints y recursos.
- Prueba de migración sobre una COPIA de una instalación existente, restauración verificada y revisión manual de seguridad.
- Confirmación de que ningún documento sensible, archivo SQL o PHP fuente es accesible directamente en el hosting público.

### Validación y despliegue
CI verde significa compilación y pruebas automáticas acotadas; NO certifica seguridad ni funcionamiento real de todo el producto.
No ejecutar las semillas QA en ninguna base con datos reales.
Para migrar una instalación: copia de seguridad + verificación de restauración + migraciones numeradas; nunca ejecutar `install.sql` sobre producción existente.

## Decisiones aprobadas
Los datos pertenecen a cada tenant; las empresas de seguridad solo obtienen delegaciones explícitas. Varios operadores por empresa. Límites desde CREAMOX. «Ver como» solo lectura. Sin depuración automática de auditoría hasta aprobación legal. Permisos de clientes solo descendentes. Estructura industrial configurable.
