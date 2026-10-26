# Cierre QA Asiscontrol — estado de implementación

Los hallazgos definidos en la revisión del 08-09-2026 quedaron implementados en frontend, API y base de datos: login unificado con recuperación y bloqueo por intentos, roles y permisos, sesión por inactividad, auditoría, turnos y cierres forzados, navegación por rol, registro/consulta de personas con versionado, destinos estructurados, invitaciones QR, salidas con evidencias, empleados/horarios/asistencia, parqueadero asociado a vehículos, paquetes y servicios con destinatario estructurado, reservas con calendario y pre-reserva de residentes, sincronización offline y eliminación de los módulos independientes de parqueaderos y fases.

La compilación publica el backend y las migraciones dentro de `frontend/dist/backend`.

## Decisiones pendientes

La revisión QA del 08-09-2026 fue incorporada en la aplicación. Solo queda una definición funcional antes de cerrar el módulo de kiosco:

1. ¿El kiosco será una estación de autoservicio para empleados, una pantalla asistida por el vigilante o ambas?
2. ¿Qué dispositivo se instalará (tableta, computador con cámara, lector de código, lector biométrico) y qué sistema operativo tendrá?
3. ¿El kiosco debe operar sin conexión y sincronizar entradas/salidas, o solo consultar y registrar en línea?
4. ¿Qué roles pueden habilitarlo, bloquearlo y consultar sus registros?

Mientras se responde, el kiosco conserva un flujo no destructivo de marcación manual/código y no activa integraciones biométricas definitivas.

Otros proveedores externos (pasarela de pagos, hardware biométrico y proveedor de correo) siguen desacoplados y pueden configurarse posteriormente mediante variables de entorno.
