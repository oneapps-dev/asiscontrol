# Autorización de cierre forzado de turnos

Fecha: 2026-10-26.

La ruta `POST /shifts/{id}/force-close` ahora valida la sede del turno persistido con `validated_shift_site` antes de actualizar el registro. Esto exige que la sede esté dentro del alcance efectivo del actor, incluido el contexto de sede seleccionado.

## Validación pendiente

- Caso negativo: supervisor limitado a sede A intenta cerrar un turno de sede B; se espera rechazo HTTP 403 y ausencia de mutación.
- Caso positivo: el mismo supervisor cierra un turno abierto de sede A; se espera éxito y fecha de cierre.
- Verificar además revocación de asignaciones entre clientes y cierre de sesión.

Esta corrección no certifica los demás endpoints ni autoriza despliegue en producción.
