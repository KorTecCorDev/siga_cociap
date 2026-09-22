<?php

/**
 * Rutas web — SIGA-COCIAP
 * Todas las rutas de la aplicación definidas aquí.
 * Convención: GET para mostrar, POST para procesar.
 *
 * Formato: $router->get('/ruta', 'Namespace/Controlador@metodo')
 */

use Core\Router;

/** @var Router $router */

// ─── Autenticación ──────────────────────────────────────────
$router->get( '/login',          'Auth\AuthController@showLogin');
$router->post('/login/procesar', 'Auth\AuthController@login');
$router->get( '/logout',         'Auth\AuthController@logout');

// ─── Dashboard ──────────────────────────────────────────────
$router->get('/',          'DashboardController@index');
$router->get('/dashboard', 'DashboardController@index');

// ─── Notificaciones (bandeja interna, migración 058) ─────────
// La bandeja la ve cualquier usuario autenticado y solo muestra LAS SUYAS
// (el modelo filtra por usuario_id también al marcar leída). Redactar
// comunicados es de admin/registro_academico: el gate va POR MÉTODO, porque
// los tres roles de dirección son SOLO LECTURA y sí entran a su bandeja.
// Todas literales: no hay patrón {id} en este bloque.
$router->get( '/notificaciones',              'NotificacionController@index');
$router->post('/notificaciones/leer',         'NotificacionController@leer');
$router->post('/notificaciones/leer-todas',   'NotificacionController@leerTodas');
$router->get( '/notificaciones/comunicado',   'NotificacionController@comunicado');
$router->post('/notificaciones/comunicado',   'NotificacionController@guardarComunicado');
$router->get( '/notificaciones/enviados',     'NotificacionController@enviados');

// ─── Admin — Currículo Académico ────────────────────────────
$router->get( '/admin/curriculum',                                  'Admin\CurriculumController@index');
$router->post('/admin/curriculum/areas/{id}/editar',               'Admin\CurriculumController@guardarArea');
$router->post('/admin/curriculum/areas/{id}/toggle',               'Admin\CurriculumController@toggleActivaArea');
$router->post('/admin/curriculum/areas/{id}/mover',                'Admin\CurriculumController@moverArea');
$router->post('/admin/curriculum/subareas/{id}/editar',            'Admin\CurriculumController@guardarSubarea');
$router->post('/admin/curriculum/competencias/{id}/editar',        'Admin\CurriculumController@guardarCompetencia');

// ─── Admin — Cuadros estadisticos (tablero de direccion) ────
// Solo lectura. COMPONE los indicadores que ya calculan otros modelos; no
// tiene consultas propias ni reimplementa ninguna regla de negocio.
// El imprimible va ANTES: el router ancla por orden de registro.
$router->get( '/admin/cuadros/imprimir',      'Admin\CuadrosEstadisticosController@imprimir');
$router->get( '/admin/cuadros',               'Admin\CuadrosEstadisticosController@index');

// ─── Admin — Centro de Control Operativo ────────────────────
$router->get( '/admin/control',               'Admin\ControlOperativoController@index');
// Orden de merito RECTIFICADO (no oficial, no publicado): vista de solo lectura.
$router->get( '/admin/control/{periodo_id}/orden-merito-rectificado', 'Admin\ControlOperativoController@ordenMeritoRectificado');
// Cierre de bimestre — Hito A (aprobar boletas -> borrador para docentes).
$router->post('/admin/control/{periodo_id}/aprobar-bimestre',   'Admin\ControlOperativoController@aprobarBimestre');
$router->post('/admin/control/{periodo_id}/anular-aprobacion',  'Admin\ControlOperativoController@anularAprobacion');
// Compuerta de publicacion de boletas a las familias (migracion 044). Cerrar el
// bimestre NO publica: publicar es un acto separado, por nivel y con fecha/hora.
$router->post('/admin/control/{periodo_id}/publicar',           'Admin\ControlOperativoController@publicar');
$router->post('/admin/control/{periodo_id}/programar',          'Admin\ControlOperativoController@programar');
$router->post('/admin/control/{periodo_id}/despublicar',        'Admin\ControlOperativoController@despublicar');

