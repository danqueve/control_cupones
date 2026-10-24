/**
 * CONCILIACIÓN BANCARIA — Comprobantes WhatsApp vs. resumen del banco
 *
 * Va en un ARCHIVO NUEVO dentro del mismo proyecto de Apps Script
 * (al lado de Código.gs). No toca doPost/doGet: el bot sigue igual
 * y NO hace falta volver a implementar para usar esto.
 *
 * Pestañas que usa / crea:
 *   Banco            → donde importás el Excel del banco (lo pisás cada vez)
 *   Banco_Datos      → historial acumulado y limpio de todos los resúmenes
 *   Conciliacion     → cada comprobante de WhatsApp con su resultado
 *   Sin_Comprobante  → transferencias que entraron al banco sin comprobante
 *   Buscador         → buscás por CUIL, DNI o nombre
 */

const CONC_HOJA_BANCO = 'Banco';
const CONC_HOJA_DATOS = 'Banco_Datos';
const CONC_HOJA_RESULT = 'Conciliacion';
const CONC_HOJA_SIN = 'Sin_Comprobante';
const CONC_HOJA_BUSCADOR = 'Buscador';

const CONC_COLS_DATOS = ['Fecha', 'CUIL/CUIT', 'DNI', 'Nombre', 'Monto', 'Referencia', 'Código', 'Concepto', 'Conciliado con', 'Clave'];
const CONC_COLS_RESULT = ['Resultado', 'Zona', 'Fecha comp.', 'Cobrador', 'Titular (comprobante)', 'Monto', 'Billetera', 'Nro Operación', 'DNI', 'Fecha banco', 'Nombre en banco', 'CUIL en banco', 'Ref. banco'];

const CONC_TOLERANCIA_DIAS = 3;   // diferencia máxima entre fecha del comprobante y fecha del banco
const CONC_TOLERANCIA_CUIL = 5;   // con CUIL coincidente se acepta un poco más de margen

// ─── Menú ────────────────────────────────────────────────────────
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('🏦 Conciliación')
    .addItem('▶ Procesar banco y conciliar', 'concProcesarTodo')
    .addSeparator()
    .addItem('1) Solo procesar resumen del banco', 'concProcesarBanco')
    .addItem('2) Solo conciliar comprobantes', 'concConciliar')
    .addItem('Crear / reparar buscador', 'concCrearBuscador')
    .addToUi();
}

function concProcesarTodo() {
  const nuevos = concProcesarBanco(true);
  const r = concConciliar(true);
  concCrearBuscador(true);
  SpreadsheetApp.getUi().alert(
    'Listo ✅\n\n' +
    'Movimientos nuevos del banco: ' + nuevos + '\n\n' +
    '✅ Acreditados: ' + r.ok + '\n' +
    '🟡 Probables (revisar): ' + r.probable + '\n' +
    '❌ No encontrados en el banco: ' + r.no + '\n' +
    '⏳ Fuera del período del resumen: ' + r.fuera + '\n' +
    '⚪ Duplicados / sin monto: ' + r.omitidos + '\n\n' +
    'Transferencias en el banco SIN comprobante: ' + r.sinComprobante
  );
}

