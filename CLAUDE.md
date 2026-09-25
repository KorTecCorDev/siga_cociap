# SIGA-COCIAP — Contexto del proyecto

## Descripción
Sistema Integrado de Gestión Académica del Colegio de Aplicación
"Víctor Valenzuela Guardia" — UNASAM, Huaraz, Ancash, Perú.
Proyecto de tesis para obtener el título de Ingeniero de Sistemas e Informática.

## Stack tecnológico
- **Backend:** PHP 8.2 — framework MVC propio (sin Laravel aún)
- **Frontend:** HTML + SASS + JavaScript vanilla
- **Base de datos:** MySQL (XAMPP local) - Versión de MariaDB 10.4.32-MariaDB // Conjunto de caracteres del servidor: UTF-8 Unicode (utf8mb4) // Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12 // Versión del cliente de base de datos: libmysql - mysqlnd 8.2.12 // Versión de PHP: 8.2.12
- **Build tool:** Gulp (SASS → CSS, BrowserSync)
- **Control de versiones:** Git + GitHub
- **Objetivo futuro:** Migrar a Laravel
## Filosofía de Trabajo (OBLIGATORIO)

Estas reglas son de cumplimiento obligatorio durante todo el ciclo de desarrollo. En caso de conflicto entre reglas, aplica el siguiente orden de prioridad:

1. No romper funcionalidades existentes.
2. Preguntar antes de asumir cualquier comportamiento.
3. Respetar la arquitectura MVP del proyecto.
4. Mantener la compatibilidad con el sistema.
5. Realizar únicamente los cambios mínimos necesarios.
6. Mantener la calidad, seguridad y legibilidad del código.

---

### Principio de Incertidumbre

No debes tomar decisiones de diseño, arquitectura o implementación cuando exista información insuficiente, ambigua o contradictoria.

Si existe cualquier duda, por mínima que sea, debes detener la implementación y solicitar aclaraciones al usuario.

Nunca debes asumir:

- Reglas de negocio.
- Comportamientos esperados.
- Estructuras de datos.
- Relaciones entre módulos.
- Flujo de ejecución.
- Interfaces públicas.
- Convenciones no documentadas.
- Preferencias de implementación.

---

### Obligación ante la Incertidumbre

Antes de modificar cualquier archivo debes verificar si existe alguna incertidumbre.

Si detectas cualquier duda, debes:

1. Detener inmediatamente la implementación.
2. Enumerar claramente todas las dudas.
3. Explicar por qué cada una afecta la implementación.
4. Esperar la respuesta del usuario.

No continúes hasta recibir las aclaraciones correspondientes.

---

### Decisiones Técnicas

Si existen dos o más alternativas técnicamente válidas para resolver un problema:

- No elijas una automáticamente.
- Presenta todas las alternativas relevantes.
- Explica las ventajas y desventajas de cada una.
- Recomienda una opción con su justificación técnica.
- Espera la aprobación del usuario antes de implementarla.

---

### Alcance de la Implementación

Limítate estrictamente al alcance solicitado.

No implementes mejoras adicionales, optimizaciones, refactorizaciones, cambios de arquitectura o nuevas funcionalidades que no hayan sido solicitadas explícitamente.

Si detectas oportunidades de mejora, infórmalas al finalizar como recomendaciones, pero no las implementes sin autorización del usuario.

---

### Arquitectura

Respetar estrictamente la arquitectura MVP del proyecto.

- Model: acceso a datos y lógica de negocio.
- Presenter: coordinación entre Model y View.
- View: presentación e interacción con el usuario.

Nunca mezclar responsabilidades entre capas.

Mantener la estructura actual del proyecto.

---

### Modificaciones

Toda modificación debe ser incremental y localizada.

- Nunca sobrescribas archivos completos si basta con modificar una sección.
- Nunca elimines funcionalidades existentes sin autorización explícita.
- Nunca cambies interfaces públicas sin autorización.
- Reutiliza componentes existentes antes de crear nuevos.
- Conserva el estilo y organización del proyecto.
- Realiza únicamente los cambios indispensables para cumplir el objetivo solicitado.

---

### Seguridad

Toda implementación debe respetar las siguientes prácticas:

- Consultas preparadas (PDO).
- Protección contra SQL Injection.
- Protección contra XSS.
- Validación tanto en cliente como en servidor.
- Escape correcto de la salida.
- Manejo seguro de sesiones y autenticación cuando corresponda.

