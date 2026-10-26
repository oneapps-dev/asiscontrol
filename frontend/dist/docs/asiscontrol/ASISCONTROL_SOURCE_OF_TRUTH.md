# AsisControl — Fuente de verdad consolidada

Versión: 1.0  
Fecha de consolidación: 2026-09-08  
Estado: aprobada para implementación por fases

Este documento resuelve las contradicciones detectadas entre los documentos de descubrimiento, los prototipos Stitch y el código actual. Los documentos históricos siguen siendo útiles como referencia, pero no deben introducir reglas nuevas.

## Precedencia documental

1. Este documento y las decisiones aprobadas por el Product Owner.
2. `PERMISSIONS_CATALOG_APPROVED_v1.0.docx` para permisos.
3. `SHARED_CONTRACTS_DECISIONS_v0.2.docx` para estados, auditoría, evidencias y reportes.
4. `DATABASE_DECISIONS_v0.2.docx` para reglas de datos.
5. `FRONTEND_DECISIONS_v0.2.docx` para experiencias responsive.
6. `ARCHITECTURE_OVERVIEW_PRELIMINARY.docx`, `API_DECISIONS_v0.2.docx` y `BACKEND_QUESTIONS_FOR_PROGRAMMER_v0.2.docx` para decisiones técnicas todavía abiertas.
7. Los documentos Stitch y el demo anterior son referencias visuales/históricas, no contratos funcionales.

## Decisiones consolidadas

- El frontend productivo es React + Vite; el backend es PHP 8.1+ sobre MySQL/MariaDB compatible.
- La aplicación soporta PC, tablet y celular. Las pantallas Stitch de escritorio son referencias de composición, no un límite responsive.
- Los roles técnicos son `superadmin`, `admin`, `supervisor` (Operador de Medios Tecnológicos), `guard` y `auditor`. Residente y empresa contratista son identidades de negocio/portal, no roles administrativos globales.
- Reportes y auditoría quedan disponibles para `superadmin`, `supervisor` y `auditor` según alcance. `admin` gestiona la operación de su cliente, pero no exporta auditoría ni reportes técnicos.
- El sistema operativo usa `/api/...` con sesión HttpOnly y CSRF. JWT queda reservado para una futura API externa y no debe mezclarse con la sesión interna.
- La reserva usa códigos internos compatibles (`pending`, `confirmed`, `paid`, `cancelled`) y etiquetas oficiales en español (`PRE_RESERVA`, `PENDIENTE_PAGO`, `CONFIRMADA`, `CANCELADA`, `VENCIDA`). La API debe publicar ambos cuando sea necesario para transición.
- Las evidencias se cargan como archivos binarios. Las imágenes se convierten a WebP; los PDF se conservan como PDF. Una evidencia guardada es inmutable.
- La huella biométrica y el fingerprint del navegador son datos distintos. FingerprintJS solo es una señal adicional de dispositivo; nunca es el único factor de autorización.
- Nunca se envían contraseñas por correo. El alta inicial debe usar un enlace de configuración de un solo uso con vencimiento.
- El bootstrap de Superadmin debe ejecutarse mediante comando/instalador protegido y obligar a rotar la credencial inicial.
- Geocercas, redes/IP permitidas y excepciones se configuran por sede/punto de acceso; solo usuarios con permiso de dispositivos pueden modificarlas y todo cambio queda auditado.
- Los registros de personas no se destruyen operativamente. Un registro archivado/eliminado conserva su evidencia; un nuevo ingreso con el mismo documento crea una nueva versión enlazada a la anterior.
- La sincronización offline aplica política `latest_wins` por `occurred_at` para eventos correlacionables; conserva ambos payloads y genera una anotación automática. Los casos sin correlación quedan en revisión manual.
- Pagos y pasarelas quedan desacoplados para una fase posterior; reservas y planes funcionan con estados de pago manuales mientras no se defina proveedor.
- Backups se pueden solicitar manualmente o programar; los jobs conservan alcance, checksum, estado y auditoría.
- Offline usa una cola local con UUID/idempotencia, reintentos y revisión de conflictos estilo merge; la interfaz siempre muestra el estado online/offline.
- La solución objetivo es la herramienta funcional completa documentada. Solo integraciones externas de hardware, OCR/biometría, pagos o canales de mensajería quedan como adaptadores posteriores.
- Los términos de suscripción mantienen los periodos pagados y no contemplan devoluciones ni reembolsos.
- Se publican términos de uso y privacidad que delimitan responsabilidades, sin prometer seguridad absoluta; las obligaciones legales inderogables y los incidentes se gestionan mediante controles, auditoría y notificación.
- Toda variable sensible se lee desde `.env`/variables de entorno; no se debe commitear una credencial real.
- Se requiere un plano de administración del proveedor para crear clientes, consultar métricas multi-tenant, suspender cuentas y operar soporte con auditoría separada.
- Responsable contractual definido: **Creamox SAS**. Los textos finales de jurisdicción, contacto y autorizaciones deben completar la revisión legal.
- Política operativa definida: backup mensual con retención de 365 días; notificaciones exclusivamente por email hasta contratar un proveedor.
- Geocerca inicial: radio configurable por punto, 150 m por defecto; redes/IP permitidas opcionales. La política inicial es `review` (anota y envía a revisión); puede cambiarse a `block` por entorno autorizado.
- Los datos personales son de sus titulares (residentes, visitantes, empleados, contratistas y operadores). El cliente que define finalidades es el responsable del tratamiento; Asiscontrol opera como encargado/procesador, salvo pacto legal distinto.

