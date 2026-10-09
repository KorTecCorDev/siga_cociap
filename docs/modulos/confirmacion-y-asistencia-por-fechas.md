# Plan — Conducta y asistencia con autoguardado + «Confirmar», y asistencia POR FECHAS

> **ESTADO: DESPLEGADO en v1.0.5 (30/09/2026)**, con las migraciones `068`, `069` y `070`
> aplicadas a mano en producción antes del push. Las rondas de abajo son el historial.

> **OCTAVA RONDA (09/10/2026, decisiones del usuario; sin migración) — no se bloquea antes
> del fin del bimestre, y el índice muestra las secciones bloqueadas.** En `dev`.
> 1. **Hueco:** `diasSinTomar` corta en `min(fecha_fin, hoy)`, así que los días FUTUROS no
>    contaban como «sin tomar». Con todo lo pasado al día, el auxiliar/RA podía bloquear a
>    mitad del bimestre. Después, `dia`, `jornada` y `confirmar` se niegan y el resto del bimestre ya no
>    se podía registrar. Pasó en local: 1.º A/B y 2.º A/B del III se bloquearon el 07/10 y les
>    faltan las listas del 08 y 09/10.
> 2. **Regla:** en un bimestre por fechas, `bloquearRA` (no forzado) se niega mientras
>    `hoy < fecha_fin` («podrás bloquear desde el DD/MM, su último día»). Punto único
>    `AsistenciaModel::bimestreTerminado()` (fecha de PHP, no `NOW()`). El panel de la sección
>    deshabilita el botón y lo dice. **El director (forzado) sigue pudiendo** bloquear antes.
> 3. ⚠️ **`limite_notas` debe quedar DESPUÉS del último día:** si se apaga antes que
>    `fecha_fin` termine (el I Bimestre tuvo `limite_notas = fecha_fin 00:00`), el auxiliar/RA
>    ya no puede bloquear y solo queda el forzado del director.
> 4. **Índice `/admin/asistencia`:** la card de una sección con cierre vigente en el bimestre
>    en curso lleva «🔒 Bloqueada» y no pinta la barra, igual que conducta
>    (`AsistenciaModel::seccionesBloqueadas`, `.asistencia-estado-badge`).

