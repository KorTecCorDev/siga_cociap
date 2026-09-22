/**
 * calificaciones.js — SIGA-COCIAP
 * Lógica del panel de calificaciones del docente.
 */

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const BASE = document.querySelector('meta[name="base-url"]')?.content ?? '';

// ── Acordeones de criterios ──────────────────────────────────
document.querySelectorAll('.criterio-bloque').forEach(bloque => {
    const header = bloque.querySelector('.criterio-bloque__header');
    header.addEventListener('click', (e) => {
        if (e.target.closest('button') || e.target.tagName === 'INPUT') return;
        const abriendo = !bloque.classList.contains('criterio-bloque--open');
        bloque.classList.toggle('criterio-bloque--open');
        if (abriendo) {
            setTimeout(() => bloque.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 260);
        }
    });
});

// ── Banner "ir al criterio pendiente" ────────────────────────
// El boton abre el criterio destino y hace scroll suave. Esperamos a que
// arranque la animacion del acordeon antes de calcular el destino para que
// scrollIntoView use la altura final, no la cerrada.
document.querySelectorAll('[data-criterio-target]').forEach(btn => {
    btn.addEventListener('click', () => {
        const criterioId = btn.dataset.criterioTarget;
        const bloque = document.getElementById('criterio-' + criterioId);
        if (!bloque) return;
        bloque.classList.add('criterio-bloque--open');
        setTimeout(() => bloque.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
    });
});

// ── Detección de cambios sin guardar ─────────────────────────
// Compara el valor actual de cada input contra su data-nota-inicial.
// Vaciar un campo sí es un cambio (el autosave borra la nota en BD).
// Marca el card con --con-cambios (ámbar) mientras el autosave no confirmó.
function notaActualDifiereDeInicial(input) {
    const inicial = (input.dataset.notaInicial ?? '').trim();
    const actual  = (input.value ?? '').trim();
    const ni = inicial === '' ? null : parseInt(inicial, 10);
    const na = actual  === '' ? null : parseInt(actual,  10);
    return ni !== na;
}

// ── Autosave por celda ────────────────────────────────────────
// Se dispara en blur. Si no hay cambio respecto a data-nota-inicial,
// no hace nada. Nota vacía = borra la fila en BD. En fallo silencioso
// la card queda ámbar y el docente reintenta al volver al campo.
async function autoguardarCelda(input) {
    const form = input.closest('.form-notas');
    if (!form) return;
    if (!notaActualDifiereDeInicial(input)) return;

    const match = input.name.match(/\[(\d+)\]/);
    if (!match) return;

    const criterioId    = form.dataset.criterioId;
    const competenciaId = form.dataset.competenciaId;
    const cargaId       = form.dataset.cargaId;
    const matriculaId   = match[1];
    const nota          = input.value.trim(); // '' = borrar

    const fd = new FormData();
    fd.append('_csrf_token',    CSRF);
    fd.append('criterio_id',    criterioId);
    fd.append('competencia_id', competenciaId);
    fd.append('matricula_id',   matriculaId);
    fd.append('nota',           nota);

    try {
        const res  = await fetch(`${BASE}/docente/calificaciones/${cargaId}/autosave`,
            { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            input.dataset.notaInicial = nota;
            actualizarProgresoCriterio(form);
            // Editar una nota desconfirma el criterio en el servidor → pendiente.
            actualizarEstadoCriterio(form.closest('.criterio-bloque'), true);
            const tieneAlgunaNota = Array.from(form.querySelectorAll('.input-nota'))
                .some(i => i.value.trim() !== '');
            const info = obtenerContenedorIluminacion(form);
            if (info) {
                info.contenedor.classList.toggle(`${info.prefijo}--con-notas`, tieneAlgunaNota);
                if (tieneAlgunaNota) {
                    const btnEliminar = info.contenedor.querySelector('.btn-eliminar-criterio');
                    if (btnEliminar) btnEliminar.dataset.tieneCalificaciones = '1';
                }
            }
            // Sincronizar "Ver resumen": borrar una nota puede desconfirmar el
            // criterio (blanco sin motivo) y re-bloquear el acceso al resumen.
            if (typeof data.resumenAccesible === 'boolean') {
                sincronizarBotonResumen(competenciaId, data.resumenAccesible);
            }
        }
    } catch {
        // Silencioso: card queda ámbar, docente reintenta en el siguiente blur
    }

    recalcularCambiosForm(form);
}

// Sincroniza el botón "Ver resumen" de una competencia con la accesibilidad
// que reporta el servidor (misma apariencia que el render bloqueado de la vista
// PHP). Evita que el botón mienta tras borrar notas hasta la siguiente recarga.
function sincronizarBotonResumen(competenciaId, accesible) {
    const card = document.getElementById('comp-' + competenciaId);
    if (!card) return;
    const link = card.querySelector('.competencia-card__acciones a.btn--secondary');
    if (!link) return;

    if (accesible) {
        link.classList.remove('btn-ver-resumen--bloqueado');
        link.removeAttribute('tabindex');
        link.removeAttribute('aria-disabled');
        link.removeAttribute('title');
    } else {
        link.classList.add('btn-ver-resumen--bloqueado');
        link.tabIndex = -1;
        link.setAttribute('aria-disabled', 'true');
        link.setAttribute('title', 'Confirma todos los criterios (sin pendientes ni vacíos) para acceder al resumen');
    }
}

// Vista normal: el form vive dentro de .criterio-bloque.
// Vista transversales: el form vive directamente dentro de .competencia-card
// (cada competencia tiene un único criterio implícito). Devolvemos el ancestro
// y el prefijo BEM apropiado para que los modificadores compartan lenguaje.
function obtenerContenedorIluminacion(form) {
    const contenedor = form.closest('.criterio-bloque, .competencia-card');
    if (!contenedor) return null;
    const prefijo = contenedor.classList.contains('criterio-bloque')
        ? 'criterio-bloque'
        : 'competencia-card';
    return { contenedor, prefijo };
}

function recalcularCambiosForm(form) {
    const info = obtenerContenedorIluminacion(form);
    if (!info) return;
    const hayCambios = Array.from(form.querySelectorAll('.input-nota'))
        .some(notaActualDifiereDeInicial);
    info.contenedor.classList.toggle(`${info.prefijo}--con-cambios`, hayCambios);
    actualizarCardTransversal(form);
}

// En la vista de calificaciones completa las transversales se muestran como
// .competencia-card--transversal con sus criterios dentro. Propagamos al card
// entero el color de sus criterios: ambar si alguno tiene cambios sin guardar,
// verde si alguno tiene notas guardadas. El ambar gana al verde (mismo lenguaje
// que .criterio-bloque). Solo aplica a las transversales; en academicas el
// closest no encuentra el card y la funcion es un no-op.
function actualizarCardTransversal(el) {
    const card = el.closest('.competencia-card--transversal');
    if (!card) return;
    const hayCambios = !!card.querySelector('.criterio-bloque--con-cambios');
    const hayNotas   = !!card.querySelector('.criterio-bloque--con-notas');
    card.classList.toggle('competencia-card--con-cambios', hayCambios);
    card.classList.toggle('competencia-card--con-notas', hayNotas);
}

// Actualiza en vivo el chip "X de Y" (Alumnos con nota guardada) del criterio.
// Cuenta sobre `data-nota-inicial` (lo PERSISTIDO, no `value`): así un autosave
// fallido no infla el conteo. El total = nº de inputs = alumnos no exonerados
// (los EXO no renderizan input), que coincide con $totalAlumnos del servidor.
// Solo aplica a forms dentro de un .criterio-bloque; en otra estructura es no-op.
function actualizarProgresoCriterio(form) {
    const bloque = form.closest('.criterio-bloque');
    if (!bloque) return;
    const chip = bloque.querySelector('.criterio-bloque__progreso');
    if (!chip) return;
    const inputs  = form.querySelectorAll('.input-nota');
    const total   = inputs.length;
    const conNota = Array.from(inputs)
        .filter(i => (i.dataset.notaInicial ?? '').trim() !== '').length;
    chip.textContent = `${conNota} de ${total}`;
    chip.classList.toggle(
        'criterio-bloque__progreso--completo',
        total > 0 && conNota >= total
    );
}

// Enciende/apaga el punto "pendiente de confirmar" (Opción A) de un criterio.
// `pendiente=true` al editar/omitir/renombrar (desconfirma); `false` al Confirmar.
// Espeja el estado server-side de `criterios.confirmado_en` sin recargar.
function actualizarEstadoCriterio(bloque, pendiente) {
    const dot = bloque?.querySelector('.criterio-bloque__estado');
    if (!dot) return;
    dot.classList.toggle('criterio-bloque__estado--pendiente', pendiente);
}

// ── Pegado masivo desde Excel / portapapeles ─────────────────
// Al copiar una columna en Excel y pegarla sobre cualquier input-nota,
// distribuye cada línea en el input correspondiente hacia abajo.
document.querySelectorAll('.form-notas').forEach(form => {
    form.addEventListener('paste', (e) => {
        if (!e.target.classList.contains('input-nota')) return;

        const raw    = (e.clipboardData || window.clipboardData).getData('text');
        const lineas = raw.split(/\r?\n/).map(l => l.trim()).filter(l => l !== '');

        // Si es un solo valor, dejar el comportamiento nativo del input
        if (lineas.length <= 1) return;

        e.preventDefault();

        const inputs         = Array.from(form.querySelectorAll('.input-nota'));
        const inicio         = inputs.indexOf(e.target);
        const affectedInputs = [];

        lineas.forEach((linea, i) => {
            const idx = inicio + i;
            if (idx >= inputs.length) return;

            // Si Excel copió varias columnas, solo usar la primera
            const celda = linea.split('\t')[0].trim();
            const num   = parseInt(celda, 10);

            if (!isNaN(num)) {
                const valida = Math.min(20, Math.max(0, num));
                inputs[idx].value = String(valida).padStart(2, '0');
                inputs[idx].classList.remove('input--error');
                inputs[idx].classList.add('input--pasted');
                setTimeout(() => inputs[idx].classList.remove('input--pasted'), 1200);
                affectedInputs.push(inputs[idx]);
            } else if (celda === '' || celda === '-' || celda === '—') {
                inputs[idx].value = '';
                inputs[idx].classList.remove('input--error');
                affectedInputs.push(inputs[idx]);
            }
        });

        // Autoguardar cada celda afectada (el paste no dispara blur)
        affectedInputs.forEach(inp => autoguardarCelda(inp));

        recalcularCambiosForm(form);
    });
});

// ── Validación y formato de inputs de nota (0-20) ────────────
document.querySelectorAll('.input-nota').forEach(input => {

    // 1. Bloquear teclas no numéricas en tiempo real
    input.addEventListener('keydown', (e) => {
        const esControl = e.ctrlKey || e.metaKey;
        const esNavegacion = [
            'Backspace', 'Delete', 'Tab',
            'ArrowLeft', 'ArrowRight', 'Home', 'End',
        ].includes(e.key);
        if (esNavegacion || esControl) return;
        if (!/^[0-9]$/.test(e.key)) e.preventDefault();
    });

    // 2. Limpiar caracteres no numéricos que entren por pegado individual
    input.addEventListener('input', () => {
        const soloDigitos = input.value.replace(/\D/g, '');
        if (input.value !== soloDigitos) input.value = soloDigitos;
        const form = input.closest('.form-notas');
        if (form) recalcularCambiosForm(form);
    });

    // 3. Al salir del campo: normalizar rango, luego autoguardar.
    input.addEventListener('blur', async () => {
        const val = input.value.trim();
        if (val === '') {
            input.classList.remove('input--error');
        } else {
            const nota = parseInt(val, 10);
            if (!isNaN(nota)) {
                const valida = Math.min(20, Math.max(0, nota));
                input.value = String(valida).padStart(2, '0');
                input.classList.remove('input--error');
            } else {
                input.value = '';
                input.classList.remove('input--error');
            }
        }
        const form = input.closest('.form-notas');
        if (form) recalcularCambiosForm(form);
        await autoguardarCelda(input);
    });
});

// ── Guardar notas ────────────────────────────────────────────
document.querySelectorAll('.form-notas').forEach(form => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const criterioId    = form.dataset.criterioId;
        const competenciaId = form.dataset.competenciaId;
        const cargaId       = form.dataset.cargaId;
        const status        = form.querySelector('.form-notas__status');
        const btn           = form.querySelector('button[type="submit"]');

        // Recopilar notas
        const inputs = form.querySelectorAll('.input-nota');
        const notas  = {};
        let valido   = true;

        inputs.forEach(input => {
            const nombre = input.name; // notas[matricula_id]
            const match  = nombre.match(/\[(\d+)\]/);
            if (!match) return;

            const val = input.value.trim();
            if (val === '') return; // nota vacía = no registrar

            const nota = parseInt(val);
            if (isNaN(nota) || nota < 0 || nota > 20) {
                input.classList.add('input--error');
                valido = false;
            } else {
                input.classList.remove('input--error');
                notas[match[1]] = nota;
            }
        });

        if (!valido) {
            mostrarStatus(status, 'error', 'Verifica que las notas sean entre 0 y 20.');
            return;
        }

        if (Object.keys(notas).length === 0) {
            mostrarStatus(status, 'warning', 'No hay notas que guardar.');
            return;
        }

        // Detectar alumnos sin nota — requieren motivo obligatorio antes de guardar.
        // El promedio usa AVG (ignora NULLs): vacío ≠ cero. El docente debe
        // justificar cada omisión a través del modal antes de poder confirmar.
        const sinCalificar = [];
        inputs.forEach(input => {
            if (input.value.trim() === '') {
                const fila      = input.closest('tr');
                const nombre    = fila?.querySelector('.col-nombre')?.textContent?.trim()
                                  ?? 'Alumno sin nombre';
                const matricula = input.name.match(/\[(\d+)\]/)?.[1] ?? null;
                sinCalificar.push({ nombre, matricula });
            }
        });

        if (sinCalificar.length > 0) {
            // Abrir modal y esperar confirmación antes de continuar con el fetch.
            abrirModalOmisiones(sinCalificar, async (omisiones) => {
                await ejecutarGuardado(
                    form, criterioId, competenciaId, cargaId,
                    notas, omisiones, status, btn
                );
            });
            return; // El fetch se lanzará desde el callback del modal
        }

        // Sin alumnos vacíos: guardar directamente
        await ejecutarGuardado(
            form, criterioId, competenciaId, cargaId,
            notas, {}, status, btn
        );
    });
});

