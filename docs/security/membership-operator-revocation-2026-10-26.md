# AsisControl — revocación de membresías por estado del operador

Fecha: 2026-10-26. Rama: `main`.

## Problema
Una relación `tenant_relationships` marcada como activa no debe conservar autoridad si la propia empresa operadora de seguridad pasa a estado suspendido o comercialmente inactivo, o deja de tener el tipo `security_company`.

## Corrección
En `backend/api/tenant_memberships.php`, las dos consultas que calculan `active_relation` verifican también los estados operativo/comercial y el tipo de cliente del operador. Estas consultas alimentan `member_target_valid` en cada solicitud y `member_allowed_targets` al ofrecer contextos seleccionables.

## Regresión
Se añadió un caso de suspensión del operador en `backend/tests/multi_tenant_membership_smoke.sh`. Es una comprobación de integración de rechazo a nivel de sesión; no prueba aisladamente cada condición SQL de revalidación. Sigue pendiente ampliar la matriz positiva/negativa por operación, escenario de delegación y cambio de estados concurrente.

## Liberación
El workflow de CI sobre MySQL nuevo no acredita restauración desde una base histórica. No desplegar hasta completar criterios CA-01 a CA-23 y pruebas de migración.
