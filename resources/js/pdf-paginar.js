/**
 * pdf-paginar.js — «Página X de Y» en cada hoja de un PDF de html2pdf (18/10/2026).
 *
 * Los PDF de los ZIP (boletas archivadas, lote de ratificaciones) no pasan por
 * @page, así que no ven el pie de `base/_paginacion.scss`. Este helper lo
 * estampa con jsPDF sobre el PDF ya generado, en el MARGEN inferior: el
 * contenido no se toca. Cada PDF se numera por sí solo (1 de N del estudiante).
 * Ver docs/modulos/ui.md § «Numeración de páginas al imprimir».
 *
 * Uso: var pdf = await html2pdf().set(...).from(el).toPdf().get('pdf');
 *      paginarPdf(pdf, yMm);
 */
(function () {
    'use strict';

    /**
     * @param {object} pdf  instancia jsPDF (unidad mm)
     * @param {number} yMm  línea base del texto, medida desde el borde superior
     */
    window.paginarPdf = function (pdf, yMm) {
        var total = pdf.internal.getNumberOfPages();
        var ancho = pdf.internal.pageSize.getWidth();

        for (var i = 1; i <= total; i++) {
            pdf.setPage(i);
            pdf.setFont('helvetica', 'normal');
            pdf.setFontSize(7);
            pdf.setTextColor(68, 68, 68);
            pdf.text('Página ' + i + ' de ' + total, ancho / 2, yMm, { align: 'center' });
        }
    };
})();