Nunca sacrifiques seguridad por rapidez de implementación.

---

### Estilo del Código

Respeta siempre:

- Convenciones de nombres existentes.
- Organización de carpetas.
- Estructura del proyecto.
- Estilo de codificación.
- Patrones ya utilizados.
- Consistencia con el resto del sistema.

El código nuevo debe integrarse naturalmente con el código existente.

---

### Protocolo Obligatorio antes de Implementar

Antes de escribir cualquier línea de código debes:

1. Leer completamente el Plan de Implementación.
2. Comprender el objetivo funcional.
3. Analizar la arquitectura relacionada.
4. Identificar todos los archivos involucrados.
5. Analizar dependencias directas e indirectas.
6. Evaluar posibles riesgos de regresión.
7. Verificar que no existan ambigüedades.

Si existe cualquier duda, vuelve al apartado **Principio de Incertidumbre**.

---

### Verificación Obligatoria

Antes de finalizar una implementación debes verificar que:

- No se rompieron funcionalidades existentes.
- No existen errores de sintaxis.
- No se modificaron interfaces públicas sin autorización.
- La arquitectura MVP continúa respetándose.
- El cambio mantiene compatibilidad con el resto del sistema.
- Solo se modificaron los archivos estrictamente necesarios.
- No existen efectos secundarios conocidos derivados de la implementación.

No finalices una tarea hasta completar esta verificación.

## Red de documentación (LEER ANTES DE TOCAR UN MÓDULO)

La documentación detallada vive en `docs/` y se lee BAJO DEMANDA. **Antes de
modificar código de un módulo, lee SU archivo** — contiene reglas de negocio,
decisiones de diseño y gotchas que NO son visibles en el código:

| Si el cambio toca… | Lee primero… |
|---|---|
| Boletas (imprimible, digital, token, pública dormida, firmas) | `docs/modulos/boletas.md` |
| Calificaciones, criterios, bloqueos, transversales, consulta de notas | `docs/modulos/calificaciones.md` |
| Matrículas, apoderados, estados, alta provisional, retorno/traslado | `docs/modulos/matriculas.md` |
| **Retorno de grado** (matrícula oficial vs operativa, candado, uniones) | `docs/modulos/retorno-grado.md` |
| Horarios, cargas académicas, solapes, Tutoría TOE | `docs/modulos/horarios.md` |
| Orden de mérito, snapshot, desempates, rectificaciones | `docs/modulos/orden-merito.md` |
| Usuarios, secciones/tutores, Director EBR, panel de bloqueos, conducta | `docs/modulos/admin.md` |
| **Usuarios de Dirección** (los 3 directores, solo lectura, `ROLES_DIRECCION`) | `docs/modulos/usuarios-direccion.md` |
| **Acompañamiento pedagógico: riesgo académico / situación final MINEDU + seguimiento** (PRO·RR·PER, `/admin/cuadros/acompanamiento`, A4) | `docs/modulos/usuarios-direccion.md` |
| **Reglas de PROMOCIÓN DE GRADO** (situación final PRO·RR·PER por grado y ciclo, «la mitad», qué cuenta, SIAGIE) | `docs/modulos/promocion-de-grado.md` |
| Exportación de notas al SIAGIE (llenado de Excel oficiales) | `docs/modulos/export-siagie.md` |
| UI: wayfinding, dashboard docente, botón Cerrar, tablas sticky, **banners de aviso** | `docs/modulos/ui.md` |
| Producción, seguridad, despliegue, secretos, setup SQL desde cero | `docs/infraestructura.md` |
| Decisiones diferidas (suspensiones, compuerta de publicación, capacitación) | `docs/decisiones-diferidas.md` |
| **CERRAR un bimestre en producción** (secuencia, consultas, verificación) | `docs/runbooks/cierre-de-bimestre.md` |
| Compuerta temporal de edición y los 4 registros del bimestre | `docs/modulos/cierre-cuatro-registros.md` |
| Boleta con todas las competencias del plan y guion donde no hay dato | `docs/modulos/boleta-competencias-completas.md` |
| **Notas del COLEGIO DE ORIGEN** (trasladado: informativas, nunca en boleta) | `docs/modulos/matriculas.md` |
| **Completar notas de un bimestre CERRADO** (llegó tarde: extraordinaria, en lote) | `docs/modulos/calificaciones.md` |
| **Notificaciones y comunicados** (bandeja, campana, avisos al docente) | `docs/modulos/notificaciones.md` |
| **Consulta de notas con transversales y conducta** | `docs/modulos/consulta-notas-ampliada.md` |
| **Bloqueos fantasma del cierre + visibilidad del tutor** | `docs/modulos/transversales-visibilidad-tutor.md` |
| **Cambio de sección a mitad de bimestre** (mudanza, convalidación, reversión) | `docs/modulos/cambio-seccion.md` |
| **Estado vivo: pendientes, migraciones, planes con fecha** | `docs/ESTADO.md` |

