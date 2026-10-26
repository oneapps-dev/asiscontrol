# Plan de implementación de arquitectura multi cliente

## Propósito

Este plan convierte el requerimiento funcional del 14 de octubre de 2026 en una secuencia de implementación para Asiscontrol. El objetivo es pasar del modelo actual, que ya cuenta con aislamiento básico por `tenant_id` y operación de control de accesos, a una plataforma SaaS jerárquica con clientes, clientes secundarios, alcances, permisos efectivos, límites comerciales y dashboards diferenciados.

El plan cubre las funciones que no dependen de una pasarela de pagos ni de un modelo específico de hardware. Las integraciones biométricas, lectores, torniquetes, LPR y pasarelas se mantienen detrás de adaptadores para implementarlas después sin cambiar el núcleo de autorización.

## Resultado objetivo

La interfaz efectiva de cada usuario debe calcularse así:

```text
Interfaz efectiva = tipo de cliente
                  + rol maestro
                  + alcance asignado
                  + plan contratado
                  + restricciones administrativas
                  + estado de la cuenta
```

El frontend nunca debe permitir seleccionar manualmente el rol o el cliente como mecanismo de autorización. El backend debe calcular y validar el contexto en cada petición.

## Principios de implementación

1. CREAMOX es el único nivel global de Superadministrador.
2. Los clientes no crean roles maestros ni amplían permisos.
3. El rol define qué puede hacer una persona; el alcance define dónde puede hacerlo.
4. Los permisos efectivos solo pueden reducirse hacia niveles inferiores.
5. Toda consulta y mutación debe validar tenant, estado, alcance, permiso y límite del plan.
6. La información operativa de un cliente nunca se comparte con otro cliente por defecto.
7. Las acciones de soporte global y “Ver como” deben quedar auditadas.
8. Las migraciones deben ser incrementales, reversibles cuando sea posible y probadas en una copia de la base de datos.
9. Las variables protegidas se mantienen exclusivamente en `.env`.
10. Las funciones de hardware y pagos se consumen mediante interfaces desacopladas.

## Situación actual resumida

Ya existe una base funcional con:

- autenticación por sesión y recuperación de contraseña;
- aislamiento básico por `tenant_id`;
- clientes, sedes, turnos, visitas, vehículos, paquetes, incidentes y reservas;
- panel de proveedor, planes y suscripciones;
- dispositivos y puntos de acceso;
- roles básicos y matriz de permisos;
- evidencias convertidas a WebP;
- cola offline y resolución de conflictos;
- frontend React compilado junto con backend PHP y SQL.

Las principales brechas son la jerarquía entre clientes, los alcances efectivos, los límites aplicados en backend, los dashboards por perfil, la función “Ver como” y el control de escalamiento de privilegios.

## Fases de implementación

### Fase 0 — Corrección de seguridad y congelamiento del modelo actual

Objetivo: eliminar riesgos antes de ampliar la arquitectura.

Tareas:

- Impedir que un administrador, coordinador, supervisor o vigilante cree o asigne el rol `superadmin`.
- Separar la identidad global de CREAMOX de los usuarios internos de cada tenant.
- Hacer que `superadmin` solo sea válido para cuentas registradas en `platform_admins` con nivel global activo.
- Validar que una cuenta suspendida, inactiva o con suscripción vencida no pueda iniciar sesión ni consumir endpoints operativos.
- Revisar todos los endpoints que usan solo `tenant_id` y preparar una función central de contexto autorizado.
- Mantener un registro de las decisiones y cambios de esta fase en auditoría de plataforma.

Entregables:

- Corrección de `POST/PATCH /users`.
- Funciones `require_platform_admin`, `require_tenant_context` y `require_scope` revisadas.
- Pruebas que demuestren que un administrador de cliente no puede elevarse a CREAMOX.

Criterios de aceptación:

- Un usuario interno nunca puede crear, editar ni activar un Superadministrador global.
- Una cuenta suspendida recibe una respuesta funcional de acceso bloqueado.
- Las operaciones existentes continúan funcionando para los usuarios legítimos.

### Fase 1 — Modelo de clientes y jerarquía

Objetivo: representar clientes principales, clientes secundarios y tipos de instalación.

Cambios de base de datos:

- Crear catálogo `client_types` con:
  - `security_company`;
  - `residential`;
  - `industrial`;
  - `corporate`;
  - `custom`.
