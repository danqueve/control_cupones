# Control de Cupones — Imperio Comercial

Sistema web para controlar que los cupones de pago que llegan por WhatsApp
(cargados por el bot en la planilla de Google) estén realmente acreditados
en la cuenta del **Banco Macro**.

- **Subir resumen:** se elige el Excel de "Últimos movimientos" de Macro y el sistema lo cruza con los cupones.
- **Resultado:** ✅ acreditados · 🟡 a revisar · ❌ no figuran en el banco · 💵 plata que entró sin cupón.
- **Pendientes:** los cupones que no figuran quedan en seguimiento hasta que aparecen en otro resumen (se resuelven solos) o se resuelven a mano con una nota.
- **Buscador:** por DNI o CUIL (completo o parcial) o por nombre y apellido de quien pagó.
- **Historial:** cada control y cada búsqueda quedan guardados.
- **SAS Imperio (solo lectura):** muestra el nombre del cliente por DNI.

Dirección: `https://control.imperiocomercial.com.ar`

## Cómo funciona

```
Bot WhatsApp ──► Planilla de Google ──► Exportador.gs (JSON con token) ──┐
                                                                         ▼
Excel de Macro ──► navegador (lo lee con SheetJS) ──► PHP ──► MySQL c2881399_control
                                                               │
                                       SAS Imperio (solo SELECT) ◄┘  nombre por DNI
```

**Cómo se cruza un cupón con una transferencia** (en este orden):

1. El N° de operación del cupón aparece en el concepto o la referencia del banco, y el monto es igual → ✅
2. El CUIL del cupón coincide con el del banco y el monto es igual (hasta 5 días de diferencia) → ✅
3. El nombre del titular coincide con el del banco y el monto es igual (hasta 3 días) → ✅
4. Solo coinciden el monto y la fecha → 🟡 a revisar
5. Sin coincidencia → ❌ no figura (pasa a Pendientes). Si el cupón es de los últimos 2 días del resumen, o de una fecha sin resumen cargado → ⏳ en espera.

Cada control vuelve a cruzar **todos** los cupones contra **todo** el historial del banco,
y guarda una foto del resultado del período del archivo subido.

**Fechas del Excel de Macro:** del 1 al 12 de cada mes, Excel las guarda con día y mes
invertidos (el 9/10 aparece como 10 de septiembre). El sistema lo detecta y lo corrige solo.

## Instalación en el VPS (vps-5710883-x)

### 1. Subdominio

En el panel de dominios crear el subdominio **control** de `imperiocomercial.com.ar`
(queda la carpeta `/home/c2881399/public_html/control`) y elegir **PHP 8.1 o superior** para ese subdominio.

### 2. Código

```bash
cd /home/c2881399/public_html/control
rm -rf cgi-bin
git init
git remote add origin https://github.com/danqueve/control_cupones.git
git pull origin main
chown -R c2881399:c2881399 /home/c2881399/public_html/control
```

### 3. Base de datos

```bash
mysql
```
```sql
CREATE DATABASE c2881399_control CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'c2881399_control'@'localhost' IDENTIFIED BY 'UNA_CLAVE_LARGA';
GRANT ALL PRIVILEGES ON c2881399_control.* TO 'c2881399_control'@'localhost';
FLUSH PRIVILEGES;
```
```bash
mysql c2881399_control < /home/c2881399/public_html/control/sql/schema.sql
```

### 4. Usuario de SOLO LECTURA para SAS Imperio

Primero confirmar el nombre de la tabla de clientes y sus columnas:

```bash
mysql -e "SHOW TABLES FROM c2881399_credit LIKE '%client%'"
mysql -e "SHOW COLUMNS FROM c2881399_credit.ic_clientes"
```

Crear el usuario con permiso **únicamente de SELECT** y solo sobre las 3 columnas que usa
(no ve teléfonos, direcciones ni tokens):

