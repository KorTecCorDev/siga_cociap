/**
 * asistencia-fechas.js — SIGA-COCIAP
 * ASISTENCIA POR FECHAS con AUTOGUARDADO y CONFIRMACIÓN (29/09/2026, migración 069).
 *
 * Lo usan la grilla mensual (`_grilla-fechas.php`) y la vista por estudiante
 * (`estudiante.php` en un bimestre por fechas); las dos respetan el mismo
 * contrato de DOM (ver la cabecera de `_grilla-fechas.php`).
 *
 *   - Tocar un día recorre · → F → FJ → T → TJ → · y lo AUTOGUARDA como borrador
 *     (`/admin/asistencia/dia`). Los 4 contadores los calcula el SERVIDOR de las
 *     fechas y vuelven en la respuesta: aquí no se suma nada.
 *   - Al llegar a FJ/TJ se abre el select del motivo. Puede quedar vacío: se
 *     exige al CONFIRMAR, no al marcar (decisión del usuario).
 *   - «Confirmar» da el visto bueno (`/admin/asistencia/confirmar`); cambiar
 *     cualquier día lo desconfirma.
 *
 * 🔴 LOS ENVÍOS DE UNA FILA VAN EN COLA (`fila._cola`), como en conducta.js:
 * dos toques rápidos en la misma celda no pueden llegar en desorden.
 *
 * Expone `confirmarAsistencia(fila)` y `asistenciaSinGuardar(fila)` para
 * `registro-estudiante.js`.
 *
 * La GRILLA no tiene columna «Estado» (29/09/2026): `.af-estado`, `.af-confirmar`
 * y `.asistencia-status` solo existen en la vista por estudiante y aquí son
 * opcionales. En la grilla el estado lo dice la franja del N° (clases de fila) y
 * un guardado fallido, la celda roja, la franja roja y el aviso global.
 */

const BASE_AF = document.querySelector('meta[name="base-url"]')?.content ?? '';
const URL_DIA       = `${BASE_AF}/admin/asistencia/dia`;
const URL_CONFIRMAR = `${BASE_AF}/admin/asistencia/confirmar`;
const CICLO         = ['', 'F', 'FJ', 'T', 'TJ'];
const JUSTIFICADAS  = ['FJ', 'TJ'];

const feedbackAf = document.getElementById('asistencia-feedback');
let feedbackAfTimer;

function avisar(tipo, mensaje) {
    if (!feedbackAf) return;
    feedbackAf.textContent = mensaje;
    feedbackAf.className = `asistencia-feedback asistencia-feedback--${tipo}`;
    feedbackAf.hidden = false;
    clearTimeout(feedbackAfTimer);
    feedbackAfTimer = setTimeout(() => { feedbackAf.hidden = true; }, 3500);
}

function statusFila(fila, tipo, mensaje) {
    const s = fila.querySelector('.asistencia-status');
    if (!s) return;
    s.textContent = mensaje;
    s.className = `asistencia-status status--${tipo}`;
}

// Pinta una celda según su tipo y su motivo («!» = justificada sin motivo).
// Solo toca las clases de TIPO: la celda grande de la vista por estudiante
// lleva además su columna (`af-col-N`) y el número del día (`.af-celda__num`).
function pintarCelda(celda) {
    const tipo = celda.dataset.tipo ?? '';
    CICLO.filter(Boolean).forEach(t => celda.classList.remove(`af-celda--${t.toLowerCase()}`));
    if (tipo) celda.classList.add(`af-celda--${tipo.toLowerCase()}`);
    const sinMotivo = JUSTIFICADAS.includes(tipo) && !celda.dataset.motivo;
    const conMotivo = JUSTIFICADAS.includes(tipo) && !!celda.dataset.motivo;
    celda.classList.toggle('af-celda--sin-motivo', sinMotivo);
    // Icono de documento = la justificación YA tiene motivo (29/09/2026).
    celda.classList.toggle('af-celda--con-motivo', conMotivo);
    const texto = celda.querySelector('.af-celda__tipo') ?? celda;
    texto.textContent = tipo + (sinMotivo ? '!' : '');
    // El nombre del motivo, al pasar el cursor o mantener pulsado.
    const nombre = conMotivo ? nombreMotivo(celda.dataset.motivo) : '';
    celda.title = celda.dataset.fecha + (nombre ? ` · ${nombre}` : (sinMotivo ? ' · Falta el motivo' : ''));
}

// Nombre de un motivo, leído del select del catálogo (no hay copia en el JS).
function nombreMotivo(id) {
    return document.querySelector(`#af-motivo-select option[value="${id}"]`)?.textContent ?? '';
}

// Cantidad de justificaciones SIN motivo de la fila (las de este mes, más las
// de otros meses que el servidor contó en `data-sin-motivo-otros`).
function sinMotivo(fila) {
    const visibles = [...fila.querySelectorAll('.af-celda[data-fecha]')]
        .filter(c => JUSTIFICADAS.includes(c.dataset.tipo) && !c.dataset.motivo).length;
    return visibles + (parseInt(fila.dataset.sinMotivoOtros, 10) || 0);
}

