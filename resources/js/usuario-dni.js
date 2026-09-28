/**
 * usuario-dni.js — SIGA-COCIAP
 * «Nuevo usuario»: al completar el DNI consulta si la persona ya está registrada
 * (p. ej. como apoderado). Si existe, JALA sus datos registrados y los deja de
 * solo lectura; solo se pueden completar los que falten (decisión del usuario,
 * 28/09/2026). El servidor aplica la misma regla: esto es ayuda, no el control.
 */
(function () {
    var BASE = document.querySelector('meta[name="base-url"]')
        ? document.querySelector('meta[name="base-url"]').content
        : '';

    var dni   = document.getElementById('dni');
    var aviso = document.getElementById('dniAviso');
    var texto = document.getElementById('dniAvisoTexto');
    if (!dni || !aviso || !texto) return;

    var form    = dni.form;
    var enviar  = form.querySelector('[type="submit"]');
    var CAMPOS  = ['apellido_paterno', 'apellido_materno', 'nombres', 'correo', 'telefono'];
    var consultado = '';

    function campo(id) { return document.getElementById(id); }

    function mostrar(clase, mensaje) {
        texto.className   = 'alert ' + clase;
        texto.textContent = mensaje;
        aviso.hidden      = false;
    }

    // Vuelve el formulario a su estado normal (DNI nuevo o editado).
    function liberar() {
        aviso.hidden = true;
        enviar.disabled = false;
        CAMPOS.forEach(function (id) {
            var el = campo(id);
            if (el && el.dataset.jalado === '1') {
                el.value = '';
                el.readOnly = false;
                delete el.dataset.jalado;
            }
        });
        var sexo = campo('sexo');
        if (sexo && sexo.dataset.jalado === '1') {
            sexo.value = '';
            sexo.disabled = false;
            delete sexo.dataset.jalado;
        }
    }

    function jalar(p) {
        CAMPOS.forEach(function (id) {
            var el = campo(id);
            var valor = p[id];
            if (el && valor) {
                el.value = valor;
                el.readOnly = true;
                el.dataset.jalado = '1';
            }
        });
        // Un <select> no admite readonly: se deshabilita (no se envía) y el
        // servidor usa el sexo registrado. Si la persona no lo tiene, se elige.
        var sexo = campo('sexo');
        if (sexo && p.sexo) {
            sexo.value = p.sexo;
            sexo.disabled = true;
            sexo.dataset.jalado = '1';
        }
    }

    dni.addEventListener('input', function () {
        var valor = dni.value.trim();
        if (valor === consultado) return;
        if (consultado !== '') liberar();
        consultado = '';
        if (!/^\d{8}$/.test(valor)) return;

        consultado = valor;
        fetch(BASE + '/admin/usuarios/persona?dni=' + encodeURIComponent(valor), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (dni.value.trim() !== valor || !data.existe) return;

                if (data.tieneCuenta) {
                    mostrar('alert--error', 'Este DNI ya tiene una cuenta de usuario'
                        + (data.rolCuenta ? ' (' + data.rolCuenta + ')' : '')
                        + '. Búscala en la lista de usuarios para editarla.');
                    enviar.disabled = true;
                    return;
                }

                jalar(data.persona);
                mostrar('alert--info', 'Este DNI ya está registrado en SIGA-COCIAP como '
                    + data.origen + '. Se usarán sus datos registrados; solo puedes completar los que falten.');
            })
            .catch(function () { /* sin red: el servidor valida igual al guardar */ });
    });
})();
