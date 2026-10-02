<?php

namespace App\Siagie;

use App\Models\SiagieExportModel;
use RuntimeException;

/**
 * LlenadorSiagie
 *
 * Orquestación del volcado de notas SIGA → Excel oficial del SIAGIE. Es la
 * capa compartida por el CLI (scripts/siagie/llenar-siagie.php) y el módulo
 * web (Admin\ActasSiagieController): parsea las hojas de área, mapea cada
 * columna a su competencia SIGA por la leyenda, cruza los estudiantes contra
 * las matrículas y decide qué literal + conclusión escribir en cada celda.
 *
 * Está partida en operaciones puras:
 *   - analizar()          → decide TODO sin tocar disco ni BD (es el preview).
 *   - escribirVerificado()→ genera el temporal y lo verifica celda por celda.
 *   - persistirCodigos()  → guarda los códigos SIAGIE emparejados.
 * Cada llamador aplica su propia política de disposición del temporal (el CLI
 * respalda y reemplaza el original in-place; el web lo streamea para descargar).
 *
 * Reglas completas: docs/modulos/export-siagie.md
 */
class LlenadorSiagie
{
    /** Hojas de metadata del libro que no llevan notas. */
    private const HOJAS_META = ['Generalidades', 'Parametros'];

    /*
     * EXCEPCIONES DE HOJA: viven en `EQUIVALENCIAS_ACTA_SIAGIE` (helpers.php)
     * desde el 02/10/2026, porque la situacion final tambien las lee (cuenta lo
     * que va en el acta). Se aplican DESPUES de resolver la hoja y ANTES del
     * mapeo por leyenda: el mapeo por texto acertaria el area "oficial" (que
     * no tiene notas) y dejaria el acta en blanco.
     */

    private SiagieExportModel $modelo;

    public function __construct(?SiagieExportModel $modelo = null)
    {
        $this->modelo = $modelo ?? new SiagieExportModel();
    }

