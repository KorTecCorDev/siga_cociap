# Verificaciones — Rediseño del orden de mérito

Scripts CLI de verificación de las fases ya implementadas. Se corren a mano
(`php database/verificaciones/<archivo>.php`) contra la BD local. Asumen el
dataset de referencia (backup activo, mismo que producción): IDs de matrícula/
grado concretos del I Bimestre (541 retirado, 220/666 pendientes, 692/190 retorno).

- **`verif_fase_a_orden_merito.php`** — SOLO LECTURA. Comprueba el filtro por `tipo`:
  pendientes entran al ranking, `trasladado`/`retirado` quedan fuera, el retorno de
  grado sigue anclado (operativa compite, oficial excluida) y B1 sin empates nuevos.
  Lee el cálculo **EN VIVO** (`rankingGradoLive`, por reflexión), no el wrapper
  snapshot-aware: B1 congeló la regla ESPECIAL de la Fase C (roster sin filtro de
  tipo), y desde el snapshot un `trasladado` sí aparece.

- **`verif_fase_b_orden_merito.php`** — Comprueba la migración 046 y el candado de
  inmutabilidad (`registrarRanking`): publicado sin oficial → oficial; publicado con
  oficial → rectificado (oficial intacto). Escribe, pero **todo corre dentro de una
  TRANSACCIÓN con ROLLBACK** y su paso 4 verifica que los conteos volvieron al valor
  previo. NO se limpia con DELETE: su primera versión lo hacía y llegó a destruir el
  snapshot oficial de B1 en local (26/07/2026).

- **`verif_fase1_rediseno_merito.php`** — SOLO LECTURA. P1/P2 del rediseño 2: B1 lee
  del snapshot (528), el universo del cálculo en vivo son solo competencias
  bloqueadas, y el orden estable por `matricula_id` corre sin error.

- **`verif_fase5b_rediseno_merito.php`** — Transacción + ROLLBACK. Con el bimestre
  reabierto el ranking SIGUE saliendo del snapshot (candado 046), mientras que
  `gradosConEmpatesPendientes` sí mira el cálculo en vivo. Su **paso 0 es un control**:
  si el vivo y el snapshot coincidieran, los pasos siguientes no probarían nada.

- **`verif_empates_card_control.php`** — Transacción + ROLLBACK. La card de empates de
  `/admin/control` y la pantalla donde se resuelven (+ el guard del cierre) deben
  reportar **los mismos grados y los mismos conteos**. Su **paso 2 retira temporalmente
  las resoluciones humanas** para tener un escenario con empates de verdad: con todo
  resuelto ambos dan 0 y la comparación no probaría nada. El paso 3 verifica que el
  ROLLBACK las devolvió.
  - Existe porque el Centro de Control tuvo su **propia copia de la cascada** desde el
    08/06/2026, congelada en la tupla de 3 conteos (`num_c|num_b|num_ad`) sin los
    criterios de regularidad alta (`num_alto`, `num_16`) que el motor real incorporó
    ese mismo día. Inventaba empates que el motor deshace solo y que la pantalla de
    resolución nunca ofrecía → **fantasmas irresolubles**. Corregido el 04/08/2026
    delegando en `OrdenMeritoModel::gradosConEmpatesPendientesDetalle`.

- **`verif_roster_asistencia.php`** — **SOLO LECTURA** (no escribe, no abre
  transacciones): es el único que **se puede correr en PRODUCCIÓN**, y por eso NO lleva
  el guard de secretos de los demás. Comprueba que la grilla de
  `/admin/asistencia/{id}?periodo={pid}` lista **el mismo roster que la grilla de notas**
  (`getAlumnosSeccion`: filtro por `tipo` + las dos exclusiones de retorno de grado, sin
  filtrar por estado) y que los `esperados` de `getProgresoPorSeccion` cuadran con ella.
  - Su **bloque 3 mide el impacto del despliegue** frente a la regla vieja
    (`m.estado='aprobada'`, sin tipo ni retornos): quién entra, quién sale y cuántas
    filas de `inasistencias` tiene cada uno. **Correrlo en prod ANTES del deploy** dice
    exactamente qué imprimibles ya firmados cambian. En local: 6 entran (todos
    `pendiente`, dos con datos de B1 que la grilla no mostraba) y 1 sale (la matrícula
    oficial de un retorno activo).
  - El bloque 4 cuenta las filas de matrículas fuera del roster: **dato histórico, no se
    borra** — siguen sumando en la boleta, que va por matrícula y no por roster.