// ─── Admin — Actas SIAGIE (llenado de plantillas RegNotas) ──
$router->get( '/admin/actas-siagie',                     'Admin\ActasSiagieController@index');
$router->get( '/admin/actas-siagie/vinculos',            'Admin\ActasSiagieController@vinculos');
$router->post('/admin/actas-siagie/previsualizar',       'Admin\ActasSiagieController@previsualizar');
$router->get( '/admin/actas-siagie/reporte',             'Admin\ActasSiagieController@reportePreview');
$router->post('/admin/actas-siagie/confirmar',           'Admin\ActasSiagieController@confirmar');
$router->get( '/admin/actas-siagie/resultado',           'Admin\ActasSiagieController@resultado');
$router->get( '/admin/actas-siagie/resultado/descargar', 'Admin\ActasSiagieController@descargar');
$router->get( '/admin/actas-siagie/resultado/reporte',   'Admin\ActasSiagieController@reporteFinal');

// ─── Admin — Secciones y Tutores ────────────────────────────
$router->get( '/admin/secciones',             'Admin\SeccionController@index');
$router->post('/admin/secciones/{id}/tutor',  'Admin\SeccionController@asignarTutor');

// ─── Admin — Buscador de estudiantes ────────────────────────
$router->get( '/admin/buscar-estudiante',     'Admin\BuscadorEstudianteController@index');
$router->get( '/admin/buscar-estudiante/api', 'Admin\BuscadorEstudianteController@buscar');

// ─── Admin — Conducta ───────────────────────────────────────
$router->get( '/admin/conducta',              'Admin\ConductaController@index');
$router->post('/admin/conducta/guardar',      'Admin\ConductaController@guardar');
$router->post('/admin/conducta/{id}/bloquear','Admin\ConductaController@bloquear');
$router->get( '/admin/conducta/{id}/imprimir/{periodo_id}', 'Admin\ConductaController@imprimir');
$router->get( '/admin/conducta/{id}',         'Admin\ConductaController@seccion');

// ─── Admin — Asistencia (incidencias) ───────────────────────
$router->get( '/admin/asistencia',            'Admin\AsistenciaController@index');
$router->post('/admin/asistencia/{id}/bloquear','Admin\AsistenciaController@bloquear');
$router->get( '/admin/asistencia/{id}/imprimir/{periodo_id}', 'Admin\AsistenciaController@imprimir');
$router->get( '/admin/asistencia/{id}',       'Admin\AsistenciaController@seccion');
$router->post('/admin/asistencia/guardar',    'Admin\AsistenciaController@guardar');

// ─── Admin — Exoneraciones ──────────────────────────────────
$router->get( '/admin/exoneraciones',                         'Admin\ExoneracionController@index');
$router->get( '/admin/exoneraciones/{seccion_id}',            'Admin\ExoneracionController@seccion');
$router->post('/admin/exoneraciones/{seccion_id}/registrar',  'Admin\ExoneracionController@registrar');
$router->post('/admin/exoneraciones/{id}/revocar',            'Admin\ExoneracionController@revocar');

// ─── Admin — Director EBR ───────────────────────────────────
$router->get( '/admin/director-ebr',                       'Admin\DirectorEbrController@index');
$router->post('/admin/director-ebr/{anio_id}/asignar',     'Admin\DirectorEbrController@asignar');
$router->post('/admin/director-ebr/{id}/imagenes',         'Admin\DirectorEbrController@actualizarImagenes');

// ─── Admin — Usuarios ───────────────────────────────────────
$router->get( '/admin/usuarios',             'Admin\UsuarioController@index');
$router->get( '/admin/usuarios/crear',       'Admin\UsuarioController@create');
$router->post('/admin/usuarios/crear',       'Admin\UsuarioController@store');
$router->get( '/admin/usuarios/{id}/editar', 'Admin\UsuarioController@edit');
$router->post('/admin/usuarios/{id}/editar', 'Admin\UsuarioController@update');
$router->post('/admin/usuarios/{id}/estado', 'Admin\UsuarioController@toggleEstado');

