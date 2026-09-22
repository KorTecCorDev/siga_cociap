# Transversales: bloqueos fantasma del cierre + visibilidad del tutor

> **Estado: LAS CUATRO FASES EN PRODUCCIÓN (deploy `cf8bdb2`, 06/08/2026), y la
> migración `051` de F2 APLICADA ALLÍ el mismo día, después del deploy.**
> No aplicada en local a propósito (allí siguen los 130, para reproducir el escenario).
> **Qué se construyó y con qué cifras: §5, que manda sobre las §1-§3.**
> Nace de una observación del usuario sobre
> `/admin/control` en el II Bimestre y de evaluar la propuesta de "bloquear las
> transversales antes que las académicas".
> **Sin migración de esquema; sí una migración de DATOS (`051`) en F2.**
> Contexto del módulo: `docs/modulos/calificaciones.md` §"Transversales por docente".
>
> **Decisiones cerradas del usuario (06/08/2026 — no re-preguntar):**
> **(1)** los 130 bloqueos fantasma de B2 **se borran**;
> **(2)** el tutor **solo mira** hasta tener el promedio final — nada de escribir
> conclusiones sobre un parcial, con guard en SERVIDOR;
> **(3)** el resumen **sí muestra el nombre de la carga y del docente**, derogando la
> regla de privacidad del 14/06/2026 (`73838d1`).

---

## 1. El hallazgo: `/admin/control` acusa a 23 docentes de algo que no hicieron

El panel muestra en B2:

> *"Competencias que el cierre bloqueó automáticamente porque el docente no las había
> bloqueado al aprobar el bimestre. 130 competencia(s) en 65 carga(s) de 23 docente(s);
> 130 sin ningún criterio registrado."*

**El mensaje es falso.** Las 130 se explican al 100% sin ningún olvido:

| Causa | Cargas | Docentes | Por qué el docente NO pudo bloquearlas |
|---|---|---|---|
| **A)** Carga TOE / área `tipo='tutoria'` | 23 | 23 | El formulario **no adjunta** transversales a la carga de tutoría — decisión del 07/07/2026, `CalificacionController:507` |
| **B)** Carga **no dueña** en sección unidocente | 42 | 6 | Las TIC/GAMA se adjuntan **una sola vez por área**, en la carga dueña — `CalificacionController:508-514` |
| **C)** Olvido real | **0** | **0** | — |

23 + 42 = 65 cargas × 2 competencias = **130**. Cuadra exacto.

### Causa raíz

`AnioAcademicoModel::bloquearCompetenciasPendientes` (bloque 2, línea 233) inserta las
transversales así:

```sql
FROM cargas_academicas ca
INNER JOIN secciones s ON s.id = ca.seccion_id
INNER JOIN grados    g ON g.id = s.grado_id
INNER JOIN areas     a ON a.tipo = 'transversal' AND a.nivel_id = g.nivel_id
INNER JOIN competencias comp ON comp.area_id = a.id
WHERE ca.estado = 'activa' AND ca.anio_id = (...)
```

**TODAS las cargas activas**, sin excepción. El formulario del docente aplica dos
exclusiones; el cierre forzado, ninguna. Los dos lados de la misma regla divergieron.

### Este defecto YA mordió una vez, y se parcheó del lado equivocado

El comentario de `TransversalModel::estadoCargasSeccion:322-332` documenta que tras un
cierre "las no-dueña sumaban transversales que el total (dueña) no cuenta, inflando el
numerador por encima del total (ej. **53/41**) y habilitando las conclusiones antes de
tiempo". Se arregló **el conteo**, no **el origen**: las filas fantasma se siguen creando.

### Impacto real (medido, para dimensionar sin alarmismo)

- ✅ **NO contamina el promedio agregado.** `getPromediosSeccion` une bloqueos con
  `calificaciones`; una carga fantasma no tiene notas transversales, así que no aporta
  filas. El dato de la boleta es correcto.