> ⚠️ **Esta tabla enruta por TEMA, nunca por ESTADO.** No añadir «(PLAN, sin implementar)»,
> «en dev» ni «desplegado» a una fila: eso caduca en cada despliegue y se lee al revés de la
> realidad. **Si un módulo está construido, a medias o solo planeado se responde en
> `docs/ESTADO.md`**, y el detalle en la cabecera de su propio doc. Hasta el 17/08/2026 cinco
> filas llevaban esa etiqueta y **tres eran falsas** — anunciaban como plan sin implementar
> cosas que llevaban diez días en producción, justo en el archivo que se carga en cada sesión.

### Reglas de mantenimiento de la red
- Al terminar un cambio de módulo, actualiza SU archivo en `docs/` (no CLAUDE.md).
- CLAUDE.md solo cambia si nace un invariante global o un módulo nuevo (fila en la tabla).
- Pendientes, migraciones y planes con fecha se registran SOLO en `docs/ESTADO.md`.
- **AL DESPLEGAR, actualiza la CABECERA del doc del módulo, no solo `ESTADO.md`.** Es el
  hueco de proceso que dejó cuatro docs afirmando «en `dev`» o «sin desplegar» meses después
  de estar en producción: el doc se escribe al IMPLEMENTAR y nadie vuelve a él al DESPLEGAR.
  El registro del deploy va en `ESTADO.md` (sección Git); el estado del módulo, en su doc.
- NUNCA usar la sintaxis de import `@ruta` en CLAUDE.md — auto-carga el archivo
  en cada sesión y anula el ahorro de contexto. Referenciar siempre por ruta simple.

## Invariantes críticos (NO romper)

Versión de una línea; el porqué completo está en el doc del módulo.

- **Boleta = solo competencias bloqueadas** (`getBoletaAlumno` INNER JOIN `bloqueos_competencia`).
- **Boletas al público SIEMPRE por token** — jamás reintroducir rutas anónimas por id
  (eran enumerables). Toda boleta se arma con `BoletaModel::armar()`; el QR sale solo
  de `urlBoletaToken()`.
- **CERRAR UN BIMESTRE NO PUBLICA NADA.** Publicar las boletas a las familias es un
  acto separado, por NIVEL y con fecha/hora (`periodos_publicacion`, migración 044).
  Punto único: `PublicacionBoletaModel`. El umbral `'oficial'` de `BoletaModel::armar()`
  respeta la compuerta (acceso en línea de familias); `'archivo'` la ignora a propósito
  (documento impreso por staff: salida masiva y trasladado). **TODAS las vistas de
  boleta se arman con `estructuraCompleta = true`** (9 entradas, desde el 04/08/2026):
  **siempre las 4 columnas de bimestre**, aunque estén vacías (formato oficial); el
  guard de datos es el que respeta la compuerta — abrir columnas NO filtra notas.
  Si nace una entrada nueva, pasa el flag: la estructura anual es del DOCUMENTO, no
  del umbral de datos.
- **Escala de notas: punto único de verdad en `app/Helpers/helpers.php`**
  (`NOTA_MIN_AD/A/B`, `nota_a_literal()`, `escala_rangos()`). NUNCA hardcodear umbrales.
  **APROBAR depende del NIVEL** y también sale de ahí: `LITERALES_APROBATORIOS` /
  `nota_es_aprobatoria()` (primaria AD+A · secundaria AD+A+B). La escala literal es la
  misma en los dos niveles; lo que cambia es dónde está la línea del aprobado. ⚠️ NO es
  la métrica «en logro» de `AnioAcademicoModel::getResumenBimestre()` (AD+A en ambos):
  son preguntas distintas y no se unifican. Ver `docs/modulos/calificaciones.md`.
