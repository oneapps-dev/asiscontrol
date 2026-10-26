**Documento Maestro — Sistema Web de Control de Asistencia (SaaS) "AsisControl"**

**Versión:** 2.0 Final  
**Stack:** PHP + MySQL + cPanel (Banahosting)  
**Modelo:** SaaS multiempresa con Superpanel, panel admin por cliente y kiosk por sede  
**Autor:** Creamox

\---

**1. Visión general del sistema**

Sistema web SaaS de control de asistencia para negocios pequeños y medianos en Colombia. Se vende como licencia anual. Tiene tres capas completamente separadas:

1. **Superpanel** — usado exclusivamente por el desarrollador/proveedor para gestionar clientes, planes, licencias y métricas del negocio SaaS.
2. **Panel Admin** — usado por el dueño o administrador del negocio cliente para gestionar empleados, sedes, asistencia y reportes.
3. **Kiosk** — pantalla en el PC del establecimiento donde los empleados registran entrada y salida.

\---

**2. Stack tecnológico**

|Componente|Tecnología|
|-|-|
|Servidor|cPanel — Banahosting (compartido)|
|Backend|PHP 8.x (puro con estructura MVC simple o Slim Framework)|
|Base de datos|MySQL 8.x|
|Frontend|HTML5 + CSS3 + JavaScript Vanilla|
|Gráficas|Chart.js (client-side)|
|Autenticación|Sessions PHP + JWT para API|
|Hash contraseñas|password\_hash() / password\_verify() con PASSWORD\_BCRYPT|
|Fingerprint dispositivo|FingerprintJS open source (sin periférico físico, solo navegador)|
|Exportar PDF|Dompdf (PHP)|
|Exportar Excel|PhpSpreadsheet (PHP)|
|Storage fotos|Carpeta /uploads/fotos/ en el mismo servidor|
|Correos|PHPMailer con SMTP|
|Deploy|FTP o Git desde cPanel|
|Cron Jobs|cPanel Cron Jobs|

\---

**3. Lo que NO se debe hacer**

* No intentar leer la dirección MAC desde el navegador (no disponible en aplicaciones web).
* No leer IP ni User-Agent desde el body del request — siempre desde $\_SERVER en PHP.
* No guardar el deviceToken en localStorage como fuente de verdad — solo en cookie HttpOnly.
* No usar DELETE real en empleados — siempre soft-delete con deleted\_at.
* No depender de la fecha del día para decidir entrada/salida — usar el último tipo de registro.
* No poner el superpanel en la misma ruta del panel admin del cliente.
* No hacer hard delete de ninguna empresa aunque esté eliminada — conservar datos históricos.
* No transmitir la foto como base64 — enviar como archivo binario via FormData y $\_FILES.

\---

**4. Modelo de datos completo**

**Tabla plans**

sql

id            **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

name          **VARCHAR**(80) NOT NULL

description   **TEXT** NULL

price\_cop     **DECIMAL**(12,2) NOT NULL

duration\_months **INT** NOT NULL **DEFAULT** 12

max\_employees **INT** NOT NULL **DEFAULT** 10

max\_locations **INT** NOT NULL **DEFAULT** 1

max\_devices   **INT** NOT NULL **DEFAULT** 1

features      JSON NULL

is\_active     **TINYINT**(1) **DEFAULT** 1

created\_at    **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

updated\_at    **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP** **ON** **UPDATE** **CURRENT\_TIMESTAMP**

**Tabla companies**

sql

id            **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

name          **VARCHAR**(120) NOT NULL

nit           **VARCHAR**(30) NULL

city          **VARCHAR**(80) NULL

country       **VARCHAR**(60) **DEFAULT** 'Colombia'

contact\_name  **VARCHAR**(120) NULL

contact\_email **VARCHAR**(120) NULL

contact\_phone **VARCHAR**(30) NULL

logo\_url      **VARCHAR**(500) NULL

timezone      **VARCHAR**(60) **DEFAULT** 'America/Bogota'

**status**        **ENUM**('active','suspended','cancelled') **DEFAULT** 'active'

trial         **TINYINT**(1) **DEFAULT** 0

trial\_ends\_at **TIMESTAMP** NULL

notes         **TEXT** NULL

deleted\_at    **TIMESTAMP** NULL

created\_at    **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

updated\_at    **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP** **ON** **UPDATE** **CURRENT\_TIMESTAMP**

**Tabla licenses**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

plan\_id         **INT** **UNSIGNED** NOT NULL

license\_key     **VARCHAR**(128) **UNIQUE** NOT NULL

**status**          **ENUM**('active','expired','suspended','trial') **DEFAULT** 'active'

activated\_at    **TIMESTAMP** NOT NULL

expires\_at      **TIMESTAMP** NOT NULL

grace\_days      **INT** **DEFAULT** 5

activated\_by    **INT** **UNSIGNED** NULL

price\_paid\_cop  **DECIMAL**(12,2) NULL

notes           **TEXT** NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

updated\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP** **ON** **UPDATE** **CURRENT\_TIMESTAMP**

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**FOREIGN** **KEY** (plan\_id) **REFERENCES** plans(id)

**Tabla payments**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

license\_id      **INT** **UNSIGNED** NULL

amount\_cop      **DECIMAL**(12,2) NOT NULL

payment\_date    **DATE** NOT NULL

payment\_method  **VARCHAR**(60) NULL

reference       **VARCHAR**(120) NULL

confirmed\_by    **INT** **UNSIGNED** NULL

notes           **TEXT** NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**Tabla locations (sedes)**

sql

id            **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id    **INT** **UNSIGNED** NOT NULL

name          **VARCHAR**(120) NOT NULL

address       **VARCHAR**(255) NULL

city          **VARCHAR**(80) NULL

is\_active     **TINYINT**(1) **DEFAULT** 1

created\_at    **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

updated\_at    **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP** **ON** **UPDATE** **CURRENT\_TIMESTAMP**

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**Tabla devices**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

location\_id     **INT** **UNSIGNED** NOT NULL

name            **VARCHAR**(120) NULL

device\_token    **VARCHAR**(128) **UNIQUE** NOT NULL

fingerprint     **VARCHAR**(255) NULL

ip\_address      **VARCHAR**(60) NULL

user\_agent      **TEXT** NULL

is\_active       **TINYINT**(1) **DEFAULT** 1

registered\_at   **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

last\_seen\_at    **TIMESTAMP** NULL

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**FOREIGN** **KEY** (location\_id) **REFERENCES** locations(id)

**Tabla employees**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

name            **VARCHAR**(120) NOT NULL

username        **VARCHAR**(60) NOT NULL

password\_hash   **VARCHAR**(255) NOT NULL

role            **ENUM**('employee','admin') **DEFAULT** 'employee'

schedule\_start  **TIME** NULL

schedule\_end    **TIME** NULL

tolerance\_mins  **INT** **DEFAULT** 10

is\_active       **TINYINT**(1) **DEFAULT** 1

