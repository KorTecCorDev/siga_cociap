-- ============================================================================
-- ALERTA DE EVALUACION INCOMPLETA — consulta de solo lectura
-- ============================================================================
-- Replica en SQL puro `ControlOperativoModel::alertasEvaluacionIncompleta()`,
-- uno de los dos prerrequisitos del cierre de un bimestre (el otro son los
-- empates del orden de merito, que NO se pueden replicar en SQL: su cascada
-- vive en PHP — ver el README de esta carpeta).
--
-- PARA QUE SIRVE: medir el bloqueador contra PRODUCCION desde phpMyAdmin, sin
-- depender de que el Centro de control (que ya muestra esta alerta) este
-- desplegado. El trabajo que genera es valido en cualquier caso: cada blanco se
-- resuelve registrando la NOTA o la OMISION desde el modulo del docente.
--
-- QUE CUENTA COMO BLANCO: criterios de las cargas ACTIVAS de la seccion del
-- alumno que otros companeros SI tienen con nota (o sea, el criterio se evaluo)
-- y a el le faltan, sin estar cubiertos por omision (motivo) ni por exoneracion
-- del area. Mismo universo del merito: incluye Etica y Valores, excluye el resto
-- de transversales y tutoria. Excluye `trasladado`/`retirado` y aplica el
-- anclaje de retorno POR BIMESTRE del merito: segun el TRAMO guardado en
-- retornos_grado (migracion 073), cada bimestre cuenta en la matricula que lo
-- curso (desde el 05/10/2026; antes solo excluia la oficial de un retorno activo).
--
-- COMO LEER EL RESULTADO: la alerta solo aflora un criterio cuando algun
-- companero de la seccion YA tiene nota en el. Mientras el bimestre avanza la
-- cifra SUBE: lo que se mide a mitad de bimestre es un piso, no un total.
--
-- USO: cambiar el `SET @periodo` de cada bloque (2 = II Bimestre). Cada bloque
-- es autocontenido a proposito, para que se pueda ejecutar suelto en phpMyAdmin
-- sin arrastrar la variable de otra conexion.
--
-- SOLO LECTURA: no escribe nada.
--
-- Validada el 27/07/2026 contra el metodo PHP en los dos periodos con datos:
-- B2 -> 19 alumnos / 19 blancos, y B1 -> 10 alumnos / 671 blancos. Si se cambia
-- `alertasEvaluacionIncompleta`, esta consulta debe cambiar con el.
-- ============================================================================


-- ============ A) RESUMEN: cuanto falta y donde =============================
SET @periodo := 2;

SELECT
    n.nombre         AS nivel,
    g.nombre_display AS grado,
    s.nombre         AS seccion,
    COUNT(DISTINCT m.id)  AS alumnos_afectados,
    COUNT(*)              AS blancos,
    COUNT(DISTINCT cr.id) AS criterios_afectados
FROM matriculas m
INNER JOIN secciones s          ON s.id  = m.seccion_id
INNER JOIN grados g             ON g.id  = s.grado_id
INNER JOIN niveles n            ON n.id  = g.nivel_id
INNER JOIN cargas_academicas ca ON ca.seccion_id = m.seccion_id
                               AND ca.estado     = 'activa'
INNER JOIN criterios cr         ON cr.carga_id       = ca.id
                               AND cr.periodo_id     = @periodo
                               AND cr.eliminado_en   IS NULL
                               AND cr.extraordinario = 0
INNER JOIN competencias comp    ON comp.id = cr.competencia_id
LEFT  JOIN subareas sa          ON sa.id   = comp.subarea_id
INNER JOIN areas a              ON a.id    = COALESCE(sa.area_id, comp.area_id)
WHERE m.tipo NOT IN ('trasladado', 'retirado')
  -- Universo del merito: Etica y Valores entra; el resto de tutoria y las
  -- transversales, no. El CONVERT + COLLATE evita el "Illegal mix of
  -- collations" cuando el cliente manda el literal en otro juego de caracteres.
  AND (a.tipo NOT IN ('transversal', 'tutoria')
       OR a.nombre_boleta = CONVERT('Ética y Valores' USING utf8mb4) COLLATE utf8mb4_unicode_ci)
  AND EXISTS (SELECT 1 FROM calificaciones_criterio cc2 WHERE cc2.criterio_id = cr.id)
  AND NOT EXISTS (SELECT 1 FROM calificaciones_criterio cc
                  WHERE cc.criterio_id = cr.id AND cc.matricula_id = m.id)
  AND NOT EXISTS (SELECT 1 FROM omisiones_criterio oc
                  WHERE oc.criterio_id = cr.id AND oc.matricula_id = m.id)
  AND NOT EXISTS (SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = m.id AND ex.area_id = a.id
                    AND ex.revocado_en IS NULL)
  -- Anclaje de retorno por bimestre: el TRAMO (= RetornoGradoModel::sqlCursoElPeriodo)
  AND m.id NOT IN (SELECT r.matricula_oficial_id FROM retornos_grado r
                   JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                   JOIN periodos px ON px.id = @periodo
                   WHERE (px.numero >= pd.numero AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))))
  AND m.id NOT IN (SELECT r.matricula_operativa_id FROM retornos_grado r
                   JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                   JOIN periodos px ON px.id = @periodo
                   WHERE NOT (px.numero >= pd.numero AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))))
