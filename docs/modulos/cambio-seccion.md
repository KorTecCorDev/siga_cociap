# Cambio de sección

> **Estado: PLAN REDISEÑADO Y APROBADO por el usuario el 07/10/2026. NADA construido.**
> **Reemplaza al plan del 09/07/2026**, que nunca se construyó y quedó desactualizado frente a
> los módulos 066–074 (auxiliares, confirmación, asistencia por fechas, tutor por periodo,
> retorno con tramo, borradores). Varias de sus decisiones se **derogaron** (ver § 3).
> Hoy un cambio de sección solo se hace a mano en la BD: no hay rutas, ni
> `CambioSeccionModel`, ni tablas `cambios_seccion*`, ni ningún `UPDATE` de
> `matriculas.seccion_id`.
> Estado vivo y prioridades: `docs/ESTADO.md`. Módulos que toca: `matriculas.md`,
> `calificaciones.md`, `boletas.md`, `admin.md`, `retorno-grado.md`,
> `confirmacion-y-asistencia-por-fechas.md`, `notificaciones.md`.
>
> **Migración: `075_cambio_seccion.sql`** (decidido el 07/10/2026). La reserva de la `053`
> queda LIBERADA: usarla habría puesto el esquema «antes» de la 054–074 en el setup.

## 1. Motivo del negocio

Los cambios de sección se dan por muchos motivos (convivencia, conducta, nivel) y **en
cualquier momento**, según la urgencia. Cada cambio está ordenado por una **Resolución
Directoral** (R.D.), que se emite fuera de SIGA.

Lo que el colegio necesita:

- que el cambio quede registrado con su sustento (R.D.);
- que los **docentes de la sección nueva puedan VER las calificaciones** que el estudiante
  obtuvo en su sección anterior, sin poder editarlas;
- que la autonomía de cada docente se respete: nadie recibe en su carga notas que no puso.

## 2. Los hechos técnicos que mandan

1. **«Confirmada» y «bloqueada» NO son estados del estudiante.** `criterios.confirmado_en`
   es del **criterio** y `bloqueos_competencia` es de la **carga** `(carga, competencia,
   periodo)`. Por eso una nota no se puede «llevar» a otra carga sin cambiarle de dueño.
2. **Si las notas del bimestre en curso se quedan vivas en la carga de origen, se filtran a
   la boleta**: cuando el docente de origen bloquea, el bloqueo matchea también al estudiante
   que se fue, y si destino ya calificó, la competencia sale **duplicada**. Por eso, en el
   bimestre abierto, se **archivan y retiran**.
3. **Hoy la lista de estudiantes de una sección es la de su sección ACTUAL**
   (`getResumenCompetencia`, `CalificacionModel.php`, `WHERE s.id = sección de la carga`),
   para cualquier bimestre. Medido con los casos reales: en el historial del I Bimestre, el
   docente de **destino** ve al estudiante **con las celdas vacías** y el de **origen ya no
   lo ve**, aunque su nota siga saliendo en la boleta.
4. **Lo que es de la MATRÍCULA viaja solo** (la matrícula es la misma):
   `conducta_respuestas`, `inasistencias`, `asistencia_incidencias`,
   `conclusiones_transversales`, `exoneraciones`, `notas_autorizadas_siagie`,
   `notas_externas`. **Lo que es de la SECCIÓN no viaja** y no debe viajar (es historia de
   esa sección): `asistencia_jornadas`, `cierres_conducta`, `cierres_transversales`,
   `auxiliar_secciones`, `tutor_periodo`, criterios y bloqueos. **El único problema real es
   lo que es de la CARGA**: notas por criterio, promedios, omisiones y transversales
   registradas por carga.
5. Medido el 07/10/2026: **67 de 119 combinaciones (grado, área) tienen docentes DISTINTOS
   por sección (56 %)**. Que el docente de destino sea otro es el caso mayoritario, no un
   caso borde.

## 3. Decisiones cerradas (07/10/2026 — no re-preguntar)

1. **Misma matrícula** (`UPDATE matriculas.seccion_id`), mismo grado y año.
2. **Bimestres CERRADOS:** las notas bloqueadas **se quedan en la carga de origen** (boleta,
   acta y mérito ya las leen bien desde allí) y los **docentes de destino las VEN, solo
   lectura**.
