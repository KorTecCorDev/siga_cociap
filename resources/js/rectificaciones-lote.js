/**
 * rectificaciones-lote.js — SIGA-COCIAP
 * Grilla de CALIFICACIÓN EXTRAORDINARIA EN LOTE (/rectificaciones/extraordinaria/lote).
 *
 * Hace cuatro cosas, todas de captura; ninguna regla de negocio vive aquí:
 *   1. Valida la nota igual que la grilla del docente (calificaciones.js):
 *      solo dígitos al teclear, limpia lo pegado y, al salir, recorta a 0-20
 *      con dos dígitos.
 *   2. Muestra el literal en vivo de cada nota tecleada.
 *   3. Revela la conclusión descriptiva en TODA fila con nota —es opcional
 *      (18/09/2026)— y le pone `required` solo si el literal la exige (un campo
 *      required oculto bloquea el envío sin decir por qué).
 *   4. Cuenta las filas con dato y habilita el botón de registrar.
 *   5. Filas de CONDUCTA (literal en vivo) y ASISTENCIA (solo dígitos, tope
 *      que llega en data-tope) — migración 063. Cuentan como una fila más.
 *
 * ⚠️ Los umbrales de la escala y los literales que exigen conclusión NO se
 * escriben aquí: llegan en data-* desde PHP, que los saca de las constantes de
 * app/Helpers/helpers.php y de CalificacionModel::conclusionObligatoria. El
 * PUNTO ÚNICO DE VERDAD sigue estando en PHP.
 */