> **SÉPTIMA RONDA (08/10/2026, regla del colegio; migración 076) — una falta solo es
> justificada con el MOTIVO PRINCIPAL.** **DESPLEGADO el 08/10/2026** (merge `3d58934`, sigue
> v1.0.5), con la `076` aplicada a mano en producción ANTES del merge: 1 ancla vigente,
> 0 contadores incoherentes, 41 filas del III recontadas (34 confirmadas, ninguna en sección
> bloqueada) y el III sin publicar.
> 1. **Regla:** una FJ cuenta en `faltas_justificadas` solo si su motivo es el **ANCLA** (motivo
>    principal) del bimestre. Al principio es «Justificación escrita autorizada». Con cualquier
>    otro motivo (p. ej. la verbal) cuenta en **`faltas`**. Las **tardanzas no cambian**: TJ suma
>    todos los motivos.
> 2. **La marca del día NO cambia:** la celda sigue diciendo FJ con su motivo, y el anexo «Detalle
>    de incidencias» la lista como FJ. Solo cambia en qué contador cae.
>    - **Icono de documento SOLO con el ancla** (decisión del usuario, 08/10/2026). Lo pintan
>      `_af-celda.php` y `pintarCelda()`; el JS lo lee del `<option data-principal>` del menú.
>    - Las demás justificaciones no llevan icono, pero su motivo sale al pasar el cursor.
> 3. **Alcance: todo el sistema** (decisión del usuario). Se cambió el punto único
>    `recalcularContadores`, cuya regla vive en `AsistenciaModel::sqlConteoContadores()`. Boleta,
>    imprimible, Dirección, SIAGIE y cuadros de contadores leen los mismos números. No toca I y II
>    (solo números) ni la vía extraordinaria.
> 4. **Datos ya guardados:** la 076 los **recuenta sin desconfirmar** (decisión del usuario). Las
>    marcas no cambian, así que la confirmación sigue valiendo. En local: 39 filas, 85 FJ verbales
>    pasan a F, 33 filas confirmadas, ninguna en sección bloqueada.
> 5. **Estadísticas de justificaciones de Cuadros: ALINEADAS** (08/10/2026). El usuario derogó
>    su «no tocar» del mismo día al pedir el icono también ahí.
>    - **F/FJ con la regla de los contadores:** una FJ fuera del ancla cuenta como F, en
>      `AsistenciaEstadisticaModel::tipoQueCuenta`. Afecta a «% F justificadas», las cifras por
>      estudiante y por sección y la tabla por nivel.
>    - **Lo que mide el MOTIVO sigue contando cada MARCA:** usos por motivo, verbales y su
>      alerta. Su base es el contador `justificaciones` (FJ + TJ de cualquier motivo), no FJ + TJ,
>      que ya no incluye las FJ verbales y dejaría el % de verbales por encima de 100.
>    - Tendencias y días críticos no cambian: F y FJ son ausencias en los dos casos.
>    - En local (III): F 212 · FJ 60, idénticos a los contadores oficiales confirmados.
>    - ⚠️ `tipoQueCuenta` es la versión PHP de `sqlConteoContadores`: al tocar una, revisar la
>      otra. Lo comprueba `verif_asistencia_estadisticas.php`.
> 6. **Leyenda** en la grilla y en la vista por estudiante: «En el total F también se cuentan las
>    FJ cuyo motivo no es "…"». El nombre es el del ancla del bimestre.
>    - **El ENCABEZADO de la columna FJ ES la pastilla de una celda FJ con el motivo
>      principal** (08/10/2026, aclaración del usuario: el papel es PARTE de la pastilla,
>      no un icono aparte). Contorno de FJ y documento en la esquina superior derecha.
>      - Punto único: el parcial `admin/asistencia/_pastilla-fj.php` (`af-tipo--fj
>        af-tipo--con-motivo`).
>      - El dibujo de la esquina es el mixin `af-esquina-motivo` de `_asistencia.scss`, que
>        comparten la celda y la pastilla: no pueden divergir.
>      - Tooltip: «Faltas justificadas: solo cuenta las de "<ancla>"».
>      - Solo en bimestres por fechas. Sin ancla, cada vista conserva su rótulo de siempre
>        (texto «FJ», «Faltas justif.», «F. just.»).
>      - Donde el rótulo era la sigla, la pastilla lo reemplaza. Donde eran palabras (tarjeta
>        y tabla por nivel de Cuadros), la pastilla va delante del texto.
>    - **Dónde va:**
>      - grilla y vista por estudiante;
>      - tabla compartida `_tabla-incidencias` (consulta de Dirección; con `$nombreAnclaFj`);
>      - imprimible del registro, con la nota al pie que lo explica en papel;
>      - cuadros: «F. just.» del top de incidencias, y la tarjeta, la tabla por nivel y la de
>        estudiantes de Justificaciones.
>    - **NO va en:**
>      - las boletas (decisión del usuario: mezclan I/II, que no siguen la regla);
>      - el lote extraordinario (números a mano);
>      - los bimestres de solo números;
>      - los gráficos.
> 7. **ANCLA ELEGIBLE, ÚNICA Y NUNCA AUSENTE** (decisiones del usuario, 08/10/2026):
>    - **Datos:** `asistencia_motivos.es_principal` (1 o NULL) con UNIQUE `uq_es_principal`
>      (nunca dos) y CHECK `chk_principal_vigente` (solo vale 1 y nunca en un retirado).
>    - **Nunca cero:** no hay acción que quite el ancla. `hacerPrincipal()` la TRASLADA en una
>      transacción, y `retirar()` se niega con el ancla.
>    - **Quién:** admin y RA, en Motivos, con el botón «Hacer principal» y confirmación.
>      Dirección ve la insignia «Principal» en solo lectura.
>    - **Alcance del cambio: solo los bimestres en curso.** Cada bimestre **CONGELA su ancla al
>      CERRARSE** (`periodos.asistencia_motivo_principal_id`, `congelarAnclaPeriodo` en
>      `PeriodoController::cerrar`).
>      - Es inmutable como el tutor de la 071: un re-cierre tras reabrir conserva la primera.
>      - Un bimestre reabierto sigue contando con su ancla congelada.
>      - PUNTO ÚNICO: `anclaDelPeriodo()`.
>    - **Con secciones bloqueadas** (cierre vigente) en un bimestre sin congelar, el traslado
>      **se niega** y las lista: hay que reabrir su asistencia primero.
>    - **Al trasladar se recuentan** los bimestres sin congelar **sin desconfirmar**
>      (`AsistenciaModel::recontarPorCambioDeAncla`, con la misma regla de conteo).
>    - **Caso límite aceptado:** si se retira un motivo que es el ancla congelada de un bimestre
>      y ese bimestre se reabre, el motivo ya no se ofrece. Sus FJ nuevas contarían como F.
> 8. **Verificador** `verif_asistencia_fechas.php`:
>    - casos f2 (regla de conteo) y 2c (ancla): única, no retirable, no se traslada con
>      secciones bloqueadas, recuenta sin desconfirmar, bimestre congelado intacto, re-congelar
>      no cambia nada;
>    - coherencia global, bimestre por bimestre, con su ancla;
>    - sin la 076, se detiene y lo dice.
>    - Probado sobre una COPIA de la BD local con la 076: TODO OK (81). La batería queda con los
>      mismos 5 fallos que ya existían antes de este trabajo.

> **SEXTA RONDA (30/09/2026, decisiones del usuario, CERRADAS) — el estilo establecido manda:**
> las grillas del III Bimestre se ven como los registros del I y II (solo lectura de
> `/admin/conducta/{id}` y tabla de números de asistencia) y como la grilla del tutor.
> **Deroga** el punto 2 de la segunda ronda (en las grillas), el punto 3 de la tercera y el
> punto 5 de la cuarta.
> 1. **Columnas N° con el estilo del sistema** (`.tabla-notas`): sin blanco forzado, sin
>    negrita, sin separador grueso. La franja de estado se conserva, en **3 px**
>    (`--franja`), como la barra original.
> 2. **Sin N° repetido al final** en las dos grillas. Los **imprimibles lo conservan**: la
>    observación fue solo de las grillas en pantalla.
> 3. **Leyenda de criterios = la del tutor** (`.conducta-criterios-leyenda` +
>    `ol.conducta-criterios-lista` con chip de código). Se borró la variante en rejilla.
> 4. **Cabecera de cada criterio con el chip** `competencia-card__codigo--solo`, como el tutor.
> 5. **Nota y Literal en columnas SEPARADAS** (`col-resultado`), con los badges
>    `nota-numeral` / `nota-literal`, también en el III editable: `conducta.js` los pinta
>    en `[data-formato="numeral|literal"]` (solo la grilla; la vista por estudiante sigue
>    con su texto «15 (A)»). 🔴 Esos spans NO llevan `.cc-nota`: su gris se carga después
>    de `.nota-numeral--*` con la misma especificidad y dejaba el número y el contorno grises. En la vía extraordinaria por literal directo,
>    Nota queda con guion y el literal va en su columna.
> 6. **Se conservan**: franja sin fondo de fila, paleta F/FJ/T/TJ y mes en la esquina.
> 7. **Nota EN VIVO en la grilla**: se actualiza con cada marca, Sí ÷ N × 20 contando lo no
>    respondido como No (si se deja así, ya es la nota final: nunca muestra algo que luego
>    baje), con el mismo badge y literal que la definitiva. Sin ninguna marca, guion. Antes
>    salía solo con los N criterios respondidos. La vista por estudiante aplica la MISMA
>    regla en vivo, con su formato propio «15 (A)».
>    Es solo la pantalla: confirmar y bloquear siguen exigiendo los N criterios.
> 8. **«Tabla», no «Grilla», en los textos de pantalla** de las vistas por estudiante
>    (conducta y asistencia): «← Tabla», «Confirmar/Guardar y volver a la tabla». No «Lista»:
>    en asistencia ya significa la lista del día («Pasar lista de hoy», «Sin tomar lista»).