deleted\_at      **TIMESTAMP** NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

updated\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP** **ON** **UPDATE** **CURRENT\_TIMESTAMP**

**UNIQUE** **KEY** unique\_username\_company (company\_id, username)

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**Tabla attendance\_records**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

employee\_id     **INT** **UNSIGNED** NOT NULL

device\_id       **INT** **UNSIGNED** NOT NULL

location\_id     **INT** **UNSIGNED** NOT NULL

**type**            **ENUM**('entrada','salida') NOT NULL

recorded\_at     **TIMESTAMP** NOT NULL

photo\_url       **VARCHAR**(500) NULL

photo\_status    **ENUM**('ok','unavailable') **DEFAULT** 'ok'

ip\_address      **VARCHAR**(60) NULL

fingerprint     **VARCHAR**(255) NULL

user\_agent      **TEXT** NULL

is\_manual       **TINYINT**(1) **DEFAULT** 0

notes           **TEXT** NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

**INDEX** idx\_employee\_date (employee\_id, recorded\_at)

**INDEX** idx\_company\_date (company\_id, recorded\_at)

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**FOREIGN** **KEY** (employee\_id) **REFERENCES** employees(id)

**FOREIGN** **KEY** (device\_id) **REFERENCES** devices(id)

**FOREIGN** **KEY** (location\_id) **REFERENCES** locations(id)

**Tabla attendance\_shifts (pares entrada/salida)**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

employee\_id     **INT** **UNSIGNED** NOT NULL

location\_id     **INT** **UNSIGNED** NOT NULL

entry\_record\_id **INT** **UNSIGNED** NOT NULL

exit\_record\_id  **INT** **UNSIGNED** NULL

shift\_date      **DATE** NOT NULL

entry\_time      **TIMESTAMP** NOT NULL

exit\_time       **TIMESTAMP** NULL

hours\_worked    **DECIMAL**(5,2) NULL

**status**          **ENUM**('open','closed','incomplete','late','on\_time') **DEFAULT** 'open'

late\_minutes    **INT** **DEFAULT** 0

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

updated\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP** **ON** **UPDATE** **CURRENT\_TIMESTAMP**

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**FOREIGN** **KEY** (employee\_id) **REFERENCES** employees(id)

**FOREIGN** **KEY** (entry\_record\_id) **REFERENCES** attendance\_records(id)

**FOREIGN** **KEY** (exit\_record\_id) **REFERENCES** attendance\_records(id)

**Tabla daily\_attendance\_summary**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

employee\_id     **INT** **UNSIGNED** NOT NULL

summary\_date    **DATE** NOT NULL

total\_hours     **DECIMAL**(5,2) **DEFAULT** 0

total\_shifts    **INT** **DEFAULT** 0

late\_count      **INT** **DEFAULT** 0

incomplete\_count **INT** **DEFAULT** 0

**status**          **ENUM**('present','absent','late','incomplete','partial') **DEFAULT** 'absent'

first\_entry     **TIMESTAMP** NULL

last\_exit       **TIMESTAMP** NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

updated\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP** **ON** **UPDATE** **CURRENT\_TIMESTAMP**

**UNIQUE** **KEY** unique\_employee\_date (employee\_id, summary\_date)

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**FOREIGN** **KEY** (employee\_id) **REFERENCES** employees(id)

**Tabla access\_attempts**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NULL

device\_token    **VARCHAR**(128) NULL

ip\_address      **VARCHAR**(60) NULL

fingerprint     **VARCHAR**(255) NULL

user\_agent      **TEXT** NULL

route           **VARCHAR**(255) NULL

**status**          **ENUM**('authorized','blocked','invalid\_license','invalid\_device') NOT NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

**Tabla super\_admins**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

name            **VARCHAR**(120) NOT NULL

email           **VARCHAR**(120) **UNIQUE** NOT NULL

password\_hash   **VARCHAR**(255) NOT NULL

role            **ENUM**('owner','support','billing') **DEFAULT** 'support'

is\_active       **TINYINT**(1) **DEFAULT** 1

last\_login\_at   **TIMESTAMP** NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

**Tabla superadmin\_audit\_log**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

super\_admin\_id  **INT** **UNSIGNED** NOT NULL

**action**          **VARCHAR**(120) NOT NULL

entity\_type     **VARCHAR**(60) NULL

entity\_id       **INT** **UNSIGNED** NULL

detail          **TEXT** NULL

ip\_address      **VARCHAR**(60) NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

**FOREIGN** **KEY** (super\_admin\_id) **REFERENCES** super\_admins(id)

**Tabla notifications\_log**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

**type**            **VARCHAR**(80) NOT NULL

subject         **VARCHAR**(255) NULL

sent\_to         **VARCHAR**(120) NULL

**status**          **ENUM**('sent','failed') **DEFAULT** 'sent'

sent\_at         **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**Tabla client\_notes**

sql

id              **INT** **UNSIGNED** **AUTO\_INCREMENT** **PRIMARY** **KEY**

company\_id      **INT** **UNSIGNED** NOT NULL

super\_admin\_id  **INT** **UNSIGNED** NOT NULL

note            **TEXT** NOT NULL

created\_at      **TIMESTAMP** **DEFAULT** **CURRENT\_TIMESTAMP**

**FOREIGN** **KEY** (company\_id) **REFERENCES** companies(id)

**FOREIGN** **KEY** (super\_admin\_id) **REFERENCES** super\_admins(id)

\---

**5. Estructura de rutas del sistema**

text

/superadmin/login.php

/superadmin/dashboard.php

/superadmin/companies.php

/superadmin/companies/create.php

/superadmin/companies/detail.php?id=

/superadmin/licenses.php

/superadmin/licenses/activate.php

/superadmin/plans.php

/superadmin/plans/create.php

/superadmin/payments.php

/superadmin/notifications.php

/superadmin/metrics.php

/superadmin/audit.php

/superadmin/admins.php

/admin/login.php

/admin/dashboard.php

/admin/employees.php

/admin/employees/create.php

/admin/employees/detail.php?id=

/admin/attendance.php

/admin/reports.php

/admin/locations.php

/admin/devices.php

/admin/settings.php

/admin/license.php

/kiosk/index.php

/kiosk/blocked.php

/api/device/verify.php

/api/device/register.php

/api/employee/checkin.php

/api/admin/login.php

/api/admin/logout.php

/api/employees/index.php

/api/employees/store.php

/api/employees/update.php

/api/employees/status.php

/api/attendance/index.php

/api/reports/summary.php

/api/reports/employee.php

/api/reports/export.php

/api/license/status.php

/api/superadmin/login.php

/api/superadmin/companies.php

/api/superadmin/licenses/activate.php

/api/superadmin/plans.php

/api/superadmin/payments.php

/api/superadmin/notify.php

/cron/nightly\_summary.php

/uploads/fotos/{company\_id}/{location\_id}/{employee\_id}/{timestamp}.jpg

\---

**6. Estructura de carpetas del proyecto PHP**

text

/

