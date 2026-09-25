# Promoción de grado — reglas de la situación final

> **Qué es este documento.** La referencia completa y razonada de cómo SIGA-COCIAP determina si
> un estudiante es **promovido** (`PRO`), **requiere recuperación** (`RR`) o **permanece en el
> grado** (`PER`). Explica la norma, cómo se lee en los casos que la norma deja abiertos, qué
> datos entran al cálculo y dónde vive cada regla en el código.
>
> **Vigente al 24/09/2026** (commit `c07e423`). El diario de cambios, las mediciones y los
> detalles de pantalla del informe viven en `docs/modulos/usuarios-direccion.md`
> § «Riesgo académico = situación final del MINEDU». Si este documento y el código discrepan,
> **manda el código**, y este documento se corrige.

---

## 1. Marco normativo

| Norma | Qué aporta |
|---|---|
| **RVM N.° 00094-2020-MINEDU** | «Norma que regula la evaluación de las competencias de los estudiantes de la Educación Básica». Define los cuadros de promoción, recuperación y permanencia, y la convención de «la mitad» para números impares (numeral 5.1.3). |
| **RVM N.° 048-2024-MINEDU** (30/04/2024) | Modifica la anterior. En lo que aquí importa, **reescribe el cuadro completo de Secundaria**. El de **Primaria sigue siendo el original de 2020**. |

- **No existe norma posterior que las derogue.** La RM N.° 474-2022-MINEDU, que a veces se cita como
  su reemplazo, es la norma técnica del año escolar 2023, no una norma de evaluación.
- **El SIAGIE asigna la situación final automáticamente** al cierre del año, con «el último nivel de
  logro de cada competencia, registrado en el SIAGIE» (RVM 048-2024, 5.1.1.3). SIGA replica esa regla
  para **proyectar** la situación durante el año y la **calcula en firme** en el último bimestre.

---

## 2. Conceptos

| Término | Significado en este documento |
|---|---|
| **Competencia** | Unidad de evaluación del CNEB. Cada una recibe un **nivel de logro**: `AD`, `A`, `B` o `C`. |
| **Nivel de logro** | Se calcula desde la nota numérica 00-20 con la escala única de `helpers.php`: AD 18-20 · A 14-17 · B 11-13 · C 00-10. |
| **Área** | Agrupa competencias. Las subáreas del colegio (p. ej. *Ciencias Sociales · Geografía*) **cuentan dentro de su área**, no como áreas propias. |
| **n** | Número de competencias del área **con nivel de logro registrado**. |
| **ab / b / c** | Cuántas de esas `n` competencias están en A o AD / en B / en C. |
| **Grado final de ciclo** | Primaria 2.º, 4.º y 6.º · Secundaria 2.º y 5.º. |
| **Grado intermedio** | Primaria 3.º y 5.º · Secundaria 1.º, 3.º y 4.º. (Primaria 1.º tiene promoción automática.) |

### Por qué importa el ciclo

La norma exige más al **cerrar un ciclo**: «se espera que un estudiante haya avanzado un nivel en
el desarrollo de la competencia en ese tiempo» (RVM 094-2020, 5.1.3). En la práctica:

- **Grado final de ciclo:** la promoción exige «**B en las demás competencias**», así que **una sola
  C en cualquier área impide la promoción**.
- **Grado intermedio:** la norma admite C de forma explícita, siempre que **cada área** conserve la
  mitad o más de sus competencias en B o superior.

El mismo número de C significa cosas distintas según el grado y según en qué áreas caiga. Por eso
la regla **se cuenta por área**, nunca con un total global de competencias.

---

## 3. «La mitad» y «más de la mitad»

Son las dos medidas de las que dependen todos los cuadros.

| Expresión | Regla | Fuente |
|---|---|---|
| **«la mitad»** | `ceil(n / 2)` | Convención explícita de la RVM 094-2020 (5.1.3): área de 5 → 3; de 3 → 2; de 1 → «esa única competencia». |
| **«la mitad o más»** | `c ≥ ceil(n / 2)` | Se deriva de la anterior. |
| **«más de la mitad»** | **`c > n / 2`**, la mitad entera más una | **Decisión del colegio (24/09/2026)**: la norma no la define para los impares. |

