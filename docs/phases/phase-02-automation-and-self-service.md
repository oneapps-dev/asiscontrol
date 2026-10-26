# Fase 2 - Automatización y autoservicio

Estado: implementada para los flujos que no dependen de proveedores externos.

Objetivo: reducir friccion operativa y habilitar flujos previos al ingreso.

## Alcance

- Invitaciones QR con vencimiento.
- Portal de residentes, empleados o anfitriones.
- Aprobaciones y notificaciones.
- Importacion y exportacion XLSX.
- Firma de entrega de paquetes.
- Analitica avanzada.
- Integracion externa de WhatsApp.
- Cola offline para porteria.
- Gestion documental de contratistas.
- Asignacion de parqueaderos.
- Integracion de reconocimiento de placas.
- Almacenamiento externo de objetos.
- Preparacion multi-idioma.

Implementado en esta entrega: portal de residentes, invitaciones QR, notificaciones por email en cola, firma y evidencias WebP, importación/exportación CSV/XLSX, cola offline con resolución de conflictos, contratistas/cualificaciones, reservas con invitados QR, personal doméstico y reportes de asistencia. WhatsApp, pagos, OCR/placas y almacenamiento externo quedan como adaptadores posteriores.

## Entregables esperados

- Tablas para invitaciones, aprobaciones, firmas, documentos y cola offline.
- Modulo frontend de portal externo.
- Workers o tareas programadas compatibles con hosting disponible.
- Adaptadores de proveedores externos con contratos claros.
- Pruebas de sincronizacion y resolucion de conflictos.

## Riesgos

- WhatsApp y notificaciones dependen de proveedores y aprobaciones externas.
- La cola offline requiere pruebas serias de concurrencia.
- Reconocimiento de placas debe evaluarse con camaras reales del sitio.
