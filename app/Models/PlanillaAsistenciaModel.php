<?php

namespace App\Models;

use App\Siagie\XlsxQuirurgico;
use RuntimeException;

/**
 * PlanillaAsistenciaModel — la PLANILLA DE ASISTENCIA MANUAL (28/09/2026):
 * una hoja en blanco por días para marcar la asistencia a mano, en Excel
 * (plantilla `resources/plantillas/registro-asistencia.xlsx` + XlsxQuirurgico)
 * o en PDF (vista print). Ver docs/modulos/auxiliares.md (F4c).
 *
 * SOLO admin, RA y Dirección (decisión del 28/09/2026): el auxiliar no la
 * tiene, para que registre en el sistema y deje el papel.
 *
 * El roster es el de la grilla de asistencia (`AsistenciaModel::
 * getEstudiantesConIncidencias`, roster de evaluación en orden alfabético):
 * la planilla lista a quien luego se registra, ni uno más ni uno menos.
 */
class PlanillaAsistenciaModel extends BaseModel
{
    protected string $table = 'secciones';

    public const PLANTILLA = ROOT_PATH . '/resources/plantillas/registro-asistencia.xlsx';

    /** Filas de estudiantes que trae la plantilla (11..45). El PDF no tiene límite. */
    public const FILAS_EXCEL = 35;
    private const PRIMERA_FILA = 11;

    /**
     * Filas en blanco para ingresos tardíos (hasta 3), siempre que la fila no
     * baje de ALTO_MIN_MM; y alto de fila que llena la hoja, con tope ALTO_MAX_MM
     * (rediseño del 28/09/2026: comodidad para escribir a mano).
     */
    public const FILAS_LIBRES = 3;
    public const ALTO_MIN_MM  = 5.0;
    public const ALTO_MAX_MM  = 9.0;
    /** Alto que queda para las filas en la hoja del Excel (A4 horizontal, márgenes y cabecera de la plantilla). */
    private const ALTO_UTIL_EXCEL_MM = 136.0;
    /** Ídem en el PDF (página `paisaje`, márgenes de 1 cm, pie dentro de la hoja). */
    public const ALTO_UTIL_PDF_MM = 131.0;

    /** 25 columnas de día: 5 semanas × (L, Mar., Mié., J, V). */
    public const COLUMNAS_DIA = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R',
                                 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB'];

    /**
     * Caracteres que caben en cada casilla de nombre (medido en la hoja impresa,
     * Arial). Por encima, el nombre se abrevia con `nombreQueCabe()`.
     */
    public const MAX_NOMBRE   = 36;   // columna de estudiantes (80 mm, 9 pt)
    public const MAX_TUTOR    = 32;   // casilla TUTOR(A) (84 mm, 11 pt negrita)
    public const MAX_AUXILIAR = 26;   // casilla AUXILIAR (~63 mm, 11 pt negrita)

    /** Leyenda del SIAGIE (confirmada por el usuario el 28/09/2026). */
    public const LEYENDA = ['F' => 'Falta', 'J' => 'Falta justificada', 'T' => 'Tardanza', 'U' => 'Tardanza justificada'];
    public const ABREV = [1 => 'L', 2 => 'Mar.', 3 => 'Mié.', 4 => 'J', 5 => 'V'];
    public const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio',
                          'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /** Código del pie de la plantilla; el año se ajusta al año académico activo. */
    private const PIE_CODIGO = 'CAVVG/HZ/';
    private const PIE_ANIO_PLANTILLA = '2026';

    /**
     * Días hábiles de un mes para la planilla. SEMANA 1 = la del primer día
     * hábil del mes; se numera TODO el mes (no se recorta al bimestre). Nunca
     * pasa de 5 semanas (verificado 2026–2040).
     *
     * @return array<int, array{semana:int, indice:int, dia:int, abrev:string}>
     *         `indice` = posición 0..24 (columna = COLUMNAS_DIA[indice])
     */
    public static function diasPlanilla(int $anio, int $mes): array
    {
        $dia = new \DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
        while ((int) $dia->format('N') > 5) {
            $dia = $dia->modify('+1 day');                                        // 1.er día hábil
        }
        $lunes1 = $dia->modify('-' . ((int) $dia->format('N') - 1) . ' days');   // lunes de SEMANA 1
        $out = [];
        for ($d = $dia; (int) $d->format('n') === $mes; $d = $d->modify('+1 day')) {
            $n = (int) $d->format('N');
            if ($n > 5) {
                continue;
            }
            $semana = intdiv((int) $lunes1->diff($d)->days, 7) + 1;
            $out[]  = ['semana' => $semana, 'indice' => ($semana - 1) * 5 + ($n - 1),
                       'dia' => (int) $d->format('j'), 'abrev' => self::ABREV[$n]];
        }
        return $out;
    }

