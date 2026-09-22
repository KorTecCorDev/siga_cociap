<?php
/**
 * Aviso: el estudiante AÚN NO TIENE CALIFICACIONES OFICIALES (21/09/2026).
 *
 * Sustituye al 404 que devolvían las boletas internas (docente y gestión) cuando
 * no había ningún bimestre publicable con competencias bloqueadas. La matrícula
 * existe: decir "página no encontrada" era falso.
 *
 * Página SUELTA (sin layout), como las de error, y con su mismo CSS
 * (`errores.css`). Se abre en pestaña nueva, así que lleva el botón Cerrar de
 * los documentos: `.btn-boleta--cerrar` + `print-fit.js` (cierra la ventana o
 * vuelve atrás). La regla de "tiene boleta" es
 * `BoletaModel::periodoPublicableConNotas`, la misma que desactiva los botones
 * en la ficha de matrícula.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="base-url" content="<?= rtrim(url(''), '/') ?>">
    <title>Sin calificaciones oficiales — SIGA-COCIAP</title>
    <link rel="stylesheet" href="<?= url('css/errores.css') ?>">
</head>
<body>
    <div class="error-page">
        <h1>Aún no tiene calificaciones oficiales</h1>
        <p>
            El estudiante todavía no tiene competencias aprobadas y bloqueadas en un
            bimestre con boleta. La boleta estará disponible cuando las tenga.
        </p>
        <a href="#" class="btn-boleta--cerrar" aria-label="Cerrar esta ventana">✕ Cerrar</a>
    </div>
    <script src="<?= url('js/print-fit.js') ?>"></script>
</body>
</html>
