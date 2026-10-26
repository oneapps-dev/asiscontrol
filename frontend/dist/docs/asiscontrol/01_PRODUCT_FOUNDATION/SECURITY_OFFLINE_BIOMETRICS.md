# AsisControl — Seguridad, offline y biometría

## Dispositivo autorizado

Cada dispositivo pertenece a un `tenant`, sede y punto de acceso. El token de autorización se mantiene en cookie HttpOnly, Secure y SameSite, y su hash se guarda en el servidor. El servidor resuelve el tenant y punto; el cliente nunca puede escoger ese contexto en el payload.

La IP, User-Agent y estado de red se leen en el servidor. El fingerprint del navegador se guarda como señal de riesgo y cambio de dispositivo, no como autenticación única. Revocar un dispositivo bloquea nuevas operaciones inmediatamente.

## Operación offline

La cola local debe conservar eventos durante meses y soportar al menos 10.000 pendientes. Cada evento incluye:

- `event_uuid` e `idempotency_key`.
- Acción y payload original.
- Hora local del dispositivo y hora de recepción del servidor.
- Identificador de dispositivo, sede, punto y operador.
- Estado `PENDIENTE`, `SINCRONIZADO`, `FALLIDO` o `REQUIERE_REVISION`.

La sincronización es automática. Para eventos de acceso sobre la misma persona y acción, prevalece el `occurred_at` más reciente. El evento anterior nunca se borra: se almacena en `offline_conflicts` con decisión `latest_wins`, evento remoto relacionado y una anotación generada por el sistema. Las colisiones que no puedan correlacionarse pasan a `manual_review`.

Si se detecta corrupción de almacenamiento local, desviación relevante del reloj o cambio de navegador, el puesto entra en modo preventivo y exige reautorización/soporte.

## Biometría

La huella se representa como plantilla cifrada, nunca como imagen. La plantilla pertenece al tenant y a la persona. Se homologará SecuGen WebAPI mediante agente local para puestos Windows y SecuGen Unity 20 Bluetooth mediante wrapper nativo para Android/iOS; la integración HID DigitalPersona queda como alternativa empresarial. Regula se usará para lectura documental y WebAuthn para autenticación de operadores.

La contingencia aprobada es: huella, lector de cédula, registro manual con fotografía y motivo. Si la cámara es obligatoria y falla, la operación debe quedar marcada como contingencia y seguir la política que apruebe el Product Owner.

## Evidencias

Las imágenes de cámara, fotos de paquetes, firmas y documentos se almacenan fuera de las tablas principales. El servidor valida MIME real, tamaño, extensión segura y calcula hash SHA-256. Las imágenes se convierten a WebP antes de persistirlas. Una vez guardadas, las evidencias no se editan ni se eliminan desde operación.

## Controles obligatorios

- HTTPS en producción.
- Sesiones rotadas después del login y cookies HttpOnly.
- CSRF en toda escritura.
- Rate limiting para login, tokens y endpoints de sincronización.
- Reautenticación para permisos, dispositivos, backups, restauraciones y correcciones.
- Auditoría de cada acción crítica con IP, usuario, entidad y metadata.

## Contexto geográfico y de red

Cada punto de acceso inicia con una geocerca configurable de 150 metros y una lista opcional de redes/IP en formato CIDR. El modo inicial es `review`: registra el evento con estado `review` y anotación automática cuando el contexto no coincide. Un administrador puede seleccionar `block` cuando haya proxy/red confiable y pruebas de GPS suficientes.