    /**
     * Nombre que cabe en su casilla (decisión del usuario, 28/09/2026). Si
     * «APELLIDOS, Nombres» pasa de $max caracteres, se deja el primer nombre y la
     * inicial del segundo con punto: «SANTAMARIA RODRIGUEZ, JAKELINE E.». Los
     * apellidos no se tocan. Si aun así no cabe, o hay un solo nombre, vuelve tal
     * cual: el documento reduce la letra antes que cortarlo.
     * PUNTO ÚNICO para el Excel y el PDF.
     */
    public static function nombreQueCabe(string $nombre, int $max): string
    {
        if (mb_strlen($nombre) <= $max || !str_contains($nombre, ', ')) {
            return $nombre;
        }
        [$apellidos, $nombres] = explode(', ', $nombre, 2);
        $partes = preg_split('/\s+/u', trim($nombres), -1, PREG_SPLIT_NO_EMPTY);
        if (count($partes) < 2) {
            return $nombre;
        }
        return $apellidos . ', ' . $partes[0] . ' ' . mb_substr($partes[1], 0, 1) . '.';
    }

    /**
     * Cuántas filas lleva la grilla (PUNTO ÚNICO para el PDF y el Excel):
     * los estudiantes y hasta FILAS_LIBRES en blanco, mientras cada fila no baje
     * de ALTO_MIN_MM en la hoja. Una sección muy grande no pierde a nadie: se
     * queda sin filas libres y con filas más bajas.
     */
    public static function filasGrilla(int $estudiantes, float $altoUtilMm = self::ALTO_UTIL_EXCEL_MM): int
    {
        $caben  = (int) floor($altoUtilMm / self::ALTO_MIN_MM);
        $libres = max(0, min(self::FILAS_LIBRES, $caben - $estudiantes));
        return $estudiantes + $libres;
    }

    /** Alto de fila (mm) que llena el alto útil, con tope ALTO_MAX_MM. */
    public static function altoFilaMm(int $filas, float $altoUtilMm = self::ALTO_UTIL_EXCEL_MM): float
    {
        return $filas > 0 ? min(self::ALTO_MAX_MM, $altoUtilMm / $filas) : self::ALTO_MAX_MM;
    }

    /**
     * Meses que toca un periodo (de su fecha de inicio a la de fin), los únicos
     * que se ofrecen en el modo «con fechas» (decisión del 28/09/2026).
     *
     * @return array<string, array{anio:int, mes:int, nombre:string}> clave 'AAAA-MM'
     */
    public static function mesesDelPeriodo(array $periodo): array
    {
        $meses = [];
        $d     = new \DateTimeImmutable(substr((string) $periodo['fecha_inicio'], 0, 7) . '-01');
        $fin   = new \DateTimeImmutable(substr((string) $periodo['fecha_fin'], 0, 7) . '-01');
        for (; $d <= $fin; $d = $d->modify('+1 month')) {
            $meses[$d->format('Y-m')] = [
                'anio'   => (int) $d->format('Y'),
                'mes'    => (int) $d->format('n'),
                'nombre' => self::MESES[(int) $d->format('n')],
            ];
        }
        return $meses;
    }

