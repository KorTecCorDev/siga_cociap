/**
 * conducta.js — SIGA-COCIAP · ETAPA 1 (auxiliar académico / Registro Académico)
 * Grilla de criterios Si/No con AUTOGUARDADO y CONFIRMACIÓN (29/09/2026).
 *
 * Mismo flujo que los docentes:
 *   - Cada toque ✓/✗ se guarda al instante como BORRADOR (`/admin/conducta/guardar`).
 *     Un borrador queda «al aire»: no cuenta en boleta, tutor ni cuadros.
 *   - «Confirmar» (por fila) guarda los N criterios y da el visto bueno
 *     (`/admin/conducta/confirmar`). Cambiar algo confirmado lo desconfirma.
 *   - «Bloquear y aprobar» sigue siendo el visto final (formulario de la vista).
 *
 * 🔴 LOS ENVÍOS DE UNA FILA VAN EN COLA. Tocar ✓ y luego ✗ rápido lanzaría dos
 * peticiones que pueden llegar al servidor en desorden y dejar guardada la
 * PRIMERA marca. La cola (`fila._cola`) las serializa, y «Confirmar» espera a que
 * se vacíe antes de enviar.
 *
 * Lo usa también la vista por estudiante (`registro-estudiante.js` llama a
 * `confirmarFila`): el contrato de DOM es el mismo (`.conducta-fila`,
 * `data-matricula`, `data-periodo`, `data-csrf`, `data-total`, `.cc-toggle`,
 * `.cc-btn[data-v]`, `.cc-nota`, `.conducta-status`, `.conducta-confirmar`).
 * La GRILLA no tiene `.conducta-status` ni `.conducta-confirmar` (sin columna
 * «Estado», 29/09/2026): aquí son opcionales. En la grilla, un autoguardado
 * fallido pinta la franja ROJA del N° (`conducta-fila--error`) y el aviso global.
 * La grilla usa `[data-formato="numeral"]` y `[data-formato="literal"]` en lugar
 * de `.cc-nota`: la nota sale como badge y el literal va en su propia columna.
 */

const BASE = document.querySelector('meta[name="base-url"]')?.content ?? '';
const URL_BORRADOR  = `${BASE}/admin/conducta/guardar`;
const URL_CONFIRMAR = `${BASE}/admin/conducta/confirmar`;

const feedback = document.getElementById('conducta-feedback');
let feedbackTimer;

function mostrarFeedback(tipo, mensaje) {
    if (!feedback) return;
    feedback.textContent = mensaje;
    feedback.className = `conducta-feedback conducta-feedback--${tipo}`;
    feedback.hidden = false;
    clearTimeout(feedbackTimer);
    feedbackTimer = setTimeout(() => { feedback.hidden = true; }, 3000);
}

function mostrarStatusFila(fila, tipo, mensaje, persistente = false) {
    const status = fila.querySelector('.conducta-status');
    if (!status) return;
    status.textContent = mensaje;
    status.className = `conducta-status status--${tipo}`;
    if (!persistente && tipo === 'success') {
        setTimeout(() => {
            if (status.textContent === mensaje) {
                status.textContent = '';
                status.className = 'conducta-status';
            }
        }, 2500);
    }
}

// Literal a partir de la nota (espejo de nota_a_literal en PHP: 18/14/11).
function literalDe(nota) {
    if (nota >= 18) return 'AD';
    if (nota >= 14) return 'A';
    if (nota >= 11) return 'B';
    return 'C';
}