// ─── 1) Procesar resumen del banco ───────────────────────────────
function concProcesarBanco(silencioso) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const hojaBanco = ss.getSheetByName(CONC_HOJA_BANCO);
  if (!hojaBanco) {
    throw new Error('No encuentro la pestaña "' + CONC_HOJA_BANCO + '". Importá ahí el Excel del banco.');
  }

  const valores = hojaBanco.getDataRange().getValues();
  const map = concMapearEncabezado(valores);
  if (!map) throw new Error('No encuentro el encabezado (Fecha / Concepto / Credito) en la pestaña Banco.');

  const datos = concObtenerHojaDatos();
  const existentes = new Set();
  if (datos.getLastRow() > 1) {
    datos.getRange(2, 10, datos.getLastRow() - 1, 1).getValues().forEach(r => existentes.add(String(r[0])));
  }

  const nuevas = [];
  let ultimaFecha = null;
  for (let i = map.filaHeader + 1; i < valores.length; i++) {
    const fila = valores[i];
    const fecha = concResolverFecha(fila[map.fecha], ultimaFecha);
    if (!fecha) continue;
    ultimaFecha = fecha;

    const credito = concParseMonto(fila[map.credito]);
    if (!credito || credito <= 0) continue; // solo ingresos

    const concepto = String(fila[map.concepto] || '').trim();
    const referencia = String(fila[map.referencia] || '').trim();
    const codigo = map.codigo >= 0 ? String(fila[map.codigo] || '').trim() : '';
    const cuit = concExtraerCuit(concepto);
    const dni = cuit ? concDniDeCuit(cuit) : '';
    const nombre = concNombreBanco(concepto);

    const clave = [Utilities.formatDate(fecha, 'America/Argentina/Tucuman', 'yyyy-MM-dd'), referencia, credito.toFixed(2), codigo].join('|');
    if (existentes.has(clave)) continue;
    existentes.add(clave);

    nuevas.push([fecha, cuit, dni, nombre, credito, referencia, codigo, concepto, '', clave]);
  }

  if (nuevas.length) {
    const desde = datos.getLastRow() + 1;
    datos.getRange(desde, 1, nuevas.length, CONC_COLS_DATOS.length).setValues(nuevas);
    datos.getRange(2, 1, datos.getLastRow() - 1, 1).setNumberFormat('dd/MM/yyyy');
    datos.getRange(2, 5, datos.getLastRow() - 1, 1).setNumberFormat('$ #,##0.00');
    datos.getRange(2, 3, datos.getLastRow() - 1, 1).setNumberFormat('0');
    datos.getRange(2, 2, datos.getLastRow() - 1, 1).setNumberFormat('@');
  }

  if (!silencioso) SpreadsheetApp.getUi().alert('Movimientos nuevos agregados: ' + nuevas.length);
  return nuevas.length;
}

