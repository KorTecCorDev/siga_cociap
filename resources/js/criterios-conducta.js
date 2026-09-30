/**
 * Criterios de conducta por año (F6, 28/09/2026).
 * Pide confirmación en los formularios marcados con [data-confirm] (retirar un
 * criterio), como anio-academico.js. El servidor valida las reglas igual.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute || !form.hasAttribute('data-confirm')) {
            return;
        }
        if (!window.confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });
})();