3. **Bimestre ABIERTO:** **no se toma ninguna nota**, ni siquiera las aprobadas y
   bloqueadas. Se **archivan con copia** (criterio, competencia y omisiones) y se retiran de
   las tablas vivas. **Destino califica de cero**, sin botón para convalidar.
   ⇒ **Deroga** el candado del plan de julio («no mover si hay ≥1 competencia bloqueada») y
   el botón «Convalidar».
4. El docente de destino **ve las notas archivadas del bimestre abierto como referencia**, en
   un bloque visualmente separado e inconfundible («No oficiales — archivadas al cambiar de
   sección»). Requisito del usuario: **acceso fácil y sin posibilidad de confusión**.
5. **La vista es una pantalla aparte, de solo lectura, centrada en la CARGA ACADÉMICA**: el
   docente ve lo que corresponde a sus cargas, **con las transversales y el detalle por
   criterio**.
6. **Causa raíz: la lista de estudiantes se arma POR BIMESTRE.** El historial de origen
   conserva al estudiante en los bimestres que cursó allí y el de destino no le muestra una
   fila vacía (§ 6).
7. **R.D.:** el número, la fecha y el motivo son obligatorios. **SIGA no genera el
   documento** (a diferencia del traslado, que emite su *Constancia de traslado* con
   correlativo). **Nomenclatura = la del traslado** (decisión del 07/10/2026):
   `N° 000-{AÑO}-CAVVG-DA`. Se arma SIEMPRE con `TrasladoModel::formatearNumero()` y el
   sufijo de `config('institucion_datos')['sufijo_constancia']`; el formulario pide solo el
   correlativo, nunca el texto libre, para que no diverja del formato.
   **La numeración es COMPARTIDA con las constancias de traslado** y admite cualquier
   número libre; un cambio revertido LIBERA el suyo. **La reversión no lleva R.D.**: solo
   el motivo. Regla completa en `matriculas.md` § «Numeración COMPARTIDA de R.D.».
8. **Avisos** (con `NotificacionModel`): tutores, docentes y auxiliares de origen y de
   destino, y los directores (`ROLES_DIRECCION`).
9. **Reversión SÍ, recuperando las notas archivadas.** Lo que haya registrado destino
   también se archiva, así que no se pierde nada. Cierra el único punto abierto del plan de
   julio.
10. **Asistencia por fechas:** todo el bimestre se ve en la sección de destino, sin fecha
    efectiva. Los totales no cambian (las incidencias son por matrícula).
11. **Retorno de grado:** no se puede cambiar de sección una matrícula **operativa** ni una
    **oficial con retorno vigente**. Una oficial con retorno **revertido** sí se puede.
12. **Las matrículas 259 y 339 se registran en el módulo**, vigentes desde el II Bimestre,
    **con su R.D. real, ambas del 01/09/2026**:
    **339 (GALICIA MENDOZA, hoy 4.° B) → R.D. `N° 059-2026-CAVVG-DA`** y
    **259 (DIEGO LOPEZ, hoy 4.° A) → R.D. `N° 060-2026-CAVVG-DA`**.
    ⚠️ La R.D. es del III Bimestre pero **el cambio rige desde el II** (las dos ya tienen
    notas del II en su sección actual): la R.D. formalizó un cambio hecho antes. El
    `periodo_id` sale de los DATOS, no de `rd_fecha`.

13. **Revertir ≠ regresar** (07/10/2026). **Revertir** = deshacer un cambio **mientras su
    bimestre sigue abierto** (como si no hubiera ocurrido). Si ese bimestre **ya cerró**,
    volver a la sección anterior es un **cambio NUEVO** (el *regreso*): el bimestre cerrado
    queda en la sección donde se cursó. Escenario del usuario: A en I–II, B en el III,
    regreso a A en el IV → I, II y IV del primer docente, III del segundo (probado en
    `verif_cambio_seccion.php` § 5b). `CambioSeccionModel::tipoDeMovimiento()` decide entre
    `normal`, `regreso` y `revertir`; `ejecutar()` rechaza la vuelta dentro del mismo
    bimestre y remite a revertir.
14. **El regreso NO lleva R.D.**: solo el motivo, y no ocupa número (`rd_*` en NULL; el CHECK
    `chk_cs_rd_completa` exige los tres llenos o los tres vacíos). Un cambio `normal` sin R.D.
    se rechaza.
