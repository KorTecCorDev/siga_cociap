<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\DirectorEbrModel;
use App\Models\RatificacionModel;
use App\Models\RatificacionPlantillaModel;
use Core\Session;
use Core\View;

/**
 * RATIFICACIÓN DE MATRÍCULA — plantillas del documento por bloques (migración
 * 080, 14/10/2026). Ver docs/modulos/ratificaciones.md.
 *
 * Mismo acceso mixto que `Admin\RatificacionController`: el constructor admite
 * `RatificacionModel::ROLES_VEN` (Dirección en SOLO LECTURA) y cada método de
 * escritura empieza con `requireRole(RatificacionModel::ROLES_REGISTRAN)` + CSRF.
 * Lo vigila `verif_ratificaciones.php`.
 *
 * Las reglas (versiones, validación, render) viven en el modelo; aquí se lee la
 * petición, se reciben las imágenes y se responde.
 */
class RatificacionPlantillaController extends BaseController
{
    private const IMAGEN_MAX_BYTES = 2 * 1024 * 1024;

    private RatificacionModel $ratificaciones;
    private RatificacionPlantillaModel $model;

    public function __construct()
    {
        $this->requireRole(RatificacionModel::ROLES_VEN);
        $this->ratificaciones = new RatificacionModel();
        $this->model          = new RatificacionPlantillaModel();
    }

    // GET /admin/ratificaciones/plantillas?anio_id=&tipo=
    public function index(): void
    {
        $anios = $this->ratificaciones->listarAnios();
        $anio  = $this->anioElegido($anios);
        $tipo  = $this->tipoElegido((string) $this->query('tipo', 'ratificado'));

        $plantilla = null;
        $bloques   = [];
        $anterior  = null;
        if ($anio !== null) {
            $plantilla = $this->model->vigente((int) $anio['id'], $tipo);
            if ($plantilla !== null) {
                $bloques = $this->model->bloquesParaDocumento(
                    (int) $plantilla['id'],
                    RatificacionPlantillaModel::varsEjemplo((int) $anio['anio'])
                );
            } else {
                $anterior = $this->model->plantillaDelAnioAnterior((int) $anio['id'], $tipo);
            }
        }

        $this->view('admin/ratificaciones/plantillas', [
            'titulo'        => 'Plantillas del documento de ratificación',
            'anios'         => $anios,
            'anio'          => $anio,
            'tipo'          => $tipo,
            'plantilla'     => $plantilla,
            'bloques'       => $bloques,
            'huella'        => $plantilla !== null
                ? RatificacionPlantillaModel::huella((string) $plantilla['titulo'], $bloques) : '',
            'anterior'      => $anterior,
            'puedeEscribir' => has_role(RatificacionModel::ROLES_REGISTRAN),
            'page_scripts'  => ['ratificacion-plantilla'],
        ]);
    }

    // POST /admin/ratificaciones/plantillas/guardar
    public function guardar(): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $anio = $this->ratificaciones->anio((int) $this->input('anio_id', 0));
        $tipo = (string) $this->input('tipo', '');
        if ($anio === null || !isset(RatificacionPlantillaModel::TIPOS[$tipo])) {
            $this->notFound();
        }
        $volver = url('admin/ratificaciones/plantillas?anio_id=' . (int) $anio['id'] . '&tipo=' . $tipo);

        $crudos = $this->input('bloques', []);
        $crudos = is_array($crudos) ? array_values($this->conImagenes($crudos, $pendientes)) : [];

        $movidas = [];
        try {
            if ($pendientes instanceof \DomainException) {
                throw $pendientes;
            }
            $validado = $this->model->validarBloques((string) $this->input('titulo', ''), $crudos);
            $movidas  = $this->moverImagenes($pendientes);
            $cambio   = $this->model->guardar(
                (int) $anio['id'], $tipo, $validado,
                (int) Session::user()['id'], (string) $this->input('huella', '')
            );
        } catch (\DomainException $e) {
            $this->descartarImagenes($movidas);
            $this->redirectWithError($volver, $e->getMessage());
        } catch (\Throwable $e) {
            $this->descartarImagenes($movidas);
            throw $e;
        }

