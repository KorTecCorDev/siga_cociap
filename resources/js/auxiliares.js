/**
 * auxiliares.js — SIGA-COCIAP
 * Asignación de secciones a los auxiliares académicos (/admin/auxiliares).
 * Mismo esquema que secciones.js (modal + envío AJAX + recarga).
 */

(function () {
    var BASE = document.querySelector('meta[name="base-url"]')
        ? document.querySelector('meta[name="base-url"]').content
        : '';

    var form = document.getElementById('formAuxiliar');
    if (!form) return;

    var datos = document.getElementById('modalAuxiliarData');
    var auxiliares = datos ? JSON.parse(datos.dataset.auxiliares || '[]') : [];
    var seccionAbierta = 0;

    // Aviso (no bloqueo) si el auxiliar elegido tiene un hijo/a en la sección.
    function _avisoHijo() {
        var sel   = document.getElementById('auxiliar_id');
        var aviso = document.getElementById('modalAuxAvisoHijo');
        if (!sel || !aviso) return;
        var elegido = auxiliares.filter(function (a) { return String(a.id) === sel.value; })[0];
        aviso.hidden = !(elegido && (elegido.seccionesHijo || []).indexOf(seccionAbierta) !== -1);
    }
    document.getElementById('auxiliar_id').addEventListener('change', _avisoHijo);

    // ── Abrir el modal desde el botón de cada fila ───────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-abrir-auxiliar]');
        if (!btn || btn.disabled) return;

        var seccionId = btn.getAttribute('data-seccion-id');
        var actualId  = btn.getAttribute('data-auxiliar-id') || '';
        var nivel     = btn.getAttribute('data-nivel') || '';
        if (actualId === '0') actualId = '';

        document.querySelector('#modalAuxiliar .modal-title').textContent =
            actualId ? 'Cambiar auxiliar' : 'Asignar auxiliar';
        document.getElementById('modalAuxSeccionLabel').textContent = btn.getAttribute('data-label') || '';

        var badge = document.getElementById('modalAuxNivelBadge');
        badge.textContent = nivel;
        badge.className   = 'modal-nivel-badge modal-nivel-badge--' +
            (nivel.toLowerCase().indexOf('prim') !== -1 ? 'primaria' : 'secundaria');

        form.action = BASE + '/admin/auxiliares/' + seccionId + '/asignar';

        // A diferencia del tutor, un auxiliar puede tener VARIAS secciones:
        // se listan todos, sin filtrar por disponibilidad.
        var sel = document.getElementById('auxiliar_id');
        sel.innerHTML = '<option value="">— Sin auxiliar —</option>';
        auxiliares.forEach(function (a) {
            var opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.nombre + ' (' + a.dni + ')' + (String(a.id) === actualId ? ' (actual)' : '');
            sel.appendChild(opt);
        });
        sel.value = actualId;
        seccionAbierta = Number(seccionId);
        _avisoHijo();

        _feedback('', '');
        var enviar = form.querySelector('[type="submit"]');
        enviar.disabled    = false;
        enviar.textContent = 'Guardar';

        Modal.abrir('modalAuxiliar');
    });

    // ── Envío AJAX ───────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var sel    = document.getElementById('auxiliar_id');
        var enviar = form.querySelector('[type="submit"]');

        if (sel.value === '' &&
            !confirm('¿Confirmas que la sección quedará sin auxiliar desde este bimestre?')) {
            return;
        }

        enviar.disabled    = true;
        enviar.textContent = 'Guardando…';
        _feedback('Guardando...', 'loading');

        fetch(form.action, { method: 'POST', body: new FormData(form) })
            .then(function (res) {
                return res.json().catch(function () { throw new Error('HTTP ' + res.status); });
            })
            .then(function (data) {
                if (data.success) {
                    Modal.cerrar('modalAuxiliar');
                    window.location.reload();
                    return;
                }
                _feedback(data.mensaje || 'No se pudo guardar.', 'error');
                enviar.disabled    = false;
                enviar.textContent = 'Guardar';
            })
            .catch(function () {
                _feedback('Error de conexión. Intenta de nuevo.', 'error');
                enviar.disabled    = false;
                enviar.textContent = 'Guardar';
            });
    });

    function _feedback(texto, tipo) {
        var el = document.getElementById('modalAuxFeedback');
        if (!el) return;
        el.textContent = texto;
        el.className   = tipo ? 'modal-feedback modal-feedback--' + tipo : 'modal-feedback';
    }
})();