- **`verif_asistencia_boleta.php`** — Transacción + ROLLBACK. Comprueba que el bloque de
  ASISTENCIA de la boleta usa el mismo umbral que las notas (`periodoAportaNotas`) **sin
  alterar lo que ven las familias**: `'oficial'` sigue siendo cerrados+publicados y
  `'archivo'` cerrados, mientras `'borrador'` y `'todos'` suman el bimestre en curso.
  - Su **paso 4 SIMULA el Hito A** del bimestre activo: sin él, `'borrador'` daría lo
    mismo que `'archivo'` y la aserción no probaría nada. El mismo paso verifica que
    `'oficial'` y `'archivo'` **no** se contagian del bimestre en curso, y el cierre
    comprueba que el ROLLBACK devolvió `boletas_aprobadas_en` a su valor original.
  - Existe porque la asistencia era el único de los tres bloques por periodo (notas,
    conducta, asistencia) que no honraba la excepción de la vista previa de RA.

- **`verif_estructura_boleta.php`** — secciones 1-3 **SOLO LECTURA**; la **sección 4 escribe
  en transacción + ROLLBACK** y se **omite en producción** (guarda del archivo de secretos),
  así que sigue siendo apto para producción. Comprueba que
  las boletas se arman con la **estructura anual completa** (las 4 columnas de bimestre) en
  los cuatro umbrales, y —lo importante— que abrir esas columnas **NO relaja el guard de
  datos**: con `'oficial'` se ven 4 columnas pero solo aportan notas los bimestres cerrados
  Y publicados, aunque el bimestre en curso ya tenga notas en la BD.
  - Su **paso 3 es el control**: compara los bimestres con datos **con y sin**
    `estructuraCompleta` y exige que sean los mismos. Si difirieran, el flag estaría
    filtrando datos y no solo formato.
  - Existe porque la regla de formato del 09/07/2026 se había aplicado solo al token y al
    trasladado: la impresión masiva y el ZIP de archivo colapsaban columnas, y el papel que
    se firma salía con otro formato que el que la familia abre por QR.
  - 🔴 **17/09/2026 — estuvo en ROJO por su aserto, y CIEGO en sus verdes.** El esperado de
    la sección 2 contaba `calificaciones` en crudo, sin el invariante de competencias
    BLOQUEADAS ni el criterio confirmado: se puso rojo cuando la matrícula de prueba recibió
    una nota de B3 sin bloquear (el veredicto dependía del equipo, no del código). Ahora el
    esperado sale de `getBoletaAlumno` por cada fuente de `boletaContexto`, y el sujeto de
    prueba excluye exonerados (el `EXO` se inyecta en los 4 periodos).
  - Y con los datos reales los cuatro umbrales **coincidían**: la sección 2 no podía ver una
    fuga. Por eso nace la **sección 4**: sobre el último bimestre cerrado, publicado y con
    notas, fuerza *cerrado sin publicar*, *activo con Hito A* y *activo en registro*, y exige
    qué umbral muestra y cuál oculta ese bimestre. **Probado con 4 mutantes** de
    `periodoAportaNotas` (uno por umbral): la versión nueva los detecta todos; la anterior,
    **ninguno**.
  - Su **bloque 4b** cubre el invariante mayor de la boleta —«solo competencias
    BLOQUEADAS»—, que **ningún verificador del repo vigilaba**: retira un bloqueo en
    transacción y exige que esa celda desaparezca de los cuatro umbrales. La sección 2 no
    puede hacerlo, porque su esperado sale de la misma consulta que podría perder el JOIN.
    Medido con el mutante (`INNER JOIN` → `LEFT JOIN` en `getBoletaAlumno`): el bloque nuevo
    falla en los 4 umbrales y **la versión anterior del verificador se ponía VERDE** — el
    fallo le quitaba además su propio rojo.

