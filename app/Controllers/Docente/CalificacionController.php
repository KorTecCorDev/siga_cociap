<?php

namespace App\Controllers\Docente;

use App\Controllers\BaseController;
use App\Models\CalificacionModel;
use App\Models\CriterioModel;
use App\Models\ExoneracionModel;
use App\Models\OmisionCriterioModel;
use App\Models\RectificacionModel;
use Core\Session;

/**
 * CalificacionController
 * Panel del docente para gestión de criterios y notas.
 */


class CalificacionController extends BaseController
{
    /** Longitud máxima del nombre de un criterio; el detalle va en `descripcion` */
    public const CRITERIO_NOMBRE_MAX = 100;

    private CalificacionModel    $calModel;
    private CriterioModel        $critModel;
    private OmisionCriterioModel $omisionModel;
    private ExoneracionModel     $exoModel;

    public function __construct()
    {
        $this->requireRole(['docente', 'admin', 'registro_academico']);
        $this->calModel    = new CalificacionModel();
        $this->critModel   = new CriterioModel();
        $this->omisionModel = new OmisionCriterioModel();
        $this->exoModel    = new ExoneracionModel();
    }
    
    private function getBloqueos(int $cargaId, int $periodoId): array
    {
        $resultado = $this->calModel->query("
            SELECT competencia_id
            FROM bloqueos_competencia
            WHERE carga_id  = ?
            AND periodo_id = ?
        ", [$cargaId, $periodoId]);

        // Retorna array de IDs bloqueados para chequeo rápido
        return array_column($resultado, 'competencia_id');
    }

    /**
     * GET /docente/mis-cargas
     * Lista las cargas académicas del docente en el periodo activo.
     */
    public function misCargas(): void
    {
        $user          = Session::user();
        $periodoActivo = $this->getPeriodoActivo();

        // Bimestres seleccionables del año vigente: activo + cerrados. Sirven
        // para que el docente revise sus notas de bimestres pasados (read-only).
        $periodos = $this->calModel->query("
            SELECT p.id, p.numero, p.nombre_display, p.estado, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE a.estado = 'activo'
              AND p.estado IN ('activo', 'cerrado')
            ORDER BY p.numero ASC
        ");

        // Periodo seleccionado: el de la query si es valido; si no, el activo.
        $periodoId = (int) ($this->query('periodo_id') ?? 0);
        $periodo   = null;
        foreach ($periodos as $p) {
            if ((int) $p['id'] === $periodoId) {
                $periodo = $p;
                break;
            }
        }
        if (!$periodo) {
            $periodo = $periodoActivo;
        }

        // Historico = el periodo elegido NO es el activo (grilla en solo lectura).
        $esHistorico = $periodo
            && (!$periodoActivo || (int) $periodo['id'] !== (int) $periodoActivo['id']);

        $cargas = $this->getCargas($user['id'], $periodo ? (int) $periodo['id'] : 0);

        // Docente de aula (unidocente): es tutor(a) de aula solo si es el TUTOR de
        // una seccion es_unidocente (es_aula). Un especialista (Ingles, Ed. Fisica)
        // que dicta en secciones unidocentes NO es aula. La vista marca ESE grupo
        // como "Mi aula"; las demas secciones (caso mixto: ademas especialista en
        // otro grado) se listan normalmente con su propio encabezado.
        $tieneAula = false;
        $aula      = null;
        foreach ($cargas as $c) {
            if (!empty($c['es_aula'])) {
                $tieneAula = true;
                $aula      = trim($c['grado_nombre'] . ' ' . $c['seccion_nombre']);
                break;
            }
        }

        // Tutoría y Conducta tienen sus propias cards de acceso en el dashboard
        // (/docente/inicio); aquí solo se listan las cargas académicas.
        $this->view('docente/mis-cargas', [
            'titulo'      => 'Mis cargas académicas',
            'cargas'      => $cargas,
            'periodo'     => $periodo,
            'periodos'    => $periodos,
            'esHistorico' => $esHistorico,
            'tieneAula'   => $tieneAula,
            'aula'        => $aula,
        ]);
    }

    /**
     * GET /docente/calificaciones/{carga_id}/historial/{periodo_id}
     * Grilla criterio-a-criterio de SU carga en un bimestre cerrado, SOLO
     * LECTURA. Muestra unicamente las competencias oficiales (bloqueadas) y
     * reutiliza el parcial consulta-notas/_tabla.php. Para corregir se usa
     * Rectificacion (RA); aqui no hay edicion.
     */
    public function historial(string $cargaId, string $periodoId): void
    {
        $cargaId   = (int) $cargaId;
        $periodoId = (int) $periodoId;

        // validarCargaDocente filtra por docente_id = usuario actual: garantiza
        // que el docente solo abra SUS propias cargas.
        $carga = $this->validarCargaDocente($cargaId);
        if (!$carga) {
            $this->redirectWithError(url('docente/mis-cargas'), 'Carga no encontrada.');
        }

        $periodo = $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);
        if (!$periodo) {
            $this->redirectWithError(url('docente/mis-cargas'), 'Periodo no encontrado.');
        }

        // Competencias OFICIALES (bloqueadas) de la carga en ese periodo.
        $exonerados   = $this->exoModel->getActivasParaCarga($cargaId, (int) $periodo['anio_id']);
        $competencias = $this->bloquesBloqueadosDeCarga($cargaId, $periodoId);

        $this->view('docente/historial-carga', [
            'titulo'       => 'Historial — ' . ($carga['nombre_display'] ?? ''),
            'carga'        => $carga,
            'periodo'      => $periodo,
            'competencias' => $competencias,
            // Sección ÚNICA de extraordinarias al final (21/09/2026).
            // Las de Ética del I Bimestre 2026 son reservadas a dirección: al
            // DOCENTE no se le explican (RectificacionModel::RESERVADA_DIRECCION_*).
            'extraordinariasCarga' => (new RectificacionModel())
                ->getExtraordinariasDeCargas([$cargaId], $periodoId, Session::hasRole('docente')),
            'exonerados'   => $exonerados,
        ]);
    }

    /**
     * Bloques read-only {competencia, criterios, alumnos} de las competencias
     * OFICIALES (bloqueadas) de una carga en un periodo. Compartido por el
     * histórico de carga (historial) y el histórico de área (historialArea).
     */
    private function bloquesBloqueadosDeCarga(int $cargaId, int $periodoId): array
    {
        $bloqueadas = $this->calModel->query("
            SELECT comp.id,
                   comp.nombre_completo,
                   comp.codigo_minedu,
                   (a.tipo = 'transversal') AS es_transversal
            FROM bloqueos_competencia bc
            INNER JOIN competencias comp ON comp.id = bc.competencia_id
            LEFT  JOIN areas a ON a.id = comp.area_id
            WHERE bc.carga_id   = ?
              AND bc.periodo_id = ?
            ORDER BY comp.orden, comp.id
        ", [$cargaId, $periodoId]);

        $bloques = [];
        foreach ($bloqueadas as $b) {
            $competenciaId = (int) $b['id'];
            $resumen = $this->calModel->getResumenCompetencia($cargaId, $competenciaId, $periodoId);

            $omisionesPorCriterio = [];
            foreach ($resumen['criterios'] as $cr) {
                $omisionesPorCriterio[(int) $cr['id']] =
                    $this->omisionModel->getPorCriterio((int) $cr['id']);
            }
            foreach ($resumen['alumnos'] as &$al) {
                $al['omisiones_criterios'] = [];
                $mid = (int) $al['matricula_id'];
                foreach ($omisionesPorCriterio as $critId => $porMat) {
                    if (isset($porMat[$mid])) {
                        $al['omisiones_criterios'][$critId] = $porMat[$mid];
                    }
                }
            }
            unset($al);

            $bloques[] = [
                'competencia' => [
                    'nombre_completo' => $b['nombre_completo'],
                    'codigo_minedu'   => $b['codigo_minedu'],
                    'es_transversal'  => $b['es_transversal'],
                ],
                'criterios'       => $resumen['criterios'],
                'alumnos'         => $resumen['alumnos'],
            ];
        }
        return $bloques;
    }

    /**
     * Subárea-cargas ACTIVAS del docente para un área en una sección, ordenadas
     * por orden de subárea (la primera es la DUEÑA de las transversales). Cada
     * fila trae meta de sección/área/nivel. Vacío si el docente no dicta esa
     * área en esa sección. Base de la vista de área unidocente (formularioArea/
     * historialArea); el caller valida `es_aula` (requester es el tutor) con la
     * primera fila.
     */
    private function getCargasAreaDocente(int $seccionId, int $areaId, int $usuarioId): array
    {
        return $this->calModel->query("
            SELECT
                ca.id              AS carga_id,
                ca.subarea_id,
                sa.orden           AS subarea_orden,
                sa.nombre          AS subarea_nombre,
                s.es_unidocente,
                -- es_aula: este docente es el TUTOR de la seccion unidocente. La
                -- vista de area consolidada es del aula; un especialista (que solo
                -- dicta un area-curso) no debe abrirla aunque la seccion sea
                -- unidocente.
                (s.es_unidocente = 1 AND s.tutor_id = ca.docente_id) AS es_aula,
                s.nombre           AS seccion_nombre,
                g.nombre_display   AS grado_nombre,
                n.id               AS nivel_id,
                n.nombre           AS nivel_nombre,
                n.codigo           AS nivel_codigo,
                n.escala_boleta,
                a.id               AS area_id,
                a.nombre           AS area_nombre,
                a.tipo             AS area_tipo
            FROM cargas_academicas ca
            INNER JOIN secciones s  ON s.id  = ca.seccion_id
            INNER JOIN grados g     ON g.id  = s.grado_id
            INNER JOIN niveles n    ON n.id  = g.nivel_id
            LEFT  JOIN subareas sa  ON sa.id = ca.subarea_id
            INNER JOIN areas a      ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE ca.seccion_id = ?
              AND ca.docente_id = ?
              AND ca.estado     = 'activa'
              AND a.id          = ?
            ORDER BY COALESCE(sa.orden, 0), ca.id
        ", [$seccionId, $usuarioId, $areaId]);
    }

    /**
     * GET /docente/calificaciones/area/{seccion_id}/{area_id}
     * Vista UNIFICADA de un área para una sección UNIDOCENTE: una sola pantalla
     * con TODAS las competencias de las subárea-cargas del área + las
     * transversales (una vez, en la carga dueña). Reutiliza la vista
     * calificaciones.php — cada card lleva su propio carga_id, así que los
     * endpoints de guardar/confirmar/aprobar siguen siendo por carga.
     */
    public function formularioArea(string $seccionId, string $areaId): void
    {
        $seccionId = (int) $seccionId;
        $areaId    = (int) $areaId;
        $user      = Session::user();
        $periodo   = $this->getPeriodoActivo();

        if (!$periodo) {
            $this->redirectWithError(url('docente/mis-cargas'), 'No hay un periodo activo.');
        }

        $cargasArea = $this->getCargasAreaDocente($seccionId, $areaId, $user['id']);
        if (empty($cargasArea) || empty($cargasArea[0]['es_aula'])) {
            $this->redirectWithError(
                url('docente/mis-cargas'),
                'Vista de área no disponible para esta sección.'
            );
        }

        $bloqueado = $this->calModel->periodoEstaBloqueado($periodo['id']);
        $meta      = $cargasArea[0];
        $nivelId   = (int) $meta['nivel_id'];

        // Dueña de las transversales del área a nivel SECCIÓN (misma regla que
        // getCargas/estadoCargasSeccion): la subárea-carga activa de menor orden.
        // Se exige que pertenezca a este docente (en una sección unidocente real
        // todas las subáreas del área son suyas) para adjuntarle las TIC/GAMA;
        // así el bloqueo vive donde el conteo del cierre lo espera.
        $idsArea      = array_map(static fn($c) => (int) $c['carga_id'], $cargasArea);
        $duenaCargaId = $this->calModel->cargaDuenaTransversales($seccionId, $areaId);
        $duenaPropia  = $duenaCargaId !== null && in_array($duenaCargaId, $idsArea, true);

        // Acumula competencias (cada una con su carga_id), notas, bloqueos y
        // exonerados de TODAS las subárea-cargas del área. Las claves de notas
        // (criterio_id) y los ids de competencia son únicos por carga, así que la
        // unión no colisiona.
        $competencias    = [];
        $notasExistentes = [];
        $bloqueos        = [];
        $exonerados      = [];

        foreach ($cargasArea as $cr) {
            $cid   = (int) $cr['carga_id'];
            $comps = $this->critModel->getCompetenciasConCriterios($cid, $periodo['id']);
            foreach ($comps as $comp) {
                $comp['carga_id'] = $cid;
                $competencias[]   = $comp;
            }
            $notasExistentes += $this->getNotasExistentes($cid, $periodo['id']);
            foreach ($this->getBloqueos($cid, $periodo['id']) as $b) {
                $bloqueos[] = (int) $b;
            }
            foreach ($this->exoModel->getActivasParaCarga($cid, (int) $periodo['anio_id']) as $ex) {
                $exonerados[] = (int) $ex;
            }
        }
        $exonerados = array_values(array_unique($exonerados));

        // Transversales TIC/GAMA: una sola vez por área, en la carga dueña. Sus
        // notas y bloqueos ya entraron arriba (la dueña es una de las cargas).
        if ($duenaPropia) {
            $transversales = $this->critModel->getCompetenciasTransversalesConCriterios(
                $duenaCargaId, $periodo['id'], $nivelId
            );
            foreach ($transversales as $t) {
                $t['carga_id']  = $duenaCargaId;
                $competencias[] = $t;
            }
        }

        $alumnos = $this->getAlumnosSeccion($seccionId);

        // Piso de "no se evaluó": área-aware (cubre todas las subárea-cargas del
        // área en la sección unidocente). Da igual qué carga del área se pase.
        $permiteNoEvaluar = $this->calModel->permiteNoEvaluarEnCarga(
            (int) $meta['carga_id'], (int) $periodo['id']
        );

        // Carga sintética: la vista lee el título por área (es_unidocente) y usa
        // `id` solo como fallback (cada card ya trae su carga_id real).
        $carga = [
            'id'             => (int) $meta['carga_id'],
            'es_unidocente'  => 1,
            'area_nombre'    => $meta['area_nombre'],
            'nombre_display' => $meta['area_nombre'],
            'nivel_nombre'   => $meta['nivel_nombre'],
            'nivel_codigo'   => $meta['nivel_codigo'],
            'grado_nombre'   => $meta['grado_nombre'],
            'seccion_nombre' => $meta['seccion_nombre'],
            'seccion_id'     => $seccionId,
        ];

        $this->view('docente/calificaciones', [
            'extraordinariasPorComp' => $this->extraordinariasPorCompetencia($competencias, (int) $meta['carga_id'], (int) $periodo['id']),
            'titulo'           => 'Calificaciones — ' . ($meta['area_nombre'] ?? ''),
            'carga'            => $carga,
            'periodo'          => $periodo,
            'competencias'     => $competencias,
            'alumnos'          => $alumnos,
            'bloqueado'        => $bloqueado,
            'notasExistentes'  => $notasExistentes,
            'bloqueos'         => $bloqueos,
            'exonerados'       => $exonerados,
            'permiteNoEvaluar' => $permiteNoEvaluar,
            'page_scripts'     => ['calificaciones'],
        ]);
    }

    /**
     * GET /docente/calificaciones/area/{seccion_id}/{area_id}/historial/{periodo_id}
     * Histórico de área (solo lectura) para una sección UNIDOCENTE: reúne las
     * competencias OFICIALES (bloqueadas) de todas las subárea-cargas del área
     * en un bimestre cerrado y reutiliza la vista del histórico de carga.
     */
    public function historialArea(string $seccionId, string $areaId, string $periodoId): void
    {
        $seccionId = (int) $seccionId;
        $areaId    = (int) $areaId;
        $periodoId = (int) $periodoId;
        $user      = Session::user();

        $cargasArea = $this->getCargasAreaDocente($seccionId, $areaId, $user['id']);
        if (empty($cargasArea) || empty($cargasArea[0]['es_aula'])) {
            $this->redirectWithError(
                url('docente/mis-cargas'),
                'Vista de área no disponible para esta sección.'
            );
        }

        $periodo = $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);
        if (!$periodo) {
            $this->redirectWithError(url('docente/mis-cargas'), 'Periodo no encontrado.');
        }

        $meta         = $cargasArea[0];
        $competencias = [];
        $exonerados   = [];

        foreach ($cargasArea as $cr) {
            $cid = (int) $cr['carga_id'];
            foreach ($this->bloquesBloqueadosDeCarga($cid, $periodoId) as $bloque) {
                $competencias[] = $bloque;
            }
            foreach ($this->exoModel->getActivasParaCarga($cid, (int) $periodo['anio_id']) as $ex) {
                $exonerados[] = (int) $ex;
            }
        }
        $exonerados = array_values(array_unique($exonerados));

        $carga = [
            'nombre_display' => $meta['area_nombre'],
            'area_nombre'    => $meta['area_nombre'],
            'grado_nombre'   => $meta['grado_nombre'],
            'seccion_nombre' => $meta['seccion_nombre'],
            'nivel_nombre'   => $meta['nivel_nombre'],
            'nivel_codigo'   => $meta['nivel_codigo'],
        ];

        $this->view('docente/historial-carga', [
            'titulo'       => 'Historial — ' . ($meta['area_nombre'] ?? ''),
            'carga'        => $carga,
            'periodo'      => $periodo,
            'competencias' => $competencias,
            // Sección ÚNICA de extraordinarias al final, de todas las cargas del área.
            'extraordinariasCarga' => (new RectificacionModel())
                ->getExtraordinariasDeCargas(array_column($cargasArea, 'carga_id'), $periodoId, Session::hasRole('docente')),
            'exonerados'   => $exonerados,
        ]);
    }

    /**
     * GET /docente/calificaciones/{carga_id}
     * Muestra las competencias y criterios de una carga.
     */
    public function formulario(string $cargaId): void
    {
        $cargaId = (int) $cargaId;
        $periodo = $this->getPeriodoActivo();

        if (!$periodo) {
            $this->redirectWithError(
                url('docente/mis-cargas'),
                'No hay un periodo activo.'
            );
        }

        $carga = $this->validarCargaDocente($cargaId);
        if (!$carga) {
            $this->redirectWithError(
                url('docente/mis-cargas'),
                'Carga no encontrada.'
            );
        }

        // Modelo nuevo: las competencias transversales (TIC/GAMA) las registra
        // cada docente DENTRO de su propia carga (sección "Competencias
        // Transversales" más abajo). Una carga transversal independiente es del
        // modelo viejo: ya no se califica aquí. El tutor solo agrega las
        // conclusiones y cierra el bimestre desde /docente/tutoria.
        if ($carga['area_tipo'] === 'transversal') {
            $this->redirectWithSuccess(
                url('docente/tutoria'),
                'Las competencias transversales ahora se registran en cada carga. '
                . 'Como tutor(a), aquí agregas las conclusiones y cierras el bimestre.'
            );
        }

        $bloqueado       = $this->calModel->periodoEstaBloqueado($periodo['id']);
        $competencias    = $this->critModel->getCompetenciasConCriterios(
            $cargaId,
            $periodo['id']
        );

        // Competencias transversales (TIC/GAMA): cada docente las registra
        // en su propia carga con el mismo mecanismo de criterios y notas
        // (Variante 1 = viven en la carga de cada docente, no en una carga
        // transversal dedicada como en el modelo de B1).
        // ⚠️ Se bloquean POR SEPARADO, una por una, igual que las propias.
        // El empaquetado con "la última competencia propia" se retiró en el II
        // Bimestre; ver el docblock de bloquear(). Este comentario afirmaba lo
        // contrario hasta el 07/08/2026.
        //
        // UNIDOCENTE: el mismo docente dicta todas las subáreas, así que las
        // TIC/GAMA se adjuntan UNA sola vez por área —en la carga dueña (subárea
        // de menor orden)— y no se duplican en cada subárea. Para especialistas
        // (no unidocente) cada carga lleva las suyas, como siempre.
        //
        // ÉTICA Y VALORES (área tipo 'tutoria'): la carga del tutor NO lleva la
        // sección de transversales — las TIC/GAMA se registran en las cargas
        // académicas regulares de cada docente, nunca en la de tutoría
        // (decisión 07/07/2026).
        //
        // ⚠️ ESTAS DOS EXCLUSIONES SON EL UNIVERSO CANÓNICO de las transversales
        // y están escritas en CUATRO SITIOS (mantenerlos en sync; si cambia uno,
        // revisar los otros tres):
        //   1. AQUI                                              (formulario del docente)
        //   2. TransversalModel::estadoCargasSeccion             (gate del cierre del tutor)
        //   3. CalificacionModel::cargaDuenaTransversales
        //   4. AnioAcademicoModel::bloquearCompetenciasPendientes (cierre forzado)
        // El sitio 4 no las aplicaba y el cierre de B2 inventó 130 bloqueos en
        // cargas que ningún docente podía bloquear — corregido el 06/08/2026,
        // limpieza de lo ya creado en la migración 051.
        $adjuntarTransversales = ($carga['area_tipo'] !== 'tutoria');
        if ($adjuntarTransversales && !empty($carga['es_unidocente'])) {
            $duena = $this->calModel->cargaDuenaTransversales(
                (int) $carga['seccion_id'],
                (int) $carga['area_resuelta_id']
            );
            $adjuntarTransversales = ($duena === $cargaId);
        }
        if ($adjuntarTransversales) {
            $transversales = $this->critModel->getCompetenciasTransversalesConCriterios(
                $cargaId,
                $periodo['id'],
                (int) $carga['nivel_id']
            );
            $competencias = array_merge($competencias, $transversales);
        }

        $alumnos         = $this->getAlumnosSeccion($carga['seccion_id']);
        $notasExistentes = $this->getNotasExistentes($cargaId, $periodo['id']);
        $bloqueos        = $this->getBloqueos($cargaId, $periodo['id']);
        $exonerados      = $this->exoModel->getActivasParaCarga($cargaId, (int) $periodo['anio_id']);

        // Piso de carga: si marcar "no se evaluó" dejaría la carga sin ninguna
        // calificación, no se ofrece el botón (el servidor también lo rechaza).
        $permiteNoEvaluar = $this->calModel->permiteNoEvaluarEnCarga(
            $cargaId,
            (int) $periodo['id']
        );

        $this->view('docente/calificaciones', [
            'titulo'          => 'Calificaciones — ' . (!empty($carga['es_unidocente'])
                ? ($carga['area_nombre'] ?? '')
                : ($carga['nombre_display'] ?? '')),
            'carga'           => $carga,
            'periodo'         => $periodo,
            'competencias'    => $competencias,
            'alumnos'         => $alumnos,
            'bloqueado'       => $bloqueado,
            'notasExistentes' => $notasExistentes,
            'bloqueos'        => $bloqueos,
            'exonerados'      => $exonerados,
            'permiteNoEvaluar' => $permiteNoEvaluar,
            'extraordinariasPorComp' => $this->extraordinariasPorCompetencia($competencias, $cargaId, (int) $periodo['id']),
            'page_scripts'    => ['calificaciones'],
        ]);
    }

    /**
     * Calificaciones extraordinarias de RA por competencia de la grilla,
     * indexadas "carga-competencia". En la grilla del docente la
     * extraordinaria YA NO se pinta como un criterio más (18/09/2026): se
     * muestra solo esta card informativa. El dato no cambia —sigue siendo el
     * criterio `extraordinario`—; solo cambia cómo se presenta.
     */
    private function extraordinariasPorCompetencia(array $competencias, int $cargaFallback, int $periodoId): array
    {
        $out = [];
        foreach ($competencias as $comp) {
            foreach ($comp['criterios'] ?? [] as $cr) {
                if (!empty($cr['extraordinario'])) {
                    $cid = (int) ($comp['carga_id'] ?? $cargaFallback);
                    $out[$cid . '-' . (int) $comp['id']] = (new RectificacionModel())
                        ->getExtraordinariasDeCompetencia($cid, (int) $comp['id'], $periodoId, Session::hasRole('docente'));
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * POST /docente/calificaciones/{carga_id}
     * Guarda las notas de un criterio para todos los alumnos.
     */
    public function guardar(string $cargaId): void
    {
        $this->validateCsrf();
        $cargaId = (int) $cargaId;

        $periodo = $this->getPeriodoActivo();
        if (!$periodo || $this->calModel->periodoEstaBloqueado($periodo['id'])) {
            $this->json([
                'success' => false,
                'mensaje' => 'El periodo está cerrado.',
            ], 403);
        }

        $criterioId    = (int) $this->input('criterio_id');
        $competenciaId = (int) $this->input('competencia_id');
        $notasRaw      = $this->input('notas', []);
        $omisionesRaw  = $this->input('omisiones', []);

        if (!is_array($notasRaw))     $notasRaw     = [];
        if (!is_array($omisionesRaw)) $omisionesRaw = [];

        // ── Verificar bloqueo de competencia ────────────────
        if ($this->calModel->competenciaBloqueada(
            $cargaId, $competenciaId, $periodo['id']
        )) {
            $this->json([
                'success' => false,
                'mensaje' => 'Esta competencia ya fue aprobada y bloqueada. No se pueden modificar las notas.',
            ], 403);
        }

        if (!$criterioId || !$competenciaId) {
            $this->json([
                'success' => false,
                'mensaje' => 'Datos incompletos.',
            ], 400);
        }

        // Criterio extraordinario (RA): el docente no escribe en él,
        // en ningún estado (incluida una reapertura). Solo Rectificación.
        if ($this->critModel->esExtraordinario($criterioId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Este criterio corresponde a una calificación extraordinaria de Registro Académico. Solo el módulo de Rectificación puede modificarlo.',
            ], 403);
        }

        // ── Normalizar entradas ─────────────────────────────
        // Notas válidas: numéricas, recortadas a 0-20. Omisiones válidas: motivo
        // del catálogo. Si un alumno llega en ambos, manda la nota (la omisión se
        // descarta) para que las dos colecciones sean disjuntas.
        $notas = [];
        foreach ($notasRaw as $mid => $val) {
            $val = trim((string) $val);
            if ($val === '' || !is_numeric($val)) continue;
            $notas[(int) $mid] = max(0, min(20, (int) $val));
        }
        $omisiones = [];
        foreach ($omisionesRaw as $mid => $motivo) {
            $mid = (int) $mid;
            if (isset($notas[$mid])) continue;
            if (!array_key_exists($motivo, OmisionCriterioModel::MOTIVOS)) continue;
            $omisiones[$mid] = $motivo;
        }

        // ── Validación dura del filtro de omisión (defensa de servidor) ──
        // Cada alumno del roster (excluidos exonerados) debe tener, para ESTE
        // criterio, una nota o una omisión (enviada ahora o ya registrada). Sin
        // esto, el sello de "Ver resumen" sería bypasseable por una petición
        // directa que omita las omisiones de los blancos.
        $resumen        = $this->calModel->getResumenCompetencia($cargaId, $competenciaId, (int) $periodo['id']);
        $exonerados     = $this->exoModel->getActivasParaCarga($cargaId, (int) $periodo['anio_id']);
        $exoSet         = array_flip(array_map('intval', $exonerados));
        $omisionPrevia  = $this->omisionModel->getPorCriterio($criterioId);

        $faltantes = 0;
        foreach ($resumen['alumnos'] as $a) {
            $mid = (int) $a['matricula_id'];
            if (isset($exoSet[$mid])) continue;
            $ok = isset($notas[$mid])
               || isset($omisiones[$mid])
               || isset($omisionPrevia[$mid]);
            if (!$ok) {
                $faltantes++;
            }
        }
        if ($faltantes > 0) {
            $this->json([
                'success' => false,
                'mensaje' => "Hay {$faltantes} alumno(s) en blanco sin motivo de omisión. "
                    . 'Registra una nota o justifica la omisión antes de confirmar.',
            ], 422);
        }

        // Nada que persistir y nada que justificar → no hay criterio que confirmar.
        if (empty($notas) && empty($omisiones)) {
            $this->json([
                'success' => false,
                'mensaje' => 'No hay notas que guardar.',
            ], 400);
        }

        // ── Persistencia ATÓMICA ────────────────────────────
        // Notas + omisiones + reagregación + sello en una sola transacción (PDO
        // singleton compartido por todos los modelos). Si algo falla, rollback
        // total y el criterio NO queda sellado. Elimina la ventana de los dos
        // fetch separados que dejaba "Ver resumen" abierto sin las omisiones.
        $userId = Session::user()['id'];
        $this->calModel->beginTransaction();
        try {
            // 1. Notas con valor (guardarNotaCriterio NO abre transacción propia)
            foreach ($notas as $mid => $nota) {
                $this->calModel->guardarNotaCriterio($criterioId, $mid, $nota);
            }
            // 2. Omitidos: borrar cualquier nota previa contradictoria + registrar motivo
            foreach (array_keys($omisiones) as $mid) {
                $this->calModel->eliminarNotaCriterio($criterioId, $mid);
            }
            if (!empty($omisiones)) {
                $this->omisionModel->guardarLote($criterioId, $omisiones, $userId);
            }
            // 3. Alumno con nota no conserva omisión que lo contradiga
            foreach (array_keys($notas) as $mid) {
                $this->omisionModel->eliminarOmision($criterioId, $mid);
            }
            // 4. Sellar como CONFIRMADO (desbloquea "Ver resumen") — SOLO tras
            //    pasar la validación. El autosave nunca llega aquí. DEBE ir ANTES
            //    de reagregar: el promedio ahora solo cuenta criterios
            //    confirmados, así que el sello tiene que existir para que este
            //    criterio entre en el cálculo.
            $this->critModel->marcarConfirmado($criterioId, $userId);
            // 5. Reagregar promedios + limpiar huérfanos (DELETE de filas sin
            //    notas confirmadas). Con el criterio ya sellado, su nota cuenta.
            $this->calModel->recalcularPromedioSeccion(
                $cargaId, $competenciaId, (int) $periodo['id'], $userId
            );

            $this->calModel->commit();
        } catch (\Exception $e) {
            $this->calModel->rollback();
            log_error('Error al guardar/confirmar criterio', [
                'carga_id'       => $cargaId,
                'competencia_id' => $competenciaId,
                'criterio_id'    => $criterioId,
                'periodo_id'     => $periodo['id'],
                'error'          => $e->getMessage(),
            ]);
            $this->json([
                'success' => false,
                'mensaje' => 'Error al guardar las notas. Intenta nuevamente.',
            ], 500);
        }

        // El criterio quedó sellado (marcarConfirmado dentro de la transacción).
        // Devolvemos la accesibilidad para que el cliente re-habilite "Ver
        // resumen" si ya no quedan criterios pendientes en la competencia.
        $resumenAccesible = $this->critModel->competenciaListaParaResumen(
            $cargaId, $competenciaId, (int) $periodo['id']
        );

        $this->json([
            'success'          => true,
            'mensaje'          => 'Notas guardadas correctamente.',
            'resumenAccesible' => $resumenAccesible,
        ]);
    }

    /**
     * POST /docente/calificaciones/{carga_id}/autosave
     * Guarda o borra la nota de UNA celda al salir del campo (blur).
     * Nota vacía = borrar la fila en calificaciones_criterio.
     */
    public function autosave(string $cargaId): void
    {
        $this->validateCsrf();
        $cargaId = (int) $cargaId;

        $periodo = $this->getPeriodoActivo();
        if (!$periodo || $this->calModel->periodoEstaBloqueado($periodo['id'])) {
            $this->json(['success' => false, 'mensaje' => 'El periodo está cerrado.'], 403);
        }

        $criterioId    = (int) $this->input('criterio_id');
        $competenciaId = (int) $this->input('competencia_id');
        $matriculaId   = (int) $this->input('matricula_id');
        $nota          = trim($this->input('nota', ''));

        if (!$criterioId || !$competenciaId || !$matriculaId) {
            $this->json(['success' => false, 'mensaje' => 'Datos incompletos.'], 400);
        }

        if ($this->calModel->competenciaBloqueada($cargaId, $competenciaId, $periodo['id'])) {
            $this->json(['success' => false, 'mensaje' => 'Competencia bloqueada.'], 403);
        }

        // Criterio extraordinario (RA): intocable para el docente (ver guardar()).
        if ($this->critModel->esExtraordinario($criterioId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Este criterio corresponde a una calificación extraordinaria de Registro Académico. Solo el módulo de Rectificación puede modificarlo.',
            ], 403);
        }

        if ($nota === '') {
            $this->calModel->eliminarNotaCriterio($criterioId, $matriculaId);
        } else {
            $notaInt = max(0, min(20, (int) $nota));
            $this->calModel->guardarNotaCriterio($criterioId, $matriculaId, $notaInt);
        }

        // Cualquier edición por autosave (set o blank) desconfirma el criterio:
        // su contenido cambió respecto al último estado confirmado, así que debe
        // volver a "pendiente". Esto re-bloquea "Ver resumen", lo saca del
        // promedio agregado (recalcular abajo ya filtra confirmado_en) y obliga a
        // volver a Confirmar (que re-dispara el filtro de omisión). El autosave
        // NUNCA sella; solo "Confirmar" (endpoint /guardar) marca confirmado.
        $this->critModel->desconfirmar($criterioId);

        try {
            $this->calModel->recalcularPromedioSeccion(
                $cargaId,
                $competenciaId,
                $periodo['id'],
                Session::user()['id']
            );
        } catch (\Exception $e) {
            log_error('Autosave: error recalculando promedio', [
                'carga_id'       => $cargaId,
                'competencia_id' => $competenciaId,
                'error'          => $e->getMessage(),
            ]);
        }

        // Accesibilidad actual de la competencia, para que el cliente sincronice
        // el botón "Ver resumen" sin recargar (misma regla que el guard de
        // resumen()): bloqueada, o todos los criterios confirmados.
        $resumenAccesible = $this->calModel->competenciaBloqueada($cargaId, $competenciaId, $periodo['id'])
            || $this->critModel->competenciaListaParaResumen($cargaId, $competenciaId, $periodo['id']);

        $this->json(['success' => true, 'resumenAccesible' => $resumenAccesible]);
    }

    /**
     * POST /docente/calificaciones/{carga_id}/omisiones
     * Registra el motivo por el que uno o más alumnos no fueron evaluados
     * en un criterio. Puede llamarse varias veces (upsert por pares).
     */
    public function guardarOmisiones(string $cargaId): void
    {
        $this->validateCsrf();
        $cargaId = (int) $cargaId;

        $periodo = $this->getPeriodoActivo();
        if (!$periodo || $this->calModel->periodoEstaBloqueado($periodo['id'])) {
            $this->json(['success' => false, 'mensaje' => 'El periodo está cerrado.'], 403);
        }

        $criterioId    = (int) $this->input('criterio_id');
        $competenciaId = (int) $this->input('competencia_id');
        $omisiones     = $this->input('omisiones', []);

        if (!$criterioId || !$competenciaId || empty($omisiones) || !is_array($omisiones)) {
            $this->json(['success' => false, 'mensaje' => 'Datos incompletos.'], 400);
        }

        if ($this->calModel->competenciaBloqueada($cargaId, $competenciaId, $periodo['id'])) {
            $this->json([
                'success' => false,
                'mensaje' => 'Esta competencia ya fue aprobada y bloqueada.',
            ], 403);
        }

        // Criterio extraordinario (RA): intocable para el docente (ver guardar()).
        if ($this->critModel->esExtraordinario($criterioId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Este criterio corresponde a una calificación extraordinaria de Registro Académico. Solo el módulo de Rectificación puede modificarlo.',
            ], 403);
        }

        $this->omisionModel->guardarLote($criterioId, $omisiones, Session::user()['id']);

        // Registrar/cambiar una omisión altera la composición del criterio, así
        // que lo desconfirma igual que editar una nota: vuelve a "pendiente" y
        // obliga a re-Confirmar antes de poder ver el resumen o aprobar.
        $this->critModel->desconfirmar($criterioId);

        $resumenAccesible = $this->critModel->competenciaListaParaResumen(
            $cargaId, $competenciaId, $periodo['id']
        );

        $this->json([
            'success'          => true,
            'mensaje'          => 'Omisiones registradas.',
            'resumenAccesible' => $resumenAccesible,
        ]);
    }

    /**
     * POST /docente/criterios/crear
     * Crea un nuevo criterio de evaluación.
     */
    public function crearCriterio(): void
    {
        $this->validateCsrf();

        $cargaId       = (int) $this->input('carga_id');
        $competenciaId = (int) $this->input('competencia_id');
        $nombre        = trim($this->input('nombre', ''));
        $descripcion   = trim($this->input('descripcion', ''));
        $periodo       = $this->getPeriodoActivo();

        if (empty($nombre) || !$periodo) {
            $this->json([
                'success' => false,
                'mensaje' => 'Datos incompletos.',
            ], 400);
        }

        if (mb_strlen($nombre) > self::CRITERIO_NOMBRE_MAX) {
            $this->json([
                'success' => false,
                'mensaje' => 'El nombre del criterio no puede superar los '
                    . self::CRITERIO_NOMBRE_MAX . ' caracteres (tiene '
                    . mb_strlen($nombre) . '). Usa el campo descripción para el detalle.',
            ], 422);
        }

        if ($this->calModel->periodoEstaBloqueado($periodo['id'])) {
            $this->json([
                'success' => false,
                'mensaje' => 'Periodo bloqueado.',
            ], 403);
        }

        // Competencia aprobada/bloqueada → inmutable: tampoco se le pueden AGREGAR
        // criterios (parejo con renombrar/eliminar). Defensa de servidor por si se
        // fuerza la petición; la UI no ofrece "Agregar criterio" en una bloqueada.
        if ($this->calModel->competenciaBloqueada($cargaId, $competenciaId, $periodo['id'])) {
            $this->json([
                'success' => false,
                'mensaje' => 'Esta competencia ya fue aprobada y bloqueada. No se pueden agregar criterios.',
            ], 403);
        }

        $id = $this->critModel->crear(
            $cargaId,
            $competenciaId,
            $periodo['id'],
            $nombre,
            $descripcion !== '' ? $descripcion : null
        );

        $this->json([
            'success'     => true,
            'id'          => $id,
            'nombre'      => $nombre,
            'descripcion' => $descripcion,
            'mensaje'     => 'Criterio creado.',
        ]);
    }

    /**
     * POST /docente/criterios/{id}/renombrar
     * Cambia el nombre de un criterio. Permitido aunque ya tenga calificaciones.
     */
    public function renombrarCriterio(string $id): void
    {
        $this->validateCsrf();
        $id          = (int) $id;
        $nombre      = trim($this->input('nombre', ''));
        $descripcion = trim($this->input('descripcion', ''));

        if (empty($nombre)) {
            $this->json(['success' => false, 'mensaje' => 'El nombre no puede estar vacío.'], 400);
        }

        if (mb_strlen($nombre) > self::CRITERIO_NOMBRE_MAX) {
            $this->json([
                'success' => false,
                'mensaje' => 'El nombre del criterio no puede superar los '
                    . self::CRITERIO_NOMBRE_MAX . ' caracteres (tiene '
                    . mb_strlen($nombre) . '). Usa el campo descripción para el detalle.',
            ], 422);
        }

        $criterio = $this->critModel->queryOne(
            "SELECT id, carga_id, competencia_id, periodo_id
             FROM criterios WHERE id = ? AND eliminado_en IS NULL",
            [$id]
        );

        if (!$criterio) {
            $this->json(['success' => false, 'mensaje' => 'Criterio no encontrado.'], 404);
        }

        $periodoId     = (int) $criterio['periodo_id'];
        $cargaId       = (int) $criterio['carga_id'];
        $competenciaId = (int) $criterio['competencia_id'];

        if ($this->calModel->periodoEstaBloqueado($periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'Periodo bloqueado.'], 403);
        }

        // Competencia aprobada/bloqueada → criterio INMUTABLE para el docente
        // (parejo con eliminarCriterio). Para corregir un typo hay que reabrir el
        // bimestre desde el panel del director.
        if ($this->calModel->competenciaBloqueada($cargaId, $competenciaId, $periodoId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Esta competencia ya fue aprobada y bloqueada. No se puede editar el criterio.',
            ], 403);
        }

        // Criterio extraordinario (RA): intocable para el docente (ver guardar()).
        if ($this->critModel->esExtraordinario($id)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Este criterio corresponde a una calificación extraordinaria de Registro Académico. Solo el módulo de Rectificación puede modificarlo.',
            ], 403);
        }

