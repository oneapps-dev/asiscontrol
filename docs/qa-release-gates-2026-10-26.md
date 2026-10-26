# AsisControl — puertas de liberación end-to-end

Fecha: 2026-10-26. Rama: `main`. Estado: **NO APTO PARA PRODUCCIÓN**.

Este registro distingue software incorporado de fases cerradas. Una suite CI verde solo demuestra las rutas cubiertas en una base MySQL 8 inicializada desde cero.

| Fase | Implementación existente | Evidencia faltante para cerrar |
|---|---|---|
| 0 Seguridad | Autorización global y endurecimiento incremental de rutas | Matriz exhaustiva de rutas heredadas con casos permitidos/denegados |
| 1 Clientes | Tipos, jerarquía y operadores | Delegaciones concurrentes, revocaciones y límites históricos |
| 2 Permisos | Roles, alcances y membresías intercliente | Cobertura positiva/negativa por recurso, sede y puesto |
| 3 Planes | Cuotas y entitlements básicos | Concurrencia, continuidad de suscripciones y retención |
| 4 Sectores | Módulos y estructura industrial/residencial | E2E de operaciones sectoriales y regresión funcional |
| 5 Dashboards | KPIs contextuales desde SQL | Interfaces completas por perfil, navegación y pruebas de datos |
| 6 Contextos | Cambio de sede/cliente y vista de soporte de solo lectura | E2E navegador, expiración y varias pestañas |
| 7 Auditoría | Registro estructurado y filtros | Cobertura de mutaciones, exportación y retención |
| 8 Usuarios | Administración y contexto restringido | Formularios finales, cambios de rol, alcance y revocación |
| 9 Entrega | CI PHP/React/MySQL y smoke tests | CA-01 a CA-23, clon histórico, restauración, carga, despliegue y aprobación |

## Condiciones objetivas de cierre

1. Vincular cada criterio CA-01 a CA-23 con su caso ejecutable y evidencia. No declarar conformidad por inferencia.
2. Probar cada ruta pública y cada ruta autenticada con permiso permitido, permiso denegado, tenant ajeno, sede ajena y contexto revocado donde aplique.
3. Probar dispositivos y cola offline con conectividad intermitente, reintentos y sesiones simultáneas.
4. Construir el paquete cPanel y verificar que `.env`, datos QA, scripts, SQL, copias y archivos internos no estén expuestos.
5. Restaurar una copia **anonimizada** de una instalación histórica y probar migraciones incrementales con respaldo y rollback.
6. Verificar contra hosting de ensayo: login, API, permisos, flujos por rol, carga y recuperación.
7. Obtener autorización explícita de despliegue después de la evidencia de aceptación.

## Incidencia de CI del cierre forzado

Las ejecuciones de `7fa6eb9` y `0a60aadb` fallaron por una prueba positiva que ejecutaba `/shifts/{id}/force-close` con un supervisor sin el permiso `shifts.monitor`. La ruta sí exige ese permiso. El commit `824173d` corrige la prueba para usar una cuenta administradora con permiso explícito y contexto de sede seleccionado; mantiene los casos negativos. Resultado de CI del nuevo commit pendiente de confirmación en el momento de registrar este archivo.

## Integridad de entrega

No ejecutar `backend/database/install.sql` contra bases existentes; no publicar `frontend/dist/Archive.zip` ni los archivos del backend de desarrollo; no marcar las diez fases como completas sin evidencias verificables.
