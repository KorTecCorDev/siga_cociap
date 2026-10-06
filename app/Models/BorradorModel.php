<?php

namespace App\Models;

/**
 * BorradorModel — PUNTO ÚNICO de los borradores de formularios (migración 074).
 *
 * Lo tecleado en un formulario de carga manual se autoguarda aquí, por
 * usuario, para recuperarlo al volver (otro equipo, navegador cerrado, sesión
 * vencida). Ver `docs/modulos/borradores-y-sesion.md`.
 *
 * 🔴 REGLA CENTRAL: un borrador NUNCA toca las tablas oficiales. Lo oficial lo
 * escribe el POST de siempre, con sus validaciones; el borrador solo devuelve
 * los valores a los campos. Se elimina SOLO tras un guardado oficial exitoso.
 *
 * 🔴 SEGURIDAD:
 *   · `usuario_id` sale SIEMPRE de la sesión (quien llama), nunca del request.
 *   · La clave la arma `clave()` con ENTEROS: el cliente nunca la envía.
 *   · `deOtros()` devuelve nombre y fecha, NUNCA el contenido.
 */
class BorradorModel extends BaseModel
{
    protected string $table = 'borradores_formulario';

    /**
     * Formularios con borrador y los ids de contexto que forman su clave. Se
     * habilitan por fase: un tipo que no esté aquí se rechaza.
     */
    public const TIPOS = [
        'rect_lote'           => ['matricula', 'periodo'],
        'rect_competencia'    => ['matricula', 'carga', 'competencia', 'periodo'],
        'rect_extraordinaria' => ['matricula', 'carga', 'competencia', 'periodo'],
        // Un formulario POR BIMESTRE en la misma pantalla: la clave lleva el periodo.
        'notas_siagie'        => ['matricula', 'periodo'],
        // Un solo formulario por estudiante (varios bimestres en filas libres).
        'notas_origen'        => ['matricula'],
    ];

    /** Prefijo de cada id en la clave (explícito: carga y competencia comparten inicial). */
    private const PREFIJOS = [
        'matricula' => 'm', 'periodo' => 'p', 'carga' => 'ca', 'competencia' => 'co',
    ];

    /** Tope del JSON de un borrador (S6). El lote más grande pesa unos pocos KB. */
    public const MAX_BYTES = 262144;

    /**
     * Clave del contexto, o null si el tipo no existe o falta un id. Ej.:
     * `m698-p1`. Solo enteros positivos: nada del request llega crudo.
     */
    public static function clave(string $tipo, array $ctx): ?string
    {
        if (!isset(self::TIPOS[$tipo])) {
            return null;
        }
        $partes = [];
        foreach (self::TIPOS[$tipo] as $campo) {
            $id = filter_var($ctx[$campo] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                return null;
            }
            $partes[] = self::PREFIJOS[$campo] . $id;
        }
        return implode('-', $partes);
    }

