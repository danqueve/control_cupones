(function () {
  'use strict';

  var input = document.getElementById('archivo');
  var zona = document.getElementById('zona-subida');
  var procesando = document.getElementById('procesando');
  var procesandoTexto = document.getElementById('procesando-texto');
  var error = document.getElementById('error-subida');
  var ocupado = false;

  function mostrarError(texto) {
    error.textContent = texto;
    error.hidden = false;
    procesando.classList.remove('visible');
    ocupado = false;
  }

  function leerExcel(archivo) {
    return new Promise(function (resolve, reject) {
      if (typeof XLSX === 'undefined') {
        reject(new Error('No se pudo cargar el lector de Excel. Recargá la página.'));
        return;
      }
      var lector = new FileReader();
      lector.onerror = function () { reject(new Error('No se pudo leer el archivo.')); };
      lector.onload = function () {
        try {
          var libro = XLSX.read(new Uint8Array(lector.result), { type: 'array' });
          var hoja = libro.Sheets[libro.SheetNames[0]];
          var filas = XLSX.utils.sheet_to_json(hoja, { header: 1, raw: true, defval: '' });
          // Quitar filas vacías del final
          filas = filas.filter(function (f) { return f.some(function (c) { return c !== '' && c !== null; }); });
          resolve(filas);
        } catch (e) {
          reject(new Error('El archivo no parece ser un Excel válido.'));
        }
      };
      lector.readAsArrayBuffer(archivo);
    });
  }

  function procesar(archivo) {
    if (!archivo || ocupado) return;
    ocupado = true;
    error.hidden = true;
    procesandoTexto.textContent = 'Leyendo ' + archivo.name + '…';
    procesando.classList.add('visible');

    leerExcel(archivo)
      .then(function (filas) {
        procesandoTexto.textContent = 'Comparando ' + filas.length + ' filas con los cupones… puede tardar unos segundos.';
        return fetch('api/subir.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf: window.CSRF, archivo: archivo.name, filas: filas })
        });
      })
      .then(function (resp) {
        return resp.json().catch(function () { throw new Error('El servidor no respondió bien. Probá de nuevo.'); });
      })
      .then(function (datos) {
        if (datos.error) throw new Error(datos.error);
        window.location.href = 'control.php?id=' + encodeURIComponent(datos.id) + (datos.aviso ? '&aviso=1' : '');
      })
      .catch(function (e) {
        mostrarError(e.message || 'Ocurrió un error.');
      })
      .finally(function () { input.value = ''; });
  }

  input.addEventListener('change', function () { procesar(input.files[0]); });

  ['dragenter', 'dragover'].forEach(function (ev) {
    zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('arrastrando'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('arrastrando'); });
  });
  zona.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) procesar(e.dataTransfer.files[0]);
  });
})();
