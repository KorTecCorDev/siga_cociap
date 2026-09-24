# Módulo: Orden de mérito, snapshot y rectificaciones

> Documentado el 03/07/2026 al crear la red de documentación (este módulo se
> implementó el 17/06/2026 y no tenía sección propia en CLAUDE.md).
> Los invariantes globales viven en CLAUDE.md.
>
> **Rediseño 2 (aprobado 25/07/2026, SIN implementar): ver
> `orden-merito-rediseno.md`.** Este archivo describe el estado ACTUAL; se irá
> actualizando a medida que cada fase del rediseño entre en producción.

## Orden de mérito — snapshot al cerrar (17/06/2026)

El orden de mérito era 100% dinámico (se recalculaba en vivo desde `calificaciones`
+ estado actual de matrículas/secciones). Eso corrompía rankings de bimestres
cerrados ante reversión de retorno de grado, traslados o ediciones. Se convirtió
en documento oficial inmutable por snapshot.

### Reglas de negocio
- **Anclado POR BIMESTRE según dónde están las notas:** en retorno de grado activo
  el alumno compite en su sección OPERATIVA (grado inferior); tras revertir, los
  bimestres pasados quedan congelados en la operativa y desde el siguiente compite
  en su sección OFICIAL.
- **Nómina y boletas SIEMPRE muestran grado/sección OFICIAL** (nómina vía filtro en
  `PanelController::getMatriculados`; boletas vía `CalificacionModel::boletaContexto`:
  identidad = oficial, notas por unión [operativa, oficial]).
- **El snapshot solo se (re)genera al CERRAR** el bimestre. Reabrir para corregir
  notas SÍ actualiza el ranking (regenera al re-cerrar) **mientras el bimestre no
  haya sido publicado**; si ya lo estuvo, el oficial es inmutable y la corrección va
  a `orden_merito_rectificado` (candado 046). Una reversión con el bimestre cerrado
  nunca lo toca.
- **Empates resueltos ANTES de cerrar; bimestres se abren en orden cronológico.**
- **"Vigente"** (buscador, columna puesto de nómina) = snapshot del cerrado de
  mayor número < bimestre activo (`EstudianteModel::ultimoBimestreCerrado`).
- **Excluye áreas `tipo IN ('transversal','tutoria')`, con UNA excepción: ÉTICA Y
  VALORES cuenta en TODA secundaria, 5.º incluido (05/08/2026).** Ética **no es
  tutoría**: es la Educación Religiosa de secundaria, servida por la carga TOE porque
  el área homónima de ese nivel es un cascarón (0 cargas, 0 notas). Su `tipo='tutoria'`
  es un artefacto de implementación. Se ancla por `nombre_boleta`, **nunca por id**.
  Detalle, historia e impacto medido: ver la sección de auditoría más abajo.
- **El cálculo EN VIVO solo cuenta competencias BLOQUEADAS** (rediseño 2): join a
  `bloqueos_competencia`, sin filtrar `origen` (cuenta `docente` y `cierre`). En un
  bimestre cerrado no cambia nada (todo está bloqueado); en uno activo el ranking
  provisional refleja solo lo ya confirmado.
- **Cascada de desempate sin apellidos** (rediseño 2): tras `num_16` el desempate es
  MANUAL y el orden de presentación lo fija `m.id` (neutro y determinista).
- **PUNTO ÚNICO de "qué empates faltan" (desde el 04/08/2026):**
  `OrdenMeritoModel::gradosConEmpatesPendientesDetalle`. Lo consumen el guard del
  cierre (vía el wrapper `gradosConEmpatesPendientes`, que devuelve strings) y la card
  del Centro de Control (`ControlOperativoModel::empatesPendientes`, que ahora solo
  delega). **NUNCA reimplementar la cascada fuera de `aplicarDesempate`.**
  - **Por qué existe la regla:** el Centro de Control tuvo su propia copia desde el
    08/06/2026 (`detectarGruposIrreducibles`, hoy eliminada) que se quedó en la tupla
    de 3 conteos y nunca incorporó `num_alto`/`num_16` — añadidos al motor real ese
    mismo día, cuatro horas después (`d41c548`). Durante dos meses la card inventó
    empates que el motor deshace solo; y como la pantalla de resolución sí usa el
    motor real, esos grupos **jamás aparecían allí para resolverse**: la card no se
    limpiaba nunca. Medido antes del arreglo: B1 mostraba 7 grados "pendientes" con
    0 reales (14 desempates ya resueltos), B2 mostraba 6 con 0 reales.
  - La copia además arrastraba el roster viejo (`estado='aprobada'` en vez del filtro
    por `tipo`), no excluía notas extraordinarias ni no bloqueadas (P2) y contaba
    toda la tutoría en vez de solo Ética y Valores.
  - Regresión cubierta por `database/verificaciones/verif_empates_card_control.php`.

### Implementación
- **Fase 1 — candados:** `AnioAcademicoModel::hayBimestrePrevioPendiente` (no abrir
  si un bimestre de número menor sigue pendiente) y
  `OrdenMeritoModel::gradosConEmpatesPendientes` (el cierre ABORTA si hay empates
  pendientes), ambos validados en `PeriodoController::abrir`/`cerrar`.
- **Fase 2 — snapshot:** migración `023_orden_merito_snapshot.sql` — tabla con
  `puesto_grado` Y `puesto_seccion`, grado/sección explícitos congelados y métricas
  (num_competencias, total_notas, promedios, num_c/b/ad/alto/16).
  UNIQUE(periodo, matricula).
- `OrdenMeritoModel::rankingGrado`/`rankingPorSeccion` son wrappers snapshot-aware
  (`debeUsarSnapshot`: tiene filas Y (cerrado **o** ya fue publicado) → lee snapshot;
  si no, cálculo vivo). La segunda condición cubre la REAPERTURA de un bimestre
  publicado: su oficial ya salió a las familias y es inmutable, así que el claustro
  y las familias siguen viendo el congelado mientras se corrige. Está memoizado por
  periodo (se llama en bucle, un grado por iteración).
  El cálculo vivo ancla por bimestre: excluye la OFICIAL si su operativa cubrió ese
  periodo e incluye la operativa revertida (desactivada) en sus periodos.
- `PeriodoController::cerrar` llama `generarSnapshot` DENTRO de su transacción
  (PDO singleton compartido → atómico). `gradosConRanking` es snapshot-aware.
- **Backfill:** `database/backfill_orden_merito.php` — idempotente; SALTA periodos
  con empates pendientes para no congelar un orden arbitrario **y, desde el 26/07,
  también los que ya tienen snapshot oficial PUBLICADO** (candado 046), salvo que se
  invoque con `--forzar`. Sin esa guarda sobrescribía en silencio el documento
  entregado a las familias: en B1 habría cambiado las 528 filas reconstruidas a mano
  por las 518 de la regla general.
- **Reconstrucción de B1:** `database/reconstruir_snapshot_b1.php` — único camino
  soportado para regenerar el oficial de B1 con la regla ESPECIAL de la Fase C (roster
  SIN filtro de tipo). Nunca corre en prod (guard por el archivo de secretos), simula
  salvo `--confirmar`, y verifica la firma del documento (528 / puestos 1-72 / 0
  empates) dentro de la transacción antes del COMMIT. Existe porque el snapshot local
  se perdió el 26/07 y `backfill` no puede reponerlo: aplica la regla general. Detalle
  y verificación de fidelidad en `docs/ESTADO.md`.
- `gradosConEmpatesPendientes` usa el cálculo EN VIVO (`rankingGradoLive`), NO el
  wrapper snapshot-aware: valida lo que se va a congelar, no lo ya congelado. Importa
  al RE-cerrar un bimestre publicado y reabierto, donde `debeUsarSnapshot` es `true` y
  leer por `rankingGrado` devolvería el snapshot viejo, sin ver los empates que
  introdujeron las rectificaciones.
- Los empates se detectan/resuelven a nivel GRADO (UI por periodo+grado en
  `Director\OrdenMeritoController::desempate`).
- Limitación menor conocida: `getConteosGrado` (header del reporte) sigue en vivo —
  solo afecta el conteo del grado operativo de un retorno revertido, no los puestos.

