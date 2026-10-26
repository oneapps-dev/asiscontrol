# Asiscontrol Backend

Backend PHP/MySQL para el MVP de Asiscontrol. Esta carpeta esta pensada para publicarse en cPanel junto con el build del frontend.

## Requisitos

- PHP 8.1+
- MySQL 8 o MariaDB compatible
- Extension PDO MySQL habilitada
- Extensiones GD (WebP), ZipArchive y SimpleXML habilitadas para evidencias e importación/exportación XLSX
- Apache con `mod_rewrite`

## Instalacion local o cPanel

1. Importar `backend/database/install.sql` para crear la base, tablas y datos demo en una sola pasada.
2. Copiar `backend/.env.example` como `backend/.env` (idealmente fuera del document root), completar las variables de base de datos y SMTP, y proteger el archivo con permisos 600. Los secretos no deben almacenarse en `.htaccess` ni en el frontend. Si el document root es `frontend/dist`, puedes indicar la ruta absoluta con `ASISCONTROL_ENV_FILE` o colocar el archivo en `dist/backend/.env` (el build nunca lo copia).
3. Si el document root es `frontend/dist`, conserva el `.htaccess` raíz incluido: reenvía `/api/*` directamente a `backend/api/index.php`. Si publicas `backend/public` como document root, usa la regla incluida en `backend/public/.htaccess`.
4. En frontend de produccion usar `VITE_API_REQUIRED=true` para evitar fallback demo si la API falla.
5. En produccion usar `APP_ENV=production` para ocultar detalles internos de errores.
6. En produccion usar `FORCE_HTTPS=1` y `SECURE_COOKIES=1`; en desarrollo local dejarlos desactivados si no usas HTTPS.

Para actualizar una instalacion existente sin recrear la base:

```bash
php /ruta/al/proyecto/backend/scripts/run_migrations.php
```

El runner crea `schema_migrations`, aplica los `.sql` de `backend/database/migrations` en orden y omite los que ya quedaron registrados.

Usuarios demo del seed:

- `admin@asiscontrol.local`
- `guardia@asiscontrol.local`
- Password: `demo1234`

## Endpoints MVP

