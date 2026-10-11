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
 */

const SPREADSHEET_ID = '1tLE-FifxTbdrq8oMQ8ZmKi2etNt2HrEXDafiazb-rlA';
const TOKEN = 'PEGAR_EL_MISMO_TOKEN_QUE_EN_config.php';

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

function doGet(e) {
  const token = e && e.parameter ? e.parameter.token : '';
  if (!token || token !== TOKEN) {
    return json_({ ok: false, error: 'Token inválido' });
  }

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const tz = ss.getSpreadsheetTimeZone();
  const zonas = [];

  ss.getSheets().forEach(hoja => {
    if (hoja.getLastRow() < 2) return;
    const datos = hoja.getDataRange().getValues();
    const enc = datos[0].map(v => String(v).trim());
    // Solo pestañas de zona (las que arma el bot)
    if (enc.indexOf('Remitente') < 0 || enc.indexOf('Monto') < 0 || enc.indexOf('Nro Operación') < 0) return;

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
    zonas.push({ nombre: hoja.getName(), filas: filas });
  });

  return json_({ ok: true, generado: new Date().toISOString(), zonas: zonas });
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
