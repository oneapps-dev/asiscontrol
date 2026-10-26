# AsisControl — Registro de implementación multicliente
Actualizado: 2026-10-26
Rama autorizada: `main`
Plan rector: `docs/plan-implementacion-arquitectura-multicliente-2026-10-24.md`

## Estado y regla de entrega

**EN DESARROLLO — NO DESPLEGAR TODAVÍA.** El plan de fases 0–9 aún NO se ha completado ni certificado de extremo a extremo. Las pruebas CI comprueban un subconjunto de operaciones sobre una base MySQL nueva, pero no equivalen a auditoría integral, instalación migrada ni prueba en BanaHosting.

### Avances de seguridad e integridad

- La elevación a `superadmin` exige un registro activo en `platform_admins`. Se impide su asignación desde la administración ordinaria.
- Las sesiones de empleados se revalidan contra estado del usuario, rol, tenant y suscripción. El portal revalida residente, persona, unidad, sede y tenant; una revocación surte efecto en la próxima petición.
- Se añadieron filtros por sede a `/bootstrap` y consultas de visitas, vehículos, paquetes, unidades, incidentes, invitaciones, sedes, dispositivos, reservas, parqueaderos, puestos y eventos de acceso.
- Las operaciones de turnos, visitas, vehículos, paquetes e incidentes seleccionadas verifican el turno, el cliente y la sede autorizados.
- Rutas antiguas no auditadas **se deniegan temporalmente a usuarios limitados a sedes**. Esto es un bloqueo conservador de seguridad, NO una implementación funcional definitiva de esas rutas. No desplegar para usuarios reales hasta recuperar todos los flujos autorizados mediante las verificaciones requeridas.
- Auditoría global, datos de personas y restricciones de alcance general requieren permisos de cliente completo; no se exponen a un perfil limitado.
- La sincronización offline reconoce IDs reales de visita y vehículo; valida pertenencia a tenant/sede y protege cambios a registros de otras sedes. Falta probar completamente reintentos, conflictos concurrentes e idempotencia.

### Migraciones implementadas

| Migración | Contenido |
|---|---|
| 015 | Tipos de clientes, jerarquía, relaciones de administración delegada |
| 016 | Roles maestros, techos de permisos, restricciones descendentes y alcances |
| 017 | Límites de plan normalizados y funcionalidades contratadas |
| 018 | Permiso `clients.manage` para clientes secundarios |
| 019 | Reparación de alcances automáticos operativos, conservando concesiones explícitas |

Estas migraciones son incrementales. **No ejecutar `install.sql` sobre una base existente.** Antes de cualquier migración real: copia restaurable y pruebas sobre clon.

### API y frontend entregados parcialmente

- CREAMOX puede crear clientes independientes o secundarios por tipo, con administrador e invitación de activación.
- Operador de Medios dispone de una vista de clientes administrados y la API exige pertenencia y permiso `clients.manage`.
- Se incorporaron endpoints para contexto, permisos efectivos, alcances, restricciones de rol, límites de suscripción y métricas contextuales.
- El dashboard muestra un bloque contextual con métricas calculadas en MySQL, restringidas a las sedes del usuario. Es una primera implementación del dashboard diferenciado, no el conjunto definitivo de indicadores por perfil y sector.
- Las semillas QA aisladas simulan empresa de seguridad, propiedad horizontal, industria, personal, turnos, visitas, vehículos, paquetes, incidentes, unidades y dispositivos.

## Pruebas implementadas en GitHub Actions

- Sintaxis de archivos PHP, tipado TypeScript, compilación React/Vite.
- Base de datos MySQL 8 nueva, instalador, migraciones y semillas QA.
- API de salud y autenticación básica.
- Comprobación negativa del panel global desde guardia.
- Aislamiento entre dos sedes del **mismo** tenant en `/bootstrap` y `/visits`.
- Rechazo de creación de turno fuera del alcance.
- Denegación de lectura de auditoría a guardia.
- Denegación de salida offline sobre visita de otra sede.
- Revocación de sesión del portal tras desactivar un residente.
- Verificación del conteo de visitas del dashboard contextual frente a una consulta SQL restringida por sede.

La evidencia de cada ejecución está en GitHub Actions. Cualquier ejecución roja bloquea considerar la revisión validada.

## Fases todavía pendientes

1. Revisar todas las rutas existentes, recursos adjuntos, importaciones y exports para aplicar permiso + propiedad + alcance + estado comercial. Rehabilitar cada ruta temporalmente bloqueada solo después de probarla.
2. Usuarios con asignaciones autorizadas en múltiples tenants y selector de contexto verificable en backend/frontend.
3. Integrar límites y funcionalidades del plan a todos los recursos y operaciones, no solo sedes, puestos, unidades y usuarios.
4. Jerarquías industriales configurables (sede, edificio, piso, área, dependencia) y sus pantallas/acciones.
5. Dashboards completos para CREAMOX, administradores, Operadores de Medios, supervisores, vigilantes, auditores y residentes con métricas y visibilidad por sector.
6. Modo CREAMOX «Ver como» en solo lectura, expirable y auditado, sin mutaciones.
7. Auditoría estructurada extensiva y gestión completa de permisos, roles, alcances, sesiones y concesiones.
8. Matriz de aceptación CA-01 a CA-23, regresión de módulos existentes, carga, concurrencia, seguridad ofensiva y calidad offline.
9. Prueba de actualización desde copia de una base real, respaldo y restauración, documentación del despliegue compartido y verificación de que no sean públicos los archivos sensibles.

### Condición de cierre

No declarar listo para BanaHosting hasta:
- 100 % de fases implementadas sin reducción funcional;
- CI y matriz de aceptación aprobadas;
- instalación limpia, migración desde esquema anterior y restauración probadas;
- paquete de despliegue seguro y manuales completos;
- autorización del usuario para su posterior publicación en servidor de pruebas.

## Decisiones aprobadas

Propiedad de datos por tenant y delegaciones explícitas; varios operadores por empresa; límites configurables por CREAMOX; «Ver como» solo lectura; retención de auditoría sin borrado automático hasta política aprobada; permisos descendentes; jerarquía industrial configurable; datos QA ficticios exclusivamente en ambientes de prueba.