        $this->critModel->renombrar($id, $nombre, $descripcion !== '' ? $descripcion : null);

        // Cambiar nombre/descripción ES un cambio en el criterio → se desconfirma
        // igual que editar una nota u omisión: vuelve a "pendiente", sale del
        // promedio agregado y obliga a re-Confirmar antes de verlo en el resumen o
        // aprobar. Recalcular para que el promedio refleje de inmediato su salida.
        $userId = Session::user()['id'];
        $this->critModel->desconfirmar($id);
        try {
            $this->calModel->recalcularPromedioSeccion($cargaId, $competenciaId, $periodoId, $userId);
        } catch (\Exception $e) {
            log_error('Renombrar criterio: error recalculando promedio', [
                'criterio_id' => $id,
                'error'       => $e->getMessage(),
            ]);
        }

        $resumenAccesible = $this->critModel->competenciaListaParaResumen(
            $cargaId, $competenciaId, $periodoId
        );

        $this->json([
            'success'          => true,
            'nombre'           => $nombre,
            'descripcion'      => $descripcion,
            'mensaje'          => 'Criterio actualizado.',
            'resumenAccesible' => $resumenAccesible,
        ]);
    }

    /**
     * POST /docente/criterios/{id}/eliminar
     * Soft-delete de un criterio aunque ya tenga calificaciones.
     * El criterio y sus calificaciones_criterio se conservan en BD para auditoría.
     * Si tenía calificaciones, recalcula el promedio de la competencia.
     */
    public function eliminarCriterio(string $id): void
    {
        $this->validateCsrf();
        $id = (int) $id;

        $criterio = $this->critModel->queryOne(
            "SELECT id, carga_id, competencia_id, periodo_id
             FROM criterios
             WHERE id = ? AND eliminado_en IS NULL",
            [$id]
        );

        if (!$criterio) {
            $this->json(['success' => false, 'mensaje' => 'Criterio no encontrado.'], 404);
        }

        $periodoId     = (int) $criterio['periodo_id'];
        $cargaId       = (int) $criterio['carga_id'];
        $competenciaId = (int) $criterio['competencia_id'];

        if ($this->calModel->periodoEstaBloqueado($periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'El periodo está cerrado.'], 403);
        }

        if ($this->calModel->competenciaBloqueada($cargaId, $competenciaId, $periodoId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Esta competencia ya fue aprobada y bloqueada.',
            ], 403);
        }

        // Criterio extraordinario (RA): intocable para el docente (ver guardar()).
        if ($this->critModel->esExtraordinario($id)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Este criterio corresponde a una calificación extraordinaria de Registro Académico. Solo el módulo de Rectificación puede modificarlo.',
            ], 403);
        }

        $teniaCals = $this->critModel->tieneCalificaciones($id);
        $user      = Session::user();

        // Paso 1 (integridad): el borrado del criterio y la limpieza de sus
        // calificaciones huerfanas deben ser ATOMICOS. Antes el recalculo iba en
        // un try/catch que TRAGABA el error: si fallaba, el criterio quedaba
        // borrado pero sus calificaciones huerfanas sobrevivian (origen del bug
        // "competencia fantasma"). Con la transaccion, o se completa todo o se
        // revierte todo; nunca queda una nota colgada ni se toca una nota valida.
        $this->calModel->beginTransaction();
        try {
            if (!$this->critModel->eliminarConAuditoria($id, $user['id'])) {
                throw new \RuntimeException('eliminarConAuditoria no afecto ninguna fila');
            }

            if ($teniaCals) {
                $this->calModel->recalcularPromedioSeccion(
                    $cargaId,
                    $competenciaId,
                    $periodoId,
                    $user['id']
                );
            }

            $this->calModel->commit();
        } catch (\Throwable $e) {
            $this->calModel->rollback();
            log_error('Error al eliminar criterio: rollback, no se modifico nada', [
                'criterio_id'    => $id,
                'carga_id'       => $cargaId,
                'competencia_id' => $competenciaId,
                'error'          => $e->getMessage(),
            ]);
            $this->json([
                'success' => false,
                'mensaje' => 'No se pudo eliminar el criterio. No se modifico ningun dato.',
            ], 500);
        }

        // Borrar un criterio vacío (pendiente) puede dejar la competencia con
        // TODOS sus criterios confirmados: sin esto "Ver resumen" seguía
        // bloqueado hasta recargar (la rama sin notas no recarga la página).
        $resumenAccesible = $this->critModel->competenciaListaParaResumen(
            $cargaId, $competenciaId, $periodoId
        );

        $this->json([
            'success'           => true,
            'mensaje'           => 'Criterio eliminado.',
            'tenia_calificaciones' => $teniaCals,
            'resumenAccesible'  => $resumenAccesible,
        ]);
    }

    /**
     * POST /docente/calificaciones/conclusion
     * Guarda la conclusión descriptiva de una competencia.
     */
    public function guardarConclusion(): void
    {
        $this->validateCsrf();

        $matriculaId   = (int) $this->input('matricula_id');
        $cargaId       = (int) $this->input('carga_id');
        $competenciaId = (int) $this->input('competencia_id');
        $conclusion    = trim($this->input('conclusion', ''));
        $periodo       = $this->getPeriodoActivo();

        if (!$periodo) {
            $this->json([
                'success' => false,
                'mensaje' => 'Sin periodo activo.',
            ], 400);
        }

        $ok = $this->calModel->execute("
            UPDATE calificaciones
            SET conclusion_descriptiva = ?,
                modificado_en          = NOW()
            WHERE matricula_id   = ?
              AND carga_id       = ?
              AND competencia_id = ?
              AND periodo_id     = ?
        ", [$conclusion, $matriculaId, $cargaId, $competenciaId, $periodo['id']]);

        $this->json([
            'success' => $ok,
            'mensaje' => $ok ? 'Conclusión guardada.' : 'Error al guardar.',
        ]);
    }

    // ── Métodos privados ─────────────────────────────────────

    private function getPeriodoActivo(): ?array
    {
        return $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado = 'activo'
            LIMIT 1
        ");
    }

    private function getCargas(int $docenteId, int $periodoId = 0): array
    {
        return $this->calModel->query("
            SELECT
                ca.id,
                ca.horas_semanales,
                ca.seccion_id,
                s.nombre          AS seccion_nombre,
                s.es_unidocente,
                -- Mi aula = la seccion es unidocente Y este docente es su tutor
                -- (el unidocente que dicta todas las areas core). Un especialista
                -- (Ingles, Ed. Fisica) que dicta en una seccion unidocente NO es
                -- el aula: es_unidocente por si solo no alcanza para etiquetarlo.
                (s.es_unidocente = 1 AND s.tutor_id = ca.docente_id) AS es_aula,
                g.nombre_display  AS grado_nombre,
                n.nombre          AS nivel_nombre,
                n.codigo          AS nivel_codigo,
                n.escala_boleta,
                CASE
                    WHEN s.es_unidocente = 1 THEN a.nombre
                    ELSE COALESCE(sa.nombre, a.nombre)
                END               AS nombre_display,
                a.nombre          AS area_nombre,
                a.tipo            AS area_tipo,
                sa.id             AS subarea_id,
                sa.nombre         AS subarea_nombre,
                a.id              AS area_id,
                -- Competencia vinculada a la subarea (1 subarea = 1 competencia).
                -- El unidocente NO dicta subareas: sus cards muestran el nombre
                -- corto + codigo MINEDU de la competencia en vez de la subarea.
                (SELECT comp.nombre_corto  FROM competencias comp
                    WHERE comp.subarea_id = ca.subarea_id ORDER BY comp.id LIMIT 1
                ) AS competencia_corto,
                (SELECT comp.codigo_minedu FROM competencias comp
                    WHERE comp.subarea_id = ca.subarea_id ORDER BY comp.id LIMIT 1
                ) AS competencia_codigo,
                (
                    -- Competencias PROPIAS del area/subarea de la carga. La barra de
                    -- avance compara este total contra competencias_bloqueadas, que
                    -- recorre EXACTAMENTE este mismo universo (propias). Las TIC/GAMA
                    -- transversales NO se suman aqui: tienen su propio distintivo
                    -- (total_transversales / transversales_bloqueadas). Sumarlas al
                    -- total mientras el numerador solo cuenta propias dejaba el avance
                    -- atascado (p. ej. 5/7 = 71%) al bloquear todo (Variante 1).
                    SELECT COUNT(DISTINCT comp2.id)
                    FROM competencias comp2
                    WHERE (
                        (ca.subarea_id IS NOT NULL AND comp2.subarea_id = ca.subarea_id)
                        OR
                        (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL
                            AND comp2.area_id = ca.area_id)
                    )
                ) AS total_competencias,
                -- Avance defensivo: numerador y denominador recorren el MISMO
                -- universo (las competencias PROPIAS de la carga, vía su predicado
                -- subarea/area). Para una carga normal excluye los bloqueos
                -- transversales TIC/GAMA (Variante 1, mismo carga_id); para una
                -- carga transversal (B1 reactivada) sus propias TIC/GAMA SÍ cuentan.
                (
                    SELECT COUNT(*)
                    FROM bloqueos_competencia bc2
                    WHERE bc2.carga_id   = ca.id
                      AND bc2.periodo_id = ?
                      AND bc2.competencia_id IN (
                          SELECT comp3.id
                          FROM competencias comp3
                          WHERE (ca.subarea_id IS NOT NULL AND comp3.subarea_id = ca.subarea_id)
                             OR (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL
                                 AND comp3.area_id = ca.area_id)
                      )
                ) AS competencias_bloqueadas,
                (
                    SELECT COUNT(DISTINCT cr2.competencia_id)
                    FROM criterios cr2
                    WHERE cr2.carga_id     = ca.id
                      AND cr2.periodo_id   = ?
                      AND cr2.eliminado_en IS NULL
                      AND cr2.competencia_id IN (
                          SELECT comp4.id
                          FROM competencias comp4
                          WHERE (ca.subarea_id IS NOT NULL AND comp4.subarea_id = ca.subarea_id)
                             OR (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL
                                 AND comp4.area_id = ca.area_id)
                      )
                ) AS competencias_con_criterios,
                -- Distintivo de transversales: las TIC/GAMA de la carga viven en
                -- el area transversal del nivel (n.id), pero bajo el mismo
                -- carga_id (Variante 1). Se bloquean UNA POR UNA, igual que las
                -- propias: el empaquetado con la ultima competencia propia se
                -- retiro en el II Bimestre (ver bloquear); este comentario
                -- afirmaba lo contrario hasta el 07/08/2026. 3 estados en la
                -- vista: bloqueadas==total -> completas; con_criterios>0 -> en progreso.
                --
                -- UNIDOCENTE: las TIC/GAMA se registran UNA vez por area (en la
                -- carga dueña = subarea de menor orden); las demas subareas del
                -- area muestran 0 transversales para no contarlas N veces. Para
                -- especialistas (no unidocente) cada carga cuenta las suyas.
                --
                -- TUTORIA (Etica y Valores): la carga del tutor NO lleva
                -- transversales (decision 07/07/2026) -> los 3 contadores en 0.
                CASE WHEN a.tipo = 'tutoria' THEN 0
                     WHEN s.es_unidocente = 1
                          AND ca.id <> (
                              SELECT cad.id FROM cargas_academicas cad
                              LEFT JOIN subareas sad ON sad.id = cad.subarea_id
                              WHERE cad.seccion_id = ca.seccion_id
                                AND cad.estado     = 'activa'
                                AND COALESCE(cad.area_id, sad.area_id) = COALESCE(ca.area_id, sa.area_id)
                              ORDER BY COALESCE(sad.orden, 0), cad.id LIMIT 1
                          )
                     THEN 0
                     ELSE (
                        SELECT COUNT(*)
                        FROM competencias compt
                        INNER JOIN areas at2 ON at2.id = compt.area_id
                        WHERE at2.tipo     = 'transversal'
                          AND at2.nivel_id = n.id
                     )
                END AS total_transversales,
                CASE WHEN a.tipo = 'tutoria' THEN 0
                     WHEN s.es_unidocente = 1
                          AND ca.id <> (
                              SELECT cad.id FROM cargas_academicas cad
                              LEFT JOIN subareas sad ON sad.id = cad.subarea_id
                              WHERE cad.seccion_id = ca.seccion_id
                                AND cad.estado     = 'activa'
                                AND COALESCE(cad.area_id, sad.area_id) = COALESCE(ca.area_id, sa.area_id)
                              ORDER BY COALESCE(sad.orden, 0), cad.id LIMIT 1
                          )
                     THEN 0
                     ELSE (
                        SELECT COUNT(*)
                        FROM bloqueos_competencia bct
                        INNER JOIN competencias compt ON compt.id = bct.competencia_id
                        INNER JOIN areas at2 ON at2.id = compt.area_id AND at2.tipo = 'transversal'
                        WHERE bct.carga_id   = ca.id
                          AND bct.periodo_id = ?
                          AND at2.nivel_id   = n.id
                     )
                END AS transversales_bloqueadas,
                CASE WHEN a.tipo = 'tutoria' THEN 0
                     WHEN s.es_unidocente = 1
                          AND ca.id <> (
                              SELECT cad.id FROM cargas_academicas cad
                              LEFT JOIN subareas sad ON sad.id = cad.subarea_id
                              WHERE cad.seccion_id = ca.seccion_id
                                AND cad.estado     = 'activa'
                                AND COALESCE(cad.area_id, sad.area_id) = COALESCE(ca.area_id, sa.area_id)
                              ORDER BY COALESCE(sad.orden, 0), cad.id LIMIT 1
                          )
                     THEN 0
                     ELSE (
                        SELECT COUNT(DISTINCT crt.competencia_id)
                        FROM criterios crt
                        INNER JOIN competencias compt ON compt.id = crt.competencia_id
                        INNER JOIN areas at2 ON at2.id = compt.area_id AND at2.tipo = 'transversal'
                        WHERE crt.carga_id     = ca.id
                          AND crt.periodo_id   = ?
                          AND crt.eliminado_en IS NULL
                          AND at2.nivel_id     = n.id
                     )
                END AS transversales_con_criterios
            FROM cargas_academicas ca
            INNER JOIN secciones s  ON s.id  = ca.seccion_id
            INNER JOIN grados g     ON g.id  = s.grado_id
            INNER JOIN niveles n    ON n.id  = g.nivel_id
            LEFT  JOIN subareas sa  ON sa.id = ca.subarea_id
            LEFT  JOIN areas a      ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE ca.docente_id = ?
              AND ca.estado     = 'activa'
              -- Las TIC/GAMA se registran DENTRO de cada carga normal (sección
              -- transversal del formulario). Una carga transversal independiente
              -- (modelo viejo) NO debe listarse como tarjeta: era el ingreso de
              -- promedios del tutor, hoy reemplazado por /docente/tutoria.
              AND (a.tipo IS NULL OR a.tipo != 'transversal')
              -- Tutoría (TOE): se oculta del registro de notas MIENTRAS su area
              -- no tenga competencias (hoy = sin calificaciones). El dia que se
              -- agreguen competencias, la card aparece sola (future-proof por
              -- datos, no por tipo). Forma NULL-safe: solo excluye tipo='tutoria'
              -- sin competencias; area NULL u otros tipos pasan.
              AND (a.tipo IS NULL OR a.tipo != 'tutoria'
                   OR EXISTS (SELECT 1 FROM competencias ctu WHERE ctu.area_id = a.id))
            ORDER BY n.id, g.numero, s.nombre, a.orden, sa.orden
        ", [$periodoId, $periodoId, $periodoId, $periodoId, $docenteId]);
    }

    private function validarCargaDocente(int $cargaId): ?array
    {
        $user = Session::user();
        return $this->calModel->queryOne("
            SELECT
                ca.*,
                s.nombre          AS seccion_nombre,
                s.es_unidocente,
                g.nombre_display  AS grado_nombre,
                n.id              AS nivel_id,
                n.nombre          AS nivel_nombre,
                n.codigo          AS nivel_codigo,
                n.escala_boleta,
                COALESCE(sa.nombre, a.nombre) AS nombre_display,
                a.nombre          AS area_nombre,
                a.tipo            AS area_tipo,
                COALESCE(ca.area_id, sa.area_id) AS area_resuelta_id
            FROM cargas_academicas ca
            INNER JOIN secciones s  ON s.id  = ca.seccion_id
            INNER JOIN grados g     ON g.id  = s.grado_id
            INNER JOIN niveles n    ON n.id  = g.nivel_id
            LEFT  JOIN subareas sa  ON sa.id = ca.subarea_id
            LEFT  JOIN areas a      ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE ca.id         = ?
              AND ca.docente_id = ?
              AND ca.estado     = 'activa'
        ", [$cargaId, $user['id']]);
    }

    private function getAlumnosSeccion(int $seccionId): array
    {
        return $this->calModel->query("
            SELECT
                m.id AS matricula_id,
                p.dni,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                CONCAT(
                    p.apellido_paterno, ' ',
                    p.apellido_materno, ', ',
                    p.nombres
                ) AS nombre_completo
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            WHERE m.seccion_id = ?
            -- Regla del proyecto: el docente tiene a disposición a TODOS los
            -- estudiantes matriculados de la sección (aprobada, pendiente e
            -- incluso desactivado por baja administrativa, p. ej. deuda: el
            -- alumno sigue asistiendo mientras regulariza). El ÚNICO excluido es
            -- el TRASLADO DE SALIDA (tipo='trasladado'): ese sí abandonó el
            -- colegio y no debe calificarse. Un traslado siempre es desactivado,
            -- así que basta filtrar por tipo. Se suma 'retirado': ya no asiste
            -- (sin traslado oficial) y tampoco debe calificarse. Ver migración 045.
            -- Este es EL roster canónico: el resto del sistema (conducta,
            -- asistencia y sus contadores de avance) declara explícitamente que
            -- copia el de aquí. Desde hoy no se copia: sale del punto único
            -- `roster_evaluacion()` en helpers.php, que conserva el porqué de
            -- cada condición.
            " . roster_evaluacion('m') . "
            ORDER BY " . orden_alfabetico('p') . "
        ", [$seccionId]);
    }

    private function getNotasExistentes(int $cargaId, int $periodoId): array
    {
        $resultado = $this->calModel->query("
            SELECT
                cc.matricula_id,
                cc.nota,
                cr.id AS criterio_id,
                cr.competencia_id
            FROM calificaciones_criterio cc
            INNER JOIN criterios cr ON cr.id = cc.criterio_id
            WHERE cr.carga_id     = ?
              AND cr.periodo_id   = ?
              AND cr.eliminado_en IS NULL
        ", [$cargaId, $periodoId]);

        // Indexar por criterio_id y matricula_id para acceso rápido
        $notas = [];
        foreach ($resultado as $row) {
            $notas[$row['criterio_id']][$row['matricula_id']] = $row['nota'];
        }
        return $notas;
    }
        /**
 * GET /docente/calificaciones/{carga_id}/resumen/{competencia_id}
 * Vista de resumen con promedios y conclusiones por alumno.
 */
    public function resumen(string $cargaId, string $competenciaId): void
    {
        $cargaId       = (int) $cargaId;
        $competenciaId = (int) $competenciaId;
        $periodo       = $this->getPeriodoActivo();

        if (!$periodo) {
            $this->redirectWithError(
                url('docente/mis-cargas'),
                'No hay un periodo activo.'
            );
        }

        $carga = $this->validarCargaDocente($cargaId);
        if (!$carga) {
            $this->redirectWithError(
                url('docente/mis-cargas'),
                'Carga no encontrada.'
            );
        }

        // Obtener competencia (con flag de transversal: su conclusión y
        // bloqueo no se gestionan desde este resumen sino vía tutor/Variante 1)
        $competencia = $this->calModel->queryOne("
            SELECT c.*,
                   (a.tipo = 'transversal') AS es_transversal
            FROM competencias c
            LEFT JOIN areas a ON a.id = c.area_id
            WHERE c.id = ?
        ", [$competenciaId]);

        // Verificar si está bloqueada
        $bloqueada = $this->calModel->competenciaBloqueada(
            $cargaId, $competenciaId, $periodo['id']
        );

        // Guard de accesibilidad (defensa en profundidad): solo se entra al
        // resumen si está bloqueada (lectura) o la competencia está LISTA, es
        // decir tiene ≥1 criterio y TODOS confirmados. Cierra el bypass del
        // filtro de omisión cuando se edita/omite tras confirmar (el criterio se
        // desconfirma) o si se fuerza la URL con criterios pendientes/vacíos.
        $accesible = $bloqueada
            || $this->critModel->competenciaListaParaResumen($cargaId, $competenciaId, $periodo['id']);
        if (!$accesible) {
            $this->redirectWithError(
                url('docente/calificaciones/' . $cargaId),
                'Tienes criterios sin confirmar o vacíos en esta competencia. '
                . 'Vuelve a la grilla, confírmalos (o elimina los vacíos) antes de ver el resumen.'
            );
        }

        // Obtener resumen completo. soloConfirmados=true: la vista nunca muestra
        // un criterio pendiente ni notas autoguardadas sin confirmar (cuando no
        // está bloqueada). Si está bloqueada, todos sus criterios ya están
        // confirmados, así que el filtro es inocuo (defensa en profundidad).
        $resumen = $this->calModel->getResumenCompetencia(
            $cargaId, $competenciaId, $periodo['id'], true
        );

        // Añadir omisiones por criterio a cada alumno
        $omisionesPorCriterio = [];
        foreach ($resumen['criterios'] as $criterio) {
            $omisionesPorCriterio[(int) $criterio['id']] =
                $this->omisionModel->getPorCriterio((int) $criterio['id']);
        }
        foreach ($resumen['alumnos'] as &$alumno) {
            $alumno['omisiones_criterios'] = [];
            $matId = (int) $alumno['matricula_id'];
            foreach ($omisionesPorCriterio as $critId => $porMatricula) {
                if (isset($porMatricula[$matId])) {
                    $alumno['omisiones_criterios'][$critId] = $porMatricula[$matId];
                }
            }
        }
        unset($alumno);

        $exonerados = $this->exoModel->getActivasParaCarga($cargaId, (int) $periodo['anio_id']);

        // Destino del "Volver": si el docente es AULA y el área tiene >1 subárea-
        // carga suya, vino de la vista consolidada de área (misma condición
        // $esGrupo && $esAula de mis-cargas). Si no (especialista, o área de una
        // sola carga), vuelve a la carga individual.
        $user       = Session::user();
        $cargasArea = $this->getCargasAreaDocente(
            (int) $carga['seccion_id'], (int) $carga['area_resuelta_id'], (int) $user['id']
        );
        $volverUrl = (count($cargasArea) > 1 && !empty($cargasArea[0]['es_aula']))
            ? url('docente/calificaciones/area/' . (int) $carga['seccion_id'] . '/' . (int) $carga['area_resuelta_id'])
            : url('docente/calificaciones/' . $cargaId);

        // Calificaciones extraordinarias de RA en esta competencia: el docente
        // debe verlas diferenciadas (con motivo) de su registro ordinario.
        $extraordinarias = [];
        foreach ($resumen['criterios'] as $cr) {
            if (!empty($cr['extraordinario'])) {
                $extraordinarias = (new RectificacionModel())
                    ->getExtraordinariasDeCompetencia($cargaId, $competenciaId, (int) $periodo['id'], Session::hasRole('docente'));
                break;
            }
        }

        $this->view('docente/resumen-competencia', [
            'titulo'          => 'Resumen — ' . ($competencia['nombre_corto'] ?? ''),
            'carga'           => $carga,
            'periodo'         => $periodo,
            'competencia'     => $competencia,
            'criterios'       => $resumen['criterios'],
            'alumnos'         => $resumen['alumnos'],
            'bloqueada'       => $bloqueada,
            'exonerados'      => $exonerados,
            'extraordinarias' => $extraordinarias,
            'volverUrl'       => $volverUrl,
            'page_scripts'    => ['resumen'],
        ]);
    }

    /**
     * POST /docente/calificaciones/{carga_id}/conclusion/{competencia_id}
     * Guarda la conclusión de UN alumno específico.
     */
    public function guardarConclusionAlumno(
        string $cargaId,
        string $competenciaId
    ): void {
        $this->validateCsrf();

        $cargaId       = (int) $cargaId;
        $competenciaId = (int) $competenciaId;
        $matriculaId   = (int) $this->input('matricula_id');
        $conclusion    = trim($this->input('conclusion', ''));
        $periodo       = $this->getPeriodoActivo();

        if (!$periodo) {
            $this->json(['success' => false, 'mensaje' => 'Sin periodo activo.'], 400);
        }

        if ($this->calModel->competenciaBloqueada($cargaId, $competenciaId, $periodo['id'])) {
            $this->json(['success' => false, 'mensaje' => 'Competencia bloqueada.'], 403);
        }

        try {
            $guardado = $this->calModel->actualizarConclusion(
                $matriculaId, $cargaId, $competenciaId, $periodo['id'], $conclusion
            );

            if (!$guardado) {
                $this->json([
                    'success' => false,
                    'mensaje' => 'No se encontró la calificación. Guarda las notas del alumno primero.',
                ], 400);
                return;
            }

            $this->json(['success' => true, 'mensaje' => 'Conclusión guardada.']);

        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'mensaje' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /docente/calificaciones/{carga_id}/bloquear/{competencia_id}
     * Aprueba y bloquea UNA competencia de forma independiente.
     *
     * Desde el II Bimestre cada competencia —incluidas las transversales
     * TIC/GAMA registradas en la propia carga— se aprueba y bloquea por
     * separado, con el mismo mecanismo y las mismas validaciones. Ya no
     * existe el empaquetado de la "última competencia propia".
     */
    public function bloquear(string $cargaId, string $competenciaId): void
    {
        $this->validateCsrf();

        $cargaId       = (int) $cargaId;
        $competenciaId = (int) $competenciaId;
        $periodo       = $this->getPeriodoActivo();
        $user          = Session::user();

        if (!$periodo) {
            $this->json(['success' => false, 'mensaje' => 'Sin periodo activo.'], 400);
        }

        $confirmaSinNotas = !empty($this->input('sin_calificaciones'));

        // Validar la competencia que se quiere bloquear (propia o transversal).
        $error = $this->errorBloqueoCompetencia(
            $cargaId, $competenciaId, $periodo, $confirmaSinNotas
        );
        if ($error !== null) {
            $this->json(['success' => false, 'mensaje' => $error], 400);
        }

        $ok = $this->calModel->bloquearCompetencia(
            $cargaId, $competenciaId, $periodo['id'], $user['id']
        );

        $this->json([
            'success' => $ok,
            'mensaje' => $ok ? 'Competencia aprobada y bloqueada correctamente.'
                             : 'Error al bloquear.',
        ]);
    }

    /**
     * Valida si una competencia puede bloquearse. Retorna NULL si está lista
     * o el mensaje de error. Alumnos sin promedio son válidos solo si tienen
     * omisión registrada o están exonerados de la carga.
     */
    private function errorBloqueoCompetencia(
        int $cargaId,
        int $competenciaId,
        array $periodo,
        bool $confirmaSinNotas
    ): ?string {
        $resumen = $this->calModel->getResumenCompetencia(
            $cargaId, $competenciaId, (int) $periodo['id']
        );

        $sinCriterios = empty($resumen['criterios']);
        if ($sinCriterios && $confirmaSinNotas) {
            // Paso 2 (integridad): "No se evaluó" crea un bloqueo SIN criterios.
            // Si la competencia todavía tiene calificaciones (huérfanas, porque no
            // hay criterio vivo), bloquear aquí produciría el estado fantasma
            // (bloqueo + notas + 0 criterios) que aparecía en la boleta. Se rechaza
            // para no crear la inconsistencia. Con el Paso 1 esto no debería
            // ocurrir; es defensa en profundidad (no borra ninguna nota).
            if ($this->calModel->tieneCalificacionesEnCompetencia(
                $cargaId, $competenciaId, (int) $periodo['id']
            )) {
                return 'Esta competencia tiene calificaciones sin criterios vivos '
                     . '(estado inconsistente). Recarga la página; si el problema '
                     . 'persiste, avisa a administración para depurarla.';
            }
            // Piso de carga: el docente no puede dejar su carga sin ninguna
            // calificación. Si marcar esta como "no se evaluó" vaciaría la carga,
            // se rechaza (el director sí puede forzarlo desde el panel de bloqueos).
            if (!$this->calModel->permiteNoEvaluarEnCarga($cargaId, (int) $periodo['id'])) {
                return 'No puedes dejar esta carga sin ninguna calificación. '
                     . 'Registra notas en al menos una competencia. Si el área no se '
                     . 'evalúa (casillero cedido), el director debe finalizarla desde '
                     . 'el panel de bloqueos.';
            }
            return null;
        }
        if ($sinCriterios) {
            return 'sin criterios ni notas registradas.';
        }

        // Puerta de confirmación: TODOS los criterios deben estar confirmados.
        // Un criterio editado/omitido tras confirmar (o uno vacío que nunca pudo
        // confirmarse) deja la competencia "no lista": su nota no entraría en el
        // promedio bloqueado → pérdida silenciosa. Se exige re-confirmar antes de
        // aprobar. El camino "No se evaluó" (sin criterios) ya se resolvió arriba.
        if (!$this->critModel->competenciaListaParaResumen($cargaId, $competenciaId, (int) $periodo['id'])) {
            return 'Tienes criterios sin confirmar o vacíos. En la grilla, '
                 . 'confírmalos (o elimina los vacíos) antes de aprobar.';
        }

        $matriculasConOmision = $this->omisionModel->getMatriculasConOmisionEnCompetencia(
            $cargaId, $competenciaId, (int) $periodo['id']
        );
        $exonerados = $this->exoModel->getActivasParaCarga($cargaId, (int) $periodo['anio_id']);

        $sinNota = array_filter(
            $resumen['alumnos'],
            fn($a) => $a['promedio'] === null
                && !in_array((int) $a['matricula_id'], $matriculasConOmision, true)
                && !in_array((int) $a['matricula_id'], $exonerados, true)
        );

        if (!empty($sinNota)) {
            return 'Hay ' . count($sinNota) . ' alumno(s) sin nota ni motivo de omisión registrado.';
        }

        return null;
    }
}