    /**
     * Cabecera de la planilla: sección del año activo con nómina aprobada (las
     * mismas que lista `/admin/asistencia`), con su tutor(a). NULL si no existe.
     */
    public function seccion(int $seccionId): ?array
    {
        $s = $this->queryOne("
            SELECT s.id, s.nombre AS seccion_nombre,
                   g.numero AS grado_numero, g.nombre_display AS grado_nombre,
                   n.nombre AS nivel_nombre, a.anio,
                   tp.apellido_paterno AS tutor_paterno, tp.apellido_materno AS tutor_materno,
                   tp.nombres AS tutor_nombres
            FROM secciones s
            INNER JOIN grados g            ON g.id = s.grado_id
            INNER JOIN niveles n           ON n.id = g.nivel_id
            INNER JOIN anios_academicos a  ON a.id = s.anio_id AND a.estado = 'activo'
            LEFT  JOIN usuarios tu         ON tu.id = s.tutor_id
            LEFT  JOIN personas tp         ON tp.id = tu.persona_id
            WHERE s.id = ? AND s.estado_nomina = 'aprobada'
        ", [$seccionId]);

        if ($s === null) {
            return null;
        }
        $s['tutor_nombre'] = $s['tutor_paterno'] === null ? ''
            : $s['tutor_paterno'] . ' ' . $s['tutor_materno'] . ', ' . $s['tutor_nombres'];
        return $s;
    }

    /**
     * Nombre del archivo (decisión del 28/09/2026): Registro_Asistencia_1A_Primaria.
     * Sin tildes ni espacios, para que se abra bien en cualquier equipo.
     */
    public static function nombreArchivo(array $seccion): string
    {
        $limpio = static fn(string $s): string => preg_replace('/[^A-Za-z0-9]/', '',
            strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
                       'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']));
        return 'Registro_Asistencia_' . (int) $seccion['grado_numero'] . $limpio($seccion['seccion_nombre'])
            . '_' . $limpio($seccion['nivel_nombre']);
    }

    /**
     * Llena una COPIA de la plantilla y devuelve la ruta del .xlsx generado (el
     * llamador lo envía y lo borra). Nunca escribe sobre la plantilla: el
     * XlsxQuirurgico guarda junto al archivo que abre, así que se abre una copia
     * única en temporales (dos descargas a la vez no chocan).
     *
     * @param array      $seccion   self::seccion()
     * @param array      $periodo   bimestre activo {nombre_display}
     * @param array|null $auxiliar  AuxiliarSeccionModel::vigente() o null
     * @param string[]   $alumnos   nombres en orden (a lo sumo FILAS_EXCEL)
     * @param array|null $mes       ['anio','mes','nombre'] o null = en blanco
     */
    public function generarExcel(array $seccion, array $periodo, ?array $auxiliar, array $alumnos, ?array $mes): string
    {
        if (count($alumnos) > self::FILAS_EXCEL) {
            throw new RuntimeException('La plantilla admite ' . self::FILAS_EXCEL . ' estudiantes.');
        }

        $copia = tempnam(sys_get_temp_dir(), 'planilla_');
        if ($copia === false || !copy(self::PLANTILLA, $copia)) {
            throw new RuntimeException('No se pudo preparar la plantilla de asistencia.');
        }

        try {
            $x    = new XlsxQuirurgico($copia);
            $hoja = $x->nombresDeHojas()[0];

            // Encabezado: título y nivel (celdas combinadas VACÍAS en la plantilla).
            $x->escribir($hoja, 'L1', 'REGISTRO AUXILIAR DE ASISTENCIA - ' . $seccion['anio']);
            $x->escribir($hoja, 'L3', 'NIVEL ' . mb_strtoupper($seccion['nivel_nombre']));

            // Franja de datos (fila 6). Casilla vacía = se completa a mano.
            $x->escribir($hoja, 'A6', trim($seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre']));
            $x->escribir($hoja, 'C6', $periodo['nombre_display']);
            if ($seccion['tutor_nombre'] !== '') {
                $x->escribir($hoja, 'K6', self::nombreQueCabe($seccion['tutor_nombre'], self::MAX_TUTOR));
            }
            if ($auxiliar !== null) {
                $x->escribir($hoja, 'Y6', self::nombreQueCabe($auxiliar['nombre'], self::MAX_AUXILIAR));
            }

            // Días: solo en el modo «con fechas». En blanco quedan solo los
            // rótulos SEMANA 1..5 y el MES vacío (decisión 28/09/2026). Con MES
            // escrito, la plantilla sombrea sola (formato condicional) las
            // casillas sin número de día.
            if ($mes !== null) {
                $x->escribir($hoja, 'D6', mb_strtoupper($mes['nombre']));
                foreach (self::diasPlanilla($mes['anio'], $mes['mes']) as $d) {
                    $col = self::COLUMNAS_DIA[$d['indice']];
                    $x->escribir($hoja, $col . '9', sprintf('%02d', $d['dia']));
                    $x->escribir($hoja, $col . '10', $d['abrev']);
                }
            }

            foreach (array_values($alumnos) as $i => $nombre) {
                $x->escribir($hoja, 'B' . (self::PRIMERA_FILA + $i), self::nombreQueCabe($nombre, self::MAX_NOMBRE));
            }

            // Filas: las de la grilla con el alto que llena la hoja; el resto,
            // ocultas (no se imprimen).
            $filas = self::filasGrilla(count($alumnos));
            $alto  = self::altoFilaMm($filas) * 72 / 25.4;
            for ($f = 0; $f < self::FILAS_EXCEL; $f++) {
                $f < $filas
                    ? $x->altoFila($hoja, self::PRIMERA_FILA + $f, $alto)
                    : $x->ocultarFila($hoja, self::PRIMERA_FILA + $f);
            }

            $x->reemplazarEnPie($hoja, self::PIE_CODIGO . self::PIE_ANIO_PLANTILLA,
                self::PIE_CODIGO . $seccion['anio']);

            $tmp = $x->guardarEnTemporal();
        } finally {
            @unlink($copia);
        }
        return $tmp;
    }
}