├── api/

│   ├── device/

│   │   ├── verify.php

│   │   └── register.php

│   ├── employee/

│   │   └── checkin.php

│   ├── admin/

│   │   ├── login.php

│   │   └── logout.php

│   ├── employees/

│   │   ├── index.php

│   │   ├── store.php

│   │   ├── update.php

│   │   └── status.php

│   ├── attendance/

│   │   └── index.php

│   ├── reports/

│   │   ├── summary.php

│   │   ├── employee.php

│   │   └── export.php

│   ├── license/

│   │   └── status.php

│   └── superadmin/

│       ├── login.php

│       ├── companies.php

│       ├── licenses/

│       │   └── activate.php

│       ├── plans.php

│       ├── payments.php

│       └── notify.php

├── admin/

│   ├── login.php

│   ├── dashboard.php

│   ├── employees.php

│   ├── employees/

│   │   ├── create.php

│   │   └── detail.php

│   ├── attendance.php

│   ├── reports.php

│   ├── locations.php

│   ├── devices.php

│   ├── settings.php

│   └── license.php

├── superadmin/

│   ├── login.php

│   ├── dashboard.php

│   ├── companies.php

│   ├── companies/

│   │   ├── create.php

│   │   └── detail.php

│   ├── licenses.php

│   ├── licenses/

│   │   └── activate.php

│   ├── plans.php

│   ├── plans/

│   │   └── create.php

│   ├── payments.php

│   ├── notifications.php

│   ├── metrics.php

│   ├── audit.php

│   └── admins.php

├── kiosk/

│   ├── index.php

│   └── blocked.php

├── cron/

│   └── nightly\_summary.php

├── includes/

│   ├── db.php

│   ├── auth.php

│   ├── license.php

│   ├── device.php

│   ├── fingerprint.php

│   ├── attendance.php

│   ├── metrics.php

│   ├── mailer.php

│   └── helpers.php

├── assets/

│   ├── css/

│   ├── js/

│   └── img/

├── uploads/

│   └── fotos/

└── vendor/

&#x20;   ├── dompdf/

&#x20;   ├── phpspreadsheet/

    └── phpmailer/





\---

**7. Seguridad del dispositivo kiosk**

**Mecanismo de restricción**

El objetivo es que el módulo kiosk solo funcione en el PC autorizado del establecimiento. No se usa dirección MAC porque los navegadores no la exponen. Se usa una combinación de tres capas:

1. **Cookie HttpOnly** con el deviceToken — generada al activar el equipo, no accesible desde JavaScript.
2. **FingerprintJS** — hash generado del navegador y hardware del equipo (GPU, canvas, fuentes, WebGL, resolución, audio). No requiere ningún periférico físico adicional.
3. **IP del establecimiento** — leída exclusivamente desde $\_SERVER\['REMOTE\_ADDR'] en PHP, nunca desde el body del request.

**Registro del dispositivo (primera vez)**

text

1\. Admin entra al PC del establecimiento

2\. Abre la URL del kiosk

3\. Sistema detecta ausencia de deviceToken en cookie

4\. Muestra pantalla de activación

5\. Admin ingresa credenciales y selecciona la sede

6\. PHP genera UUID v4 como deviceToken

7\. PHP guarda deviceToken en MySQL vinculado a company\_id + location\_id

8\. PHP escribe cookie: HttpOnly, Secure, SameSite=Strict, expires = 1 año

9\. PHP registra fingerprint e IP desde $\_SERVER

10\. Kiosk queda operativo

**Verificación en cada carga**

php

*// PHP lee cookie, nunca confía en el body*

$deviceToken = $\_COOKIE\['device\_token'] ?? null;

$ip = $\_SERVER\['REMOTE\_ADDR'];

$userAgent = $\_SERVER\['HTTP\_USER\_AGENT'];

*// JS envía solo el fingerprint hash (no IP, no token)*

$fingerprint = $\_POST\['fingerprint'] ?? null;

*// PHP consulta MySQL*

$device = getDeviceByToken($deviceToken);

**if** (!$device || !$device\['is\_active']) {

&#x20;   renderBlockedScreen('Dispositivo no autorizado');

&#x20;   logAccessAttempt('blocked');

    \\\*\\\*exit\\\*\\\*;





}

*// Resolver company\_id DESDE el dispositivo, nunca desde el cliente*

$companyId = $device\['company\_id'];

$locationId = $device\['location\_id'];

*// Validar licencia usando el company\_id resuelto*

$license = getActiveLicense($companyId);

**if** (!$license || strtotime($license\['expires\_at']) < time()) {

&#x20;   renderBlockedScreen('Licencia vencida');

&#x20;   \\\*\\\*exit\\\*\\\*;





}

\---

**8. Flujo completo de marcación de empleado**

**Paso 1 — Validación silenciosa del dispositivo**

PHP verifica deviceToken (cookie), fingerprint, licencia activa. Si falla cualquier punto, se muestra pantalla bloqueada y se registra el intento en access\_attempts.

**Paso 2 — Empleado digita usuario y contraseña**

Formulario en pantalla completa. Presiona "Registrar".

**Paso 3 — PHP resuelve contexto y valida en cadena**

text

1\. Leer deviceToken desde cookie → obtener company\_id y location\_id

2\. Validar licencia activa con grace\_days incluidos

3\. Buscar username DENTRO de ese company\_id (no en toda la tabla)

4\. Validar is\_active = 1 del empleado

5\. password\_verify($password, $employee\['password\_hash'])

Si cualquier paso falla, responder con código y mensaje específico. Nunca revelar si el usuario existe o no en errores de credenciales.

**Paso 4 — Ventana de protección anti-doble registro**

sql

**SELECT** **type**, recorded\_at **FROM** attendance\_records

**WHERE** employee\_id = ? AND company\_id = ?

**ORDER** **BY** recorded\_at **DESC**

**LIMIT** 1

Si el último registro fue entrada hace menos de 10 minutos:

* No crear ningún registro nuevo
* Responder: "Tu entrada ya fue registrada a las HH:MM"
* Reiniciar formulario

**Paso 5 — Captura automática de foto**

javascript

*// JS: capturar foto y enviar como archivo*

**const** stream = **await** navigator.mediaDevices.getUserMedia({ video: true });

**const** video = document.createElement('video');

video.srcObject = stream;

**await** video.play();

*// Countdown visual 3 segundos*

**await** countdown(3);

**const** canvas = document.createElement('canvas');

canvas.width = 640; canvas.height = 480;

canvas.getContext('2d').drawImage(video, 0, 0);

stream.getTracks().forEach(t => t.stop());

*// Convertir a Blob y enviar como FormData, NO como base64*

canvas.toBlob(**async** (blob) => {

&#x20;   \*\*const\*\* formData \\= \*\*new\*\* FormData();

&#x20;   formData.append('photo', blob, 'checkin.jpg');

    formData.append('fingerprint', fingerprintHash);

    \\\*// deviceToken NO se envía desde JS, PHP lo lee desde la cookie\\\*

    

    \\\*\\\*const\\\*\\\* response \\\\= \\\*\\\*await\\\*\\\* fetch('/api/employee/checkin.php', {

        method: 'POST',

        body: formData

    });





}, 'image/jpeg', 0.85);

