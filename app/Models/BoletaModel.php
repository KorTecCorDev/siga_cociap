<?php

namespace App\Models;

/**
 * BoletaModel
 *
 * Ensamblador UNICO de los datos de una boleta (digital e imprimible).
 * Fusiona las tres copias que vivian dispersas en los controladores
 * (Boleta\BoletaController, BoletaPublicaController publico y
 * Admin\BoletaPublicaController). Un solo punto de verdad para el
 * documento: las reglas de agregacion, transversales, exoneraciones,
 * conducta, asistencia y firma del director se calculan aqui.
 *
 * Es PURO: data in -> array out. La autorizacion (rol, alcance, padre)
 * vive en los entry points (controladores), no aqui.
 */
class BoletaModel extends BaseModel
{
    private CalificacionModel    $calModel;
    private ConductaModel        $conductaModel;
    private AsistenciaModel      $asistenciaModel;
    private OmisionCriterioModel $omisionModel;
    private ExoneracionModel     $exoModel;
    private DirectorEbrModel     $dirModel;
    private PublicacionBoletaModel $publicacionModel;

    public function __construct()
    {
        parent::__construct();
        $this->calModel         = new CalificacionModel();
        $this->conductaModel    = new ConductaModel();
        $this->asistenciaModel  = new AsistenciaModel();
        $this->omisionModel     = new OmisionCriterioModel();
        $this->exoModel         = new ExoneracionModel();
        $this->dirModel         = new DirectorEbrModel();
        $this->publicacionModel = new PublicacionBoletaModel();
    }

    /**
     * Ultimo periodo PUBLICABLE con notas del alumno: cerrado (OFICIAL) o activo
     * con boletas aprobadas (BORRADOR, Hito A). Un bimestre en registro aun NO
     * tiene boleta. Con $soloCerrados = true considera UNICAMENTE bimestres
     * cerrados (trasladados: su boleta es exclusivamente oficial). Retorna el id
     * o null si no hay ninguno.
     *
     * PUNTO UNICO (21/09/2026; antes privado en BoletaController): lo usan las
     * rutas internas de boleta (docente y gestion) y la ficha de matricula para
     * decidir si ofrece los botones. Si ambos no leyeran la misma regla, un
     * boton activo podria llevar al aviso de "sin calificaciones".
     */
    public function periodoPublicableConNotas(int $anioId, int $matriculaId, bool $soloCerrados = false): ?int
    {
        $condicionEstado = $soloCerrados
            ? "p.estado = 'cerrado'"
            : "(p.estado = 'cerrado'
                   OR (p.estado = 'activo' AND p.boletas_aprobadas_en IS NOT NULL))";

        $periodo = $this->queryOne("
            SELECT p.id
            FROM periodos p
            WHERE p.anio_id = ?
              AND {$condicionEstado}
              AND EXISTS (
                  SELECT 1 FROM calificaciones cal
                  INNER JOIN bloqueos_competencia bc
                      ON bc.carga_id = cal.carga_id
                     AND bc.competencia_id = cal.competencia_id
                     AND bc.periodo_id = cal.periodo_id
                  WHERE cal.matricula_id = ? AND cal.periodo_id = p.id
              )
            ORDER BY p.numero DESC
            LIMIT 1
        ", [$anioId, $matriculaId]);

        return $periodo ? (int) $periodo['id'] : null;
    }