// Nota RA en vivo: se actualiza con cada marca, en la grilla y en la vista por
// estudiante (ver abajo).
function recalcularNotaFila(fila) {
    const toggles = fila.querySelectorAll('.cc-toggle');
    const total   = parseInt(fila.dataset.total, 10) || toggles.length;
    let si = 0, respondidos = 0;
    toggles.forEach(t => {
        const v = t.dataset.valor;
        if (v === '1' || v === '0') respondidos++;
        if (v === '1') si++;
    });
    // Grilla de la sección (30/09/2026): numeral y literal en columnas separadas,
    // con EXACTAMENTE las clases del solo lectura y de la grilla del tutor. Se
    // ubican por `data-formato`, SIN `.cc-nota`: esa clase trae color gris y, al
    // cargarse después que `.nota-numeral--*` con la misma especificidad, dejaba
    // el número y su contorno en gris.
    // NOTA EN VIVO (decisión del usuario, 30/09/2026): se actualiza con CADA marca,
    // Sí ÷ N × 20 con lo no respondido contado como No — si se deja así, ya es la
    // nota final, y nunca muestra algo que luego baje. Mismo badge que la definitiva.
    // Sin ninguna marca no hay dato: guion.
    const num = fila.querySelector('[data-formato="numeral"]');
    if (num) {
        const lit = fila.querySelector('[data-formato="literal"]');
        if (respondidos === 0) {
            num.textContent = '—';
            num.className = 'text-muted';
            if (lit) { lit.textContent = '—'; lit.className = 'text-muted'; }
        } else {
            const nota  = Math.round((si / total) * 20);
            const clave = literalDe(nota).toLowerCase();
            num.textContent = String(nota).padStart(2, '0'); // espejo de fmt_nota()
            num.className = `nota-numeral nota-numeral--${clave}`;
            if (lit) { lit.textContent = literalDe(nota); lit.className = `nota-literal nota-literal--${clave}`; }
        }
        return;
    }
    // Vista por estudiante: la MISMA regla en vivo (30/09/2026), con su formato
    // propio «15 (A)».
    const span = fila.querySelector('.cc-nota');
    if (!span) return;
    if (respondidos === 0) {
        span.textContent = '—';
        span.className = 'cc-nota';
    } else {
        const nota = Math.round((si / total) * 20); // lo no respondido cuenta como No
        span.textContent = `${nota} (${literalDe(nota)})`;
        span.className = `cc-nota cc-nota--${literalDe(nota).toLowerCase()}`;
    }
}

function pintarToggle(toggle) {
    const v = toggle.dataset.valor;
    toggle.querySelectorAll('.cc-btn').forEach(b => {
        const activo = b.dataset.v === v;
        b.classList.toggle('cc-btn--activo', activo);
        b.setAttribute('aria-pressed', activo ? 'true' : 'false');
    });
}

// true si la fila tiene los N criterios respondidos.
function filaCompleta(fila) {
    return [...fila.querySelectorAll('.cc-toggle')]
        .every(t => t.dataset.valor === '1' || t.dataset.valor === '0');
}

// Estado visible de la fila. `guardada` = CONFIRMADA (barra verde);
// `pendiente` = borrador o incompleta (ámbar). El botón Confirmar solo se
// habilita con los N criterios respondidos y sin confirmar.
function marcarEstado(fila, confirmada) {
    fila.classList.toggle('conducta-fila--guardada', confirmada);
    fila.classList.toggle('conducta-fila--pendiente', !confirmada);
    fila.dataset.confirmada = confirmada ? '1' : '0';
    const btn = fila.querySelector('.conducta-confirmar');
    if (btn) {
        btn.hidden   = confirmada;
        btn.disabled = !filaCompleta(fila);
    }
    const estado = fila.querySelector('.conducta-estado');
    if (estado) {
        estado.textContent = confirmada ? '✓ Confirmado' : (filaCompleta(fila) ? 'Sin confirmar' : 'Incompleto');
        estado.className   = `conducta-estado conducta-estado--${confirmada ? 'ok' : 'pendiente'}`;
    }
}

// Cola de envíos por fila (ver cabecera). Devuelve la promesa del envío.
function encolar(fila, tarea) {
    fila._pendientes = (fila._pendientes ?? 0) + 1;
    const p = (fila._cola ?? Promise.resolve()).then(tarea, tarea);
    fila._cola = p.finally(() => { fila._pendientes--; });
    return p;
}

// true si la fila tiene envíos en vuelo o un autoguardado fallido.
function filaSinGuardar(fila) {
    return (fila._pendientes ?? 0) > 0 || fila.dataset.errorGuardado === '1';
}

