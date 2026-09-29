/**
 * registro-estudiante.js — SIGA-COCIAP
 * Entrada POR ESTUDIANTE de conducta y asistencia (28/09/2026).
 *
 * 🔴 NO GUARDA POR SU CUENTA. Se carga DESPUÉS de `conducta.js` o de
 * `asistencia.js` y usa SUS funciones —las mismas de la grilla, contra los mismos
 * endpoints—; aquí solo vive lo propio de esta pantalla:
 *   · los botones −/+ de los contadores de asistencia,
 *   · «Confirmar y siguiente →» (avanza SOLO si salió bien),
 *   · el cambio de estudiante desde el selector,
 *   · el aviso del navegador si se sale con algo sin guardar.
 *
 * CONDUCTA (29/09/2026): cada marca se AUTOGUARDA en `conducta.js`; el avance
 * CONFIRMA con `confirmarFila`. ASISTENCIA POR FECHAS: igual, con
 * `asistencia-fechas.js` (`confirmarAsistencia`). ASISTENCIA de un bimestre de
 * solo números (histórico): la fila entera con `guardarFila` de `asistencia.js`.
 */

const filaEstudiante = document.querySelector('.registro-estudiante');

if (filaEstudiante) {
    const esConducta = filaEstudiante.classList.contains('conducta-fila');
    const esFechas   = filaEstudiante.classList.contains('af-fila');

    // Conducta y fechas: envíos en vuelo o autoguardado fallido.
    // Asistencia por números: la fila marcada `--con-cambios` por `asistencia.js`.
    const hayCambios = () => {
        if (esConducta) return filaSinGuardar(filaEstudiante);
        if (esFechas)   return asistenciaSinGuardar(filaEstudiante);
        return filaEstudiante.classList.contains('asistencia-fila--con-cambios');
    };

    const accionFinal = () => {
        if (esConducta) return confirmarFila(filaEstudiante);
        if (esFechas)   return confirmarAsistencia(filaEstudiante);
        return guardarFila(filaEstudiante);
    };

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

    const botonSiguiente = filaEstudiante.querySelector('[data-accion="siguiente"]');
    botonSiguiente?.addEventListener('click', async () => {
        botonSiguiente.disabled = true;
        const ok = await accionFinal();
        if (ok) {
            window.location.href = filaEstudiante.dataset.siguiente;
            return;
        }
        botonSiguiente.disabled = false;
    });

    // El selector cambia de estudiante al elegir. Si hay algo sin guardar, el
    // aviso de abajo pregunta antes de salir.
    const selector = document.querySelector('[data-selector-estudiante]');
    selector?.querySelector('select')?.addEventListener('change', () => selector.submit());

    window.addEventListener('beforeunload', e => {
        if (!hayCambios()) return;
        e.preventDefault();
        e.returnValue = '';
    });
}
