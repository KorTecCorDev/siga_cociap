/**
 * matriculas.js — Detalle de matrícula (show).
 * Disclosures con mejora progresiva: el HTML renderiza el formulario abierto;
 * aquí lo colapsamos y mostramos el disparador, así sin JS sigue funcionando.
 *  - Desactivar: despliega el motivo de la baja.
 *  - Editar datos: despliega el formulario de datos personales del estudiante.
 *  - Exonerar: despliega el formulario de exoneración de área.
 */

// Confirmación de los formularios marcados con [data-confirm] (quitar apoderado,
// hacer responsable), como criterios-conducta.js. El servidor valida igual.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.hasAttribute && form.hasAttribute('data-confirm')
        && !window.confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
    }
});

// Datos del apoderado con otros estudiantes ([data-confirm-cambios]): el confirm()
// lista EXACTAMENTE qué campos cambian (valor anterior → nuevo), para que se
// confirme sabiendo qué se edita. Sin cambios, envía sin preguntar. Los campos
// [data-mayus] se comparan como los guarda el servidor (mayúsculas, espacios simples).
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!form.hasAttribute || !form.hasAttribute('data-confirm-cambios')) return;

    const normalizar = (el, v) => {
        const t = v.trim().replace(/\s+/g, ' ');
        return el.hasAttribute('data-mayus') ? t.toUpperCase() : t;
    };
    const cambios = [];
    form.querySelectorAll('[data-campo]').forEach((el) => {
        const antes   = normalizar(el, el.defaultValue);
        const despues = normalizar(el, el.value);
        if (antes !== despues) {
            cambios.push(`• ${el.dataset.campo}: ${antes || '(vacío)'} → ${despues || '(vacío)'}`);
        }
    });
    if (cambios.length === 0) return;

    const mensaje = [form.dataset.confirmCambios, '', ...cambios, '', form.dataset.confirmNota || '']
        .join('\n').trim();
    if (!window.confirm(mensaje)) e.preventDefault();
});

