/**
 * asistencia.js — SIGA-COCIAP
 * Vista admin/asistencia/{seccion}: guardado por fila (batch de los 4
 * contadores). Cada fila marca --con-cambios cuando alguno de sus inputs
 * difiere de su valor inicial; al guardar exitosamente pasa a --registrada.
 */

const BASE = document.querySelector('meta[name="base-url"]')?.content ?? '';
const URL_GUARDAR = `${BASE}/admin/asistencia/guardar`;

const feedback = document.getElementById('asistencia-feedback');
let feedbackTimer;

function mostrarFeedback(tipo, mensaje) {
    if (!feedback) return;
    feedback.textContent = mensaje;
    feedback.className = `asistencia-feedback asistencia-feedback--${tipo}`;
    feedback.hidden = false;
    clearTimeout(feedbackTimer);
    feedbackTimer = setTimeout(() => { feedback.hidden = true; }, 3000);
}

function mostrarStatusFila(fila, tipo, mensaje, persistente = false) {
    const status = fila.querySelector('.asistencia-status');
    if (!status) return;
    status.textContent = mensaje;
    status.className = `asistencia-status status--${tipo}`;
    if (!persistente && tipo === 'success') {
        setTimeout(() => {
            if (status.textContent === mensaje) {
                status.textContent = '';
                status.className = 'asistencia-status';
            }
        }, 2500);
    }
}

// Compara valores como enteros. Inputs numéricos pueden traer '5' vs '05';
// los inputs type=number normalizan, pero comparamos defensivamente.
function inputDifiereDeInicial(input) {
    const inicial = parseInt(input.dataset.inicial ?? '0', 10) || 0;
    const actual  = parseInt(input.value ?? '0', 10) || 0;
    return inicial !== actual;
}

function recalcularCambiosFila(fila) {
    const hayCambios = Array.from(fila.querySelectorAll('.asistencia-input'))
        .some(inputDifiereDeInicial);
    fila.classList.toggle('asistencia-fila--con-cambios', hayCambios);
}

// Saneamiento en cliente: clamp [0..max] y solo dígitos. Espejo del servidor.
function sanearInput(input) {
    let val = (input.value ?? '').trim();
    val = val.replace(/\D/g, '');
    if (val === '') {
        input.value = '0';
        return;
    }
    const max = parseInt(input.max, 10) || 99;
    let n = parseInt(val, 10);
    if (isNaN(n) || n < 0) n = 0;
    if (n > max)            n = max;
    input.value = String(n);
}

// Devuelve true si guardó. La grilla lo ignora; la entrada por estudiante
// (registro-estudiante.js) lo usa para avanzar solo si el guardado salió bien.
async function guardarFila(fila) {
    const matriculaId = fila.dataset.matriculaId;
    const periodoId   = fila.dataset.periodoId;
    const csrf        = fila.dataset.csrf;
    const btn         = fila.querySelector('.asistencia-guardar');
    const inputs      = fila.querySelectorAll('.asistencia-input');

    // Normalizar valores antes de enviar y capturar previos para rollback.
    const valoresPrevios = {};
    const valoresEnviar  = {};
    inputs.forEach(i => {
        valoresPrevios[i.name] = i.dataset.inicial ?? '0';
        sanearInput(i);
        valoresEnviar[i.name]  = i.value;
    });

    btn.disabled = true;
    mostrarStatusFila(fila, 'loading', 'Guardando…', true);

    try {
        const body = new URLSearchParams({
            _csrf_token:  csrf,
            matricula_id: matriculaId,
            periodo_id:   periodoId,
            ...valoresEnviar,
        });

        const res  = await fetch(URL_GUARDAR, { method: 'POST', body });
        const data = await res.json();

        if (data.success) {
            // Sincroniza el estado: los valores enviados son ahora el nuevo
            // "inicial" para futuros diffs. La fila se marca como registrada
            // y se limpia el ámbar de cambios pendientes.
            inputs.forEach(i => { i.dataset.inicial = i.value; });
            fila.classList.add('asistencia-fila--registrada');
            recalcularCambiosFila(fila);
            mostrarStatusFila(fila, 'success', '✓ Guardado');
            mostrarFeedback('ok', '✓ Guardado');
            return true;
        } else {
            // Rollback: restaurar los inputs y mantener el estado anterior.
            inputs.forEach(i => { i.value = valoresPrevios[i.name]; });
            recalcularCambiosFila(fila);
            mostrarStatusFila(fila, 'error', '⚠ ' + (data.mensaje ?? 'Error.'), true);
            mostrarFeedback('error', '⚠ ' + (data.mensaje ?? 'Error al guardar.'));
            return false;
        }
    } catch (err) {
        inputs.forEach(i => { i.value = valoresPrevios[i.name]; });
        recalcularCambiosFila(fila);
        mostrarStatusFila(fila, 'error', '⚠ Error de conexión.', true);
        mostrarFeedback('error', '⚠ Error de conexión.');
        return false;
    } finally {
        btn.disabled = false;
    }
}

// Registrar listeners en cada fila.
document.querySelectorAll('.asistencia-fila').forEach(fila => {
    const inputs = fila.querySelectorAll('.asistencia-input');
    const btn    = fila.querySelector('.asistencia-guardar');

    inputs.forEach(input => {
        // Detección de cambios en cada tecla.
        input.addEventListener('input', () => recalcularCambiosFila(fila));

        // Al perder foco: saneamos y recalculamos (puede haber clamp).
        input.addEventListener('blur', () => {
            sanearInput(input);
            recalcularCambiosFila(fila);
        });

        // Enter en cualquier input → guardar la fila completa.
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                guardarFila(fila);
            }
        });
    });

    btn?.addEventListener('click', () => guardarFila(fila));
});