// ── Guardar notas (función extraída para reutilizar desde el modal) ──────────
// omisiones = { matricula_id: motivo } — vacío cuando no hay alumnos sin nota
async function ejecutarGuardado(form, criterioId, competenciaId, cargaId, notas, omisiones, status, btn) {
    btn.disabled = true;
    mostrarStatus(status, 'loading', 'Guardando...');

    try {
        // Notas + omisiones en UNA sola petición atómica. El servidor valida
        // que ningún alumno quede en blanco sin motivo antes de sellar el
        // criterio (el filtro de omisión ya no depende solo de este modal).
        const formData = new FormData();
        formData.append('_csrf_token',    CSRF);
        formData.append('criterio_id',    criterioId);
        formData.append('competencia_id', competenciaId);
        Object.entries(notas).forEach(([id, nota]) => {
            formData.append(`notas[${id}]`, nota);
        });
        Object.entries(omisiones).forEach(([id, motivo]) => {
            formData.append(`omisiones[${id}]`, motivo);
        });

        const url = `${BASE}/docente/calificaciones/${cargaId}/guardar`;
        const res  = await fetch(url, { method: 'POST', body: formData });
        const data = await res.json();

        if (!data.success) {
            mostrarStatus(status, 'error', '⚠ ' + data.mensaje);
            return;
        }

        mostrarStatus(status, 'success', '✓ ' + data.mensaje);

        // Sincronizar "Ver resumen": confirmar ESTE criterio solo desbloquea el
        // acceso si ya no quedan criterios pendientes/vacíos en la competencia.
        // El servidor lo calcula (competenciaListaParaResumen) y lo devuelve.
        if (typeof data.resumenAccesible === 'boolean') {
            sincronizarBotonResumen(competenciaId, data.resumenAccesible);
        }

        const tieneAlgunaNota = Array.from(form.querySelectorAll('.input-nota'))
            .some(i => i.value.trim() !== '');
        if (tieneAlgunaNota) {
            const info = obtenerContenedorIluminacion(form);
            if (info) {
                info.contenedor.classList.add(`${info.prefijo}--con-notas`);
                const btnEliminar = info.contenedor.querySelector('.btn-eliminar-criterio');
                if (btnEliminar) btnEliminar.dataset.tieneCalificaciones = '1';
            }
        }

        form.querySelectorAll('.input-nota').forEach(i => {
            i.dataset.notaInicial = i.value;
        });
        actualizarProgresoCriterio(form);
        // Confirmar selló el criterio en el servidor → ya no está pendiente.
        actualizarEstadoCriterio(form.closest('.criterio-bloque'), false);
        recalcularCambiosForm(form);

    } catch (err) {
        mostrarStatus(status, 'error', 'Error de conexión.');
    } finally {
        btn.disabled = false;
    }
}

