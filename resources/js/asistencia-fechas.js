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
 *   - Cada marca se AUTOGUARDA como borrador (`/admin/asistencia/dia`) y afirma
 *     SOLO sobre su estudiante (09/10/2026, migración 077): «✓ Asistió» guarda su
 *     presencia; ya no toma la lista de la sección. Los 4 contadores los calcula
 *     el SERVIDOR: aquí no se suma nada.
 *   - «Pasar lista» (⚠ del encabezado del día o el botón del AVISO DE HOY) pone ✓
 *     a los que faltan, por `/admin/asistencia/{id}/jornada`. No hay «deshacer».
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

// ── Lista del día y ✓ por estudiante (09/10/2026, migración 077) ─────
// Una celda está CUBIERTA si tiene incidencia o ✓ (propio o de la lista). Cada
// marca afirma SOLO sobre su estudiante; «Pasar lista» (⚠ del día o el aviso de
// hoy) pone ✓ a los que faltan. No hay «deshacer»: un día se corrige marcando.

function celdaCubierta(celda) {
    return (celda.dataset.tipo ?? '') !== '' || celda.dataset.tomada === '1';
}

// Repinta lo que depende del estado de UN día en la grilla: su encabezado (⚠ con
// los que faltan, o ✓ como simple indicador) y, si es hoy, el aviso de hoy.
function refrescarDia(fecha) {
    const celdas = [...document.querySelectorAll(`.af-grilla .af-celda[data-fecha="${fecha}"]`)];
    if (celdas.length === 0) return;
    const faltan = celdas.filter(c => !celdaCubierta(c)).length;

    const th = document.querySelector(`th.af-th-dia[data-fecha="${fecha}"]`);
    const marca = th?.querySelector('.af-th-dia__lista');
    if (th && marca) {
        th.classList.toggle('af-th-dia--sin-tomar', faltan > 0);
        if (faltan === 0 && marca.tagName === 'BUTTON') {
            const span = document.createElement('span');
            span.className = 'af-th-dia__lista af-th-dia__lista--solo';
            span.title = 'Día completo';
            span.textContent = '✓';
            marca.replaceWith(span);
        } else if (faltan > 0 && marca.tagName === 'BUTTON') {
            marca.title = `Faltan ${faltan} · tocar para marcar ✓ a los que faltan`;
        }
        // Un día completo no vuelve a ⚠ en la grilla abierta: nada lo descubre
        // (toda opción del menú deja la celda cubierta).
    }
    pintarAvisoHoy(fecha, celdas, faltan);
}

function pintarAvisoHoy(fecha, celdas, faltan) {
    const aviso = document.querySelector(`.af-aviso-hoy[data-fecha="${fecha}"]`);
    if (!aviso) return;
    const total = celdas.length;
    const texto = aviso.querySelector('.af-aviso-hoy__texto');
    const btn   = aviso.querySelector('.af-lista--hoy');
    aviso.dataset.faltan = String(faltan);
    if (faltan === 0) {
        const faltas    = celdas.filter(c => ['F', 'FJ'].includes(c.dataset.tipo)).length;
        const tardanzas = celdas.filter(c => ['T', 'TJ'].includes(c.dataset.tipo)).length;
        texto.textContent = `lista completa · ${faltas} falta(s) · ${tardanzas} tardanza(s).`;
    } else if (faltan === total) {
        texto.textContent = 'todavía no se toma lista.';
    } else {
        texto.textContent = `faltan ${faltan} estudiante(s) por marcar.`;
    }
    if (btn) {
        btn.hidden = faltan === 0;
        btn.textContent = faltan === total ? 'Todos asistieron' : `Marcar a los ${faltan} como asistentes`;
    }
}

function pintarSinTomar(n) {
    if (n === null || n === undefined) return;
    document.querySelectorAll('.af-sin-tomar-total').forEach(el => { el.textContent = n; });
}

