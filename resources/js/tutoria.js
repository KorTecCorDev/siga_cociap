/**
 * tutoria.js — SIGA-COCIAP
 * Panel del tutor: guarda conclusiones transversales y cierra el bimestre.
 */

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const BASE = document.querySelector('meta[name="base-url"]')?.content ?? '';

function mostrarEstadoTutoria(tipo, mensaje) {
    const el = document.getElementById('tutoria-status');
    if (!el) return;
    el.textContent = mensaje;
    el.className   = `form-notas__status status--${tipo}`;
    if (tipo === 'success') {
        setTimeout(() => { el.textContent = ''; }, 4000);
    }
}

async function guardarConclusionesTransversales(periodoId) {
    const textareas = document.querySelectorAll('.textarea-conclusion-transversal');
    let guardadas = 0;
    let errores   = 0;

    for (const ta of textareas) {
        const texto = ta.value.trim();
        if (texto === (ta.dataset.guardada ?? '') && texto === ta.defaultValue.trim()) {
            continue; // sin cambios
        }

        // Las conclusiones de RÉPLICA del acta SIAGIE (data-area-id, card
        // «Conclusiones descriptivas requeridas») van a su propio endpoint.
        const esReplica = ta.dataset.areaId !== undefined;
        const formData  = new FormData();
        formData.append('_csrf_token',  CSRF);
        formData.append('matricula_id', ta.dataset.matriculaId);
        if (esReplica) {
            formData.append('area_id', ta.dataset.areaId);
        } else {
            formData.append('competencia_id', ta.dataset.competenciaId);
        }
        formData.append('conclusion', texto);
        const destino = esReplica ? 'conclusion-replica' : 'conclusion';

        try {
            const res  = await fetch(`${BASE}/docente/tutoria/${periodoId}/${destino}`,
                { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                ta.dataset.guardada = texto;
                ta.defaultValue     = texto;
                guardadas++;
            } else {
                errores++;
            }
        } catch (err) {
            errores++;
        }
    }

    if (errores > 0) {
        mostrarEstadoTutoria('error', `⚠ ${errores} conclusión(es) no se pudieron guardar.`);
    } else if (guardadas > 0) {
        mostrarEstadoTutoria('success', `✓ ${guardadas} conclusión(es) guardada(s).`);
    } else {
        mostrarEstadoTutoria('warning', 'No hay cambios que guardar.');
    }
    return errores === 0;
}

// Opción 1: las conclusiones opcionales vacías nacen colapsadas tras un enlace.
// El enlace funciona como toggle: muestra el textarea (y lo enfoca) o lo vuelve
// a ocultar. El texto del botón alterna entre "agregar" y "ocultar".
document.querySelectorAll('.tutoria-conclusion__toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const ta    = document.getElementById(btn.dataset.target);
        const campo = ta?.closest('.tutoria-conclusion__campo');
        if (!campo) return;
        const mostrar = campo.hidden;            // estaba oculto → mostrar
        campo.hidden  = !mostrar;
        btn.setAttribute('aria-expanded', mostrar ? 'true' : 'false');
        // Color estandar del proyecto: cerrado = secundario, abierto = primario.
        btn.classList.toggle('btn--primary',   mostrar);
        btn.classList.toggle('btn--secondary', !mostrar);
        if (btn.dataset.labelAbrir && btn.dataset.labelCerrar) {
            btn.textContent = mostrar ? btn.dataset.labelCerrar : btn.dataset.labelAbrir;
        }
        if (mostrar) ta?.focus();
    });
});

// Gate: "Aprobar y Bloquear" solo se habilita tras guardar las conclusiones.
// Nace deshabilitado (en la vista) y se reactiva al guardar; si luego se edita
// una conclusión, se vuelve a deshabilitar hasta guardar de nuevo.
function habilitarAprobar(habilitar) {
    const btn = document.getElementById('btn-cerrar-transversal');
    if (!btn) return;
    btn.disabled = !habilitar;
    if (habilitar) {
        btn.removeAttribute('title');
    } else {
        btn.title = 'Primero guarda las conclusiones';
    }
}

document.getElementById('btn-guardar-conclusiones-trans')
    ?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        const ok = await guardarConclusionesTransversales(btn.dataset.periodoId);
        btn.disabled = false;
        if (ok) habilitarAprobar(true);
    });

// Cualquier edición posterior invalida el guardado: re-deshabilita Aprobar.
// Ademas mantiene la marca verde del toggle en sync: punto si hay texto, sin
// punto si quedo vacio (las obligatorias no tienen toggle → btn es null y se ignora).
document.querySelectorAll('.textarea-conclusion-transversal').forEach(ta => {
    ta.addEventListener('input', () => {
        habilitarAprobar(false);
        const btn = document.querySelector(
            `.tutoria-conclusion__toggle[data-target="${ta.id}"]`
        );
        btn?.classList.toggle('tutoria-conclusion__toggle--con-texto', ta.value.trim() !== '');
    });
});

document.getElementById('btn-cerrar-transversal')
    ?.addEventListener('click', async (e) => {
        const btn       = e.currentTarget;
        const periodoId = btn.dataset.periodoId;

        // Validación rápida en cliente (el servidor revalida todo)
        const vacias = Array.from(
            document.querySelectorAll('.textarea-conclusion-transversal[data-obligatorio="1"]')
        ).filter(ta => ta.value.trim() === '');

        if (vacias.length > 0) {
            mostrarEstadoTutoria('error',
                `⚠ Falta(n) ${vacias.length} conclusión(es) obligatoria(s).`);
            vacias[0].focus();
            return;
        }

        if (!confirm(
            '¿Cerrar el bimestre transversal?\n\n' +
            'Las notas TIC/GAMA y sus conclusiones aparecerán en las boletas. ' +
            'Solo el director podrá reabrir (desbloqueando una carga de la sección).'
        )) return;

        btn.disabled = true;
        mostrarEstadoTutoria('loading', 'Guardando y cerrando...');

        // Guardar conclusiones pendientes antes del cierre
        const okGuardado = await guardarConclusionesTransversales(periodoId);
        if (!okGuardado) {
            btn.disabled = false;
            return;
        }

        try {
            const formData = new FormData();
            formData.append('_csrf_token', CSRF);

            const res  = await fetch(`${BASE}/docente/tutoria/${periodoId}/cerrar`,
                { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                mostrarEstadoTutoria('success', '✓ ' + data.mensaje);
                setTimeout(() => window.location.reload(), 900);
            } else {
                mostrarEstadoTutoria('error', '⚠ ' + data.mensaje);
                btn.disabled = false;
            }
        } catch (err) {
            mostrarEstadoTutoria('error', 'Error de conexión.');
            btn.disabled = false;
        }
    });