- Ampliar `tenants` con `client_type_id`, `parent_tenant_id`, `root_tenant_id`, `created_by_platform_user_id` y estado comercial.
- Crear `tenant_relationships` para relaciones como empresa de seguridad, cliente administrado y cliente secundario.
- Crear `tenant_features` o una estructura equivalente para módulos habilitados por tipo de cliente.
- Conservar `sites` como sedes físicas del cliente y `access_points` como puestos operativos.
- Agregar restricciones de integridad para evitar ciclos en la jerarquía.
- Crear índices para búsquedas por cliente raíz, cliente padre y estado.

Backend:

- Actualizar `POST /platform/tenants` para recibir tipo de cliente, plan, sede inicial y rol principal.
- Crear `GET/POST/PATCH /platform/client-types` solo para CREAMOX.
- Crear `GET /platform/tenants/{id}/children`.
- Crear `POST /platform/tenants/{id}/children` para que un Operador de Medios cree clientes secundarios dentro del límite contratado.
- Crear validación de que una Propiedad Horizontal no pueda crear clientes secundarios.
- Crear el flujo específico de creación:
  - Empresa de Seguridad → Operador de Medios Tecnológicos.
  - Propiedad Horizontal → Administrador.
  - Empresa Industrial o Corporativa → Administrador y Coordinador opcional.

Frontend:

- Agregar tipo de cliente al formulario de creación de clientes.
- Mostrar la estructura jerárquica en el panel CREAMOX.
- Crear un selector de cliente y árbol de relación para usuarios autorizados.
- Eliminar cualquier selector manual de rol como contexto de seguridad.

Criterios de aceptación:

- CREAMOX puede crear cada tipo de cliente con su usuario principal correcto.
- Una empresa de seguridad puede crear clientes secundarios solo dentro de su jerarquía.
- Una Propiedad Horizontal permanece como un único cliente aunque tenga varias sedes o porterías.
- El backend rechaza clientes secundarios que no correspondan al tipo de cliente.

### Fase 2 — Roles maestros, permisos efectivos y alcances

Objetivo: implementar autorización jerárquica real.

Modelo de datos:

- Crear `master_roles` administrado por CREAMOX.
- Mantener una relación `role_permissions_master` para el máximo global por rol.
- Crear `tenant_role_restrictions` para permisos que un cliente puede retirar sin agregar nuevos.
- Crear `user_scope_assignments` para asignar alcance a:
  - cliente;
  - cliente secundario;
  - sede;
  - puesto;
  - unidad residencial;
  - vigilantes específicos cuando aplique.
- Crear `permission_change_logs` o integrar cambios de permisos en la auditoría de plataforma.

Roles iniciales:

- Superadministrador CREAMOX.
- Operador de Medios Tecnológicos.
- Administrador.
- Coordinador / Supervisor.
- Auditor.
- Vigilante.
- Residente.
- Empleado autenticado opcional.

Motor de autorización:

```text
permiso efectivo = permiso maestro CREAMOX
                 ∩ permiso permitido por tipo de cliente
                 ∩ permiso del rol
                 ∩ restricciones del superior
                 ∩ alcance asignado
                 ∩ estado del plan
```

Endpoints:

- `GET /me/context`.
- `GET /me/effective-permissions`.
- `GET /users/{id}/scopes`.
- `PUT /users/{id}/scopes`.
- `GET /roles/master`.
- `PUT /tenants/{id}/role-restrictions/{role}`.
- `POST /users/{id}/role-change`.

Backend:

- Centralizar la comprobación en `authorize($permission, $resourceContext)`.
- Aplicar el alcance a todas las consultas de visitas, vehículos, paquetes, incidentes, reservas, turnos, dispositivos, auditoría y reportes.
- Validar que el usuario que asigna un rol o alcance tenga autoridad sobre el destinatario.
- Impedir que un cliente conceda permisos que no existen en el rol maestro.

Frontend:

- Mostrar solo permisos efectivos.
- Permitir al administrador retirar permisos subordinados, pero no ampliar permisos máximos.
- Crear pantallas para asignar clientes, sedes, puestos y unidades.
- Mostrar una barra de contexto para usuarios con más de un alcance.

Criterios de aceptación:

- Un coordinador con dos clientes asignados no puede consultar un tercero por URL ni API.
- Un vigilante limitado a un puesto no puede leer eventos de otra sede.
- Un administrador puede retirar “Paquetes” a un vigilante, pero no conceder “Backups”.
- Ninguna acción depende únicamente de ocultar botones en React.

### Fase 3 — Límites de planes y reglas comerciales

