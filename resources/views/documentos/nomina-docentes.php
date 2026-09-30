<?php
/**
 * Nómina de docentes (A4 VERTICAL desde el 29/09/2026) —
 * `Documentos\DocumentoController::nominaDocentes`. Layout: print. Un bloque
 * por nivel, docentes en orden alfabético. El DNI solo sale para admin, RA y
 * Dirección (decisión del 28/09/2026).
 *
 * Cada docente es una FICHA de dos filas (un `<tbody>` que no se parte entre
 * hojas): arriba el contacto; abajo, a todo el ancho, la TUTORÍA primero y
 * luego las cargas, cada línea con sus secciones como etiquetas y sus áreas.
 * Las secciones se nombran enteras, nunca «1.° A-B» (decisión del usuario).
 *
 * 🔴 BLANCO Y NEGRO: el color nunca es la única señal. La tutoría se reconoce
 * por su rótulo «TUTORÍA» y su barra gruesa; las secciones, por su recuadro.
 * Se usan bordes y texto de color, no fondos (el diálogo de impresión suele
 * quitar los fondos).
 *
 * @var array<int, array{nombre:string, docentes:array}> $niveles  bloques a imprimir
 * @var array<int, string> $filtro   niveles que trae el documento (id => nombre)
 * @var int                $nivelId  nivel filtrado (0 = todos)
 * @var bool               $conDni
 * @var bool               $deSusSecciones  el auxiliar: solo los docentes de sus secciones
 * @var string|null        $anio
 */
$total    = array_sum(array_map(static fn($n) => count($n['docentes']), $niveles));
$columnas = $conDni ? 4 : 3;   // columnas de la fila de contacto, sin contar N°
$alcance  = $nivelId ? $filtro[$nivelId] : ($deSusSecciones ? 'Docentes de tus secciones' : 'Todos los niveles');
?>
<div class="nomina-print nomina-docentes">

    <header class="nomina-print__head">
        <img class="nomina-print__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        <div class="nomina-print__titulo">
            <h1><?= e(config('institucion')) ?></h1>
            <p>Nómina de docentes<?= !empty($anio) ? ' &middot; ' . e($anio) : '' ?></p>
            <p class="nomina-print__sec">
                <?= e($alcance) ?>
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
            <table class="nomina-print__tabla nomina-docentes__tabla">
                <thead>
                    <tr>
                        <th class="nomina-print__num">N°</th>
                        <th>Apellidos y nombres</th>
                        <?php if ($conDni): ?><th>DNI</th><?php endif; ?>
                        <th>Celular</th>
                        <th>Correo</th>
                    </tr>
                </thead>
                <?php foreach ($nivel['docentes'] as $i => $d): ?>
                    <tbody class="nomina-docentes__ficha">
                        <tr>
                            <td class="nomina-print__num" rowspan="2"><?= $i + 1 ?></td>
                            <td class="nomina-docentes__nombre"><?= e($d['nombre']) ?></td>
                            <?php if ($conDni): ?><td class="nomina-docentes__dato"><?= $d['dni'] !== '' ? e($d['dni']) : '—' ?></td><?php endif; ?>
                            <td class="nomina-docentes__dato"><?= $d['telefono'] !== '' ? e($d['telefono']) : '—' ?></td>
                            <td class="nomina-docentes__correo"><?= $d['correo'] !== '' ? e($d['correo']) : '—' ?></td>
                        </tr>
                        <tr>
                            <td class="nomina-docentes__detalle" colspan="<?= $columnas ?>">
                                <?php if ($d['tutoria'] !== []): ?>
                                    <div class="nomina-docentes__tutoria">
                                        <span class="nomina-docentes__rotulo">Tutoría</span>
                                        <?php foreach ($d['tutoria'] as $sec): ?>
                                            <span class="nomina-docentes__sec nomina-docentes__sec--tutoria"><?= e($sec) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php foreach ($d['cargas'] as $c): ?>
                                    <div class="nomina-docentes__carga">
                                        <div class="nomina-docentes__secs">
                                            <?php foreach ($c['secciones'] as $sec): ?>
                                                <span class="nomina-docentes__sec"><?= e($sec) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="nomina-docentes__areas"><?= e(implode(' · ', $c['areas'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($d['cargas'] === [] && $d['tutoria'] === []): ?>—<?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </section>
    <?php endforeach; ?>

</div>