    /**
     * Arma la boleta anual completa de un alumno.
     *
     * COMPUERTA DEL HITO A (09/07/2026): un bimestre aporta NOTAS segun su estado
     * de boleta (boleta_estado_bimestre). $datos define el umbral:
     *   - 'oficial'  : solo bimestres CERRADOS **y PUBLICADOS al nivel del alumno**
     *                  (acceso EN LINEA de familias: token, digital, /padre/notas).
     *                  El BORRADOR de Hito A NUNCA se expone al publico.
     *   - 'archivo'  : solo bimestres CERRADOS, IGNORANDO la compuerta de
     *                  publicacion (documento generado por STAFF: salida masiva
     *                  impresa y boleta del trasladado). Ver abajo.
     *   - 'borrador' : cerrado O activo con Hito A aprobado (interno: docente y
     *                  gestion). Un bimestre en 'registro' NO aporta notas aunque
     *                  el docente ya haya bloqueado su competencia.
     *   - 'todos'    : incluye 'registro' (UNICA excepcion: la vista previa de RA,
     *                  su herramienta para decidir el Hito A).
     *
     * COMPUERTA DE PUBLICACION (21/07/2026, migracion 044): cerrar un bimestre ya
     * NO publica sus boletas; publicar es un acto separado, por NIVEL y con
     * fecha/hora. El corte 'oficial' / 'archivo' existe porque RA IMPRIME las
     * boletas ANTES de la reunion de entrega: la compuerta protege el acceso EN
     * LINEA de las familias, no la impresion del colegio. Mismo umbral de datos
     * (solo cerrados) en ambos; solo cambia si se respeta la publicacion.
     *
     * @param int    $matriculaId  matricula consultada (puede ser operativa en retorno).
     * @param int    $periodoId    periodo a resaltar como activo.
     * @param string $datos        'oficial' | 'archivo' | 'borrador' | 'todos'.
     * @param bool   $estructuraCompleta REGLA DE FORMATO (09/07/2026): mantiene la
     *                             estructura anual completa (todas las columnas de
     *                             bimestres) aunque $datos filtre las notas
     *                             insertadas (trasladados via gestion). Sin ella y
     *                             con $datos='oficial', las columnas colapsan a
     *                             cerrados (comportamiento historico del token).
     * @return array|null          null si la matricula o el periodo no existen.
     */
    public function armar(int $matriculaId, int $periodoId, string $datos = 'oficial', bool $estructuraCompleta = false): ?array
    {
        // Retorno de grado: la boleta SIEMPRE se rotula con la matricula oficial
        // (grado/seccion SIAGIE) y sus notas se leen por union de las matriculas
        // involucradas (operativa + oficial). En el caso normal, identidad y
        // unica fuente son la propia matricula.
        $ctx       = $this->calModel->boletaContexto($matriculaId);
        $identidad = (int) $ctx['identidad'];
        $fuentes   = $ctx['fuentes'];

        $alumno  = $this->getAlumno($identidad);
        $periodo = $this->getPeriodo($periodoId);

        if (!$alumno || !$periodo) {
            return null;
        }

        $anioId = (int) $periodo['anio_id'];

        // Compuerta de PUBLICACION: solo la respeta el umbral 'oficial' (acceso en
        // linea de familias). 'archivo' comparte el corte de datos pero la ignora,
        // porque RA imprime las boletas antes de la reunion de entrega. null = la
        // compuerta no aplica a este umbral.
        $publicados = ($datos === 'oficial')
            ? $this->publicacionModel->periodosPublicados($anioId, (int) $alumno['nivel_id'])
            : null;

        // Estructura (columnas) vs datos: las columnas son todos los periodos del
        // anio, salvo el modo historico del token ('oficial'/'archivo' sin
        // estructura completa), que colapsa a cerrados. El umbral de NOTAS lo
        // aplica el guard del loop de abajo segun $datos.
        $colapsarColumnas = in_array($datos, ['oficial', 'archivo'], true) && !$estructuraCompleta;
        $periodos = $this->getPeriodosDelAnio($anioId, $colapsarColumnas);

        // Con las columnas colapsadas, un bimestre cerrado pero AUN NO PUBLICADO
        // tampoco arma columna: la familia no debe ver una columna vacia que
        // delate que el bimestre ya cerro. Sin colapsar (estructura anual
        // completa) la columna se mantiene y es el guard de datos el que la
        // deja vacia.
        if ($publicados !== null && $colapsarColumnas) {
            $periodos = array_values(array_filter(
                $periodos,
                fn(array $p): bool => isset($publicados[(int) $p['id']])
            ));
        }

        // Logro anual = nota del ULTIMO bimestre del anio (mayor numero), visible
        // SOLO cuando ese bimestre esta cerrado. NO es el ultimo bimestre CERRADO
        // ni un promedio: es el nivel alcanzado al final del anio (competencias).
        $ultimoBim        = $this->getUltimoBimestreDelAnio($anioId);
        $ultimoBimestreId = $ultimoBim ? (int) $ultimoBim['id'] : 0;
        $ultimoCerrado    = $ultimoBim !== null && $ultimoBim['estado'] === 'cerrado';

        // Compuerta del Hito A: qué periodos APORTAN notas segun $datos. Un
        // periodo que no aporta queda como columna vacia (la estructura no cambia).
        $periodosConDatos = [];
        $datosPorPeriodo  = [];
        foreach ($periodos as $p) {
            if (!$this->periodoAportaNotas($p, $datos, $publicados)) {
                $datosPorPeriodo[$p['id']] = [];
                continue;
            }
            $periodosConDatos[$p['id']] = true;
            $rows = [];
            foreach ($fuentes as $mid) {
                $rows = array_merge($rows, $this->calModel->getBoletaAlumno((int) $mid, $p['id']));
            }
            $datosPorPeriodo[$p['id']] = $rows;
        }

        // ESQUELETO DEL PLAN (05/08/2026): el documento muestra TODAS las
        // competencias que la seccion dicta, con guion donde no hay dato. Sale de
        // la matricula de EVALUACION (la operativa en un retorno de grado: se
        // evalua donde se cursa, Regla A), no de la de identidad con la que se
        // rotula la boleta. Las notas de la otra matricula no se pierden: el
        // builder crea su fila al superponerlas.
        $esqueleto = $this->calModel->estructuraCompetenciasSeccion((int) $ctx['evaluacion']);

        $areas   = $this->buildAreasConBimestres($datosPorPeriodo, $periodos, $ultimoBimestreId, $ultimoCerrado, $esqueleto);
        $exoData = $this->exoModel->getConCompetenciasParaBoletaUnion($fuentes, $anioId);
        $areas   = ExoneracionModel::inyectarEnAreas($areas, $exoData, $periodos);

        // Asistencia: una columna por bimestre que APORTA, con el MISMO umbral que
        // las notas (`periodoAportaNotas`) + total. Hasta el 04/08/2026 tenia su
        // propio criterio ("solo cerrados", independiente de $datos), y por eso la
        // vista previa de RA mostraba las notas y la conducta del bimestre en curso
        // pero NO su asistencia. Unificado: el umbral vive en un solo sitio.
        //   - 'oficial' / 'archivo': sin cambio — `periodoAportaNotas` exige bimestre
        //     CERRADO, y en 'oficial' ademas PUBLICADO (compuerta 044). Si no, la
        //     familia veria la columna de asistencia de un bimestre cuyas notas
        //     siguen ocultas, lo que delataria que ya cerro.
        //   - 'borrador' y 'todos': suman el bimestre en curso, que es el dato que
        //     RA necesita ver para decidir.
        // El total SUMA los bimestres CON REGISTRO mostrados (no un acumulado por
        // numero<=, que podria incluir uno no mostrado); incluye el bimestre en
        // curso a proposito, asi que en vista previa es provisional.
        // ESTRUCTURA ANUAL COMPLETA tambien aqui (04/08/2026): SIEMPRE una columna por
        // bimestre del anio, aunque no tenga dato. La tabla de asistencia es parte del
        // MODELO OFICIAL igual que la de notas, asi que su forma no puede depender de
        // cuantos bimestres cerraron. Mismo argumento que las notas: con las columnas
        // fijas, una vacia no revela si el bimestre cerro.
        $asisBimestres = [];
        $asisTotal = ['faltas' => 0, 'faltas_justificadas' => 0, 'tardanzas' => 0, 'tardanzas_justificadas' => 0];
        $ceros = ['faltas' => 0, 'faltas_justificadas' => 0, 'tardanzas' => 0, 'tardanzas_justificadas' => 0];

        foreach ($this->getPeriodosDelAnio($anioId) as $pa) {
            // SIN DATO que mostrar, por TRES motivos distintos que se pintan igual (guion):
            //   - el bimestre aun no puede tener registro ('pendiente'),
            //   - no corresponde a este umbral (no publicado, o en curso bajo 'oficial'), o
            //   - NADIE registro la asistencia de este alumno en ese bimestre (07/08/2026).
            // Se marca para que la vista lo pinte apagado en vez de con ceros: un cero
            // se lee como dato real ("no falto ningun dia") y aqui no hay dato. Ademas,
            // con guiones el lector no intenta cuadrar la suma con el Total.
            //
            // EL TERCER MOTIVO corrige un dato FALSO que se estaba imprimiendo: un alumno
            // que llego despues del bimestre no tiene fila en `inasistencias`, y
            // `getDelBimestre` devuelve ceros ante la ausencia de fila, asi que su boleta
            // afirmaba "0 faltas" de un bimestre que NO CURSO. Medido en la matricula 694.
            // Va en TERCER lugar a proposito: `||` corta en corto, asi que la consulta
            // extra no se ejecuta cuando el umbral ya dijo que no hay nada que mostrar
            // — se conserva la regla de que una columna vacia no sale de datos que este
            // umbral no debe ver.
            $sinRegistro = !$this->periodoAportaNotas($pa, $datos, $publicados)
                        || ($pa['estado'] ?? '') === 'pendiente'
                        || !$this->asistenciaModel->tieneRegistroUnion($fuentes, (int) $pa['id']);

            // OJO: variable propia, NO reusar el nombre $datos (parametro del metodo,
            // leido mas abajo por el filtro de conducta). Si no aporta NO se consulta:
            // la columna vacia no puede salir de datos que este umbral no debe ver.
            $asisDatos = $sinRegistro
                ? $ceros
                : $this->asistenciaModel->getDelBimestreUnion($fuentes, (int) $pa['id']);

            $asisBimestres[] = [
                'id'           => (int) $pa['id'],
                'numero'       => (int) $pa['numero'],
                'datos'        => $asisDatos,
                'sin_registro' => $sinRegistro,
            ];
            if (!$sinRegistro) {
                foreach ($asisTotal as $k => $_) { $asisTotal[$k] += (int) $asisDatos[$k]; }
            }
        }

        // Conducta [periodo_id => literal]: mismo umbral del Hito A que las notas.
        // Solo los periodos que APORTAN (segun $datos) muestran conducta; en 'todos'
        // no se filtra (vista previa de RA).
        $conducta = $this->conductaModel->getParaBoletaUnion($fuentes, $anioId);
        if ($datos !== 'todos') {
            $conducta = array_intersect_key($conducta, $periodosConDatos);
        }

        return [
            'alumno'            => $alumno,
            'periodos'          => $periodos,
            'periodo_activo_id' => $periodoId,
            'areas'             => $areas,
            'conducta'          => $conducta,
            'asistencia'        => [
                'bimestres' => $asisBimestres,
                'total'     => $asisTotal,
            ],
            'omisiones'   => $this->omisionModel->getPorMatriculaAnioUnion($fuentes, $anioId),
            'institucion' => config('institucion'),
            'tutor'       => $this->getTutorSeccion($identidad),
            'directorEbr' => $this->dirModel->getVigenteEnFecha($anioId),
        ];
    }