> **SEGUNDA RONDA DE AJUSTES (29/09/2026, decisiones del usuario, CERRADAS):**
> 1. Grilla de asistencia: encabezado FIJO (sticky) con el nombre del MES en la esquina
>    fija, como `.conducta-scroll`.
> 2. ⚠️ *En las grillas, derogado en la sexta ronda; los imprimibles lo conservan.* **N° repetido al final de la fila** en las dos grillas del auxiliar (asistencia por
>    fechas y conducta) y en sus DOS imprimibles.
> 3. Icono de documento en la celda FJ/TJ que ya tiene motivo (grilla y vista por
>    estudiante).
> 4. Fechas justificadas: **anexo «Detalle de justificaciones»** (N° · Estudiante · Fecha
>    · Tipo · Motivo). En el imprimible, en **hoja aparte**; en Dirección, debajo de la
>    tabla. La columna «Fechas» deja de repetir el nombre del motivo.
> 5. El tutor ve la conducta solo con la sección bloqueada y aprobada (ya es así).
> 6. **Estadísticas de justificaciones** como bloque nuevo de `/admin/cuadros`, desde el
>    III Bimestre, SOLO lo confirmado, lo ven Dirección, admin y RA (no el tutor).
>    Indicadores: tasa de asistencia, % F justificadas, % T justificadas, % verbales,
>    registro al día (tarjetas); usos por motivo, secciones por estudiante (apiladas),
>    tendencia semanal, patrón por día de la semana (gráficos); estudiantes que más se
>    ausentan con justificadas incluidas (top 3 + empates por sección, con motivo más
>    frecuente); estudiantes con **5 o más** justificaciones y más de la mitad verbales;
>    días críticos del colegio; Primaria vs Secundaria; **oportunidad del registro**
>    (demora entre fecha y registro, por sección; el usuario la incluye); versión A4.
>    Los LÍMITES (feriados contados como hábiles, historial desde el III, sin umbrales,
>    solo el último motivo) **NO se muestran en pantalla** por decisión del usuario:
>    quedan documentados aquí.
> 7. **Reapertura ESCALONADA** (deshacer solo la última etapa: tutor → auxiliar) de
>    conducta **y de transversales**: DIFERIDA, no se hace ahora (ver `docs/ESTADO.md`).
>
> **Avance de la segunda ronda (29/09/2026, sin commit):** puntos 1 a 4 HECHOS y probados en
> Chrome con sesión de admin (encabezado fijo con el mes, N° final en las dos grillas y en
> los dos imprimibles, icono `doc-add.svg` en FJ/TJ con motivo y su nombre al pasar el
> cursor, anexo en hoja aparte con `break-before: page` y en Dirección debajo de la tabla;
> `AsistenciaModel::justificaciones` y parcial `_anexo-justificaciones.php` compartidos).
> Nota: `/consulta-notas/.../asistencia` solo abre secciones con competencias bloqueadas en
> el bimestre (regla previa); en local, del III solo la 13.
>
> **Punto 6 HECHO (29/09/2026, sin commit)**, probado en Chrome con admin (pantalla y A4):
> - `AsistenciaEstadisticaModel::justificaciones($periodoId)` devuelve null en bimestres sin
>   fechas y el bloque no se pinta. Tiene **una sola base**: el roster con su confirmación y
>   las incidencias confirmadas no extraordinarias. Todo se agrega en PHP sobre esas filas,
>   así ninguna cifra se cuenta sobre un universo distinto.
> - Reusa `AsistenciaModel::topConEmpates` (extraído de `getTopIncidenciasPorSeccion`, con
>   un A/B idéntico a `HEAD`) y `mas_de_la_mitad()`.
> - Clave `justificaciones` en `CuadrosEstadisticosController::componerBloques`. Gráficos J1
>   a J5 en `_chart-data.php`, sección 6 con 4 pestañas en `index.php` y chip en el índice.
> - Sección en `imprimir.php` y parciales `_just-kpis`, `_just-criticos` y
>   `_just-estudiantes`.
> - Defecto hallado y corregido: Frappe escribe en el eje Y el error de coma flotante
>   («0.30000000000000004») cuando los valores son menores que 1. `cuadros.js` redondea
>   esas etiquetas tras dibujar, y otra vez en cada redibujado (MutationObserver). Aplica a
>   todos los gráficos del tablero.
> - `verif_asistencia_estadisticas.php` (null frente a datos, borrador frente a confirmado,
>   alerta con la mitad justa y con menos de 5, empates, motivo frecuente). Batería 54/54.

