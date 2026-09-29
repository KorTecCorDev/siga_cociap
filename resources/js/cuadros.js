/**
 * Gráficos del tablero de Dirección (/admin/cuadros y su imprimible).
 * Lee los datos desde el <script type="application/json" id="cuadros-data">
 * y los dibuja con Frappe Charts (vendorizado en js/frappe-charts.min.js).
 *
 * DOS PALETAS, y la distinción importa:
 *
 *  · Estados (tienen orden y significado): verde #16a34a, ámbar #d97706,
 *    rojo #dc2626. Se usan en el embudo de conducta, que es un progreso, y
 *    en los literales AD/A/B/C, que también son estados: su donut lo pinta el
 *    partial _panel-bimestre.php con las variables --lit-* del SASS, y aquí
 *    se reproducen esos mismos cuatro valores para que el gráfico apilado y
 *    el donut no digan lo mismo con colores distintos.
 *
 *  · Categorías (no tienen orden): azul #1e6fa8, teal #0d9488,
 *    púrpura #7c3aed, naranja #e07b1a. Púrpura es el color de conducta en
 *    el wayfinding del sistema (resources/sass/base/_variables.scss).
 *
 * Por eso aquí SÍ aparecen rojo y ámbar, pese a la regla de _variables.scss
 * que los reserva: allí se prohíben como identidad de categoría, y estos
 * son estados. No "corregir" a azul/teal sin leer esto.
 *
 * ───────────────────────────────────────────────────────────────────────
 * DIBUJADO PEREZOSO — no es una optimización, es una corrección.
 *
 * Desde que la pantalla organiza los bloques en pestañas, la mayoría de los
 * contenedores nace oculto. Frappe mide el contenedor EN EL MOMENTO de
 * instanciar y le escribe al SVG un width en px: dentro de un panel con
 * `hidden` esa medida es 0 y el gráfico nace vacío para siempre, sin ningún
 * error en consola.
 *
 * Por eso los gráficos no se crean al cargar, sino la primera vez que su
 * contenedor está VISIBLE: al arrancar (los de la pestaña activa, y todos en
 * el imprimible, que no tiene pestañas) y cada vez que `tabs.js` avisa de que
 * un panel se ha mostrado. `data-dibujado` impide repetirlos.
 * ───────────────────────────────────────────────────────────────────────
 */
