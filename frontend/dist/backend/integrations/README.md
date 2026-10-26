# Adaptadores de dispositivos

La integración seleccionada mantiene dos caminos compatibles:

1. **Puesto fijo Windows:** `SecuGenWebApiAdapter` conversa con el agente local firmado de SecuGen WebAPI. La URL se configura únicamente mediante `SECUGEN_WEBAPI_URL` en `backend/.env`.
2. **Celular/tablet:** `NativeBluetoothAdapter` define el contrato que implementará el wrapper React Native/Capacitor para SecuGen Unity 20 Bluetooth. El SDK nativo devuelve un identificador/resultado derivado; Asiscontrol no recibe una imagen de huella cruda.

La PWA continúa funcionando con cámara, QR, WebAuthn y captura manual cuando no existe periférico. El modelo y firmware comprados deben pasar la homologación física antes de activar identificación automática.