- **`verif_universo_merito.php`** — **SOLO LECTURA**, apto para producción. Lista, grado por
  grado y periodo por periodo, **qué áreas aportan al promedio del orden de mérito** y
  cuáles quedan excluidas, con el conteo de notas de cada una.
  - **Falla (exit 1)** si un área PROHIBIDA en un grado empieza a aportar. La lista vive
    en el array `$prohibidas` del propio script: hoy solo cubre **5.º de secundaria**
    (Arte y Cultura, EPT, Ética y Valores, Ed. Religiosa y Transversales), que es la regla
    del colegio del 04/08/2026.
  - Existe porque el universo del mérito **no está declarado en ninguna tabla**: se deriva
    de las notas que existan, así que un área entra al promedio en cuanto alguien le crea
    una carga y registra notas. Esta verificación es la red de seguridad — a propósito NO
    se hardcodeó la exclusión en el SQL del mérito, que duplicaría el plan de estudios.

- **`verif_nomina_docente_render.php`** — **SOLO LECTURA**, y la única que **RENDERIZA una
  vista de verdad**: simula una sesión de docente, ejecuta
  `Docente\PanelController::nomina()` y examina el HTML. Comprueba que el **panel de boleta
  (imprimir + digital) está presente**, que el rótulo del mérito no nombra un bimestre sin
  publicar y que el buscador no lista matrículas `pendiente`.
  - 🔴 **Existe por una regresión real del 10/08/2026:** al cambiar la fuente del mérito se
    eliminó la variable `$bimestre`, pero el array de la vista seguía leyéndola en
    `'bimestreCerrado' => $bimestre[...] ?? null`. **El `??` suprime el aviso de variable
    indefinida**, así que la clave quedó en `null` y **el panel de boleta desapareció de
    todas las cards**. Ni `php -l` ni las verificaciones del MODELO podían verlo.
  - **Control ejecutado** (reintroduciendo el fallo a propósito): la sonda pasa de
    **2080 paneles a 0** y el HTML de **1 413 206 a 650 006 bytes** — falla, como debe.
  - **Lección aplicable a todo el repo:** `?? null` sobre una VARIABLE (no sobre un índice
    de un array que ya existe) convierte un error en un silencio. Al tocar un controlador,
    revisar **todas** las claves que pasa a su vista, no solo las que se editan.

- **`verif_merito_nomina_compuerta.php`** — Transacción + ROLLBACK. Prueba
  `PublicacionBoletaModel::ultimoPeriodoPublicadoPorNivel()`, la fuente del puesto que ve
  el docente en `/docente/nomina`: un bimestre **cerrado y NO publicado** no cuenta (debe
  devolver el anterior), la **publicación escalonada** da un bimestre distinto por nivel, y
  suspendida/despublicada dejan de contar.
  - Existe porque hasta el 10/08/2026 esa nómina resolvía el puesto con "último bimestre
    **cerrado**" y enseñaba el mérito que `/docente/orden-merito` ocultaba. El escenario 3
    reproduce la ventana real: primaria publicada y secundaria todavía no.

