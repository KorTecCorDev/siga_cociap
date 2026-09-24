<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CurriculumModel;
use Core\Session;

class CurriculumController extends BaseController
{
    private CurriculumModel $model;

    public function __construct()
    {
        $this->requireRole('admin');
        $this->model = new CurriculumModel();
    }

    // GET /admin/curriculum
    public function index(): void
    {
        $nivelId = max(1, (int)($this->query('nivel') ?? 1));
        $areaId  = (int)($this->query('area') ?? 0);

        $niveles    = $this->model->getNiveles();
        $nivelesIds = array_map('intval', array_column($niveles, 'id'));

        if (!in_array($nivelId, $nivelesIds) && !empty($nivelesIds)) {
            $nivelId = $nivelesIds[0];
        }

        $areas = $this->model->getAreasParaSidebar($nivelId);

        if (!$areaId && !empty($areas)) {
            $areaId = (int)$areas[0]['id'];
        }

        $area = $areaId ? $this->model->getAreaDetalle($areaId) : null;

        // Aprobacion de la UGEL (solo talleres): por AÑO y GRADO. Se muestra el
        // año pedido por URL, si no el activo, si no el mas reciente.
        $anios = $anioSel = $aprobacion = $resolucion = null;
        if ($area && $area['tipo'] === 'taller') {
            $anios   = $this->model->aniosAcademicos();
            $anioSel = $this->elegirAnio($anios, (int) ($this->query('anio') ?? 0));
            $aprobacion = $anioSel ? $this->model->aprobacionTaller((int) $area['id'], (int) $anioSel['id']) : [];
            $resolucion = $anioSel ? $this->model->resolucionTaller((int) $area['id'], (int) $anioSel['id']) : null;
        }

        $this->view('admin/curriculum/index', [
            'titulo'      => 'Curriculo Academico',
            'niveles'     => $niveles,
            'nivelActivo' => $nivelId,
            'areas'       => $areas,
            'areaId'      => $areaId,
            'area'        => $area,
            'anios'       => $anios,
            'anioSel'     => $anioSel,
            'aprobacion'  => $aprobacion,
            'resolucion'  => $resolucion,
        ]);
    }

    // POST /admin/curriculum/areas/{id}/aprobacion
    // Aprobacion de la UGEL de un TALLER para un año, grado por grado (migracion
    // 064). Decide si el taller cuenta para la situacion final de ese año y
    // grado. Siempre editable (decision del usuario), tambien en años cerrados.
    public function guardarAprobacionTaller(string $id): void
    {
        $this->validateCsrf();
        $id      = (int) $id;
        $nivelId = (int) ($this->input('nivel_id') ?? 1);
        $anioId  = (int) ($this->input('anio_id') ?? 0);
        $back    = url('admin/curriculum?nivel=' . $nivelId . '&area=' . $id . '&anio=' . $anioId);

        $area = $this->model->getAreaDetalle($id);
        if (!$area || $area['tipo'] !== 'taller') {
            $this->redirectWithError($back, 'Solo los talleres tienen aprobación de la UGEL.');
        }
        $anio = $this->elegirAnio($this->model->aniosAcademicos(), $anioId);
        if (!$anio || (int) $anio['id'] !== $anioId) {
            $this->redirectWithError($back, 'Año académico no válido.');
        }

        // Solo grados del nivel del taller: lo que llegue fuera de esa lista se
        // descarta (nunca se confia en el formulario).
        $validos  = array_map('intval', array_column($this->model->aprobacionTaller($id, $anioId), 'grado_id'));
        $marcados = array_values(array_intersect(
            array_map('intval', (array) ($this->input('grados') ?? [])),
            $validos
        ));

        // Resolucion Directoral DEL COLEGIO que crea el taller (sustento, no la
        // aprobacion de la UGEL). Numero hasta 60; fecha opcional y valida.
        $rdNumero = trim((string) ($this->input('rd_numero') ?? ''));
        $rdFecha  = trim((string) ($this->input('rd_fecha') ?? ''));
        if (mb_strlen($rdNumero) > 60) {
            $this->redirectWithError($back, 'El número de la Resolución Directoral admite como máximo 60 caracteres.');
        }
        if ($rdFecha !== '') {
            $f = \DateTime::createFromFormat('Y-m-d', $rdFecha);
            if (!$f || $f->format('Y-m-d') !== $rdFecha) {
                $this->redirectWithError($back, 'La fecha de la Resolución Directoral no es válida.');
            }
            if ($rdNumero === '') {
                $this->redirectWithError($back, 'Indica el número de la Resolución Directoral si registras su fecha.');
            }
        }
        $rd = $rdNumero !== '' ? ['numero' => $rdNumero, 'fecha' => $rdFecha !== '' ? $rdFecha : null] : null;

        $this->model->guardarAprobacionTaller($id, $anioId, $marcados, $rd, (int) Session::user()['id']);

        $this->redirectWithSuccess($back, $marcados
            ? 'Aprobación guardada: el taller cuenta para la situación final de ' . count($marcados) . ' grado(s) en ' . $anio['anio'] . '.'
            : 'Aprobación guardada: el taller NO cuenta para la situación final en ' . $anio['anio'] . '.');
    }