    /**
     * Analiza un archivo SIAGIE y decide qué escribir, SIN tocar disco ni BD.
     * Las escrituras quedan ENCOLADAS en el XlsxQuirurgico devuelto; el llamador
     * decide si materializarlas (escribirVerificado) o descartarlas (preview).
     *
     * @param string $rutaArchivo  ruta al .xlsx a analizar.
     * @param array  $resoluciones fila(int) => estudiante_id(int). Resolución
     *               manual de identidad para filas sin_match/ambiguo; 0 = dejar
     *               en blanco. Vacío ⇒ comportamiento automático puro (CLI).
     * @return array {destino, etiqueta, xlsx, escrituras, codigos, reporte,
     *                resumen, matching, roster}
     * @throws RuntimeException en rechazo (sin hoja Parametros, destino inválido,
     *                          periodo no cerrado en SIGA).
     */
    public function analizar(string $rutaArchivo, array $resoluciones = []): array
    {
        $reporte = [];
        $xlsx    = new XlsxQuirurgico($rutaArchivo);

        // 1. Parámetros del archivo (hoja oculta) → destino en SIGA
        if (!in_array('Parametros', $xlsx->nombresDeHojas(), true)) {
            throw new RuntimeException('El archivo no tiene la hoja "Parametros" — no es una plantilla SIAGIE');
        }
        $par = $xlsx->leerCeldas('Parametros');
        $destino = $this->modelo->resolverDestino([
            'anio'           => $par[4]['B'] ?? '',
            'nivel_nombre'   => $par[3]['C'] ?? '',
            'periodo_codigo' => $par[6]['B'] ?? '',
            'seccion_texto'  => $par[8]['C'] ?? '',
        ]);
        if (isset($destino['error'])) {
            throw new RuntimeException($destino['error']);
        }
        $etiqueta = "{$destino['nivel_nombre']} {$destino['grado_numero']}{$destino['seccion_nombre']} — {$destino['periodo_nombre']}";
        $reporte[] = "Destino: {$etiqueta} (periodo {$destino['periodo_estado']})";

        // Regla 2: SOLO bimestres cerrados por Registro académico
        if ($destino['periodo_estado'] !== 'cerrado') {
            throw new RuntimeException("El {$destino['periodo_nombre']} NO está cerrado en SIGA — archivo rechazado completo");
        }

        // 2. Universo SIGA y catálogo de competencias del nivel
        $estudiantes = $this->modelo->estudiantesDeSeccion($destino['seccion_id']);
        // Cada alumno de la sección lleva su origen para distinguirlo de los de
        // otras secciones (que se cargan bajo demanda para detectar cambios de
        // sección sin tramitar).
        foreach ($estudiantes as &$e) {
            $e['seccion_id']     = $destino['seccion_id'];
            $e['seccion_nombre'] = $destino['seccion_nombre'];
        }
        unset($e);
        $rosterPorId = [];
        foreach ($estudiantes as $e) {
            $rosterPorId[(int) $e['estudiante_id']] = $e;
        }
        $otras = [];   // estudiantes de otras secciones del grado (carga diferida)
        $catalogo = [];
        foreach ($this->modelo->competenciasDelNivel($destino['nivel_id']) as $c) {
            $catalogo[MatcherEstudiantes::normalizar($c['nombre_completo'])][] = $c;
        }

        // 3. Recorrer las hojas de área
        $escriturasLog    = [];   // [hoja, ref, texto] para la verificación
        $matchesBase      = null; // matching de la primera hoja (se reusa si las filas coinciden)
        $firmaBase        = null;
        $codigosPersistir = [];   // estudiante_id => codigo (dedupe entre hojas)
        $codigosInvalidos = [];   // estudiante_id => true (código col. B sin 14 dígitos, reportado una vez)
        $notasCache       = [];   // matricula_id => notas por competencia
        $exoCache         = [];   // matricula_id => set exoneradas
        $autCache         = [];   // matricula_id => notas autorizadas por direccion
        $advertencias     = [];
        $blancos          = [];
        $autorizadas      = [];   // celdas llenadas con nota autorizada (informe aparte)
        $filasBaseCount   = 0;
        $totNl = $totConc = 0;
        $porCodigo = $porNombre = $porManual = 0;

        foreach ($xlsx->nombresDeHojas() as $hoja) {
            if (in_array($hoja, self::HOJAS_META, true)) {
                continue;
            }
            $celdas = $xlsx->leerCeldas($hoja);

            // 3a. Layout: fila 2 marca las columnas NL; fila 1 trae el número
            $columnas = []; // numero => ['nl'=>col, 'conc'=>col]
            foreach ($celdas[2] ?? [] as $col => $v) {
                if (trim((string) $v) === 'NL') {
                    $numero = (int) ltrim((string) ($celdas[1][$col] ?? ''), '0');
                    if ($numero > 0) {
                        $columnas[$numero] = ['nl' => $col, 'conc' => $this->siguienteColumna($col)];
                    }
                }
            }
            if ($columnas === []) {
                $reporte[] = "HOJA {$hoja}: sin columnas NL reconocibles — omitida";
                continue;
            }

            // 3b. Filas de estudiantes (desde la 3, mientras haya nombre)
            $filasExcel = [];
            for ($f = 3; isset($celdas[$f]['C']); $f++) {
                $filasExcel[] = [
                    'fila'      => $f,
                    'id_siagie' => trim((string) ($celdas[$f]['A'] ?? '')),
                    'codigo'    => trim((string) ($celdas[$f]['B'] ?? '')),
                    'nombre'    => trim((string) $celdas[$f]['C']),
                ];
            }
            if ($filasExcel === []) {
                $reporte[] = "HOJA {$hoja}: sin estudiantes — omitida";
                continue;
            }
            $ultimaFila = end($filasExcel)['fila'];

            // 3c. Leyenda al pie → mapear cada columna a la competencia SIGA
            $leyenda = []; // numero => texto
            foreach ($celdas as $f => $cols) {
                if ($f <= $ultimaFila) {
                    continue;
                }
                foreach ($cols as $v) {
                    if (preg_match('/^\s*(\d{1,2})\s*=\s*(.+)$/u', (string) $v, $m)) {
                        $leyenda[(int) $m[1]] = trim($m[2]);
                    }
                }
            }

            // Resolución hoja → área SIGA por el código del tab (063-MATE → '063').
            // Habilita desambiguar homónimos por área (Matemática vs Taller) y el
            // llenado por posición (Inglés, leyenda abreviada). Si el código no mapea
            // (primaria aún sin poblar, o CAST SEGNL sin área) → $areaHoja = null y
            // el comportamiento es IDÉNTICO al matching global previo.
            $codigoHoja = explode('-', $hoja, 2)[0];
            $areaHoja   = $this->modelo->areaPorCodigoSiagie($destino['nivel_id'], $codigoHoja);
            $compsArea  = $areaHoja ? $this->modelo->competenciasDeArea((int) $areaHoja['id']) : [];
            $usadasArea = []; // competencia_id ya asignada en esta hoja (evita doble asignación)

            // EXCEPCIÓN DE HOJA: el área que el SIAGIE espera aquí no es la que
            // evalúa en SIGA (EREL ← Ética; EPT de 5° ← GAMA). Manda sobre la
            // leyenda, porque el texto acertaría el área oficial —que no tiene
            // cargas— y dejaría el acta en blanco. Ver EQUIVALENCIAS_ACTA_SIAGIE (helpers.php).
            $excepcion = $this->excepcionDeHoja($codigoHoja, $destino, $reporte);

            $mapa = []; // numero => competencia (fila del catálogo)
            $sinEquivalente = [];
            foreach ($columnas as $numero => $cc) {
                if ($excepcion !== null
                    && ($excepcion['columnas'] === null
                        || in_array($numero, $excepcion['columnas'], true))) {
                    $mapa[$numero] = $excepcion['competencia'];
                    continue;
                }
                $texto = $leyenda[$numero] ?? null;
                if ($texto === null) {
                    $reporte[] = "HOJA {$hoja}: la columna {$numero} no aparece en la leyenda — omitida";
                    continue;
                }
                $clave      = MatcherEstudiantes::normalizar($texto);
                $candidatas = $catalogo[$clave] ?? [];
                if (count($candidatas) === 1) {
                    $mapa[$numero] = $candidatas[0];
                    $usadasArea[(int) $candidatas[0]['competencia_id']] = true;
                } elseif (count($candidatas) > 1) {
                    // Homónimos (Matemática vs Taller): quedarse con el de ESTA hoja/área.
                    $enArea = $areaHoja
                        ? array_values(array_filter($candidatas, fn($c) => (int) $c['area_id'] === (int) $areaHoja['id']))
                        : [];
                    if (count($enArea) === 1) {
                        $mapa[$numero] = $enArea[0];
                        $usadasArea[(int) $enArea[0]['competencia_id']] = true;
                    } else {
                        $reporte[] = "HOJA {$hoja}: la competencia {$numero} matchea " . count($candidatas) . ' competencias de SIGA — omitida por ambigüedad';
                    }
                } else {
                    // Sin match de texto. Si la hoja se resolvió a un área, asignar por
                    // POSICIÓN a su competencia de ese orden (leyenda abreviada, Inglés),
                    // sin repetir competencia dentro de la hoja.
                    $comp = $compsArea[$numero - 1] ?? null;
                    if ($comp !== null && !isset($usadasArea[(int) $comp['competencia_id']])) {
                        $mapa[$numero] = $comp;
                        $usadasArea[(int) $comp['competencia_id']] = true;
                        $reporte[] = "HOJA {$hoja}: columna {$numero} ('{$texto}') sin match de texto → asignada por posición a '{$comp['nombre_completo']}' ({$comp['area_nombre']})";
                    } else {
                        $sinEquivalente[] = "{$numero} ({$texto})";
                    }
                }
            }
            if ($mapa === []) {
                $detalle = $sinEquivalente !== [] ? ' (sin equivalente en SIGA: ' . implode('; ', $sinEquivalente) . ')' : '';
                $reporte[] = "HOJA {$hoja}: en blanco{$detalle}";
                continue;
            }
            if ($sinEquivalente !== []) {
                $reporte[] = "HOJA {$hoja}: columnas sin equivalente en SIGA (en blanco): " . implode('; ', $sinEquivalente);
            }

            // 3d. Matching (se reusa el de la primera hoja si las filas son idénticas)
            $firma = md5(serialize(array_map(fn($r) => [$r['fila'], $r['codigo'], $r['nombre']], $filasExcel)));
            if ($matchesBase === null || $firma !== $firmaBase) {
                $resultado = MatcherEstudiantes::matchear($filasExcel, $estudiantes);
                if ($matchesBase === null) {
                    // Detección de cambio de sección sin tramitar: si hay filas de
                    // identidad dudosa (o resoluciones que aplicar), traemos las otras
                    // secciones del grado, las sumamos al roster válido y anotamos el
                    // posible alumno en su sección real. NO auto-escribe nada.
                    $hayDudosas = false;
                    foreach ($resultado['matches'] as $mm) {
                        if ($mm['estado'] === 'sin_match' || $mm['estado'] === 'ambiguo') {
                            $hayDudosas = true;
                            break;
                        }
                    }
                    if ($hayDudosas || $resoluciones !== []) {
                        $otras = $this->modelo->estudiantesDeOtrasSecciones(
                            $destino['grado_id'], $destino['anio_id'], $destino['seccion_id']
                        );
                        foreach ($otras as $oe) {
                            $rosterPorId[(int) $oe['estudiante_id']] = $oe;
                        }
                        $this->anotarOtraSeccion($resultado, $otras);
                    }

                    // Resolución manual de identidad (solo la base; el detalle y las
                    // rejections se reportan una vez, como el matching).
                    $resultado = $this->aplicarResoluciones($resultado, $rosterPorId, $resoluciones, $destino['seccion_id'], $reporte);
                    $matchesBase = $resultado;
                    $firmaBase   = $firma;
                    $filasBaseCount = count($filasExcel);
                    // El detalle de matching se reporta UNA vez (las hojas comparten nómina)
                    foreach ($resultado['matches'] as $mm) {
                        if ($mm['estado'] === 'match_codigo') $porCodigo++;
                        if ($mm['estado'] === 'match_nombre') $porNombre++;
                        if ($mm['estado'] === 'match_manual') $porManual++;
                    }
                    $manualTxt = $porManual > 0 ? ", por resolucion {$porManual}" : '';
                    $reporte[] = '';
                    $reporte[] = 'MATCHING DE ESTUDIANTES: Excel ' . count($filasExcel)
                        . ' | SIGA ' . count($estudiantes)
                        . " | matcheados " . ($porCodigo + $porNombre + $porManual)
                        . " (por código {$porCodigo}, por nombre {$porNombre}{$manualTxt})";
                    foreach ($resultado['matches'] as $mm) {
                        if (!$this->esMatch($mm['estado'])) {
                            $extra = '';
                            if (isset($mm['otra_seccion'])) {
                                $os = $mm['otra_seccion'];
                                $extra = " — posible cambio de sección: existe en {$destino['grado_numero']}{$os['seccion_nombre']}"
                                    . " ({$os['apellido_paterno']} {$os['apellido_materno']}, {$os['nombres']}, DNI {$os['dni']})";
                            }
                            $reporte[] = "  ✗ fila {$mm['fila']} [{$mm['estado']}] {$mm['nombre']}"
                                . ($mm['detalle'] !== '' ? " — {$mm['detalle']}" : '')
                                . $extra;
                        } elseif ($mm['detalle'] !== '') {
                            $reporte[] = "  ⚠ fila {$mm['fila']} {$mm['nombre']} — {$mm['detalle']}";
                        }
                    }
                    foreach ($resultado['siga_sin_fila'] as $e) {
                        $reporte[] = "  ✗ en SIGA pero sin fila en el Excel: {$e['apellido_paterno']} {$e['apellido_materno']}, {$e['nombres']} (DNI {$e['dni']})";
                    }
                    $reporte[] = '';
                } else {
                    // Hoja con nómina distinta: aplicar resoluciones en silencio.
                    $descartar = [];
                    $resultado = $this->aplicarResoluciones($resultado, $rosterPorId, $resoluciones, $destino['seccion_id'], $descartar);
                    $reporte[] = "HOJA {$hoja}: la nómina difiere de las demás hojas — matching recalculado";
                }
            } else {
                $resultado = $matchesBase;
            }

            // 3e. Volcado
            $celdasNl = $celdasConc = 0;
            foreach ($resultado['matches'] as $mm) {
                if (!$this->esMatch($mm['estado'])) {
                    continue;
                }
                $e    = $mm['estudiante'];
                $mid  = (int) $e['matricula_id'];
                $fila = $mm['fila'];

                if (!isset($notasCache[$mid])) {
                    $notasCache[$mid] = $this->modelo->notasOficiales($mid, $destino['periodo_id']);
                    $exoCache[$mid]   = $this->modelo->competenciasExoneradas($mid, $destino['anio_id']);
                    $autCache[$mid]   = $this->modelo->notasAutorizadas($mid, $destino['periodo_id']);
                }
                // Código SIAGIE a persistir tras match por nombre o resolución (una sola vez).
                // Es una mutación DURABLE que se vuelve la llave de matcheo de los próximos
                // bimestres → solo si tiene el formato oficial de 14 dígitos; nunca grabar basura.
                if ($this->persisteCodigo($mm['estado']) && $mm['codigo'] !== '' && trim((string) $e['codigo_estudiante']) === '') {
                    $eid = (int) $e['estudiante_id'];
                    if (preg_match('/^\d{14}$/', $mm['codigo'])) {
                        $codigosPersistir[$eid] = $mm['codigo'];
                    } elseif (!isset($codigosInvalidos[$eid])) {
                        $codigosInvalidos[$eid] = true;
                        $advertencias[] = "{$hoja} fila {$fila}: el código '{$mm['codigo']}' de la columna B no tiene 14 dígitos — la nota SÍ se escribe, pero el código NO se registra en SIGA (revisar el Excel)";
                    }
                }

                foreach ($mapa as $numero => $cc) {
                    $compId = (int) $cc['competencia_id'];
                    $cols   = $columnas[$numero];
                    $refNl  = $cols['nl'] . $fila;
                    $refCo  = $cols['conc'] . $fila;

                    if (isset($exoCache[$mid][$compId])) {
                        $blancos[] = "{$hoja} fila {$fila}: EXONERADO — celda omitida ({$mm['nombre']})";
                        continue;
                    }
                    $nota = $notasCache[$mid][$compId] ?? null;
                    if ($nota === null) {
                        // Sin nota oficial: si dirección autorizó una nota para esta
                        // competencia (ausencia justificada, solo SIAGIE), se llena la
                        // celda con ella. Nunca pisa un valor ya presente en el Excel.
                        $aut = $autCache[$mid][$compId] ?? null;
                        if ($aut !== null) {
                            if (isset($celdas[$fila][$cols['nl']])) {
                                $advertencias[] = "{$hoja} {$refNl}: la celda YA tiene valor '{$celdas[$fila][$cols['nl']]}' — no se toca (nota autorizada)";
                            } else {
                                $xlsx->escribir($hoja, $refNl, $aut['literal']);
                                $escriturasLog[] = [$hoja, $refNl, $aut['literal']];
                                $celdasNl++;
                                $autorizadas[] = "{$hoja} {$refNl}: {$aut['literal']} — nota AUTORIZADA por dirección (no evaluado) — {$mm['nombre']}";
                            }
                            $concAut = $aut['conclusion'] ?? null;
                            if ($concAut !== null && $concAut !== '' && !isset($celdas[$fila][$cols['conc']])) {
                                $xlsx->escribir($hoja, $refCo, $concAut);
                                $escriturasLog[] = [$hoja, $refCo, $concAut];
                                $celdasConc++;
                            }
                            continue;
                        }
                        $blancos[] = "{$hoja} fila {$fila} col {$cols['nl']}: sin nota oficial (sin bloqueo o no evaluada) — {$mm['nombre']}";
                        continue;
                    }
                    // Nunca sobreescribir un valor ya presente en el Excel
                    if (isset($celdas[$fila][$cols['nl']])) {
                        $advertencias[] = "{$hoja} {$refNl}: la celda YA tiene valor '{$celdas[$fila][$cols['nl']]}' — no se toca";
                    } else {
                        $literal = nota_a_literal((int) $nota['nota_numerica']);
                        $xlsx->escribir($hoja, $refNl, $literal);
                        $escriturasLog[] = [$hoja, $refNl, $literal];
                        $celdasNl++;
                    }
                    // Regla 3: TODAS las conclusiones existentes
                    $conclusion = $nota['conclusion'];
                    if ($conclusion !== null && $conclusion !== '') {
                        $len = mb_strlen($conclusion);
                        if ($len < 10 || $len > 500) {
                            $advertencias[] = "{$hoja} {$refCo}: conclusión de {$len} caracteres — el SIAGIE valida 10–500 ({$mm['nombre']})";
                        }
                        if (isset($celdas[$fila][$cols['conc']])) {
                            $advertencias[] = "{$hoja} {$refCo}: la celda YA tiene valor — no se toca";
                        } else {
                            $xlsx->escribir($hoja, $refCo, $conclusion);
                            $escriturasLog[] = [$hoja, $refCo, $conclusion];
                            $celdasConc++;
                        }
                    }
                }
            }
            $totNl   += $celdasNl;
            $totConc += $celdasConc;
            $areaSiga = implode(', ', array_unique(array_column($mapa, 'area_nombre')));
            $marcaExc = $excepcion !== null
                ? " [EXCEPCIÓN: {$excepcion['motivo']}"
                    . ($excepcion['columnas'] === null ? ', misma nota en todas las columnas' : '')
                    . ']'
                : '';
            $reporte[] = "HOJA {$hoja} → {$areaSiga}{$marcaExc}: " . count($mapa) . " competencia(s) mapeada(s); {$celdasNl} NL, {$celdasConc} conclusiones";
        }

        // 4. Advertencias y celdas en blanco
        if ($advertencias !== []) {
            $reporte[] = '';
            $reporte[] = 'ADVERTENCIAS (' . count($advertencias) . '):';
            foreach ($advertencias as $a) {
                $reporte[] = "  ⚠ {$a}";
            }
        }
        if ($blancos !== []) {
            $reporte[] = '';
            $reporte[] = 'CELDAS EN BLANCO (' . count($blancos) . '):';
            foreach ($blancos as $b) {
                $reporte[] = "  · {$b}";
            }
        }
        if ($autorizadas !== []) {
            $reporte[] = '';
            $reporte[] = 'NOTAS AUTORIZADAS POR DIRECCIÓN — no evaluado, solo SIAGIE (' . count($autorizadas) . '):';
            foreach ($autorizadas as $a) {
                $reporte[] = "  ✎ {$a}";
            }
        }

        // Filas sin resolver que apuntan a un alumno de otra sección (aviso UI).
        $otraSeccionDetectadas = 0;
        if ($matchesBase !== null) {
            foreach ($matchesBase['matches'] as $mm) {
                if ($mm['estado'] === 'sin_match' && isset($mm['otra_seccion'])) {
                    $otraSeccionDetectadas++;
                }
            }
        }

        return [
            'destino'    => $destino,
            'etiqueta'   => $etiqueta,
            'xlsx'       => $xlsx,
            'escrituras' => $escriturasLog,
            'codigos'    => $codigosPersistir,
            'reporte'    => $reporte,
            'resumen'    => [
                'nl'                => $totNl,
                'conc'              => $totConc,
                'match_codigo'      => $porCodigo,
                'match_nombre'      => $porNombre,
                'match_manual'      => $porManual,
                'advertencias'      => count($advertencias),
                'blancos'           => count($blancos),
                'autorizadas'       => count($autorizadas),
                'estudiantes_excel' => $filasBaseCount,
                'estudiantes_siga'  => count($estudiantes),
                'otra_seccion'      => $otraSeccionDetectadas,
            ],
            'matching'         => $matchesBase,
            'roster'           => $estudiantes,
            'roster_otras'     => $otras,
            'roster_valido_ids'=> array_map('intval', array_keys($rosterPorId)),
        ];
    }

