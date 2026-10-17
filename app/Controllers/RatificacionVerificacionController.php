<?php

namespace App\Controllers;

use App\Models\RatificacionModel;
use Core\View;

/**
 * Verificación PÚBLICA del documento de RATIFICACIÓN por el QR (migración 080,
 * 15/10/2026). Ver docs/modulos/ratificaciones.md.
 *
 * Sin login, como `FirmaController` y la boleta por token: por eso vive aquí y
 * no en `Admin\RatificacionController`, que exige sesión en su constructor.
 *
 * Solo responde si el documento es AUTÉNTICO: nombre, año, fecha de emisión y
 * si sigue vigente o fue anulado. Nunca DNI, tipo ni motivo. Un token mal
 * formado y uno desconocido reciben la MISMA respuesta.
 */
class RatificacionVerificacionController extends BaseController
{
    // GET /ratificacion/verificar/{token}
    public function verificar(string $token): void
    {
        $doc = (new RatificacionModel())->porToken($token);
        if ($doc === null) {
            http_response_code(404);
        }

        View::setLayout('digital');
        $this->view('ratificacion/verificar', [
            'titulo'      => 'Verificación de documento',
            'institucion' => config('institucion'),
            'doc'         => $doc,
        ]);
    }
}
