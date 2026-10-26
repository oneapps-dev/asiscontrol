# Estado de implementación — Asiscontrol

Actualizado: 2026-09-08

## Completado en esta revisión

- Fuente de verdad consolidada para producto, roles, permisos, seguridad, offline, biometría y entidades.
- Migración `2026_09_08_007_platform_contract_hardening.sql` con empresas de seguridad, puntos de acceso, dispositivos, alcances, cualificaciones, eventos de acceso, conflictos offline, invitados de reservas y backups.
- Migración `2026_09_08_008_permission_alignment.sql` para retirar reportes/auditoría de `admin`, retirar aprobaciones de `supervisor` y bloquear gestión de reservas para `guard`.
- Migraciones `2026_09_08_009_record_versioning.sql` y `2026_09_08_010_platform_control_plane.sql` para conservar versiones archivadas y administrar clientes, planes, suscripciones, métricas y activaciones.
- Migraciones `2026_09_08_011_backup_and_sync_policy.sql` y `2026_09_08_012_supervisor_device_visibility.sql` para backups mensuales/365 días, decisión offline `latest_wins` y acceso de supervisores a dispositivos.
- Instalación única alineada en `backend/database/install.sql`, `schema.sql` y `seed.sql` para la base configurada en `vzwquesv_app`.
- Endpoints PHP para puntos de acceso, dispositivos, revocación, eventos online, conflictos offline y jobs de backup.
- Tokens de dispositivo hasheados y visibles una sola vez; adjuntos de imagen y firmas convertidos a WebP.
- API cliente React con métodos para los endpoints nuevos y panel de configuración para puntos/dispositivos.
- Navegación por rol: reportes/auditoría no se ofrecen a `admin`; aprobaciones solo a `admin`/`superadmin`.
- Paleta CSS canónica Asiscontrol e interfaz en español con temas claro/oscuro.
- Build verificado: `npm run lint` y `npm run build`; `frontend/dist` contiene frontend, backend y documentación publicada.
- El empaquetado de `dist` excluye `.env`, backups, uploads, firmas y almacenamiento operativo; solo se publica `.env.example` como plantilla.
- Variables protegidas centralizadas en `backend/.env` mediante cargador seguro; `.htaccess` ya no contiene credenciales.
- Módulo React **Clientes** conectado a endpoints `/platform/*`, con alta de tenant, token de activación, suspensión y actualización de suscripción.
- Superpanel con métricas SaaS, historial de notificaciones por cliente y auditoría independiente del proveedor.
- Decisiones del Product Owner incorporadas: Creamox SAS, backups mensuales con 365 días, `latest_wins` offline con anotación automática, email como único canal inicial y adaptadores SecuGen/Regula/WebAuthn definidos.
- Geocerca configurable por punto con radio inicial de 150 m, IP/CIDR opcional y modo `review`/`block` controlado por entorno.
- Contratos de adaptador para SecuGen WebAPI (agente local Windows) y Unity 20 Bluetooth (wrapper nativo móvil) en `backend/integrations`.
- Gestión de empleados y personal doméstico, horarios semanales, asistencia con estados de puntualidad, soportes múltiples WebP y reportes CSV/Excel/imprimibles.
- Gestión de contratistas con ARL/certificaciones y solicitud de aprobación cuando el alta no la realiza un administrador.
- Salidas normales y administrativas de personas/vehículos, con motivo obligatorio, evidencia múltiple y conservación auditable.
- Estados de paquetes rechazado/devuelto y formularios de entrega con observaciones, firma y fotografías.
- Revisión/cierre de incidentes, invitados QR por reserva y control de check-in/check-out.
- Correcciones administrativas con valor anterior/nuevo, motivo y registro inmutable; restauración de backups con doble confirmación.
- Cola offline con reintentos desde el primer corte de conexión, límite de 10.000 eventos, indicador visible de conexión y resolución `latest_wins`/manual.
- Diseño responsive alineado con Stitch: sidebar de 260 px en escritorio, menú hamburguesa en tablet/celular, tablas convertidas en tarjetas y temas claro/oscuro.
- Importación de restricciones, personas y usuarios desde CSV o XLSX, con plantillas descargables y reporte de filas inválidas.

## Pendiente de decisión o integración

La implementación funcional queda lista para continuar, pero estas decisiones requieren una definición externa:

1. Modelo exacto y firmware que se comprará para homologación física.
2. Límite aprobado de concurrencia global de una empresa de seguridad multicliente.
3. Proveedor de pagos y conciliación de depósitos/reservas.
4. Correo, domicilio y jurisdicción de Creamox SAS para publicar los textos legales.

Estas preguntas también están en `ASISCONTROL_SOURCE_OF_TRUTH.md` junto con los contratos técnicos que ya quedaron definidos. No bloquean la operación actual; solo impiden activar integraciones físicas, pagos o publicar los datos legales definitivos.