// ─── Director — Año académico y bimestres ───────────────────
// Las rutas literales (crear) van ANTES del patrón {id} para que el router no capture "crear" como parámetro
$router->get( '/director/anios',                 'Director\AnioAcademicoController@index');
$router->get( '/director/anios/crear',           'Director\AnioAcademicoController@create');
$router->post('/director/anios/crear',           'Director\AnioAcademicoController@store');
$router->post('/director/anios/{id}/activar',    'Director\AnioAcademicoController@activar');
$router->post('/director/anios/{id}/cerrar',     'Director\AnioAcademicoController@cerrar');
$router->get( '/director/anios/{id}',            'Director\AnioAcademicoController@show');
// Bimestres
$router->post('/director/periodos/{id}/editar',  'Director\PeriodoController@editar');
$router->post('/director/periodos/{id}/abrir',   'Director\PeriodoController@abrir');
$router->post('/director/periodos/{id}/cerrar',  'Director\PeriodoController@cerrar');
$router->post('/director/periodos/{id}/reabrir', 'Director\PeriodoController@reabrir');
$router->get( '/director/periodos/{id}/stats',   'Director\PeriodoController@stats');

// ─── Secciones y cargas ──────────────────────────────────────
// Las secciones se administran en /admin/secciones (Admin\SeccionController).
// Aquí vivían 3 rutas a un `Director\SeccionController` que no existe en el
// repositorio; se retiraron el 22/08/2026 (ver el bloque de Matrícula).
$router->get( '/director/cargas',                          'Director\CargaAcademicaController@index');
$router->get( '/director/cargas/crear',                    'Director\CargaAcademicaController@create');
$router->post('/director/cargas/crear',                    'Director\CargaAcademicaController@store');
// La de 5 segmentos va ANTES que la de 4: el router ancla por orden de registro.
$router->get( '/director/cargas/seccion/{seccion_id}/horario', 'Director\CargaAcademicaController@horarioSeccion');
$router->get( '/director/cargas/seccion/{seccion_id}',     'Director\CargaAcademicaController@porSeccion');
$router->get( '/director/cargas/{id}/editar',              'Director\CargaAcademicaController@edit');
$router->post('/director/cargas/{id}/editar', 'Director\CargaAcademicaController@update');
$router->post('/director/cargas/{id}/estado', 'Director\CargaAcademicaController@toggleEstado');
// Reemplazo de docente en carga activa (auditoria por snapshot). Literales
// "reemplazar"/"reemplazos" distintas de editar/estado -> sin colision.
$router->get( '/director/cargas/{id}/reemplazar', 'Director\ReemplazoDocenteController@form');
$router->post('/director/cargas/{id}/reemplazar', 'Director\ReemplazoDocenteController@reemplazar');
$router->get( '/director/cargas/{id}/reemplazos', 'Director\ReemplazoDocenteController@historial');
$router->get( '/director/reemplazos/{id}/snapshot', 'Director\ReemplazoDocenteController@verSnapshot');

// ─── Matrícula ───────────────────────────────────────────────
// RUTAS FANTASMA RETIRADAS EL 22/08/2026 (auditoría de cierre de la 1.0).
// Aquí había 7 rutas a `Secretaria\MatriculaController` y `Director\
// MatriculaController`, más 3 a `Director\SeccionController` arriba: diez en
// total hacia tres controladores que NO existen en el repositorio.
//   * No reventaban —el router comprueba `class_exists`, registra el fallo y
//     devuelve un 404 limpio—, pero eran superficie registrada en producción,
//     y 4 de ellas figuraban como rutas POST sin CSRF en cualquier auditoría.
//   * El módulo de matrículas REAL es el de abajo (`Matricula\...`), que cubre
//     alta, aprobación y cambio de estado para admin y registro académico.
// Se retiró con ellas la entrada muerta del rol 'secretaria' en
// `AuthController::redirigirPorRol` — ver el comentario allí.