        $this->redirectWithSuccess($volver, $cambio ? 'Plantilla guardada.' : 'No había cambios que guardar.');
    }

    // POST /admin/ratificaciones/plantillas/duplicar
    public function duplicar(): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $anio = $this->ratificaciones->anio((int) $this->input('anio_id', 0));
        $tipo = (string) $this->input('tipo', '');
        if ($anio === null || !isset(RatificacionPlantillaModel::TIPOS[$tipo])) {
            $this->notFound();
        }
        $volver = url('admin/ratificaciones/plantillas?anio_id=' . (int) $anio['id'] . '&tipo=' . $tipo);

        try {
            $this->model->duplicarDelAnioAnterior((int) $anio['id'], $tipo, (int) Session::user()['id']);
        } catch (\DomainException $e) {
            $this->redirectWithError($volver, $e->getMessage());
        }
        $this->redirectWithSuccess($volver, 'Plantilla copiada. Revísala antes de emitir documentos.');
    }

    // GET /admin/ratificaciones/plantillas/{id}/vista-previa
    public function vistaPrevia(string $id): void
    {
        $plantilla = $this->model->plantilla((int) $id);
        if ($plantilla === null) {
            $this->notFound();
        }
        $anio = $this->model->anio((int) $plantilla['anio_id']);
        $vars = RatificacionPlantillaModel::varsEjemplo((int) $anio['anio']);
        $datos = config('institucion_datos') ?? [];

        View::setLayout('print');
        $this->view('admin/ratificaciones/documento', [
            'titulo'      => 'Vista previa — ' . $plantilla['titulo'],
            'vistaPrevia' => true,
            'plantilla'   => $plantilla,
            'bloques'     => $this->model->bloquesParaDocumento((int) $plantilla['id'], $vars),
            'vars'        => $vars,
            'lema'        => (string) ($anio['lema_oficial'] ?? ''),
            'institucion' => $datos,
            'codModular'  => (string) ($datos['codigo_modular_secundaria'] ?? ''),
            'motivoTexto' => $plantilla['tipo'] === 'no_ratificado'
                ? 'Aquí va el texto oficial del motivo elegido para el estudiante.' : null,
            'detalleTexto' => null,
            'fecha'       => date('Y-m-d'),
            // El nombre real del Director para juzgar el diseño; la firma y el
            // sello no salen en la vista previa (`documento.php`).
            'directorEbr' => (new DirectorEbrModel())->getVigenteEnFecha((int) $plantilla['anio_id'], date('Y-m-d')),
        ]);
    }

    // GET /admin/ratificaciones/plantillas/imagen/{archivo}
    public function imagen(string $archivo): void
    {
        // Mismo blindaje que FirmaController (nombre estricto + realpath dentro
        // de la base), pero CON login (constructor) y PNG o JPEG.
        if (!preg_match(RatificacionPlantillaModel::PATRON_IMAGEN, $archivo)) {
            $this->notFound();
        }
        $base     = realpath((string) config('ratificacion_img_path'));
        $real     = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . $archivo);
        if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
            $this->notFound();
        }

        header('Content-Type: ' . (str_ends_with($archivo, '.png') ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($real);
    }

    // ── Apoyo ───────────────────────────────────────────────────

    /**
     * Resuelve la imagen de cada bloque de tipo imagen: la NUEVA subida (archivo
     * `imagen[clave]`, se valida aquí y se mueve después) o la que ya tenía
     * (`imagen_actual`, debe existir en la carpeta). Deja en `$pendientes` las
     * subidas por mover, o la DomainException del primer archivo inválido.
     */
    private function conImagenes(array $crudos, mixed &$pendientes): array
    {
        $pendientes = [];
        $files = $_FILES['imagen'] ?? null;
        $dir   = (string) config('ratificacion_img_path');
        $n = 0;
        foreach ($crudos as $clave => $b) {
            $n++;
            if (!is_array($b) || ($b['tipo'] ?? '') !== 'imagen') {
                continue;
            }
            $error = is_array($files['error'] ?? null) ? ($files['error'][$clave] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                $actual = (string) ($b['imagen_actual'] ?? '');
                $existe = preg_match(RatificacionPlantillaModel::PATRON_IMAGEN, $actual)
                    && is_file($dir . '/' . $actual);
                $crudos[$clave]['imagen_archivo'] = $existe ? $actual : '';
                continue;
            }
            try {
                $ext = $this->validarSubida(
                    (int) $error, (string) $files['tmp_name'][$clave], (int) $files['size'][$clave], $n
                );
            } catch (\DomainException $e) {
                $pendientes = $e;
                return $crudos;
            }
            $nombre = 'ratif_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $crudos[$clave]['imagen_archivo'] = $nombre;
            $pendientes[] = ['tmp' => (string) $files['tmp_name'][$clave], 'nombre' => $nombre];
        }
        return $crudos;
    }

    /** Valida una imagen subida (por contenido, no por extensión); devuelve png|jpg. */
    private function validarSubida(int $error, string $tmp, int $tamano, int $n): string
    {
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || $tamano > self::IMAGEN_MAX_BYTES) {
            throw new \DomainException("La imagen del bloque {$n} no debe superar 2 MB.");
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            log_error("Ratificación: subida de imagen fallida (código {$error}).");
            throw new \DomainException("No se pudo recibir la imagen del bloque {$n}. Vuelve a intentarlo.");
        }
        $info = @\getimagesize($tmp);
        $tipo = $info[2] ?? null;
        if ($tipo !== IMAGETYPE_PNG && $tipo !== IMAGETYPE_JPEG) {
            throw new \DomainException("La imagen del bloque {$n} debe ser PNG o JPG.");
        }
        return $tipo === IMAGETYPE_PNG ? 'png' : 'jpg';
    }

    /** Mueve las subidas validadas a la carpeta de imágenes; devuelve sus rutas. */
    private function moverImagenes(array $pendientes): array
    {
        $dir = rtrim((string) config('ratificacion_img_path'), '/');
        if ($pendientes !== [] && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $movidas = [];
        foreach ($pendientes as $p) {
            $destino = $dir . '/' . $p['nombre'];
            if (!move_uploaded_file($p['tmp'], $destino)) {
                $this->descartarImagenes($movidas);
                log_error("Ratificación: no se pudo mover la imagen a {$destino}.");
                throw new \DomainException('No se pudo guardar una de las imágenes. Vuelve a intentarlo.');
            }
            $movidas[] = $destino;
        }
        return $movidas;
    }

    /**
     * Borra SOLO las imágenes que esta misma petición acaba de mover, cuando el
     * guardado no llegó a confirmarse (nadie más las referencia todavía).
     */
    private function descartarImagenes(array $movidas): void
    {
        foreach ($movidas as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    private function tipoElegido(string $tipo): string
    {
        return isset(RatificacionPlantillaModel::TIPOS[$tipo]) ? $tipo : 'ratificado';
    }

    /** Año pedido por `?anio_id=`, o el activo, o el más reciente (como RatificacionController). */
    private function anioElegido(array $anios): ?array
    {
        $pedido = (int) $this->query('anio_id', 0);
        foreach ($anios as $a) {
            if ($pedido > 0 && (int) $a['id'] === $pedido) {
                return $a;
            }
        }
        if ($pedido > 0) {
            $this->notFound();
        }
        foreach ($anios as $a) {
            if ($a['estado'] === 'activo') {
                return $a;
            }
        }
        return $anios[0] ?? null;
    }
}
