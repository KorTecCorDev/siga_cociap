<?php

namespace App\Models;

use Core\Session;

class BoletaPublicaModel extends BaseModel
{
    protected string $table = 'boletas_publicas';

    // Sin O, 0, I, 1, L para evitar confusión visual
    private const ALFAS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * RETORNO DE GRADO — la matricula OPERATIVA nunca genera boleta ni token
     * propios: el documento SIEMPRE se emite con la OFICIAL (regla A, 05/08/2026).
     *
     * La condicion NO se escribe aqui: sale de `matricula_documento()`
     * (`app/Helpers/helpers.php`), que desde el 02/09/2026 es el PUNTO UNICO del
     * criterio DOCUMENTO. Antes era la constante privada `SQL_EXCLUIR_OPERATIVA`
     * de esta clase, copiada ademas a mano en el token publico y en el cuadro de
     * `/matriculas/resumen`. El porque completo —y la advertencia sobre el
     * hibrido con `WHERE estado`— vive en el docblock del helper.
     *
     * Se usa aliasando la tabla de matriculas como `m`, que es como la aliasan
     * las tres consultas de abajo.
     */
    private function sqlExcluirOperativa(): string
    {
        return "\n              " . matricula_documento('m');
    }

    /**
     * Candidata a boleta = tiene al menos UNA competencia BLOQUEADA en el
     * periodo, en la matricula que CURSO ese periodo: la propia o —si participa
     * de un retorno de grado y el tramo cubre el periodo— la operativa.
     *
     * POR QUE LA UNION: el retorno reparte las notas por bimestre entre dos
     * matriculas de SECCIONES DISTINTAS (antes del retorno, la oficial; desde
     * el retorno, la operativa). Anclando solo en la oficial, el estudiante
     * DESAPARECE del lote en los bimestres cursados en el grado operativo —
     * que es justo lo que pasaba con el II Bimestre (medido el 05/08/2026:
     * 2° B mostraba 18 aprobables en vez de 19).
     *
     * Hasta el 05/10/2026 solo seguia a la operativa de un retorno ACTIVO: al
     * revertirlo, el estudiante desaparecia del lote de los bimestres que curso
     * en el grado operativo (2° B, II Bimestre: 19 -> 18). Ahora decide el TRAMO
     * guardado (`RetornoGradoModel`, migracion 073).
     *
     * Reemplaza al INNER JOIN calificaciones+bloqueos que habia antes; el
     * EXISTS no multiplica filas, asi que tampoco depende del DISTINCT.
     * Lleva UN parametro posicional: el periodo.
     *
     * Requiere que la tabla de matriculas este aliasada como `m`.
     */
    private static function sqlTieneBloqueos(): string
    {
        return "
              EXISTS (
                  SELECT 1
                  FROM calificaciones cal
                  INNER JOIN matriculas mcal ON mcal.id = cal.matricula_id
                  INNER JOIN bloqueos_competencia bc
                          ON bc.carga_id       = cal.carga_id
                         AND bc.competencia_id = cal.competencia_id
                         AND bc.periodo_id     = cal.periodo_id
                  WHERE cal.periodo_id = ?
                    AND cal.matricula_id IN " . RetornoGradoModel::sqlFuentes('m') . "
                    " . RetornoGradoModel::sqlCursoElPeriodo('mcal', 'cal.periodo_id') . "
              )";
    }

    /**
     * Genera un código único con formato COCIAP-{anio}-B{bimestre}-XXXXXX.
     */
    public function generarCodigo(int $anio, int $numBimestre): string
    {
        do {
            $rand = '';
            for ($i = 0; $i < 6; $i++) {
                $rand .= self::ALFAS[random_int(0, strlen(self::ALFAS) - 1)];
            }
            $codigo = "COCIAP-{$anio}-B{$numBimestre}-{$rand}";
            $existe  = $this->findBy('codigo_acceso', $codigo);
        } while ($existe);

        return $codigo;
    }