// ── Modal de omisiones ───────────────────────────────────────────────────────
// Muestra el modal con un select de motivo por cada alumno sin nota.
// onConfirmar recibe { matricula_id: motivo } y ejecuta el guardado real.

const MOTIVOS_OMISION = [
    ['ausencia_injustificada', 'Ausencia injustificada'],
    ['ausencia_justificada',   'Ausencia justificada (enfermedad, permiso)'],
    ['abandono',               'Abandono / retiro'],
    ['no_aplico',              'No aplicó para este alumno'],
];

function abrirModalOmisiones(alumnos, onConfirmar) {
    const modal     = document.getElementById('omision-modal');
    const lista     = document.getElementById('omision-lista');
    const btnOk     = document.getElementById('omision-confirmar');
    const btnCancel = document.getElementById('omision-cancelar');
    const statusEl  = document.getElementById('omision-status');

    // Poblar lista de alumnos con un select por cada uno
    lista.innerHTML = '';
    alumnos.forEach(({ nombre, matricula }) => {
        const fila = document.createElement('div');
        fila.className = 'omision-modal__fila';

        const label = document.createElement('span');
        label.className   = 'omision-modal__nombre';
        label.textContent = nombre;

        const select = document.createElement('select');
        select.className         = 'omision-modal__select form-input';
        select.dataset.matricula = matricula;
        select.required          = true;

        const placeholder = document.createElement('option');
        placeholder.value       = '';
        placeholder.textContent = '— Selecciona un motivo —';
        select.appendChild(placeholder);

        MOTIVOS_OMISION.forEach(([val, txt]) => {
            const opt = document.createElement('option');
            opt.value       = val;
            opt.textContent = txt;
            select.appendChild(opt);
        });

        fila.append(label, select);
        lista.appendChild(fila);
    });

    // Habilitar "Confirmar" solo cuando todos los selects tienen valor
    const actualizarBoton = () => {
        const selects = lista.querySelectorAll('select');
        btnOk.disabled = Array.from(selects).some(s => s.value === '');
    };
    lista.addEventListener('change', actualizarBoton);
    actualizarBoton();

    statusEl.textContent = '';
    statusEl.className   = 'omision-modal__status';
    modal.hidden = false;

    const cerrar = () => {
        modal.hidden = true;
        lista.removeEventListener('change', actualizarBoton);
    };

    btnCancel.onclick = cerrar;
    modal.querySelector('.omision-modal__backdrop').onclick = cerrar;

    btnOk.onclick = async () => {
        const omisiones = {};
        lista.querySelectorAll('select').forEach(s => {
            if (s.value) omisiones[s.dataset.matricula] = s.value;
        });
        cerrar();
        await onConfirmar(omisiones);
    };
}