// ─── Módulo de Matrículas ────────────────────────────────────
// Las rutas literales (crear) van ANTES del patrón {id} para que el router
// no capture "crear" como parámetro. Lo mismo con los sub-recursos del {id}.
$router->get( '/matriculas',                     'Matricula\MatriculaController@index');
$router->get( '/matriculas/resumen',             'Matricula\MatriculaController@resumen');
$router->get( '/matriculas/resumen/imprimir',    'Matricula\MatriculaController@resumenImprimir');
$router->get( '/matriculas/nomina/imprimir',     'Matricula\MatriculaController@nominaImprimir');
$router->get( '/matriculas/crear',               'Matricula\MatriculaController@create');
$router->post('/matriculas/crear',               'Matricula\MatriculaController@store');
$router->post('/matriculas/{id}/estudiante',     'Matricula\MatriculaController@actualizarEstudiante');
$router->get( '/matriculas/{id}/apoderado',      'Matricula\MatriculaController@apoderado');
$router->post('/matriculas/{id}/apoderado',      'Matricula\MatriculaController@storeApoderado');
$router->get( '/matriculas/{id}/documentos',     'Matricula\MatriculaController@documentos');
$router->post('/matriculas/{id}/documentos',     'Matricula\MatriculaController@storeDocumentos');
$router->post('/matriculas/{id}/activar',        'Matricula\MatriculaController@activar');
$router->post('/matriculas/{id}/desactivar',     'Matricula\MatriculaController@desactivar');
$router->post('/matriculas/{id}/retirar',         'Matricula\MatriculaController@retirar');
$router->post('/matriculas/{id}/revertir-retiro', 'Matricula\MatriculaController@revertirRetiro');
// Traslado de salida (constancia oficial): formulario + registro.
$router->get( '/matriculas/{id}/trasladar',      'Matricula\TrasladoController@form');
$router->post('/matriculas/{id}/trasladar',      'Matricula\TrasladoController@store');
$router->get( '/matriculas/{id}/notas-externas', 'Matricula\MatriculaController@notasExternas');
$router->post('/matriculas/{id}/notas-externas', 'Matricula\MatriculaController@storeNotasExternas');
// Notas autorizadas por dirección para SIAGIE (informe aparte, solo admin/RA)
$router->get( '/matriculas/{id}/notas-siagie/informe',  'Matricula\MatriculaController@informeNotaSiagie');
$router->post('/matriculas/{id}/notas-siagie/eliminar', 'Matricula\MatriculaController@eliminarNotaSiagie');
$router->get( '/matriculas/{id}/notas-siagie',          'Matricula\MatriculaController@notasSiagie');
$router->post('/matriculas/{id}/notas-siagie',          'Matricula\MatriculaController@storeNotaSiagie');
// Exoneraciones desde el detalle (solo admin/RA — lo exige el controlador;
// candado de notas vivas incluido).
$router->post('/matriculas/{id}/exonerar',       'Admin\ExoneracionController@registrarDesdeMatricula');
// Retorno de grado
$router->get( '/matriculas/{id}/retorno/revertir', 'Matricula\RetornoGradoController@confirmarReversion');
$router->post('/matriculas/{id}/retorno/revertir', 'Matricula\RetornoGradoController@revertir');
$router->get( '/matriculas/{id}/retorno',        'Matricula\RetornoGradoController@create');
$router->post('/matriculas/{id}/retorno',        'Matricula\RetornoGradoController@store');
// Boleta INTERNA de gestion (admin/registro/secretarias): mismo flujo que la del
// docente (muestra BORRADOR mientras el bimestre no cierra). Distinta de la
// publica por token (/boleta/ver/{token}), que sigue mostrando SOLO lo oficial.
$router->get( '/matriculas/{id}/boleta/imprimir', 'Boleta\BoletaController@verImprimirMatricula');
$router->get( '/matriculas/{id}/boleta',          'Boleta\BoletaController@verDigitalMatricula');
// El detalle {id} va al FINAL para no capturar los sub-recursos anteriores.
$router->get( '/matriculas/{id}',                'Matricula\MatriculaController@show');

