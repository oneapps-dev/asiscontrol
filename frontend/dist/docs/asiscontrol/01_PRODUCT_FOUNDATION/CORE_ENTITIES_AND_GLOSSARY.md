# AsisControl — Entidades y glosario canónico

| Concepto | Definición | Persistencia principal |
|---|---|---|
| Empresa de seguridad | Organización que administra varios clientes | `security_companies` |
| Cliente / tenant | Ámbito aislado de datos y configuración | `tenants` |
| Sede | Ubicación física del cliente | `sites` |
| Punto de acceso | Entrada/salida concreta de una sede | `access_points` |
| Dispositivo | Equipo autorizado para operar un punto | `devices` |
| Usuario | Cuenta con rol y permisos | `users`, `user_scopes` |
| Persona | Identidad física controlada | `people` |
| Calidad | Relación simultánea de una persona con el cliente | `person_qualifications` |
| Empleado | Perfil laboral y horario de una persona | `employee_profiles` |
| Evento de acceso | Entrada, salida o salida administrativa | `access_events` |
| Visita | Autorización/estancia de visitante | `visits` |
| Restricción | Regla informativa, de revisión o bloqueo | `restrictions` |
| Aprobación | Solicitud que requiere decisión administrativa | `approvals` |
| Evidencia | Foto o PDF asociado a una entidad | `file_attachments` |
| Reserva | Uso temporal de un recurso | `bookings` |
| Recurso reservable | Zona social, sala o espacio configurado | `booking_resources` |
| Invitado de evento | Persona incluida en una reserva/evento con QR | `reservation_guests`, `invitations` |
| Conflicto offline | Colisión entre eventos local y remoto | `offline_conflicts` |
| Auditoría | Registro inmutable de acciones relevantes | `audit_logs` |
| Backup | Copia técnica u operativa auditada | `backup_jobs` |

## Estados de interfaz

La base puede conservar códigos internos en inglés para compatibilidad histórica, pero la interfaz siempre muestra español. Ejemplos: `pending` → `PENDIENTE`, `confirmed` → `CONFIRMADA`, `cancelled` → `CANCELADA`, `inside` → `DENTRO`, `exited` → `FUERA`.