- `GET /api/health`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/password/request`
- `POST /api/auth/password/reset`
- `GET /api/me`
- `GET /api/bootstrap`

`/api/health` verifica la conexión, las tablas mínimas (`tenants`, `users`, `login_attempts`) y sus columnas de autenticación. Responde `503` si PHP funciona pero la base de datos o el esquema no están disponibles; en ese caso devuelve `missing_tables`/`missing_columns` y un `request_id` para localizar el detalle en el log de PHP.
- `GET /api/sites`
- `POST /api/sites`
- `PATCH /api/sites/{id}`
- `GET /api/access-points`
- `POST /api/access-points`
- `PATCH /api/access-points/{id}`
- `GET /api/devices`
- `POST /api/devices`
- `POST /api/devices/{id}/revoke`
- `GET /api/users`
- `POST /api/users`
- `PATCH /api/users/{id}`
- `GET /api/permissions`
- `PUT /api/permissions/{role}`
- `GET /api/units`
- `POST /api/units`
- `GET /api/residents`
- `POST /api/residents`
- `POST /api/shifts`
- `POST /api/shifts/{id}/close`
- `GET /api/visits`
- `GET /api/people`
- `POST /api/people/{id}/archive`
- `POST /api/visits`
- `POST /api/visits/{id}/exit`
- `GET /api/vehicles`
- `POST /api/vehicles`
- `POST /api/vehicles/{id}/exit`
- `GET /api/parking?q=ABC`
- `POST /api/parking`
- `PATCH /api/parking/{id}`
- `GET /api/packages`
- `POST /api/packages`
- `POST /api/portal/auth/request`
- `POST /api/portal/auth/verify`
- `POST /api/portal/auth/logout`
- `GET /api/portal/me`
- `GET /api/portal/packages`
- `GET /api/portal/invitations`
- `POST /api/portal/invitations`
- `POST /api/packages/{id}/deliver`
- `POST /api/packages/{id}/reject`
- `POST /api/packages/{id}/return`
- `POST /api/packages/{id}/signature`
- `GET /api/invitations`
- `POST /api/invitations`
- `POST /api/invitations/{id}/cancel`
- `POST /api/invitations/redeem`
- `GET /api/invitations/{id}/print.html`
- `GET /api/approvals`
- `POST /api/approvals`
- `POST /api/approvals/{id}/approve`
- `POST /api/approvals/{id}/reject`

Reservas y calendario:

- `GET /api/booking-resources`
- `POST /api/booking-resources`
- `GET /api/bookings?from=YYYY-MM-DD&to=YYYY-MM-DD&resource_id=1`
- `POST /api/bookings`
- `POST /api/bookings/{id}/status`
- `DELETE /api/bookings/{id}`
- `GET /api/bookings/{id}/guests`
- `POST /api/bookings/{id}/guests`
- `POST /api/reservation-guests/{id}/check-in`
- `POST /api/reservation-guests/{id}/check-out`

Empleados y continuidad operativa:

- `GET|POST /api/employees`
- `PATCH /api/employees/{id}`
- `PUT /api/employees/{id}/schedule`
- `GET|POST /api/attendance`
- `POST /api/kiosk/attendance` — marcación rápida por código, documento o correo; admite cola offline.
- `GET|POST /api/contractors`
- `GET|POST /api/domestic-staff`
- `GET|POST /api/corrections` (solo superadmin)
- `POST /api/visits/{id}/admin-exit`
- `POST /api/vehicles/{id}/admin-exit`

Operación offline:

- `GET /api/access-events?from=YYYY-MM-DD&to=YYYY-MM-DD` — consulta eventos para auditoría.
- `POST /api/access-events` — registra un evento online con idempotencia.
- `POST /api/offline/events` — recibe eventos con `event_uuid` e `idempotency_key`; repetir la misma clave es seguro.
- `GET /api/offline/conflicts`
- `POST /api/offline/conflicts/{id}/resolve`
- `GET /api/offline/status`

Backups:

- `GET /api/backups`
- `GET /api/backups/policy`
- `PUT /api/backups/policy` — configura frecuencia, retención, destino y cifrado para usuarios autorizados.
- `POST /api/backups` — crea un job en cola; el cron de `scripts/backup_database.php` genera el archivo comprimido y debe ejecutarse con la frecuencia aprobada.
- `GET /api/backups/restores`
- `POST /api/backups/{id}/restore/request` y `POST /api/backups/{id}/restore/approve` — flujo de doble confirmación.

Las invitaciones se crean con token QR, se pueden cancelar y se redimen contra un turno activo para crear una visita.
El portal de residentes usa token temporal por correo y solo muestra paquetes/invitaciones asociados a la unidad autenticada.
El frontend renderiza QR real y usa `BarcodeDetector` para escaneo con camara cuando el navegador lo soporta.
La firma de paquetes recibe una imagen PNG en `signature_data_url` y guarda evidencia en `backend/storage/signatures`.
- `GET /api/incidents`
- `POST /api/incidents`
- `POST /api/incidents/{id}/close`
- `GET /api/restrictions`
- `POST /api/restrictions`
- `GET /api/audit`
- `GET /api/audit.csv?from=YYYY-MM-DD&to=YYYY-MM-DD&action=visita`
- `GET /api/audit.html?from=YYYY-MM-DD&to=YYYY-MM-DD&action=visita`
- `GET /api/jobs/imports`
- `GET /api/jobs/exports`
- `GET /api/notifications`
- `GET /api/reports/summary`
- `GET /api/reports/visits.csv?from=YYYY-MM-DD&to=YYYY-MM-DD&status=inside`
- `GET /api/reports/visits.xlsx?from=YYYY-MM-DD&to=YYYY-MM-DD&status=inside`
- `GET /api/reports/visits.html?from=YYYY-MM-DD&to=YYYY-MM-DD&status=inside`
- `GET /api/reports/attendance.csv?from=YYYY-MM-DD&to=YYYY-MM-DD`
- `GET /api/reports/attendance.xlsx?from=YYYY-MM-DD&to=YYYY-MM-DD`
- `GET /api/reports/attendance.html?from=YYYY-MM-DD&to=YYYY-MM-DD` — vista imprimible/guardar PDF desde el navegador.
- `POST /api/imports`
- `GET /api/imports/templates/{restrictions|people|users}.csv`
- `GET /api/imports/templates/{restrictions|people|users}.xlsx`
- `GET /api/files?owner_type=incident&owner_id=1`
- `POST /api/files`
- `GET /api/files/{id}/download`
- `GET /api/dashboard`
- `GET /api/shifts` y `POST /api/shifts/{id}/force-close` — supervisión global de turnos para administradores.
- `POST /api/visits/admin-exit-employees` — salida administrativa global de empleados con motivo obligatorio.
- `POST /api/portal/bookings` — pre-reserva de espacios desde la sesión unificada del residente.

Panel del proveedor (solo usuarios registrados en `platform_admins` o `superadmin`):

- `GET /api/platform/me`
- `GET /api/platform/plans`
- `GET /api/platform/metrics`
- `GET /api/platform/notifications`
- `GET /api/platform/audit`
- `GET /api/platform/tenants`
- `GET /api/platform/tenants/{id}/metrics`
- `POST /api/platform/tenants` — crea tenant, sede, administrador, suscripción inicial y token de activación de 48 horas.
- `POST /api/platform/tenants/{id}/status` — activa o suspende un cliente.
- `POST /api/platform/tenants/{id}/subscription` — actualiza plan/periodo sin acortar periodos pagados.
- `POST /api/platform/account/setup` — público; canjea el token de activación por una contraseña inicial.

Las peticiones mutables, excepto login, requieren el header `X-CSRF-Token` devuelto por `POST /api/auth/login` o `GET /api/bootstrap`.
Los endpoints administrativos de usuarios y sedes requieren rol `admin` o `superadmin`.
Las importaciones CSV y XLSX aceptan `job_type` con valores `restrictions`, `people` o `users`.
Toda imagen subida como adjunto o firma se convierte a WebP antes de guardarse. Los PDF se guardan sin conversion.
Los tokens de dispositivo se muestran una sola vez al autorizarlo; en la base de datos solo se guarda su hash.
El esquema incluye puntos de acceso, dispositivos, alcances de usuario, cualificaciones, eventos de acceso, conflictos offline, invitados de reservas, jobs/políticas de backup, control plane de clientes y operación de empleados/contratistas. Las migraciones `2026_09_08_007` a `2026_09_08_013` y `2026_09_12_014` mantienen instalaciones existentes al día. La 014 incorpora los roles residente/operador técnico, auditoría de inicio de sesión, parqueadero temporal y destinatarios estructurados de paquetes.
El login bloquea temporalmente email/IP despues de 5 fallos en 15 minutos.
El frontend incluye cola offline local para visitas, salidas, vehiculos, paquetes, incidentes y marcaciones de asistencia cuando la API falla. La cola se sincroniza automaticamente al recuperar la conexion y puede revisarse desde el indicador de pendientes en la barra superior.

## Smoke test

Con un servidor PHP apuntando a la API:

```bash
BASE_URL=http://127.0.0.1:8000/api bash backend/scripts/smoke_test.sh
```

## Diagnóstico de login en producción

Antes de probar el formulario, abre `https://TU_DOMINIO/api/health`.

