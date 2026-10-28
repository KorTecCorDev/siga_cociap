<?php

namespace App\Models;

/**
 * RegistroAuxiliarModel
 * Armado del REGISTRO AUXILIAR DE CALIFICACIONES (27/10/2026), compartido por
 * los dos documentos que lo imprimen:
 *   - el del DOCENTE (`Docente\CalificacionController`): solo sus cargas;
 *   - el del ÁREA COMPLETA (`Consulta\ConsultaNotasController`): todas las
 *     cargas de la sección, para Dirección, RA y admin.
 * Solo lo OFICIAL (competencias bloqueadas), como el historial.
 * Reglas: docs/modulos/calificaciones.md § Registro auxiliar.
 */
class RegistroAuxiliarModel extends BaseModel
{
    // ── Presupuesto de ancho de la MATRIZ, en mm ────────────────────────────
    // Espejo de `resources/sass/pages/_registro-auxiliar.scss` (`.ra-m__c-*`)
    // y de `@page paisaje` (_boleta.scss): si cambian allí, cambiar aquí, o el
    // reparto en partes deja tablas que desbordan la hoja.
    /** A4 horizontal (297 mm) menos los márgenes de 1 cm de `@page paisaje`. */
    public const ANCHO_UTIL_MM = 277;
    /** `.ra-m__c-num` (5) + `.ra-m__c-nombre` (58): se repiten en cada parte. */
    public const ANCHO_FIJO_MM = 63;
    /** Mínimo legible de una columna de criterio (sus `<col>` no llevan ancho fijo). */
    public const ANCHO_CRITERIO_MM = 5;
    // Logro, Resumen y transversales van como PAR numeral | literal (27/10/2026,
    // los dos niveles): `.ra-m__c-pnum` + `.ra-m__c-plit`.
    public const ANCHO_NUMERAL_MM = 5;
    public const ANCHO_LITERAL_MM = 5.5;
    /** Logro de cada competencia propia: un par. */
    public const ANCHO_LOGRO_MM = self::ANCHO_NUMERAL_MM + self::ANCHO_LITERAL_MM;
    /** `.ra-m__c-vacia`: competencia en blanco. */
    public const ANCHO_VACIA_MM = 7;
    /** Resumen: un par por competencia del área (una en blanco usa el mismo par), en la última parte. */
    public const ANCHO_RESUMEN_MM = self::ANCHO_NUMERAL_MM + self::ANCHO_LITERAL_MM;
    /** Transversales: un par por columna, en la última parte (matriz y hoja CD). */
    public const ANCHO_TRANSVERSAL_MM = self::ANCHO_NUMERAL_MM + self::ANCHO_LITERAL_MM;

    // ── Presupuesto de las CONCLUSIONES (hoja CD), en mm ────────────────────
    /** Logro de la hoja CD: `.ra-cd__c-num` + `.ra-cd__c-lit` (numeral y literal, 7 + 7). */
    public const ANCHO_CD_LOGRO_MM = 14;
    /**
     * Mínimo de la columna de conclusión. 45 y no 55: con 55, un área de 3
     * competencias y 2 transversales que hoy se imprime en una parte (con
     * conclusiones de ~51 mm) se partiría en dos.
     */
    public const ANCHO_CD_CONCLUSION_MM = 45;
    /** Tope de competencias por parte de la hoja CD (decisión del 27/10/2026). */
    public const CD_COMPETENCIAS_POR_PARTE = 3;

    private CalificacionModel    $calModel;
    private OmisionCriterioModel $omisionModel;
    private ExoneracionModel     $exoModel;

    public function __construct()
    {
        parent::__construct();
        $this->calModel     = new CalificacionModel();
        $this->omisionModel = new OmisionCriterioModel();
        $this->exoModel     = new ExoneracionModel();
    }