- **Rutas literales ANTES que patrones `{param}`** en `routes/web.php` (el router
  ancla por orden de registro).
- **`criterios.confirmado_en` es la única verdad de "oficial"**: cualquier mutación
  del criterio desconfirma; `marcarConfirmado` va ANTES de `recalcularPromedioSeccion`.
- **Fila en `calificaciones` existe ⟺ el alumno tiene nota viva** (el DELETE de
  huérfanos mira `eliminado_en`, NO `confirmado_en`).
- **Estados de matrícula: SOLO `pendiente`/`aprobada`/`desactivado`** (`'activo'` se
  eliminó del enum; otras columnas `estado` de otras tablas son independientes).
- **Retorno de grado: se EVALÚA en la operativa, se DOCUMENTA con la oficial**
  (Regla A, 05/08/2026). Los datos NO se copian ni se mueven entre matrículas:
  cada bimestre queda donde se cursó y la boleta une las fuentes al leer
  (`boletaContexto`). ⚠️ Las dos exclusiones son **INVERSAS**, y **cada una tiene
  su PUNTO ÚNICO en `helpers.php`** (27/08 y 02/09/2026): `roster_evaluacion()`
  excluye la **oficial** (los rosters de evaluación) y `matricula_documento()`
  excluye la **operativa** (lote de boletas, token público y **todas** las
  estadísticas de `/matriculas/resumen`). Ninguna se escribe a mano. La tercera
  forma —pegarle el `WHERE estado` de la primera a la segunda— es el **híbrido**,
  y con un retorno `revertido` no excluye ninguna de las dos: lo impide
  `verif_matricula_documento.php`. Un retorno NO se registra a mitad de bimestre ya
  evaluado (candado en `RetornoGradoController`), y se ancla en el DATO, no en la
  fecha: **los bimestres se solapan**. Ver `docs/modulos/retorno-grado.md`.
- **Rosters de evaluación (calificaciones, conducta, transversales, tutoría)
  excluyen matrículas `tipo IN ('trasladado','retirado')`** — el resto (incl.
  `desactivado` por deuda y `pendiente`) SÍ se califica. `retirado` = ya no asiste
  sin traslado oficial (migración 045); reversible vía `tipo_anterior`. NO extender
  a los usos de `trasladado` en boleta (un retirado es desactivado no-trasladado →
  BORRADOR). **PUNTO ÚNICO desde el 27/08/2026: `roster_evaluacion()` en
  `helpers.php`** (emite las 3 condiciones, como `orden_alfabetico()`); antes
  estaban copiadas a mano en 9 consultas, `getAlumnosSeccion` incluida. ⚠️ **TRES
  consultas NO lo usan a propósito** y llevan comentario que lo dice:
  `CalificacionModel` (añade `estado IN ('aprobada','pendiente')`),
  `ControlOperativoModel` y `OrdenMeritoModel::ROSTER_MERITO` (universo del mérito,
  que es su propio punto único). Protegido por `verif_roster_evaluacion.php`.
  Ver `docs/modulos/matriculas.md`.
- **Los 4 contadores de `inasistencias` son INDEPENDIENTES**, no un total y su
  subconjunto: `faltas`/`tardanzas` YA son las **sin justificación**. NUNCA restar
  (159 filas tienen `faltas_justificadas > faltas`). Ver `docs/modulos/admin.md`.
- **Orden de mérito excluye áreas `tipo IN ('transversal','tutoria')`, con UNA excepción:
  ÉTICA Y VALORES cuenta en TODA secundaria, 5.º incluido** (decisión cerrada 05/08/2026).
  **Ética NO es tutoría**: es la nota del área-curso **Educación Religiosa de secundaria**,
  que no tiene cargas propias (la evalúa el tutor por su carga TOE). Su `tipo='tutoria'` es
  un artefacto de implementación. Sin la excepción, el mismo curso pesaba en primaria y no
  en secundaria. Deroga la regla del 04/08 que la sacaba de 5.º —aquella listaba «Ética y
  Valores» y «Educación Religiosa» como áreas distintas, siendo la misma—.
  El **vínculo Ética↔Ed. Religiosa vive en 3 sitios y ninguno es un dato estructural**:
  excepción de hoja SIAGIE (`035-EREL`), excepción del mérito y `alias_boleta` del área;
  al tocar uno, revisar los otros dos. Se identifica por `nombre_boleta`
  (`AREA_ETICA_NOMBRE_BOLETA`), **NUNCA por id** (difiere entre entornos; el id 57 es GAMA
  y el código C57 es Ética). ⚠️ El área *Ed. Religiosa* de secundaria debe seguir **sin
  cargas**: si recibiera notas, el mismo curso contaría dos veces (guard en
  `verif_universo_merito.php`). Ver `docs/modulos/orden-merito.md`.
