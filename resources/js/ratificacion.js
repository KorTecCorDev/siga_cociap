/**
 * Ratificación de matrícula (migración 080, 14/10/2026).
 * Ver docs/modulos/ratificaciones.md.
 *
 *  1. Fila de decisión ([data-ratif-form]): el motivo y el detalle solo se ven
 *     con «No ratificado», y entonces el motivo es obligatorio. El servidor
 *     valida lo mismo.
 *  2. Con documento emitido ([data-confirm-cambios]) el confirm() lista cada
 *     campo que cambia, antes → después, como en matriculas.js. Al aceptar se
 *     marca `confirmar_anulacion`, que el servidor exige para ese cambio. Sin
 *     cambios, envía sin preguntar.
 *  3. [data-confirm]: confirmación simple (retirar un motivo).
 */
(function () {
    'use strict';

    function esNoRatificado(form) {
        return form.elements.decision && form.elements.decision.value === 'no_ratificado';
    }

    function ajustarFila(form) {
        var noRatif = esNoRatificado(form);
        var motivo  = form.querySelector('[data-ratif-motivo]');
        var detalle = form.querySelector('[data-ratif-detalle]');
        if (motivo) {
            motivo.hidden   = !noRatif;
            motivo.required = noRatif;
        }
        if (detalle) {
            detalle.hidden = !noRatif;
        }
    }

    /** Texto visible de un control: el de la opción en un select. */
    function texto(el, inicial) {
        if (el.tagName === 'SELECT') {
            var opciones = Array.prototype.slice.call(el.options);
            var op = inicial
                ? opciones.filter(function (o) { return o.defaultSelected; })[0]
                : el.options[el.selectedIndex];
            return op && op.value !== '' ? op.text.trim() : '';
        }
        return (inicial ? el.defaultValue : el.value).trim().replace(/\s+/g, ' ');
    }

    function decisionInicial(form) {
        var op = Array.prototype.slice.call(form.elements.decision.options)
            .filter(function (o) { return o.defaultSelected; })[0];
        return op ? op.value : '';
    }

    function cambios(form) {
        var antesNoRatif   = decisionInicial(form) === 'no_ratificado';
        var despuesNoRatif = esNoRatificado(form);
        var lista = [];
        Array.prototype.forEach.call(form.querySelectorAll('[data-campo]'), function (el) {
            var esDeMotivo = el.name !== 'decision';
            // Motivo y detalle solo existen para un no ratificado.
            var antes   = esDeMotivo && !antesNoRatif   ? '' : texto(el, true);
            var despues = esDeMotivo && !despuesNoRatif ? '' : texto(el, false);
            if (antes !== despues) {
                lista.push('• ' + el.dataset.campo + ': ' + (antes || '(vacío)') + ' → ' + (despues || '(vacío)'));
            }
        });
        return lista;
    }

    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (form && form.hasAttribute('data-ratif-form') && e.target.name === 'decision') {
            ajustarFila(form);
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute) {
            return;
        }

        if (form.hasAttribute('data-confirm')) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
            return;
        }

        if (form.hasAttribute('data-ratif-form') && form.hasAttribute('data-confirm-cambios')) {
            var lista = cambios(form);
            if (lista.length === 0) {
                return;
            }
            var mensaje = [form.getAttribute('data-confirm-cambios'), ''].concat(lista).join('\n');
            if (!window.confirm(mensaje)) {
                e.preventDefault();
                return;
            }
            form.elements.confirmar_anulacion.value = '1';
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ratif-form]'), ajustarFila);
    });
})();