**Paso 6 — PHP decide entrada o salida**

sql

**SELECT** **type** **FROM** attendance\_records

**WHERE** employee\_id = ? AND company\_id = ?

**ORDER** **BY** recorded\_at **DESC**

**LIMIT** 1

|Resultado|Acción|
|-|-|
|Sin registros o último tipo = salida|Registrar entrada|
|Último tipo = entrada|Registrar salida|

No hay límite de turnos por día. El sistema alterna infinitamente.

**Paso 7 — Guardado en orden seguro**

text

1\. PHP recibe archivo via $\_FILES\['photo']

2\. Validar tipo MIME y tamaño máximo (2MB)

3\. Generar nombre: timestamp\_uniqid().jpg

4\. move\_uploaded\_file() a /uploads/fotos/{company\_id}/{location\_id}/{employee\_id}/

5\. Si falla → photo\_url = NULL, photo\_status = 'unavailable', continuar

6\. INSERT en attendance\_records con todos los campos

7\. Si tipo = 'salida':

a. Buscar el entry\_record\_id del último par abierto

b. Calcular hours\_worked = (exit\_time - entry\_time) en decimales

c. INSERT en attendance\_shifts con status = 'closed'

8\. Si tipo = 'entrada':

a. Calcular tardanza: recorded\_at > schedule\_start + tolerance\_mins

b. INSERT en attendance\_shifts con status = 'open' o 'late'

9\. UPDATE devices SET last\_seen\_at = NOW() WHERE id = ?

**Paso 8 — Confirmación visual**

text

Entrada puntual:  "✅ Bienvenido, {nombre} — {hora} — {sede}"  badge verde "Puntual"

Entrada tardanza: "✅ Bienvenido, {nombre} — {hora}"           badge ámbar "Tardanza"

Salida:           "👋 Hasta luego, {nombre} — Turno: {horas}h"

Re-login < 10min: "✔ Tu entrada ya fue registrada a las {hora}"

Después de 3 segundos: limpiar formulario, foco al campo usuario.

\---

**9. Casos especiales de turnos**

**Turno nocturno cruzando medianoche**

El sistema NO usa la fecha del día para decidir. Usa el último tipo de registro. Un empleado que entra a las 10 PM del lunes y sale a las 3 AM del martes:

* PHP ve último tipo = entrada → registra salida
* hours\_worked = diferencia de timestamps = 5h correctamente
* shift\_date = fecha de la entrada = lunes
* El resumen del martes NO cuenta ese turno como del martes

**Empleado con múltiples turnos**

text

8:00 AM  → entrada (último: vacío)

12:00 PM → salida  (último: entrada)

2:00 PM  → entrada (último: salida)   ← nuevo turno sin restricción

6:00 PM  → salida  (último: entrada)

Cada par genera un registro en attendance\_shifts. Las horas se suman en daily\_attendance\_summary.

**Turno incompleto**

Si un empleado entra y no marca salida, el Cron Job a las 11:59 PM:

* Detecta registros en attendance\_shifts con status = 'open' y entry\_time de más de 14 horas.
* Marca status = 'incomplete'.
* NO inventa hora de salida.
* Genera alerta para el admin.
* El admin puede corregir manualmente desde el módulo de asistencia.

**Empleado en cualquier sede**

Un empleado puede registrar en cualquier sede de su empresa. El location\_id se resuelve automáticamente desde el deviceToken del kiosk donde se registra. El admin puede ver en qué sede marcó cada turno.

\---

**10. Pantallas del módulo kiosk**

**Pantalla K1 — Dispositivo no autorizado**

**Ruta:** /kiosk/blocked.php

**Cuándo aparece:** El deviceToken no existe, no coincide, o la licencia está vencida.

**Elementos:**

* Pantalla completa sin navegación
* Ícono de escudo con X en rojo
* Título: "Dispositivo no autorizado"
* Subtítulo según motivo: dispositivo no registrado / licencia vencida / dispositivo suspendido
* Botón "Solicitar autorización" (envía correo al admin)
* Log del intento guardado en access\_attempts

**Criterios de aceptación:**

* El formulario de login nunca se renderiza si el dispositivo no está autorizado
* El motivo específico se muestra al usuario sin exponer datos técnicos

\---

**Pantalla K2 — Kiosk de registro**

**Ruta:** /kiosk/index.php

**Modo:** Pantalla completa, sin barra de URL visible, sin menú

**Elementos:**

* Logo de la empresa (si tiene configurado) o logo del sistema
* Nombre de la sede visible
* Reloj en tiempo real (hora y fecha actualizando cada segundo con JS)
* Título: "Registra tu asistencia"
* Campo: Usuario (foco automático al cargar y al reiniciar)
* Campo: Contraseña (con botón mostrar/ocultar)
* Botón principal: "Registrar"
* Texto pequeño: "Tu cámara se activará automáticamente"

**Estados de la interfaz que JS debe manejar:**

1. loading — Spinner mientras valida dispositivo al cargar la página
2. blocked — Redirige a K1
3. ready — Muestra formulario, foco en usuario
4. processing — Inputs deshabilitados, "Validando..."
5. camera — Overlay de cámara con countdown 3, 2, 1
6. saving — "Guardando registro..."
7. success — Pantalla de confirmación (K3)
8. error — Mensaje de error, permite reintento inmediato

**Mensajes de error:**

* "Completa los dos campos"
* "Dispositivo no autorizado"
* "Licencia vencida. Contacta al administrador"
* "Usuario o contraseña incorrectos"
* "Usuario inactivo"
* "No fue posible acceder a la cámara — verifica permisos"
* "Tu entrada ya fue registrada a las HH:MM"

**Criterio HTTPS:** getUserMedia solo funciona en HTTPS o localhost. El servidor debe tener SSL activo.

\---

**Pantalla K3 — Confirmación de registro**

**Aparece:** 3 segundos después de un registro exitoso, luego reinicia

**Variantes:**

* Entrada puntual: checkmark verde, "Bienvenido {nombre}", hora, badge "Puntual"
* Entrada con tardanza: checkmark ámbar, "Bienvenido {nombre}", hora, badge "Tardanza {X} min"
* Salida: ícono de despedida azul, "Hasta luego {nombre}", "Turno: {X}h {Y}min"
* Re-login protegido: checkmark gris, "Tu entrada ya fue registrada a las {hora}"

\---

**11. Pantallas del panel admin del cliente**

**Pantalla A1 — Login administrador**

**Ruta:** /admin/login.php

**Elementos:**

* Layout split: panel izquierdo azul marino con logo y tagline, panel derecho con formulario
* Campo usuario o correo
* Campo contraseña con show/hide
* Botón "Ingresar"
* Enlace "¿Olvidaste tu contraseña?" (para versión futura)

