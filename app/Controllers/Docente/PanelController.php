<?php

namespace App\Controllers\Docente;

use App\Controllers\BaseController;
use App\Models\CalificacionModel;
use App\Models\ConductaModel;
use App\Models\DirectorEbrModel;
use App\Models\EstudianteModel;
use App\Models\HorarioModel;
use App\Models\OrdenMeritoModel;
use App\Models\PublicacionBoletaModel;
use App\Models\TransversalModel;
use App\Models\NotaExternaModel;
use Core\Session;
use Core\View;

/**
 * PanelController
 * Dashboard del docente y nómina de matriculados de su(s) nivel(es).
 */
class PanelController extends BaseController
{
    private CalificacionModel $calModel;
    private TransversalModel  $transModel;
    private ConductaModel     $conductaModel;
    private HorarioModel      $horarioModel;

    public function __construct()
    {
        $this->requireRole(['docente', 'admin']);
        $this->calModel      = new CalificacionModel();
        $this->transModel    = new TransversalModel();
        $this->conductaModel = new ConductaModel();
        $this->horarioModel  = new HorarioModel();
    }

    /**
     * GET /docente/inicio — dashboard del docente.
     */
    public function index(): void
    {
        $user    = Session::user();
        $did     = (int) $user['id'];
        $periodo = $this->getPeriodoActivo();
        $pid     = $periodo ? (int) $periodo['id'] : 0;

        $cargas  = $pid ? $this->getCargasResumen($did, $pid) : [];

        // Docente de aula (unidocente): solo si es el TUTOR de una seccion
        // es_unidocente (es_aula), donde dicta TODAS las areas core. Un
        // especialista (Ingles, Ed. Fisica) que dicta en secciones unidocentes
        // NO es aula: es_unidocente por si solo no alcanza.
        //   - tieneAula: es tutor(a) de aula (para el badge de identidad).
        //   - soloAula : el aula es TODA su carga (habilita el rotulo "Mi aula").
        // Caso mixto (unidocente de una seccion + especialista en otra, p.ej. el
        // docente que ademas dicta C. y T. en otro grado): tieneAula=true pero
        // soloAula=false, asi el dashboard usa el rotulo generico y NO mezcla
        // secciones bajo "Mi aula"; la identidad queda en el badge de bienvenida.
        $tieneAula  = false;
        $hayOtras   = false;
        $aula       = null;
        $areasAula  = [];
        $seccionesAula = [];   // labels unicos de aulas unidocentes (para los chips)
        foreach ($cargas as $c) {
            if (!empty($c['es_aula'])) {
                $tieneAula = true;
                $label     = trim($c['grado_nombre'] . ' ' . $c['seccion_nombre']);
                $aula    ??= $label;
                $seccionesAula[$label] = true;
                $areasAula[(int) $c['area_id']] = true;
            } else {
                $hayOtras = true;
            }
        }
        $soloAula   = $tieneAula && !$hayOtras;
        $nAreasAula = count($areasAula);

        // KPIs / resumen de cargas
        $sumTotal = $sumBloq = $completas = $sinCriterios = 0;
        $pendientes = [];
        foreach ($cargas as $c) {
            $total = (int) $c['total_comp'];
            $bloq  = (int) $c['bloq'];
            $crit  = (int) $c['con_criterios'];
            $sumTotal += $total;
            $sumBloq  += $bloq;
            if ($total > 0 && $bloq >= $total) {
                $completas++;
            }
            if ($bloq === 0 && $crit === 0) {
                $sinCriterios++;
            }
            // Lista de pendientes: cargas que aún no están completas.
            if ($total === 0 || $bloq < $total) {
                $pendientes[] = [
                    'id'      => (int) $c['id'],
                    'nombre'  => $c['nombre_display'] ?? '—',
                    'seccion' => $c['grado_nombre'] . ' ' . $c['seccion_nombre'],
                    'motivo'  => ($bloq === 0 && $crit === 0)
                        ? 'Sin criterios'
                        : 'Faltan ' . max(0, $total - $bloq) . ' de ' . $total,
                    'critico' => ($bloq === 0 && $crit === 0),
                    'faltan'  => max(0, $total - $bloq),
                ];
            }
        }
        $avance = $sumTotal > 0 ? (int) round($sumBloq / $sumTotal * 100) : 0;

        // Prioridad: primero lo más crítico (cargas sin criterios, sin iniciar),
        // luego las que tienen más competencias por bloquear. usort es estable
        // en PHP 8.2, así que en empate se conserva el orden por nivel/grado.
        usort($pendientes, static function (array $a, array $b): int {
            return [(int) $b['critico'], $b['faltan']]
               <=> [(int) $a['critico'], $a['faltan']];
        });

        // Días para el cierre (limite_notas)
        $diasCierre = null;
        if ($periodo && !empty($periodo['limite_notas'])) {
            $diasCierre = (int) ceil(
                (strtotime($periodo['limite_notas']) - time()) / 86400
            );
        }

        // Card de Tutoría (solo tutores del año activo)
        $tutoria      = null;
        $seccionTutor = $this->transModel->getSeccionDelTutor($did);
        if ($seccionTutor && $periodo) {
            $sid    = (int) $seccionTutor['id'];
            $estado = $this->transModel->estadoCargasSeccion($sid, $pid);
            $cierre = $this->transModel->getCierreVigente($sid, $pid);
            $listo  = $estado['total'] > 0 && $estado['bloqueadas'] >= $estado['total'];
            $tutoria = [
                'seccion'    => $seccionTutor,
                'total'      => $estado['total'],
                'bloqueadas' => $estado['bloqueadas'],
                'cierre'     => $cierre,
                'listo'      => $listo,
                'pendientes' => ($listo && !$cierre)
                    ? $this->transModel->conclusionesObligatoriasPendientes(
                        $sid, $pid, $seccionTutor['nivel_codigo']
                      )
                    : 0,
            ];
        }

        // Card de Conducta (solo tutores del año activo): pendiente (RA no
        // bloqueó) / disponible / cerrado. Misma fuente que /docente/mis-cargas.
        $conducta = null;
        if ($seccionTutor && $periodo) {
            $cc = $this->conductaModel->getCierreVigente((int) $seccionTutor['id'], $pid);
            $conducta = [
                'seccion' => $seccionTutor,
                'cierre'  => $cc,
                'cerrado' => $cc && !empty($cc['tutor_cerrado_en']),
            ];
        }

        // Chips de identidad del encabezado: un chip por cada ROL que cumple el
        // docente, combinables. Unidocente (dicta todas las areas de un aula) y
        // tutor (responsable de una seccion) son atributos INDEPENDIENTES, con
        // fuentes distintas (es_unidocente vs secciones.tutor_id).
        //   - "Unidocente - X"  por cada aula unidocente.
        //   - "Tutor(a) - Y"    si es tutor de una seccion (cualquier nivel).
        //   - "Docente"         como complemento si ademas dicta fuera de su aula,
        //                       o como unica etiqueta del especialista sin rol.
        $chips = [];
        foreach (array_keys($seccionesAula) as $label) {
            $chips[] = ['tipo' => 'unidocente', 'texto' => 'Unidocente — ' . $label];
        }
        if ($seccionTutor) {
            $rotTutor = match ($user['sexo'] ?? null) {
                'M'     => 'Tutor',
                'F'     => 'Tutora',
                default => 'Tutor(a)',
            };
            $chips[] = [
                'tipo'  => 'tutor',
                'texto' => $rotTutor . ' — ' . trim($seccionTutor['grado_nombre'] . ' ' . $seccionTutor['nombre']),
            ];
        }
        if ((!$tieneAula && !$seccionTutor) || ($tieneAula && $hayOtras)) {
            $chips[] = ['tipo' => 'docente', 'texto' => 'Docente'];
        }

        // Avance TOTAL del bimestre: suma TODAS las responsabilidades del docente
        // como unidades — cada competencia académica + (solo si es tutor) la
        // tutoría y la conducta de su sección, una unidad cada una. Es el número
        // global del KPI "Avance del bimestre"; el avance de la card "Mis cargas"
        // sigue siendo solo académico ($avance).
        $respTotal = $sumTotal;
        $respHecho = $sumBloq;
        if ($tutoria !== null) {
            $respTotal++;
            if ($tutoria['cierre']) { $respHecho++; }
        }
        if ($conducta !== null) {
            $respTotal++;
            if ($conducta['cerrado']) { $respHecho++; }
        }
        $avanceTotal = $respTotal > 0 ? (int) round($respHecho / $respTotal * 100) : 0;

        // Niveles del docente + resumen de nómina
        $niveles      = $this->getNivelesDocente($did);
        $nominaResumen = $this->getNominaResumen($niveles);
        $totalNomina  = array_sum(array_column($nominaResumen, 'n'));

        // Horario de la semana
        $horario = $this->getHorario($did);

        $this->view('docente/inicio', [
            'titulo'        => 'Inicio',
            'periodo'       => $periodo,
            'cargas'        => $cargas,
            'tieneAula'     => $tieneAula,
            'soloAula'      => $soloAula,
            'aula'          => $aula,
            'chips'         => $chips,
            'nAreasAula'    => $nAreasAula,
            'nCargas'       => count($cargas),
            'avance'        => $avance,
            'avanceTotal'   => $avanceTotal,
            'sumTotal'      => $sumTotal,
            'sumBloq'       => $sumBloq,
            'completas'     => $completas,
            'sinCriterios'  => $sinCriterios,
            'diasCierre'    => $diasCierre,
            'pendientes'    => $pendientes,
            'tutoria'       => $tutoria,
            'conducta'      => $conducta,
            'niveles'       => $niveles,
            'nominaResumen' => $nominaResumen,
            'totalNomina'   => $totalNomina,
            'horario'       => $horario,
            'page_scripts'  => [],
        ]);
    }


