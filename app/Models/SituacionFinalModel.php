<?php

namespace App\Models;

/**
 * SituacionFinalModel — proyección de la SITUACIÓN FINAL del estudiante
 * (PRO / RR / PER) y, con ella, el RIESGO ACADÉMICO (23/09/2026).
 *
 * «Estudiante en riesgo» dejó de ser un conteo global inventado (primaria
 * B+C >= 3 · secundaria C >= 3) y pasó a ser lo que define el MINEDU: los que
 * NO serán promovidos. La regla vive en `helpers.php`
 * (`situacion_final_analisis()`), que es su punto único; este modelo solo le
 * entrega los conteos POR ÁREA y ordena la salida.
 *
 * 🔴 POR QUÉ ES UN MODELO PROPIO Y NO UN MÉTODO DEL MÉRITO. Hasta hoy el riesgo
 * salía de `OrdenMeritoModel::statsPorGrado`, y de ahí heredaba dos cosas que
 * NO le correspondían:
 *   · el ROSTER del mérito (`estado = 'aprobada'`), que deja fuera a las
 *     matrículas `pendiente` —el estado en que NACE toda matrícula—, que sí se
 *     califican, sí reciben boleta y por tanto sí serán promovidas o no;
 *   · los agregados GLOBALES del ranking (`num_b`, `num_c`), que no pueden
 *     responder una regla que se cuenta POR ÁREA.
 * Pertenecer al documento del mérito y ser promovido de grado son preguntas
 * distintas, y desde hoy tienen dos dueños distintos.
 *
 * ⚠️ SIEMPRE EN VIVO, TAMBIÉN EN BIMESTRES CERRADOS. `orden_merito_snapshot`
 * congela solo agregados globales y no puede reconstruir los conteos por área.
 * A cambio desaparece el «guard del descuadre» que necesitaba el informe
 * anterior: la fila y su desglose salen ahora de la MISMA consulta, así que no
 * pueden contradecirse.
 */
class SituacionFinalModel extends BaseModel
{
    protected string $table = 'matriculas';

    /**
     * PUNTO ÚNICO del ROSTER de la situación final: a quién se le proyecta.
     *
     * Son DOS condiciones y ninguna de las dos es negociable:
     *
     *  1. `matriculas_vigentes()` — fuera el trasladado y el retirado, que ya no
     *     cursan aquí. Se emite desde `helpers.php`, no se escribe a mano.
     *     **NO filtra por `estado`**, a propósito: `pendiente` y `desactivado`
     *     siguen asistiendo, se califican y serán promovidos o no.
     *  2. ANCLAJE POR BIMESTRE del retorno de grado — se excluye la matrícula
     *     OFICIAL en los periodos que cubrió su OPERATIVA (siempre si el retorno
     *     está `activo`; solo en los bimestres con notas si está `revertido`).
     *     Es el mismo anclaje que usa el ranking en vivo del mérito.
     *
     * 🔴 NO ES `roster_evaluacion()`, Y LA DIFERENCIA IMPORTA. Aquel excluye la
     * OPERATIVA de un retorno REVERTIDO **siempre**, porque describe el estado
     * de HOY («¿a quién califico ahora?»). Este informe mira un BIMESTRE
     * concreto, y en los bimestres que el alumno cursó en la operativa es ahí
     * donde están sus notas: con `roster_evaluacion()` la oficial saldría sin
     * una sola competencia evaluada y el alumno aparecería como «sin datos» en
     * un bimestre que sí cursó.
     *
     * 🔴 TAMPOCO ES `ROSTER_MERITO`, que exige `estado = 'aprobada'` porque el
     * orden de mérito es un documento que se firma. La promoción de grado no lo
     * es. Las tres condiciones se parecen y resuelven preguntas distintas;
     * pegarle a una el filtro de otra es el «híbrido» que ya costó un defecto en
     * `/matriculas/resumen`.
     *
     * Lleva UN parámetro posicional: el `periodo_id` del anclaje.
     */
    private const ROSTER_SITUACION = "
        m.id NOT IN (
            SELECT matricula_oficial_id FROM retornos_grado WHERE estado = 'activo'
            UNION
            SELECT r.matricula_oficial_id
            FROM retornos_grado r
            INNER JOIN calificaciones c2
                ON c2.matricula_id = r.matricula_operativa_id
               AND c2.periodo_id   = ?
            WHERE r.estado = 'revertido'
        )
    ";

