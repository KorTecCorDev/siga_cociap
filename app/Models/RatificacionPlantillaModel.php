<?php

namespace App\Models;

/**
 * RatificacionPlantillaModel — cuerpo del documento de RATIFICACIÓN por año y
 * tipo (migración 080, 14/10/2026). Ver docs/modulos/ratificaciones.md.
 *
 * Enfoque D: el esqueleto del documento (membrete, datos del estudiante, motivo,
 * firma, QR) es fijo en la vista; lo que el colegio edita cada año es una lista
 * de BLOQUES: párrafo, tabla, imagen o salto de página. Nunca se guarda HTML.
 *
 * 🔴 PUNTO ÚNICO de tres reglas:
 *  1. VERSIONES. Hay una plantilla por (año, tipo, versión); la vigente es la de
 *     versión más alta. Emitir la CONGELA (`congelada_en`, Fase 3). Guardar sobre
 *     una congelada crea la versión siguiente, así las emisiones anteriores
 *     siguen apuntando a la suya; sobre una no congelada, la reescribe.
 *  2. VALIDACIÓN de bloques (`validarBloques()`), la misma que repite el JS.
 *  3. RENDER del párrafo (`renderParrafo()`): escapa PRIMERO y solo después
 *     aplica `**negrita**` y las variables, así ningún dato produce HTML.
 */
class RatificacionPlantillaModel extends BaseModel
{
    protected string $table = 'ratificacion_plantillas';

    public const TIPOS = ['ratificado' => 'Ratificado', 'no_ratificado' => 'No ratificado'];

    public const BLOQUES = [
        'parrafo' => 'Párrafo',
        'tabla'   => 'Tabla',
        'imagen'  => 'Imagen',
        'salto'   => 'Salto de página',
    ];

    public const TAMANOS = ['pequena' => 'Pequeña', 'media' => 'Mediana', 'completa' => 'Ancho completo'];

    /** Variables del párrafo => qué escriben. */
    public const VARIABLES = [
        'estudiante'     => 'apellidos y nombres',
        'dni'            => 'DNI',
        'grado'          => 'grado',
        'seccion'        => 'sección',
        'nivel'          => 'nivel',
        'anio'           => 'año académico',
        'anio_siguiente' => 'año siguiente',
        // El RESPONSABLE (`vinculo_familiar.es_responsable = 1`, del estudiante,
        // no de la matrícula): hay uno solo y siempre existe. Se lee al imprimir,
        // no se congela en la emisión (decisión del usuario, 14/10/2026).
        'apoderado'      => 'apoderado responsable',
        'apoderado_dni'  => 'DNI del apoderado',
        'parentesco'     => 'parentesco del apoderado',
    ];

    public const TITULO_MIN  = 3;
    public const TITULO_MAX  = 150;
    public const PARRAFO_MAX = 5000;
    public const BLOQUES_MAX = 40;
    public const TABLA_FILAS_MAX    = 30;
    public const TABLA_COLUMNAS_MAX = 8;
    public const CELDA_MAX   = 300;

    /** Nombre de archivo de una imagen subida (lo genera el controlador). */
    public const PATRON_IMAGEN = '/^[A-Za-z0-9_-]+\.(png|jpg)$/';

    /** Año por id con su lema (membrete del documento), o null. */
    public function anio(int $anioId): ?array
    {
        return $this->queryOne(
            "SELECT id, anio, estado, lema_oficial FROM anios_academicos WHERE id = ?",
            [$anioId]
        );
    }