- **Mérito EN VIVO = solo competencias BLOQUEADAS** (join a `bloqueos_competencia`, sin
  filtrar `origen`). La cascada ya no desempata por apellido: tras `num_16` es MANUAL y
  el orden lo fija `m.id`.
- **Las ÁREAS EXONERADAS no entran al promedio del mérito** (`NOT EXISTS` sobre
  `exoneraciones` en las 2 queries que calculan promedio; cubre exoneración por área y por
  subárea). Es el cálculo EN VIVO: los snapshots guardados NO se tocan. Nació porque
  **se puede exonerar a un alumno que YA TIENE notas** (con confirmación explícita) y esas
  notas **no se borran** —dejan de mostrarse, para que la exoneración sea reversible—, así
  que sin el filtro la boleta decía EXO y el ranking seguía promediando la nota.
  Ver `docs/modulos/matriculas.md` y `docs/modulos/orden-merito.md`.
- **PUBLICAR libera boletas Y orden de mérito juntos**, por nivel, bajo la compuerta 044.
  Cerrar oficializa el mérito pero no lo muestra: claustro y familias lo ven solo cuando
  su nivel está publicado. **CERRAR EXIGE MÉRITO ÍNTEGRO**: 0 empates sin resolver y 0
  alumnos con evaluación incompleta (`alertasEvaluacionIncompleta`).
- **`gradosConEmpatesPendientes` usa `rankingGradoLive`, NO `rankingGrado`** — valida lo
  que se va a congelar, no lo ya congelado (si no, al re-cerrar un bimestre publicado y
  reabierto leería el snapshot viejo).
- **Ningún script de `database/` debe "limpiar" con DELETE lo que no creó.** Si escribe
  para probar, transacción + rollback (ya pasó: una verificación borró el oficial de B1).
- **Roster de asistencia = roster de notas** (`getAlumnosSeccion`): `/admin/asistencia`
  usa el mismo filtro por `tipo` + exclusiones de retorno, SIN filtrar por `estado`.
  Filtrar por `estado='aprobada'` dejaba fuera a `pendiente`/`desactivado`, que sí
  asisten, y su boleta salía con 0 inasistencias (dato falso). Ver `docs/modulos/admin.md`.
- **Orden alfabético SIEMPRE con `orden_alfabetico()` / `COLLATE_ES`** (`helpers.php`):
  las columnas de `personas` son `utf8mb4_unicode_ci`, que equipara Ñ ≡ N y coloca
  ÑIQUEN antes que NOLASCO. El `COLLATE utf8mb4_spanish_ci` va SOLO en el `ORDER BY`
  — cambiar la colación de las columnas rompería la búsqueda tolerante a la ñ.
  Nunca ordenar por un alias `CONCAT(...)`: hereda la colación de la columna.
  Ver `docs/modulos/ui.md`.
- **Orden de mérito: al ranking SOLO entran las matrículas `estado='aprobada'`**
  (12/08/2026; deroga la mitad `estado`→`tipo` de la Fase A). `pendiente` —el estado
  en que NACE toda matrícula— y `desactivado` NO compiten, aunque sí se califican y
  reciben boleta: **pertenecer al documento oficial y ser evaluado son preguntas
  distintas**. Se conserva además el filtro por `tipo` (sin él, la excepción de
  abajo podría reingresar a un retirado) y el anclaje de retorno. **UNA excepción:**
  la operativa de un retorno REVERTIDO, que queda `desactivado` pero es donde viven
  las notas de los bimestres que el alumno cursó allí. PUNTO ÚNICO:
  `OrdenMeritoModel::ROSTER_MERITO` (5 consultas). Mover `matriculas.estado` dentro
  o fuera de `aprobada` obliga a llamar a `sincronizarRosterPorMatricula` — son 6
  sitios contando los de `tipo`. Ver `docs/modulos/orden-merito.md`.
