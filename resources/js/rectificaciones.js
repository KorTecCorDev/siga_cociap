/**
 * rectificaciones.js — SIGA-COCIAP
 * Vista /rectificaciones/editar: muestra en vivo la evolución del promedio
 * de la competencia (numeral + literal) a medida que se editan las notas por
 * criterio. El cálculo replica al backend: promedio = ROUND(AVG(notas)) sobre
 * los criterios CON nota (vacío = NULL, no cuenta).
 *
 * Además vuelve OBLIGATORIA la conclusión en vivo cuando el literal del
 * promedio la exige, para que el navegador frene el envío antes de que lo
 * rechace el servidor (18/09/2026).
 *
 * ⚠️ Los umbrales y los literales que exigen conclusión NO se escriben aquí:
 * llegan en data-* desde PHP (constantes de app/Helpers/helpers.php y
 * CalificacionModel::conclusionObligatoria), igual que en rectificaciones-lote.js.
 */
(function () {
    var form = document.getElementById('rectEditarForm');
    if (!form) return;

    var NOTA_MIN_AD = parseInt(form.dataset.notaMinAd, 10);
    var NOTA_MIN_A  = parseInt(form.dataset.notaMinA, 10);
    var NOTA_MIN_B  = parseInt(form.dataset.notaMinB, 10);
    if (isNaN(NOTA_MIN_AD) || isNaN(NOTA_MIN_A) || isNaN(NOTA_MIN_B)) return;

    var exigenConclusion = (form.dataset.literalesConclusion || '')
        .split(',')
        .filter(function (l) { return l !== ''; });

    var conclusion  = form.querySelector('#conclusion');
    var marcaObliga = form.querySelector('[data-rect-concl-obligatoria]');

    var preview = document.getElementById('rectPreview');
    if (!preview) return;

    var inputs  = Array.prototype.slice.call(document.querySelectorAll('.rect-nota-input'));
    if (inputs.length === 0) return;

    var elNum = preview.querySelector('[data-rect-num]');
    var elLit = preview.querySelector('[data-rect-lit]');

    function literal(n) {
        if (n >= NOTA_MIN_AD) return 'AD';
        if (n >= NOTA_MIN_A)  return 'A';
        if (n >= NOTA_MIN_B)  return 'B';
        return 'C';
    }

    function dosDigitos(n) {
        return (n < 10 ? '0' : '') + n;
    }

    /** La conclusión es obligatoria solo si el literal resultante la exige. */
    function sincronizarConclusion(lit) {
        var exige = lit !== '' && exigenConclusion.indexOf(lit) !== -1;
        if (conclusion)  conclusion.required = exige;
        if (marcaObliga) marcaObliga.hidden  = !exige;
    }

    function recalcular() {
        var suma = 0;
        var cuenta = 0;

        inputs.forEach(function (input) {
            var bruto = input.value.trim();
            if (bruto === '') return;             // vacío = NULL, no promedia
            var n = parseInt(bruto, 10);
            if (isNaN(n)) return;
            if (n < 0)  n = 0;
            if (n > 20) n = 20;                    // clamp igual que el backend
            suma += n;
            cuenta += 1;
        });

        // Sin ninguna nota: no hay promedio que mostrar.
        if (cuenta === 0) {
            elNum.textContent = '—';
            elLit.textContent = '—';
            preview.dataset.rectLiteral = '';
            sincronizarConclusion('');
            return;
        }

        var prom = Math.round(suma / cuenta);      // ROUND(AVG(...)) del backend
        var lit  = literal(prom);

        elNum.textContent = dosDigitos(prom);
        elLit.textContent = lit;
        preview.dataset.rectLiteral = lit;         // permite colorear por literal
        sincronizarConclusion(lit);
    }

    inputs.forEach(function (input) {
        input.addEventListener('input', recalcular);
    });

    recalcular(); // estado inicial al cargar
})();
