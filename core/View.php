<?php

namespace Core;

/**
 * View
 * Renderizador de vistas PHP con soporte de layouts.
 * Equivalente al sistema Blade de Laravel (sin directivas, PHP puro).
 */
class View
{
    private static string $layout   = 'app';
    private static array  $sections = [];
    private static string $currentSection = '';

    /**
     * Renderiza una vista con su layout.
     * Uso: View::render('auth/login', ['titulo' => 'Iniciar sesión'])
     */
    public static function render(string $view, array $data = []): void
    {
        // Extraer variables para que estén disponibles en la vista
        extract($data, EXTR_SKIP);

        // Capturar el contenido de la vista
        ob_start();
        $viewFile = VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewFile)) {
            throw new \RuntimeException("Vista [{$view}] no encontrada en: {$viewFile}");
        }
        require $viewFile;
        $content = ob_get_clean();

        // Renderizar el layout con el contenido
        $layoutFile = VIEW_PATH . '/layouts/' . self::$layout . '.php';

        // Una página completa del layout de la app es una «pantalla»: se anota
        // como el lugar al que volver si la sesión vence (06/10/2026).
        if (self::$layout === 'app') {
            Session::recordarPantalla();
        }

        if (file_exists($layoutFile)) {
            extract(['content' => $content] + $data, EXTR_SKIP);
            require $layoutFile;
        } else {
            echo $content;
        }

        // Resetear el layout para el siguiente render
        self::$layout = 'app';
    }

    /**
     * Renderiza una vista sin layout (para respuestas AJAX, partials).
     */
    public static function partial(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewFile)) {
            throw new \RuntimeException("Partial [{$view}] no encontrado.");
        }
        require $viewFile;
    }

    /**
     * Captura el contenido de una vista como string.
     */
    public static function make(string $view, array $data = []): string
    {
        ob_start();
        self::partial($view, $data);
        return ob_get_clean();
    }

    /** Cambia el layout para la siguiente vista */
    public static function setLayout(string $layout): void
    {
        self::$layout = $layout;
    }

    /**
     * Respuesta JSON limpia.
     * Uso: View::json(['success' => true, 'data' => $resultado])
     */
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
