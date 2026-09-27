<?php

namespace App\Controllers;

use Core\View;
use Core\Session;
use App\Models\NotificacionModel;

/**
 * BaseController
 * Controlador base con métodos comunes.
 * Todos los controladores de la app extienden de este.
 */
abstract class BaseController
{
    /**
     * Renderiza una vista pasando datos automáticamente.
     * Los flashes de sesión siempre están disponibles en las vistas.
     */
    protected function view(string $view, array $data = []): void
    {
        $usuario = Session::user();

        // Datos globales disponibles en todas las vistas
        $globals = [
            'auth_user'     => $usuario,
            'flash_success' => Session::getFlash('success'),
            'flash_error'   => Session::getFlash('error'),
            'flash_info'    => Session::getFlash('info'),
            'flash_warning' => Session::getFlash('warning'),
            'app_name'      => config('app.name'),
            'institucion'   => config('app.institucion'),

            // Campana de notificaciones (migración 058). Vive aquí, y no en un
            // middleware —que este proyecto NO tiene, por decisión—, por el
            // mismo motivo que `auth_user` y los flashes: lo necesita el LAYOUT
            // en todas las páginas. Es un COUNT servido por `idx_bandeja`, y
            // solo se ejecuta si hay sesión y el rol puede recibir avisos.
            // NULL = este rol no recibe notificaciones y el layout no pinta la
            // campana; un entero (0 incluido) = sí las recibe.
            'notificaciones_no_leidas' => null,
        ];

        if ($usuario !== null && has_role(NotificacionModel::ROLES_RECEPTORES)) {
            $globals['notificaciones_no_leidas'] =
                (new NotificacionModel())->contarNoLeidas((int) $usuario['id']);
        }

        View::render($view, array_merge($globals, $data));
    }

    /** Respuesta JSON */
    protected function json(mixed $data, int $status = 200): void
    {
        View::json($data, $status);
    }

    /**
     * Respuesta 404 estandar del proyecto. PUNTO UNICO: cualquier controlador
     * que quiera cortar con "no existe" llama aqui.
     *
     * ⚠️ NACIO EL 07/08/2026 PORQUE NO EXISTIA. Varios controladores ya
     * llamaban `$this->notFound()` sin que estuviera definido en ningun sitio
     * (solo `Router` y `RectificacionController` tenian el suyo, ambos PRIVADOS
     * y por tanto inalcanzables desde fuera). El resultado era el peor posible:
     * en local reventaba con "Call to undefined method" y en produccion el
     * blindaje global lo capturaba como excepcion y devolvia la pagina de error
     * GENERICA — nunca un 404. No se noto antes porque los unicos caminos que
     * lo invocaban exigian un periodo inexistente; los gates de
     * /consulta-notas fueron los primeros en dispararlo de verdad.
     *
     * ⚠️ `require` DIRECTO, no `$this->view()`: `shared/404.php` es una pagina
     * HTML completa (con su propio <!DOCTYPE>), asi que pasarla por el layout
     * anida un documento dentro de otro. Es el mismo mecanismo que usa
     * `Router::notFound()`.
     */
    protected function notFound(): never
    {
        http_response_code(404);
        require VIEW_PATH . '/shared/404.php';
        exit;
    }

    /**
     * Corta la petición con un 403 real: código HTTP + página "Acceso
     * denegado" + exit. Gemelo de `notFound()`, por la MISMA razón:
     * `shared/403.php` es una página HTML completa, y pasarla por
     * `$this->view()` la anidaba dentro de `layouts/app.php` — un documento
     * dentro de otro, con el menú lateral alrededor y sin estilo responsive
     * (21/09/2026).
     */
    protected function forbidden(): never
    {
        http_response_code(403);
        require VIEW_PATH . '/shared/403.php';
        exit;
    }

    /** Redirige con mensaje flash de éxito */
    protected function redirectWithSuccess(string $url, string $mensaje): never
    {
        Session::flash('success', $mensaje);
        redirect($url);
    }

    /** Redirige con mensaje flash de error */
    protected function redirectWithError(string $url, string $mensaje): never
    {
        Session::flash('error', $mensaje);
        redirect($url);
    }

    /**
     * Redirige una ruta RENOMBRADA a la nueva, conservando lo que sigue al
     * prefijo (`/imprimir`, `/tutores`) y el query string entero
     * (`?periodo_id`, `?secciones[]`), para que los enlaces y marcadores
     * viejos sigan llegando (24/09/2026: `riesgo` → `acompanamiento`).
     * Solo la llaman rutas literales, así que el resto es uno de sus sufijos.
     */
    protected function redirigirRutaRenombrada(string $vieja, string $nueva): never
    {
        $ruta  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $pos   = strpos($ruta, $vieja);
        $resto = $pos === false ? '' : substr($ruta, $pos + strlen($vieja));
        $qs    = (string) ($_SERVER['QUERY_STRING'] ?? '');
        redirect('/' . $nueva . $resto . ($qs !== '' ? '?' . $qs : ''));
    }

    /** Valida el token CSRF del request actual */
    protected function validateCsrf(): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Session::verifyCsrf($token)) {
            http_response_code(403);
            exit('Token de seguridad inválido. Recarga la página e intenta de nuevo.');
        }
    }

    /** Verifica que el usuario esté autenticado */
    protected function requireAuth(): void
    {
        if (!Session::isLoggedIn()) {
            Session::flash('error', 'Debes iniciar sesión para acceder.');
            redirect('/login');
        }
    }

    /** Verifica que el usuario tenga alguno de los roles indicados */
    protected function requireRole(string|array $roles): void
    {
        $this->requireAuth();
        if (!Session::hasRole($roles)) {
            $this->forbidden();
        }
    }

    /** Obtiene y sanitiza un valor POST */
    protected function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /** Obtiene y sanitiza un valor GET */
    protected function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Verifica si el request es AJAX */
    protected function isAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
