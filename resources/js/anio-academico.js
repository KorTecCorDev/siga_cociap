/**
 * anio-academico.js — Año académico y bimestres
 * - Rellena y abre el modal de edición de fechas de un bimestre.
 * - Pide confirmación en las acciones marcadas con [data-confirm].
 * - Abre automáticamente el modal de indicadores tras cerrar un bimestre.
 *
 * Nota: este script se carga antes que app.js, que define window.Modal.
 * Por eso solo se usa Modal dentro de handlers (clic / DOMContentLoaded),
 * que se ejecutan después de que app.js ya cargó.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="base-url"]');
    var BASE = meta ? meta.content : '';

    /** Abre el modal de edición de fechas con los datos del bimestre. */
    window.abrirModalFechas = function (btn) {
        var id     = btn.getAttribute('data-periodo-id');
        var nombre = btn.getAttribute('data-nombre') || 'bimestre';
        var inicio = btn.getAttribute('data-inicio') || '';
        var fin    = btn.getAttribute('data-fin') || '';
        var limite = btn.getAttribute('data-limite') || '';

        document.getElementById('modalFechasTitulo').textContent = 'Editar fechas — ' + nombre;
        document.getElementById('formFechas').action = BASE + '/director/periodos/' + id + '/editar';
        document.getElementById('fecha_inicio').value = inicio;
        document.getElementById('fecha_fin').value     = fin;
        document.getElementById('limite_notas').value  = limite;
        // Fecha de los auxiliares (migración 078): vacía = la de notas.
        var limiteAux = document.getElementById('limite_auxiliares');
        if (limiteAux) {
            limiteAux.value = btn.getAttribute('data-limite-aux') || '';
        }

        if (window.Modal) {
            window.Modal.abrir('modalFechas');
        }
    };

    /** Abre el modal de reapertura con el motivo obligatorio. */
    window.abrirModalReabrir = function (btn) {
        var id     = btn.getAttribute('data-periodo-id');
        var nombre = btn.getAttribute('data-nombre') || 'bimestre';

        document.getElementById('modalReabrirTitulo').textContent = 'Reabrir ' + nombre;
        document.getElementById('formReabrir').action = BASE + '/director/periodos/' + id + '/reabrir';
        document.getElementById('motivo').value = '';

        if (window.Modal) {
            window.Modal.abrir('modalReabrir');
        }
    };

    // Confirmación para formularios de acción (activar/cerrar/abrir).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute || !form.hasAttribute('data-confirm')) {
            return;
        }
        if (!window.confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });

    // Abre el modal de indicadores automáticamente al volver de un cierre.
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.querySelector('.modal-overlay[data-autoabrir="1"]');
        if (modal && window.Modal) {
            window.Modal.abrir(modal.id);
        }
    });
})();
