# AsisControl — Inventario estático de rutas para revisión de autorizaciones

Fecha: 2026-10-26. Fuente: archivos PHP en `main`; generado por búsqueda de expresiones de ruta en el código.

**Estado: REVISIÓN PENDIENTE.** Este inventario es un apoyo de la Fase 9; **no** demuestra cobertura total, seguridad ni ausencia de vulnerabilidades. No incluye todas las rutas anidadas, alias, condiciones `in_array`, cláusulas compuestas ni las definidas sin comparación literal; las expresiones regex enumeradas requieren auditoría manual.

## Controles obligatorios para CADA endpoint

1. Autenticación y revalidación de estado de usuario/suscripción, incluido contexto delegado activo.
2. Permiso efectivo basado en rol maestro y restricciones descendentes, nunca exclusivamente UI.
3. Tenant de todos los IDs consultados/modificados y relación comercial si aplica.
4. Alcance de sede, puesto, unidad o persona, incluida la selección temporal.
5. Protección CSRF, idempotencia, validación de entrada y tratamiento de errores.
6. Propiedad y descarga de archivos adjuntos, recursos de importación, exportación y backups.
7. Auditoría de cambios sin registrar contraseñas, tokens ni datos sensibles innecesarios.
8. Al menos una prueba de usuario permitido y otra de usuario ajeno por rol/tenant/sede.

## Grupos de mayor riesgo funcional pendientes de certificar

| Grupo | Rutas/operaciones a inspeccionar | Riesgo principal |
|---|---|---|
| Identidades | `/users`, `/permissions`, `/users/{id}/scopes`, cambios de rol | Escalamiento y concesiones fuera de tenant |
| Datos de personas | `/people`, empleados, contratistas, residentes | Información personal fuera del alcance |
| Evidencias | Adjuntos, firmas, fotos, videos, archivos de importación | IDOR, descargas directas y fugas entre clientes |
| Asistencia | `/attendance`, kiosk, turnos, correcciones | Eventos falsificados, mezcla de sedes y auditoría insuficiente |
| Exportación | Reportes CSV/XLSX, auditorías, backups | Extracción de datos multi-sede/multicliente |
| Operación | Visitas, vehículos, paquetes, reservaciones, incidentes | Acciones sobre objetos no autorizados |
| Offline | Eventos, conflictos, reintentos, cola legada | Reenvío cruzado, duplicados, reemplazos indebidos |
| SaaS | Clientes secundarios, suscripciones, límites, membresías | Relación comercial vencida, elevación o cuotas |

## Inventario derivado de código fuente (parcial)

### `backend/api/index.php`

Detectados: 102 pares método-ruta literales y 37 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

