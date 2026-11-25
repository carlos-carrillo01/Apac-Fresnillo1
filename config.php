<?php

date_default_timezone_set('America/Mexico_City');

// carga valores privados desde el entorno o desde config.local.php
$config_local = [];
$config_privado = __DIR__ . '/config.local.php';

// si config.local.php tiene un error de escritura, se guarda el motivo en lugar de tumbar el sitio
$GLOBALS['apac_error_config'] = '';

if (is_file($config_privado)) {
    try {
        $valores_privados = require $config_privado;
    } catch (Throwable $e) {
        $valores_privados = [];
        $GLOBALS['apac_error_config'] = 'config.local.php tiene un error en la línea ' . $e->getLine()
            . ' (revisa comillas, comas y que termine en ];): ' . $e->getMessage();
        error_log('[apac config] ' . $GLOBALS['apac_error_config']);
    }

    if (is_array($valores_privados)) {
        $config_local = $valores_privados;
    } elseif ($GLOBALS['apac_error_config'] === '') {
        $GLOBALS['apac_error_config'] = 'config.local.php debe terminar devolviendo la lista de datos: return [ ... ];';
    }
}

// busca primero la variable del servidor y luego el archivo local
function apac_config($nombre, $predeterminado = '')
{
    global $config_local;
    $variable = getenv('APAC_' . strtoupper($nombre));

    if ($variable !== false && $variable !== '') {
        return $variable;
    }

    return $config_local[$nombre] ?? $predeterminado;
}

define('DB_HOST', apac_config('db_host', 'localhost'));
define('DB_USER', apac_config('db_user', 'root'));
define('DB_PASS', apac_config('db_pass', '1234'));
define('DB_NAME', apac_config('db_name', 'apac_carlos'));

// correo por Gmail (SMTP, puerto 587 con STARTTLS; es el único puerto que permite InfinityFree)
define('SMTP_HOST', apac_config('smtp_host', 'smtp.gmail.com'));
define('SMTP_PUERTO', (int) apac_config('smtp_puerto', 587));
define('SMTP_USUARIO', trim(apac_config('smtp_usuario', '')));
// la contraseña de aplicación de Google se puede pegar con o sin espacios
define('SMTP_CLAVE', str_replace(' ', '', apac_config('smtp_clave', '')));
define('CORREO_NOMBRE', apac_config('correo_nombre', 'apac fresnillo'));

// API HTTP de Brevo (opcional; déjalo vacío para usar solo Gmail)
define('BREVO_API_KEY', apac_config('brevo_api_key', ''));
define('BREVO_REMITENTE', apac_config('brevo_remitente', ''));

// correo que aparece como remitente. Con Gmail, si lo dejas vacío se usa tu misma cuenta de Gmail.
define('CORREO_REMITENTE', trim(apac_config('correo_remitente', BREVO_REMITENTE)));

// pon 'debug' => true en config.local.php solo mientras pruebas; muestra el detalle de los errores
define('APAC_DEBUG', filter_var(apac_config('debug', false), FILTER_VALIDATE_BOOLEAN));

define('APAC_TEL_1', '4939357179');
define('APAC_TEL_2', '4939357809');
define('APAC_HORARIO', 'lunes a viernes, de 8:00 a.m. a 4:00 p.m.');
define('APAC_DIRECCION', 'avenida fresnos no. 54, col. luis donaldo colosio, fresnillo, zacatecas');
define('APAC_COFEPRIS', '2432035036X00130');
