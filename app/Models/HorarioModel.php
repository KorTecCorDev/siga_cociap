<?php

namespace App\Models;

/**
 * HorarioModel
 *
 * PUNTO ÚNICO del horario semanal: las sesiones y el ARMADO de la grilla de
 * doble entrada (días en columnas, franjas en filas).
 *
 * ⚠️ NACIÓ EL 24/08/2026 POR EXTRACCIÓN, SIN CAMBIO FUNCIONAL. Toda esta lógica
 * vivía dentro de `Docente\PanelController` —la consulta en un método PRIVADO
 * (`getHorario`) y el armado de la grilla inline en `horarioImprimir()`—, así
 * que era inalcanzable desde cualquier otra pantalla. Al abrir el horario POR
 * SECCIÓN para los usuarios de dirección, la única alternativa era copiar ~130
 * líneas: el patrón con el que ya divergieron cuatro reglas en este repositorio.
 *
 * Sigue el precedente de `BoletaModel::armar()`: el modelo también ARMA, no solo
 * consulta. La vista recibe la estructura lista y solo la pinta.
 *
 * Las dos vistas que lo consumen difieren únicamente en el EJE:
 *   - horario del DOCENTE  → cada celda se identifica por sección + área.
 *   - horario de la SECCIÓN → cada celda se identifica por docente + área.
 * De ahí que `armarGrilla()` reciba por cuál agrupar.
 */
class HorarioModel extends BaseModel
{
    protected string $table = 'sesiones_horario';

    /** Días fijos: la BD no maneja fin de semana. */
    public const DIAS = [
        'lunes'     => 'Lunes',
        'martes'    => 'Martes',
        'miercoles' => 'Miércoles',
        'jueves'    => 'Jueves',
        'viernes'   => 'Viernes',
    ];

    /** Duración por defecto de la hora académica si falta la configuración. */
    private const DURACION_FALLBACK = 45;

    // ── Datos ────────────────────────────────────────────────────