    // ── Queries privadas ────────────────────────────────────────

    private function getAlumno(int $matriculaId): ?array
    {
        return $this->queryOne("
            SELECT
                m.id                AS matricula_id,
                p.nombres,
                p.apellido_paterno,
                p.apellido_materno,
                p.dni,
                CONCAT(
                    p.apellido_paterno, ' ',
                    p.apellido_materno, ', ',
                    p.nombres
                )                   AS nombre_completo,
                g.nombre_display    AS grado_nombre,
                s.nombre            AS seccion_nombre,
                n.id                AS nivel_id,
                n.nombre            AS nivel_nombre,
                n.codigo            AS nivel_codigo,
                n.escala_boleta,
                a.anio              AS anio_academico
            FROM matriculas m
            INNER JOIN estudiantes e        ON e.id = m.estudiante_id
            INNER JOIN personas p           ON p.id = e.persona_id
            INNER JOIN secciones s          ON s.id = m.seccion_id
            INNER JOIN grados g             ON g.id = s.grado_id
            INNER JOIN niveles n            ON n.id = g.nivel_id
            INNER JOIN anios_academicos a   ON a.id = m.anio_id
            WHERE m.id = ?
            LIMIT 1
        ", [$matriculaId]);
    }

    private function getPeriodo(int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT
                p.id,
                p.anio_id,
                a.anio,
                CONCAT(p.nombre_display, ' — ', a.anio) AS nombre_display
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
            LIMIT 1
        ", [$periodoId]);
    }

    /**
     * ¿El periodo aporta NOTAS a la boleta segun el umbral $datos? Compuerta del
     * Hito A: usa boleta_estado_bimestre (punto unico de verdad) por periodo.
     *   - 'oficial'  -> 'oficial' (cerrado) Y publicado al nivel del alumno.
     *   - 'archivo'  -> 'oficial' (cerrado), sin mirar la publicacion.
     *   - 'borrador' -> 'oficial' o 'borrador' (cerrado o activo con Hito A).
     *   - 'todos'    -> siempre (incluye 'registro'; solo vista previa de RA).
     *
     * @param array|null $publicados set [periodo_id => true] de la compuerta de
     *                   publicacion, o null si el umbral no la respeta.
     */
    private function periodoAportaNotas(array $periodo, string $datos, ?array $publicados = null): bool
    {
        if ($datos === 'todos') {
            return true;
        }
        $estado = boleta_estado_bimestre(
            $periodo['estado'] ?? null,
            $periodo['boletas_aprobadas_en'] ?? null
        );

        if ($datos === 'oficial' || $datos === 'archivo') {
            if ($estado !== 'oficial') {
                return false;
            }
            // 'archivo' ($publicados === null) ignora la compuerta a proposito.
            return $publicados === null || isset($publicados[(int) $periodo['id']]);
        }

        return $estado !== 'registro';   // 'borrador': oficial o borrador
    }

    /**
     * Periodos del anio. Con $soloCerrados filtra a bimestres CERRADOS
     * (estructura de columnas del token: el BORRADOR de Hito A no arma columna).
     * Incluye `boletas_aprobadas_en` para que el guard de datos de armar() pueda
     * derivar el estado de boleta (Hito A) por periodo.
     */
    private function getPeriodosDelAnio(int $anioId, bool $soloCerrados = false): array
    {
        $filtro = $soloCerrados ? "AND estado = 'cerrado'" : '';
        return $this->query("
            SELECT id, numero, nombre_display, estado, boletas_aprobadas_en
            FROM periodos
            WHERE anio_id = ? {$filtro}
            ORDER BY numero
        ", [$anioId]);
    }

    /**
     * Ultimo bimestre del anio: el periodo de mayor `numero` (dinamico, no
     * hardcodea la cantidad). Su cierre habilita el logro anual. Retorna
     * id/numero/estado o null si el anio no tiene periodos.
     */
    private function getUltimoBimestreDelAnio(int $anioId): ?array
    {
        return $this->queryOne("
            SELECT id, numero, estado
            FROM periodos
            WHERE anio_id = ?
            ORDER BY numero DESC
            LIMIT 1
        ", [$anioId]);
    }

    /**
     * Reorganiza los datos planos por periodo en una estructura
     * areas[nombre_area][comp_id] = { nombre, bimestres[periodo_id], literal_final }.
     * `literal_final` (logro anual) sale de la nota del ULTIMO bimestre del anio,
     * solo si ese bimestre esta cerrado (ver getUltimoBimestreDelAnio).
     */
    private function buildAreasConBimestres(
        array $datosPorPeriodo,
        array $periodos,
        int $ultimoBimestreId,
        bool $ultimoCerrado,
        array $esqueleto = []
    ): array {
        $areas = [];

        // ESQUELETO PRIMERO (05/08/2026): todas las competencias del plan de la
        // seccion existen en el documento aunque no tengan ninguna nota; la vista
        // las pinta con guion. Sembrarlo antes fija ademas el ORDEN de areas y
        // competencias (a.orden, comp.orden), que hasta ahora dependia de que se
        // habia calificado y variaba entre alumnos de la misma seccion.
        // Las notas se superponen despues: una competencia con nota que ya NO
        // este en el plan activo (carga desactivada tras evaluar) sigue creando
        // su entrada en el loop de abajo. Esa es la red de seguridad — ninguna
        // nota registrada puede desaparecer del documento.
        foreach ($esqueleto as $fila) {
            $nombreArea = $this->nombreAreaBoleta($fila);
            $compId     = $fila['competencia_id'];
            if (!isset($areas[$nombreArea][$compId])) {
                $areas[$nombreArea][$compId] = $this->nuevaEntradaCompetencia($fila);
            }
        }

        foreach ($datosPorPeriodo as $periodoId => $notas) {
            foreach ($notas as $nota) {
                $nombreArea = $this->nombreAreaBoleta($nota);
                $compId     = $nota['competencia_id'];

                if (!isset($areas[$nombreArea][$compId])) {
                    $areas[$nombreArea][$compId] = $this->nuevaEntradaCompetencia($nota);
                }

                $notaNum = isset($nota['nota_numerica']) ? (int) $nota['nota_numerica'] : null;
                $areas[$nombreArea][$compId]['bimestres'][$periodoId] = [
                    'nota'       => $notaNum,
                    'literal'    => $notaNum !== null ? CalificacionModel::toLiteral($notaNum) : null,
                    'conclusion' => $nota['conclusion_descriptiva'] ?? null,
                ];
            }
        }

        // Logro anual = literal de la nota del ULTIMO bimestre del anio, y SOLO si
        // ese bimestre esta cerrado. No se promedian los bimestres ni se usa el
        // ultimo CERRADO: es el nivel final del anio (modelo por competencias).
        // Sin cierre del ultimo bimestre => null (el chip "Anual" muestra —).
        foreach ($areas as &$comps) {
            foreach ($comps as &$comp) {
                $b = $ultimoCerrado ? ($comp['bimestres'][$ultimoBimestreId] ?? null) : null;
                $comp['literal_final'] = ($b !== null && $b['nota'] !== null)
                    ? $b['literal']
                    : null;
            }
        }
        unset($comps, $comp);

        return $areas;
    }

    /**
     * Nombre del bloque de area tal como se rotula en la boleta. PUNTO UNICO:
     * lo comparten las filas de nota y las del esqueleto del plan, y de el
     * depende que ambas caigan en la MISMA clave de $areas (si divergieran, una
     * competencia con nota saldria duplicada bajo dos titulos).
     */
    private function nombreAreaBoleta(array $fila): string
    {
        $nombre = $fila['nombre_boleta'] ?? $fila['area_nombre'];
        if (!empty($fila['alias_boleta'])) {
            $nombre .= ' ' . $fila['alias_boleta'];
        }
        return $nombre;
    }

    /**
     * Entrada de competencia SIN valores (los bimestres los llena el loop de
     * notas). Sirve igual a una fila de nota y a una del esqueleto del plan
     * porque ambas traen las mismas columnas.
     */
    private function nuevaEntradaCompetencia(array $fila): array
    {
        // En secciones unidocentes (1°-3° primaria) el área se muestra como
        // bloque área-curso: cada competencia por su código MINEDU + nombre,
        // SIN prefijo ni etiqueta de subárea (afecta boleta imprimible y
        // digital). Para especialistas (4°-6° y secundaria) se conserva el
        // prefijo/etiqueta "Aritmética — …".
        $muestraSubarea = empty($fila['es_unidocente'])
            && ($fila['area_tipo'] ?? '') === 'con_subareas'
            && !empty($fila['subarea_nombre']);
        $prefijoSubarea = $muestraSubarea ? $fila['subarea_nombre'] . ' — ' : '';

        return [
            'nombre'            => trim(
                $prefijoSubarea .
                ($fila['codigo_minedu'] ? $fila['codigo_minedu'] . '. ' : '') .
                ($fila['nombre_corto'] ?? $fila['competencia_nombre'] ?? '')
            ),
            'nombre_largo'      => trim($prefijoSubarea . ($fila['competencia_nombre'] ?? '')),
            'subarea_nombre'    => $muestraSubarea ? ($fila['subarea_nombre'] ?? '') : '',
            'competencia_texto' => $fila['competencia_nombre'] ?? '',
            'bimestres'         => [],
        ];
    }

    private function getTutorSeccion(int $matriculaId): ?array
    {
        $seccion = $this->queryOne("
            SELECT s.tutor_id
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            WHERE m.id = ?
            LIMIT 1
        ", [$matriculaId]);

        $tutorId = (int) ($seccion['tutor_id'] ?? 0);
        if (!$tutorId) {
            return null;
        }

        $persona = $this->queryOne("
            SELECT p.apellido_paterno, p.apellido_materno, p.nombres, p.sexo
            FROM usuarios u
            INNER JOIN personas p ON p.id = u.persona_id
            WHERE u.id = ?
            LIMIT 1
        ", [$tutorId]);

        if (!$persona || empty($persona['apellido_paterno'])) {
            return null;
        }

        return [
            'nombre' => $persona['apellido_paterno'] . ' '
                      . $persona['apellido_materno'] . ', '
                      . $persona['nombres'],
            'sexo'   => $persona['sexo'],
        ];
    }
}