## Inmutabilidad del oficial + versión rectificada (migración 046, 24/07/2026)

Fase B del rediseño. Regla: **el snapshot OFICIAL (`orden_merito_snapshot`) no cambia
una vez que el periodo ESTUVO publicado** (compuerta 044). Un orden de mérito publicado
es información pública; corregirlo sin acto formal es una regresión grave.

- **Disparador (monotónico, a nivel de periodo):** `PublicacionBoletaModel::fuePublicado`
  = existe fila con `primera_publicacion_en` sellada, o `publica_en <= ahora` (cubre las
  programadas que alcanzaron su hora). El sello lo pone `publicar()` con `COALESCE` (una
  sola vez, nunca se limpia) → suspender por reapertura o despublicar a mano NO reabren el
  candado. Columna additiva `periodos_publicacion.primera_publicacion_en` (backfill en 046).
- **Punto único de escritura:** `OrdenMeritoModel::registrarRanking($periodoId, $usuarioId,
  $motivo)` → si `fuePublicado` **y** ya hay oficial, escribe la versión RECTIFICADA
  (`generarSnapshotRectificado`, tabla `orden_merito_rectificado`) y el oficial queda
  intacto; si no, (re)genera el oficial. Devuelve `'oficial'|'rectificado'`. Lo usan
  `PeriodoController::cerrar` y `RectificacionController` (antes llamaban `generarSnapshot`
  directo). **`generarSnapshot` directo NO honra el candado** — reservado para el
  backfill y la reconstrucción de B1 (registro inicial del oficial de un bimestre ya
  publicado que aún no tenía snapshot).
- **Fuente de cálculo común:** `calcularFilasRanking` (extraída) alimenta el oficial y el
  rectificado con el mismo ranking en vivo, para que solo difieran en tabla y `motivo`.
- **Vista:** el Centro de control (`/admin/control`) muestra una card cuando hay versión
  rectificada + una página de solo lectura
  (`/admin/control/{periodo_id}/orden-merito-rectificado`). El oficial se sigue viendo por
  el flujo normal del director; la rectificada es "registrada pero no mostrada" ahí.
- `orden_merito_rectificado` guarda **la última** versión no oficial por periodo (se
  sobrescribe); la traza por criterio vive en `rectificaciones_calificacion`.
- **Comparación con el oficial (18/09/2026).** La vista suma «Puesto oficial» y «Cambio»
  (`OrdenMeritoModel::puestosOficiales`). ⚠️ **Se resalta la fila cuyo PROMEDIO cambió, no la
  que cambió de puesto**: en B1 el oficial reconstruido lleva 13 estudiantes (10 trasladados +
  3 retirados) que el cálculo de hoy ya no incluye, así que **121 puestos se corren sin que
  nadie cambie de nota**, frente a **1** promedio rectificado (medido). La vista cuenta y
  explica a esos estudiantes que faltan, y dice que **las extraordinarias no cuentan**. Ese era
  el otro «no muestra la rectificación»: la prueba 697 era un alumno con sus 27 notas de B1
  extraordinarias, que por regla no entra al ranking. **La regla se mantiene** (decisión del
  18/09). Al rectificar una nota extraordinaria, el flash ahora lo avisa.

## Rectificación de calificaciones (17/06/2026)

Módulo GENERAL para que `admin`/`registro_academico` corrijan notas que ya
salieron del flujo normal del docente, con auditoría obligatoria.

- **Invariante:** una competencia es RECTIFICABLE solo si está BLOQUEADA y/o en
  periodo CERRADO (`RectificacionModel::esRectificable`). Si está abierta va por
  el flujo del docente. Control = rol + estado rectificable + motivo obligatorio +
  traza en `rectificaciones_calificacion`.
- **Mecánica:** edición por criterio → recálculo de UNA matrícula (reusa
  `calcularPromedio`/`guardarNotaFinal`/`actualizarConclusion`) → valida
  `conclusionObligatoria(literal, nivel)` → registra auditoría → **regenera el
  snapshot del bimestre** (`OrdenMeritoModel::generarSnapshot`).
- **Interacción con empates (cuidado):** tras regenerar, `rankingGrado` lee del
  snapshot y NO detecta empates nuevos. Por eso existe
  `OrdenMeritoModel::gradoTieneEmpateLivePendiente()` (calcula en vivo ignorando
  el snapshot); si la corrección introdujo un empate, avisa al director.
- **Piezas:** migración `024_rectificaciones_calificacion.sql`,
  `RectificacionModel`, `Rectificacion\RectificacionController`, rutas
  `/rectificaciones[...]`, vistas `resources/views/rectificaciones/`, SASS
  `pages/_rectificaciones.scss`. El buscador `buscador-estudiante.js` acepta
  `data-target-base` en `#buscadorResultados` para redirigir las tarjetas.
- **Entradas:** card en dashboard y acciones en `matriculas/show.php`.

### Calificación extraordinaria (16/07/2026, migración 042)