- Respuesta esperada: `{"ok":true,"database":"ok","schema":"ok"}`.
- `database: unavailable`: PHP no está recibiendo `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` o la cuenta no tiene acceso a MySQL. Verifica también que el valor vacío de `DB_PASS` use comillas rectas (`""`), no comillas tipográficas.
- `schema: incompleto`: importa `backend/database/install.sql` en una base nueva, o ejecuta `php backend/scripts/run_migrations.php` en una instalación existente. La respuesta lista `missing_tables` o `missing_columns`.
- Si `/api/health` está correcto y el login aún devuelve `Error interno`, copia el `request_id` de la respuesta y búscalo en el log de errores PHP del hosting. No actives `APP_DEBUG=1` en producción salvo durante una prueba breve y controlada.

Después de corregir el servidor, vuelve a construir y publica todo el contenido de `frontend/dist`; así el frontend y el endpoint PHP quedan en la misma versión.

## Notificaciones

Las solicitudes de recuperacion de contrasena crean registros en `notifications`. Tambien quedan en cola notificaciones operativas para administradores/supervisores cuando se recibe un paquete o se crea una invitacion QR.

Para enviarlas por SMTP desde cPanel, programar un cron:

```bash
php /ruta/al/proyecto/backend/scripts/process_notifications.php 20
```

El numero final indica cuantas notificaciones procesar por ejecucion. Si un envio falla, el procesador reintenta con espera progresiva y marca la notificacion como `failed` despues de 5 intentos.

## Retencion de datos

Para revisar visitantes/contratistas salidos hace mas de 365 dias:

```bash
php /ruta/al/proyecto/backend/scripts/anonymize_old_visits.php 365
```

Para aplicar anonimizacion:

```bash
php /ruta/al/proyecto/backend/scripts/anonymize_old_visits.php 365 --apply
```

## Backups

Para generar un backup comprimido de MySQL:

```bash
php /ruta/al/proyecto/backend/scripts/backup_database.php
```

Programar este comando en cron una vez al mes. El script conserva automáticamente la ventana de retención configurada (por defecto 365 días) y elimina únicamente archivos de backup que superen ese plazo. Si `BACKUP_ENCRYPTION_REQUIRED=1`, también cifra el archivo con OpenSSL y exige `BACKUP_ENCRYPTION_KEY` desde `.env`; el valor no se almacena en la base de datos.

Para vencer reservas pendientes de pago, programar también:

```bash
php /ruta/al/proyecto/backend/scripts/expire_bookings.php
```

## Seguridad pendiente antes de produccion

- Confirmar que el hosting sirve todo por HTTPS antes de dejar `FORCE_HTTPS=1`.
- Revisar CSP si se agregan CDNs, mapas o servicios externos.
- La exportación PDF se ofrece como vista HTML imprimible (el navegador permite guardar como PDF); Dompdf puede añadirse si se necesita generación de archivos sin navegador.
- Revisar retencion, consentimiento y tratamiento de datos personales segun ley aplicable.
- Pendientes externos: homologar el modelo/firmware comprado, completar los datos legales de Creamox SAS y definir la pasarela de pagos. La política de geocerca (150 m, modo `review`), backups mensuales/365 días y notificaciones por email ya están implementadas.
