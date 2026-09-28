/**
 * registro-estudiante.js — SIGA-COCIAP
 * Entrada POR ESTUDIANTE de conducta y asistencia (28/09/2026).
 *
 * 🔴 NO GUARDA POR SU CUENTA. Se carga DESPUÉS de `conducta.js` o de
 * `asistencia.js` y llama a SU `guardarFila(fila)` —la misma función que usa la
 * grilla, contra el mismo endpoint—; aquí solo vive lo propio de esta pantalla:
 *   · los botones −/+ de los contadores de asistencia,
 *   · «Guardar y siguiente» (avanza SOLO si el guardado salió bien),
 *   · el cambio de estudiante desde el selector,
 *   · el aviso del navegador si se sale con cambios sin guardar.
 */

const filaEstudiante = document.querySelector('.registro-estudiante');

if (filaEstudiante) {
    // Estado de las marcas de conducta tal como quedó guardado. Asistencia no lo
    // necesita: `asistencia.js` ya marca la fila `--con-cambios` y la limpia al guardar.
    const marcasConducta = () =>
        [...filaEstudiante.querySelectorAll('.cc-toggle')].map(t => t.dataset.valor ?? '').join(',');
    let marcasGuardadas = marcasConducta();

    const hayCambios = () =>
        filaEstudiante.classList.contains('asistencia-fila--con-cambios')
        || marcasConducta() !== marcasGuardadas;

    async function guardar() {
        const ok = await guardarFila(filaEstudiante);
        if (ok) marcasGuardadas = marcasConducta();
        return ok;
    }

    // −/+ de asistencia: mismo tope que el input (atributo max, espejo del
    // servidor). Dispara `input` para que asistencia.js recalcule los cambios.
    filaEstudiante.querySelectorAll('[data-paso]').forEach(boton => {
        boton.addEventListener('click', () => {
            const input = boton.parentElement.querySelector('.asistencia-input');
            if (!input) return;
            const max = parseInt(input.max, 10) || 99;
            const n   = (parseInt(input.value, 10) || 0) + parseInt(boton.dataset.paso, 10);
            input.value = String(Math.min(max, Math.max(0, n)));
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    // «Guardar» de conducta (el de asistencia ya lo engancha asistencia.js por
    // su clase `.asistencia-guardar`).
    filaEstudiante.querySelector('[data-accion="guardar"]')?.addEventListener('click', guardar);

    const botonSiguiente = filaEstudiante.querySelector('[data-accion="siguiente"]');
    botonSiguiente?.addEventListener('click', async () => {
        botonSiguiente.disabled = true;
        const ok = await guardar();
        if (ok) {
            window.location.href = filaEstudiante.dataset.siguiente;
            return;
        }
        botonSiguiente.disabled = false;
    });

    // El selector cambia de estudiante al elegir. Si hay cambios sin guardar,
    // el aviso de abajo pregunta antes de salir.
    const selector = document.querySelector('[data-selector-estudiante]');
    selector?.querySelector('select')?.addEventListener('change', () => selector.submit());

    window.addEventListener('beforeunload', e => {
        if (!hayCambios()) return;
        e.preventDefault();
        e.returnValue = '';
    });
}