    /**
     * ¿El contexto existe y es coherente? La matrícula existe, el periodo es
     * de SU año y, si el formulario lleva carga, la carga también es de ese
     * año. No repite la regla «rectificable» / «insertable» / «elegible» del
     * formulario (cara, y el borrador se guarda cada pocos segundos): el
     * guardado oficial la vuelve a exigir, y un borrador no escribe nada
     * oficial. La carga no se ata a la SECCIÓN de la matrícula a propósito:
     * en un retorno de grado el bimestre se cursa en otra.
     */
    public function contextoValido(string $tipo, array $ctx): bool
    {
        if (!isset(self::TIPOS[$tipo])) {
            return false;
        }
        $matricula = (int) ($ctx['matricula'] ?? 0);

        // Sin periodo en la clave (notas de origen): basta con que la matrícula exista.
        if (!in_array('periodo', self::TIPOS[$tipo], true)) {
            return $this->queryOne("SELECT 1 AS x FROM matriculas WHERE id = ? LIMIT 1", [$matricula]) !== null;
        }

        $periodo = (int) ($ctx['periodo'] ?? 0);
        $fila = $this->queryOne("
            SELECT m.anio_id
            FROM matriculas m
            INNER JOIN periodos p ON p.anio_id = m.anio_id
            WHERE m.id = ? AND p.id = ?
            LIMIT 1
        ", [$matricula, $periodo]);
        if ($fila === null) {
            return false;
        }

        if (in_array('carga', self::TIPOS[$tipo], true)) {
            $carga = $this->queryOne(
                "SELECT 1 AS x FROM cargas_academicas WHERE id = ? AND anio_id = ? LIMIT 1",
                [(int) ($ctx['carga'] ?? 0), (int) $fila['anio_id']]
            );
            if ($carga === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Borrador del usuario para ese formulario, o null.
     *
     * @return array{datos: array, revision: int, actualizado_en: string}|null
     */
    public function obtener(int $usuarioId, string $tipo, string $clave): ?array
    {
        $fila = $this->queryOne("
            SELECT datos, revision, actualizado_en
            FROM borradores_formulario
            WHERE usuario_id = ? AND tipo = ? AND clave = ?
        ", [$usuarioId, $tipo, $clave]);

        if ($fila === null) {
            return null;
        }
        $datos = json_decode((string) $fila['datos'], true);
        return [
            'datos'          => is_array($datos) ? $datos : [],
            'revision'       => (int) $fila['revision'],
            'actualizado_en' => (string) $fila['actualizado_en'],
        ];
    }

    /**
     * Guarda el borrador si la `revision` que conoce el cliente es la vigente
     * (S8: dos pestañas o equipos no se pisan en silencio). 0 = el cliente no
     * conocía ninguno.
     *
     * @return array{ok: bool, revision: int}  ok=false ⇒ conflicto
     */
    public function guardar(
        int $usuarioId,
        int $matriculaId,
        string $tipo,
        string $clave,
        string $json,
        int $revisionConocida
    ): array {
        // Actualiza solo si nadie lo cambió desde que este cliente lo leyó.
        $stmt = $this->db->prepare("
            UPDATE borradores_formulario
            SET datos = ?, revision = revision + 1
            WHERE usuario_id = ? AND tipo = ? AND clave = ? AND revision = ?
        ");
        $stmt->execute([$json, $usuarioId, $tipo, $clave, $revisionConocida]);
        if ($stmt->rowCount() === 1) {
            return ['ok' => true, 'revision' => $revisionConocida + 1];
        }

        // No existía (o la revisión no calza). Solo crea si el cliente no
        // conocía ninguno; INSERT IGNORE resuelve la carrera con el UNIQUE.
        if ($revisionConocida === 0) {
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO borradores_formulario
                    (usuario_id, matricula_id, tipo, clave, datos, revision)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$usuarioId, $matriculaId, $tipo, $clave, $json]);
            if ($stmt->rowCount() === 1) {
                return ['ok' => true, 'revision' => 1];
            }
        }

        $actual = $this->queryOne("
            SELECT revision FROM borradores_formulario
            WHERE usuario_id = ? AND tipo = ? AND clave = ?
        ", [$usuarioId, $tipo, $clave]);

        return ['ok' => false, 'revision' => (int) ($actual['revision'] ?? 0)];
    }

    /**
     * Otros usuarios con un borrador del MISMO formulario: nombre y fecha,
     * nunca el contenido (S1, S11). Para avisar y no duplicar trabajo.
     *
     * @return array<int, array{nombre: string, actualizado_en: string}>
     */
    public function deOtros(string $tipo, string $clave, int $usuarioId): array
    {
        return $this->query("
            SELECT CONCAT(per.nombres, ' ', per.apellido_paterno) AS nombre,
                   b.actualizado_en
            FROM borradores_formulario b
            INNER JOIN usuarios u  ON u.id = b.usuario_id
            INNER JOIN personas per ON per.id = u.persona_id
            WHERE b.tipo = ? AND b.clave = ? AND b.usuario_id <> ?
            ORDER BY b.actualizado_en DESC
        ", [$tipo, $clave, $usuarioId]);
    }

    /** Elimina el borrador del usuario: SOLO tras un guardado oficial exitoso. */
    public function eliminar(int $usuarioId, string $tipo, string $clave): void
    {
        $this->execute("
            DELETE FROM borradores_formulario
            WHERE usuario_id = ? AND tipo = ? AND clave = ?
        ", [$usuarioId, $tipo, $clave]);
    }
}