**Comportamiento:**

* Validar credenciales con password\_verify()
* Verificar role = 'admin'
* Verificar que la empresa tenga licencia activa (con gracia)
* Crear $\_SESSION con user\_id, company\_id, role
* Redirigir a /admin/dashboard.php
* Si licencia vencida sin gracia: bloquear login con mensaje claro

\---

**Pantalla A2 — Dashboard principal**

**Ruta:** /admin/dashboard.php

**Layout:**

* Sidebar izquierdo con navegación: Inicio, Empleados, Asistencia, Reportes, Sedes, Dispositivos, Configuración, Licencia
* Barra superior: título, selector de sede, fecha de hoy, botón logout

**KPIs del día (6 tarjetas):**

* Empleados activos (total)
* Presentes ahora (tienen entrada sin salida)
* Ausentes hoy
* Tardanzas hoy
* Turnos incompletos de ayer
* Horas totales trabajadas hoy

**Sección central:**

* Lista de empleados con estado actual del día (presente/ausente/tarde/incompleto)
* Últimos 10 registros del día con foto thumbnail

**Sección gráficos:**

* Barras: horas trabajadas por día (últimos 7 días) — Chart.js
* Línea: hora promedio de entrada por día — Chart.js

**Filtro de sede:** selector en barra superior que afecta todos los datos de la vista

\---

**Pantalla A3 — Gestión de empleados**

**Ruta:** /admin/employees.php

**Tabla con columnas:** Nombre, Usuario, Horario esperado, Estado (activo/inactivo), Estado hoy, Sede habitual, Acciones

**Acciones por fila:** Editar, Ver detalle, Desactivar/Reactivar

**Botón:** "Nuevo empleado" → abre modal A3-M

**Modal A3-M — Crear/editar empleado:**

* Nombre completo
* Usuario (único por empresa)
* Contraseña temporal
* Rol (Empleado / Administrador)
* Hora entrada esperada
* Hora salida esperada
* Minutos de tolerancia (default 10)
* Estado activo (toggle)

**Reglas:**

* username único por company\_id
* Contraseña hasheada con password\_hash($pass, PASSWORD\_BCRYPT)
* Desactivar = UPDATE SET is\_active = 0, deleted\_at = NOW() — NUNCA DELETE
* Empleados inactivos no pueden marcar asistencia pero su historial permanece intacto

\---

**Pantalla A4 — Detalle del empleado**

**Ruta:** /admin/employees/detail.php?id=

**Secciones:**

* Header: avatar, nombre, usuario, horario, estado, botón editar
* KPIs del mes: días trabajados, tardanzas, ausencias, horas acumuladas, hora promedio entrada/salida
* Gráficos: barras de horas por día, línea de puntualidad semanal
* Tabla historial: fecha, entrada, salida, horas, sede, estado del día, thumbnail foto
* Galería de evidencias fotográficas con click para ampliar

**Filtro:** selector de rango de fechas

\---

**Pantalla A5 — Asistencia consolidada**

**Ruta:** /admin/attendance.php

**Tabla con columnas:** Fecha/Hora, Empleado, Tipo, Sede, Estado, Foto, Dispositivo, Observaciones, Acciones

**Filtros:** por fecha, rango, empleado, tipo (entrada/salida), estado, sede

**Acciones por fila:**

* Ver foto en modal
* Editar observación
* Marcar justificación manual
* Corregir hora de salida en turno incompleto

**Paginación:** 25 registros por página

\---

**Pantalla A6 — Reportes**

**Ruta:** /admin/reports.php

**Filtros superiores:** rango de fechas, empleados (multi-select), sede, botones exportar PDF y Excel

**KPIs del periodo:**

* Horas totales trabajadas
* Porcentaje de asistencia
* Total tardanzas
* Total ausencias

**Gráficos:**

* Barras agrupadas: horas trabajadas por empleado y por día
* Barras apiladas: puntual / tarde / ausente por empleado
* Radar comparativo de desempeño multi-empleado
* Heatmap mensual: filas = empleados, columnas = días, colores = estado

**Exportación:**

* PDF con Dompdf: incluye KPIs, tabla resumen, gráficos
* Excel con PhpSpreadsheet: hojas separadas (Resumen, Empleados, Registros, Métricas)

\---

**Pantalla A7 — Sedes**

**Ruta:** /admin/locations.php

**Tabla:** Nombre, Dirección, Ciudad, Dispositivos activos, Estado, Acciones

**Botón:** "Nueva sede" → modal con nombre, dirección, ciudad

**Límite:** según max\_locations del plan contratado. Si se alcanza, bloquear con mensaje de upgrade

\---

**Pantalla A8 — Dispositivos**

**Ruta:** /admin/devices.php

**Tabla:** Nombre, Sede, IP, Última vez visto, Estado, Acciones

**Acciones:** Ver detalle, Desactivar, Reemplazar (genera nuevo token)

**Límite:** según max\_devices del plan

\---

**Pantalla A9 — Configuración**

**Ruta:** /admin/settings.php

**Secciones:**

* Información empresa: nombre, NIT, logo, zona horaria
* Reglas de asistencia: hora entrada/salida default, minutos tolerancia, horas para marcar incompleto (default 14)
* Kiosk: mostrar reloj (sí/no), segundos countdown (2/3/5), preview cámara (sí/no)
* Seguridad: ver dispositivos autorizados, reemplazar dispositivo

\---

**Pantalla A10 — Licencia**

**Ruta:** /admin/license.php

**Información visible:**

* Estado: Activa / Por vencer / Vencida con badge por color
* Plan actual y sus límites
* Fecha activación y vencimiento
* Días restantes con barra de progreso
* Historial de activaciones

**Acciones:**

* Ingresar nueva clave de activación
* Botón "Renovar" (genera correo de solicitud manual al proveedor)

\---

**12. Pantallas del Superpanel (proveedor SaaS)**

El superpanel está en una ruta separada (/superadmin/) y no tiene ninguna relación con el panel admin del cliente. Solo accede el equipo del proveedor.

**Pantalla S1 — Login Superpanel**

**Ruta:** /superadmin/login.php

* Formulario minimalista
* Credenciales de super\_admins
* Crear $\_SESSION con superadmin\_id, role
* Registrar login en superadmin\_audit\_log

\---

**Pantalla S2 — Dashboard Superpanel**

**Ruta:** /superadmin/dashboard.php

**KPIs del negocio SaaS:**

* Total clientes activos
* Clientes por vencer en 15 días (badge ámbar)
* Clientes vencidos sin renovar
* Clientes suspendidos
* ARR (ingresos anuales recurrentes en COP)
* MRR equivalente
* Total empleados gestionados en toda la plataforma
* Total marcaciones del mes en toda la plataforma

**Gráficos:**

* Línea: clientes nuevos por mes
* Barras: ingresos registrados por mes
* Donut: distribución por plan (Básico/Estándar/Pro/Empresarial)

**Alertas del día:**

