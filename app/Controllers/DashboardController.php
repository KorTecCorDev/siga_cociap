<?php

namespace App\Controllers;

use Core\Session;

/**
 * DashboardController
 * Punto de entrada post-login. Redirige al panel de cada rol.
 */
class DashboardController extends BaseController
{
    public function index(): void
    {
        $this->requireAuth();

        $rol = Session::user()['rol_codigo'] ?? '';

        // Cada rol tiene su propio panel — el dashboard genérico es para
        // admin, registro académico, las secretarías y, desde el 24/08/2026,
        // los TRES directores: es su punto de entrada y su única vía a los
        // módulos de supervisión (bloqueos, orden de mérito, Centro de Control,
        // consulta de notas, matrículas). Antes se les redirigía a
        // `/director/anios`, que no enlaza a ninguno de ellos.
        $rolesConCards = [
            'admin', 'registro_academico',
            'secretaria_academica', 'secretaria_administrativa',
            ...ROLES_DIRECCION,
        ];

        if (in_array($rol, $rolesConCards, true)) {
            $this->view('dashboard/index', [
                'titulo' => 'Panel de administración',
            ]);
            return;
        }

        // Los demás roles van directo a su módulo
        // ⚠️ Un rol sin entrada aquí ni en $rolesConCards cae a /login, que con
        // sesión abierta lo devuelve al dashboard: un bucle. Todo rol nuevo entra.
        $destinos = [
            'docente'    => url('docente/inicio'),
            'padre'      => url('padre/inicio'),
            ROL_AUXILIAR => url('auxiliar/inicio'),
        ];

        redirect($destinos[$rol] ?? url('login'));
    }
}