15. Al regresar, el docente de la sección original **NO ve sus propias notas parciales** del
    bimestre en que el estudiante se fue (quedan en el archivo, solo para auditoría). Ve lo
    oficial del bimestre cursado fuera y, como referencia, lo archivado del bimestre abierto.
16. **Revertir DESCONFIRMA** los criterios que reciben notas restauradas (invariante
    «`criterios.confirmado_en` es la única verdad de oficial»); el docente vuelve a confirmar.

17. **Revertir con competencias BLOQUEADAS en origen: NO se permite** (opción a, 07/10/2026).
    Si en origen ya se bloqueó una competencia que recibiría notas restauradas, la reversión se
    rechaza y el aviso nombra esas competencias; se desbloquea primero con el flujo de siempre.
    La reversión nunca toca una competencia oficial. En DESTINO no hay guarda: lo suyo se
    archiva igual que cuando un estudiante llega (decisión 3).
    ⚠️ **Costo medido** (verificador, matrícula 162): revertir desconfirmó **8 criterios** de
    origen (6 con nota y 2 con omisión: restaurar una omisión también muta el criterio). Mientras
    el docente no los reconfirme, el resumen de esas competencias no está disponible para la
    sección entera. El aviso al docente de origen (fase 2) debe decirlo.

⚠️ **«Archivo del cambio» NO es la tabla de borradores.** `borradores_formulario` (074) guarda
lo tecleado en un formulario y se BORRA tras el guardado oficial; `cambios_seccion_*` es un
archivo PERMANENTE (auditoría, referencia y reversión). Se archiva TODO lo del bimestre
abierto, también lo bloqueado (decisión 3).

## 3b. Lo construido (fases 1 y 2, 07/10/2026)

- **Modelo** (`CambioSeccionModel`): `seccionDelPeriodo` / `sqlSeccionDelPeriodo`,
  `motivoNoElegible`, `tipoDeMovimiento`, `ejecutar`, `motivoNoRevertible`,
  `bloqueosQueImpidenRevertir`, `revertir`, `avisar`, `periodoCerrado`.
- **Rutas** (solo POST; el formulario vive en el detalle):
  `POST /matriculas/{id}/cambiar-seccion` y `POST /matriculas/{id}/cambiar-seccion/revertir`
  → `Matricula\CambioSeccionController` (admin y registro académico; CSRF; avisos FUERA de la
  transacción).
- **Pantalla** `/matriculas/{id}`:
  - card «Cambios de sección» (historial a la vista, con su reversión desplegable mientras el
    bimestre del cambio siga abierto, o el aviso de por qué no se puede);
  - en «Gestión de la matrícula», la acción «Cambiar de sección» (destino, N.° y fecha de R.D.
    con el número sugerido, motivo). La opción de **regreso** se marca en el select y el JS
    **deshabilita** los campos de R.D. (no se envían: no ocupa número). La vuelta en el mismo
    bimestre abierto no se ofrece como destino: es la reversión.
- **Avisos** (`NotificacionModel::TIPO_CAMBIO_SECCION`): un aviso por persona, sin el emisor;
  texto distinto para origen, destino y dirección. Todavía **sin enlace**: el de los docentes
  de destino apuntará a la vista de solo lectura de la fase 3.
- **Verificador** `verif_cambio_seccion.php` (§ 1–6, transacción + rollback).

## 4. Casos reales en la BD (medido el 07/10/2026)

- **Hay 2 cambios de sección hechos a mano**, un intercambio dentro de 4.° grado: la matrícula
  259 (hoy en 4.° A) cursó el I Bimestre en 4.° B, y la 339 (hoy en 4.° B) lo cursó en
  4.° A. Sus notas del I Bimestre siguen bloqueadas en la carga de origen y salen bien en la
  boleta.
- ⚠️ **La matrícula 692 NO es un cambio de sección**, aunque tiene filas del I Bimestre en
  una carga de otra sección: es la **operativa del retorno n.° 1** (oficial 190, revertido
  el 05/10) y esas filas son **copias inertes** que dejó el registro del retorno el 21/06
  (`retorno-grado.md`). ⇒ **Toda detección, script o verificador de este módulo excluye
  las matrículas presentes en `retornos_grado`.** Compararlas solo por
  «sección de la matrícula ≠ sección de la carga» las clasifica mal.

