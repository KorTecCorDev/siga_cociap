<?php

namespace App\Models;

/**
 * NominaDocenteModel — la NÓMINA DE DOCENTES del año activo (28/09/2026):
 * datos de contacto, áreas × secciones que dicta cada docente y su tutoría.
 * La imprimen admin, RA y Dirección (todo el colegio) y el auxiliar académico
 * (solo los docentes de sus secciones). Ver docs/modulos/auxiliares.md (F4b).
 *
 * Qué cuenta como «dicta»: las cargas ACTIVAS del año activo, salvo las de
 * áreas `transversal` (modelo viejo, inactivas) y `tutoria` (la carga TOE es
 * siempre del tutor: la cubre la columna Tutoría, que sale de
 * `secciones.tutor_id`). Los talleres SÍ cuentan: se dictan.
 */
class NominaDocenteModel extends BaseModel
{
    protected string $table = 'cargas_academicas';

    /**
     * Documento agrupado por nivel y, dentro de cada nivel, por docente en orden
     * alfabético. Un docente con cargas en dos niveles sale en los dos bloques,
     * cada uno con las cargas de ese nivel (decisión del usuario, 28/09/2026).
     *
     * $seccionIds = null → todo el colegio.
     * $seccionIds = [...] → solo los docentes que dictan o son tutores en alguna
     *                       de esas secciones, pero con TODAS sus cargas
     *                       (decisión del usuario, 28/09/2026).
     *
     * Cada docente: nombre, dni, telefono, correo,
     *   cargas  = [['areas' => 'Matemática (Álgebra, Aritmética)', 'secciones' => '1.° A, 1.° B'], …]
     *             (las secciones con exactamente las mismas áreas van en una línea),
     *   tutoria = '2.° A' o ''.
     *
     * @return array<int, array{nombre:string, docentes:array}> clave = nivel_id
     */
    public function documento(?array $seccionIds = null): array
    {
        $filas = $this->filas();

        if ($seccionIds !== null) {
            $ids      = array_map('intval', $seccionIds);
            $docentes = [];
            foreach ($filas as $f) {
                if (in_array((int) $f['seccion_id'], $ids, true)) {
                    $docentes[(int) $f['docente_id']] = true;
                }
            }
            $filas = array_values(array_filter($filas, static fn($f) => isset($docentes[(int) $f['docente_id']])));
        }

        // Las filas llegan ordenadas (nivel, docente, grado, sección, área,
        // subárea): los arrays se llenan en ese orden y no hay que reordenar.
        $niveles = [];
        foreach ($filas as $f) {
            $nid = (int) $f['nivel_id'];
            $did = (int) $f['docente_id'];
            $sid = (int) $f['seccion_id'];
            $niveles[$nid] ??= ['nombre' => $f['nivel_nombre'], 'docentes' => []];
            $d = &$niveles[$nid]['docentes'][$did];
            $d ??= [
                'nombre'    => $f['apellido_paterno'] . ' ' . $f['apellido_materno'] . ', ' . $f['nombres'],
                'dni'       => (string) $f['dni'],
                'telefono'  => (string) ($f['telefono'] ?? ''),
                'correo'    => (string) ($f['correo'] ?? ''),
                'secciones' => [],   // sid => ['etiqueta', 'areas' => [área => [subáreas]]]
                'tutoria'   => [],
            ];
            $etiqueta = trim($f['grado_nombre'] . ' ' . $f['seccion_nombre']);
            if ((int) $f['es_tutoria'] === 1) {
                $d['tutoria'][] = $etiqueta;
            } else {
                $d['secciones'][$sid] ??= ['etiqueta' => $etiqueta, 'areas' => []];
                $subs = &$d['secciones'][$sid]['areas'][$f['area_nombre']];
                $subs ??= [];
                if ($f['subarea_nombre'] !== null) {
                    $subs[] = $f['subarea_nombre'];
                }
                unset($subs);
            }
            unset($d);
        }

        foreach ($niveles as &$nivel) {
            foreach ($nivel['docentes'] as &$d) {
                $d['cargas']  = self::agruparCargas($d['secciones']);
                $d['tutoria'] = implode(', ', $d['tutoria']);
                unset($d['secciones']);
            }
            unset($d);
            $nivel['docentes'] = array_values($nivel['docentes']);
        }
        unset($nivel);

        return $niveles;
    }