GROUP BY n.id, n.nombre, g.numero, g.nombre_display, s.nombre
ORDER BY n.id, g.numero, s.nombre;


-- ============ B) DETALLE POR CRITERIO: a quien se le pide ==================
-- Una fila por criterio pendiente, con el docente que puede resolverlo y
-- cuantos companeros ya tienen nota (contexto de cuanto falta de ese criterio).
SET @periodo := 2;

SELECT
    n.nombre         AS nivel,
    g.nombre_display AS grado,
    s.nombre         AS seccion,
    a.nombre         AS area,
    CONCAT(pd.apellido_paterno, ' ', pd.apellido_materno, ', ', pd.nombres) AS docente,
    cr.carga_id,
    comp.nombre_completo AS competencia,
    cr.nombre            AS criterio,
    COUNT(*)             AS alumnos_sin_nota,
    (SELECT COUNT(*) FROM calificaciones_criterio cc3
      WHERE cc3.criterio_id = cr.id) AS companeros_con_nota
FROM matriculas m
INNER JOIN secciones s          ON s.id  = m.seccion_id
INNER JOIN grados g             ON g.id  = s.grado_id
INNER JOIN niveles n            ON n.id  = g.nivel_id
INNER JOIN cargas_academicas ca ON ca.seccion_id = m.seccion_id
                               AND ca.estado     = 'activa'
INNER JOIN usuarios u           ON u.id  = ca.docente_id
INNER JOIN personas pd          ON pd.id = u.persona_id
INNER JOIN criterios cr         ON cr.carga_id       = ca.id
                               AND cr.periodo_id     = @periodo
                               AND cr.eliminado_en   IS NULL
                               AND cr.extraordinario = 0
INNER JOIN competencias comp    ON comp.id = cr.competencia_id
LEFT  JOIN subareas sa          ON sa.id   = comp.subarea_id
INNER JOIN areas a              ON a.id    = COALESCE(sa.area_id, comp.area_id)
WHERE m.tipo NOT IN ('trasladado', 'retirado')
  AND (a.tipo NOT IN ('transversal', 'tutoria')
       OR a.nombre_boleta = CONVERT('Ética y Valores' USING utf8mb4) COLLATE utf8mb4_unicode_ci)
  AND EXISTS (SELECT 1 FROM calificaciones_criterio cc2 WHERE cc2.criterio_id = cr.id)
  AND NOT EXISTS (SELECT 1 FROM calificaciones_criterio cc
                  WHERE cc.criterio_id = cr.id AND cc.matricula_id = m.id)
  AND NOT EXISTS (SELECT 1 FROM omisiones_criterio oc
                  WHERE oc.criterio_id = cr.id AND oc.matricula_id = m.id)
  AND NOT EXISTS (SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = m.id AND ex.area_id = a.id
                    AND ex.revocado_en IS NULL)
  -- Anclaje de retorno por bimestre: el TRAMO (= RetornoGradoModel::sqlCursoElPeriodo)
  AND m.id NOT IN (SELECT r.matricula_oficial_id FROM retornos_grado r
                   JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                   JOIN periodos px ON px.id = @periodo
                   WHERE (px.numero >= pd.numero AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))))
  AND m.id NOT IN (SELECT r.matricula_operativa_id FROM retornos_grado r
                   JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                   JOIN periodos px ON px.id = @periodo
                   WHERE NOT (px.numero >= pd.numero AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))))
GROUP BY n.id, n.nombre, g.numero, g.nombre_display, s.nombre, a.nombre,
         docente, cr.carga_id, cr.id, comp.nombre_completo, cr.nombre
ORDER BY alumnos_sin_nota DESC, n.id, g.numero, s.nombre;


-- ============ C) DETALLE POR ALUMNO ========================================
-- Para hablar con la familia o con el tutor: que le falta exactamente a quien.
SET @periodo := 2;

SELECT
    n.nombre         AS nivel,
    g.nombre_display AS grado,
    s.nombre         AS seccion,
    CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS alumno,
    m.id             AS matricula_id,
    m.tipo,
    COUNT(*)         AS blancos