Alta de nota por RA a un alumno SIN calificación en una competencia que ya
salió del flujo del docente (cerrada/bloqueada). Vive dentro de Rectificación
(`GET/POST /rectificaciones/extraordinaria[...]`). Detalle completo del flujo
y sus guardas en `docs/modulos/calificaciones.md` (sección "Calificación
extraordinaria"). Lo que importa a ESTE módulo:

- **La nota extraordinaria NO cuenta en el orden de mérito** (decisión del
  usuario): `calificaciones.extraordinaria = 1` y las DOS agregaciones en vivo
  (`rankingGradoLive`, `rankingPorSeccionLive`) filtran
  `AND cal.extraordinaria = 0`. Va a boleta y SIAGIE, no mueve puestos.
  ⚠️ **Es regla del MÉRITO, no de las notas en general**: la situación final
  (riesgo académico, `SituacionFinalModel`) **sí cuenta las extraordinarias**
  desde el 24/09/2026 —el SIAGIE calcula la promoción con ellas—. No
  «unificar» los dos filtros: responden preguntas distintas.
- Por eso el alta **NO regenera el snapshot** (el ranking no cambia); la
  rectificación normal sí sigue regenerándolo.
- La auditoría distingue `tipo='extraordinaria'` (nota_anterior NULL) de
  `tipo='rectificacion'` en `rectificaciones_calificacion`.
- Tras el alta, la competencia pasa a ser rectificable por el flujo normal
  (corrección futura de la extraordinaria = rectificación estándar).
- Los subqueries de ANCLAJE de retorno (`c2`) NO filtran extraordinarias a
  propósito: deciden DÓNDE compite el alumno (dónde viven sus notas), no qué
  suma al promedio.

## Integración con matrículas (7.1) — SOLO COMPITEN LAS APROBADAS (12/08/2026)

> 🔒 **DECISIÓN DEL USUARIO (12/08/2026).** Al orden de mérito solo entran las
> matrículas con **`estado='aprobada'`**. Deroga la mitad `estado`→`tipo` de la Fase A
> (24/07). Motivo: el orden de mérito es un documento que se firma y se archiva, y un
> alumno cuya matrícula no está regularizada no puede figurar en él.

**PUNTO ÚNICO: `OrdenMeritoModel::ROSTER_MERITO`**, un fragmento SQL interpolado en las
**5** consultas del modelo que enumeran competidores o grados con ranking
(`rankingGradoLive`, `rankingPorSeccionLive`, `gradosConRanking`, `calcularFilasRanking`
y `gradosConEmpatesPendientesDetalle`). Se extrajo al hacer este cambio: cinco copias a
mano de la misma regla es el patrón de fallo que este repo ya pagó cuatro veces.

```sql
m.tipo NOT IN ('trasladado', 'retirado')
AND (m.estado = 'aprobada'
     OR m.id IN (SELECT r.matricula_operativa_id FROM retornos_grado r
                 WHERE r.estado = 'revertido'))
```

Tres cosas que no son obvias al leerlo:

- **`pendiente` es el estado en que NACE toda matrícula** (`MatriculaModel::crear`) y en
  el que se queda el registro provisional mientras falte el DNI. No es un caso raro: son
  alumnos que asisten y a los que el docente ya les puso nota.
- **Seguir calificándose y pertenecer al documento son cosas distintas.** Los rosters de
  evaluación (calificaciones, asistencia, conducta, transversales, tutoría) **NO
  cambiaron**: siguen filtrando solo por `tipo`, así que un `pendiente` se califica, tiene
  conducta y recibe boleta — simplemente no tiene puesto. `alertasEvaluacionIncompleta`
  también los sigue vigilando (su nota va a boleta y al SIAGIE igual), así que un
  pendiente con evaluación incompleta **sigue abortando el cierre**. Es deliberado.
- **El filtro por `tipo` NO es redundante** ahora que hay uno por `estado`: `retirar()`
  exige que la matrícula ya esté `desactivado`, así que sin él la excepción del retorno
  revertido podría reingresar a un RETIRADO.

**La excepción del retorno revertido.** Al revertir, la operativa queda `desactivado`
(`RetornoGradoController::revertir`) pero conserva su `tipo`, y es donde viven las notas
de los bimestres que el alumno cursó en el grado inferior. Sin la excepción se caería del
ranking de un bimestre que sí cursó. Es el `OR revertido` explícito que la Fase A había
podido eliminar cuando el criterio era el `tipo`. La operativa de un retorno **activo** no
la necesita: nace `aprobada` a propósito (`RetornoGradoController::guardar:142`).

Se sigue EXCLUYENDO la matrícula oficial de un retorno activo (`m.id NOT IN
(SELECT matricula_oficial_id FROM retornos_grado WHERE estado='activo' …)`) — el
estudiante compite en su grado OPERATIVO (anclaje por bimestre intacto).

**Impacto medido el 12/08/2026** (copia local, antes de publicar B2): 3 matrículas
pendientes en todo el año, las tres de secundaria y con 55-64 notas cada una — dos por
«Registro provisional — pendiente de DNI». Estaban en el snapshot de B2 en los puestos
41/50, 44/50 (5.º) y 43/45 (3.º): **ningún primer puesto se movió** y la media beca no se
vio afectada. B2 pasó de **523 a 520** filas y **11 compañeros** cambiaron de puesto.
Como no existe ninguna matrícula `desactivado` que no sea además `trasladado`/`retirado`,
hoy el criterio nuevo y el viejo difieren **exactamente** en esos 3.

> Nota histórica: la vista live de un bimestre CERRADO sin snapshot (fallback) refleja
> este roster actual; por eso el reporte oficial debe venir del snapshot congelado
> (ver Fases B y C en `docs/ESTADO.md`), no del cálculo en vivo tardío.

### Los SEIS sitios que mueven el roster

`sincronizarRosterPorMatricula` sigue siendo el único que puede mover el roster de un
snapshot, y ahora se dispara también por `estado`. La reversión es **simétrica en los dos
ejes**: aprobar una matrícula la REINCORPORA al mérito de los bimestres cerrados y no
publicados, igual que revertir un retiro.

| Acción | Dónde | Eje | Efecto |
|---|---|---|---|
| Traslado de salida | `TrasladoController::guardar` | tipo | sale |
| Marcar retirado | `MatriculaController::retirar` | tipo | sale |
| Revertir retiro | `MatriculaController::revertirRetiro` | tipo | vuelve |
| Activar matrícula | `MatriculaController::activar` | tipo + estado | vuelve |
| Desactivar matrícula | `MatriculaController::desactivar` | estado | sale |
| Desmarcar un documento | `MatriculaController::guardarDocumentos` | estado | sale |

Las dos últimas son nuevas del 12/08. `guardarDocumentos` es fácil de pasar por alto:
desmarcar un documento entregado degrada la matrícula de `aprobada` a `pendiente`
(línea 700) y **eso reescribe el documento oficial**, así que va en transacción y avisa.
`activar` y `desactivar` no tenían transacción y ahora la tienen.

**NO hace falta en `RetornoGradoController::revertir`**, que deja la operativa en
`desactivado`: la excepción de `ROSTER_MERITO` la mantiene dentro a propósito, así que el
roster no se mueve.

**Verificación:** `verif_roster_merito_estado.php` (transacción + ROLLBACK, guard de
prod). 20 comprobaciones, **cada regla en sus DOS ramas** —que excluya a quien debe
excluir Y que siga dejando pasar a quien debe competir—, porque el 11/08 se desplegó una
guarda que bloqueaba justo el caso que debía permitir y su verificador estaba en verde
por probar la función vecina. Ojo con su **paso 0**: normaliza el snapshot dentro de la
transacción, porque `escribirOficial` reescribe el periodo ENTERO y una divergencia sin
reconciliar descuadraría todas las firmas. `verif_fase_a_orden_merito.php` (solo lectura)
cubre el filtro en sí, ahora con casos derivados de la BD en vez de IDs fijos.

## Visibilidad: quién ve el mérito y cuándo (rediseño 2, 26/07/2026)

**Publicar libera boletas Y orden de mérito juntos**, por NIVEL, bajo la compuerta 044.
Cerrar el bimestre oficializa (congela) el mérito, pero no lo muestra a nadie fuera de
dirección.

- **Director:** `/director/orden-merito`, siempre (no depende de la compuerta).
- **Staff — ranking por SECCIÓN: `/director/ranking-seccion[/{periodo}]` (10/08/2026).**
  Mismos 4 roles que el módulo (admin, RA, director general, director EBR) porque comparte
  el `requireRole` de `Director\OrdenMeritoController`. **NO aplica la compuerta 044**, a
  diferencia del flujo del docente: el staff lo necesita ANTES de publicar, que es cuando
  el dato sirve para decidir. Es el mismo criterio que ya seguía el ranking por grado.
  - **No calcula nada nuevo:** llama a `rankingPorSeccion()`, el punto único
    snapshot-aware (cerrado → congelado; activo → en vivo sobre lo ya bloqueado), que
    comparten el imprimible del director y la vista del docente.
  - **Reutiliza la vista del docente** (`docente/ranking-seccion-periodo.php`)
    parametrizando `$rutaBase`, `$rutaMerito`, `$provisional` y `$hayPendientes`, todos
    con default. Se descartó copiarla: **dos copias divergen**, y así nacieron los 130
    bloqueos fantasma y la card de empates irresolubles.
  - **Sin top-N: todos los estudiantes de cada sección** (decisión del usuario), igual que
    el reporte imprimible.
  - Avisa cuando el periodo está **abierto** (badge *Provisional*: el ranking en vivo se
    mueve) y cuando hay **empates sin resolver** (el orden aún no es oficializable).
  - **Verificación:** `verif_ranking_seccion_staff.php` (solo lectura, corre en prod).
    Contrasta la suma de las secciones contra el snapshot oficial —**B1 528=528 y B2
    524=524**— y comprueba que la compuerta no recorta al staff. Medido el 10/08: en B2 los
    **11 grados** estaban ocultos al claustro (publicación programada al 13-14/08) y
    visibles aquí, que es justo el motivo de la vista.
- **Claustro:** `Docente\OrdenMeritoController` lista solo los periodos con **algún**
  nivel publicado (`PublicacionBoletaModel::periodosConAlgunNivelPublicado`) y, dentro
  de un periodo, solo los grados de niveles publicados (`nivelesPublicados`). El
  criterio de "publicado" no se duplica fuera de ese modelo.
- 🔴 **FUGA CORREGIDA EL 10/08/2026 — la NÓMINA del docente enseñaba el mérito NO
  PUBLICADO.** `/docente/nomina` resolvía el puesto con **`ultimoBimestreCerrado()`**, sin
  preguntar por la publicación: mostraba «Orden de mérito vigente: II Bimestre» y el
  puesto de cada alumno mientras `/docente/orden-merito` —dos clics más allá— se lo
  ocultaba. **Cerrar congela el ranking; PUBLICAR es lo que lo muestra**, y entre ambos
  actos pasan días.
  - **Punto único nuevo:** `PublicacionBoletaModel::ultimoPeriodoPublicadoPorNivel()`
    devuelve, **por NIVEL**, el último bimestre cerrado **y ya publicado**. Recorre los
    cerrados de más reciente a más antiguo y **reutiliza `nivelesPublicados()`** en vez de
    reescribir el criterio de "publicado" (son 4 bimestres como mucho; una quinta copia de
    esa regla es lo que costó los 130 bloqueos fantasma).
  - **La respuesta es POR NIVEL porque la compuerta lo es:** primaria se publica un día
    antes que secundaria, así que en esa ventana la misma pantalla muestra legítimamente
    **B2 para primaria y B1 para secundaria**. El encabezado lista un rótulo por nivel
    cuando difieren; decir uno solo mentiría.
  - **No se oculta lo ya publicado** (decisión del usuario): retrocede al último bimestre
    visible en vez de borrar el puesto. Medido el 10/08: la nómina pasa de enseñar **B2**
    (cerrado, publicación programada al 13-14/08) a enseñar **B1**, publicado el 22/07.
  - Cada alumno lleva `merito_visible`, que separa **"su nivel aún no se publicó"** de
    **"está publicado pero no tiene puesto"** — mensajes distintos en la card.
  - **El buscador de `Admin\BuscadorEstudianteController` NO se toca:** sus roles son staff
    (admin, RA, secretaría, los dos directores) y para ellos rige lo mismo que en
    `/director/ranking-seccion` — ven el mérito antes de publicar porque lo necesitan para
    decidir.
  - **Revisado y descartado como caso análogo:** el panel de BOLETA de esa misma nómina usa
    el umbral `'borrador'`, que por diseño no pasa por la compuerta (son las notas que el
    propio docente registra, y salen con marca de agua). Un ranking comparativo no es lo
    mismo que la boleta de su alumno.
  - **Verificación:** `verif_merito_nomina_compuerta.php` (transacción + ROLLBACK, guard de
    prod). Cubre los 4 escenarios: cerrado-sin-publicar devuelve el anterior, publicación
    escalonada da bimestres distintos por nivel, y suspendida/despublicada dejan de contar.
- **Familias:** `/padre/orden-merito` (grado) y `/padre/ranking-seccion` (solo la
  sección del hijo), bajo la misma compuerta que las notas
  (`PanelController::getPeriodoVigentePadre`). Ven la lista completa con nombre y
  promedio, igual que el claustro (decisión del usuario, 26/07).
- **Retorno de grado:** `getHijo` devuelve la matrícula OFICIAL, pero el alumno compite
  con la OPERATIVA. `PanelController::contextoMerito` recorre las fuentes de
  `boletaContexto` y se queda con la que aparece en el ranking; **los rótulos usan ese
  grado/sección**, no el oficial (integridad: no mostrar "2°" sobre una tabla de 1°).
  Es la excepción consciente a "boletas y nómina siempre muestran el grado OFICIAL":
  aquí lo que se muestra es un ranking de un grado concreto, no un documento SIAGIE.

**El cierre exige mérito íntegro:** `PeriodoController::cerrar` aborta si hay empates
sin resolver o alumnos con evaluación incompleta
(`ControlOperativoModel::alertasEvaluacionIncompleta`: criterios que otros compañeros de
su sección sí tienen con nota y a él le faltan, sin omisión ni exoneración, en cargas
ACTIVAS). NO valida "0 competencias sin bloquear" —el propio cierre las fuerza, y ese
camino maneja las transversales—, que es una diferencia consciente con el diseño (P3).

## El reporte imprimible: MODELO OFICIAL del documento (11/08/2026)

> 🔒 **DECISIÓN CERRADA — este es el modelo oficial del orden de mérito del colegio.**
> Aprobado por el usuario el 11/08/2026 tras revisar el documento renderizado. No se
> rediseña de nuevo sin decisión explícita suya: el formato de un documento que se firma
> y se archiva no es una preferencia estética, y cambiarlo deja el papel ya firmado sin
> corresponder con el que emite el sistema. Lo que sí se puede tocar sin volver a
> preguntar es lo que NO altera la estructura firmable (corregir un rótulo, ajustar la
> altura de fila si un grado crece por encima del tope de 55).
>
> **Las 4 decisiones que definen el modelo** (elegidas por el usuario, no re-preguntar):
> A4 vertical a una columna · una hoja por sección · en cada hoja de sección firman
> Director EBR **y** tutor · hojas agrupadas por grado · «Distinción» como distintivo
> junto al nombre, no como columna.

`GET /director/orden-merito/{periodo}/imprimir` →
`Director\OrdenMeritoController::imprimir` + `resources/views/director/reporte-merito.php`
+ `resources/sass/pages/_reporte-merito.scss`. **Solo cambió la presentación: ni una
consulta, ni el modelo, ni qué se muestra.** Sigue leyendo `rankingGrado` y
`rankingPorSeccion` (snapshot-aware) con el mismo roster.

**Antes:** A4 apaisado, 2 hojas por grado — la 2.ª apilaba TODAS las secciones del grado,
cada una con una línea suelta de firma del tutor.
**Ahora:** A4 **vertical**, y cada hoja es un documento autónomo:

| Hoja | Contenido | Firman |
|---|---|---|
| 1 por grado | orden de mérito del grado completo | Director EBR + **todos** sus tutores |
| 1 por SECCIÓN | ranking interno de esa sección | Director EBR + el tutor de esa sección |

Orden del legajo: agrupado por grado (mérito y a continuación sus secciones).
Medido en B1/B2 2026: **34 hojas** (11 grados + 23 secciones), 80 bloques de firma.

**Por qué vertical.** La tabla solo necesita ~74mm de columnas fijas, así que los 277mm
del apaisado se desperdiciaban en la columna de nombre mientras sus 190mm de alto partían
los grados grandes y dejaban las firmas huérfanas en una hoja sin contexto. En retrato
caben **55 alumnos por hoja** (medido, no estimado). 10 de los 11 grados entran enteros
con sus firmas al pie; **solo 1.º de secundaria (72) usa dos hojas**, con el `<thead>`
repetido y el bloque de firmas viajando entero.

⚠️ **55 es un tope real y está al límite**: 4.º de secundaria tenía 55 en B1 y la hoja
mide 278.1mm de los 281mm útiles. Si un grado pasa de ahí, la tabla continúa en una
segunda hoja (degradación limpia, no rotura). Para recuperar filas se toca la ALTURA DE
FILA, que la fijan `.promedio-val` y `.medalla` —no el `font-size` de la celda—, nunca el
espacio de firma.

**Dos cosas que conviene no volver a descubrir a mano:**
- **`.reporte-footer` es COMPARTIDO** con `admin/asistencia/imprimir`,
  `admin/conducta/imprimir` y el acta de desempates. Lo que solo vale para el mérito vive
  bajo el scope **`.merito-doc`** (mismo patrón que `.registro-doc` y `.acta-doc`): hoy,
  el espacio de firma de 15mm en vez de los 18mm compartidos.
- La columna **«Distinción»** (44mm) pasó a ser un **distintivo junto al nombre**: solo
  aplica a 3 de las 37-72 filas, así que iba vacía en el 95 % de la tabla. No se perdió
  información.
- `body.doc-landscape` / `@page paisaje` (en `_boleta.scss`) **quedaron sin ningún
  consumidor**; se conservan como mecanismo para el próximo documento ancho.
- Se eliminaron `.reporte-seccion-bloque` y `.reporte-seccion-firma`, que quedaron
  muertas al no existir ya la hoja que apilaba secciones.

## Traslados y retiros en el snapshot — REGLA NUEVA DESDE B2 (11/08/2026)

> 🔒 **DECISIÓN DEL USUARIO (11/08/2026): B1 se queda EXACTAMENTE como está; la regla
> nueva rige desde B2.** Un estudiante que pasa a `trasladado` o `retirado` sale del
> snapshot del orden de mérito. Motivo: **la publicación siempre cae después de que el
> bimestre siguiente ya se activó**, así que entre el cierre y la publicación hay una
> ventana en la que un alumno se va, y el documento se entrega a las familias cuando ese
> alumno ya no está en el colegio.

**Los dos bimestres del MISMO año llevan criterios opuestos. Es deliberado.**

| | Filas | `trasladado` | `retirado` | Criterio |
|---|---|---|---|---|
| **B1** (publicado 22/07) | 528 | 10 | 1 | roster SIN filtro de tipo (regla especial de reconstrucción) |
| **B2** en adelante | — | 0 | 0 | se excluyen |

**B1 no requiere ninguna acción: el candado 046 ya lo protege.** `fuePublicado(1)` es `true`,
así que toda rectificación futura va a `orden_merito_rectificado` y el oficial de 528 no se
toca. Solo lo pondría en riesgo invocar `generarSnapshot`/`backfill` a mano (ambos tienen
guarda documentada). Verificado el 11/08/2026.

### Cómo está implementado (11/08/2026)

**El fallo que resolvió, en una línea: `generarSnapshot` recalculaba el ROSTER, no solo
las notas.** «¿Cuánto sacó cada uno?» y «¿quién pertenece a este documento?» son preguntas
distintas, y la segunda viajaba de polizón en la primera. **Caso testigo:** ESCUDERO
TORRES (1.º sec C) cursó B2 completo —27 competencias, promedio 14.41, puesto 30— y pasó a
`trasladado` 38 minutos después de generarse el snapshot; salió del oficial de B2 no por
una decisión sobre ella, sino porque se rectificaron tres notas de Educación Física de
**4.º de primaria**, arrastrando a 42 compañeros de puesto. Sin un solo aviso.

Dos piezas, ambas en `OrdenMeritoModel`:

**1. Guarda de roster en la rectificación.** `registrarRanking(..., $exigirMismoRoster)`.
Con el flag activo (lo pasa `RectificacionController`), compara el roster recién calculado
con el del snapshot: si difiere, **ABORTA la reescritura** y devuelve `'roster_cambiado'`,
que el controlador convierte en un aviso pidiendo regularizar la matrícula. Mejor un
ranking desactualizado y ruidoso que un documento oficial reescrito en silencio.
**No re-rankea en PHP a propósito:** reproducir la cascada fuera de `aplicarDesempate`
sería su quinta copia, y ese es el patrón de fallo caro de este repo.

⚠️ **La guarda solo corre cuando se va a escribir el OFICIAL** (corregido el 11/08/2026,
mismo día). Si el periodo ya está publicado, `registrarRanking` sale antes por la rama del
candado 046 y registra la versión rectificada **aunque el roster difiera**: allí el oficial
ya es intocable, así que la guarda no protege nada, y `orden_merito_rectificado` es una
versión de trabajo cuyo sentido es reflejar el cálculo de hoy, roster incluido.
**Con el orden inverso B1 no registraba NADA:** su roster diverge **por diseño** (528 filas
del documento reconstruido contra 517 del motor —los 10 `trasladado` + 1 `retirado` que la
regla especial reincorporó—), así que toda rectificación suya devolvía `'roster_cambiado'`
y pedía «regularizar la matrícula», que es justo lo que en B1 **no** se debe hacer. El caso
se le escapó al verificador porque su paso 5 solo probaba `sincronizarRosterPorMatricula`
sobre el periodo publicado, nunca `registrarRanking`; hoy es el paso 6.

**2. `sincronizarRosterPorMatricula($matriculaId, $usuarioId)` — punto único.** Es lo único
que puede mover el roster. No decide por su cuenta: pregunta a `calcularFilasRanking` (que
ya filtra por tipo) y actúa si difiere. Alcance: periodos **cerrados y NO publicados**.
Devuelve los efectos, que `describirEfectosRoster()` convierte en el mensaje al usuario
(«Salió del orden de mérito de II Bimestre (ocupaba el puesto 4° de 3° Secundaria); 41
compañeros cambiaron de puesto»).

⚠️ **SE LLAMA DESDE LOS CUATRO SITIOS QUE MUEVEN `matriculas.tipo`** dentro o fuera de
(`trasladado`,`retirado`). **Si nace un quinto, tiene que llamar aquí.**

| Acción | Dónde | Efecto |
|---|---|---|
| Traslado de salida | `TrasladoController::guardar` | sale |
| Marcar retirado | `MatriculaController::retirar` | sale |
| Reactivar matrícula | `MatriculaController::activar` | vuelve |
| Revertir retiro | `MatriculaController::revertirRetiro` | vuelve |

> ⚠️ **Desde el 12/08/2026 son SEIS**: el roster también exige `estado='aprobada'`, así
> que `desactivar` y `guardarDocumentos` se suman a la lista. Tabla completa en
> «Los SEIS sitios que mueven el roster» (§7.1).

**Decisiones del usuario (11/08/2026), no re-preguntar:**
- **Automático, avisando.** Sin pantalla de confirmación; el mensaje de éxito dice el
  puesto que ocupaba y cuántos compañeros se movieron.
- **Reversión SIMÉTRICA.** Volver a continuador/nuevo lo reintegra: las notas nunca se
  borraron. Evita que un retiro marcado por error lo deje fuera para siempre. Verificado:
  el snapshot vuelve a su firma exacta.
- **Sin migración.** La traza va al log de la aplicación más `generado_en`/`generado_por`
  del snapshot y el motivo de la matrícula. Se descartó una tabla dedicada por no añadir
  un paso de despliegue con la publicación encima.

Las cuatro llamadas van **dentro de la transacción** de su controlador (el PDO es
singleton compartido): o se traslada y se ajusta el ranking, o no ocurre ninguna de las
dos. `retirar` y `revertirRetiro` no tenían transacción y ahora la tienen.

**Verificación:** `database/verificaciones/verif_roster_snapshot_traslado.php` (transacción
+ ROLLBACK, guard de prod). 20 comprobaciones: la rectificación con roster intacto sí
regenera; con roster cambiado aborta y no toca nada; el traslado saca y renumera sin dejar
huecos; la reversión devuelve la firma original; un bimestre publicado queda intacto; y en
un publicado con roster divergente **sí** se registra la versión rectificada (paso 6, la
regresión de arriba).

### Divergencias ANTERIORES al código: `database/sincronizar_roster_snapshot.php`

La sincronización se dispara con el **cambio de tipo**. Las matrículas que ya habían
cambiado antes de que ese código existiera quedan huérfanas: el snapshot las incluye, su
tipo las excluye y **ningún acto futuro les va a volver a mover el tipo**. Mientras esa
divergencia viva, la guarda de `registrarRanking` aborta toda rectificación del periodo —
correcto, pero deja el bimestre bloqueado.

Ese es el estado en que quedó PRODUCCIÓN el 11/08/2026: B2 con 524 filas incluyendo a una
alumna cuyo `tipo` ya era `trasladado`. **Sin este script, desplegar el código dejaba B2
sin poder rectificarse.**

- **Corre en producción** (por eso no lleva guard de secretos): **simula por defecto** y
  solo escribe con `--confirmar`.
- Solo mira periodos cerrados **no publicados**; B1 se salta siempre y lo dice.
- Sincroniza **por matrícula**, no de golpe, para que cada salida o reingreso quede en el
  log con su puesto y su arrastre, igual que si lo hubiera hecho el acto normal.
- Sirve además como diagnóstico permanente: sin `--confirmar` solo informa.
- Probado de extremo a extremo el 11/08 sobre la copia local: crear la divergencia →
  detectarla → aplicarla (522 filas) → revertir el tipo → reconciliar, y la **firma del
  snapshot volvió idéntica** a la de partida.

**Es también el camino previsto para aplicar el cambio de regla del 12/08/2026** (solo
compiten las aprobadas) sobre los bimestres ya cerrados y no publicados: desplegar el
código deja B2 divergente —el snapshot lo incluye, el roster no— y **sin correr el script
B2 no se puede rectificar**. En local: 523 → 520.

⚠️ **Corregido el 12/08: con VARIAS divergencias a la vez solo se registraba una.** La
primera llamada resuelve todas —`escribirOficial` reescribe el periodo entero con las
filas frescas— así que las siguientes no ven diferencia y no devuelven efectos: dos de
las tres salidas de B2 no dejaban ni una línea de log. Ahora las barridas se informan
igual, con el puesto que ocupaban y la matrícula que disparó la reescritura. De quién
sale del documento oficial siempre tiene que quedar traza. Verificado con un round trip
de 2 divergencias simultáneas: firma idéntica al volver.

## Las ÁREAS EXONERADAS salen del cálculo (05/08/2026)

> **Regla:** una competencia cuya área (o subárea) esté exonerada para ese alumno **no
> entra en el promedio del orden de mérito**. Filtro en las **dos** queries de
> `OrdenMeritoModel` que calculan promedio (`rankingGradoLive` y `rankingPorSeccion`).

Nació al permitir **exonerar a un alumno que ya tiene notas** (ver
`docs/modulos/matriculas.md`). Como esas notas **no se borran** —para que la exoneración
sea reversible y para no tener que reabrir un bimestre cerrado—, sin este filtro el
sistema quedaba incoherente: la boleta mostraba `EXO` y el ranking seguía promediando la
nota.

- Cubre las **dos formas de exonerar**: por **área** (`a.id` llega ya resuelta con
  `COALESCE(sa.area_id, comp.area_id)`, así que alcanza a todas sus subáreas) y por
  **subárea** suelta. Los `NULL` no generan falsos positivos: una exoneración de área
  tiene `subarea_id` NULL y `NULL = x` es NULL.
- ⚠️ **Es el cálculo EN VIVO. Los snapshots guardados NO se tocan** — el de B1 es
  inmutable (candado `046`) y sigue en 528 filas.
- **Impacto medido sobre el caso real** (NOLASCO ALVARADO, 5.º B primaria, exonerada de
  Ed. Religiosa el 05/08 con 3 notas ya puestas, 1 en B1 cerrado y 2 en B2):

```
Promedio B2:  13.38  ->  13.21     (baja: sus notas de Religión estaban por encima de su media)
Su puesto:       30  ->     30
Puestos que cambian en el grado (39 alumnos):  0
Snapshot de B1: 528 filas, su puesto congelado 34 (promedio 12.17) — intacto
```

## El motor de cálculo, auditado (04/08/2026) — DECISIONES CERRADAS, no re-preguntar

Auditoría de `rankingGradoLive` + `aplicarDesempate` a petición del usuario. **No se
cambió nada del código**: las tres preguntas abiertas se decidieron por mantener el
comportamiento actual. Se documenta para no volver a plantearlas.

### Cómo calcula, en una línea
**Promedio aritmético simple por COMPETENCIA** (`AVG(cal.nota_numerica)`), sin ponderar
por área, horas ni nivel: un área con 4 competencias pesa el cuádruple que una con 1.
`promedio_exacto` (sin redondear) ordena; `promedio_general` (2 decimales) se muestra;
el agrupamiento de empates redondea a 6 decimales.

**El denominador varía entre compañeros del mismo grado** (medido en B1: 1° primaria de
19 a 23 competencias, 5° secundaria de 18 a 20). No es un fallo —`AVG` es por alumno—
pero el promedio no mide lo mismo para todos. Por eso la cascada trata el **N desigual**
como empate irreducible: si dos alumnos empatan en promedio con distinto N, la decisión
es humana. *(En B1 esa rama no llegó a dispararse: 0 grupos con N desigual.)*

### El motor NO distingue primaria de secundaria
No hay una sola condición por `nivel_id` en el cálculo. Las diferencias entre niveles las
producen los DATOS, no el código: primaria aporta 25 competencias evaluables al mérito y
secundaria 29 (28 más Ética, ver abajo).

### Ética y Valores ENTRA al mérito en toda secundaria (05/08/2026) — DECISIÓN CERRADA

Filtro vigente en las dos queries del cálculo en vivo:

```sql
AND (a.tipo NOT IN ('transversal', 'tutoria')
     OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "')
```

**Por qué.** Ética y Valores **no es tutoría**: es la nota que corresponde al área-curso
**Educación Religiosa de secundaria**, que no tiene cargas propias (el tutor la evalúa a
través de su carga TOE). Sin la excepción, **el mismo curso pesaba en el promedio en
primaria** —donde Ed. Religiosa es un área-curso normal— **y no pesaba en secundaria**.
Esa asimetría no tenía justificación pedagógica, solo técnica.

**El vínculo Ética ↔ Ed. Religiosa vive en TRES sitios y ninguno es un dato estructural.**
No existe columna, FK ni configuración que diga "el área 24 reemplaza a la 14":

| Dónde | Cómo |
|---|---|
| SIAGIE | `LlenadorSiagie::EXCEPCIONES_HOJA` → hoja `035-EREL`, `buscar` por `nombre_boleta` |
| Orden de mérito | la excepción de arriba, en las 2 queries |
| Boleta | `areas.alias_boleta` del área 24 = `(Educación Religiosa)` |

Al tocar cualquiera de los tres, revisar los otros dos.

#### Deroga la regla de 5.º del 04/08 — y por qué aquella era errónea

La decisión anterior sacaba a Ética del mérito de 5.º. Se apoyaba en una lista que
enumeraba **«Ética y Valores» y «Educación Religiosa» como áreas distintas**, siendo la
misma. Además, Arte y EPT están fuera de 5.º **por dato** (0 cargas), mientras que Ética
**sí se dicta en 5.º** (50 notas en B2, todas bloqueadas): excluirla solo de ese grado
habría exigido una excepción por grado hardcodeada en el SQL, justo lo que este módulo
evita para no duplicar el plan de estudios.

#### Impacto medido en B2 con el MOTOR REAL (cascada completa, no solo promedio)

| Grado | Alumnos | Cambian N | Cambian puesto | Salto máx |
|---|---|---|---|---|
| Primaria (los 6) | 252 | **0** | **0** | — |
| Secundaria 1° | 72 | 72 | 29 | 3 |
| Secundaria 2° | 52 | 52 | 18 | 3 |
| Secundaria 3° | 45 | 45 | 7 | 2 |
| Secundaria 4° | 53 | 52 | 9 | 2 |
| Secundaria 5° | 50 | 50 | 13 | 2 |

- **Ningún primer puesto cambia** en ningún grado → la media beca no se ve afectada.
- **Primaria: 0 cambios**, confirmación de que la excepción no se filtra de nivel (su TOE
  tiene 0 competencias y su `nombre_boleta` es «Tutoría (TOE)»).
- Condiciones duras del cierre tras el cambio: **0 empates pendientes** y **0 alumnos con
  evaluación incompleta** en B2.

> ⚠️ Una medición anterior (04/08) reportaba 76 puestos movidos, salto 9 y un primer
> puesto cambiando en 1.º. Estaba hecha ordenando **solo por promedio** y resolviendo el
> área con `comp.area_id` en vez de `COALESCE(sa.area_id, comp.area_id)`, lo que
> descartaba las áreas con subáreas. Las cifras de la tabla son las buenas.

**B1 no se ve afectado, por tres vías independientes:** tiene **0 notas de Ética**; su
snapshot está publicado y es inmutable (candado 046); y los lectores usan el snapshot
(528 filas), no el cálculo en vivo.

**Lo que se tocó en el mismo cambio:**
- `ControlOperativoModel::alertasEvaluacionIncompleta` ya incluía Ética, así que su
  filtro **convergió solo**; se reescribió el comentario, que afirmaba lo contrario.
- `database/verificaciones/verif_universo_merito.php`: Ética sale de la lista de
  prohibidas de 5.º y sus dos consultas replican ahora la excepción — antes reportaban
  «correctamente fuera» de un área que ya aportaba, o sea **mentían en vez de fallar**.
  «Educación Religiosa» se queda en la lista con otro significado: **guard
  anti-duplicado**, extendido a los 5 grados por un chequeo dedicado.
- `database/reconstruir_snapshot_b1.php` conserva su propia excepción: reproduce el
  documento oficial de B1 tal como se generó. Inocuo (B1 tiene 0 notas de Ética).

⚠️ **Refuerzo recomendado:** desactivar el área *Educación Religiosa* de secundaria desde
`/admin/curriculum`. Ahora que Ética cuenta, una carga creada sobre esa área haría que el
**mismo curso contara dos veces**. No añadir `AND a.activa = 1` al mérito (ver más abajo).

#### Este cambio NO alinea SIGA con el SIAGIE — y no pretende hacerlo

Corrige una inconsistencia **interna**. Las divergencias con el acta siguen, en ambas
direcciones (medido en 5.º, B2):

| | Cuenta en el mérito | Llega al SIAGIE |
|---|---|---|
| Ética y Valores (50) | ✅ | ✅ `035-EREL` |
| GAMA, transversal (50) | ❌ | ✅ `032-ETRA` |
| Taller de Pre-Cálculo (50) | ✅ | ❌ sin hoja |
| Taller de Raz. Matemático (50) | ✅ | ❌ sin hoja |

Si alguna vez se decide que el mérito reproduzca el promedio del SIAGIE, son tres
decisiones más, no una.

### Qué entra y qué no en el promedio — tabla de referencia (04/08/2026)

| Nivel | Tipo | Área | Subáreas | Comps. | ¿Entra? |
|---|---|---|---|---|---|
| Primaria | área-curso | Arte y Cultura | — | 2 | **Sí** |
| Primaria | área-curso | Educación Física | — | 3 | **Sí** |
| Primaria | área-curso | Educación Religiosa | — | 2 | **Sí** |
| Primaria | área-curso | Inglés | — | 3 | **Sí** |
| Primaria | área-curso | Personal Social | — | 5 | **Sí** |
| Primaria | con subáreas | Ciencia y Tecnología | Química · Biología · Física | 3 | **Sí** |
| Primaria | con subáreas | Comunicación | Gramática · Plan Lector · Razonamiento Verbal | 3 | **Sí** |
| Primaria | con subáreas | Matemática | Aritmética · Álgebra · Geometría · Raz. Matemático | 4 | **Sí** |
| Primaria | transversal | Competencias Transversales | — | 2 | No |
| Primaria | tutoría | Tutoría (TOE) | — | 0 | No |
| Secundaria | área-curso | Arte y Cultura | — | 2 | **Sí** |
| Secundaria | área-curso | DPCC | — | 2 | **Sí** |
| Secundaria | área-curso | Educación Física | — | 3 | **Sí** |
| Secundaria | área-curso | Educación para el Trabajo | — | 1 | **Sí** |
| Secundaria | área-curso | Educación Religiosa | — | 2 | **Sí** |
| Secundaria | área-curso | Inglés | — | 3 | **Sí** |
| Secundaria | área-curso | Taller de Pre-Cálculo | — | 1 | **Sí** |
| Secundaria | área-curso | Taller de Razonamiento Matemático | — | 2 | **Sí** |
| Secundaria | con subáreas | Ciencia y Tecnología | Química · Biología · Física | 3 | **Sí** |
| Secundaria | con subáreas | Ciencias Sociales | Historia · Geografía · Economía | 3 | **Sí** |
| Secundaria | con subáreas | Comunicación | Raz. Verbal · Literatura · Lenguaje | 3 | **Sí** |
| Secundaria | con subáreas | Matemática | Aritmética · Álgebra · Geometría · Trigonometría | 4 | **Sí** |
| Secundaria | transversal | Competencias Transversales | — | 2 | No |
| Secundaria | tutoría | **Ética y Valores** | — | 1 | **Sí** (desde 05/08 — es Ed. Religiosa) |

**Totales que entran: Primaria 25 competencias · Secundaria 28.**

Notas de lectura:
- **Las subáreas no son una unidad de cómputo.** El promedio es por COMPETENCIA: cada
  subárea aporta las suyas (normalmente 1) y el área con subáreas pesa la suma. Matemática
  (4) pesa el doble que Arte y Cultura (2).
- **La CONDUCTA no entra**, y no por un filtro: vive en otra tabla
  (`calificaciones_conducta`) que el mérito ni siquiera consulta. Verificado: no hay
  ninguna referencia a conducta en `OrdenMeritoModel`.
- **Las competencias transversales (TIC/GAMA) no entran** en ninguno de los dos niveles.
- Ese universo aún se filtra por: nota **bloqueada**, `extraordinaria = 0` y el roster
  (`tipo NOT IN ('trasladado','retirado')` + anclaje de retorno).

### 5.º de secundaria lleva OTRO plan — regla del colegio (revisada el 05/08/2026)

**En 5.º de secundaria no entran al mérito:** Arte y Cultura, Educación para el Trabajo
y las Competencias Transversales.

> ⚠️ **La regla del 04/08 incluía además «Ética y Valores» y «Educación Religiosa», y se
> DEROGÓ el 05/08.** Las enumeraba como dos áreas distintas siendo **la misma**: en
> secundaria Ed. Religiosa es un cascarón sin cargas y su nota la produce la carga de
> Ética. Al aclararse, el usuario decidió que **Ética cuenta en los 5 grados**. Es el
> caso testigo de que una regla registrada puede apoyarse en una premisa falsa sobre
> cómo están modelados los datos: contrastarlas con la BD antes de codificar excepciones.

| Área | Situación en 5.º |
|---|---|
| Arte y Cultura | **fuera**: 5.º no la lleva (0 cargas y 0 notas; sí en 1.º-4.º) |
| Educación para el Trabajo | **fuera**: 5.º no la lleva (0 cargas y 0 notas; sí en 1.º-4.º) |
| Competencias Transversales | **fuera** por `tipo='transversal'` |
| **Ética y Valores** | **ENTRA** (50 notas en B2, todas bloqueadas) — es la Ed. Religiosa del nivel |
| Educación Religiosa (secundaria) | 0 cargas y 0 notas: cascarón del catálogo. Vigilada como **guard anti-duplicado**, no como veto curricular |

**Plan de 5.º frente a 1.º-4.º:** no lleva Arte y Cultura ni EPT, y sí lleva **Taller de
Pre-Cálculo** (exclusivo del grado). Competencias que entran: **1.º-4.º = 27 · 5.º = 25**
(una más que antes en cada tramo, por Ética).

> **Ojo al leer el acta SIAGIE de 5.º:** `032-EPT` se nutre de **GAMA**, una competencia
> transversal que **no** cuenta para el mérito (`LlenadorSiagie:77`, solo 5.º,
> precisamente porque el grado no lleva el curso de EPT). Y en sentido contrario, los dos
> **talleres** (Raz. Matemático y Pre-Cálculo) **sí** cuentan para el mérito y **no**
> llegan al acta, porque no tienen hoja en el SIAGIE. `035-EREL` ← Ética ya no es una
> discrepancia: desde el 05/08 cuenta en ambos. El acta y el ranking **no tienen por qué
> cuadrar área por área**.

**Por qué esto NO se codificó como excepción en el SQL del mérito:** el plan de estudios
se deriva de las **cargas académicas**; hardcodear "5.º no lleva Arte" en la query
duplicaría esa fuente de verdad y quedaría desincronizado el día que el plan cambie. La
red de seguridad es `database/verificaciones/verif_universo_merito.php` (solo lectura,
corre en prod): lista qué área aporta al promedio en cada grado y **falla** si una
prohibida empieza a aportar.

**Refuerzo pendiente (dato, no código):** desactivar el área *Educación Religiosa* de
secundaria desde `/admin/curriculum`. `CargaAcademicaModel` solo ofrece áreas con
`activa = 1`, así que impide crearle una carga — sin carga no hay notas. **No** añadir
`AND a.activa = 1` al mérito: convertiría desactivar un área en una alteración
**retroactiva** de rankings ya calculados (el exportador SIAGIE ya evita esa trampa con
`WHERE a.activa = 1 OR notas > 0`).

### Decisiones del usuario (04/08/2026)
1. **Primaria se sigue ordenando por PROMEDIO NUMÉRICO**, igual que secundaria. Se evaluó
   ordenarla por conteo literal (más AD, menos C…) —que es lo que su boleta comunica, ya
   que en primaria solo se publica AD/A/B/C— y **se decidió mantener el promedio**.
   - Consecuencia asumida, medida en B1: **180 alumnos de primaria** están en grupos con
     la boleta literal idéntica (mismo N y misma distribución C/B/A/AD), y en **42** de
     esos grupos el orden lo decide el numeral, que la familia de primaria **no ve**.
     En secundaria son 81 alumnos / 33 grupos, pero allí el numeral sí se publica.
2. **`num_alto` (15,16) y `num_16` se quedan como están**, aunque sean criterios de escala
   vigesimal aplicados también a primaria. Siguen congelados desde el cambio de escala del
   10/06/2026.
3. **Los umbrales de `num_c`/`num_b` se quedan HARDCODEADOS.** No se unifican con las
   constantes pese a ser un cambio sin efecto en el resultado de hoy.

### ⚠️ Riesgo latente que dejan las decisiones 2 y 3
La cascada mezcla dos fuentes de umbral:
```sql
SUM(cal.nota_numerica <= 10)             AS num_c    -- literal, hardcodeado
SUM(cal.nota_numerica BETWEEN 11 AND 13) AS num_b    -- literal, hardcodeado
SUM(cal.nota_numerica >= " . NOTA_MIN_AD . ") AS num_ad  -- constante
```
Hoy los tres coinciden con la escala vigente (`NOTA_MIN_B=11`, `NOTA_MIN_A=14`,
`NOTA_MIN_AD=18`), así que el ranking es correcto.

**DISPARADOR:** si alguna vez se mueve `NOTA_MIN_B` o `NOTA_MIN_A`, `num_ad` se ajustará
solo y `num_c`/`num_b` se quedarán atrás **en silencio**, desincronizando el desempate.
Ante un cambio de escala hay que venir aquí y actualizar estas dos líneas a mano (y
revisar `num_alto`/`num_16`, congelados desde el cambio anterior). Las dos queries del
modelo (`rankingGradoLive` y `rankingPorSeccionLive`) llevan la misma copia.

## El tablero de Dirección consume el motor (04/09/2026)

`OrdenMeritoModel::statsPorGrado($periodoId)` es un punto de entrada nuevo
para pantallas que necesitan **indicadores por grado** y no el ranking completo:
devuelve, por grado, `mejor` · `peores` · `total`. Recorre `gradosConRanking` +
`rankingGrado` —ambos snapshot-aware— y **no añade ninguna consulta**.

🔴 **YA NO DEVUELVE `en_riesgo` (24/09/2026).** Lo hizo mientras «estudiante en riesgo» fue
un conteo de competencias en C, y de aquí heredaba dos cosas que no le tocaban: el ROSTER
del mérito (`estado='aprobada'`, que deja fuera a las matrículas `pendiente`) y los
agregados GLOBALES del ranking, incapaces de responder una regla que el MINEDU cuenta **por
área**. Ahora es la situación final (`PRO`/`RR`/`PER`) y su dueño es `SituacionFinalModel`,
con roster propio. Ver `docs/modulos/usuarios-direccion.md` § «Riesgo académico = situación
final del MINEDU».

De él cuelgan **tres pantallas**, vía la fachada
`AnioAcademicoModel::getStatsCierre`: `/admin/cuadros`, su imprimible A4 y
`/director/periodos/{id}/stats` (más el modal de cierre). Antes esas tres se
alimentaban de un ranking paralelo que llevaba **seis reglas de menos** y mentía
en el bimestre abierto. El caso medido y las consecuencias, en
`docs/modulos/usuarios-direccion.md` § *El mérito de esta pantalla no era el
mérito*; la lección general, en la memoria de reglas duplicadas.

⚠️ **Un solo recorrido de grados**: `rankingGrado` es snapshot-aware pero **no
está memoizado** (lo memoizado es `debeUsarSnapshot`). Quien necesite dos
indicadores del mismo periodo debe sacarlos de una sola pasada, como hace
`statsPorGrado`, y no llamar al ranking una vez por indicador.

El «en riesgo» de `AnioAcademicoModel::getResumenBimestre` —promedio general bajo
`NOTA_MIN_B` por nivel— **no es** el riesgo académico del informe de Dirección: son dos
preguntas distintas que compartían pantalla y rótulo. Desde el 07/09/2026 ya no comparten el
rótulo: aquélla se llama **«Promedio en C»**. Detalle en
`docs/modulos/usuarios-direccion.md`.

### `num_a` se DERIVA, no se consulta (07/09/2026)

`statsPorGrado` añade `num_a` a cada fila del ranking por **resta**:
`num_competencias − num_ad − num_b − num_c`. Es exacto porque los cuatro
literales son **disjuntos y exhaustivos** sobre 00-20 —AD (≥ `NOTA_MIN_AD`),
A (14-17), B (11-13), C (≤ 10)—, así que lo que no es AD, B ni C es A.

- **Cero consultas**: las otras tres columnas ya venían, y salen igual por las
  dos rutas de `rankingGrado` (en vivo y snapshot, que las guarda tal cual).
- ⚠️ **No usar `num_alto` para esto**: `(15,16)` **solapa** con A. Es un criterio
  de desempate, no un tramo de la escala.
- 🔴 **Es una premisa, y las premisas se vigilan.** Si algún día un tramo se
  solapara, un `SUM()` cambiara de condición o un `COUNT()` contara notas que los
  `SUM()` no cuentan, `num_a` saldría negativo o descuadrado y la tabla enseñaría
  un perfil **plausible y falso**, sin ningún error. Hay aserto en
  `verif_cuadros_merito_motor.php`: `AD+A+B+C = num_competencias` y `A >= 0` en
  toda fila listada, en los tres bimestres. Probado con mutante.

Lo consume el perfil por estudiante de «Estudiantes en riesgo» en
`/admin/cuadros`.

### `detalleCompetenciasC` — el desglose que cuadra (07/09/2026)

`detalleCompetenciasC(array $matriculaIds, int $periodoId)` devuelve, por matrícula, las
competencias en C con área, curso, nota y docente. Alimenta el desplegable de
«Estudiantes en riesgo».

Vive en ESTE modelo porque **replica el universo del mérito**; ninguna otra consulta del
repo puede cuadrar con `num_c` (la de boleta incluye extraordinarias, tutoría entera y
transversales agregadas).

⚠️ **Replica los filtros de la NOTA, no los del ALUMNO.** Bloqueo, `extraordinaria = 0`,
transversal/tutoría salvo Ética y exoneraciones **sí**. `ROSTER_MERITO` y el anclaje de
retorno **no**: la lista de matrículas ya viene resuelta por el ranking, y volver a
aplicarlos solo podría quitar filas de una matrícula ya seleccionada.

Una sola consulta con `IN` para todos los grados — `statsPorGrado` promete un solo
recorrido. Coste: `getStatsCierre` pasa de 26 ms a 40 ms en B1.

🔴 El snapshot **no guarda detalle**, así que en bimestre cerrado la fila está congelada y
esto va en vivo: si no cuadran, `statsPorGrado` deja `detalle_c` en NULL. Detalle del
guard y del aserto (que estuvo ciego) en `docs/modulos/usuarios-direccion.md`.

Verificación: `verif_cuadros_merito_motor.php` (solo lectura, corre en prod).

## Estado operativo
Ver `docs/ESTADO.md`. **Rediseño 1 COMPLETADO (25/07/2026):** A = filtro por tipo (en
prod); B = inmutabilidad tras publicar + versión rectificada no oficial en Centro de
control (migración 046 en prod); C = reconstrucción de B1 EN PROD.
**Rediseño 2 COMPLETADO (26/07/2026) y DESPLEGADO** (a más tardar en la v1.0.1, 08/09/2026; corregido el 22/09 — la cabecera se quedó en «dev» al desplegar): F1-F6 + F5b y
fixes, sin migración nueva. Detalle y desviaciones del plan en
`orden-merito-rediseno.md` §8.

**B1 (periodo 1) tiene snapshot oficial de 528 filas en PROD** (reemplazó a 519 previas).
Roster por REGLA del usuario: todos los estudiantes con calificaciones bloqueadas/aprobadas
en B1, SIN filtro de tipo (reincorpora 8 `trasladado` + `541` `retirado`, continuadores con
notas B1), conservando el anclaje de retornos. Es un CASO ESPECIAL de reconstrucción: la
regla general del código sigue filtrando por tipo (Fase A) y produce 519/520, por eso NO se
debe correr `backfill_orden_merito.php` en prod (sobrescribiría el 528). El candado 046
mantiene el oficial inmutable (B1 publicado → futuras correcciones van a `orden_merito_rectificado`).

## Otra regla que identifica a Ética por su nombre (21/09/2026)

Además de los 3 sitios del vínculo Ética ↔ Ed. Religiosa, `RectificacionModel::sqlSinReservadasDireccion`
identifica Ética por `AREA_ETICA_NOMBRE_BOLETA` para no explicarle al docente las extraordinarias
del I Bimestre 2026 (ver `calificaciones.md`). **No toca el mérito** (las extraordinarias ya están
fuera de él), pero si cambia el `nombre_boleta` de Ética, esa regla deja de aplicar sin avisar.

