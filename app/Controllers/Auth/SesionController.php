<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Models\UsuarioModel;
use Core\Session;

/**
 * SesionController — el aviso de sesión por vencer (06/10/2026).
 *
 * ⚠️ SIN MODELOS EN EL CONSTRUCTOR, a propósito: `renovar` y `estado` los llama
 * sesion.js en segundo plano y NO deben abrir conexión a MySQL (en Hostinger
 * compartido el límite que duele es el de conexiones simultáneas). Solo leen la
 * sesión, que vive en archivo. Únicamente `expirada` —una vez por cierre— usa la
 * BD, y crea su modelo dentro del método.
 *
 * 🔴 Respuestas SIN mensajes: no se explica el flujo interno al cliente
 * (regla del colegio). Basta el código HTTP.
 */
class SesionController extends BaseController
{
    /**
     * POST /sesion/renovar — lo dispara una PERSONA: el botón «Seguir
     * trabajando» (`hace` = 0), o su actividad real (teclas, clics) cerca del
     * vencimiento, que manda `hace` = segundos desde esa última acción. La
     * actividad se fija en ese momento real, no en el de la petición.
     */
    public function renovar(): void
    {
        if (!Session::isLoggedIn()) {
            $this->json(['success' => false], 401);
        }
        $this->validateCsrf();
        $hace = filter_var($this->input('hace', 0), FILTER_VALIDATE_INT);
        Session::fijarUltimaActividad($hace === false ? 0 : $hace);
        $this->json(['success' => true, 'restante' => Session::segundosRestantes()]);
    }

    /**
     * GET /sesion/estado — segundos que le quedan a la sesión, SIN contar esta
     * consulta como actividad: la hace el navegador, no una persona.
     *
     * 🔴 Nació de un fallo crítico: la primera versión de sesion.js visitaba
     * /login al llegar a cero; por un segundo de diferencia con el servidor
     * (que vence con `> timeout`) esa visita RENOVABA la sesión y showLogin
     * devolvía a la pantalla. La sesión no vencía nunca.
     */
    public function estado(): void
    {
        if (!Session::isLoggedIn()) {
            $this->json(['success' => false], 401);
        }
        Session::noContarComoActividad();
        $this->json([
            'success'  => true,
            'restante' => Session::timeout() - (int) Session::segundosInactivo(),
        ]);
    }

    /**
     * GET /sesion/expirada — CIERRA la sesión por inactividad y lleva al login.
     * Destino de sesion.js al terminar la cuenta regresiva.
     *
     * No recibe parámetros: el regreso es la ÚLTIMA PANTALLA que el servidor
     * mostró y viaja dentro de la sesión, así nadie lo edita ni lo fija con un
     * enlace externo. Solo cierra si el SERVIDOR confirma la inactividad (5 s
     * de margen por el reloj): un enlace externo no puede expulsar a quien está
     * trabajando; en ese caso la visita no cuenta como actividad y se vuelve.
     */
    public function expirada(): void
    {
        if (Session::isLoggedIn()) {
            $inactivo = Session::segundosInactivo();
            $pantalla = Session::ultimaPantalla();
            if ($inactivo !== null && $inactivo < Session::timeout() - 5) {
                Session::noContarComoActividad();
                redirect($pantalla !== null ? url(ltrim($pantalla, '/')) : url('dashboard'));
            }
            (new UsuarioModel())->cerrarSesion((int) Session::user()['id']);
            Session::cerrarPorInactividad($pantalla);
        }

        redirect(url('login') . '?timeout=1');
    }
}