- **Orden de mérito: el snapshot OFICIAL es INMUTABLE una vez que el periodo
  ESTUVO publicado** (compuerta 044; `PublicacionBoletaModel::fuePublicado`,
  monotónico vía `periodos_publicacion.primera_publicacion_en`). Cierres y
  rectificaciones posteriores NO tocan el oficial: van a `orden_merito_rectificado`
  (versión no oficial, visible solo en `/admin/control`). PUNTO ÚNICO:
  `OrdenMeritoModel::registrarRanking` (lo usan `cerrar` y la rectificación);
  `generarSnapshot` directo NO honra el candado (solo backfill/reconstrucción).
- **«Estudiante en riesgo» = SITUACIÓN FINAL del MINEDU** (24/09/2026): los que **no
  serían promovidos**, es decir `RR` (requiere recuperación) o `PER` (permanece en el
  grado). Norma: **RVM 00094-2020-MINEDU, modificada por la RVM 048-2024-MINEDU**.
  **Deroga** la regla del 23/09 (primaria B+C ≥ 3 · secundaria C ≥ 3), que era un conteo
  global inventado. 🔴 **SE CUENTA POR ÁREA, NO POR COMPETENCIAS SUELTAS**, y la exigencia
  **depende del GRADO**: en los finales de ciclo (primaria 2.º/4.º/6.º, secundaria 2.º/5.º)
  una sola «C» impide la promoción; en los intermedios basta la mitad o más en B o superior
  **en cada área**. «La mitad» es `ceil(n/2)` por convención explícita de la norma (5 → 3,
  3 → 2, 1 → esa única). **«Más de la mitad» es la mitad entera más una, `c > n/2`**
  (3 de 5, 2 de 3, 1 de 1; decisión del usuario del 24/09/2026, punto único
  `mas_de_la_mitad()`) — NO se deriva de `ceil(n/2)`. **1.º de primaria: promoción automática**, nunca en riesgo (se
  lista aparte como seguimiento). Las **transversales NO cuentan** (numeral 5.1.3.9);
  las **extraordinarias SÍ** (el mérito no: son filtros distintos a propósito).
  **Competencias pendientes** (24/09/2026): la sigla sale de lo evaluado y
  `situacion_final_proyectar()` la prueba contra el PLAN completo de cada área →
  riesgo **seguro** o **proyectado**. Cada competencia usa su **último nivel registrado**
  (como el SIAGIE), salvo en el periodo final, que usa solo sus notas. Los **talleres**
  (`tipo='taller'`, migración 064) cuentan SOLO en los años y grados que la UGEL aprobó
  (`talleres_aprobacion`, se marca en Currículo; 2026: ninguno); boleta y mérito, siempre.
  En un retorno de grado, toda regla (también esta) usa el grado de la matrícula OFICIAL.
  PUNTO ÚNICO: `situacion_final_analisis()` en `helpers.php` (función pura) y
  `SituacionFinalModel` (los datos). ⚠️ **Su roster NO es `roster_evaluacion()` ni
  `ROSTER_MERITO`**: es `matriculas_vigentes()` + el anclaje por bimestre del retorno —el
  primero no ancla y dejaría sin notas a un retorno revertido; el segundo exige
  `estado='aprobada'` y deja fuera a los `pendiente`, que sí se evalúan—. No es el
  «Promedio en C» de `getResumenBimestre`. **El riesgo cuenta al retorno en su matrícula
  OFICIAL**, que además fija el grado y con él la regla. **El tutor ve SU sección**
  (`/docente/tutoria/acompanamiento` y su card): sale de `tutor_id` en servidor, nunca de la URL, y
  solo en bimestres PUBLICADOS de su nivel (compuerta 044). El informe se llama
  **«Acompañamiento pedagógico»** (24/09/2026; las rutas `riesgo` redirigen) y suma un bloque de
  **SEGUIMIENTO** que NO es riesgo ni entra a sus cifras: **todo evaluado** (PRO, RR, PER y
  1.º de primaria; desde el 25/09/2026 un RR/PER sale en los dos bloques) con
  prim ≥ 3 B o ≥ 3 C · sec ≥ 3 C (punto único `seguimiento_pedagogico()`). Ver
  `docs/modulos/usuarios-direccion.md`.
