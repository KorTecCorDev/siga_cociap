<?php
/**
 * @var array  $usuarios
 * @var array  $contexto    usuario_id => ['cursos' => [curso => secciones], 'tutor' => secciones]
 * @var array  $auxiliares  usuario_id => secciones del bimestre activo
 * @var array  $auth_user
 */

// Los tres directores comparten badge: tienen las mismas atribuciones y se leen
// como un solo estamento. El listado de códigos sale de ROLES_DIRECCION
// (helpers.php) para que un director nuevo no quede con el badge por defecto.
$badgeRol = fn(string $codigo): string => match(true) {
    $codigo === 'admin'                       => 'badge--error',
    in_array($codigo, ROLES_DIRECCION, true)  => 'badge--warning',
    default                                   => 'badge--info',
};

// Buscador: los tres directores forman un solo grupo, igual que su badge.
$grupoRol = fn(array $u): string => in_array($u['rol_codigo'], ROLES_DIRECCION, true)
    ? 'direccion'
    : $u['rol_codigo'];

$grupos = [];
foreach ($usuarios as $u) {
    $g = $grupoRol($u);
    $grupos[$g] ??= ['label' => $g === 'direccion' ? 'Dirección' : $u['rol_nombre'], 'total' => 0];
    $grupos[$g]['total']++;
}

// «Prim. 4° A, Prim. 4° B, Sec. 1° A» → «Prim. 4° A, 4° B, Sec. 1° A».
$listar = function (array $secciones): string {
    $salida = [];
    $previo = null;
    foreach ($secciones as $etiqueta) {
        [$prefijo, $resto] = array_pad(explode(' ', $etiqueta, 2), 2, '');
        $salida[] = $prefijo === $previo ? $resto : $etiqueta;
        $previo   = $prefijo;
    }
    return implode(', ', $salida);
};

// Contexto bajo el nombre: lo que dicta, tutorea o acompaña. Devuelve
// [línea visible, texto buscable]: en un aula unidocente se muestra solo
// «Aula: 2° A», pero sus cursos siguen siendo buscables.
$contextoDe = function (array $u) use ($contexto, $auxiliares, $listar): array {
    $visible  = [];
    $buscable = [];
    $c        = $contexto[(int) $u['id']] ?? null;
    if ($c !== null) {
        if ($c['aula']) {
            $visible[] = 'Aula: ' . $listar($c['aula']);
        }
        foreach ($c['cursos'] as $curso => $secciones) {
            $buscable[] = $curso . ': ' . $listar($secciones);
            $fuera      = array_values(array_diff($secciones, $c['aula']));
            if ($fuera) {
                $visible[] = $curso . ': ' . $listar($fuera);
            }
        }
        if ($c['tutor']) {
            $visible[] = 'Tutoría: ' . $listar($c['tutor']);
        }
    }
    if (!empty($auxiliares[(int) $u['id']])) {
        $visible[] = 'Auxiliar: ' . $listar($auxiliares[(int) $u['id']]);
    }
    $linea = implode(' · ', $visible);
    return [$linea, $linea . ' ' . implode(' ', $buscable)];
};
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <div>
        <h1 class="page-title">Gestión de Usuarios</h1>
        <p class="page-subtitle">
            <?= count($usuarios) ?> usuario<?= count($usuarios) !== 1 ? 's' : '' ?> registrado<?= count($usuarios) !== 1 ? 's' : '' ?>
        </p>
    </div>
    <a href="<?= url('admin/usuarios/crear') ?>" class="btn btn--primary">
        + Nuevo usuario
    </a>
</div>

<?php if (empty($usuarios)): ?>
    <div class="card">
        <div class="card__body">
            <div class="empty-state">
                <p>No hay usuarios registrados todavía.</p>
            </div>
        </div>
    </div>
<?php else: ?>

<div class="card usuarios-buscador" id="usuarios-buscador" hidden>
    <div class="usuarios-buscador__caja">
        <svg class="usuarios-buscador__lupa" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <line x1="16.5" y1="16.5" x2="21" y2="21"></line>
        </svg>
        <input type="search" id="usuarios-buscar" class="usuarios-buscador__input"
               placeholder="Busca por nombre, DNI, correo, curso o sección"
               autocomplete="off" spellcheck="false"
               aria-label="Buscar usuarios">
        <button type="button" class="usuarios-buscador__limpiar" id="usuarios-limpiar"
                aria-label="Limpiar búsqueda" hidden>&times;</button>
        <kbd class="usuarios-buscador__atajo" title="Pulsa / para buscar">/</kbd>
    </div>

    <div class="usuarios-buscador__filtros">
        <div class="usuarios-buscador__chips" role="group" aria-label="Filtrar por rol">
            <button type="button" class="filtro-chip filtro-chip--activo" data-grupo="" aria-pressed="true">
                Todos <span class="filtro-chip__n" data-n=""><?= count($usuarios) ?></span>
            </button>
            <?php foreach ($grupos as $codigo => $g): ?>
                <button type="button" class="filtro-chip" data-grupo="<?= e($codigo) ?>" aria-pressed="false">
                    <?= e($g['label']) ?> <span class="filtro-chip__n" data-n="<?= e($codigo) ?>"><?= (int) $g['total'] ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="usuarios-buscador__estado" role="group" aria-label="Filtrar por estado">
            <button type="button" class="usuarios-buscador__seg usuarios-buscador__seg--activo" data-estado="" aria-pressed="true">Todos</button>
            <button type="button" class="usuarios-buscador__seg" data-estado="activo" aria-pressed="false">Activos</button>
            <button type="button" class="usuarios-buscador__seg" data-estado="inactivo" aria-pressed="false">Inactivos</button>
        </div>
    </div>

    <p class="usuarios-buscador__contador" id="usuarios-contador" aria-live="polite"></p>