| Línea | Método/ruta literal | Estado de revisión |
|---:|---|---|
| 46 | `GET /health` | Pendiente de auditoría individual |
| 139 | `POST /auth/login` | Pendiente de auditoría individual |
| 225 | `POST /auth/password/request` | Pendiente de auditoría individual |
| 232 | `POST /auth/password/reset` | Pendiente de auditoría individual |
| 239 | `POST /portal/auth/request` | Pendiente de auditoría individual |
| 251 | `POST /portal/auth/verify` | Pendiente de auditoría individual |
| 260 | `POST /portal/auth/login` | Pendiente de auditoría individual |
| 284 | `POST /portal/auth/logout` | Pendiente de auditoría individual |
| 289 | `GET /portal/me` | Pendiente de auditoría individual |
| 293 | `POST /auth/logout` | Pendiente de auditoría individual |
| 298 | `GET /me` | Pendiente de auditoría individual |
| 305 | `POST /platform/account/setup` | Pendiente de auditoría individual |
| 335 | `GET /shifts` | Pendiente de auditoría individual |
| 355 | `POST /shifts` | Pendiente de auditoría individual |
| 383 | `GET /visits` | Pendiente de auditoría individual |
| 396 | `GET /people` | Pendiente de auditoría individual |
| 433 | `POST /visits` | Pendiente de auditoría individual |
| 485 | `GET /bootstrap` | Pendiente de auditoría individual |
| 535 | `GET /sites` | Pendiente de auditoría individual |
| 539 | `POST /sites` | Pendiente de auditoría individual |
| 578 | `GET /access-points` | Pendiente de auditoría individual |
| 583 | `POST /access-points` | Pendiente de auditoría individual |
| 648 | `GET /devices` | Pendiente de auditoría individual |
| 653 | `POST /devices` | Pendiente de auditoría individual |
| 705 | `GET /users` | Pendiente de auditoría individual |
| 716 | `POST /users` | Pendiente de auditoría individual |
| 803 | `GET /restrictions` | Pendiente de auditoría individual |
| 808 | `POST /restrictions` | Pendiente de auditoría individual |
| 830 | `GET /vehicles` | Pendiente de auditoría individual |
| 834 | `GET /parking` | Pendiente de auditoría individual |
| 848 | `POST /parking` | Pendiente de auditoría individual |
| 889 | `GET /booking-resources` | Pendiente de auditoría individual |
| 893 | `POST /booking-resources` | Pendiente de auditoría individual |
| 920 | `GET /bookings` | Pendiente de auditoría individual |
| 929 | `POST /bookings` | Pendiente de auditoría individual |
| 1026 | `POST /vehicles` | Pendiente de auditoría individual |
| 1078 | `GET /permissions` | Pendiente de auditoría individual |
| 1093 | `GET /employees` | Pendiente de auditoría individual |
| 1108 | `POST /employees` | Pendiente de auditoría individual |
| 1181 | `GET /attendance` | Pendiente de auditoría individual |
| 1191 | `POST /kiosk/attendance` | Pendiente de auditoría individual |
| 1232 | `POST /attendance` | Pendiente de auditoría individual |
| 1255 | `GET /contractors` | Pendiente de auditoría individual |
| 1260 | `POST /contractors` | Pendiente de auditoría individual |
| 1279 | `GET /domestic-staff` | Pendiente de auditoría individual |
| 1284 | `POST /domestic-staff` | Pendiente de auditoría individual |
| 1294 | `GET /corrections` | Pendiente de auditoría individual |
| 1299 | `POST /corrections` | Pendiente de auditoría individual |
| 1328 | `POST /visits/admin-exit-employees` | Pendiente de auditoría individual |
| 1358 | `GET /units` | Pendiente de auditoría individual |
| 1362 | `POST /units` | Pendiente de auditoría individual |
| 1386 | `GET /residents` | Pendiente de auditoría individual |
| 1402 | `POST /residents` | Pendiente de auditoría individual |
| 1439 | `GET /packages` | Pendiente de auditoría individual |
| 1443 | `GET /portal/packages` | Pendiente de auditoría individual |
| 1448 | `GET /portal/invitations` | Pendiente de auditoría individual |
| 1464 | `GET /portal/bookings` | Pendiente de auditoría individual |
| 1469 | `POST /portal/bookings` | Pendiente de auditoría individual |
| 1498 | `GET /portal/activity` | Pendiente de auditoría individual |
| 1504 | `POST /portal/invitations` | Pendiente de auditoría individual |
| 1528 | `POST /packages` | Pendiente de auditoría individual |
| 1630 | `GET /invitations` | Pendiente de auditoría individual |
| 1634 | `GET /approvals` | Pendiente de auditoría individual |
| 1638 | `POST /approvals` | Pendiente de auditoría individual |
| 1675 | `POST /invitations` | Pendiente de auditoría individual |
| 1720 | `POST /invitations/redeem` | Pendiente de auditoría individual |
| 1770 | `GET /incidents` | Pendiente de auditoría individual |
| 1774 | `POST /incidents` | Pendiente de auditoría individual |
| 1817 | `GET /audit` | Pendiente de auditoría individual |
| 1823 | `GET /audit.csv` | Pendiente de auditoría individual |
| 1844 | `GET /audit.html` | Pendiente de auditoría individual |
| 1853 | `GET /jobs/imports` | Pendiente de auditoría individual |
| 1865 | `GET /jobs/exports` | Pendiente de auditoría individual |
| 1877 | `GET /notifications` | Pendiente de auditoría individual |
| 1889 | `GET /reports/summary` | Pendiente de auditoría individual |
| 1894 | `GET /reports/visits.csv` | Pendiente de auditoría individual |
| 1920 | `GET /reports/visits.xlsx` | Pendiente de auditoría individual |
| 1932 | `GET /reports/visits.html` | Pendiente de auditoría individual |
| 1941 | `POST /imports` | Pendiente de auditoría individual |
| 1968 | `GET /files` | Pendiente de auditoría individual |
| 1981 | `POST /files` | Pendiente de auditoría individual |
| 1995 | `GET /access-events` | Pendiente de auditoría individual |
| 2020 | `POST /access-events` | Pendiente de auditoría individual |
| 2093 | `POST /offline/events` | Pendiente de auditoría individual |
| 2143 | `GET /offline/conflicts` | Pendiente de auditoría individual |
| 2175 | `GET /backups` | Pendiente de auditoría individual |
| 2186 | `POST /backups` | Pendiente de auditoría individual |
| 2235 | `GET /backups/restores` | Pendiente de auditoría individual |
| 2240 | `GET /backups/policy` | Pendiente de auditoría individual |
| 2246 | `PUT /backups/policy` | Pendiente de auditoría individual |
| 2281 | `GET /offline/status` | Pendiente de auditoría individual |
| 2289 | `GET /reports/attendance.csv` | Pendiente de auditoría individual |
| 2299 | `GET /reports/attendance.xlsx` | Pendiente de auditoría individual |
| 2311 | `GET /platform/me` | Pendiente de auditoría individual |
| 2316 | `GET /platform/plans` | Pendiente de auditoría individual |
| 2321 | `GET /platform/metrics` | Pendiente de auditoría individual |
| 2336 | `GET /platform/notifications` | Pendiente de auditoría individual |
| 2345 | `GET /platform/audit` | Pendiente de auditoría individual |
| 2354 | `GET /platform/tenants` | Pendiente de auditoría individual |
| 2393 | `POST /platform/tenants` | Pendiente de auditoría individual |
| 2487 | `GET /dashboard` | Pendiente de auditoría individual |
| 2736 | `GET /reports/attendance.html` | Pendiente de auditoría individual |

