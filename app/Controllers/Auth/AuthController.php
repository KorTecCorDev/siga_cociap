<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Models\UsuarioModel;
use Core\Session;

/**
 * AuthController
 * Gestiona el inicio y cierre de sesión del sistema.
 */
class AuthController extends BaseController
{
    private UsuarioModel $usuarioModel;

    public function __construct()
    {
        $this->usuarioModel = new UsuarioModel();
    }

    /**
     * GET /login
     * Muestra el formulario de inicio de sesión.
     * Si ya hay sesión activa, redirige al dashboard.
     */
    public function showLogin(): void
    {
        if (Session::isLoggedIn()) {
            $this->redirigirAlEntrar(Session::user()['rol_codigo']);
        }

        $this->view('auth/login', [
            'titulo' => 'Iniciar sesión',
        ]);
    }

    /**
     * POST /login/procesar
     * Procesa las credenciales y abre la sesión.
     */
    public function login(): void
    {
        // CSRF del login: auto-recuperable. Si el token quedó desfasado (sesión
        // reciclada por inactividad/GC mientras la página de login seguía abierta,
        // botón Atrás o pestaña vieja), NO cortamos con un 403 sin salida —eso
        // dejaba al usuario atascado en /login/procesar con "Token de seguridad
        // inválido"—. Volvemos a /login con el aviso de sesión expirada (mismo
        // mensaje que el timeout real, ya renderizado por la vista) y un token
        // fresco, para que reintente sin fricción. El resto de formularios (AJAX
        // que esperan JSON) siguen usando el validateCsrf() estricto.
        if (!Session::verifyCsrf($this->input('_csrf_token', ''))) {
            // El destino del regreso sigue en la sesión: la URL va limpia.
            redirect(url('login') . '?timeout=1');
        }

        $dni      = trim($this->input('dni', ''));
        $password = $this->input('password', '');
        $errores  = [];

        // ── Validación de campos ─────────────────────────────
        if (empty($dni)) {
            $errores['dni'] = 'El DNI es obligatorio.';
        } elseif (!preg_match('/^\d{8}$/', $dni)) {
            $errores['dni'] = 'El DNI debe tener exactamente 8 dígitos.';
        }

        if (empty($password)) {
            $errores['password'] = 'La contraseña es obligatoria.';
        }

        if ($errores) {
            $this->view('auth/login', [
                'titulo'    => 'Iniciar sesión',
                'errores'   => $errores,
                'dni_previo' => $dni,
            ]);
            return;
        }

        // ── Búsqueda del usuario ─────────────────────────────
        $usuario = $this->usuarioModel->findByDni($dni);

        // Mensaje genérico — no revelar si el DNI existe o no
        $errorCredenciales = 'DNI o contraseña incorrectos.';

        if (!$usuario) {
            $this->view('auth/login', [
                'titulo'     => 'Iniciar sesión',
                'errores'    => ['dni' => $errorCredenciales],
                'dni_previo' => $dni,
            ]);
            return;
        }

        // ── Verificar estado del usuario ─────────────────────
        if ($usuario['estado'] !== 'activo') {
            $this->view('auth/login', [
                'titulo'  => 'Iniciar sesión',
                'errores' => ['dni' => 'Tu cuenta está inactiva. Comunícate con personal de Registro Académico.'],
                'dni_previo' => $dni,
            ]);
            return;
        }

        // ── Verificar contraseña ─────────────────────────────
        if (!password_verify($password, $usuario['password_hash'])) {
            $this->view('auth/login', [
                'titulo'     => 'Iniciar sesión',
                'errores'    => ['dni' => $errorCredenciales],
                'dni_previo' => $dni,
            ]);
            return;
        }

        // ── Sesión válida — registrar acceso ─────────────────
        // Token único para control de sesión única
        $token = bin2hex(random_bytes(32));
        $this->usuarioModel->registrarAcceso($usuario['id'], $token);

        // Regenerar ID de sesión para prevenir session fixation
        session_regenerate_id(true);

        // Guardar datos del usuario en sesión
        Session::set('auth_user', [
            'id'              => $usuario['id'],
            'persona_id'      => $usuario['persona_id'],
            'dni'             => $usuario['dni'],
            'nombres'         => $usuario['nombres'],
            'apellido_paterno'=> $usuario['apellido_paterno'],
            'apellido_materno'=> $usuario['apellido_materno'],
            'rol_id'          => $usuario['rol_id'],
            'rol_nombre'      => $usuario['rol_nombre'],
            'rol_codigo'      => $usuario['rol_codigo'],
            'correo'          => $usuario['correo'],
            'sexo'            => $usuario['sexo'] ?? null,
        ]);
        Session::set('auth_token', $token);
        Session::set('_last_activity', time());

        // ── Redirigir: a la pantalla de antes, o según rol ───
        $this->redirigirAlEntrar($usuario['rol_codigo']);
    }

    /**
     * Tras iniciar sesión, vuelve a la pantalla donde se estaba (06/10/2026):
     * la sesión vence a los 10 minutos y se perdía el lugar de trabajo. El
     * destino sale de LA SESIÓN (`Session::tomarDestino()`, de un solo uso y
     * validado por `volver_valido()`), nunca del request. Sin destino, el de
     * siempre por rol.
     */
    private function redirigirAlEntrar(string $rol): never
    {
        $destino = Session::tomarDestino();
        if ($destino !== null) {
            redirect(url(ltrim($destino, '/')));
        }
        $this->redirigirPorRol($rol);
    }

    /**
     * GET /logout
     * Cierra la sesión y redirige al login.
     */
    public function logout(): void
    {
        $user = Session::user();
        if ($user) {
            $this->usuarioModel->cerrarSesion($user['id']);
        }

        Session::destroy();

        Session::start();
        Session::flash('success', 'Sesión cerrada correctamente. ¡Hasta pronto!');
        redirect(url('login'));
    }

    /**
     * Redirige al dashboard correspondiente según el rol del usuario.
     */
    private function redirigirPorRol(string $rol): never
    {
        // Las claves son códigos de `roles.codigo`. Un rol sin entrada propia cae
        // en el dashboard por el `??` de abajo, que es un destino válido.
        //
        // Retirada el 22/08/2026 la entrada `'secretaria' => 'secretaria/matriculas'`:
        // estaba doblemente muerta. Su destino apuntaba a un controlador que no
        // existe en el repositorio (la ruta se retiró de `routes/web.php` el mismo
        // día), y su clave no correspondía a ningún rol real — los códigos en la
        // base son `secretaria_academica` y `secretaria_administrativa`, así que
        // esta rama nunca llegó a ejecutarse. Ambos roles llegan hoy al dashboard.
        $destinos = [
            // Los TRES directores salieron el 24/08/2026 y caen al dashboard por
            // el `??` de abajo. Aterrizaban en `/director/anios`, que no enlaza a
            // ningun otro modulo, y cuyo boton "← Dashboard" los devolvia ahi
            // mismo: un bucle. De los nueve modulos que tenian permitidos solo
            // alcanzaban uno; el resto habia que escribirlo a mano en la barra.
            'admin'             => url('dashboard'),
            'registro_academico'=> url('dashboard'),
            'docente'           => url('docente/inicio'),
            'padre'             => url('padre/inicio'),
            // Panel propio, estilo del panel docente (28/09/2026).
            ROL_AUXILIAR        => url('auxiliar/inicio'),
        ];

        redirect($destinos[$rol] ?? url('dashboard'));
    }
}
