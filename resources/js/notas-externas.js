/**
 * notas-externas.js — SIGA-COCIAP
 * Formulario de NOTAS DEL COLEGIO DE ORIGEN (/matriculas/{id}/notas-externas).
 *
 * Única función: añadir filas al lote. El informe de origen puede traer 25+
 * competencias y el formulario nace con 6, así que se clona la última fila
 * vacía tantas veces como haga falta.
 *
 * El servidor descarta las filas en blanco, así que sobrar filas es inofensivo.
 */
(function () {
    var form = document.getElementById('notasOrigenForm');
    if (!form) return;

    var cuerpo = form.querySelector('[data-notas-origen-cuerpo]');
    var boton  = form.querySelector('[data-notas-origen-agregar]');
    if (!cuerpo || !boton) return;

    boton.addEventListener('click', function () {
        var filas = cuerpo.querySelectorAll('.notas-origen__fila');
        if (filas.length === 0) return;

        var ultima = filas[filas.length - 1];
        var nueva  = ultima.cloneNode(true);

        // Cada fila viene con su PAREJA: un <tr> de continuación con la
        // conclusión descriptiva. Se clonan las dos o la fila nueva nacería sin
        // conclusión y los arrays del POST se desalinearían.
        var contigua = ultima.nextElementSibling;
        var conclusionNueva = contigua && contigua.classList.contains('notas-origen__fila-conclusion')
            ? contigua.cloneNode(true)
            : null;

        // Un clon arrastra los valores tecleados: se limpian para que la fila
        // nueva nazca vacía (los <select> vuelven a su primera opción).
        var limpiar = function (tr) {
            tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            tr.querySelectorAll('textarea').forEach(function (t) { t.value = ''; });
            tr.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
        };
        limpiar(nueva);
        if (conclusionNueva) limpiar(conclusionNueva);

        cuerpo.appendChild(nueva);
        if (conclusionNueva) cuerpo.appendChild(conclusionNueva);

        var primer = nueva.querySelector('input');
        if (primer) primer.focus();
    });
})();


/**
 * Card de importación: marcar / desmarcar todas las áreas de una vez.
 *
 * Vive fuera del IIFE de las filas repetibles porque la card puede existir sin
 * el formulario (y al revés): cada bloque comprueba lo suyo y sale si no está.
 */
(function () {
    var casillas = document.querySelectorAll('[data-area-casilla]');
    if (casillas.length === 0) return;

    function marcarTodas(valor) {
        Array.prototype.forEach.call(casillas, function (c) { c.checked = valor; });
    }

    var todas = document.querySelector('[data-areas-todas]');
    var ninguna = document.querySelector('[data-areas-ninguna]');

    if (todas)   todas.addEventListener('click', function () { marcarTodas(true); });
    if (ninguna) ninguna.addEventListener('click', function () { marcarTodas(false); });
})();


/**
 * Card de importación: no enviar sin elegir bimestre ni área.
 *
 * El servidor tiene la MISMA guarda y avisa con un flash; esto solo ahorra el
 * viaje y señala el error donde está. El mensaje se crea desde el JS en vez de
 * dejarlo oculto en la vista para no depender de que un `[hidden]` le gane al
 * CSS de su contenedor, que en este proyecto ya falló una vez.
 */
(function () {
    var form = document.querySelector('[data-importar-form]');
    if (!form) return;

    function marcadas(nombre) {
        return form.querySelectorAll('input[name="' + nombre + '[]"]:checked').length;
    }

    function avisar(texto) {
        var p = form.querySelector('[data-importar-error]');

        if (!texto) {
            if (p && p.parentNode) p.parentNode.removeChild(p);
            return;
        }
        if (!p) {
            p = document.createElement('p');
            p.className = 'form-error';
            p.setAttribute('data-importar-error', '');
            form.insertBefore(p, form.querySelector('.form-actions'));
        }
        p.textContent = texto;
    }

    form.addEventListener('submit', function (e) {
        if (marcadas('periodos') === 0) {
            e.preventDefault();
            avisar('Marca al menos un bimestre para traer sus competencias.');
        } else if (marcadas('areas') === 0) {
            e.preventDefault();
            avisar('Marca al menos un area para traer sus competencias.');
        }
    });

    // Corregida la selección, el aviso sobra. Los botones "Marcar todas" y
    // "Ninguna" cambian las casillas por codigo, que NO dispara `change`: por
    // eso escuchan tambien el click.
    form.addEventListener('change', function () { avisar(''); });
    form.addEventListener('click', function (e) {
        var origen = e.target;
        if (!origen || !origen.closest) return;   // el objetivo puede no ser un elemento
        if (origen.closest('[data-areas-todas], [data-areas-ninguna]')) avisar('');
    });
})();