    /** Versión vigente (la más alta) del año y tipo, o null si no hay ninguna. */
    public function vigente(int $anioId, string $tipo): ?array
    {
        return $this->queryOne("
            SELECT p.*,
                   (SELECT COUNT(*) FROM ratificacion_emisiones e WHERE e.plantilla_id = p.id) AS emisiones
            FROM ratificacion_plantillas p
            WHERE p.anio_id = ? AND p.tipo = ?
            ORDER BY p.version DESC
            LIMIT 1
        ", [$anioId, $tipo]);
    }

    /** Plantilla por id (cualquier versión), o null. */
    public function plantilla(int $id): ?array
    {
        return $this->queryOne("SELECT * FROM ratificacion_plantillas WHERE id = ?", [$id]);
    }

    /** Bloques de una plantilla, en orden. */
    public function bloques(int $plantillaId): array
    {
        return $this->query("
            SELECT id, orden, tipo, texto, tabla_json, imagen_archivo, imagen_tamano
            FROM ratificacion_bloques
            WHERE plantilla_id = ?
            ORDER BY orden
        ", [$plantillaId]);
    }

    /**
     * Huella del contenido (título + bloques ya normalizados). Sirve para dos
     * cosas: saber si un guardado no cambia nada y detectar que otra persona
     * guardó la misma plantilla mientras se editaba.
     */
    public static function huella(string $titulo, array $bloques): string
    {
        $norm = array_map(static fn(array $b): array => [
            (string) $b['tipo'],
            $b['texto'] ?? null,
            $b['tabla_json'] ?? null,
            $b['imagen_archivo'] ?? null,
            $b['imagen_tamano'] ?? null,
        ], array_values($bloques));
        return hash('sha256', json_encode([$titulo, $norm], JSON_UNESCAPED_UNICODE));
    }

    /** Huella de la versión vigente ('' si no hay plantilla). */
    public function huellaVigente(int $anioId, string $tipo): string
    {
        $p = $this->vigente($anioId, $tipo);
        return $p === null ? '' : self::huella((string) $p['titulo'], $this->bloques((int) $p['id']));
    }

    /**
     * Valida y normaliza lo que llega del editor. Cada bloque crudo trae `tipo`
     * y su dato: `texto`, `tabla_json` o `imagen_archivo` + `imagen_tamano`.
     * Devuelve los bloques limpios, listos para `guardar()`.
     *
     * @throws \DomainException con un mensaje para la pantalla.
     */
    public function validarBloques(string $titulo, array $crudos): array
    {
        $titulo = trim($titulo);
        $largo  = mb_strlen($titulo);
        if ($largo < self::TITULO_MIN || $largo > self::TITULO_MAX) {
            throw new \DomainException('El título debe tener entre ' . self::TITULO_MIN
                . ' y ' . self::TITULO_MAX . ' caracteres.');
        }
        if ($crudos === []) {
            throw new \DomainException('Agrega al menos un bloque al documento.');
        }
        if (count($crudos) > self::BLOQUES_MAX) {
            throw new \DomainException('El documento no puede tener más de ' . self::BLOQUES_MAX . ' bloques.');
        }

        $limpios = [];
        $n = 0;
        foreach ($crudos as $b) {
            $n++;
            $tipo = is_array($b) ? (string) ($b['tipo'] ?? '') : '';
            if (!isset(self::BLOQUES[$tipo])) {
                throw new \DomainException("El bloque {$n} no es válido.");
            }
            $limpio = ['tipo' => $tipo, 'texto' => null, 'tabla_json' => null,
                       'imagen_archivo' => null, 'imagen_tamano' => null];

            if ($tipo === 'parrafo') {
                $limpio['texto'] = $this->validarParrafo((string) ($b['texto'] ?? ''), $n);
            } elseif ($tipo === 'tabla') {
                $limpio['tabla_json'] = $this->validarTabla((string) ($b['tabla_json'] ?? ''), $n);
            } elseif ($tipo === 'imagen') {
                $archivo = (string) ($b['imagen_archivo'] ?? '');
                if (!preg_match(self::PATRON_IMAGEN, $archivo)) {
                    throw new \DomainException("Elige la imagen del bloque {$n}.");
                }
                $tamano = (string) ($b['imagen_tamano'] ?? '');
                if (!isset(self::TAMANOS[$tamano])) {
                    throw new \DomainException("Elige el tamaño de la imagen del bloque {$n}.");
                }
                $limpio['imagen_archivo'] = $archivo;
                $limpio['imagen_tamano']  = $tamano;
            }
            $limpios[] = $limpio;
        }
        return ['titulo' => $titulo, 'bloques' => $limpios];
    }

    private function validarParrafo(string $texto, int $n): string
    {
        // Saltos de línea de Windows → \n, para que la huella no dependa del navegador.
        $texto = trim(str_replace("\r\n", "\n", $texto));
        if ($texto === '') {
            throw new \DomainException("Escribe el texto del párrafo {$n}.");
        }
        if (mb_strlen($texto) > self::PARRAFO_MAX) {
            throw new \DomainException("El párrafo {$n} no debe superar " . self::PARRAFO_MAX . ' caracteres.');
        }
        preg_match_all('/\{([^{}\s]*)\}/u', $texto, $m);
        $desconocidas = array_diff(array_unique($m[1]), array_keys(self::VARIABLES));
        if ($desconocidas !== []) {
            throw new \DomainException("El párrafo {$n} usa una variable que no existe: {"
                . implode('}, {', $desconocidas) . '}.');
        }
        return $texto;
    }

    /** Tabla = lista de filas de celdas de texto; la primera es la cabecera. */
    private function validarTabla(string $json, int $n): string
    {
        $filas = json_decode($json, true);
        if (!is_array($filas) || $filas === [] || !array_is_list($filas)) {
            throw new \DomainException("La tabla del bloque {$n} está vacía.");
        }
        if (count($filas) > self::TABLA_FILAS_MAX) {
            throw new \DomainException("La tabla del bloque {$n} no puede tener más de " . self::TABLA_FILAS_MAX . ' filas.');
        }
        $columnas = null;
        $conTexto = false;
        $limpia   = [];
        foreach ($filas as $fila) {
            if (!is_array($fila) || $fila === [] || !array_is_list($fila)) {
                throw new \DomainException("La tabla del bloque {$n} no es válida.");
            }
            $columnas ??= count($fila);
            if (count($fila) !== $columnas) {
                throw new \DomainException("Todas las filas de la tabla del bloque {$n} deben tener las mismas columnas.");
            }
            if ($columnas > self::TABLA_COLUMNAS_MAX) {
                throw new \DomainException("La tabla del bloque {$n} no puede tener más de " . self::TABLA_COLUMNAS_MAX . ' columnas.');
            }
            $celdas = [];
            foreach ($fila as $celda) {
                if (!is_string($celda) && !is_int($celda) && !is_float($celda)) {
                    throw new \DomainException("La tabla del bloque {$n} no es válida.");
                }
                $celda = trim((string) $celda);
                if (mb_strlen($celda) > self::CELDA_MAX) {
                    throw new \DomainException("Cada celda de la tabla del bloque {$n} admite hasta " . self::CELDA_MAX . ' caracteres.');
                }
                $conTexto = $conTexto || $celda !== '';
                $celdas[] = $celda;
            }
            $limpia[] = $celdas;
        }
        if (!$conTexto) {
            throw new \DomainException("La tabla del bloque {$n} está vacía.");
        }
        return json_encode($limpia, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Guarda la plantilla del año y tipo con los bloques YA validados.
     *
     *  - Sin plantilla: crea la versión 1.
     *  - Vigente NO congelada: la reescribe (título y bloques).
     *  - Vigente congelada (ya se emitió con ella): crea la versión siguiente.
     *
     * `$huellaEsperada` es la huella que tenía la vigente al abrir el editor
     * ('' si no había): si otra persona guardó entretanto, no se pisa su trabajo.
     *
     * @return bool false si no había cambios (no escribe nada).
     * @throws \DomainException
     */
    public function guardar(int $anioId, string $tipo, array $validado, int $userId, string $huellaEsperada): bool
    {
        if (!isset(self::TIPOS[$tipo])) {
            throw new \DomainException('Tipo de documento no válido.');
        }
        $titulo  = (string) $validado['titulo'];
        $bloques = (array) $validado['bloques'];

        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            // Bloquea la vigente: emitir (Fase 3) la congela bajo el mismo candado.
            $vigente = $this->queryOne("
                SELECT id, version, titulo, congelada_en
                FROM ratificacion_plantillas
                WHERE anio_id = ? AND tipo = ?
                ORDER BY version DESC
                LIMIT 1
                FOR UPDATE
            ", [$anioId, $tipo]);

            $huellaActual = $vigente === null
                ? '' : self::huella((string) $vigente['titulo'], $this->bloques((int) $vigente['id']));
            if (!hash_equals($huellaActual, $huellaEsperada)) {
                throw new \DomainException('La plantilla cambió mientras la editabas. Vuelve a abrirla y repite tus cambios.');
            }
            if ($huellaActual !== '' && hash_equals($huellaActual, self::huella($titulo, $bloques))) {
                if ($propia) {
                    $this->commit();
                }
                return false;
            }

            if ($vigente !== null && $vigente['congelada_en'] === null) {
                $plantillaId = (int) $vigente['id'];
                $this->execute("UPDATE ratificacion_plantillas SET titulo = ? WHERE id = ?", [$titulo, $plantillaId]);
                $this->execute("DELETE FROM ratificacion_bloques WHERE plantilla_id = ?", [$plantillaId]);
            } else {
                $version = $vigente === null ? 1 : (int) $vigente['version'] + 1;
                $plantillaId = $this->insertarPlantilla($anioId, $tipo, $version, $titulo, $userId);
            }
            $this->insertarBloques($plantillaId, $bloques);

            if ($propia) {
                $this->commit();
            }
        } catch (\PDOException $e) {
            if ($propia) {
                $this->rollback();
            }
            // Dos primeras versiones a la vez: el UNIQUE (año, tipo, versión) frena a una.
            if ($e->getCode() === '23000') {
                throw new \DomainException('La plantilla cambió mientras la editabas. Vuelve a abrirla y repite tus cambios.');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($propia) {
                $this->rollback();
            }
            throw $e;
        }
        return true;
    }

    /**
     * Copia como versión 1 la plantilla vigente del MISMO tipo del año anterior
     * (por número de año, nunca por id). Solo si este año aún no tiene ninguna.
     * Las imágenes se comparten: los archivos nunca se borran.
     *
     * @throws \DomainException
     */
    public function duplicarDelAnioAnterior(int $anioId, string $tipo, int $userId): void
    {
        if (!isset(self::TIPOS[$tipo])) {
            throw new \DomainException('Tipo de documento no válido.');
        }
        $origen = $this->plantillaDelAnioAnterior($anioId, $tipo);
        if ($origen === null) {
            throw new \DomainException('No hay una plantilla del año anterior para copiar.');
        }

        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            $existe = $this->queryOne(
                "SELECT id FROM ratificacion_plantillas WHERE anio_id = ? AND tipo = ? LIMIT 1 FOR UPDATE",
                [$anioId, $tipo]
            );
            if ($existe !== null) {
                throw new \DomainException('Este año ya tiene su plantilla; edítala directamente.');
            }
            $nuevaId = $this->insertarPlantilla($anioId, $tipo, 1, (string) $origen['titulo'], $userId);
            $this->insertarBloques($nuevaId, $this->bloques((int) $origen['id']));
            if ($propia) {
                $this->commit();
            }
        } catch (\Throwable $e) {
            if ($propia) {
                $this->rollback();
            }
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw new \DomainException('Este año ya tiene su plantilla; edítala directamente.');
            }
            throw $e;
        }
    }

    /** Vigente del mismo tipo en el año anterior (`anio - 1`), con su año; o null. */
    public function plantillaDelAnioAnterior(int $anioId, string $tipo): ?array
    {
        return $this->queryOne("
            SELECT p.id, p.titulo, p.version, ant.anio
            FROM anios_academicos act
            JOIN anios_academicos ant ON ant.anio = act.anio - 1
            JOIN ratificacion_plantillas p ON p.anio_id = ant.id AND p.tipo = ?
            WHERE act.id = ?
            ORDER BY p.version DESC
            LIMIT 1
        ", [$tipo, $anioId]);
    }

    private function insertarPlantilla(int $anioId, string $tipo, int $version, string $titulo, int $userId): int
    {
        $this->execute("
            INSERT INTO ratificacion_plantillas (anio_id, tipo, version, titulo, creado_por)
            VALUES (?, ?, ?, ?, ?)
        ", [$anioId, $tipo, $version, $titulo, $userId]);
        return (int) $this->db->lastInsertId();
    }

    private function insertarBloques(int $plantillaId, array $bloques): void
    {
        $orden = 0;
        foreach ($bloques as $b) {
            $this->execute("
                INSERT INTO ratificacion_bloques
                    (plantilla_id, orden, tipo, texto, tabla_json, imagen_archivo, imagen_tamano)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [$plantillaId, ++$orden, $b['tipo'], $b['texto'] ?? null, $b['tabla_json'] ?? null,
                $b['imagen_archivo'] ?? null, $b['imagen_tamano'] ?? null]);
        }
    }

    // ── Documento ───────────────────────────────────────────────

    /**
     * Bloques listos para la vista del documento: el párrafo con su `html`
     * (seguro, ver `renderParrafo()`) y la tabla con sus `filas`.
     *
     * @param array<string,string> $vars valores de VARIABLES
     */
    public function bloquesParaDocumento(int $plantillaId, array $vars): array
    {
        $salida = [];
        foreach ($this->bloques($plantillaId) as $b) {
            if ($b['tipo'] === 'parrafo') {
                $b['html'] = self::renderParrafo((string) $b['texto'], $vars);
            } elseif ($b['tipo'] === 'tabla') {
                $b['filas'] = json_decode((string) $b['tabla_json'], true) ?: [];
            }
            $salida[] = $b;
        }
        return $salida;
    }

    /**
     * Párrafo → HTML. Escapa PRIMERO todo el texto; después convierte
     * `**negrita**` en <strong> y reemplaza cada {variable} por su valor
     * escapado. Ningún texto de la plantilla ni dato del estudiante puede
     * producir etiquetas. Los saltos de línea se respetan.
     *
     * @param array<string,string> $vars
     */
    public static function renderParrafo(string $texto, array $vars): string
    {
        $html = e($texto);
        $html = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);
        $html = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($vars): string {
            return array_key_exists($m[1], $vars) ? e((string) $vars[$m[1]]) : $m[0];
        }, $html);
        return nl2br($html, false);
    }

    /** Datos de ejemplo de la vista previa. */
    public static function varsEjemplo(int $anio): array
    {
        return [
            'estudiante'     => 'APELLIDO APELLIDO, NOMBRES DE EJEMPLO',
            'dni'            => '00000000',
            'grado'          => '3.°',
            'seccion'        => 'A',
            'nivel'          => 'Secundaria',
            'anio'           => (string) $anio,
            'anio_siguiente' => (string) ($anio + 1),
            'apoderado'      => 'APELLIDO APELLIDO, NOMBRES DEL APODERADO',
            'apoderado_dni'  => '00000000',
            'parentesco'     => 'Madre',
        ];
    }
}
