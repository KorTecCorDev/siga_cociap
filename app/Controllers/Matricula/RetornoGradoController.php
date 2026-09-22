<?php

namespace App\Controllers\Matricula;

use App\Controllers\BaseController;
use App\Models\MatriculaModel;
use Core\Session;

/**
 * RetornoGradoController
 * Caso especial: un estudiante asiste a un grado INFERIOR al oficial de SIAGIE.
 * Se crea una "matrícula operativa" en el grado destino y se vincula con la
 * "matrícula oficial" en retornos_grado. El estudiante compite académicamente
 * con su grado OPERATIVO (ver integración en OrdenMeritoController).
 */
class RetornoGradoController extends BaseController
{
    private MatriculaModel $model;

    public function __construct()
    {
        // El Director EBR SALE el 24/08/2026: registrar un retorno de grado es
        // escritura, y su rol pasa a ser de supervision en solo lectura. El
        // permiso ademas era HUERFANO — para llegar al formulario habia que
        // pasar por /matriculas/{id}, que le devolvia 403; solo era alcanzable
        // pegando la URL a mano. El retorno se consulta desde el detalle de la
        // matricula, que si ve.
        $this->requireRole(['admin', 'registro_academico']);
        $this->model = new MatriculaModel();
    }

    // ── GET /matriculas/{id}/retorno ─────────────────────────────
    public function create(string $matriculaId): void
    {
        $matricula = $this->requireMatricula((int) $matriculaId);

        // ¿Ya tiene un retorno activo?
        $existente = $this->model->queryOne(
            "SELECT id FROM retornos_grado WHERE matricula_oficial_id = ? AND estado = 'activo' LIMIT 1",
            [(int) $matriculaId]
        );
        if ($existente) {
            $this->redirectWithError(url('matriculas/' . $matriculaId),
                'Esta matrícula ya tiene un retorno de grado activo.');
        }

        // CANDADO DEL BIMESTRE EN CURSO (ver el detalle en store()): se avisa ya
        // en el GET para no hacer llenar un formulario que el POST va a rechazar.
        $bloqueo = $this->evaluacionEnBimestreActivo((int) $matriculaId);
        if ($bloqueo) {
            $this->redirectWithError(url('matriculas/' . $matriculaId),
                $this->mensajeBimestreEnCurso($bloqueo));
        }

        // Secciones de grados INFERIORES al actual, del mismo año y nivel.
        $secciones = $this->model->query("
            SELECT s.id, s.nombre, g.numero AS grado_numero, g.nombre_display AS grado_nombre
            FROM secciones s
            INNER JOIN grados g ON g.id = s.grado_id
            WHERE s.anio_id = ?
              AND g.nivel_id = ?
              AND g.numero < ?
            ORDER BY g.numero, s.nombre
        ", [(int) $matricula['anio_id'], (int) $matricula['nivel_id'], (int) $matricula['grado_numero']]);

        $this->view('matriculas/retorno', [
            'titulo'    => 'Retorno de grado',
            'matricula' => $matricula,
            'secciones' => $secciones,
        ]);
    }

    // ── POST /matriculas/{id}/retorno ────────────────────────────
    public function store(string $matriculaId): void
    {
        $this->validateCsrf();
        $oficial   = $this->requireMatricula((int) $matriculaId);
        $usuarioId = (int) (Session::user()['id'] ?? 0);

        $seccionDestino = (int) $this->input('seccion_destino_id');
        $motivo         = trim((string) $this->input('motivo'));

        if (!$seccionDestino || $motivo === '') {
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno'),
                'Selecciona la sección destino y describe el motivo.');
        }

        // Validar que la sección destino exista, sea del mismo año y de grado inferior.
        $destino = $this->model->queryOne("
            SELECT s.id, g.numero AS grado_numero
            FROM secciones s
            INNER JOIN grados g ON g.id = s.grado_id
            WHERE s.id = ? AND s.anio_id = ? AND g.nivel_id = ?
            LIMIT 1
        ", [$seccionDestino, (int) $oficial['anio_id'], (int) $oficial['nivel_id']]);

        if (!$destino || (int) $destino['grado_numero'] >= (int) $oficial['grado_numero']) {
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno'),
                'La sección destino debe ser de un grado inferior al oficial.');
        }

        // Evitar duplicado de retorno activo.
        $yaActivo = $this->model->queryOne(
            "SELECT id FROM retornos_grado WHERE matricula_oficial_id = ? AND estado = 'activo' LIMIT 1",
            [(int) $matriculaId]
        );
        if ($yaActivo) {
            $this->redirectWithError(url('matriculas/' . $matriculaId),
                'Esta matrícula ya tiene un retorno de grado activo.');
        }

        // ── CANDADO: no se puede retornar a mitad de un bimestre YA EVALUADO ──
        // REGLA A (05/08/2026): las notas viven donde se registraron —antes del
        // retorno en la oficial, desde el retorno en la operativa— y NO se copian
        // ni se mueven. Eso solo es coherente si el bimestre en curso todavia no
        // tiene evaluacion en la oficial; si la tiene, el retorno la parte en dos:
        //
        //   1. El corte del roster es INSTANTANEO: los 9 rosters de evaluacion
        //      dejan de ver la oficial, asi que el docente de la seccion de origen
        //      pierde al estudiante a mitad de bimestre y sus criterios ya
        //      cargados quedan inalcanzables desde la grilla.
        //   2. Los CRITERIOS son de la seccion, no del alumno: los de la seccion
        //      destino estan vacios para el y los de origen no existen alla. No
        //      hay convalidacion automatica (eso es el plan de cambio de seccion).
        //   3. La operativa quedaria con promedios sin criterios que los respalden
        //      -> la alerta de evaluacion incompleta la marca y el guard P4 ABORTA
        //      EL CIERRE del bimestre.
        //
        // OJO: no se ancla en FECHAS porque los bimestres SE SOLAPAN (B1 termina
        // el 16/06 y B2 empieza el 01/06), asi que "entre bimestres" no existe
        // como ventana. Se ancla en el DATO: que no haya evaluacion registrada.
        $bloqueo = $this->evaluacionEnBimestreActivo((int) $matriculaId);
        if ($bloqueo) {
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno'),
                $this->mensajeBimestreEnCurso($bloqueo));
        }

