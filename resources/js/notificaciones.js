/**
 * notificaciones.js — SIGA-COCIAP
 * Bandeja /notificaciones: marcar como leída sin recargar la página.
 * Comunicado /notificaciones/comunicado: validación en cliente de los destinos
 * (el servidor valida lo mismo; esto solo evita el viaje de ida y vuelta).
 *
 * El servidor devuelve el contador actualizado, así que la campana de la barra
 * se sincroniza con la misma respuesta: no hay dos fuentes de verdad.
 */
(function () {
    var form = document.querySelector('[data-comunicado-form]');
    if (!form) return;

    var error   = form.querySelector('[data-comunicado-error]');
    var seccion = form.querySelector('#seccion_id');

    function mostrarError(texto) {
        if (!error) return;
        error.textContent = texto;
        error.hidden = texto === '';
    }

    form.addEventListener('submit', function (ev) {
        var marcadas = form.querySelectorAll('input[name="destinos[]"]:checked');
        var conSeccion = form.querySelector('input[name="destinos[]"][value="seccion"]:checked');

        if (marcadas.length === 0) {
            ev.preventDefault();
            mostrarError('Elige a quién va dirigido el comunicado.');
            return;
        }
        if (conSeccion && seccion && seccion.value === '') {
            ev.preventDefault();
            mostrarError('Elige la sección a la que va el comunicado.');
            seccion.focus();
            return;
        }
        mostrarError('');
    });
})();

(function () {
    var lista = document.querySelector('.notif-lista');
    if (!lista) return;

    var meta  = document.querySelector('meta[name="csrf-token"]');
    var base  = document.querySelector('meta[name="base-url"]');
    if (!meta || !base) return;

    var token   = meta.getAttribute('content');
    var urlLeer = base.getAttribute('content') + '/notificaciones/leer';

    /** Refleja en la campana el contador que acaba de devolver el servidor. */
    function pintarCampana(noLeidas) {
        var campana = document.querySelector('.navbar__notif');
        if (!campana) return;

        var badge = campana.querySelector('.navbar__notif-badge');
        if (noLeidas > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'navbar__notif-badge';
                campana.appendChild(badge);
            }
            badge.textContent = noLeidas > 99 ? '99+' : String(noLeidas);
            campana.classList.add('navbar__notif--pendientes');
        } else {
            if (badge) badge.remove();
            campana.classList.remove('navbar__notif--pendientes');
        }
    }

    /**
     * Refleja el mismo contador en la cabecera de la bandeja: el subtítulo y
     * «Marcar todas». Sin esto la campana bajaba y la página seguía diciendo
     * «Tienes N sin leer». Mismo texto que pinta la vista en PHP.
     */
    function pintarResumen(noLeidas) {
        var resumen = document.querySelector('[data-notif-resumen]');
        if (resumen) {
            resumen.textContent = '';
            if (noLeidas > 0) {
                var n = document.createElement('strong');
                n.textContent = String(noLeidas);
                resumen.appendChild(document.createTextNode('Tienes '));
                resumen.appendChild(n);
                resumen.appendChild(document.createTextNode(noLeidas === 1
                    ? ' notificación sin leer.'
                    : ' notificaciones sin leer.'));
            } else {
                resumen.textContent = 'No tienes notificaciones sin leer.';
            }
        }

        if (noLeidas === 0) {
            var todas = document.querySelector('[data-notif-leer-todas]');
            if (todas) todas.remove();
        }
    }

    function marcarLeida(item, boton) {
        var id = item.dataset.notifId;
        if (!id) return;

        var datos = new FormData();
        datos.append('id', id);
        datos.append('_csrf_token', token);

        fetch(urlLeer, {
            method: 'POST',
            body: datos,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            // «Ver detalle» navega en el mismo clic: sin keepalive el navegador
            // puede cancelar la petición al salir y la notificación quedaría
            // sin leer.
            keepalive: true
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.success !== true) return;
                item.classList.remove('notif--sin-leer');
                var punto = item.querySelector('.notif__punto');
                if (punto) punto.remove();
                if (boton) boton.remove();
                pintarCampana(res.noLeidas || 0);
                pintarResumen(res.noLeidas || 0);
            })
            .catch(function () { /* silencioso: marcar leída no es crítico */ });
    }

    lista.addEventListener('click', function (ev) {
        var item = ev.target.closest('.notif');
        if (!item) return;

        // Botón explícito "Marcar como leída".
        var boton = ev.target.closest('[data-notif-leer]');
        if (boton) {
            marcarLeida(item, boton);
            return;
        }

        // Abrir el detalle también la da por leída (el enlace sigue su curso).
        if (ev.target.closest('[data-notif-abrir]') && item.classList.contains('notif--sin-leer')) {
            marcarLeida(item, item.querySelector('[data-notif-leer]'));
        }
    });
})();