async function postear(url, fila, respuestas) {
    const body = new URLSearchParams();
    body.append('_csrf_token', fila.dataset.csrf);
    body.append('matricula_id', fila.dataset.matricula);
    body.append('periodo_id', fila.dataset.periodo);
    Object.entries(respuestas).forEach(([cid, v]) => body.append(`respuestas[${cid}]`, v));
    const res = await fetch(url, { method: 'POST', body });
    return res.json();
}

// AUTOGUARDADO de un borrador: una o varias respuestas de la fila.
function autoguardar(fila, respuestas) {
    return encolar(fila, async () => {
        try {
            const data = await postear(URL_BORRADOR, fila, respuestas);
            if (data.success) {
                fila.dataset.errorGuardado = '0';
                fila.classList.remove('conducta-fila--error');
                marcarEstado(fila, !!data.confirmado);
                if (fila.querySelector('.conducta-status')?.classList.contains('status--error')) {
                    mostrarStatusFila(fila, 'success', 'Guardado');
                }
                return true;
            }
            fila.dataset.errorGuardado = '1';
            fila.classList.add('conducta-fila--error');
            mostrarStatusFila(fila, 'error', '⚠ ' + (data.mensaje ?? 'No se guardó'), true);
            mostrarFeedback('error', '⚠ ' + (data.mensaje ?? 'No se guardó el cambio.'));
            return false;
        } catch {
            fila.dataset.errorGuardado = '1';
            fila.classList.add('conducta-fila--error');
            mostrarStatusFila(fila, 'error', '⚠ Sin conexión: no se guardó', true);
            mostrarFeedback('error', '⚠ Sin conexión: el cambio no se guardó.');
            return false;
        }
    });
}

// CONFIRMAR una fila: espera la cola, envía los N criterios y confirma.
// En modo silencioso no dispara el toast global (lo usa «Confirmar todo»).
// Devuelve true si quedó confirmada.
async function confirmarFila(fila, silencioso = false) {
    if (!filaCompleta(fila)) {
        mostrarStatusFila(fila, 'error', '⚠ Responde todos', true);
        if (!silencioso) mostrarFeedback('error', '⚠ Faltan criterios por responder.');
        return false;
    }
    const respuestas = {};
    fila.querySelectorAll('.cc-toggle').forEach(t => { respuestas[t.dataset.criterio] = t.dataset.valor; });

    const btn = fila.querySelector('.conducta-confirmar');
    if (btn) btn.disabled = true;
    mostrarStatusFila(fila, 'loading', 'Confirmando…', true);

    return encolar(fila, async () => {
        try {
            const data = await postear(URL_CONFIRMAR, fila, respuestas);
            if (data.success) {
                fila.dataset.errorGuardado = '0';
                fila.classList.remove('conducta-fila--error');
                marcarEstado(fila, true);
                mostrarStatusFila(fila, 'success', '');
                if (!silencioso) mostrarFeedback('ok', '✓ Confirmado');
                return true;
            }
            marcarEstado(fila, false);
            mostrarStatusFila(fila, 'error', '⚠ ' + (data.mensaje ?? 'Error'), true);
            if (!silencioso) mostrarFeedback('error', '⚠ ' + (data.mensaje ?? 'Error al confirmar.'));
            return false;
        } catch {
            marcarEstado(fila, false);
            mostrarStatusFila(fila, 'error', '⚠ Error de conexión', true);
            if (!silencioso) mostrarFeedback('error', '⚠ Error de conexión.');
            return false;
        }
    });
}