        $this->model->beginTransaction();
        try {
            // 1) Matrícula operativa en el grado inferior (estado 'aprobada' para
            //    que el estudiante figure en calificaciones y ranking operativo).
            $operativaId = $this->model->create([
                'estudiante_id'  => (int) $oficial['estudiante_id'],
                'seccion_id'     => $seccionDestino,
                'anio_id'        => (int) $oficial['anio_id'],
                'tipo'           => 'continuador',
                'serie_recibo'   => $oficial['serie_recibo'] ?? null,
                'estado'         => 'aprobada',
                'fecha_registro' => date('Y-m-d'),
                'registrado_por' => $usuarioId,
            ]);

            // 2) Vínculo en retornos_grado.
            $this->model->execute("
                INSERT INTO retornos_grado
                    (matricula_oficial_id, matricula_operativa_id, motivo,
                     autorizado_por, fecha_retorno, estado)
                VALUES (?, ?, ?, ?, CURDATE(), 'activo')
            ", [(int) $matriculaId, $operativaId, $motivo, $usuarioId]);

            // 3) Trasladar ASISTENCIA y CONDUCTA del/los bimestre(s) ACTIVO(s).
            //
            //    Las CALIFICACIONES ya NO se copian (hasta el 05/08/2026 este paso
            //    era un INSERT IGNORE que duplicaba TODAS las notas de la oficial en
            //    la operativa, sin filtrar periodo). Bajo la REGLA A cada bimestre
            //    queda en la matricula donde se curso, y la boleta las une por
            //    bimestre — duplicarlas creaba una segunda fuente de verdad que
            //    ademas hacia aparecer al estudiante DOS VECES en el lote de boletas.
            //
            //    Asistencia y conducta si se MUEVEN (UPDATE, no INSERT) porque son
            //    contadores por bimestre, no datos por criterio: no hay nada que
            //    convalidar, y el bimestre en curso se termina de cursar en la
            //    seccion destino. Moverlos —en vez de copiarlos— evita que ambas
            //    matriculas tengan fila del mismo bimestre: la union de asistencia
            //    de la boleta SUMA campo a campo, asi que un solape inflaria las
            //    faltas en silencio.
            //
            //    Los bimestres CERRADOS no se tocan: son el registro historico de
            //    la seccion de origen. Sin conflicto de UNIQUE: la operativa acaba
            //    de nacer y no tiene ninguna fila.
            $activos = array_column($this->model->query("
                SELECT id FROM periodos
                WHERE estado = 'activo' AND anio_id = ?
            ", [(int) $oficial['anio_id']]), 'id');

            if ($activos) {
                $marcas = implode(',', array_fill(0, count($activos), '?'));
                foreach (['inasistencias', 'conducta_respuestas', 'calificaciones_conducta'] as $tabla) {
                    $this->model->execute("
                        UPDATE {$tabla}
                        SET matricula_id = ?
                        WHERE matricula_id = ?
                          AND periodo_id IN ({$marcas})
                    ", array_merge([$operativaId, (int) $matriculaId], $activos));
                }
            }

            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error en retorno de grado', ['id' => $matriculaId, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno'),
                'No se pudo registrar el retorno de grado.');
        }

        $this->redirectWithSuccess(url('matriculas/' . $matriculaId),
            'Retorno de grado registrado. El estudiante competirá en su grado operativo.');
    }

    // ── GET /matriculas/{id}/retorno/revertir ────────────────────
    public function confirmarReversion(string $matriculaId): void
    {
        $oficial = $this->requireMatricula((int) $matriculaId);
        $retorno = $this->getRetornoActivo((int) $matriculaId);

        if (!$retorno) {
            $this->redirectWithError(url('matriculas/' . $matriculaId),
                'Esta matrícula no tiene un retorno de grado activo que revertir.');
        }

        // Bimestres en los que la matrícula operativa tiene notas: son los que se
        // consolidarán en la boleta oficial. Deben estar cerrados para revertir.
        $periodos = $this->periodosConNotas((int) $retorno['matricula_operativa_id']);

        $this->view('matriculas/retorno-revertir', [
            'titulo'    => 'Revertir retorno de grado',
            'matricula' => $oficial,
            'retorno'   => $retorno,
            'periodos'  => $periodos,
        ]);
    }

    // ── POST /matriculas/{id}/retorno/revertir ────────────────────
    public function revertir(string $matriculaId): void
    {
        $this->validateCsrf();
        $oficial   = $this->requireMatricula((int) $matriculaId);
        $usuarioId = (int) (Session::user()['id'] ?? 0);

        $motivo = trim((string) $this->input('motivo'));
        if ($motivo === '') {
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno/revertir'),
                'Describe el motivo de la reversión.');
        }

        $retorno = $this->getRetornoActivo((int) $matriculaId);
        if (!$retorno) {
            $this->redirectWithError(url('matriculas/' . $matriculaId),
                'Esta matrícula no tiene un retorno de grado activo que revertir.');
        }

        // Regla: solo se revierte con el/los bimestre(s) cursado(s) en el grado
        // operativo CERRADOS, para congelar esas notas antes de consolidar.
        $abiertos = $this->periodosConNotas((int) $retorno['matricula_operativa_id'], false);
        if ($abiertos) {
            $nombres = implode(', ', array_column($abiertos, 'nombre_display'));
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno/revertir'),
                'No se puede revertir: el/los bimestre(s) con notas en el grado operativo deben estar cerrados (' . $nombres . ').');
        }

