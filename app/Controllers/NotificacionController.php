<?php

namespace App\Controllers;

use App\Models\NotificacionModel;
use Core\Session;

/**
 * NotificacionController
 * Bandeja interna de notificaciones (migración 058).
 *
 * DOS SUPERFICIES:
 *   - Bandeja: la lee cualquier usuario autenticado; solo ve LAS SUYAS
 *     (el modelo filtra siempre por usuario_id, también al marcar leída).
 *   - Comunicados: los redacta admin / registro académico, y los dos ven el
 *     historial de enviados (/notificaciones/enviados).
 *
 * ⚠️ El gate de escritura va POR MÉTODO, no en el constructor: los tres roles
 * de ROLES_DIRECCION son SOLO LECTURA y tienen que poder ENTRAR a su bandeja,
 * pero nunca redactar. Mismo criterio que el resto del sistema.
 *
 * EXCEPCIÓN ACOTADA (16/09/2026): `leer` y `leerTodas` NO llevan gate de rol,
 * así que dirección SÍ los alcanza. Es deliberado: solo cambian el estado
 * personal de SU propia bandeja (el modelo filtra por usuario_id), no un dato
 * académico. Sin ello su campana nunca bajaría a cero.
 */
class NotificacionController extends BaseController
{
    private NotificacionModel $model;

    public function __construct()
    {
        $this->requireAuth();
        $this->model = new NotificacionModel();
    }

    /** GET /notificaciones — bandeja del usuario en sesión. */
    public function index(): void
    {
        $uid = (int) (Session::user()['id'] ?? 0);

        $this->view('notificaciones/index', [
            'titulo'        => 'Notificaciones',
            'notificaciones' => $this->model->paraUsuario($uid),
            'noLeidas'      => $this->model->contarNoLeidas($uid),
            'puedeEmitir'   => has_role(NotificacionModel::ROLES_EMISORES),
            'page_scripts'  => ['notificaciones'],
        ]);
    }

    /**
     * POST /notificaciones/leer — marca UNA como leída.
     * Responde JSON: la bandeja la marca sin recargar.
     * Sin gate de rol a propósito: dirección también marca SU bandeja.
     */
    public function leer(): void
    {
        $this->validateCsrf();

        $uid = (int) (Session::user()['id'] ?? 0);
        $id  = (int) $this->input('id');

        $this->json([
            'success'  => $this->model->marcarLeida($id, $uid),
            'noLeidas' => $this->model->contarNoLeidas($uid),
        ]);
    }

    /**
     * POST /notificaciones/leer-todas — marca todas las del usuario.
     * Sin gate de rol a propósito: dirección también marca SU bandeja.
     */
    public function leerTodas(): void
    {
        $this->validateCsrf();

        $uid = (int) (Session::user()['id'] ?? 0);
        $this->model->marcarTodasLeidas($uid);

        $this->redirectWithSuccess(url('notificaciones'), 'Notificaciones marcadas como leídas.');
    }

    /** GET /notificaciones/comunicado — formulario de comunicado. */
    public function comunicado(): void
    {
        $this->requireRole(NotificacionModel::ROLES_EMISORES);

        // Solo secciones del año ACTIVO: son las únicas cuyos docentes puede
        // resolver docentesDeSeccion(). listarConTutor() incluía también las del
        // año planificado, que daban siempre «No hay destinatarios».
        $this->view('notificaciones/comunicado', [
            'titulo'       => 'Nuevo comunicado',
            'secciones'    => $this->model->seccionesParaComunicado(),
            'destinos'     => NotificacionModel::DESTINOS,
            'page_scripts' => ['notificaciones'],
        ]);
    }

    /** GET /notificaciones/enviados — historial de comunicados (todos los emisores). */
    public function enviados(): void
    {
        $this->requireRole(NotificacionModel::ROLES_EMISORES);

        $this->view('notificaciones/enviados', [
            'titulo'      => 'Comunicados enviados',
            'comunicados' => $this->model->comunicadosEnviados(),
            'destinos'    => NotificacionModel::DESTINOS,
        ]);
    }

    /**
     * POST /notificaciones/comunicado — envía el comunicado.
     * Destinos COMBINABLES (casillas): todos los docentes, los docentes de UNA
     * sección, dirección y personal administrativo. Se unen, se deduplican y
     * se excluye a quien envía.
     */
    public function guardarComunicado(): void
    {
        $this->requireRole(NotificacionModel::ROLES_EMISORES);
        $this->validateCsrf();

        $emisorId = (int) (Session::user()['id'] ?? 0);
        $titulo   = trim((string) $this->input('titulo', ''));
        $mensaje  = trim((string) $this->input('mensaje', ''));
        $volver   = url('notificaciones/comunicado');

        // Lista blanca: solo claves de DESTINOS, sin repetir.
        $pedidos  = $this->input('destinos', []);
        $destinos = array_values(array_intersect(
            array_keys(NotificacionModel::DESTINOS),
            is_array($pedidos) ? array_map('strval', $pedidos) : []
        ));

        if ($titulo === '' || $mensaje === '') {
            $this->redirectWithError($volver, 'El título y el mensaje son obligatorios.');
        }
        // El maxlength del formulario es solo cliente: sin esto, un título largo
        // reventaba el INSERT y el usuario veía el error genérico.
        if (mb_strlen($titulo) > 150) {
            $this->redirectWithError($volver, 'El título no puede pasar de 150 caracteres.');
        }
        if ($destinos === []) {
            $this->redirectWithError($volver, 'Elige a quién va dirigido el comunicado.');
        }

        $seccionId = null;
        if (in_array(NotificacionModel::DESTINO_SECCION, $destinos, true)) {
            $seccionId = (int) $this->input('seccion_id');
            if ($seccionId <= 0) {
                $this->redirectWithError($volver, 'Elige la sección a la que va el comunicado.');
            }
        }

        $destinatarios = $this->model->destinatariosDeComunicado($destinos, $seccionId, $emisorId);

        if ($destinatarios === []) {
            $this->redirectWithError($volver, 'No hay destinatarios para esa selección.');
        }

        $this->model->beginTransaction();
        try {
            $this->model->enviarComunicado(
                $emisorId, $titulo, $mensaje, $destinos, $seccionId, $destinatarios
            );
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al enviar comunicado', [
                'emisor' => $emisorId, 'destinos' => implode(',', $destinos),
                'error'  => $e->getMessage(),
            ]);
            $this->redirectWithError($volver, 'No se pudo enviar el comunicado.');
        }

        $n = count($destinatarios);
        $this->redirectWithSuccess(
            url('notificaciones'),
            'Comunicado enviado a ' . $n . ($n === 1 ? ' persona.' : ' personas.')
        );
    }
}
