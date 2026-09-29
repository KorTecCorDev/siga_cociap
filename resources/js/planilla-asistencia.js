/**
 * Planilla de asistencia manual — página de selección (28/09/2026).
 * El mes solo se elige en el modo «con fechas»; si la sección supera las filas
 * del Excel, se avisa y se desactiva ese botón. El servidor valida lo mismo.
 */
(function () {
    const form = document.getElementById('formPlanilla');
    if (!form) return;

    const seccion  = document.getElementById('seccion');
    const modo     = document.getElementById('modo');
    const mes      = document.getElementById('mes');
    const aviso    = document.getElementById('planillaAviso');
    const btnExcel = document.getElementById('btnExcel');
    const limite   = parseInt(form.dataset.filasExcel, 10);

    function actualizar() {
        const conFechas = modo.value === 'mes';
        mes.disabled = !conFechas;
        mes.required = conFechas;

        const opcion = seccion.options[seccion.selectedIndex];
        const n = opcion ? parseInt(opcion.dataset.estudiantes || '0', 10) : 0;
        const excede = n > limite;
        aviso.hidden = !excede;
        btnExcel.disabled = excede;
    }

    modo.addEventListener('change', actualizar);
    seccion.addEventListener('change', actualizar);
    actualizar();
})();
