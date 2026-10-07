<?php

namespace App\Models;

/**
 * ExoneracionModel
 * Gestiona exoneraciones anuales de alumnos de áreas o subáreas.
 */
class ExoneracionModel extends BaseModel
{
    protected string $table = 'exoneraciones';

    /**
     * Retorna los matricula_id de alumnos exonerados para el área/subárea de una carga.
     * Incluye exoneraciones a nivel de área (aplica a toda la carga) y a nivel de subárea.
     *
     * Con `$periodoId` (cambio de sección, 07/10/2026): los alumnos que CURSARON
     * ese bimestre en la sección de la carga, no los de hoy (un exonerado que
     * cambió de sección sigue saliendo EXO en el historial de su sección
     * anterior). Sin él, la sección de hoy, como antes.
     * @return int[]
     */
    public function getActivasParaCarga(int $cargaId, int $anioId, ?int $periodoId = null): array
    {
        $carga = $this->queryOne("
            SELECT ca.area_id, ca.subarea_id, ca.seccion_id, sa.area_id AS sa_area_id
            FROM cargas_academicas ca
            LEFT JOIN subareas sa ON sa.id = ca.subarea_id
            WHERE ca.id = ?
        ", [$cargaId]);

        if (!$carga) return [];

        $seccionId = (int) $carga['seccion_id'];
        $subareaId = $carga['subarea_id'] ? (int) $carga['subarea_id'] : null;
        $areaId    = $carga['area_id']
                   ? (int) $carga['area_id']
                   : ($carga['sa_area_id'] ? (int) $carga['sa_area_id'] : null);

        if (!$areaId && !$subareaId) return [];

        $enSeccion  = $periodoId !== null
            ? CambioSeccionModel::sqlEnSeccionDelPeriodo('m', $seccionId, $periodoId)
            : 'm.seccion_id = ' . $seccionId;
        $params     = [$anioId];
        $conditions = [];

        if ($areaId) {
            $conditions[] = "e.area_id = ?";
            $params[]     = $areaId;
        }
        if ($subareaId) {
            $conditions[] = "e.subarea_id = ?";
            $params[]     = $subareaId;
        }

        $where = '(' . implode(' OR ', $conditions) . ')';

        $rows = $this->query("
            SELECT DISTINCT e.matricula_id
            FROM exoneraciones e
            INNER JOIN matriculas m ON m.id = e.matricula_id
            WHERE e.anio_id      = ?
              AND e.revocado_en  IS NULL
              AND $enSeccion
              AND $where
        ", $params);

        return array_map('intval', array_column($rows, 'matricula_id'));
    }

    /**
     * Retorna datos de competencias exoneradas de un alumno en un año,
     * en el formato que necesita inyectarEnAreas() para la boleta.
     */
    public function getConCompetenciasParaBoleta(int $matriculaId, int $anioId): array
    {
        return $this->query("
            SELECT
                COALESCE(a_dir.nombre,     a_sub.nombre)     AS area_nombre,
                COALESCE(a_dir.nombre_boleta, a_sub.nombre_boleta) AS nombre_boleta,
                COALESCE(a_dir.alias_boleta,  a_sub.alias_boleta)  AS alias_boleta,
                COALESCE(a_dir.tipo,       a_sub.tipo)       AS area_tipo,
                COALESCE(a_dir.orden,      a_sub.orden)      AS area_orden,
                sa.nombre  AS subarea_nombre,
                comp.id    AS competencia_id,
                comp.codigo_minedu,
                comp.nombre_corto,
                comp.nombre_completo AS competencia_nombre,
                comp.orden           AS comp_orden
            FROM exoneraciones e
            LEFT  JOIN areas       a_dir ON a_dir.id  = e.area_id
            LEFT  JOIN subareas    sa    ON sa.id      = e.subarea_id
            LEFT  JOIN areas       a_sub ON a_sub.id   = sa.area_id
            INNER JOIN competencias comp ON (
                (e.area_id    IS NOT NULL AND comp.area_id    = e.area_id)
                OR
                (e.subarea_id IS NOT NULL AND comp.subarea_id = e.subarea_id)
            )
            WHERE e.matricula_id = ?
              AND e.anio_id     = ?
              AND e.revocado_en IS NULL
            ORDER BY COALESCE(a_dir.orden, a_sub.orden), comp.orden
        ", [$matriculaId, $anioId]);
    }

    /**
     * Versión por UNIÓN para el retorno de grado: fusiona las exoneraciones de
     * varias matrículas del mismo estudiante (oficial + operativa), deduplicando
     * por competencia (la oficial, última en la lista, gana). Con una sola
     * matrícula se comporta igual que getConCompetenciasParaBoleta().
     */
    public function getConCompetenciasParaBoletaUnion(array $matriculaIds, int $anioId): array
    {
        if (count($matriculaIds) <= 1) {
            return $this->getConCompetenciasParaBoleta((int) ($matriculaIds[0] ?? 0), $anioId);
        }

        $porComp = [];
        foreach ($matriculaIds as $id) {
            foreach ($this->getConCompetenciasParaBoleta((int) $id, $anioId) as $row) {
                $porComp[(int) $row['competencia_id']] = $row;
            }
        }
        return array_values($porComp);
    }

    /**
     * Retorna las exoneraciones activas de una sección con detalle de alumno y área.
     */
    public function getParaSeccion(int $seccionId, int $anioId): array
    {
        return $this->query("
            SELECT
                e.id,
                e.motivo,
                e.registrado_en,
                e.area_id,
                e.subarea_id,
                m.id AS matricula_id,
                CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres)
                    AS alumno_nombre,
                COALESCE(a_dir.nombre, a_sub.nombre) AS area_nombre,
                sa.nombre AS subarea_nombre,
                CONCAT(p_reg.apellido_paterno, ' ', p_reg.nombres) AS registrado_por_nombre
            FROM exoneraciones e
            INNER JOIN matriculas   m     ON m.id     = e.matricula_id
            INNER JOIN estudiantes  est   ON est.id   = m.estudiante_id
            INNER JOIN personas     p     ON p.id     = est.persona_id
            LEFT  JOIN areas        a_dir ON a_dir.id = e.area_id
            LEFT  JOIN subareas     sa    ON sa.id    = e.subarea_id
            LEFT  JOIN areas        a_sub ON a_sub.id = sa.area_id
            INNER JOIN usuarios     u_reg ON u_reg.id = e.registrado_por
            INNER JOIN personas     p_reg ON p_reg.id = u_reg.persona_id
            WHERE m.seccion_id  = ?
              AND e.anio_id     = ?
              AND e.revocado_en IS NULL
            ORDER BY " . orden_alfabetico('p', 2) . "
        ", [$seccionId, $anioId]);
    }

    /**
     * Exoneraciones VIGENTES de una matrícula, con nombres de área/subárea y
     * registrador. Para la card "Exoneraciones" del detalle /matriculas/{id}.
     */
    public function getVigentesPorMatricula(int $matriculaId): array
    {
        return $this->query("
            SELECT
                e.id,
                e.motivo,
                e.registrado_en,
                e.area_id,
                e.subarea_id,
                COALESCE(a_dir.nombre, a_sub.nombre) AS area_nombre,
                sa.nombre AS subarea_nombre,
                CONCAT(p_reg.apellido_paterno, ' ', p_reg.nombres) AS registrado_por_nombre
            FROM exoneraciones e
            LEFT  JOIN areas    a_dir ON a_dir.id = e.area_id
            LEFT  JOIN subareas sa    ON sa.id    = e.subarea_id
            LEFT  JOIN areas    a_sub ON a_sub.id = sa.area_id
            INNER JOIN usuarios u_reg ON u_reg.id = e.registrado_por
            INNER JOIN personas p_reg ON p_reg.id = u_reg.persona_id
            WHERE e.matricula_id = ?
              AND e.revocado_en IS NULL
            ORDER BY e.registrado_en
        ", [$matriculaId]);
    }

    /**
     * Retorna las opciones de área/subárea exonerable para una sección.
     * Usa las cargas activas de la sección para determinar qué áreas están en juego.
     * No incluye transversales.
     * @return array [['area_id', 'subarea_id', 'label'], ...]
     */
    public function getOpcionesParaSeccion(int $seccionId, int $anioId): array
    {
        $rows = $this->query("
            SELECT DISTINCT
                ca.area_id,
                ca.subarea_id,
                COALESCE(a_dir.nombre, a_sub.nombre) AS area_nombre,
                sa.nombre                             AS subarea_nombre,
                COALESCE(a_dir.orden,  a_sub.orden)  AS area_orden,
                sa.orden                              AS sub_orden
            FROM cargas_academicas ca
            LEFT  JOIN areas    a_dir ON a_dir.id = ca.area_id
            LEFT  JOIN subareas sa    ON sa.id    = ca.subarea_id
            LEFT  JOIN areas    a_sub ON a_sub.id = sa.area_id
            WHERE ca.seccion_id = ?
              AND ca.anio_id   = ?
              AND ca.estado    = 'activa'
              AND COALESCE(a_dir.tipo, a_sub.tipo) NOT IN ('transversal')
            ORDER BY area_orden, sub_orden
        ", [$seccionId, $anioId]);

        $opciones = [];
        foreach ($rows as $r) {
            if ($r['subarea_id']) {
                $opciones[] = [
                    'value'     => 'sub_' . $r['subarea_id'],
                    'area_id'   => null,
                    'subarea_id'=> (int) $r['subarea_id'],
                    'label'     => ($r['area_nombre'] ?? '') . ' — ' . ($r['subarea_nombre'] ?? '') . ' (subárea)',
                ];
            } else {
                $opciones[] = [
                    'value'   => 'area_' . $r['area_id'],
                    'area_id' => (int) $r['area_id'],
                    'subarea_id' => null,
                    'label'   => ($r['area_nombre'] ?? '') . ' (área completa)',
                ];
            }
        }

        return $opciones;
    }

    /**
     * Retorna los alumnos activos de una sección.
     */
    public function getAlumnosSeccion(int $seccionId, int $anioId): array
    {
        return $this->query("
            SELECT
                m.id AS matricula_id,
                CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres)
                    AS nombre_completo
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas    p ON p.id = e.persona_id
            WHERE m.seccion_id = ?
              AND m.anio_id   = ?
              AND m.estado    = 'aprobada'
            ORDER BY " . orden_alfabetico('p', 2) . "
        ", [$seccionId, $anioId]);
    }

    /**
     * Registra una exoneracion. Valida que no exista ya una activa para el mismo
     * alumno + año + área/subárea antes de insertar.
     */
    /**
     * true si la matrícula tiene alguna nota VIVA del año en el área/subárea
     * (fila en `calificaciones` — invariante: fila existe ⟺ nota viva). Se usa
     * para BLOQUEAR el registro de una exoneración cuando ya hay notas
     * (decisión 07/07/2026): primero deben eliminarse — evita estados mixtos
     * nota+EXO en la grilla del docente y en la boleta.
     * La exoneración por ÁREA también revisa las subáreas de esa área.
     */
    public function tieneNotasVivas(int $matriculaId, int $anioId, ?int $areaId, ?int $subareaId): bool
    {
        if (!$areaId && !$subareaId) {
            return false;
        }

        if ($areaId) {
            $cond   = "(c.area_id = ? OR c.subarea_id IN (SELECT sa.id FROM subareas sa WHERE sa.area_id = ?))";
            $params = [$anioId, $matriculaId, $areaId, $areaId];
        } else {
            $cond   = "c.subarea_id = ?";
            $params = [$anioId, $matriculaId, $subareaId];
        }

        $row = $this->queryOne("
            SELECT 1
            FROM calificaciones cal
            INNER JOIN periodos p     ON p.id = cal.periodo_id AND p.anio_id = ?
            INNER JOIN competencias c ON c.id = cal.competencia_id
            WHERE cal.matricula_id = ?
              AND $cond
            LIMIT 1
        ", $params);

        return !empty($row);
    }

    public function registrar(
        int    $matriculaId,
        int    $anioId,
        ?int   $areaId,
        ?int   $subareaId,
        string $motivo,
        int    $registradoPor
    ): bool {
        if (!$areaId && !$subareaId) return false;

        $existe = $this->queryOne("
            SELECT id FROM exoneraciones
            WHERE matricula_id = ?
              AND anio_id      = ?
              AND area_id    <=> ?
              AND subarea_id <=> ?
              AND revocado_en  IS NULL
        ", [$matriculaId, $anioId, $areaId, $subareaId]);

        if ($existe) return false;

        return $this->execute("
            INSERT INTO exoneraciones
                (matricula_id, anio_id, area_id, subarea_id, motivo, registrado_por)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$matriculaId, $anioId, $areaId, $subareaId, trim($motivo), $registradoPor]);
    }

    /**
     * Revoca (soft-delete) una exoneración por su ID.
     */
    public function revocar(int $id, int $revocadoPor): bool
    {
        return $this->execute("
            UPDATE exoneraciones
            SET revocado_en  = NOW(),
                revocado_por = ?
            WHERE id         = ?
              AND revocado_en IS NULL
        ", [$revocadoPor, $id]);
    }

    /**
     * Inyecta competencias EXO en el array $areas que genera buildAreasConBimestres().
     * Las áreas exoneradas aparecen al final si no tienen ninguna nota regular.
     *
     * @param array $areas   Estructura areas[nombre][comp_id] = {nombre, bimestres, literal_final}
     * @param array $exoData Filas devueltas por getConCompetenciasParaBoleta()
     * @param array $periodos [{ id, numero, nombre_display }, ...]
     */
    public static function inyectarEnAreas(array $areas, array $exoData, array $periodos): array
    {
        $bimestresVacios = [];
        foreach ($periodos as $p) {
            $bimestresVacios[$p['id']] = ['nota' => null, 'literal' => 'EXO', 'conclusion' => null];
        }

        foreach ($exoData as $exo) {
            $nombreArea = $exo['nombre_boleta'] ?? $exo['area_nombre'];
            if (!empty($exo['alias_boleta'])) {
                $nombreArea .= ' ' . $exo['alias_boleta'];
            }
            $compId = (int) $exo['competencia_id'];

            // Construir nombre de competencia igual que buildAreasConBimestres()
            $prefijoSubarea = '';
            if (($exo['area_tipo'] ?? '') === 'con_subareas' && !empty($exo['subarea_nombre'])) {
                $prefijoSubarea = $exo['subarea_nombre'] . ' — ';
            }
            $nombreComp = trim(
                $prefijoSubarea .
                ($exo['codigo_minedu'] ? $exo['codigo_minedu'] . '. ' : '') .
                ($exo['nombre_corto'] ?? $exo['competencia_nombre'] ?? '')
            );
            $nombreLargo = trim($prefijoSubarea . ($exo['competencia_nombre'] ?? ''));

            if (!isset($areas[$nombreArea])) {
                $areas[$nombreArea] = [];
            }

            // LA EXONERACION MANDA SOBRE LAS NOTAS, EN TODOS LOS BIMESTRES
            // (decision del usuario, 05/08/2026). Un alumno exonerado a mitad
            // de año muestra EXO en los CUATRO bimestres, incluidos los que
            // curso y aprobo antes de solicitarla.
            //
            // Las notas NO se borran de `calificaciones`: solo dejan de
            // mostrarse. Es lo que hace REVERSIBLE la exoneracion —al revocarla
            // reaparecen solas— y evita tener que reabrir un bimestre cerrado
            // para borrarlas, cosa que hoy ni siquiera es posible
            // (`periodoEstaBloqueado` bloquea todo periodo cerrado).
            //
            // ⚠️ Consecuencia asumida: la boleta de un bimestre YA ENTREGADO
            // cambia hacia atras (decia la nota, pasa a decir EXO), y el acta
            // que ya se subio al SIAGIE conserva la nota. La divergencia con el
            // SIAGIE se gestiona fuera de SIGA.
            // El snapshot de orden de merito NO se toca (es inmutable): el
            // puesto de ese bimestre sigue calculado con la nota que hubo.
            if (!isset($areas[$nombreArea][$compId])) {
                $areas[$nombreArea][$compId] = [
                    'nombre'       => $nombreComp,
                    'nombre_largo' => $nombreLargo,
                    'bimestres'    => $bimestresVacios,
                    'literal_final'=> 'EXO',
                    'es_exonerado' => true,
                ];
                continue;
            }

            $areas[$nombreArea][$compId]['es_exonerado']  = true;
            $areas[$nombreArea][$compId]['bimestres']     = $bimestresVacios;
            $areas[$nombreArea][$compId]['literal_final'] = 'EXO';
        }

        return $areas;
    }

}