| Competencias del área (n) | 1 | 2 | 3 | 4 | 5 |
|---|---|---|---|---|---|
| **la mitad** | 1 | 1 | 2 | 2 | 3 |
| **más de la mitad** | 1 | 2 | 2 | 3 | 3 |

**Por qué «más de la mitad» es la mitad entera más una:**

1. Personal Social tiene 5 competencias, y 3 de 5 es «más de la mitad» en su sentido común.
2. **Coherencia:** así, «C en más de la mitad» equivale **exactamente** a «no llega a la mitad en
   B o superior», para cualquier `n`. La permanencia y la promoción miran la misma frontera desde
   lados opuestos.
3. La lectura anterior (`c > ceil(n/2)`, vigente del 23 al 24/09/2026) exigía 4 C de 5. Además, en
   un área de **una sola competencia** (EPT, Ética y Valores, Taller de Pre-Cálculo) al 100 % en C
   hacía imposible contar para la permanencia (`1 > 1`).

⚠️ **Solo con `n` par se distinguen** «la mitad o más» y «más de la mitad». Con `n` impar coinciden.
Esto importa en Secundaria 2.º y 5.º, que usan «la mitad o más» para la permanencia (ver § 4.2).

---

## 4. Cuadros de promoción, recuperación y permanencia

Siempre se evalúa en este orden: **PRO → PER → RR**. `RR` es el resto: no cumple las condiciones de
promoción **ni** las de permanencia.

### 4.1 Primaria (RVM 094-2020, sin modificar)

| Grado | Ciclo | PRO — promovido | PER — permanece |
|---|---|---|---|
| **1.º** | — | **Automática** | No aplica |
| **2.º, 4.º, 6.º** | Final | A o AD en **la mitad o más** de las competencias de **4 áreas**, **y B o superior en todas las demás** (ninguna C) | C en **más de la mitad** de las competencias de **4 o más áreas** |
| **3.º, 5.º** | Intermedio | B o superior en **la mitad o más** de las competencias de **todas** las áreas (puede tener AD, A o C en las demás) | C en **más de la mitad** de las competencias de **4 o más áreas** |

### 4.2 Secundaria (texto vigente tras la RVM 048-2024)

| Grado | Ciclo | PRO — promovido | PER — permanece |
|---|---|---|---|
| **1.º, 3.º, 4.º** | Intermedio | Como mínimo B en **la mitad o más** de las competencias de **cada una** de las áreas (puede tener AD, A o C en las demás) | C en **más de la mitad** de las competencias de **cada una de 4 o más áreas** |
| **2.º, 5.º** | Final | A o AD en **la mitad o más** de las competencias de **cada una de 3 áreas**, **y B en las demás** (ninguna C) | C en **la mitad o más** de las competencias de **cada una de 4 o más áreas** |

### 4.3 Requiere recuperación (RR)

`RR` = no alcanza la promoción y no reúne las 4 áreas de la permanencia. En la práctica:

- **Grado intermedio:** al menos un área no llega a la mitad en B o superior, pero menos de 4 áreas
  tienen C en más de la mitad.
- **Grado final de ciclo:** tiene alguna C, **o** no reúne las áreas en A/AD que se exigen (4 en
  primaria, 3 en secundaria), sin llegar a la permanencia. **Un RR puede no tener ni una sola C**:
  basta con que falten áreas en A o AD.

### 4.4 Decisión de lectura en la permanencia de Primaria

La norma dice «C en más de la mitad de las competencias de cuatro áreas **y B en las demás**».
Leída al pie de la letra, un estudiante con C de sobra quedaría **fuera** de la permanencia, que es
lo contrario de lo que busca la norma. Se considera un artefacto de redacción: **se aplica solo la
condición de C**, igual que el SIAGIE.

### 4.5 Los tres filtros y su jerarquía (revisado el 25/09/2026)

