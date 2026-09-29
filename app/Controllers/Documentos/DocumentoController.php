<?php

namespace App\Controllers\Documentos;

use App\Controllers\BaseController;
use App\Models\AuxiliarSeccionModel;
use App\Models\NominaDocenteModel;
use Core\Session;
use Core\View;

/**
 * Documentos de consulta del día a día que comparten varios roles (28/09/2026):
 * la nómina de docentes (F4b) y, en la F4c, la planilla de asistencia manual.
 * Ver docs/modulos/auxiliares.md.
 *
 * SOLO LEE. El acceso se guarda POR MÉTODO (cada documento decide sus roles):
 * admin, RA y Dirección ven todo el colegio; el auxiliar, solo lo de SUS
 * secciones del bimestre en curso (`AuxiliarSeccionModel::seccionesDe`).
 */
class DocumentoController extends BaseController
{
    /** Quien ve el documento de TODO el colegio (decisión D9). */
    private const ROLES_COLEGIO = ['admin', 'registro_academico', ...ROLES_DIRECCION];

    // GET /documentos/nomina-docentes[?nivel=ID]
    // Decisiones D8/D9 y las del 28/09/2026: por nivel con filtro; el DNI solo
    // para admin, RA y Dirección; el auxiliar ve a los docentes de sus
    // secciones, con todas sus cargas.
    public function nominaDocentes(): void
    {
        $this->requireRole([...self::ROLES_COLEGIO, ROL_AUXILIAR]);

        $esAuxiliar = Session::hasRole(ROL_AUXILIAR);
        $seccionIds = null;
        if ($esAuxiliar) {
            $seccionIds = $this->seccionesDelAuxiliar();
            if ($seccionIds === []) {
                $this->forbidden();
            }
        }

        $niveles = (new NominaDocenteModel())->documento($seccionIds);

        // El filtro solo acepta niveles que el documento trae: nunca ids a mano.
        $nivelId = (int) $this->query('nivel', 0);
        $visibles = isset($niveles[$nivelId]) ? [$nivelId => $niveles[$nivelId]] : $niveles;

        $anio = (new AuxiliarSeccionModel())->periodoActivo();

        View::setLayout('print');
        $this->view('documentos/nomina-docentes', [
            'titulo'    => 'Nómina de docentes',
            'bodyClass' => 'doc-landscape',
            'niveles'   => $visibles,
            'filtro'    => array_map(static fn($n) => $n['nombre'], $niveles),
            'nivelId'   => isset($niveles[$nivelId]) ? $nivelId : 0,
            'conDni'    => !$esAuxiliar,
            'anio'      => $anio['anio'] ?? null,
        ]);
    }

    /**
     * Secciones a cargo del auxiliar en el bimestre EN CURSO (documentos del día
     * a día: sin historial). Vacío si no hay bimestre activo o no tiene ninguna.
     *
     * @return int[]
     */
    private function seccionesDelAuxiliar(): array
    {
        $aux     = new AuxiliarSeccionModel();
        $periodo = $aux->periodoActivo();
        if ($periodo === null) {
            return [];
        }
        return $aux->seccionesDe((int) Session::user()['id'], (int) $periodo['id']);
    }
}