> **TERCERA RONDA (29/09/2026, decisiones del usuario, HECHA y probada en Chrome con RA):**
> 1. **Imprimible de asistencia sin columna «Fechas».** El anexo pasa a **«Detalle de
>    incidencias»** con TODAS las fechas: F, FJ, T y TJ; el motivo solo en FJ/TJ, guion en
>    F/T (`AsistenciaModel::detalleIncidencias`; `justificaciones()` delega en él y la
>    consulta de Dirección no cambia). Al pie de la hoja principal, un recuadro avisa que se
>    adjunta el detalle. Con anexo, **las firmas van al final del anexo**; sin anexo, al pie
>    de la tabla (parcial `_firmas-registro.php`).
> 2. **Sin columna «Estado»** en las grillas de conducta y de asistencia por fechas. La
>    grilla conserva solo «Confirmar todo»; a un estudiante suelto se le confirma en
>    «Registrar por estudiante». El estado lo dice una **franja en el N° inicial** (verde
>    confirmado, ámbar sin confirmar, rojo si falló un guardado), explicada en la leyenda.
>    La vista por estudiante conserva su estado y su botón; el JS los trata como opcionales.
> 3. ⚠️ *Derogado en la sexta ronda.* **Las dos columnas N°** (inicial y final) van en blanco, con el número en negrita y un
>    separador grueso oscuro. El N° final queda pegado a los totales (o a la Nota en
>    conducta). El N° inicial mide exactamente 40 px: con el relleno medía 47 y el nombre
>    sticky le tapaba el separador. La franja y el separador comparten `box-shadow`
>    mediante `--franja`, para que ninguna regla de estado borre el separador.

> **CUARTA RONDA (30/09/2026, decisiones del usuario, HECHA y probada en Chrome con admin):**
>
> **Regla del usuario:** el estilo UI/UX ya establecido no se cambia. La grilla del III debe
> verse como la de los bimestres anteriores.
>
> 1. **Sin fondo de estado en la fila** en las dos grillas.
>    - Deroga, del 29/09, el ámbar de fila entera de conducta y el tinte verde de la grilla por
>      fechas.
>    - El estado lo dice **solo la franja del N°**.
>    - Se restituye el **resaltado del sistema al pasar el cursor, en TODAS las columnas**, N°
>      inicial, nombre y N° final incluidos. Esto deroga el «sin resaltado» del 29/09 en conducta.
>    - La tabla de números de I/II y de Dirección (`_tabla-incidencias.php`) conserva su verde de
>      «registrada»: es su estilo establecido.
> 2. **Franja de asistencia:** se conserva su distinción.
>    - Sin franja si el estudiante no tiene registro.
>    - Ámbar si es borrador, verde si está confirmado, rojo si falló un guardado.
>    - Conducta sigue en ámbar para todo lo no confirmado.
> 3. **Paleta de incidencias** (convención global en `docs/modulos/ui.md`).
>    - Familias: rojo = faltas, naranja = tardanzas.
>    - Injustificada **rellena**, justificada **en contorno**.
>    - Sale en los encabezados de los totales, en la leyenda de la grilla y en los totales y la
>      leyenda de la vista por estudiante.
> 4. **Cabecera del estudiante FIJA** en las dos vistas «por estudiante», bajo la barra
>    superior, para registrar desde el celular.
> 5. ⚠️ *Derogado en la sexta ronda.* **Bloque «Criterios» de la grilla admin:** sigue plegable, ahora en rejilla con el chip de
>    código (`.conducta-criterios-leyenda--rejilla` + `.criterios-codigos--rejilla`). La vista del
>    tutor conserva el bloque base.
> 6. **PENDIENTE DE DEBATE: la marca «asistió normalmente»** en cada día.
>    - El sistema no guarda un «pasé lista» por día: un día sin incidencia puede ser «asistió» o
>      «nadie registró».
>    - Opciones:
>      - a) solo con fila registrada;
>      - b) todo día transcurrido;
>      - c) solo confirmado;
>      - d) registrar el día tomado por sección y fecha, con una migración nueva. Es la única que
>        hace la marca verdadera y no deducida.
>    - No se implementó nada.