## 5. Diseño

### 5.1 Datos (migración `075`)

- `cambios_seccion` (el evento): `matricula_id`, `anio_id`, `periodo_id` (desde qué
  bimestre rige el destino), `seccion_origen_id`, `seccion_destino_id`, `rd_numero`,
  `rd_fecha`, `motivo`, `estado ENUM('vigente','revertido')`, `movido_por/en`,
  `revertido_por/en`, `motivo_reversion`.
- `cambios_seccion_competencia`, `cambios_seccion_criterio` y `cambios_seccion_omision`:
  copia de lo retirado del bimestre abierto, con `lado ENUM('origen','destino')` para la
  reversión simétrica. El criterio queda **denormalizado** (nombre, carga, competencia),
  con su FK a `criterios` en `ON DELETE SET NULL`.

### 5.2 Punto único — `CambioSeccionModel`

- `seccionDelPeriodo(matriculaId, periodoId)` y `sqlSeccionDelPeriodo($m, $colPeriodo)`:
  usan el destino del último cambio vigente con `periodo ≤ P` (por `orden`); si no hay, el
  origen del primer cambio posterior; si no hay ningún cambio, `m.seccion_id`. Mismo modelo
  que `RetornoGradoModel::sqlRosterDelPeriodo`.
- `elegible`: estado de la matrícula, retorno (decisión 11), destino del mismo grado y año,
  distinto del actual.
- `ejecutar`, en TRANSACCIÓN: INSERT del evento → copia → DELETE de las filas vivas del
  bimestre abierto en las cargas de origen → `UPDATE seccion_id` →
  `OrdenMeritoModel::sincronizarRosterPorMatricula`. Los avisos van **fuera** de la
  transacción (patrón de las notas de origen: que falle un aviso no tumba un registro
  válido).
- `revertir`, en TRANSACCIÓN: archiva lo de destino, restaura lo de origen (avisa si un
  criterio ya no existe), vuelve la sección y marca el cambio como `'revertido'`.
- `notasCursadasEnOtraSeccion(matriculaId, cargaDestinoId)`: por cada bimestre cerrado
  cursado en otra sección, ubica la carga equivalente de origen (misma área o subárea) y
  arma bloques {competencia (incl. transversales), criterios, notas, omisiones, conclusión}
  reutilizando `bloquesBloqueadosDeCarga` / `getResumenCompetencia` filtrados a una sola
  matrícula. Fuente oficial: `calificaciones` con INNER JOIN a `bloqueos_competencia` (la
  misma regla que la boleta). Le suma, aparte, el bloque de referencia archivado.

### 5.3 Interfaz

- `/matriculas/{id}`, card «Gestión de la matrícula»: fila desplegable «Cambiar de sección»
  (destino, N.° de R.D., fecha de la R.D. y motivo, todos obligatorios) y el bloque «Revertir»
  si hay un cambio vigente. Mismo patrón que Desactivar y Exonerar.
- Docente: `GET /docente/calificaciones/{carga}/procedencia/{matricula}`, de solo lectura.
  Acceso: `validarCargaDocente` + pertenencia de la matrícula a la sección de la carga.
  Arriba, la tarjeta de procedencia (sección de origen, R.D. y fecha); luego los bimestres
  cerrados (oficiales) y al final, aparte y con su propio rótulo, la referencia archivada.
  Se llega por un **chip** en la fila del estudiante (grilla e historial), sin reestructurar
  la tabla, y por el enlace de la notificación. Patrón a imitar:
  `/docente/notas-origen/{matricula}` (`PanelController::notasOrigen`).
- Rutas literales ANTES de los patrones `{param}`. SASS en `resources/sass/`, sin CSS
  inline, comillas ASCII y luego `gulp build`.

## 6. Lista de estudiantes por bimestre — INVENTARIO (07/10/2026)

Medido el 07/10/2026: **111 líneas en 91 funciones** cruzan la matrícula con su sección
(`m.seccion_id` y equivalentes; búsqueda: `(m|mat|m2|mm|mo)\.seccion_id|matriculas\.seccion_id`
más 3 guardas sin alias). Criterio: ¿la consulta pregunta por **HOY** o por **UN BIMESTRE**
(recibe un periodo, o lee notas, asistencia, conducta o cierres de un periodo)?

