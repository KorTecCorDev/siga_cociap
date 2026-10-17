/**
 * Editor de plantillas de ratificación por bloques (migración 080, 14/10/2026).
 * Ver docs/modulos/ratificaciones.md.
 *
 *  1. Agregar ([data-agregar]) clona el <template> del tipo y reemplaza la
 *     clave __K__; subir/bajar/quitar mueven el bloque en el DOM (el orden del
 *     formulario es el orden del documento).
 *  2. Tabla ([data-tabla-editor]): filas y columnas de celdas sin nombre; al
 *     enviar se serializa a JSON en el oculto [data-tabla-json].
 *  3. Imagen: vista previa local y aviso si no es PNG/JPG o pasa de 2 MB.
 *  El servidor valida todo lo mismo (RatificacionPlantillaModel).
 */
(function () {
    'use strict';

    var IMAGEN_MAX = 2 * 1024 * 1024;

    var form = document.querySelector('[data-ratif-editor]');
    if (!form) {
        return;
    }
    var contenedor = form.querySelector('[data-bloques]');
    var maxBloques = parseInt(form.getAttribute('data-max-bloques'), 10) || 40;
    var siguiente  = 0;

    function bloques() {
        return Array.prototype.slice.call(contenedor.querySelectorAll('[data-bloque]'));
    }

    // ── 1. Agregar, mover, quitar ──────────────────────────────────────────
    function agregar(tipo) {
        if (bloques().length >= maxBloques) {
            return;
        }
        var tpl = document.querySelector('template[data-plantilla-bloque="' + tipo + '"]');
        if (!tpl) {
            return;
        }
        siguiente++;
        var envoltura = document.createElement('div');
        envoltura.innerHTML = tpl.innerHTML.replace(/__K__/g, 'n' + siguiente);
        var nuevo = envoltura.querySelector('[data-bloque]');
        contenedor.appendChild(nuevo);
        var foco = nuevo.querySelector('textarea, input[type="text"], input[type="file"]');
        if (foco) {
            foco.focus();
        }
        nuevo.scrollIntoView({ block: 'nearest' });
    }

    form.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button');
        if (!btn || !form.contains(btn)) {
            return;
        }
        if (btn.hasAttribute('data-agregar')) {
            agregar(btn.getAttribute('data-agregar'));
            return;
        }
        var bloque = btn.closest('[data-bloque]');
        if (!bloque) {
            return;
        }
        if (btn.hasAttribute('data-quitar')) {
            // El documento lleva al menos un bloque (lo exige también el servidor):
            // para cambiarlo, primero se agrega el nuevo y luego se quita este.
            if (bloques().length > 1) {
                bloque.remove();
            }
        } else if (btn.hasAttribute('data-mover')) {
            if (btn.getAttribute('data-mover') === '-1' && bloque.previousElementSibling) {
                contenedor.insertBefore(bloque, bloque.previousElementSibling);
            } else if (btn.getAttribute('data-mover') === '1' && bloque.nextElementSibling) {
                contenedor.insertBefore(bloque.nextElementSibling, bloque);
            }
            btn.focus();
        } else if (btn.hasAttribute('data-tabla')) {
            editarTabla(btn.closest('[data-tabla-editor]'), btn.getAttribute('data-tabla'));
        }
    });

    // ── 2. Tabla ───────────────────────────────────────────────────────────
    function celda(valor) {
        var td = document.createElement('td');
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-input';
        input.maxLength = 300;
        input.setAttribute('aria-label', 'Celda');
        input.value = valor || '';
        td.appendChild(input);
        return td;
    }

    function editarTabla(editor, accion) {
        var tbody = editor.querySelector('tbody');
        var filas = tbody.rows;
        var columnas = filas.length ? filas[0].cells.length : 0;
        var maxFilas = parseInt(editor.getAttribute('data-max-filas'), 10) || 30;
        var maxCols  = parseInt(editor.getAttribute('data-max-columnas'), 10) || 8;
        var i;

        if (accion === '+fila' && filas.length < maxFilas) {
            var tr = document.createElement('tr');
            for (i = 0; i < columnas; i++) {
                tr.appendChild(celda(''));
            }
            tbody.appendChild(tr);
            tr.querySelector('input').focus();
        } else if (accion === '-fila' && filas.length > 1) {
            tbody.deleteRow(filas.length - 1);
        } else if (accion === '+columna' && columnas < maxCols) {
            for (i = 0; i < filas.length; i++) {
                filas[i].appendChild(celda(''));
            }
        } else if (accion === '-columna' && columnas > 1) {
            for (i = 0; i < filas.length; i++) {
                filas[i].deleteCell(columnas - 1);
            }
        }
    }

    /** Serializa la tabla; devuelve la primera celda si está toda vacía. */
    function serializarTabla(editor) {
        var datos = [];
        var conTexto = false;
        Array.prototype.forEach.call(editor.querySelector('tbody').rows, function (tr) {
            var fila = [];
            Array.prototype.forEach.call(tr.querySelectorAll('input'), function (input) {
                var v = input.value.trim();
                conTexto = conTexto || v !== '';
                fila.push(v);
            });
            datos.push(fila);
        });
        editor.querySelector('[data-tabla-json]').value = JSON.stringify(datos);
        return conTexto ? null : editor.querySelector('tbody input');
    }

    // ── 3. Imagen ──────────────────────────────────────────────────────────
    form.addEventListener('change', function (ev) {
        var input = ev.target;
        if (!input.matches('[data-imagen-archivo]')) {
            return;
        }
        var archivo = input.files && input.files[0];
        var vista = input.closest('[data-bloque]').querySelector('[data-imagen-vista]');
        input.setCustomValidity('');
        if (!archivo) {
            return;
        }
        if (['image/png', 'image/jpeg'].indexOf(archivo.type) === -1) {
            input.setCustomValidity('La imagen debe ser PNG o JPG.');
        } else if (archivo.size > IMAGEN_MAX) {
            input.setCustomValidity('La imagen no debe superar 2 MB.');
        }
        if (input.validationMessage) {
            input.reportValidity();
            return;
        }
        if (vista) {
            vista.src = URL.createObjectURL(archivo);
            vista.hidden = false;
        }
    });

    // ── Envío ──────────────────────────────────────────────────────────────
    form.addEventListener('submit', function (ev) {
        var lista = bloques();
        for (var i = 0; i < lista.length; i++) {
            var editor = lista[i].querySelector('[data-tabla-editor]');
            if (!editor) {
                continue;
            }
            var vacia = serializarTabla(editor);
            if (vacia) {
                ev.preventDefault();
                vacia.setCustomValidity('Escribe al menos una celda de la tabla.');
                vacia.reportValidity();
                vacia.addEventListener('input', function limpiar() {
                    this.setCustomValidity('');
                    this.removeEventListener('input', limpiar);
                });
                return;
            }
        }
        form.querySelector('button[type="submit"]').disabled = true;
    });
}());
