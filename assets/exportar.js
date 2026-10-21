/* Descarga a Excel: arma el .xlsx en el navegador con los datos que dejó la página en data-excel. */
(function () {
  'use strict';

  function fechaExcel(texto) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(texto || '');
    // Mediodía UTC para que ninguna zona horaria corra el día
    return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], 12)) : '';
  }

  function celda(valor, tipo) {
    if (valor === null || valor === undefined || valor === '') return '';
    if (tipo === 'f') return fechaExcel(String(valor));
    if (tipo === 'm' || tipo === 'n') {
      var n = Number(valor);
      return isFinite(n) ? n : String(valor);
    }
    return String(valor);   // los textos (N° de operación, CUIL…) quedan como texto: Excel no les come los ceros
  }

  function hojaDe(def) {
    var tipos = def.columnas.map(function (c) { return c[1]; });
    var filas = [def.columnas.map(function (c) { return c[0]; })];
    def.filas.forEach(function (f) {
      filas.push(f.map(function (v, i) { return celda(v, tipos[i]); }));
    });
    var hoja = XLSX.utils.aoa_to_sheet(filas, { cellDates: true });

    var formatos = { f: 'dd/mm/yyyy', m: '#,##0.00', n: '0' };
    var anchos = def.columnas.map(function (c, i) {
      var max = String(c[0]).length;
      for (var r = 1; r < filas.length && r < 300; r++) {
        var v = filas[r][i];
        var largo = v instanceof Date ? 10 : String(v).length;
        if (largo > max) max = largo;
      }
      return { wch: Math.min(Math.max(max + 2, 10), 48) };
    });
    hoja['!cols'] = anchos;
    if (filas.length > 1) {
      hoja['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: filas.length - 1, c: tipos.length - 1 } }) };
    }
    for (var r = 1; r < filas.length; r++) {
      for (var c = 0; c < tipos.length; c++) {
        var ref = hoja[XLSX.utils.encode_cell({ r: r, c: c })];
        if (ref && formatos[tipos[c]] && (ref.t === 'n' || ref.t === 'd')) ref.z = formatos[tipos[c]];
      }
    }
    return hoja;
  }

  function descargar(datos) {
    var libro = XLSX.utils.book_new();
    datos.hojas.forEach(function (def) {
      XLSX.utils.book_append_sheet(libro, hojaDe(def), def.nombre.slice(0, 31));
    });
    XLSX.writeFile(libro, datos.archivo + '.xlsx', { cellDates: true });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-excel]') : null;
    if (!b) return;
    try {
      descargar(JSON.parse(b.getAttribute('data-excel')));
    } catch (err) {
      alert('No pude armar el Excel: ' + err.message);
    }
  });
})();
