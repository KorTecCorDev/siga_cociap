/**
 * sesion.js — Aviso «¿Sigues trabajando?» antes de que venza la sesión (06/10/2026).
 *
 * La sesión vence a los N segundos SIN PETICIONES (Core\Session, 10 minutos).
 * Leer una boleta física no hace peticiones, así que antes se perdía la sesión
 * —y con ella lo tecleado— sin aviso. Este script:
 *
 *   1. Lleva la cuenta de la última actividad CON EL SERVIDOR: cada carga de
 *      página y cada fetch exitoso a la app. Se comparte entre pestañas por
 *      localStorage (solo una marca de tiempo, ningún dato personal), porque la
 *      sesión es una sola para todas.
 *   2. Cuenta la ACTIVIDAD HUMANA real (teclas, campos, clics, toques, rueda;
 *      NO mover el mouse) y, solo cuando faltan ~2 minutos, si hubo actividad
 *      desde el último contacto con el servidor, renueva EN SILENCIO con
 *      POST /sesion/renovar: ~1 petición por ciclo, sin tocar la BD. Así quien
 *      trabaja no ve el aviso; quien solo LEE, sí.
 *   3. 60 s antes del vencimiento abre el modal; «Seguir trabajando» (o cerrarlo)
 *      renueva con POST /sesion/renovar.
 *   4. Al entrar en el aviso y al llegar a cero CONSULTA AL SERVIDOR
 *      (GET /sesion/estado, que no renueva). Solo si el servidor confirma el
 *      vencimiento va a /sesion/expirada, que CIERRA la sesión y lleva al login;
 *      el regreso a esta pantalla lo guarda el SERVIDOR en la sesión.
 *
 * 🔴 SEGURIDAD: NO hay renovación automática. Solo renueva una PERSONA: con el
 * botón o con su actividad real, y solo cuentan eventos `isTrusted` (los que
 * genera el navegador por una acción humana, no un script). Sin eso el
 * vencimiento por inactividad dejaría de existir. Y el vencimiento REAL lo
 * decide el servidor: este script es solo una ayuda.
 */
