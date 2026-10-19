<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\BoletaModel;
use App\Models\BoletaPublicaModel;
use App\Models\CalificacionModel;
use Core\Session;
use Core\View;

class BoletaPublicaController extends BaseController
{
    private BoletaPublicaModel $model;
    private CalificacionModel  $calModel;
    private BoletaModel        $boletaModel;

    public function __construct()
    {
        $this->requireRole(['admin', 'registro_academico']);
        $this->model       = new BoletaPublicaModel();
        $this->calModel    = new CalificacionModel();
        $this->boletaModel = new BoletaModel();
    }

    /**
     * URL del QR permanente por token (matrícula identidad). Mismo criterio que
     * Boleta\BoletaController: un solo enlace por estudiante, estable todo el año.
     */
    private function urlBoletaToken(int $matriculaId): string
    {
        $identidad = (int) $this->calModel->boletaContexto($matriculaId)['identidad'];
        return url('boleta/digital/' . $this->model->getOCrearToken($identidad));
    }

    /** GET /admin/boletas-publicas — selector de periodos */
    public function index(): void
    {
        // Conteo de boletas OFICIALES disponibles (matrículas con ≥1 competencia
        // bloqueada), no de códigos generados (el código quedó dormido).
        $periodos = $this->model->query("
            SELECT p.id, p.numero, p.nombre_display, p.estado, a.anio,
                   COUNT(DISTINCT CASE WHEN bc.id IS NOT NULL THEN cal.matricula_id END) AS total_boletas
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            LEFT JOIN calificaciones cal ON cal.periodo_id = p.id
            LEFT JOIN bloqueos_competencia bc
                   ON bc.carga_id       = cal.carga_id
                  AND bc.competencia_id = cal.competencia_id
                  AND bc.periodo_id     = cal.periodo_id
            WHERE a.estado = 'activo'
            GROUP BY p.id
            ORDER BY p.numero
        ");

        $this->view('admin/boletas-publicas/index', [
            'titulo'   => 'Boletas Públicas',
            'periodos' => $periodos,
        ]);
    }

    /** GET /admin/boletas-publicas/{periodo_id} — tabla de boletas generadas */
    public function porPeriodo($periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(url('admin/boletas-publicas'), 'Período no encontrado.');
        }

        // Un bimestre 'pendiente' aun no empezo: no hay nada que gestionar y ni
        // siquiera la vista previa tendria sentido. La tarjeta del indice ya no
        // navega; esto cubre la URL escrita a mano o guardada.
        if (($periodo['estado'] ?? '') === 'pendiente') {
            $this->redirectWithError(
                url('admin/boletas-publicas'),
                'El ' . $periodo['nombre_display'] . ' todavía no ha iniciado: no tiene boletas que gestionar.'
            );
        }

        $estudiantes = $this->model->getEstudiantesParaPeriodo($periodoId);
        $secciones   = $this->model->getSeccionesParaPeriodo($periodoId);

        $totalBoletas     = count($estudiantes);
        $totalConsultadas = count(array_filter($estudiantes, fn($e) => (int) $e['token_consultas'] > 0));

        $this->view('admin/boletas-publicas/periodo', [
            'titulo'           => 'Boletas — ' . $periodo['nombre_display'],
            'periodo'          => $periodo,
            'estudiantes'      => $estudiantes,
            'secciones'        => $secciones,
            'totalBoletas'     => $totalBoletas,
            'totalConsultadas' => $totalConsultadas,
            // Emitir el documento oficial (imprimir / archivar) exige el bimestre
            // CERRADO; la vista previa no, que para eso existe.
            'periodoOficial'   => $this->periodoEsOficial($periodo),
        ]);
    }

    /** POST /admin/boletas-publicas/generar-tokens — genera tokens permanentes para matrículas sin token */
    public function generarTokens(): void
    {
        $this->validateCsrf();
        $count = $this->model->generarTokensActivos();
        $msg   = $count > 0
            ? "Se generaron {$count} token" . ($count !== 1 ? 's' : '') . " de acceso."
            : 'Todas las matrículas ya tienen token de acceso.';

        $count > 0
            ? $this->redirectWithSuccess(url('admin/boletas-publicas'), $msg)
            : $this->redirectWithError(url('admin/boletas-publicas'), $msg);
    }

    /** POST /admin/boletas-publicas/{periodo_id}/actualizar — resetea fechas de boletas con novedades */
    public function actualizar($periodoId): void
    {
        $this->validateCsrf();
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(url('admin/boletas-publicas'), 'Período no encontrado.');
        }

        $usuarioId  = Session::user()['id'] ?? 0;
        $actualizadas = $this->model->actualizarTimestamps($periodoId, $usuarioId);