    /**
     * GET /docente/notas-origen/{matricula}
     * Las calificaciones que un estudiante de MI sección trae de su colegio
     * anterior. SOLO LECTURA.
     *
     * Existe porque esas notas NO salen en la boleta (regla del colegio): el
     * Informe de Progreso del COCIAP lleva solo lo cursado aquí. Sin esta
     * pantalla el docente no sabría con qué llega el estudiante.
     *
     * GUARDA: hay que tener carga ACTIVA en la sección de esa matrícula. Si no,
     * 404 — ni siquiera se confirma que la matrícula exista.
     *
     * El docente ve el informe COMPLETO, con las filas de su(s) área(s)
     * RESALTADAS: el plan del otro colegio no coincide con el nuestro, así que
     * recortar por área escondería lo que no tiene equivalente.
     */
    public function notasOrigen(string $matriculaId): void
    {
        $mid   = (int) $matriculaId;
        $did   = (int) (Session::user()['id'] ?? 0);
        $model = new NotaExternaModel();

        // Un admin entra a supervisar (el constructor ya lo admite); un docente
        // necesita carga en la sección.
        if (!has_role('admin') && !$model->docenteTieneAcceso($mid, $did)) {
            $this->notFound();
        }

        $notas = $model->getDeMatricula($mid);
        if ($notas === []) {
            $this->notFound();
        }

        $estudiante = $this->calModel->queryOne("
            SELECT TRIM(CONCAT(per.apellido_paterno, ' ', per.apellido_materno, ', ', per.nombres)) AS nombre_completo,
                   g.nombre_display AS grado_nombre,
                   s.nombre         AS seccion_nombre
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas per  ON per.id = e.persona_id
            INNER JOIN secciones s   ON s.id = m.seccion_id
            INNER JOIN grados g      ON g.id = s.grado_id
            WHERE m.id = ?
        ", [$mid]);
        if ($estudiante === null) {
            $this->notFound();
        }

        // Agrupadas por periodo, tal y como vienen en el informe de origen, y
        // dentro de cada periodo en el ORDEN DE LA CURRÍCULA (18/09/2026): lo
        // que no calza con nuestro plan va al final.
        $porPeriodo = [];
        foreach ($notas as $n) {
            $porPeriodo[(string) $n['periodo_nombre']][] = $n;
        }
        foreach ($porPeriodo as $periodoNombre => $filas) {
            $porPeriodo[$periodoNombre] = $model->ordenarSegunCurricula($mid, $filas);
        }

        $this->view('docente/notas-origen', [
            'titulo'      => 'Notas del colegio de origen',
            'estudiante'  => $estudiante,
            'porPeriodo'  => $porPeriodo,
            'misAreas'    => $model->areasDelDocenteEnSeccion($mid, $did),
            'colegio'     => $notas[0]['colegio_origen'] ?? null,
            'total'       => count($notas),
        ]);
    }
    /**
     * GET /docente/nomina — buscador en vivo de matriculados (aprobados) de los
     * niveles del docente + selector para imprimir la nómina de una sección.
     * Nunca expone el DNI (dato sensible de consulta restringida).
     */
    public function nomina(): void
    {
        $user    = Session::user();
        $did     = (int) $user['id'];
        $niveles = $this->getNivelesDocente($did);

        // Buscador en vivo: SOLO matriculas 'aprobada' (decision del usuario,
        // 10/08/2026). Es una CONSULTA, no un roster: la grilla de
        // calificaciones, la de asistencia y la de conducta siguen mostrando
        // 'pendiente' y 'desactivado' —esos alumnos asisten y SE EVALUAN—, que
        // es el invariante de CLAUDE.md y lo que arreglo el fix del 04/08.
        // Aqui solo se oculta la CARD del resultado; no se saca a nadie de la
        // evaluacion ni cambia un solo dato.
        $alumnos = $this->getMatriculados($niveles, 0, true);

        // ORDEN DE MERITO — bajo la COMPUERTA DE PUBLICACION (044).
        // Antes se usaba el ULTIMO BIMESTRE CERRADO, y eso era una FUGA: cerrar
        // congela el ranking, pero lo que lo hace visible es PUBLICAR, que es un
        // acto separado, por NIVEL y con fecha. En la ventana entre ambos —dias,
        // no minutos— esta nomina mostraba el puesto y el nombre del bimestre
        // que el propio /docente/orden-merito le ocultaba.
        // La respuesta va POR NIVEL porque la compuerta lo es: primaria suele
        // publicarse un dia antes que secundaria, y en esa franja un docente con
        // ambos niveles ve legitimamente distinto bimestre en cada card.
        // 🔴 DOS CONCEPTOS DISTINTOS, DOS VARIABLES CON NOMBRE PROPIO. Antes los
        // dos salian de una sola variable `$bimestre` (el ultimo cerrado), y al
        // cambiar la fuente del MERITO se rompio en silencio la de la BOLETA:
        //   · $publicados    → MERITO. Bajo la compuerta 044, y POR NIVEL.
        //   · $ultimoCerrado → BOLETA oficial que el docente puede abrir. NO
        //     pasa por la 044 a proposito: su regla es `boleta_estado_bimestre`
        //     (umbral 'borrador'/'archivo'), porque son las notas que el propio
        //     docente registra, no un ranking comparativo.
        // No volver a fusionarlas: reglas distintas, vigencias distintas.
        $estModel   = new EstudianteModel();
        $anio       = $estModel->anioActivo();
        $anioId     = $anio ? (int) $anio['id'] : 0;

        $publicados = $anioId
            ? (new PublicacionBoletaModel())->ultimoPeriodoPublicadoPorNivel($anioId)
            : [];

        $ultimoCerrado = $anioId ? $estModel->ultimoBimestreCerrado($anioId) : null;

        // Un mismo periodo suele servir a varios niveles: se agrupan los grados
        // por periodo para no repetir la consulta del ranking.
        $gradosPorPeriodo = [];
        $bimestresMerito  = [];
        foreach ($alumnos as $a) {
            $nid = (int) ($a['nivel_id'] ?? 0);
            if (!isset($publicados[$nid])) {
                continue;
            }
            $pid = (int) $publicados[$nid]['id'];
            $gid = (int) ($a['grado_id'] ?? 0);
            if ($gid) {
                $gradosPorPeriodo[$pid][$gid] = true;
            }
            $bimestresMerito[$nid] = [
                'nivel_nombre' => $a['nivel_nombre'],
                'bimestre'     => $publicados[$nid]['nombre_display'],
            ];
        }

        $ordenModel = new OrdenMeritoModel();
        $puestos    = [];
        foreach ($gradosPorPeriodo as $pid => $set) {
            $puestos[$pid] = $ordenModel->puestosPorGrado(array_keys($set), $pid);
        }

        foreach ($alumnos as &$a) {
            $nid = (int) ($a['nivel_id'] ?? 0);
            $pid = isset($publicados[$nid]) ? (int) $publicados[$nid]['id'] : null;
            // `merito_visible` distingue "su nivel aun no se publico" de "esta
            // publicado pero el alumno no tiene puesto": son mensajes distintos.
            $a['merito_visible'] = $pid !== null;
            $a['puesto'] = $pid !== null
                ? ($puestos[$pid][(int) $a['matricula_id']]['puesto'] ?? null)
                : null;
        }
        unset($a);

        // Lista de secciones (para el selector de impresión), única y ordenada.
        // Solo cuentan los 'aprobada': el selector alimenta la nómina imprimible
        // (documento oficial), que excluye pendientes y desactivados.
        $secciones = [];
        foreach ($alumnos as $a) {
            if ($a['estado'] !== 'aprobada') {
                continue;
            }
            $sid = (int) $a['seccion_id'];
            if (!isset($secciones[$sid])) {
                $secciones[$sid] = [
                    'seccion_id'     => $sid,
                    'nivel_nombre'   => $a['nivel_nombre'],
                    'grado_nombre'   => $a['grado_nombre'],
                    'seccion_nombre' => $a['seccion_nombre'],
                    'n'              => 0,
                ];
            }
            $secciones[$sid]['n']++;
        }

        // Estado de la boleta del bimestre ACTIVO: 'borrador' tras el Hito A (RA
        // aprobo), 'registro' antes. Mientras el activo no se aprueba, la boleta
        // visible es la OFICIAL del ultimo bimestre CERRADO ($ultimoCerrado).
        $periodoActivo = $this->getPeriodoActivo();
        $estadoBoleta  = boleta_estado_bimestre(
            $periodoActivo['estado'] ?? null,
            $periodoActivo['boletas_aprobadas_en'] ?? null
        );

        $this->view('docente/nomina', [
            'titulo'           => 'Nómina de matriculados',
            'alumnos'          => $alumnos,
            'secciones'        => array_values($secciones),
            'total'            => count($alumnos),
            'tieneOrdenMerito' => $bimestresMerito !== [],
            // Un rotulo por nivel: en la ventana de publicacion escalonada puede
            // haber dos bimestres vigentes a la vez, y decir solo uno mentiria.
            'bimestresMerito'  => array_values($bimestresMerito),
            'estadoBoleta'     => $estadoBoleta,
            'bimestreActivo'   => $periodoActivo['nombre_display'] ?? null,
            // Alimenta el PANEL DE BOLETA de cada card (`$hayBoletaVisible`): si
            // llega null con el bimestre activo aun en 'registro', el panel
            // desaparece para TODOS los alumnos. Paso en el commit ea5c446.
            'bimestreCerrado'  => $ultimoCerrado['nombre_display'] ?? null,
            'page_scripts'     => ['nomina'],
        ]);
    }

    /**
     * GET /docente/nomina/{seccion_id}/imprimir — nómina A4 de una sección.
     * Nunca incluye DNI (dato sensible de consulta restringida).
     */
    public function nominaImprimir(string $seccionId): void
    {
        $user      = Session::user();
        $did       = (int) $user['id'];
        $seccionId = (int) $seccionId;
        $niveles   = $this->getNivelesDocente($did);
        $nivelIds  = array_map('intval', array_column($niveles, 'id'));

        $seccion = $this->calModel->queryOne("
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

        // Autorización: la sección debe pertenecer a un nivel del docente.
        if (!$seccion || !in_array((int) $seccion['nivel_id'], $nivelIds, true)) {
            $this->redirectWithError(url('docente/nomina'), 'Sección no disponible.');
        }

        $alumnos = $this->getMatriculados($niveles, $seccionId);

        // Sello del Director EBR vigente del año académico activo (solo el sello).
        $anio        = $this->getAnioActivo();
        $directorEbr = $anio
            ? (new DirectorEbrModel())->getVigenteEnFecha((int) $anio['id'])
            : null;

        View::setLayout('print');
        $this->view('docente/nomina-imprimir', [
            'titulo'      => 'Nómina ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion'     => $seccion,
            'alumnos'     => $alumnos,
            'directorEbr' => $directorEbr,
            'anio'        => $anio,
        ]);
    }

    /**
     * GET /docente/horario/imprimir — horario semanal del docente en tabla de
     * doble entrada (días en columnas, franjas horarias en filas). Una hoja
     * A4 horizontal, con color por carga y leyenda al final. Layout: print.
     */
    public function horarioImprimir(): void
    {
        $user     = Session::user();
        $did      = (int) $user['id'];
        $sesiones = $this->getHorario($did);

        if (empty($sesiones)) {
            $this->redirectWithError(
                url('docente/inicio'),
                'No tienes horario registrado para imprimir.'
            );
        }

        // Grilla armada por HorarioModel — PUNTO ÚNICO compartido con el
        // horario por sección. Hasta el 24/08/2026 estas ~130 líneas vivían
        // aquí inline, y era la razón por la que no había horario por sección.
        $anio    = $this->getAnioActivo();
        $horario = $this->horarioModel->armarGrilla(
            $sesiones,
            $this->horarioModel->duracionHoraAcademica($anio ? (int) $anio['id'] : null),
            'seccion'
        );

        // Documento → nombre legal completo del docente (no el nombre corto).
        $docente = trim(
            ($user['apellido_paterno'] ?? '') . ' ' .
            ($user['apellido_materno'] ?? '') . ', ' .
            ($user['nombres'] ?? '')
        );

        // Sello del Director EBR vigente del año académico activo.
        $directorEbr = $anio
            ? (new DirectorEbrModel())->getVigenteEnFecha((int) $anio['id'])
            : null;

        View::setLayout('print');
        $this->view('docente/horario-imprimir', [
            'titulo'      => 'Horario — ' . $docente,
            'docente'     => $docente,
            'anio'        => $anio,
            'dias'        => $horario['dias'],
            'segmentos'   => $horario['segmentos'],
            'startAt'     => $horario['startAt'],
            'covered'     => $horario['covered'],
            'leyenda'     => $horario['leyenda'],
            'totalHoras'  => $horario['totalHoras'],
            'directorEbr' => $directorEbr,
        ]);
    }

    // ── Privados ─────────────────────────────────────────────────

    private function getAnioActivo(): ?array
    {
        return $this->calModel->queryOne("
            SELECT id, anio FROM anios_academicos WHERE estado = 'activo' LIMIT 1
        ");
    }

    private function getPeriodoActivo(): ?array
    {
        return $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado = 'activo'
            LIMIT 1
        ");
    }

    private function getNivelesDocente(int $docenteId): array
    {
        return $this->calModel->query("
            SELECT DISTINCT n.id, n.nombre, n.codigo
            FROM cargas_academicas ca
            INNER JOIN secciones s ON s.id = ca.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN niveles n   ON n.id = g.nivel_id
            WHERE ca.docente_id = ? AND ca.estado = 'activa'
            ORDER BY n.id
        ", [$docenteId]);
    }

    /**
     * Resumen de cada carga: total/bloqueadas/con-criterios. El total y las
     * bloqueadas incluyen las competencias transversales TIC/GAMA del nivel
     * (cada docente las registra en su carga): una carga queda pendiente hasta
     * bloquear sus oficiales Y sus transversales. `con_criterios` se mide solo
     * sobre las oficiales (gobierna el aviso "Sin criterios").
     */
    private function getCargasResumen(int $docenteId, int $periodoId): array
    {
        return $this->calModel->query("
            SELECT ca.id, ca.horas_semanales,
                   s.nombre          AS seccion_nombre,
                   s.es_unidocente,
                   -- Mi aula = la seccion es unidocente Y este docente es su tutor.
                   -- Un especialista (Ingles, Ed. Fisica) que dicta en una seccion
                   -- unidocente NO es aula; es_unidocente por si solo no alcanza.
                   (s.es_unidocente = 1 AND s.tutor_id = ca.docente_id) AS es_aula,
                   g.nombre_display  AS grado_nombre,
                   n.nombre          AS nivel_nombre,
                   a.id              AS area_id,
                   CASE WHEN s.es_unidocente = 1 THEN a.nombre
                        ELSE COALESCE(sa.nombre, a.nombre) END AS nombre_display,
                   (
                       (
                           SELECT COUNT(DISTINCT c2.id) FROM competencias c2
                           WHERE (ca.subarea_id IS NOT NULL AND c2.subarea_id = ca.subarea_id)
                              OR (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL AND c2.area_id = ca.area_id)
                       ) + (
                           -- Transversales TIC/GAMA: se cuentan UNA vez por area, en la
                           -- carga dueña (subarea de menor orden). UNIDOCENTE: sin la
                           -- logica de dueña se sumaban a las N cargas del aula mientras
                           -- los bloqueos solo viven en la dueña, dejando el avance
                           -- atascado (ej. 31/45 = 69% aunque todo este aprobado). Misma
                           -- regla que getCargas y TransversalModel::estadoCargasSeccion.
                           -- Polidocente (es_unidocente = 0): cada carga cuenta las suyas
                           -- (comportamiento intacto). Tutoria (Etica y Valores): la
                           -- carga del tutor NO lleva transversales -> 0 (07/07/2026).
                           CASE WHEN a.tipo = 'tutoria' THEN 0
                                WHEN s.es_unidocente = 1
                                     AND ca.id <> (
                                         SELECT cad.id FROM cargas_academicas cad
                                         LEFT JOIN subareas sad ON sad.id = cad.subarea_id
                                         WHERE cad.seccion_id = ca.seccion_id
                                           AND cad.estado     = 'activa'
                                           AND COALESCE(cad.area_id, sad.area_id) = COALESCE(ca.area_id, sa.area_id)
                                         ORDER BY COALESCE(sad.orden, 0), cad.id LIMIT 1
                                     )
                                THEN 0
                                ELSE (
                                    SELECT COUNT(*) FROM competencias ct
                                    INNER JOIN areas at2 ON at2.id = ct.area_id
                                    WHERE at2.tipo = 'transversal' AND at2.nivel_id = n.id
                                )
                           END
                       )
                   ) AS total_comp,
                   (
                       -- Académicas bloqueadas de la carga (filtradas a SU universo
                       -- de competencias propias) + transversales bloqueadas con la
                       -- MISMA lógica de dueña que total_comp. Antes se contaban TODOS
                       -- los bloqueos de la carga; tras un cierre que bloquea TIC/GAMA
                       -- en cada subárea, las no-dueña sumaban transversales que el
                       -- denominador (dueña) no cuenta -> el avance superaba 100%.
                       (
                           SELECT COUNT(*) FROM bloqueos_competencia bc
                           WHERE bc.carga_id = ca.id AND bc.periodo_id = ?
                             AND bc.competencia_id IN (
                                 SELECT cb.id FROM competencias cb
                                 WHERE (ca.subarea_id IS NOT NULL AND cb.subarea_id = ca.subarea_id)
                                    OR (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL AND cb.area_id = ca.area_id)
                             )
                       ) + (
                           CASE WHEN a.tipo = 'tutoria' THEN 0
                                WHEN s.es_unidocente = 1
                                     AND ca.id <> (
                                         SELECT cad.id FROM cargas_academicas cad
                                         LEFT JOIN subareas sad ON sad.id = cad.subarea_id
                                         WHERE cad.seccion_id = ca.seccion_id
                                           AND cad.estado     = 'activa'
                                           AND COALESCE(cad.area_id, sad.area_id) = COALESCE(ca.area_id, sa.area_id)
                                         ORDER BY COALESCE(sad.orden, 0), cad.id LIMIT 1
                                     )
                                THEN 0
                                ELSE (
                                    SELECT COUNT(*) FROM bloqueos_competencia bct
                                    INNER JOIN competencias compt ON compt.id = bct.competencia_id
                                    INNER JOIN areas at2 ON at2.id = compt.area_id AND at2.tipo = 'transversal'
                                    WHERE bct.carga_id = ca.id AND bct.periodo_id = ? AND at2.nivel_id = n.id
                                )
                           END
                       )
                   ) AS bloq,
                   (
                       SELECT COUNT(DISTINCT cr.competencia_id) FROM criterios cr
                       WHERE cr.carga_id = ca.id AND cr.periodo_id = ? AND cr.eliminado_en IS NULL
                         AND cr.competencia_id IN (
                             SELECT c4.id FROM competencias c4
                             WHERE (ca.subarea_id IS NOT NULL AND c4.subarea_id = ca.subarea_id)
                                OR (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL AND c4.area_id = ca.area_id)
                         )
                   ) AS con_criterios
            FROM cargas_academicas ca
            INNER JOIN secciones s ON s.id = ca.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN niveles n   ON n.id = g.nivel_id
            LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
            LEFT  JOIN areas a     ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE ca.docente_id = ? AND ca.estado = 'activa'
              -- Excluye la carga transversal independiente (modelo viejo): las
              -- TIC/GAMA se registran dentro de cada carga; el tutor cierra en
              -- /docente/tutoria. Sin esto, el conteo de la card del dashboard
              -- sumaba una carga fantasma al tutor.
              AND (a.tipo IS NULL OR a.tipo != 'transversal')
              -- Tutoría (TOE): no cuenta como responsabilidad mientras su area no
              -- tenga competencias (sin calificaciones). Ver getCargas. NULL-safe.
              AND (a.tipo IS NULL OR a.tipo != 'tutoria'
                   OR EXISTS (SELECT 1 FROM competencias ctu WHERE ctu.area_id = a.id))
            ORDER BY n.id, g.numero, s.nombre, a.orden, sa.orden
        ", [$periodoId, $periodoId, $periodoId, $docenteId]);
    }

    /** Conteo de matriculados aprobados por sección de los niveles dados. */
    private function getNominaResumen(array $niveles): array
    {
        $ids = array_map('intval', array_column($niveles, 'id'));
        if (empty($ids)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->calModel->query("
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
              -- que getMatriculados, para que la card y el detalle cuadren.
              AND m.id NOT IN (
                  SELECT matricula_operativa_id
                  FROM retornos_grado
                  WHERE estado = 'activo'
              )
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
     *                          el 10/08/2026, el BUSCADOR en vivo.
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
     * evaluación—, pero es el precio de filtrar por estado en esta pantalla.
     */
    private function getMatriculados(array $niveles, int $seccionId = 0, bool $soloAprobadas = true): array
    {
        $ids = array_map('intval', array_column($niveles, 'id'));
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

        return $this->calModel->query("
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
              -- oculta la operativa interna (grado inferior). Espejo de la regla
              -- de OrdenMeritoModel, que excluye la oficial para el ranking operativo.
              AND m.id NOT IN (
                  SELECT matricula_operativa_id
                  FROM retornos_grado
                  WHERE estado = 'activo'
              )
              AND n.id IN ($ph)$filtroSeccion
            ORDER BY n.id, g.numero, s.nombre,
                     " . orden_alfabetico('p') . "
        ", $params);
    }

    /**
     * Sesiones de horario del docente. Delega en `HorarioModel` — PUNTO ÚNICO
     * desde el 24/08/2026. La consulta vivía aquí, privada dentro del
     * controlador, y por eso el horario POR SECCIÓN no podía reutilizarla.
     */
    private function getHorario(int $docenteId): array
    {
        return $this->horarioModel->getSesionesDocente($docenteId);
    }
}
