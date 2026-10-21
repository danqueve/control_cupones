# Control de Cupones — contexto del proyecto

> Documento para darle contexto a un asistente (Claude Project, otro LLM, un colaborador).
> Describe qué hace el sistema, cómo está armado, cómo está desplegado y qué quedó pendiente.
> No contiene claves ni tokens: esos viven solo en `app/config.php` (no se sube a GitHub).

Última actualización: 21/10/2026 · Repo: `danqueve/control_cupones` (privado, rama `main`)

---

## 1. Qué es y para qué sirve

**Imperio Comercial** (crédito y venta en cuotas, Tucumán / Santiago del Estero / Catamarca) cobra con
cobradores que reciben transferencias de clientes. Cada cobrador manda el comprobante (imagen o PDF)
a un grupo de WhatsApp de su zona. Un bot lee el comprobante con IA y lo carga en una planilla de Google (**"cupón"**).

**Control de Cupones** responde, una vez por semana:

1. De los cupones que cargó el bot, **¿cuáles están realmente acreditados en el Banco Macro?**
2. **¿Cuáles no figuran en el banco?** Quedan como *pendientes* para seguimiento.
3. **¿Quién mandó plata al banco sin cupón?**
4. Buscar por **DNI, CUIL (completo o parcial) o nombre y apellido** de quien depositó ("Titular Origen") y ver todo lo que hizo.
5. Guardar **historial** de controles y de búsquedas.

Usuarios: el dueño (Alejandro) y una persona más, con poca experiencia técnica, desde celular y PC.
Por eso la interfaz es simple, en español rioplatense, con colores y tarjetas grandes.
Banco único: **Banco Macro**. Controles semanales.

## 2. Arquitectura

```
Grupos de WhatsApp (8 zonas)
   └─► Bot comprobantes (Node, whatsapp-web.js, PM2) ── Claude Haiku lee el comprobante
         └─► Google Sheet (una pestaña por zona)
               └─► Exportador.gs (Apps Script web app, devuelve JSON, protegido por token)
                     └─► PHP: Planilla::sincronizar() ──► MySQL c2881399_control (tabla cupones)

Excel/CSV de Macro ("Últimos movimientos")
   └─► navegador (SheetJS lee el archivo) ── POST JSON ──► api/subir.php
         └─► Banco::parsear → Conciliador::conciliar → MySQL (movimientos, controles, control_items, pendientes)

SAS Imperio (MySQL c2881399_credit, SOLO LECTURA) ──► nombre del cliente por DNI (opcional)
```

- **Stack:** PHP 8.3 sin framework (páginas renderizadas en servidor) + MySQL 8 + PDO con consultas preparadas.
  Probado también en MariaDB 10.11 y PHP 8 local.
- **Sin dependencias de Composer.** Único asset externo: `assets/vendor/xlsx.full.min.js` (SheetJS 0.18.5, Apache 2.0).
- **Sesión/seguridad:** `password_hash`, CSRF en todos los POST, bloqueo de 15 min tras 5 intentos fallidos,
  cookie `httponly` + `SameSite=Lax`, sesión de 8 h de inactividad, `.htaccess` que fuerza HTTPS
  (excepto localhost/192.168/10.) y bloquea `app/`, `bin/`, `sql/`, `apps-script/`, `.git`, `*.md`, `*.sql`, `*.log`.
- **Decisión de diseño importante:** los cupones se **leen de la planilla** (el sistema no recibe datos del bot).
  El bot no se modifica y no escribe en esta base.

## 3. Estructura del repo

