<?php

namespace App\Models;

/**
 * NotaExternaModel — PUNTO ÚNICO de las notas del COLEGIO DE ORIGEN.
 *
 * REGLA DEL COLEGIO (decidida el 10/09/2026): el Informe de Progreso que emite
 * el COCIAP tras un traslado lleva **solo** las calificaciones cursadas AQUÍ.
 * Lo que el estudiante trae de su colegio anterior es INFORMATIVO, y su público
 * son los DOCENTES con carga en su sección, que necesitan saber con qué llega.
 *
 * ⚠️ POR ESO ESTA TABLA NO LA LEE `BoletaModel`, Y NO DEBE LEERLA. No es un
 * mecanismo muerto: que no llegue a la boleta ES el comportamiento pedido. El
 * caso contrario —notas NUESTRAS que no se registraron a tiempo— va por otro
 * camino distinto: la calificación extraordinaria, que sí entra a boleta y
 * SIAGIE. Son dos mecanismos con destinos opuestos; no unificarlos.
 *
 * Los nombres de área, competencia y periodo van en TEXTO LIBRE porque el plan
 * curricular del colegio de origen no tiene por qué coincidir con el nuestro.
 * `area_id` (migración 057) es un mapeo OPCIONAL que solo sirve para resaltarle
 * a cada docente las filas de su propia carga.
 */
class NotaExternaModel extends BaseModel
{
    protected string $table = 'notas_externas';

    public const LITERALES = ['AD', 'A', 'B', 'C'];

    /**
     * Largo máximo de cada texto, IGUAL al de su columna. El `sql_mode` del
     * servidor no es estricto: lo que pase de aquí MariaDB lo recorta en
     * silencio (así se cortó una competencia a 120, migración 061). El
     * controlador rechaza en vez de dejar que se recorte, y la vista usa los
     * mismos valores en su `maxlength`.
     */
    public const MAX_PERIODO     = 30;
    public const MAX_AREA        = 120;
    public const MAX_COMPETENCIA = 255;
    public const MAX_COLEGIO     = 200;
    /**
     * La conclusión descriptiva del informe de origen (migración 062). Es
     * OPCIONAL para los cuatro literales: no rige aquí la obligatoriedad por
     * nivel del COCIAP, porque no es una evaluación nuestra — se transcribe lo
     * que emitió el otro colegio, y si su informe no la trae no se inventa.
     */
    public const MAX_CONCLUSION  = 1000;

    /** Currícula ya leída, por matrícula. Ver `curriculaParaImportar()`. */
    private array $curriculaCache = [];