| Línea | Patrón dinámico | Estado de revisión |
|---:|---|---|
| 345 | `#^/shifts/(\d+)/force-close$#` | Requiere verificar métodos y recursos |
| 375 | `#^/shifts/(\d+)/close$#` | Requiere verificar métodos y recursos |
| 411 | `#^/people/(\d+)/archive$#` | Requiere verificar métodos y recursos |
| 466 | `#^/visits/(\d+)/exit$#` | Requiere verificar métodos y recursos |
| 560 | `#^/sites/(\d+)$#` | Requiere verificar métodos y recursos |
| 619 | `#^/access-points/(\d+)$#` | Requiere verificar métodos y recursos |
| 695 | `#^/devices/(\d+)/revoke$#` | Requiere verificar métodos y recursos |
| 751 | `#^/users/(\d+)$#` | Requiere verificar métodos y recursos |
| 868 | `#^/parking/(\d+)$#` | Requiere verificar métodos y recursos |
| 962 | `#^/bookings/(\d+)/status$#` | Requiere verificar métodos y recursos |
| 990 | `#^/bookings/(\d+)/guests$#` | Requiere verificar métodos y recursos |
| 1009 | `#^/reservation-guests/(\d+)/(check-in\|check-out)$#` | Requiere verificar métodos y recursos |
| 1018 | `#^/bookings/(\d+)$#` | Requiere verificar métodos y recursos |
| 1059 | `#^/vehicles/(\d+)/exit$#` | Requiere verificar métodos y recursos |
| 1083 | `#^/permissions/(superadmin\|admin\|supervisor\|coordinator\|guard\|auditor\|resident\|tech_operator)$#` | Requiere verificar métodos y recursos |
| 1142 | `#^/employees/(\d+)$#` | Requiere verificar métodos y recursos |
| 1167 | `#^/employees/(\d+)/schedule$#` | Requiere verificar métodos y recursos |
| 1319 | `#^/visits/(\d+)/admin-exit$#` | Requiere verificar métodos y recursos |
| 1349 | `#^/vehicles/(\d+)/admin-exit$#` | Requiere verificar métodos y recursos |
| 1390 | `#^/residents/(\d+)/password$#` | Requiere verificar métodos y recursos |
| 1579 | `#^/packages/(\d+)/deliver$#` | Requiere verificar métodos y recursos |
| 1601 | `#^/packages/(\d+)/(reject\|return)$#` | Requiere verificar métodos y recursos |
| 1614 | `#^/packages/(\d+)/signature$#` | Requiere verificar métodos y recursos |
| 1657 | `#^/approvals/(\d+)/(approve\|reject)$#` | Requiere verificar métodos y recursos |
| 1708 | `#^/invitations/(\d+)/cancel$#` | Requiere verificar métodos y recursos |
| 1716 | `#^/invitations/(\d+)/print.html$#` | Requiere verificar métodos y recursos |
| 1798 | `#^/incidents/(\d+)/review$#` | Requiere verificar métodos y recursos |
| 1806 | `#^/incidents/(\d+)/close$#` | Requiere verificar métodos y recursos |
| 1958 | `#^/imports/templates/(restrictions\|people\|users)\.csv$#` | Requiere verificar métodos y recursos |
| 1963 | `#^/imports/templates/(restrictions\|people\|users)\.xlsx$#` | Requiere verificar métodos y recursos |
| 1976 | `#^/files/(\d+)/download$#` | Requiere verificar métodos y recursos |
| 2151 | `#^/offline/conflicts/(\d+)/resolve$#` | Requiere verificar métodos y recursos |
| 2209 | `#^/backups/(\d+)/restore/request$#` | Requiere verificar métodos y recursos |
| 2224 | `#^/backups/(\d+)/restore/approve$#` | Requiere verificar métodos y recursos |
| 2374 | `#^/platform/tenants/(\d+)/metrics$#` | Requiere verificar métodos y recursos |
| 2453 | `#^/platform/tenants/(\d+)/status$#` | Requiere verificar métodos y recursos |
| 2467 | `#^/platform/tenants/(\d+)/subscription$#` | Requiere verificar métodos y recursos |