// ── Contador de caracteres del nombre de criterio ────────────
// Pinta "67/100" junto al campo y marca --excedido cuando supera el límite.
const CRITERIO_NOMBRE_MAX = 100;

function vincularContador(input, contador) {
    const actualizar = () => {
        const len = input.value.trim().length;
        contador.textContent = `${len}/${CRITERIO_NOMBRE_MAX}`;
        contador.classList.toggle('contador-chars--excedido', len > CRITERIO_NOMBRE_MAX);
    };
    input.addEventListener('input', actualizar);
    actualizar();
}

document.querySelectorAll('.agregar-criterio').forEach(bloque => {
    const input    = bloque.querySelector('.input-nuevo-criterio');
    const contador = bloque.querySelector('.contador-chars');
    if (input && contador) vincularContador(input, contador);
});

// ── Agregar criterio ─────────────────────────────────────────
document.querySelectorAll('.btn-agregar-criterio').forEach(btn => {
    btn.addEventListener('click', async () => {
        const cargaId       = btn.dataset.cargaId;
        const competenciaId = btn.dataset.competenciaId;
        const bloque        = btn.closest('.agregar-criterio');
        const input         = bloque.querySelector('.input-nuevo-criterio');
        const inputDesc     = bloque.querySelector('.input-nuevo-criterio-desc');
        const nombre        = input.value.trim();
        const descripcion   = inputDesc ? inputDesc.value.trim() : '';

        if (!nombre) {
            input.focus();
            return;
        }

        if (nombre.length > CRITERIO_NOMBRE_MAX) {
            alert(`El nombre supera los ${CRITERIO_NOMBRE_MAX} caracteres. ` +
                  `Usa el campo de descripción para el detalle.`);
            input.focus();
            return;
        }

        btn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('_csrf_token',   CSRF);
            formData.append('carga_id',      cargaId);
            formData.append('competencia_id',competenciaId);
            formData.append('nombre',        nombre);
            formData.append('descripcion',   descripcion);

            const res = await fetch(`${BASE}/docente/criterios/crear`,
                { method: 'POST', body: formData }
            );
            const data = await res.json();

            if (data.success) {
                // Recargar la página para mostrar el nuevo criterio
                window.location.reload();
            } else {
                alert(data.mensaje);
            }
        } catch (err) {
            alert('Error de conexión.');
        } finally {
            btn.disabled = false;
        }
    });
});