    /**
     * Sesiones del horario de un DOCENTE, ordenadas por día y hora.
     *
     * ⚠️ `sesiones_horario.docente_id` referencia `usuarios.id` (no `personas`).
     *
     * Movida VERBATIM desde `Docente\PanelController::getHorario()`. El
     * `COALESCE(ca.area_id, sa.area_id)` es obligatorio: una carga puede colgar
     * de una SUBÁREA y entonces `ca.area_id` es NULL — un join directo pierde
     * esas filas en silencio (ver `docs/modulos/horarios.md`).
     */
    public function getSesionesDocente(int $docenteId): array
    {
        return $this->query("
            SELECT bh.dia_semana, bh.numero_bloque, bh.hora_inicio, bh.hora_fin,
                   s.id AS seccion_id,
                   g.nombre_display AS grado_nombre, g.numero AS grado_numero,
                   n.codigo AS nivel_codigo,
                   s.nombre AS seccion_nombre,
                   sh.docente_id,
                   CASE WHEN s.es_unidocente = 1 THEN a.nombre
                        ELSE COALESCE(sa.nombre, a.nombre) END AS area_nombre
            FROM sesiones_horario sh
            INNER JOIN bloques_horario bh ON bh.id = sh.bloque_id
            INNER JOIN cargas_academicas ca ON ca.id = sh.carga_id AND ca.estado = 'activa'
            INNER JOIN secciones s ON s.id = sh.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN niveles n   ON n.id = g.nivel_id
            LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
            LEFT  JOIN areas a     ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE sh.docente_id = ?
            ORDER BY FIELD(bh.dia_semana,'lunes','martes','miercoles','jueves','viernes'),
                     bh.hora_inicio, bh.hora_fin
        ", [$docenteId]);
    }

    /**
     * Sesiones del horario de una SECCIÓN, ordenadas por día y hora.
     *
     * Misma consulta que la del docente salvo el filtro y el añadido del nombre
     * del docente, que es lo que identifica cada celda en este eje. El nombre
     * sale de `personas` vía `usuarios`, igual que en el resto del sistema.
     */
    public function getSesionesSeccion(int $seccionId): array
    {
        return $this->query("
            SELECT bh.dia_semana, bh.numero_bloque, bh.hora_inicio, bh.hora_fin,
                   s.id AS seccion_id,
                   g.nombre_display AS grado_nombre, g.numero AS grado_numero,
                   n.codigo AS nivel_codigo,
                   s.nombre AS seccion_nombre,
                   sh.docente_id,
                   CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres)
                       AS docente_nombre,
                   CASE WHEN s.es_unidocente = 1 THEN a.nombre
                        ELSE COALESCE(sa.nombre, a.nombre) END AS area_nombre
            FROM sesiones_horario sh
            INNER JOIN bloques_horario bh ON bh.id = sh.bloque_id
            INNER JOIN cargas_academicas ca ON ca.id = sh.carga_id AND ca.estado = 'activa'
            INNER JOIN secciones s ON s.id = sh.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN niveles n   ON n.id = g.nivel_id
            INNER JOIN usuarios u  ON u.id = sh.docente_id
            INNER JOIN personas p  ON p.id = u.persona_id
            LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
            LEFT  JOIN areas a     ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE sh.seccion_id = ?
            ORDER BY FIELD(bh.dia_semana,'lunes','martes','miercoles','jueves','viernes'),
                     bh.hora_inicio, bh.hora_fin
        ", [$seccionId]);
    }

    /**
     * Todo lo que necesita el DOCUMENTO del horario de una sección
     * (`director/horario-seccion.php`): la grilla, la cabecera de la sección, el
     * año y el sello del Director EBR. NULL si la sección no existe.
     *
     * PUNTO ÚNICO desde el 28/09/2026: lo usan Dirección
     * (`Director\CargaAcademicaController::horarioSeccion`) y el auxiliar
     * académico para sus secciones (`Auxiliar\PanelController::horario`). Antes
     * vivía en el controlador de Dirección, y darle el rol al auxiliar allí le
     * habría abierto también el listado de cargas de todo el colegio.
     */
    public function documentoSeccion(int $seccionId): ?array
    {
        $seccion = (new CargaAcademicaModel())->findSeccion($seccionId);
        if (!$seccion) {
            return null;
        }

        $anio = $this->queryOne(
            "SELECT id, anio FROM anios_academicos WHERE estado = 'activo' LIMIT 1"
        );

        $grilla = $this->armarGrilla(
            $this->getSesionesSeccion($seccionId),
            $this->duracionHoraAcademica($anio ? (int) $anio['id'] : null),
            'docente'
        );

        return array_merge($grilla, [
            'seccion' => [
                'id'             => $seccionId,
                'grado_nombre'   => $seccion['grado_nombre'],
                'seccion_nombre' => $seccion['seccion_nombre'],
                'nivel_nombre'   => $seccion['nivel_nombre'] ?? '',
                // findSeccion devuelve el tutor en TRES columnas sueltas, no
                // compuesto: se arma aqui (vacio si la seccion no tiene tutor).
                'tutor_nombre'   => trim($seccion['tutor_id'] ?? null
                    ? ($seccion['tutor_paterno'] ?? '') . ' ' . ($seccion['tutor_materno'] ?? '')
                      . ', ' . ($seccion['tutor_nombres'] ?? '')
                    : ''),
            ],
            'anio'        => $anio,
            'directorEbr' => $anio
                ? (new DirectorEbrModel())->getVigenteEnFecha((int) $anio['id'])
                : null,
        ]);
    }

    /**
     * Duración de la hora académica del año, en minutos. Nunca se hardcodea:
     * sale de `configuracion_horario`. Fallback 45 si falta o viene en 0/NULL.
     */
    public function duracionHoraAcademica(?int $anioId): int
    {
        if (!$anioId) {
            return self::DURACION_FALLBACK;
        }

        $cfg = $this->queryOne(
            "SELECT duracion_hora_min FROM configuracion_horario WHERE anio_id = ? LIMIT 1",
            [$anioId]
        );

        return (int) ($cfg['duracion_hora_min'] ?? 0) ?: self::DURACION_FALLBACK;
    }

    // ── Armado de la grilla ──────────────────────────────────────

    /**
     * Convierte una lista de sesiones en la estructura que pinta la grilla.
     *
     * @param array  $sesiones      salida de getSesionesDocente/getSesionesSeccion.
     * @param int    $duracionHora  minutos de la hora académica.
     * @param string $eje           'seccion' (horario del docente) | 'docente'
     *                              (horario de la sección). Define por qué se
     *                              agrupa el color y qué rotula la leyenda.
     *
     * @return array{
     *     dias: array, segmentos: array, startAt: array, covered: array,
     *     leyenda: array, totalHoras: int
     * }
     */
    public function armarGrilla(array $sesiones, int $duracionHora, string $eje = 'seccion'): array
    {
        $vacia = [
            'dias'       => self::DIAS,
            'segmentos'  => [],
            'startAt'    => [],
            'covered'    => [],
            'leyenda'    => [],
            'totalHoras' => 0,
        ];

        if (empty($sesiones)) {
            return $vacia;
        }

        // Eje de tiempo por PUNTOS DE CORTE: se reúnen todos los inicios y fines
        // distintos y se ordenan. Cada par consecutivo define un segmento (fila
        // mínima). Un bloque que abarca varios segmentos se fusiona luego con
        // rowspan, de modo que un bloque largo de un día y dos bloques cortos de
        // otro queden ALINEADOS en el mismo eje.
        $puntosSet = [];
        foreach ($sesiones as $s) {
            $puntosSet[$s['hora_inicio']] = true;
            $puntosSet[$s['hora_fin']]    = true;
        }
        $puntos = array_keys($puntosSet);
        sort($puntos, SORT_STRING); // "HH:MM:SS" ordena cronológicamente como texto
        $indice = array_flip($puntos);

        // Color por GRUPO: la misma materia dictada por el mismo interlocutor
        // (sección o docente, según el eje) comparte color aunque esté repartida
        // en más de una carga o en varios bloques. Primero se agrupa y ORDENA;
        // el color se asigna en ese orden para que la leyenda quede correlativa.
        $grupos     = [];
        $totalHoras = 0;

        foreach ($sesiones as $s) {
            $key = $this->claveGrupo($s, $eje);

            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'key'            => $key,
                    'nivel_codigo'   => $s['nivel_codigo'],
                    'grado_numero'   => (int) $s['grado_numero'],
                    'seccion_nombre' => $s['seccion_nombre'],
                    'seccion'        => $s['grado_nombre'] . ' ' . $s['seccion_nombre'],
                    'docente'        => $s['docente_nombre'] ?? '',
                    'area'           => $s['area_nombre'],
                    'horas'          => 0,
                ];
            }

            // Horas académicas del bloque: round(duración / hora académica).
            // Con hora de 45 min: 45→1, 90→2, 180→4; un doble atípico de 95→2.
            // Contar bloques sobreestimaba los dobles.
            $minutos     = (int) round(
                (strtotime($s['hora_fin']) - strtotime($s['hora_inicio'])) / 60
            );
            $horasBloque = (int) round($minutos / $duracionHora);

            $grupos[$key]['horas'] += $horasBloque;
            $totalHoras            += $horasBloque;
        }

