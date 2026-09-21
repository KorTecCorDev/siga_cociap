<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso denegado — SIGA-COCIAP</title>
    <link rel="stylesheet" href="<?= url('css/errores.css') ?>">
</head>
<body>
    <div class="error-page">
        <div class="error-page__codigo error-page__codigo--403">403</div>
        <h1>Acceso denegado</h1>
        <p>No tienes permisos para acceder a esta sección.</p>
        <a href="<?= url('dashboard') ?>">Volver al inicio</a>
    </div>
</body>
</html>
