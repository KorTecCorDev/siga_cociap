<?php

namespace App\Controllers\Boleta;

use App\Controllers\BaseController;
use App\Models\BoletaModel;
use App\Models\BoletaPublicaModel;
use App\Models\CalificacionModel;
use App\Models\PublicacionBoletaModel;
use Core\Session;
use Core\View;

/**
 * BoletaController
 *
 * Entry points DELGADOS de la boleta. Cada metodo solo decide QUIEN entra y
 * QUE periodo se muestra; el ensamblado del documento vive en BoletaModel y
 * el QR sale SIEMPRE del token permanente (urlBoletaToken).
 *
 * Direccionamiento:
 *  - Publico (familias): SIEMPRE por token, solo bimestres oficiales (cerrados).
 *  - Interno (docente/admin): autenticado por id+alcance, puede ver BORRADOR.
 *
 * No existen rutas anonimas por id (se retiraron `ver`/`verDigital`).
 */
class BoletaController extends BaseController
{
    private CalificacionModel      $calModel;
    private BoletaModel            $boletaModel;
    private BoletaPublicaModel     $boletaPublicaModel;
    private PublicacionBoletaModel $publicacionModel;

    public function __construct()
    {
        $this->calModel           = new CalificacionModel();
        $this->boletaModel        = new BoletaModel();
        $this->boletaPublicaModel = new BoletaPublicaModel();
        $this->publicacionModel   = new PublicacionBoletaModel();
    }

    /**
     * URL del QR PERMANENTE de un estudiante: el enlace publico por token, que
     * se mantiene durante todo el anio academico (un solo QR por estudiante). Se
     * rotula con la matricula IDENTIDAD (oficial) para que coincida con el QR
     * que ven los padres, incluso en retorno de grado.
     */
    private function urlBoletaToken(int $matriculaId): string
    {
        $identidad = (int) $this->calModel->boletaContexto($matriculaId)['identidad'];
        $token     = $this->boletaPublicaModel->getOCrearToken($identidad);

        return url("boleta/digital/{$token}");
    }

    /**
     * GET /boleta/ver/{token}
     * Vista imprimible publica sin login — resuelve token -> matricula + periodo.
     */
    public function verToken(string $token): void
    {
        ['matricula_id' => $matriculaId, 'periodo_id' => $periodoId] = $this->resolveToken($token);
        $this->render($matriculaId, $periodoId, 'print', [
            'datos'           => 'oficial',
            // Formato oficial: SIEMPRE las cuatro columnas de bimestre, aunque
            // esten vacias (regla de formato 09/07/2026, la misma que ya aplica
            // la boleta del trasladado). Los DATOS los sigue filtrando el guard
            // de armar(): solo entran los bimestres oficiales y publicados.
            // Con la estructura anual fija no hay filtracion de la compuerta: las
            // columnas estan todo el año, asi que una vacia no revela si el
            // bimestre cerro. Colapsarlas era justo lo que lo delataba.
            'estructuraCompleta' => true,
            'registrarVisita' => true,
        ]);
    }

    /**
     * GET /boleta/digital/{token}
     * Vista digital publica sin login — resuelve token -> matricula + periodo.
     * Destino del QR: aqui se registra cada visita por token.
     */
    public function verDigitalToken(string $token): void
    {
        ['matricula_id' => $matriculaId, 'periodo_id' => $periodoId] = $this->resolveToken($token);
        $this->render($matriculaId, $periodoId, 'digital', [
            'datos'           => 'oficial',
            // Misma REGLA DE FORMATO que la imprimible por token: estructura anual
            // completa. La vista ya pinta los bimestres sin nota como chip vacio
            // ('--vacio' / '--empty'), y con las 4 columnas fijas una vacia no
            // revela si el bimestre ya cerro. Los DATOS los sigue filtrando el
            // guard de armar() (solo cerrados y publicados).
            'estructuraCompleta' => true,
            'registrarVisita' => true,
        ]);
    }

