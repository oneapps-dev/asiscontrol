# Revisión de consultas de empleados y asistencia — 2026-10-26

## Corrección implementada

La lectura `GET /employees` une ahora `people` con `employee_profiles` mediante `person_id` **y** `tenant_id`, y calcula `today_events` exigiendo igualmente el mismo `tenant_id` en `attendance_records`. Esto evita depender exclusivamente de la unicidad global de los identificadores y refuerza el contrato multicliente en el propio SQL.

## Estado

Código publicado en `main`, commit `eb7b3ead`. Este cambio no implica que todos los endpoints de asistencia estén auditados. Es necesario completar casos positivos y negativos de consulta, mutación, asistencia offline, pertenencia a sede y datos históricos.

## Criterio de liberación

Conservar el estado NO DESPLEGAR hasta superar CI y la totalidad de CA-01 a CA-23, regresión de flujos y restauración de base histórica.