// ─── Rectificación de calificaciones (auditada) ──────────────
// Módulo general: corrige notas ya cerradas/bloqueadas con traza. Solo
// admin/registro_academico (gateado en el controlador). Las literales van
// ANTES del patrón {id} de matrícula y entre sí (editar/guardar antes de
// matricula/{id}) para que el router no capture mal los segmentos.
$router->get( '/rectificaciones',                 'Rectificacion\RectificacionController@index');
$router->get( '/rectificaciones/editar',          'Rectificacion\RectificacionController@editar');
$router->post('/rectificaciones/guardar',         'Rectificacion\RectificacionController@guardar');
$router->get( '/rectificaciones/extraordinaria',  'Rectificacion\RectificacionController@extraordinaria');
$router->post('/rectificaciones/extraordinaria/guardar', 'Rectificacion\RectificacionController@guardarExtraordinaria');
$router->get( '/rectificaciones/extraordinaria/lote', 'Rectificacion\RectificacionController@extraordinariaLote');
$router->post('/rectificaciones/extraordinaria/lote/guardar', 'Rectificacion\RectificacionController@guardarExtraordinariaLote');
$router->get( '/rectificaciones/matricula/{id}',  'Rectificacion\RectificacionController@matricula');

// ─── Consulta de calificaciones (solo lectura) ───────────────
// Supervision read-only por periodo -> seccion -> area/carga. Solo muestra
// lo oficial (bloqueado). admin/registro_academico/director (gateado en el
// controlador). Literales primero; las sub-rutas con dos params no chocan con
// la literal /consulta-notas por tener mas segmentos.
$router->get('/consulta-notas',                                   'Consulta\ConsultaNotasController@index');
// Literal de 2 segmentos: entrada del dashboard al explorador de criterios, que
// salta al bimestre por defecto. Va ANTES de cualquier patron {periodo_id}.
$router->get('/consulta-notas/criterios',                         'Consulta\ConsultaNotasController@criteriosInicio');
// Las dos de nivel SECCION van ANTES que la de 4 segmentos: mas especificas
// primero, el router ancla por orden de registro.
$router->get('/consulta-notas/{periodo_id}/seccion/{seccion_id}/transversales', 'Consulta\ConsultaNotasController@transversales');
// La grilla Si/No va ANTES que /conducta: mas segmentos, y el router ancla por
// orden de registro. Reusa la vista del tutor (ver conductaCriterios).
$router->get('/consulta-notas/{periodo_id}/seccion/{seccion_id}/conducta/criterios', 'Consulta\ConsultaNotasController@conductaCriterios');
$router->get('/consulta-notas/{periodo_id}/seccion/{seccion_id}/conducta',      'Consulta\ConsultaNotasController@conducta');
$router->get('/consulta-notas/{periodo_id}/seccion/{seccion_id}/asistencia',    'Consulta\ConsultaNotasController@asistencia');
$router->get('/consulta-notas/{periodo_id}/seccion/{seccion_id}', 'Consulta\ConsultaNotasController@seccion');
$router->get('/consulta-notas/{periodo_id}/carga/{carga_id}',     'Consulta\ConsultaNotasController@carga');
// Eje POR DOCENTE (24/08/2026). La literal /docentes va ANTES que el patron
// /docente/{id}: son prefijos distintos, pero el router ancla por orden de
// registro y conviene no depender de eso.
$router->get('/consulta-notas/{periodo_id}/docentes',             'Consulta\ConsultaNotasController@docentes');
$router->get('/consulta-notas/{periodo_id}/docente/{docente_id}', 'Consulta\ConsultaNotasController@docente');
// Explorador de CRITERIOS: seccion -> carga (area + docente) -> competencia ->
// criterio. /criterios/imprimir va ANTES que /criterios: mas segmentos primero.
$router->get('/consulta-notas/{periodo_id}/criterios/imprimir',   'Consulta\ConsultaNotasController@criteriosImprimir');
$router->get('/consulta-notas/{periodo_id}/criterios',            'Consulta\ConsultaNotasController@criterios');

// ─── Constancias de traslado (registro oficial) ──────────────
$router->get( '/traslados',                'Matricula\TrasladoController@index');
$router->get( '/traslados/{id}/imprimir',  'Matricula\TrasladoController@imprimir');
$router->post('/traslados/{id}/anular',    'Matricula\TrasladoController@anular');