* Lista de clientes que vencen en los próximos 7 días
* Clientes con licencia activa sin ninguna marcación en 30 días (riesgo churn)

\---

**Pantalla S3 — Gestión de clientes**

**Ruta:** /superadmin/companies.php

**Tabla con columnas:** Nombre, NIT, Plan, Estado, Activación, Vencimiento, Días restantes, Empleados activos, Acciones

**Filtros:** por estado, plan, ciudad, vencimiento próximo

**Acciones por fila:**

* Ver detalle completo
* Activar licencia
* Renovar licencia
* Cambiar plan
* Suspender / reactivar
* Enviar notificación manual
* Ver notas internas
* Agregar nota
* Soft-delete

**Botón:** "Nuevo cliente" → formulario S3-C

**Formulario S3-C — Crear cliente:**

* Nombre empresa
* NIT
* Ciudad
* Nombre contacto
* Correo contacto
* Teléfono
* Plan asignado (select de plans)
* Fecha inicio (default hoy)
* Fecha corte (calculada automáticamente según duración del plan)
* Precio pagado real (puede diferir del precio del plan por descuentos)
* Notas iniciales
* Trial (sí/no)
* Al guardar: genera license\_key única, crea registros en companies y licenses

\---

**Pantalla S4 — Detalle del cliente**

**Ruta:** /superadmin/companies/detail.php?id=

**Secciones:**

* Info general: nombre, NIT, contacto, ciudad
* Estado de licencia: plan, fechas, días restantes, estado
* Límites del plan vs uso actual: empleados, sedes, dispositivos
* KPIs de uso: total marcaciones del mes, empleados activos, sedes activas
* Historial de licencias: todas las activaciones con fechas, plan, quién activó, precio
* Historial de pagos: fecha, monto, método, referencia, quién confirmó
* Notas internas: cronológicas con superadmin que las escribió
* Historial de notificaciones enviadas

**Acciones:**

* Renovar licencia
* Cambiar plan
* Registrar pago
* Agregar nota
* Suspender
* Enviar correo manual
* Exportar datos del cliente en CSV

\---

**Pantalla S5 — Gestión de planes**

**Ruta:** /superadmin/plans.php

**Tabla:** Nombre, Precio COP/año, Max empleados, Max sedes, Max dispositivos, Clientes activos, Estado

**Botón:** "Nuevo plan" → formulario S5-F

**Formulario S5-F:**

* Nombre del plan
* Descripción
* Precio anual COP
* Duración en meses (default 12)
* Máximo de empleados
* Máximo de sedes
* Máximo de dispositivos kiosk
* Módulos incluidos (checkboxes): reportes avanzados, exportación PDF, exportación Excel, multi-sede, galería de fotos
* Estado activo

**Regla:** Un plan con clientes activos no puede eliminarse, solo archivarse

\---

**Pantalla S6 — Pagos y facturación**

**Ruta:** /superadmin/payments.php

**Tabla global:** Cliente, Plan, Monto COP, Fecha pago, Método, Referencia, Confirmado por

**Filtros:** por cliente, rango de fechas, plan, método de pago

**Botón:** "Registrar pago manual" → modal con cliente (select), monto, fecha, método, referencia, notas

**Panel lateral:**

* Total ingresos del mes actual
* Total ingresos del año
* Cuentas por cobrar: clientes con licencia activa y sin pago registrado

\---

**Pantalla S7 — Notificaciones**

**Ruta:** /superadmin/notifications.php

**Pestañas:**

* Automáticas: configuración de cuándo enviar avisos de vencimiento (30, 15, 7 días, día vencimiento, post-vencimiento). Toggle on/off por tipo.
* Manuales: seleccionar cliente(s), asunto, cuerpo del mensaje, enviar. Con historial.
* Plantillas: editar el HTML de cada tipo de correo automático

**Historial:** tabla con todos los correos enviados, cliente, tipo, fecha, estado (enviado/fallido)

\---

**Pantalla S8 — Métricas SaaS**

**Ruta:** /superadmin/metrics.php

**Métricas de negocio:**

* ARR, MRR, ingresos del mes vs mes anterior
* Clientes nuevos por mes (gráfico de línea)
* Churn rate mensual: clientes que vencieron y no renovaron
* Distribución por plan (donut)
* Ingresos por plan (barras)
* Top 10 clientes por uso (marcaciones)
* Clientes inactivos: licencia activa pero 0 marcaciones en 30 días
* Proyección de renovaciones: cuánto dinero entra si todos renuevan en los próximos 90 días

\---

**Pantalla S9 — Auditoría**

**Ruta:** /superadmin/audit.php

**Tabla:** Fecha, Superadmin, Acción, Entidad, Detalle, IP

**Filtros:** por superadmin, por tipo de acción, por rango de fechas

Todas las acciones del superpanel quedan registradas automáticamente en superadmin\_audit\_log.

\---

**Pantalla S10 — Gestión de superadmins**

**Ruta:** /superadmin/admins.php

* Tabla de superadmins activos con roles
* Roles: Owner (acceso total), Support (solo lectura + notas), Billing (gestión pagos y licencias)
* Crear, editar, desactivar superadmins
* El Owner no puede ser desactivado por otros roles

\---

**13. APIs requeridas**

**API de verificación de dispositivo**

**Endpoint:** POST /api/device/verify.php

**Body (FormData o JSON):**

json

{

"fingerprint": "hash\_del\_dispositivo"

}

PHP lee deviceToken desde cookie, IP y User-Agent desde $\_SERVER.

**Respuesta exitosa:**

json

{

"authorized": true,

"company\_id": 5,

"location\_id": 2,

"location\_name": "Sede Centro",

"company\_name": "Empresa Demo",

"logo\_url": "/uploads/logos/5/logo.png"

}

**Respuesta fallida:**

json

{

"authorized": false,

"reason": "invalid\_device",

"message": "Este dispositivo no está autorizado"

}

\---

**API de registro de asistencia (check-in)**

**Endpoint:** POST /api/employee/checkin.php

**Body (FormData):**

text

photo: \[archivo binario JPG via $\_FILES]

fingerprint: hash\_string

username: string

password: string

PHP lee deviceToken desde cookie.

**Flujo interno del endpoint:**

text

1\. Leer deviceToken desde $\_COOKIE

2\. Obtener company\_id y location\_id desde el device

3\. Validar licencia

4\. Validar dispositivo activo

5\. Buscar employee por username + company\_id

6\. Verificar is\_active

7\. password\_verify()

8\. Verificar ventana de protección 10 minutos

9\. Procesar foto: $\_FILES\['photo'], validar MIME, move\_uploaded\_file()

10\. Determinar tipo: consultar último registro

11\. INSERT attendance\_records

12\. INSERT/UPDATE attendance\_shifts

13\. UPDATE daily\_attendance\_summary

14\. Responder JSON con resultado

**Respuesta exitosa:**

json