> **QUINTA RONDA (30/09/2026, decisiones del usuario; migración 070):** lista del día,
> menú por celda y justificación siempre con motivo. Resuelve el punto 6 de la cuarta
> ronda.
>
> 1. **✓ asistió = lista del día.** Cada sección y bimestre tiene una lista por fecha
>    (`asistencia_jornadas`).
>    - Sin lista, el día está **«Sin tomar»** (⚠ en el encabezado, celda vacía).
>    - Con lista, todo estudiante sin incidencia sale con **✓**, en gris pizarra: nunca
>      verde, que es el color de AD y de «confirmado».
>    - La lista se toma con el botón del encabezado del día, con «Pasar lista de hoy» o con
>      **cualquier marca** (`marcarDia` la toma en su transacción).
>    - Se **deshace** solo si ningún estudiante de la sección tiene incidencia ese día.
>    - UNIQUE `(sección, periodo, fecha)`: **los bimestres se solapan**.
> 2. **Menú por celda** (`_af-menu.php`) en lugar del ciclo de toques.
>    - Opciones: ✓ Asistió · F · T guardan y cierran; FJ · TJ piden el motivo y «Guardar»
>      solo se habilita con uno.
>    - **Cancelar, tocar fuera o Esc no cambian nada.**
>    - El ciclo viejo no admitía la regla «cancelar = volver a F»: F → FJ → cancelar → F →
>      FJ… nunca llegaba a T.
> 3. **FJ/TJ SIEMPRE con motivo:** el modelo las rechaza sin él y hay un CHECK
>    `chk_justificada_con_motivo`.
>    - Los dos CHECK están en producción (comprobado con `SHOW CREATE TABLE`, 05/10/2026).
>      La copia local exportada de producción NO los trae: no sirve para auditarlos.
>    - La 070 convirtió las existentes sin motivo a F/T (1 en local), las recontó y las
>      desconfirmó.
>    - Se retiraron la marca «!», el borde discontinuo, «Falta motivo (n)» y
>      `data-sin-motivo-otros`. La comprobación de `confirmar()` queda solo como defensa.
> 4. **Relleno:** listas con `origen = 'migracion'` en todo lunes a viernes ya transcurrido
>    (hasta AYER) de cada bimestre por fechas. Es lo que se suponía antes. En local: 851
>    (23 secciones × 37 días).
> 5. **Bloqueo:** el auxiliar/RA exige además **0 días sin tomar**; el mensaje lista las
>    fechas. El director (forzado) no lo exige. El panel del auxiliar muestra «Días sin
>    tomar: N» en lugar de «Listo para bloquear».
> 6. **Días no lectivos** (`asistencia_dias_no_lectivos`, `/admin/asistencia/no-lectivos`):
>    - son de **todo el colegio**; admin y RA editan, Dirección los ve (enlace desde
>      Motivos);
>    - un día no lectivo no se marca, no se exige al bloquear y sale como «NL»;
>    - solo se declara un día **sin incidencias** en el colegio, y borra las listas de ese
>      día.
> 7. **Solo lectura** (historial, bloqueo): el ✓ sale solo para estudiantes CONFIRMADOS.
>    Tras un bloqueo forzado, un borrador no muestra sus incidencias, y un ✓ afirmaría una
>    asistencia falsa.
> 8. **Parcial de celda único** (`_af-celda.php`): los cinco estados, compartidos por la
>    grilla y el calendario por estudiante. El JS repinta con las mismas clases.
> 9. El **QR de 2027** abrirá la misma lista con `origen = 'qr'`: diseño en
>    `docs/decisiones-diferidas.md`.
>
> Pendiente de decisión: que las estadísticas de Cuadros descuenten los días no lectivos
> (ver `decisiones-diferidas.md`).
>
> **Estado (30/09/2026, sin commit):** la 070 está aplicada en local (851 listas, 0
> justificadas sin motivo, 0 contadores incoherentes). La batería da 55/55, con
> `verif_asistencia_jornadas.php` nuevo.
>
> Probado en Chrome con admin, en 2.° B:
> - menú por celda;
> - FJ + Cancelar no cambia nada; T + TJ + Cancelar deja la T;
> - TJ con motivo guarda con su icono;
> - la marca toma la lista y toda la columna pasa a ✓;
> - FJ sin motivo por POST directo → 400;
> - deshacer con incidencias → 400; sin incidencias → vuelve a «Sin tomar»;
> - «Pasar lista de hoy» funciona;
> - el pie «Días sin tomar» cuadra y el botón de bloqueo queda deshabilitado;
> - el no lectivo 08/10 sale como «NL» en la grilla y en el calendario;
> - vista por estudiante con el mismo menú.
>
> Los datos de prueba se revirtieron.
>
> **Con sesión de AUXILIAR (ESPINOZA):**
> - con 18/18 confirmados y el 30/09 sin tomar, el panel dice «Días sin tomar: 1» en lugar
>   de «Listo para bloquear»;
> - el POST de bloqueo se niega con «Hay 1 día(s) sin lista tomada (30/09)…» y no crea
>   cierre;
> - estado revertido.
>
> **Con sesión de DIRECTOR EBR (BERNUI):**
> - Motivos en solo lectura, con el enlace a «Días no lectivos»;
> - la pantalla de no lectivos sin formularios y con «← Motivos»;
> - `POST /no-lectivos/crear` y `POST /{id}/jornada` → **403 por rol**, también con un
>   token CSRF válido. Nada escrito.
>
> **Celular real: PROBADO por el usuario el 30/09/2026.** Entró por la IP de la laptop en
> la wifi (`http://<IP>:3000/siga_cociap/public`; en el celular, `localhost` es el propio
> teléfono). Conforme. La quinta ronda queda cerrada.

> **AVANCE (29/09/2026, sin commit):**
> - ✅ **Fase 1 — Conducta B2: HECHA y probada.** `ConductaModel::RESPUESTAS_OFICIALES`
>   (punto único), `guardarBorrador` / `confirmar` / `estaConfirmado`, `bloquearRA(...,
>   $forzado)`, ruta `POST /admin/conducta/confirmar`, guardián común
>   `ConductaController::escrituraValidada`, `conducta.js` reescrito (autoguardado en cola
>   por fila), vistas con Estado/Confirmar/«Confirmar todo», «← Anterior» / «Confirmar y
>   siguiente →», director forzado con aviso, retorno de grado mueve
>   `conducta_confirmaciones`. `verif_confirmacion_conducta.php` TODO OK; batería 52/52.
>   Chrome (ESPINOZA, 2.° A): autoguardado, Marcar Sí = borrador, confirmar, desconfirmar
>   al cambiar, bloqueo rechazado 0/18 (también forjado), Confirmar todo 18/18, vista por
>   estudiante. Defecto hallado y corregido: `.btn` le ganaba a `[hidden]` en «Confirmar».
>   Datos de prueba que quedaron en local: 2.° A III Bimestre con 18 confirmados.
> - ✅ **Fase 2 — migración 069 aplicada en local** por el usuario (29/09/2026): I y II
>   por números, III y IV por fechas, 2 motivos, fila de AMADO desconfirmada.
> - ✅ **Fase 3 — Asistencia, datos: HECHA.** `AsistenciaModel::sqlConfirmada` /
>   `sqlVisible` (puntos únicos), `diasMarcables` (reusa `diasPlanilla`), `incidenciasDe`,
>   `marcarDia`, `recalcularContadores` (único escritor de contadores por fechas),
>   `confirmar`, `confirmarSeccion`, `bloquearRA(..., $forzado)`, `guardar` viejo negado
>   en bimestres por fechas, `registrarExtraordinaria` pisa borradores y nunca lo
>   confirmado. `AsistenciaMotivoModel` (lectura). Rutas `POST /admin/asistencia/dia`,
>   `/confirmar`, `/{id}/confirmar-todo`. Retorno de grado mueve `asistencia_incidencias`.
>   Dirección (consulta) e imprimible leen solo lo confirmado. Textos del director
>   corregidos: decían que la asistencia sin registro «cuenta como 0» y que no salía de la
>   boleta al reabrir; ambas cosas dejaron de ser ciertas. `verif_asistencia_fechas.php`
>   TODO OK; `verif_asistencia_partial_compartido.php` actualizado (3 métodos nuevos).
> - ✅ **Fase 4 — Motivos: HECHA.** `AsistenciaMotivoModel` (listar, crear con código
>   estable sin tildes, renombrar con traza, retirar —nunca el último—, mover),
>   `Admin\AsistenciaMotivoController`, vista `admin/asistencia/motivos.php` (reusa las
>   clases de criterios de conducta), botón «Motivos» en el índice (admin/RA) y card
>   «Motivos de justificación» para Dirección (icono `check-circle.svg`). Verificador §2b.
> - ✅ **Fase 5 — Pantallas: HECHA y probada en Chrome (ESPINOZA, 2.° A).** Grilla mensual
>   (`_grilla-fechas.php` + `asistencia-fechas.js`, el viejo `asistencia.js` queda para los
>   bimestres de números), calendario por estudiante (`_estudiante-fechas.php`), select de
>   motivo compartido (`_af-motivo.php`), imprimible con columna «Fechas» y guion para
>   quien no está confirmado, consulta de Dirección con columna «Fechas». Panel del
>   auxiliar: «Confirmados X de N» y «Listo para bloquear» también en asistencia.
>   Probado: F, FJ sin motivo (botón y servidor la rechazan), motivo → confirmar, T por la
>   vista por estudiante + «Confirmar y siguiente», «Confirmar todo» 18/18, imprimible
>   (render con bloqueo temporal revertido). Defectos hallados y corregidos: `$titulo`
>   pisado por el parcial (título de pestaña = una fecha), columnas fijas translúcidas al
>   desplazar, CSS inline evitado con `.af-col-N`.
> - ✅ **Fase 6 — Documentación:** `CLAUDE.md` (fila + 2 invariantes), `ESTADO.md`
>   (068/069 en prod antes del merge + comprobación de cierres de I y II),
>   `retorno-grado.md`, `admin.md`, `decisiones-diferidas.md`.
> - ⏳ **Falta:** probar con sesión de RA (pantalla de motivos), de Director (bloqueo
>   forzado de conducta y asistencia, card de motivos, consulta con fechas) y de tutor;
>   celular real; commit.