### `backend/api/multitenant.php`

Detectados: 5 pares método-ruta literales y 9 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

| Línea | Método/ruta literal | Estado de revisión |
|---:|---|---|
| 112 | `GET /me/contexts` | Pendiente de auditoría individual |
| 117 | `POST /me/context/select` | Pendiente de auditoría individual |
| 138 | `POST /me/context/clear` | Pendiente de auditoría individual |
| 168 | `GET /roles/master` | Pendiente de auditoría individual |
| 177 | `GET /platform/client-types` | Pendiente de auditoría individual |

| Línea | Patrón dinámico | Estado de revisión |
|---:|---|---|
| 182 | `#^/users/(\d+)/scopes$#` | Requiere verificar métodos y recursos |
| 233 | `#^/users/(\d+)/role-change$#` | Requiere verificar métodos y recursos |
| 270 | `#^/tenants/(\d+)/role-restrictions/(admin\|supervisor\|coordinator\|guard\|auditor\|resident\|tech_operator)$#` | Requiere verificar métodos y recursos |
| 577 | `#^/platform/tenants/[0-9]+/(?:memberships(?:/[0-9]+)?\|membership-options)$#` | Requiere verificar métodos y recursos |
| 580 | `#^/platform/tenants/[0-9]+/children$#` | Requiere verificar métodos y recursos |
| 596 | `#^/shifts/[0-9]+/close$#` | Requiere verificar métodos y recursos |
| 597 | `#^/(visits\|vehicles)/[0-9]+/exit$#` | Requiere verificar métodos y recursos |
| 598 | `#^/packages/[0-9]+/(deliver\|reject\|return\|signature)$#` | Requiere verificar métodos y recursos |
| 599 | `#^/incidents/[0-9]+/(review\|close)$#` | Requiere verificar métodos y recursos |

### `backend/api/tenant_hierarchy.php`

Detectados: 0 pares método-ruta literales y 1 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

| Línea | Patrón dinámico | Estado de revisión |
|---:|---|---|
| 16 | `#^/platform/tenants/(\d+)/children$#` | Requiere verificar métodos y recursos |

### `backend/api/platform_support.php`

Detectados: 4 pares método-ruta literales y 1 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

| Línea | Método/ruta literal | Estado de revisión |
|---:|---|---|
| 39 | `POST /platform/impersonation/stop` | Pendiente de auditoría individual |
| 123 | `POST /platform/impersonation/start` | Pendiente de auditoría individual |
| 184 | `GET /platform/impersonation/context` | Pendiente de auditoría individual |
| 213 | `GET /platform/impersonation/audit` | Pendiente de auditoría individual |

| Línea | Patrón dinámico | Estado de revisión |
|---:|---|---|
| 100 | `#^/platform/tenants/([0-9]+)/preview-users$#` | Requiere verificar métodos y recursos |

### `backend/api/organization.php`

Detectados: 4 pares método-ruta literales y 2 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

| Línea | Método/ruta literal | Estado de revisión |
|---:|---|---|
| 84 | `GET /organization/nodes` | Pendiente de auditoría individual |
| 101 | `POST /organization/nodes` | Pendiente de auditoría individual |
| 189 | `GET /organization/people` | Pendiente de auditoría individual |
| 204 | `POST /organization/people` | Pendiente de auditoría individual |

| Línea | Patrón dinámico | Estado de revisión |
|---:|---|---|
| 134 | `#^/organization/nodes/([0-9]+)$#` | Requiere verificar métodos y recursos |
| 236 | `#^/organization/people/([0-9]+)$#` | Requiere verificar métodos y recursos |

