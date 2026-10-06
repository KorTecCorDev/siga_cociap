/**
 * borrador.js — Autoguardado de formularios de carga manual (06/10/2026).
 *
 * Se activa en un <form data-borrador-tipo="…" data-borrador-revision="N">
 * con sus ids de contexto en data-borrador-ctx-<campo> (p. ej. matricula,
 * periodo). Guarda lo tecleado en POST /borradores/guardar.
 *
 * 🔴 El borrador NUNCA es el registro oficial: lo oficial lo escribe el POST
 * del formulario, con sus validaciones. La RESTAURACIÓN no la hace este
 * script: la pinta el servidor en los campos (escapada), igual que tras un
 * error de validación.
 *
 *   · Guarda ~1,5 s después de dejar de teclear, al salir de un campo y al
 *     abandonar la página. Solo si algo cambió.
 *   · Al ENVIAR el formulario deja de guardar: si no, el guardado de salida
 *     podría recrear el borrador que el POST oficial acaba de eliminar.
 *   · 409 = el formulario se cambió en otra pestaña o equipo: deja de guardar
 *     para no pisarlo.
 *   · Mensajes al usuario genéricos: no se explica el flujo interno.
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-borrador-tipo]');
    if (!form || !window.fetch) return;

    var BASE  = (document.querySelector('meta[name="base-url"]') || {}).content || '';
    var CSRF  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var tipo  = form.dataset.borradorTipo;
    var revision = parseInt(form.dataset.borradorRevision || '0', 10) || 0;
    var estado   = form.querySelector('[data-borrador-estado]');

    // Ids de contexto: data-borrador-ctx-matricula → matricula, etc.
    var contexto = {};
    Object.keys(form.dataset).forEach(function (k) {
        if (k.indexOf('borradorCtx') === 0 && k.length > 11) {
            contexto[k.charAt(11).toLowerCase() + k.slice(12)] = form.dataset[k];
        }
    });

    var IGNORAR = { _csrf_token: true };
    var temporizador = null;
    var ultimoGuardado = null;
    var detenido = false;     // tras enviar el formulario o tras un conflicto
    var enCurso  = false;
    var pendiente = false;

    function mostrar(texto, esError) {
        if (!estado) return;
        estado.textContent = texto;
        estado.classList.toggle('text-danger', !!esError);
        estado.classList.toggle('text-muted', !esError);
    }

    // Nombres con corchetes de PHP (nota[c12], asistencia[faltas]) → objeto
    // anidado, igual que los leería $_POST.
    function serializar() {
        var datos = {};
        new FormData(form).forEach(function (valor, nombre) {
            if (IGNORAR[nombre] || typeof valor !== 'string') return;
            var partes = nombre.replace(/\]/g, '').split('[');
            var nodo = datos;
            for (var i = 0; i < partes.length - 1; i++) {
                var p = partes[i];
                if (typeof nodo[p] !== 'object' || nodo[p] === null) nodo[p] = {};
                nodo = nodo[p];
            }
            nodo[partes[partes.length - 1]] = valor;
        });
        return JSON.stringify(datos);
    }

    function cuerpo(json) {
        var fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('tipo', tipo);
        fd.append('revision', String(revision));
        fd.append('datos', json);
        Object.keys(contexto).forEach(function (k) { fd.append(k, contexto[k]); });
        return fd;
    }

    function hora() {
        var d = new Date();
        return (d.getHours() < 10 ? '0' : '') + d.getHours() + ':'
             + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes();
    }

    function guardar() {
        if (detenido) return;
        if (enCurso) { pendiente = true; return; }
        var json = serializar();
        if (json === ultimoGuardado) return;

        enCurso = true;
        fetch(BASE + '/borradores/guardar', { method: 'POST', body: cuerpo(json) })
            .then(function (res) {
                if (res.redirected) throw { codigo: 401 };
                return res.json().catch(function () { return {}; }).then(function (data) {
                    if (res.status === 409) throw { codigo: 409 };
                    if (!res.ok || !data.success) throw { codigo: res.status };
                    return data;
                });
            })
            .then(function (data) {
                revision = parseInt(data.revision, 10) || revision;
                ultimoGuardado = json;
                mostrar('Borrador guardado ' + hora());
            })
            .catch(function (err) {
                if (err && err.codigo === 409) {
                    detenido = true;
                    mostrar('⚠ Este formulario se modificó en otra ventana. Recarga la página para continuar.', true);
                    return;
                }
                mostrar('⚠ No se pudo guardar el borrador.', true);
            })
            .then(function () {
                enCurso = false;
                if (pendiente) { pendiente = false; programar(); }
            });
    }

    function programar() {
        if (detenido) return;
        clearTimeout(temporizador);
        temporizador = setTimeout(guardar, 1500);
    }

    form.addEventListener('input', programar);
    form.addEventListener('change', programar);
    form.addEventListener('focusout', function () {
        clearTimeout(temporizador);
        guardar();
    });

    // El POST oficial elimina el borrador al guardar con éxito: a partir de aquí
    // no se escribe nada más (ni siquiera el guardado de salida).
    form.addEventListener('submit', function () {
        detenido = true;
        clearTimeout(temporizador);
    });

    // Al abandonar la página, lo último tecleado (keepalive sobrevive a la salida).
    window.addEventListener('pagehide', function () {
        if (detenido) return;
        var json = serializar();
        if (json === ultimoGuardado) return;
        try {
            fetch(BASE + '/borradores/guardar', { method: 'POST', body: cuerpo(json), keepalive: true });
        } catch (e) { /* el navegador lo descarta: queda el último guardado */ }
    });

    // Punto de partida: lo que la página trae pintado (borrador restaurado o
    // vacío) no se reenvía si nadie lo toca.
    ultimoGuardado = serializar();
})();