// ─── 2) Conciliar ────────────────────────────────────────────────
function concConciliar(silencioso) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const hojaDatos = concObtenerHojaDatos();
  const nBanco = Math.max(hojaDatos.getLastRow() - 1, 0);
  const banco = nBanco ? hojaDatos.getRange(2, 1, nBanco, CONC_COLS_DATOS.length).getValues() : [];

  // Movimientos del banco
  const movs = banco.map((r, i) => ({
    i: i,
    fecha: r[0] instanceof Date ? r[0] : null,
    cuit: String(r[1] || ''),
    dni: r[2] ? Number(r[2]) : 0,
    nombre: String(r[3] || ''),
    monto: Number(r[4]) || 0,
    ref: String(r[5] || ''),
    asignado: null,
  })).filter(m => m.fecha && m.monto > 0);

  let minBanco = null, maxBanco = null;
  movs.forEach(m => {
    if (!minBanco || m.fecha < minBanco) minBanco = m.fecha;
    if (!maxBanco || m.fecha > maxBanco) maxBanco = m.fecha;
  });

  const porMonto = {};
  movs.forEach(m => {
    const k = Math.round(m.monto * 100);
    (porMonto[k] = porMonto[k] || []).push(m);
  });

  // Comprobantes de todas las zonas
  const comps = concLeerComprobantes(ss);
  const contador = { ok: 0, probable: 0, no: 0, fuera: 0, omitidos: 0, sinComprobante: 0 };

  const candidatos = (c, tol) => {
    const lista = porMonto[Math.round(c.monto * 100)] || [];
    return lista.filter(m => !m.asignado && Math.abs(concDiasEntre(m.fecha, c.fecha)) <= tol);
  };
  const asignar = (c, m, resultado) => {
    m.asignado = c;
    c.mov = m;
    c.resultado = resultado;
  };
  const masCercano = (c, lista) => lista.slice().sort((a, b) =>
    Math.abs(concDiasEntre(a.fecha, c.fecha)) - Math.abs(concDiasEntre(b.fecha, c.fecha)))[0];

  // Marcar los que no se concilian
  comps.forEach(c => {
    if (/DUPLICADO/i.test(c.estado)) { c.resultado = '⚪ Duplicado (no se concilia)'; return; }
    if (!c.monto) { c.resultado = '⚪ Sin monto leído'; return; }
    if (!c.fecha) { c.resultado = '⚪ Sin fecha leída'; return; }
  });
  const pendientes = () => comps.filter(c => !c.resultado);

  // Pasada 1: mismo DNI/CUIL + mismo monto
  pendientes().forEach(c => {
    if (!c.dni) return;
    const lista = candidatos(c, CONC_TOLERANCIA_CUIL).filter(m => m.dni === c.dni);
    if (lista.length) asignar(c, masCercano(c, lista), '✅ Acreditado (CUIL + monto)');
  });

  // Pasada 2: nombre parecido + mismo monto
  pendientes().forEach(c => {
    const lista = candidatos(c, CONC_TOLERANCIA_DIAS).filter(m => concNombresCoinciden(c.titular, m.nombre));
    if (lista.length) asignar(c, masCercano(c, lista), '✅ Acreditado (nombre + monto)');
  });

  // Pasada 3: solo monto + fecha (empezando por los más claros)
  pendientes()
    .map(c => ({ c: c, n: candidatos(c, CONC_TOLERANCIA_DIAS).length }))
    .sort((a, b) => a.n - b.n)
    .forEach(x => {
      const c = x.c;
      const lista = candidatos(c, CONC_TOLERANCIA_DIAS);
      if (lista.length === 1) {
        asignar(c, lista[0], '🟡 Probable (monto y fecha) — revisar');
      } else if (lista.length > 1) {
        asignar(c, masCercano(c, lista), '🟡 Probable — había ' + lista.length + ' transferencias iguales, revisar');
      }
    });

  // Pasada 4: sin match
  pendientes().forEach(c => {
    const fuera = !minBanco ||
      concDiasEntre(c.fecha, minBanco) < -CONC_TOLERANCIA_DIAS ||
      concDiasEntre(c.fecha, maxBanco) > CONC_TOLERANCIA_DIAS;
    c.resultado = fuera ? '⏳ Fuera del período del resumen' : '❌ No encontrado en el banco';
  });

  comps.forEach(c => {
    const r = c.resultado;
    if (r.indexOf('✅') === 0) contador.ok++;
    else if (r.indexOf('🟡') === 0) contador.probable++;
    else if (r.indexOf('❌') === 0) contador.no++;
    else if (r.indexOf('⏳') === 0) contador.fuera++;
    else contador.omitidos++;
  });

  // Escribir pestaña Conciliacion
  const filas = comps.map(c => [
    c.resultado, c.zona, c.fecha, c.cobrador, c.titular, c.monto, c.billetera, c.nroOp,
    c.dni || (c.mov ? c.mov.dni : '') || '',
    c.mov ? c.mov.fecha : '', c.mov ? c.mov.nombre : '', c.mov ? c.mov.cuit : '', c.mov ? c.mov.ref : '',
  ]);
  const prioridad = s => {
    const orden = ['❌', '🟡', '⏳', '✅', '⚪'];
    for (let k = 0; k < orden.length; k++) if (String(s).indexOf(orden[k]) === 0) return k;
    return 9;
  };
  filas.sort((a, b) => {
    const oa = prioridad(a[0]), ob = prioridad(b[0]);
    if (oa !== ob) return oa - ob;
    return (b[2] || 0) - (a[2] || 0);
  });
  concEscribirTabla(ss, CONC_HOJA_RESULT, CONC_COLS_RESULT, filas, { fechas: [3, 10], montos: [6], texto: [12] }, r => {
    const s = String(r[0]);
    if (s.indexOf('✅') === 0) return '#e6f4ea';
    if (s.indexOf('🟡') === 0) return '#fff4ce';
    if (s.indexOf('❌') === 0) return '#fce8e6';
    return '#f1f3f4';
  });

  // Marcar en Banco_Datos con qué comprobante se concilió
  if (nBanco) {
    const marcas = banco.map(() => ['']);
    movs.forEach(m => {
      if (m.asignado) marcas[m.i] = [m.asignado.zona + ' fila ' + m.asignado.fila];
    });
    hojaDatos.getRange(2, 9, nBanco, 1).setValues(marcas);
  }

  // Transferencias sin comprobante (solo las que traen CUIL, o sea transferencias de personas)
  const sin = movs.filter(m => !m.asignado && m.dni)
    .sort((a, b) => b.fecha - a.fecha)
    .map(m => [m.fecha, m.nombre, m.cuit, m.dni, m.monto, m.ref, String(banco[m.i][7] || '')]);
  contador.sinComprobante = sin.length;
  concEscribirTabla(ss, CONC_HOJA_SIN, ['Fecha', 'Nombre en banco', 'CUIL/CUIT', 'DNI', 'Monto', 'Referencia', 'Concepto'],
    sin, { fechas: [1], montos: [5], texto: [3] }, null);

  if (!silencioso) {
    SpreadsheetApp.getUi().alert('Conciliación lista: ✅ ' + contador.ok + ' · 🟡 ' + contador.probable +
      ' · ❌ ' + contador.no + ' · ⏳ ' + contador.fuera + '\nSin comprobante: ' + contador.sinComprobante);
  }
  return contador;
}