    /**
     * Genera el temporal con las escrituras encoladas y lo verifica celda por
     * celda releyendo la copia. El original NO se toca. Devuelve la ruta del
     * temporal verificado (el llamador lo respalda/reemplaza o lo streamea).
     *
     * @param array $escrituras [[hoja, ref, texto], …] las mismas que se encolaron.
     * @throws RuntimeException si alguna celda no quedó como se esperaba.
     */
    public function escribirVerificado(XlsxQuirurgico $xlsx, array $escrituras): string
    {
        $tmp   = $xlsx->guardarEnTemporal();
        $ver   = new XlsxQuirurgico($tmp);
        $cache = [];
        foreach ($escrituras as [$hoja, $ref, $texto]) {
            $cache[$hoja] ??= $ver->leerCeldas($hoja);
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $mr);
            $leido = $cache[$hoja][(int) $mr[2]][$mr[1]] ?? null;
            if ($leido !== $texto) {
                @unlink($tmp);
                throw new RuntimeException("Verificación FALLIDA en {$hoja}!{$ref}: se esperaba '{$texto}' y se leyó '" . var_export($leido, true) . "' — el original NO fue tocado");
            }
        }
        return $tmp;
    }

    /**
     * Persiste los códigos SIAGIE emparejados (solo si el campo estaba vacío).
     * @param array $codigos estudiante_id => codigo.
     * @return int cuántos se escribieron efectivamente.
     */
    public function persistirCodigos(array $codigos): int
    {
        $persistidos = 0;
        foreach ($codigos as $estudianteId => $codigo) {
            if ($this->modelo->guardarCodigoSiagie((int) $estudianteId, $codigo)) {
                $persistidos++;
            }
        }
        return $persistidos;
    }

    // ── internos ────────────────────────────────────────────────

    /**
     * Aplica las resoluciones manuales de identidad sobre el resultado del
     * matching. Solo transforma filas sin_match/ambiguo en 'match_manual'
     * apuntando a un estudiante del roster VÁLIDO (su sección ∪ otras secciones
     * del grado); rechaza (y reporta) toda resolución que viole las guardas:
     * fuera del roster, estudiante ya asignado, o código en conflicto. Si el
     * alumno elegido es de otra sección, lo marca como cambio de sección sin
     * tramitar. Con $resoluciones vacío es un NO-OP total (idéntico al CLI).
     *
     * @param array $rosterPorId    estudiante_id => fila (sección ∪ otras secciones).
     * @param int   $seccionActualId sección del acta (para detectar el cruce).
     */
    private function aplicarResoluciones(array $resultado, array $rosterPorId, array $resoluciones, int $seccionActualId, array &$reporte): array
    {
        if ($resoluciones === []) {
            return $resultado;
        }

        // Estudiantes ya tomados por un match (automático o previo).
        $tomados = [];
        foreach ($resultado['matches'] as $mm) {
            if ($mm['estudiante'] !== null && $this->esMatch($mm['estado'])) {
                $tomados[(int) $mm['estudiante']['estudiante_id']] = $mm['fila'];
            }
        }

        $huboCambio = false;
        foreach ($resultado['matches'] as &$mm) {
            $fila = (int) $mm['fila'];
            if (!array_key_exists($fila, $resoluciones)) {
                continue;
            }
            $eid = (int) $resoluciones[$fila];
            if ($eid <= 0) {
                continue; // "dejar en blanco" explícito
            }
            if (!in_array($mm['estado'], ['sin_match', 'ambiguo'], true)) {
                $reporte[] = "  ⚠ RESOLUCION IGNORADA fila {$fila}: estado '{$mm['estado']}' no es resoluble manualmente";
                continue;
            }
            $e = $rosterPorId[$eid] ?? null;
            if ($e === null) {
                $reporte[] = "  ✗ RESOLUCION RECHAZADA fila {$fila}: el estudiante elegido no pertenece a la sección";
                continue;
            }
            if (isset($tomados[$eid])) {
                $reporte[] = "  ✗ RESOLUCION RECHAZADA fila {$fila}: ese estudiante ya está asignado a la fila {$tomados[$eid]}";
                continue;
            }
            $codigoSiga  = trim((string) ($e['codigo_estudiante'] ?? ''));
            $codigoExcel = trim((string) $mm['codigo']);
            if ($codigoSiga !== '' && $codigoExcel !== '' && $codigoSiga !== $codigoExcel) {
                $reporte[] = "  ✗ RESOLUCION RECHAZADA fila {$fila}: el estudiante ya tiene código {$codigoSiga} y el Excel trae {$codigoExcel} — corregir en su matrícula";
                continue;
            }
            $mm['estado']     = 'match_manual';
            $mm['estudiante'] = $e;
            $esCruce = (int) ($e['seccion_id'] ?? $seccionActualId) !== $seccionActualId;
            $detalle = "Resuelto manualmente → {$e['apellido_paterno']} {$e['apellido_materno']}, {$e['nombres']} (DNI {$e['dni']})";
            if ($esCruce) {
                $detalle .= " — CAMBIO DE SECCIÓN sin tramitar (SIGA lo tiene en {$e['seccion_nombre']})";
            }
            $mm['detalle']       = $detalle;
            $mm['cruce_seccion'] = $esCruce;
            $tomados[$eid]       = $fila;
            $huboCambio          = true;
        }
        unset($mm);

        // Recalcular los "sin fila en el Excel" según las asignaciones finales.
        if ($huboCambio) {
            $sinFila = [];
            foreach ($rosterPorId as $eid => $e) {
                if (!isset($tomados[$eid])) {
                    $sinFila[] = $e;
                }
            }
            $resultado['siga_sin_fila'] = $sinFila;
        }

        return $resultado;
    }

    /**
     * Anota, en cada fila `sin_match`, el estudiante de OTRA sección del grado
     * cuyo nombre normalizado coincide de forma ÚNICA (probable cambio de
     * sección sin tramitar). Es solo una pista para el reporte y la UI: no
     * cambia el estado ni escribe nada; el usuario debe confirmarlo.
     *
     * @param array $otras estudiantesDeOtrasSecciones() (traen seccion_id/nombre).
     */
    private function anotarOtraSeccion(array &$resultado, array $otras): void
    {
        if ($otras === []) {
            return;
        }
        $porNombre = [];
        foreach ($otras as $e) {
            $clave = MatcherEstudiantes::normalizar($e['apellido_paterno'] . ' ' . $e['apellido_materno'] . ' ' . $e['nombres']);
            $porNombre[$clave][] = $e;
        }
        foreach ($resultado['matches'] as &$mm) {
            if ($mm['estado'] !== 'sin_match') {
                continue;
            }
            $clave = MatcherEstudiantes::normalizar(str_replace(',', ' ', $mm['nombre']));
            if (isset($porNombre[$clave]) && count($porNombre[$clave]) === 1) {
                $mm['otra_seccion'] = $porNombre[$clave][0];
            }
        }
        unset($mm);
    }

    /**
     * EXCEPCIONES declaradas para un nivel, ya resueltas a su competencia, para
     * MOSTRARLAS (Actas SIAGIE → Vínculos). Sin esto las dos reglas vigentes
     * solo existirían dentro del código y nadie en Registro académico sabría
     * por qué la hoja de Ed. Religiosa se llena con la nota del tutor.
     *
     * Es la misma resolución que usa el llenado real (mismo método privado), así
     * que lo que se ve en pantalla es exactamente lo que se aplicará.
     *
     * @return array<int, array{codigo_hoja: string, grados: ?array, columnas: ?array,
     *                          motivo: string, competencia: ?array, error: ?string}>
     */
    public function excepcionesDeclaradas(string $nivelCodigo, int $nivelId): array
    {
        $out = [];
        foreach (EQUIVALENCIAS_ACTA_SIAGIE as $regla) {
            if ($regla['nivel_codigo'] !== $nivelCodigo) {
                continue;
            }
            // Un grado cualquiera de los que cubre la regla basta para resolverla:
            // la competencia destino no depende del grado, solo su aplicabilidad.
            $grado   = $regla['grados'][0] ?? 1;
            $reporte = [];
            $res     = $this->excepcionDeHoja($regla['codigo_hoja'], [
                'nivel_codigo' => $nivelCodigo,
                'nivel_id'     => $nivelId,
                'grado_numero' => $grado,
            ], $reporte);

            $out[] = [
                'codigo_hoja' => $regla['codigo_hoja'],
                'grados'      => $regla['grados'],
                'columnas'    => $regla['columnas'],
                'motivo'      => $regla['motivo'],
                'competencia' => $res['competencia'] ?? null,
                'error'       => $res === null ? ($reporte[0] ?? 'no se pudo resolver') : null,
            ];
        }
        return $out;
    }

    /**
     * Excepción de hoja aplicable a este destino, ya resuelta a una competencia
     * concreta, o null si no hay ninguna. Ver EQUIVALENCIAS_ACTA_SIAGIE (helpers.php).
     *
     * Si la regla existe pero su competencia no se puede identificar de forma
     * ÚNICA (área ausente o renombrada, con más de una competencia, o código
     * MINEDU duplicado), devuelve null y deja constancia en $reporte: preferimos
     * el comportamiento anterior —la celda queda en blanco y se reporta— antes
     * que escribir una nota adivinada en un acta oficial.
     *
     * @return array{competencia: array, columnas: ?array, motivo: string}|null
     */
    private function excepcionDeHoja(string $codigoHoja, array $destino, array &$reporte): ?array
    {
        foreach (EQUIVALENCIAS_ACTA_SIAGIE as $regla) {
            if ($regla['nivel_codigo'] !== ($destino['nivel_codigo'] ?? null)
                || $regla['codigo_hoja'] !== $codigoHoja) {
                continue;
            }
            if ($regla['grados'] !== null
                && !in_array((int) $destino['grado_numero'], $regla['grados'], true)) {
                continue;
            }

            $comp = null;
            if (isset($regla['buscar']['nombre_boleta'])) {
                $comps = $this->modelo->competenciasDeAreaPorNombreBoleta(
                    (int) $destino['nivel_id'],
                    $regla['buscar']['nombre_boleta']
                );
                if (count($comps) === 1) {
                    $comp = $comps[0];
                } else {
                    $reporte[] = "EXCEPCION NO APLICADA (hoja {$codigoHoja}): el area '"
                        . $regla['buscar']['nombre_boleta'] . "' tiene " . count($comps)
                        . ' competencia(s); se esperaba exactamente 1 — la hoja queda con su mapeo normal';
                }
            } elseif (isset($regla['buscar']['codigo_minedu'])) {
                $comp = $this->modelo->competenciaPorCodigoMinedu(
                    (int) $destino['nivel_id'],
                    $regla['buscar']['codigo_minedu']
                );
                if ($comp === null) {
                    $reporte[] = "EXCEPCION NO APLICADA (hoja {$codigoHoja}): no hay una competencia unica con codigo MINEDU '"
                        . $regla['buscar']['codigo_minedu'] . "' en el nivel — la hoja queda con su mapeo normal";
                }
            }

            if ($comp === null) {
                return null;
            }
            return [
                'competencia' => $comp,
                'columnas'    => $regla['columnas'],
                'motivo'      => $regla['motivo'],
            ];
        }
        return null;
    }

    /** Estados que cuentan como emparejamiento efectivo (se escribe la nota). */
    private function esMatch(string $estado): bool
    {
        return $estado === 'match_codigo'
            || $estado === 'match_nombre'
            || $estado === 'match_manual';
    }

    /** Estados tras los cuales se persiste el código SIAGIE (no venía por código). */
    private function persisteCodigo(string $estado): bool
    {
        return $estado === 'match_nombre' || $estado === 'match_manual';
    }

    /** Columna siguiente en notación Excel (D→E, Z→AA). */
    private function siguienteColumna(string $col): string
    {
        $n = 0;
        foreach (str_split($col) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        $n++;
        $s = '';
        while ($n > 0) {
            $r = ($n - 1) % 26;
            $s = chr(65 + $r) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }
}