    /**
     * GET /docente/boleta/{matricula_id}
     * Boleta DIGITAL para el docente. Validada por alcance: solo alumnos de un
     * nivel donde el docente tiene carga activa. El periodo se resuelve solo.
     */
    public function verDigitalDocente($matriculaId): void
    {
        $this->requireRole(['docente', 'admin']);
        $matriculaId = (int) $matriculaId;
        $res         = $this->resolverBoletaDocente($matriculaId);
        $periodoId   = $res['periodo_id'];

        $this->render($matriculaId, $periodoId, 'digital', [
            'datos'              => 'borrador',
            // Explicito aunque 'borrador' hoy no colapse columnas: la estructura
            // anual es del DOCUMENTO, no del umbral de datos.
            'estructuraCompleta' => true,
            'vistaPrevia' => $res['estado_matricula'] === 'desactivado'
                          || $this->estadoBoletaDePeriodo($periodoId) !== 'oficial',
        ]);
    }

    /**
     * GET /docente/boleta/{matricula_id}/imprimir
     * Boleta IMPRIMIBLE (A4) para el docente. Mismo alcance que verDigitalDocente.
     */
    public function verImprimirDocente($matriculaId): void
    {
        $this->requireRole(['docente', 'admin']);
        $matriculaId = (int) $matriculaId;
        $res         = $this->resolverBoletaDocente($matriculaId);
        $periodoId   = $res['periodo_id'];

        $this->render($matriculaId, $periodoId, 'print', [
            'datos'              => 'borrador',
            'estructuraCompleta' => true,   // ver verDigitalDocente
            'vistaPrevia' => $res['estado_matricula'] === 'desactivado'
                          || $this->estadoBoletaDePeriodo($periodoId) !== 'oficial',
        ]);
    }

    /**
     * Render UNICO de la boleta. Arma los datos via BoletaModel, fija el QR
     * SIEMPRE desde el token permanente y elige layout/vista.
     *
     * @param array $opts ['datos'=>'oficial'|'borrador'|'todos' (umbral Hito A; default
     *                     'oficial'), 'vistaPrevia'=>bool, 'registrarVisita'=>bool,
     *                     'sinQr'=>bool (trasladados: token muerto, el QR se omite),
     *                     'estructuraCompleta'=>bool (todas las columnas del anio
     *                     aunque 'datos' filtre las notas)]
     */
    private function render(int $matriculaId, int $periodoId, string $layout, array $opts = []): void
    {
        $data = $this->boletaModel->armar(
            $matriculaId,
            $periodoId,
            $opts['datos'] ?? 'oficial',
            $opts['estructuraCompleta'] ?? false
        );

        if (!$data) {
            http_response_code(404);
            require VIEW_PATH . '/shared/404.php';
            exit;
        }

        // La boleta se rotula con la matrícula IDENTIDAD (oficial). El conteo y el
        // QR se anclan a ella para que coincidan aun en retorno de grado.
        $identidad = (int) $data['alumno']['matricula_id'];

        // Cuenta toda consulta que pase por el token (escaneo de QR o portal).
        if ($opts['registrarVisita'] ?? false) {
            $this->boletaPublicaModel->registrarVisitaToken($identidad);
        }

        $vista  = $layout === 'print' ? 'boleta/alumno' : 'boleta/digital';
        $rotulo = $layout === 'print' ? 'Boleta — ' : 'Boleta Digital — ';

        View::setLayout($layout);
        $this->view($vista, array_merge($data, [
            'titulo'      => $rotulo . $data['alumno']['nombre_completo'],
            // sinQr (trasladados): url_boleta vacia -> las vistas omiten el QR
            // (su token esta muerto; un QR impreso dirigiria a "no encontrado").
            'url_boleta'  => ($opts['sinQr'] ?? false) ? '' : $this->urlBoletaToken($identidad),
            'vistaPrevia' => $opts['vistaPrevia'] ?? false,
        ]));
    }

