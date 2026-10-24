/**
 * EXPORTADOR DE CUPONES — para Control de Cupones (control.imperiocomercial.com.ar)
 *
 * Proyecto de Apps Script APARTE del bot (no tocar el del bot).
 * Solo LEE la planilla y la entrega en formato JSON a quien tenga el TOKEN.
 *
 * Implementar como: Aplicación web
 *   Ejecutar como: Yo
 *   Quién tiene acceso: Cualquier usuario
 * (sin el token correcto no devuelve ningún dato)
 *
 * Parámetros GET:
 *   token    (obligatorio) — clave de acceso
 *   periodo  (opcional)    — "2026-10" filtra solo hojas de ese mes + hojas sin mes
 *   meses    (opcional)    — "true" devuelve la lista de meses disponibles sin filas
 */

const SPREADSHEET_ID = '1tLE-FifxTbdrq8oMQ8ZmKi2etNt2HrEXDafiazb-rlA';
const TOKEN = 'bd2c2e656d0beea22fd79db73c8a96a487a124dfde41eb3f';

const CAMPOS = {
  'Fecha Recepción': 'fecha_recepcion',
  'Remitente': 'remitente',
  'Fecha Comprobante': 'fecha_comprobante',
  'Hora': 'hora',
  'Monto': 'monto',
  'Banco/Billetera': 'billetera',
  'Nro Operación': 'nro_operacion',
  'Titular Origen': 'titular',
  'CBU/CVU/Alias': 'cbu',
  'CUIL Origen': 'cuil',
  'Estado': 'estado',
  'ID Mensaje': 'msg_id',
};

// Meses en español — misma tabla que Codigo.gs
const MESES_ES_ = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

/**
 * Extrae el periodo "2026-10" del nombre de una hoja mensual.
 * "Zona Norte - Oct 2026" → "2026-10"
 * "Zona Norte"             → null (hoja sin mes = datos anteriores a la separación)
 */
function periodoDeHoja_(nombre) {
  const m = nombre.match(/\s+-\s+(Ene|Feb|Mar|Abr|May|Jun|Jul|Ago|Sep|Oct|Nov|Dic)\s+(\d{4})$/);
  if (!m) return null;
  const idx = MESES_ES_.indexOf(m[1]);
  if (idx < 0) return null;
  return m[2] + '-' + String(idx + 1).padStart(2, '0');
}

/**
 * Extrae la zona base quitando el sufijo mensual.
 * "Zona Norte - Oct 2026" → "Zona Norte"
 * "Zona Norte"             → "Zona Norte"
 */
function zonaBase_(nombre) {
  return nombre.replace(/\s+-\s+(Ene|Feb|Mar|Abr|May|Jun|Jul|Ago|Sep|Oct|Nov|Dic)\s+\d{4}$/, '');
}

function doGet(e) {
  const token = e && e.parameter ? e.parameter.token : '';
  if (!token || token !== TOKEN) {
    return json_({ ok: false, error: 'Token inválido' });
  }

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const tz = ss.getSpreadsheetTimeZone();
  const periodoFiltro = e.parameter.periodo || '';  // "2026-10" o vacío
  const soloMeses = e.parameter.meses === 'true';

  // Si piden solo la lista de meses disponibles
  if (soloMeses) {
    const mesesSet = new Set();
    ss.getSheets().forEach(hoja => {
      if (hoja.getLastRow() < 2) return;
      const enc = hoja.getRange(1, 1, 1, Math.min(hoja.getLastColumn(), 13)).getValues()[0].map(v => String(v).trim());
      if (enc.indexOf('Remitente') < 0 || enc.indexOf('Monto') < 0 || enc.indexOf('Nro Operación') < 0) return;
      const p = periodoDeHoja_(hoja.getName());
      mesesSet.add(p || 'sin-mes');
    });
    // Ordenar descendente (más reciente primero)
    const lista = Array.from(mesesSet).sort().reverse();
    return json_({ ok: true, meses: lista });
  }

  const zonas = [];

  ss.getSheets().forEach(hoja => {
    if (hoja.getLastRow() < 2) return;
    const datos = hoja.getDataRange().getValues();
    const enc = datos[0].map(v => String(v).trim());
    // Solo pestañas de zona (las que arma el bot)
    if (enc.indexOf('Remitente') < 0 || enc.indexOf('Monto') < 0 || enc.indexOf('Nro Operación') < 0) return;

    const nombreHoja = hoja.getName();
    const periodoHoja = periodoDeHoja_(nombreHoja);

    // Filtrar por periodo si se pidió
    if (periodoFiltro) {
      // Si la hoja tiene mes, solo incluirla si coincide
      // Si la hoja NO tiene mes (datos viejos), incluirla siempre
      if (periodoHoja && periodoHoja !== periodoFiltro) return;
    }

    const zonaLimpia = zonaBase_(nombreHoja);

    const filas = [];
    for (let i = 1; i < datos.length; i++) {
      const fila = { fila: i + 1 };
      let vacia = true;
      enc.forEach((titulo, j) => {
        const campo = CAMPOS[titulo];
        if (!campo) return;
        let v = datos[i][j];
        if (v instanceof Date) {
          v = Utilities.formatDate(v, tz, campo === 'hora' ? 'HH:mm' : 'dd/MM/yyyy');
        }
        if (v !== '' && v !== null) vacia = false;
        fila[campo] = v;
      });
      if (!vacia) filas.push(fila);
    }

    // Agrupar por zona base: si ya existe "Zona Norte", agregar las filas
    const existente = zonas.find(z => z.nombre === zonaLimpia);
    if (existente) {
      existente.filas = existente.filas.concat(filas);
    } else {
      zonas.push({ nombre: zonaLimpia, filas: filas });
    }
  });

  return json_({
    ok: true,
    generado: new Date().toISOString(),
    periodo: periodoFiltro || 'todos',
    zonas: zonas,
  });
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
