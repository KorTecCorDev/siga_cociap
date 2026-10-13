/**
 * rectificaciones-asistencia.js — SIGA-COCIAP
 * Vista /rectificaciones/asistencia (F5, 13/10/2026): el motivo de la
 * justificación solo se pide para FJ y TJ, y entonces es obligatorio. El
 * servidor lo vuelve a exigir (AsistenciaModel::rectificarDia y el CHECK
 * chk_justificada_con_motivo).
 */
(function () {
    var form = document.getElementById('rectAsistenciaForm');
    if (!form) return;

    var marca  = form.querySelector('#marca');
    var grupo  = form.querySelector('[data-rect-motivo-just]');
    var motivo = form.querySelector('#motivo_id');
    if (!marca || !grupo || !motivo) return;

    function sincronizar() {
        var justificada = marca.value === 'FJ' || marca.value === 'TJ';
        grupo.hidden    = !justificada;
        motivo.required = justificada;
        motivo.disabled = !justificada;
    }

    marca.addEventListener('change', sincronizar);
    sincronizar();
})();
