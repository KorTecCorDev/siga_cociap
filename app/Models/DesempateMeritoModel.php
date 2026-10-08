<?php

namespace App\Models;

/**
 * DesempateMeritoModel
 * Resolucion manual de empates irreducibles del orden de merito.
 *
 * Un empate es irreducible cuando la cascada automatica (promedio exacto ->
 * menos C -> menos B -> mas AD) no separa a los alumnos, o cuando tienen distinto
 * numero de competencias (exoneraciones). Ahi el puesto lo designa una persona.
 *
 * La resolucion se ancla al CONJUNTO de matriculas empatadas + periodo via
 * `grupo_clave` (CSV ordenado de matricula_id), para que la misma decision sirva
 * en el ranking general y en el ranking por seccion sin resolverse dos veces.
 */
class DesempateMeritoModel extends BaseModel
{
    protected string $table = 'desempates_merito';

    /**
     * Clave canonica de un grupo empatado: matricula_id ordenados, separados por coma.
     * Asi el mismo conjunto produce siempre la misma clave sin importar el orden de entrada.
     */
    public static function claveGrupo(array $matriculaIds): string
    {
        $ids = array_map('intval', $matriculaIds);
        sort($ids, SORT_NUMERIC);
        return implode(',', $ids);
    }

    /**
     * Devuelve la resolucion que cubre a un grupo empatado, o null si aun no se resolvio.
     * Retorna: ['id', 'motivo', 'resuelto_por', 'resuelto_en',
     *           'orden' => [matricula_id => orden_manual]].
     *
     * Busca un desempate del periodo cuyo detalle CONTENGA todas las matriculas pedidas
     * (coincidencia exacta o superconjunto). Asi una resolucion del ranking general
     * sobre {A,B,C} tambien ordena al subgrupo {A,B} del ranking por seccion, sin
     * resolver el mismo empate dos veces con resultados contradictorios. El orden_manual
     * es un orden total; restringirlo a un subconjunto preserva el orden relativo.
     */
    public function getResolucion(int $periodoId, array $matriculaIds): ?array
    {
        $ids = array_values(array_unique(array_map('intval', $matriculaIds)));
        $n   = count($ids);
        if ($n < 2) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, $n, '?'));

        // Desempate del periodo que contenga TODAS las matriculas pedidas.
        $row = $this->queryOne("
            SELECT dmo.desempate_id
            FROM desempates_merito_orden dmo
            INNER JOIN desempates_merito dm ON dm.id = dmo.desempate_id
            WHERE dm.periodo_id = ?
              AND dmo.matricula_id IN ($placeholders)
            GROUP BY dmo.desempate_id
            HAVING COUNT(DISTINCT dmo.matricula_id) = ?
            ORDER BY dmo.desempate_id DESC
            LIMIT 1
        ", array_merge([$periodoId], $ids, [$n]));

        if (!$row) {
            return null;
        }

        $desempateId = (int) $row['desempate_id'];

        $cabecera = $this->queryOne("
            SELECT id, motivo, resuelto_por, resuelto_en
            FROM desempates_merito WHERE id = ?
        ", [$desempateId]);

        // Orden restringido a las matriculas pedidas (preserva el orden relativo).
        $detalle = $this->query("
            SELECT matricula_id, orden_manual
            FROM desempates_merito_orden
            WHERE desempate_id = ?
              AND matricula_id IN ($placeholders)
            ORDER BY orden_manual
        ", array_merge([$desempateId], $ids));

        $orden = [];
        foreach ($detalle as $fila) {
            $orden[(int) $fila['matricula_id']] = (int) $fila['orden_manual'];
        }

        if (count($orden) !== $n) {
            return null;
        }

        $cabecera['orden'] = $orden;
        return $cabecera;
    }

    /**
     * Guarda (o reemplaza) la resolucion de un grupo empatado.
     * $ordenMatriculas: matricula_id en el orden designado (indice 0 = primer puesto).
     * Idempotente por (periodo_id, grupo_clave): re-resolver reemplaza el detalle y
     * actualiza quien/cuando/motivo -> permite el override de Admin sobre Registro.
     */
    public function guardar(
        int $periodoId,
        int $gradoId,
        array $ordenMatriculas,
        int $usuarioId,
        string $motivo
    ): int {
        $clave = self::claveGrupo($ordenMatriculas);

        $this->beginTransaction();
        try {
            $existente = $this->queryOne("
                SELECT id FROM desempates_merito
                WHERE periodo_id = ? AND grupo_clave = ?
                LIMIT 1
            ", [$periodoId, $clave]);

            if ($existente) {
                $desempateId = (int) $existente['id'];
                $this->execute("
                    UPDATE desempates_merito
                    SET grado_id = ?, motivo = ?, resuelto_por = ?, resuelto_en = NOW()
                    WHERE id = ?
                ", [$gradoId, $motivo, $usuarioId, $desempateId]);
                $this->execute(
                    "DELETE FROM desempates_merito_orden WHERE desempate_id = ?",
                    [$desempateId]
                );
            } else {
                $this->execute("
                    INSERT INTO desempates_merito
                        (periodo_id, grado_id, grupo_clave, motivo, resuelto_por)
                    VALUES (?, ?, ?, ?, ?)
                ", [$periodoId, $gradoId, $clave, $motivo, $usuarioId]);
                $desempateId = (int) $this->db->lastInsertId();
            }

            $orden = 1;
            foreach ($ordenMatriculas as $matriculaId) {
                $this->execute("
                    INSERT INTO desempates_merito_orden
                        (desempate_id, matricula_id, orden_manual)
                    VALUES (?, ?, ?)
                ", [$desempateId, (int) $matriculaId, $orden]);
                $orden++;
            }

            $this->commit();
            return $desempateId;
        } catch (\Exception $e) {
            $this->rollback();
            log_error('Error guardando desempate de merito', [
                'periodo_id' => $periodoId,
                'grado_id'   => $gradoId,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Lista las resoluciones de un periodo (para auditoria/listado).
     */
    public function getResolucionesPorPeriodo(int $periodoId): array
    {
        return $this->query("
            SELECT
                dm.id, dm.grado_id, dm.grupo_clave, dm.motivo,
                dm.resuelto_en,
                p.apellido_paterno, p.apellido_materno, p.nombres
            FROM desempates_merito dm
            INNER JOIN usuarios u ON u.id = dm.resuelto_por
            INNER JOIN personas p ON p.id = u.persona_id
            WHERE dm.periodo_id = ?
            ORDER BY dm.resuelto_en DESC
        ", [$periodoId]);
    }

    /** Numero de resoluciones de desempate registradas en un periodo. */
    public function contarPorPeriodo(int $periodoId): int
    {
        $row = $this->queryOne(
            "SELECT COUNT(*) AS n FROM desempates_merito WHERE periodo_id = ?",
            [$periodoId]
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Acta de un periodo: cada resolucion con su cabecera (grado, quien la tomo,
     * fecha, motivo) y el detalle de alumnos en el orden manual designado.
     * Pensada para el reporte/constancia que explica las decisiones a docentes y padres.
     *
     * Retorna [ {id, grado_id, motivo, resuelto_en, resuelto_por_nombre, resuelto_por_sexo,
     *            grado_nombre, nivel_nombre, alumnos[ {matricula_id, orden_manual,
     *            apellido_paterno, apellido_materno, nombres, seccion_nombre} ]} ].
     * Ordenada por nivel y grado. Las metricas (promedio, AD/B/C, puesto) las anexa el
     * controller desde la fuente unica del ranking, para que coincidan con el orden de merito.
     */
    public function getActaPorPeriodo(int $periodoId): array
    {
        $resoluciones = $this->query("
            SELECT
                dm.id, dm.grado_id, dm.motivo, dm.resuelto_en,
                g.nombre_display AS grado_nombre,
                n.nombre         AS nivel_nombre,
                p.apellido_paterno, p.apellido_materno, p.nombres,
                p.sexo           AS resuelto_por_sexo
            FROM desempates_merito dm
            INNER JOIN usuarios u ON u.id = dm.resuelto_por
            INNER JOIN personas p ON p.id = u.persona_id
            INNER JOIN grados   g ON g.id = dm.grado_id
            INNER JOIN niveles  n ON n.id = g.nivel_id
            WHERE dm.periodo_id = ?
            ORDER BY n.id, g.numero, dm.resuelto_en DESC
        ", [$periodoId]);

        foreach ($resoluciones as &$res) {
            $res['resuelto_por_nombre'] = trim(
                $res['apellido_paterno'] . ' ' . $res['apellido_materno'] . ', ' . $res['nombres']
            );
            $res['alumnos'] = $this->query("
                SELECT
                    dmo.matricula_id, dmo.orden_manual,
                    p.apellido_paterno, p.apellido_materno, p.nombres,
                    s.nombre AS seccion_nombre
                FROM desempates_merito_orden dmo
                INNER JOIN matriculas  m ON m.id = dmo.matricula_id
                INNER JOIN estudiantes e ON e.id = m.estudiante_id
                INNER JOIN personas    p ON p.id = e.persona_id
                -- La sección donde CURSÓ el bimestre (cambio de sección, 07/10/2026).
                INNER JOIN secciones   s ON s.id = " . CambioSeccionModel::sqlSeccionDelPeriodo('m', $periodoId) . "
                WHERE dmo.desempate_id = ?
                ORDER BY dmo.orden_manual
            ", [(int) $res['id']]);
        }
        unset($res);

        return $resoluciones;
    }

    /**
     * Cuadro comparativo competencia por competencia para un grupo de matriculas
     * empatadas. Por cada competencia trae la nota numeral y literal de cada alumno,
     * y marca `resaltar = true` cuando todos comparten el MISMO literal pero con
     * DISTINTA nota numeral (la diferencia que la boleta literal oculta — clave para
     * explicar el desempate a los padres, sobre todo en primaria).
     *
     * Replica el camino de enlace del ranking (area/subarea via `competencias`, sin
     * filtrar por bloqueos y excluyendo transversales) para que estas notas cuadren
     * exactamente con el promedio que produjo el empate.
     *
     * Retorna [ {label, notas[matricula_id=>int|null], literales[matricula_id=>str|null],
     *            resaltar} ] ordenado por area y competencia.
     */
    public function getComparativoCompetencias(array $matriculaIds, int $periodoId): array
    {
        $ids = array_values(array_unique(array_map('intval', $matriculaIds)));
        if (count($ids) < 2) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $filas = $this->query("
            SELECT
                cal.matricula_id,
                comp.id              AS competencia_id,
                comp.nombre_completo AS competencia_nombre,
                cal.nota_numerica,
                a.nombre             AS area_nombre,
                sa.nombre            AS subarea_nombre
            FROM calificaciones cal
            INNER JOIN competencias comp ON comp.id = cal.competencia_id
            LEFT  JOIN subareas sa       ON sa.id  = comp.subarea_id
            INNER JOIN areas a           ON a.id   = COALESCE(sa.area_id, comp.area_id)
            WHERE cal.matricula_id IN ($ph)
              AND cal.periodo_id = ?
              AND a.tipo != 'transversal'
            ORDER BY a.orden, comp.orden, comp.id
        ", array_merge($ids, [$periodoId]));

        // Pivote: una fila por competencia, columnas por matricula.
        $comp = [];
        foreach ($filas as $f) {
            $cid = (int) $f['competencia_id'];
            if (!isset($comp[$cid])) {
                $curso = !empty($f['subarea_nombre']) ? $f['subarea_nombre'] : $f['area_nombre'];
                $comp[$cid] = [
                    'label'     => $curso . ' — ' . $f['competencia_nombre'],
                    'notas'     => [],
                    'literales' => [],
                ];
            }
            $nota = $f['nota_numerica'];
            $comp[$cid]['notas'][(int) $f['matricula_id']]     = $nota !== null ? (int) $nota : null;
            $comp[$cid]['literales'][(int) $f['matricula_id']] = $nota !== null ? nota_a_literal((int) $nota) : null;
        }

        // Marca las competencias con mismo literal pero distinto numeral.
        foreach ($comp as &$c) {
            $notas     = array_filter($c['notas'],     static fn($n) => $n !== null);
            $literales = array_filter($c['literales'], static fn($l) => $l !== null);
            $c['resaltar'] = (
                count($literales) >= 2
                && count(array_unique($literales)) === 1
                && count(array_unique($notas)) > 1
            );
        }
        unset($c);

        return array_values($comp);
    }
}