// ─── Docente — Panel / Nómina ────────────────────────────────
$router->get( '/docente/inicio',                       'Docente\PanelController@index');
$router->get( '/docente/nomina',                       'Docente\PanelController@nomina');
$router->get( '/docente/nomina/{seccion_id}/imprimir', 'Docente\PanelController@nominaImprimir');

// Notas del COLEGIO DE ORIGEN de un estudiante de MI seccion (migracion 057).
// Solo lectura e informativas: NO salen en la boleta del COCIAP. La guarda
// (tener carga activa en esa seccion) esta en el controlador, que responde 404
// si no se cumple. Se llega desde la notificacion que genera Registro Academico.
$router->get( '/docente/notas-origen/{matricula}',     'Docente\PanelController@notasOrigen');
$router->get( '/docente/horario/imprimir',             'Docente\PanelController@horarioImprimir');
// Boletas del docente (validadas por nivel). La literal /imprimir va ANTES del
// patron generico para que el router no capture "imprimir" como matricula_id.
$router->get( '/docente/boleta/{matricula_id}/imprimir', 'Boleta\BoletaController@verImprimirDocente');
$router->get( '/docente/boleta/{matricula_id}',          'Boleta\BoletaController@verDigitalDocente');
// Orden de merito (lectura publica para el claustro). Dos flujos separados:
// orden de merito por GRADO (media beca) y ranking por SECCION (sin media beca).
$router->get( '/docente/orden-merito',                  'Docente\OrdenMeritoController@index');
$router->get( '/docente/orden-merito/{periodo_id}',     'Docente\OrdenMeritoController@porPeriodo');
$router->get( '/docente/ranking-seccion',               'Docente\OrdenMeritoController@seccionIndex');
$router->get( '/docente/ranking-seccion/{periodo_id}',  'Docente\OrdenMeritoController@seccionPorPeriodo');

// ─── Calificaciones ──────────────────────────────────────────
$router->get( '/docente/mis-cargas',                        'Docente\CalificacionController@misCargas');
// Vista de AREA (solo secciones unidocente): una pantalla por area con TODAS
// las subarea-cargas del area + transversales. La literal "area" distingue del
// patron base {carga_id}; las literales/largas van ANTES por orden de lectura.
$router->get( '/docente/calificaciones/area/{seccion_id}/{area_id}/historial/{periodo_id}', 'Docente\CalificacionController@historialArea');
$router->get( '/docente/calificaciones/area/{seccion_id}/{area_id}', 'Docente\CalificacionController@formularioArea');
// Historico del docente: grilla read-only de SU carga en un bimestre cerrado.
// 5 segmentos: no colisiona con el patron base de 3 (el router ancla ^...$).
$router->get( '/docente/calificaciones/{carga_id}/historial/{periodo_id}', 'Docente\CalificacionController@historial');
$router->get( '/docente/calificaciones/{carga_id}',         'Docente\CalificacionController@formulario');
$router->post('/docente/calificaciones/{carga_id}/guardar',   'Docente\CalificacionController@guardar');
$router->post('/docente/calificaciones/{carga_id}/autosave',  'Docente\CalificacionController@autosave');
$router->post('/docente/calificaciones/{carga_id}/omisiones', 'Docente\CalificacionController@guardarOmisiones');

// ─── Criterios ───────────────────────────────────────────────
$router->post('/docente/criterios/crear',             'Docente\CalificacionController@crearCriterio');
$router->post('/docente/criterios/{id}/renombrar',   'Docente\CalificacionController@renombrarCriterio');
$router->post('/docente/criterios/{id}/eliminar',    'Docente\CalificacionController@eliminarCriterio');
$router->post('/docente/calificaciones/conclusion', 'Docente\CalificacionController@guardarConclusion');


// ─── Panel padre ─────────────────────────────────────────────
$router->get('/padre/inicio',  'Padre\PanelController@index');
$router->get('/padre/notas',   'Padre\PanelController@notas');
$router->get('/padre/alertas', 'Padre\PanelController@alertas');
// Orden de merito de las familias (rediseno 2, fase 6): bajo la misma compuerta
// de publicacion que las boletas — publicar un nivel libera notas Y merito.
$router->get('/padre/orden-merito',    'Padre\PanelController@ordenMerito');
$router->get('/padre/ranking-seccion', 'Padre\PanelController@rankingSeccion');