// Estado visible: Confirmado / Falta motivo / Sin confirmar / Sin registrar.
function pintarEstado(fila) {
    const confirmada = fila.dataset.confirmada === '1';
    const faltan     = sinMotivo(fila);
    fila.classList.toggle('asistencia-fila--registrada', confirmada);
    fila.classList.toggle('asistencia-fila--con-cambios', !confirmada && fila.dataset.registrada === '1');

    const estado = fila.querySelector('.af-estado');
    if (estado) {
        let txt = '✓ Confirmado', mod = 'ok';
        if (!confirmada) {
            mod = 'pendiente';
            txt = faltan > 0 ? `Falta motivo (${faltan})`
                : (fila.dataset.registrada === '1' ? 'Sin confirmar' : 'Sin registrar');
        }
        estado.textContent = txt;
        estado.className = `af-estado af-estado--${mod}`;
    }
    const btn = fila.querySelector('.af-confirmar');
    if (btn) {
        btn.hidden   = confirmada;
        btn.disabled = faltan > 0;
    }
    pintarJustificaciones(fila);
}

// Contadores del BIMESTRE devueltos por el servidor (fuente única).
function pintarTotales(fila, c) {
    if (!c) return;
    fila.querySelectorAll('.af-total[data-campo]').forEach(td => {
        td.textContent = c[td.dataset.campo] ?? 0;
    });
    fila.dataset.confirmada = c.confirmado ? '1' : '0';
    fila.dataset.registrada = '1';
}

function encolarAf(fila, tarea) {
    fila._pendientes = (fila._pendientes ?? 0) + 1;
    const p = (fila._cola ?? Promise.resolve()).then(tarea, tarea);
    fila._cola = p.finally(() => { fila._pendientes--; });
    return p;
}

function asistenciaSinGuardar(fila) {
    return (fila._pendientes ?? 0) > 0 || fila.dataset.errorGuardado === '1';
}

async function postearAf(url, fila, extra) {
    const body = new URLSearchParams({
        _csrf_token:  fila.dataset.csrf,
        matricula_id: fila.dataset.matriculaId,
        periodo_id:   fila.dataset.periodoId,
        ...extra,
    });
    const res = await fetch(url, { method: 'POST', body });
    return res.json();
}

// AUTOGUARDADO de un día (tipo vacío = desmarcar).
function guardarDia(fila, celda) {
    const datos = { fecha: celda.dataset.fecha, tipo: celda.dataset.tipo ?? '', motivo_id: celda.dataset.motivo ?? '' };
    return encolarAf(fila, async () => {
        try {
            const data = await postearAf(URL_DIA, fila, datos);
            if (data.success) {
                fila.dataset.errorGuardado = '0';
                fila.classList.remove('asistencia-fila--error');
                pintarTotales(fila, data.contadores);
                statusFila(fila, 'success', '');
                pintarEstado(fila);
                return true;
            }
            fila.dataset.errorGuardado = '1';
            fila.classList.add('asistencia-fila--error');
            celda.classList.add('af-celda--error');
            statusFila(fila, 'error', '⚠ ' + (data.mensaje ?? 'No se guardó'));
            avisar('error', '⚠ ' + (data.mensaje ?? 'No se guardó el día.'));
            return false;
        } catch {
            fila.dataset.errorGuardado = '1';
            fila.classList.add('asistencia-fila--error');
            celda.classList.add('af-celda--error');
            statusFila(fila, 'error', '⚠ Sin conexión: no se guardó');
            avisar('error', '⚠ Sin conexión: el día no se guardó.');
            return false;
        }
    });
}

// CONFIRMAR un estudiante: espera la cola y pide el visto bueno al servidor.
async function confirmarAsistencia(fila) {
    const faltan = sinMotivo(fila);
    if (faltan > 0) {
        statusFila(fila, 'error', '⚠ Falta el motivo');
        avisar('error', `⚠ Elige el motivo de ${faltan} justificación(es) antes de confirmar.`);
        return false;
    }
    const btn = fila.querySelector('.af-confirmar');
    if (btn) btn.disabled = true;
    statusFila(fila, 'loading', 'Confirmando…');

    return encolarAf(fila, async () => {
        try {
            const data = await postearAf(URL_CONFIRMAR, fila, {});
            if (data.success) {
                fila.dataset.errorGuardado = '0';
                pintarTotales(fila, data.contadores);
                statusFila(fila, 'success', '');
                pintarEstado(fila);
                avisar('ok', '✓ Confirmado');
                return true;
            }
            statusFila(fila, 'error', '⚠ ' + (data.mensaje ?? 'Error'));
            avisar('error', '⚠ ' + (data.mensaje ?? 'Error al confirmar.'));
            pintarEstado(fila);
            return false;
        } catch {
            statusFila(fila, 'error', '⚠ Error de conexión');
            avisar('error', '⚠ Error de conexión.');
            pintarEstado(fila);
            return false;
        }
    });
}