## Contexto

En el repaso del módulo auxiliares (29/09/2026) el usuario pidió:
1. **B2**: el flujo de los docentes en conducta Y asistencia. Cada cambio se **autoguarda
   como borrador**, «Confirmar» da el visto bueno y **«Bloquear y aprobar» sigue siendo el
   visto final**.
2. **Asistencia por fechas (opción A)**: el auxiliar marca **qué días** hubo F/FJ/T/TJ y los
   4 contadores **se calculan solos**. Hoy solo existen 4 números por bimestre.

El problema de fondo es la **integridad del dato**. Hoy nada marca qué es oficial: toda
lectura asume que una fila guardada es definitiva, y eso solo es cierto porque el guardado
exige la fila completa. Con borradores, 4 «Sí» de conducta darían 8 (C) en la boleta, y un
«1» a medio teclear saldría como falta. Además, si fechas y contadores fueran dos fuentes,
se desincronizarían en silencio, que es el patrón de fallo conocido del repo.

Medido en local: conducta II 524/524 y III 42/42 completos; migración **068** ya aplicada
por el usuario (confirmaciones + backfill).

## Decisiones cerradas por el usuario (29/09/2026)

| Tema | Decisión |
|---|---|
| Borradores | «Al aire»: no cuentan en boleta, cuadros, tutor, bloqueo ni vía extraordinaria |
| Editar algo confirmado | Lo desconfirma. Las confirmaciones sobreviven a la reapertura del director |
| Bloqueo con pendientes | **b)** auxiliar/RA exigen 100 % confirmado; el **director puede forzar** y lo no confirmado queda fuera (guion) |
| Asistencia en boleta | **Pasa a exigir el bloqueo**, como conducta |
| Vista por estudiante | Dos botones: «← Anterior» y «Confirmar y siguiente →». Grilla: «Confirmar todo» |
| Fechas: desde | **III Bimestre** en adelante; I y II quedan como números (histórico) |
| Días marcables | Lunes a viernes dentro de `periodos.fecha_inicio..fecha_fin` (sin calendario de feriados) |
| Códigos | F · FJ · T · TJ |
| Justificación | **Motivo obligatorio** en FJ/TJ, elegido de un select, guardado para reportes |
| Motivos | **Catálogo con pantalla de gestión**: admin y RA editan, Dirección lee; un motivo usado se retira, no se borra. Iniciales: «Justificación escrita autorizada», «Justificación verbal» |
| Motivo: cuándo | Al **confirmar** (el día se autoguarda sin motivo; Confirmar se niega si falta) |
| Grilla | **Mensual día × estudiante**, como la planilla de papel, con los 4 totales del bimestre al final de cada fila |
| Quién ve fechas | Registran: auxiliar, RA, admin. Ven: **Dirección**. **Familias → v1.1** (sin logins de padres) |

**Decisiones técnicas mías** (revisables en este plan):
- **No se marcan días futuros** (máx. = hoy): nadie falta mañana.
- **Retorno de grado:** las fechas del bimestre en curso **se mueven** con sus contadores a la
  operativa, como ya hacen `inasistencias` y conducta (`RetornoGradoController:188`).
- **Vía extraordinaria** (bimestre cerrado, alumno que llegó tarde): sigue siendo **4 números
  sin fechas**, y queda confirmada al insertarse.
- **Cuadros de Dirección:** leen solo lo **confirmado**, pero **sin** exigir bloqueo. Es el
  mismo criterio de monitoreo que ya usa `getDistribucionLiteralesAnual`.