        // Orden: primaria antes que secundaria, luego grado 1→N, sección y materia.
        $nivelOrden = ['prim' => 0, 'sec' => 1];
        usort($grupos, function ($a, $b) use ($nivelOrden) {
            return [$nivelOrden[$a['nivel_codigo']] ?? 9, $a['grado_numero'],
                    $a['seccion_nombre'], $a['area']]
               <=> [$nivelOrden[$b['nivel_codigo']] ?? 9, $b['grado_numero'],
                    $b['seccion_nombre'], $b['area']];
        });

        // El tono se calcula con el ángulo áureo (137.508°): cada grupo recibe un
        // color claramente distinto y SIN repetir, sea cual sea la cantidad de
        // grupos. Saturación/luminosidad fijas para mantener fondos claros
        // legibles con texto oscuro.
        $colorPorGrupo = [];
        $leyenda       = [];
        foreach ($grupos as $i => $g) {
            $hue   = (int) round(fmod($i * 137.508, 360));
            $color = "hsl({$hue}, 70%, 82%)";

            $colorPorGrupo[$g['key']] = $color;
            $leyenda[] = [
                'color'   => $color,
                'nivel'   => $g['nivel_codigo'],
                'seccion' => $g['seccion'],
                'docente' => $g['docente'],
                'areas'   => [$g['area']],
                'horas'   => $g['horas'],
            ];
        }

        // Ubicación de cada bloque en el eje de segmentos, con su rowspan.
        // $startAt[dia][fila] = celda que ARRANCA en esa fila (con rowspan).
        // $covered[dia][fila] = fila ocupada (inicio o continuación) → en las
        // filas continuadas no se dibuja <td>.
        $startAt = [];
        $covered = [];
        foreach ($sesiones as $s) {
            $dia  = $s['dia_semana'];
            $r0   = $indice[$s['hora_inicio']];
            $r1   = $indice[$s['hora_fin']];
            $span = $r1 - $r0;

            if ($span < 1) {
                continue; // salvaguarda: fin <= inicio (no debería ocurrir)
            }

            $startAt[$dia][$r0] = [
                'area'    => $s['area_nombre'],
                'seccion' => $s['grado_nombre'] . ' ' . $s['seccion_nombre'],
                'docente' => $s['docente_nombre'] ?? '',
                'nivel'   => $s['nivel_codigo'],
                'color'   => $colorPorGrupo[$this->claveGrupo($s, $eje)],
                'rowspan' => $span,
            ];

            for ($r = $r0; $r < $r1; $r++) {
                $covered[$dia][$r] = true;
            }
        }

        // Filas de la grilla: un segmento por cada par de puntos consecutivos.
        // Los huecos entre bloques (recreos) salen como filas vacías.
        $segmentos = [];
        $n = count($puntos);
        for ($i = 0; $i < $n - 1; $i++) {
            $segmentos[] = ['inicio' => $puntos[$i], 'fin' => $puntos[$i + 1]];
        }

        return [
            'dias'       => self::DIAS,
            'segmentos'  => $segmentos,
            'startAt'    => $startAt,
            'covered'    => $covered,
            'leyenda'    => array_values($leyenda),
            'totalHoras' => $totalHoras,
        ];
    }

    /** Clave de agrupación de color según el eje de la grilla. */
    private function claveGrupo(array $sesion, string $eje): string
    {
        $ancla = $eje === 'docente'
            ? (string) ($sesion['docente_id'] ?? '')
            : (string) $sesion['seccion_id'];

        return $ancla . '|' . $sesion['area_nombre'];
    }
}
