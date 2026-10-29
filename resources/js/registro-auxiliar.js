/**
 * Registro auxiliar de calificaciones — página de selección (27/10/2026).
 * - «Imprimir seleccionadas» se habilita solo con al menos una card marcada.
 *   El servidor valida lo mismo: sin cargas propias elegidas responde 404.
 * - «Marcar todas» / «Desmarcar todas» en la cabecera de cada grupo.
 */
(function () {
    const form = document.getElementById('formRegistroAuxiliar');
    if (!form) return;

    const boton    = document.getElementById('btnRaSeleccion');
    const casillas = form.querySelectorAll('[data-ra-casilla]');
    const grupos   = form.querySelectorAll('[data-ra-grupo]');

    function actualizarGrupo(grupo) {
        const atajo = grupo.querySelector('[data-ra-marcar-grupo]');
        const delGrupo = grupo.querySelectorAll('[data-ra-casilla]');
        const todas = delGrupo.length > 0 && Array.prototype.every.call(delGrupo, (c) => c.checked);
        atajo.textContent = todas ? 'Desmarcar todas' : 'Marcar todas';
        atajo.setAttribute('aria-pressed', todas ? 'true' : 'false');
    }

    function actualizar() {
        boton.disabled = !Array.prototype.some.call(casillas, (c) => c.checked);
        grupos.forEach(actualizarGrupo);
    }

    grupos.forEach((grupo) => {
        grupo.querySelector('[data-ra-marcar-grupo]').addEventListener('click', () => {
            const delGrupo = grupo.querySelectorAll('[data-ra-casilla]');
            const marcar = !Array.prototype.every.call(delGrupo, (c) => c.checked);
            delGrupo.forEach((c) => { c.checked = marcar; });
            actualizar();
        });
    });

    casillas.forEach((c) => c.addEventListener('change', actualizar));
    // Al volver con «Atrás» el navegador puede restaurar las marcas.
    window.addEventListener('pageshow', actualizar);
    actualizar();
})();
