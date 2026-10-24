const COLUMNAS = [
  'Fecha Recepción', 'Grupo', 'Remitente', 'Fecha Comprobante', 'Hora',
  'Monto', 'Banco/Billetera', 'Nro Operación', 'Titular Origen',
  'CBU/CVU/Alias', 'Estado', 'Revisión Manual', 'ID Mensaje'
];
const HOJA_INDICE = 'Índice';
const COLUMNAS_INDICE = ['Nro Operación', 'Grupo', 'Fecha Recepción', 'Monto', 'Fila', 'ID Mensaje'];

// Meses en español para el nombre de la hoja
const MESES_ES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

/**
 * Calcula el nombre de la hoja mensual a partir de la zona.
 * Ej: "Zona Norte" → "Zona Norte - Oct 2026"
 * Usa la fecha/hora actual de Argentina.
 */
function nombreHojaMensual(nombreZona) {
  const ahora = new Date();
  // Obtener mes y año en zona Argentina
  const partes = Utilities.formatDate(ahora, 'America/Argentina/Buenos_Aires', 'M-yyyy').split('-');
  const mes = parseInt(partes[0], 10) - 1; // 0-based
  const anio = partes[1];
  return nombreZona + ' - ' + MESES_ES[mes] + ' ' + anio;
}

/**
 * Extrae el nombre de zona base quitando el sufijo mensual.
 * "Zona Norte - Oct 2026" → "Zona Norte"
 * "Zona Norte"             → "Zona Norte"
 */
function zonaBase(nombreHoja) {
  return nombreHoja.replace(/\s+-\s+(Ene|Feb|Mar|Abr|May|Jun|Jul|Ago|Sep|Oct|Nov|Dic)\s+\d{4}$/, '');
}

