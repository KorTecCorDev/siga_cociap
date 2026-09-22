<?php

namespace App\Models;

/**
 * OrdenMeritoModel
 * Fuente única de verdad del ranking del orden de mérito: query de promedios +
 * cascada de desempate por regularidad + resolución manual.
 *
 * La usan tanto Director\OrdenMeritoController (ranking y reporte) como el buscador
 * de estudiantes (puesto por estudiante), para que el puesto sea idéntico en todos
 * lados. La cascada vivía antes en el controller; se extrajo aquí para no duplicarla.
 *
 * Escala COCIAP: AD 17-20 · A 14-16 · B 11-13 · C 0-10.
 */
class OrdenMeritoModel extends BaseModel
{
    protected string $table = 'matriculas';

    /**
     * PUNTO ÚNICO del ROSTER del orden de mérito: quién PERTENECE al documento.
     * Se interpola (alias `m` = matriculas) en las 5 consultas del modelo que
     * enumeran competidores o grados con ranking. No confundir con el filtro de
     * QUÉ NOTAS suman (bloqueadas, no extraordinarias, áreas no exoneradas), que
     * es otra pregunta y vive en cada query.
     *
     * REGLA VIGENTE (12/08/2026, decisión del usuario): al orden de mérito solo
     * entran las matrículas APROBADAS. Un `pendiente` —el estado en que NACE toda
     * matrícula (`MatriculaModel::crear`) y en el que se queda el registro
     * provisional mientras falte el DNI— se sigue calificando y recibe boleta,
     * pero NO compite: el orden de mérito es un documento que se firma y se
     * archiva, y un alumno sin matrícula regularizada no puede figurar en él.
     *
     * > Deroga la Fase A del 24/07/2026, que había cambiado `estado='aprobada'`
     * > por el filtro de `tipo` para alinear el mérito con los rosters de
     * > evaluación. Esa alineación se mantiene en TODO lo demás (calificaciones,
     * > asistencia, conducta, transversales, tutoría siguen filtrando solo por
     * > `tipo`): pertenecer al documento oficial y ser evaluado son dos preguntas
     * > distintas, y desde hoy tienen dos respuestas distintas.
     *
     * SE CONSERVA el filtro por `tipo` además del de `estado`, y no es redundante:
     * `retirar()` exige que la matrícula ya esté `desactivado`, así que sin él la
     * excepción del retorno revertido (abajo) podría reingresar a un RETIRADO.
     *
     * EXCEPCIÓN — la operativa de un retorno de grado REVERTIDO. Al revertir, esa
     * matrícula queda `desactivado` (`RetornoGradoController::revertir`) pero
     * conserva su `tipo`, y es donde viven las notas de los bimestres que el
     * alumno cursó en el grado inferior. Sin la excepción se caería del ranking de
     * un bimestre que sí cursó, rompiendo el anclaje por bimestre. Es el `OR
     * revertido` explícito que la Fase A había podido eliminar cuando el criterio
     * era el `tipo`. (La operativa de un retorno ACTIVO no la necesita: nace
     * `aprobada` a propósito, ver `RetornoGradoController::guardar`.)
     */
    private const ROSTER_MERITO = "
        m.tipo NOT IN ('trasladado', 'retirado')
        AND (
            m.estado = 'aprobada'
            OR m.id IN (
                SELECT r.matricula_operativa_id
                FROM retornos_grado r
                WHERE r.estado = 'revertido'
            )
        )
    ";

    /**
     * Umbral de "estudiante en riesgo": cuántas competencias en C hacen que un
     * estudiante entre en la lista de `statsPorGrado` (decisión del usuario,
     * 04/09/2026). Es un umbral de PRESENTACIÓN, no de la escala: la escala vive
     * en `helpers.php` y aquí solo se cuenta cuántas notas caen por debajo de
     * ella. Quién es "C" lo decide `num_c` en las dos queries del ranking.
     *
     * ⚠️ NO confundir con el "en riesgo" de `AnioAcademicoModel::getResumenBimestre`,
     * que es otra pregunta: allí es el PROMEDIO GENERAL por debajo de NOTA_MIN_B,
     * contado por nivel. Las dos cifras conviven en `/admin/cuadros` y son
     * legítimamente distintas; el pie de cada bloque lo explica.
     */
    public const RIESGO_MIN_C = 3;

    private DesempateMeritoModel $desempateModel;
    private PublicacionBoletaModel $publicacionModel;

    /** Memo de debeUsarSnapshot() por periodo (constante dentro de la request). */
    private array $usaSnapshot = [];

    public function __construct()
    {
        parent::__construct();
        $this->desempateModel   = new DesempateMeritoModel();
        $this->publicacionModel = new PublicacionBoletaModel();
    }

    /**
     * Ranking de un grado en un periodo (todas las secciones juntas), con la cascada
     * de desempate aplicada. Cada fila trae: puesto, media_beca, empate_pendiente,
     * empate_clave, además de las métricas. Excluye competencias transversales.
     *
     * Snapshot-aware: si el periodo está CERRADO y tiene snapshot, devuelve el
     * ranking CONGELADO (documento oficial inmutable). Si no, lo calcula en vivo.
     */
    public function rankingGrado(int $gradoId, int $periodoId): array
    {
        if ($this->debeUsarSnapshot($periodoId)) {
            return $this->rankingGradoDesdeSnapshot($gradoId, $periodoId);
        }
        return $this->rankingGradoLive($gradoId, $periodoId);
    }

    /**
     * ¿El grado tiene algún empate irreducible SIN resolver, calculado EN VIVO?
     * Ignora el snapshot a propósito: se usa tras una rectificación de notas
     * (periodo ya cerrado y con snapshot) para avisar si la corrección introdujo
     * un empate que el director debe resolver antes de regenerar el documento.
     */
    public function gradoTieneEmpateLivePendiente(int $gradoId, int $periodoId): bool
    {
        foreach ($this->rankingGradoLive($gradoId, $periodoId) as $fila) {
            if (!empty($fila['empate_pendiente'])) {
                return true;
            }
        }
        return false;
    }

