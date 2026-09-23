/**
 * cuadros-riesgo.js — SIGA-COCIAP
 *
 * Buscador del listado de /admin/cuadros/riesgo (23/09/2026). Antes filtraba
 * tambien por nivel y grado en cliente, dentro de /admin/cuadros; desde que el
 * informe tiene vista propia esos dos filtros van por URL, porque el resumen
 * estadistico y el A4 tienen que describir lo filtrado. Aqui queda solo ubicar
 * a una persona: el buscador NO toca las cifras.
 *
 * 🔴 EL BUSCADOR NACE `hidden` Y LO DESTAPA ESTE ARCHIVO. Sin JS el listado se
 * sigue leyendo entero en vez de quedarse con un campo que no busca.
 *
 * Cada estudiante es un <tbody data-riesgo-fila> (franja + desglose): se oculta
 * entero. Un grado sin ningun estudiante visible se oculta tambien, para que su
 * encabezado no se lea como un grado sin casos.
 */
(function () {
    const filtros = document.getElementById('riesgo-filtros');
    if (!filtros) return;   // sin casos, o el A4: no hay nada que buscar

    const filas    = Array.prototype.slice.call(document.querySelectorAll('[data-riesgo-fila]'));
    const bloques  = Array.prototype.slice.call(document.querySelectorAll('[data-riesgo-bloque]'));
    const input    = document.getElementById('riesgo-buscar');
    const contador = document.getElementById('riesgo-contador');
    const sinRes   = document.getElementById('riesgo-sin-resultados');

    const TOTAL = filas.length;

    /**
     * Mismo normalizador que `nomina.js`: minusculas, NFD y sin marcas
     * diacriticas, para que "nunez" encuentre a "NÚÑEZ". Se normalizan LOS DOS
     * LADOS aqui: el servidor emite `data-buscar` en crudo a proposito.
     * El rango va escrito como ̀-ͯ, no con los caracteres literales.
     */
    function normalizar(t) {
        return (t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function aplicar() {
        const q = normalizar(input ? input.value.trim() : '');
        let visibles = 0;

        filas.forEach(function (f) {
            const ok = q === '' || normalizar(f.getAttribute('data-buscar')).indexOf(q) !== -1;
            f.hidden = !ok;
            if (ok) visibles++;
        });

        bloques.forEach(function (b) {
            b.hidden = !b.querySelector('[data-riesgo-fila]:not([hidden])');
        });

        if (contador) {
            contador.textContent = 'Mostrando ' + visibles + ' de ' + TOTAL
                + (TOTAL === 1 ? ' estudiante.' : ' estudiantes.');
        }
        if (sinRes) sinRes.hidden = visibles !== 0;
    }

    if (input) input.addEventListener('input', aplicar);

    filtros.hidden = false;
    aplicar();
})();