| Filtro | Pregunta | Resultado |
|---|---|---|
| **Permanencia** | ¿Reúne las 4 áreas en C de § 4.1/4.2? | `PER`: sin oportunidad de recuperación |
| **Promoción** | ¿Cumple el cuadro de su grado? | `PRO`; si no, y no es PER, `RR` |
| **Acompañamiento** | ¿Acumula competencias bajas? Primaria ≥ 3 B **o** ≥ 3 C (por separado) · secundaria ≥ 3 C | Bloque de **seguimiento** (no es una situación final) |

- **PER y PRO son mutuamente excluyentes**, así que da igual cuál se evalúe primero. En los grados
  intermedios, «C en más de la mitad» equivale a «no llega a la mitad en B o superior». En los
  grados finales, PRO exige cero C. El código evalúa PRO → PER → RR, y por esa exclusión es lo
  mismo que PER → PRO/RR. Se decidió no reordenarlo.
- **El acompañamiento es transversal**: se aplica **a todos** los evaluados, sean PRO, RR o PER
  (decisión del 25/09/2026). Un RR o PER sobre el umbral sale también en el bloque de riesgo.
  No se aplica a `PEND` ni a los estudiantes sin datos. Punto único: `seguimiento_pedagogico()`.
  Detalle de pantalla en `docs/modulos/usuarios-direccion.md`.

---

## 5. Qué entra al cálculo

### 5.1 Competencias que cuentan

| Cuenta | No cuenta |
|---|---|
| Competencias de las **áreas curriculares** | **Competencias transversales** (TIC y GAMA): la norma las excluye (5.1.3, p. 9) |
| **Ética y Valores** (secundaria): es el área-curso Educación Religiosa, que evalúa el tutor | **Tutoría (TOE)**: no tiene competencias |
| **Notas extraordinarias** (alta tardía en bimestre cerrado): son notas oficiales de boleta y SIAGIE | **Áreas o subáreas exoneradas**: no entran ni al numerador ni al denominador |
| **Talleres**, **solo** en los años y grados que la **UGEL aprobó** (`talleres_aprobacion`) | Talleres **no aprobados**: en 2026 ninguno, así que el Taller de Razonamiento Matemático y el de Pre-Cálculo **no cuentan** |
| Solo competencias **bloqueadas** (nota oficial) | Competencias sin bloquear (borrador del docente) |

> Las exclusiones son **distintas** a las del orden de mérito a propósito: el mérito ignora las
> extraordinarias y sí incluye los talleres. Competir por un puesto y ser promovido son preguntas
> distintas.

### 5.2 Nivel de logro final de cada competencia

- **Bimestres I a III:** cada competencia vale por su **último nivel registrado** en el año, aunque
  se haya evaluado en un bimestre anterior (RVM 094-2020, 5.1.2.2 p. 3; RVM 048-2024, 5.1.1.3 p. 3).
  **No es un promedio anual.**
- **Último bimestre del año:** cuenta **solo** lo registrado en ese bimestre, igual que el logro
  anual de la boleta. La norma exige que al terminar el periodo lectivo todas las competencias
  tengan nivel de logro.

### 5.3 Qué estudiantes se evalúan

- Todas las matrículas **vigentes**: fuera trasladados y retirados. **No se filtra por estado**: las
  matrículas `pendiente` y `desactivado` siguen asistiendo, se califican y serán promovidas o no.
- **Retorno de grado:** cada bimestre se lee donde se cursó, pero **toda regla usa el grado de la
  matrícula OFICIAL**: el ciclo, la exigencia y la aprobación de talleres. El estudiante se reporta
  en su grado y sección oficiales. Ver `docs/modulos/retorno-grado.md`.

---

## 6. Proyección durante el año

Hasta el último bimestre, la situación final es una **proyección**: «si el año terminara hoy».

| Situación | Cuándo |
|---|---|
| `PRO` / `RR` / `PER` | Se calcula con las competencias que tienen nivel de logro. |
| `ND` — sin datos | El estudiante no tiene ninguna competencia evaluada. No cuenta como evaluado ni como riesgo. |
| `PEND` — pendiente | **Solo en el último bimestre**: faltan competencias por evaluar. No es riesgo; se lista aparte hasta completarse. |

