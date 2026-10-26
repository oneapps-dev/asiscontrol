# AsisControl — Registro actualizado de implementación multicliente

Actualizado: 2026-10-26  
Rama: `main`  
Plan rector: `docs/plan-implementacion-arquitectura-multicliente-2026-10-24.md`

## Estado de liberación

**EN DESARROLLO — NO DESPLEGAR EN BANAHOSTING.**
Se implementó y probó en CI una parte sustancial de las fases 0–9, pero aún no se ha demostrado el cumplimiento de todos los criterios CA-01 a CA-23, la compatibilidad de TODAS las rutas heredadas, ni la migración y restauración de una instalación real.
**Una ejecución CI verde sobre MySQL nuevo no autoriza la publicación.**

## Componentes incorporados

| Fase | Avance verificable | Pendiente antes de cierre |
|---|---|---|
| 0 Seguridad | Rol global restringido a `platform_admins`; revalidación de sesión, suspensión y suscripción; filtros conservadores por sede | Auditoría integral de endpoints heredados; regresión operativa sin pérdida funcional |
| 1 Clientes | Tipos security_company, residential, industrial, corporate y custom; jerarquía, relaciones administradas y clientes secundarios | Revisar exhaustivamente los casos de delegación |
| 2 Permisos y alcances | Rol maestro con techo; restricciones descendentes; sede y miembros multicliente explícitos; revocación efectiva | Permisos por cada recurso, adjuntos, reportes, importaciones y URL directas |
| 3 Planes | Límites de sedes, puestos, unidades, usuarios por categoría y clientes secundarios, incluidos cupos de asignaciones | Entitlements restantes y concurrencia de cuotas |
| 4 Sectores | Dependencias industriales configurables, personal; módulos residenciales por tipo y excepciones autorizadas | Flujos empresariales y residenciales en ambiente real |
| 5 Dashboards | KPI SQL por sector/rol, acotados a cliente y sedes; clientes administrados, asistencia y residencia | Tableros finales por perfil, enlaces y acciones |
| 6 Soporte CREAMOX | Ver como solo lectura, revocable, 10 minutos, usuario real, auditoría y smoke tests | E2E navegador y UX de expiración |
| 7 Auditoría | Ledger estructurado adicional, redactado, filtros de cliente/actor y vista CREAMOX | Cobertura total de mutaciones, retención aprobada, exportación |
| 8 Contextos | Sedes seleccionables y asignaciones a varios clientes por usuario, rol y sede; validación de vínculo, suscripción y expiración | Multi-tab, móviles y eventos offline heredados pendientes |
| 9 Entrega | PHP lint, TypeScript/build, MySQL limpio, tests integrados; webroot sin SQL de QA | CA-01 a CA-23, E2E, base real clonada, backup/restore, cargas y pruebas en hosting |

## Migraciones implementadas

| Versión | Contenido |
|---|---|
| 015 | Tipos de cliente, jerarquía, relaciones |
| 016 | Roles maestros, restricciones, alcances |
| 017 | Entitlements y límites de planes |
| 018 | Permiso `clients.manage` para clientes secundarios |
| 019 | Reparación no destructiva de alcances operativos |
| 020 | Sesiones del soporte Ver como |
| 021 | Jerarquía industrial y personal |
| 022 | Auditoría estructurada |
| 023 | `tenant_user_memberships`: asignaciones intercliente con rol/sede |

**No ejecutar `backend/database/install.sql` en una instalación existente.** Antes de migrar: copia restaurable, clon de pruebas y `backend/scripts/run_migrations.php` controlado.

## Contrato de contexto y asignación multicliente

- La cuenta principal permanece en `users.tenant_id`. La selección no altera esa identidad.
- `tenant_user_memberships` une usuario, cliente destino, rol, sede y vencimiento opcional. No permite rol global.
- Operadores de seguridad solo gestionan personal de su empresa para clientes con relación `managed_by` o `secondary_of` vigente.
- El backend revalida en cada petición el rol origen (techo de privilegios), la membresía, relación comercial, sede, estado del cliente y vigencia de la suscripción.
- La revocación bloquea el contexto delegado; el usuario regresa explícitamente a su cuenta principal.
- El frontend no permite cambiar cliente con cola offline pendiente; eventos antiguos sin identificación original quedan retenidos, nunca se atribuyen a la sesión actual.
- El servidor rechaza un evento offline cuya identidad o cliente declarado no coincidan con los de la sesión.

Rutas incorporadas:
- `GET /api/me/tenants`; `POST /api/me/tenant/select`; `POST /api/me/tenant/clear`.
- `GET /api/platform/tenants/{id}/membership-options`.
- `GET/POST /api/platform/tenants/{id}/memberships`; `DELETE /api/platform/tenants/{id}/memberships/{memberId}`.
- `GET /api/me/contexts`, `POST /api/me/context/select` y `POST /api/me/context/clear`.
- `GET /api/dashboard/contextual`.

## Pruebas automatizadas

El workflow `.github/workflows/asiscontrol-ci.yml` utiliza MySQL 8 aislado y ejecuta:

1. Sintaxis PHP, TypeScript, build con API obligatoria y comprobación del webroot.
2. Instalación limpia, todas las migraciones y datos QA ficticios.
3. `multitenant_security_smoke.sh`: alcance, aislamiento y autorización.
4. `platform_preview_smoke.sh`: Ver como y rechazo de escrituras.
5. `industrial_structure_smoke.sh`: jerarquía, ciclos y personal.
6. `user_quota_smoke.sh`: cuotas por categoría/reactivación.
7. `client_type_gate_smoke.sh`: módulos y excepciones.
8. `site_context_smoke.sh`: selección y filtrado de sedes.
9. `structured_audit_smoke.sh`: reportes y redacción.
10. `multi_tenant_membership_smoke.sh`: varios clientes, tercero bloqueado, escalamiento, revocación y suscripciones.
11. `sector_dashboard_smoke.sh`: indicadores SQL y visibilidad por perfil.
12. `offline_context_smoke.sh`: bloqueo de origen entre usuario/cliente más idempotencia.