- ✅ **NO infla el gate del tutor**: `estadoCargasSeccion` ya usa la lógica de dueña.
- 🔴 **SÍ acusa injustamente a 23 docentes** en un panel que ve la dirección. Es el daño
  principal y es de confianza, no de datos.
- ⚠️ **Sospecha no medida:** al desbloquear una académica de una carga fantasma,
  `liberarTransversalesDeCarga` borra esos bloqueos y `BloqueoController::desbloquear`
  **anula el cierre transversal de la sección**. Podría explicar parte de las **48
  anulaciones sobre 71 cierres** que tiene B2. Verificar al implementar F1.
- **B1 es otra historia, pero no del todo** (corregido el 06/08/2026 al medirlo): de sus
  **774** transversales forzadas, **690 sí** son del modelo viejo (carga única del tutor)
  — coinciden exactamente con las 345 cargas legítimas × 2 — pero **84 son ESTE MISMO
  defecto** de carga no-dueña. B1 no tiene ningún forzado en carga TOE.
  **Se decidió NO tocarlo** (usuario, 06/08/2026): está cerrado y publicado, y su aviso de
  incidencias queda como registro histórico. La migración 051 se ancla solo a B2.

---

## 2. Lo que la evaluación de la propuesta dejó establecido

La propuesta original era *"que el docente bloquee las transversales antes que las
académicas para que el tutor no espere"*. Medido:

- **Ya es posible hoy**: `bloquear($cargaId, $competenciaId)` es por competencia y admite
  "propia o transversal", sin guard de orden. **64 cargas de B2 (16%) ya lo hacen.**
- **No destraba nada**, porque el gate del tutor cuenta académicas + transversales, y la
  vista `docente/tutoria.php:98` **oculta la tabla de promedios entera** si no está listo.
- **El acoplamiento es gratuito**: `getPromediosSeccion` filtra `a.tipo='transversal'`, o
  sea que las académicas **no participan del dato que el cierre congela**.
- **La ganancia es real pero modesta** ⚠️ **(cifra corregida el 06/08/2026 — la anterior,
  "en las 23 secciones la transversal llegó 40-144 h después", era falsa).** Medido sobre
  los bloqueos `origen='docente'` de B2: la transversal es la última en **13 de 23**
  secciones (ahí F4 no adelanta nada), y la académica en las otras **10**. De esas 10, solo
  **4 ganarían tiempo útil**: 47 h, 29 h, 11 h y 3 h; las otras 6 están por debajo de 2.5 h.
  O sea: F4 es correcta y sirve, pero beneficia a 4 secciones de 23.

Conclusión: lo que hay que arreglar es **la ceguera del tutor** (F2/F3) y **el ruido del
cierre forzado** (F1), no el orden de bloqueo, que ya es libre.

---

## 3. Fases

Orden: **F1 → F2 → F3 → F4**. Ninguna lleva migración de esquema. F1 es un bugfix con
efecto inmediato en la confianza del panel; F2 y F3 son el pedido del usuario; F4 es la
corrección conceptual del gate.

### F1 — El cierre forzado deja de inventar bloqueos transversales

**Archivo:** `app/Models/AnioAcademicoModel.php` (`bloquearCompetenciasPendientes`,
bloque 2).

Añadir al `WHERE` las **dos** exclusiones que el formulario ya aplica:

1. `AND (a2.tipo IS NULL OR a2.tipo <> 'tutoria')` sobre el área resuelta de la carga
   (`COALESCE(ca.area_id, sa.area_id)`).
2. En secciones `es_unidocente = 1`, solo la **carga dueña** del área: la subconsulta
   `ORDER BY COALESCE(sad.orden,0), cad.id LIMIT 1` que ya usan `estadoCargasSeccion` y
   `CalificacionModel::cargaDuenaTransversales`.

⚠️ **Esa regla de "dueña" quedaría escrita en un CUARTO sitio** (formulario, gate del
tutor, `cargaDuenaTransversales` y ahora el cierre). Al implementar, evaluar si se
unifica en `cargaDuenaTransversales` y el SQL la consume — o dejar constancia explícita
del cuarteto en los cuatro comentarios. **No dejar el cuarto sitio mudo.**