- **Los TRES directores son SOLO LECTURA y salen de `ROLES_DIRECCION`**
  (`helpers.php`; 24/08/2026). Nunca listar sus códigos a mano — eran 44 literales
  en 16 archivos. **DOS excepciones deliberadas, que NO se deben "arreglar":**
  `DirectorEbrModel::listarCandidatos` y `Admin\DirectorEbrController::asignar`
  anclan a `'director_ebr'` **en singular** porque **solo el Director EBR firma**
  boletas, actas y reportes. La escritura se guarda POR MÉTODO
  (`ROLES_ESCRIBEN`), no en el constructor: el director entra a VER. ⚠️ Al buscar
  estos códigos, hacerlo **entre comillas** — `director_ebr` también es parte de
  la tabla `director_ebr_historial`. La única copia que no puede leer la constante
  es el color del avatar en `_admin.scss`. Ver `docs/modulos/usuarios-direccion.md`.
- **PDO preparado siempre**; `cargas_academicas` y `criterios` NO tienen UNIQUE KEY →
  proteger duplicados con `WHERE NOT EXISTS`.
- **NUNCA CSS inline en PHP** — todo en SASS bajo `resources/sass/` + `gulp build`.
- **Secciones de contenido variable: «datos a la vista, acción aparte»** (21/09/2026).
  Con datos, card abierta con sus acciones en la cabecera; sin datos, no se pinta y
  queda solo su botón. NUNCA datos detrás de un `<details>` cerrado (tampoco se
  imprime). Rige para toda sección nueva. Ver `docs/modulos/ui.md`.
- **El auto-deploy de Hostinger borra TODO lo no versionado** en cada push →
  secretos y archivos subidos viven fuera del repo (`~/siga_secrets/`, `~/siga_uploads/`).
- **Git: `dev` = trabajo, `main` = producción (auto-deploy). PREGUNTAR antes de
  mergear `dev` → `main`.**

## Arquitectura y base de datos
El árbol de carpetas y el listado de tablas se leen del propio repo (`ls`,
`SHOW TABLES`) — no se documentan aquí para que no se desincronicen.
Lo que NO es derivable ya vive en los invariantes de arriba (`public/css/app.css`
lo compila Gulp, `config/database.php` no lleva secretos, `routes/web.php` es la
única tabla de rutas). Migraciones aplicadas y orden de setup desde cero:
`docs/infraestructura.md` y `docs/ESTADO.md`.

## Estructura curricular
- **Área con subáreas:** cada subárea tiene 1 competencia y 1 docente
- **Área-curso:** sin subáreas, 1 docente dicta todas las competencias
- **Unidocente:** primaria 1°-3°, flag `es_unidocente` en tabla secciones
  -Todas las áreas son un área-curso, todas las cargas que deberían ser subáreas se integran al área y se evalúa como un área-curso.
- **Competencias transversales:** las registran cada docente por carga académica y las conclusiones descriptivas, aprobación y bloqueo de notas queda a cargo del tutor de sección.
- **Tutoría (TOE):** área `tipo='tutoria'` sin competencias — visible en horario,
  invisible a notas (por datos, no por código). Ver `docs/modulos/horarios.md`.

## Escala de calificaciones
- **Notas:** siempre numéricas 00-20 en BD
- **Umbrales literales (actualizados 10/06/2026):** AD: 18-20 · A: 14-17 · B: 11-13 · C: 00-10.
  Definidos como PUNTO ÚNICO DE VERDAD en `app/Helpers/helpers.php`:
  constantes `NOTA_MIN_AD` (18), `NOTA_MIN_A` (14), `NOTA_MIN_B` (11).
  - Conversión PHP: SIEMPRE via `nota_a_literal()` (los modelos delegan, no duplican el match).
  - Queries SQL: interpolan las constantes (`OrdenMeritoModel` x2, `ControlOperativoModel`,
    `AnioAcademicoModel`). NUNCA hardcodear el umbral en una query nueva.
    - ⚠️ **EXCEPCIÓN CONOCIDA (auditada el 04/08/2026, se mantiene por decisión):** en las
      dos queries de `OrdenMeritoModel` solo `num_ad` usa la constante; `num_c` (`<= 10`)
      y `num_b` (`BETWEEN 11 AND 13`) están **hardcodeados**. Hoy coinciden con la escala,
      pero **si se mueve `NOTA_MIN_B` o `NOTA_MIN_A` hay que actualizarlos a mano** o el
      desempate se desincroniza en silencio. Ver `docs/modulos/orden-merito.md`.
  - Leyendas de boletas: `escala_rangos()` genera los rangos de texto.
  - El cambio es retroactivo automático: la BD solo guarda `nota_numerica`; el literal
    se calcula al vuelo (B1: 717 notas de 17 pasaron de AD a A).
  - Los desempates `num_alto IN (15,16)` y `num_16` del orden de mérito NO se tocaron
    (decisión del colegio pendiente sobre regenerar el ranking B1).