function doPost(e) {
  try {
    const datos = JSON.parse(e.postData.contents);
    const zonaOriginal = sanitizarNombreHoja(datos.hoja || datos.grupo || 'Comprobantes');
    const nombreHoja = nombreHojaMensual(zonaOriginal);
    const indice = obtenerOCrearIndice();

    // Asegurar que el monto sea siempre un número: si llega vacío o
    // corrupto, cae ordenadamente en 0 en vez de quedar como texto raro.
    let montoNum = parseFloat(datos.monto);
    if (isNaN(montoNum)) montoNum = 0;

    const nroOpRaw = (datos.nro_operacion || '').toString().trim();
    const nroOpKey = normalizarOperacion(nroOpRaw);
    const encontrado = nroOpKey ? buscarEnIndice(indice, nroOpKey) : null;

    // El bot ya había guardado este MISMO mensaje antes (típicamente
    // porque WhatsApp reenvió el evento tras un reinicio/reconexión).
    // No es un duplicado real del cliente: confirmamos sin duplicar fila.
    if (encontrado && datos.msg_id && encontrado.msgId && encontrado.msgId === datos.msg_id) {
      return ContentService
        .createTextOutput(JSON.stringify({
          ok: true, hoja: encontrado.grupo, fila: encontrado.fila,
          duplicado: false, reprocesado: true,
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }

    const hoja = obtenerOCrearHoja(nombreHoja);
    const duplicado = encontrado;

    const estado = duplicado ? 'DUPLICADO ⚠️' : (datos.estado || 'ok');
    const revisionTexto = datos.revision_manual
      ? ('REVISAR ⚠️ - ' + (datos.motivo_revision || 'inconsistencia visual detectada'))
      : '';

    const fila = [
      datos.fecha_recepcion   || '', datos.grupo             || '',
      datos.remitente         || '', datos.fecha_comprobante || '',
      datos.hora_comprobante  || '', montoNum,
      datos.banco_billetera   || '', datos.nro_operacion     || '',
      datos.titular_origen    || '', datos.cuit_cbu          || '',
      estado, revisionTexto, datos.msg_id || '',
    ];

    hoja.appendRow(fila);
    const numFila = hoja.getLastRow();
    hoja.getRange(numFila, 6).setNumberFormat('$ #,##0.00');

    if (duplicado) {
      hoja.getRange(numFila, 1, 1, COLUMNAS.length).setBackground('#fce8e6');
    } else {
      if (nroOpKey) {
        // En el índice guardamos el nombre de hoja completo (con mes)
        indice.appendRow([nroOpKey, nombreHoja, datos.fecha_recepcion || '', montoNum, numFila, datos.msg_id || '']);
      }
      if (datos.revision_manual) {
        hoja.getRange(numFila, 1, 1, COLUMNAS.length).setBackground('#fff4ce');
      }
    }

    return ContentService
      .createTextOutput(JSON.stringify({
        ok: true, hoja: nombreHoja, fila: numFila,
        duplicado: !!duplicado, original: duplicado || null,
      }))
      .setMimeType(ContentService.MimeType.JSON);
  } catch (err) {
    return ContentService
      .createTextOutput(JSON.stringify({ ok: false, error: err.message }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

function doGet(e) {
  return ContentService
    .createTextOutput(JSON.stringify({ ok: true, mensaje: 'Comprobantes Bot activo' }))
    .setMimeType(ContentService.MimeType.JSON);
}

function normalizarOperacion(str) {
  return String(str).toUpperCase().replace(/\s+/g, '');
}

function sanitizarNombreHoja(nombre) {
  return String(nombre).replace(/[\[\]\*\?\/\\:]/g, '_').substring(0, 100);
}

function obtenerOCrearHoja(nombreHoja) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let hoja = ss.getSheetByName(nombreHoja);
  if (!hoja) {
    hoja = ss.insertSheet(nombreHoja);
    hoja.appendRow(COLUMNAS);
    const enc = hoja.getRange(1, 1, 1, COLUMNAS.length);
    enc.setBackground('#1a73e8').setFontColor('#ffffff').setFontWeight('bold').setHorizontalAlignment('center');
    hoja.setFrozenRows(1);
    hoja.setColumnWidth(1, 160); hoja.setColumnWidth(2, 180); hoja.setColumnWidth(3, 140);
    hoja.setColumnWidth(7, 150); hoja.setColumnWidth(8, 160); hoja.setColumnWidth(9, 160);
    hoja.setColumnWidth(12, 220);
  }
  return hoja;
}

function obtenerOCrearIndice() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let hoja = ss.getSheetByName(HOJA_INDICE);
  if (!hoja) {
    hoja = ss.insertSheet(HOJA_INDICE);
    hoja.appendRow(COLUMNAS_INDICE);
    const enc = hoja.getRange(1, 1, 1, COLUMNAS_INDICE.length);
    enc.setBackground('#5f6368').setFontColor('#ffffff').setFontWeight('bold');
    hoja.setFrozenRows(1);
    hoja.hideSheet();
  } else {
    const headers = hoja.getRange(1, 1, 1, hoja.getLastColumn()).getValues()[0];
    if (headers.indexOf('ID Mensaje') === -1) {
      hoja.getRange(1, hoja.getLastColumn() + 1).setValue('ID Mensaje');
    }
  }
  return hoja;
}

function buscarEnIndice(indice, nroOpKey) {
  const ultimaFila = indice.getLastRow();
  if (ultimaFila < 2) return null;
  const columnaA = indice.getRange(2, 1, ultimaFila - 1, 1);
  const encontrada = columnaA.createTextFinder(nroOpKey)
    .matchEntireCell(true)
    .useRegularExpression(false)
    .findNext();
  if (!encontrada) return null;
  const fila = encontrada.getRow();
  const valores = indice.getRange(fila, 1, 1, 6).getValues()[0];
  return { grupo: valores[1], fecha: valores[2], monto: valores[3], fila: valores[4], msgId: valores[5] };
}

function resetearDatos() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  // Borra datos de TODAS las hojas de zona (con o sin sufijo mensual)
  ss.getSheets().forEach(hoja => {
    const nombre = hoja.getName();
    if (nombre === HOJA_INDICE) return;
    if (hoja.getLastRow() < 2) return;
    const enc = hoja.getRange(1, 1, 1, Math.min(hoja.getLastColumn(), COLUMNAS.length)).getValues()[0];
    // Solo hojas que tengan la estructura del bot
    if (enc.indexOf('Remitente') >= 0 && enc.indexOf('Monto') >= 0) {
      hoja.deleteRows(2, hoja.getLastRow() - 1);
      Logger.log('Reset: ' + nombre);
    }
  });
  const indice = ss.getSheetByName(HOJA_INDICE);
  if (indice && indice.getLastRow() > 1) indice.deleteRows(2, indice.getLastRow() - 1);
  Logger.log('Reset completo.');
}

function migrarColumnaRevision() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  ss.getSheets().forEach(hoja => {
    const nombre = hoja.getName();
    if (nombre === HOJA_INDICE) return;
    const headers = hoja.getRange(1, 1, 1, hoja.getLastColumn()).getValues()[0];
    if (headers.indexOf('Revisión Manual') !== -1) return;
    const posIdMensaje = headers.indexOf('ID Mensaje');
    if (posIdMensaje === -1) return;
    hoja.insertColumnBefore(posIdMensaje + 1);
    hoja.getRange(1, posIdMensaje + 1).setValue('Revisión Manual');
    Logger.log('Migrada: ' + nombre);
  });
  Logger.log('Migración completa.');
}
