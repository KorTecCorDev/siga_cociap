/**
 * conducta-tutor.js — SIGA-COCIAP · ETAPA 2 (Tutor)
 * Ingreso de la nota del tutor (00-20, opcional), recalculo de la final en vivo
 * (promedio con la de RA, .5 a favor) y cierre/aprobacion de la seccion.
 */

const BASE = document.querySelector('meta[name="base-url"]')?.content ?? '';
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

function mostrarStatus(fila, tipo, mensaje, persistente = false) {
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

function literalDe(nota) {
    if (nota >= 18) return 'AD';
    if (nota >= 14) return 'A';
    if (nota >= 11) return 'B';
    return 'C';
}

const pad2 = n => String(n).padStart(2, '0');

// Final en vivo: vacio -> nota RA; con nota -> promedio (Math.round = .5 a favor).
function recalcularFinal(fila) {
    const ra    = parseInt(fila.dataset.notaRa, 10) || 0;
    const input = fila.querySelector('.conducta-nota-tutor');
    const sNota = fila.querySelector('.conducta-final__nota');
    const sLit  = fila.querySelector('.conducta-final__lit');
    if (!sNota || !sLit) return;

    const raw = input && input.value !== '' ? parseInt(input.value, 10) : null;
    const fin = (raw === null || isNaN(raw)) ? ra : Math.round((ra + raw) / 2);
    const lit = literalDe(fin);
    const litLower = lit.toLowerCase();

    // Mismo estilo que ver-resumen: badges nota-numeral / nota-literal.
    sNota.textContent = pad2(fin);
    sNota.className = `nota-numeral nota-numeral--${litLower} conducta-final__nota`;
    sLit.textContent = lit;
    sLit.className = `nota-literal nota-literal--${litLower} conducta-final__lit`;
}

function sanear(input) {
    let v = (input.value ?? '').trim().replace(/\D/g, '');
    if (v !== '') {
        let n = parseInt(v, 10);
        if (n > 20) n = 20;
        v = String(n);
    }
    input.value = v;
}

async function guardarNota(fila) {
    const input = fila.querySelector('.conducta-nota-tutor');
    if (!input) return;
    sanear(input);

    mostrarStatus(fila, 'loading', '…', true);
    try {
        const body = new URLSearchParams({
            _csrf_token:  fila.dataset.csrf,
            matricula_id: fila.dataset.matricula,
            nota:         input.value,
        });
        const res  = await fetch(`${BASE}/docente/conducta/${fila.dataset.periodo}/nota`, { method: 'POST', body });
        const data = await res.json();

        if (data.success) {
            mostrarStatus(fila, 'success', '✓');
            mostrarFeedback('ok', '✓ Guardado');
        } else {
            mostrarStatus(fila, 'error', '⚠', true);
            mostrarFeedback('error', '⚠ ' + (data.mensaje ?? 'Error al guardar.'));
        }
    } catch (err) {
        mostrarStatus(fila, 'error', '⚠', true);
        mostrarFeedback('error', '⚠ Error de conexión.');
    }
}

document.querySelectorAll('.conducta-tutor-fila').forEach(fila => {
    const input = fila.querySelector('.conducta-nota-tutor');
    if (!input) return; // modo lectura (ya cerrado)

    input.addEventListener('input', () => recalcularFinal(fila));
    input.addEventListener('blur', () => { sanear(input); recalcularFinal(fila); guardarNota(fila); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    });
});

// Cierre de la seccion
const cerrarForm = document.getElementById('conducta-cerrar-form');
cerrarForm?.addEventListener('submit', async e => {
    e.preventDefault();
    if (!confirm('¿Cerrar y aprobar la conducta de la sección? Después de esta acción ya no podrás modificarla.')) return;

    const btn = cerrarForm.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    try {
        const body = new URLSearchParams({ _csrf_token: cerrarForm.dataset.csrf });
        const res  = await fetch(cerrarForm.dataset.action, { method: 'POST', body });
        const data = await res.json();

        mostrarFeedback(data.success ? 'ok' : 'error', (data.success ? '✓ ' : '⚠ ') + data.mensaje);
        if (data.success) {
            setTimeout(() => window.location.reload(), 800);
        } else if (btn) {
            btn.disabled = false;
        }
    } catch (err) {
        mostrarFeedback('error', '⚠ Error de conexión.');
        if (btn) btn.disabled = false;
    }
});