</div>

<div class="card">
    <div class="tabla-notas-wrapper">
        <table class="tabla-notas">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>DNI</th>
                    <th>Rol</th>
                    <th class="text-center">Estado</th>
                    <th>Último acceso</th>
                    <th class="text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $u):
                    $iniciales   = mb_strtoupper(mb_substr($u['apellido_paterno'], 0, 1) . mb_substr($u['nombres'], 0, 1));
                    $esYo        = (int) $u['id'] === (int) ($auth_user['id'] ?? 0);
                    $estaActivo  = $u['estado'] === 'activo';
                    $labelEstado = $estaActivo ? 'Activo' : 'Inactivo';
                    $badgeEstado = $estaActivo ? 'badge--activo' : 'badge--error';
                    $nombre      = \App\Models\UsuarioModel::nombreCompleto($u);
                    [$academico, $academicoBuscable] = $contextoDe($u);
                    $grupo       = $grupoRol($u);
                    $buscar      = implode(' ', [
                        $nombre, $u['dni'], (string) $u['correo'], $u['rol_nombre'],
                        $grupo === 'direccion' ? 'Dirección' : '', $academicoBuscable,
                    ]);
                ?>
                <tr class="usuarios-fila<?= !$estaActivo ? ' fila-inactiva' : '' ?>"
                    data-grupo="<?= e($grupo) ?>"
                    data-estado="<?= $estaActivo ? 'activo' : 'inactivo' ?>"
                    data-buscar="<?= e($buscar) ?>"
                    data-editar="<?= e(url('admin/usuarios/' . $u['id'] . '/editar')) ?>">

                    <td>
                        <div class="td-usuario">
                            <div class="usuario-avatar usuario-avatar--<?= e($u['rol_codigo']) ?>">
                                <?= e($iniciales) ?>
                            </div>
                            <div>
                                <div class="td-usuario__nombre" data-resaltar><?= e($nombre) ?></div>
                                <?php if ($u['correo']): ?>
                                    <div class="td-usuario__sub" data-resaltar><?= e($u['correo']) ?></div>
                                <?php endif; ?>
                                <?php if ($academico !== ''): ?>
                                    <div class="td-usuario__sub td-usuario__academico" data-resaltar><?= e($academico) ?></div>
                                <?php endif; ?>
                                <?php if ($esYo): ?>
                                    <div class="td-usuario__sub">(tú)</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <td class="text-sm" data-resaltar><?= e($u['dni']) ?></td>

                    <td>
                        <span class="badge <?= $badgeRol($u['rol_codigo']) ?>">
                            <?= e($u['rol_nombre']) ?>
                        </span>
                    </td>

                    <td class="text-center">
                        <span class="badge <?= $badgeEstado ?>"><?= $labelEstado ?></span>
                    </td>

                    <td class="text-sm text-muted">
                        <?= $u['ultimo_acceso'] ? fecha_es($u['ultimo_acceso']) : '—' ?>
                    </td>

                    <td>
                        <div class="td-acciones">
                            <a href="<?= url('admin/usuarios/' . $u['id'] . '/editar') ?>"
                               class="btn btn--secondary btn--sm">
                                Editar
                            </a>
                            <?php if (!$esYo): ?>
                                <form method="POST"
                                      action="<?= url('admin/usuarios/' . $u['id'] . '/estado') ?>"
                                      onsubmit="return confirm('¿<?= $estaActivo ? 'Desactivar' : 'Activar' ?> a <?= e(addslashes($nombre)) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="filtro_q" value="" data-filtro="q">
                                    <input type="hidden" name="filtro_rol" value="" data-filtro="rol">
                                    <input type="hidden" name="filtro_estado" value="" data-filtro="estado">
                                    <button type="submit"
                                            class="btn btn--sm <?= $estaActivo ? 'btn--danger' : 'btn--secondary' ?>">
                                        <?= $estaActivo ? 'Desactivar' : 'Activar' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>

                </tr>
                <?php endforeach; ?>
                <tr class="usuarios-sin-resultados" id="usuarios-sin-resultados" hidden>
                    <td colspan="6">
                        <p class="usuarios-sin-resultados__texto">Nadie coincide con esta búsqueda.</p>
                        <button type="button" class="btn btn--secondary btn--sm" id="usuarios-limpiar-todo">
                            Limpiar filtros
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>