// ─── Buscador ────────────────────────────────────────────────────
function concCrearBuscador(silencioso) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let h = ss.getSheetByName(CONC_HOJA_BUSCADOR);
  const valorPrevio = h ? h.getRange('B2').getValue() : '';
  if (!h) h = ss.insertSheet(CONC_HOJA_BUSCADOR, 0);
  h.clear();

  h.getRange('A1').setValue('🔎 Buscador por CUIL, DNI o nombre').setFontSize(14).setFontWeight('bold');
  h.getRange('A2').setValue('Buscar:').setFontWeight('bold');
  h.getRange('B2').setValue(valorPrevio).setBackground('#fff4ce').setNumberFormat('@')
    .setNote('Escribí un CUIL (con o sin guiones), un DNI o parte del nombre.');
  h.getRange('B2:D2').merge();

  const q = 'q,TRIM(TO_TEXT($B$2)),d,REGEXREPLACE(q,"[^0-9]",""),dni,IF(LEN(d)=11,VALUE(MID(d,3,8)),IF(LEN(d)>=6,VALUE(d),0))';

  h.getRange('A3').setFormula('=IF($B$2="","",COUNT(A7:A)&" transferencias en el banco · total $ "&TEXT(SUM(E7:E),"#,##0.00"))');
  h.getRange('K3').setFormula('=IF($B$2="","",COUNTA(K7:K)-COUNTIF(K7:K,"Sin resultados")&" comprobantes por WhatsApp")');

  h.getRange('A5').setValue('Transferencias en el banco').setFontWeight('bold');
  h.getRange(6, 1, 1, 9).setValues([CONC_COLS_DATOS.slice(0, 9)]);
  h.getRange('A7').setFormula('=LET(' + q + ',IF(q="","",IFERROR(FILTER(' + CONC_HOJA_DATOS + '!A2:I,' +
    'IF(dni>0,' + CONC_HOJA_DATOS + '!C2:C=dni,ISNUMBER(SEARCH(q,' + CONC_HOJA_DATOS + '!D2:D)))),"Sin resultados")))');

  h.getRange('K5').setValue('Comprobantes recibidos por WhatsApp').setFontWeight('bold');
  h.getRange(6, 11, 1, CONC_COLS_RESULT.length).setValues([CONC_COLS_RESULT]);
  h.getRange('K7').setFormula('=LET(' + q + ',IF(q="","",IFERROR(FILTER(' + CONC_HOJA_RESULT + '!A2:M,' +
    'IF(dni>0,' + CONC_HOJA_RESULT + '!I2:I=dni,ISNUMBER(SEARCH(q,' + CONC_HOJA_RESULT + '!E2:E))+ISNUMBER(SEARCH(q,' + CONC_HOJA_RESULT + '!K2:K)))),"Sin resultados")))');

  h.getRange(6, 1, 1, 9).setBackground('#1a73e8').setFontColor('#ffffff').setFontWeight('bold');
  h.getRange(6, 11, 1, CONC_COLS_RESULT.length).setBackground('#1a73e8').setFontColor('#ffffff').setFontWeight('bold');
  h.getRange('A7:A').setNumberFormat('dd/MM/yyyy');
  h.getRange('E7:E').setNumberFormat('$ #,##0.00');
  h.getRange('M7:M').setNumberFormat('dd/MM/yyyy');
  h.getRange('T7:T').setNumberFormat('dd/MM/yyyy');
  h.getRange('P7:P').setNumberFormat('$ #,##0.00');
  h.setFrozenRows(6);
  h.setColumnWidth(4, 200); h.setColumnWidth(8, 260); h.setColumnWidth(11, 230); h.setColumnWidth(15, 200);
  h.activate();
  h.getRange('B2').activate();

  if (!silencioso) SpreadsheetApp.getUi().alert('Buscador listo. Escribí el CUIL, DNI o nombre en la celda amarilla.');
}