En el bimestre en curso las dos respuestas coinciden (la sección del periodo abierto es la
actual), así que convertir una consulta DEL PERIODO **no cambia nada hoy**: solo corrige los
bimestres cerrados cursados en otra sección. PUNTO ÚNICO: `sqlEnSeccionDelPeriodo` /
`sqlSeccionDelPeriodo`.

### 6.1 HOY — no se tocan (≈ 45 funciones)

Gestión, identidad y documentos que se entregan HOY: `MatriculaModel` (listar, contar,
nómina, resumen, cuadro, `findById`, sugerir sección, año anterior), `NominaModel`,
`SeccionModel::listarConTutor`, `EstudianteModel::buscarEnAnioActivo`, `TrasladoModel`,
`ApoderadoModel::getHijos`, `ExoneracionController` (index, revocar),
`ExoneracionModel::getParaSeccion` / `getAlumnosSeccion` (la exoneración es anual),
`ControlOperativoModel::matriculasPendientes`, `RectificacionModel::getMatriculaInfo`,
cabeceras de boleta (`BoletaModel::getAlumno`, `BoletaPublicaController::getAlumno`,
`BoletaController::resolverBoletaDocente` / `resolveToken`: la boleta es anual y nombra la
sección de hoy), `CalificacionModel::estructuraCompetenciasSeccion` (el plan es del grado),
`Padre\PanelController` (getHijo, contextoMerito), `ConductaModel::contextoMatricula`,
`AuxiliarSeccionModel::seccionesConHijos`, accesos del docente
(`NotaExternaModel::docenteTieneAcceso` / `areasDelDocenteEnSeccion`, `procedencia`,
`notasOrigen`), `NotificacionModel::crearParaDocentesDeSeccion`, todo lo de retorno de grado
(`mo.seccion_id`: `RetornoGradoController`, `MatriculaController::show`,
`SituacionFinalModel::areasQueCuentan` / `ubicacionOficialRetornos`), las 3 guardas de
escritura del tutor (`TutoriaController` ×2, `ConductaTutorController`: escriben en el
bimestre en curso) y los conteos por GRADO del mérito (`gradosConRanking`,
`gradosConEmpatesPendientesDetalle`, `Director\OrdenMeritoController::getConteosGrado`:
el grado no cambia).

### 6.2 DEL PERIODO — se convierten (≈ 40 funciones), por módulo

| # | Módulo | Funciones | Por qué importa |
|---|---|---|---|
| A ✅ | **Boleta (datos)** | `ConductaModel::getParaBoleta` / `getParaPeriodo` (cierre de conducta), `CalificacionModel::getTransversalesAgregadas` (cierre de transversales), `BoletaModel::getTutorSeccion`, `BoletaPublicaController::getTutorSeccion` | Un bimestre cursado en otra sección se publica con el **cierre y el tutor de ESA sección** (invariante «documentos = el tutor del bimestre»). Hoy saldría con guion o con el tutor equivocado. |
| B | **Rectificación / extraordinarias** | `RectificacionModel::sqlInsertables`, `matriculasSinNotasEnCerrados`, `sqlTransversalesInsertables` | Hoy ofrecería insertar notas de un bimestre cerrado en las cargas de la sección ACTUAL, no en las de donde lo cursó. |
| C | **Acta SIAGIE** | `SiagieExportModel::estudiantesDeSeccion` / `estudiantesDeOtrasSecciones` (hoy SIN periodo: hay que dárselo) | El acta de un bimestre lista a quien lo cursó en esa sección. |
| D | **Tutoría** | `TutoriaController::getAlumnosSeccion`, `TransversalModel::getPromediosSeccion` / `getConclusionesSeccion`, `ConclusionReplicaModel::getSeccion` | El panel del tutor de un bimestre cerrado. |
| E | **Conducta** | `ConductaModel::getEstudiantesParaRegistro`, `getEstudiantesParaTutor`, `completitudSeccion`, `getLiteralesLegado`, `getRegistroLegado`, `getProgresoConductaPorSeccion`, `getIncumplimientoCriterios` | Registro y cierre de conducta por bimestre. |
| F | **Asistencia** | `AsistenciaModel::getEstudiantesConIncidencias`, `getProgresoPorSeccion`, `getIncidenciasPorSeccion`, `getTopIncidenciasPorSeccion`, `AsistenciaJornadaModel::incidenciasDelDia`, `AsistenciaEstadisticaModel::roster` | Decisión 10: el bimestre se ve en la sección donde rigió el cambio. |
| G | **Calificaciones del docente y cierre** | `Docente\CalificacionController::getAlumnosSeccion`, `ExoneracionModel::getActivasParaCarga` (EXO en un historial pasado), `ControlOperativoModel::alertasEvaluacionIncompleta`, `AnioAcademicoModel::competenciasVaciasDelPeriodo` | Grilla, historial y la compuerta del cierre. |
| H | **Mérito y situación final** | `OrdenMeritoModel::rankingPorSeccionLive`, `rankingGradoLive` / `calcularFilasRanking` (etiqueta de sección), `DesempateMeritoModel::getActaPorPeriodo`, `SituacionFinalModel::rosterDelPeriodo`, `planPorMatricula`, `transversalesDelActa`, `fueraDelColegio` | Ranking por sección y acta del bimestre. Snapshots publicados: INTOCABLES. |
| I | **Estadísticas** | `AnioAcademicoModel::getResumenBimestre` / `getEvolucionAnual`, `AsistenciaModel::getEvolucionIncidenciasAnual`, `ConductaModel::getDistribucionLiteralesAnual` | Cifras por sección y bimestre. |