// ── Select del motivo (un solo elemento, anclado a la celda) ────────
const popover = document.getElementById('af-motivo');
const selectMotivo = popover?.querySelector('select');
let celdaMotivo = null;

function abrirMotivo(celda) {
    if (!popover) return;
    celdaMotivo = celda;
    selectMotivo.value = celda.dataset.motivo ?? '';
    const r = celda.getBoundingClientRect();
    popover.hidden = false;
    popover.style.top  = `${window.scrollY + r.bottom + 6}px`;
    popover.style.left = `${Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - popover.offsetWidth - 8))}px`;
    selectMotivo.focus();
}

function cerrarMotivo() {
    if (popover) popover.hidden = true;
    celdaMotivo = null;
}

selectMotivo?.addEventListener('change', () => {
    const celda = celdaMotivo;
    if (!celda) return;
    const fila = celda.closest('.af-fila');
    celda.dataset.motivo = selectMotivo.value;
    pintarCelda(celda);
    fila.dataset.confirmada = '0';
    pintarEstado(fila);
    guardarDia(fila, celda);
    cerrarMotivo();
});
popover?.querySelector('.af-motivo__cerrar')?.addEventListener('click', cerrarMotivo);
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarMotivo(); });
document.addEventListener('click', e => {
    if (popover && !popover.hidden && !popover.contains(e.target) && e.target !== celdaMotivo) cerrarMotivo();
});

// ── Lista de justificaciones (solo vista por estudiante) ────────────
// Muestra cada FJ/TJ con su motivo y permite cambiarlo sin tener que recorrer
// el ciclo de la celda otra vez.
function pintarJustificaciones(fila) {
    const lista = fila.querySelector('.af-justificaciones');
    if (!lista) return;
    const celdas = [...fila.querySelectorAll('.af-celda[data-fecha]')]
        .filter(c => JUSTIFICADAS.includes(c.dataset.tipo));
    lista.innerHTML = '';
    lista.hidden = celdas.length === 0;
    celdas.forEach(c => {
        const li = document.createElement('li');
        li.className = 'af-justificaciones__item';
        const [a, m, d] = c.dataset.fecha.split('-');
        const nombre = c.dataset.motivo ? (nombreMotivo(c.dataset.motivo) || 'Motivo') : 'Falta el motivo';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn--secondary btn--sm' + (c.dataset.motivo ? '' : ' af-justificaciones__falta');
        btn.textContent = `${c.dataset.tipo} ${d}/${m}: ${nombre}`;
        btn.addEventListener('click', e => { e.stopPropagation(); abrirMotivo(c); });
        li.appendChild(btn);
        lista.appendChild(li);
    });
}

// ── Enganche ────────────────────────────────────────────────────────
document.querySelectorAll('.af-fila').forEach(fila => {
    fila.querySelectorAll('button.af-celda[data-fecha]').forEach(celda => {
        pintarCelda(celda);
        celda.addEventListener('click', e => {
            e.stopPropagation();
            const actual = celda.dataset.tipo ?? '';
            const sig    = CICLO[(CICLO.indexOf(actual) + 1) % CICLO.length];
            celda.dataset.tipo = sig;
            // El motivo solo vive en FJ/TJ; al pasar de FJ a TJ se conserva.
            if (!JUSTIFICADAS.includes(sig)) celda.dataset.motivo = '';
            celda.classList.remove('af-celda--error');
            pintarCelda(celda);
            fila.dataset.confirmada = '0';
            pintarEstado(fila);
            guardarDia(fila, celda);
            if (JUSTIFICADAS.includes(sig) && !celda.dataset.motivo) {
                abrirMotivo(celda);
            } else {
                cerrarMotivo();
            }
        });
    });
    fila.querySelector('.af-confirmar')?.addEventListener('click', () => confirmarAsistencia(fila));
    pintarEstado(fila);
});

// «Confirmar todo»: espera a que se vacíen las colas antes de enviar el formulario.
document.querySelector('.af-confirmar-todo')?.addEventListener('submit', async e => {
    const form = e.currentTarget;
    if (form.dataset.listo === '1') return;
    e.preventDefault();
    const btn = form.querySelector('button');
    if (btn) btn.disabled = true;
    await Promise.all([...document.querySelectorAll('.af-fila')].map(f => f._cola ?? Promise.resolve()));
    form.dataset.listo = '1';
    form.submit();
});

// Salir con envíos en vuelo o con un día que no se guardó pierde datos: avisar.
// La vista por estudiante tiene su propio aviso (`registro-estudiante.js`).
if (!document.querySelector('.registro-estudiante')) {
    window.addEventListener('beforeunload', e => {
        if (![...document.querySelectorAll('.af-fila')].some(asistenciaSinGuardar)) return;
        e.preventDefault();
        e.returnValue = '';
    });
}