// «Confirmar todo»: confirma las filas completas sin confirmar. Las incompletas
// se saltan y se informan.
async function confirmarTodas(boton) {
    const filas      = [...document.querySelectorAll('.conducta-fila')].filter(f => f.dataset.confirmada !== '1');
    const completas  = filas.filter(filaCompleta);
    const incompletas = filas.length - completas.length;

    if (completas.length === 0) {
        mostrarFeedback(incompletas ? 'error' : 'ok',
            incompletas
                ? `⚠ ${incompletas} estudiante(s) tienen criterios sin responder.`
                : 'No hay estudiantes por confirmar.');
        return;
    }

    if (boton) boton.disabled = true;
    let ok = 0, fail = 0;
    for (const fila of completas) {
        (await confirmarFila(fila, true)) ? ok++ : fail++;
    }

    // Todo confirmado y nada a medias → recargar para mostrar el estado de la BD
    // (y habilitar «Bloquear y aprobar», que se decide en el servidor).
    if (fail === 0 && incompletas === 0) {
        mostrarFeedback('ok', `✓ ${ok} confirmado(s). Actualizando…`);
        setTimeout(() => window.location.reload(), 700);
        return;
    }

    if (boton) boton.disabled = false;
    const extra = incompletas ? ` · ${incompletas} sin completar` : '';
    if (fail === 0) mostrarFeedback('ok', `✓ ${ok} confirmado(s)${extra}. Completa los que faltan.`);
    else            mostrarFeedback('error', `Confirmados ${ok}, con error ${fail}${extra}.`);
}

// «Marcar Sí»: rellena ✓ en los criterios SIN responder y los AUTOGUARDA como
// borrador (una petición por fila). Nunca pisa una marca existente.
function autollenarSi() {
    let celdas = 0, filas = 0;
    document.querySelectorAll('.conducta-fila').forEach(fila => {
        const nuevas = {};
        fila.querySelectorAll('.cc-toggle').forEach(toggle => {
            const v = toggle.dataset.valor;
            if (v === '1' || v === '0') return;                    // ya respondida → no tocar
            if (toggle.querySelector('.cc-btn')?.disabled) return; // seccion bloqueada
            toggle.dataset.valor = '1';
            pintarToggle(toggle);
            nuevas[toggle.dataset.criterio] = '1';
            celdas++;
        });
        if (Object.keys(nuevas).length) {
            recalcularNotaFila(fila);
            marcarEstado(fila, false);
            autoguardar(fila, nuevas);
            filas++;
        }
    });

    if (celdas === 0) {
        mostrarFeedback('ok', 'No quedaban criterios sin responder.');
    } else {
        mostrarFeedback('ok', `✓ ${celdas} criterio(s) marcados en ${filas} estudiante(s). Revisa las excepciones y confirma.`);
    }
}

document.getElementById('conducta-autollenar')?.addEventListener('click', () => {
    if (!confirm('¿Marcar ✓ (Sí) en todos los criterios sin responder?\nNo cambia las marcas existentes. Luego revisa las excepciones y confirma.')) return;
    autollenarSi();
});

document.getElementById('conducta-confirmar-todos')?.addEventListener('click', e => {
    confirmarTodas(e.currentTarget);
});

document.querySelectorAll('.conducta-fila').forEach(fila => {
    fila.querySelectorAll('.cc-toggle').forEach(toggle => {
        toggle.addEventListener('click', e => {
            const b = e.target.closest('.cc-btn');
            if (!b || b.disabled || !toggle.contains(b)) return;
            if (toggle.dataset.valor === b.dataset.v) return; // misma marca: no es un cambio
            toggle.dataset.valor = b.dataset.v;
            pintarToggle(toggle);
            recalcularNotaFila(fila);
            marcarEstado(fila, false); // cambiar algo confirmado lo desconfirma
            autoguardar(fila, { [toggle.dataset.criterio]: b.dataset.v });
        });
    });
    fila.querySelector('.conducta-confirmar')?.addEventListener('click', () => confirmarFila(fila));
    recalcularNotaFila(fila); // nota inicial al cargar
    marcarEstado(fila, fila.dataset.confirmada === '1');
});

// Salir con envíos en vuelo o con un autoguardado fallido pierde datos: avisar.
// La vista por estudiante tiene su propio aviso (`registro-estudiante.js`).
if (!document.querySelector('.registro-estudiante')) {
    window.addEventListener('beforeunload', e => {
        if (![...document.querySelectorAll('.conducta-fila')].some(filaSinGuardar)) return;
        e.preventDefault();
        e.returnValue = '';
    });
}