## Diseño de datos

**068 (ya aplicada):**
- `conducta_confirmaciones` (fila por estudiante y bimestre = confirmado).
- `inasistencias.confirmado_en` / `confirmado_por` (vacío = borrador).

**069 — `069_asistencia_por_fechas.sql`** (idempotente; backfill solo si la estructura es nueva):
- `asistencia_motivos` (id, codigo UNIQUE, nombre, orden, retirado_en/por,
  creado_en, modificado_en/por).
  - Se siembra con los 2 motivos iniciales.
- `asistencia_incidencias`:
  - columnas: id, matricula_id, periodo_id, fecha DATE, tipo ENUM('F','FJ','T','TJ'),
    motivo_id NULL FK, registrado_por, registrado_en, modificado_en;
  - `UNIQUE (matricula_id, fecha)`: una incidencia por estudiante y día;
  - `CHECK (motivo_id IS NULL OR tipo IN ('FJ','TJ'))`.
- `periodos.asistencia_por_fechas TINYINT`:
  - 0 en los bimestres `cerrado` (I, II);
  - 1 en `activo` y `pendiente`.
  - Se ancla en el DATO, no en una fecha escrita en el código.
- **Filas del bimestre en curso guardadas como números:**
  - las que tienen los 4 contadores en 0 quedan igual (coinciden con «cero fechas»);
  - las que tienen algún contador > 0 se **desconfirman** y la migración las **lista**. El
    auxiliar las vuelve a registrar por fecha.
  - Local: 1 fila (prueba de AMADO, F2 T3). **Producción: medirlo con el SELECT previo
    antes de aplicar.**

**Invariante nuevo** (va a `CLAUDE.md`):
> En un periodo con `asistencia_por_fechas = 1`, toda fila de `inasistencias` con
> `extraordinaria = 0` tiene sus 4 contadores **iguales al conteo** de sus
> `asistencia_incidencias`.

- Solo los escribe un punto único: `AsistenciaModel::recalcularContadores()`, en la misma
  transacción que cada cambio de día.
- «Confirmado» es la única verdad de «oficial», en conducta y en asistencia.

## Puntos únicos (nada se copia a mano)

**`ConductaModel`:** un fragmento SQL «respuestas confirmadas» que usan:
- `getParaBoleta(Union)`, `getParaPeriodo`, `getEstudiantesParaTutor`, `completitudSeccion`
  (ahora cuenta confirmados);
- `getProgresoConductaPorSeccion`, `getDistribucionLiteralesAnual`,
  `getIncumplimientoCriterios`;
- `sqlAdmiteExtraordinaria`, `registrarLiteralExtraordinario`;
- `getEstudiantesParaRegistro` con un parámetro `$soloConfirmadas`, que usan las grillas de
  solo lectura del tutor (`ConductaTutorController:133`) y de Dirección
  (`ConsultaNotasController:492`).

**`AsistenciaModel`:** dos fragmentos, «fila confirmada» y «fila visible = confirmada + cierre
vigente».
- Visible (boleta): `getDelBimestre(Union)`, `getAcumuladoAnual(Union)`, `tieneRegistroUnion`.
- Solo confirmada (monitoreo y vía extraordinaria): `sqlSinRegistro`, `admiteExtraordinaria`,
  `getProgresoPorSeccion`, `getIncidenciasPorSeccion`, `getTopIncidenciasPorSeccion`,
  `getEvolucionIncidenciasAnual`.
- Métodos nuevos:
  - `diasMarcables(periodo)`: días lun–vie del bimestre, sin futuros;
  - `incidenciasDe(...)`: grilla y calendario;
  - `marcarDia(...)`: autoguardado de un día + recálculo de contadores + desconfirmar, en una
    transacción;
  - `confirmar(...)`: exige motivo en cada FJ/TJ;
  - `confirmarSeccion(...)`: «Confirmar todo».
- **Reusar** `PlanillaAsistenciaModel::mesesDelPeriodo()` y la lógica lun–vie de
  `diasPlanilla()`. No se escribe otro calendario.

**Bloqueo:**
- `ConductaModel::bloquearRA` y `AsistenciaModel::bloquearRA` exigen 100 % confirmado cuando
  los llama el auxiliar o RA.
- Los métodos del director (`Director\BloqueoController::bloquearConducta` /
  `bloquearAsistencia`) pasan un flag «forzado» que omite esa exigencia.

**Retorno de grado:** añadir `asistencia_incidencias` y `conducta_confirmaciones` a la lista
que se mueve en `RetornoGradoController:188`.

**Motivos:** `AsistenciaMotivoModel` + `Admin\AsistenciaMotivoController`, copiando el patrón
de `CriterioConductaController` (`ROLES_VEN` / `ROLES_EDITAN`, retirar en vez de borrar).
- Rutas `/admin/asistencia/motivos*` **antes** de `/admin/asistencia/{id}` en
  `routes/web.php` (invariante del router).
- El select de la vista sale del catálogo: **sin copia en JS**, a diferencia de
  `MOTIVOS_OMISION`.

## Escritura (rutas nuevas, mismas guardas que hoy)

Guardas comunes a todas: `puedeRegistrar`, periodo editable, sección no bloqueada,
`matriculaEnRoster`, CSRF.

- **Conducta:**
  - `POST /admin/conducta/respuesta`: un criterio → upsert + borrar la confirmación;
  - `POST /admin/conducta/confirmar`: las N respuestas + confirmación en una transacción;
  - «Marcar Sí» guarda borrador.
- **Asistencia:**
  - `POST /admin/asistencia/dia`: fecha + tipo (o vacío) + motivo opcional → valida día
    marcable;
  - `POST /admin/asistencia/confirmar` (uno) y `/admin/asistencia/{id}/confirmar-todo`.
  - El endpoint viejo `/admin/asistencia/guardar` (4 números) **se retira** en periodos por
    fechas: responde 409.

