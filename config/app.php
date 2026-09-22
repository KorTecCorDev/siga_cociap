<?php

/**
 * Configuración principal — SIGA-COCIAP
 * En producción estos valores vendrán de variables de entorno.
 */

return [
    'name'            => 'SIGA-COCIAP',
    'nombre_completo' => 'Sistema Integrado de Gestión Académica',
    'institucion'     => 'Colegio de Aplicación "Víctor Valenzuela Guardia"',
    // Datos institucionales estructurados para membretes de documentos oficiales
    // (constancia de traslado, etc.). 'institucion' (arriba) se mantiene como
    // string por compatibilidad con boletas/reportes que ya lo consumen así.
    // Lo que varía por año (lema "Año de…", correlativo inicial) vive en
    // anios_academicos, no aquí.
    'institucion_datos' => [
        'nombre_oficial'            => 'Colegio de Aplicación "Víctor Valenzuela Guardia"',
        'eslogan'                   => 'Colegio de ciencias con Valores',
        'propietario'               => 'UNASAM',
        'ente_rector'               => 'MINEDU · DRE Áncash · UGEL Huaraz',
        'ugel'                      => 'UGEL Huaraz',
        'codigo_modular_primaria'   => '17191525-0',
        'codigo_modular_secundaria' => '1310044-0',
        'codigo_local'              => '912206',
        'resoluciones'              => 'R.D. N° 00372-2002 · R.D. N° 05713-2015',
        'direccion'                 => 'Jr. Julián de Morales N° 573',
        'ubicacion'                 => 'Huaraz · Huaraz · Áncash',
        'telefonos'                 => '976 671 341 · 934 224 103',
        'sufijo_constancia'         => 'CAVVG-DA',
        'lugar'                     => 'Huaraz',
    ],
    'version'         => '1.0.3',
    // debug activo SOLO en entornos locales/privados (XAMPP, LAN). En cualquier
    // host publico (produccion o Host inyectado) queda en false → nunca expone
    // errores. El default seguro es OFF: si el host no es local, no hay debug.
    'debug'           => (static function (): bool {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return str_starts_with($host, 'localhost')
            || str_starts_with($host, '127.0.0.1')
            || str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.');
    })(),
    'session_timeout' => 600,            // 10 minutos en segundos
    'timezone'        => 'America/Lima',
    'locale'          => 'es_PE',
    'url'             => 'http://localhost/siga_cociap/public', // fallback CLI únicamente
    // En producción (host sigacociap.net) fuerza la base limpia sin prefijo /public.
    // En local/LAN queda '' → autodetección por HTTP_HOST (BrowserSync :3000, IP DHCP).
    'app_url'         => str_contains($_SERVER['HTTP_HOST'] ?? '', 'sigacociap.net')
        ? 'https://sigacociap.net'
        : '',

    // Almacenamiento de firmas/sello del Director EBR. DEBE vivir FUERA del repo
    // porque el auto-deploy de Hostinger borra todo lo no versionado. Si existe el
    // directorio externo (produccion) se usa; si no (XAMPP local) cae a storage/.
    'firmas_path'     => is_dir('/home/u761410128/siga_uploads/firmas')
        ? '/home/u761410128/siga_uploads/firmas'
        : dirname(__DIR__) . '/storage/firmas',

    // Temporal EFÍMERO del módulo Actas SIAGIE: aquí vive el xlsx subido entre el
    // paso "previsualizar" y "confirmar" (segundos/minutos), y se borra al
    // confirmar. DEBE vivir FUERA del repo en produccion (el auto-deploy de
    // Hostinger borra lo no versionado). En el servidor usa el home de Hostinger;
    // en XAMPP local cae a storage/tmp/siagie. Se detecta por el home base para
    // apuntar afuera aunque la carpeta aun no exista (el controlador la crea).
    'siagie_tmp_path' => is_dir('/home/u761410128')
        ? '/home/u761410128/siga_uploads/siagie_tmp'
        : dirname(__DIR__) . '/storage/tmp/siagie',

    // Log de errores/auditoria. DEBE vivir FUERA del repo: el auto-deploy de
    // Hostinger hace checkout limpio y borraria storage/logs/*.log en cada push,
    // perdiendo el historial. En el servidor (detecta el home de Hostinger) usa
    // un directorio DEDICADO externo (siga_logs, separado de uploads); en XAMPP
    // local cae a storage/logs. Se detecta por el home base, no por el dir del
    // log, para que apunte afuera aunque la carpeta aun no exista (mkdir la crea).
    'log_path'        => is_dir('/home/u761410128')
        ? '/home/u761410128/siga_logs/siga.log'
        : dirname(__DIR__) . '/storage/logs/siga.log',
];
