<?php

namespace App\Models;

/**
 * NominaModel — la NÓMINA DE MATRICULADOS: la que consulta y la que imprime el
 * docente, y desde el 28/09/2026 también el auxiliar académico para sus
 * secciones. Vivía como métodos privados de `Docente\PanelController`; se
 * extrajo aquí para que el segundo rol la reutilice en vez de copiarla (mismo
 * motivo por el que el horario pasó a `HorarioModel`).
 *
 * Es un DOCUMENTO OFICIAL (SIAGIE): muestra la matrícula oficial de un retorno
 * de grado (`matricula_documento()`), nunca a los trasladados, y nunca el DNI.
 */
class NominaModel extends BaseModel
{
    protected string $table = 'matriculas';

    /**
     * Cabecera de la nómina de una sección: grado, nivel y tutor(a).
     * NULL si la sección no existe.
     */
    public function seccion(int $seccionId): ?array
    {
        return $this->queryOne("
            SELECT s.id, s.nombre AS seccion_nombre,
                   g.numero AS grado_numero, g.nombre_display AS grado_nombre,
                   n.id AS nivel_id, n.nombre AS nivel_nombre,
                   tp.sexo AS tutor_sexo,
                   CASE WHEN tp.id IS NULL THEN ''
                        ELSE CONCAT(tp.apellido_paterno, ' ', tp.apellido_materno, ', ', tp.nombres)
                   END AS tutor_nombre
            FROM secciones s
            INNER JOIN grados g  ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            LEFT  JOIN usuarios tu ON tu.id = s.tutor_id
            LEFT  JOIN personas tp ON tp.id = tu.persona_id
            WHERE s.id = ?
        ", [$seccionId]);
    }

    /** Conteo de matriculados aprobados por sección de los niveles dados. */
    public function resumenPorSeccion(array $nivelIds): array
    {
        $ids = array_map('intval', $nivelIds);
        if (empty($ids)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->query("
            SELECT n.id AS nivel_id, n.nombre AS nivel_nombre,
                   g.numero AS grado_numero, g.nombre_display AS grado_nombre,
                   s.id AS seccion_id, s.nombre AS seccion_nombre,
                   COUNT(*) AS n
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN niveles n   ON n.id = g.nivel_id
            WHERE m.estado = 'aprobada' AND m.tipo != 'trasladado'
              -- Retorno de grado: la nomina (documento oficial SIAGIE) muestra
              -- la matricula OFICIAL y oculta la operativa interna. Mismo filtro
              -- que matriculados(), para que la card y el detalle cuadren.
              " . matricula_documento('m') . "
              AND n.id IN ($ph)
            GROUP BY s.id
            ORDER BY n.id, g.numero, s.nombre
        ", $ids);
    }

    /**
     * Matriculados de los niveles dados (o de una sección concreta), con su
     * apoderado responsable (vinculo_familiar.es_responsable = 1).
     *
     * $soloAprobadas = true  → solo 'aprobada'. Lo usan la nómina IMPRIMIBLE
     *                          (documento oficial SIAGIE — no relajar) y, desde
     *                          el 10/08/2026, el BUSCADOR en vivo del docente.
     * $soloAprobadas = false → incluye 'pendiente' y 'desactivado'. Hoy SIN
     *                          llamadores; se conserva porque es el criterio de
     *                          los ROSTERS (grilla de notas, asistencia,
     *                          conducta), donde esos alumnos SI aparecen porque
     *                          asisten y se evalúan.
     * Trasladados y operativas de retorno quedan fuera SIEMPRE.
     *
     * ⚠️ Un 'desactivado' por DEUDA sí se califica, así que no saldrá en el
     * buscador aunque el docente deba evaluarlo. Hoy no muerde —los 11
     * desactivados del año son trasladados/retirados, que ya están fuera de la
     * evaluación—, pero es el precio de filtrar por estado en esa pantalla.
     */
    public function matriculados(array $nivelIds, int $seccionId = 0, bool $soloAprobadas = true): array
    {
        $ids = array_map('intval', $nivelIds);
        if (empty($ids)) {
            return [];
        }
        $ph     = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $filtroSeccion = '';
        if ($seccionId > 0) {
            $filtroSeccion = ' AND s.id = ?';
            $params[]      = $seccionId;
        }
        $filtroEstado = $soloAprobadas
            ? "m.estado = 'aprobada'"
            : "m.estado IN ('aprobada', 'pendiente', 'desactivado')";

        return $this->query("
            SELECT m.id AS matricula_id, m.estado,
                   p.apellido_paterno, p.apellido_materno, p.nombres,
                   s.id AS seccion_id, s.nombre AS seccion_nombre,
                   g.id AS grado_id, g.numero AS grado_numero, g.nombre_display AS grado_nombre,
                   n.id AS nivel_id, n.nombre AS nivel_nombre,
                   TRIM(CONCAT(
                       COALESCE(ap.apellido_paterno, ''), ' ',
                       COALESCE(ap.apellido_materno, ''), ' ',
                       COALESCE(ap.nombres, '')
                   )) AS apoderado_nombre,
                   ap.telefono AS apoderado_telefono,
                   tp.sexo AS tutor_sexo,
                   TRIM(CONCAT(
                       COALESCE(tp.apellido_paterno, ''), ' ',
                       COALESCE(tp.apellido_materno, ''), ' ',
                       COALESCE(tp.nombres, '')
                   )) AS tutor_nombre,
                   -- ¿Tiene al menos una competencia bloqueada (boleta con
                   -- contenido)? Gobierna la aparicion de los botones de boleta.
                   EXISTS (
                       SELECT 1 FROM calificaciones cal
                       INNER JOIN bloqueos_competencia bc
                           ON bc.carga_id       = cal.carga_id
                          AND bc.competencia_id = cal.competencia_id
                          AND bc.periodo_id     = cal.periodo_id
                       WHERE cal.matricula_id = m.id
                   ) AS tiene_boleta
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            INNER JOIN secciones s   ON s.id = m.seccion_id
            INNER JOIN grados g      ON g.id = s.grado_id
            INNER JOIN niveles n     ON n.id = g.nivel_id
            LEFT  JOIN usuarios tu   ON tu.id = s.tutor_id
            LEFT  JOIN personas tp   ON tp.id = tu.persona_id
            LEFT JOIN vinculo_familiar vf
                ON  vf.estudiante_id = e.id
                AND vf.es_responsable = 1
                AND vf.id = (
                    SELECT MIN(vf2.id) FROM vinculo_familiar vf2
                    WHERE vf2.estudiante_id = e.id AND vf2.es_responsable = 1
                )
            LEFT JOIN apoderados apo ON apo.id = vf.apoderado_id
            LEFT JOIN personas ap    ON ap.id = apo.persona_id
            WHERE {$filtroEstado} AND m.tipo != 'trasladado'
              -- Retorno de grado: la nómina es un documento OFICIAL (SIAGIE), por
              -- lo que muestra la matrícula ORIGINAL (grado/sección oficial) y
              -- oculta la operativa interna (grado inferior). Punto unico
              -- matricula_documento() (28/09/2026).
              " . matricula_documento('m') . "
              AND n.id IN ($ph)$filtroSeccion
            ORDER BY n.id, g.numero, s.nombre,
                     " . orden_alfabetico('p') . "
        ", $params);
    }
}