(function () {
    var form = document.getElementById('rectLoteForm');
    if (!form) return;

    var NOTA_MIN_AD = parseInt(form.dataset.notaMinAd, 10);
    var NOTA_MIN_A  = parseInt(form.dataset.notaMinA, 10);
    var NOTA_MIN_B  = parseInt(form.dataset.notaMinB, 10);
    if (isNaN(NOTA_MIN_AD) || isNaN(NOTA_MIN_A) || isNaN(NOTA_MIN_B)) return;

    var exigenConclusion = (form.dataset.literalesConclusion || '')
        .split(',')
        .filter(function (l) { return l !== ''; });

    var inputs   = Array.prototype.slice.call(form.querySelectorAll('.rect-lote__nota'));
    var elLlenas = form.querySelector('[data-rect-lote-llenas]');
    var btn      = form.querySelector('[data-rect-lote-submit]');
    // Filas de conducta y asistencia: pueden ser las ÚNICAS del lote (alumno
    // con sus notas completas), así que su ausencia de notas no corta el script.
    var conducta = form.querySelector('.rect-lote__conducta');
    var asist    = Array.prototype.slice.call(form.querySelectorAll('.rect-lote__asist'));
    if (inputs.length === 0 && !conducta && asist.length === 0) return;

    function literal(n) {
        if (n >= NOTA_MIN_AD) return 'AD';
        if (n >= NOTA_MIN_A)  return 'A';
        if (n >= NOTA_MIN_B)  return 'B';
        return 'C';
    }

    /** Nota válida tecleada, o null si la fila está vacía o no es un número. */
    function notaDe(input) {
        var bruto = input.value.trim();
        if (bruto === '') return null;
        var n = parseInt(bruto, 10);
        if (isNaN(n)) return null;
        if (n < 0)  n = 0;
        if (n > 20) n = 20;
        return n;
    }

    function filaDe(input) {
        return input.closest('tr');
    }

    function actualizarFila(input) {
        var fila  = filaDe(input);
        if (!fila) return;
        var clave = fila.dataset.clave;
        var celda = fila.querySelector('.rect-lote__literal');
        var bloqueConclusion = form.querySelector(
            '.rect-lote__conclusion[data-clave="' + clave + '"]'
        );
        var textarea = bloqueConclusion
            ? bloqueConclusion.querySelector('textarea')
            : null;
        var marca = bloqueConclusion
            ? bloqueConclusion.querySelector('[data-concl-obligatoria]')
            : null;

        var n = notaDe(input);

        if (n === null) {
            if (celda) {
                celda.textContent = '—';
                celda.dataset.literal = '';
            }
            if (bloqueConclusion) {
                bloqueConclusion.hidden = true;
                if (textarea) textarea.required = false;
            }
            return;
        }

        var lit = literal(n);
        if (celda) {
            celda.textContent = lit;
            celda.dataset.literal = lit;
        }

        // Con nota, la conclusión siempre está a la vista (es opcional); solo
        // es OBLIGATORIA donde la regla del nivel la exige.
        var exige = exigenConclusion.indexOf(lit) !== -1;
        if (bloqueConclusion) {
            bloqueConclusion.hidden = false;
            if (textarea) textarea.required = exige;
            if (marca)    marca.hidden = !exige;
        }
    }

    /** Al salir del campo: recorta a 0-20 con dos dígitos (como el docente). */
    function normalizar(input) {
        var bruto = input.value.trim();
        if (bruto === '') return;
        var n = parseInt(bruto, 10);
        if (isNaN(n)) {
            input.value = '';
            return;
        }
        n = Math.min(20, Math.max(0, n));
        input.value = (n < 10 ? '0' : '') + n;
    }

    function actualizarContador() {
        var llenas = 0;
        inputs.forEach(function (input) {
            if (notaDe(input) !== null) llenas += 1;
        });
        if (conducta && conducta.value !== '') llenas += 1;
        // La asistencia es UNA fila: cuenta si cualquiera de sus 4 tiene dato.
        if (asist.some(function (a) { return a.value.trim() !== ''; })) llenas += 1;
        if (elLlenas) elLlenas.textContent = String(llenas);
        if (btn) btn.disabled = llenas === 0;
    }

    inputs.forEach(function (input) {
        // Bloquea en vivo toda tecla que no sea dígito (navegación y atajos pasan).
        input.addEventListener('keydown', function (e) {
            var navegacion = ['Backspace', 'Delete', 'Tab', 'ArrowLeft',
                'ArrowRight', 'Home', 'End', 'Enter'].indexOf(e.key) !== -1;
            if (navegacion || e.ctrlKey || e.metaKey) return;
            if (!/^[0-9]$/.test(e.key)) e.preventDefault();
        });
        input.addEventListener('input', function () {
            // Limpia lo que entre pegado y no sea dígito.
            var soloDigitos = input.value.replace(/\D/g, '');
            if (input.value !== soloDigitos) input.value = soloDigitos;
            actualizarFila(input);
            actualizarContador();
        });
        input.addEventListener('blur', function () {
            normalizar(input);
            actualizarFila(input);
            actualizarContador();
        });
        // Estado inicial (por si el navegador restaura valores al volver atrás).
        actualizarFila(input);
    });

    // ── Conducta: el literal elegido se repite en la columna de literal ──
    function actualizarConducta() {
        var celda = conducta.closest('tr').querySelector('.rect-lote__literal');
        if (!celda) return;
        celda.textContent = conducta.value || '—';
        celda.dataset.literal = conducta.value;
    }
    if (conducta) {
        conducta.addEventListener('change', function () {
            actualizarConducta();
            actualizarContador();
        });
        actualizarConducta();
    }

    // ── Asistencia: solo dígitos, recortada al tope al salir ─────────────
    asist.forEach(function (input) {
        var tope = parseInt(input.dataset.tope, 10);
        input.addEventListener('keydown', function (e) {
            var navegacion = ['Backspace', 'Delete', 'Tab', 'ArrowLeft',
                'ArrowRight', 'Home', 'End', 'Enter'].indexOf(e.key) !== -1;
            if (navegacion || e.ctrlKey || e.metaKey) return;
            if (!/^[0-9]$/.test(e.key)) e.preventDefault();
        });
        input.addEventListener('input', function () {
            var soloDigitos = input.value.replace(/\D/g, '');
            if (input.value !== soloDigitos) input.value = soloDigitos;
            actualizarContador();
        });
        input.addEventListener('blur', function () {
            var bruto = input.value.trim();
            if (bruto === '') return;
            var n = parseInt(bruto, 10);
            if (isNaN(n)) { input.value = ''; return; }
            if (!isNaN(tope)) n = Math.min(tope, n);
            input.value = String(Math.max(0, n));
            actualizarContador();
        });
    });

    actualizarContador();
})();