### 6.1 Denominador y certeza

En los bimestres I a III el docente elige qué competencias evalúa, así que un área puede tener
menos competencias registradas que las de su plan. Por ejemplo, Matemática tiene 4 y quizá solo se
registraron 2.

- **La sigla** se calcula con lo **evaluado** (`n` = registradas). Contar lo pendiente como «no
  logrado» sería inventar un dato en contra del estudiante.
- **La certeza** vuelve a probar la sigla contra el **plan completo** de cada área:
  - *mejor caso:* todas las pendientes en AD;
  - *peor caso:* todas en C.
- Un riesgo es **seguro** si ni el mejor caso llega a la promoción. Es **proyectado** si lo pendiente
  aún puede salvarlo, y entonces el informe lo marca «Depende de N pendientes».
- «Seguro» no es definitivo: las notas de los bimestres siguientes reemplazan a las de hoy.

---

## 7. Ejemplos resueltos

Todos están comprobados contra `situacion_final_analisis()`. Las áreas no mencionadas están en A o
AD.

| # | Grado | Datos relevantes | Resultado | Por qué |
|---|---|---|---|---|
| 1 | Prim 3.º (intermedio) | Matemática (4): B, B, C, C | **PRO** | La mitad (2) está en B o superior; en un grado intermedio las C están permitidas. |
| 2 | Prim 4.º (final) | Todo en A/AD salvo **una C** en Inglés | **RR** | En un grado final de ciclo, una sola C impide la promoción. |
| 3 | Sec 1.º (intermedio) | Ciencias Sociales (3): A, C, C | **RR** | 1 de 3 en B o superior no llega a la mitad (2). Es 1 área de las 4 que exige la permanencia. |
| 4 | Sec 1.º | **4 áreas de 3** competencias con **2 C** cada una | **PER** | 2 de 3 es más de la mitad, en 4 áreas. |
| 5 | Prim 5.º | Personal Social 3 C de 5 · Matemática 3 C de 4 · Comunicación 2 C de 3 · CyT 2 C de 3 | **PER** | Las 4 áreas tienen C en más de la mitad (3/5, 3/4, 2/3, 2/3). |
| 6 | Sec 3.º | Educación para el Trabajo (1 competencia) en C | **RR** | El área de una sola competencia no llega a la mitad en B. Cuenta como 1 de las 4 áreas de la permanencia. |
| 7 | Sec 5.º (final) | Matemática 2 C de 4 · Arte 1 C de 2 · DPCC 1 C de 2 · Inglés 2 C de 3 | **PER** | En 2.º y 5.º basta **la mitad o más** en C: se cumple en 4 áreas. |
| 8 | Sec 1.º | **Las mismas notas** del ejemplo 7 | **RR** | En 1.º se exige *más* de la mitad: solo Inglés (2 de 3) cuenta. Matemática, Arte y DPCC tienen exactamente la mitad, que no es más de la mitad, y conservan la mitad en B. |
| 9 | Sec 2.º (final) | **Ninguna C**; todo en B salvo Matemática y Comunicación en A | **RR** | Sin C, pero solo 2 áreas con la mitad en A/AD (se exigen 3). Un RR sin ninguna C. |
| 10 | Sec 2.º (final) | Ninguna C; Matemática, Comunicación y CyT con la mitad o más en A | **PRO** | 3 áreas en A/AD y ninguna C. |

---

## 8. Relación con el SIAGIE