FROM matriculas m
INNER JOIN secciones s          ON s.id  = m.seccion_id
INNER JOIN grados g             ON g.id  = s.grado_id
INNER JOIN niveles n            ON n.id  = g.nivel_id
INNER JOIN estudiantes e        ON e.id  = m.estudiante_id
INNER JOIN personas p           ON p.id  = e.persona_id
INNER JOIN cargas_academicas ca ON ca.seccion_id = m.seccion_id
                               AND ca.estado     = 'activa'
INNER JOIN criterios cr         ON cr.carga_id       = ca.id
                               AND cr.periodo_id     = @periodo
                               AND cr.eliminado_en   IS NULL
                               AND cr.extraordinario = 0
INNER JOIN competencias comp    ON comp.id = cr.competencia_id
LEFT  JOIN subareas sa          ON sa.id   = comp.subarea_id
INNER JOIN areas a              ON a.id    = COALESCE(sa.area_id, comp.area_id)
WHERE m.tipo NOT IN ('trasladado', 'retirado')
  AND (a.tipo NOT IN ('transversal', 'tutoria')
       OR a.nombre_boleta = CONVERT('Ética y Valores' USING utf8mb4) COLLATE utf8mb4_unicode_ci)
  AND EXISTS (SELECT 1 FROM calificaciones_criterio cc2 WHERE cc2.criterio_id = cr.id)
  AND NOT EXISTS (SELECT 1 FROM calificaciones_criterio cc
                  WHERE cc.criterio_id = cr.id AND cc.matricula_id = m.id)
  AND NOT EXISTS (SELECT 1 FROM omisiones_criterio oc
                  WHERE oc.criterio_id = cr.id AND oc.matricula_id = m.id)
  AND NOT EXISTS (SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = m.id AND ex.area_id = a.id
                    AND ex.revocado_en IS NULL)
  -- Anclaje de retorno por bimestre: el TRAMO (= RetornoGradoModel::sqlCursoElPeriodo)
  AND m.id NOT IN (SELECT r.matricula_oficial_id FROM retornos_grado r
                   JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                   JOIN periodos px ON px.id = @periodo
                   WHERE (px.numero >= pd.numero AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))))
  AND m.id NOT IN (SELECT r.matricula_operativa_id FROM retornos_grado r
                   JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                   JOIN periodos px ON px.id = @periodo
                   WHERE NOT (px.numero >= pd.numero AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))))
GROUP BY n.id, n.nombre, g.numero, g.nombre_display, s.nombre, alumno, m.id, m.tipo
ORDER BY blancos DESC, n.id, g.numero, s.nombre, alumno;


-- ============ D) TOTAL (una cifra, para comparar con el metodo PHP) ========
SET @periodo := 2;

SELECT COUNT(DISTINCT m.id) AS alumnos_afectados, COUNT(*) AS blancos
FROM matriculas m
INNER JOIN secciones s          ON s.id  = m.seccion_id
INNER JOIN cargas_academicas ca ON ca.seccion_id = m.seccion_id
                               AND ca.estado     = 'activa'
INNER JOIN criterios cr         ON cr.carga_id       = ca.id
                               AND cr.periodo_id     = @periodo
                               AND cr.eliminado_en   IS NULL
                               AND cr.extraordinario = 0
INNER JOIN competencias comp    ON comp.id = cr.competencia_id
LEFT  JOIN subareas sa          ON sa.id   = comp.subarea_id
INNER JOIN areas a              ON a.id    = COALESCE(sa.area_id, comp.area_id)
WHERE m.tipo NOT IN ('trasladado', 'retirado')
  AND (a.tipo NOT IN ('transversal', 'tutoria')
       OR a.nombre_boleta = CONVERT('Ética y Valores' USING utf8mb4) COLLATE utf8mb4_unicode_ci)
  AND EXISTS (SELECT 1 FROM calificaciones_criterio cc2 WHERE cc2.criterio_id = cr.id)
  AND NOT EXISTS (SELECT 1 FROM calificaciones_criterio cc
                  WHERE cc.criterio_id = cr.id AND cc.matricula_id = m.id)
  AND NOT EXISTS (SELECT 1 FROM omisiones_criterio oc
                  WHERE oc.criterio_id = cr.id AND oc.matricula_id = m.id)
  AND NOT EXISTS (SELECT 1 FROM exoneraciones ex
                  WHERE ex.matricula_id = m.id AND ex.area_id = a.id
                    AND ex.revocado_en IS NULL)
  -- Anclaje de retorno por bimestre: el TRAMO (= RetornoGradoModel::sqlCursoElPeriodo)
  AND m.id NOT IN (SELECT r.matricula_oficial_id FROM retornos_grado r
                   JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                   JOIN periodos px ON px.id = @periodo
                   WHERE (px.numero >= pd.numero AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))))
  AND m.id NOT IN (SELECT r.matricula_operativa_id FROM retornos_grado r
                   JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                   JOIN periodos px ON px.id = @periodo
                   WHERE NOT (px.numero >= pd.numero AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))));