Evidencia por ejecución: [GitHub Actions](https://github.com/oneapps-dev/asiscontrol/actions).
**Solo las ejecuciones que hayan finalizado satisfactoriamente se consideran pruebas aprobadas.**

## Cobertura de autorización recuperada y comprobada (parcial)

| Endpoints | Restricción aplicada | Test |
|---|---|---|
| GET /files, POST /files, GET /files/{id}/download | files.manage; tipo de propietario permitido; registro del mismo cliente y sede; validación previa a cada descarga | evidence_owner_scope_smoke.sh |
| GET /reports/visits.csv, .xlsx, .html | reports.view y filtro SQL por sedes antes de paginar | site_scoped_reports_smoke.sh |
| GET /attendance y GET /reports/attendance.csv, .xlsx, .html | attendance.view; filtro SQL por sede y joins por cliente | site_scoped_reports_smoke.sh |
| GET /reports/summary | reports.view; agregados SQL por sede | site_scoped_reports_smoke.sh |

Se corrigió la ruta histórica GET /reports/attendance.html, que estaba definida después del 404 final del enrutador. No se ampliaron los permisos: en la base QA, el Administrador no tiene reports.view, de modo que el test de reportes usa Supervisor autorizado.

Pruebas aprobadas del bloque de reportes: [Actions #40378349135](https://github.com/oneapps-dev/asiscontrol/actions/runs/40378349135). Las nuevas regresiones de evidencias también deben aprobarse en su propia ejecución de CI.

**Esta cobertura es parcial.** Las rutas que todavía no tienen prueba positiva y negativa permanecen bajo revisión. La Fase 9 y los criterios CA-01 a CA-23 NO están cerrados.

## Endurecimiento de rutas heredadas — 26 de octubre

Se completó un bloque adicional de correcciones sobre los endpoints históricos. No supone el cierre total del plan:

| Alcance | Implementación | Evidencia |
|---|---|---|
| Creación de registros | 10 endpoints capturan el ID real ANTES de guardar auditoría, evitando responder con el ID del log | `legacy_created_ids_smoke.sh` y pruebas de reservas |
| Campos opcionales | 11 expresiones usan el valor validado/default, evitando campos NULL al crear reservas, marcar asistencia y registrar eventos | `site_scoped_bookings_smoke.sh` y regresiones |
| Visitas, vehículos, paquetes e incidentes | Filtros SQL por cliente y sede ANTES de LIMIT tanto en listados como en bootstrap | `site_scoped_operational_pagination_smoke.sh` (220 registros ajenos por cada colección) |
| Reservas e invitados | Autorización de sede en recursos, reservas, invitados y cambios de estado | `site_scoped_bookings_smoke.sh` |
| Turnos, invitaciones y parqueaderos | La consulta aplica primero alcance por sede en SQL y conserva un segundo filtro defensivo | PHP lint, build, MySQL CI; falta ampliar pruebas de volumen específicas de estos tres módulos |

Las validaciones anteriores no implican que el resto de rutas haya sido certificado. Los flujos offline con múltiples pestañas, importaciones, recursos descargables, reportes adicionales y rutas de mutación necesitan su propia matriz de permisos, prueba positiva y prueba negativa.

**Última ejecución verde de este bloque:** [GitHub Actions #40382310210](https://github.com/oneapps-dev/asiscontrol/actions/runs/40382310210), commit `c67ad67540d2682a400e99a6b92dc942058ed370`.
El entorno es una base MySQL 8 aislada; no se probó una base de datos histórica restaurada en BanaHosting.

## Prioridades aún pendientes

1. **Cobertura de rutas heredadas**: matriz por endpoint (rol, permiso, tenant, sede, puesto, suscripción, evidencias, errores) y rehabilitación segura de las rutas provisionalmente cerradas. Inventario estático preliminar en `docs/security/route-authorization-review-2026-10-26.md` (118 pares método-ruta literales y 55 patrones dinámicos detectados; no equivalen a cobertura certificada).
2. **CA-01 a CA-23**: extraer los criterios exactos del requerimiento original, relacionar cada caso con prueba, resultado y evidencia.
3. **Offline E2E**: conflictos, reintentos concurrentes, colas legacy, aislamiento entre pestañas, conectividad intermitente y dispositivos.
4. **Paneles por perfil**: terminar los módulos y drilldowns de CREAMOX, Operador, PH, industria, supervisión, vigilancia, auditoría y residentes.
5. **Comercial**: verificar entitlements, límites concurrentes, retención y periodos pagados.
6. **Migración/entrega real**: clonar una base existente con seguridad, backup/restore, migraciones, E2E, carga y pruebas de despliegue cPanel.
7. Solo publicar después de aprobación explícita del usuario y checklist cerrado.

## Reglas de entrega

- No usar datos QA ni credenciales demo en producción.
- No convertir parámetros de React en autorización ni ampliar permisos de un actor.
- No sacrificar funciones heredadas; todo bloqueo temporal necesita reparación y regresión.
- Nunca exponer `backend/database`, `backend/tests`, `backend/scripts`, ficheros .env, copias ni tokens en el directorio web.
- No declarar AsisControl listo para producción por el solo hecho de que CI sea verde.