Objetivo: convertir los límites almacenados en reglas aplicadas.

Catálogo de límites:

- clientes secundarios;
- sedes;
- puestos;
- unidades residenciales;
- usuarios administrativos;
- usuarios operativos, si el plan lo requiere;
- retención de reportes, si aplica;
- módulos habilitados.

Backend:

- Crear `plan_limits` normalizado o un lector seguro de `limits_json`.
- Crear `plan_entitlements` para módulos y capacidades.
- Crear `assert_plan_capacity($tenantId, $resource, $quantity = 1)`.
- Aplicar la validación antes de crear clientes secundarios, sedes, puestos, unidades y usuarios limitados.
- Devolver errores funcionales como `PLAN_LIMIT_REACHED`, sin detalles SQL.
- Mantener los periodos pagados aunque se cambie el plan.
- Registrar cambios de plan y decisiones administrativas en auditoría.

Frontend:

- Mostrar consumo actual, límite y porcentaje utilizado.
- Deshabilitar acciones cuando el límite esté lleno, manteniendo el motivo visible.
- Diferenciar “sin permiso” de “límite del plan alcanzado”.

Criterios de aceptación:

- Una empresa con límite de tres clientes secundarios no puede crear un cuarto.
- El límite de clientes no bloquea la cantidad de vigilantes si el plan no lo define.
- Los periféricos y la biometría no se bloquean por plan, tal como exige el requerimiento.

### Fase 4 — Flujos específicos por tipo de cliente

Objetivo: adaptar estructura, datos y módulos al contexto contractual.

Empresa de Seguridad:

- Dashboard multi-cliente del Operador de Medios.
- Administración de clientes secundarios.
- Asignación de coordinadores, vigilantes, sedes y puestos.
- Consulta consolidada de turnos, accesos, incidentes y dispositivos.
- Métricas por cliente y por puesto.

Propiedad Horizontal:

- Torres, bloques, unidades, residentes, parqueaderos y espacios comunes.
- Invitaciones, reservas, paquetes y personal autorizado.
- Dashboard de visitantes, personas dentro, vehículos, paquetes y reservas.
- Prohibición de crear clientes secundarios.

Empresa Industrial o Corporativa:

- Áreas, pisos, oficinas y dependencias.
- Empleados, contratistas, ARL, horarios y asistencia.
- Visitantes, vehículos, puestos, turnos e incidentes.
- Ocultar residentes, torres, apartamentos y casas como estructura principal.

Backend:

- Validar la disponibilidad de entidades por `client_type`.
- Rechazar creación de entidades no compatibles con el tipo de cliente.
- Crear catálogos de estructura organizacional para empresas industriales.

Frontend:

- Renderizar módulos según `client_type`, permisos efectivos y plan.
- Cambiar etiquetas, campos y formularios según el tipo de cliente.
- No cargar información de residentes para clientes industriales salvo que exista una configuración autorizada.

### Fase 5 — Dashboards diferenciados

Objetivo: entregar una experiencia propia para cada perfil principal.

Dashboards requeridos:

1. CREAMOX: clientes, suscripciones, límites, dispositivos, alertas globales, auditoría, estado de plataforma y acceso “Ver como”.
2. Operador de Medios: clientes administrados, cupos, puestos, vigilantes, turnos, personas, vehículos, incidentes y actividad por cliente.
3. Administrador de Propiedad Horizontal: visitantes, personas dentro, vehículos, paquetes, reservas, eventos, vigilantes, puestos y residentes.
4. Administrador Empresarial: empleados programados, presentes, retardos, ausentes, contratistas, visitantes, vehículos, incidentes y alertas documentales.
5. Coordinador / Supervisor: únicamente información de clientes, sedes, puestos y vigilantes asignados.
6. Vigilante: turno, registrar visitante, salida, vehículo, invitaciones, paquetes, personas dentro e incidentes.
7. Residente: invitaciones, visitantes autorizados, paquetes, reservas, unidad, vehículos, parqueaderos y notificaciones.

Implementación frontend:

- Separar `Dashboard` en componentes por perfil.
- Crear una selección de dashboard basada en contexto calculado, no en una selección manual.
- Hacer que las tarjetas, alertas y listas enlacen al módulo correspondiente.
- Mantener el estado de turno visible solo para perfiles operativos.
- No mostrar administración SaaS a vigilantes o residentes.

Implementación backend:

- Crear `GET /dashboard` con filtros por tipo de cliente, rol y alcance.
- Crear endpoints de métricas específicas para plataforma, cliente y puesto.
- Evitar que el frontend tenga que descargar datos fuera del alcance para luego ocultarlos.

