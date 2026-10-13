/**
 * Gráfico del reporte «Faltas y justificaciones — Mis secciones» del auxiliar
 * (/auxiliar/justificaciones, 13/10/2026). Lee los datos del
 * <script type="application/json" id="just-aux-data"> y los dibuja con Frappe
 * Charts (vendorizado en js/frappe-charts.min.js), como /admin/cuadros.
 *
 * QUÉ RESPONDE: en qué sección se justifican faltas con un motivo que NO vale.
 * Por eso son SOLO DOS series y apiladas: «Faltas justificadas» y «Cuentan como
 * falta» (sumadas = todas las FJ registradas). Las tardanzas NO entran: apilarlas
 * con faltas sumaría cosas que no se comparan; viven en la tabla de abajo.
 *
 * COLORES = los de las incidencias del sistema (`_asistencia.scss`):
 *   · rosa  #fb7185  ($af-fj-borde) — falta justificada;
 *   · carmín #be123c ($af-f)        — cuenta como falta: es lo que VALE.
 * Validados con el validador de paletas (ΔE normal 20.7 · daltonismo 20.2). Una
 * tercera serie de la misma familia (durazno/terracota de la tardanza) NO pasa:
 * no añadirla sin volver a validar. El rosa queda por debajo de 3:1 contra el
 * fondo: la tabla «Por sección», con las mismas cifras, es su vista accesible.
 */
(function () {
    'use strict';

    if (typeof frappe === 'undefined' || !frappe.Chart) {
        return;
    }
    var nodo = document.getElementById('just-aux-data');
    var cont = document.getElementById('chart-just-aux');
    if (!nodo || !cont) {
        return;
    }

    var datos;
    try {
        datos = JSON.parse(nodo.textContent);
    } catch (e) {
        return;
    }
    if (!datos.labels || !datos.labels.length) {
        return;
    }

    new frappe.Chart('#chart-just-aux', {
        type: 'bar',
        height: 260,
        colors: ['#fb7185', '#be123c'],
        axisOptions: { xAxisMode: 'tick' },
        barOptions: { stacked: 1, spaceRatio: 0.5 },
        data: {
            labels: datos.labels,
            datasets: [
                { name: 'Faltas justificadas', values: datos.justificadas },
                { name: 'Cuentan como falta', values: datos.comoFalta }
            ]
        }
    });
})();
