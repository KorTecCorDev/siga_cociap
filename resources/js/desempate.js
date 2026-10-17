/**
 * Resolución de un empate del orden de mérito (17/10/2026).
 * Ver docs/modulos/orden-merito.md, "Expediente del desempate".
 *
 * La pantalla tiene UN SOLO formulario, en el modal `#modalDesempate`, para
 * todos los grupos empatados. Cada botón "Resolver este empate" lleva en
 * `data-alumnos` los estudiantes de su grupo; al abrir, aquí se pintan las
 * filas de orden (con textContent, nunca HTML) y se abre con `Modal` (app.js).
 * Cerrar el modal descarta lo marcado (`Modal.cerrar` hace form.reset()).
 *
 * Validación en cliente de los criterios aplicados: al menos uno marcado. El
 * servidor lo vuelve a validar (`OrdenMeritoController::guardarDesempate`);
 * esto solo evita el viaje de ida y vuelta. Se valida en el CLIC del botón
 * Guardar y no en `submit`: app.js deshabilita el botón ("Guardando…") en la
 * fase de captura del submit, antes de que este archivo pudiera cancelarlo, y
 * el botón quedaba bloqueado. Enter en un campo también dispara ese clic.
 */
(function () {
    'use strict';

    var MODAL = 'modalDesempate';

    // `window.Modal` se lee al ABRIR, no aquí: las vistas cargan su script antes
    // que app.js, que es quien lo define (mismo patrón que anio-academico.js).
    var overlay = document.getElementById(MODAL);
    if (!overlay) {
        return;
    }

    var form     = overlay.querySelector('[data-desempate-form]');
    var filas    = overlay.querySelector('[data-desempate-filas]');
    var titulo   = document.getElementById('modalDesempateTitulo');
    var guardar  = overlay.querySelector('[data-desempate-guardar]');
    var casillas = form.querySelectorAll('[data-criterio]');
    var error    = form.querySelector('[data-criterio-error]');

    function hayMarcado() {
        return Array.prototype.some.call(casillas, function (c) { return c.checked; });
    }

    function pintarFilas(alumnos) {
        while (filas.firstChild) {
            filas.removeChild(filas.firstChild);
        }
        alumnos.forEach(function (a, i) {
            var tr      = document.createElement('tr');
            var tdOrden = document.createElement('td');
            var tdNom   = document.createElement('td');
            var input   = document.createElement('input');

            tdOrden.className = 'col-puesto text-center';
            tdNom.className   = 'col-nombre';

            input.type      = 'number';
            input.name      = 'orden[' + parseInt(a.mid, 10) + ']';
            input.className = 'input-orden';
            input.min       = '1';
            input.max       = String(alumnos.length);
            input.value     = String(i + 1);
            input.required  = true;
            input.setAttribute('aria-label', 'Orden de ' + a.nombre);

            tdNom.textContent = a.nombre;
            tdOrden.appendChild(input);
            tr.appendChild(tdOrden);
            tr.appendChild(tdNom);
            filas.appendChild(tr);
        });
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-desempate-abrir]');
        if (!btn) {
            return;
        }
        var alumnos;
        try {
            alumnos = JSON.parse(btn.getAttribute('data-alumnos') || '[]');
        } catch (e) {
            alumnos = [];
        }
        if (!alumnos.length || !window.Modal) {
            return;
        }
        pintarFilas(alumnos);
        titulo.textContent = 'Resolver empate · ' + (btn.getAttribute('data-grupo-titulo') || '');
        if (error) {
            error.hidden = true;
        }
        if (guardar) {
            guardar.disabled = false;
            guardar.textContent = 'Guardar resolución';
        }
        window.Modal.abrir(MODAL);
    });

    Array.prototype.forEach.call(casillas, function (c) {
        c.addEventListener('change', function () {
            if (error && hayMarcado()) {
                error.hidden = true;
            }
        });
    });

    if (guardar) {
        guardar.addEventListener('click', function (ev) {
            if (!hayMarcado()) {
                ev.preventDefault();
                if (error) {
                    error.hidden = false;
                }
                if (casillas[0]) {
                    casillas[0].focus();
                }
            }
        });
    }
})();