    /**
     * Valida que el docente actual pueda ver la boleta de $matriculaId (alumno en
     * un nivel donde tiene carga activa) y devuelve el periodo a mostrar (el más
     * reciente con notas bloqueadas) junto con el estado de la matrícula, para que
     * el entry point fuerce la vista previa si está desactivada.
     * Responde 403 si está fuera de alcance y el aviso de sinCalificaciones()
     * si no hay periodo publicable con notas.
     *
     * @return array{periodo_id: int, estado_matricula: string}
     */
    private function resolverBoletaDocente(int $matriculaId): array
    {
        $docenteId = (int) Session::user()['id'];

        // Alcance: la matrícula existe, no es un traslado de salida (fuera de la
        // grilla del docente) y su NIVEL coincide con un nivel donde el docente
        // tiene carga activa. Evita abrir boletas fuera de alcance manipulando el
        // id en la URL. Las desactivadas SÍ pasan (siguen en la grilla): su boleta
        // se sirve siempre como BORRADOR.
        $mat = $this->calModel->queryOne("
            SELECT m.id, m.anio_id, m.estado
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            WHERE m.id = ?
              AND m.tipo <> 'trasladado'
              AND g.nivel_id IN (
                  SELECT DISTINCT g2.nivel_id
                  FROM cargas_academicas ca
                  INNER JOIN secciones s2 ON s2.id = ca.seccion_id
                  INNER JOIN grados g2    ON g2.id = s2.grado_id
                  WHERE ca.docente_id = ? AND ca.estado = 'activa'
              )
            LIMIT 1
        ", [$matriculaId, $docenteId]);

        if (!$mat) {
            $this->forbidden();
        }

        $periodoId = $this->boletaModel->periodoPublicableConNotas((int) $mat['anio_id'], $matriculaId);

        if ($periodoId === null) {
            $this->sinCalificaciones();
        }

        return ['periodo_id' => $periodoId, 'estado_matricula' => $mat['estado']];
    }

    /**
     * GET /matriculas/{matricula_id}/boleta
     * Boleta DIGITAL interna para la gestion de matriculas (admin, registro y
     * secretarias). Mismo flujo que la del docente: muestra el BORRADOR mientras
     * el bimestre no cierra. Distinta de la publica por token (/boleta/digital/
     * {token}), que sigue mostrando SOLO lo oficial. Autorizada por rol; no
     * restringe por nivel (estos roles gestionan cualquier matricula).
     */
    public function verDigitalMatricula($matriculaId): void
    {
        $this->requireRole(['admin', 'registro_academico', 'secretaria_academica', 'secretaria_administrativa', ...ROLES_DIRECCION]);
        $matriculaId = (int) $matriculaId;
        $res         = $this->resolverBoletaGestion($matriculaId);

        $this->render($matriculaId, $res['periodo_id'], 'digital', $this->optsBoletaGestion($res));
    }

    /**
     * GET /matriculas/{matricula_id}/boleta/imprimir
     * Version IMPRIMIBLE (A4) de la boleta interna de gestion. Mismo alcance y
     * flujo que verDigitalMatricula.
     */
    public function verImprimirMatricula($matriculaId): void
    {
        $this->requireRole(['admin', 'registro_academico', 'secretaria_academica', 'secretaria_administrativa', ...ROLES_DIRECCION]);
        $matriculaId = (int) $matriculaId;
        $res         = $this->resolverBoletaGestion($matriculaId);

        $this->render($matriculaId, $res['periodo_id'], 'print', $this->optsBoletaGestion($res));
    }

    /**
     * Opciones de render de la boleta interna de gestion segun la matricula:
     * - TRASLADADO consumado (desactivado + tipo trasladado): su ULTIMA boleta
     *   OFICIAL — estructura anual completa con DATOS solo de bimestres
     *   cerrados (regla de formato 09/07/2026), sin banner, CON firma, SIN QR
     *   (el token esta muerto: un QR impreso dirigiria a "no encontrado").
     * - Desactivado por otra causa (deuda/baja): BORRADOR forzado siempre.
     * - Resto: vista previa segun el estado del periodo (regla normal).
     */
    private function optsBoletaGestion(array $res): array
    {
        if ($res['estado_matricula'] === 'desactivado' && $res['tipo'] === 'trasladado') {
            return [
                // 'archivo': mismo corte de datos que 'oficial' (solo cerrados)
                // pero IGNORA la compuerta de publicacion — es un documento
                // administrativo de STAFF y el alumno ya no tiene vinculo con la
                // institucion, asi que no depende del calendario de entrega.
                'datos'              => 'archivo',
                'estructuraCompleta' => true,
                'vistaPrevia'        => false,
                'sinQr'              => true,
            ];
        }

        return [
            'datos'              => 'borrador',
            'estructuraCompleta' => true,   // ver verDigitalDocente
            'vistaPrevia' => $res['estado_matricula'] === 'desactivado'
                          || $this->estadoBoletaDePeriodo($res['periodo_id']) !== 'oficial',
        ];
    }

    /**
     * Resuelve el periodo a mostrar para la boleta interna de gestion. A
     * diferencia de resolverBoletaDocente NO valida alcance por nivel, porque
     * los roles de gestion de matricula pueden abrir cualquier matricula,
     * incluidas las desactivadas (traslado/baja).
     * - TRASLADADO consumado: ancla al ultimo bimestre CERRADO con notas (su
     *   boleta es exclusivamente OFICIAL; sin cerrados no hay boleta -> aviso,
     *   su documento de salida es la constancia de traslado).
     * - Resto: ultimo periodo publicable (cerrado u activo con Hito A).
     * 404 si la matricula no existe; aviso de sinCalificaciones() si no hay
     * periodo elegible.
     *
     * @return array{periodo_id: int, estado_matricula: string, tipo: string}
     */
    private function resolverBoletaGestion(int $matriculaId): array
    {
        $mat = $this->calModel->queryOne(
            "SELECT id, anio_id, estado, tipo FROM matriculas WHERE id = ? LIMIT 1",
            [$matriculaId]
        );

        $esTrasladado = $mat
            && $mat['estado'] === 'desactivado'
            && $mat['tipo']   === 'trasladado';

        // Matrícula inexistente: 404 de verdad. Existe pero sin notas: aviso.
        if (!$mat) {
            $this->notFound();
        }

        $periodoId = $this->boletaModel->periodoPublicableConNotas(
            (int) $mat['anio_id'], $matriculaId, $esTrasladado
        );

        if ($periodoId === null) {
            $this->sinCalificaciones();
        }

        return [
            'periodo_id'       => $periodoId,
            'estado_matricula' => $mat['estado'],
            'tipo'             => $mat['tipo'],
        ];
    }

    /**
     * Aviso "aún no tiene calificaciones oficiales" en lugar del 404 (21/09/2026).
     * La matrícula EXISTE y el usuario puede verla: un 404 decía "no existe", que
     * era falso. Página suelta con `require` (como `notFound()`), HTTP 200 y el
     * botón Cerrar de los documentos: estas rutas se abren en pestaña nueva.
     * La regla de "tiene boleta" es `BoletaModel::periodoPublicableConNotas`.
     */
    private function sinCalificaciones(): never
    {
        require VIEW_PATH . '/boleta/sin-calificaciones.php';
        exit;
    }

    /** Estado de boleta ('registro'|'borrador'|'oficial') de un periodo. */
    private function estadoBoletaDePeriodo(int $periodoId): string
    {
        $p = $this->calModel->queryOne(
            "SELECT estado, boletas_aprobadas_en FROM periodos WHERE id = ? LIMIT 1",
            [$periodoId]
        );
        return boleta_estado_bimestre($p['estado'] ?? null, $p['boletas_aprobadas_en'] ?? null);
    }

    /**
     * Resuelve un token a matricula_id + periodo_id.
     * Elige el período más reciente con competencias bloqueadas;
     * si no hay ninguno, usa el primer período del año.
     * Termina con 404 si el token no existe o no hay períodos.
     */
    private function resolveToken(string $token): array
    {
        // El token es bin2hex(random_bytes(16)) = 32 hex en minúsculas. Validar
        // el formato antes de consultar rechaza basura/payloads de inmediato,
        // con el mismo 404 que un token inexistente. No afecta los QR ya
        // emitidos: todos los tokens vigentes cumplen este patrón.
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            http_response_code(404);
            require VIEW_PATH . '/shared/404.php';
            exit;
        }

        // Una matrícula 'desactivado' (baja o traslado) no expone su boleta por
        // token aunque el QR impreso siga circulando: se trata como inexistente.
        // El NIVEL se necesita para la compuerta de publicación (es por nivel).
        //
        // RETORNO DE GRADO (05/08/2026): la matrícula OPERATIVA tampoco expone
        // boleta. El documento se emite SIEMPRE con la OFICIAL, así que cada
        // estudiante tiene UNA sola URL pública; si la operativa respondiera,
        // habría dos direcciones distintas sirviendo el mismo documento. Se trata
        // como inexistente (404), sin revelar que el token existe.
        // No rompe nada emitido: el QR se ancla a la matrícula IDENTIDAD, que en
        // un retorno es siempre la oficial (ver renderBoleta), y ninguna boleta
        // pública se llegó a generar con el token de una operativa.
        // La exclusión sale de `matricula_documento()` (PUNTO ÚNICO del criterio
        // DOCUMENTO, `helpers.php`), la misma línea que usan el lote de boletas y
        // las estadísticas de /matriculas/resumen. Antes estaba escrita a mano aquí.
        $matricula = $this->calModel->queryOne(
            "SELECT m.id, m.anio_id, n.id AS nivel_id
             FROM matriculas m
             INNER JOIN secciones s ON s.id = m.seccion_id
             INNER JOIN grados    g ON g.id = s.grado_id
             INNER JOIN niveles   n ON n.id = g.nivel_id
             WHERE m.token_acceso = ?
               AND m.estado <> 'desactivado'
               " . matricula_documento('m') . "
             LIMIT 1",
            [$token]
        );

        if (!$matricula) {
            http_response_code(404);
            require VIEW_PATH . '/shared/404.php';
            exit;
        }

        $matriculaId = (int) $matricula['id'];
        $anioId      = (int) $matricula['anio_id'];

        // COMPUERTA DE PUBLICACION (044): el token ancla al ultimo bimestre
        // PUBLICADO al nivel del alumno, no al ultimo cerrado. Cerrar B2 no cambia
        // lo que ve la familia: sigue viendo su boleta de B1 hasta que RA publique.
        $publicados = $this->publicacionModel->periodosPublicados(
            $anioId,
            (int) $matricula['nivel_id']
        );

        $candidatos = $this->calModel->query("
            SELECT p.id
            FROM periodos p
            WHERE p.anio_id = ?
              AND EXISTS (
                  SELECT 1
                  FROM calificaciones cal
                  INNER JOIN bloqueos_competencia bc
                      ON bc.carga_id       = cal.carga_id
                     AND bc.competencia_id = cal.competencia_id
                     AND bc.periodo_id     = cal.periodo_id
                  WHERE cal.matricula_id = ? AND cal.periodo_id = p.id
              )
            ORDER BY p.numero DESC
        ", [$anioId, $matriculaId]);

        $periodoId = null;
        foreach ($candidatos as $c) {
            if (isset($publicados[(int) $c['id']])) {
                $periodoId = (int) $c['id'];
                break;
            }
        }

        // Sin bimestre publicado con notas, el ancla cae al primer periodo del
        // anio: la boleta se arma vacia, exactamente como antes del primer cierre
        // (comportamiento historico, no una pantalla nueva).
        if ($periodoId === null) {
            $primero = $this->calModel->queryOne(
                "SELECT id FROM periodos WHERE anio_id = ? ORDER BY numero ASC LIMIT 1",
                [$anioId]
            );
            $periodoId = $primero ? (int) $primero['id'] : null;
        }

        if ($periodoId === null) {
            http_response_code(404);
            require VIEW_PATH . '/shared/404.php';
            exit;
        }

        return ['matricula_id' => $matriculaId, 'periodo_id' => $periodoId];
    }
}