// ─── Boletas públicas por CÓDIGO — DORMIDO (se conserva para reactivar) ──────
// El acceso público por código tecleado se jubiló en favor del QR por token
// (enlace permanente por estudiante). El controlador y las vistas
// (BoletaPublicaController, boleta-publica/*) se conservan intactos; basta
// re-registrar estas dos rutas ANTES de /boleta/{id} para reactivarlo:
//   $router->get( '/boleta-publica',           'BoletaPublicaController@formulario');
//   $router->post('/boleta-publica/consultar', 'BoletaPublicaController@consultar');

// ─── Firmas/sello del Director EBR (servido público desde almacenamiento externo) ───
$router->get('/firmas/{archivo}', 'FirmaController@servir');

// ─── Admin — Boletas públicas ────────────────────────────────
$router->get( '/admin/boletas-publicas',                             'Admin\BoletaPublicaController@index');
$router->post('/admin/boletas-publicas/generar-tokens',              'Admin\BoletaPublicaController@generarTokens');
$router->get( '/admin/boletas-publicas/{periodo_id}',                'Admin\BoletaPublicaController@porPeriodo');
$router->get( '/admin/boletas-publicas/{periodo_id}/vista-previa',   'Admin\BoletaPublicaController@vistaPrevia');
$router->get( '/admin/boletas-publicas/{periodo_id}/boletas-alumno', 'Admin\BoletaPublicaController@boletasAlumno');
$router->get( '/admin/boletas-publicas/{periodo_id}/archivar',       'Admin\BoletaPublicaController@archivar');
// Mismo ZIP de PDFs que 'archivar', pero con el documento EN BORRADOR (el de la
// vista previa) — para circularlo por Drive antes de cerrar el bimestre.
$router->get( '/admin/boletas-publicas/{periodo_id}/archivar-borrador', 'Admin\BoletaPublicaController@archivarBorrador');
// CÓDIGO dormido (se conserva para reactivar — métodos generar/actualizar/imprimir intactos):
//   $router->post('/admin/boletas-publicas/{periodo_id}/generar',    'Admin\BoletaPublicaController@generar');
//   $router->post('/admin/boletas-publicas/{periodo_id}/actualizar', 'Admin\BoletaPublicaController@actualizar');
//   $router->get( '/admin/boletas-publicas/{periodo_id}/imprimir',   'Admin\BoletaPublicaController@imprimir');

// ─── Boleta de calificaciones — SOLO por token (seguridad) ───
// Las rutas anónimas por id ({matricula_id}/{periodo_id}) se retiraron: eran
// enumerables. Todo acceso público es por token permanente (inadivinable).
// El acceso interno (docente/admin) va por sus rutas autenticadas con alcance.
$router->get('/boleta/digital/{token}', 'Boleta\BoletaController@verDigitalToken');
$router->get('/boleta/ver/{token}',     'Boleta\BoletaController@verToken');

// ─── Orden de mérito ─────────────────────────────────────────
$router->get('/director/orden-merito',                          'Director\OrdenMeritoController@index');
$router->get('/director/orden-merito/{periodo_id}/imprimir',    'Director\OrdenMeritoController@imprimir');
// Desempate: rutas literales ANTES del patrón genérico {periodo_id} para que el
// router no capture "desempate" como periodo.
$router->get('/director/orden-merito/{periodo_id}/desempate/{grado_id}',  'Director\OrdenMeritoController@desempate');
$router->post('/director/orden-merito/{periodo_id}/desempate/{grado_id}', 'Director\OrdenMeritoController@guardarDesempate');
// Acta de desempates: la mas especifica (/imprimir) antes que la de pantalla.
$router->get('/director/orden-merito/{periodo_id}/desempates/imprimir', 'Director\OrdenMeritoController@desempatesImprimir');
$router->get('/director/orden-merito/{periodo_id}/desempates',          'Director\OrdenMeritoController@desempates');
$router->get('/director/orden-merito/{periodo_id}',             'Director\OrdenMeritoController@porPeriodo');

