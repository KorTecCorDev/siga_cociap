<?php

namespace Core;

/**
 * Session
 * Manejo centralizado y seguro de sesiones.
 * Implementa: sesión única por usuario, expiración por inactividad,
 * protección CSRF y regeneración de ID.
 */
class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Solo aceptar IDs de sesión generados por el servidor (refuerza la
        // anti-fijación) y nunca leer el ID desde la URL.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        // Detecta HTTPS (directo o tras el proxy SSL de Hostinger) para marcar la
        // cookie como Secure solo cuando corresponde. En local HTTP queda false,
        // así no se rompe el desarrollo en XAMPP/BrowserSync.
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);

        // Configuración segura de la cookie de sesión
        session_set_cookie_params([
            'lifetime' => 0,           // expira al cerrar navegador
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,    // Secure solo bajo HTTPS (producción)
            'httponly' => true,        // inaccesible desde JS
            'samesite' => 'Lax',
        ]);

        session_name('SIGA_SESSION');
        session_start();
        self::$started = true;

        // Generar token CSRF si no existe
        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        // Verificar expiración por inactividad (10 minutos = 600 segundos).
        // `config()` NO admite notación con punto: con 'app.session_timeout'
        // siempre devolvía el valor por defecto (06/10/2026).
        $timeout = self::timeout();
        self::$actividadPrevia = isset($_SESSION['_last_activity']) ? (int) $_SESSION['_last_activity'] : null;
        if (isset($_SESSION['_last_activity'])) {
            if ((time() - $_SESSION['_last_activity']) > $timeout) {
                // El destino del regreso se calcula ANTES de destruir y viaja a
                // la sesión nueva: nunca en la URL (ver destinoTrasVencer).
                self::cerrarPorInactividad(self::destinoTrasVencer());
                redirect('/login?timeout=1');
                return;
            }
        }
        $_SESSION['_last_activity'] = time();
    }

    /** `_last_activity` ANTES de esta petición (null si no había). */
    private static ?int $actividadPrevia = null;

    /** Segundos sin actividad ANTES de esta petición (null si no había marca). */
    public static function segundosInactivo(): ?int
    {
        return self::$actividadPrevia === null ? null : time() - self::$actividadPrevia;
    }

    /**
     * Esta petición NO cuenta como actividad: devuelve `_last_activity` a su
     * valor anterior (06/10/2026). La usan las consultas del aviso de sesión,
     * que hace el NAVEGADOR y no una persona: si renovaran, la sesión no
     * vencería nunca —eso pasó con la primera versión de sesion.js—.
     */
    public static function noContarComoActividad(): void
    {
        if (self::$actividadPrevia !== null) {
            $_SESSION['_last_activity'] = self::$actividadPrevia;
        }
    }

    /**
     * Fija la última actividad en el momento REAL de la última acción humana,
     * `$hace` segundos atrás (06/10/2026). Sin esto, la renovación silenciosa
     * reiniciaba el reloj al RENOVAR y no al ACTUAR: con 10 min de sesión, la
     * PC quedaba abierta hasta ~18 min tras la última acción.
     *
     * Límites: `$hace` entre 0 y el timeout, y NUNCA antes de la marca previa
     * (no se acorta lo que ya se tenía). El cliente no gana poder: lo máximo es
     * `$hace = 0`, lo mismo que una renovación normal.
     */
    public static function fijarUltimaActividad(int $hace): void
    {
        $hace  = max(0, min($hace, self::timeout()));
        $marca = time() - $hace;
        if (self::$actividadPrevia !== null) {
            $marca = max($marca, self::$actividadPrevia);
        }
        $_SESSION['_last_activity'] = $marca;
    }

    /** Segundos que le quedan a la sesión según `_last_activity` actual. */
    public static function segundosRestantes(): int
    {
        return self::timeout() - (time() - (int) ($_SESSION['_last_activity'] ?? time()));
    }

    /** Segundos de inactividad tras los que vence la sesión. */
    public static function timeout(): int
    {
        return (int) config('session_timeout', 600);
    }

    // ── Regreso a la pantalla tras el login (06/10/2026) ─────────────────
    //
    // 🔴 El destino vive EN LA SESIÓN, nunca en la URL. En la URL cualquiera
    // podía editarlo (y se volvía a otra pantalla, no a la propia) y además
    // exponía datos de trabajo (`matricula=698`) en la barra del login.
    //   · `_ultima_pantalla`: la última página HTML completa que el servidor
    //     mostró (la anota View::render). Es la verdad de «dónde estaba».
    //   · `_volver`: el destino pendiente en la sesión ANÓNIMA del login.
    // Todo valor se pasa por volver_valido() al guardarlo y otra vez al usarlo.

    /** ¿La petición es una NAVEGACIÓN GET (no un fetch, no un POST)? */
    private static function esNavegacionGet(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }
        $modo = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'navigate';
        return $modo === 'navigate' && empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /** La anota View::render en cada página completa servida con sesión. */
    public static function recordarPantalla(): void
    {
        if (!self::isLoggedIn() || !self::esNavegacionGet()) {
            return;
        }
        $ruta = volver_valido(ruta_actual_app());
        if ($ruta !== null) {
            $_SESSION['_ultima_pantalla'] = $ruta;
        }
    }

    /**
     * A dónde volver si la sesión vence EN ESTA petición: la pantalla pedida si
     * es una navegación a una pantalla; si no (fetch, POST, el propio login,
     * /sesion/*), la última pantalla mostrada o el destino ya pendiente (el
     * formulario de login abierto más de 10 minutos).
     */
    private static function destinoTrasVencer(): ?string
    {
        if (self::esNavegacionGet()) {
            $pedida = volver_valido(ruta_actual_app());
            if ($pedida !== null) {
                return $pedida;
            }
        }
        return volver_valido($_SESSION['_ultima_pantalla'] ?? null)
            ?? volver_valido($_SESSION['_volver'] ?? null);
    }

    /** Destino de la pantalla actual sin sesión iniciada (lo usa requireAuth). */
    public static function recordarDestinoSinSesion(): void
    {
        if (!self::esNavegacionGet()) {
            return;
        }
        $ruta = volver_valido(ruta_actual_app());
        if ($ruta !== null) {
            $_SESSION['_volver'] = $ruta;
        }
    }

    /**
     * Cierra la sesión por inactividad y abre una ANÓNIMA nueva que solo
     * conserva el destino del regreso. Nada más pasa de una a otra.
     */
    public static function cerrarPorInactividad(?string $destino): void
    {
        self::destroy();
        self::start();
        $destino = volver_valido($destino);
        if ($destino !== null) {
            $_SESSION['_volver'] = $destino;
        }
    }

    /** Toma el destino del regreso y lo BORRA: se usa una sola vez. */
    public static function tomarDestino(): ?string
    {
        $destino = volver_valido($_SESSION['_volver'] ?? null);
        unset($_SESSION['_volver']);
        return $destino;
    }

    /** La última pantalla mostrada en esta sesión (validada). */
    public static function ultimaPantalla(): ?string
    {
        return volver_valido($_SESSION['_ultima_pantalla'] ?? null);
    }

    /** Guarda un valor en la sesión */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /** Obtiene un valor de la sesión */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /** Verifica si existe una clave en la sesión */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /** Elimina una clave de la sesión */
    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Guarda un mensaje flash (disponible solo en el siguiente request) */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /** Obtiene y elimina un mensaje flash */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /** Verifica si hay un mensaje flash */
    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    /** Retorna el token CSRF actual */
    public static function csrfToken(): string
    {
        return $_SESSION['_csrf_token'] ?? '';
    }

    /** Valida el token CSRF enviado en un formulario */
    public static function verifyCsrf(string $token): bool
    {
        return hash_equals(self::csrfToken(), $token);
    }

    /** Usuario autenticado actual */
    public static function user(): ?array
    {
        return $_SESSION['auth_user'] ?? null;
    }

    /** Verifica si hay sesión activa */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['auth_user']);
    }

    /** Verifica si el usuario tiene un rol específico */
    public static function hasRole(string|array $roles): bool
    {
        $user = self::user();
        if (!$user) return false;

        $userRole = $user['rol_codigo'] ?? '';
        $roles = is_array($roles) ? $roles : [$roles];
        return in_array($userRole, $roles);
    }

    /** Destruye la sesión completamente */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        self::$started = false;
    }
}