(function () {
    'use strict';

    var metaTimeout = document.querySelector('meta[name="session-timeout"]');
    var modal       = document.getElementById('modalSesion');
    if (!metaTimeout || !modal) return;

    var TIMEOUT = parseInt(metaTimeout.content, 10);
    if (!(TIMEOUT > 0)) return;
    var AVISO = Math.min(60, Math.floor(TIMEOUT / 2));
    // Zona de renovación silenciosa: empieza antes que el aviso (2 min con 10
    // de sesión; proporcional si la sesión es más corta).
    var ZONA_RENOVAR = AVISO + Math.min(60, Math.floor(TIMEOUT / 4));

    var BASE = (document.querySelector('meta[name="base-url"]') || {}).content || '';
    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var CLAVE = 'siga_ultima_actividad';

    var cuenta   = modal.querySelector('[data-sesion-cuenta]');
    var error    = modal.querySelector('[data-sesion-error]');
    var seguir   = modal.querySelector('[data-sesion-seguir]');
    var memoria  = Date.now();   // respaldo si localStorage no está disponible
    var saliendo = false;

    function leer() {
        try {
            var v = parseInt(window.localStorage.getItem(CLAVE), 10);
            if (v > 0) return Math.max(v, memoria);
        } catch (e) { /* modo privado o almacenamiento bloqueado */ }
        return memoria;
    }

    function fijar(ms) {
        memoria = ms;
        try { window.localStorage.setItem(CLAVE, String(ms)); } catch (e) { /* sin memoria compartida */ }
    }

    function marcar() { fijar(Date.now()); }

    // Ajusta el contador a lo que dice el SERVIDOR (segundos restantes).
    function sincronizar(restante) {
        fijar(Date.now() - (TIMEOUT - restante) * 1000);
    }

    // 🔴 Al vencer se va a /sesion/expirada, que CIERRA la sesión en el servidor.
    // NUNCA a /login directo: esa visita contaba como actividad y, si el
    // servidor aún no la daba por vencida (vence con `> timeout`, un segundo
    // después que este contador), la RENOVABA y devolvía a la pantalla. La
    // sesión no vencía nunca (fallo crítico del 06/10/2026, visto en el log).
    function irAExpirada() {
        if (saliendo) return;
        saliendo = true;
        // Sin parámetros: el servidor sabe cuál fue la última pantalla y guarda
        // el regreso en la sesión. Nada editable en la URL.
        location.href = BASE + '/sesion/expirada';
    }

    // Las consultas del propio aviso NO son actividad: no se cuentan.
    function esDelAviso(url) {
        return url.pathname.indexOf('/sesion/estado') !== -1
            || url.pathname.indexOf('/sesion/expirada') !== -1;
    }

    // Toda respuesta EXITOSA de la propia app renovó la sesión en el servidor.
    // Una redirección (al login) o un error NO cuentan.
    if (window.fetch) {
        var fetchOriginal = window.fetch;
        window.fetch = function () {
            return fetchOriginal.apply(this, arguments).then(function (res) {
                try {
                    var url = new URL(res.url, location.href);
                    if (url.origin === location.origin && res.ok && !res.redirected && !esDelAviso(url)) marcar();
                } catch (e) { /* URL no evaluable: no cuenta */ }
                return res;
            });
        };
    }

    // Pregunta al SERVIDOR cuánto queda, sin renovar (GET /sesion/estado). Si
    // ya venció, o no hay respuesta válida, se cierra. Si otra pestaña tuvo
    // actividad, el contador se ajusta a la verdad del servidor.
    var consultando = false;
    var verificado  = false;
    function consultarServidor(alTerminar) {
        consultando = true;
        fetch(BASE + '/sesion/estado', { cache: 'no-store' })
            .then(function (res) {
                if (res.redirected || !res.ok) throw new Error('vencida');
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success || !(data.restante > 0)) throw new Error('vencida');
                sincronizar(data.restante);
                verificado  = true;
                consultando = false;
                if (alTerminar) alTerminar();
            })
            .catch(function () {
                consultando = false;
                irAExpirada();
            });
    }

    function renovar() {
        if (saliendo) return;
        var fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fetch(BASE + '/sesion/renovar', { method: 'POST', body: fd })
            .then(function (res) {
                if (res.redirected || !res.ok) throw new Error('vencida');
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success) throw new Error('vencida');
                sincronizar(data.restante);
                error.hidden = true;
                Modal.cerrar('modalSesion');
            })
            .catch(function () {
                error.hidden = false;
                setTimeout(irAExpirada, 2500);
            });
    }

    seguir.addEventListener('click', renovar);

    // Cerrar el aviso (clic en el fondo o Escape) también es una persona presente:
    // se renueva igual, para que cerrar no deje la sesión a punto de vencer.
    modal.addEventListener('click', function (e) {
        if (e.target === modal) renovar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('modal--activo')) renovar();
    });

    // ── Actividad humana ─────────────────────────────────────
    // Solo eventos `isTrusted`. Mover el mouse NO cuenta: un roce accidental
    // mantendría abierta una PC desatendida. Con el aviso abierto tampoco: ahí
    // la persona responde con el botón (o Escape / clic en el fondo).
    var actividadHumana = 0;
    ['keydown', 'input', 'pointerdown', 'wheel', 'touchstart'].forEach(function (tipo) {
        document.addEventListener(tipo, function (e) {
            if (!e.isTrusted || modal.classList.contains('modal--activo')) return;
            actividadHumana = Date.now();
        }, { capture: true, passive: true });
    });

    // Renovación silenciosa por actividad. Si falla, no insiste en cada tick:
    // espera 15 s; el aviso y /sesion/estado se encargan si de verdad venció.
    var renovandoSilencio = false;
    var reintentarDesde   = 0;
    // La acción que ya se renovó: cada acción humana renueva UNA sola vez. Sin
    // esto, el redondeo a segundos del servidor podía dejar la marca un poco
    // antes de la acción y renovar en cada tick (una petición por segundo).
    var accionRenovada    = 0;
    function renovarEnSilencio() {
        renovandoSilencio = true;
        var fd = new FormData();
        fd.append('_csrf_token', CSRF);
        // Hace cuántos segundos fue la última acción humana: el servidor fija
        // la actividad en ESE momento, no en el de esta petición. Si no, el
        // cierre llegaba tarde (con 10 min de sesión, hasta ~18 min).
        accionRenovada = actividadHumana;
        fd.append('hace', String(Math.max(0, Math.floor((Date.now() - actividadHumana) / 1000))));
        fetch(BASE + '/sesion/renovar', { method: 'POST', body: fd })
            .then(function (res) {
                if (res.redirected || !res.ok) throw new Error('no');
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success) throw new Error('no');
                sincronizar(data.restante);
            })
            .catch(function () { reintentarDesde = Date.now() + 15000; })
            .then(function () { renovandoSilencio = false; });
    }

    function tick() {
        if (saliendo || consultando) return;
        var restante = TIMEOUT - Math.floor((Date.now() - leer()) / 1000);
        var abierto  = modal.classList.contains('modal--activo');

        // Hubo actividad humana DESPUÉS del último contacto con el servidor y
        // se acerca el vencimiento: renovar sin molestar. Una vez por ciclo.
        if (!abierto && restante > 0 && restante <= ZONA_RENOVAR && !renovandoSilencio
                && actividadHumana > accionRenovada && actividadHumana > leer()
                && Date.now() >= reintentarDesde) {
            renovarEnSilencio();
        }

        if (restante > AVISO) {
            verificado = false;
            // Otra pestaña renovó la sesión: el aviso ya no corresponde.
            if (abierto) Modal.cerrar('modalSesion');
            return;
        }
        // Al entrar en la zona de aviso, y al llegar a cero, manda el servidor.
        if (!verificado || restante <= 0) {
            consultarServidor(tick);
            return;
        }
        cuenta.textContent = String(restante);
        if (!abierto) Modal.abrir('modalSesion');
    }

    marcar();   // cargar esta página ya renovó la sesión en el servidor
    setInterval(tick, 1000);

    // Al VOLVER a la pestaña se evalúa de inmediato (06/10/2026): oculta, el
    // navegador espacia o congela el temporizador (Chrome: 1 vez por minuto;
    // el celular lo suspende) y la página podía verse un rato tras vencer.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) tick();
    });
    // Restaurada desde la caché del botón Atrás: no pasó por el servidor.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) tick();
    });
})();