- **Primaria:** boleta solo muestra literal (AD/A/B/C)
- **Secundaria:** boleta muestra numeral + literal
- **Conclusión descriptiva:**
  - Primaria: obligatoria en B y C
  - Secundaria: obligatoria solo en C

## Flujo de calificaciones (módulo principal)
```
1. Docente entra a su carga académica
2. Define criterios de evaluación (libres, igual peso)
3. Ingresa notas por criterio para todos los alumnos
4. Sistema calcula promedio automáticamente
5. Docente ve resumen → agrega conclusiones descriptivas
6. Docente aprueba y bloquea la competencia
7. Padre puede ver notas, criterios y conclusiones
8. Padre accede a la boleta desde /padre/notas SIEMPRE por token:
   - "Ver boleta digital"  → /boleta/digital/{token}  (mobile-first)
   - "🖨 Imprimir"          → /boleta/ver/{token}      (A4 landscape)
```

## Convenciones de código
- **Namespace:** `App\Controllers\`, `App\Models\`, `Core\`
- **Rutas:** `$router->get('/ruta', 'Namespace\Controlador@metodo')`
- **Vistas:** `$this->view('carpeta/archivo', ['variable' => $valor])`
- **JSON:** `$this->json(['success' => true, 'mensaje' => '...'])`
- **CSRF:** siempre `$this->validateCsrf()` en métodos POST
- **404:** siempre `$this->notFound()` (`BaseController`, `never`). Hace
  `http_response_code(404)` + `require` de `shared/404.php` + `exit`. NUNCA
  `$this->view('shared/404')`: esa vista es una página HTML completa y el layout
  la anidaría dentro de otra. Existe desde el 07/08/2026 — antes varios
  controladores lo llamaban sin que estuviera definido (fatal en local, página de
  error genérica en prod)
- **403:** siempre `$this->forbidden()` (`BaseController`, `never`), gemelo de
  `notFound()`; `requireRole` ya lo usa. NUNCA `$this->view('shared/403')`: anidaba
  la página dentro del layout (21/09/2026). Las páginas `shared/403|404|500` cargan
  **`public/css/errores.css`** (entrada SASS propia `resources/sass/errores.scss`),
  no `app.css`
- **Auth: por controlador — NO hay middleware.** `app/Middleware/AuthMiddleware.php`
  nunca se usó y se eliminó (commit `eb0e9cf`, 20/06/2026). No reintroducir una capa
  de middleware sin acordarlo antes.
- **Commits:** Conventional Commits en español sin tildes
- **Estilos:** NUNCA CSS inline en PHP — siempre en SASS bajo `resources/sass/`
- **config():** la función NO soporta notación de puntos. Usar `config('institucion')`,
  NO `config('app.institucion')`. Las claves son las del array en `config/app.php`.
- **Comillas ASCII** en vistas PHP — jamás comillas tipográficas U+201C/U+201D en
  atributos HTML (rompen los `data-*` y el JS en silencio).
- **`exif_imagetype()` NO disponible en XAMPP local** — usar `\getimagesize($path)[2]`.

## Notas de entorno
- Logo del colegio: `public/assets/img/logo_cociap.png` (con guión bajo)
- URL base dinámica via meta tag: `<meta name="base-url" content="...">`
- BrowserSync corre en puerto 3000; alias Git Bash `local3000` para iniciar el entorno
- Todo QR se genera con `qrcode.min.js` local — sin servicios de terceros
- `hash.php` en raíz: archivo temporal para hashes bcrypt — eliminar tras usarlo,
  NO commitear ni dejar en el servidor
- Firma/sello PNG del Director EBR: FUERA del repo (`~/siga_uploads/firmas/` prod,
  `storage/firmas/` local), servidos por `GET /firmas/{archivo}`. Detalle en
  `docs/infraestructura.md`.