| Aspecto | ¿Coincide? |
|---|---|
| Cuadros de primaria y secundaria | Sí: son los de la norma. |
| Último nivel registrado | Sí: la misma regla (RVM 048-2024). |
| Transversales, exoneradas, extraordinarias | Sí. |
| Talleres | Sí: cuentan solo si están en el plan del SIAGIE (aprobados por la UGEL). |
| **Ética y Valores → Educación Religiosa** | Sí. El exportador duplica la nota de Ética en las 2 columnas de EREL. Con la lectura vigente de «más de la mitad», 1 de 1 y 2 de 2 dan el mismo resultado en las tres reglas. |
| **«Más de la mitad» con n impar** | **Por confirmar.** La norma no lo define y el manual del SIAGIE no es público. Se contrastará con la situación final del SIAGIE al cierre del IV bimestre de 2026. |
| ⚠️ **5.º de secundaria: EPT ← GAMA** | **Divergencia conocida.** En 5.º no se dicta EPT, y su acta en el SIAGIE (`032-ETRA`) se llena con la transversal GAMA. Para el SIAGIE, 5.º tiene un área más: una GAMA en C daría RR. SIGA no la cuenta. Hoy no afecta a nadie (0 GAMA en C). |

**Momento del cálculo:** el SIAGIE solo determina la situación final al cierre del año. Todo lo que
SIGA muestra en los bimestres I a III es proyección; la comparación real se hace en el IV bimestre.

---

## 9. Dónde vive cada regla en el código

| Pieza | Ubicación | Responsabilidad |
|---|---|---|
| `mitad_competencias(n)` | `app/Helpers/helpers.php` | Punto único de «la mitad» (`ceil(n/2)`). |
| `mas_de_la_mitad(c, n)` | `app/Helpers/helpers.php` | Punto único de «más de la mitad» (`2c > n`). |
| `grado_final_de_ciclo()`, `grado_promocion_automatica()` | `app/Helpers/helpers.php` | Ciclos y 1.º de primaria. |
| `situacion_final_analisis()` | `app/Helpers/helpers.php` | **La regla**: función pura que devuelve la sigla, los conteos, el veredicto por área y el motivo legible. |
| `situacion_final_proyectar()` | `app/Helpers/helpers.php` | Certeza (mejor y peor caso contra el plan) y `PEND` en el último bimestre. |
| `situacion_efecto_competencia()` | `app/Helpers/helpers.php` | Chip de cada competencia en el informe (⚠ PER, ⚠ RR, «En el límite»). |
| `SituacionFinalModel` | `app/Models/SituacionFinalModel.php` | Los datos: roster, notas, plan, retorno de grado y talleres (`areasQueCuentan()`). |

**Ningún umbral se escribe a mano fuera de estas funciones.** Las únicas copias deliberadas son las
de control de las verificaciones, que existen precisamente para detectar si la regla cambia.

### Verificaciones

| Script | Qué protege |
|---|---|
| `database/verificaciones/verif_situacion_final.php` | La regla pura: convenciones de «la mitad» y «más de la mitad», un caso por rama de cada cuadro, la frontera entre «la mitad o más» y «más de la mitad», la monotonía de la proyección (3 000 casos aleatorios) y los chips. |
| `database/verificaciones/verif_riesgo_situacion_bd.php` | Contra la BD: recalcula cada situación con una copia de control escrita a mano, más talleres, último nivel registrado, retorno de grado y certeza. |

---

## 10. Historial de decisiones

| Fecha | Decisión |
|---|---|
| 23/09/2026 | El «estudiante en riesgo» deja de ser un conteo de competencias y pasa a ser la **situación final del MINEDU** (RR o PER). |
| 23/09/2026 | Permanencia de primaria: se aplica solo la condición de C (§ 4.4). |
| 24/09/2026 | **Último nivel registrado**, como el SIAGIE; en el último bimestre, solo sus notas. |
| 24/09/2026 | **Las extraordinarias cuentan.** Los **talleres** solo con aprobación de la UGEL por año y grado. |
| 24/09/2026 | **Certeza** seguro / proyectado contra el plan completo; `PEND` en el último bimestre. |
| 24/09/2026 | **«Más de la mitad» = la mitad entera más una** (`c > n/2`). Deroga `c > ceil(n/2)`. |
| 25/09/2026 | Revisión de los tres filtros (§ 4.5): «la mitad o más» sigue siendo `ceil(n/2)`: en las áreas impares es la mitad entera más una (5 → 3) y en las pares, la mitad exacta (4 → 2). El **acompañamiento** pasa a aplicarse a **todos** (también RR/PER). |
