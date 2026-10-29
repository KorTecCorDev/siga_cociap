<?php

namespace App\Models;

/**
 * SituacionEstudianteModel — CHIPS DE SITUACIÓN de las filas de un bimestre
 * (28/10/2026). PUNTO ÚNICO de la pregunta «¿qué marca lleva este estudiante en
 * la lista de esta sección y este bimestre?» para las vistas y documentos de
 * SOLO LECTURA (registro auxiliar, historial del docente, /consulta-notas).
 *
 * El catálogo (texto, clase, título) vive en `SITUACIONES_ESTUDIANTE`
 * (helpers.php) y se pinta con `shared/_situacion-chips.php`. Aquí solo se
 * decide QUÉ claves le tocan a cada fila, en este orden:
 *
 *  1. `trasladado` / `retirado` — el `tipo` de la matrícula (situación de hoy:
 *     la BD no guarda la fecha de salida).
 *  2. `retorno` — la matrícula OFICIAL de un retorno de grado en un bimestre de
 *     su TRAMO (`RetornoGradoModel::oficialesEnTramo`): ese bimestre lo cursó en
 *     la operativa, que en las vistas de lectura no sale nunca (decisión del
 *     usuario, 28/10/2026); sus datos se leen de allí por esta fila.
 *  3. `cambio_viene` — sección DESTINO de un cambio vigente, desde el bimestre
 *     del cambio: «Viene de 4° A» (el último cambio que la trajo).
 *  4. `cambio_paso` — sección ORIGEN de un cambio vigente, en los bimestres
 *     anteriores al cambio: «Pasó a 4° B» (el primer cambio que la sacó).
 *
 * Un cambio REVERTIDO no cuenta, como en `CambioSeccionModel::sqlSeccionDelPeriodo`.
 */
class SituacionEstudianteModel extends BaseModel
{
    protected string $table = 'matriculas';

    private RetornoGradoModel $retorno;

    public function __construct()
    {
        parent::__construct();
        $this->retorno = new RetornoGradoModel();
    }

    /**
     * Añade a cada fila:
     *  - `situaciones`: lista de ['clave' => string, 'dato' => ?string] (vacía si
     *    no lleva chip);
     *  - `cursado_en`: la sección donde CURSÓ el bimestre la oficial de un retorno
     *    en su tramo («1° B»), o null. Las vistas funden ahí las celdas de
     *    criterios: el logro vino de otra carga, con otros criterios.
     * Las filas deben traer `matricula_id`; el resto no se toca.
     *
     * @param  array<int, array> $filas
     * @return array<int, array>
     */
    public function anotar(array $filas, int $seccionId, int $periodoId): array
    {
        if ($filas === []) {
            return $filas;
        }
        $ids    = array_map(static fn(array $f): int => (int) $f['matricula_id'], $filas);
        $mapa   = $this->deMatriculas($ids, $seccionId, $periodoId);
        $tramos = $this->retorno->oficialesEnTramo($ids, $periodoId);
        foreach ($filas as &$f) {
            $mid = (int) $f['matricula_id'];
            $f['situaciones'] = $mapa[$mid] ?? [];
            $f['cursado_en']  = $tramos[$mid]['seccion'] ?? null;
        }
        unset($f);
        return $filas;
    }

    /**
     * [matricula_id => [['clave' => …, 'dato' => …], …]] de las matrículas dadas,
     * en la sección y el bimestre dados. Solo trae las que llevan algún chip.
     *
     * @param  int[] $matriculaIds
     * @return array<int, list<array{clave: string, dato: ?string}>>
     */
    public function deMatriculas(array $matriculaIds, int $seccionId, int $periodoId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $matriculaIds))));
        if ($ids === []) {
            return [];
        }
        $in     = implode(',', array_fill(0, count($ids), '?'));
        $numero = $this->retorno->numeroPeriodo($periodoId);
        $out    = [];

        // 1. Salida de la matrícula.
        foreach ($this->query("
            SELECT id, tipo FROM matriculas
            WHERE id IN ({$in}) AND tipo IN ('trasladado', 'retirado')
        ", $ids) as $r) {
            $out[(int) $r['id']][] = ['clave' => (string) $r['tipo'], 'dato' => null];
        }

        // 2. Oficial de un retorno en un bimestre de su tramo (punto único del retorno).
        foreach (array_keys($this->retorno->oficialesEnTramo($ids, $periodoId)) as $oficial) {
            $out[$oficial][] = ['clave' => 'retorno', 'dato' => null];
        }

        // 3 y 4. Cambios de sección vigentes que tocan ESTA sección.
        $viene = [];
        $paso  = [];
        foreach ($this->query("
            SELECT cs.matricula_id, cs.seccion_origen_id, cs.seccion_destino_id, p.numero,
                   CONCAT(go.nombre_display, ' ', so.nombre) AS origen,
                   CONCAT(gd.nombre_display, ' ', sd.nombre) AS destino
            FROM cambios_seccion cs
            INNER JOIN periodos  p  ON p.id  = cs.periodo_id
            INNER JOIN secciones so ON so.id = cs.seccion_origen_id
            INNER JOIN grados    go ON go.id = so.grado_id
            INNER JOIN secciones sd ON sd.id = cs.seccion_destino_id
            INNER JOIN grados    gd ON gd.id = sd.grado_id
            WHERE cs.estado = 'vigente'
              AND cs.matricula_id IN ({$in})
              AND (cs.seccion_origen_id = ? OR cs.seccion_destino_id = ?)
            ORDER BY p.numero, cs.id
        ", array_merge($ids, [$seccionId, $seccionId])) as $r) {
            $mid = (int) $r['matricula_id'];
            if ((int) $r['seccion_destino_id'] === $seccionId && (int) $r['numero'] <= $numero) {
                $viene[$mid] = (string) $r['origen'];            // el último gana
            }
            if ((int) $r['seccion_origen_id'] === $seccionId && (int) $r['numero'] > $numero) {
                $paso[$mid] ??= (string) $r['destino'];          // el primero gana
            }
        }
        foreach ($viene as $mid => $seccion) {
            $out[$mid][] = ['clave' => 'cambio_viene', 'dato' => $seccion];
        }
        foreach ($paso as $mid => $seccion) {
            $out[$mid][] = ['clave' => 'cambio_paso', 'dato' => $seccion];
        }

        return $out;
    }
}
