# Investigación de dispositivos — Asiscontrol

Fecha: 2026-09-08  
Estado: arquitectura seleccionada; requiere homologación física del modelo/firmware antes de compra.

## Conclusión ejecutiva

Una aplicación React en navegador no puede asumir acceso universal a lectores biométricos USB/Bluetooth. La arquitectura seleccionada usa un **agente local** autorizado por Asiscontrol para puestos fijos y una **app contenedora nativa/React Native** para celulares/tablets con lector externo; la PWA queda para cámara, QR, WebAuthn y operación offline.

## Opciones encontradas

| Opción | Escritorio/portátil | Android | iOS/iPadOS | Transporte | Encaje |
|---|---|---|---|---|---|
| SecuGen WebAPI + Hamster Air/IV/Plus/Pro | Sí, mediante cliente local Windows y JavaScript | No como PWA pura; SDK nativo | No como PWA pura; SDK nativo | USB; Unity 20 también Bluetooth | Buena para portería Windows fija |
| SecuGen Unity 20 Bluetooth | Sí, Bluetooth o USB | Sí mediante SDK del fabricante | Sí mediante SDK BLE del fabricante | Bluetooth/USB | Mejor opción portátil si se acepta wrapper nativo |
| HID DigitalPersona Web Client + 4500/5300 | Sí, requiere DigitalPersona Client/Workstation local; navegador evergreen | SDK Android separado | No se confirma flujo web puro | USB; SDK/local agent | Buena para Windows corporativo |
| Suprema BioMini | SDK/driver y puente local | Hay soporte Android por OTG en documentación del fabricante | Requiere validación específica | USB/OTG | Solo después de prueba con modelo exacto |
| ZKTeco | Depende del modelo y SDK/REST del equipo | Depende de SDK/app | Depende de SDK/app | Ethernet/Wi-Fi/USB según modelo | No comprar sin modelo, firmware y API confirmados |
| Cámara + OCR/document reader (Regula u otro) | Sí desde navegador moderno | Sí desde navegador/app | Sí desde navegador/app | Cámara; NFC/RFID solo con SDK móvil compatible | Recomendado para cédula/MRZ y captura de evidencia |
| WebAuthn/passkeys | Sí | Sí | Sí | Sensor biométrico interno, USB/NFC/BLE de autenticador | Recomendado para autenticar operadores, no para identificar visitantes |

## Qué sí y qué no se puede hacer desde React web

- **Sí:** cámara para foto/QR/OCR, WebAuthn/passkeys, formularios offline y sincronización, y lectores que se expongan como teclado HID.
- **Condicional:** Web Bluetooth exige HTTPS y permiso explícito del usuario; su disponibilidad real depende del navegador y del perfil BLE del dispositivo.
- **Condicional:** Web Serial es un borrador y requiere navegador compatible; no debe ser el único camino de producción.
- **No asumir:** leer directamente una huella cruda desde cualquier USB/Bluetooth en Safari, Firefox, Android o iOS sin driver, SDK, agente local o aplicación nativa.
- **No confundir:** WebAuthn usa el sensor local para desbloquear una credencial criptográfica; no entrega a la aplicación la huella del usuario.

## Arquitectura seleccionada

1. **Base universal:** React/PWA con cámara, QR, captura manual, WebAuthn y almacenamiento offline.
2. **Puesto fijo Windows:** agente local firmado (localhost/WebSocket) que encapsule SecuGen WebAPI o HID DigitalPersona. El agente se registra como `device` y solo puede operar en su `access_point`.
3. **Puesto móvil:** wrapper React Native/Capacitor para SDK de Unity 20 u otro lector homologado. La PWA se mantiene como fallback manual.
4. **Documento de identidad:** empezar con cámara/OCR; evaluar Regula Web Components para navegador y Regula Mobile SDK si se requiere NFC/RFID o verificación de autenticidad.
5. **Backend:** almacenar plantillas/identificadores derivados, no imágenes biométricas crudas salvo obligación legal expresa; todo evento conserva `event_uuid`, `idempotency_key`, dispositivo y auditoría.

## Prueba de homologación antes de compra

- Confirmar modelo, firmware, sistema operativo y licencia SDK.
- Probar captura, enrolamiento y matching 1:1/1:N con los navegadores objetivo.
- Probar desconexión, reconexión, duplicados, reloj incorrecto y cola offline.
- Medir latencia en portería y consumo de batería en móvil.
- Verificar que el proveedor entregue documentación, soporte y derecho de uso comercial.
- Validar tratamiento legal de biometría y documentos antes de activar identificación automática.

## Fuentes consultadas

- SecuGen WebAPI: <https://secugen.com/products/webapi/>
- SecuGen Unity 20 Bluetooth: <https://secugen.com/products/unity-20-bluetooth/>
- HID DigitalPersona Web Client API: <https://docs.hidglobal.com/digitalpersona-v4.2.0/ad/dev/web-client-api.htm>
- HID DigitalPersona Device Access overview: <https://hidglobal.github.io/digitalpersona-devices/>
- Regula Document Reader SDK: <https://docs.regulaforensics.com/develop/doc-reader-sdk/>
- Regula Web Components: <https://dev.regulaforensics.com/DocumentReaderSDK-WebComponents-Docs/index.html>
- W3C WebAuthn: <https://www.w3.org/TR/webauthn/all/>
- Web Serial (borrador): <https://wicg.github.io/serial/>