(function () {
    'use strict';

    if (typeof frappe === 'undefined' || !frappe.Chart) {
        return;
    }

    var dataEl = document.getElementById('cuadros-data');
    if (!dataEl) {
        return;
    }

    var data;
    try {
        data = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }

    var AZUL    = '#1e6fa8';
    var TEAL    = '#0d9488';
    var PURPURA = '#7c3aed';
    var NARANJA = '#e07b1a';

    var VERDE = '#16a34a';
    var AMBAR = '#d97706';
    var ROJO  = '#dc2626';

    // AD / A / B / C — los mismos valores que --lit-ad/-a/-b/-c en el SASS.
    var LITERALES = [VERDE, AZUL, AMBAR, ROJO];

    // La unidad de cada serie ("% en logro", " faltas") NO se escribe aqui: la
    // trae el propio dato, desde `_chart-data.php`. Antes estaba a mano en cada
    // grafica y la tabla de valores del papel habria tenido que repetirla,
    // creando otra regla duplicada de las que este repositorio ya ha visto
    // divergir. Sin unidad, el tooltip muestra el numero desnudo.
    var unidad = function (d) {
        var sufijo = d.unidad || '';
        return function (v) { return v + sufijo; };
    };

    // ── Registro: id del contenedor -> cómo se dibuja ────────────────
    // Cada entrada solo se registra si su dato existe y tiene contenido, así
    // que un bimestre a medio llenar simplemente tiene menos gráficos.
    var registro = {};

    var registrar = function (id, clave, fabrica) {
        var d = data[clave];
        if (d && d.labels && d.labels.length) {
            registro[id] = function () { fabrica(d, id); };
        }
    };

    // Línea: evolución del % en logro por bimestre, una serie por nivel.
    // El PHP ya entregó las series recortadas a los bimestres comparables, así
    // que aquí no hay huecos que tratar.
    registrar('chart-evolucion', 'evolucion', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'line',
            height: 300,
            colors: [AZUL, TEAL],
            axisOptions: { xAxisMode: 'tick' },
            lineOptions: { hideDots: 0, regionFill: 0 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // Barras agrupadas: primer puesto vs último puesto de cada grado.
    registrar('chart-brecha', 'brecha', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 320,
            colors: [AZUL, NARANJA],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { spaceRatio: 0.4 },
            data: {
                labels: d.labels,
                datasets: [
                    { name: 'Primer puesto', values: d.mejor },
                    { name: 'Último puesto', values: d.peor }
                ]
            }
        });
    });

    // Torta: en qué etapa del cierre está cada sección.
    registrar('chart-conducta-embudo', 'conductaEmbudo', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'pie',
            height: 280,
            colors: [VERDE, AMBAR, ROJO],
            data: { labels: d.labels, datasets: [{ values: d.values }] }
        });
    });

    // Barras: secciones con menor cobertura de conducta.
    registrar('chart-conducta-secciones', 'conductaSecciones', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 280,
            colors: [PURPURA],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { spaceRatio: 0.35 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: [{ name: 'Calificados', values: d.values }] }
        });
    });

    // Barras apiladas: reparto AD/A/B/C de conducta en cada nivel. La altura
    // total es cuánta gente hay calificada; los tramos, cómo se reparte.
    registrar('chart-conducta-literales', 'conductaLiterales', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 300,
            colors: LITERALES,
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { stacked: 1, spaceRatio: 0.6 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // Línea: evolución del % en logro (AD+A) de conducta, una serie por nivel.
    registrar('chart-conducta-evolucion', 'conductaEvolucion', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'line',
            height: 300,
            colors: [AZUL, TEAL],
            axisOptions: { xAxisMode: 'tick' },
            lineOptions: { hideDots: 0, regionFill: 0 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // Barras: qué norma de convivencia se incumple más. El eje son códigos
    // (C1..C10), que solos no dicen nada, así que el tooltip lleva el texto
    // completo del criterio.
    registrar('chart-conducta-criterios', 'conductaCriterios', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 300,
            colors: [PURPURA],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { spaceRatio: 0.35 },
            tooltipOptions: {
                formatTooltipX: function (etq) {
                    var i = d.labels.indexOf(etq);
                    return (i >= 0 && d.textos) ? etq + ' — ' + d.textos[i] : etq;
                },
                formatTooltipY: unidad(d)
            },
            data: { labels: d.labels, datasets: [{ name: 'No cumple', values: d.values }] }
        });
    });

    // Barras: comparativa entre secciones. Ojo, son las SIN JUSTIFICAR: en la
    // base de datos los cuatro contadores son independientes y `faltas` ya
    // excluye a las justificadas (ver AsistenciaModel).
    registrar('chart-asis-faltas', 'asisFaltas', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 300,
            colors: [NARANJA],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { spaceRatio: 0.3 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: [{ name: 'Faltas sin justificar', values: d.values }] }
        });
    });

    registrar('chart-asis-tardanzas', 'asisTardanzas', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 300,
            colors: [AMBAR],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { spaceRatio: 0.3 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: [{ name: 'Tardanzas sin justificar', values: d.values }] }
        });
    });

    // Línea: evolución anual de faltas y tardanzas sin justificar.
    //
    // ROJO + ÁMBAR, no naranja + ámbar (07/09/2026). Dos motivos, y el segundo
    // es el que manda: naranja #e07b1a y ámbar #d97706 son casi el mismo tono y
    // en dos líneas finas no se distinguen; y aquí las series NO son categorías
    // sueltas, tienen orden de gravedad —una falta pesa más que una tardanza—,
    // así que les toca la paleta de ESTADOS, igual que al embudo de conducta.
    // Ver la nota de las dos paletas en la cabecera de este archivo.
    registrar('chart-asis-evolucion', 'asisEvolucion', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'line',
            height: 280,
            colors: [ROJO, AMBAR],
            axisOptions: { xAxisMode: 'tick' },
            lineOptions: { hideDots: 0, regionFill: 0 },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // Barras apiladas: cuánto se justifica en cada nivel.
    registrar('chart-asis-justificacion', 'asisJustificacion', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 280,
            colors: [NARANJA, TEAL],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { stacked: 1, spaceRatio: 0.5 },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // ── Justificaciones (29/09/2026) ─────────────────────────────────
    // Colores: faltas ROJO y tardanzas ÁMBAR (orden de gravedad, como la
    // evolución anual); justificadas AZUL (FJ) y TEAL (TJ).

    // J1 — Usos por motivo, apilados FJ + TJ.
    registrar('chart-just-motivos', 'justMotivos', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 260,
            colors: [AZUL, TEAL],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { stacked: 1, spaceRatio: 0.5 },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // J2 — Secciones por estudiante, los cuatro tipos apilados.
    registrar('chart-just-secciones', 'justSecciones', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 300,
            colors: [ROJO, AZUL, AMBAR, TEAL],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { stacked: 1, spaceRatio: 0.3 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // J3 — Tendencia semanal (promedio diario).
    registrar('chart-just-semanas', 'justSemanas', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'line',
            height: 260,
            colors: [ROJO, AMBAR],
            axisOptions: { xAxisMode: 'tick' },
            lineOptions: { hideDots: 0, regionFill: 0 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // J4 — Patrón por día de la semana.
    registrar('chart-just-dias', 'justDias', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 260,
            colors: [ROJO, AMBAR],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { spaceRatio: 0.4 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: d.datasets }
        });
    });

    // J5 — Oportunidad del registro por sección.
    registrar('chart-just-oportunidad', 'justOportunidad', function (d, id) {
        new frappe.Chart('#' + id, {
            type: 'bar',
            height: 280,
            colors: [AZUL],
            axisOptions: { xAxisMode: 'tick' },
            barOptions: { spaceRatio: 0.3 },
            tooltipOptions: { formatTooltipY: unidad(d) },
            data: { labels: d.labels, datasets: [{ name: 'Días de demora', values: d.values }] }
        });
    });

    // ── Dibujado ─────────────────────────────────────────────────────
    // `offsetParent === null` cubre el caso que importa: el contenedor, o
    // cualquiera de sus padres, está oculto (`hidden`, `display:none`).
    var visible = function (el) {
        return el.offsetParent !== null;
    };

    // ── Eje Y con decimales (29/09/2026) ─────────────────────────────
    // Frappe calcula los ticks sumando pasos decimales y escribe el error de
    // coma flotante tal cual: «0.30000000000000004», que además se corta contra
    // el borde («)0000004»). Pasa con valores menores que 1, justo los
    // promedios diarios de las estadísticas de justificaciones. Frappe no tiene
    // formateador para el eje Y, así que se redondean sus etiquetas después de
    // dibujar, y OTRA VEZ cada vez que Frappe redibuja (lo hace al cambiar el
    // tamaño, desde su ResizeObserver): de ahí el MutationObserver.
    var redondear = function (texto) {
        var n = Number(texto);
        if (texto === '' || !isFinite(n) || Math.round(n) === n) {
            return texto;
        }
        return String(Math.round(n * 100) / 100);
    };

    var sanearEjeY = function (el) {
        el.querySelectorAll('g.y.axis text').forEach(function (t) {
            var limpio = redondear(t.textContent);
            if (limpio !== t.textContent) {
                t.textContent = limpio;
            }
        });
    };

    var vigilarEjeY = function (el) {
        sanearEjeY(el);
        if (typeof MutationObserver === 'function') {
            new MutationObserver(function () { sanearEjeY(el); })
                .observe(el, { childList: true, subtree: true, characterData: true });
        }
    };

    var barrer = function () {
        Object.keys(registro).forEach(function (id) {
            var el = document.getElementById(id);
            if (!el || el.getAttribute('data-dibujado') === '1' || !visible(el)) {
                return;
            }
            el.setAttribute('data-dibujado', '1');
            registro[id]();
            vigilarEjeY(el);
        });
    };

    // tabs.js emite este evento al mostrar un panel, y se cargó ANTES que este
    // archivo: los avisos de su arranque ya pasaron. Por eso el barrido
    // inicial de abajo no es redundante, es el que dibuja la pestaña activa.
    document.addEventListener('tabs:mostrado', barrer);

    barrer();
})();