// Ranking por SECCION para el staff (admin/RA/directores). Flujo hermano del de
// arriba y hay que no confundirlos: el de GRADO define la media beca, este es
// interno de cada seccion. A diferencia de /docente/ranking-seccion, este NO
// aplica la compuerta de publicacion: el staff lo necesita antes de publicar.
// Literal antes que el patron {periodo_id}, como en el bloque anterior.
$router->get('/director/ranking-seccion',                'Director\OrdenMeritoController@seccionIndex');
$router->get('/director/ranking-seccion/{periodo_id}',   'Director\OrdenMeritoController@seccionPorPeriodo');

// ─── Gestión de bloqueos ─────────────────────────────────────
$router->get( '/director/bloqueos',                     'Director\BloqueoController@index');
$router->post('/director/bloqueos/bloquear',             'Director\BloqueoController@bloquear');
$router->post('/director/bloqueos/limpiar-cierre',       'Director\BloqueoController@limpiarBloqueosCierre');
$router->post('/director/bloqueos/transversal/{seccion_id}/cerrar',  'Director\BloqueoController@cerrarTransversal');
$router->post('/director/bloqueos/transversal/{seccion_id}/reabrir', 'Director\BloqueoController@reabrirTransversal');
// Prefijo propio ('transversal-competencia') para no chocar con el patron de
// arriba: aqui el parametro es un bloqueo_id, no una seccion_id.
$router->post('/director/bloqueos/transversal-competencia/{bloqueo_id}/liberar', 'Director\BloqueoController@liberarTransversalCompetencia');
$router->post('/director/bloqueos/conducta/{seccion_id}/bloquear',   'Director\BloqueoController@bloquearConducta');
$router->post('/director/bloqueos/conducta/{seccion_id}/cerrar',     'Director\BloqueoController@cerrarConducta');
$router->post('/director/bloqueos/conducta/{seccion_id}/reabrir',    'Director\BloqueoController@reabrirConducta');
$router->post('/director/bloqueos/asistencia/{seccion_id}/bloquear', 'Director\BloqueoController@bloquearAsistencia');
$router->post('/director/bloqueos/asistencia/{seccion_id}/reabrir',  'Director\BloqueoController@reabrirAsistencia');
$router->post('/director/bloqueos/{id}/desbloquear',     'Director\BloqueoController@desbloquear');

// ─── Resumen y bloqueo de competencia ────────────────────────
$router->get(
    '/docente/calificaciones/{carga_id}/resumen/{competencia_id}',
    'Docente\CalificacionController@resumen'
);
$router->post(
    '/docente/calificaciones/{carga_id}/bloquear/{competencia_id}',
    'Docente\CalificacionController@bloquear'
);
$router->post(
    '/docente/calificaciones/{carga_id}/conclusion/{competencia_id}',
    'Docente\CalificacionController@guardarConclusionAlumno'
);

// ─── Tutoría — transversales y cierre del tutor ──────────────
$router->get( '/docente/tutoria',                          'Docente\TutoriaController@index');
$router->post('/docente/tutoria/{periodo_id}/conclusion',  'Docente\TutoriaController@guardarConclusion');
$router->post('/docente/tutoria/{periodo_id}/cerrar',      'Docente\TutoriaController@cerrar');
$router->get( '/docente/tutoria/{periodo_id}',             'Docente\TutoriaController@index');

// ─── Conducta — cierre del tutor (Etapa 2) ──────────────────
$router->get( '/docente/conducta',                         'Docente\ConductaTutorController@index');
$router->post('/docente/conducta/{periodo_id}/nota',       'Docente\ConductaTutorController@guardarNota');
$router->post('/docente/conducta/{periodo_id}/cerrar',     'Docente\ConductaTutorController@cerrar');
// Grilla Si/No de los auxiliares en SOLO LECTURA para el tutor (gate: cierre
// vigente de RA). Sin endpoints de escritura.
$router->get( '/docente/conducta/{periodo_id}/criterios',  'Docente\ConductaTutorController@criterios');
$router->get( '/docente/conducta/{periodo_id}',            'Docente\ConductaTutorController@index');
