<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SiagieExportModel;
use App\Siagie\LlenadorSiagie;
use Core\Session;

/**
 * ActasSiagieController
 *
 * Módulo web "Actas SIAGIE" (Registro académico / admin): vuelca las notas
 * oficiales de SIGA a la plantilla RegNotas que el SIAGIE exporta por
 * sección+bimestre, para no re-digitarlas a mano ante UGEL-MINEDU.
 *
 * Flujo EFÍMERO en dos pasos (una sección por vez):
 *   1. previsualizar → sube el .xlsx, lo analiza SIN escribir nada, muestra el
 *      reporte (matching, celdas a escribir, advertencias, blancos) y permite
 *      RESOLVER la identidad de filas dudosas eligiendo al alumno del roster.
 *   2. confirmar → re-analiza con las resoluciones, escribe el archivo, lo
 *      verifica celda por celda, persiste los códigos SIAGIE y ofrece la
 *      descarga del acta llenada + su reporte de auditoría.
 *
 * El .xlsx subido vive en un temporal fuera del repo (config siagie_tmp_path)
 * solo entre ambos pasos y se borra al confirmar. Toda la lógica de volcado la
 * aporta App\Siagie\LlenadorSiagie (compartida con el CLI). Reglas completas:
 * docs/modulos/export-siagie.md
 */
class ActasSiagieController extends BaseController
{
    /** Tamaño máximo del .xlsx subido (los RegNotas del SIAGIE pesan < 1 MB). */
    private const MAX_BYTES = 6 * 1024 * 1024;

    /** Antigüedad (segundos) tras la cual un temporal se considera basura. */
    private const TTL_TEMP = 1800;

    private LlenadorSiagie $llenador;

    public function __construct()
    {
        $this->requireRole(['admin', 'registro_academico']);
        $this->llenador = new LlenadorSiagie();
    }

    /** GET /admin/actas-siagie — formulario de subida. */
    public function index(): void
    {
        $this->limpiarTemporalesViejos();
        $this->view('admin/actas_siagie/index', [
            'titulo' => 'Actas SIAGIE',
        ]);
    }