```sql
CREATE USER 'c2881399_sasro'@'localhost' IDENTIFIED BY 'OTRA_CLAVE_LARGA';
GRANT SELECT (dni, nombres, apellidos) ON c2881399_credit.ic_clientes TO 'c2881399_sasro'@'localhost';
FLUSH PRIVILEGES;
```

La consulta de `sas.sql` en `app/config.php` tiene que devolver una columna `dni` y una `nombre`.

### 5. Exportador de la planilla (Google Apps Script)

Es un proyecto **nuevo y separado** del bot (no se toca el Apps Script del bot).

1. Generar un token: `openssl rand -hex 24`
2. Ir a https://script.google.com → **Nuevo proyecto** → nombre "Exportador cupones".
3. Pegar el contenido de `apps-script/Exportador.gs` y poner el token en `const TOKEN`.
4. **Implementar → Nueva implementación → Aplicación web**
   - Ejecutar como: **Yo**
   - Quién tiene acceso: **Cualquier usuario** (sin el token no devuelve nada)
5. Autorizar y copiar la URL que termina en `/exec`.
6. Probar: `curl -sL "URL?token=EL_TOKEN" | head -c 300` → tiene que empezar con `{"ok":true`.

### 6. Configuración

```bash
cd /home/c2881399/public_html/control
cp app/config.example.php app/config.php
nano app/config.php      # claves de la base, URL + token del exportador, datos de SAS
chmod 640 app/config.php
chown c2881399:c2881399 app/config.php
```

### 7. Usuarios

El PHP de consola del VPS por defecto es 5.6: usar el de PHP 8.

```bash
ls /usr/local/lsws/ | grep lsphp          # ver versiones disponibles
PHP=/usr/local/lsws/lsphp83/bin/php
cd /home/c2881399/public_html/control
$PHP bin/crear_usuario.php alejandro "Alejandro Quevedo"
$PHP bin/crear_usuario.php usuario2 "Nombre Apellido"
```

El mismo comando sirve para cambiar una contraseña.

### 8. Probar

Entrar a `https://control.imperiocomercial.com.ar`, ingresar, tocar **Actualizar ahora**
(tiene que decir cuántos cupones leyó de la planilla) y subir el Excel de Macro.

Verificar que las carpetas internas están bloqueadas (tiene que dar 403):
`https://control.imperiocomercial.com.ar/app/config.php`

## Actualizar

```bash
cd /home/c2881399/public_html/control && git pull origin main
```

Si un cambio agrega tablas o columnas, se indica en el commit y se corre el SQL correspondiente.

## Opcional: leer la planilla cada 30 minutos (cron)

No hace falta: la planilla se lee sola al subir un resumen y al buscar (si pasaron más de 15 minutos).

```
*/30 * * * * /usr/local/lsws/lsphp83/bin/php /home/c2881399/public_html/control/bin/sincronizar.php >> /home/c2881399/control_sync.log 2>&1
```

## Archivos

| Archivo | Qué hace |
|---|---|
| `index.php` | Inicio: subir resumen, último control, pendientes, buscar |
| `control.php` | Resultado de un control |
| `buscar.php` | Buscador por DNI / CUIL / nombre |
| `pendientes.php` | Cupones en seguimiento |
| `historial.php` | Controles y búsquedas anteriores |
| `api/subir.php` | Recibe el Excel ya leído y ejecuta el control |
| `app/Banco.php` | Lee el Excel de Macro (fechas, montos, CUIL, nombre) |
| `app/Conciliador.php` | Cruza cupones con transferencias |
| `app/Controles.php` | Guarda el control, pendientes e historial |
| `app/Planilla.php` | Trae los cupones desde la planilla de Google |
| `app/Busqueda.php` | Buscador |
| `app/Sas.php` | Nombre del cliente en SAS (solo lectura) |
| `apps-script/Exportador.gs` | Publica la planilla como JSON con token |
| `sql/schema.sql` | Tablas |
| `assets/vendor/xlsx.full.min.js` | SheetJS 0.18.5 (lector de Excel, licencia Apache 2.0) |