// ─── Lectura de comprobantes ─────────────────────────────────────
function concLeerComprobantes(ss) {
  const comps = [];
  ss.getSheets().forEach(hoja => {
    if (hoja.getLastRow() < 2) return;
    const datos = hoja.getDataRange().getValues();
    const enc = datos[0].map(v => String(v).trim());
    const col = n => enc.indexOf(n);
    if (col('Remitente') < 0 || col('Monto') < 0 || col('Nro Operación') < 0) return; // no es una zona

    // Extraer zona base: "Zona Norte - Oct 2026" → "Zona Norte"
    const nombreHoja = hoja.getName();
    const zona = typeof zonaBase === 'function' ? zonaBase(nombreHoja) : nombreHoja;

    for (let i = 1; i < datos.length; i++) {
      const r = datos[i];
      const get = n => (col(n) >= 0 ? r[col(n)] : '');
      if (r.join('') === '') continue;

      const fecha = concFechaComprobante(get('Fecha Comprobante')) || concFechaComprobante(get('Fecha Recepción'));
      const titular = String(get('Titular Origen') || '');
      const cuit = concExtraerCuit(String(get('CUIL Origen') || '')) ||
                   concExtraerCuit(String(get('CBU/CVU/Alias') || '')) ||
                   concExtraerCuit(titular);
      comps.push({
        zona: zona,
        fila: i + 1,
        fecha: fecha,
        cobrador: String(get('Remitente') || ''),
        titular: titular,
        monto: concParseMonto(get('Monto')),
        billetera: String(get('Banco/Billetera') || ''),
        nroOp: String(get('Nro Operación') || ''),
        estado: String(get('Estado') || ''),
        dni: cuit ? concDniDeCuit(cuit) : 0,
        resultado: null,
        mov: null,
      });
    }
  });
  return comps;
}

// ─── Utilidades puras (sin acceso a la planilla) ─────────────────
function concMapearEncabezado(valores) {
  for (let i = 0; i < Math.min(valores.length, 15); i++) {
    const enc = valores[i].map(v => concNormalizar(String(v)));
    const idx = n => enc.indexOf(n);
    if (idx('CONCEPTO') >= 0 && idx('CREDITO') >= 0 && idx('FECHA') >= 0) {
      return {
        filaHeader: i,
        fecha: idx('FECHA'),
        referencia: idx('REFERENCIA'),
        codigo: idx('CODIGO CAUSAL'),
        concepto: idx('CONCEPTO'),
        credito: idx('CREDITO'),
      };
    }
  }
  return null;
}

function concNormalizar(s) {
  return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().trim();
}

// El Excel del banco trae fechas mezcladas: "13/10/2026" como texto, y los
// días 1 al 12 convertidos por error en fechas reales con día y mes al revés
// (9/10 aparece como 10 de septiembre). Para esas, se prueban las dos lecturas
// y se elige la más cercana a la fila anterior (el resumen viene ordenado).
function concResolverFecha(valor, anterior) {
  if (valor instanceof Date && !isNaN(valor)) {
    const a = new Date(valor.getFullYear(), valor.getMonth(), valor.getDate());
    if (valor.getDate() > 12) return a;
    const b = new Date(valor.getFullYear(), valor.getDate() - 1, valor.getMonth() + 1);
    if (anterior) {
      return Math.abs(concDiasEntre(a, anterior)) <= Math.abs(concDiasEntre(b, anterior)) ? a : b;
    }
    const hoy = new Date();
    const fa = a > hoy, fb = b > hoy;
    if (fa !== fb) return fa ? b : a;
    return Math.abs(concDiasEntre(a, hoy)) <= Math.abs(concDiasEntre(b, hoy)) ? a : b;
  }
  return concParseFechaTexto(valor);
}