{

"success": true,

"type": "entrada",

"employee\_name": "Carlos Martínez",

"recorded\_at": "2026-05-07T08:05:00-05:00",

"shift\_status": "on\_time",

"location\_name": "Sede Centro",

"message": "Entrada registrada exitosamente"

}

\---

**API de login admin**

**Endpoint:** POST /api/admin/login.php

**Body:**

json

{

"username": "admin",

"password": "secret"

}

**Validaciones:**

* Credenciales correctas
* Role = 'admin'
* Empresa con licencia activa (con gracia)

\---

**APIs CRUD empleados**

text

GET    /api/employees/index.php?company\_id=

POST   /api/employees/store.php

PUT    /api/employees/update.php

PATCH  /api/employees/status.php   ← soft-delete / reactivar, NUNCA DELETE

\---

**APIs de reportes**

text

GET /api/reports/summary.php?date\_from=\&date\_to=\&location\_id=

GET /api/reports/employee.php?employee\_id=\&date\_from=\&date\_to=

GET /api/reports/export.php?format=pdf\&date\_from=\&date\_to=

\---

**14. Cron Job nocturno**

**Configuración:** cPanel → Cron Jobs → 59 23 \* \* \* php /home/usuario/public\_html/cron/nightly\_summary.php

**Zona horaria:** El servidor debe estar configurado en America/Bogota o el script debe usar date\_default\_timezone\_set('America/Bogota').

**Lógica del script:**

php

*// 1. Obtener todos los empleados activos de empresas con licencia vigente*

$employees = getActiveEmployeesWithActiveLicense();

**foreach** ($employees **as** $employee) {

&#x20;   $today \\= date('Y-m-d');



&#x20;   \\\*// 2\\\\. Verificar turnos abiertos con más de 14 horas\\\*

    $openShifts \\\\= getOpenShiftsOlderThan($employee\\\\\\\['id'\\\\], 14);

    \\\*\\\*foreach\\\*\\\* ($openShifts \\\*\\\*as\\\*\\\* $shift) {

        markShiftAsIncomplete($shift\\\\\\\['id'\\\\]);

        createAdminAlert($employee\\\\\\\['company\\\\\\\_id'\\\\], 'incomplete\\\\\\\_shift', $shift);

    }

    

    \\\*// 3\\\\. Verificar si el empleado tiene algún turno hoy\\\*

    $hasActivity \\\\= hasActivityToday($employee\\\\\\\['id'\\\\], $today);

    

    \\\*// 4\\\\. Verificar si hoy es día laborable según horario del empleado\\\*

    \\\*// (considera turnos nocturnos que cruzan medianoche)\\\*

    $isWorkday \\\\= isWorkdayForEmployee($employee, $today);

    

    \\\*// 5\\\\. Si no tiene actividad y es día laborable → registrar ausencia\\\*

    \\\*\\\*if\\\*\\\* (\\\\!$hasActivity \\\&\\\& $isWorkday) {

        upsertDailySummary($employee\\\\\\\['id'\\\\], $today, \\\\\\\[

            'status' \\\\=\\\\> 'absent',

            'total\\\\\\\_hours' \\\\=\\\\> 0,

            'total\\\\\\\_shifts' \\\\=\\\\> 0

        \\\\]);

    }

    

    \\\*// 6\\\\. Consolidar resumen del día para empleados con actividad\\\*

    \\\*\\\*if\\\*\\\* ($hasActivity) {

        consolidateDailySummary($employee\\\\\\\['id'\\\\], $today);

    }





}

\---

**15. Reglas de negocio completas**

**Reglas de licencia**

* La licencia se valida en cada request del kiosk y del admin.
* Si expires\_at < NOW() pero está dentro del grace\_days → permitir acceso con banner de aviso.
* Si expires\_at + grace\_days < NOW() → bloquear kiosk completamente. Admin puede ver datos históricos con aviso.
* El grace\_days default es 5, configurable por el superadmin al activar la licencia.
* Las licencias de trial tienen status = 'trial' y expires\_at calculado desde la fecha de creación.

**Reglas de límites de plan**

* Si total employees WHERE company\_id = X AND is\_active = 1 >= plan.max\_employees → bloquear creación de nuevo empleado con mensaje de upgrade.
* Si total locations WHERE company\_id = X AND is\_active = 1 >= plan.max\_locations → bloquear nueva sede.
* Si total devices WHERE company\_id = X AND is\_active = 1 >= plan.max\_devices → bloquear nuevo dispositivo.

**Reglas de asistencia**

* El tipo de marcación (entrada/salida) se determina exclusivamente por el último registro, no por la hora ni la fecha.
* La ventana de protección de 10 minutos aplica solo para entradas consecutivas del mismo empleado.
* Un turno incompleto se genera cuando han pasado más de 14 horas desde la última entrada sin salida.
* Los turnos incompletos no tienen hora de salida calculada artificialmente.
* Un empleado inactivo (is\_active = 0) no puede marcar asistencia.
* Las fotos son evidencia de auditoría. Se conservan aunque el empleado sea desactivado.

**Reglas de integridad de datos**

* Los empleados nunca se borran físicamente. Siempre soft-delete con deleted\_at.
* Las empresas nunca se borran físicamente en la base de datos aunque el superadmin las elimine del panel.
* Los registros de attendance\_records son inmutables. Solo se pueden agregar observaciones o justificaciones.
* Solo el admin puede agregar correcciones manuales, y quedan marcadas con is\_manual = 1.

\---

**16. Métricas y cálculos**

**Por empleado por día**

* total\_hours = suma de hours\_worked de todos los turnos cerrados del día
* status:

  * present si tiene al menos un turno cerrado y sin tardanzas
  * late si tiene al menos una tardanza
  * incomplete si tiene algún turno marcado como incompleto
  * partial si tiene actividad pero combinación de cerrado e incompleto
  * absent si no tiene ningún registro

**Tardanza**

text

late\_minutes = TIMESTAMPDIFF(MINUTE, schedule\_start, entry\_time)

Si late\_minutes > tolerance\_mins → status = 'late'

**Horas trabajadas por turno**

text

hours\_worked = TIMESTAMPDIFF(MINUTE, entry\_time, exit\_time) / 60

**Cumplimiento mensual**

text

expected\_days = días laborables del mes según horario del empleado

worked\_days = COUNT(summary\_date WHERE status IN ('present','late'))

compliance = worked\_days / expected\_days \* 100

\---

**17. Colores corporativos (tokens únicos y canónicos)**

Esta es la única tabla de tokens de color válida. No hay otra referencia. Toda implementación debe usar estos valores:

|Token CSS|Hex|Uso|
|-|-|-|
|--color-primary|#1E3A5F|Sidebar, headers, botones primarios|
|--color-primary-mid|#2D6A9F|Tarjetas secundarias, íconos|
|--color-accent|#4A90D9|Hover, bordes activos, badges|
|--color-bg-light|#F8FBFF|Fondo general de páginas|
|--color-bg-card|#EBF4FF|Fondo de cards y filas alternas|
|--color-white|#FFFFFF|Cards, modales|
|--color-success|#27AE60|Entradas, puntual, activo|
|--color-warning|#F39C12|Tardanzas, por vencer, alertas|
|--color-error|#E74C3C|Errores, ausencias, vencido|
|--color-text|#1C2B3A|Texto principal|
|--color-text-muted|#7F8C9B|Texto secundario, subtítulos|
|--color-border|#D6E4F0|Bordes de cards y divisores|

