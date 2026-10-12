/**
 * usuarios-buscador.js — SIGA-COCIAP
 * Vista /admin/usuarios: buscador en vivo sobre la tabla ya cargada.
 *   - Palabras en cualquier orden, sin tildes y tolerante a la ñ.
 *   - Secciones sin formato: «5b», «5 b» y «5.° B» son lo mismo.
 *   - Chips de rol con contador, filtro de estado y resaltado de coincidencias.
 *   - Atajos: «/» enfoca, Esc limpia, Enter con un solo resultado abre Editar.
 *   - La búsqueda queda en la URL para que «Atrás» la recupere, y viaja en los
 *     formularios de Activar/Desactivar y en el enlace Editar para volver a
 *     la lista filtrada.
 * Sin JS la tabla se ve completa, como siempre (la card nace oculta).
 */
(function () {
    const card = document.getElementById('usuarios-buscador');
    if (!card) return;

    const input      = document.getElementById('usuarios-buscar');
    const btnLimpiar = document.getElementById('usuarios-limpiar');
    const btnTodo    = document.getElementById('usuarios-limpiar-todo');
    const contador   = document.getElementById('usuarios-contador');
    const sinFila    = document.getElementById('usuarios-sin-resultados');
    const chips      = Array.from(card.querySelectorAll('.filtro-chip'));
    const segmentos  = Array.from(card.querySelectorAll('.usuarios-buscador__seg'));
    const camposFiltro = Array.from(document.querySelectorAll('input[data-filtro]'));

    // ── Normalización ────────────────────────────────────────────
    function normalizar(texto) {
        return (texto || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }
    function compactar(texto) {
        return normalizar(texto).replace(/[^a-z0-9]/g, '');
    }

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

    // ── Filas ────────────────────────────────────────────────────
    const filas = Array.from(document.querySelectorAll('.usuarios-fila')).map(function (tr) {
        const buscar = tr.dataset.buscar || '';
        return {
            tr:       tr,
            grupo:    tr.dataset.grupo || '',
            estado:   tr.dataset.estado || '',
            editar:   tr.dataset.editar || '',
            enlace:   tr.querySelector('[data-editar-link]'),
            texto:    normalizar(buscar),
            compacto: compactar(buscar),
            resaltables: Array.from(tr.querySelectorAll('[data-resaltar]')).map(function (el) {
                return { el: el, original: el.textContent };
            }),
        };
    });

    function coincide(fila, lista) {
        return lista.every(function (t) {
            if (t.texto !== null && fila.texto.indexOf(t.texto) !== -1) return true;
            return t.compacto !== '' && fila.compacto.indexOf(t.compacto) !== -1;
        });
    }

    // ── Resaltado (solo nodos de texto, nunca innerHTML) ────────
    function resaltar(item, lista) {
        const original = item.original;
        const palabras = lista.map(function (t) { return t.texto; }).filter(Boolean);
        const secciones = lista.map(function (t) {
            const s = /^(\d{1,2})([a-z])$/.exec(t.compacto);
            return s ? { grado: s[1], letra: s[2] } : null;
        }).filter(Boolean);

        if (palabras.length === 0 && secciones.length === 0) {
            item.el.textContent = original;
            return;
        }

        // Texto normalizado carácter a carácter, con su índice de origen.
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
        function marcar(desde, largo) {
            for (let j = desde; j < desde + largo; j++) marcas[origen[j]] = true;
        }
        palabras.forEach(function (p) {
            let desde = plano.indexOf(p);
            while (desde !== -1) {
                marcar(desde, p.length);
                desde = plano.indexOf(p, desde + p.length);
            }
        });
        // Secciones escritas sin formato («5b»): se marca «5° B» tal como se ve.
        secciones.forEach(function (s) {
            const patron = new RegExp('(^|[^0-9])(' + s.grado + '\\s*[.°º]*\\s*' + s.letra + ')(?![a-z])', 'g');
            let r;
            while ((r = patron.exec(plano)) !== null) {
                marcar(r.index + r[1].length, r[2].length);
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

    // ── Estado y render ──────────────────────────────────────────
    const estado = { q: '', grupo: '', estado: '' };

    function marcarActivo(botones, atributo, valor, claseActiva) {
        botones.forEach(function (b) {
            const activo = (b.dataset[atributo] || '') === valor;
            b.classList.toggle(claseActiva, activo);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
    }

    function sincronizarUrl() {
        const params = new URLSearchParams(window.location.search);
        [['q', estado.q], ['rol', estado.grupo], ['estado', estado.estado]].forEach(function (par) {
            if (par[1]) params.set(par[0], par[1]); else params.delete(par[0]);
        });
        // Activar/Desactivar y Editar vuelven a la lista con este mismo filtro.
        const valores = { q: estado.q, rol: estado.grupo, estado: estado.estado };
        camposFiltro.forEach(function (campo) {
            campo.value = valores[campo.dataset.filtro] || '';
        });
        const filtro = new URLSearchParams();
        Object.keys(valores).forEach(function (k) {
            if (valores[k]) filtro.set(k, valores[k]);
        });
        sufijoFiltro = filtro.toString() ? '?' + filtro.toString() : '';
        filas.forEach(function (f) {
            if (f.enlace && f.editar) f.enlace.href = f.editar + sufijoFiltro;
        });

        const qs = params.toString();
        try {
            history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
        } catch (e) { /* sin historial: el filtro sigue funcionando */ }
    }

    let visibles = [];
    let sufijoFiltro = '';

    function aplicar() {
        const lista   = terminos(estado.q);
        const porGrupo = {};
        let totalTexto = 0;
        visibles = [];

        filas.forEach(function (f) {
            const pasaTexto  = coincide(f, lista);
            const pasaEstado = estado.estado === '' || f.estado === estado.estado;
            if (pasaTexto && pasaEstado) {
                porGrupo[f.grupo] = (porGrupo[f.grupo] || 0) + 1;
                totalTexto++;
            }
            const visible = pasaTexto && pasaEstado && (estado.grupo === '' || f.grupo === estado.grupo);
            f.tr.hidden = !visible;
            if (visible) {
                visibles.push(f);
                f.resaltables.forEach(function (item) { resaltar(item, lista); });
            }
        });

        card.querySelectorAll('.filtro-chip__n').forEach(function (n) {
            const g = n.dataset.n || '';
            n.textContent = g === '' ? totalTexto : (porGrupo[g] || 0);
        });

        marcarActivo(chips, 'grupo', estado.grupo, 'filtro-chip--activo');
        marcarActivo(segmentos, 'estado', estado.estado, 'usuarios-buscador__seg--activo');

        const hayFiltro = estado.q.trim() !== '' || estado.grupo !== '' || estado.estado !== '';
        btnLimpiar.hidden = estado.q === '';
        sinFila.hidden = visibles.length !== 0;
        card.classList.toggle('usuarios-buscador--filtrando', hayFiltro);

        if (!hayFiltro) {
            contador.textContent = filas.length + (filas.length === 1 ? ' usuario' : ' usuarios');
        } else if (visibles.length === 1) {
            contador.textContent = 'Mostrando 1 de ' + filas.length + ' · Enter para editar';
        } else {
            contador.textContent = 'Mostrando ' + visibles.length + ' de ' + filas.length;
        }

        sincronizarUrl();
    }

    function limpiarTodo() {
        estado.q = '';
        estado.grupo = '';
        estado.estado = '';
        input.value = '';
        aplicar();
        input.focus();
    }

    // ── Eventos ──────────────────────────────────────────────────
    input.addEventListener('input', function () {
        estado.q = input.value;
        aplicar();
    });

    input.addEventListener('keydown', function (e) {
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
            if (visibles.length === 1 && visibles[0].editar) {
                window.location.href = visibles[0].editar + sufijoFiltro;
            }
        }
    });

    btnLimpiar.addEventListener('click', function () {
        input.value = '';
        estado.q = '';
        aplicar();
        input.focus();
    });

    if (btnTodo) btnTodo.addEventListener('click', limpiarTodo);

    chips.forEach(function (b) {
        b.addEventListener('click', function () {
            const g = b.dataset.grupo || '';
            estado.grupo = estado.grupo === g ? '' : g;
            aplicar();
        });
    });

    segmentos.forEach(function (b) {
        b.addEventListener('click', function () {
            estado.estado = b.dataset.estado || '';
            aplicar();
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        const t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        e.preventDefault();
        input.focus();
        input.select();
    });

    // ── Inicio: recuperar la búsqueda de la URL ─────────────────
    const params  = new URLSearchParams(window.location.search);
    const grupoUrl = params.get('rol') || '';
    const estadoUrl = params.get('estado') || '';
    estado.q      = params.get('q') || '';
    estado.grupo  = chips.some(function (b) { return b.dataset.grupo === grupoUrl; }) ? grupoUrl : '';
    estado.estado = ['activo', 'inactivo'].indexOf(estadoUrl) !== -1 ? estadoUrl : '';
    input.value   = estado.q;

    card.hidden = false;
    aplicar();
})();