// ── Editar criterio (nombre + descripción) ───────────────────
// El input de nombre lleva contador en vivo. Si el nombre actual supera
// los 100 caracteres (criterios antiguos de hasta 120), aparece el botón
// "Mover a descripción": traslada el texto íntegro a la descripción y
// deja el nombre listo para escribir uno corto.
document.querySelectorAll('.btn-renombrar-criterio').forEach(btn => {
    btn.addEventListener('click', () => {
        const criterioId = btn.dataset.criterioId;
        const bloque     = document.getElementById(`criterio-${criterioId}`);
        const identidad  = bloque.querySelector('.criterio-bloque__identidad');
        const h4         = bloque.querySelector('.criterio-bloque__nombre');
        const acciones   = bloque.querySelector('.criterio-bloque__acciones');
        const nombreActual = h4.textContent.trim();
        const descActual   = btn.dataset.descripcion ?? '';

        const input = document.createElement('input');
        input.type      = 'text';
        input.value     = nombreActual;
        input.className = 'form-input criterio-nombre-input';

        const contador = document.createElement('span');
        contador.className = 'contador-chars';
        vincularContador(input, contador);

        const textareaDesc = document.createElement('textarea');
        textareaDesc.value       = descActual;
        textareaDesc.rows        = 2;
        textareaDesc.placeholder = 'Descripción del criterio (opcional)';
        textareaDesc.className   = 'form-input criterio-desc-input';

        const btnGuardar = document.createElement('button');
        btnGuardar.type      = 'button';
        btnGuardar.textContent = 'Guardar';
        btnGuardar.className = 'btn btn--primary btn--sm';

        const btnCancelar = document.createElement('button');
        btnCancelar.type      = 'button';
        btnCancelar.textContent = 'Cancelar';
        btnCancelar.className = 'btn btn--secondary btn--sm';

        const filaNombre = document.createElement('div');
        filaNombre.className = 'criterio-nombre-editar__fila';
        filaNombre.append(input, contador, btnGuardar, btnCancelar);

        const wrapper = document.createElement('div');
        wrapper.className = 'criterio-nombre-editar';
        wrapper.append(filaNombre);

        // Criterio antiguo con nombre > 100: ofrecer traslado con un clic
        if (nombreActual.length > CRITERIO_NOMBRE_MAX) {
            const btnMover = document.createElement('button');
            btnMover.type        = 'button';
            btnMover.textContent = '↓ Mover a descripción';
            btnMover.title       = 'Pasa este texto a la descripción y deja el nombre libre';
            btnMover.className   = 'btn btn--secondary btn--sm btn-mover-descripcion';
            btnMover.addEventListener('click', () => {
                textareaDesc.value = textareaDesc.value.trim()
                    ? textareaDesc.value.trim() + '\n' + input.value.trim()
                    : input.value.trim();
                input.value = '';
                input.dispatchEvent(new Event('input'));
                btnMover.remove();
                input.focus();
            });
            filaNombre.insertBefore(btnMover, btnGuardar);
        }

        wrapper.append(textareaDesc);

        h4.hidden       = true;
        const pDesc = identidad.querySelector('.criterio-bloque__descripcion');
        if (pDesc) pDesc.hidden = true;
        acciones.hidden = true;
        identidad.append(wrapper);
        input.focus();
        input.select();

        const cancelar = () => {
            h4.hidden       = false;
            if (pDesc) pDesc.hidden = false;
            acciones.hidden = false;
            wrapper.remove();
        };

        const guardar = async () => {
            const nuevo     = input.value.trim();
            const nuevaDesc = textareaDesc.value.trim();
            if (!nuevo) { input.focus(); return; }
            if (nuevo.length > CRITERIO_NOMBRE_MAX) {
                alert(`El nombre supera los ${CRITERIO_NOMBRE_MAX} caracteres. ` +
                      `Usa la descripción para el detalle.`);
                input.focus();
                return;
            }
            if (nuevo === nombreActual && nuevaDesc === descActual) { cancelar(); return; }

            btnGuardar.disabled = btnCancelar.disabled = true;

            try {
                const formData = new FormData();
                formData.append('_csrf_token', CSRF);
                formData.append('nombre',      nuevo);
                formData.append('descripcion', nuevaDesc);

                const res  = await fetch(
                    `${BASE}/docente/criterios/${criterioId}/renombrar`,
                    { method: 'POST', body: formData }
                );
                const data = await res.json();

                if (data.success) {
                    h4.textContent = data.nombre;
                    btn.dataset.descripcion = data.descripcion ?? '';

                    // Actualizar / crear / quitar la línea de descripción visible
                    let p = identidad.querySelector('.criterio-bloque__descripcion');
                    if (data.descripcion) {
                        if (!p) {
                            p = document.createElement('p');
                            p.className = 'criterio-bloque__descripcion';
                            identidad.insertBefore(p, wrapper);
                        }
                        p.textContent = data.descripcion;
                        p.hidden = false;
                    } else if (p) {
                        p.remove();
                    }

                    const btnEliminar = acciones.querySelector('.btn-eliminar-criterio');
                    if (btnEliminar) btnEliminar.dataset.nombre = data.nombre;

                    // Renombrar desconfirma el criterio → re-bloquea "Ver resumen"
                    // hasta re-Confirmar. Sincronizar el botón de la competencia.
                    const card = btn.closest('.competencia-card');
                    if (card && typeof data.resumenAccesible === 'boolean') {
                        sincronizarBotonResumen(card.id.replace('comp-', ''), data.resumenAccesible);
                    }
                    // Renombrar desconfirma el criterio → pendiente.
                    actualizarEstadoCriterio(bloque, true);
                    cancelar();
                } else {
                    alert(data.mensaje);
                    btnGuardar.disabled = btnCancelar.disabled = false;
                }
            } catch (err) {
                alert('Error de conexión.');
                btnGuardar.disabled = btnCancelar.disabled = false;
            }
        };

        btnGuardar.addEventListener('click', guardar);
        btnCancelar.addEventListener('click', cancelar);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter')  { e.preventDefault(); guardar(); }
            if (e.key === 'Escape') { cancelar(); }
        });
        textareaDesc.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { cancelar(); }
        });
    });
});

