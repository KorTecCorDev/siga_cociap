(function () {
    'use strict';

    var items    = document.querySelectorAll('.boleta-archivo-item');
    var total    = items.length;
    var elStatus   = document.getElementById('archivo-status');
    var elDetalle  = document.getElementById('archivo-detalle');
    var elBarra    = document.getElementById('archivo-barra');
    var elContador = document.getElementById('archivo-contador');
    var elIcono    = document.getElementById('archivo-icono');
    var elProgreso = document.getElementById('archivo-progreso');
    var zipNombre  = typeof ARCHIVO_ZIP_NOMBRE !== 'undefined'
                     ? ARCHIVO_ZIP_NOMBRE
                     : 'boletas.zip';

    function setProgreso(procesados) {
        var pct = total > 0 ? Math.round((procesados / total) * 100) : 0;
        elBarra.style.width = pct + '%';
        elContador.textContent = procesados + ' / ' + total;
    }

    function setError(msg) {
        elIcono.textContent = '❌';
        elStatus.textContent = msg;
        elProgreso.classList.add('archivo-progreso--error');
    }

    async function archivarTodo() {
        if (!total) {
            elStatus.textContent = 'No hay boletas para archivar.';
            return;
        }

        if (typeof JSZip === 'undefined' || typeof html2pdf === 'undefined') {
            setError('Error: librerías no cargadas. Recarga la página.');
            return;
        }

        var zip = new JSZip();
        setProgreso(0);

        for (var i = 0; i < total; i++) {
            var item        = items[i];
            var nombreArch  = item.dataset.nombreArchivo || ('boleta_' + (i + 1));
            var carpeta     = item.dataset.carpeta       || 'Sin_seccion';

            elStatus.textContent  = 'Generando PDF ' + (i + 1) + ' de ' + total + '...';
            elDetalle.textContent = nombreArch;
            setProgreso(i);

            try {
                var blob = await html2pdf()
                    .set({
                        margin:      [5, 5, 5, 5],
                        filename:    nombreArch + '.pdf',
                        image:       { type: 'jpeg', quality: 0.95 },
                        html2canvas: { scale: 1.5, useCORS: true, logging: false },
                        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
                        pagebreak:   { mode: 'avoid-all' }
                    })
                    .from(item)
                    .outputPdf('blob');

                zip.folder(carpeta).file(nombreArch + '.pdf', blob);
            } catch (e) {
                console.error('Error en boleta ' + nombreArch, e);
                setError('Error al generar "' + nombreArch + '". Revisa la consola.');
                return;
            }
        }

        setProgreso(total);
        elStatus.textContent  = 'Comprimiendo ZIP...';
        elDetalle.textContent = '';

        try {
            var zipBlob = await zip.generateAsync({ type: 'blob', compression: 'DEFLATE' });

            var a    = document.createElement('a');
            a.href   = URL.createObjectURL(zipBlob);
            a.download = zipNombre;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);

            elIcono.textContent = '✅';
            elStatus.textContent = '¡Listo! Descargando ' + zipNombre;
            elProgreso.classList.add('archivo-progreso--done');
        } catch (e) {
            console.error('Error al generar ZIP', e);
            setError('Error al comprimir el ZIP. Revisa la consola.');
        }
    }

    // Los botones del layout print (Volver / Imprimir) no aplican aquí
    var layoutAcciones = document.querySelector('.boleta-acciones');
    if (layoutAcciones) layoutAcciones.style.display = 'none';

    // Esperar a que imágenes (logo, firma del director) estén cargadas
    // para que html2canvas las capture correctamente
    if (document.readyState === 'complete') {
        archivarTodo();
    } else {
        window.addEventListener('load', archivarTodo);
    }
})();