## Pantallas

- **Grilla de asistencia** (`admin/asistencia/seccion.php` + nuevo parcial):
  - pestañas de mes del bimestre;
  - columnas = días marcables del mes;
  - tocar una celda: · → F → FJ → T → TJ → ·;
  - FJ/TJ sin motivo lleva una marca «!» y abre un select pequeño;
  - al final de cada fila: F · FJ · T · TJ del bimestre, estado
    (Confirmado / Borrador / Falta motivo) y «Confirmar»;
  - arriba: «Confirmar todo».
  - El modo solo lectura (historial y Dirección) muestra las mismas celdas sin interacción.
  - I y II siguen con el parcial actual de números: `_tabla-incidencias.php` **no se borra**.
- **Por estudiante** (`admin/asistencia/estudiante.php`):
  - calendario del bimestre mes a mes con celdas grandes;
  - lista de FJ/TJ con su select de motivo;
  - los 4 totales;
  - «← Anterior» / «Confirmar y siguiente →».
- **Conducta:**
  - grilla con estado y «Confirmar» por fila, «Confirmar todo»;
  - vista por estudiante con «← Anterior» / «Confirmar y siguiente →»;
  - panel del auxiliar con «Confirmados X de N».
- **Imprimible del registro de asistencia:** cada fila lleva sus fechas (resumen por
  estudiante: «F: 03/09, 17/09 · FJ: 10/09 (Just. escrita)…»).
- **Dirección** (`consulta-notas/.../asistencia`): mismo detalle de fechas y motivos, en
  solo lectura.
- **Pantalla de motivos:** enlazada desde el índice de asistencia (admin/RA) y desde el hub
  de Dirección en lectura. La card del dashboard necesita icono: se decide al construirla.
- JS: `asistencia.js` se reescribe para celdas-día. `conducta.js` recibe el autoguardado.
  `registro-estudiante.js` recibe «← Anterior» y deja de llamar a `guardarFila` como
  guardado final. Estilos solo en SASS + `gulp build`.

## Orden de ejecución (cada fase: código → verificador de dos ramas → batería → Chrome)

1. **Conducta B2** (independiente): lecturas confirmadas → rutas → JS y vistas → bloqueo
   forzado del director.
2. **Migración 069:** el usuario la aplica en local.
3. **Asistencia, datos:** puntos únicos, `marcarDia`/`confirmar`, recálculo, retorno de
   grado, compuerta de boleta por cierre.
4. **Motivos:** catálogo + pantalla.
5. **Asistencia, pantallas:** grilla mensual, vista por estudiante, imprimible, Dirección.
6. **Documentación:**
   - `auxiliares.md`, `admin.md`, `retorno-grado.md` (nueva tabla que se mueve);
   - `CLAUDE.md` (2 invariantes);
   - `ESTADO.md` (068/069 en producción **antes** del merge; medir filas del III con números);
   - `decisiones-diferidas.md` (familias v1.1; motivos con documento de sustento y QR 2027,
     cuya tabla diaria es `asistencia_incidencias`).

## Archivos críticos

- `app/Models/ConductaModel.php`, `app/Models/AsistenciaModel.php` (puntos únicos),
  `app/Models/BoletaModel.php` (solo si cambia la firma de lo que usa; hoy llama a
  `tieneRegistroUnion` / `getDelBimestreUnion`).
- `app/Controllers/Admin/ConductaController.php`, `AsistenciaController.php`,
  `Director/BloqueoController.php`, `Matricula/RetornoGradoController.php`,
  `Consulta/ConsultaNotasController.php`, `Docente/ConductaTutorController.php`.
- Nuevos: `AsistenciaMotivoModel`, `Admin/AsistenciaMotivoController`, vistas de motivos,
  grilla mensual y calendario.
- `routes/web.php`, `resources/js/{conducta,asistencia,registro-estudiante}.js`,
  `resources/sass/pages/{_conducta,_asistencia,_registro-estudiante}.scss`.
- `database/migrations/069_asistencia_por_fechas.sql`.
- `database/verificaciones/verif_confirmacion_conducta.php`,
  `verif_asistencia_fechas.php` (nuevos), y ajustar `verif_rol_auxiliar.php` /
  `verif_asistencia_partial_compartido.php` a los ganchos nuevos.

## Verificación

- **Verificadores nuevos** (transacción + rollback; nunca DELETE de lo que no crearon), con
  las dos ramas de cada guarda:
  - un borrador **no** aparece en boleta, padre, tutor, cuadros ni completitud; al confirmar,
    **sí**;
  - editar desconfirma;
  - bloqueo del auxiliar con pendientes → rechazado; forzado del director → aceptado y los
    pendientes salen con guion;
  - contadores = conteo de fechas tras cada `marcarDia`;
  - día rechazado si es sábado, domingo, fuera del bimestre, futuro o repetido;
  - motivo en F/T rechazado;
  - Confirmar sin motivo en FJ/TJ → rechazado;
  - retorno de grado mueve fechas y confirmación;
  - la vía extraordinaria mira solo lo confirmado;
  - I y II intactos.
- **Batería completa** de `database/verificaciones/` en verde.
- **Chrome con ESPINOZA** (y luego RA, director y tutor):
  - pasar lista de un día por la grilla;
  - FJ con motivo;
  - confirmar todo;
  - intentar bloquear con uno pendiente;
  - vista por estudiante en el celular;
  - imprimible con fechas;
  - Dirección en lectura;
  - boleta del III antes y después del bloqueo.
- **Antes de producción:**
  - medir filas del III con números > 0;
  - comprobar que cada sección de I y II tiene cierre de asistencia vigente (si falta alguno,
    su asistencia desaparecería de la boleta publicada: detenerse y decidir con el usuario).
