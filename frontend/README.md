# Asiscontrol frontend

Aplicación React + Vite para control de accesos, visitas, reservas, paquetes e incidentes. La interfaz está en español, es responsive (PC, tablet y móvil) y admite tema claro/oscuro. Incluye exportación CSV/Excel y plantillas de importación CSV/XLSX.

## Desarrollo

```bash
npm install
npm run dev
```

Variables opcionales:

- `VITE_API_BASE=/api` — prefijo de la API PHP.
- `VITE_API_REQUIRED=true` — evita el modo demo local cuando el backend no responde.

La cola offline se reintenta automáticamente al recuperar la red y cada 30 segundos mientras haya pendientes; también puede forzarse desde el indicador de la barra superior.

## Producción

```bash
cp .env.production.example .env.production
npm run build
```

El build ejecuta `scripts/copy-backend-to-dist.mjs` y copia únicamente el backend operativo dentro de `frontend/dist` para el paquete cPanel. La documentación de arquitectura y las semillas QA permanecen en GitHub y no deben publicarse en el directorio web. El build debe generarse desde el código fuente; la copia histórica de `dist` almacenada en el repositorio no es un paquete verificado. `VITE_API_REQUIRED=true` evita que una caída de la API se disfrace como modo demo local. La API queda disponible bajo `/api`; copia `backend/.env.example` como `backend/.env` en el servidor y completa allí MySQL/SMTP. No pongas secretos en `.htaccess` ni en el frontend.

Al subir el contenido de `dist`, conserva el archivo oculto `.htaccess` en la raíz del document root. Debe responder `GET /api/health` con JSON; si devuelve 404, el hosting no está cargando el rewrite o el archivo oculto no fue subido.

## Contrato funcional

La fuente de verdad, los roles, permisos, entidades, seguridad offline y preguntas pendientes están en:

- `../02 AsisControl/Para entregar a Programación/00_README/ASISCONTROL_SOURCE_OF_TRUTH.md`
- `../02 AsisControl/Para entregar a Programación/00_README/PRODUCT_SCOPE.md`
- `../02 AsisControl/Para entregar a Programación/01_PRODUCT_FOUNDATION/SECURITY_OFFLINE_BIOMETRICS.md`

En el artefacto publicado se incluye la carpeta `docs/asiscontrol` con el estado y decisiones de producto.

No se requieren claves Gemini ni servicios de IA para ejecutar Asiscontrol.
