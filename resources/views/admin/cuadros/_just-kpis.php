<?php
/**
 * Justificaciones — TARJETAS y comparación por NIVEL (29/09/2026). Compartido
 * por la pantalla y el A4. Los valores los calcula `AsistenciaEstadisticaModel`;
 * aquí solo se formatean («—» cuando no hay con qué calcular).
 *
 * @var array $just  bloque `justificaciones` de componerBloques()
 */
$fmtPct = static fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '%';
$k = $just['kpis'];
?>
<div class="cuadros-kpis mb-md">
    <div class="cuadros-kpi">
        <span class="cuadros-kpi__n"><?= $fmtPct($k['tasa_asistencia']) ?></span>
        <span class="cuadros-kpi__t">Tasa de asistencia</span>
    </div>
    <div class="cuadros-kpi">
        <span class="cuadros-kpi__n"><?= $fmtPct($k['pct_fj']) ?></span>
        <span class="cuadros-kpi__t"><?php
            $nombreAnclaFj = $just['motivo_principal'] ?? null; require VIEW_PATH . '/admin/asistencia/_pastilla-fj.php'; ?> Faltas justificadas</span>
    </div>
    <div class="cuadros-kpi">
        <span class="cuadros-kpi__n"><?= $fmtPct($k['pct_tj']) ?></span>
        <span class="cuadros-kpi__t">Tardanzas justificadas</span>
    </div>
    <div class="cuadros-kpi">
        <span class="cuadros-kpi__n"><?= $fmtPct($k['pct_verbales']) ?></span>
        <span class="cuadros-kpi__t">Justificaciones verbales</span>
    </div>
    <div class="cuadros-kpi">
        <span class="cuadros-kpi__n"><?= $fmtPct($k['pct_confirmado']) ?></span>
        <span class="cuadros-kpi__t">Registro confirmado (<?= (int) $k['estudiantes'] ?>/<?= (int) $k['esperados'] ?>)</span>
    </div>
</div>

<?php if (count($just['niveles'] ?? []) > 1): ?>
    <div class="tabla-notas-wrapper">
        <table class="tabla-notas cuadros-top cuadros-just-niveles">
            <caption class="cuadros-top__caption">Primaria frente a Secundaria</caption>
            <thead>
                <tr>
                    <th class="col-nombre">Nivel</th>
                    <th class="text-center">Tasa de asistencia</th>
                    <th class="text-center"><?php
                        $nombreAnclaFj = $just['motivo_principal'] ?? null; require VIEW_PATH . '/admin/asistencia/_pastilla-fj.php'; ?> Faltas justificadas</th>
                    <th class="text-center">Tardanzas justificadas</th>
                    <th class="text-center">Justificaciones verbales</th>
                    <th class="text-center">Registro confirmado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($just['niveles'] as $n): ?>
                    <tr>
                        <td class="col-nombre"><?= e($n['nivel']) ?></td>
                        <td class="text-center"><?= $fmtPct($n['tasa_asistencia']) ?></td>
                        <td class="text-center"><?= $fmtPct($n['pct_fj']) ?></td>
                        <td class="text-center"><?= $fmtPct($n['pct_tj']) ?></td>
                        <td class="text-center"><?= $fmtPct($n['pct_verbales']) ?></td>
                        <td class="text-center"><?= $fmtPct($n['pct_confirmado']) ?> (<?= (int) $n['estudiantes'] ?>/<?= (int) $n['esperados'] ?>)</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