**Verificación:** re-simular el cierre de B2 en transacción con ROLLBACK y comprobar que
inserta **130 filas menos** y que el aviso de `/admin/control` baja a **0 competencias en
0 cargas**.

### F2 — Limpieza de los 130 fantasmas ya creados en B2

✅ **DECISIÓN CERRADA (06/08/2026): SE BORRAN.** F1 evita los futuros; esta fase borra los
existentes para que el panel deje de acusar a 23 docentes por B2.

**Migración de datos `051`** (no de esquema), con la estructura de la 047/050:

- **PASO 1 (solo lectura, de ABORTO):** la huella del servidor (`DATABASE()`, `USER()`,
  `@@version_compile_os`) + el recuento clasificado A/B/C. Debe dar **130 filas en A+B y
  CERO en C**: si aparece una sola en C, hay un olvido real de un docente y **borrarla
  sería destruir su bloqueo legítimo** → abortar.
- 🔴 **Guard indispensable:** ninguna de esas 65 cargas puede tener **calificaciones
  transversales** colgando. Si las tuviera, borrar el bloqueo dejaría notas sin bloqueo
  —el "estado fantasma" (bloqueo + notas + 0 criterios) que el proyecto ya persiguió— y
  además cambiaría el promedio agregado. Hoy las 130 están *sin ningún criterio
  registrado*, así que se espera 0; **se comprueba en el PASO 1, no se asume**.
- **PASO 2:** `DELETE` acotado por `periodo_id = 2` **AND** `origen = 'cierre'` **AND**
  área transversal **AND** (carga TOE **OR** no-dueña de unidocente). Envuelto en
  `START TRANSACTION … COMMIT`, ensayado antes con `ROLLBACK` **en la propia producción**
  (el procedimiento que funcionó con la 050).
- **PASO 3:** el aviso de `/admin/control` para B2 debe quedar en **0 competencias / 0
  cargas / 0 docentes**, y el cierre transversal de las 23 secciones **intacto**.
- ⚠️ **Numeración:** la `049` sigue **reservada** al registro retroactivo de notas, así
  que esta es la **`051`**. Al aplicarlas manda la dependencia, no el número (ya pasó con
  la 050 antes que la 049).
- ⚠️ **Orden respecto a F1:** si se borran los fantasmas **antes** de arreglar el forzado,
  el siguiente cierre los vuelve a crear. **F1 va primero, o al menos en el mismo
  despliegue.**

### F3 — El tutor ve los promedios parciales y un "Ver resumen"

**Archivos:** `Docente\TutoriaController::index` · `resources/views/docente/tutoria.php`
· `pages/_dashboard.scss` (o el parcial donde vivan las clases de tutoría).

1. **Dejar de ocultar la tabla.** Hoy `tutoria.php:98` la pinta solo con
   `$listo || $cerrado`. Pasa a pintarse **siempre**, con dos estados visuales:
   - **provisional** — badge "Parcial: N de M cargas aprobadas", fila por alumno con los
     promedios de lo que ya está bloqueado y **guion** donde aún no hay aporte;
   - **definitivo** — el de hoy, cuando `$listo`.
2. **"Ver resumen" de lo ya aprobado**: bloque que lista **qué cargas ya bloquearon sus
   transversales** y cuáles faltan, con el promedio que aporta cada una.
   ✅ **DECISIÓN CERRADA (06/08/2026): SE MUESTRAN EL NOMBRE DE LA CARGA Y EL DEL
   DOCENTE.** Esto **DEROGA** la regla escrita en `tutoria.php:55` —"NO se expone el
   detalle por carga ni el nombre de otros docentes (información sensible)"—, que nació el
   **14/06/2026** en el commit `73838d1`, dentro de un lote de protección de datos.
   - **Al implementar, borrar ese comentario y sustituirlo por la regla nueva con su
     fecha**, o el código quedará afirmando lo contrario de lo que hace.
   - `estadoCargasSeccion` **ya devuelve** `nombre_display` y `docente_nombre` por carga:
     no hace falta tocar el modelo, solo dejar de ocultarlos en la vista.
   - Alcance de lo que se expone: **área/carga, docente y si sus transversales están
     aprobadas**. Nada de notas de otras áreas ni datos personales (el mismo lote del
     14/06 protegía el DNI: **eso no se toca**).