    /** El año pedido si existe; si no, el activo; si no, el mas reciente. */
    private function elegirAnio(array $anios, int $pedido): ?array
    {
        foreach ($anios as $a) { if ((int) $a['id'] === $pedido) { return $a; } }
        foreach ($anios as $a) { if ($a['estado'] === 'activo') { return $a; } }
        return $anios[0] ?? null;
    }

    // POST /admin/curriculum/areas/{id}/editar
    public function guardarArea(string $id): void
    {
        $this->validateCsrf();
        $id      = (int)$id;
        $nivelId = (int)($this->input('nivel_id') ?? 1);
        $back    = url('admin/curriculum?nivel=' . $nivelId . '&area=' . $id);

        $nombre = trim($this->input('nombre') ?? '');
        if ($nombre === '') {
            $this->redirectWithError($back, 'El nombre del area es obligatorio.');
        }

        // Codigo(s) de hoja SIAGIE. Formato: uno o varios codigos de 2-4 digitos
        // separados por coma ('063', '0006,0007'). Es el vinculo hoja->area que
        // usa el exportador; un codigo mal puesto manda un area entera a la hoja
        // equivocada del acta oficial, asi que se valida formato Y colision.
        $codigoSiagie = preg_replace('/\s+/', '', (string)($this->input('codigo_siagie') ?? ''));
        if ($codigoSiagie !== '') {
            if (!preg_match('/^\d{2,4}(,\d{2,4})*$/', $codigoSiagie)) {
                $this->redirectWithError($back,
                    'El codigo SIAGIE debe ser uno o varios codigos de 2 a 4 digitos separados por coma (ej: 063 o 0006,0007).');
            }
            $enUso = $this->model->areaConCodigoSiagie($nivelId, explode(',', $codigoSiagie), $id);
            if ($enUso !== null) {
                $this->redirectWithError($back,
                    "El codigo SIAGIE ya lo usa el area '{$enUso}' en este nivel. Dos areas no pueden compartir hoja: el acta se llenaria con el area equivocada.");
            }
        }

        $this->model->actualizarArea($id, [
            'nombre'        => $nombre,
            'nombre_boleta' => trim($this->input('nombre_boleta') ?? '') ?: null,
            'alias_boleta'  => trim($this->input('alias_boleta')  ?? '') ?: null,
            'nombre_siagie' => trim($this->input('nombre_siagie') ?? '') ?: null,
            'codigo_siagie' => $codigoSiagie ?: null,
            'orden'         => (int)($this->input('orden') ?? 0),
        ]);

        $this->redirectWithSuccess($back, 'Area actualizada correctamente.');
    }

    // POST /admin/curriculum/subareas/{id}/editar
    public function guardarSubarea(string $id): void
    {
        $this->validateCsrf();
        $id      = (int)$id;
        $areaId  = (int)($this->input('area_id')  ?? 0);
        $nivelId = (int)($this->input('nivel_id') ?? 1);
        $back    = url('admin/curriculum?nivel=' . $nivelId . '&area=' . $areaId);

        $nombre = trim($this->input('nombre') ?? '');
        if ($nombre === '') {
            $this->redirectWithError($back, 'El nombre de la subarea es obligatorio.');
        }

        $this->model->actualizarSubarea($id, $nombre, (int)($this->input('orden') ?? 0));
        $this->redirectWithSuccess($back, 'Subarea actualizada correctamente.');
    }

    // POST /admin/curriculum/competencias/{id}/editar
    public function guardarCompetencia(string $id): void
    {
        $this->validateCsrf();
        $id      = (int)$id;
        $areaId  = (int)($this->input('area_id')  ?? 0);
        $nivelId = (int)($this->input('nivel_id') ?? 1);
        $back    = url('admin/curriculum?nivel=' . $nivelId . '&area=' . $areaId);

        $nombreCompleto = trim($this->input('nombre_completo') ?? '');
        if ($nombreCompleto === '') {
            $this->redirectWithError($back, 'El nombre de la competencia es obligatorio.');
        }

        $this->model->actualizarCompetencia(
            $id,
            trim($this->input('codigo_minedu') ?? '') ?: null,
            $nombreCompleto,
            trim($this->input('nombre_corto') ?? '') ?: null,
            (int)($this->input('orden') ?? 0)
        );

        $this->redirectWithSuccess($back, 'Competencia actualizada correctamente.');
    }

    // POST /admin/curriculum/areas/{id}/mover
    public function moverArea(string $id): void
    {
        $this->validateCsrf();
        $id        = (int)$id;
        $nivelId   = (int)($this->input('nivel_id')  ?? 1);
        $areaId    = (int)($this->input('area_id')   ?? $id);
        $direccion = $this->input('direccion') === 'down' ? 'down' : 'up';
        $back      = url('admin/curriculum?nivel=' . $nivelId . '&area=' . $areaId);

        $this->model->moverArea($id, $direccion);
        $this->redirectWithSuccess($back, 'Orden actualizado.');
    }

    // POST /admin/curriculum/areas/{id}/toggle
    public function toggleActivaArea(string $id): void
    {
        $this->validateCsrf();
        $id      = (int)$id;
        $nivelId = (int)($this->input('nivel_id') ?? 1);
        $back    = url('admin/curriculum?nivel=' . $nivelId . '&area=' . $id);

        $this->model->toggleActivaArea($id);
        $this->redirectWithSuccess($back, 'Estado del area actualizado.');
    }
}
