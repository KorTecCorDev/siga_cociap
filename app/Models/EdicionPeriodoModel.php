<?php

namespace App\Models;

/**
 * EdicionPeriodoModel
 *
 * COMPUERTA TEMPORAL DE EDICIÓN (F1 del plan de bloqueos, 09/10/2026;
 * `docs/modulos/cierre-cuatro-registros.md` §0).
 *
 * PUNTO ÚNICO de «¿se puede registrar en este periodo?» y de «días para el
 * cierre». Antes la regla estaba escrita cinco veces —tres en PHP
 * (`CalificacionModel::periodoEstaBloqueado`, `ConductaModel::periodoEditable`,
 * `AsistenciaModel::periodoEditable`) y dos como columna SQL `editable`— con
 * dos semánticas para un periodo `pendiente`, y los días restantes, tres veces.
 *
 * Regla: editable ⟺ `estado = 'activo'` Y (sin fecha límite O ahora ≤ límite).
 * Un periodo `pendiente` NO es editable (aún no empieza). El borde es
 * inclusivo: justo en el límite todavía se puede.
 *
 * POR ROL: cada registro pregunta con el rol que lo REGISTRA, no con el del
 * usuario en sesión:
 *  - ROL_DOCENTE  → académicas, TIC/GAMA y todo lo del tutor (conducta etapa 2,
 *    conclusiones y cierre de transversales).
 *  - ROL_AUXILIAR → conducta etapa 1 y asistencia, la registre el auxiliar, RA
 *    o admin.
 * Hoy los dos leen `limite_notas`; la F4 del plan les dará fechas distintas.
 *
 * ZONA HORARIA — el «ahora» lo calcula PHP (America/Lima, fijado en
 * public/index.php), igual que `PublicacionBoletaModel::ahora()`. Nunca NOW():
 * el MySQL de producción corre en UTC.
 *
 * NO es compuerta (y no pasa por aquí): `AnioAcademicoModel::getDocentesMasRapidos`
 * usa `limite_notas` solo como referencia de margen.
 */
class EdicionPeriodoModel extends BaseModel
{
    protected string $table = 'periodos';

    public const ROL_DOCENTE  = 'docente';
    public const ROL_AUXILIAR = 'auxiliar';

    public const MOTIVO_CERRADO       = 'cerrado';
    public const MOTIVO_PLAZO_VENCIDO = 'plazo_vencido';
    public const MOTIVO_NO_INICIADO   = 'no_iniciado';

    /** true si el rol puede registrar en el periodo. */
    public function esEditable(int $periodoId, string $rol): bool
    {
        return $this->motivoNoEditable($periodoId, $rol) === null;
    }

    /**
     * Por qué NO se puede registrar, o null si se puede. Un periodo que no
     * existe se trata como cerrado.
     */
    public function motivoNoEditable(int $periodoId, string $rol): ?string
    {
        $p = $this->queryOne("SELECT estado, limite_notas FROM periodos WHERE id = ?", [$periodoId]);
        return $p ? self::motivoSobre($p, $rol) : self::MOTIVO_CERRADO;
    }

    /**
     * La misma regla sobre una fila de `periodos` ya leída (necesita `estado`
     * y `limite_notas`). La usan los listados que antes calculaban la columna
     * `editable` en SQL.
     */
    public static function motivoSobre(array $periodo, string $rol, ?int $ahora = null): ?string
    {
        if (($periodo['estado'] ?? null) === 'cerrado') {
            return self::MOTIVO_CERRADO;
        }
        if (($periodo['estado'] ?? null) !== 'activo') {
            return self::MOTIVO_NO_INICIADO;
        }
        $limite = self::limiteDe($periodo, $rol);
        if ($limite !== null && strtotime($limite) < ($ahora ?? time())) {
            return self::MOTIVO_PLAZO_VENCIDO;
        }
        return null;
    }

    /** Fecha límite que rige al rol en esa fila de `periodos` (null = sin límite). */
    public static function limiteDe(array $periodo, string $rol): ?string
    {
        $limite = $periodo['limite_notas'] ?? null;
        return ($limite === null || $limite === '') ? null : (string) $limite;
    }

    /**
     * Días hasta la fecha límite, para los KPI de los paneles; negativo = ya
     * pasó, null = sin límite. Redondea HACIA AFUERA del cero: antes un
     * `ceil()` mostraba «0 días para el cierre» hasta 24 h DESPUÉS de vencer.
     */
    public static function diasParaCierre(?string $limite, ?int $ahora = null): ?int
    {
        if ($limite === null || $limite === '') {
            return null;
        }
        $dias = (strtotime($limite) - ($ahora ?? time())) / 86400;
        return (int) ($dias >= 0 ? ceil($dias) : floor($dias));
    }
}