## Tokens visuales canónicos

```css
--color-primary: #1E3A5F;
--color-primary-mid: #2D6A9F;
--color-accent: #4A90D9;
--color-bg-light: #F8FBFF;
--color-bg-card: #EBF4FF;
--color-white: #FFFFFF;
--color-success: #27AE60;
--color-warning: #F39C12;
--color-error: #E74C3C;
--color-text: #1C2B3A;
--color-text-muted: #7F8C9B;
--color-border: #D6E4F0;
--font-family: Inter, sans-serif;
```

`DESIGN.md`, los prototipos antiguos y los activos de marca deben migrarse a estos valores. No se utilizarán `#0047C1`, `#155EEF`, `#022448` ni Poppins como tokens del producto.

## Contrato mínimo de seguridad y operación

- Todo evento de acceso debe tener `event_uuid` e `idempotency_key` únicos.
- Los eventos offline conservan hora local, origen, dispositivo y hora de recepción del servidor.
- Un conflicto nunca se elimina automáticamente: si es correlacionable aplica `latest_wins`, conserva ambos payloads y genera anotación; si no, pasa a `REQUIERE_REVISION`.
- Un dispositivo se autoriza para un cliente, sede y punto de acceso concretos; la autorización puede revocarse.
- La geolocalización, IP/red y fingerprint son validaciones complementarias. El servidor resuelve siempre el contexto del dispositivo.
- Los registros de acceso, auditoría, evidencias y decisiones de aprobación no tienen borrado operativo.
- Las descargas, backups, restauraciones, correcciones y cambios de permisos generan auditoría.

## Criterios de aceptación técnicos

- Marcación online: máximo 6 segundos incluyendo periféricos.
- Marcación local/offline: máximo 3 segundos.
- Capacidad: 2.500 movimientos diarios por sede y al menos 10.000 eventos pendientes por dispositivo.
- Navegadores oficiales del puesto: Chrome y Edge. Paneles: Chrome, Edge, Safari y Firefox.
- Ambientes separados: desarrollo, pruebas y producción.

## Preguntas que no se pueden resolver solo con los documentos

1. ¿Qué modelo exacto (familia y firmware) de SecuGen/Regula se comprará para homologación física?
2. ¿Cuál será el límite aprobado de concurrencia global de una empresa de seguridad multicliente?
3. ¿Qué proveedor/canal de pagos se elegirá cuando se active la integración?
4. ¿Qué correo/domicilio/jurisdicción de Creamox SAS deben publicarse en los textos legales definitivos?