Criterios de aceptación:

- Cada dashboard tiene estructura, métricas y acciones propias.
- Un residente no recibe datos empresariales o administrativos.
- Un coordinador solo recibe datos de sus asignaciones.
- CREAMOX no queda condicionado por un turno operativo.

### Fase 6 — Selector de contexto y función “Ver como”

Objetivo: permitir soporte controlado sin cambiar la cuenta real.

Selector de contexto:

- Mostrar la ruta `Empresa de Seguridad > Cliente > Sede > Puesto`.
- Permitir seleccionar únicamente contextos presentes en `user_scope_assignments`.
- Guardar el contexto seleccionado en sesión con expiración y auditoría.
- Recalcular permisos y datos al cambiar de contexto.

“Ver como”:

- Disponible exclusivamente para CREAMOX.
- Flujo: cliente → rol → usuario o perfil de referencia.
- Primera versión en modo lectura.
- Mostrar siempre el indicador `Modo Ver como activo`.
- Incluir botón `Salir de Ver como`.
- No reemplazar la cuenta real ni sus credenciales.

Endpoints:

- `POST /platform/impersonation/start`.
- `GET /platform/impersonation/context`.
- `POST /platform/impersonation/stop`.
- `GET /platform/impersonation/audit`.

Auditoría mínima:

- Superadministrador.
- Cliente visualizado.
- Rol y usuario visualizados.
- Inicio, fin y duración.
- Contexto seleccionado.
- Acciones realizadas, si en el futuro se habilitan acciones de escritura.

### Fase 7 — Auditoría y trazabilidad completa

Objetivo: cumplir la trazabilidad exigida por el modelo jerárquico.

Ampliar la auditoría para registrar:

- actor;
- acción;
- fecha y hora;
- cliente raíz y cliente efectivo;
- sede;
- puesto;
- registro afectado;
- valor anterior;
- valor nuevo;
- IP;
- dispositivo;
- contexto seleccionado;
- motivo y referencia de soporte.

Implementación:

- Crear una función transaccional de auditoría estructurada.
- Usar `metadata_json` para datos técnicos, pero normalizar los campos que se consultarán frecuentemente.
- Auditar creación, suspensión, cambio de estado, contraseña, rol, permisos, alcance, sede, puesto, dispositivo, plan y restricciones.
- Mantener auditoría separada para plataforma y para cada tenant.
- Agregar filtros por cliente, sede, puesto, actor, acción y rango de fechas.

### Fase 8 — Actualización de configuración y administración de usuarios

Objetivo: dividir la configuración actual y eliminar ambigüedades.

Secciones:

1. Configuración general.
2. Estructura y sedes.
3. Puestos de acceso.
4. Usuarios.
5. Roles y restricciones de permisos.
6. Alcances.
7. Dispositivos.
8. Restricciones.
9. Importaciones.
10. Configuración específica por tipo de cliente.

El módulo de usuarios debe incluir:

- nombre completo;
- tipo y número de documento;
- teléfono;
- correo;
- estado;
- rol maestro;
- alcance;
- sede y puesto;
- cambio de contraseña;
- activación, suspensión e inactivación;
- historial de auditoría.

Debe eliminarse el selector manual de rol que modifica el estado local del usuario. El rol debe llegar del backend y cambiarse únicamente mediante una operación autorizada.

### Fase 9 — Pruebas, migración y despliegue

Pruebas unitarias:

- cálculo de permisos efectivos;
- validación de alcance;
- límites de plan;
- jerarquía de clientes;
- estados de suscripción;
- auditoría estructurada;
- creación de dashboards por contexto.

Pruebas de integración:

- cada endpoint con rol permitido y no permitido;
- acceso cruzado entre clientes;
- acceso cruzado entre sedes y puestos;
- suspensión de clientes y usuarios;
- creación de clientes secundarios;
- restricciones de permisos descendentes;
- “Ver como” sin mutar la cuenta real.

Pruebas de aceptación:

- CA-01 a CA-23 del requerimiento funcional.
- Escenario de Empresa de Seguridad con límite de tres clientes.
- Escenario de coordinador limitado a un cliente y cuatro vigilantes.
- Escenario de Propiedad Horizontal sin clientes secundarios.
- Escenario de empresa industrial sin módulos residenciales.
- Escenario de intento de escalamiento a `superadmin`.
- Escenario de acceso directo por URL sin alcance.

Migración:

