/**
 * borrador.js — Autoguardado de formularios de carga manual (06/10/2026).
 *
 * Se activa en CADA <form data-borrador-tipo="…" data-borrador-revision="N">
 * de la página (puede haber varios: notas SIAGIE tiene uno por bimestre), con
 * sus ids de contexto en data-borrador-ctx-<campo> (p. ej. matricula,
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
 *   · Un formulario con `data-borrador-guardar-antes` (p. ej. «Traer
 *     competencias», que recarga la página) espera a que TODOS los borradores
 *     de la página queden guardados antes de enviarse: así el servidor ya los
 *     tiene cuando pinta la página nueva.
 *   · Mensajes al usuario genéricos: no se explica el flujo interno.
 */
(function () {
    'use strict';

    if (!window.fetch) return;
    var BASE  = (document.querySelector('meta[name="base-url"]') || {}).content || '';
    var CSRF  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var IGNORAR = { _csrf_token: true };

    function hora() {
        var d = new Date();
        return (d.getHours() < 10 ? '0' : '') + d.getHours() + ':'
             + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes();
    }

    var instancias = [];
    document.querySelectorAll('form[data-borrador-tipo]').forEach(function (f) {
        instancias.push(iniciar(f));
    });

    // Formularios que recargan la página sin ser el guardado oficial: primero
    // se guardan los borradores, después se envía. Si otro script ya canceló el
    // envío (su validación), no se hace nada.
    document.querySelectorAll('form[data-borrador-guardar-antes]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (e.defaultPrevented || instancias.length === 0) return;
            e.preventDefault();
            Promise.all(instancias.map(function (i) { return i.ahora(); }))
                .catch(function () { /* se envía igual: queda el último guardado */ })
                .then(function () { HTMLFormElement.prototype.submit.call(f); });
        });
    });

    function iniciar(form) {
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

        var temporizador = null;
        var ultimoGuardado = null;
        var detenido = false;     // tras enviar el formulario o tras un conflicto
        var enCurso  = false;
        var pendiente = false;
        var enVuelo  = Promise.resolve();

        function mostrar(texto, esError) {
            if (!estado) return;
            estado.textContent = texto;
            estado.classList.toggle('text-danger', !!esError);
            estado.classList.toggle('text-muted', !esError);
        }

        // Nombres con corchetes de PHP (nota[c12], asistencia[faltas]) → objeto
        // anidado, igual que los leería $_POST. `campo[]` (filas repetibles, como
        // las notas de origen) → lista en el orden de la página: sin esto cada
        // fila pisaba a la anterior.
        function serializar() {
            var datos = {};
            new FormData(form).forEach(function (valor, nombre) {
                if (IGNORAR[nombre] || typeof valor !== 'string') return;
                var lista = nombre.slice(-2) === '[]';
                var base  = lista ? nombre.slice(0, -2) : nombre;
                var partes = base.replace(/\]/g, '').split('[');
                var nodo = datos;
                for (var i = 0; i < partes.length - 1; i++) {
                    var p = partes[i];
                    if (typeof nodo[p] !== 'object' || nodo[p] === null) nodo[p] = {};
                    nodo = nodo[p];
                }
                var clave = partes[partes.length - 1];
                if (lista) {
                    if (!Array.isArray(nodo[clave])) nodo[clave] = [];
                    nodo[clave].push(valor);
                } else {
                    nodo[clave] = valor;
                }
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

        // Devuelve una promesa que se resuelve al terminar (con o sin éxito).
        function guardar() {
            if (detenido) return Promise.resolve();
            if (enCurso) { pendiente = true; return enVuelo; }
            var json = serializar();
            if (json === ultimoGuardado) return Promise.resolve();

            enCurso = true;
            enVuelo = fetch(BASE + '/borradores/guardar', { method: 'POST', body: cuerpo(json) })
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
            return enVuelo;
        }

        // Guardar YA (sin esperar el retraso), y esperar a que termine.
        function ahora() {
            clearTimeout(temporizador);
            return enCurso ? enVuelo.then(guardar) : guardar();
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

        return { ahora: ahora };
    }
})();