| Ruta | Qué hace |
|---|---|
| `index.php` | Inicio: subir resumen (arrastrar archivo), último control, pendientes, buscador rápido, estado de la planilla, botón "Actualizar ahora" |
| `control.php?id=N&f=...` | Resultado de un control: 4 tarjetas (Acreditados, A revisar, No figuran, Plata sin cupón) y filtros |
| `buscar.php?q=...` | Buscador por DNI/CUIL/nombre; personas encontradas, lo que entró al banco y los cupones |
| `pendientes.php` | Cupones que no figuran: resolver con nota, reabrir, ver resueltos |
| `historial.php` | Controles anteriores y últimas búsquedas |
| `login.php`, `logout.php` | Acceso |
| `api/subir.php` | Recibe el archivo ya leído por el navegador (JSON) y ejecuta el control |
| `app/bootstrap.php` | Config, PDO, sesión, login, CSRF, helpers (`h`, `dinero`, `fecha_ar`, `estado_info`) |
| `app/Texto.php` | Normalizar nombres, extraer CUIL/DNI, parsear montos y fechas argentinas |
| `app/Banco.php` | Lee el archivo de Macro (encabezados, fechas, créditos, nombre y CUIL del concepto) |
| `app/Conciliador.php` | Cruza cupones con transferencias (lógica pura, sin base de datos) |
| `app/Controles.php` | Orquesta un control: guarda, concilia, pendientes, foto en historial |
| `app/Planilla.php` | Trae los cupones desde el Exportador y los guarda en MySQL |
| `app/Busqueda.php` | Buscador y resumen por persona |
| `app/Sas.php` | Nombre del cliente en SAS, solo lectura |
| `app/layout.php` | Encabezado, navegación, pie, etiquetas |
| `apps-script/Exportador.gs` | Proyecto de Apps Script **aparte del bot**: publica la planilla como JSON con token |
| `bin/crear_usuario.php` | Crear usuario o cambiar contraseña (solo consola) |
| `bin/sincronizar.php` | Sincronizar la planilla por consola (para cron, opcional) |
| `sql/schema.sql` | Las 8 tablas |
| `assets/app.css`, `assets/subir.js` | Estilos (verde `#155e4b`, nav inferior en móvil) y subida del archivo |
| `README.md` | Guía de instalación en el VPS |

## 4. Modelo de datos (MySQL `c2881399_control`, utf8mb4)

