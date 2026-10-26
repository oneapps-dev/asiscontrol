# Autorización de cierre forzado de turnos

Fecha: 2026-10-26.

La ruta `POST /shifts/{id}/force-close` ahora valida la sede del turno persistido con `validated_shift_site` antes de actualizar el registro. Esto exige que la sede esté dentro del alcance efectivo del actor, incluido el contexto de sede seleccionado.

## Validación pendiente

- Caso negativo: supervisor limitado a sede A intenta cerrar un turno de sede B; se espera rechazo HTTP 403 y ausencia de mutación.
- Caso positivo: el mismo supervisor cierra un turno abierto de sede A; se espera éxito y fecha de cierre.
- Verificar además revocación de asignaciones entre clientes y cierre de sesión.

Esta corrección no certifica los demás endpoints ni autoriza despliegue en producción.

## Recuperación funcional de ruta limitada por sede

Se identificó que `enforce_legacy_scope_gate` rechazaba `POST /shifts/{id}/force-close` para operadores limitados por sede incluso cuando la acción era legítima. El commit `a79d5c0` incorpora esa ruta al conjunto de rutas admitidas. Se mantiene `require_permission('shifts.monitor')` y la comprobación del turno persistido mediante `validated_shift_site`. El commit `7fa6eb9` agrega una prueba de cierre forzado permitido para un supervisor de la sede autorizada, además del rechazo de sede ajena. No considerar certificado hasta que CI finalice satisfactoriamente.