document.addEventListener('DOMContentLoaded', () => {
    // Filtros de la lista (/matriculas): Nivel → Grado → Sección coherentes. Cada
    // select ofrece solo lo compatible con los de arriba, y si la opción elegida
    // deja de serlo vuelve a «Todos». Sin JS se ven todas las opciones.
    const fNivel   = document.querySelector('[data-filtro-nivel]');
    const fGrado   = document.querySelector('[data-filtro-grado]');
    const fSeccion = document.querySelector('[data-filtro-seccion]');
    if (fNivel && fGrado && fSeccion) {
        const originales = (sel) => Array.from(sel.options).map((o) => o.cloneNode(true));
        const todosGrados    = originales(fGrado);
        const todasSecciones = originales(fSeccion);

        const reconstruir = (sel, opciones, compatible) => {
            const elegido = sel.value;
            sel.replaceChildren(...opciones
                .filter((o) => o.value === '' || compatible(o))
                .map((o) => o.cloneNode(true)));
            sel.value = Array.from(sel.options).some((o) => o.value === elegido) ? elegido : '';
        };

        const aplicar = () => {
            const nivel = fNivel.value;
            reconstruir(fGrado, todosGrados, (o) => !nivel || o.dataset.nivel === nivel);
            const grado = fGrado.value;
            reconstruir(fSeccion, todasSecciones, (o) =>
                (!nivel || o.dataset.nivel === nivel) && (!grado || o.dataset.grado === grado));
        };
        fNivel.addEventListener('change', aplicar);
        fGrado.addEventListener('change', aplicar);
        aplicar();
    }

    // Alta provisional (crear): al marcar "sin DNI" se oculta el campo DNI y se
    // relaja la obligatoriedad (DNI y serie). El servidor es la autoridad; esto
    // es solo mejora de UX. El input DNI se deshabilita para no enviarse.
    const provToggle = document.querySelector('[data-provisional-toggle]');
    if (provToggle) {
        const dniGroup = document.querySelector('[data-dni-group]');
        const dniInput = dniGroup ? dniGroup.querySelector('input[name="dni"]') : null;
        const serie    = document.querySelector('input[name="serie_recibo"]');
        const hint     = document.querySelector('[data-provisional-hint]');

        const aplicar = () => {
            const on = provToggle.checked;
            if (dniGroup) dniGroup.hidden = on;
            if (dniInput) { dniInput.disabled = on; dniInput.required = !on; }
            if (serie)    serie.required = !on;
            if (hint)     hint.hidden = !on;
        };
        provToggle.addEventListener('change', aplicar);
        aplicar(); // estado inicial (por si el navegador conserva el check)
    }

    // Editar datos personales del estudiante.
    document.querySelectorAll('[data-editar-form]').forEach((form) => {
        const card    = form.closest('.card__body');
        const control = card ? card.querySelector('[data-editar-control]') : null;
        const toggle  = card ? card.querySelector('[data-editar-toggle]') : null;
        const cancel  = form.querySelector('[data-editar-cancel]');
        if (!control || !toggle) return;

        // Estado inicial (mejorado por JS): colapsado.
        control.hidden = false;
        form.hidden    = true;
        if (cancel) cancel.hidden = false;

        toggle.addEventListener('click', () => {
            form.hidden    = false;
            control.hidden = true;
            const primero = form.querySelector('input, select');
            if (primero) primero.focus();
        });

        if (cancel) {
            cancel.addEventListener('click', () => {
                form.reset();          // descarta lo tecleado, vuelve a los valores guardados
                form.hidden    = true;
                control.hidden = false;
            });
        }
    });

    document.querySelectorAll('[data-desactivar-form]').forEach((form) => {
        const bloque  = form.closest('.mat-accion');
        const control = bloque ? bloque.querySelector('[data-desactivar-control]') : null;
        const toggle  = bloque ? bloque.querySelector('[data-desactivar-toggle]') : null;
        const cancel  = form.querySelector('[data-desactivar-cancel]');
        const textarea = form.querySelector('textarea');
        if (!control || !toggle) return;

        // Estado inicial (mejorado por JS): colapsado.
        control.hidden = false;
        form.hidden    = true;
        if (cancel) cancel.hidden = false;

        toggle.addEventListener('click', () => {
            form.hidden    = false;
            control.hidden = true;
            if (textarea) textarea.focus();
        });

        if (cancel) {
            cancel.addEventListener('click', () => {
                form.hidden    = true;
                control.hidden = false;
                if (textarea) textarea.value = '';
            });
        }
    });

    // Exonerar de área (mismo disclosure que Desactivar, dentro de su .mat-accion).
    document.querySelectorAll('[data-exonerar-form]').forEach((form) => {
        const bloque  = form.closest('.mat-accion');
        const control = bloque ? bloque.querySelector('[data-exonerar-control]') : null;
        const toggle  = bloque ? bloque.querySelector('[data-exonerar-toggle]') : null;
        const cancel  = form.querySelector('[data-exonerar-cancel]');
        const select  = form.querySelector('select');
        if (!control || !toggle) return;

        // Estado inicial (mejorado por JS): colapsado.
        control.hidden = false;
        form.hidden    = true;
        if (cancel) cancel.hidden = false;

        toggle.addEventListener('click', () => {
            form.hidden    = false;
            control.hidden = true;
            if (select) select.focus();
        });

        if (cancel) {
            cancel.addEventListener('click', () => {
                form.reset();          // descarta selección y motivo tecleados
                form.hidden    = true;
                control.hidden = false;
            });
        }
    });
    // Cambio de sección y su reversión (07/10/2026): mismo disclosure que
    // Desactivar. Sin JS los formularios se ven abiertos.
    [['cambio', 'select'], ['cambio-revertir', 'textarea']].forEach(([nombre, foco]) => {
        document.querySelectorAll('[data-' + nombre + '-form]').forEach((form) => {
            const bloque  = form.closest('.mat-accion');
            const control = bloque ? bloque.querySelector('[data-' + nombre + '-control]') : null;
            const toggle  = bloque ? bloque.querySelector('[data-' + nombre + '-toggle]') : null;
            const cancel  = form.querySelector('[data-' + nombre + '-cancel]');
            if (!control || !toggle) return;

            control.hidden = false;
            form.hidden    = true;
            if (cancel) cancel.hidden = false;

            toggle.addEventListener('click', () => {
                form.hidden    = false;
                control.hidden = true;
                const campo = form.querySelector(foco);
                if (campo) campo.focus();
            });
            if (cancel) {
                cancel.addEventListener('click', () => {
                    form.reset();
                    form.dispatchEvent(new Event('change'));
                    form.hidden    = true;
                    control.hidden = false;
                });
            }
        });
    });

    // REGRESO a la sección anterior: no lleva R.D. Los campos se DESHABILITAN
    // (un campo deshabilitado no se envía, así el regreso no ocupa el número
    // sugerido). El servidor vuelve a decidirlo.
    document.querySelectorAll('[data-cambio-form]').forEach((form) => {
        const destino = form.querySelector('[data-cambio-destino]');
        const rd      = form.querySelector('[data-cambio-rd]');
        const aviso   = form.querySelector('[data-cambio-regreso-aviso]');
        if (!destino || !rd) return;

        const aplicar = () => {
            const opcion  = destino.options[destino.selectedIndex];
            const regreso = !!opcion && opcion.dataset.regreso === '1';
            rd.hidden = regreso;
            rd.querySelectorAll('input').forEach((input) => {
                input.disabled = regreso;
                input.required = !regreso;
            });
            if (aviso) aviso.hidden = !regreso;
        };
        destino.addEventListener('change', aplicar);
        form.addEventListener('change', aplicar);
        aplicar();
    });
});