    /** El periodo con su año y el lema oficial, para la cabecera del documento. */
    public function periodo(int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT p.*, a.anio, a.lema_oficial
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);
    }

    /**
     * Cargas ACTIVAS de una sección en el año del periodo, con los mismos
     * filtros que `Docente\CalificacionController::getCargas()`: sin la carga
     * transversal del modelo viejo y Tutoría/Ética solo si su área tiene
     * competencias. Trae el nombre del DOCENTE (`docente`), que rotula las
     * transversales y las firmas del registro del área completa. Orden: el
     * de la lista «Áreas y cargas» de /consulta-notas (área, luego subárea).
     */
    public function cargasDeSeccion(int $seccionId, int $periodoId): array
    {
        return $this->query("
            SELECT
                ca.id,
                ca.seccion_id,
                ca.docente_id,
                s.nombre          AS seccion_nombre,
                (s.es_unidocente = 1 AND s.tutor_id = ca.docente_id) AS es_aula,
                g.nombre_display  AS grado_nombre,
                n.id              AS nivel_id,
                n.nombre          AS nivel_nombre,
                n.codigo          AS nivel_codigo,
                CASE
                    WHEN s.es_unidocente = 1 THEN a.nombre
                    ELSE COALESCE(sa.nombre, a.nombre)
                END               AS nombre_display,
                a.id              AS area_id,
                a.nombre          AS area_nombre,
                a.tipo            AS area_tipo,
                sa.id             AS subarea_id,
                sa.nombre         AS subarea_nombre,
                TRIM(CONCAT(pu.apellido_paterno, ' ', pu.apellido_materno, ', ', pu.nombres)) AS docente
            FROM cargas_academicas ca
            INNER JOIN secciones s  ON s.id  = ca.seccion_id
            INNER JOIN grados g     ON g.id  = s.grado_id
            INNER JOIN niveles n    ON n.id  = g.nivel_id
            INNER JOIN usuarios ud  ON ud.id = ca.docente_id
            INNER JOIN personas pu  ON pu.id = ud.persona_id
            LEFT  JOIN subareas sa  ON sa.id = ca.subarea_id
            LEFT  JOIN areas a      ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE ca.seccion_id = ?
              AND ca.estado     = 'activa'
              AND ca.anio_id    = (SELECT anio_id FROM periodos WHERE id = ?)
              AND (a.tipo IS NULL OR a.tipo != 'transversal')
              AND (a.tipo IS NULL OR a.tipo != 'tutoria'
                   OR EXISTS (SELECT 1 FROM competencias ctu WHERE ctu.area_id = a.id))
            ORDER BY a.orden, sa.orden, ca.id
        ", [$seccionId, $periodoId]);
    }

    /**
     * Firmas del pie: cada docente (en el orden dado, sin repetir), Dirección
     * EBR (titular vigente) y Dirección General (el único usuario activo con
     * ese rol). Sin usuario, solo el cargo. Sin imagen de firma.
     *
     * @param string[] $docentes nombres «Apellidos, Nombres»
     */
    public function firmas(array $docentes, int $anioId): array
    {
        $firmas = [];
        foreach (array_unique($docentes) as $d) {
            $firmas[] = ['nombre' => $d, 'cargo' => 'Docente'];
        }
        $ebr = (new DirectorEbrModel())->getVigenteEnFecha($anioId);
        $firmas[] = ['nombre' => $ebr['nombre_completo'] ?? null, 'cargo' => 'Dirección EBR'];
        $firmas[] = ['nombre' => (new UsuarioModel())->nombreUnicoActivoPorRolCodigo('director_general'), 'cargo' => 'Dirección General'];
        return $firmas;
    }

    /**
     * Arma UNA hoja del registro auxiliar: el área completa de una sección, con
     * las competencias del plan en su orden. Cada competencia es una «columna»:
     *   - `propia`    : bloqueada con criterios en una carga de `$cargas` → sus notas;
     *   - `noeval`    : bloqueada sin criterios en una carga de `$cargas` («No evaluada»);
     *   - `pendiente` : de una carga de `$cargas`, aún sin aprobar (solo lo oficial);
     *   - `otra`      : de una carga que no está en `$cargas` → EN BLANCO.
     * Nunca se lee una nota de una carga que no esté en `$cargas`.
     * Las transversales van aparte (COMP-TRA), una por carga.
     *
     * @param array $cargas       las que llenan la hoja (el docente: las suyas
     *                            elegidas; el área completa: todas)
     * @param bool  $paraDocente  oculta las extraordinarias reservadas a Dirección
     */
    public function hoja(array $meta, array $cargas, array $periodo, bool $paraDocente): array
    {
        $periodoId = (int) $periodo['id'];
        $areaId    = (int) $cargas[0]['area_id'];

        // Plan del área (directas y vía subáreas), mismo orden que el acta SIAGIE
        // (`SiagieExportModel::competenciasDeArea`); aquí hace falta además la
        // subárea para saber de qué carga es cada competencia.
        $plan = $this->query("
            SELECT c.id, c.codigo_minedu, c.nombre_completo, c.subarea_id, c.area_id
            FROM competencias c
            LEFT JOIN subareas s ON s.id = c.subarea_id
            WHERE COALESCE(c.area_id, s.area_id) = ?
            ORDER BY c.orden, c.id
        ", [$areaId]);

        $columnas      = [];
        $transversales = [];
        $roster        = null;
        $ids           = [];
        $hayVariasTr   = false;
        $conTr         = 0;

        $bloquesPorCarga = [];
        foreach ($cargas as $c) {
            $cid  = (int) $c['id'];
            $ids[] = $cid;
            $exo  = array_map('intval', $this->exoModel->getActivasParaCarga($cid, (int) $periodo['anio_id'], $periodoId));
            $bloquesPorCarga[$cid] = ['carga' => $c, 'exo' => $exo, 'bloques' => $this->bloquesBloqueadosDeCarga($cid, $periodoId)];
            foreach ($bloquesPorCarga[$cid]['bloques'] as $b) {
                if (!empty($b['competencia']['es_transversal'])) {
                    $conTr++;
                    break;
                }
            }
        }
        $hayVariasTr = $conTr > 1;

        // ¿De qué carga es cada competencia del plan? Misma regla que
        // `getCargas` (total_competencias): por subárea o, en un área-curso, por área.
        $duenaDe = static function (array $comp) use ($cargas): ?int {
            foreach ($cargas as $c) {
                if (!empty($c['subarea_id'])) {
                    if ((int) $comp['subarea_id'] === (int) $c['subarea_id']) {
                        return (int) $c['id'];
                    }
                } elseif ($comp['area_id'] !== null && (int) $comp['area_id'] === (int) $c['area_id']) {
                    return (int) $c['id'];
                }
            }
            return null;
        };

        // Código de la competencia de cada subárea (1 subárea = 1 competencia): con
        // varias cargas, agrupa en la cabecera las transversales de cada una.
        $codigoSubarea = [];
        foreach ($plan as $comp) {
            if ($comp['subarea_id'] !== null) {
                $codigoSubarea[(int) $comp['subarea_id']] ??= $comp['codigo_minedu'];
            }
        }

        // Bloqueadas por carga e id de competencia.
        $bloqueadas = [];
        foreach ($bloquesPorCarga as $cid => $bc) {
            foreach ($bc['bloques'] as $b) {
                $comp = $b['competencia'];
                if (!empty($comp['es_transversal'])) {
                    // Rótulo de la carga (leyenda): la subárea; si no tiene, el
                    // DOCENTE (solo lo traen las cargas del área completa,
                    // `cargasDeSeccion`) y, en el registro del docente, el nombre de
                    // la carga. En la cabecera de la tabla, `grupo`: el código de la
                    // competencia de su subárea (27/10/2026: sin escribir el curso),
                    // con el rótulo como respaldo si la carga no tiene subárea.
                    $rotulo = $hayVariasTr
                        ? ($bc['carga']['subarea_nombre'] ?? $bc['carga']['docente'] ?? $bc['carga']['nombre_display'])
                        : null;
                    $transversales[] = [
                        'competencia' => $comp,
                        'subarea'     => $rotulo,
                        'carga_id'    => $cid,
                        'grupo'       => $hayVariasTr
                            ? ($codigoSubarea[(int) ($bc['carga']['subarea_id'] ?? 0)] ?? $rotulo)
                            : null,
                        'alumnos'     => $this->indexarPorMatricula($b['alumnos']),
                        'evaluada'    => !empty($b['criterios']),
                        'exo'         => $bc['exo'],
                    ];
                    $roster ??= $b['alumnos'];
                    continue;
                }
                $bloqueadas[$cid . '-' . (int) $comp['id']] = $b;
            }
        }

        $enPlan = [];
        foreach ($plan as $comp) {
            $enPlan[(int) $comp['id']] = true;
            $cid = $duenaDe($comp);
            $col = [
                'id'     => (int) $comp['id'],
                'codigo' => $comp['codigo_minedu'],
                'nombre' => $comp['nombre_completo'],
                'tipo'   => 'otra',
            ];
            if ($cid !== null) {
                $b = $bloqueadas[$cid . '-' . (int) $comp['id']] ?? null;
                if ($b === null) {
                    $col['tipo'] = 'pendiente';
                } elseif (empty($b['criterios'])) {
                    $col['tipo'] = 'noeval';
                } else {
                    $col['tipo']      = 'propia';
                    $col['criterios'] = criterios_ordinarios($b['criterios']);
                    $col['alumnos']   = $this->indexarPorMatricula($b['alumnos']);
                    $col['lista']     = $b['alumnos'];
                    $col['exo']       = $bloquesPorCarga[$cid]['exo'];
                    $roster ??= $b['alumnos'];
                }
            }
            $columnas[] = $col;
        }
        // Defensa: una bloqueada propia que no esté en el plan del área no se pierde.
        foreach ($bloqueadas as $clave => $b) {
            if (!isset($enPlan[(int) $b['competencia']['id']]) && !empty($b['criterios'])) {
                $cid = (int) explode('-', $clave)[0];
                $roster ??= $b['alumnos'];
                $columnas[] = [
                    'id' => (int) $b['competencia']['id'], 'codigo' => $b['competencia']['codigo_minedu'],
                    'nombre' => $b['competencia']['nombre_completo'], 'tipo' => 'propia',
                    'criterios' => criterios_ordinarios($b['criterios']),
                    'alumnos' => $this->indexarPorMatricula($b['alumnos']), 'lista' => $b['alumnos'],
                    'exo' => $bloquesPorCarga[$cid]['exo'],
                ];
            }
        }

        $subareas = array_values(array_filter(array_map(
            static fn(array $c): ?string => $c['subarea_nombre'] ?? null,
            $cargas
        )));

        return [
            'meta'            => $meta,
            'area_nombre'     => $meta['area_nombre'],
            'subareas'        => $meta['es_aula'] ? [] : $subareas,
            'columnas'        => $columnas,
            'transversales'   => $transversales,
            'roster'          => $roster ?? [],
            'extraordinarias' => (new RectificacionModel())
                ->getExtraordinariasDeCargas($ids, $periodoId, $paraDocente),
        ];
    }

    /**
     * Bloques read-only {competencia, criterios, alumnos} de las competencias
     * OFICIALES (bloqueadas) de una carga en un periodo. Compartido por el
     * registro auxiliar y por el histórico de carga, de área y de procedencia
     * del docente (que delegan aquí).
     */
    public function bloquesBloqueadosDeCarga(int $cargaId, int $periodoId): array
    {
        $bloqueadas = $this->query("
            SELECT comp.id,
                   comp.nombre_completo,
                   comp.codigo_minedu,
                   (a.tipo = 'transversal') AS es_transversal
            FROM bloqueos_competencia bc
            INNER JOIN competencias comp ON comp.id = bc.competencia_id
            LEFT  JOIN areas a ON a.id = comp.area_id
            WHERE bc.carga_id   = ?
              AND bc.periodo_id = ?
            ORDER BY comp.orden, comp.id
        ", [$cargaId, $periodoId]);

        $bloques = [];
        foreach ($bloqueadas as $b) {
            $competenciaId = (int) $b['id'];
            $resumen = $this->calModel->getResumenCompetencia($cargaId, $competenciaId, $periodoId);

            $omisionesPorCriterio = [];
            foreach ($resumen['criterios'] as $cr) {
                $omisionesPorCriterio[(int) $cr['id']] =
                    $this->omisionModel->getPorCriterio((int) $cr['id']);
            }
            foreach ($resumen['alumnos'] as &$al) {
                $al['omisiones_criterios'] = [];
                $mid = (int) $al['matricula_id'];
                foreach ($omisionesPorCriterio as $critId => $porMat) {
                    if (isset($porMat[$mid])) {
                        $al['omisiones_criterios'][$critId] = $porMat[$mid];
                    }
                }
            }
            unset($al);

            $bloques[] = [
                'competencia' => [
                    'id'              => $competenciaId,
                    'nombre_completo' => $b['nombre_completo'],
                    'codigo_minedu'   => $b['codigo_minedu'],
                    'es_transversal'  => $b['es_transversal'],
                ],
                'criterios'       => $resumen['criterios'],
                'alumnos'         => $resumen['alumnos'],
            ];
        }
        return $bloques;
    }

    /**
     * PARTES de la matriz: reparto goloso por competencias ENTERAS (nunca se
     * corta una) en el ancho útil de la hoja, descontando N° y nombres, que se
     * repiten en cada parte. El bloque final (Resumen + transversales) va en la
     * última parte si cabe; si no, en una parte propia.
     *
     * @return list<array{columnas: int[], final: bool}> índices de `$columnas`
     */
    public static function partesMatriz(array $columnas, int $nTransversales): array
    {
        $anchos = [];
        foreach ($columnas as $c) {
            $anchos[] = $c['tipo'] === 'propia'
                ? count($c['criterios']) * self::ANCHO_CRITERIO_MM + self::ANCHO_LOGRO_MM
                : self::ANCHO_VACIA_MM;
        }
        $final = count($columnas) * self::ANCHO_RESUMEN_MM + $nTransversales * self::ANCHO_TRANSVERSAL_MM;
        return self::empaquetar($anchos, $final, PHP_INT_MAX);
    }

    /**
     * PARTES de la hoja CD: hasta `CD_COMPETENCIAS_POR_PARTE` competencias por
     * parte, cada una con su logro y una conclusión de al menos
     * `ANCHO_CD_CONCLUSION_MM`; las transversales, en la última.
     *
     * @param array $propias solo las columnas `propia`
     * @return list<array{columnas: int[], final: bool}> índices de `$propias`
     */
    public static function partesConclusiones(array $propias, int $nTransversales): array
    {
        $anchos = array_fill(0, count($propias), self::ANCHO_CD_LOGRO_MM + self::ANCHO_CD_CONCLUSION_MM);
        return self::empaquetar($anchos, $nTransversales * self::ANCHO_TRANSVERSAL_MM, self::CD_COMPETENCIAS_POR_PARTE);
    }

    /**
     * Grupos de la cabecera de las transversales: una celda por carga
     * (columnas consecutivas de la misma `carga_id`) con su `grupo` (el código
     * de la competencia de su subárea). Vacío si las transversales son de una
     * sola carga: entonces no hay nada que distinguir y no se pinta la fila.
     *
     * @return list<array{grupo: string, span: int}>
     */
    public static function gruposTransversales(array $transversales): array
    {
        $grupos = [];
        $ultima = null;
        foreach ($transversales as $t) {
            if (($t['grupo'] ?? null) === null) {
                return [];
            }
            if ($t['carga_id'] === $ultima) {
                $grupos[count($grupos) - 1]['span']++;
                continue;
            }
            $grupos[] = ['grupo' => (string) $t['grupo'], 'span' => 1];
            $ultima   = $t['carga_id'];
        }
        return $grupos;
    }

    /**
     * Empaquetado goloso en orden: abre parte nueva cuando el siguiente bloque
     * no cabe (o se llegó al tope); el bloque final va en la última parte si
     * cabe, o en una parte propia (solo si hay bloque final). Siempre devuelve
     * al menos una parte, y solo la última lleva `final = true`.
     */
    private static function empaquetar(array $anchos, float $final, int $tope): array
    {
        $presupuesto = self::ANCHO_UTIL_MM - self::ANCHO_FIJO_MM;
        $partes = [];
        $actual = [];
        $usado  = 0.0;
        foreach ($anchos as $i => $w) {
            if ($actual !== [] && ($usado + $w > $presupuesto || count($actual) >= $tope)) {
                $partes[] = $actual;
                $actual = [];
                $usado  = 0.0;
            }
            $actual[] = $i;
            $usado   += $w;
        }
        // Sin bloque final (CD sin transversales) nunca se abre una parte vacía.
        if ($actual !== [] && $final > 0 && $usado + $final > $presupuesto) {
            $partes[] = $actual;
            $actual = [];
        }
        $partes[] = $actual;

        $out = [];
        $ultima = count($partes) - 1;
        foreach ($partes as $k => $p) {
            $out[] = ['columnas' => $p, 'final' => $k === $ultima];
        }
        return $out;
    }

    /** Filas de alumnos indexadas por matricula_id. */
    private function indexarPorMatricula(array $alumnos): array
    {
        $out = [];
        foreach ($alumnos as $a) {
            $out[(int) $a['matricula_id']] = $a;
        }
        return $out;
    }
}