// ── Eliminar criterio ────────────────────────────────────────
document.querySelectorAll('.btn-eliminar-criterio').forEach(btn => {
    btn.addEventListener('click', async () => {
        const criterioId  = btn.dataset.criterioId;
        const nombre      = btn.dataset.nombre;
        const tieneCals   = btn.dataset.tieneCalificaciones === '1';

        const mensaje = tieneCals
            ? `¿Eliminar el criterio "${nombre}"?\n\n` +
              `Este criterio tiene notas registradas. ` +
              `El promedio de los alumnos será recalculado sin este criterio.\n\n`
            : `¿Eliminar el criterio "${nombre}"?`;

        if (!confirm(mensaje)) return;

        btn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('_csrf_token', CSRF);

            const res = await fetch(
                `${BASE}/docente/criterios/${criterioId}/eliminar`,
                { method: 'POST', body: formData }
            );
            const data = await res.json();

            if (data.success) {
                if (data.tenia_calificaciones) {
                    // Recargar para reflejar el promedio recalculado
                    window.location.reload();
                } else {
                    // Sin recarga: sincronizar lo que dependía del criterio
                    // borrado. Si era el único pendiente, "Ver resumen" pasa a
                    // accesible (el servidor lo decide, como en el autosave).
                    const bloque = document.getElementById(`criterio-${criterioId}`);
                    const card   = bloque ? bloque.closest('.competencia-card') : null;
                    bloque?.remove();
                    if (card && typeof data.resumenAccesible === 'boolean') {
                        sincronizarBotonResumen(card.id.replace('comp-', ''), data.resumenAccesible);
                    }
                    // El banner "Próximo pendiente" ya no puede apuntar a él.
                    const irA = document.querySelector(`[data-criterio-target="${criterioId}"]`);
                    irA?.closest('.calificaciones-pendiente')?.remove();
                }
            } else {
                alert(data.mensaje);
                btn.disabled = false;
            }
        } catch (err) {
            alert('Error de conexión.');
            btn.disabled = false;
        }
    });
});