3. **Conclusiones con promedio parcial:** ✅ **DECISIÓN CERRADA (06/08/2026): el tutor
   SOLO MIRA hasta tener el promedio final.** Con promedio parcial la tabla es de lectura;
   escribir conclusiones se habilita cuando todas las cargas aportaron. Razón: una
   conclusión escrita sobre un parcial puede quedar describiendo un promedio que después
   cambia (de B a A), y ese error lo paga la familia en la boleta.
   - 🔴 **El guard va EN SERVIDOR, no solo en la vista.** Hoy `guardarConclusion` solo
     exige que no haya cierre vigente (`TutoriaController:139`); **no** comprueba
     `$listo`. Sin ese guard, ocultar el textarea es cosmético: el endpoint sigue
     aceptando el POST.
   - El mensaje de estado debe decir **por qué** está en solo lectura ("faltan N cargas
     por aprobar sus transversales"), no solo que no se puede.

### F4 — El cierre transversal deja de depender de las académicas

**Archivo:** `TransversalModel::estadoCargasSeccion`.

Que `total_comp` y `comp_bloqueadas` cuenten **solo las competencias transversales** de
cada carga (manteniendo la lógica de dueña y las exclusiones actuales del `WHERE`). El
tutor podrá cerrar en cuanto todas las cargas hayan aprobado sus TIC/GAMA, sin esperar
las académicas — que no participan del dato.

**Consecuencias que hay que aceptar conscientemente:**

- El % de la barra "Avance de aprobación de la sección" cambia de significado. Hay que
  reetiquetarlo ("Cargas con transversales aprobadas"), o mentirá.
- **Cerrar antes alarga la ventana de la cascada:** desbloquear cualquier académica
  libera las transversales de esa carga y anula el cierre. B2 ya lleva **48 anulaciones
  sobre 71 cierres**; esto podría subir. Es reversible y con traza, pero es trabajo del
  tutor.
- Con F1 aplicado, el universo del gate se reduce a las cargas que realmente registran
  transversales, así que F4 y F1 se refuerzan.

---

## 4. Verificación

Script de solo lectura en `database/verificaciones/`:

1. **F1** — para B2, clasificar los bloqueos transversales `origen='cierre'` en A/B/C
   (TOE, no-dueña, resto). Tras el fix, una re-simulación debe dar **0 en A y B**.
2. **F1** — que el conjunto de cargas a las que el cierre adjuntaría transversales sea
   **idéntico** al conjunto al que el formulario se las adjunta. Es la equivalencia que
   hoy no se cumple: **misma regla, dos implementaciones**.
3. **F3** — el promedio parcial que ve el tutor coincide con
   `getPromediosSeccion` restringido a las cargas ya bloqueadas, y el definitivo (con
   todo bloqueado) coincide **alumno a alumno** con `getPromediosMatricula`, que es la
   fuente de la boleta.
4. **F4** — para las 23 secciones de B2, comparar el gate viejo y el nuevo: cuántas
   habrían podido cerrar antes y **cuánto antes**. Si la respuesta es "ninguna", F4 es
   correcta pero inútil, y conviene saberlo antes de invertir.

---

## 5. LO QUE SE CONSTRUYÓ — las 4 fases (06/08/2026). Manda sobre §1-§3.

**Estado: en `dev`, sin desplegar. La migración `051` NO se ha aplicado en ningún
entorno.** Se implementó en dos tandas el mismo día: primero F1+F2 (el defecto del cierre
forzado y su limpieza), después F3+F4 (la visibilidad del tutor y el desacople del gate).

### 🔴 La secuencia de despliegue, y por qué choca con el cierre de B2

```
F1 a producción  →  aplicar la 051  →  CERRAR B2
```

Los fantasmas los crea **el cierre forzado**. Si B2 se cierra antes de que F1 esté en
prod, nacen fantasmas nuevos; si la 051 se aplica con el código viejo todavía arriba, el
siguiente cierre los recrea. **F1 tiene que estar desplegado antes de tocar el cierre.**

✅ **Se respetó el orden:** deploy `cf8bdb2` primero, migración `051` después, ambos el
06/08/2026. Falta el paso 3 (cerrar B2), ya con el fix arriba.

🔎 **Una hipótesis que los hechos desmintieron, anotada para no repetirla.** Antes de
aplicarla se advirtió aquí que en producción podía **no haber nada que borrar**, razonando
que B2 seguía ABIERTO y que los fantasmas los crea el cierre forzado. **Falso: estaban los
130, exactamente los mismos que en local** (46 TOE + 84 no-dueña), así que en prod el
cierre forzado de B2 sí llegó a correr y el bimestre se reabrió después.
**Lección: el estado ACTUAL de un periodo no dice nada sobre los procesos que ya
corrieron sobre él; eso solo lo responden los datos.**

### F1 — `AnioAcademicoModel::bloquearCompetenciasPendientes` (bloque 2)

Se añadieron al `SELECT` las dos exclusiones que el formulario ya aplicaba: carga de
**tutoría** fuera, y en secciones **unidocentes** solo la **carga dueña** del área.

**Probado con la prueba dura**, no por inspección: en transacción con `ROLLBACK` se
vaciaron los 820 bloqueos transversales de B2 y se recrearon con el SQL nuevo →
**insertó 690 en lugar de 820, exactamente 130 menos**, todas en la clase legítima
(0 en TOE y 0 en no-dueña), y local quedó en 820 tras el rollback.

**La regla de "carga dueña" queda escrita en CUATRO sitios** (decisión del usuario:
cuarto sitio documentado, **no** helper compartido — el gate del tutor es delicado y no
se toca código que hoy funciona). Los cuatro llevan ahora un comentario cruzado que los
nombra a todos:

1. `CalificacionController::calificaciones` — formulario del docente
2. `TransversalModel::estadoCargasSeccion` — gate del cierre del tutor
3. `CalificacionModel::cargaDuenaTransversales` — versión PHP para una carga
4. `AnioAcademicoModel::bloquearCompetenciasPendientes` — cierre forzado (el que faltaba)

### F2 — migración `051_limpieza_bloqueos_transversales_fantasma.sql`

Estructura de la `050`: **PASO 1.0** huella del servidor · **PASO 1** clasificación A/B/C
con veredicto de aborto + guard de notas/criterios · **PASO 2** `DELETE` en transacción ·
**PASO 3** verificación en conexión nueva · **PASO 4** deshacer.

**Aborta** si aparece una sola fila en `C_OLVIDO_REAL` (sería un docente que de verdad no
bloqueó) o si alguna de esas cargas tiene notas o criterios colgando. El periodo se ancla
por `numero = 2` + año activo, **nunca por `id`**.

**Ensayada entera en local con `ROLLBACK`**, con la verificación *dentro* de la
transacción: PASO 1 verde (46 TOE + 84 no-dueña, **0 olvidos reales**, 0 notas y 0
criterios colgando) → **borró 130** → dentro de la transacción el aviso quedó en **0
competencias / 0 cargas**, con los **690** bloqueos de docente y los **23** cierres
vigentes intactos → tras el `ROLLBACK`, local volvió a 130 y B1 siguió en 774.

### F3 — El tutor ve los promedios provisionales y quién falta

**Archivos:** `TutoriaController` (`index` sin cambios, `guardarConclusion` con guard) ·
`resources/views/docente/tutoria.php` · `resources/views/docente/inicio.php` ·
`pages/_dashboard.scss` (`.tutoria-cargas`, nuevo).

1. **La tabla se pinta siempre.** Antes exigía `$listo || $cerrado`. Ahora hay una sola
   variable, `$editable = !$cerrado && $listo`, que gobierna textareas y botones; la tabla
   queda fuera de esa condición y en estado provisional lleva el badge `Provisional`
   (reusa `.carga-transversal--progreso`).
2. **Resumen de cargas con nombre de docente**, solo mientras el bimestre está parcial.
   `estadoCargasSeccion` ya devolvía `nombre_display` y `docente_nombre`: no hizo falta
   tocar el modelo, solo dejar de ocultarlos. **Se listan únicamente las cargas que
   APORTAN** (`total_comp > 0`): las de tutoría y las no-dueñas valen 0 a propósito, y
   listarlas haría creer al tutor que espera por alguien que nunca va a aportar.
   ✅ El comentario de privacidad del 14/06/2026 **fue reescrito, no borrado**: ahora
   declara qué se expone (área, docente, estado) y qué no (notas ajenas, DNI).
3. **El guard de escritura está en el SERVIDOR** (`guardarConclusion`), como exigía el
   plan: `guardarConclusion` no comprobaba `$listo`, así que ocultar el textarea habría
   sido cosmético. Devuelve 403 con el número de competencias que faltan.

⚠️ **Dato medido que matiza la decisión (no la cambia):** al liberar cargas en
transacción sobre B2, el promedio provisional **sí se mueve** (34 de 48 celdas con 12 de
15 cargas sin aprobar), pero **el LITERAL no llegó a cambiar** mientras quedara alguna
carga aportando — ni en secundaria ni en primaria, y las conclusiones obligatorias se
mantuvieron en 0. O sea: el riesgo que motiva el bloqueo es real en el promedio, pero en
los datos de B2 no se materializó en el literal, que es lo que decide la obligatoriedad.
La decisión de dejarlo en solo lectura se mantiene por ser la defensiva.

### F4 — El cierre transversal deja de depender de las académicas

**Archivo:** `TransversalModel::estadoCargasSeccion` — `total_comp` y `comp_bloqueadas`
cuentan ahora **solo transversales**, manteniendo intacta la lógica de dueña. Numerador y
denominador se movieron juntos, así que el gate sigue cuadrando.

**Los otros dos consumidores del método se revisaron uno a uno** (no solo el panel del
tutor): `BloqueoController` (cierre transversal manual) y `PanelController` (card del
dashboard docente). Los dos preguntan exactamente "¿se puede cerrar el bimestre
transversal?", así que F4 los mejora en vez de romperlos — pero **sus textos pasaban a
mentir** y se ajustaron: «faltan cargas por bloquear» → «faltan competencias
transversales por bloquear», y «Bloqueadas X de Y» → «Transversales bloqueadas X de Y».
Un `X de Y` a secas se leía como el total de la sección y ya no lo es.

**Medido en las 23 secciones:** todas dan LISTO con el gate nuevo (en local B2 está
cerrado y todo bloqueado), y en las unidocentes de primaria solo **8 de 15** cargas
aportan — la lógica de dueña sigue aplicándose.

### Verificación de F3 y F4

El estado provisional **no existe de forma natural en local** (B2 está cerrado y todo
bloqueado), así que se construyó en transacción con `ROLLBACK`: liberar las transversales
de una carga deja la sección en `30/28`, `$listo` pasa a false, el guard del servidor
**rechaza el POST** indicando que faltan 2, la tabla provisional conserva sus 24 alumnos
con promedio y el resumen muestra `14 de 15 cargas aprobadas`. Tras el rollback, `30/30`.

### Verificación de F1 y F2

`database/verificaciones/verif_transversales_fantasma.php` — solo lectura, corre en prod,
acepta el número de bimestre como argumento. Cuatro bloques: clasificación A/B/C · guard
de notas/criterios · **equivalencia de universos** · registros que no deben moverse.

El bloque de equivalencia es el que impide que el defecto vuelva: compara el universo del
**cierre forzado** con el del **formulario**. Hoy da **345 = 345**; antes del fix eran
410 contra 345. Si alguien reescribe una de las dos ramas, ese bloque lo delata.

⚠️ Mientras la `051` no se aplique, el script **falla a propósito** en el bloque 1 (los
130 siguen ahí). Es el comportamiento correcto: verifica el estado final.

---

## 6. Fuera de alcance

- **B1 no se toca**: sus 774 transversales forzadas son del modelo viejo (carga única del
  tutor), no un defecto de esta regla.
- **No se añade ningún guard de orden de bloqueo**: el orden ya es libre y los datos
  muestran que 64 cargas lo aprovechan.
- **No se toca `/admin/control`** más allá de que su aviso deje de dispararse: el texto
  es correcto para el caso que sí describe (un docente que de verdad no bloqueó).

---

## 7. Nomenclatura: las DOS caras de las transversales (25/08/2026)

Las transversales existen en el sistema como **dos objetos distintos**, y hasta el
25/08/2026 los dos se llamaban **«Competencias Transversales»** en tres pantallas.

| Objeto | Quién lo produce | Dónde se ve | Rótulo |
|---|---|---|---|
| **Registro por carga** (insumo) | cada **docente**, en su carga | separador dentro de `consulta-notas/carga.php`; contador de la tarjeta en `seccion.php` | **Competencias Transversales — Registro del docente** |
| **Promedio de la sección** (resultado) | el **tutor**, al cerrar | `consulta-notas/transversales.php` y su enlace en `seccion.php` | **Promedios de Competencias Transversales** |

- 🔴 **El rótulo va en el TÍTULO, no solo en el subtítulo.** La distinción ya estaba
  escrita —y bien— en las líneas secundarias de las tres pantallas, pero el título
  era idéntico. Diferenciar con la letra chica funciona cuando tienes las dos cosas
  delante; no funciona cuando las ves en momentos distintos, que es el caso real del
  director.
- **El término no se inventó**: `docente/tutoria.php` ya rotulaba el bloque del tutor
  como «Promedios C. Transversales». La consulta ahora habla como la pantalla donde
  nace el dato. *(La forma abreviada se conserva allí: va en la cabecera de una tabla
  estrecha, y es una restricción de espacio, no una decisión de nomenclatura.)*
- ⚠️ **El enlace y su destino deben decir lo MISMO.** Renombrar solo el enlace de
  `seccion.php` habría dejado al director aterrizando en una página titulada distinto
  — empeorando el wayfinding en vez de arreglarlo.
- **`seccion.php` lleva dos encabezados de grupo** (`dash-grupo__titulo`, el patrón de
  `admin/cuadros`): **«Registros de la sección»** y **«Áreas y cargas»**. Esa frontera
  —lo que es de la sección vs. lo que es por carga— es exactamente la que separa las
  dos caras, así que ubicar las listas ya desambigua antes de leer ningún rótulo.
- **Verificado en `verif_consulta_notas_ampliada.php` (bloque F5)**, que comprueba la
  **propiedad** —los dos títulos son distintos y el enlace coincide con su destino—
  y **no** el texto literal: fijar la cadena convertiría cualquier reescritura legítima
  del rótulo en un fallo. Se probó su rama roja (con los dos títulos iguales, sale
  `RESULTADO: 1 FALLO(S)` y código de salida 1) y se revirtió.
- ⚠️ **El área en BD sigue llamándose «Competencias Transversales»** (y «Comp. Transv.»
  en secundaria): son nombres de DATO, no rótulos de UI. `verif_universo_merito.php` y
  `boleta/alumno.php` dependen de ellos — no renombrarlos por coherencia visual.


> ⚠️ **Desde el 10/09/2026 hay UNA vía que escribe una transversal fuera del flujo del
> tutor:** el lote de calificación extraordinaria para bimestres CERRADOS. Solo entra si
> el cierre de la sección está VIGENTE, y usa como carga la que ya evalúa esa transversal
> en la sección. **Su conclusión va a `conclusiones_transversales`**, igual que la del
> tutor. Detalle y candados en `calificaciones.md`, sección «Captura EN LOTE».