### `backend/api/tenant_features.php`

Detectados: 0 pares método-ruta literales y 1 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

| Línea | Patrón dinámico | Estado de revisión |
|---:|---|---|
| 31 | `#^/platform/tenants/([0-9]+)/features$#` | Requiere verificar métodos y recursos |

### `backend/api/tenant_memberships.php`

Detectados: 3 pares método-ruta literales y 2 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

| Línea | Método/ruta literal | Estado de revisión |
|---:|---|---|
| 197 | `GET /me/tenants` | Pendiente de auditoría individual |
| 213 | `POST /me/tenant/clear` | Pendiente de auditoría individual |
| 224 | `POST /me/tenant/select` | Pendiente de auditoría individual |

| Línea | Patrón dinámico | Estado de revisión |
|---:|---|---|
| 259 | `#^/platform/tenants/(\d+)/membership-options$#` | Requiere verificar métodos y recursos |
| 263 | `#^/platform/tenants/(\d+)/memberships(?:/(\d+))?$#` | Requiere verificar métodos y recursos |

### `backend/api/audit_structured.php`

Detectados: 0 pares método-ruta literales y 0 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

### `backend/api/plan_limits.php`

Detectados: 0 pares método-ruta literales y 2 expresiones de ruta dinámicas. Los números indican líneas aproximadas del archivo analizado.

| Línea | Patrón dinámico | Estado de revisión |
|---:|---|---|
| 77 | `#^/platform/tenants/(\d+)/limits$#` | Requiere verificar métodos y recursos |
| 89 | `#^/platform/plans/(\d+)/limits$#` | Requiere verificar métodos y recursos |

## Resumen del inventario parcial

- 118 pares método-ruta literales y 55 expresiones dinámicas encontrados en 9 archivos (con posible duplicación entre ramas y helpers).
- Los tests existentes demuestran **casos específicos**, no cobertura integral: `multitenant_security_smoke.sh`, `platform_preview_smoke.sh`, `client_type_gate_smoke.sh`, `multi_tenant_membership_smoke.sh`, `offline_context_smoke.sh`, entre otros.
- La siguiente tarea es cruzar cada fila con permiso concreto, control de propiedad y sede, test negativo, test positivo y código de retorno esperado. La ruta marcada «pendiente» no debe calificarse como validada.
- Mantener el estado **EN DESARROLLO — NO DESPLEGAR** hasta recuperar las funcionalidades legítimas bloqueadas y cerrar la matriz CA-01 a CA-23.


## Subconjunto revisado en integración — 26 de octubre de 2026

Las siguientes rutas ahora se prueban con clientes/sedes permitidos y ajenos; el listado general anterior se mantiene pendiente de auditoría para todas las demás rutas.

| Rutas | Verificación de backend | Prueba |
|---|---|---|
| GET/POST /files | files.manage, propietario permitido y existente, propiedad de tenant y sede | evidence_owner_scope_smoke.sh |
| GET /files/{id}/download | Revalidación de propiedad antes de entregar bytes, nunca solo por ID del archivo | evidence_owner_scope_smoke.sh |
| GET /reports/visits.csv, .xlsx, .html | reports.view y COALESCE entre sede de visita y sede del turno antes de LIMIT | site_scoped_reports_smoke.sh |
| GET /attendance | attendance.view, tenant de empleado y perfil, filtro de sede antes de LIMIT | site_scoped_reports_smoke.sh |
| GET /reports/attendance.csv, .xlsx, .html | Igual alcance de asistencia con validaciones de CSV, XML de XLSX y HTML | site_scoped_reports_smoke.sh |
| GET /reports/summary | Totales de visitas, vehículos, paquetes e incidentes restringidos en SQL | site_scoped_reports_smoke.sh |

El módulo de adjuntos admite solo los tipos de propietario shift_start, visit, visit_exit, vehicle, vehicle_exit, package, package_delivery, incident y booking. Los tipos desconocidos, padres de otros clientes y registros sin sede autorizada se rechazan.

No se autorizó crear nuevas capacidades de acceso para aprobar los tests: la matriz actual de roles se conserva. La primera ejecución verde de este subconjunto fue [GitHub Actions #40378349135](https://github.com/oneapps-dev/asiscontrol/actions/runs/40378349135).

Pendiente: recursos no listados, descargas desde importaciones, eventos offline con concurrencia, modificaciones de personal, integridad de backups, la matriz CA-01 a CA-23, pruebas con navegador real, restauración y migración de una base existente.