    /**
     * PUNTO ÚNICO de QUÉ ÁREAS cuentan para la situación final. Lo usan las
     * notas (`notasPorMatricula`) y el plan (`planPorMatricula`): si divergieran, la
     * cobertura contaría como pendiente una competencia que el cálculo ignora.
     *
     * 🔴 UN TALLER CUENTA SOLO SI LA UGEL LO APROBÓ PARA ESE AÑO Y GRADO
     * (decisión del usuario, 24/09/2026; migración 064). La norma cuenta los
     * talleres (RVM 094-2020, 5.1.3 p. 11) que forman parte del plan de estudios
     * registrado en el SIAGIE; en 2026 la UGEL no aprobó los del colegio, pero
     * puede aprobarlos otro año, y solo para algunos grados. La aprobación vive
     * en `talleres_aprobacion` (se marca en Currículo) y se mira por el año y
     * el grado OFICIAL de la matrícula de la nota: por eso esto es un método
     * que recibe la columna de matrícula, y no una constante.
     *
     * 🔴 RETORNO DE GRADO: SIEMPRE EL GRADO DE LA MATRÍCULA OFICIAL, jamás el
     * de la operativa (decisión del usuario, 24/09/2026), igual que el resto de
     * las reglas (la fila ya se reubica en la oficial). Si la nota está en la
     * operativa de un retorno —activo o revertido: la operativa nunca es la
     * identidad del estudiante—, se usa el grado de su oficial. El PLAN de
     * competencias, en cambio, sale de donde cursa: es un dato, no una regla. Sin fila aprobada, el
     * taller no cuenta. Siguen en la boleta y en el orden de mérito del colegio
     * siempre (esos filtros no cambian).
     *
     * @param string $colMatricula  columna SQL con el id de la matrícula
     *                              ('cal.matricula_id', 'm.id'); nunca un dato de usuario
     */
    private static function areasQueCuentan(string $colMatricula): string
    {
        return "
            (a.tipo NOT IN ('transversal', 'tutoria', 'taller')
             OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "'
             OR (a.tipo = 'taller' AND EXISTS (
                    SELECT 1
                    FROM matriculas mt
                    -- Matrícula OFICIAL: la propia, salvo que sea la operativa
                    -- de un retorno de grado.
                    LEFT  JOIN retornos_grado rg      ON rg.matricula_operativa_id = mt.id
                    INNER JOIN matriculas mo          ON mo.id = COALESCE(rg.matricula_oficial_id, mt.id)
                    INNER JOIN secciones so           ON so.id = mo.seccion_id
                    INNER JOIN talleres_aprobacion ta ON ta.area_id  = a.id
                                                     AND ta.anio_id  = mo.anio_id
                                                     AND ta.grado_id = so.grado_id
                                                     AND ta.aprobado = 1
                    WHERE mt.id = $colMatricula)))
        ";
    }

    /**
     * Filtro de QUÉ NOTAS entran al cálculo. Es otra pregunta que el roster, y
     * la norma la responde en el numeral 5.1.3 (puntos 9 a 11):
     *
     *  · Las COMPETENCIAS TRANSVERSALES **no se tienen en cuenta** para la
     *    situación final. (Además están duplicadas por alumno —una fila por cada
     *    carga que las registra—, así que colarlas inflaría los conteos sin que
     *    se note: 28 282 filas contra 13 308 pares en el II Bimestre.)
     *  · Los TALLERES cuentan solo si la UGEL los aprobó para ese año y grado:
     *    ver `areasQueCuentan()`.
     *  · Las competencias organizadas en áreas curriculares SÍ
     *    cuentan. De ahí la excepción de ÉTICA Y VALORES: su `tipo='tutoria'` es
     *    un artefacto de implementación —es la Educación Religiosa de
     *    secundaria, cuyo área propia es un cascarón sin cargas—, y para la
     *    norma es un área curricular de UNA competencia. Se ancla por
     *    `nombre_boleta`, NUNCA por id (difiere entre entornos).
     *  · Las ÁREAS O SUBÁREAS EXONERADAS quedan fuera del numerador y también
     *    del denominador: exonerar no borra las notas anteriores, así que sin
     *    este filtro la boleta diría EXO y la situación final seguiría contando
     *    la nota.
     *
     *  · Las NOTAS EXTRAORDINARIAS **SÍ** cuentan (decisión del usuario,
     *    24/09/2026). Son notas oficiales —van a la boleta y al SIAGIE, que
     *    calcula la situación final con ellas—. Hasta ese día este filtro
     *    llevaba `extraordinaria = 0`, copiado del universo del MÉRITO, donde
     *    sí es una regla (la extraordinaria no mueve puestos). El precio fue
     *    real: las 276 notas de Ética de B1 se registraron como extraordinarias
     *    y la situación final las ignoraba. ⚠️ El mérito CONSERVA su filtro:
     *    las dos preguntas ya no comparten universo de notas.
     *
     * Aquí entran TODOS los literales, no solo los no aprobatorios.
     */
    private const FILTRO_NOTAS = "
        NOT EXISTS (
            SELECT 1 FROM exoneraciones ex
            WHERE ex.matricula_id = cal.matricula_id
              AND ex.revocado_en IS NULL
              AND (ex.area_id = a.id OR ex.subarea_id = comp.subarea_id)
        )
    ";