// ── Helper: mostrar estado ───────────────────────────────────
function mostrarStatus(el, tipo, mensaje) {
    if (!el) return;
    el.textContent  = mensaje;
    el.className    = `form-notas__status status--${tipo}`;

    if (tipo === 'success') {
        setTimeout(() => { el.textContent = ''; }, 4000);
    }
}

// ── Guardar conclusión descriptiva ───────────────────────────
document.querySelectorAll('.btn-guardar-conclusion').forEach(btn => {
    btn.addEventListener('click', async () => {
        const cargaId       = btn.dataset.cargaId;
        const competenciaId = btn.dataset.competenciaId;
        const contenedor    = btn.closest('.conclusion-form');
        const textarea      = contenedor.querySelector('.textarea-conclusion');
        const status        = contenedor.querySelector('.conclusion-status');
        const conclusion    = textarea.value.trim();

        if (!conclusion) {
            status.textContent = '⚠ Escribe la conclusión antes de guardar.';
            status.className   = 'conclusion-status status--warning';
            return;
        }

        btn.disabled = true;
        status.textContent = 'Guardando...';
        status.className   = 'conclusion-status status--loading';

        try {
            const formData = new FormData();
            formData.append('_csrf_token',   CSRF);
            formData.append('carga_id',      cargaId);
            formData.append('competencia_id',competenciaId);
            formData.append('conclusion',    conclusion);

            const res  = await fetch(
                `${BASE}/docente/calificaciones/conclusion`,
                { method: 'POST', body: formData }
            );
            const data = await res.json();

            if (data.success) {
                status.textContent = '✓ Conclusión guardada.';
                status.className   = 'conclusion-status status--success';
                setTimeout(() => { status.textContent = ''; }, 4000);
            } else {
                status.textContent = '⚠ ' + data.mensaje;
                status.className   = 'conclusion-status status--error';
            }
        } catch (err) {
            status.textContent = 'Error de conexión.';
            status.className   = 'conclusion-status status--error';
        } finally {
            btn.disabled = false;
        }
    });
});

