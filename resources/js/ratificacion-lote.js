/**
 * Lote de documentos de RATIFICACIÓN de una sección (Fase 4, 15/10/2026).
 * Ver docs/modulos/ratificaciones.md.
 *
 * Patrón de `public/js/archivar-boletas.js`: html2pdf convierte cada
 * `.ratif-lote-item` (un documento A4 vigente) a PDF y JSZip los empaqueta en
 * un ZIP que se descarga solo. El nombre del ZIP viene en `data-zip-nombre`.
 */
(function () {
    'use strict';

    var items      = document.querySelectorAll('.ratif-lote-item');
    var total      = items.length;
    var elProgreso = document.getElementById('archivo-progreso');
    var elStatus   = document.getElementById('archivo-status');
    var elDetalle  = document.getElementById('archivo-detalle');
    var elBarra    = document.getElementById('archivo-barra');
    var elContador = document.getElementById('archivo-contador');
    var elIcono    = document.getElementById('archivo-icono');

    if (!elProgreso) {
        return;
    }
    var zipNombre = elProgreso.getAttribute('data-zip-nombre') || 'ratificacion.zip';

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

    async function generarTodo() {
        if (typeof JSZip === 'undefined' || typeof html2pdf === 'undefined') {
            setError('No se pudo preparar la descarga. Recarga la página.');
            return;
        }

        var zip = new JSZip();
        setProgreso(0);

        for (var i = 0; i < total; i++) {
            var item   = items[i];
            var nombre = item.dataset.nombreArchivo || ('documento_' + (i + 1));

            elStatus.textContent  = 'Generando PDF ' + (i + 1) + ' de ' + total + '...';
            elDetalle.textContent = nombre;
            setProgreso(i);

            try {
                var pdf = await html2pdf()
                    .set({
                        // Márgenes en CADA hoja (no en el ítem: solo darían la 1.ª y la última).
                        margin:      [15, 17.5, 15, 17.5],
                        filename:    nombre + '.pdf',
                        image:       { type: 'jpeg', quality: 0.95 },
                        html2canvas: { scale: 1.5, useCORS: true, logging: false },
                        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
                        // avoid-all: no parte un párrafo ni una tabla; css: respeta el salto de página.
                        pagebreak:   { mode: ['avoid-all', 'css', 'legacy'] }
                    })
                    .from(item)
                    .toPdf()
                    .get('pdf');

                // «Página X de Y» dentro del margen inferior de 15mm (pdf-paginar.js).
                paginarPdf(pdf, 286);
                var blob = pdf.output('blob');

                zip.file(nombre + '.pdf', blob);
            } catch (e) {
                console.error('Error en documento ' + nombre, e);
                setError('No se pudo generar el PDF de ' + nombre + '.');
                return;
            }
        }

        setProgreso(total);
        elStatus.textContent  = 'Comprimiendo ZIP...';
        elDetalle.textContent = '';

        try {
            var zipBlob = await zip.generateAsync({ type: 'blob', compression: 'DEFLATE' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(zipBlob);
            a.download = zipNombre;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);

            elIcono.textContent = '✅';
            elStatus.textContent = 'Listo. Descargando ' + zipNombre;
            elProgreso.classList.add('archivo-progreso--done');
        } catch (e) {
            console.error('Error al generar el ZIP', e);
            setError('No se pudo comprimir el ZIP.');
        }
    }

    // Los botones del layout print (Cerrar / Imprimir) no aplican aquí.
    var acciones = document.querySelector('.boleta-acciones');
    if (acciones) {
        acciones.style.display = 'none';
    }

    // Esperar imágenes (logo, firma, sello, QR) para que html2canvas las capture.
    if (document.readyState === 'complete') {
        generarTodo();
    } else {
        window.addEventListener('load', generarTodo);
    }
})();