    /**
     * La situación final proyectada de todos los estudiantes de un periodo,
     * agrupada por GRADO. Punto único de la pantalla, su A4, la banda de
     * `/admin/cuadros` y el panel del tutor.
     *
     * Cuatro consultas fijas, ninguna por alumno ni por grado.
     *
     * 🔴 EL RETORNO DE GRADO SE EVALÚA DONDE CURSÓ Y SE CUENTA DONDE PERTENECE
     * (decisión del usuario, 23/09/2026): las notas salen de la matrícula que
     * cubrió el bimestre (anclaje de `ROSTER_SITUACION`) pero la fila se REUBICA
     * en el grado y la sección de la matrícula OFICIAL, y la regla que se le
     * aplica es la del grado OFICIAL — que ahora pesa el doble, porque el grado
     * decide si es final de ciclo. La fila conserva su origen en `retorno`.
     *
     * @return array<int, array{grado:array, evaluados:int, sin_datos:int,
     *                          en_riesgo:array, seguimiento:array, por_seccion:array,
     *                          cobertura:array, cobertura_seccion:array}>
     */
    public function porGrado(int $periodoId): array
    {
        $roster   = $this->rosterDelPeriodo($periodoId);
        [$numeroActual, $final] = $this->datosPeriodo($periodoId);
        $notas    = $this->notasPorMatricula($periodoId, $final);
        $plan     = $this->planPorMatricula($periodoId);
        $oficial  = $this->ubicacionOficialRetornos();
        $incorp   = $this->incorporadosDespues($periodoId);

        $porGrado   = [];
        $porSeccion = [];

        foreach ($roster as $m) {
            $mid = (int) $m['matricula_id'];

            // ── Reubicación del retorno de grado ─────────────────────────────
            // La fila pertenece a su matrícula OFICIAL. Si el grado oficial no
            // existiera en el roster, se queda donde está en vez de perderse.
            $ofi     = $oficial[$mid] ?? null;
            $retorno = null;
            if ($ofi !== null) {
                $retorno = [
                    'grado_nombre'   => (string) $m['grado_nombre'],
                    'nivel_nombre'   => (string) $m['nivel_nombre'],
                    'seccion_nombre' => (string) $m['seccion_nombre'],
                ];
                $m['grado_id']       = $ofi['grado_id'];
                $m['grado_numero']   = $ofi['grado_numero'];
                $m['grado_nombre']   = $ofi['grado_nombre'];
                $m['nivel_id']       = $ofi['nivel_id'];
                $m['nivel_nombre']   = $ofi['nivel_nombre'];
                $m['nivel_codigo']   = $ofi['nivel_codigo'];
                $m['seccion_nombre'] = $ofi['seccion_nombre'];
                $m['seccion_id']     = $ofi['seccion_id'];
            }

            $gid = (int) $m['grado_id'];
            $porGrado[$gid] ??= [
                'grado' => [
                    'id'            => $gid,
                    'numero'        => (int) $m['grado_numero'],
                    'nombre_display' => (string) $m['grado_nombre'],
                    'nivel_id'      => (int) $m['nivel_id'],
                    'nivel_nombre'  => (string) $m['nivel_nombre'],
                    'nivel_codigo'  => (string) $m['nivel_codigo'],
                ],
                'evaluados'   => 0,
                'sin_datos'   => 0,
                'en_riesgo'   => [],
                // Seguimiento pedagógico (24/09/2026; desde el 25/09 para
                // TODOS, también RR/PER): evaluados con competencias bajas según
                // `seguimiento_pedagogico()`. No entra a las cifras de riesgo.
                'seguimiento' => [],
                // Periodo final con competencias sin nota: no es riesgo, se
                // lista aparte hasta completarse (`SITUACION_PENDIENTE`).
                'pendiente_final' => [],
                'periodo_final'   => $final,
                'por_seccion' => [],
                // COBERTURA del grado: sobre cuántas áreas se calculó, de las
                // que su plan dicta. En un bimestre cerrado coinciden; en uno
                // abierto la diferencia es el margen de la proyección, y el
                // informe la declara en vez de disimularla.
                //
                // 🔴 `parciales` SE CUENTA POR ESTUDIANTE, no comparando el
                // mínimo del grado con su plan máximo: cada alumno tiene su
                // propio plan (las exoneraciones se lo recortan), así que
                // cruzar el mínimo de uno con el plan de otro inventaba huecos
                // donde solo había un exonerado.
                //
                // Desde el 24/09/2026 `parciales` cuenta a quien tiene alguna
                // COMPETENCIA pendiente (no solo un área entera sin nota), y
                // `pro_proyectados` a los promovidos cuya promoción todavía
                // depende de lo pendiente (certeza `proyectada`).
                'cobertura'   => self::COBERTURA_VACIA,
                // La misma cobertura y los `sin_datos`, POR SECCIÓN (24/09/2026).
                // Sin este desglose, `riesgo_filtrar_secciones()` recortaba la
                // lista pero dejaba la cobertura del GRADO entero: filtrar a una
                // sección decía «52 sin su plan completo» con 47 evaluados.
                'cobertura_seccion' => [],
            ];

            $fila = $this->componerFila($m, $notas[$mid] ?? [], $plan, $retorno, $final, $numeroActual);
            $sec  = (string) $m['seccion_nombre'];
            $porGrado[$gid]['cobertura_seccion'][$sec] ??= self::COBERTURA_VACIA;

            // «Evaluado» = tiene al menos un área con nivel de logro. Quien no
            // tiene ninguno no entra al denominador: decir que un alumno sin
            // notas es el 100 % de algo sería un dato falso, no ausente.
            if ($fila['situacion'] === SITUACION_SIN_DATOS) {
                // Sin notas porque AÚN NO PERTENECÍA al colegio (24/09/2026): se
                // incorporó después de este bimestre. No es un hueco de datos y
                // no hace parcial la proyección: va en su propio contador.
                if (isset($incorp[$mid])) {
                    $porGrado[$gid]['cobertura']['incorporados']++;
                    $porGrado[$gid]['cobertura_seccion'][$sec]['incorporados']++;
                    continue;
                }
                $porGrado[$gid]['sin_datos']++;
                $porGrado[$gid]['cobertura_seccion'][$sec]['sin_datos']++;
                continue;
            }

            $porGrado[$gid]['evaluados']++;
            $porSeccion[$gid][$sec] ??= ['seccion_nombre' => $sec, 'total' => 0];
            $porSeccion[$gid][$sec]['total']++;

            self::sumarCobertura($porGrado[$gid]['cobertura'], $fila);
            self::sumarCobertura($porGrado[$gid]['cobertura_seccion'][$sec], $fila);

            if ($fila['situacion'] === SITUACION_PENDIENTE) {
                $porGrado[$gid]['pendiente_final'][] = $fila;
                continue;
            }
            // SEGUIMIENTO pedagógico: cualquier evaluado con competencias bajas
            // (umbral único en `seguimiento_pedagogico()`), SEA CUAL SEA su
            // situación. Desde el 25/09/2026 incluye a los RR y PER, que salen
            // además en el bloque de riesgo; antes solo PRO y 1.º de primaria.
            // No entra a ninguna cifra de riesgo.
            if (seguimiento_pedagogico($fila['num_b'], $fila['num_c'], (string) $m['nivel_codigo'])) {
                $porGrado[$gid]['seguimiento'][] = $fila;
            }

            if (situacion_es_riesgo($fila['situacion'])) {
                $porGrado[$gid]['en_riesgo'][] = $fila;
                continue;
            }

            // PRO o promoción automática (1.º de primaria): NUNCA riesgo —decir
            // de un alumno de 1.º que no será promovido es sencillamente falso—.
            if (!$fila['automatica'] && $fila['certeza'] === CERTEZA_PROYECTADA) {
                $porGrado[$gid]['cobertura']['pro_proyectados']++;
                $porGrado[$gid]['cobertura_seccion'][$sec]['pro_proyectados']++;
            }
        }

        // Los que YA NO PERTENECEN al colegio y cursaron este bimestre
        // (24/09/2026): fuera del cálculo —su situación final la determina la
        // nueva institución—, pero contados, para que el total de la sección
        // cuadre con el que tuvo en el bimestre.
        foreach ($this->fueraDelColegio($periodoId) as $f) {
            $gid = (int) $f['grado_id'];
            if (!isset($porGrado[$gid])) {
                continue;
            }
            $k   = $f['tipo'] === 'retirado' ? 'retirados' : 'trasladados';
            $sec = (string) $f['seccion_nombre'];
            $porGrado[$gid]['cobertura'][$k] += (int) $f['n'];
            $porGrado[$gid]['cobertura_seccion'][$sec] ??= self::COBERTURA_VACIA;
            $porGrado[$gid]['cobertura_seccion'][$sec][$k] += (int) $f['n'];
        }

        foreach ($porGrado as $gid => &$g) {
            situacion_ordenar($g['en_riesgo']);
            situacion_ordenar($g['seguimiento']);

            $secs = $porSeccion[$gid] ?? [];
            ksort($secs, SORT_STRING);
            $g['por_seccion'] = array_values($secs);
        }
        unset($g);

        // Orden estable de los grados: nivel y luego número, como el resto del
        // sistema. Las claves se pierden a propósito (la vista itera).
        uasort($porGrado, static fn(array $a, array $b): int =>
            [$a['grado']['nivel_id'], $a['grado']['numero']]
            <=> [$b['grado']['nivel_id'], $b['grado']['numero']]);

        return array_values($porGrado);
    }

