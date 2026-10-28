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
    /**
     * Criterio AGRUPADO por curso (GAMA replicada en EPT y Arte de 5.º,
     * 28/10/2026): sobre él va el nombre del curso (`.ra-m__grupo--cr`), que en
     * 5 mm se partía letra por letra. Con 9 mm cada competencia va en su parte.
     */
    public const ANCHO_CRITERIO_GRUPO_MM = 9;
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
    /**
     * Transversales: un par por columna (matriz y hoja CD). En la matriz van
     * además sus criterios (`anchoTransversal`), en bloques por carga.
     */
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
        $plan      = $this->planDeArea($areaId);

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
            $bloquesPorCarga[$cid] = [
                'carga'   => $c,
                'exo'     => $exo,
                // En el DOCUMENTO la fila oficial del tramo de un retorno va vacía
                // (todo lo cursado en la operativa, en `anexo_retorno`).
                'bloques' => array_map(
                    static fn(array $b): array => ['alumnos' => self::vaciarTramo($b['alumnos'])] + $b,
                    $this->bloquesBloqueadosDeCarga($cid, $periodoId)
                ),
            ];
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
                        // Sus criterios y notas numerales en la matriz, como las
                        // propias (28/10/2026).
                        'criterios'   => criterios_ordinarios($b['criterios']),
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

        $hoja = [
            'meta'            => $meta,
            'area_nombre'     => $meta['area_nombre'],
            'area_orden'      => (int) ($this->areaInfo($areaId)['orden'] ?? 0),
            'subareas'        => $meta['es_aula'] ? [] : $subareas,
            'columnas'        => $columnas,
            'transversales'   => $transversales,
            'roster'          => $roster ?? [],
            'extraordinarias' => (new RectificacionModel())
                ->getExtraordinariasDeCargas($ids, $periodoId, $paraDocente),
            'anexo_retorno'   => $this->anexoRetorno($roster ?? [], $cargas, $periodoId, (int) $periodo['anio_id']),
        ];

        // Acta SIAGIE: si el área es FUENTE de una equivalencia no transversal
        // (Ética → Educación Religiosa), la hoja es la del área DESTINO.
        $eq = $this->equivalenciaDeArea((int) $cargas[0]['seccion_id'], $areaId);
        return $eq !== null ? $this->comoAreaDestino($hoja, $eq) : $hoja;
    }

    // ── Equivalencias del ACTA SIAGIE en el registro (28/10/2026) ─────────────
    // Decisiones del usuario: el registro sigue las reglas del acta. Ética y
    // Valores (área de tutoría, secundaria) se presenta como EDUCACIÓN RELIGIOSA,
    // su nota replicada en C51 y C52, y JAMÁS con su propia competencia; en 5.º,
    // GAMA (CT4) se replica en TODAS las competencias de EPT y de Arte y Cultura.
    // La regla sale de `SituacionFinalModel::equivalenciasActa()`, que resuelve
    // EQUIVALENCIAS_ACTA_SIAGIE igual que el llenador (degradación segura).

    /** Plan de un área (directas y vía subáreas), en el orden del acta SIAGIE. */
    private function planDeArea(int $areaId): array
    {
        return $this->query("
            SELECT c.id, c.codigo_minedu, c.nombre_completo, c.subarea_id, c.area_id
            FROM competencias c
            LEFT JOIN subareas s ON s.id = c.subarea_id
            WHERE COALESCE(c.area_id, s.area_id) = ?
            ORDER BY c.orden, c.id
        ", [$areaId]);
    }

    /** Nombre, tipo y orden de un área. */
    private function areaInfo(int $areaId): ?array
    {
        return $this->queryOne("SELECT id, nombre, tipo, orden FROM areas WHERE id = ?", [$areaId]);
    }

    /**
     * Equivalencias del acta que rigen en el grado de la sección.
     *
     * @return list<array{grados:?array, transversal:bool, fuente:array, destino:array}>
     */
    private function equivalenciasDeSeccion(int $seccionId): array
    {
        $s = $this->queryOne("
            SELECT g.numero, n.id AS nivel_id, n.codigo AS nivel_codigo
            FROM secciones s
            INNER JOIN grados g  ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            WHERE s.id = ?
        ", [$seccionId]);
        if ($s === null) {
            return [];
        }
        $grado = (int) $s['numero'];
        return array_values(array_filter(
            (new SituacionFinalModel())->equivalenciasActa((int) $s['nivel_id'], (string) $s['nivel_codigo']),
            static fn(array $eq): bool => $eq['grados'] === null || in_array($grado, $eq['grados'], true)
        ));
    }

    /** La equivalencia NO transversal cuya fuente es esta área (Ética), o null. */
    private function equivalenciaDeArea(int $seccionId, int $areaId): ?array
    {
        foreach ($this->equivalenciasDeSeccion($seccionId) as $eq) {
            if (!$eq['transversal'] && (int) $eq['fuente']['area_id'] === $areaId) {
                return $eq;
            }
        }
        return null;
    }

    /**
     * Nombre con que el registro rotula un área de la sección: el del área
     * DESTINO si es fuente del acta (Tutoría → «Educación Religiosa»), o null.
     * Para la tarjeta de la pantalla de selección del docente.
     */
    public function nombreEnRegistro(int $seccionId, int $areaId): ?string
    {
        $eq = $this->equivalenciaDeArea($seccionId, $areaId);
        return $eq !== null ? (string) $eq['destino']['area'] : null;
    }

    /**
     * La hoja del área FUENTE presentada como la del área DESTINO: cabecera y
     * orden del destino, y la columna de la competencia fuente REEMPLAZADA por
     * una columna por cada competencia del destino con sus mismos criterios,
     * notas, logro, conclusión y exonerados. La competencia fuente no queda en
     * ninguna parte del documento (tampoco en el anexo del retorno).
     */
    private function comoAreaDestino(array $hoja, array $eq): array
    {
        $destino = $this->areaInfo((int) $eq['destino']['area_id']);
        $plan    = $this->planDeArea((int) $eq['destino']['area_id']);
        if ($destino === null || $plan === []) {
            return $hoja;
        }
        $fuenteId = (int) $eq['fuente']['competencia_id'];
        $replicar = static function (array $fuente, array $comp, array $campos): array {
            foreach ($campos as $de => $a) {
                $fuente[$de] = $comp[$a];
            }
            return $fuente;
        };

        $columnas = [];
        foreach ($hoja['columnas'] as $col) {
            if ((int) $col['id'] !== $fuenteId) {
                $columnas[] = $col;
                continue;
            }
            foreach ($plan as $comp) {
                $columnas[] = $replicar($col, $comp, ['id' => 'id', 'codigo' => 'codigo_minedu', 'nombre' => 'nombre_completo']);
            }
        }
        $hoja['columnas'] = $columnas;

        foreach ($hoja['anexo_retorno'] as &$est) {
            foreach ($est['cargas'] as &$g) {
                $comps = [];
                foreach ($g['competencias'] as $c) {
                    if ((int) $c['id'] !== $fuenteId) {
                        $comps[] = $c;
                        continue;
                    }
                    $g['nombre'] = (string) $destino['nombre'];
                    foreach ($plan as $comp) {
                        $comps[] = $replicar($c, $comp, ['id' => 'id', 'codigo' => 'codigo_minedu', 'nombre' => 'nombre_completo']);
                    }
                }
                $g['competencias'] = $comps;
            }
            unset($g);
        }
        unset($est);

        $hoja['meta']['area_nombre'] = (string) $destino['nombre'];
        $hoja['meta']['area_tipo']   = (string) $destino['tipo'];
        $hoja['area_nombre']         = (string) $destino['nombre'];
        $hoja['area_orden']          = (int) $destino['orden'];
        $hoja['subareas']            = [];
        return $hoja;
    }

    /**
     * HOJAS de las áreas DESTINO de una equivalencia TRANSVERSAL del acta (5.º:
     * GAMA → EPT y Arte y Cultura), que no tienen carga en la sección. Solo en
     * el registro del ÁREA COMPLETA. Por cada competencia del destino:
     *   - criterios: TODOS los de la fuente (GAMA) en cada carga de la sección
     *     que la tiene bloqueada, agrupados por carga (`grupos`, con el nombre
     *     del curso), con sus notas, omisiones y exonerados de esa carga;
     *   - logro: el promedio que CONGELÓ EL TUTOR (el de la boleta y el acta);
     *   - conclusión: la de GAMA del tutor (decisión del usuario: siempre).
     * Sin cierre vigente del tutor, las competencias quedan «Aún no aprobada».
     * Docente y firma: el tutor DEL BIMESTRE (`TutorPeriodoModel`).
     *
     * @param  array $cargas  `cargasDeSeccion()` (todas las de la sección)
     * @return list<array>    hojas como las de hoja(), con `docentes`
     */
    public function hojasReplicaTransversal(array $cargas, array $periodo): array
    {
        if ($cargas === []) {
            return [];
        }
        $seccionId = (int) $cargas[0]['seccion_id'];
        $periodoId = (int) $periodo['id'];
        $hojas     = [];
        foreach ($this->equivalenciasDeSeccion($seccionId) as $eq) {
            if (!$eq['transversal']) {
                continue;
            }
            $destino = $this->areaInfo((int) $eq['destino']['area_id']);
            $plan    = $this->planDeArea((int) $eq['destino']['area_id']);
            if ($destino === null || $plan === []) {
                continue;
            }
            $hojas[] = $this->hojaReplicaTransversal($cargas, $periodo, (int) $eq['fuente']['competencia_id'], $destino, $plan, $seccionId, $periodoId);
        }
        return $hojas;
    }

    private function hojaReplicaTransversal(array $cargas, array $periodo, int $fuenteId, array $destino, array $plan, int $seccionId, int $periodoId): array
    {
        // Criterios de la fuente por carga, y las filas con TODAS sus notas. Las
        // cargas: TODAS las de la sección con la fuente BLOQUEADA en el bimestre,
        // activas o no (en el I Bimestre 2026, GAMA vive en la carga transversal
        // del modelo antiguo, desactivada por la migración 027: el promedio del
        // tutor la cuenta, así que el registro también).
        $grupos = [];
        $filas  = [];
        foreach ($this->query("
            SELECT ca.id, comp.id AS comp_id, comp.nombre_completo, comp.codigo_minedu,
                   (ac.tipo = 'transversal') AS es_transversal
            FROM cargas_academicas ca
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id = ca.id AND bc.competencia_id = ? AND bc.periodo_id = ?
            INNER JOIN competencias comp ON comp.id = bc.competencia_id
            LEFT  JOIN areas ac    ON ac.id = comp.area_id
            LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
            LEFT  JOIN areas a     ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE ca.seccion_id = ?
            ORDER BY a.orden, sa.orden, ca.id
        ", [$fuenteId, $periodoId, $seccionId]) as $c) {
            $cid = (int) $c['id'];
            $b   = $this->bloqueDeCompetencia($cid, [
                'id' => $c['comp_id'], 'nombre_completo' => $c['nombre_completo'],
                'codigo_minedu' => $c['codigo_minedu'], 'es_transversal' => $c['es_transversal'],
            ], $periodoId);
            $criterios = criterios_ordinarios($b['criterios']);
            if ($criterios === []) {
                continue;
            }
            $grupos[] = [
                'nombre'    => $this->rotuloCarga($cid)['nombre'],
                'criterios' => $criterios,
                'exo'       => array_map('intval', $this->exoModel->getActivasParaCarga($cid, (int) $periodo['anio_id'], $periodoId)),
            ];
            foreach ($b['alumnos'] as $a) {
                $mid = (int) $a['matricula_id'];
                if (!isset($filas[$mid])) {
                    $filas[$mid] = $a;
                    continue;
                }
                $filas[$mid]['notas_criterios']     = $filas[$mid]['notas_criterios'] + $a['notas_criterios'];
                $filas[$mid]['omisiones_criterios'] = $filas[$mid]['omisiones_criterios'] + $a['omisiones_criterios'];
            }
        }

        // Logro y conclusión: los que congeló el tutor (lectura: por la fila).
        $trans  = new TransversalModel();
        $cierre = $trans->getCierreVigente($seccionId, $periodoId);
        $prom   = $cierre !== null ? $trans->getPromediosSeccion($seccionId, $periodoId, true) : [];
        $concl  = $cierre !== null ? $trans->getConclusionesSeccion($seccionId, $periodoId, true) : [];
        foreach ($filas as $mid => &$f) {
            $nota = $prom[$mid][$fuenteId] ?? null;
            $f['promedio']               = $nota;
            $f['literal']                = $nota !== null ? nota_a_literal((int) $nota) : null;
            $f['conclusion_descriptiva'] = $concl[$mid][$fuenteId] ?? null;
        }
        unset($f);
        // Retorno de grado: en el documento, la fila del tramo va vacía.
        $lista = self::vaciarTramo(array_values($filas));

        $tipo = $grupos === [] ? 'noeval' : ($cierre === null ? 'pendiente' : 'propia');
        $columnas = [];
        foreach ($plan as $comp) {
            $col = [
                'id'     => (int) $comp['id'],
                'codigo' => $comp['codigo_minedu'],
                'nombre' => $comp['nombre_completo'],
                'tipo'   => $tipo,
            ];
            if ($tipo === 'propia') {
                $col['criterios'] = array_merge(...array_column($grupos, 'criterios'));
                $col['grupos']    = $grupos;
                $col['alumnos']   = $this->indexarPorMatricula($lista);
                $col['lista']     = $lista;
                $col['exo']       = [];
            }
            $columnas[] = $col;
        }

        $c0    = $cargas[0];
        $tutor = (new TutorPeriodoModel())->tutorDe($seccionId, $periodoId)['nombre'] ?? null;
        return [
            'meta' => [
                'area_nombre'    => (string) $destino['nombre'],
                'area_tipo'      => (string) $destino['tipo'],
                'grado_nombre'   => $c0['grado_nombre'],
                'seccion_nombre' => $c0['seccion_nombre'],
                'nivel_nombre'   => $c0['nivel_nombre'],
                'nivel_codigo'   => $c0['nivel_codigo'],
                'es_aula'        => false,
            ],
            'area_nombre'     => (string) $destino['nombre'],
            'area_orden'      => (int) $destino['orden'],
            'subareas'        => [],
            'columnas'        => $columnas,
            'transversales'   => [],
            'roster'          => $grupos !== [] ? $lista : [],
            'extraordinarias' => [],
            'anexo_retorno'   => [],
            'docentes'        => $tutor !== null ? [['nombre' => $tutor, 'detalle' => null]] : [],
        ];
    }

    /**
     * RETORNO DE GRADO en el DOCUMENTO (decisión del usuario, 28/10/2026: «fila
     * vacía + anexo»). Las filas de lectura de la oficial en su tramo traen el
     * logro de la operativa (`CalificacionModel::conDatosDelTramo`, para las
     * pantallas); el registro impreso las deja VACÍAS —nombre, chip y «—»— y
     * pone todo lo cursado en el anexo. Las demás filas no se tocan.
     */
    private static function vaciarTramo(array $alumnos): array
    {
        foreach ($alumnos as &$al) {
            if (($al['cursado_en'] ?? null) === null) {
                continue;
            }
            $al['promedio']               = null;
            $al['literal']                = null;
            $al['conclusion_descriptiva'] = null;
            $al['cursado_en']             = null;   // sin celda fundida: «—» en cada criterio
        }
        unset($al);
        return $alumnos;
    }

    /**
     * ANEXO DEL RETORNO DE GRADO de una hoja (sección + área), 28/10/2026: por cada
     * estudiante del roster que es la OFICIAL de un retorno en su tramo, TODAS las
     * calificaciones que obtuvo en la operativa en las cargas EQUIVALENTES a las
     * de la hoja (`CambioSeccionModel::cargasEquivalentes`): cada competencia
     * BLOQUEADA, con sus criterios de esa carga, la nota de cada criterio, las
     * omisiones, el logro y la conclusión. Regla del registro auxiliar: todas las
     * calificaciones de cada criterio, aunque se hayan registrado en otra sección.
     *
     * @return list<array{matricula_id:int, nombre:string, seccion:string,
     *                    cargas: list<array{nombre:string, docente:?string, competencias:list<array>}>}>
     */
    public function anexoRetorno(array $roster, array $cargas, int $periodoId, int $anioId): array
    {
        $tramos = (new RetornoGradoModel())->oficialesEnTramo(array_column($roster, 'matricula_id'), $periodoId);
        if ($tramos === []) {
            return [];
        }
        $cambio = new CambioSeccionModel();
        $out    = [];
        foreach ($roster as $al) {
            $t = $tramos[(int) $al['matricula_id']] ?? null;
            if ($t === null) {
                continue;
            }
            $grupos = [];
            $vistas = [];
            foreach ($cargas as $c) {
                $equivalentes = $cambio->cargasEquivalentes(
                    ['area_resuelta_id' => (int) $c['area_id'], 'subarea_id' => $c['subarea_id'] ?? null],
                    $t['seccion_id']
                );
                foreach ($equivalentes as $eq) {
                    $eid = (int) $eq['id'];
                    if (isset($vistas[$eid])) {
                        continue;
                    }
                    $vistas[$eid] = true;
                    $competencias = $this->bloquesDeMatricula($eid, $periodoId, $t['operativa'], $anioId);
                    if ($competencias !== []) {
                        $grupos[] = $this->rotuloCarga($eid) + ['competencias' => $competencias];
                    }
                }
            }
            $out[] = [
                'matricula_id' => (int) $al['matricula_id'],
                'nombre'       => $al['apellido_paterno'] . ' ' . $al['apellido_materno'] . ', ' . $al['nombres'],
                'seccion'      => $t['seccion'],
                'cargas'       => $grupos,
            ];
        }
        return $out;
    }

    /**
     * Las competencias BLOQUEADAS de una carga en un periodo, con la fila de UNA
     * matrícula: criterios de esa carga, nota de cada criterio, omisiones, EXO,
     * logro y conclusión. Lee con `getResumenCompetencia` SIN el flag de lectura:
     * su roster es el de evaluación del bimestre (`sqlRosterDelPeriodo`), que es
     * justo donde la operativa CURSÓ su tramo. Sin fila, la competencia no sale.
     */
    private function bloquesDeMatricula(int $cargaId, int $periodoId, int $matriculaId, int $anioId): array
    {
        $exo = in_array(
            $matriculaId,
            array_map('intval', $this->exoModel->getActivasParaCarga($cargaId, $anioId, $periodoId)),
            true
        );
        $out = [];
        foreach ($this->competenciasBloqueadas($cargaId, $periodoId) as $b) {
            $resumen = $this->calModel->getResumenCompetencia($cargaId, (int) $b['id'], $periodoId);
            $fila    = null;
            foreach ($resumen['alumnos'] as $a) {
                if ((int) $a['matricula_id'] === $matriculaId) {
                    $fila = $a;
                    break;
                }
            }
            if ($fila === null) {
                continue;
            }
            $criterios = criterios_ordinarios($resumen['criterios']);
            $omisiones = [];
            foreach ($criterios as $cr) {
                $om = $this->omisionModel->getPorCriterio((int) $cr['id'])[$matriculaId] ?? null;
                if ($om !== null) {
                    $omisiones[(int) $cr['id']] = $om;
                }
            }
            $out[] = [
                'id'             => (int) $b['id'],
                'codigo'         => (string) $b['codigo_minedu'],
                'nombre'         => (string) $b['nombre_completo'],
                'es_transversal' => (bool) $b['es_transversal'],
                'criterios'      => $criterios,
                'alumno'         => ['omisiones_criterios' => $omisiones] + $fila,
                'exo'            => $exo,
            ];
        }
        return $out;
    }

    /**
     * Rótulo de una carga para el anexo: su subárea (o su área) y el docente, con
     * el nombre como en el resto del documento («Apellidos, Nombres»).
     *
     * @return array{nombre: string, docente: ?string}
     */
    private function rotuloCarga(int $cargaId): array
    {
        $r = $this->queryOne("
            SELECT COALESCE(sa.nombre, a.nombre) AS nombre,
                   TRIM(CONCAT(pu.apellido_paterno, ' ', pu.apellido_materno, ', ', pu.nombres)) AS docente
            FROM cargas_academicas ca
            LEFT JOIN subareas sa ON sa.id = ca.subarea_id
            LEFT JOIN areas a     ON a.id  = COALESCE(ca.area_id, sa.area_id)
            LEFT JOIN usuarios ud ON ud.id = ca.docente_id
            LEFT JOIN personas pu ON pu.id = ud.persona_id
            WHERE ca.id = ?
        ", [$cargaId]);
        return [
            'nombre'  => (string) ($r['nombre'] ?? ''),
            'docente' => ($r['docente'] ?? '') !== '' && $r['docente'] !== ',' ? $r['docente'] : null,
        ];
    }

    /**
     * ANEXO de la hoja «Competencias transversales de la sección» (decisión del
     * usuario, 28/10/2026: «fila vacía + anexo al pie»). La fila oficial de un
     * retorno en su tramo trae, por la lectura (`sqlDatosDelPeriodo`), el
     * promedio y la conclusión que congeló el TUTOR de la sección operativa: se
     * quitan de su fila y van al anexo, con la sección y el tutor DEL BIMESTRE
     * (`TutorPeriodoModel`). Sin cierre vigente en la sección operativa, el anexo
     * lo dice y no muestra notas.
     *
     * @param  array $ts  ConsultaNotasController::transversalesSeccion()
     * @return array      $ts con `promedios`/`conclusiones` sin esas filas y `anexo`
     */
    public function conAnexoRetornoTransversales(array $ts, int $periodoId): array
    {
        $ts['anexo'] = [];
        if ($ts['cierre'] === null || $ts['alumnos'] === []) {
            return $ts;
        }
        $tramos = (new RetornoGradoModel())->oficialesEnTramo(array_column($ts['alumnos'], 'matricula_id'), $periodoId);
        if ($tramos === []) {
            return $ts;
        }
        $trans  = new TransversalModel();
        $tutor  = new TutorPeriodoModel();
        foreach ($ts['alumnos'] as $al) {
            $mid = (int) $al['matricula_id'];
            $t   = $tramos[$mid] ?? null;
            if ($t === null) {
                continue;
            }
            $cerrado = $trans->getCierreVigente($t['seccion_id'], $periodoId) !== null;
            $ts['anexo'][] = [
                'nombre'       => $al['nombre_completo'],
                'seccion'      => $t['seccion'],
                'tutor'        => $tutor->tutorDe($t['seccion_id'], $periodoId)['nombre'] ?? null,
                'cerrado'      => $cerrado,
                'promedios'    => $cerrado ? ($ts['promedios'][$mid] ?? []) : [],
                'conclusiones' => $cerrado ? ($ts['conclusiones'][$mid] ?? []) : [],
            ];
            unset($ts['promedios'][$mid], $ts['conclusiones'][$mid]);
        }
        return $ts;
    }

    /** Competencias BLOQUEADAS (oficiales) de una carga en un periodo, en orden del plan. */
    private function competenciasBloqueadas(int $cargaId, int $periodoId): array
    {
        return $this->query("
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
    }

    /**
     * Bloques read-only {competencia, criterios, alumnos} de las competencias
     * OFICIALES (bloqueadas) de una carga en un periodo. Compartido por el
     * registro auxiliar y por el histórico de carga, de área y de procedencia
     * del docente (que delegan aquí).
     */
    public function bloquesBloqueadosDeCarga(int $cargaId, int $periodoId): array
    {
        $bloqueadas = $this->competenciasBloqueadas($cargaId, $periodoId);

        $bloques = [];
        foreach ($bloqueadas as $b) {
            $bloques[] = $this->bloqueDeCompetencia($cargaId, $b, $periodoId);
        }
        return $bloques;
    }

    /**
     * Un bloque read-only {competencia, criterios, alumnos} de una competencia
     * bloqueada de una carga: el roster de LECTURA (sus usuarios son de solo
     * lectura) con las omisiones de cada criterio.
     *
     * @param array $b fila de `competenciasBloqueadas()`
     */
    private function bloqueDeCompetencia(int $cargaId, array $b, int $periodoId): array
    {
        $competenciaId = (int) $b['id'];
        $resumen = $this->calModel->getResumenCompetencia($cargaId, $competenciaId, $periodoId, false, true);

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

        return [
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

    /**
     * PARTES de la matriz: reparto goloso por bloques ENTEROS (nunca se corta
     * uno) en el ancho útil de la hoja, descontando N° y nombres, que se
     * repiten en cada parte. Bloques, en orden: cada competencia y, después,
     * las transversales de cada CARGA (28/10/2026: con sus criterios ya no
     * caben siempre juntas al final). El Resumen va en la última parte si cabe;
     * si no, en una parte propia.
     *
     * @return list<array{columnas: int[], transversales: int[], final: bool}>
     *         índices de `$columnas` y de `$transversales`
     */
    public static function partesMatriz(array $columnas, array $transversales): array
    {
        $anchos = [];
        $bloques = [];
        foreach ($columnas as $i => $c) {
            $anchos[]  = $c['tipo'] === 'propia'
                ? count($c['criterios']) * (!empty($c['grupos']) ? self::ANCHO_CRITERIO_GRUPO_MM : self::ANCHO_CRITERIO_MM)
                  + self::ANCHO_LOGRO_MM
                : self::ANCHO_VACIA_MM;
            $bloques[] = ['c', [$i]];
        }
        // Transversales consecutivas de la misma carga = un bloque.
        $ultima = null;
        foreach ($transversales as $i => $t) {
            $w = self::anchoTransversal($t);
            if ($t['carga_id'] === $ultima) {
                $anchos[count($anchos) - 1] += $w;
                $bloques[count($bloques) - 1][1][] = $i;
                continue;
            }
            $anchos[]  = $w;
            $bloques[] = ['t', [$i]];
            $ultima    = $t['carga_id'];
        }
        $final = count($columnas) * self::ANCHO_RESUMEN_MM;

        $out = [];
        foreach (self::empaquetar($anchos, $final, PHP_INT_MAX) as $parte) {
            $cols = [];
            $trs  = [];
            foreach ($parte['columnas'] as $k) {
                [$tipo, $idx] = $bloques[$k];
                if ($tipo === 'c') {
                    $cols = array_merge($cols, $idx);
                } else {
                    $trs = array_merge($trs, $idx);
                }
            }
            $out[] = ['columnas' => $cols, 'transversales' => $trs, 'final' => $parte['final']];
        }
        return $out;
    }

    /**
     * Ancho de una transversal en la matriz: sus criterios + el par del logro;
     * sin evaluar, solo el par en blanco.
     */
    public static function anchoTransversal(array $t): float
    {
        return ($t['evaluada'] ? count($t['criterios']) * self::ANCHO_CRITERIO_MM : 0)
            + self::ANCHO_TRANSVERSAL_MM;
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