- **`verif_ranking_seccion_staff.php`** — **SOLO LECTURA**, apto para producción. Comprueba
  que `/director/ranking-seccion` (staff) muestra el universo completo: la suma de
  estudiantes de todas las secciones cuadra **exactamente** con las filas del snapshot
  oficial del periodo (B1 **528=528**, B2 **524=524**), no hay top-N escondido y los puestos
  por sección empiezan en 1 sin huecos.
  - Su comprobación característica es la **compuerta**: lista los grados que el claustro NO
    ve (niveles sin publicar) y confirma que el staff **sí** los ve. El 10/08/2026 eran los
    **11 grados de B2**, cuya publicación estaba programada al 13-14/08 — el motivo mismo
    de que la vista exista.
  - No prueba el cálculo (eso es `rankingPorSeccion`, ya cubierto): prueba que la vista
    nueva **no filtra de más ni de menos**.

- **`verif_flag_editable_timezone.php`** — Transacción + ROLLBACK. El flag `editable` de
  `AsistenciaModel::listarPeriodosActivos` y `ConductaModel::listarPeriodosActivos` debe
  coincidir con el guard **real** de escritura (`periodoEditable`, que resuelve el "ahora"
  en PHP) en las cuatro fronteras del `limite_notas`.
  - Su **paso 2 es la prueba dura**: fuerza la sesión MySQL a **UTC** —como corre
    producción— y comprueba que ahí la consulta **vieja** con `NOW()` contradice al guard
    real mientras la nueva no. Sin ese paso la verificación no probaría nada: en local el
    desfase es **0** y el bug no se manifiesta. Si el `NOW()` viejo **no** llega a diferir,
    el script lo declara **fallo de control**, no éxito.
  - Existe porque hasta el 10/08/2026 esas dos consultas calculaban el flag en SQL: en
    producción apagaban la UI **5 horas antes** de que el sistema dejara de aceptar
    escrituras.

- **`verif_roster_snapshot_traslado.php`** — Transacción + ROLLBACK. El roster del snapshot
  solo lo mueve un traslado/retiro, **nunca** una rectificación de notas. Comprueba las dos
  piezas del 11/08/2026: `registrarRanking(..., $exigirMismoRoster)` regenera cuando el
  roster está intacto y **aborta con `'roster_cambiado'`** cuando no (dejando el snapshot
  byte a byte igual), y `sincronizarRosterPorMatricula()` saca al trasladado renumerando sin
  huecos, informa del puesto que ocupaba y de cuántos compañeros se movieron, y **lo
  reintegra** al revertir devolviendo la firma exacta del snapshot original.
  - Su paso 5 es el que protege a B1: fuerza un traslado sobre un periodo **ya publicado** y
    confirma que el snapshot no se mueve (candado 046).
  - Existe porque `escribirOficial` borra y reinserta el periodo ENTERO: el 11/08/2026, al
    rectificar tres notas de Educación Física de **4.º de primaria**, desapareció del oficial
    de B2 una alumna de **1.º de secundaria** (trasladada 38 min después del cierre) y 42
    compañeros cambiaron de puesto, sin un solo aviso.
  - Se salta solo (exit 0) si no hay ningún bimestre cerrado, con snapshot y sin publicar:
    es el estado normal una vez publicado todo.

## Consultas operativas (phpMyAdmin)

- **`alerta_evaluacion_incompleta.sql`** — SOLO LECTURA. Replica
  `ControlOperativoModel::alertasEvaluacionIncompleta()` en SQL puro, para medir contra
  **producción** desde phpMyAdmin sin depender de que el Centro de control esté
  desplegado. Cuatro bloques autocontenidos (resumen por sección · detalle por criterio
  **con el docente responsable** · detalle por alumno · total). Validada contra el método
  PHP en los dos periodos con datos (B2: 19/19 · B1: 10/671).
  - Es uno de los **dos** prerrequisitos del cierre. El otro —empates del orden de
    mérito— **no es replicable en SQL**: su cascada (grupos por promedio, N desigual,
    tupla de 5 conteos, resolución manual por `grupo_clave`) vive en PHP. Se consulta en
    `/director/orden-merito/{periodo}`, que ya lista los bimestres `activo`.
  - **Ojo con la secuencia:** la alerta es estable (no mira `bloqueos_competencia`), así
    que medirla y resolverla vale antes o después del deploy. Los **empates NO**: P2 del
    rediseño 2 reduce el universo del cálculo en vivo a competencias BLOQUEADAS, así que
    los empates cambian con el deploy, y una resolución se ancla al conjunto exacto de
    matrículas (`grupo_clave`) — si el grupo cambia, deja de cubrirlo. Resolver empates
    va DESPUÉS del deploy y con todo bloqueado.