- `usuarios` — acceso (hash, intentos, bloqueo).
- `cupones` — copia de la planilla. `clave` = sha1(zona|fecha_recepcion|cobrador|monto|nro_op|titular#ocurrencia).
  Cada sincronización marca todo `en_planilla=0` y vuelve a subir lo que sigue estando (upsert). Guarda el último
  resultado de conciliación (`estado_conc`, `detalle_conc`, `movimiento_id`).
- `movimientos` — transferencias recibidas según Macro, **acumuladas** entre resúmenes. `clave` = sha1(fecha|ref|monto|código|concepto#ocurrencia),
  así que subir dos veces el mismo archivo no duplica.
- `controles` — una fila por resumen subido (período, totales, contadores).
- `control_items` — **foto** del resultado de cada control, no cambia aunque después se reconcilie.
- `pendientes` — cupones que no figuran (`pendiente` | `resuelto`, nota, quién y cuándo).
- `busquedas` — historial de consultas.
- `ajustes` — clave/valor (por ej. `ultima_sincronizacion`).

## 5. Lectura del archivo de Macro (`Banco.php`, `Texto.php`)

Columnas: `Fecha, Referencia, Codigo Causal, Concepto, Debito, Credito, Saldo`. Se usan solo los **créditos** (plata que entra).

Particularidades reales del archivo (importante, ya costó errores):

- **Fechas del 1 al 12 de cada mes** vienen como número de serie de Excel con **día y mes invertidos**
  (el 9/10 aparece como 10/09). Las del 13 en adelante vienen como texto `dd/mm/aaaa`. Se corrige con una
  heurística: se elige la lectura más cercana a la fila anterior (el archivo viene ordenado por fecha descendente).
- **Montos** como `64.000,00`. `Texto::monto()` entiende formato argentino, de EE.UU. (`64,000.00`) y número directo.
- **CSV:** el navegador lee con `raw: true` para no convertir `38.000,00` en 38 ni invertir fechas.
  **Recomendación: subir el Excel original de Macro sin abrirlo ni volver a guardarlo.** CSV funciona; TXT no se probó.
- **Concepto del banco** trae el CUIL de 11 dígitos y a veces el nombre (cortado):
  `ING TRANSF:YAMILA BELEN DELGADILL-27432251360` o `TRANSF FERNANDO 20339742597 VAR`.
  De ahí se extraen CUIL → DNI (dígitos 3 al 10) y nombre. En el archivo de prueba, 668 de 867 movimientos tienen nombre.
- Archivo de prueba real: 867 créditos del 18/09 al 13/10/2026 (total ≈ $68,76 M), 858 con DNI.

## 6. Cómo se concilia un cupón con una transferencia (`Conciliador.php`)

Cada control **vuelve a cruzar todos los cupones contra todo el historial del banco** (no solo contra el archivo recién subido).
Pasadas en orden; una transferencia se asigna a un solo cupón; en empate gana la fecha más cercana:

1. **N° de operación** del cupón aparece en el concepto o en la referencia del banco + mismo monto (±5 días) → ✅ `ok`
2. **DNI/CUIL** coincide + mismo monto (±5 días) → ✅ `ok`
3. **Nombre** coincide (prefijos de ≥4 letras, mínimo 2 palabras) + mismo monto (±3 días) → ✅ `ok`
   Si el banco informó solo el CUIL (sin nombre), se compara con el nombre del cliente en SAS según el DNI (`Sas::nombres`, solo lectura; detalle "Coincide nombre (según SAS) y monto"). Si SAS está apagado o falla, ese paso se omite.
4. Solo **monto + fecha** (±3 días) → 🟡 `revisar`
5. Sin coincidencia → ❌ `no` (pasa a Pendientes), o ⏳ `espera` si el cupón es de los últimos 2 días del resumen
   o de una fecha para la que todavía no se subió resumen.
- `omitido`: cupón duplicado (lo marca el bot), sin monto o sin fecha.
- Parámetros en `config.php`: `tolerancia_dias` (3), `tolerancia_dias_cuil` (5), `dias_espera` (2).
- Los **pendientes se resuelven solos** cuando un resumen posterior trae la transferencia (nota automática "Apareció en el banco el … (control #N)"),
  o a mano con una nota.
- **Plata sin cupón:** transferencias con DNI, no asignadas a ningún cupón, dentro del período del archivo.
- `GET_LOCK('control_cupones')` evita dos controles simultáneos.

## 7. Planilla de Google y bot de WhatsApp

- **Bot** (repo aparte `danqueve/comprobantes-bot`): Node 20 + `whatsapp-web.js` (Chromium headless) en el VPS, `/opt/comprobantes-bot`, PM2 (`comprobantes-bot`).
  Lee imagen/PDF con **Claude Haiku**, detecta duplicados, hace chequeo visual de ediciones, responde con mensaje de agradecimiento y
  agrega una fila a la pestaña de la zona. Los grupos se configuran en el `.env` del bot: `GRUPOS_PERMITIDOS` (IDs `...@g.us`)
  y `NOMBRES_GRUPOS` (mismo orden; el nombre es el de la pestaña).
- **Zonas activas (8):** Zona Norte, Zona Sur, Zona Este, Tafi del Valle, Zona 6, Zona Sgo, Leales y **Suroeste** (agregada el 13/10/2026).
  Una zona nueva no requiere cambios en este sistema: la pestaña se crea sola y el Exportador la toma.
- **Columnas de cada pestaña:** Fecha Recepción, Grupo, Remitente, Fecha Comprobante, Hora, Monto, Banco/Billetera, Nro Operación,
  Titular Origen, CBU/CVU/Alias, Estado, ID Mensaje. El Exportador acepta además `CUIL Origen` si algún día el bot lo agrega.
- **Mapeo al sistema:** `Remitente` (nombre en WhatsApp de quien envió) = *cobrador*; `Titular Origen` = *titular* que pagó;
  el CUIL del cupón sale de `CUIL Origen` o se busca dentro de `CBU/CVU/Alias`.
- **Exportador** (`apps-script/Exportador.gs`): web app con acceso "Cualquier usuario" pero que **solo responde si llega el token**
  (`?token=...`); devuelve `{ok, generado, zonas:[{nombre, filas}]}`. Lee las pestañas que tengan columnas Remitente, Monto y Nro Operación.
  Si se modifica el script hay que publicar **Nueva versión** (no "Nueva implementación") para conservar la URL.
- **Sincronización:** `Planilla::sincronizarSiHaceFalta()` lee solo si la última lectura tiene más de 15 min (`minutos_vigencia`);
  se dispara al subir un resumen, al buscar y con el botón "Actualizar ahora". Si falla, el control sigue con la última lectura y avisa.

## 8. SAS Imperio (solo lectura, opcional)

- Muestra el nombre del cliente de SAS junto al DNI ("Cliente de SAS").
- Conexión PDO **separada**, con `SET SESSION TRANSACTION READ ONLY`, y la consulta configurable debe empezar con `SELECT`.
  Si falla, se desactiva sola y el sistema sigue funcionando sin esos nombres.
- Tabla real: `c2881399_credit.ic_clientes`, columnas `nombres`, `apellidos`, `dni`, `cuil` (varchar). Consulta vigente en `config.example.php`
  (quita puntos y espacios del DNI).
- Usuario MySQL dedicado `c2881399_sasro` con `GRANT SELECT (dni, nombres, apellidos)` **solo sobre esas 3 columnas**
  (no ve teléfono, dirección ni tokens). Probado: no puede leer otras columnas ni borrar.
- **Requisito del dueño:** SAS siempre en solo lectura. Nunca se escribe en SAS desde este sistema.

## 9. Despliegue

- **Producción:** VPS Dattaweb `vps-5710883-x` (AlmaLinux 8, panel estilo Ferozo, SSH puerto 5848, usuario del sitio `c2881399`).
  Es el mismo VPS donde corren el bot, SAS Imperio y el CRM. URL: `https://control.imperiocomercial.com.ar`
  (vhost en `/opt/apache/conf/vhosts/control.imperiocomercial.com.ar.conf`, DocumentRoot `/home/c2881399/public_html/control`), **PHP 8.3** por subdominio.
- **Actualizar:** `cd /home/c2881399/public_html/control && git pull origin main` (si el cambio agrega tablas, se indica en el commit).
- **Prueba local:** XAMPP en Linux Mint, `/opt/lampp/htdocs/control_cupones`, base `control_cupones`, usuario `root` sin clave, `http://localhost/control_cupones`.
  Usar `/opt/lampp/bin/php` para comandos de consola.
- **Configuración:** `app/config.php` (copiar de `config.example.php`; `chmod 640`). Nunca va a Git. Verificar siempre que
  `https://control.imperiocomercial.com.ar/app/config.php` devuelva **403**.
- **Usuarios:** `/usr/local/lsws/lsphp83/bin/php bin/crear_usuario.php <usuario> "<Nombre Apellido>"`
  (el PHP de consola por defecto del VPS es 5.6; no usarlo).

## 10. Trampas conocidas del entorno

- El `public_html` del VPS tiene **su propio `.git`** (otro proyecto): al operar en `control/` con git como root puede aparecer
  "posesión dudosa" → `git config --global --add safe.directory /home/c2881399/public_html/control`.
- El panel del VPS no deja asignar una carpeta distinta por dominio, por eso el sistema vive físicamente en `public_html/control`.
- Los comandos SQL (`CREATE USER`, `GRANT`…) se escriben **dentro** de `mysql>`; pegados directo en bash dan "no se encontró la orden".
- Para copiar de la terminal de Linux: Ctrl+Shift+C / Ctrl+Shift+V.
- Los mensajes de error de base de datos nunca se muestran al usuario (se captura `PDOException` antes que `RuntimeException`).

## 11. Estado actual

**Hecho y funcionando**
- Sistema completo desplegado en el VPS: subir resumen, comparación, pendientes, buscador, historial, 2 usuarios.
- Probado con el Excel real de Macro y con CSV (mismos resultados: 867 movimientos).
- Planilla conectada vía Exportador. Grupo **Suroeste** agregado al bot (8 zonas).

**Pendiente / a confirmar**
- Activar SAS en producción: crear el usuario `c2881399_sasro` (se intentó pegando SQL en bash; hay que hacerlo dentro de `mysql>`),
  poner su clave en `config.php` y `'activo' => true`. Si se usó una clave débil, cambiarla.
- Cargar a mano (o pedir reenvío) los 4 comprobantes de Suroeste que llegaron antes de configurar el grupo
  (AstroPay, Prex, Juarez, Ibañez) y borrar el cupón de prueba de $10.400 de esa pestaña.
- Limpiar datos de prueba de la base local si se vuelve a probar (`TRUNCATE` de `control_items`, `pendientes`, `movimientos`, `controles`
  y reset de `estado_conc` en `cupones`).

**Ideas de mejora (no hechas)**
- Que el bot extraiga el **CUIL del pagador** como campo propio (`CUIL Origen`). Hoy el bot ya lee un campo `cuit_cbu_origen` y lo guarda en la columna
  `CBU/CVU/Alias`; el control saca el CUIL de ahí si el modelo devolvió un CUIL y no un CBU/alias. Un campo separado lo haría más confiable.
  Antes de hacerlo, medir cuántos cupones ya tienen CUIL (`SELECT COUNT(*), SUM(cuit IS NOT NULL) FROM cupones`).
- Guardar la imagen del cupón en Drive y mostrarla en el control.
- Exportar a Excel el resultado de un control / la lista de pendientes.
- Cron cada 30 min para sincronizar la planilla (`bin/sincronizar.php`; hoy es bajo demanda).
- Mostrar en el buscador el historial de cupones por cobrador.

## 12. Convenciones para quien modifique el código

- Español en la interfaz y en los comentarios; mensajes de error claros para gente no técnica.
- `declare(strict_types=1)`; PDO con consultas preparadas; escapar toda salida con `h()`; CSRF en todo POST.
- La lógica de cruce vive en `Conciliador` (pura, sin base de datos) para poder probarla con arrays.
- No guardar claves en el repo. No escribir jamás en la base de SAS.
- Antes de cambiar una regla de conciliación, probar con el Excel real de Macro y comparar los contadores del control.