function concParseFechaTexto(valor) {
  const m = String(valor || '').match(/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
  if (!m) return null;
  let anio = Number(m[3]);
  if (anio < 100) anio += 2000;
  const d = new Date(anio, Number(m[2]) - 1, Number(m[1]));
  return isNaN(d) ? null : d;
}

function concFechaComprobante(valor) {
  if (valor instanceof Date && !isNaN(valor)) return new Date(valor.getFullYear(), valor.getMonth(), valor.getDate());
  return concParseFechaTexto(valor);
}

function concParseMonto(v) {
  if (typeof v === 'number') return isNaN(v) ? 0 : v;
  const s = String(v || '').replace(/[^0-9,.\-]/g, '');
  if (!s) return 0;
  const normal = s.indexOf(',') >= 0 ? s.replace(/\./g, '').replace(',', '.') : s;
  const n = parseFloat(normal);
  return isNaN(n) ? 0 : n;
}

function concExtraerCuit(texto) {
  const m = String(texto || '').match(/(?:^|\D)((?:20|23|24|25|26|27|30|33|34)\d{9})(?!\d)/);
  if (m) return m[1];
  const g = String(texto || '').match(/(?:^|\D)((?:20|23|24|25|26|27|30|33|34))-(\d{8})-(\d)(?!\d)/);
  return g ? g[1] + g[2] + g[3] : '';
}

function concDniDeCuit(cuit) {
  return Number(String(cuit).substring(2, 10)) || 0;
}

function concNombreBanco(concepto) {
  const m = String(concepto).match(/TRANSF:\s*([A-ZÑ ,.'´]+?)\s*-\s*\d{11}/i);
  if (!m) return '';
  // Los códigos tipo "7L8GYKNX47LD..." no son nombres
  return /\d/.test(m[1]) ? '' : m[1].trim();
}

function concNombresCoinciden(a, b) {
  const tok = s => concNormalizar(s).replace(/[^A-Z ]/g, ' ').split(/\s+/).filter(t => t.length >= 3);
  const ta = tok(a), tb = tok(b);
  if (!ta.length || !tb.length) return false;
  let coinciden = 0;
  ta.forEach(x => {
    if (tb.some(y => x === y || (Math.min(x.length, y.length) >= 4 && (x.indexOf(y) === 0 || y.indexOf(x) === 0)))) coinciden++;
  });
  return coinciden >= 2 || (coinciden === 1 && Math.min(ta.length, tb.length) === 1);
}

function concDiasEntre(a, b) {
  return Math.round((a - b) / 86400000);
}

// ─── Utilidades de planilla ──────────────────────────────────────
function concObtenerHojaDatos() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let h = ss.getSheetByName(CONC_HOJA_DATOS);
  if (!h) {
    h = ss.insertSheet(CONC_HOJA_DATOS);
    h.getRange(1, 1, 1, CONC_COLS_DATOS.length).setValues([CONC_COLS_DATOS])
      .setBackground('#5f6368').setFontColor('#ffffff').setFontWeight('bold');
    h.setFrozenRows(1);
    h.hideColumns(10);
    h.setColumnWidth(4, 200); h.setColumnWidth(8, 300);
  }
  return h;
}

function concEscribirTabla(ss, nombre, columnas, filas, formatos, colorFila) {
  let h = ss.getSheetByName(nombre);
  if (!h) h = ss.insertSheet(nombre);
  h.clear();
  h.getRange(1, 1, 1, columnas.length).setValues([columnas])
    .setBackground('#1a73e8').setFontColor('#ffffff').setFontWeight('bold');
  h.setFrozenRows(1);
  if (!filas.length) return;
  const rango = h.getRange(2, 1, filas.length, columnas.length);
  (formatos.texto || []).forEach(c => h.getRange(2, c, filas.length, 1).setNumberFormat('@'));
  rango.setValues(filas);
  (formatos.fechas || []).forEach(c => h.getRange(2, c, filas.length, 1).setNumberFormat('dd/MM/yyyy'));
  (formatos.montos || []).forEach(c => h.getRange(2, c, filas.length, 1).setNumberFormat('$ #,##0.00'));
  if (colorFila) rango.setBackgrounds(filas.map(r => columnas.map(() => colorFila(r))));
  h.autoResizeColumns(1, columnas.length);
}