    /** Cobertura de una sección antes de contar a nadie. */
    private const COBERTURA_VACIA = ['min' => null, 'max' => 0, 'plan' => 0, 'parciales' => 0,
                                     'sin_datos' => 0, 'pro_proyectados' => 0,
                                     'incorporados' => 0, 'trasladados' => 0, 'retirados' => 0];

    /**
     * Suma un estudiante EVALUADO a una cobertura (la del grado o la de su
     * sección): mismo cálculo en los dos niveles, para que no puedan divergir.
     */
    private static function sumarCobertura(array &$cob, array $fila): void
    {
        $cob['min']  = $cob['min'] === null ? $fila['areas_evaluadas'] : min($cob['min'], $fila['areas_evaluadas']);
        $cob['max']  = max($cob['max'],  $fila['areas_evaluadas']);
        $cob['plan'] = max($cob['plan'], $fila['areas_plan']);
        if ($fila['pendientes'] > 0) {
            $cob['parciales']++;
        }
    }

    /**
     * La situación final de UNA sección, para su tutor. PUNTO ÚNICO del panel
     * (`/docente/tutoria/acompanamiento`) y de su card en `/docente/inicio`: si cada uno
     * recortara por su cuenta, la card podría decir otra cifra que el informe al
     * que lleva.
     *
     * Recorre TODOS los grados a propósito: un retorno de grado cuya matrícula
     * oficial está en esta sección se evalúa en otro grado, y `porGrado()` es
     * quien lo reubica aquí.
     *
     * @param  array $seccion fila de `SeccionModel::seccionesDelAnio()`
     * @return array{seccion:array, filtrado:array, stats:array}
     */
    public function deSeccion(int $periodoId, array $seccion): array
    {
        return riesgo_por_seccion($this->porGrado($periodoId), [$seccion])[0];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Composición de una fila
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Arma la fila de un estudiante: agrupa sus notas por área, aplica la regla
     * de `helpers.php` y deja listo el desglose.
     *
     * El DESGLOSE sale de las mismas filas que los conteos, así que no puede
     * contradecir a la situación de su propia fila. Lista las competencias NO
     * aprobatorias del nivel (`nota_es_aprobatoria()`), que son las que el
     * tutor tiene que atender.
     */
    private function componerFila(array $m, array $notas, array $plan, ?array $retorno, bool $periodoFinal, int $numeroActual): array
    {
        $nivel = (string) $m['nivel_codigo'];
        $grado = (int) $m['grado_numero'];
        $suPlan = $plan[(int) $m['matricula_id']] ?? [];

        $areas    = [];
        $lit      = ['AD' => 0, 'A' => 0, 'B' => 0, 'C' => 0];
        $desglose = [];

        foreach ($notas as $n) {
            $literal = nota_a_literal((int) $n['nota']);
            $aid     = (int) $n['area_id'];

            $areas[$aid] ??= ['id' => $aid, 'nombre' => (string) $n['area'], 'n' => 0, 'n_ab' => 0, 'n_b' => 0, 'n_c' => 0];
            $areas[$aid]['n']++;
            $areas[$aid][$literal === 'C' ? 'n_c' : ($literal === 'B' ? 'n_b' : 'n_ab')]++;

            if (isset($lit[$literal])) {
                $lit[$literal]++;
            }

            if (!nota_es_aprobatoria($literal, $nivel)) {
                $desglose[] = $n + ['literal' => $literal, 'arrastrada' => $n['periodo_numero'] < $numeroActual, 'efecto' => null];
            }
        }

        // Cada área lleva las competencias que su PLAN le dicta; las del plan
        // sin ninguna evaluada entran con `n = 0`. Un área con notas y sin plan
        // (no ocurre: medido 0 el 24/09) se toma con plan = lo evaluado.
        foreach ($suPlan as $aid => $p) {
            $areas[$aid] ??= ['id' => $aid, 'nombre' => $p['nombre'], 'n' => 0, 'n_ab' => 0, 'n_b' => 0, 'n_c' => 0];
            $areas[$aid]['plan'] = $p['plan'];
        }

        $a = situacion_final_proyectar(array_values($areas), $nivel, $grado, $periodoFinal);

        // Chip de EFECTO de cada fila (24/09/2026): del veredicto de su área, que
        // sale del mismo análisis que la sigla (`situacion_efecto_competencia()`).
        $veredicto = array_column($a['por_area'] ?? [], null, 'id');
        foreach ($desglose as &$d) {
            $ar = $veredicto[(int) $d['area_id']] ?? null;
            $d['efecto'] = $ar !== null ? situacion_efecto_competencia($ar, $d['literal'], $a) : null;
        }
        unset($d);

        $compPlan = 0;
        foreach ($areas as $ar) {
            $compPlan += max((int) $ar['n'], (int) ($ar['plan'] ?? $ar['n']));
        }

        return [
            'matricula_id'    => (int) $m['matricula_id'],
            'nombre_completo' => $m['apellido_paterno'] . ' ' . $m['apellido_materno'] . ', ' . $m['nombres'],
            'seccion_nombre'  => (string) $m['seccion_nombre'],
            'situacion'       => $a['situacion'],
            'motivo'          => $a['motivo'],
            'automatica'      => $a['automatica'],
            'final_de_ciclo'  => $a['final_de_ciclo'],
            // Cobertura (decisión del 23/09/2026): las áreas SIN ninguna
            // competencia evaluada no entran al cálculo, y el informe lo dice en
            // vez de disimularlo. En un bimestre abierto es lo normal.
            'areas_evaluadas' => $a['areas'],
            'areas_plan'      => $suPlan !== [] ? count($suPlan) : $a['areas'],
            // Cobertura en COMPETENCIAS (24/09/2026): un área a medias no está
            // evaluada. `pendientes` = las del plan sin nivel de logro.
            'competencias_plan' => $compPlan,
            // Cuántas de las evaluadas vienen de un bimestre ANTERIOR (último
            // nivel registrado, como el SIAGIE).
            'arrastradas'     => count(array_filter($notas, static fn(array $n): bool => $n['periodo_numero'] < $numeroActual)),
            'pendientes'      => $a['pendientes'],
            'areas_pendientes' => $a['areas_pendientes'],
            'certeza'         => $a['certeza'],
            'areas_c'         => $a['areas_c_mas'],
            'num_ad'          => $lit['AD'],
            'num_a'           => $lit['A'],
            'num_b'           => $lit['B'],
            'num_c'           => $lit['C'],
            'num_competencias' => count($notas),
            'detalle'         => $desglose,
            'retorno'         => $retorno,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consultas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Roster del periodo: una fila por estudiante, con su grado, nivel y
     * sección. Incluye a quien no tiene ninguna nota (sale como «sin datos»),
     * porque el denominador de la cobertura es el alumnado, no las notas.
     */
    private function rosterDelPeriodo(int $periodoId): array
    {
        return $this->query("
            SELECT m.id AS matricula_id,
                   p.apellido_paterno, p.apellido_materno, p.nombres,
                   s.id AS seccion_id, s.nombre AS seccion_nombre,
                   g.id AS grado_id, g.numero AS grado_numero, g.nombre_display AS grado_nombre,
                   n.id AS nivel_id, n.nombre AS nivel_nombre, n.codigo AS nivel_codigo
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            INNER JOIN secciones s   ON s.id = m.seccion_id
            INNER JOIN grados g      ON g.id = s.grado_id
            INNER JOIN niveles n     ON n.id = g.nivel_id
            INNER JOIN periodos per  ON per.anio_id = m.anio_id AND per.id = ?
            WHERE 1 = 1
              " . matriculas_vigentes('m') . "
              AND (" . self::ROSTER_SITUACION . ")
            ORDER BY n.id, g.numero, s.nombre, " . orden_alfabetico('p') . "
        ", [$periodoId, $periodoId]);
    }

    /**
     * Todas las notas que cuentan para la situación final, agrupadas por
     * matrícula. UNA consulta para el colegio entero: una por alumno serían
     * más de quinientas.
     *
     * Trae TODOS los literales (los conteos por área necesitan también los AD y
     * los A) y el docente de la carga DUEÑA del bloqueo, que es de quien depende
     * cada competencia pendiente.
     *
     * 🔴 ÚLTIMO NIVEL REGISTRADO, COMO EL SIAGIE (decisión del usuario,
     * 24/09/2026). La situación final se define con «el último nivel de logro
     * de cada competencia» (RVM 094-2020, 5.1.2.2 p. 3; RVM 048-2024, 5.1.1.3
     * p. 3). Por eso, en B1-B3, una competencia no evaluada en el bimestre
     * elegido toma su nivel del ÚLTIMO bimestre anterior en que se registró,
     * y la fila dice de cuál (`periodo`). Antes se usaban solo las notas del
     * bimestre elegido, y la proyección no era la del SIAGIE.
     *
     * ⚠️ EN EL PERIODO FINAL NO SE ARRASTRA (decisión del usuario): ahí manda
     * solo el último bimestre, igual que el logro anual de la boleta, y una
     * competencia sin nota queda PENDIENTE. La regla del periodo final exige
     * evaluarlas todas, así que con esa regla cumplida los dos criterios dan
     * lo mismo.
     *
     * El arrastre no cruza matrículas: sale de la misma `matricula_id` (un
     * cambio de sección la conserva; un retorno de grado se evalúa en la
     * matrícula anclada del bimestre).
     *
     * @return array<int, array<int, array>>  clave = matricula_id
     */
    private function notasPorMatricula(int $periodoId, bool $periodoFinal): array
    {
        $filas = $this->query("
            SELECT cal.matricula_id,
                   cal.competencia_id,
                   per.numero         AS periodo_numero,
                   per.nombre_display AS periodo,
                   cal.nota_numerica AS nota,
                   a.id      AS area_id,
                   a.nombre  AS area,
                   sa.nombre AS curso,
                   COALESCE(NULLIF(comp.nombre_corto, ''), comp.nombre_completo) AS competencia,
                   comp.codigo_minedu AS codigo,
                   p.apellido_paterno, p.apellido_materno, p.nombres
            FROM calificaciones cal
            -- Solo competencias BLOQUEADAS: una nota sin aprobar todavia no es
            -- un nivel de logro, es un borrador del docente.
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            INNER JOIN competencias comp ON comp.id = cal.competencia_id
            LEFT  JOIN subareas sa       ON sa.id   = comp.subarea_id
            INNER JOIN areas a           ON a.id    = COALESCE(sa.area_id, comp.area_id)
            -- El docente es informativo: LEFT, porque una carga sin usuario no
            -- puede impedir que se cuente la nota.
            LEFT  JOIN cargas_academicas ca ON ca.id = cal.carga_id
            LEFT  JOIN usuarios u           ON u.id  = ca.docente_id
            LEFT  JOIN personas p           ON p.id  = u.persona_id
            -- Bimestres del MISMO año hasta el elegido (solo el elegido si es el
            -- periodo final).
            INNER JOIN periodos per  ON per.id  = cal.periodo_id
            INNER JOIN periodos pact ON pact.id = ?
                                    AND per.anio_id = pact.anio_id
                                    AND per.numero <= pact.numero
            WHERE " . ($periodoFinal ? 'per.id = pact.id AND ' : '') . self::areasQueCuentan('cal.matricula_id') . ' AND ' . self::FILTRO_NOTAS . "
            -- Por competencia, primero el bimestre MÁS RECIENTE: el bucle se
            -- queda con esa fila y descarta las anteriores.
            ORDER BY a.orden, comp.orden, comp.id, per.numero DESC
        ", [$periodoId]);

        $out   = [];
        $visto = [];
        foreach ($filas as $f) {
            $mid = (int) $f['matricula_id'];
            $cid = (int) $f['competencia_id'];
            if (isset($visto[$mid][$cid])) {
                continue;
            }
            $visto[$mid][$cid] = true;

            $docente = trim($f['apellido_paterno'] . ' ' . $f['apellido_materno']
                          . ($f['nombres'] !== null ? ', ' . $f['nombres'] : ''));

            $out[$mid][] = [
                'periodo_numero' => (int) $f['periodo_numero'],
                'periodo'     => (string) $f['periodo'],
                'area_id'     => (int) $f['area_id'],
                'area'        => (string) $f['area'],
                // El "curso" es la subárea cuando existe (Álgebra, Física...);
                // si no, el área ya ES el curso (área-curso).
                'curso'       => $f['curso'] !== null ? (string) $f['curso'] : null,
                'competencia' => (string) $f['competencia'],
                'codigo'      => $f['codigo'] !== null ? (string) $f['codigo'] : null,
                'nota'        => (int) $f['nota'],
                'docente'     => $docente !== '' ? $docente : '—',
            ];
        }

        return $out;
    }

    /**
     * Cuántas áreas evaluables le TOCAN a cada estudiante — el denominador de la
     * cobertura.
     *
     * El universo es EL PLAN QUE SU SECCIÓN DICTA (cargas activas), no el
     * catálogo del nivel: es la misma decisión (D1) con la que se armó la boleta
     * de competencias completas, y por el mismo motivo —un área que el grado no
     * lleva es «no corresponde», no «no evaluado»—.
     *
     * 🔴 VA POR MATRÍCULA Y NO POR SECCIÓN, por las EXONERACIONES. Un área
     * exonerada no entra al cálculo de la situación final (la norma no la
     * cuenta, y el filtro de notas ya la descarta), así que si el denominador
     * fuese el de la sección, el exonerado aparecería eternamente con «7 de 8
     * áreas» y el informe declararía un hueco de datos donde solo hay una
     * exoneración. Medido: son los 2 alumnos de primaria exonerados de Educación
     * Religiosa y 1 de secundaria exonerado de Ética.
     *
     * 🔴 SE CUENTA POR COMPETENCIA, NO POR ÁREA (24/09/2026). La norma dice «la
     * mitad de las competencias DEL ÁREA», y en B1-B3 el docente elige qué
     * competencias evalúa: un área con 1 de 4 evaluadas no está «evaluada». Este
     * plan es el que usan las COTAS de `situacion_final_proyectar()` y la
     * cobertura. Medido en B2: 433 de 480 estudiantes tienen alguna pendiente.
     *
     * Una carga de ÁREA cubre todas sus competencias, también las de sus
     * subáreas; una de SUBÁREA, solo las suyas. ⚠️ El área se resuelve SIEMPRE
     * por `COALESCE(sa.area_id, comp.area_id)`: las competencias de subárea
     * tienen `area_id` NULL, y contar por esa columna las pierde en silencio.
     * Se descuentan las exoneraciones de ÁREA y de SUBÁREA —el mismo universo
     * que `FILTRO_NOTAS`—: una exonerada no es una pendiente.
     *
     * @return array<int, array<int, array{nombre:string, plan:int}>>
     *         clave = matricula_id → area_id
     */
    private function planPorMatricula(int $periodoId): array
    {
        $filas = $this->query("
            SELECT m.id AS matricula_id, a.id AS area_id, a.nombre AS area,
                   COUNT(DISTINCT comp.id) AS plan
            FROM matriculas m
            INNER JOIN periodos per ON per.anio_id = m.anio_id AND per.id = ?
            INNER JOIN cargas_academicas ca
                    ON ca.seccion_id = m.seccion_id
                   AND ca.estado     = 'activa'
            INNER JOIN competencias comp
                    ON (ca.subarea_id IS NOT NULL AND comp.subarea_id = ca.subarea_id)
                    OR (ca.subarea_id IS NULL
                        AND (comp.area_id = ca.area_id
                             OR comp.subarea_id IN (SELECT id FROM subareas WHERE area_id = ca.area_id)))
            LEFT  JOIN subareas sa ON sa.id = comp.subarea_id
            INNER JOIN areas a     ON a.id  = COALESCE(sa.area_id, comp.area_id)
            WHERE " . self::areasQueCuentan('m.id') . "
              AND NOT EXISTS (
                  SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = m.id
                    AND ex.revocado_en IS NULL
                    AND (ex.area_id = a.id OR ex.subarea_id = comp.subarea_id)
              )
            GROUP BY m.id, a.id, a.nombre
        ", [$periodoId]);

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['matricula_id']][(int) $f['area_id']] = [
                'nombre' => (string) $f['area'],
                'plan'   => (int) $f['plan'],
            ];
        }

        return $out;
    }

    /**
     * El `numero` del periodo y si es el FINAL de su año (mayor `numero`). Mismo
     * anclaje que el logro anual y la regla del periodo final: nunca el número 4
     * literal.
     *
     * @return array{0:int, 1:bool}
     */
    /**
     * Matrículas del año SIN ninguna nota hasta este bimestre y CON notas en uno
     * posterior: se incorporaron después (24/09/2026). Se ancla en el DATO, no
     * en `fecha_registro`: los bimestres se solapan, y hay matrículas
     * registradas dentro de las fechas de B1 cuya primera nota es de B2.
     *
     * @return array<int, true>  clave = matrícula
     */
    private function incorporadosDespues(int $periodoId): array
    {
        $filas = $this->query("
            SELECT m.id
            FROM matriculas m
            INNER JOIN periodos per ON per.anio_id = m.anio_id AND per.id = ?
            WHERE NOT EXISTS (
                    SELECT 1 FROM calificaciones c
                    INNER JOIN periodos p1 ON p1.id = c.periodo_id
                    WHERE c.matricula_id = m.id AND p1.numero <= per.numero)
              AND EXISTS (
                    SELECT 1 FROM calificaciones c
                    INNER JOIN periodos p2 ON p2.id = c.periodo_id
                    WHERE c.matricula_id = m.id AND p2.numero > per.numero)
        ", [$periodoId]);

        return array_fill_keys(array_map(static fn(array $f): int => (int) $f['id'], $filas), true);
    }

    /**
     * Trasladados y retirados que CURSARON este bimestre (tienen notas en él),
     * por grado, sección y tipo (decisión del usuario, 24/09/2026). Solo se
     * cuentan: no se calcula su situación final.
     *
     * Es la NEGACIÓN de `matriculas_vigentes()`, que sigue siendo el punto único
     * de quién «ya no pertenece» (`NOT (1 = 1 AND tipo NOT IN …)`).
     */
    private function fueraDelColegio(int $periodoId): array
    {
        return $this->query("
            SELECT m.tipo, s.grado_id, s.nombre AS seccion_nombre, COUNT(*) AS n
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            WHERE NOT (1 = 1 " . matriculas_vigentes('m') . ")
              AND EXISTS (SELECT 1 FROM calificaciones c
                          WHERE c.matricula_id = m.id AND c.periodo_id = ?)
            GROUP BY m.tipo, s.grado_id, s.nombre
        ", [$periodoId]);
    }

    private function datosPeriodo(int $periodoId): array
    {
        $f = $this->query("
            SELECT p.numero,
                   p.numero = (SELECT MAX(p2.numero) FROM periodos p2 WHERE p2.anio_id = p.anio_id) AS es_final
            FROM periodos p WHERE p.id = ?
        ", [$periodoId]);

        return [(int) ($f[0]['numero'] ?? 0), (bool) ($f[0]['es_final'] ?? false)];
    }

    /**
     * Grado, nivel y sección OFICIALES de cada matrícula operativa de un retorno
     * de grado, para reubicar su fila. UNA consulta, SIN filtrar `estado`:
     * activo o revertido, la operativa nunca es la identidad del estudiante
     * (mismo criterio que `matricula_documento()`).
     *
     * @return array<int, array>  clave = matrícula operativa
     */
    private function ubicacionOficialRetornos(): array
    {
        $out = [];
        foreach ($this->query("
            SELECT r.matricula_operativa_id,
                   s.id AS seccion_id, s.nombre AS seccion_nombre,
                   g.id AS grado_id, g.numero AS grado_numero, g.nombre_display AS grado_nombre,
                   n.id AS nivel_id, n.nombre AS nivel_nombre, n.codigo AS nivel_codigo
            FROM retornos_grado r
            INNER JOIN matriculas mo ON mo.id = r.matricula_oficial_id
            INNER JOIN secciones s   ON s.id  = mo.seccion_id
            INNER JOIN grados g      ON g.id  = s.grado_id
            INNER JOIN niveles n     ON n.id  = g.nivel_id
        ") as $r) {
            $out[(int) $r['matricula_operativa_id']] = [
                'seccion_id'     => (int) $r['seccion_id'],
                'seccion_nombre' => (string) $r['seccion_nombre'],
                'grado_id'       => (int) $r['grado_id'],
                'grado_numero'   => (int) $r['grado_numero'],
                'grado_nombre'   => (string) $r['grado_nombre'],
                'nivel_id'       => (int) $r['nivel_id'],
                'nivel_nombre'   => (string) $r['nivel_nombre'],
                'nivel_codigo'   => (string) $r['nivel_codigo'],
            ];
        }

        return $out;
    }
}
