<?php
/**
 * Bootstrap centralizado para todos los endpoints de la API.
 * Carga la configuración, inicia sesión y registra el manejador de errores.
 */

// Configuración base y constantes (DB, SMTP, datos de APAC, zona horaria)
require_once __DIR__ . '/../config.php';

// por si en el servidor quedó un config.php antiguo sin esta constante
if (!defined('APAC_DEBUG')) {
    define('APAC_DEBUG', false);
}

// config.local.php con errores de escritura: responder con un mensaje claro
if (!empty($GLOBALS['apac_error_config'])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Error de configuración del servidor: ' . $GLOBALS['apac_error_config']], JSON_UNESCAPED_UNICODE);
    exit;
}

// Iniciar sesión de forma segura antes de cualquier controlador
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
        'path'     => '/'
    ]);
    session_start();
}

// En producción no se muestran avisos de PHP: romperían la respuesta JSON
if (!APAC_DEBUG) {
    ini_set('display_errors', '0');
}

// Capturar errores fatales no atrapados y devolverlos como JSON limpio
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('[apac] ' . $e->getMessage());
    $respuesta = ['ok' => false, 'error' => 'Error interno del servidor.'];
    if (APAC_DEBUG) {
        $respuesta['debug'] = $e->getMessage();
    }
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    exit;
});

// Errores fatales (por ejemplo, tiempo máximo agotado) también responden en JSON,
// así la página muestra un mensaje en lugar de "Error en la solicitud (500)".
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('[apac fatal] ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
    if (headers_sent()) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $mensaje = stripos($error['message'], 'maximum execution time') !== false
        ? 'El servidor tardó demasiado en responder (probablemente al conectar con el correo). Intenta de nuevo.'
        : 'Error interno del servidor.';
    $respuesta = ['ok' => false, 'error' => $mensaje];
    if (APAC_DEBUG) {
        $respuesta['debug'] = $error['message'] . ' en ' . basename($error['file']) . ':' . $error['line'];
    }
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
});

// Captura la salida para poder limpiarla si ocurre un error fatal
ob_start();
