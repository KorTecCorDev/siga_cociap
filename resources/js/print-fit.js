/**
 * print-fit.js — Ajuste de viewport para documentos A4 vistos en pantalla.
 *
 * Las vistas de impresion (boleta, reporte de merito) estan disenadas a un
 * ancho A4 fijo (mm/pt). En el celular, `width=device-width` aplasta ese
 * ancho dentro de ~390px y el contenido se encima. Este script mide el ancho
 * REAL de la hoja y fija el viewport a ese ancho, de modo que el telefono la
 * muestre completa y ajustada (igual que al abrir un PDF).
 *
 * - Solo afecta la PANTALLA: el viewport no influye en la impresion (@page)
 *   ni en el PDF guardado.
 * - No modifica estilos de forma permanente (mide con max-content y restaura).
 * - Si la hoja cabe en la pantalla, deja el comportamiento normal.
 */
(function () {
    var meta = document.querySelector('meta[name="viewport"]');
    if (!meta) { return; }

    var sheet = document.querySelector('.boleta-body') || document.body;
    if (!sheet) { return; }

    // Mide el ancho natural de la hoja sin dejar el estilo aplicado.
    function anchoNatural() {
        var prev = sheet.style.width;
        sheet.style.width = 'max-content';
        var w = Math.max(sheet.offsetWidth, sheet.scrollWidth);
        sheet.style.width = prev;
        return w;
    }

    function ajustar() {
        var w = anchoNatural();
        var pantalla = (window.screen && window.screen.width) || window.innerWidth;
        var raiz = document.documentElement;

        if (!w || w <= pantalla) {
            // La hoja cabe: comportamiento normal.
            meta.setAttribute('content', 'width=device-width, initial-scale=1.0');

            // Se LIMPIA, no se deja a 1: si el telefono rota y la hoja pasa a
            // caber, un valor viejo dejaria los botones agrandados sin motivo.
            raiz.style.removeProperty('--doc-escala-inv');
        } else {
            // La hoja es mas ancha que la pantalla: el navegador la ajusta a lo ancho.
            meta.setAttribute('content', 'width=' + w);

            // El navegador va a encajar una hoja de `w` px en `pantalla` px, asi
            // que TODO se ve a `pantalla/w` de su tamano: en un movil de 371px
            // con una hoja de 794 eso es un 47 %, y los botones de accion (11pt,
            // ~34px de alto) quedan en ~16px FISICOS. El minimo tactil es 24px
            // (WCAG 2.5.8 AA) y 44px en AAA: estabamos por debajo incluso del AA.
            //
            // El DOCUMENTO no se toca -es un visor fiel- pero los CONTROLES no
            // son el documento: se compensan con la inversa de la escala.
            //
            // 🔴 SOLO SE PUBLICA EN ESTA RAMA. Si se calculara siempre, en
            // escritorio `screen.width` vale 1920, la hoja 794, y los botones
            // saldrian encogidos a 0,41 sin que nadie lo hubiera pedido. El tope
            // de 2,5 evita un boton absurdo en una pantalla muy pequena.
            raiz.style.setProperty(
                '--doc-escala-inv', Math.min(2.5, w / pantalla).toFixed(3)
            );
        }
    }

    ajustar();
    window.addEventListener('load', ajustar);
    window.addEventListener('orientationchange', function () {
        setTimeout(ajustar, 200);
    });
})();

/**
 * Texto que NUNCA se corta: `[data-ajustar-texto]` (01/10/2026).
 *
 * Cada casilla lleva la letra MAS GRANDE que cabe, con tope en el tamano base
 * que trae el atributo (`data-ajustar-texto="9"`, en pt): lo que cabe sale en
 * el base y lo largo se achica JUSTO hasta el borde, al decimo de punto
 * (decision del usuario, 01/10/2026). Es el «reducir hasta ajustar» del Excel.
 * Se mide el ancho REAL porque contar caracteres no basta: dos nombres del
 * mismo largo necesitan letras distintas (la M es mas ancha que la I). El
 * escalon por largo del CSS queda solo como respaldo sin JS. Hoy lo usa la
 * planilla de asistencia (`documentos/planilla-asistencia-imprimir.php`).
 */
(function () {
    var LETRA_MIN_PT = 6;
    // 1 px de holgura (~0,26 mm): el redondeo de la impresion no recorta la ultima letra.
    var HOLGURA_PX = 1;
    var nodos = document.querySelectorAll('[data-ajustar-texto]');
    if (!nodos.length) { return; }

    // Ancho del TEXTO contra el espacio util (ancho menos relleno). No sirve
    // `scrollWidth`: nunca es menor que `clientWidth`, asi que no admite holgura.
    function cabe(el, decimas) {
        el.style.fontSize = (decimas / 10) + 'pt';
        var estilo = window.getComputedStyle(el);
        var util = el.clientWidth - parseFloat(estilo.paddingLeft) - parseFloat(estilo.paddingRight);
        var rango = document.createRange();
        rango.selectNodeContents(el);
        return rango.getBoundingClientRect().width <= util - HOLGURA_PX;
    }

    function ajustarTexto() {
        Array.prototype.forEach.call(nodos, function (el) {
            var base = parseFloat(el.getAttribute('data-ajustar-texto'));
            if (!(base > LETRA_MIN_PT)) { return; }
            // Casilla sin ancho (oculta o sin maquetar): no hay nada que medir;
            // sin esto, la busqueda la hundiria al minimo.
            if (el.clientWidth === 0) { el.style.removeProperty('font-size'); return; }

            // Busqueda binaria en decimas de punto: el mayor tamano que cabe.
            var lo = LETRA_MIN_PT * 10;
            var hi = Math.round(base * 10);
            if (cabe(el, hi)) { return; }
            while (lo < hi) {
                var medio = Math.ceil((lo + hi) / 2);
                if (cabe(el, medio)) { lo = medio; } else { hi = medio - 1; }
            }
            cabe(el, lo);
        });
    }

    ajustarTexto();
    window.addEventListener('load', ajustarTexto);
    window.addEventListener('beforeprint', ajustarTexto);
})();

/**
 * Boton "Cerrar" del documento.
 *
 * Las boletas/reportes A4 se abren en ventana nueva por script (window.open en
 * app.js), asi que window.close() las cierra de forma fiable y se vuelve a la
 * ventana de origen. Fallback por si la ventana NO fue abierta por script (el
 * usuario abrio la pestana a mano) y el navegador bloquea close(): historial ->
 * referrer del mismo origen -> inicio (base-url).
 */
(function () {
    var cerrar = document.querySelector('.btn-boleta--cerrar');
    if (!cerrar) { return; }

    cerrar.addEventListener('click', function (e) {
        e.preventDefault();

        // Ventana abierta por script: cierra directo (la pagina desaparece y el
        // setTimeout de abajo nunca corre).
        window.close();

        // Si seguimos aqui, close() fue bloqueado: degradar.
        setTimeout(function () {
            if (window.history.length > 1) {
                window.history.back();
                return;
            }
            var ref = document.referrer;
            if (ref) {
                try {
                    if (new URL(ref).origin === window.location.origin) {
                        window.location.href = ref;
                        return;
                    }
                } catch (err) { /* referrer invalido: seguir al fallback */ }
            }
            var meta = document.querySelector('meta[name="base-url"]');
            window.location.href = (meta && meta.getAttribute('content')) || '/';
        }, 120);
    });
})();
