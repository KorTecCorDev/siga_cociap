/**
 * bloqueos.js — Panel del director:
 *  1. Tabs (cards) que muestran/ocultan cada tipo de bloqueo SIN recargar.
 *     Sin detalle hasta hacer clic; recuerda el último tab abierto por
 *     periodo (sessionStorage) para no perder el contexto tras un POST.
 *  2. Acordeones de secciones académicas.
 *  3. Buscador en vivo de competencias académicas (mismo motor que
 *     usuarios-buscador.js): filtra filas, abre solo las secciones con
 *     coincidencias y, al limpiar, devuelve cada acordeón a su estado inicial.
 *     La búsqueda y la fila tocada se recuerdan por periodo (sessionStorage)
 *     para volver a ella tras Desbloquear/Bloquear.
 */

document.addEventListener('DOMContentLoaded', () => {

    // ── Tabs del hub ─────────────────────────────────────────────
    const cards   = Array.from(document.querySelectorAll('.bloqueos-tabcard'));
    const paneles = Array.from(document.querySelectorAll('.bloqueos-panel'));

    if (cards.length && paneles.length) {
        // Clave por periodo: así el tab recordado no se mezcla entre bimestres.
        const periodoId = new URLSearchParams(location.search).get('periodo_id') || '0';
        const storeKey  = 'bloqueos.tab.' + periodoId;

        const mostrar = (nombre) => {
            cards.forEach((c) => {
                const activo = c.dataset.tab === nombre;
                c.classList.toggle('bloqueos-tabcard--activa', activo);
                c.setAttribute('aria-selected', activo ? 'true' : 'false');
            });
            paneles.forEach((p) => {
                p.hidden = p.dataset.panel !== nombre;
            });
        };

        const ocultar = () => {
            cards.forEach((c) => {
                c.classList.remove('bloqueos-tabcard--activa');
                c.setAttribute('aria-selected', 'false');
            });
            paneles.forEach((p) => { p.hidden = true; });
        };

        cards.forEach((card) => {
            card.addEventListener('click', () => {
                const nombre = card.dataset.tab;
                const yaActiva = card.classList.contains('bloqueos-tabcard--activa');
                if (yaActiva) {
                    // Segundo clic en la card activa → colapsa (vuelve a "sin detalle").
                    ocultar();
                    sessionStorage.removeItem(storeKey);
                } else {
                    mostrar(nombre);
                    sessionStorage.setItem(storeKey, nombre);
                }
            });
        });

        // Restaurar el último tab del periodo (si su card existe).
        const guardado = sessionStorage.getItem(storeKey);
        if (guardado && cards.some((c) => c.dataset.tab === guardado)) {
            mostrar(guardado);
        }
    }

    // ── Acordeones de secciones académicas ───────────────────────
    document.querySelectorAll('.bloqueo-seccion__header').forEach((btn) => {
        btn.addEventListener('click', () => {
            const seccion = btn.closest('.bloqueo-seccion');
            const abierto = seccion.hasAttribute('data-open');
            if (abierto) {
                seccion.removeAttribute('data-open');
                btn.setAttribute('aria-expanded', 'false');
            } else {
                seccion.setAttribute('data-open', '');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    iniciarBuscador();
});

// ── Buscador de competencias académicas ──────────────────────────
function iniciarBuscador() {
    const card = document.getElementById('bloqueos-buscador');
    if (!card) return;

    const panel      = card.closest('.bloqueos-panel');
    const input      = document.getElementById('bloqueos-buscar');
    const btnLimpiar = document.getElementById('bloqueos-limpiar');
    const contador   = document.getElementById('bloqueos-contador');
    const sinFila    = document.getElementById('bloqueos-sin-resultados');
    const segmentos  = Array.from(card.querySelectorAll('.bloqueos-buscador__seg'));
    const titulos    = Array.from(document.querySelectorAll('.bloqueos-nivel-titulo[data-nivel]'));

    const periodoId = new URLSearchParams(location.search).get('periodo_id') || '0';
    const keyBuscar = 'bloqueos.buscar.' + periodoId;
    const keyFoco   = 'bloqueos.foco.' + periodoId;

    const leer = (k) => { try { return sessionStorage.getItem(k); } catch (e) { return null; } };
    const escribir = (k, v) => { try { sessionStorage.setItem(k, v); } catch (e) { /* sin storage */ } };
    const borrar = (k) => { try { sessionStorage.removeItem(k); } catch (e) { /* sin storage */ } };

    // ── Normalización (igual que usuarios-buscador.js) ───────────
    const normalizar = (t) => (t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const compactar  = (t) => normalizar(t).replace(/[^a-z0-9]/g, '');

    // «5.° B» o «5 b» → un solo término de sección «5b».
    function terminos(consulta) {
        const crudos = normalizar(consulta).split(/\s+/).filter(Boolean);
        const salida = [];
        for (let i = 0; i < crudos.length; i++) {
            const actual    = crudos[i];
            const siguiente = crudos[i + 1];
            if (/^\d+[.°º]*$/.test(actual) && siguiente && /^[a-z]$/.test(siguiente)) {
                salida.push({ texto: null, compacto: actual.replace(/\D/g, '') + siguiente });
                i++;
            } else {
                salida.push({ texto: actual, compacto: compactar(actual) });
            }
        }
        return salida;
    }

    // ── Secciones y filas ────────────────────────────────────────
    const secciones = Array.from(document.querySelectorAll('.bloqueo-seccion')).map((el) => ({
        el:      el,
        btn:     el.querySelector('.bloqueo-seccion__header'),
        nivel:   el.dataset.nivel || '',
        inicial: null, // data-open antes de filtrar; null = no se está filtrando
    }));

    const filas = Array.from(document.querySelectorAll('.bloqueo-fila')).map((tr) => {
        const buscar = tr.dataset.buscar || '';
        return {
            tr:       tr,
            seccion:  secciones.find((s) => s.el.contains(tr)),
            estado:   tr.dataset.estado || '',
            texto:    normalizar(buscar),
            compacto: compactar(buscar),
            resaltables: Array.from(tr.querySelectorAll('[data-resaltar]')).map((el) => ({
                el: el, original: el.textContent,
            })),
        };
    });

    const coincide = (fila, lista) => lista.every((t) => {
        if (t.texto !== null && fila.texto.indexOf(t.texto) !== -1) return true;
        return t.compacto !== '' && fila.compacto.indexOf(t.compacto) !== -1;
    });

    // Resaltado por nodos de texto, nunca innerHTML.
    function resaltar(item, palabras) {
        const original = item.original;
        if (palabras.length === 0) {
            item.el.textContent = original;
            return;
        }
        let plano = '';
        const origen = [];
        for (let i = 0; i < original.length; i++) {
            const n = normalizar(original[i]);
            for (let k = 0; k < n.length; k++) {
                plano += n[k];
                origen.push(i);
            }
        }
        const marcas = new Array(original.length).fill(false);
        palabras.forEach((p) => {
            let desde = plano.indexOf(p);
            while (desde !== -1) {
                for (let j = desde; j < desde + p.length; j++) marcas[origen[j]] = true;
                desde = plano.indexOf(p, desde + p.length);
            }
        });
        const fragmento = document.createDocumentFragment();
        let i = 0;
        while (i < original.length) {
            const marcado = marcas[i];
            let j = i;
            while (j < original.length && marcas[j] === marcado) j++;
            const trozo = document.createTextNode(original.slice(i, j));
            if (marcado) {
                const mark = document.createElement('mark');
                mark.appendChild(trozo);
                fragmento.appendChild(mark);
            } else {
                fragmento.appendChild(trozo);
            }
            i = j;
        }
        item.el.textContent = '';
        item.el.appendChild(fragmento);
    }

    const abrir = (s, abierta) => {
        if (abierta) s.el.setAttribute('data-open', '');
        else s.el.removeAttribute('data-open');
        if (s.btn) s.btn.setAttribute('aria-expanded', abierta ? 'true' : 'false');
    };

    // ── Estado y render ──────────────────────────────────────────
    const estado = { q: '', estado: '' };

    function aplicar() {
        const lista     = terminos(estado.q);
        const palabras  = lista.map((t) => t.texto).filter(Boolean);
        const filtrando = estado.q.trim() !== '' || estado.estado !== '';
        const porSeccion = new Map();
        let nFilas = 0;

        filas.forEach((f) => {
            const visible = !filtrando
                || (coincide(f, lista) && (estado.estado === '' || f.estado === estado.estado));
            f.tr.hidden = !visible;
            f.resaltables.forEach((item) => resaltar(item, visible ? palabras : []));
            if (visible) {
                nFilas++;
                porSeccion.set(f.seccion, (porSeccion.get(f.seccion) || 0) + 1);
            }
        });

        const nivelesVisibles = new Set();
        secciones.forEach((s) => {
            if (filtrando) {
                // Recordar cómo estaba el acordeón antes del primer filtro.
                if (s.inicial === null) s.inicial = s.el.hasAttribute('data-open');
                const conFilas = porSeccion.has(s);
                s.el.hidden = !conFilas;
                if (conFilas) {
                    abrir(s, true);
                    nivelesVisibles.add(s.nivel);
                }
            } else {
                s.el.hidden = false;
                if (s.inicial !== null) {
                    abrir(s, s.inicial);
                    s.inicial = null;
                }
                nivelesVisibles.add(s.nivel);
            }
        });
        titulos.forEach((t) => { t.hidden = !nivelesVisibles.has(t.dataset.nivel); });

        segmentos.forEach((b) => {
            const activo = (b.dataset.estado || '') === estado.estado;
            b.classList.toggle('bloqueos-buscador__seg--activo', activo);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });

        btnLimpiar.hidden = estado.q === '';
        sinFila.hidden = nFilas !== 0;
        card.classList.toggle('bloqueos-buscador--filtrando', filtrando);

        const nSec = filtrando ? porSeccion.size : secciones.length;
        const txtSec = nSec + (nSec === 1 ? ' sección' : ' secciones');
        const txtFil = nFilas + (nFilas === 1 ? ' competencia' : ' competencias');
        contador.textContent = filtrando
            ? 'Mostrando ' + txtFil + ' en ' + txtSec
            : txtSec + ' · ' + txtFil;

        if (filtrando) escribir(keyBuscar, JSON.stringify(estado));
        else borrar(keyBuscar);
    }

    const panelVisible = () => !panel || !panel.hidden;

    // ── Eventos ──────────────────────────────────────────────────
    input.addEventListener('input', () => {
        estado.q = input.value;
        aplicar();
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (input.value !== '') {
                e.preventDefault();
                input.value = '';
                estado.q = '';
                aplicar();
            } else {
                input.blur();
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
        }
    });

    btnLimpiar.addEventListener('click', () => {
        input.value = '';
        estado.q = '';
        aplicar();
        input.focus();
    });

    segmentos.forEach((b) => {
        b.addEventListener('click', () => {
            estado.estado = b.dataset.estado || '';
            aplicar();
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || !panelVisible()) return;
        const t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        e.preventDefault();
        card.scrollIntoView({ block: 'start', behavior: 'smooth' });
        input.focus({ preventScroll: true });
        input.select();
    });

    // Fila tocada: se guarda solo si el envío siguió adelante (el confirm()
    // inline corre antes y, si se cancela, deja defaultPrevented).
    document.addEventListener('submit', (e) => {
        if (e.defaultPrevented) return;
        const tr = e.target.closest ? e.target.closest('.bloqueo-fila') : null;
        if (tr && tr.dataset.fila) escribir(keyFoco, tr.dataset.fila);
    });

    // ── Inicio: recuperar búsqueda y volver a la fila ────────────
    let guardado = null;
    try { guardado = JSON.parse(leer(keyBuscar) || 'null'); } catch (e) { guardado = null; }
    if (guardado && typeof guardado === 'object') {
        estado.q = typeof guardado.q === 'string' ? guardado.q : '';
        estado.estado = segmentos.some((b) => (b.dataset.estado || '') === guardado.estado)
            ? guardado.estado : '';
        input.value = estado.q;
    }

    card.hidden = false;
    aplicar();

    const foco = leer(keyFoco);
    if (foco) {
        borrar(keyFoco);
        const f = filas.find((x) => x.tr.dataset.fila === foco);
        if (f && panelVisible()) {
            // Si el filtro la dejaría fuera (p. ej. «Bloqueadas» tras desbloquear),
            // se muestra igual: es la fila que se acaba de tocar.
            f.tr.hidden = false;
            if (f.seccion) {
                f.seccion.el.hidden = false;
                if (f.seccion.inicial === null) f.seccion.inicial = f.seccion.el.hasAttribute('data-open');
                abrir(f.seccion, true);
                const t = titulos.find((x) => x.dataset.nivel === f.seccion.nivel);
                if (t) t.hidden = false;
            }
            sinFila.hidden = true;
            f.tr.scrollIntoView({ block: 'center' });
            f.tr.classList.add('bloqueo-fila--foco');
            setTimeout(() => f.tr.classList.remove('bloqueo-fila--foco'), 2600);
        }
    }
}