    /** Cálculo EN VIVO del ranking de grado (fuente de la vista activa y del snapshot). */
    private function rankingGradoLive(int $gradoId, int $periodoId): array
    {
        $estudiantes = $this->query("
            SELECT
                m.id AS matricula_id,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                p.dni,
                s.nombre AS seccion_nombre,
                COUNT(cal.nota_numerica)            AS num_competencias,
                SUM(cal.nota_numerica)             AS total_notas,
                ROUND(AVG(cal.nota_numerica), 2)   AS promedio_general,
                AVG(cal.nota_numerica)             AS promedio_exacto,
                SUM(cal.nota_numerica <= 10)               AS num_c,
                SUM(cal.nota_numerica BETWEEN 11 AND 13)   AS num_b,
                SUM(cal.nota_numerica >= " . NOTA_MIN_AD . ")               AS num_ad,
                SUM(cal.nota_numerica IN (15, 16))         AS num_alto,
                SUM(cal.nota_numerica = 16)                AS num_16
            FROM matriculas m
            INNER JOIN estudiantes e      ON e.id  = m.estudiante_id
            INNER JOIN personas p         ON p.id  = e.persona_id
            INNER JOIN secciones s        ON s.id  = m.seccion_id
            INNER JOIN grados g           ON g.id  = s.grado_id
            INNER JOIN calificaciones cal ON cal.matricula_id = m.id
            -- P2 (rediseño 2): el mérito en vivo solo cuenta competencias
            -- BLOQUEADAS (aprobadas por el docente o forzadas por el cierre).
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            INNER JOIN competencias comp  ON comp.id = cal.competencia_id
            LEFT  JOIN subareas sa        ON sa.id   = comp.subarea_id
            INNER JOIN areas a            ON a.id    = COALESCE(sa.area_id, comp.area_id)
            WHERE g.id           = ?
              AND cal.periodo_id = ?
              -- Las calificaciones EXTRAORDINARIAS (insertadas por RA vía
              -- Rectificación, migración 042) NO cuentan para el mérito:
              -- van a boleta y SIAGIE, pero no mueven puestos.
              AND cal.extraordinaria = 0
              -- ROSTER del documento (quién compite). PUNTO ÚNICO: la condición
              -- vive en self::ROSTER_MERITO y la comparten las 5 consultas del
              -- modelo. Solo compiten las matrículas APROBADAS (12/08/2026).
              AND (" . self::ROSTER_MERITO . ")
              -- Anclaje por bimestre: el alumno compite donde están sus notas de
              -- ESE periodo. Se excluye la OFICIAL cuando su operativa cubrió este
              -- periodo (retorno activo siempre; revertido solo en sus bimestres).
              AND m.id NOT IN (
                  SELECT matricula_oficial_id FROM retornos_grado WHERE estado = 'activo'
                  UNION
                  SELECT r.matricula_oficial_id
                  FROM retornos_grado r
                  INNER JOIN calificaciones c2
                      ON c2.matricula_id = r.matricula_operativa_id
                     AND c2.periodo_id   = ?
                  WHERE r.estado = 'revertido'
              )
              -- El mérito excluye las áreas 'transversal' y 'tutoria', con UNA
              -- excepción: ÉTICA Y VALORES cuenta en TODA secundaria, 5.º incluido
              -- (decisión del usuario, 05/08/2026).
              --
              -- POR QUÉ: Ética NO es tutoría. Es la Educación Religiosa de secundaria,
              -- servida por la carga TOE porque el área Ed. Religiosa de ese nivel es un
              -- cascarón (0 cargas, 0 notas). Su `tipo='tutoria'` es un artefacto de
              -- implementación, no una afirmación curricular. Sin la excepción, el MISMO
              -- curso pesaba en el promedio en primaria (área-curso normal) y no pesaba
              -- en secundaria.
              --
              -- Deroga la regla del 04/08 que la sacaba de 5.º: aquella listaba Etica y
              -- Valores y Educacion Religiosa como areas distintas, siendo la misma.
              --
              -- SE ANCLA POR `nombre_boleta`, NUNCA POR ID (difiere entre entornos, y el
              -- id 57 es GAMA mientras que el código C57 es la competencia de Ética).
              -- El ancla es precisa: solo el área 24 lleva ese nombre_boleta, y la TOE de
              -- primaria no tiene competencias, así que no puede colarse.
              AND (a.tipo NOT IN ('transversal', 'tutoria')
                   OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "')
              -- ÁREA (o SUBÁREA) EXONERADA: fuera del cálculo (05/08/2026).
              -- Desde que se puede exonerar a un alumno QUE YA TIENE NOTAS, sus
              -- notas siguen vivas en `calificaciones` —no se borran, para que la
              -- exoneración sea reversible— pero la boleta muestra EXO. Sin este
              -- filtro el mérito seguiría promediándolas: el documento diría EXO
              -- y el ranking contaría la nota.
              -- Cubre las dos formas de exonerar: por ÁREA (a.id ya viene
              -- resuelta con COALESCE, así que alcanza a sus subáreas) y por
              -- SUBÁREA suelta. Los NULL no generan falsos positivos: una
              -- exoneración de área tiene subarea_id NULL y `NULL = x` es NULL.
              -- NO afecta a los snapshots ya guardados (el de B1 es inmutable):
              -- esto es el cálculo EN VIVO.
              AND NOT EXISTS (
                  SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = cal.matricula_id
                    AND ex.revocado_en IS NULL
                    AND (ex.area_id = a.id OR ex.subarea_id = comp.subarea_id)
              )
            GROUP BY m.id, p.apellido_paterno, p.apellido_materno,
                     p.nombres, p.dni, s.nombre
            ORDER BY promedio_exacto DESC, num_c ASC, num_b ASC, num_ad DESC,
                     num_alto DESC, num_16 DESC,
                     -- P1 (rediseño 2): tras num_16 el desempate es MANUAL; el
                     -- apellido ya no dirime. Orden estable neutro por matricula.
                     m.id
        ", [$gradoId, $periodoId, $periodoId]);

        return $this->aplicarDesempate($estudiantes, $periodoId);
    }

    /**
     * Ranking por sección dentro del grado, con la cascada aplicada por sección.
     * Retorna [seccion_nombre => filas con puesto]. Si $limite > 0 corta al top-N.
     *
     * Snapshot-aware: periodo CERRADO con snapshot → ranking congelado.
     */
    public function rankingPorSeccion(int $gradoId, int $periodoId, int $limite = 0): array
    {
        if ($this->debeUsarSnapshot($periodoId)) {
            return $this->rankingPorSeccionDesdeSnapshot($gradoId, $periodoId, $limite);
        }
        return $this->rankingPorSeccionLive($gradoId, $periodoId, $limite);
    }

    /** Cálculo EN VIVO del ranking por sección (fuente de la vista activa y del snapshot). */
    private function rankingPorSeccionLive(int $gradoId, int $periodoId, int $limite = 0): array
    {
        $filas = $this->query("
            SELECT
                m.id AS matricula_id,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                s.id     AS seccion_id,
                s.nombre AS seccion_nombre,
                COUNT(cal.nota_numerica)            AS num_competencias,
                SUM(cal.nota_numerica)             AS total_notas,
                ROUND(AVG(cal.nota_numerica), 2)   AS promedio_general,
                AVG(cal.nota_numerica)             AS promedio_exacto,
                SUM(cal.nota_numerica <= 10)               AS num_c,
                SUM(cal.nota_numerica BETWEEN 11 AND 13)   AS num_b,
                SUM(cal.nota_numerica >= " . NOTA_MIN_AD . ")               AS num_ad,
                SUM(cal.nota_numerica IN (15, 16))         AS num_alto,
                SUM(cal.nota_numerica = 16)                AS num_16
            FROM matriculas m
            INNER JOIN estudiantes e      ON e.id  = m.estudiante_id
            INNER JOIN personas p         ON p.id  = e.persona_id
            INNER JOIN secciones s        ON s.id  = m.seccion_id
            INNER JOIN grados g           ON g.id  = s.grado_id
            INNER JOIN calificaciones cal ON cal.matricula_id = m.id
            -- P2 (rediseño 2): el mérito en vivo solo cuenta competencias
            -- BLOQUEADAS (aprobadas por el docente o forzadas por el cierre).
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            INNER JOIN competencias comp  ON comp.id = cal.competencia_id
            LEFT  JOIN subareas sa        ON sa.id   = comp.subarea_id
            INNER JOIN areas a            ON a.id    = COALESCE(sa.area_id, comp.area_id)
            WHERE g.id           = ?
              AND cal.periodo_id = ?
              -- Extraordinarias fuera del mérito (ver rankingGradoLive).
              AND cal.extraordinaria = 0
              -- ROSTER del documento (ver rankingGradoLive y self::ROSTER_MERITO).
              AND (" . self::ROSTER_MERITO . ")
              AND m.id NOT IN (
                  SELECT matricula_oficial_id FROM retornos_grado WHERE estado = 'activo'
                  UNION
                  SELECT r.matricula_oficial_id
                  FROM retornos_grado r
                  INNER JOIN calificaciones c2
                      ON c2.matricula_id = r.matricula_operativa_id
                     AND c2.periodo_id   = ?
                  WHERE r.estado = 'revertido'
              )
              -- El mérito excluye las áreas 'transversal' y 'tutoria', con UNA
              -- excepción: ÉTICA Y VALORES cuenta en TODA secundaria, 5.º incluido
              -- (decisión del usuario, 05/08/2026).
              --
              -- POR QUÉ: Ética NO es tutoría. Es la Educación Religiosa de secundaria,
              -- servida por la carga TOE porque el área Ed. Religiosa de ese nivel es un
              -- cascarón (0 cargas, 0 notas). Su `tipo='tutoria'` es un artefacto de
              -- implementación, no una afirmación curricular. Sin la excepción, el MISMO
              -- curso pesaba en el promedio en primaria (área-curso normal) y no pesaba
              -- en secundaria.
              --
              -- Deroga la regla del 04/08 que la sacaba de 5.º: aquella listaba Etica y
              -- Valores y Educacion Religiosa como areas distintas, siendo la misma.
              --
              -- SE ANCLA POR `nombre_boleta`, NUNCA POR ID (difiere entre entornos, y el
              -- id 57 es GAMA mientras que el código C57 es la competencia de Ética).
              -- El ancla es precisa: solo el área 24 lleva ese nombre_boleta, y la TOE de
              -- primaria no tiene competencias, así que no puede colarse.
              AND (a.tipo NOT IN ('transversal', 'tutoria')
                   OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "')
              -- ÁREA (o SUBÁREA) EXONERADA: fuera del cálculo (05/08/2026).
              -- Desde que se puede exonerar a un alumno QUE YA TIENE NOTAS, sus
              -- notas siguen vivas en `calificaciones` —no se borran, para que la
              -- exoneración sea reversible— pero la boleta muestra EXO. Sin este
              -- filtro el mérito seguiría promediándolas: el documento diría EXO
              -- y el ranking contaría la nota.
              -- Cubre las dos formas de exonerar: por ÁREA (a.id ya viene
              -- resuelta con COALESCE, así que alcanza a sus subáreas) y por
              -- SUBÁREA suelta. Los NULL no generan falsos positivos: una
              -- exoneración de área tiene subarea_id NULL y `NULL = x` es NULL.
              -- NO afecta a los snapshots ya guardados (el de B1 es inmutable):
              -- esto es el cálculo EN VIVO.
              AND NOT EXISTS (
                  SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = cal.matricula_id
                    AND ex.revocado_en IS NULL
                    AND (ex.area_id = a.id OR ex.subarea_id = comp.subarea_id)
              )
            GROUP BY m.id, p.apellido_paterno, p.apellido_materno,
                     p.nombres, s.id, s.nombre
            ORDER BY s.nombre, promedio_exacto DESC, num_c ASC, num_b ASC, num_ad DESC,
                     num_alto DESC, num_16 DESC,
                     -- P1 (rediseño 2): tras num_16 el desempate es MANUAL; el
                     -- apellido ya no dirime. Orden estable neutro por matricula.
                     m.id
        ", [$gradoId, $periodoId, $periodoId]);

        $porSeccion = [];
        foreach ($filas as $fila) {
            $porSeccion[$fila['seccion_nombre']][] = $fila;
        }

        $secciones = [];
        foreach ($porSeccion as $sec => $estudiantes) {
            $rankeados = $this->aplicarDesempate($estudiantes, $periodoId);
            $secciones[$sec] = $limite > 0
                ? array_slice($rankeados, 0, $limite)
                : $rankeados;
        }

        return $secciones;
    }

    // ── Snapshot del orden de mérito (documento oficial congelado) ───────────

    /**
     * ¿El periodo debe leerse del SNAPSHOT? Hace falta que YA tenga filas
     * grabadas (antes del backfill, con la tabla vacía, todo cae al cálculo en
     * vivo) y además una de dos condiciones:
     *   - el periodo está 'cerrado' (documento oficial vigente), o
     *   - el periodo YA FUE PUBLICADO alguna vez (candado de la migración 046).
     *
     * La segunda condición cubre la REAPERTURA (rediseño 2): al reabrir un
     * bimestre publicado su estado deja de ser 'cerrado', pero el orden de mérito
     * oficial ya salió a las familias y es INMUTABLE. Sin esto, el claustro y las
     * familias verían un cálculo en vivo distinto del documento entregado. El
     * efecto de las correcciones se ve al re-cerrar, en la versión rectificada de
     * /admin/control. Mismo criterio monotónico (`fuePublicado`) que usa
     * registrarRanking para decidir oficial vs. rectificado.
     *
     * Memoizado por periodo: rankingGrado se llama en bucle (un grado por
     * iteración) y esta decisión es constante dentro de la request.
     */
    private function debeUsarSnapshot(int $periodoId): bool
    {
        if (isset($this->usaSnapshot[$periodoId])) {
            return $this->usaSnapshot[$periodoId];
        }

        $cerradoConSnapshot = $this->queryOne("
            SELECT 1
            FROM periodos pe
            WHERE pe.id = ? AND pe.estado = 'cerrado'
              AND EXISTS (SELECT 1 FROM orden_merito_snapshot s WHERE s.periodo_id = pe.id)
            LIMIT 1
        ", [$periodoId]) !== null;

        if ($cerradoConSnapshot) {
            return $this->usaSnapshot[$periodoId] = true;
        }

        // Reabierto: manda el snapshot si el bimestre ya estuvo publicado.
        $tieneSnapshot = $this->queryOne("
            SELECT 1 FROM orden_merito_snapshot WHERE periodo_id = ? LIMIT 1
        ", [$periodoId]) !== null;

        return $this->usaSnapshot[$periodoId] =
            $tieneSnapshot && $this->publicacionModel->fuePublicado($periodoId);
    }

    /** Ranking de grado CONGELADO (lee del snapshot). Mismo shape que el vivo. */
    private function rankingGradoDesdeSnapshot(int $gradoId, int $periodoId): array
    {
        $filas = $this->query("
            SELECT
                s.matricula_id,
                p.apellido_paterno, p.apellido_materno, p.nombres, p.dni,
                s.seccion_id,
                sec.nombre AS seccion_nombre,
                s.num_competencias, s.total_notas,
                s.promedio_general, s.promedio_exacto,
                s.num_c, s.num_b, s.num_ad, s.num_alto, s.num_16,
                s.puesto_grado AS puesto
            FROM orden_merito_snapshot s
            INNER JOIN matriculas m  ON m.id = s.matricula_id
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            LEFT  JOIN secciones sec ON sec.id = s.seccion_id
            WHERE s.periodo_id = ? AND s.grado_id = ?
            ORDER BY s.puesto_grado
        ", [$periodoId, $gradoId]);

        return $this->normalizarSnapshot($filas);
    }

    /** Ranking por sección CONGELADO (lee del snapshot). [seccion_nombre => filas]. */
    private function rankingPorSeccionDesdeSnapshot(int $gradoId, int $periodoId, int $limite = 0): array
    {
        $filas = $this->query("
            SELECT
                s.matricula_id,
                p.apellido_paterno, p.apellido_materno, p.nombres, p.dni,
                s.seccion_id,
                sec.nombre AS seccion_nombre,
                s.num_competencias, s.total_notas,
                s.promedio_general, s.promedio_exacto,
                s.num_c, s.num_b, s.num_ad, s.num_alto, s.num_16,
                s.puesto_seccion AS puesto
            FROM orden_merito_snapshot s
            INNER JOIN matriculas m  ON m.id = s.matricula_id
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            LEFT  JOIN secciones sec ON sec.id = s.seccion_id
            WHERE s.periodo_id = ? AND s.grado_id = ? AND s.puesto_seccion IS NOT NULL
            ORDER BY sec.nombre, s.puesto_seccion
        ", [$periodoId, $gradoId]);

        $porSeccion = [];
        foreach ($this->normalizarSnapshot($filas) as $f) {
            $porSeccion[$f['seccion_nombre']][] = $f;
        }
        if ($limite > 0) {
            foreach ($porSeccion as $sec => $rows) {
                $porSeccion[$sec] = array_slice($rows, 0, $limite);
            }
        }
        return $porSeccion;
    }

    /**
     * Normaliza filas del snapshot al mismo shape que el ranking en vivo:
     * puesto entero, media_beca (1º del grupo), y sin empates pendientes
     * (todos se resolvieron antes de cerrar, por eso el snapshot es definitivo).
     */
    private function normalizarSnapshot(array $filas): array
    {
        foreach ($filas as &$f) {
            $f['puesto']           = (int) $f['puesto'];
            $f['media_beca']       = ($f['puesto'] === 1);
            $f['empate_pendiente'] = false;
            $f['empate_clave']     = null;
        }
        unset($f);
        return $filas;
    }

    /**
     * Grados con ranking en un periodo (id, numero, nombre, nivel). Snapshot-aware:
     * para un periodo cerrado con snapshot enumera desde él (así incluye grados
     * "congelados" como la sección operativa de un retorno que ya no existe en el
     * estado actual de las matrículas). Para el resto, los grados con notas en vivo.
     */
    public function gradosConRanking(int $periodoId): array
    {
        if ($this->debeUsarSnapshot($periodoId)) {
            return $this->query("
                SELECT DISTINCT g.id, g.numero, g.nombre_display,
                       n.id AS nivel_id, n.nombre AS nivel_nombre, n.codigo AS nivel_codigo
                FROM orden_merito_snapshot s
                INNER JOIN grados g  ON g.id = s.grado_id
                INNER JOIN niveles n ON n.id = g.nivel_id
                WHERE s.periodo_id = ?
                ORDER BY n.id, g.numero
            ", [$periodoId]);
        }

        return $this->query("
            SELECT DISTINCT g.id, g.numero, g.nombre_display,
                   n.id AS nivel_id, n.nombre AS nivel_nombre, n.codigo AS nivel_codigo
            FROM matriculas m
            INNER JOIN secciones s        ON s.id  = m.seccion_id
            INNER JOIN grados g           ON g.id  = s.grado_id
            INNER JOIN niveles n          ON n.id  = g.nivel_id
            INNER JOIN calificaciones cal ON cal.matricula_id = m.id
            -- P2 (rediseño 2): enumerar solo grados con notas BLOQUEADAS,
            -- mismo universo que el ranking en vivo.
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            WHERE cal.periodo_id = ?
              AND (" . self::ROSTER_MERITO . ")
            ORDER BY n.id, g.numero
        ", [$periodoId]);
    }

    /**
     * Indicadores por grado del bimestre, calculados SOBRE EL MOTOR OFICIAL:
     * primer puesto, los de menor rendimiento, total de competidores y los
     * estudiantes EN RIESGO (los que acumulan `$minC` competencias en C o más).
     *
     * 🔴 NACIÓ PARA MATAR UNA COPIA (04/09/2026). Hasta hoy estos indicadores los
     * calculaba `AnioAcademicoModel::getRankingGrado`, un ranking PARALELO que no
     * exigía competencias bloqueadas, no excluía extraordinarias ni áreas
     * exoneradas, metía la TOE entera en vez de solo Ética, y no aplicaba
     * ROSTER_MERITO, el anclaje de retorno ni la cascada de desempate. Medido en
     * la BD del 04/09: en el bimestre ABIERTO anunciaba un 1.er puesto de 1.º
     * primaria con 22 competidores donde el orden de mérito tiene CERO (nada
     * bloqueado todavía), y el último puesto de 1.º secundaria salía 12.50 cuando
     * el real es 15.00. En bimestre cerrado las dos fuentes coincidían, así que
     * el defecto no tenía síntoma para quien mirara un bimestre ya cerrado.
     * Es el mismo patrón que ya costó los 130 bloqueos fantasma y la card de
     * empates irresolubles: una regla copiada a mano que devuelve un número
     * plausible y falso.
     *
     * Vive AQUÍ, en el modelo dueño del ranking, y no en quien lo pinta.
     * `AnioAcademicoModel::getStatsCierre` es hoy una fachada sobre este método.
     *
     * UN SOLO RECORRIDO de los grados: `rankingGrado` es snapshot-aware pero no
     * está memoizado, y llamarlo dos veces por grado (una para el mérito, otra
     * para el riesgo) duplicaría 11 consultas pesadas por render.
     *
     * @return array<int, array{grado:array, mejor:array, peores:array, total:int, en_riesgo:array}>
     */
    public function statsPorGrado(int $periodoId, int $minC = self::RIESGO_MIN_C): array
    {
        $porGrado = [];

        foreach ($this->gradosConRanking($periodoId) as $grado) {
            $ranking = $this->rankingGrado((int) $grado['id'], $periodoId);
            if (empty($ranking)) {
                continue;
            }

            // El motor no compone el nombre (trabaja con las tres columnas por
            // separado para poder ordenar con COLLATE_ES). Las vistas sí lo
            // esperan, y es el mismo formato que ya usaba el ranking anterior.
            foreach ($ranking as &$fila) {
                $fila['nombre_completo'] = $fila['apellido_paterno'] . ' '
                    . $fila['apellido_materno'] . ', ' . $fila['nombres'];

                // `num_a` NO se consulta: se DERIVA. Los cuatro literales son
                // disjuntos y exhaustivos sobre 00-20 —AD (>= NOTA_MIN_AD),
                // A (14-17), B (11-13), C (<= 10)—, así que A es lo que queda al
                // restar los otros tres del total de competencias contadas.
                //
                // Se hace AQUÍ, en el modelo dueño, y no en la vista, por dos
                // motivos: es la regla de la escala (helpers.php), y así sale
                // igual por las DOS rutas de `rankingGrado` —en vivo y snapshot—,
                // que traen las mismas cuatro columnas. Cero consultas añadidas:
                // esta clase promete un solo recorrido de los grados.
                //
                // ⚠️ No usar `num_alto` para esto: (15,16) SOLAPA con A, es un
                // desempate, no un tramo de la escala.
                $fila['num_a'] = (int) $fila['num_competencias']
                               - (int) $fila['num_ad']
                               - (int) $fila['num_b']
                               - (int) $fila['num_c'];
            }
            unset($fila);

            $mejor = $ranking[0];

            // Los 2 de menor rendimiento, excluyendo al primer puesto (un grado
            // de un solo estudiante no tiene "peores"). Regla anterior, intacta.
            $peores = array_values(array_filter(
                array_slice($ranking, -2),
                static fn($e) => (int) $e['matricula_id'] !== (int) $mejor['matricula_id']
            ));

            // EN RIESGO: todos los que llegan al umbral de C, sin tope por grado
            // (decisión del usuario, 04/09/2026). `num_c` ya viene calculado por
            // el ranking sobre el universo del mérito, así que esta lista NO
            // cuesta ninguna consulta y no puede desincronizarse del promedio y
            // del puesto que se muestran en su misma fila.
            $enRiesgo = array_values(array_filter(
                $ranking,
                static fn($e) => (int) $e['num_c'] >= $minC
            ));

            // Manda el número de C; a igual número, primero el peor promedio.
            usort($enRiesgo, static function ($a, $b) {
                return [(int) $b['num_c'], (float) $a['promedio_exacto'], (int) $a['puesto']]
                   <=> [(int) $a['num_c'], (float) $b['promedio_exacto'], (int) $b['puesto']];
            });

            $porGrado[] = [
                'grado'     => $grado,
                'mejor'     => $mejor,
                'peores'    => $peores,
                'total'     => count($ranking),
                'en_riesgo' => $enRiesgo,
            ];
        }

        // ── Desglose de las C, en UNA sola consulta para todos los grados ──
        // Va aquí y no dentro del bucle a propósito: una consulta por grado
        // serían 11 más por render, y este método promete un solo recorrido.
        //
        // 🔴 EL GUARD DEL DESCUADRE. `detalleCompetenciasC` calcula EN VIVO y la
        // fila puede venir del SNAPSHOT de un bimestre cerrado, que solo guarda
        // agregados. Si las dos cifras no coinciden se deja `detalle_c` en NULL
        // —que la vista lee como "no se puede mostrar"— en vez de pintar un
        // desglose que contradice a su propia fila. NULL y [] significan cosas
        // distintas y la vista las distingue: [] es "no tiene C que mostrar",
        // NULL es "no cuadra con el dato oficial".
        //
        // Medido el 07/09/2026: cuadra en 194 de 195 alumnos (falla la matrícula
        // 530 de B1, por un desbloqueo posterior al cierre).
        $ids = [];
        foreach ($porGrado as $g) {
            foreach ($g['en_riesgo'] as $al) {
                $ids[] = (int) $al['matricula_id'];
            }
        }

        $detalle = $this->detalleCompetenciasC($ids, $periodoId);

        foreach ($porGrado as &$g) {
            foreach ($g['en_riesgo'] as &$al) {
                $suyas = $detalle[(int) $al['matricula_id']] ?? [];
                $al['detalle_c'] = count($suyas) === (int) $al['num_c'] ? $suyas : null;
            }
            unset($al);
        }
        unset($g);

        return $porGrado;
    }

    /**
     * Detalle de las competencias en C de un conjunto de matrículas (07/09/2026).
     *
     * Alimenta el desglose plegable de «Estudiantes en riesgo» en `/admin/cuadros`:
     * la fila dice cuántas C tiene el estudiante, y esto dice CUÁLES y de qué
     * docente dependen.
     *
     * 🔴 REPLICA EL UNIVERSO DEL MÉRITO, y esa es toda la razón de que viva en
     * este modelo y no en `CalificacionModel`. Si el desglose se sacara de
     * `getBoletaAlumno` —que incluye extraordinarias, tutoría entera y las
     * transversales agregadas— listaría notas que la fila no cuenta y las dos
     * cifras se contradirían en pantalla, sin ningún error. Es el patrón de
     * fallo que ya costó cuatro reglas divergentes en este repositorio.
     *
     * ⚠️ REPLICA LOS FILTROS DE LA NOTA, **NO** LOS DEL ALUMNO. Van aquí:
     * bloqueo, `extraordinaria = 0`, transversal/tutoría salvo Ética y
     * exoneraciones. NO van `ROSTER_MERITO` ni el anclaje de retorno, y es
     * deliberado: esos dos deciden QUIÉN compite, y a este método ya se le
     * entrega la lista de matrículas resuelta por el ranking. Volver a
     * aplicarlos solo podría QUITAR filas de una matrícula ya seleccionada
     * —justo lo que pasaría con una fila que viene del SNAPSHOT de un bimestre
     * cerrado y que hoy ya no pasaría el roster— y produciría un descuadre
     * falso: la fila diría 5 C y el desglose no mostraría ninguna.
     *
     * ⚠️ NO HAY DETALLE CONGELADO. `orden_merito_snapshot` guarda solo los
     * agregados, así que en un bimestre cerrado la fila viene del snapshot y
     * esto se calcula EN VIVO. Medido el 07/09/2026: diverge 1 de 195 alumnos
     * (B1, matrícula 530, por un desbloqueo posterior al cierre). Quien pinte
     * esto DEBE comparar el número de filas con `num_c` y callar el desglose si
     * no cuadran, en vez de enseñar dos cifras que se contradicen.
     *
     * UNA SOLA CONSULTA para todas las matrículas, con `IN`: `statsPorGrado`
     * promete un solo recorrido de los grados, y una consulta por alumno serían
     * 118 en el peor bimestre medido. Misma forma que
     * `DesempateMeritoModel::getComparativoCompetencias`.
     *
     * El DOCENTE sale de `cal.carga_id`, un camino que el mérito no usa para
     * calcular. Es seguro porque el INNER JOIN a `bloqueos_competencia` ya fijó
     * la carga dueña: medido, dentro de este universo hay CERO competencias con
     * dos cargas (fuera de él hay 1 072, y 1 052 notas en cargas inactivas).
     *
     * @param  int[] $matriculaIds
     * @return array<int, array<int, array{area:string,curso:?string,competencia:string,codigo:?string,nota:int,docente:string}>>
     */
    public function detalleCompetenciasC(array $matriculaIds, int $periodoId): array
    {
        $ids = array_values(array_unique(array_map('intval', $matriculaIds)));
        if (empty($ids)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));

        $filas = $this->query("
            SELECT
                cal.matricula_id,
                cal.nota_numerica,
                a.nombre        AS area_nombre,
                sa.nombre       AS subarea_nombre,
                -- `nombre_corto` en una tabla de 778 filas (B1): el completo
                -- promedia 64 caracteres contra 43 y aqui la columna compite
                -- con area, curso y docente. Esta poblado en las 59
                -- competencias, pero el COALESCE cubre el dia que nazca una sin
                -- el, en vez de dejar la celda vacia.
                COALESCE(NULLIF(comp.nombre_corto, ''), comp.nombre_completo) AS competencia_nombre,
                comp.codigo_minedu,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres
            FROM calificaciones cal
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            INNER JOIN competencias comp ON comp.id = cal.competencia_id
            LEFT  JOIN subareas sa       ON sa.id   = comp.subarea_id
            INNER JOIN areas a           ON a.id    = COALESCE(sa.area_id, comp.area_id)
            -- El docente de la carga DUEÑA del bloqueo. LEFT porque el nombre es
            -- informativo: una carga sin usuario no puede borrar una nota en C.
            LEFT  JOIN cargas_academicas ca ON ca.id = cal.carga_id
            LEFT  JOIN usuarios u           ON u.id  = ca.docente_id
            LEFT  JOIN personas p           ON p.id  = u.persona_id
            WHERE cal.matricula_id IN ($ph)
              AND cal.periodo_id     = ?
              AND cal.nota_numerica <= " . (NOTA_MIN_B - 1) . "
              AND cal.extraordinaria = 0
              AND (a.tipo NOT IN ('transversal', 'tutoria')
                   OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "')
              AND NOT EXISTS (
                  SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = cal.matricula_id
                    AND ex.revocado_en IS NULL
                    AND (ex.area_id = a.id OR ex.subarea_id = comp.subarea_id)
              )
            ORDER BY a.orden, comp.orden, comp.id
        ", array_merge($ids, [$periodoId]));

        $porMatricula = [];
        foreach ($filas as $f) {
            $docente = trim($f['apellido_paterno'] . ' ' . $f['apellido_materno']
                          . ($f['nombres'] !== null ? ', ' . $f['nombres'] : ''));

            $porMatricula[(int) $f['matricula_id']][] = [
                'area'        => (string) $f['area_nombre'],
                // El "curso" es la subárea cuando existe (Álgebra, Física...);
                // si no, el área ya ES el curso (área-curso).
                'curso'       => $f['subarea_nombre'] !== null ? (string) $f['subarea_nombre'] : null,
                'competencia' => (string) $f['competencia_nombre'],
                'codigo'      => $f['codigo_minedu'] !== null ? (string) $f['codigo_minedu'] : null,
                'nota'        => (int) $f['nota_numerica'],
                'docente'     => $docente !== '' ? $docente : '—',
            ];
        }

        return $porMatricula;
    }

    /**
     * Calcula las filas del ranking de un periodo (puesto por grado + puesto por
     * sección + métricas) listas para persistir. Es la fuente COMÚN del snapshot
     * oficial y de la versión rectificada, para que ambos congelen exactamente el
     * mismo cálculo EN VIVO (con anclaje por bimestre, inmune al estado actual del
     * retorno). No escribe nada: solo devuelve las filas.
     */
    private function calcularFilasRanking(int $periodoId): array
    {
        // Grados con notas en el periodo (incluye operativas de retornos revertidos,
        // que compiten por tipo en los bimestres que cursaron).
        $grados = $this->query("
            SELECT DISTINCT g.id
            FROM matriculas m
            INNER JOIN secciones s        ON s.id  = m.seccion_id
            INNER JOIN grados g           ON g.id  = s.grado_id
            INNER JOIN calificaciones cal ON cal.matricula_id = m.id AND cal.periodo_id = ?
            -- P2 (rediseño 2): solo grados con notas BLOQUEADAS.
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            WHERE (" . self::ROSTER_MERITO . ")
        ", [$periodoId]);

        $filas = [];
        foreach ($grados as $g) {
            $gradoId = (int) $g['id'];
            $general = $this->rankingGradoLive($gradoId, $periodoId);
            if (empty($general)) {
                continue;
            }

            // Puesto y sección por matrícula desde el ranking por sección (todas).
            $secMap = [];
            foreach ($this->rankingPorSeccionLive($gradoId, $periodoId) as $rows) {
                foreach ($rows as $f) {
                    $secMap[(int) $f['matricula_id']] = [
                        'seccion_id'     => isset($f['seccion_id']) ? (int) $f['seccion_id'] : null,
                        'puesto_seccion' => (int) $f['puesto'],
                    ];
                }
            }

            foreach ($general as $f) {
                $mid = (int) $f['matricula_id'];
                $sec = $secMap[$mid] ?? ['seccion_id' => null, 'puesto_seccion' => null];
                $filas[] = [
                    'periodo_id'       => $periodoId,
                    'matricula_id'     => $mid,
                    'grado_id'         => $gradoId,
                    'seccion_id'       => $sec['seccion_id'],
                    'puesto_grado'     => (int) $f['puesto'],
                    'puesto_seccion'   => $sec['puesto_seccion'],
                    'num_competencias' => (int) $f['num_competencias'],
                    'total_notas'      => (int) $f['total_notas'],
                    'promedio_general' => $f['promedio_general'],
                    'promedio_exacto'  => $f['promedio_exacto'],
                    'num_c'            => (int) $f['num_c'],
                    'num_b'            => (int) $f['num_b'],
                    'num_ad'           => (int) $f['num_ad'],
                    'num_alto'         => (int) $f['num_alto'],
                    'num_16'           => (int) $f['num_16'],
                ];
            }
        }

        return $filas;
    }

    /**
     * (Re)genera el snapshot OFICIAL de un periodo: borra el existente e inserta
     * el ranking calculado. Se llama al CERRAR (dentro de su transacción) y en el
     * backfill. OJO: NO honra la inmutabilidad por sí mismo — quien deba respetar
     * "un bimestre publicado no cambia su oficial" usa registrarRanking().
     */
    public function generarSnapshot(int $periodoId, ?int $usuarioId = null): void
    {
        $this->escribirOficial($periodoId, $this->calcularFilasRanking($periodoId), $usuarioId);
    }

    /**
     * Persiste las filas YA CALCULADAS como snapshot oficial. Separado de
     * `generarSnapshot` para que quien ya tiene el cálculo en la mano (la
     * sincronización de roster, que necesita compararlo antes de escribir) no
     * lo repita: `calcularFilasRanking` recorre todos los grados del periodo.
     */
    private function escribirOficial(int $periodoId, array $filas, ?int $usuarioId): void
    {
        $this->execute("DELETE FROM orden_merito_snapshot WHERE periodo_id = ?", [$periodoId]);

        foreach ($filas as $f) {
            $this->execute("
                INSERT INTO orden_merito_snapshot
                    (periodo_id, matricula_id, grado_id, seccion_id,
                     puesto_grado, puesto_seccion,
                     num_competencias, total_notas, promedio_general, promedio_exacto,
                     num_c, num_b, num_ad, num_alto, num_16, generado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ", [
                $f['periodo_id'], $f['matricula_id'], $f['grado_id'], $f['seccion_id'],
                $f['puesto_grado'], $f['puesto_seccion'],
                $f['num_competencias'], $f['total_notas'],
                $f['promedio_general'], $f['promedio_exacto'],
                $f['num_c'], $f['num_b'], $f['num_ad'],
                $f['num_alto'], $f['num_16'], $usuarioId,
            ]);
        }
    }

    /**
     * (Re)genera la versión RECTIFICADA (no oficial) de un periodo. Misma fuente de
     * cálculo que el oficial, pero va a orden_merito_rectificado con su motivo.
     * Sobrescribe la versión anterior del periodo (guardamos la última).
     */
    public function generarSnapshotRectificado(int $periodoId, ?int $usuarioId, string $motivo): void
    {
        $this->escribirRectificado($periodoId, $this->calcularFilasRanking($periodoId), $usuarioId, $motivo);
    }

    /** Persiste filas YA CALCULADAS como versión rectificada (ver escribirOficial). */
    private function escribirRectificado(int $periodoId, array $filas, ?int $usuarioId, string $motivo): void
    {
        $this->execute("DELETE FROM orden_merito_rectificado WHERE periodo_id = ?", [$periodoId]);

        foreach ($filas as $f) {
            $this->execute("
                INSERT INTO orden_merito_rectificado
                    (periodo_id, matricula_id, grado_id, seccion_id,
                     puesto_grado, puesto_seccion,
                     num_competencias, total_notas, promedio_general, promedio_exacto,
                     num_c, num_b, num_ad, num_alto, num_16, generado_por, motivo)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ", [
                $f['periodo_id'], $f['matricula_id'], $f['grado_id'], $f['seccion_id'],
                $f['puesto_grado'], $f['puesto_seccion'],
                $f['num_competencias'], $f['total_notas'],
                $f['promedio_general'], $f['promedio_exacto'],
                $f['num_c'], $f['num_b'], $f['num_ad'],
                $f['num_alto'], $f['num_16'], $usuarioId, $motivo,
            ]);
        }
    }

    /** ¿El periodo ya tiene snapshot OFICIAL grabado? */
    public function tieneSnapshotOficial(int $periodoId): bool
    {
        return $this->queryOne(
            "SELECT 1 FROM orden_merito_snapshot WHERE periodo_id = ? LIMIT 1",
            [$periodoId]
        ) !== null;
    }

    /**
     * PUNTO ÚNICO de escritura del ranking que respeta la INMUTABILIDAD (migr. 046).
     * Si el periodo ya ESTUVO publicado (compuerta 044) y tiene oficial → escribe la
     * versión RECTIFICADA no oficial (el oficial no se toca). Si no → (re)genera el
     * oficial. Lo usan PeriodoController::cerrar y RectificacionController.
     * @return string 'oficial' | 'rectificado' | 'roster_cambiado'
     */
    public function registrarRanking(
        int $periodoId,
        ?int $usuarioId,
        string $motivo,
        bool $exigirMismoRoster = false
    ): string {
        $filas = $this->calcularFilasRanking($periodoId);

        // PERIODO YA PUBLICADO → versión rectificada, y la guarda de roster NO
        // aplica (11/08/2026). Va ANTES de la guarda a propósito: lo que esa
        // guarda protege es el OFICIAL, y aquí el oficial ya es intocable por el
        // candado 046 — no hay nada que proteger. La `orden_merito_rectificado`
        // es una versión de trabajo no oficial cuyo sentido es reflejar el
        // cálculo de hoy, roster incluido.
        //
        // Con el orden inverso, B1 no registraba NADA: su roster diverge por
        // diseño (528 filas del documento reconstruido contra 517 del motor, los
        // 10 `trasladado` + 1 `retirado` que la regla especial reincorporó), así
        // que toda rectificación suya devolvía 'roster_cambiado' y pedía
        // "regularizar la matrícula" — justo lo que en B1 NO se debe hacer.
        if ($this->publicacionModel->fuePublicado($periodoId) && $this->tieneSnapshotOficial($periodoId)) {
            $this->escribirRectificado($periodoId, $filas, $usuarioId, $motivo);
            return 'rectificado';
        }

        // GUARDA DE ROSTER (11/08/2026). Una rectificación responde a "¿cuánto
        // sacó cada uno?", NUNCA a "¿quién pertenece a este documento?". Como
        // `escribirOficial` borra y reinserta el periodo ENTERO, sin esta guarda
        // una corrección de nota en un grado podía añadir o quitar estudiantes
        // en OTRO grado, en silencio: paso de verdad el 11/08: al rectificar tres
        // notas de 4.º de primaria desaparecio del oficial de B2 una alumna de
        // 1.º de secundaria (trasladada 38 min despues del cierre) y 42
        // companeros cambiaron de puesto.
        //
        // Quien SÍ puede mover el roster es `sincronizarRosterPorMatricula`, en
        // el momento del cambio de tipo. Aquí, si el roster no coincide, se
        // ABORTA la reescritura y se informa: mejor un ranking desactualizado y
        // ruidoso que un documento oficial reescrito sin que nadie lo sepa.
        if ($exigirMismoRoster && $this->tieneSnapshotOficial($periodoId)) {
            $difieren = $this->rosterDifiere($periodoId, $filas);
            if ($difieren !== null) {
                log_error('Orden de mérito: roster distinto al del snapshot; no se regeneró', [
                    'periodo' => $periodoId,
                    'motivo'  => $motivo,
                    'sobran'  => $difieren['sobran'],
                    'faltan'  => $difieren['faltan'],
                ]);
                return 'roster_cambiado';
            }
        }

        $this->escribirOficial($periodoId, $filas, $usuarioId);
        return 'oficial';
    }

    /**
     * Compara el roster de unas filas recién calculadas con el del snapshot
     * oficial. Devuelve null si coinciden, o las dos diferencias:
     *   'faltan' → están en el snapshot y NO saldrían del cálculo (p. ej. el
     *              alumno cuyo tipo pasó a trasladado tras el cierre, o aquel
     *              cuya matrícula dejó de estar 'aprobada')
     *   'sobran' → saldrían del cálculo y NO están en el snapshot
     */
    private function rosterDifiere(int $periodoId, array $filas): ?array
    {
        $frescas = [];
        foreach ($filas as $f) {
            $frescas[(int) $f['matricula_id']] = true;
        }
        $guardadas = array_flip($this->matriculasEnSnapshot($periodoId));

        $faltan = array_keys(array_diff_key($guardadas, $frescas));
        $sobran = array_keys(array_diff_key($frescas, $guardadas));

        return ($faltan || $sobran) ? ['faltan' => $faltan, 'sobran' => $sobran] : null;
    }

    /** matricula_id presentes hoy en el snapshot oficial de un periodo. */
    private function matriculasEnSnapshot(int $periodoId): array
    {
        return array_map(
            static fn($r) => (int) $r['matricula_id'],
            $this->query(
                "SELECT matricula_id FROM orden_merito_snapshot WHERE periodo_id = ?",
                [$periodoId]
            )
        );
    }

    // ── Sincronización del roster por cambio de tipo (11/08/2026) ────────────

    /**
     * PUNTO ÚNICO de "este estudiante entra o sale del orden de mérito".
     *
     * REGLA DEL COLEGIO (11/08/2026, decisión del usuario): quien pasa a
     * `trasladado` o `retirado` sale del snapshot; si se revierte, vuelve a
     * entrar. Nace porque **la publicación siempre cae después de activar el
     * bimestre siguiente**: entre el cierre y la publicación hay una ventana en
     * la que el alumno se va, y el documento llega a las familias cuando ya no
     * está en el colegio.
     *
     * AMPLIADA EL 12/08/2026: el roster también exige `estado='aprobada'` (ver
     * self::ROSTER_MERITO), así que aprobar, desactivar o degradar a 'pendiente'
     * una matrícula mueve el documento igual que un traslado. La reversión es
     * SIMÉTRICA en los dos ejes: al aprobar, el alumno vuelve a entrar.
     *
     * ALCANCE: solo periodos CERRADOS que aún NO se publicaron. Un bimestre ya
     * publicado es inmutable (candado 046) y se deja intacto — por eso B1, con
     * sus 11 trasladados dentro, no se toca nunca.
     *
     * ⚠️ SE LLAMA DESDE LOS SEIS SITIOS QUE MUEVEN EL ROSTER. Si nace un
     * séptimo, tiene que llamar aquí: que la regla viva en un solo sitio es lo
     * que este repo ya pagó caro cuatro veces.
     *
     *   `matriculas.tipo` dentro/fuera de ('trasladado','retirado'):
     *     TrasladoController::guardar · MatriculaController::retirar
     *     MatriculaController::activar · MatriculaController::revertirRetiro
     *   `matriculas.estado` dentro/fuera de 'aprobada':
     *     MatriculaController::activar · MatriculaController::desactivar
     *     MatriculaController::guardarDocumentos (degrada a 'pendiente' al
     *     desmarcar un documento entregado)
     *
     * NO hace falta en `RetornoGradoController::revertir`, que deja la operativa
     * en 'desactivado': la excepción de self::ROSTER_MERITO la mantiene dentro a
     * propósito, así que el roster no se mueve.
     *
     * No decide por sí mismo quién entra o sale: pregunta al motor de siempre
     * (`calcularFilasRanking`, que ya aplica el roster) y actúa si difiere.
     *
     * @return array<int, array{periodo:string, accion:string, puesto:int,
     *                          grado:string, companeros:int}> efectos, para el
     *         mensaje al usuario. Vacío si no hubo nada que cambiar.
     */
    public function sincronizarRosterPorMatricula(int $matriculaId, ?int $usuarioId = null): array
    {
        $efectos = [];

        foreach ($this->periodosConSnapshotEditable() as $per) {
            $periodoId = (int) $per['id'];

            $antes = [];
            foreach ($this->query("
                SELECT s.matricula_id, s.puesto_grado, s.grado_id
                FROM orden_merito_snapshot s WHERE s.periodo_id = ?
            ", [$periodoId]) as $r) {
                $antes[(int) $r['matricula_id']] = $r;
            }

            $filas   = $this->calcularFilasRanking($periodoId);
            $frescas = [];
            foreach ($filas as $f) {
                $frescas[(int) $f['matricula_id']] = $f;
            }

            $estaba  = isset($antes[$matriculaId]);
            $deberia = isset($frescas[$matriculaId]);
            if ($estaba === $deberia) {
                continue;   // este periodo ya refleja la situación actual
            }

            // El grado y el puesto se leen del lado donde SÍ existe la fila.
            $ref     = $estaba ? $antes[$matriculaId] : $frescas[$matriculaId];
            $gradoId = (int) $ref['grado_id'];
            $puesto  = (int) ($ref['puesto_grado'] ?? 0);

            $this->escribirOficial($periodoId, $filas, $usuarioId);

            // Cuántos COMPAÑEROS de ese grado cambiaron de puesto por el ajuste.
            $companeros = 0;
            foreach ($frescas as $mid => $f) {
                if ($mid === $matriculaId || (int) $f['grado_id'] !== $gradoId) {
                    continue;
                }
                if (isset($antes[$mid]) && (int) $antes[$mid]['puesto_grado'] !== (int) $f['puesto_grado']) {
                    $companeros++;
                }
            }

            $efectos[] = [
                'periodo'    => (string) $per['nombre_display'],
                'accion'     => $estaba ? 'salio' : 'reintegrado',
                'puesto'     => $puesto,
                'grado'      => $this->etiquetaGrado($gradoId),
                'companeros' => $companeros,
            ];

            log_error('Orden de mérito: roster sincronizado por cambio de tipo o estado', [
                'matricula'  => $matriculaId,
                'periodo'    => $periodoId,
                'accion'     => $estaba ? 'salio' : 'reintegrado',
                'puesto'     => $puesto,
                'grado_id'   => $gradoId,
                'companeros' => $companeros,
                'usuario'    => $usuarioId,
                'filas'      => count($filas),
            ]);
        }

        return $efectos;
    }

    /**
     * Periodos cuyo snapshot oficial TODAVÍA se puede reescribir: cerrados, con
     * snapshot y no publicados. Los publicados quedan fuera por el candado 046.
     */
    private function periodosConSnapshotEditable(): array
    {
        $periodos = $this->query("
            SELECT p.id, p.nombre_display
            FROM periodos p
            WHERE p.estado = 'cerrado'
              AND EXISTS (SELECT 1 FROM orden_merito_snapshot s WHERE s.periodo_id = p.id)
            ORDER BY p.numero
        ");

        return array_values(array_filter(
            $periodos,
            fn($p) => !$this->publicacionModel->fuePublicado((int) $p['id'])
        ));
    }

    /** Etiqueta legible de un grado ("1° Secundaria") para los mensajes. */
    private function etiquetaGrado(int $gradoId): string
    {
        $g = $this->queryOne("
            SELECT g.nombre_display, n.nombre AS nivel
            FROM grados g INNER JOIN niveles n ON n.id = g.nivel_id
            WHERE g.id = ?
        ", [$gradoId]);

        return $g ? trim($g['nombre_display'] . ' ' . $g['nivel']) : ('grado ' . $gradoId);
    }

    /**
     * Convierte los efectos de `sincronizarRosterPorMatricula` en una frase para
     * el mensaje de éxito del controlador. Vacía si no hubo efectos.
     */
    public static function describirEfectosRoster(array $efectos): string
    {
        if (!$efectos) {
            return '';
        }

        $partes = [];
        foreach ($efectos as $e) {
            $companeros = (int) $e['companeros'];
            $arrastre   = $companeros === 0 ? '' : ($companeros === 1
                ? '; 1 compañero cambió de puesto'
                : sprintf('; %d compañeros cambiaron de puesto', $companeros));

            $partes[] = sprintf(
                $e['accion'] === 'salio'
                    ? 'Salió del orden de mérito de %s (ocupaba el puesto %d° de %s)%s.'
                    : 'Volvió al orden de mérito de %s (puesto %d° de %s)%s.',
                $e['periodo'], $e['puesto'], $e['grado'], $arrastre
            );
        }

        return ' ' . implode(' ', $partes);
    }

    // ── Lectores de la versión RECTIFICADA (Centro de control) ───────────────

    /**
     * Metadatos de la versión rectificada de un periodo (o null si no hay):
     * generado_en, motivo, generado_por + nombre y num_alumnos.
     */
    public function infoRectificado(int $periodoId): ?array
    {
        $row = $this->queryOne("
            SELECT r.generado_en, r.motivo, r.generado_por,
                   CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS generado_por_nombre
            FROM orden_merito_rectificado r
            LEFT JOIN usuarios u ON u.id = r.generado_por
            LEFT JOIN personas p ON p.id = u.persona_id
            WHERE r.periodo_id = ?
            ORDER BY r.generado_en DESC
            LIMIT 1
        ", [$periodoId]);

        if (!$row) {
            return null;
        }
        $cnt = $this->queryOne(
            "SELECT COUNT(*) AS n FROM orden_merito_rectificado WHERE periodo_id = ?",
            [$periodoId]
        );
        $row['num_alumnos'] = (int) ($cnt['n'] ?? 0);
        return $row;
    }

    /** Grados con ranking en la versión rectificada de un periodo. */
    public function gradosConRectificado(int $periodoId): array
    {
        return $this->query("
            SELECT DISTINCT g.id, g.numero, g.nombre_display,
                   n.id AS nivel_id, n.nombre AS nivel_nombre, n.codigo AS nivel_codigo
            FROM orden_merito_rectificado r
            INNER JOIN grados g  ON g.id = r.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            WHERE r.periodo_id = ?
            ORDER BY n.id, g.numero
        ", [$periodoId]);
    }

    /**
     * Puesto de grado y promedio de cada matrícula en el snapshot OFICIAL de un
     * periodo: matricula_id => ['puesto' => int, 'promedio' => float]. Lo usa
     * la vista del rectificado para mostrar QUÉ cambió frente al oficial.
     */
    public function puestosOficiales(int $periodoId): array
    {
        $map = [];
        foreach ($this->query("
            SELECT matricula_id, puesto_grado, promedio_general
            FROM orden_merito_snapshot
            WHERE periodo_id = ?
        ", [$periodoId]) as $f) {
            $map[(int) $f['matricula_id']] = [
                'puesto'   => (int) $f['puesto_grado'],
                'promedio' => (float) $f['promedio_general'],
            ];
        }
        return $map;
    }

    /** Ranking de grado desde la versión rectificada (mismo shape que el snapshot). */
    public function rankingGradoRectificado(int $gradoId, int $periodoId): array
    {
        $filas = $this->query("
            SELECT
                r.matricula_id,
                p.apellido_paterno, p.apellido_materno, p.nombres, p.dni,
                r.seccion_id,
                sec.nombre AS seccion_nombre,
                r.num_competencias, r.total_notas,
                r.promedio_general, r.promedio_exacto,
                r.num_c, r.num_b, r.num_ad, r.num_alto, r.num_16,
                r.puesto_grado AS puesto
            FROM orden_merito_rectificado r
            INNER JOIN matriculas m  ON m.id = r.matricula_id
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            LEFT  JOIN secciones sec ON sec.id = r.seccion_id
            WHERE r.periodo_id = ? AND r.grado_id = ?
            ORDER BY r.puesto_grado
        ", [$periodoId, $gradoId]);

        return $this->normalizarSnapshot($filas);
    }

    /**
     * Mapa matricula_id => ['puesto'=>int, 'empate_pendiente'=>bool] para varios
     * grados a la vez. Lo usa el buscador para mostrar el puesto exacto del orden
     * de mérito (incluida la resolución manual de empates).
     */
    public function puestosPorGrado(array $gradoIds, int $periodoId): array
    {
        $map = [];
        foreach (array_unique(array_map('intval', $gradoIds)) as $gid) {
            foreach ($this->rankingGrado($gid, $periodoId) as $fila) {
                $map[(int) $fila['matricula_id']] = [
                    'puesto'           => (int) $fila['puesto'],
                    'empate_pendiente' => !empty($fila['empate_pendiente']),
                ];
            }
        }
        return $map;
    }

    /**
     * Lista de grados del periodo que TODAVÍA tienen empates irreducibles sin
     * resolver (etiqueta "Nivel — Grado"). Se usa para impedir el cierre del
     * bimestre hasta que todos los empates estén resueltos: el snapshot oficial
     * del orden de mérito debe congelar un ranking 100% definido. Recorre los
     * mismos grados y la misma cascada que usa el director para resolverlos, así
     * la validación cuadra exactamente con la UI de desempate.
     *
     * Usa el cálculo EN VIVO a propósito (rankingGradoLive, NO rankingGrado):
     * valida lo que se está por congelar, no lo ya congelado. Importa al RE-CERRAR
     * un bimestre publicado y reabierto — ahí debeUsarSnapshot devuelve true
     * (candado 046) y leer por rankingGrado devolvería el snapshot viejo, sin ver
     * los empates que introdujeron las rectificaciones.
     */
    public function gradosConEmpatesPendientes(int $periodoId): array
    {
        return array_map(
            static fn(array $g): string => $g['nivel_nombre'] . ' — ' . $g['grado_nombre'],
            $this->gradosConEmpatesPendientesDetalle($periodoId)
        );
    }

    /**
     * Igual que gradosConEmpatesPendientes, pero con el detalle que necesita una
     * UI para enlazar a la pantalla de resolución: id del grado y CUÁNTOS grupos
     * irreducibles siguen sin resolver.
     *
     * PUNTO ÚNICO de "qué empates faltan": lo usan el guard del cierre (vía el
     * wrapper de arriba) y la card del Centro de Control. Antes el Centro tenía su
     * propia copia de la cascada en `ControlOperativoModel::detectarGruposIrreducibles`,
     * que se quedó congelada en la tupla de 3 conteos (num_c|num_b|num_ad) y nunca
     * incorporó los criterios de regularidad alta (`num_alto`, `num_16`, commit
     * `d41c548`). Consecuencia: inventaba empates que el motor real deshace solo, y
     * como la pantalla de resolución sí usa el motor real, esos fantasmas eran
     * IRRESOLUBLES — la card no se limpiaba nunca. No volver a duplicar esta lógica.
     *
     * `empate_clave` es la clave canónica del grupo (CSV de matrícula_id ordenados,
     * `DesempateMeritoModel::claveGrupo`), así que contar claves distintas cuenta
     * grupos, no alumnos.
     *
     * @return array<int, array{grado_id:int, grado_nombre:string, nivel_id:int,
     *                          nivel_nombre:string, n_grupos:int}>
     */
    public function gradosConEmpatesPendientesDetalle(int $periodoId): array
    {
        $grados = $this->query("
            SELECT DISTINCT g.id, g.numero, g.nombre_display,
                            n.id AS nivel_id, n.nombre AS nivel_nombre
            FROM matriculas m
            INNER JOIN secciones s        ON s.id  = m.seccion_id
            INNER JOIN grados g           ON g.id  = s.grado_id
            INNER JOIN niveles n          ON n.id  = g.nivel_id
            INNER JOIN calificaciones cal ON cal.matricula_id = m.id
            -- P2 (rediseño 2): enumerar solo grados con notas BLOQUEADAS,
            -- mismo universo que el ranking en vivo.
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            WHERE cal.periodo_id = ?
              AND (" . self::ROSTER_MERITO . ")
            ORDER BY n.id, g.numero
        ", [$periodoId]);

        $pendientes = [];
        foreach ($grados as $g) {
            $claves = [];
            foreach ($this->rankingGradoLive((int) $g['id'], $periodoId) as $fila) {
                if (!empty($fila['empate_pendiente']) && !empty($fila['empate_clave'])) {
                    $claves[$fila['empate_clave']] = true;
                }
            }
            if ($claves !== []) {
                $pendientes[] = [
                    'grado_id'     => (int) $g['id'],
                    'grado_nombre' => $g['nombre_display'],
                    'nivel_id'     => (int) $g['nivel_id'],
                    'nivel_nombre' => $g['nivel_nombre'],
                    'n_grupos'     => count($claves),
                ];
            }
        }

        return $pendientes;
    }

    // ── Cascada de desempate (movida desde OrdenMeritoController) ─────────────

    /**
     * Aplica la cascada de desempate sobre filas YA ordenadas por SQL
     * (promedio_exacto DESC, num_c ASC, num_b ASC, num_ad DESC, apellidos):
     *  - N (num_competencias) distinto en el empate → irreducible (decisión humana).
     *  - misma distribución literal exacta (num_c, num_b, num_ad) → irreducible.
     * Para cada grupo irreducible aplica la resolución humana si existe; si no, marca
     * `empate_pendiente`. Asigna puesto secuencial y media beca al 1º no pendiente.
     */
    private function aplicarDesempate(array $filas, int $periodoId): array
    {
        $total = count($filas);

        $i = 0;
        $resultado = [];
        while ($i < $total) {
            $j = $i;
            $promRef = round((float) $filas[$i]['promedio_exacto'], 6);
            while (
                $j + 1 < $total
                && round((float) $filas[$j + 1]['promedio_exacto'], 6) === $promRef
            ) {
                $j++;
            }
            $grupoProm = array_slice($filas, $i, $j - $i + 1);

            if (count($grupoProm) === 1) {
                $resultado[] = $this->marcarFila($grupoProm[0], false, null);
                $i = $j + 1;
                continue;
            }

            // ¿N uniforme dentro del grupo de promedio?
            $ns = array_unique(array_map(
                static fn($f) => (int) $f['num_competencias'],
                $grupoProm
            ));

            if (count($ns) > 1) {
                // N desigual → todo el grupo es irreducible (decisión humana).
                $resultado = array_merge(
                    $resultado,
                    $this->resolverGrupoIrreducible($grupoProm, $periodoId)
                );
                $i = $j + 1;
                continue;
            }

            // N uniforme → subagrupar por distribución literal (num_c, num_b, num_ad).
            $k = 0;
            $sub = count($grupoProm);
            while ($k < $sub) {
                $l = $k;
                $tuplaRef = $this->tuplaLiteral($grupoProm[$k]);
                while (
                    $l + 1 < $sub
                    && $this->tuplaLiteral($grupoProm[$l + 1]) === $tuplaRef
                ) {
                    $l++;
                }
                $subgrupo = array_slice($grupoProm, $k, $l - $k + 1);

                if (count($subgrupo) === 1) {
                    $resultado[] = $this->marcarFila($subgrupo[0], false, null);
                } else {
                    $resultado = array_merge(
                        $resultado,
                        $this->resolverGrupoIrreducible($subgrupo, $periodoId)
                    );
                }
                $k = $l + 1;
            }

            $i = $j + 1;
        }

        foreach ($resultado as $idx => &$fila) {
            $fila['puesto']     = $idx + 1;
            $fila['media_beca'] = ($idx === 0 && empty($fila['empate_pendiente']));
        }

        return $resultado;
    }

    /**
     * Resuelve un grupo irreducible: aplica la resolución humana si existe, o lo
     * marca como pendiente conservando el orden estable de entrada.
     */
    private function resolverGrupoIrreducible(array $grupo, int $periodoId): array
    {
        $matriculas = array_map(static fn($f) => (int) $f['matricula_id'], $grupo);
        $resolucion = $this->desempateModel->getResolucion($periodoId, $matriculas);

        if ($resolucion !== null) {
            $orden = $resolucion['orden']; // [matricula_id => orden_manual]
            usort($grupo, static function ($a, $b) use ($orden) {
                return ($orden[(int) $a['matricula_id']] ?? PHP_INT_MAX)
                     <=> ($orden[(int) $b['matricula_id']] ?? PHP_INT_MAX);
            });
            return array_map(
                fn($f) => $this->marcarFila($f, false, null),
                $grupo
            );
        }

        $clave = DesempateMeritoModel::claveGrupo($matriculas);
        return array_map(
            fn($f) => $this->marcarFila($f, true, $clave),
            $grupo
        );
    }

    /**
     * Tupla que identifica un empate irreducible. Incluye la distribución literal
     * (C, B, AD) y los criterios de regularidad alta (cantidad de notas 15-16 y de 16):
     * dos alumnos solo son irreducibles si coinciden en LOS CINCO conteos.
     */
    private function tuplaLiteral(array $fila): string
    {
        return (int) $fila['num_c'] . '|'
             . (int) $fila['num_b'] . '|'
             . (int) $fila['num_ad'] . '|'
             . (int) $fila['num_alto'] . '|'
             . (int) $fila['num_16'];
    }

    /** Anota una fila con el estado de empate sin perder sus datos. */
    private function marcarFila(array $fila, bool $pendiente, ?string $clave): array
    {
        $fila['empate_pendiente'] = $pendiente;
        $fila['empate_clave']     = $clave;
        return $fila;
    }
}
