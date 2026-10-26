# AsisControl — Alcance de producto

## MVP operativo

- Autenticación, sesiones, CSRF y control por rol/alcance.
- Dashboard operativo y toma/entrega de turno.
- Personas, visitantes, vehículos, paquetes, incidentes y evidencias.
- Restricciones, aprobaciones y auditoría operativa.
- Residentes, unidades y portal de consulta por token.
- Reservas de zonas sociales/salas con calendario, conflicto de horarios, pago confirmado, cancelación y reglas del recurso.
- QR para eventos y validación en portería.
- Cola offline, sincronización idempotente y reporte de conflictos.
- Exportaciones CSV/HTML y jobs de importación/exportación.
- Conversión de fotografías y firmas a WebP.

## Implementado en la base actual

- Portal de residentes con acceso por enlace o contraseña, consultas de paquetes/reservas y creación de invitaciones.
- Invitaciones QR con vencimiento, impresión y redención en portería.
- Cola offline con reintentos, idempotencia, conflictos y estado de conexión visible.
- Gestión documental de contratistas, cualificaciones ARL/cursos y flujo de aprobación.
- Gestión de empleados, horarios, asistencia, personal doméstico, soportes múltiples y reportes imprimibles/CSV/XLSX.
- Correcciones administrativas y salidas administrativas conservando auditoría.
- Backups manuales/programables, retención de 365 días y restauración con doble confirmación.
- Calendario de reservas de zonas/salas, invitados QR, estados de pago manual y cancelación.

## Implementado en la base actual (continuación)

- Consola de proveedor para clientes, licencias, planes, métricas, activación y suspensión con auditoría.
- Kiosco web para registrar asistencia, con cola offline y contrato listo para conectar periféricos homologados.
- Configuración por sede/punto de geocerca, redes permitidas, dispositivos y permisos.
- Diseño Stitch responsive: barra lateral en escritorio, menú hamburguesa en tablet/móvil, tarjetas para tablas y tema claro/oscuro.

## Integraciones externas posteriores

- Integración con el proveedor de pagos que defina el cliente.
- Homologación física del hardware seleccionado y conexión del kiosco a periféricos.
- Política de funcionalidades por sector y configuración de acciones rápidas específicas.

## Futuro

- Reconocimiento facial.
- Lectura automática de placas.
- Autorización digital de visitantes por residente.
- Integraciones de pago y notificaciones multicanal.

## Fuera de alcance actual

- App móvil nativa.
- Vídeo como evidencia en el MVP.
- Restricción propia por placa.
- Autoaprobación de documentos por contratistas.
- Edición o eliminación de evidencias guardadas desde la operación.