### 6.3 Decisión cerrada (07/10/2026)

- **Lote de boletas por sección = la sección de HOY** (`BoletaPublicaModel`:
  `getMatriculasAprobadasParaBoleta`, `getEstudiantesParaPeriodo`, `getSeccionesParaPeriodo`,
  `getPorPeriodo` → quedan en 6.1). El lote es lo que el tutor ACTUAL entrega; los DATOS de
  cada bimestre ya salen de donde se cursó (bloque A). Orden de conversión: A → I, un commit
  y un A/B por bloque.

**Bloque A HECHO (07/10/2026).** `BoletaPublicaController::getTutorSeccion` NO se tocó: sus
rutas están comentadas (boleta pública dormida). A/B: 2148 boletas (537 matrículas × 4
bimestres) idénticas a `HEAD`; con los datos reales no cambia nada porque la boleta firma con
el tutor del ÚLTIMO bimestre con datos. El efecto lo prueba `verif_seccion_del_periodo.php` § 4
con un escenario que anula los cierres de destino: pasa con el código nuevo y FALLA con `HEAD`.

### 6.4 Cómo se verifica

Cada bloque se convierte con un **A/B contra `HEAD`**: sin cambios de sección las salidas
deben ser idénticas, y con los dos casos reales (259, 339) solo pueden diferir en
4.° A / 4.° B de primaria y en el I Bimestre. Lo protege `verif_seccion_del_periodo.php`.

## 7. Orden de construcción

1. Migración + `CambioSeccionModel` (ejecutar, revertir y el punto único).
2. Interfaz de la matrícula + avisos.
3. Vista del docente + chip.
4. Lista por bimestre: inventario → cambio de las consultas DEL PERIODO → verificador.
5. Registro de las matrículas 259 y 339 con su R.D. real, por script en simulación y
   `--confirmar` (patrón de `aplicar_054`).
6. Documentación: este doc, `matriculas.md`, `calificaciones.md` y `ESTADO.md`.

## 8. Verificación (al construir)

- Verificador con transacción y rollback: cambio en el bimestre abierto → boleta sin
  duplicados ni fuga, origen sin filas vivas, destino vacío, copia completa; revertir →
  todo restaurado y lo de destino archivado.
- Las dos ramas de cada guarda (retorno vigente, mismo grado, R.D. vacía).
- Historial del I Bimestre de 4.° A y 4.° B con las matrículas 259 y 339, antes y después:
  origen las recupera y destino no muestra fila vacía.
- Contadores de asistencia y conducta confirmada idénticos antes y después.
- Navegador: vista de procedencia con una sesión de DOCENTE (no admin), chip y notificación.

## 9. Pendiente del usuario antes de construir

Nada. Datos completos el 07/10/2026.