    /**
     * Una línea por combinación de áreas: las secciones donde el docente dicta
     * exactamente lo mismo se juntan («Inglés · 1.° A, 1.° B, 2.° A»). Así el
     * especialista sale en una línea y el docente de aula, en otra con su sección.
     */
    private static function agruparCargas(array $secciones): array
    {
        $lineas = [];
        foreach ($secciones as $s) {
            $areas = [];
            foreach ($s['areas'] as $area => $subs) {
                $subs    = array_values(array_unique($subs));
                $areas[] = $subs ? $area . ' (' . implode(', ', $subs) . ')' : $area;
            }
            $clave = implode('; ', $areas);
            $lineas[$clave] ??= ['areas' => $clave, 'secciones' => []];
            $lineas[$clave]['secciones'][] = $s['etiqueta'];
        }
        return array_map(
            static fn($l) => ['areas' => $l['areas'], 'secciones' => implode(', ', $l['secciones'])],
            array_values($lineas)
        );
    }

    /**
     * Filas planas: una por carga (área/subárea en una sección) y una por
     * tutoría, del año activo, ordenadas para el documento.
     */
    private function filas(): array
    {
        return $this->query("
            SELECT x.*
            FROM (
                SELECT u.id AS docente_id,
                       p.apellido_paterno, p.apellido_materno, p.nombres,
                       p.dni, p.telefono, p.correo,
                       n.id AS nivel_id, n.nombre AS nivel_nombre,
                       g.numero AS grado_numero, g.nombre_display AS grado_nombre,
                       s.id AS seccion_id, s.nombre AS seccion_nombre,
                       COALESCE(a_dir.nombre, a_via.nombre) AS area_nombre,
                       COALESCE(a_dir.orden, a_via.orden)   AS area_orden,
                       sa.nombre AS subarea_nombre,
                       sa.orden  AS subarea_orden,
                       0 AS es_tutoria
                FROM cargas_academicas ca
                INNER JOIN anios_academicos an ON an.id = ca.anio_id AND an.estado = 'activo'
                INNER JOIN usuarios u  ON u.id = ca.docente_id
                INNER JOIN personas p  ON p.id = u.persona_id
                INNER JOIN secciones s ON s.id = ca.seccion_id
                INNER JOIN grados g    ON g.id = s.grado_id
                INNER JOIN niveles n   ON n.id = g.nivel_id
                LEFT  JOIN areas a_dir ON a_dir.id = ca.area_id
                LEFT  JOIN subareas sa ON sa.id    = ca.subarea_id
                LEFT  JOIN areas a_via ON a_via.id = sa.area_id
                WHERE ca.estado = 'activa'
                  AND COALESCE(a_dir.tipo, a_via.tipo) NOT IN ('transversal', 'tutoria')

                UNION ALL

                SELECT u.id, p.apellido_paterno, p.apellido_materno, p.nombres,
                       p.dni, p.telefono, p.correo,
                       n.id, n.nombre, g.numero, g.nombre_display, s.id, s.nombre,
                       NULL, NULL, NULL, NULL,
                       1
                FROM secciones s
                INNER JOIN anios_academicos an ON an.id = s.anio_id AND an.estado = 'activo'
                INNER JOIN usuarios u  ON u.id = s.tutor_id
                INNER JOIN personas p  ON p.id = u.persona_id
                INNER JOIN grados g    ON g.id = s.grado_id
                INNER JOIN niveles n   ON n.id = g.nivel_id
            ) x
            ORDER BY x.nivel_id, " . orden_alfabetico('x') . ", x.docente_id,
                     x.grado_numero, x.seccion_nombre, x.area_orden, x.subarea_orden
        ");
    }
}
