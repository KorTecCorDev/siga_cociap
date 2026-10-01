<?php

namespace App\Models;

/**
 * TutorPeriodoModel — PUNTO ÚNICO del «tutor DEL BIMESTRE» (migración 071).
 *
 * `secciones.tutor_id` es el tutor de HOY: de él salen todas las FUNCIONES del
 * tutor (tutoría, conducta, acompañamiento, cards). Los DOCUMENTOS de un
 * bimestre (boleta, acta de mérito, acompañamiento) nombran en cambio al tutor
 * que la sección tenía AL CERRAR ese bimestre, para que cambiar de tutor a mitad
 * de año no reescriba la firma de los bimestres que el nuevo no evaluó
 * (decisión del usuario, 01/10/2026).
 *
 * Reglas:
 *  - Se congela SOLO al cerrar el bimestre (`congelarPeriodo`), dentro de la
 *    transacción del cierre.
 *  - INMUTABLE: reabrir y re-cerrar conserva el PRIMER congelado (UNIQUE +
 *    INSERT IGNORE).
 *  - Sin fila congelada (bimestre en curso, o sin bimestre) → tutor ACTUAL.
 *  - Una fila congelada con tutor_id NULL significa «sin tutor al cerrar» y se
 *    respeta: NO cae al tutor actual.
 */
class TutorPeriodoModel extends BaseModel
{
    protected string $table = 'secciones_tutor_periodo';

    /**
     * Congela el tutor actual de todas las secciones del año del bimestre.
     * Las secciones que ya tienen fila para ese bimestre no se tocan.
     */
    public function congelarPeriodo(int $periodoId, int $usuarioId): void
    {
        $this->execute("
            INSERT IGNORE INTO secciones_tutor_periodo
                (seccion_id, periodo_id, tutor_id, congelado_por)
            SELECT s.id, p.id, s.tutor_id, ?
            FROM periodos p
            INNER JOIN secciones s ON s.anio_id = p.anio_id
            WHERE p.id = ?
        ", [$usuarioId > 0 ? $usuarioId : null, $periodoId]);
    }

    /**
     * Tutor de una sección en un bimestre (null = tutor actual).
     *
     * @return array{id:int, nombre:string, sexo:?string}|null  null si no hay tutor.
     */
    public function tutorDe(int $seccionId, ?int $periodoId): ?array
    {
        return $this->tutoresDeSecciones([$seccionId], $periodoId)[$seccionId] ?? null;
    }

    /**
     * Versión en lote de `tutorDe`: [seccion_id => tutor|null].
     * El nombre va como en los documentos: «PATERNO MATERNO, Nombres».
     *
     * @param  int[] $seccionIds
     * @return array<int, array{id:int, nombre:string, sexo:?string}|null>
     */
    public function tutoresDeSecciones(array $seccionIds, ?int $periodoId): array
    {
        $ids = array_values(array_unique(array_map('intval', $seccionIds)));
        if (empty($ids)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        // CASE y no COALESCE: una fila congelada con tutor_id NULL («sin tutor al
        // cerrar») NO debe caer al tutor actual.
        $filas = $this->query("
            SELECT s.id AS seccion_id,
                   u.id AS tutor_id,
                   p.apellido_paterno, p.apellido_materno, p.nombres, p.sexo
            FROM secciones s
            LEFT JOIN secciones_tutor_periodo stp
                   ON stp.seccion_id = s.id AND stp.periodo_id = ?
            LEFT JOIN usuarios u
                   ON u.id = CASE WHEN stp.id IS NULL THEN s.tutor_id ELSE stp.tutor_id END
            LEFT JOIN personas p ON p.id = u.persona_id
            WHERE s.id IN ($ph)
        ", array_merge([(int) ($periodoId ?? 0)], $ids));

        $tutores = [];
        foreach ($filas as $f) {
            $tutores[(int) $f['seccion_id']] = empty($f['apellido_paterno'])
                ? null
                : [
                    'id'     => (int) $f['tutor_id'],
                    'nombre' => $f['apellido_paterno'] . ' '
                              . $f['apellido_materno'] . ', '
                              . $f['nombres'],
                    'sexo'   => $f['sexo'],
                ];
        }
        return $tutores;
    }
}
