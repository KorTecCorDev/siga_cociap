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
     * Filtro de QUÉ NOTAS entran al cálculo. Es otra pregunta que el roster, y
     * la norma la responde en el numeral 5.1.3 (puntos 9 a 11):
     *
     *  · Las COMPETENCIAS TRANSVERSALES **no se tienen en cuenta** para la
     *    situación final. (Además están duplicadas por alumno —una fila por cada
     *    carga que las registra—, así que colarlas inflaría los conteos sin que
     *    se note: 28 282 filas contra 13 308 pares en el II Bimestre.)
     *  · Las competencias organizadas en áreas curriculares **y los talleres SÍ**
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
     * Mismo universo que `OrdenMeritoModel::detalleCompetenciasRiesgo()`, del
     * que se toma tal cual. Lo que cambia es que aquí entran TODOS los
     * literales, no solo los de debajo de `NOTA_MIN_A`.
     */
    private const FILTRO_NOTAS = "
        cal.extraordinaria = 0
        AND (a.tipo NOT IN ('transversal', 'tutoria')
             OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "')
        AND NOT EXISTS (
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
     *                          en_riesgo:array, automatica:array, por_seccion:array}>
     */
    public function porGrado(int $periodoId): array
    {
        $roster   = $this->rosterDelPeriodo($periodoId);
        $notas    = $this->notasPorMatricula($periodoId);
        $plan     = $this->planPorMatricula($periodoId);
        $oficial  = $this->ubicacionOficialRetornos();

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
                'automatica'  => [],
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
                'cobertura'   => ['min' => null, 'max' => 0, 'plan' => 0, 'parciales' => 0],
            ];

            $fila = $this->componerFila($m, $notas[$mid] ?? [], $plan, $retorno);

            // «Evaluado» = tiene al menos un área con nivel de logro. Quien no
            // tiene ninguno no entra al denominador: decir que un alumno sin
            // notas es el 100 % de algo sería un dato falso, no ausente.
            if ($fila['situacion'] === SITUACION_SIN_DATOS) {
                $porGrado[$gid]['sin_datos']++;
                continue;
            }

            $sec = (string) $m['seccion_nombre'];
            $porGrado[$gid]['evaluados']++;
            $porSeccion[$gid][$sec] ??= ['seccion_nombre' => $sec, 'total' => 0];
            $porSeccion[$gid][$sec]['total']++;

            $cob = &$porGrado[$gid]['cobertura'];
            $cob['min']  = $cob['min'] === null ? $fila['areas_evaluadas'] : min($cob['min'], $fila['areas_evaluadas']);
            $cob['max']  = max($cob['max'],  $fila['areas_evaluadas']);
            $cob['plan'] = max($cob['plan'], $fila['areas_plan']);
            if ($fila['areas_evaluadas'] < $fila['areas_plan']) {
                $cob['parciales']++;
            }
            unset($cob);

            if ($fila['automatica']) {
                // 1.º de primaria: promoción automática. Se lista aparte, como
                // seguimiento pedagógico, y NUNCA como riesgo: decir de un
                // alumno de 1.º que no será promovido es sencillamente falso.
                if ($fila['num_c'] > 0 || $fila['num_b'] > 0) {
                    $porGrado[$gid]['automatica'][] = $fila;
                }
            } elseif (situacion_es_riesgo($fila['situacion'])) {
                $porGrado[$gid]['en_riesgo'][] = $fila;
            }
        }

        foreach ($porGrado as $gid => &$g) {
            situacion_ordenar($g['en_riesgo']);
            situacion_ordenar($g['automatica']);

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

    /**
     * La situación final de UNA sección, para su tutor. PUNTO ÚNICO del panel
     * (`/docente/tutoria/riesgo`) y de su card en `/docente/inicio`: si cada uno
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
    private function componerFila(array $m, array $notas, array $plan, ?array $retorno): array
    {
        $nivel = (string) $m['nivel_codigo'];
        $grado = (int) $m['grado_numero'];

        $areas    = [];
        $lit      = ['AD' => 0, 'A' => 0, 'B' => 0, 'C' => 0];
        $desglose = [];

        foreach ($notas as $n) {
            $literal = nota_a_literal((int) $n['nota']);
            $aid     = (int) $n['area_id'];

            $areas[$aid] ??= ['nombre' => (string) $n['area'], 'n' => 0, 'n_ab' => 0, 'n_b' => 0, 'n_c' => 0];
            $areas[$aid]['n']++;
            $areas[$aid][$literal === 'C' ? 'n_c' : ($literal === 'B' ? 'n_b' : 'n_ab')]++;

            if (isset($lit[$literal])) {
                $lit[$literal]++;
            }

            if (!nota_es_aprobatoria($literal, $nivel)) {
                $desglose[] = $n + ['literal' => $literal];
            }
        }

        $a = situacion_final_analisis(array_values($areas), $nivel, $grado);

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
            'areas_plan'      => $plan[(int) $m['matricula_id']] ?? $a['areas'],
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
     * @return array<int, array<int, array>>  clave = matricula_id
     */
    private function notasPorMatricula(int $periodoId): array
    {
        $filas = $this->query("
            SELECT cal.matricula_id,
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
            WHERE cal.periodo_id = ?
              AND " . self::FILTRO_NOTAS . "
            ORDER BY a.orden, comp.orden, comp.id
        ", [$periodoId]);

        $out = [];
        foreach ($filas as $f) {
            $docente = trim($f['apellido_paterno'] . ' ' . $f['apellido_materno']
                          . ($f['nombres'] !== null ? ', ' . $f['nombres'] : ''));

            $out[(int) $f['matricula_id']][] = [
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
     * Solo descuenta la exoneración por ÁREA completa: una de SUBÁREA deja vivas
     * las demás competencias del área, que sigue contando.
     *
     * @return array<int, int>  clave = matricula_id
     */
    private function planPorMatricula(int $periodoId): array
    {
        $filas = $this->query("
            SELECT m.id AS matricula_id, COUNT(DISTINCT a.id) AS areas
            FROM matriculas m
            INNER JOIN periodos per ON per.anio_id = m.anio_id AND per.id = ?
            INNER JOIN cargas_academicas ca
                    ON ca.seccion_id = m.seccion_id
                   AND ca.estado     = 'activa'
            INNER JOIN areas a
                    ON a.id = COALESCE(ca.area_id, (SELECT sa.area_id FROM subareas sa WHERE sa.id = ca.subarea_id))
            WHERE (a.tipo NOT IN ('transversal', 'tutoria')
                   OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "')
              AND NOT EXISTS (
                  SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = m.id
                    AND ex.revocado_en IS NULL
                    AND ex.area_id = a.id
              )
            GROUP BY m.id
        ", [$periodoId]);

        return array_column($filas, 'areas', 'matricula_id');
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