// ── "No se evaluó" — cerrar una competencia académica sin notas ──────────────
// Solo aparece en competencias sin criterios. Reutiliza el endpoint de bloqueo
// con sin_calificaciones=1 (mismo flujo que "no se trabajó" del resumen). Acción
// terminal e irreversible: se exige confirmación explícita. Tras el cierre se
// recarga para reflejar el estado bloqueado ("No evaluada").
document.querySelectorAll('.btn-no-evaluo').forEach(btn => {
    btn.addEventListener('click', async () => {
        const cargaId       = btn.dataset.cargaId;
        const competenciaId = btn.dataset.competenciaId;

        if (!confirm(
            '¿Confirmar que esta competencia NO se evaluó en el bimestre?\n\n' +
            'Se cerrará sin registrar calificaciones y no aparecerá en la boleta. ' +
            'Esta acción no se puede deshacer.'
        )) return;

        btn.disabled = true;
        try {
            const formData = new FormData();
            formData.append('_csrf_token',       CSRF);
            formData.append('sin_calificaciones', '1');

            const res  = await fetch(
                `${BASE}/docente/calificaciones/${cargaId}/bloquear/${competenciaId}`,
                { method: 'POST', body: formData }
            );
            const data = await res.json();

            if (data.success) {
                window.location.reload();
            } else {
                alert('⚠ ' + (data.mensaje || 'No se pudo completar la acción.'));
                btn.disabled = false;
            }
        } catch (err) {
            alert('Error de conexión.');
            btn.disabled = false;
        }
    });
});
