<?php

namespace App\Models;

/**
 * SiagieExportModel
 *
 * Capa de datos del volcado de notas SIGA → Excel SIAGIE (registro oficial
 * ante UGEL-MINEDU). Reutiliza las fuentes de verdad existentes:
 *   - CalificacionModel::boletaContexto/getBoletaAlumno → solo competencias
 *     BLOQUEADAS + transversales agregadas con cierre vigente del tutor,
 *     con unión oficial/operativa en retorno de grado.
 *   - ExoneracionModel → competencias exoneradas (celdas que se omiten).
 *
 * Es PURO respecto a la presentación: no sabe de Excel. Lo consume el CLI
 * scripts/siagie/llenar-siagie.php y, a futuro, el módulo web.
 */
class SiagieExportModel extends BaseModel
{
    private CalificacionModel $calModel;
    private ExoneracionModel  $exoModel;
    private NotaAutorizadaSiagieModel $autModel;

    public function __construct()
    {
        parent::__construct();
        $this->calModel = new CalificacionModel();
        $this->exoModel = new ExoneracionModel();
        $this->autModel = new NotaAutorizadaSiagieModel();
    }

    /**
     * Resuelve los parámetros del archivo SIAGIE (hoja oculta "Parametros")
     * contra las entidades de SIGA. Devuelve ['error' => string] si algo no
     * calza — el archivo completo se rechaza con ese motivo.
     *
     * @param array $p ['anio'=>2026, 'nivel_nombre'=>'Primaria',
     *                  'periodo_codigo'=>'B1', 'seccion_texto'=>'1A']
     */
    public function resolverDestino(array $p): array
    {
        $anio = $this->queryOne(
            "SELECT id FROM anios_academicos WHERE anio = ?",
            [(int) ($p['anio'] ?? 0)]
        );
        if (!$anio) {
            return ['error' => "Año académico {$p['anio']} no existe en SIGA"];
        }

        $nivel = $this->queryOne(
            "SELECT id, codigo, nombre FROM niveles WHERE UPPER(nombre) = ?",
            [mb_strtoupper(trim((string) ($p['nivel_nombre'] ?? '')))]
        );
        if (!$nivel) {
            return ['error' => "Nivel '{$p['nivel_nombre']}' no existe en SIGA"];
        }

        if (!preg_match('/^(\d)\s*([A-ZÑ])$/u', mb_strtoupper(trim((string) ($p['seccion_texto'] ?? ''))), $m)) {
            return ['error' => "Sección '{$p['seccion_texto']}' con formato no reconocido (se espera '1A')"];
        }
        $gradoNumero  = (int) $m[1];
        $seccionLetra = $m[2];

        $grado = $this->queryOne(
            "SELECT id, nombre_display FROM grados WHERE nivel_id = ? AND numero = ?",
            [$nivel['id'], $gradoNumero]
        );
        if (!$grado) {
            return ['error' => "Grado {$gradoNumero}° de {$nivel['nombre']} no existe en SIGA"];
        }

        $seccion = $this->queryOne(
            "SELECT id, nombre FROM secciones WHERE grado_id = ? AND anio_id = ? AND nombre = ?",
            [$grado['id'], $anio['id'], $seccionLetra]
        );
        if (!$seccion) {
            return ['error' => "Sección {$gradoNumero}{$seccionLetra} de {$nivel['nombre']} no existe en el año {$p['anio']}"];
        }

        if (!preg_match('/^B(\d)$/', strtoupper(trim((string) ($p['periodo_codigo'] ?? ''))), $mp)) {
            return ['error' => "Periodo '{$p['periodo_codigo']}' con formato no reconocido (se espera 'B1'..'B4')"];
        }
        $periodo = $this->queryOne(
            "SELECT id, numero, estado, nombre_display FROM periodos WHERE anio_id = ? AND numero = ?",
            [$anio['id'], (int) $mp[1]]
        );
        if (!$periodo) {
            return ['error' => "Bimestre {$mp[1]} no existe en el año {$p['anio']}"];
        }

        return [
            'anio_id'         => (int) $anio['id'],
            'nivel_id'        => (int) $nivel['id'],
            'nivel_codigo'    => $nivel['codigo'],
            'nivel_nombre'    => $nivel['nombre'],
            'grado_id'        => (int) $grado['id'],
            'grado_numero'    => $gradoNumero,
            'seccion_id'      => (int) $seccion['id'],
            'seccion_nombre'  => $seccion['nombre'],
            'periodo_id'      => (int) $periodo['id'],
            'periodo_numero'  => (int) $periodo['numero'],
            'periodo_estado'  => $periodo['estado'],
            'periodo_nombre'  => $periodo['nombre_display'],
        ];
    }

