<?php

namespace App\Controllers\Matricula;

use App\Controllers\BaseController;
use App\Models\MatriculaModel;
use App\Models\RetornoGradoModel;
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
    private RetornoGradoModel $retornos;

    public function __construct()
    {
        // El Director EBR SALE el 24/08/2026: registrar un retorno de grado es
        // escritura, y su rol pasa a ser de supervision en solo lectura. El
        // permiso ademas era HUERFANO — para llegar al formulario habia que
        // pasar por /matriculas/{id}, que le devolvia 403; solo era alcanzable
        // pegando la URL a mano. El retorno se consulta desde el detalle de la
        // matricula, que si ve.
        $this->requireRole(['admin', 'registro_academico']);
        $this->model    = new MatriculaModel();
        $this->retornos = new RetornoGradoModel();
    }

    // ── GET /matriculas/{id}/retorno ─────────────────────────────
    public function create(string $matriculaId): void
    {
        $matricula = $this->requireMatricula((int) $matriculaId);

        $yaTiene = $this->mensajeYaTieneRetorno((int) $matriculaId);
        if ($yaTiene !== null) {
            $this->redirectWithError(url('matriculas/' . $matriculaId), $yaTiene);
        }

        // CANDADO DEL BIMESTRE EN CURSO (ver el detalle en store()): se avisa ya
        // en el GET para no hacer llenar un formulario que el POST va a rechazar.
        $bloqueo = $this->evaluacionEnBimestreActivo((int) $matriculaId, true);
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

        // UN retorno por matrícula y año (decisión del usuario, 05/10/2026; los
        // UNIQUE de retornos_grado ya lo imponían, esto da el mensaje).
        $yaTiene = $this->mensajeYaTieneRetorno((int) $matriculaId);
        if ($yaTiene !== null) {
            $this->redirectWithError(url('matriculas/' . $matriculaId), $yaTiene);
        }

        // Inicio del TRAMO: el primer bimestre sin cerrar. Sin él no hay dónde
        // cursar (año cerrado).
        $desde = $this->retornos->primerPeriodoSinCerrar((int) $oficial['anio_id']);
        if (!$desde) {
            $this->redirectWithError(url('matriculas/' . $matriculaId),
                'No hay ningún bimestre sin cerrar en el año: el retorno no tendría dónde cursarse.');
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
        // Desde el 05/10/2026 mira TODOS los bimestres sin cerrar, que son los
        // que el tramo cubrirá (no solo los `activo`: los bimestres se solapan).
        $bloqueo = $this->evaluacionEnBimestreActivo((int) $matriculaId, true);
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

            // 2) Vínculo en retornos_grado, con el INICIO del tramo (migración
            //    073): desde aquí la operativa cubre cada bimestre hasta revertir.
            $this->model->execute("
                INSERT INTO retornos_grado
                    (matricula_oficial_id, matricula_operativa_id, motivo,
                     autorizado_por, fecha_retorno, periodo_desde_id, estado)
                VALUES (?, ?, ?, ?, CURDATE(), ?, 'activo')
            ", [(int) $matriculaId, $operativaId, $motivo, $usuarioId, (int) $desde['id']]);

            // 3) Trasladar ASISTENCIA y CONDUCTA de los bimestres SIN CERRAR (los
            //    que cubre el tramo desde hoy).
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
            $enCurso = array_column($this->periodosNoCerrados((int) $oficial['anio_id']), 'id');

            $this->moverRegistrosDelBimestre((int) $matriculaId, $operativaId, $enCurso);

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

        // Las guardas se avisan ya en el GET (como en create()) para no hacer
        // llenar un formulario que el POST va a rechazar.
        $bloqueo = $this->bloqueoReversion($oficial, $retorno);
        if ($bloqueo !== null) {
            $this->redirectWithError(url('matriculas/' . $matriculaId), $bloqueo);
        }

        $operativaId = (int) $retorno['matricula_operativa_id'];

        $this->view('matriculas/retorno-revertir', [
            'titulo'    => 'Revertir retorno de grado',
            'matricula' => $oficial,
            'retorno'   => $retorno,
            'periodos'  => $this->periodosCursadosEnOperativa($operativaId),
            'registros' => $this->registrosDelBimestreEnCurso($operativaId),
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

        $bloqueo = $this->bloqueoReversion($oficial, $retorno);
        if ($bloqueo !== null) {
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno/revertir'), $bloqueo);
        }

        $operativaId  = (int) $retorno['matricula_operativa_id'];
        $comoBorrador = $this->input('como_borrador') === '1';
        $enCurso      = array_column($this->periodosNoCerrados((int) $oficial['anio_id']), 'id');

        // FIN del tramo (migración 073): el último bimestre CERRADO que cubrió;
        // null = tramo vacío. Los bimestres sin cerrar vuelven a la oficial (paso 3).
        $vinculo = $this->retornos->vinculo((int) $matriculaId);
        $hasta   = $vinculo ? $this->retornos->finDelTramoAlRevertir($vinculo) : null;

        $this->model->beginTransaction();
        try {
            // 1) Marcar el retorno como revertido y CERRAR su tramo.
            $this->model->execute("
                UPDATE retornos_grado
                SET estado           = 'revertido',
                    fecha_reversion  = CURDATE(),
                    periodo_hasta_id = ?,
                    motivo_reversion = ?,
                    revertido_por    = ?
                WHERE id = ?
            ", [$hasta, $motivo, $usuarioId, (int) $retorno['id']]);

            // 2) Desactivar la matrícula operativa (queda solo para auditoría).
            //    La boleta de la oficial seguirá leyendo sus notas por unión.
            $this->model->cambiarEstado(
                (int) $retorno['matricula_operativa_id'],
                'desactivado',
                $usuarioId,
                'Retorno de grado revertido — ' . $motivo
            );

            // 3) Devolver a la oficial la ASISTENCIA y la CONDUCTA de los bimestres
            //    sin cerrar: el espejo exacto del paso 3 de store(). El estudiante
            //    termina el bimestre en su sección oficial, y sin esto esos
            //    registros quedaban en la operativa, fuera de todo roster (05/10/2026).
            //    Cada fila viaja con su estado de confirmación: no hace falta
            //    bloquear ni aprobar nada antes de revertir.
            $this->moverRegistrosDelBimestre($operativaId, (int) $matriculaId, $enCurso);

            // 4) «Moverlos como borrador» (decisión del usuario, 05/10/2026): cuando
            //    la reversión se comunica TARDE, lo que registró la sección operativa
            //    describe a un estudiante que ya no estaba ahí. Se conservan las
            //    respuestas y las fechas como punto de partida, pero pierden la
            //    confirmación —la misma semántica de «editar desconfirma»— para que
            //    el personal de la sección oficial las revise antes de que cuenten.
            //    Solo toca lo que se acaba de mover: bloqueoReversion() garantiza
            //    que la oficial no tenía filas propias en esos periodos.
            if ($comoBorrador && $enCurso) {
                $marcas = implode(',', array_fill(0, count($enCurso), '?'));
                $params = array_merge([(int) $matriculaId], $enCurso);
                $this->model->execute("
                    DELETE FROM conducta_confirmaciones
                    WHERE matricula_id = ? AND periodo_id IN ({$marcas})
                ", $params);
                $this->model->execute("
                    UPDATE inasistencias
                    SET confirmado_en = NULL, confirmado_por = NULL
                    WHERE matricula_id = ? AND periodo_id IN ({$marcas})
                ", $params);
            }

            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al revertir retorno de grado', ['id' => $matriculaId, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $matriculaId . '/retorno/revertir'),
                'No se pudo revertir el retorno de grado.');
        }

        $this->redirectWithSuccess(url('matriculas/' . $matriculaId),
            'Retorno de grado revertido. El estudiante vuelve a calificarse en su grado oficial.'
            . ($comoBorrador ? ' La conducta y la asistencia del bimestre en curso quedaron como borrador.' : ''));
    }

    /**
     * Tablas de ASISTENCIA y CONDUCTA que viven por matrícula y bimestre, y que
     * un retorno (o su reversión) MUEVE de una matrícula a la otra.
     *
     * `conducta_confirmaciones` (29/09/2026, migración 068) viaja con sus
     * respuestas: sin ella, la conducta confirmada llegaría como BORRADOR y
     * saldría de la boleta. `asistencia_incidencias` (migración 069) viaja con
     * sus contadores de `inasistencias`: los contadores SON el conteo de esas
     * fechas (invariante) y deben vivir en la misma matrícula.
     */
    private const TABLAS_DEL_BIMESTRE = [
        // `asistencia_presencias`: el ✓ propio de cada día (migración 077).
        'inasistencias', 'asistencia_incidencias', 'asistencia_presencias',
        'conducta_respuestas', 'conducta_confirmaciones', 'calificaciones_conducta',
    ];

    /**
     * MUEVE (UPDATE, no copia) la asistencia y la conducta de `$de` a `$a` en
     * los periodos indicados. Lo usan store() (oficial → operativa) y revertir()
     * (operativa → oficial). Mover —en vez de copiar— evita que ambas matrículas
     * tengan fila del mismo bimestre: la unión de asistencia de la boleta SUMA
     * campo a campo, así que un solape inflaría las faltas en silencio.
     *
     * No toma la lista del día (`asistencia_jornadas`) de la sección destino: eso
     * afirmaría «asistió» para todos sus compañeros. Una incidencia que cae en un
     * día «Sin tomar» allá espera a que se pase lista, como cualquier otra.
     *
     * Debe correr dentro de la transacción de quien llama.
     */
    private function moverRegistrosDelBimestre(int $de, int $a, array $periodos): void
    {
        if (!$periodos) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($periodos), '?'));
        foreach (self::TABLAS_DEL_BIMESTRE as $tabla) {
            $this->model->execute("
                UPDATE {$tabla}
                SET matricula_id = ?
                WHERE matricula_id = ?
                  AND periodo_id IN ({$marcas})
            ", array_merge([$a, $de], $periodos));
        }
    }

    /**
     * GUARDAS de la reversión (05/10/2026). Devuelve el mensaje que la impide, o
     * null si se puede revertir. Las comparten el GET y el POST.
     *
     *  1. EVALUACIÓN de la operativa en un bimestre sin cerrar, mirando las TRES
     *     tablas de evaluación (como el candado de store()). Hasta el 05/10 solo
     *     se miraba `calificaciones`: un criterio con nota sin promedio, o una
     *     omisión, pasaba. Las notas NO se mueven entre matrículas (Regla A), así
     *     que un bimestre evaluado en la operativa debe cerrarse antes.
     *  2. CIERRE VIGENTE de conducta o asistencia, en la sección de origen o en
     *     la de destino, en un bimestre sin cerrar. Mover filas fuera de un
     *     registro ya cerrado lo altera en silencio, y meterlas en uno cerrado
     *     añade datos que nadie revisó. Se reabre primero desde los bloqueos.
     *  3. La OFICIAL ya tiene filas propias de asistencia o conducta en esos
     *     bimestres: el movimiento las chocaría (UNIQUE) o, peor, la unión de la
     *     boleta sumaría las dos. No debería pasar —la oficial está fuera del
     *     roster mientras el retorno está activo—, así que se revisa a mano.
     */
    private function bloqueoReversion(array $oficial, array $retorno): ?string
    {
        $operativaId = (int) $retorno['matricula_operativa_id'];
        $oficialId   = (int) $oficial['id'];

        $evaluacion = $this->evaluacionEnBimestreActivo($operativaId, true);
        if ($evaluacion) {
            $partes = [];
            foreach ($evaluacion as $b) {
                $partes[] = $b['nombre_display'];
            }
            return 'No se puede revertir: el estudiante ya tiene evaluación en el grado operativo '
                 . 'en un bimestre sin cerrar (' . implode(', ', $partes) . '). Las notas no se '
                 . 'mueven entre matrículas: hay que esperar a que ese bimestre cierre.';
        }

        $periodos = $this->periodosNoCerrados((int) $oficial['anio_id']);
        if (!$periodos) {
            return null;
        }
        $ids    = array_column($periodos, 'id');
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $secciones = [(int) $retorno['seccion_destino_id'], (int) $oficial['seccion_id']];

        $cierres = $this->model->query("
            SELECT 'conducta' AS registro, s.nombre AS seccion, g.nombre_display AS grado,
                   p.nombre_display AS periodo
            FROM cierres_conducta z
            INNER JOIN secciones s ON s.id = z.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN periodos p  ON p.id = z.periodo_id
            WHERE z.anulado_en IS NULL AND z.seccion_id IN (?, ?) AND z.periodo_id IN ({$marcas})
            UNION ALL
            SELECT 'asistencia', s.nombre, g.nombre_display, p.nombre_display
            FROM cierres_asistencia z
            INNER JOIN secciones s ON s.id = z.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN periodos p  ON p.id = z.periodo_id
            WHERE z.anulado_en IS NULL AND z.seccion_id IN (?, ?) AND z.periodo_id IN ({$marcas})
        ", array_merge($secciones, $ids, $secciones, $ids));
        if ($cierres) {
            $partes = array_map(
                fn($c) => "{$c['registro']} de {$c['grado']} \"{$c['seccion']}\" ({$c['periodo']})",
                $cierres
            );
            return 'No se puede revertir: hay registros cerrados en el bimestre en curso — '
                 . implode('; ', $partes) . '. La reversión mueve la asistencia y la conducta '
                 . 'del bimestre a la matrícula oficial; reabre esos cierres desde los bloqueos y vuelve a intentarlo.';
        }

        foreach (self::TABLAS_DEL_BIMESTRE as $tabla) {
            $propias = $this->model->queryOne("
                SELECT COUNT(*) AS n FROM {$tabla}
                WHERE matricula_id = ? AND periodo_id IN ({$marcas})
            ", array_merge([$oficialId], $ids));
            if ((int) ($propias['n'] ?? 0) > 0) {
                return 'No se puede revertir: la matrícula oficial ya tiene registros propios de '
                     . 'asistencia o conducta en el bimestre en curso (' . $tabla . '). Moverlos '
                     . 'duplicaría el dato en la boleta; hay que revisarlo a mano antes.';
            }
        }

        return null;
    }

    /**
     * UN retorno por matrícula y año (decisión del usuario, 05/10/2026), en las
     * dos direcciones: ni una oficial que ya tuvo retorno (activo o revertido)
     * ni una matrícula que ES la operativa de otro retorno. Devuelve el mensaje
     * que lo impide, o null.
     */
    private function mensajeYaTieneRetorno(int $matriculaId): ?string
    {
        $v = $this->retornos->vinculo($matriculaId);
        if (!$v) {
            return null;
        }
        if ($v['operativa'] === $matriculaId) {
            return 'Esta es la matrícula operativa de un retorno de grado: la gestión se hace desde la matrícula oficial.';
        }
        return $v['estado'] === 'activo'
            ? 'Esta matrícula ya tiene un retorno de grado activo.'
            : 'Esta matrícula ya tuvo un retorno de grado este año (revertido). Solo se admite uno por año.';
    }

    /** Periodos del año que aún NO están cerrados (en curso o por empezar). */
    private function periodosNoCerrados(int $anioId): array
    {
        return $this->model->query("
            SELECT id, numero, nombre_display FROM periodos
            WHERE anio_id = ? AND estado <> 'cerrado'
            ORDER BY numero
        ", [$anioId]);
    }

    /**
     * Bimestres CERRADOS que el estudiante cursó en el grado operativo: los que
     * tienen evaluación POR CRITERIO en la operativa. Son los que la boleta de la
     * oficial sigue leyendo por unión. No se mira `calificaciones` a secas porque
     * el retorno #1 arrastra en el I Bimestre 22 promedios COPIADOS por el
     * `INSERT IGNORE` anterior al 05/08/2026 (sin criterios), y la pantalla
     * anunciaba consolidar un bimestre que se cursó en la oficial.
     */
    private function periodosCursadosEnOperativa(int $operativaId): array
    {
        return $this->model->query("
            SELECT DISTINCT p.id, p.numero, p.nombre_display
            FROM calificaciones_criterio cc
            INNER JOIN criterios k ON k.id = cc.criterio_id AND k.eliminado_en IS NULL
            INNER JOIN periodos p  ON p.id = k.periodo_id
            WHERE cc.matricula_id = ? AND p.estado = 'cerrado'
            ORDER BY p.numero
        ", [$operativaId]);
    }

    /**
     * Lo que la reversión MUEVE a la oficial, por bimestre sin cerrar: para que
     * la pantalla de confirmación diga qué viaja y en qué estado.
     */
    private function registrosDelBimestreEnCurso(int $operativaId): array
    {
        return $this->model->query("
            SELECT p.nombre_display,
                   (SELECT COUNT(*) FROM conducta_respuestas cr
                     WHERE cr.matricula_id = ? AND cr.periodo_id = p.id)        AS conducta,
                   (SELECT COUNT(*) FROM conducta_confirmaciones cf
                     WHERE cf.matricula_id = ? AND cf.periodo_id = p.id)        AS conducta_confirmada,
                   (SELECT COUNT(*) FROM asistencia_incidencias ai
                     WHERE ai.matricula_id = ? AND ai.periodo_id = p.id)        AS incidencias,
                   (SELECT COUNT(*) FROM inasistencias i
                     WHERE i.matricula_id = ? AND i.periodo_id = p.id
                       AND i.confirmado_en IS NOT NULL)                         AS asistencia_confirmada
            FROM periodos p
            WHERE p.estado <> 'cerrado'
              AND p.anio_id = (SELECT anio_id FROM matriculas WHERE id = ?)
            HAVING conducta > 0 OR incidencias > 0 OR asistencia_confirmada > 0
            ORDER BY p.numero
        ", [$operativaId, $operativaId, $operativaId, $operativaId, $operativaId]);
    }

    /** Retorno ACTIVO cuya matrícula oficial es la indicada, o null. */
    private function getRetornoActivo(int $oficialId): ?array
    {
        return $this->model->queryOne("
            SELECT r.*, mo.seccion_id AS seccion_destino_id, g.nombre_display AS grado_destino, s.nombre AS seccion_destino
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
     *
     * `$noCerrados = true` (lo usa la REVERSIÓN, 05/10/2026) amplía de los
     * periodos `activo` a todos los que no están `cerrado`: los bimestres se
     * solapan y el siguiente puede tener evaluación antes de que cierre este.
     */
    private function evaluacionEnBimestreActivo(int $matriculaId, bool $noCerrados = false): array
    {
        $condEstado = $noCerrados ? "p.estado <> 'cerrado'" : "p.estado = 'activo'";

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
            WHERE {$condEstado}
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