**Tipografía:** Inter (Google Fonts). Títulos 700, subtítulos 500, texto 400, KPIs 800.  
**Border-radius:** 12px para cards y modales.  
**Sombras:** box-shadow: 0 2px 8px rgba(30,58,95,0.08).

\---

**18. Fases de desarrollo**

**Fase 1 — Base del proyecto (1 semana)**

* Crear estructura de carpetas
* Configurar base de datos MySQL con todas las tablas
* Crear includes/db.php con conexión PDO
* Crear seed SQL con: 1 plan, 1 superadmin, 1 empresa demo, 1 licencia activa, 1 sede, 1 dispositivo, 1 admin, 5 empleados, 30 días de registros de prueba
* Crear includes/helpers.php con funciones comunes

**Fase 2 — Seguridad del dispositivo (0.5 semana)**

* Integrar FingerprintJS en el frontend del kiosk
* Crear api/device/verify.php
* Crear api/device/register.php
* Crear pantalla K1 (bloqueado)
* Lógica de cookie HttpOnly en PHP

**Fase 3 — Kiosk (1 semana)**

* Crear pantalla K2 (formulario kiosk)
* Crear pantalla K3 (confirmación)
* Crear api/employee/checkin.php con toda la lógica completa
* Subida de foto con move\_uploaded\_file()
* Lógica de ventana de protección 10 minutos
* Lógica de turnos múltiples (alternancia por último tipo)

**Fase 4 — Panel admin auth y dashboard (1 semana)**

* Crear A1 login admin
* Crear A2 dashboard con KPIs y Chart.js
* Middleware de sesión para proteger rutas /admin/\*
* Selector de sede funcional

**Fase 5 — Empleados y asistencia (1 semana)**

* Crear A3 gestión empleados con modal
* Crear A4 detalle empleado
* Crear A5 asistencia consolidada con filtros
* CRUD completo con soft-delete

**Fase 6 — Reportes y exportación (1 semana)**

* Crear A6 reportes con todos los gráficos
* Integrar Dompdf para exportar PDF
* Integrar PhpSpreadsheet para exportar Excel
* Heatmap mensual con Chart.js

**Fase 7 — Sedes, dispositivos, configuración y licencia (0.5 semana)**

* Crear A7, A8, A9, A10
* Enforcement de límites del plan en UI y backend

**Fase 8 — Cron Job nocturno (0.5 semana)**

* Crear cron/nightly\_summary.php
* Configurar en cPanel
* Manejar caso de turno nocturno cruzando medianoche
* Sistema de alertas para turnos incompletos

**Fase 9 — Superpanel (1.5 semanas)**

* Crear S1 login superpanel
* Crear S2 dashboard SaaS con métricas
* Crear S3 gestión de clientes completa
* Crear S4 detalle de cliente
* Crear S5 gestión de planes
* Crear S6 pagos manuales
* Crear S7 notificaciones con PHPMailer
* Crear S8 métricas SaaS
* Crear S9 auditoría
* Crear S10 gestión de superadmins

**Fase 10 — Calidad, pruebas y deploy (0.5 semana)**

* Pruebas de todos los casos de error
* Pruebas de límites de plan
* Pruebas de turno nocturno
* Deploy final en cPanel Banahosting
* Configurar SSL (requerido para cámara)
* Configurar Cron Job en cPanel
* README de instalación

**Total estimado: 8.5 a 9 semanas**

\---

**19. Casos de prueba obligatorios**

**Casos del kiosk**

* Empleado registra entrada correctamente → foto tomada, registro guardado, confirmación visible
* Empleado registra salida correctamente → horas calculadas, par cerrado
* Empleado con turno partido (entrada, salida, entrada, salida en el mismo día) → 4 registros, 2 pares, horas sumadas
* Empleado nocturno (entra 10PM lunes, sale 3AM martes) → par cerrado, horas correctas, shift\_date = lunes
* Re-login en menos de 10 minutos → mensaje de ya registrado, sin nuevo registro
* Dispositivo no autorizado → pantalla bloqueada, log guardado
* Licencia vencida → pantalla bloqueada
* Usuario inactivo → error sin revelar existencia
* Credenciales incorrectas → error genérico
* Cámara denegada → registro guardado sin foto, photo\_status = unavailable

**Casos del admin**

* Login correcto → dashboard con datos
* Login con empresa vencida dentro de gracia → acceso con banner
* Login con empresa vencida fuera de gracia → bloqueado
* Crear empleado hasta el límite del plan → éxito
* Crear empleado superando límite → error con mensaje de upgrade
* Desactivar empleado → historial intacto, no puede marcar asistencia
* Reporte por rango → datos consistentes con tabla de asistencia
* Exportar PDF → archivo generado respetando filtros
* Exportar Excel → archivo generado con hojas correctas

**Casos del superpanel**

* Crear cliente y activar licencia → license\_key generada, empresa operativa
* Renovar licencia → extends\_at actualizado correctamente
* Suspender cliente → kiosk bloqueado para ese cliente
* Cambiar plan → límites actualizados inmediatamente
* Registrar pago manual → historial actualizado
* Enviar notificación manual → correo enviado, log registrado
* Acción del superadmin → registrada en audit\_log

**Casos del Cron Job**

* Empleado sin registro en día laborable → marcado como ausente
* Turno abierto con más de 14 horas → marcado incompleto, alerta generada
* Turno nocturno en progreso a las 11:59 PM → NO marcado como incompleto (está dentro de las 14 horas)
* Empresa con licencia vencida → no procesar ausencias (puede generar ruido)

\---

**20. Prompt para la IA programadora**

Usa este documento como especificación funcional y técnica completa para construir el sistema. El stack es PHP 8.x + MySQL + HTML + CSS + JavaScript Vanilla + Chart.js en servidor cPanel con Banahosting.

Implementa exactamente lo definido en este documento: todas las tablas, todos los endpoints, todas las pantallas, todas las reglas de negocio y todos los cálculos.

Sigue el orden de fases definido en la sección 18. No cambies el stack tecnológico ni propongas alternativas diferentes.

Genera código modular con archivos separados por responsabilidad. Usa PDO para todas las consultas a MySQL con prepared statements. Usa password\_hash y password\_verify para contraseñas. Lee siempre IP y User-Agent desde $\_SERVER. Lee siempre el deviceToken desde $\_COOKIE, nunca desde el body del request.

No omitas validaciones, estados de carga, mensajes de error, ni manejo de casos especiales. El código debe estar listo para producción MVP.

\---

*Fin del documento. Versión 2.0 Final.*