    /**
     * Genera boletas para todas las matrículas aprobadas con ≥1 competencia
     * bloqueada en el periodo. Usa INSERT IGNORE para no duplicar.
     * Retorna el número de boletas nuevas generadas.
     */
    public function generarMasivo(int $periodoId, int $usuarioId): int
    {
        $periodo = $this->queryOne("
            SELECT p.numero, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
            LIMIT 1
        ", [$periodoId]);

        if (!$periodo) return 0;

        $matriculas = $this->query("
            SELECT DISTINCT m.id AS matricula_id
            FROM matriculas m
            INNER JOIN calificaciones cal
                ON cal.matricula_id = m.id AND cal.periodo_id = ?
            INNER JOIN bloqueos_competencia bc
                ON bc.carga_id       = cal.carga_id
               AND bc.competencia_id = cal.competencia_id
               AND bc.periodo_id     = cal.periodo_id
            WHERE m.estado = 'aprobada'
        ", [$periodoId]);

        $insertadas = 0;
        foreach ($matriculas as $mat) {
            $existe = $this->queryOne(
                "SELECT id FROM boletas_publicas WHERE matricula_id = ? AND periodo_id = ?",
                [$mat['matricula_id'], $periodoId]
            );
            if ($existe) continue;

            $codigo = $this->generarCodigo((int) $periodo['anio'], (int) $periodo['numero']);
            $this->execute(
                "INSERT INTO boletas_publicas (matricula_id, periodo_id, codigo_acceso, generada_por)
                 VALUES (?, ?, ?, ?)",
                [$mat['matricula_id'], $periodoId, $codigo, $usuarioId]
            );
            $insertadas++;
        }

        return $insertadas;
    }

    /**
     * Matrículas aprobadas que tienen al menos una competencia bloqueada en el
     * periodo dado. Es el conjunto candidato a generar boleta pública y también
     * el que alimenta la vista previa antes de la aprobación del registro
     * académico. Si se pasa $seccionId, filtra a esa sección (loteo por sección
     * para evitar timeouts al renderizar todas las boletas a la vez).
     *
     * RETORNO DE GRADO: lista SIEMPRE la matrícula oficial (y en su sección
     * oficial), nunca la operativa. Ver sqlExcluirOperativa() y
     * sqlTieneBloqueos().
     */
    public function getMatriculasAprobadasParaBoleta(int $periodoId, ?int $seccionId = null): array
    {
        $whereSeccion = $seccionId ? 'AND s.id = ?' : '';
        $params       = $seccionId ? [$periodoId, $seccionId] : [$periodoId];

        return $this->query("
            SELECT DISTINCT
                m.id            AS matricula_id,
                CONCAT(
                    per.apellido_paterno, ' ',
                    per.apellido_materno, ', ',
                    per.nombres
                )                AS nombre_completo,
                g.nombre_display AS grado_nombre,
                s.nombre         AS seccion_nombre,
                g.id             AS grado_id
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id   = m.estudiante_id
            INNER JOIN personas per  ON per.id = e.persona_id
            INNER JOIN secciones s   ON s.id   = m.seccion_id
            INNER JOIN grados g      ON g.id   = s.grado_id
            WHERE m.estado = 'aprobada'"
              . $this->sqlExcluirOperativa() . "
              AND " . self::sqlTieneBloqueos() . "
              {$whereSeccion}
            ORDER BY g.id, s.nombre, " . orden_alfabetico('per') . "
        ", $params);
    }

    /**
     * Estudiantes con boleta OFICIAL (≥1 competencia bloqueada) en el periodo,
     * con su token permanente y el contador de visitas. Reemplaza a
     * getPorPeriodo (basado en código) para el hub del admin, ahora centrado
     * en el token. Si se pasa $seccionId, filtra a esa sección (loteo).
     *
     * RETORNO DE GRADO: expone SOLO el token de la matrícula oficial. El de la
     * operativa no se entrega ni se lista (decisión del 05/08/2026); la ruta
     * pública además lo rechaza.
     */
    public function getEstudiantesParaPeriodo(int $periodoId, ?int $seccionId = null): array
    {
        $whereSeccion = $seccionId ? 'AND s.id = ?' : '';
        $params       = $seccionId ? [$periodoId, $seccionId] : [$periodoId];

        return $this->query("
            SELECT DISTINCT
                m.id             AS matricula_id,
                CONCAT(
                    per.apellido_paterno, ' ',
                    per.apellido_materno, ', ',
                    per.nombres
                )                AS nombre_completo,
                s.id             AS seccion_id,
                s.nombre         AS seccion_nombre,
                g.nombre_display AS grado_nombre,
                n.nombre         AS nivel_nombre,
                m.token_acceso,
                m.token_consultas,
                m.token_ultima_consulta
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id   = m.estudiante_id
            INNER JOIN personas per  ON per.id = e.persona_id
            INNER JOIN secciones s   ON s.id   = m.seccion_id
            INNER JOIN grados g      ON g.id   = s.grado_id
            INNER JOIN niveles n     ON n.id   = g.nivel_id
            WHERE m.estado = 'aprobada'"
              . $this->sqlExcluirOperativa() . "
              AND " . self::sqlTieneBloqueos() . "
              {$whereSeccion}
            ORDER BY n.id, g.numero, s.nombre,
                     " . orden_alfabetico('per') . "
        ", $params);
    }

    /**
     * Secciones del año activo agregadas para el periodo dado, con dos
     * conteos: matrículas aprobables (con ≥1 competencia bloqueada) y
     * boletas ya generadas. Solo devuelve secciones con al menos una
     * matrícula aprobable — las demás no aportan nada al loteo.
     * Una sola query con LEFT JOINs condicionales para evitar N+1.
     *
     * RETORNO DE GRADO: el contador tiene que cuadrar con lo que devuelve
     * getMatriculasAprobadasParaBoleta, así que aplica el MISMO criterio
     * (operativa fuera, bloqueos por unión). Si divergieran, RA vería "19
     * aprobables" y le saldrían 18 boletas.
     */
    public function getSeccionesParaPeriodo(int $periodoId): array
    {
        return $this->query("
            SELECT
                s.id                                                        AS seccion_id,
                s.nombre                                                    AS seccion_nombre,
                g.nombre_display                                            AS grado_nombre,
                g.numero                                                    AS grado_numero,
                n.id                                                        AS nivel_id,
                n.nombre                                                    AS nivel_nombre,
                COUNT(DISTINCT CASE WHEN " . self::sqlTieneBloqueos() . "
                                    THEN m.id END)                          AS total_aprobables,
                COUNT(DISTINCT bp.matricula_id)                             AS total_generadas
            FROM secciones s
            INNER JOIN grados            g ON g.id = s.grado_id
            INNER JOIN niveles           n ON n.id = g.nivel_id
            INNER JOIN anios_academicos  a ON a.id = s.anio_id AND a.estado = 'activo'
            INNER JOIN matriculas        m ON m.seccion_id = s.id AND m.estado = 'aprobada'"
                                          . $this->sqlExcluirOperativa() . "
            LEFT JOIN boletas_publicas   bp ON bp.matricula_id = m.id AND bp.periodo_id = ?
            WHERE s.estado_nomina = 'aprobada'
            GROUP BY s.id
            HAVING total_aprobables > 0
            ORDER BY n.id, g.numero, s.nombre
        ", [$periodoId, $periodoId]);
    }

    /**
     * Lista boletas de un periodo con datos del estudiante, grado y sección.
     * Incluye novedades_count: competencias bloqueadas DESPUÉS de que se generó la boleta.
     * Si se pasa $seccionId, filtra a esa sección (loteo).
     */
    public function getPorPeriodo(int $periodoId, ?int $seccionId = null): array
    {
        $whereSeccion = $seccionId ? 'AND s.id = ?' : '';
        $params       = $seccionId ? [$periodoId, $seccionId] : [$periodoId];

        return $this->query("
            SELECT
                bp.id,
                bp.matricula_id,
                bp.codigo_acceso,
                bp.veces_consultada,
                bp.ultima_consulta,
                bp.generada_en,
                m.token_acceso,
                CONCAT(
                    per.apellido_paterno, ' ',
                    per.apellido_materno, ', ',
                    per.nombres
                )                AS nombre_completo,
                s.id             AS seccion_id,
                g.nombre_display AS grado_nombre,
                s.nombre         AS seccion_nombre,
                n.nombre         AS nivel_nombre,
                (
                    SELECT COUNT(*)
                    FROM calificaciones cal
                    INNER JOIN bloqueos_competencia bc
                        ON  bc.carga_id       = cal.carga_id
                        AND bc.competencia_id = cal.competencia_id
                        AND bc.periodo_id     = cal.periodo_id
                    WHERE cal.matricula_id = bp.matricula_id
                      AND cal.periodo_id   = bp.periodo_id
                      AND bc.bloqueado_en  > bp.generada_en
                )                AS novedades_count
            FROM boletas_publicas bp
            INNER JOIN matriculas m  ON m.id   = bp.matricula_id
            INNER JOIN estudiantes e ON e.id   = m.estudiante_id
            INNER JOIN personas per  ON per.id = e.persona_id
            INNER JOIN secciones s   ON s.id   = m.seccion_id
            INNER JOIN grados g      ON g.id   = s.grado_id
            INNER JOIN niveles n     ON n.id   = g.nivel_id
            WHERE bp.periodo_id = ?
              AND m.estado <> 'desactivado'
              {$whereSeccion}
            ORDER BY n.id, g.numero, s.nombre, " . orden_alfabetico('per') . "
        ", $params);
    }

    /**
     * Actualiza generada_en a NOW() para las boletas que tienen competencias
     * bloqueadas DESPUÉS de su fecha de generación.
     * Retorna el número de boletas actualizadas.
     */
    public function actualizarTimestamps(int $periodoId, int $usuarioId): int
    {
        // Contar cuántas serán actualizadas
        $row = $this->queryOne("
            SELECT COUNT(*) AS total
            FROM boletas_publicas bp
            WHERE bp.periodo_id = ?
              AND EXISTS (
                  SELECT 1
                  FROM calificaciones cal
                  INNER JOIN bloqueos_competencia bc
                      ON  bc.carga_id       = cal.carga_id
                      AND bc.competencia_id = cal.competencia_id
                      AND bc.periodo_id     = cal.periodo_id
                  WHERE cal.matricula_id = bp.matricula_id
                    AND cal.periodo_id   = bp.periodo_id
                    AND bc.bloqueado_en  > bp.generada_en
              )
        ", [$periodoId]);

        $total = (int) ($row['total'] ?? 0);
        if ($total === 0) return 0;

        $this->execute("
            UPDATE boletas_publicas bp
            SET bp.generada_en  = NOW(),
                bp.generada_por = ?
            WHERE bp.periodo_id = ?
              AND EXISTS (
                  SELECT 1
                  FROM calificaciones cal2
                  INNER JOIN bloqueos_competencia bc2
                      ON  bc2.carga_id       = cal2.carga_id
                      AND bc2.competencia_id = cal2.competencia_id
                      AND bc2.periodo_id     = cal2.periodo_id
                  WHERE cal2.matricula_id = bp.matricula_id
                    AND cal2.periodo_id   = bp.periodo_id
                    AND bc2.bloqueado_en  > bp.generada_en
              )
        ", [$usuarioId, $periodoId]);

        return $total;
    }

    /**
     * Genera un token hex-32 único para todas las matrículas aprobadas
     * que aún no tienen token_acceso. Idempotente.
     * Retorna el número de tokens generados.
     */
    public function generarTokensActivos(): int
    {
        $matriculas = $this->query(
            "SELECT id FROM matriculas WHERE estado = 'aprobada' AND token_acceso IS NULL"
        );

        $count = 0;
        foreach ($matriculas as $m) {
            do {
                $token = bin2hex(random_bytes(16));
                $existe = $this->queryOne(
                    "SELECT id FROM matriculas WHERE token_acceso = ? LIMIT 1",
                    [$token]
                );
            } while ($existe);

            $this->execute(
                "UPDATE matriculas SET token_acceso = ? WHERE id = ?",
                [$token, $m['id']]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Devuelve el token_acceso PERMANENTE de una matrícula, generándolo si aún
     * no existe (mismo formato hex-32 único que generarTokensActivos). El token
     * es estable durante todo el año académico: existe UN solo enlace/QR por
     * estudiante. Úsese para que la boleta impresa lleve siempre el mismo QR.
     */
    public function getOCrearToken(int $matriculaId): string
    {
        $row = $this->queryOne(
            "SELECT token_acceso FROM matriculas WHERE id = ? LIMIT 1",
            [$matriculaId]
        );

        if ($row && !empty($row['token_acceso'])) {
            return $row['token_acceso'];
        }

        do {
            $token  = bin2hex(random_bytes(16));
            $existe = $this->queryOne(
                "SELECT id FROM matriculas WHERE token_acceso = ? LIMIT 1",
                [$token]
            );
        } while ($existe);

        $this->execute(
            "UPDATE matriculas SET token_acceso = ? WHERE id = ?",
            [$token, $matriculaId]
        );

        return $token;
    }

    /**
     * Registra una visita a la boleta vía token (QR de la boleta impresa o
     * portal del padre). Cuenta por ESTUDIANTE en la propia matrícula —
     * desacoplado del sistema de código (`boletas_publicas`), que quedó dormido.
     * El token es uno por estudiante y permanente todo el año, así que la unidad
     * natural de conteo es la matrícula identidad (no el periodo: el QR es el
     * mismo token en el papel de B1..B4).
     */
    public function registrarVisitaToken(int $matriculaId): void
    {
        $this->execute(
            "UPDATE matriculas
             SET token_consultas       = token_consultas + 1,
                 token_ultima_consulta = NOW()
             WHERE id = ?",
            [$matriculaId]
        );
    }

    /**
     * Busca por código; si existe incrementa el contador de consultas.
     * Retorna el registro completo (matricula_id + periodo_id para getBoletaAlumno).
     *
     * El estado de la matrícula es la fuente de verdad: una matrícula
     * 'desactivado' (baja administrativa o traslado) NO debe exponer su boleta
     * pública aunque el código impreso siga circulando. Se valida también
     * bp.activa por consistencia con el flag que escriben desactivar()/traslado.
     */
    public function getPorCodigo(string $codigo): ?array
    {
        $registro = $this->queryOne("
            SELECT bp.*
            FROM boletas_publicas bp
            INNER JOIN matriculas m ON m.id = bp.matricula_id
            WHERE bp.codigo_acceso = ?
              AND bp.activa = 1
              AND m.estado <> 'desactivado'
            LIMIT 1
        ", [$codigo]);

        if (!$registro) return null;

        $this->execute(
            "UPDATE boletas_publicas
             SET veces_consultada = veces_consultada + 1,
                 ultima_consulta  = NOW()
             WHERE id = ?",
            [$registro['id']]
        );

        return $registro;
    }
}
