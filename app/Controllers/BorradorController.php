<?php

namespace App\Controllers;

use App\Controllers\Matricula\MatriculaController;
use App\Models\BorradorModel;
use Core\Session;
use Core\Throttle;

/**
 * BorradorController — autoguardado de formularios de carga manual (06/10/2026).
 *
 * Un único endpoint: POST /borradores/guardar. La LECTURA del borrador no tiene
 * ruta propia: la hace el GET de cada formulario (para el usuario de la sesión)
 * y viaja a la vista como JSON. Ver `docs/modulos/borradores-y-sesion.md`.
 *
 * 🔴 Este endpoint NUNCA escribe en tablas oficiales: solo `borradores_formulario`.
 * 🔴 Respuestas sin mensajes (no se explica el flujo interno): basta el código.
 */
class BorradorController extends BaseController
{
    /** Roles que pueden autoguardar cada tipo: los mismos que abren su formulario. */
    private const ROLES = [
        'rect_lote'           => ['admin', 'registro_academico'],
        'rect_competencia'    => ['admin', 'registro_academico'],
        'rect_extraordinaria' => ['admin', 'registro_academico'],
        'notas_siagie'        => ['admin', 'registro_academico'],
        // Los mismos que abren el formulario: punto único en MatriculaController.
        'notas_origen'        => MatriculaController::ROLES_MATRICULAN,
    ];

    /** S6: escrituras de borrador por usuario y minuto (el JS guarda cada ~1,5 s como mucho). */
    private const MAX_POR_MINUTO = 60;

    public function guardar(): void
    {
        if (!Session::isLoggedIn()) {
            $this->json(['success' => false], 401);
        }
        $this->validateCsrf();

        $tipo = (string) $this->input('tipo', '');
        if (!isset(self::ROLES[$tipo], BorradorModel::TIPOS[$tipo]) || !Session::hasRole(self::ROLES[$tipo])) {
            $this->json(['success' => false], 403);
        }

        // S1: el dueño SIEMPRE es el usuario de la sesión.
        $usuarioId = (int) Session::user()['id'];

        if (Throttle::hit('borrador:' . $usuarioId, self::MAX_POR_MINUTO, 60)) {
            $this->json(['success' => false], 429);
        }

        // S2: el cliente manda ids sueltos; la clave la arma el modelo con enteros.
        $ctx = [];
        foreach (BorradorModel::TIPOS[$tipo] as $campo) {
            $ctx[$campo] = $this->input($campo);
        }
        $clave = BorradorModel::clave($tipo, $ctx);
        $model = new BorradorModel();
        if ($clave === null || !$model->contextoValido($tipo, $ctx)) {
            $this->json(['success' => false], 400);
        }

        // S6: tamaño y forma. Debe ser un objeto JSON.
        $datos = $this->input('datos', '');
        if (!is_string($datos) || $datos === '' || strlen($datos) > BorradorModel::MAX_BYTES) {
            $this->json(['success' => false], 413);
        }
        $decodificado = json_decode($datos, true);
        if (!is_array($decodificado)) {
            $this->json(['success' => false], 400);
        }

        $revision = filter_var($this->input('revision', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        try {
            $r = $model->guardar(
                $usuarioId,
                (int) $ctx['matricula'],
                $tipo,
                $clave,
                // Se reescribe con json_encode: se guarda lo VALIDADO, no el texto crudo.
                json_encode($decodificado, JSON_UNESCAPED_UNICODE),
                $revision === false ? 0 : $revision
            );
        } catch (\Exception $e) {
            // S10: el contenido del borrador NO va al log.
            log_error('No se pudo guardar un borrador', [
                'tipo' => $tipo, 'usuario' => $usuarioId, 'error' => $e->getMessage(),
            ]);
            $this->json(['success' => false], 500);
        }

        // S8: otra pestaña o equipo lo cambió → conflicto, sin pisar.
        $this->json(['success' => $r['ok'], 'revision' => $r['revision']], $r['ok'] ? 200 : 409);
    }
}