// «Pasar lista» de un día: ✓ a todas las celdas de ese día que no estén cubiertas.
async function pasarLista(boton) {
    const barra = document.querySelector('[data-jornada-url]');
    if (!barra) return;
    const fecha = boton.dataset.fecha;
    boton.disabled = true;
    try {
        const res = await fetch(barra.dataset.jornadaUrl, {
            method: 'POST',
            body: new URLSearchParams({ _csrf_token: barra.dataset.csrf, fecha }),
        });
        const data = await res.json();
        if (data.success) {
            document.querySelectorAll(`.af-celda[data-fecha="${fecha}"]`).forEach(celda => {
                if (celdaCubierta(celda)) return;
                celda.dataset.tomada = '1';
                pintarCelda(celda);
            });
            refrescarDia(fecha);
            pintarSinTomar(data.sin_tomar);
            avisar('ok', `✓ Asistencia del ${ddmm(fecha)} completada`);
        } else {
            avisar('error', '⚠ ' + (data.mensaje ?? 'No se pudo completar la lista.'));
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
            pintarSinTomar(data.sin_tomar);
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
    // Toda opción deja ESTE día de ESTE estudiante cubierto (077): ✓ guarda su
    // presencia; una incidencia, su marca. Los demás estudiantes no cambian.
    celda.dataset.tomada = '1';
    celda.classList.remove('af-celda--error');
    pintarCelda(celda);
    refrescarDia(celda.dataset.fecha);
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
// Cada FJ/TJ con su motivo, en chips (color de la pastilla del contador) y en
// una tabla por fecha (09/10/2026). Tocar uno LLEVA AL DÍA del calendario y abre
// su menú: el menú se ubica con coordenadas del documento, así que queda junto a
// la celda aunque el desplazamiento siga en curso.
const DIAS_SEMANA_AF = ['Dom.', 'Lun.', 'Mar.', 'Mié.', 'Jue.', 'Vie.', 'Sáb.'];

// Desplazamiento INMEDIATO, no suave: al abrir una FJ/TJ el menú enfoca el
// selector de motivo, y ese foco cortaba un desplazamiento suave a medio camino.
function irAlDia(celda) {
    celda.scrollIntoView({ block: 'center' });
    abrirMenu(celda);
}

// Contador en que cae la marca: misma regla que `sqlConteoContadores` (una FJ
// solo cuenta como FJ con el motivo principal; si no, como F).
function cuentaComo(celda) {
    return celda.dataset.tipo === 'FJ' && !esMotivoPrincipal(celda.dataset.motivo) ? 'F' : celda.dataset.tipo;
}

function pastillaTipo(tipo, conMotivo) {
    const s = document.createElement('span');
    s.className = `af-tipo af-tipo--${tipo.toLowerCase()}${conMotivo ? ' af-tipo--con-motivo' : ''}`;
    s.textContent = tipo;
    return s;
}

function pintarJustificaciones(fila) {
    const bloque = fila.querySelector('.af-justificaciones');
    if (!bloque) return;
    const chips = bloque.querySelector('.af-justificaciones__chips');
    const cuerpo = bloque.querySelector('.af-justificaciones__tabla tbody');
    const celdas = [...fila.querySelectorAll('.af-celda[data-fecha]')]
        .filter(c => JUSTIFICADAS.includes(c.dataset.tipo))
        .sort((a, b) => a.dataset.fecha.localeCompare(b.dataset.fecha));
    chips.innerHTML = '';
    cuerpo.innerHTML = '';
    bloque.hidden = celdas.length === 0;

    celdas.forEach((c, i) => {
        const tipo    = c.dataset.tipo;
        const motivo  = nombreMotivo(c.dataset.motivo) || 'Motivo';
        const ancla   = esMotivoPrincipal(c.dataset.motivo);
        const ir      = e => { e.stopPropagation(); irAlDia(c); };

        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `af-tipo af-tipo--${tipo.toLowerCase()}${ancla ? ' af-tipo--con-motivo' : ''} af-justificaciones__chip`;
        // Solo tipo y fecha (09/10/2026, pedido del usuario): con muchas
        // justificaciones los chips con motivo eran una pared. El motivo va en la
        // tabla de abajo y en el title / aria-label del chip.
        btn.textContent = `${tipo} ${ddmm(c.dataset.fecha)}`;
        btn.title = `${tipo} ${ddmm(c.dataset.fecha)} · ${motivo}`;
        btn.setAttribute('aria-label', btn.title);
        btn.addEventListener('click', ir);
        li.appendChild(btn);
        chips.appendChild(li);

        const dia = DIAS_SEMANA_AF[new Date(`${c.dataset.fecha}T12:00:00`).getDay()];
        const tr = document.createElement('tr');
        tr.className = 'af-justificaciones__fila';
        tr.tabIndex = 0;
        tr.title = 'Ir al día en el calendario';
        const celdasTr = [
            String(i + 1),
            `${dia} ${ddmm(c.dataset.fecha)}`,
            pastillaTipo(tipo, ancla),
            motivo,
            pastillaTipo(cuentaComo(c), false),
        ];
        const clases = ['tr-num', 'tr-anexo-fecha', 'tr-anexo-tipo', 'tr-anexo-motivo', 'tr-anexo-tipo'];
        celdasTr.forEach((v, k) => {
            const td = document.createElement('td');
            td.className = clases[k];
            if (typeof v === 'string') td.textContent = v; else td.appendChild(v);
            tr.appendChild(td);
        });
        tr.addEventListener('click', ir);
        tr.addEventListener('keydown', e => { if (e.key === 'Enter') ir(e); });
        cuerpo.appendChild(tr);
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
    b.addEventListener('click', e => { e.stopPropagation(); pasarLista(b); });
});
// El ⚠ del encabezado se reemplaza por un ✓ al completarse el día; los ⚠ que
// siguen siendo botón conservan su listener (es el mismo elemento).

// La grilla abre desplazada hasta la COLUMNA DE HOY (09/10/2026), sin animación.
(() => {
    const scroll = document.querySelector('.af-scroll');
    const fecha  = document.querySelector('.af-aviso-hoy')?.dataset.fecha;
    const th     = fecha ? scroll?.querySelector(`th.af-th-dia[data-fecha="${fecha}"]`) : null;
    if (!scroll || !th) return;
    // Centrada en el espacio que dejan LIBRE las columnas fijas (N° y nombre):
    // en el celular casi todo el ancho es del nombre y, sin restarlo, la columna
    // de hoy quedaba tapada por él.
    const fija  = scroll.querySelector('th.col-nombre');
    const ancho = fija ? fija.offsetLeft + fija.offsetWidth : 0;
    const libre = Math.max(th.offsetWidth, scroll.clientWidth - ancho);
    scroll.scrollLeft = Math.max(0, th.offsetLeft - ancho - (libre - th.offsetWidth) / 2);
})();

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