    /** POST /admin/actas-siagie/previsualizar — analiza sin escribir. */
    public function previsualizar(): void
    {
        $this->validateCsrf();
        $volver = url('admin/actas-siagie');

        $archivo = $_FILES['acta'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->redirectWithError($volver, 'Selecciona un archivo .xlsx válido del SIAGIE.');
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            $this->redirectWithError($volver, 'La subida no es válida. Intenta de nuevo.');
        }
        if (($archivo['size'] ?? 0) <= 0 || $archivo['size'] > self::MAX_BYTES) {
            $this->redirectWithError($volver, 'El archivo está vacío o supera el tamaño permitido (6 MB).');
        }
        if (strtolower((string) pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->redirectWithError($volver, 'El archivo debe tener extensión .xlsx (el que descargas del SIAGIE).');
        }
        // Firma ZIP: todo .xlsx es un ZIP (empieza con "PK").
        if (@file_get_contents($archivo['tmp_name'], false, null, 0, 2) !== 'PK') {
            $this->redirectWithError($volver, 'El archivo no parece un .xlsx real. Vuelve a exportarlo del SIAGIE.');
        }

        // Mover a un temporal propio (token) fuera del repo.
        $this->purgarJobPrevio();
        $token   = bin2hex(random_bytes(16));
        $rutaTmp = $this->dirTmp() . '/' . $token . '.xlsx';
        if (!move_uploaded_file($archivo['tmp_name'], $rutaTmp)) {
            $this->redirectWithError($volver, 'No se pudo procesar el archivo subido. Intenta de nuevo.');
        }

        try {
            $r = $this->llenador->analizar($rutaTmp);
        } catch (\Throwable $e) {
            @unlink($rutaTmp);
            $this->redirectWithError($volver, $e->getMessage());
        }

        $nombre = $this->nombreDescarga($archivo['name']);
        Session::set('siagie_job', [
            'token'      => $token,
            'ruta'       => $rutaTmp,
            'nombre'     => $nombre,
            'reporte'    => $r['reporte'],
            // Whitelist de resolución = sección ∪ otras secciones del grado
            // (permite resolver un cambio de sección sin tramitar por DNI).
            'roster_ids' => $r['roster_valido_ids'],
            'creado'     => time(),
        ]);

        $this->view('admin/actas_siagie/preview', [
            'titulo' => 'Actas SIAGIE — Previsualización',
            'token'  => $token,
            'nombre' => $nombre,
            'r'      => $r,
        ]);
    }

    /** GET /admin/actas-siagie/reporte — descarga el reporte del preview (.txt). */
    public function reportePreview(): void
    {
        $job = Session::get('siagie_job');
        if (!is_array($job) || empty($job['reporte'])) {
            $this->redirectWithError(url('admin/actas-siagie'), 'No hay una previsualización activa.');
        }
        $this->descargarTexto(
            'reporte_' . pathinfo($job['nombre'], PATHINFO_FILENAME) . '.txt',
            implode("\n", $job['reporte']) . "\n"
        );
    }

    /** POST /admin/actas-siagie/confirmar — escribe, verifica y prepara la descarga. */
    public function confirmar(): void
    {
        $this->validateCsrf();
        $volver = url('admin/actas-siagie');

        $job   = Session::get('siagie_job');
        $token = (string) $this->input('token', '');
        if (!is_array($job) || !hash_equals((string) $job['token'], $token) || !is_file($job['ruta'])) {
            $this->redirectWithError($volver, 'La previsualización expiró o no coincide. Vuelve a subir el archivo.');
        }

        // Resoluciones manuales del POST, validadas contra el roster de la sección.
        $whitelist = array_flip($job['roster_ids']);
        $resoluciones = [];
        foreach ((array) $this->input('resolucion', []) as $fila => $eid) {
            $fila = (int) $fila;
            $eid  = (int) $eid;
            if ($fila <= 0 || $eid <= 0) {
                continue; // "dejar en blanco"
            }
            if (!isset($whitelist[$eid])) {
                continue; // fuera del roster → se ignora (defensa en profundidad)
            }
            $resoluciones[$fila] = $eid;
        }

        try {
            $r = $this->llenador->analizar($job['ruta'], $resoluciones);
            if ($r['escrituras'] === []) {
                $this->purgarJobPrevio();
                $this->redirectWithError($volver, 'No hay nada que escribir en el archivo (revisa el reporte de previsualización).');
            }
            $tmpGenerado = $this->llenador->escribirVerificado($r['xlsx'], $r['escrituras']);
            $persistidos = $this->llenador->persistirCodigos($r['codigos']);
        } catch (\Throwable $e) {
            log_error('Actas SIAGIE: fallo al confirmar', ['error' => $e->getMessage()]);
            $this->redirectWithError($volver, 'No se pudo generar el acta: ' . $e->getMessage());
        }

        // El original subido ya no se necesita; el generado queda para descargar.
        @unlink($job['ruta']);
        Session::forget('siagie_job');
        Session::set('siagie_resultado', [
            'token'       => bin2hex(random_bytes(16)),
            'ruta'        => $tmpGenerado,
            'nombre'      => $job['nombre'],
            'reporte'     => $r['reporte'],
            'resumen'     => $r['resumen'],
            'persistidos' => $persistidos,
            'creado'      => time(),
        ]);
        redirect('/admin/actas-siagie/resultado');
    }

    /**
     * GET /admin/actas-siagie/vinculos — DIAGNÓSTICO de cobertura (solo lectura).
     *
     * Responde una pregunta que hasta ahora nadie podía hacerse: **¿qué notas de
     * SIGA no están llegando al acta oficial?** Un área sin `codigo_siagie` se
     * omite EN SILENCIO — así se perdieron los talleres del I Bimestre (322
     * alumnos con nota, 0 reportados). Muestra, por bimestre:
     *   - áreas con notas y SIN destino (lo que se está perdiendo),
     *   - los vínculos hoja→área vigentes,
     *   - las excepciones de hoja (Ética→EREL, GAMA→EPT 5°), que si no viven
     *     solo dentro del código,
     *   - colisiones de código, que romperían el mapeo sin avisar.
     * No escribe nada: el vínculo se edita en Currículo (campo del área).
     */
    public function vinculos(): void
    {
        $modelo    = new SiagieExportModel();
        $periodos  = $modelo->periodosConCalificaciones();
        $periodoId = (int) ($this->query('periodo') ?? 0);

        $ids = array_map('intval', array_column($periodos, 'id'));
        if (!in_array($periodoId, $ids, true)) {
            $periodoId = $ids ? end($ids) : 0;
        }

        $vinculos = $periodoId ? $modelo->vinculosDeAreas($periodoId) : [];

        // Excepciones de TODOS los niveles del currículo (no solo los que tienen
        // notas): un vínculo existe aunque el bimestre aún no tenga nada.
        $excepciones = [];
        foreach ($this->nivelesDeCobertura($vinculos) as $nivel) {
            foreach ($this->llenador->excepcionesDeclaradas($nivel['codigo'], $nivel['id']) as $exc) {
                $exc['nivel_nombre'] = $nivel['nombre'];
                $exc['nivel_id']     = $nivel['id'];
                $excepciones[] = $exc;
            }
        }

        // Índices para clasificar cada área:
        //  - recibe: área que se lleva una hoja por excepción (aunque no tenga código),
        //  - hojaTomada: código de hoja → área que la ocupa por excepción, para
        //    detectar el área cuyo código quedó REEMPLAZADO (Ed. Religiosa ↔ 035).
        // OJO: `hojaTomada` se indexa por NIVEL + código. Las excepciones son por
        // nivel ('035' solo aplica en secundaria), y sin el nivel en la clave la
        // regla de secundaria marcaría como reemplazada a la Ed. Religiosa de
        // PRIMARIA, que se llena con normalidad.
        $recibePorExcepcion = [];
        $hojaTomada         = [];
        foreach ($excepciones as $exc) {
            if (!empty($exc['competencia']['area_id'])) {
                $areaId = (int) $exc['competencia']['area_id'];
                // Se ACUMULAN: GAMA llena dos hojas en 5° (032 y 0001, 02/10/2026).
                $recibePorExcepcion[$areaId] = isset($recibePorExcepcion[$areaId])
                    ? $recibePorExcepcion[$areaId] . ', ' . $exc['codigo_hoja']
                    : $exc['codigo_hoja'];
                $hojaTomada[$exc['nivel_id'] . '|' . $exc['codigo_hoja']] = [
                    'area_id'     => $areaId,
                    'area_nombre' => $exc['competencia']['area_nombre'] ?? '',
                    'grados'      => $exc['grados'],
                ];
            }
        }

        foreach ($vinculos as &$v) {
            $v['estado'] = $this->estadoVinculo($v, $recibePorExcepcion, $hojaTomada);
        }
        unset($v);

        $this->view('admin/actas_siagie/vinculos', [
            'titulo'      => 'Actas SIAGIE — Vínculos y cobertura',
            'periodos'    => $periodos,
            'periodoId'   => $periodoId,
            'vinculos'    => $vinculos,
            'excepciones' => $excepciones,
            'duplicados'  => $modelo->codigosSiagieDuplicados(),
        ]);
    }

    /**
     * Clasifica el vínculo de un área. El caso que importa —y que antes no se
     * veía— es `reemplazada`: el área tiene su código configurado, pero una
     * excepción entrega esa hoja a OTRA área, así que sus competencias no se
     * escriben. Sin esta distinción, la configuración miente.
     *
     * @return array{clave: string, etiqueta: string, tono: string, detalle: string, hoja: ?string}
     */
    private function estadoVinculo(array $v, array $recibePorExcepcion, array $hojaTomada): array
    {
        $areaId  = (int) $v['area_id'];
        $nivelId = (int) $v['nivel_id'];
        $codigo  = trim((string) $v['codigo_siagie']);
        $notas   = (int) $v['notas'];
        $recibe  = $recibePorExcepcion[$areaId] ?? null;

        // Hojas propias que una excepción entregó a OTRA área.
        $robadas = [];
        foreach ($codigo !== '' ? explode(',', $codigo) : [] as $cod) {
            $k = $nivelId . '|' . $cod;
            if (isset($hojaTomada[$k]) && $hojaTomada[$k]['area_id'] !== $areaId) {
                $robadas[$cod] = $hojaTomada[$k];
            }
        }

        if ($robadas !== []) {
            $cod   = array_key_first($robadas);
            $g     = $robadas[$cod]['grados'];
            $donde = $g === null ? 'en todos los grados' : 'en ' . implode('°, ', $g) . '°';
            return [
                'clave'    => 'reemplazada',
                'etiqueta' => $g === null ? 'reemplazada por excepción' : 'reemplazada ' . $donde,
                'tono'     => 'warning',
                'hoja'     => $codigo,
                'detalle'  => 'La hoja ' . $cod . ' la llena «' . $robadas[$cod]['area_nombre'] . '» ' . $donde
                            . '; las competencias de esta área no se escriben ahí.',
            ];
        }

        if ($codigo !== '') {
            // Puede tener hoja propia Y ADEMÁS recibir otra por excepción
            // (Transversales: sus 0006/0007 y, en 5°, la 032 de EPT).
            if ($recibe !== null) {
                return ['clave' => 'vinculada', 'etiqueta' => 'vinculada + excepción', 'tono' => 'activo',
                        'hoja' => $codigo . ' + ' . $recibe,
                        'detalle' => 'Además llena la hoja ' . $recibe . ', que no es su área.'];
            }
            return ['clave' => 'vinculada', 'etiqueta' => 'vinculada', 'tono' => 'activo',
                    'hoja' => $codigo, 'detalle' => ''];
        }

        if ($recibe !== null) {
            return ['clave' => 'excepcion', 'etiqueta' => 'recibe por excepción', 'tono' => 'info',
                    'hoja' => $recibe,
                    'detalle' => 'Llena la hoja ' . $recibe . ' aunque no sea su área.'];
        }

        if ($notas > 0) {
            return ['clave' => 'sin_destino', 'etiqueta' => 'sin destino', 'tono' => 'error',
                    'hoja' => null, 'detalle' => 'Tiene notas bloqueadas que NO llegan al acta.'];
        }

        return ['clave' => 'no_exporta', 'etiqueta' => 'no se exporta', 'tono' => 'sin-notas',
                'hoja' => null, 'detalle' => 'Sin código y sin notas en este bimestre.'];
    }

    /** Niveles presentes en la lista (id, codigo, nombre), sin repetir. */
    private function nivelesDeCobertura(array $filas): array
    {
        $niveles = [];
        foreach ($filas as $fila) {
            $niveles[(int) $fila['nivel_id']] = [
                'id'     => (int) $fila['nivel_id'],
                'codigo' => $fila['nivel_codigo'],
                'nombre' => $fila['nivel_nombre'],
            ];
        }
        return $niveles;
    }

    /** GET /admin/actas-siagie/resultado — confirmación con descargas. */
    public function resultado(): void
    {
        $res = Session::get('siagie_resultado');
        if (!is_array($res) || !is_file($res['ruta'])) {
            $this->redirectWithError(url('admin/actas-siagie'), 'No hay un acta generada para descargar (pudo expirar).');
        }
        $this->view('admin/actas_siagie/resultado', [
            'titulo' => 'Actas SIAGIE — Acta generada',
            'res'    => $res,
        ]);
    }

    /** GET /admin/actas-siagie/resultado/descargar — streamea el acta llenada. */
    public function descargar(): void
    {
        $res = Session::get('siagie_resultado');
        if (!is_array($res) || !is_file($res['ruta'])) {
            $this->redirectWithError(url('admin/actas-siagie'), 'El acta ya no está disponible. Vuelve a generarla.');
        }
        $this->descargarArchivo(
            $res['ruta'],
            $res['nombre'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /** GET /admin/actas-siagie/resultado/reporte — reporte final (.txt). */
    public function reporteFinal(): void
    {
        $res = Session::get('siagie_resultado');
        if (!is_array($res) || empty($res['reporte'])) {
            $this->redirectWithError(url('admin/actas-siagie'), 'No hay un reporte disponible.');
        }
        $this->descargarTexto(
            'reporte_' . pathinfo($res['nombre'], PATHINFO_FILENAME) . '.txt',
            implode("\n", $res['reporte']) . "\n"
        );
    }

    // ── internos ────────────────────────────────────────────────

    /** Directorio temporal (fuera del repo en prod); lo crea si falta. */
    private function dirTmp(): string
    {
        $dir = config('siagie_tmp_path');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /** Borra el temporal del job/resultado en sesión, si quedó alguno. */
    private function purgarJobPrevio(): void
    {
        foreach (['siagie_job', 'siagie_resultado'] as $clave) {
            $prev = Session::get($clave);
            if (is_array($prev) && !empty($prev['ruta']) && is_file($prev['ruta'])) {
                @unlink($prev['ruta']);
            }
            Session::forget($clave);
        }
    }

    /** Barre temporales huérfanos más viejos que el TTL. */
    private function limpiarTemporalesViejos(): void
    {
        $dir = config('siagie_tmp_path');
        if (!is_dir($dir)) {
            return;
        }
        $limite = time() - self::TTL_TEMP;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && @filemtime($f) < $limite) {
                @unlink($f);
            }
        }
    }

    /** Nombre de descarga saneado (ASCII), conservando la base del SIAGIE. */
    private function nombreDescarga(string $original): string
    {
        $nombre = preg_replace('/[^A-Za-z0-9._ -]/', '', basename($original)) ?? '';
        $nombre = trim($nombre);
        if ($nombre === '' || $nombre === '.xlsx') {
            $nombre = 'acta_siagie.xlsx';
        }
        if (!str_ends_with(strtolower($nombre), '.xlsx')) {
            $nombre .= '.xlsx';
        }
        return $nombre;
    }

    /** Streamea un archivo del disco como descarga y termina. */
    private function descargarArchivo(string $ruta, string $nombre, string $mime): never
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: no-store, must-revalidate');
        header('Pragma: no-cache');
        readfile($ruta);
        exit;
    }

    /** Streamea texto en memoria como descarga y termina. */
    private function descargarTexto(string $nombre, string $contenido): never
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($contenido));
        header('Cache-Control: no-store, must-revalidate');
        echo $contenido;
        exit;
    }
}