        $msg = $actualizadas > 0
            ? "{$actualizadas} boleta(s) actualizadas con las nuevas competencias bloqueadas."
            : 'No hay boletas con nuevas competencias desde la última generación.';

        $actualizadas > 0
            ? $this->redirectWithSuccess(url("admin/boletas-publicas/{$periodoId}"), $msg)
            : $this->redirectWithError(url("admin/boletas-publicas/{$periodoId}"), $msg);
    }

    /** POST /admin/boletas-publicas/{periodo_id}/generar */
    public function generar($periodoId): void
    {
        $this->validateCsrf();
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(url('admin/boletas-publicas'), 'Período no encontrado.');
        }

        $usuarioId = Session::user()['id'] ?? 0;
        $nuevas    = $this->model->generarMasivo($periodoId, $usuarioId);

        $msg = $nuevas > 0
            ? "Se generaron {$nuevas} boleta(s) nueva(s) con código de acceso."
            : 'No hay boletas nuevas que generar (ya están todas generadas).';

        $nuevas > 0
            ? $this->redirectWithSuccess(url("admin/boletas-publicas/{$periodoId}"), $msg)
            : $this->redirectWithError(url("admin/boletas-publicas/{$periodoId}"), $msg);
    }

    /**
     * GET /admin/boletas-publicas/{periodo_id}/imprimir[?seccion_id=N]
     * Impresión de códigos de acceso. Opcionalmente loteable por sección.
     */
    public function imprimir($periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(url('admin/boletas-publicas'), 'Período no encontrado.');
        }

        $seccionId = (int) $this->query('seccion_id', 0) ?: null;
        $boletas   = $this->model->getPorPeriodo($periodoId, $seccionId);

        View::setLayout('print');
        $this->view('admin/boletas-publicas/imprimir', [
            'titulo'  => 'Códigos de Acceso — ' . $periodo['nombre_display'],
            'periodo' => $periodo,
            'boletas' => $boletas,
        ]);
    }

    /**
     * GET /admin/boletas-publicas/{periodo_id}/vista-previa[?seccion_id=N]
     * Vista previa antes de la aprobación de registro académico.
     * Itera sobre las matrículas con ≥1 competencia bloqueada (set candidato
     * a generación), no sobre las que ya tienen código. Pasa $vistaPrevia=true
     * a la vista compartida boleta/alumno.php para suprimir QR y la imagen
     * de firma del director (los datos de la línea de firma se mantienen).
     * Opcionalmente loteable por sección para evitar timeouts con muchos alumnos.
     */
    public function vistaPrevia($periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(url('admin/boletas-publicas'), 'Período no encontrado.');
        }

        $seccionId   = (int) $this->query('seccion_id', 0) ?: null;
        $matriculas  = $this->model->getMatriculasAprobadasParaBoleta($periodoId, $seccionId);
        $boletasData = [];

        foreach ($matriculas as $m) {
            // EXCEPCION a la compuerta del Hito A: la vista previa de RA muestra
            // TODOS los periodos (incluido el activo en 'registro') porque es su
            // herramienta para decidir el Hito A. Staff, sin QR, marcada BORRADOR.
            // 'todos' hoy no colapsa columnas, pero se pide la estructura anual
            // de forma explicita: es una regla del DOCUMENTO, no del umbral.
            $data = $this->boletaModel->armar((int) $m['matricula_id'], $periodoId, 'todos', true);
            if ($data) {
                // En vista previa no inyectamos url_boleta (sin QR) ni mostramos
                // firma del director; el flag lo decide en alumno.php.
                $data['vistaPrevia'] = true;
                $boletasData[] = $data;
            }
        }

        View::setLayout('print');
        $this->view('admin/boletas-publicas/vista-previa', [
            'titulo'      => 'Vista previa — ' . $periodo['nombre_display'],
            'periodo'     => $periodo,
            'boletasData' => $boletasData,
            // Lote: sin «Página X de Y» (contaría toda la impresión). Ver _boleta.scss.
            'bodyClass'   => 'doc-lote',
        ]);
    }

    /**
     * GET /admin/boletas-publicas/{periodo_id}/boletas-alumno[?seccion_id=N]
     * Impresión masiva de boletas. Opcionalmente loteable por sección para
     * evitar que el render de 200+ boletas dispare timeouts.
     */
    public function boletasAlumno($periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(url('admin/boletas-publicas'), 'Período no encontrado.');
        }

        // Guard: con el bimestre abierto saldria papel oficial con su columna
        // VACIA (el umbral 'archivo' solo deja pasar bimestres cerrados). La vista
        // ya deshabilita el boton; esto cubre la URL guardada o reabierta.
        if (!$this->periodoEsOficial($periodo)) {
            $this->redirectWithError(
                url('admin/boletas-publicas/' . $periodoId),
                'Las boletas oficiales se imprimen cuando el bimestre está cerrado. Usa la vista previa mientras tanto.'
            );
        }

        $seccionId   = (int) $this->query('seccion_id', 0) ?: null;
        $matriculas  = $this->model->getMatriculasAprobadasParaBoleta($periodoId, $seccionId);
        $boletasData = [];

        foreach ($matriculas as $m) {
            $matriculaId = (int) $m['matricula_id'];
            // Documento de ARCHIVO generado por staff: solo lo oficial (cerrado),
            // pero IGNORA la compuerta de publicacion (044) porque RA imprime las
            // boletas ANTES de la reunion de entrega; con 'oficial' saldrian en
            // blanco. La compuerta protege el acceso EN LINEA, no la impresion.
            // El QR que lleva impreso si respeta la compuerta: al escanearlo el
            // dia de la entrega, la publicacion ya esta vigente.
            // REGLA DE FORMATO (09/07/2026): estructura anual completa, las 4
            // columnas de bimestre aunque esten vacias. Es EL MISMO documento que
            // /boleta/ver/{token} (mismo componente boleta/alumno.php); sin esto
            // salia con las columnas colapsadas y el impreso que se firma y
            // entrega tenia otro formato que el que la familia abre por QR.
            $data = $this->boletaModel->armar($matriculaId, $periodoId, 'archivo', true);
            if ($data) {
                $data['url_boleta'] = $this->urlBoletaToken($matriculaId);
                $boletasData[] = $data;
            }
        }

        View::setLayout('print');
        $this->view('admin/boletas-publicas/boletas-alumno', [
            'titulo'      => 'Boletas — ' . $periodo['nombre_display'],
            'periodo'     => $periodo,
            'boletasData' => $boletasData,
            // Lote: sin «Página X de Y» (contaría toda la impresión). Ver _boleta.scss.
            'bodyClass'   => 'doc-lote',
        ]);
    }

    /**
     * GET /admin/boletas-publicas/{periodo_id}/archivar[?seccion_id=N]
     * Genera PDFs individuales en el navegador (html2pdf.js + JSZip)
     * agrupados en carpetas por sección dentro de un ZIP descargable.
     */
    public function archivar($periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(url('admin/boletas-publicas'), 'Período no encontrado.');
        }

        // Mismo guard que boletasAlumno: es el mismo documento, en PDF.
        if (!$this->periodoEsOficial($periodo)) {
            $this->redirectWithError(
                url('admin/boletas-publicas/' . $periodoId),
                'El archivo de boletas se genera cuando el bimestre está cerrado. Usa la vista previa mientras tanto.'
            );
        }

        $seccionId   = (int) $this->query('seccion_id', 0) ?: null;
        $matriculas  = $this->model->getMatriculasAprobadasParaBoleta($periodoId, $seccionId);
        $boletasData = [];

        foreach ($matriculas as $m) {
            $matriculaId = (int) $m['matricula_id'];
            // Documento de ARCHIVO (PDF/ZIP para el colegio): igual que
            // boletasAlumno — solo cerrados, ignorando la compuerta de
            // publicacion (044) y con estructura anual completa. Ver el
            // comentario extendido alli.
            $data = $this->boletaModel->armar($matriculaId, $periodoId, 'archivo', true);
            if (!$data) continue;

            $a = $data['alumno'];

            // Nombre de archivo: APELLIDOS_NOMBRES (sin DNI, mayúsculas con _)
            $partes = [
                mb_strtoupper($a['apellido_paterno']),
                mb_strtoupper($a['apellido_materno']),
                mb_strtoupper($a['nombres']),
            ];
            $data['nombre_archivo'] = str_replace(' ', '_', implode('_', $partes));

            // Carpeta jerárquica: NIVEL/GRADO_SECCION (JSZip crea subcarpetas con /)
            $nivel   = mb_strtoupper(str_replace(' ', '_', trim($a['nivel_nombre'])));
            $grado   = mb_strtoupper(preg_replace('/[°\s.]+/', '', trim($a['grado_nombre'])));
            $seccion = mb_strtoupper(trim($a['seccion_nombre']));
            $data['carpeta'] = "{$nivel}/{$grado}_{$seccion}";

            $data['url_boleta'] = $this->urlBoletaToken($matriculaId);

            $boletasData[] = $data;
        }

        View::setLayout('print');
        $this->view('admin/boletas-publicas/archivar', [
            'titulo'         => 'Archivar boletas — ' . $periodo['nombre_display'],
            'periodo'        => $periodo,
            'boletasData'    => $boletasData,
            'seccionFiltro'  => $seccionId,
            // Lote: sin «Página X de Y» (contaría toda la impresión). Ver _boleta.scss.
            'bodyClass'      => 'doc-lote',
        ]);
    }

    /**
     * GET /admin/boletas-publicas/{periodo_id}/archivar-borrador[?seccion_id=N]
     * Descarga en ZIP las boletas EN BORRADOR (el mismo documento de la vista
     * previa, en PDF). Sirve para subirlas al Drive institucional y pedir el
     * visto bueno de los docentes ANTES de cerrar el bimestre.
     *
     * Se diferencia de archivar() en tres cosas, y solo en esas:
     *   - umbral 'todos' (no 'archivo'): incluye el bimestre EN CURSO, que es
     *     justo lo que se manda a revisar. Es la misma excepcion de vistaPrevia().
     *   - $vistaPrevia = true: sin QR y sin imagen de firma del director, mas la
     *     MARCA DE AGUA de borrador dentro de cada PDF.
     *   - SIN el guard de bimestre cerrado: este documento existe precisamente
     *     para el bimestre abierto. Con el bimestre ya cerrado sigue disponible
     *     (es util para reconstruir el borrador que se circulo).
     */
    public function archivarBorrador($periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(url('admin/boletas-publicas'), 'Período no encontrado.');
        }

        $seccionId   = (int) $this->query('seccion_id', 0) ?: null;
        $matriculas  = $this->model->getMatriculasAprobadasParaBoleta($periodoId, $seccionId);
        $boletasData = [];

        foreach ($matriculas as $m) {
            $data = $this->boletaModel->armar((int) $m['matricula_id'], $periodoId, 'todos', true);
            if (!$data) continue;

            $a = $data['alumno'];

            // Mismo criterio de nombre y carpeta que archivar(), con el sufijo
            // BORRADOR: estos PDFs conviven en el Drive con los definitivos y
            // el nombre del archivo tiene que decir cual es cual sin abrirlo.
            $partes = [
                mb_strtoupper($a['apellido_paterno']),
                mb_strtoupper($a['apellido_materno']),
                mb_strtoupper($a['nombres']),
            ];
            $data['nombre_archivo'] = str_replace(' ', '_', implode('_', $partes)) . '_BORRADOR';

            $nivel   = mb_strtoupper(str_replace(' ', '_', trim($a['nivel_nombre'])));
            $grado   = mb_strtoupper(preg_replace('/[°\s.]+/', '', trim($a['grado_nombre'])));
            $seccion = mb_strtoupper(trim($a['seccion_nombre']));
            $data['carpeta'] = "{$nivel}/{$grado}_{$seccion}";

            // Sin url_boleta: en borrador no se emite QR (el token llevaria a la
            // boleta publicada, que es otro documento y puede no existir aun).
            $data['vistaPrevia'] = true;

            $boletasData[] = $data;
        }

        View::setLayout('print');
        $this->view('admin/boletas-publicas/archivar', [
            'titulo'         => 'Descargar borradores — ' . $periodo['nombre_display'],
            'periodo'        => $periodo,
            'boletasData'    => $boletasData,
            'seccionFiltro'  => $seccionId,
            'esBorrador'     => true,
            // Lote: sin «Página X de Y» (contaría toda la impresión). Ver _boleta.scss.
            'bodyClass'      => 'doc-lote',
        ]);
    }

    // ── Helpers privados ────────────────────────────────────────

    private function getPeriodo(int $periodoId): ?array
    {
        return $this->model->queryOne("
            SELECT p.id, p.numero, p.nombre_display, p.estado, p.boletas_aprobadas_en,
                   a.anio, a.id AS anio_id
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
            LIMIT 1
        ", [$periodoId]);
    }

    /**
     * ¿El bimestre ya cerro? Es la condicion para EMITIR el documento oficial
     * (impresion masiva y ZIP de archivo): con el bimestre abierto, el umbral
     * 'archivo' no deja pasar sus notas y saldria papel con la columna vacia.
     *
     * OJO: la condicion es CERRADO, **no publicado**. 'archivo' ignora la
     * compuerta 044 a proposito, porque RA imprime ANTES de la reunion de
     * entrega; exigir publicacion romperia ese flujo.
     */
    private function periodoEsOficial(array $periodo): bool
    {
        return boleta_estado_bimestre(
            $periodo['estado'] ?? null,
            $periodo['boletas_aprobadas_en'] ?? null
        ) === 'oficial';
    }

}