        $this->model->beginTransaction();
        try {
            // 1) Marcar el retorno como revertido (auditoría).
            $this->model->execute("
                UPDATE retornos_grado
                SET estado           = 'revertido',
                    fecha_reversion  = CURDATE(),
                    motivo_reversion = ?,
                    revertido_por    = ?
                WHERE id = ?
            ", [$motivo, $usuarioId, (int) $retorno['id']]);

            // 2) Desactivar la matrícula operativa (queda solo para auditoría).
            //    La boleta de la oficial seguirá leyendo sus notas por unión.
            $this->model->cambiarEstado(
                (int) $retorno['matricula_operativa_id'],
                'desactivado',
                $usuarioId,
                'Retorno de grado revertido — ' . $motivo
            );

            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al revertir retorno de grado', ['id' => $matriculaId, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno/revertir'),
                'No se pudo revertir el retorno de grado.');
        }

        $this->redirectWithSuccess(url('matriculas/' . $matriculaId),
            'Retorno de grado revertido. El estudiante vuelve a calificarse en su grado oficial.');
    }

    /** Retorno ACTIVO cuya matrícula oficial es la indicada, o null. */
    private function getRetornoActivo(int $oficialId): ?array
    {
        return $this->model->queryOne("
            SELECT r.*, g.nombre_display AS grado_destino, s.nombre AS seccion_destino
            FROM retornos_grado r
            INNER JOIN matriculas mo ON mo.id = r.matricula_operativa_id
            LEFT  JOIN secciones s   ON s.id = mo.seccion_id
            LEFT  JOIN grados g      ON g.id = s.grado_id
            WHERE r.matricula_oficial_id = ? AND r.estado = 'activo'
            LIMIT 1
        ", [$oficialId]);
    }

    /**
     * Bimestres ACTIVOS en los que la matrícula ya tiene evaluación registrada.
     * Es el candado del retorno (ver store()): si devuelve algo, no se puede
     * retornar hasta cerrar ese bimestre o resolverlo a mano.
     *
     * Mira las TRES tablas donde vive la evaluación de un estudiante, porque
     * cualquiera de ellas basta para que el corte deje trabajo partido:
     *   - `calificaciones`          promedio por competencia,
     *   - `calificaciones_criterio` nota por criterio,
     *   - `omisiones_criterio`      blanco motivado (evaluación registrada
     *                               aunque no haya nota).
     * Los criterios eliminados no cuentan.
     */
    private function evaluacionEnBimestreActivo(int $matriculaId): array
    {
        return $this->model->query("
            SELECT
                p.id,
                p.nombre_display,
                (SELECT COUNT(*) FROM calificaciones c
                  WHERE c.matricula_id = ? AND c.periodo_id = p.id)          AS notas,
                (SELECT COUNT(*) FROM calificaciones_criterio cc
                  INNER JOIN criterios k ON k.id = cc.criterio_id
                  WHERE cc.matricula_id = ? AND k.periodo_id = p.id
                    AND k.eliminado_en IS NULL)                              AS criterios,
                (SELECT COUNT(*) FROM omisiones_criterio oc
                  INNER JOIN criterios k2 ON k2.id = oc.criterio_id
                  WHERE oc.matricula_id = ? AND k2.periodo_id = p.id
                    AND k2.eliminado_en IS NULL)                             AS omisiones
            FROM periodos p
            WHERE p.estado = 'activo'
              AND p.anio_id = (SELECT id FROM anios_academicos WHERE estado = 'activo' LIMIT 1)
            HAVING notas > 0 OR criterios > 0 OR omisiones > 0
            ORDER BY p.numero
        ", [$matriculaId, $matriculaId, $matriculaId]);
    }

    /** Mensaje del candado: qué bimestre bloquea, con qué y qué hacer. */
    private function mensajeBimestreEnCurso(array $bloqueo): string
    {
        $partes = [];
        foreach ($bloqueo as $b) {
            $detalle = [];
            if ((int) $b['notas'] > 0)     { $detalle[] = "{$b['notas']} nota(s)"; }
            if ((int) $b['criterios'] > 0) { $detalle[] = "{$b['criterios']} criterio(s)"; }
            if ((int) $b['omisiones'] > 0) { $detalle[] = "{$b['omisiones']} omision(es)"; }
            $partes[] = $b['nombre_display'] . ' (' . implode(', ', $detalle) . ')';
        }

        return 'No se puede registrar el retorno: el estudiante ya tiene evaluación '
             . 'en un bimestre en curso — ' . implode('; ', $partes) . '. '
             . 'El retorno debe hacerse antes de que se le califique en el bimestre, '
             . 'o esperar a que el bimestre cierre.';
    }

    /**
     * Periodos en los que una matrícula tiene calificaciones registradas.
     * Con $cerrados=true devuelve solo los cerrados (los que se consolidan);
     * con $cerrados=false devuelve solo los NO cerrados (bloquean la reversión).
     */
    private function periodosConNotas(int $matriculaId, bool $cerrados = true): array
    {
        $condEstado = $cerrados ? "p.estado = 'cerrado'" : "p.estado <> 'cerrado'";

        return $this->model->query("
            SELECT DISTINCT p.id, p.numero, p.nombre_display, p.estado
            FROM calificaciones cal
            INNER JOIN periodos p ON p.id = cal.periodo_id
            WHERE cal.matricula_id = ?
              AND {$condEstado}
            ORDER BY p.numero
        ", [$matriculaId]);
    }

    /** Carga la matrícula o muestra 404. */
    private function requireMatricula(int $id): array
    {
        $matricula = $this->model->findById($id);
        if (!$matricula) {
            // Punto único del 404 (BaseController): sin layout, porque
            // `shared/404.php` es una página HTML completa.
            $this->notFound();
        }
        return $matricula;
    }
}
