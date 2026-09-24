<?php

namespace App\Models;

class CurriculumModel extends BaseModel
{
    protected string $table = 'areas';

    public function getNiveles(): array
    {
        return $this->query("SELECT id, nombre FROM niveles ORDER BY id");
    }

    public function getAreasParaSidebar(int $nivelId): array
    {
        return $this->query(
            "SELECT id, nombre, tipo, activa, orden FROM areas WHERE nivel_id = ? ORDER BY orden, nombre",
            [$nivelId]
        );
    }

    public function getAreaDetalle(int $areaId): ?array
    {
        $area = $this->queryOne("
            SELECT a.id, a.nombre, a.nombre_boleta, a.alias_boleta,
                   a.nombre_siagie, a.codigo_siagie, a.tipo, a.orden, a.activa,
                   a.nivel_id, n.nombre AS nivel_nombre
            FROM areas a
            INNER JOIN niveles n ON n.id = a.nivel_id
            WHERE a.id = ?
        ", [$areaId]);

        if (!$area) return null;

        if ($area['tipo'] === 'con_subareas') {
            $subareas = $this->query(
                "SELECT id, nombre, orden FROM subareas WHERE area_id = ? ORDER BY orden",
                [$areaId]
            );
            foreach ($subareas as &$sa) {
                $sa['competencias'] = $this->query(
                    "SELECT id, codigo_minedu, nombre_completo, nombre_corto, orden
                     FROM competencias WHERE subarea_id = ? ORDER BY orden",
                    [$sa['id']]
                );
            }
            unset($sa);
            $area['subareas']     = $subareas;
            $area['competencias'] = [];
        } else {
            $area['subareas']     = [];
            $area['competencias'] = $this->query(
                "SELECT id, codigo_minedu, nombre_completo, nombre_corto, orden
                 FROM competencias WHERE area_id = ? ORDER BY orden",
                [$areaId]
            );
        }

        return $area;
    }

    public function actualizarArea(int $id, array $data): bool
    {
        return $this->update($id, $data);
    }

    /** Años académicos, el más reciente primero (selector de la aprobación de talleres). */
    public function aniosAcademicos(): array
    {
        return $this->query("SELECT id, anio, estado FROM anios_academicos ORDER BY anio DESC");
    }

    /**
     * Aprobación de la UGEL de un TALLER para un año, grado por grado
     * (24/09/2026, migración 064). Una fila por cada grado del nivel del
     * taller, con `aprobado` (0 si no hay fila: sin aprobación el taller NO
     * cuenta para la situación final), quién la tocó por última vez y en cuántas secciones de ese grado se dicta el taller ese año —para
     * que quien marca vea dónde tiene efecto—.
     */
    public function aprobacionTaller(int $areaId, int $anioId): array
    {
        return $this->query("
            SELECT g.id AS grado_id, g.nombre_display AS grado,
                   COALESCE(ta.aprobado, 0) AS aprobado,
                   ta.actualizado_en,
                   TRIM(CONCAT(COALESCE(p.apellido_paterno, ''), ' ', COALESCE(p.apellido_materno, ''),
                               IF(p.nombres IS NULL, '', CONCAT(', ', p.nombres)))) AS actualizado_por,
                   (SELECT COUNT(DISTINCT ca.seccion_id)
                    FROM cargas_academicas ca
                    INNER JOIN secciones s ON s.id = ca.seccion_id
                    WHERE ca.estado = 'activa' AND ca.anio_id = ? AND s.grado_id = g.id
                      AND (ca.area_id = a.id
                           OR ca.subarea_id IN (SELECT id FROM subareas WHERE area_id = a.id))
                   ) AS secciones
            FROM areas a
            INNER JOIN grados g ON g.nivel_id = a.nivel_id
            LEFT  JOIN talleres_aprobacion ta ON ta.area_id  = a.id
                                             AND ta.anio_id  = ?
                                             AND ta.grado_id = g.id
            LEFT  JOIN usuarios u ON u.id = ta.actualizado_por
            LEFT  JOIN personas p ON p.id = u.persona_id
            WHERE a.id = ? AND a.tipo = 'taller'
            ORDER BY g.numero
        ", [$anioId, $anioId, $areaId]);
    }

    /**
     * Resolución Directoral DEL COLEGIO que crea el taller para un año (migración
     * 064): una por taller y año, o null si no se registró. Es el sustento del
     * colegio; lo que hace que el taller cuente es la aprobación de la UGEL.
     */
    public function resolucionTaller(int $areaId, int $anioId): ?array
    {
        return $this->queryOne("
            SELECT tr.numero, tr.fecha, tr.actualizado_en,
                   TRIM(CONCAT(COALESCE(p.apellido_paterno, ''), ' ', COALESCE(p.apellido_materno, ''),
                               IF(p.nombres IS NULL, '', CONCAT(', ', p.nombres)))) AS actualizado_por
            FROM talleres_resolucion tr
            LEFT JOIN usuarios u ON u.id = tr.actualizado_por
            LEFT JOIN personas p ON p.id = u.persona_id
            WHERE tr.area_id = ? AND tr.anio_id = ?
        ", [$areaId, $anioId]);
    }

    /**
     * Guarda, para un taller y un año, la aprobación de la UGEL (aprobado en los
     * grados de `$gradosAprobados`, no aprobado en los demás de su nivel) y la
     * Resolución Directoral del colegio.
     *
     * Retirar una aprobación NO borra la fila: la deja en `aprobado = 0` con
     * quién y cuándo. Un grado sin fila y sin marcar no se escribe (no hay nada
     * que registrar). La RD se guarda si llega su número; si llega vacío, se
     * quita la registrada (el formulario siempre trae la actual). Todo en una
     * transacción.
     *
     * @param int[]      $gradosAprobados ids ya validados contra el nivel del taller
     * @param array|null $rd  ['numero' => string, 'fecha' => ?string Y-m-d] ya validada, o null
     */
    public function guardarAprobacionTaller(int $areaId, int $anioId, array $gradosAprobados,
                                            ?array $rd, int $usuarioId): void
    {
        $filas = $this->aprobacionTaller($areaId, $anioId);
        $marca = array_flip(array_map('intval', $gradosAprobados));

        // Si ya hay una transacción abierta (la de un verificador con rollback),
        // se trabaja dentro de ella: PDO no admite transacciones anidadas.
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            foreach ($filas as $f) {
                $gid      = (int) $f['grado_id'];
                $aprobado = isset($marca[$gid]) ? 1 : 0;
                $existe   = $f['actualizado_en'] !== null;
                if (!$aprobado && !$existe) {
                    continue;
                }
                $this->execute("
                    INSERT INTO talleres_aprobacion
                        (area_id, anio_id, grado_id, aprobado, actualizado_por)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        aprobado        = VALUES(aprobado),
                        actualizado_por = VALUES(actualizado_por)
                ", [$areaId, $anioId, $gid, $aprobado, $usuarioId]);
            }

            if ($rd !== null) {
                $this->execute("
                    INSERT INTO talleres_resolucion (area_id, anio_id, numero, fecha, actualizado_por)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        numero          = VALUES(numero),
                        fecha           = VALUES(fecha),
                        actualizado_por = VALUES(actualizado_por)
                ", [$areaId, $anioId, $rd['numero'], $rd['fecha'], $usuarioId]);
            } else {
                $this->execute("DELETE FROM talleres_resolucion WHERE area_id = ? AND anio_id = ?",
                    [$areaId, $anioId]);
            }
            if ($propia) {
                $this->commit();
            }
        } catch (\Throwable $e) {
            if ($propia) {
                $this->rollback();
            }
            throw $e;
        }
    }

    /**
     * Otra área del MISMO nivel que ya usa alguno de estos códigos SIAGIE.
     * Devuelve su nombre, o null si el código está libre.
     *
     * Importa porque `SiagieExportModel::areaPorCodigoSiagie` resuelve la hoja
     * con `FIND_IN_SET ... LIMIT 1`: si dos áreas comparten el código, la hoja
     * se asigna a una arbitraria y el acta se llena con el área equivocada, sin
     * ningún aviso. Es una colisión que hay que impedir al guardar.
     *
     * @param string[] $codigos códigos sueltos ya normalizados (sin comas).
     */
    public function areaConCodigoSiagie(int $nivelId, array $codigos, int $exceptoAreaId): ?string
    {
        if ($codigos === []) {
            return null;
        }
        $cond   = implode(' OR ', array_fill(0, count($codigos), 'FIND_IN_SET(?, a.codigo_siagie)'));
        $params = array_merge([$nivelId, $exceptoAreaId], $codigos);

        $fila = $this->queryOne("
            SELECT a.nombre
            FROM areas a
            WHERE a.nivel_id = ?
              AND a.id <> ?
              AND a.codigo_siagie IS NOT NULL
              AND ({$cond})
            LIMIT 1
        ", $params);

        return $fila['nombre'] ?? null;
    }

    public function actualizarSubarea(int $id, string $nombre, int $orden): bool
    {
        return $this->execute(
            "UPDATE subareas SET nombre = ?, orden = ? WHERE id = ?",
            [$nombre, $orden, $id]
        );
    }

    public function actualizarCompetencia(
        int $id,
        ?string $codigo,
        string $nombreCompleto,
        ?string $nombreCorto,
        int $orden
    ): bool {
        return $this->execute(
            "UPDATE competencias
             SET codigo_minedu = ?, nombre_completo = ?, nombre_corto = ?, orden = ?
             WHERE id = ?",
            [$codigo, $nombreCompleto, $nombreCorto, $orden, $id]
        );
    }

    public function toggleActivaArea(int $id): bool
    {
        return $this->execute(
            "UPDATE areas SET activa = IF(activa = 1, 0, 1) WHERE id = ?",
            [$id]
        );
    }

    public function moverArea(int $id, string $direccion): bool
    {
        $actual = $this->queryOne(
            "SELECT id, nivel_id, orden FROM areas WHERE id = ?",
            [$id]
        );
        if (!$actual) return false;

        if ($direccion === 'up') {
            $vecino = $this->queryOne(
                "SELECT id, orden FROM areas WHERE nivel_id = ? AND orden < ? ORDER BY orden DESC LIMIT 1",
                [$actual['nivel_id'], $actual['orden']]
            );
        } else {
            $vecino = $this->queryOne(
                "SELECT id, orden FROM areas WHERE nivel_id = ? AND orden > ? ORDER BY orden ASC LIMIT 1",
                [$actual['nivel_id'], $actual['orden']]
            );
        }

        if (!$vecino) return false;

        $this->beginTransaction();
        try {
            $this->execute("UPDATE areas SET orden = ? WHERE id = ?", [$vecino['orden'], $actual['id']]);
            $this->execute("UPDATE areas SET orden = ? WHERE id = ?", [$actual['orden'], $vecino['id']]);
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            return false;
        }
    }
}
