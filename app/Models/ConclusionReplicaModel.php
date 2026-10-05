<?php

namespace App\Models;

/**
 * ConclusionReplicaModel — conclusiones de RÉPLICA para el acta SIAGIE
 * (migración 072, 02/10/2026).
 *
 * Cuando una nota llena las hojas de más de un área en el acta (hoy: GAMA en
 * 5.º de secundaria → EPT y Arte y Cultura; ver `replicas_conclusion_acta()`),
 * el tutor escribe UNA conclusión por área destino, que el llenador repite en
 * todas las columnas de esa hoja.
 *
 * Reglas (decisiones del usuario, 02/10/2026):
 *   · Solo se EXIGEN cuando la conclusión de la fuente es obligatoria (misma
 *     regla: `conclusion_es_obligatoria`). Con nota aprobatoria se replica la
 *     de la fuente y estas filas no se leen.
 *   · NO salen en la boleta.
 *   · Si faltan (cierre forzado del bimestre), el llenador escribe la de la
 *     fuente y lo avisa en el reporte.
 *
 * ⚠️ Las réplicas se resuelven por la sección que EVALÚA (la del tutor). En un
 * retorno de grado el acta usa el grado de la matrícula OFICIAL; si difieren,
 * vale el aviso del llenador.
 */
class ConclusionReplicaModel extends BaseModel
{
    protected string $table = 'conclusiones_replica_acta';

    /**
     * Áreas destino de réplica de una sección: [{competencia_id, area_id,
     * area_nombre, codigo_hoja}]. Vacío si su grado no tiene réplicas. Una
     * regla que no resuelve (código o área inexistente) se omite: el llenador
     * ya la reporta como EXCEPCIÓN NO APLICADA.
     */
    public function destinosDeSeccion(int $seccionId): array
    {
        $sec = $this->queryOne("
            SELECT g.numero AS grado_numero, n.id AS nivel_id, n.codigo AS nivel_codigo
            FROM secciones s
            INNER JOIN grados g  ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            WHERE s.id = ?
        ", [$seccionId]);
        if (!$sec) {
            return [];
        }

        $reglas = replicas_conclusion_acta((string) $sec['nivel_codigo'], (int) $sec['grado_numero']);
        if ($reglas === []) {
            return [];
        }

        $siagie = new SiagieExportModel();
        $nivelId = (int) $sec['nivel_id'];
        $out = [];
        foreach ($reglas as $regla) {
            $fuente = null;
            if (isset($regla['buscar']['codigo_minedu'])) {
                $fuente = $siagie->competenciaPorCodigoMinedu($nivelId, $regla['buscar']['codigo_minedu']);
            } elseif (isset($regla['buscar']['nombre_boleta'])) {
                $comps  = $siagie->competenciasDeAreaPorNombreBoleta($nivelId, $regla['buscar']['nombre_boleta']);
                $fuente = count($comps) === 1 ? $comps[0] : null;
            }
            $area = $siagie->areaPorCodigoSiagie($nivelId, $regla['codigo_hoja']);
            if ($fuente === null || $area === null) {
                continue;
            }
            $out[] = [
                'competencia_id' => (int) $fuente['competencia_id'],
                'area_id'        => (int) $area['id'],
                'area_nombre'    => (string) $area['nombre'],
                'codigo_hoja'    => (string) $regla['codigo_hoja'],
            ];
        }
        return $out;
    }

    /**
     * Estudiantes cuya nota fuente EXIGE conclusión, con sus áreas destino:
     * [matricula_id => [competencia_id => ['literal' => 'C', 'destinos' => [...]]]].
     *
     * Recibe los promedios de quien llama (`TransversalModel::getPromediosSeccion`)
     * para que la nota que decide sea exactamente la que ve el tutor.
     */
    public function requeridas(int $seccionId, string $nivelCodigo, array $promedios): array
    {
        $destinos = $this->destinosDeSeccion($seccionId);
        if ($destinos === []) {
            return [];
        }
        $nivel = nivel_clave($nivelCodigo) === 'prim' ? 'primaria' : 'secundaria';

        $porFuente = [];
        foreach ($destinos as $d) {
            $porFuente[$d['competencia_id']][] = $d;
        }

        $out = [];
        foreach ($promedios as $matriculaId => $porComp) {
            foreach ($porFuente as $compId => $lista) {
                if (!isset($porComp[$compId])) {
                    continue;
                }
                $literal = nota_a_literal((int) $porComp[$compId], $nivel);
                if (conclusion_es_obligatoria($literal, $nivel)) {
                    $out[(int) $matriculaId][$compId] = ['literal' => $literal, 'destinos' => $lista];
                }
            }
        }
        return $out;
    }

    /** [matricula_id => [area_id => texto]] de una sección en un periodo. */
    public function getSeccion(int $seccionId, int $periodoId): array
    {
        $filas = $this->query("
            SELECT cr.matricula_id, cr.area_id, cr.conclusion
            FROM conclusiones_replica_acta cr
            INNER JOIN matriculas m ON m.id = cr.matricula_id
            WHERE m.seccion_id  = ?
              AND cr.periodo_id = ?
        ", [$seccionId, $periodoId]);

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['matricula_id']][(int) $f['area_id']] = $f['conclusion'];
        }
        return $out;
    }

    /** Réplicas obligatorias SIN texto (las suma el control de cierre del tutor). */
    public function pendientes(int $seccionId, int $periodoId, string $nivelCodigo, array $promedios): int
    {
        $requeridas = $this->requeridas($seccionId, $nivelCodigo, $promedios);
        if ($requeridas === []) {
            return 0;
        }
        $textos = $this->getSeccion($seccionId, $periodoId);

        $n = 0;
        foreach ($requeridas as $matriculaId => $porComp) {
            foreach ($porComp as $req) {
                foreach ($req['destinos'] as $d) {
                    if (trim((string) ($textos[$matriculaId][$d['area_id']] ?? '')) === '') {
                        $n++;
                    }
                }
            }
        }
        return $n;
    }

    /**
     * Para el llenador: [area_id => texto] de las matrículas dadas (las
     * fuentes de `boletaContexto`, como `notasOficiales`). En choque gana la
     * fuente posterior.
     */
    public function paraExport(array $matriculaIds, int $periodoId): array
    {
        $out = [];
        foreach ($matriculaIds as $mid) {
            $filas = $this->query("
                SELECT area_id, conclusion
                FROM conclusiones_replica_acta
                WHERE matricula_id = ? AND periodo_id = ?
            ", [(int) $mid, $periodoId]);
            foreach ($filas as $f) {
                $out[(int) $f['area_id']] = trim((string) $f['conclusion']);
            }
        }
        return $out;
    }

    /**
     * Guarda (o borra, con texto vacío) la conclusión de UNA área destino.
     * Mismo patrón que `TransversalModel::guardarConclusion`.
     */
    public function guardar(
        int $matriculaId,
        int $areaId,
        int $periodoId,
        string $conclusion,
        int $usuarioId
    ): bool {
        $conclusion = trim($conclusion);

        if ($conclusion === '') {
            return $this->execute("
                DELETE FROM conclusiones_replica_acta
                WHERE matricula_id = ? AND area_id = ? AND periodo_id = ?
            ", [$matriculaId, $areaId, $periodoId]);
        }

        return $this->execute("
            INSERT INTO conclusiones_replica_acta
                (matricula_id, periodo_id, area_id, conclusion, registrado_por)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                conclusion     = VALUES(conclusion),
                registrado_por = VALUES(registrado_por)
        ", [$matriculaId, $periodoId, $areaId, $conclusion, $usuarioId]);
    }
}
