<?php
/**
 * Nómina de docentes (A4 horizontal) — `Documentos\DocumentoController::nominaDocentes`.
 * Layout: print. Un bloque por nivel, docentes en orden alfabético.
 * El DNI solo sale para admin, RA y Dirección (decisión del 28/09/2026).
 *
 * @var array<int, array{nombre:string, docentes:array}> $niveles  bloques a imprimir
 * @var array<int, string> $filtro   niveles que trae el documento (id => nombre)
 * @var int                $nivelId  nivel filtrado (0 = todos)
 * @var bool               $conDni
 * @var string|null        $anio
 */
$total = array_sum(array_map(static fn($n) => count($n['docentes']), $niveles));
?>
<div class="nomina-print nomina-docentes">

    <header class="nomina-print__head">
        <img class="nomina-print__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        <div class="nomina-print__titulo">
            <h1><?= e(config('institucion')) ?></h1>
            <p>Nómina de docentes<?= !empty($anio) ? ' &middot; ' . e($anio) : '' ?></p>
            <p class="nomina-print__sec">
                <?= $nivelId ? e($filtro[$nivelId]) : 'Todos los niveles' ?>
                &middot; <?= $total ?> <?= $total === 1 ? 'docente' : 'docentes' ?>
            </p>
        </div>
    </header>

    <?php if (count($filtro) > 1): ?>
        <nav class="nomina-docentes__filtro" aria-label="Filtrar por nivel">
            <a href="<?= url('documentos/nomina-docentes') ?>"
               class="nomina-docentes__opcion<?= $nivelId === 0 ? ' is-activa' : '' ?>"
               <?= $nivelId === 0 ? 'aria-current="page"' : '' ?>>Todos</a>
            <?php foreach ($filtro as $id => $nombre): ?>
                <a href="<?= url('documentos/nomina-docentes?nivel=' . (int) $id) ?>"
                   class="nomina-docentes__opcion<?= $nivelId === (int) $id ? ' is-activa' : '' ?>"
                   <?= $nivelId === (int) $id ? 'aria-current="page"' : '' ?>><?= e($nombre) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="nomina-print__meta">
        <span><strong>Fecha de impresión:</strong> <?= e(date('d/m/Y H:i')) ?></span>
    </div>

    <?php if ($total === 0): ?>
        <p class="nomina-docentes__vacio">No hay docentes con carga académica en el año en curso.</p>
    <?php endif; ?>

    <?php foreach ($niveles as $nivel): ?>
        <section class="nomina-docentes__nivel">
            <h2 class="nomina-docentes__nivel-titulo"><?= e($nivel['nombre']) ?></h2>
            <table class="nomina-print__tabla">
                <thead>
                    <tr>
                        <th class="nomina-print__num">N°</th>
                        <th>Apellidos y nombres</th>
                        <?php if ($conDni): ?><th>DNI</th><?php endif; ?>
                        <th>Celular</th>
                        <th>Correo</th>
                        <th>Áreas y secciones</th>
                        <th>Tutoría</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($nivel['docentes'] as $i => $d): ?>
                        <tr>
                            <td class="nomina-print__num"><?= $i + 1 ?></td>
                            <td class="nomina-docentes__nombre"><?= e($d['nombre']) ?></td>
                            <?php if ($conDni): ?><td><?= $d['dni'] !== '' ? e($d['dni']) : '—' ?></td><?php endif; ?>
                            <td><?= $d['telefono'] !== '' ? e($d['telefono']) : '—' ?></td>
                            <td class="nomina-docentes__correo"><?= $d['correo'] !== '' ? e($d['correo']) : '—' ?></td>
                            <td>
                                <?php if ($d['cargas'] === []): ?>—<?php endif; ?>
                                <?php foreach ($d['cargas'] as $c): ?>
                                    <div class="nomina-docentes__carga">
                                        <?= e($c['areas']) ?>
                                        <span class="nomina-docentes__secciones">&middot; <?= e($c['secciones']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td><?= $d['tutoria'] !== '' ? e($d['tutoria']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>

</div>