    /**
     * Universo de estudiantes de la sección para el matching: matrículas
     * APROBADAS, excluyendo las OPERATIVAS de un retorno de grado, activo o
     * revertido (ese alumno figura en el archivo SIAGIE de su sección OFICIAL).
     * Punto único: `matricula_documento()`.
     */
    public function estudiantesDeSeccion(int $seccionId): array
    {
        return $this->query("
            SELECT
                m.id  AS matricula_id,
                e.id  AS estudiante_id,
                e.codigo_estudiante,
                p.dni,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            WHERE m.seccion_id = ?
              AND m.estado     = 'aprobada'
              " . matricula_documento('m') . "
            ORDER BY " . orden_alfabetico('p') . "
        ", [$seccionId]);
    }

    /**
     * Estudiantes de las OTRAS secciones del mismo grado y año (para detectar
     * un cambio de sección sin tramitar: la fila del SIAGIE pertenece a un
     * alumno que en SIGA sigue en otra sección). Mismos filtros que
     * estudiantesDeSeccion (aprobadas, excluye operativas de retorno)
     * MÁS la sección de origen (id + nombre) para poder informarla.
     */
    public function estudiantesDeOtrasSecciones(int $gradoId, int $anioId, int $seccionExcluida): array
    {
        return $this->query("
            SELECT
                m.id     AS matricula_id,
                e.id     AS estudiante_id,
                e.codigo_estudiante,
                p.dni,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                s.id     AS seccion_id,
                s.nombre AS seccion_nombre
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            INNER JOIN secciones s    ON s.id = m.seccion_id
            WHERE s.grado_id   = ?
              AND s.anio_id    = ?
              AND m.seccion_id <> ?
              AND m.estado     = 'aprobada'
              " . matricula_documento('m') . "
            ORDER BY s.nombre, " . orden_alfabetico('p') . "
        ", [$gradoId, $anioId, $seccionExcluida]);
    }

    /**
     * Notas oficiales del alumno en el periodo, indexadas por competencia_id.
     * Reutiliza boletaContexto (unión oficial/operativa en retorno) y
     * getBoletaAlumno (solo bloqueadas + transversales con cierre del tutor).
     * En choque por competencia gana la fuente posterior (la oficial).
     *
     * @return array competencia_id => ['nota_numerica'=>int, 'conclusion'=>?string]
     */
    public function notasOficiales(int $matriculaId, int $periodoId): array
    {
        $ctx   = $this->calModel->boletaContexto($matriculaId);
        $notas = [];
        foreach ($ctx['fuentes'] as $fuente) {
            foreach ($this->calModel->getBoletaAlumno((int) $fuente, $periodoId) as $fila) {
                $notas[(int) $fila['competencia_id']] = [
                    'nota_numerica' => (int) $fila['nota_numerica'],
                    'conclusion'    => $fila['conclusion_descriptiva'] !== null
                        ? trim((string) $fila['conclusion_descriptiva'])
                        : null,
                ];
            }
        }
        return $notas;
    }

    /**
     * Conclusiones de RÉPLICA del acta (migración 072): [area_id => texto]
     * del alumno en el periodo. Une las fuentes del retorno de grado como
     * `notasOficiales`. El llenador las usa solo cuando la conclusión de la
     * fuente es obligatoria (ver `ConclusionReplicaModel`).
     */
    public function conclusionesReplica(int $matriculaId, int $periodoId): array
    {
        $ctx = $this->calModel->boletaContexto($matriculaId);
        return (new ConclusionReplicaModel())->paraExport($ctx['fuentes'], $periodoId);
    }

    /**
     * Notas AUTORIZADAS por dirección (solo SIAGIE) del alumno en el periodo.
     * Rellenan la celda que quedaría en blanco por ausencia justificada. No
     * salen de `calificaciones` (nunca tocan boleta ni mérito); son el "informe
     * aparte" de la migración 040. La precedencia la resuelve el llenador: solo
     * se usan cuando NO hay nota oficial.
     *
     * En RETORNO de grado el alumno se evalúa en la matrícula OPERATIVA pero el
     * export procesa la OFICIAL: se unen las fuentes (igual que notasOficiales)
     * para encontrar la nota autorizada esté registrada en la que esté.
     *
     * @return array competencia_id => ['literal'=>string, 'conclusion'=>?string]
     */
    public function notasAutorizadas(int $matriculaId, int $periodoId): array
    {
        $ctx = $this->calModel->boletaContexto($matriculaId);
        $out = [];
        foreach ($ctx['fuentes'] as $fuente) {
            foreach ($this->autModel->getParaExport((int) $fuente, $periodoId) as $compId => $val) {
                $out[$compId] = $val;   // en choque gana la fuente posterior (la oficial)
            }
        }
        return $out;
    }

    /**
     * Set de competencias exoneradas del alumno en el año (unión de fuentes
     * en retorno). Sus celdas en el SIAGIE están bloqueadas → se omiten.
     *
     * @return array competencia_id => true
     */
    public function competenciasExoneradas(int $matriculaId, int $anioId): array
    {
        $ctx = $this->calModel->boletaContexto($matriculaId);
        $set = [];
        foreach ($this->exoModel->getConCompetenciasParaBoletaUnion($ctx['fuentes'], $anioId) as $fila) {
            $set[(int) $fila['competencia_id']] = true;
        }
        return $set;
    }

    /**
     * Catálogo de competencias del nivel (directas y vía subáreas) para
     * mapear la leyenda de cada hoja SIAGIE → competencia SIGA.
     */
    public function competenciasDelNivel(int $nivelId): array
    {
        return $this->query("
            SELECT
                c.id   AS competencia_id,
                c.nombre_completo,
                c.codigo_minedu,
                a.id   AS area_id,
                a.nombre AS area_nombre,
                a.tipo AS area_tipo
            FROM competencias c
            LEFT JOIN subareas s ON s.id = c.subarea_id
            INNER JOIN areas a   ON a.id = COALESCE(c.area_id, s.area_id)
            WHERE a.nivel_id = ?
            ORDER BY a.orden, c.orden
        ", [$nivelId]);
    }

    /**
     * Área del nivel cuya `codigo_siagie` incluye el código de la hoja SIAGIE
     * (p. ej. '063' → Matemática). `codigo_siagie` puede ser compuesto
     * ('0006,0007' para las dos hojas transversales) → se busca por FIND_IN_SET.
     * Devuelve null si ninguna área tiene ese código (hoja sin equivalente, o
     * nivel aún sin poblar como primaria).
     */
    public function areaPorCodigoSiagie(int $nivelId, string $codigo): ?array
    {
        return $this->queryOne("
            SELECT id, nombre, tipo
            FROM areas
            WHERE nivel_id = ?
              AND codigo_siagie IS NOT NULL
              AND FIND_IN_SET(?, codigo_siagie)
            LIMIT 1
        ", [$nivelId, $codigo]) ?: null;
    }

    /**
     * Competencias de UN área (directas y vía subáreas), en orden. Se usa para
     * resolver una columna dentro de su área: desambiguar homónimos (Matemática
     * vs Taller) por área, o asignar por posición cuando la leyenda SIAGIE es
     * abreviada (Inglés). Misma forma que competenciasDelNivel.
     */
    public function competenciasDeArea(int $areaId): array
    {
        return $this->query("
            SELECT
                c.id   AS competencia_id,
                c.nombre_completo,
                c.codigo_minedu,
                a.id   AS area_id,
                a.nombre AS area_nombre,
                a.tipo AS area_tipo
            FROM competencias c
            LEFT JOIN subareas s ON s.id = c.subarea_id
            INNER JOIN areas a   ON a.id = COALESCE(c.area_id, s.area_id)
            WHERE a.id = ?
            ORDER BY c.orden
        ", [$areaId]);
    }

    /**
     * Competencias del área del nivel identificada por su `nombre_boleta`
     * (p. ej. 'Ética y Valores'). Ancla por NOMBRE y no por id: el id del área
     * difiere entre entornos — mismo criterio que el orden de mérito
     * (`AREA_ETICA_NOMBRE_BOLETA` en helpers). Devuelve [] si no existe.
     *
     * La usan las EXCEPCIONES DE HOJA del llenador (ver `LlenadorSiagie`).
     */
    public function competenciasDeAreaPorNombreBoleta(int $nivelId, string $nombreBoleta): array
    {
        return $this->query("
            SELECT
                c.id   AS competencia_id,
                c.nombre_completo,
                c.codigo_minedu,
                a.id   AS area_id,
                a.nombre AS area_nombre,
                a.tipo AS area_tipo
            FROM competencias c
            LEFT JOIN subareas s ON s.id = c.subarea_id
            INNER JOIN areas a   ON a.id = COALESCE(c.area_id, s.area_id)
            WHERE a.nivel_id      = ?
              AND a.nombre_boleta = ?
            ORDER BY c.orden
        ", [$nivelId, $nombreBoleta]);
    }

    /**
     * Competencia del nivel por su `codigo_minedu` (p. ej. 'CT4' = GAMA,
     * 'Gestiona su aprendizaje de manera autónoma'). Ancla por el código
     * oficial, NUNCA por id: el id 57 es GAMA pero el código C57 es la
     * competencia de Ética — confundirlos escribiría la nota equivocada en un
     * acta oficial. Devuelve null si no existe o si el código está repetido
     * en el nivel (ambiguo → el llenador no aplica la excepción).
     */
    public function competenciaPorCodigoMinedu(int $nivelId, string $codigoMinedu): ?array
    {
        $filas = $this->query("
            SELECT
                c.id   AS competencia_id,
                c.nombre_completo,
                c.codigo_minedu,
                a.id   AS area_id,
                a.nombre AS area_nombre,
                a.tipo AS area_tipo
            FROM competencias c
            LEFT JOIN subareas s ON s.id = c.subarea_id
            INNER JOIN areas a   ON a.id = COALESCE(c.area_id, s.area_id)
            WHERE a.nivel_id      = ?
              AND c.codigo_minedu = ?
            LIMIT 2
        ", [$nivelId, $codigoMinedu]);

        return count($filas) === 1 ? $filas[0] : null;
    }

    /**
     * DIAGNÓSTICO DE COBERTURA: qué áreas tienen notas exportables en el periodo
     * y cuáles de ellas NO tienen destino en el SIAGIE (`codigo_siagie` vacío).
     * Sin esta vista, un área sin código queda fuera del acta EN SILENCIO — es
     * lo que pasó en B1 con los talleres (322 alumnos con nota, 0 reportados).
     *
     * Cuenta solo notas BLOQUEADAS, que son las únicas que el llenador escribe.
     * El área de tutoría/Ética aparece sin código a propósito: su destino lo
     * resuelve una EXCEPCIÓN DE HOJA, que el controlador cruza aparte.
     */
    public function coberturaAreas(int $periodoId): array
    {
        return $this->query("
            SELECT
                a.id            AS area_id,
                a.nombre        AS area_nombre,
                a.nombre_boleta,
                a.codigo_siagie,
                a.tipo          AS area_tipo,
                n.id            AS nivel_id,
                n.nombre        AS nivel_nombre,
                n.codigo        AS nivel_codigo,
                COUNT(DISTINCT cal.matricula_id) AS alumnos,
                COUNT(DISTINCT cal.competencia_id) AS competencias,
                COUNT(*)        AS notas
            FROM calificaciones cal
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            INNER JOIN competencias c ON c.id = cal.competencia_id
            LEFT  JOIN subareas sa    ON sa.id = c.subarea_id
            INNER JOIN areas a        ON a.id  = COALESCE(sa.area_id, c.area_id)
            INNER JOIN niveles n      ON n.id  = a.nivel_id
            WHERE cal.periodo_id = ?
              AND cal.extraordinaria = 0
            GROUP BY a.id, a.nombre, a.nombre_boleta, a.codigo_siagie, a.tipo,
                     n.id, n.nombre, n.codigo
            ORDER BY n.id, a.orden, a.nombre
        ", [$periodoId]);
    }

    /**
     * VÍNCULOS CONFIGURADOS: TODAS las áreas del currículo con su `codigo_siagie`
     * y, como dato anexo, cuántas notas tienen en el periodo (0 si ninguna).
     *
     * Parte de `areas`, NO de `calificaciones`: un vínculo área↔hoja existe con
     * independencia de si ese bimestre tuvo notas. Si se listaran solo las áreas
     * con notas, no se podría auditar la configuración — que es justo lo que hace
     * falta para ver, por ejemplo, que la hoja `035` la tiene asignada Educación
     * Religiosa pero la llena Ética por una excepción.
     *
     * Incluye las áreas inactivas SOLO si tienen notas en el periodo (una activa
     * sin notas es configuración legítima; una inactiva sin notas es ruido).
     */
    public function vinculosDeAreas(int $periodoId): array
    {
        return $this->query("
            SELECT
                a.id            AS area_id,
                a.nombre        AS area_nombre,
                a.nombre_boleta,
                a.codigo_siagie,
                a.tipo          AS area_tipo,
                a.activa,
                n.id            AS nivel_id,
                n.nombre        AS nivel_nombre,
                n.codigo        AS nivel_codigo,
                COALESCE(x.alumnos, 0)      AS alumnos,
                COALESCE(x.competencias, 0) AS competencias,
                COALESCE(x.notas, 0)        AS notas
            FROM areas a
            INNER JOIN niveles n ON n.id = a.nivel_id
            LEFT JOIN (
                SELECT COALESCE(sa.area_id, c.area_id) AS area_id,
                       COUNT(DISTINCT cal.matricula_id)   AS alumnos,
                       COUNT(DISTINCT cal.competencia_id) AS competencias,
                       COUNT(*)                           AS notas
                FROM calificaciones cal
                INNER JOIN bloqueos_competencia bc
                        ON bc.carga_id       = cal.carga_id
                       AND bc.competencia_id = cal.competencia_id
                       AND bc.periodo_id     = cal.periodo_id
                INNER JOIN competencias c ON c.id = cal.competencia_id
                LEFT  JOIN subareas sa    ON sa.id = c.subarea_id
                WHERE cal.periodo_id = ?
                  AND cal.extraordinaria = 0
                GROUP BY COALESCE(sa.area_id, c.area_id)
            ) x ON x.area_id = a.id
            WHERE a.activa = 1 OR COALESCE(x.notas, 0) > 0
            ORDER BY n.id, a.orden, a.nombre
        ", [$periodoId]);
    }

    /**
     * Áreas del mismo nivel que comparten algún `codigo_siagie`. Es un ERROR de
     * configuración silencioso: `areaPorCodigoSiagie` resuelve con LIMIT 1, así
     * que la hoja se asignaría a un área arbitraria. La UI lo muestra en rojo.
     */
    public function codigosSiagieDuplicados(): array
    {
        return $this->query("
            SELECT n.nombre AS nivel_nombre, a.codigo_siagie,
                   GROUP_CONCAT(a.nombre ORDER BY a.nombre SEPARATOR ' | ') AS areas
            FROM areas a
            INNER JOIN niveles n ON n.id = a.nivel_id
            WHERE a.codigo_siagie IS NOT NULL AND a.codigo_siagie <> ''
            GROUP BY a.nivel_id, n.nombre, a.codigo_siagie
            HAVING COUNT(*) > 1
            ORDER BY n.id, a.codigo_siagie
        ");
    }

    /** Periodos que ya tienen alguna calificación, para el selector del diagnóstico. */
    public function periodosConCalificaciones(): array
    {
        return $this->query("
            SELECT p.id, p.nombre_display, p.estado, p.numero
            FROM periodos p
            WHERE EXISTS (SELECT 1 FROM calificaciones c WHERE c.periodo_id = p.id)
            ORDER BY p.anio_id DESC, p.numero
        ");
    }

    /**
     * Persiste el código SIAGIE (14 dígitos, col. B del Excel) tras un match
     * exacto por nombre. SOLO escribe si el campo está vacío: un valor
     * distinto ya almacenado es un conflicto que se reporta, nunca se pisa.
     */
    public function guardarCodigoSiagie(int $estudianteId, string $codigo): bool
    {
        // Defensa en profundidad: el código SIAGIE es de 14 dígitos. Nunca
        // persistir un formato inválido (el llenador ya filtra; esto blinda a
        // cualquier otro llamador presente o futuro).
        if (!preg_match('/^\d{14}$/', $codigo)) {
            return false;
        }
        return $this->execute("
            UPDATE estudiantes
            SET codigo_estudiante = ?
            WHERE id = ?
              AND (codigo_estudiante IS NULL OR codigo_estudiante = '')
        ", [$codigo, $estudianteId]);
    }
}