1. Crear backup completo y verificar restauración.
2. Aplicar migraciones en ambiente de pruebas.
3. Crear catálogo de tipos de cliente.
4. Clasificar tenants existentes como `residential` o `custom` temporalmente.
5. Crear roles maestros y copiar permisos actuales como baseline.
6. Crear alcances iniciales a partir de `tenant_id` y `site_id`.
7. Validar que no existan usuarios internos con rol `superadmin` fuera de CREAMOX.
8. Activar validaciones por feature flag.
9. Ejecutar pruebas de regresión.
10. Aplicar en producción durante una ventana controlada.

Despliegue:

- Crear migraciones numeradas posteriores a `2026_09_12_014`.
- Mantener `backend/database/install.sql` y `schema.sql` sincronizados.
- Compilar con `VITE_API_REQUIRED=true`.
- Publicar frontend y backend en la misma versión de `frontend/dist`.
- Verificar `/api/health`, login, bootstrap, permisos y un flujo operativo por rol.
- No publicar `.env`, tokens de dispositivos ni claves SMTP.

## Nuevos endpoints propuestos

```text
GET    /api/me/context
GET    /api/me/effective-permissions
GET    /api/platform/client-types
POST   /api/platform/tenants
GET    /api/platform/tenants/{id}/children
POST   /api/platform/tenants/{id}/children
GET    /api/platform/tenants/{id}/limits
GET    /api/users/{id}/scopes
PUT    /api/users/{id}/scopes
GET    /api/roles/master
PUT    /api/tenants/{id}/role-restrictions/{role}
POST   /api/platform/impersonation/start
GET    /api/platform/impersonation/context
POST   /api/platform/impersonation/stop
GET    /api/platform/impersonation/audit
GET    /api/dashboard/platform
GET    /api/dashboard/security-company
GET    /api/dashboard/residential
GET    /api/dashboard/business
GET    /api/dashboard/supervisor
GET    /api/dashboard/guard
GET    /api/dashboard/resident
```

## Migraciones sugeridas

- `2026_10_24_015_client_types_and_hierarchy.sql`
- `2026_10_24_016_master_roles_and_scopes.sql`
- `2026_10_24_017_effective_permissions.sql`
- `2026_10_24_018_plan_entitlements_and_limits.sql`
- `2026_10_24_019_context_sessions_and_impersonation.sql`
- `2026_10_24_020_structured_audit.sql`
- `2026_10_24_021_business_structure.sql`
- `2026_10_24_022_dashboard_metrics.sql`

Cada migración debe:

- ejecutarse dentro del runner existente;
- registrar su nombre en `schema_migrations`;
- comprobar prerequisitos;
- ser segura para reintentos controlados;
- incluir índices y claves foráneas apropiadas;
- documentar la estrategia de rollback o restauración.

## Decisiones necesarias antes de iniciar la Fase 1

1. Confirmar si una empresa de seguridad y cada cliente administrado se representan como tenants relacionados o como una entidad contractual separada.
2. Confirmar los límites exactos por plan: clientes secundarios, sedes, puestos, unidades y usuarios.
3. Confirmar si “Ver como” será únicamente lectura en la primera versión. Se recomienda lectura.
4. Confirmar si el Operador de Medios Tecnológicos es siempre único por empresa de seguridad.
5. Confirmar si un usuario puede tener alcances en varios clientes al mismo tiempo.
6. Confirmar la retención de la auditoría global y de la auditoría de cada cliente.
7. Confirmar la estructura de áreas, pisos, oficinas y dependencias para clientes industriales.
8. Confirmar qué permisos puede retirar un administrador de cliente sin afectar el rol maestro.

No se requiere definir todavía una pasarela de pago ni un fabricante biométrico para comenzar las fases 0 a 8. Esas integraciones deben conectarse cuando se seleccionen los proveedores, usando los adaptadores existentes.

## Definición de cierre

La implementación se considerará completa cuando:

- no exista una ruta para escalar privilegios desde un tenant;
- todos los usuarios tengan rol y alcance efectivos calculados por backend;
- los clientes secundarios respeten jerarquía y límites;
- cada tipo de cliente vea únicamente su estructura y módulos;
- cada rol principal tenga un dashboard propio;
- “Ver como” funcione con auditoría completa;
- las pruebas CA-01 a CA-23 pasen en un ambiente con datos de prueba;
- el build final incluya frontend, backend, migraciones y documentación;
- `/api/health` y los smoke tests funcionen contra la base de datos de producción.
