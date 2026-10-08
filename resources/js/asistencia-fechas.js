/**
 * asistencia-fechas.js — SIGA-COCIAP
 * ASISTENCIA POR FECHAS con AUTOGUARDADO y CONFIRMACIÓN (29/09/2026, migración 069)
 * y LISTA DEL DÍA + MENÚ POR CELDA (30/09/2026, migración 070).
 *
 * Lo usan la grilla mensual (`_grilla-fechas.php`) y la vista por estudiante
 * (`estudiante.php` en un bimestre por fechas); las dos respetan el mismo
 * contrato de DOM (ver la cabecera de `_grilla-fechas.php` y `_af-celda.php`).
 *
 *   - Tocar un día abre el MENÚ (`#af-menu`): ✓ Asistió · F · T guardan al
 *     tocarlos; FJ · TJ piden el MOTIVO y solo se guardan con él. Cancelar,
 *     tocar fuera o Esc NO cambian nada. Una justificada sin motivo no existe
 *     (lo rechazan también el servidor y un CHECK en la base).
 *   - Cada marca se AUTOGUARDA como borrador (`/admin/asistencia/dia`) y toma la
 *     LISTA DEL DÍA de la sección: toda la columna pasa a ✓ salvo las
 *     incidencias. Los 4 contadores los calcula el SERVIDOR: aquí no se suma nada.
 *   - «Pasar lista» (encabezado del día o «Pasar lista de hoy») y «Deshacer
 *     lista» van por `/admin/asistencia/{id}/jornada`.
 *   - «Confirmar» da el visto bueno (`/admin/asistencia/confirmar`); cambiar
 *     cualquier día lo desconfirma.
 *
 * 🔴 LOS ENVÍOS DE UNA FILA VAN EN COLA (`fila._cola`), como en conducta.js:
 * dos marcas rápidas en la misma fila no pueden llegar en desorden.
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
const JUSTIFICADAS  = ['FJ', 'TJ'];
// Clases de estado de una celda: las mismas que pinta `_af-celda.php`.
const CLASES_CELDA  = ['af-celda--f', 'af-celda--fj', 'af-celda--t', 'af-celda--tj',
                       'af-celda--asistio', 'af-celda--sin-tomar', 'af-celda--con-motivo'];

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

// «16/09» a partir de «2026-09-16».
function ddmm(fecha) {
    return `${fecha.slice(8, 10)}/${fecha.slice(5, 7)}`;
}

// Nombre de un motivo, leído del select del catálogo (no hay copia en el JS).
function nombreMotivo(id) {
    return document.querySelector(`#af-menu-motivo option[value="${id}"]`)?.textContent ?? '';
}

// ¿Es el MOTIVO PRINCIPAL? Lo marca el servidor en su <option> (08/10/2026).
function esMotivoPrincipal(id) {
    return document.querySelector(`#af-menu-motivo option[value="${id}"]`)?.dataset.principal === '1';
}

// Pinta una celda según su tipo, su motivo y la LISTA DEL DÍA. Mismos estados que
// `_af-celda.php`. La celda grande de la vista por estudiante lleva además su
// columna (`af-col-N`) y el número del día (`.af-celda__num`): no se tocan.
function pintarCelda(celda) {
    const tipo   = celda.dataset.tipo ?? '';
    const tomada = celda.dataset.tomada === '1';
    const motivo = JUSTIFICADAS.includes(tipo) ? (celda.dataset.motivo ?? '') : '';
    celda.classList.remove(...CLASES_CELDA);
    if (tipo)        celda.classList.add(`af-celda--${tipo.toLowerCase()}`);
    else if (tomada) celda.classList.add('af-celda--asistio');
    else             celda.classList.add('af-celda--sin-tomar');
    // Icono de documento SOLO con el motivo principal (08/10/2026), como `_af-celda.php`.
    if (motivo && esMotivoPrincipal(motivo)) celda.classList.add('af-celda--con-motivo');

    const texto = celda.querySelector('.af-celda__tipo') ?? celda;
    texto.textContent = tipo || (tomada ? '✓' : '');

    const detalle = tipo ? (motivo ? ` · ${nombreMotivo(motivo)}` : '')
                         : (tomada ? ' · Asistió' : ' · Sin tomar lista');
    celda.title = celda.dataset.fecha + detalle;
    const nombre = celda.closest('.af-fila')?.dataset.nombre;
    celda.setAttribute('aria-label', (nombre ? `${nombre} ` : '') + celda.title);
}

// Estado visible: Confirmado / Sin confirmar / Sin registrar.
function pintarEstado(fila) {
    const confirmada = fila.dataset.confirmada === '1';
    fila.classList.toggle('asistencia-fila--registrada', confirmada);
    fila.classList.toggle('asistencia-fila--con-cambios', !confirmada && fila.dataset.registrada === '1');

    const estado = fila.querySelector('.af-estado');
    if (estado) {
        estado.textContent = confirmada ? '✓ Confirmado'
            : (fila.dataset.registrada === '1' ? 'Sin confirmar' : 'Sin registrar');
        estado.className = `af-estado af-estado--${confirmada ? 'ok' : 'pendiente'}`;
    }
    const btn = fila.querySelector('.af-confirmar');
    if (btn) btn.hidden = confirmada;
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

// ── Lista del día ───────────────────────────────────────────────────
// Pinta TODO lo que depende de la lista de `fecha`: las celdas de ese día (toda
// la columna en la grilla), su encabezado y el atajo «Pasar lista de hoy».
function pintarLista(fecha, tomada) {
    document.querySelectorAll(`.af-celda[data-fecha="${fecha}"]`).forEach(celda => {
        celda.dataset.tomada = tomada ? '1' : '0';
        pintarCelda(celda);
    });
    const th = document.querySelector(`th.af-th-dia[data-fecha="${fecha}"]`);
    if (th) {
        th.dataset.tomada = tomada ? '1' : '0';
        th.classList.toggle('af-th-dia--sin-tomar', !tomada);
        const btn = th.querySelector('.af-lista');
        if (btn) {
            btn.dataset.accion = tomada ? 'deshacer' : 'tomar';
            btn.textContent    = tomada ? '✓' : '⚠';
            btn.title          = tomada ? 'Lista tomada · tocar para deshacer' : 'Sin tomar · tocar para pasar lista';
            btn.setAttribute('aria-label', (tomada ? 'Deshacer la lista del ' : 'Pasar lista del ') + ddmm(fecha));
        }
    }
    document.querySelectorAll(`.af-lista--hoy[data-fecha="${fecha}"]`).forEach(b => { b.hidden = tomada; });
}

function pintarSinTomar(n) {
    document.querySelectorAll('.af-sin-tomar-total').forEach(el => { el.textContent = n; });
}

// Una marca tomó la lista de su día: si la columna estaba «Sin tomar», se pinta
// tomada y el contador del pie baja en uno (el servidor lo vuelve a contar al
// recargar y al bloquear).
function listaTomadaPorMarca(fecha) {
    const th = document.querySelector(`th.af-th-dia[data-fecha="${fecha}"]`);
    const antes = th ? th.dataset.tomada === '1'
        : document.querySelector(`.af-celda[data-fecha="${fecha}"]`)?.dataset.tomada === '1';
    if (antes) return;
    pintarLista(fecha, true);
    document.querySelectorAll('.af-sin-tomar-total').forEach(el => {
        el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) - 1);
    });
}

async function cambiarLista(boton) {
    const barra = document.querySelector('[data-jornada-url]');
    if (!barra) return;
    const fecha  = boton.dataset.fecha;
    const accion = boton.dataset.accion;
    if (accion === 'deshacer'
        && !confirm(`¿Deshacer la lista del ${ddmm(fecha)}? El día quedará «Sin tomar».`)) {
        return;
    }
    boton.disabled = true;
    try {
        const res = await fetch(barra.dataset.jornadaUrl, {
            method: 'POST',
            body: new URLSearchParams({ _csrf_token: barra.dataset.csrf, fecha, accion }),
        });
        const data = await res.json();
        if (data.success) {
            pintarLista(fecha, !!data.tomada);
            pintarSinTomar(data.sin_tomar ?? 0);
            avisar('ok', accion === 'tomar' ? `✓ Lista del ${ddmm(fecha)} tomada` : `Lista del ${ddmm(fecha)} deshecha`);
        } else {
            avisar('error', '⚠ ' + (data.mensaje ?? 'No se pudo cambiar la lista.'));
        }
    } catch {
        avisar('error', '⚠ Sin conexión: la lista no cambió.');
    } finally {
        boton.disabled = false;
    }
}

// ── Guardado ────────────────────────────────────────────────────────

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

// AUTOGUARDADO de un día (tipo vacío = «✓ asistió»).
function guardarDia(fila, celda) {
    const datos = { fecha: celda.dataset.fecha, tipo: celda.dataset.tipo ?? '', motivo_id: celda.dataset.motivo ?? '' };
    return encolarAf(fila, async () => {
        const fallo = (msg) => {
            fila.dataset.errorGuardado = '1';
            fila.classList.add('asistencia-fila--error');
            celda.classList.add('af-celda--error');
            statusFila(fila, 'error', '⚠ ' + msg);
            avisar('error', '⚠ ' + msg);
            return false;
        };
        try {
            const data = await postearAf(URL_DIA, fila, datos);
            if (!data.success) return fallo(data.mensaje ?? 'No se guardó el día.');
            fila.dataset.errorGuardado = '0';
            fila.classList.remove('asistencia-fila--error');
            celda.classList.remove('af-celda--error');
            pintarTotales(fila, data.contadores);
            if (data.jornada_tomada) listaTomadaPorMarca(datos.fecha);
            statusFila(fila, 'success', '');
            pintarEstado(fila);
            return true;
        } catch {
            return fallo('Sin conexión: el día no se guardó.');
        }
    });
}

// Aplica la opción elegida en el menú: pinta, desconfirma y autoguarda. Si nada
// cambia y la lista ya está tomada, no hay nada que enviar.
function aplicarOpcion(celda, tipo, motivo) {
    const igual = (celda.dataset.tipo ?? '') === tipo && (celda.dataset.motivo ?? '') === motivo;
    if (igual && celda.dataset.tomada === '1') return;
    const fila = celda.closest('.af-fila');
    celda.dataset.tipo   = tipo;
    celda.dataset.motivo = motivo;
    celda.dataset.tomada = '1';   // cualquier marca toma la lista del día
    celda.classList.remove('af-celda--error');
    pintarCelda(celda);
    if (!igual) {
        fila.dataset.confirmada = '0';
        pintarEstado(fila);
    }
    guardarDia(fila, celda);
}

// CONFIRMAR un estudiante: espera la cola y pide el visto bueno al servidor.
async function confirmarAsistencia(fila) {
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
        } finally {
            if (btn) btn.disabled = false;
        }
    });
}

// ── Menú de la celda (un solo elemento, anclado a la celda tocada) ─────
const menu         = document.getElementById('af-menu');
const menuTitulo   = menu?.querySelector('.af-menu__titulo');
const menuMotivo   = menu?.querySelector('.af-menu__motivo');
const selectMotivo = menu?.querySelector('#af-menu-motivo');
const btnGuardar   = menu?.querySelector('.af-menu__guardar');
let celdaMenu = null;
let tipoPendiente = '';   // FJ/TJ elegida, a la espera del motivo

function marcarOpcion(tipo) {
    menu.querySelectorAll('.af-menu__op').forEach(b => {
        b.setAttribute('aria-pressed', b.dataset.tipo === tipo ? 'true' : 'false');
    });
}

function mostrarMotivo(tipo) {
    tipoPendiente = tipo;
    marcarOpcion(tipo);
    menuMotivo.hidden = false;
    const actual = JUSTIFICADAS.includes(celdaMenu.dataset.tipo) ? (celdaMenu.dataset.motivo ?? '') : '';
    selectMotivo.value = actual;
    btnGuardar.disabled = !selectMotivo.value;
    selectMotivo.focus();
}

function abrirMenu(celda) {
    if (!menu) return;
    celdaMenu = celda;
    tipoPendiente = '';
    const nombre = celda.closest('.af-fila')?.dataset.nombre ?? '';
    menuTitulo.textContent = `${ddmm(celda.dataset.fecha)}${nombre ? ' · ' + nombre : ''}`;
    menuMotivo.hidden = true;
    const actual = celda.dataset.tipo ?? '';
    marcarOpcion(celda.dataset.tomada === '1' || actual ? actual : null);
    if (JUSTIFICADAS.includes(actual)) mostrarMotivo(actual);

    menu.hidden = false;
    const r = celda.getBoundingClientRect();
    const ancho = document.documentElement.clientWidth;
    menu.style.top  = `${window.scrollY + r.bottom + 6}px`;
    menu.style.left = `${Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + ancho - menu.offsetWidth - 8))}px`;
    menu.querySelector('.af-menu__op')?.focus({ preventScroll: true });
}

function cerrarMenu() {
    if (menu) menu.hidden = true;
    celdaMenu = null;
    tipoPendiente = '';
}

menu?.querySelectorAll('.af-menu__op').forEach(op => {
    op.addEventListener('click', e => {
        e.stopPropagation();
        if (!celdaMenu) return;
        const tipo = op.dataset.tipo;
        if (JUSTIFICADAS.includes(tipo)) {
            mostrarMotivo(tipo);   // no guarda hasta tener motivo
            return;
        }
        const celda = celdaMenu;
        cerrarMenu();
        aplicarOpcion(celda, tipo, '');
    });
});
selectMotivo?.addEventListener('change', () => { btnGuardar.disabled = !selectMotivo.value; });
btnGuardar?.addEventListener('click', e => {
    e.stopPropagation();
    if (!celdaMenu || !JUSTIFICADAS.includes(tipoPendiente) || !selectMotivo.value) return;
    const celda = celdaMenu;
    const tipo  = tipoPendiente;
    const motivo = selectMotivo.value;
    cerrarMenu();
    aplicarOpcion(celda, tipo, motivo);
});
menu?.querySelector('.af-menu__cancelar')?.addEventListener('click', cerrarMenu);
menu?.addEventListener('click', e => e.stopPropagation());
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarMenu(); });
document.addEventListener('click', e => {
    if (menu && !menu.hidden && !menu.contains(e.target) && e.target !== celdaMenu) cerrarMenu();
});

// ── Lista de justificaciones (solo vista por estudiante) ────────────
// Muestra cada FJ/TJ con su motivo; tocar una abre el menú de ese día.
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
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn--secondary btn--sm';
        btn.textContent = `${c.dataset.tipo} ${ddmm(c.dataset.fecha)}: ${nombreMotivo(c.dataset.motivo) || 'Motivo'}`;
        btn.addEventListener('click', e => { e.stopPropagation(); abrirMenu(c); });
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
            if (celdaMenu === celda && !menu.hidden) { cerrarMenu(); return; }
            abrirMenu(celda);
        });
    });
    fila.querySelector('.af-confirmar')?.addEventListener('click', () => confirmarAsistencia(fila));
    pintarEstado(fila);
});

document.querySelectorAll('.af-lista').forEach(b => {
    b.addEventListener('click', e => { e.stopPropagation(); cambiarLista(b); });
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