- **`verif_post_cierre_bimestre.sql`** — SOLO LECTURA, pensada para **producción** desde
  phpMyAdmin. Captura las cifras que deja el cierre de un bimestre (Fase 5 del runbook)
  más las dos comprobaciones que van después: la publicación y el `limite_notas` del
  bimestre siguiente. Ocho bloques autocontenidos, periodo anclado por **número + año
  activo** (nunca por id).
  - Su **bloque 0 es obligatorio**: la salida de los demás es idéntica en local y en prod
    (local es copia), así que sin la huella del servidor una captura no prueba en qué
    entorno se tomó. Es la lección de la migración 048, que ya se materializó una vez.
  - **El bloque 6 replica `fuePublicado()` entero**, y ahí está su valor: publicar **no**
    activa el candado 046 en el acto. `primera_publicacion_en` solo se sella cuando la
    publicación es **inmediata**; una **programada a futuro** deja el snapshot oficial
    todavía corregible hasta que llegue su `publica_en` (`candado_desde`).
  - 🔴 **Ningún criterio temporal usa `NOW()`, y es deliberado.** El MySQL de producción
    corre en **UTC**: medido el 10/08/2026, va **5 horas adelantado** respecto a Lima. La
    aplicación nunca compara contra `NOW()` para esto —`PublicacionBoletaModel::ahora()` y
    `CalificacionModel::periodoEstaBloqueado` resuelven el "ahora" en **PHP**, con
    `America/Lima`—, así que una consulta con `NOW()` daría por vencido un plazo **5 horas
    antes** que el código real. Los bloques usan
    `@ahora := UTC_TIMESTAMP() - INTERVAL 5 HOUR`, que da la hora de Lima en cualquier
    servidor (Perú no aplica horario de verano). El **bloque 0 mide el desfase** y lo
    devuelve en `desfase_horas`: 0 en local, 5 en prod.
  - Nació el 10/08/2026 porque B2 se cerró y publicó en prod **sin capturar allí** las
    cifras de su snapshot. Reutilizable en B3/B4 cambiando `@num` y los `@esp_*`.

- **`transversales_pendientes.sql`** — SOLO LECTURA. Lista a los **docentes que aún no
  aprobaron+bloquearon las transversales (TIC/GAMA)** de sus cargas: el bloqueador
  típico del cierre del tutor. Cuatro bloques autocontenidos (periodos y secciones de
  referencia · resumen por docente · detalle por carga+competencia · resumen por sección
  con el estado del cierre transversal), con `@periodo`, `@seccion_id` y `@seccion_txt`
  para acotar. Replica el universo de cargas de `CalificacionController::formulario()`
  —incluida la regla de carga **dueña** en secciones unidocentes— verificado 1 a 1
  contra `cargaDuenaTransversales()` (345 = 345 cargas, 0 diferencias) y contrastado en
  los dos periodos con datos (B1 cerrado: 0 pendientes en las 23 secciones; B2: las
  lista). Excluye por diseño la carga de Tutoría/Ética y la carga transversal del modelo
  viejo (migración 019), que no llevan TIC/GAMA.

Requisitos: haber aplicado la migración `046_orden_merito_inmutable.sql` en local y
tener B1 (periodo 1) con sus filas de `periodos_publicacion` (backfill de la 044).

**Antes de creer una prueba del mérito de B1, comprobar que el snapshot tiene sus 528
filas** (`SELECT COUNT(*) FROM orden_merito_snapshot WHERE periodo_id = 1`). Si está
vacío, reponerlo con `database/reconstruir_snapshot_b1.php`.