    /** Notas de origen de una matrícula, con el nombre del área mapeada. */
    public function getDeMatricula(int $matriculaId): array
    {
        return $this->query("
            SELECT
                ne.*,
                a.nombre        AS area_mapeada,
                a.nombre_boleta AS area_mapeada_boleta
            FROM notas_externas ne
            LEFT JOIN areas a ON a.id = ne.area_id
            WHERE ne.matricula_id = ?
            ORDER BY ne.periodo_nombre, ne.area_nombre, ne.competencia_nombre
        ", [$matriculaId]);
    }

    /**
     * Alta o actualización de UNA nota de origen. Idempotente por el UNIQUE
     * (matricula_id, periodo_nombre, competencia_nombre).
     */
    public function registrar(array $datos): bool
    {
        return $this->execute("
            INSERT INTO notas_externas
                (matricula_id, periodo_nombre, competencia_nombre, area_id,
                 area_nombre, nota_literal, conclusion_descriptiva,
                 colegio_origen, registrado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                area_id                = VALUES(area_id),
                area_nombre            = VALUES(area_nombre),
                nota_literal           = VALUES(nota_literal),
                -- Se pisa igual que el resto: volver a guardar la misma
                -- competencia REEMPLAZA la fila, como dice la pantalla.
                conclusion_descriptiva = VALUES(conclusion_descriptiva),
                colegio_origen         = VALUES(colegio_origen),
                registrado_por         = VALUES(registrado_por),
                registrado_en          = CURRENT_TIMESTAMP
        ", [
            (int) $datos['matricula_id'],
            $datos['periodo_nombre'],
            $datos['competencia_nombre'],
            isset($datos['area_id']) && (int) $datos['area_id'] > 0 ? (int) $datos['area_id'] : null,
            $datos['area_nombre'],
            $datos['nota_literal'],
            ($datos['conclusion_descriptiva'] ?? '') !== '' ? $datos['conclusion_descriptiva'] : null,
            $datos['colegio_origen'] ?? null,
            (int) $datos['registrado_por'],
        ]);
    }

    /**
     * Alta de VARIAS notas de una vez. El informe de origen llega como un
     * documento entero, así que se captura entero: registrarlas de una en una
     * son 25+ pasadas por el formulario, el mismo problema que ya tenía la
     * calificación extraordinaria.
     *
     * ⚠️ NO abre transacción: la owna quien llama. PDO no anida transacciones,
     * y abrirla aquí impedía envolver el lote desde fuera —empezando por los
     * verificadores, que escriben y hacen rollback—. Mismo criterio que
     * RectificacionController::escribirExtraordinaria.
     *
     * @param array $filas cada una con periodo_nombre, competencia_nombre,
     *                     area_nombre, nota_literal y area_id opcional
     * @return int Cuántas quedaron registradas.
     */
    public function registrarLote(
        int $matriculaId,
        array $filas,
        ?string $colegioOrigen,
        int $usuarioId
    ): int {
        foreach ($filas as $f) {
            $this->registrar([
                'matricula_id'       => $matriculaId,
                'periodo_nombre'     => $f['periodo_nombre'],
                'competencia_nombre' => $f['competencia_nombre'],
                'area_id'            => $f['area_id'] ?? null,
                'area_nombre'        => $f['area_nombre'],
                'nota_literal'       => $f['nota_literal'],
                'conclusion_descriptiva' => $f['conclusion_descriptiva'] ?? null,
                'colegio_origen'     => $colegioOrigen,
                'registrado_por'     => $usuarioId,
            ]);
        }

        return count($filas);
    }

    /** ¿Esta matrícula tiene alguna nota de origen registrada? */
    public function tiene(int $matriculaId): bool
    {
        $fila = $this->queryOne(
            "SELECT 1 AS x FROM notas_externas WHERE matricula_id = ? LIMIT 1",
            [$matriculaId]
        );
        return $fila !== null;
    }

    /**
     * GUARDA de acceso del docente: ¿tiene carga ACTIVA en la sección de esta
     * matrícula, en el año de la matrícula? Si no, no ve nada (404).
     *
     * ⚠️ `cargas_academicas.docente_id` apunta directo a `usuarios.id`.
     */
    public function docenteTieneAcceso(int $matriculaId, int $docenteId): bool
    {
        $fila = $this->queryOne("
            SELECT 1 AS x
            FROM matriculas m
            INNER JOIN cargas_academicas ca
                    ON ca.seccion_id = m.seccion_id
                   AND ca.anio_id    = m.anio_id
                   AND ca.estado     = 'activa'
            WHERE m.id = ? AND ca.docente_id = ?
            LIMIT 1
        ", [$matriculaId, $docenteId]);

        return $fila !== null;
    }

    /**
     * Áreas en las que ESE docente tiene carga en la sección de la matrícula.
     * Alimenta el RESALTADO: el docente ve el informe completo, y las filas de
     * su(s) área(s) marcadas.
     *
     * @return int[] ids de área
     */
    public function areasDelDocenteEnSeccion(int $matriculaId, int $docenteId): array
    {
        $filas = $this->query("
            SELECT DISTINCT COALESCE(ca.area_id, sa.area_id) AS area_id
            FROM matriculas m
            INNER JOIN cargas_academicas ca
                    ON ca.seccion_id = m.seccion_id
                   AND ca.anio_id    = m.anio_id
                   AND ca.estado     = 'activa'
            LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
            WHERE m.id = ? AND ca.docente_id = ?
        ", [$matriculaId, $docenteId]);

        $out = [];
        foreach ($filas as $f) {
            if ($f['area_id'] !== null) {
                $out[] = (int) $f['area_id'];
            }
        }
        return $out;
    }

    /**
     * La CURRÍCULA de la sección del estudiante, lista para importarla como
     * notas de origen: áreas con sus competencias, agrupadas y ordenadas.
     *
     * POR QUÉ EXISTE: transcribir a mano el informe del colegio anterior son
     * 27-29 competencias POR BIMESTRE, y quien llega en el III trae dos. La
     * mayoría de colegios peruanos sigue el Currículo Nacional del MINEDU —el
     * mismo que usa el COCIAP—, así que las competencias coinciden casi siempre
     * y traerlas ahorra el 100 % del tecleo.
     *
     * ⚠️ SIN SUBÁREAS. Aritmética, Plan Lector, Química… son organización
     * INTERNA del COCIAP: el colegio de origen pudo repartirse de otra forma, y
     * atribuirle una división que no usa sería inventarle datos. Se importa el
     * ÁREA y la COMPETENCIA; la subárea se descarta a propósito.
     *
     * ⚠️ El nombre de la competencia es `nombre_completo`, la redacción oficial
     * del currículo nacional: es la que más probablemente coincida con el
     * informe que trae el estudiante. El `nombre_corto` es abreviatura nuestra.
     *
     * ⚠️ Dos áreas PUEDEN traer la misma competencia (el COCIAP evalúa
     * "Resuelve problemas de cantidad" en Matemática y en Taller de Razonamiento
     * Matemático). Son filas distintas y deben seguir siéndolo: por eso la
     * UNIQUE de `notas_externas` incluye `area_nombre` desde la migración 059.
     * Aquí solo se deduplica DENTRO de un área.
     *
     * @return array [['area_id','area_nombre','competencias'=>[nombre,…]], …]
     */
    public function curriculaParaImportar(int $matriculaId): array
    {
        // MEMORIZADO POR MATRÍCULA. La pantalla de notas de origen la pide dos
        // veces —una para la card de importación y otra, vía
        // `areasDeLaSeccion()`, para el `<select>` de mapeo—, y detrás hay la
        // consulta más cara de la vista. Se cachea por instancia (vive lo que
        // el request), no en propiedad estática: dos matrículas distintas en el
        // mismo proceso siguen leyendo cada una lo suyo.
        if (isset($this->curriculaCache[$matriculaId])) {
            return $this->curriculaCache[$matriculaId];
        }

        $filas = (new CalificacionModel())->estructuraCompetenciasSeccion($matriculaId);

        $porArea = [];
        foreach ($filas as $f) {
            $areaId     = (int) $f['area_id'];
            $areaNombre = $this->nombreDeArea($f);
            $competencia = trim((string) $f['competencia_nombre']);
            if ($competencia === '') {
                continue;
            }

            if (!isset($porArea[$areaId])) {
                $porArea[$areaId] = [
                    'area_id'      => $areaId,
                    'area_nombre'  => $areaNombre,
                    'competencias' => [],
                ];
            }
            // Deduplica DENTRO del área: dos subáreas de la misma área no deben
            // producir dos filas idénticas.
            $porArea[$areaId]['competencias'][$competencia] = $competencia;
        }

        foreach ($porArea as &$area) {
            $area['competencias'] = array_values($area['competencias']);
        }
        unset($area);

        return $this->curriculaCache[$matriculaId] = array_values($porArea);
    }

    /**
     * Ordena notas de origen según la CURRÍCULA de la sección del estudiante
     * (orden de áreas y de competencias del plan, transversales al final),
     * que es el orden en que el docente lee la boleta. Lo que no calza con
     * nuestro plan —áreas o competencias propias del otro colegio— va AL
     * FINAL, conservando el orden en que llegó. No filtra nada: se devuelven
     * todas las notas recibidas (18/09/2026).
     *
     * El área se reconoce por `area_id` (el mapeo que eligió RA) o, si no lo
     * tiene, por el mismo nombre que ofrece el importador (`nombreDeArea`).
     */
    public function ordenarSegunCurricula(int $matriculaId, array $notas): array
    {
        $posArea   = [];   // area_id => posición
        $posNombre = [];   // nombre visible => posición
        $posComp   = [];   // posición de área => [competencia => posición]
        foreach ($this->curriculaParaImportar($matriculaId) as $i => $area) {
            $posArea[(int) $area['area_id']] = $i;
            $posNombre[mb_strtolower(trim((string) $area['area_nombre']))] ??= $i;
            foreach ($area['competencias'] as $j => $comp) {
                $posComp[$i][mb_strtolower(trim((string) $comp))] = $j;
            }
        }

        $sinPlan = PHP_INT_MAX;
        $claves  = [];
        foreach ($notas as $k => $n) {
            $aid = isset($n['area_id']) ? (int) $n['area_id'] : 0;
            $pa  = $posArea[$aid]
                ?? $posNombre[mb_strtolower(trim((string) ($n['area_nombre'] ?? '')))]
                ?? $sinPlan;
            $pc  = $pa === $sinPlan
                ? $sinPlan
                : ($posComp[$pa][mb_strtolower(trim((string) ($n['competencia_nombre'] ?? '')))] ?? $sinPlan);
            $claves[$k] = [$pa, $pc, $k];
        }

        uksort($notas, static fn($a, $b): int => $claves[$a] <=> $claves[$b]);
        return array_values($notas);
    }

    /**
     * Nombre visible de un área: el de boleta si lo tiene. PUNTO ÚNICO para que
     * el texto que se importa y el del `<select>` de mapeo sean el MISMO; si
     * divergen, la fila importada apunta a un área que el select no ofrece.
     */
    private function nombreDeArea(array $fila): string
    {
        $boleta = trim((string) ($fila['nombre_boleta'] ?? ''));
        return $boleta !== '' ? $boleta : (string) $fila['area_nombre'];
    }

    /**
     * Áreas del plan de la SECCIÓN, para el `<select>` de mapeo opcional que usa
     * Registro Académico al registrar.
     *
     * ⚠️ SALE DE `curriculaParaImportar()`, no de una consulta propia. Si el
     * select ofreciera un juego de áreas distinto del que trae el importador,
     * una fila importada apuntaría a un área que el select no tiene y el mapeo
     * —y con él el resaltado al docente— se perdería al guardar. Una sola
     * fuente y un solo nombre.
     *
     * Efecto colateral querido: ahora incluye las TRANSVERSALES, que la consulta
     * por cargas no traía porque su área no tiene cargas propias.
     *
     * El orden es el del PLAN (`areas.orden`), no alfabético: es el mismo que
     * usan la boleta y la card de importación.
     */
    public function areasDeLaSeccion(int $matriculaId): array
    {
        $out = [];
        foreach ($this->curriculaParaImportar($matriculaId) as $area) {
            $out[] = ['id' => $area['area_id'], 'nombre' => $area['area_nombre']];
        }
        return $out;
    }
}
