<?php
/**
 * Diagnóstico del servidor (base de datos y correo).
 * Solo funciona con 'debug' => true en config.local.php. Apágalo al terminar.
 * Para probar un envío real: diagnostico.php?enviar=tucorreo@gmail.com
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/correo.php';

header('Content-Type: text/html; charset=utf-8');

if (!empty($GLOBALS['apac_error_config'])) {
    exit('Error de configuración: ' . htmlspecialchars($GLOBALS['apac_error_config'], ENT_QUOTES, 'UTF-8'));
}

if (!APAC_DEBUG) {
    http_response_code(404);
    exit('No disponible. Activa debug en config.local.php para usar el diagnóstico.');
}

$filas = [];
function fila(&$filas, $ok, $prueba, $detalle) {
    $filas[] = [$ok, $prueba, $detalle];
}

fila($filas, version_compare(PHP_VERSION, '7.4', '>='), 'Versión de PHP', PHP_VERSION);
fila($filas, extension_loaded('openssl'), 'Extensión openssl (necesaria para Gmail)', extension_loaded('openssl') ? 'activa' : 'falta');
fila($filas, extension_loaded('mysqli'), 'Extensión mysqli', extension_loaded('mysqli') ? 'activa' : 'falta');
fila($filas, is_file(__DIR__ . '/config.local.php'), 'Archivo config.local.php', is_file(__DIR__ . '/config.local.php') ? 'encontrado' : 'NO está en el servidor');

// Base de datos
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $con = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    fila($filas, true, 'Conexión a MySQL', DB_USER . '@' . DB_HOST . ' / ' . DB_NAME);
    foreach (['usuarios' => ['id', 'nombre', 'correo', 'clave_hash', 'creado_en'], 'practicas' => ['id', 'usuario_id', 'programa', 'actividad', 'como_fue', 'nota', 'creado_en']] as $tabla => $columnas) {
        try {
            $res = $con->query('SHOW COLUMNS FROM `' . $tabla . '`');
            $hay = [];
            while ($c = $res->fetch_assoc()) {
                $hay[] = $c['Field'];
            }
            $faltan = array_diff($columnas, $hay);
            fila($filas, !$faltan, 'Tabla ' . $tabla, $faltan ? 'faltan columnas: ' . implode(', ', $faltan) : 'correcta');
        } catch (mysqli_sql_exception $e) {
            fila($filas, false, 'Tabla ' . $tabla, 'no existe. Importa sql/apac_infinityfree.sql (InfinityFree) o sql/apac.sql (XAMPP).');
        }
    }
} catch (mysqli_sql_exception $e) {
    fila($filas, false, 'Conexión a MySQL', $e->getMessage());
}

// Correo
fila($filas, correo_smtp_configurado(), 'Datos de Gmail en config.local.php',
    correo_smtp_configurado() ? 'usuario ' . SMTP_USUARIO . ', clave de ' . strlen(SMTP_CLAVE) . ' caracteres' : 'faltan smtp_usuario / smtp_clave (o siguen los valores de ejemplo)');
if (correo_smtp_configurado() && strlen(SMTP_CLAVE) !== 16 && stripos(SMTP_HOST, 'gmail') !== false) {
    fila($filas, false, 'Contraseña de aplicación', 'debería tener 16 letras; parece que pusiste tu contraseña normal');
}

$inicio = microtime(true);
$socket = @stream_socket_client('tcp://' . SMTP_HOST . ':' . SMTP_PUERTO, $errno, $errstr, 8);
$tiempo = round(microtime(true) - $inicio, 1);
if ($socket) {
    $saludo = trim((string) fgets($socket, 512));
    fclose($socket);
    fila($filas, true, 'Conexión a ' . SMTP_HOST . ':' . SMTP_PUERTO, "responde en {$tiempo} s ({$saludo})");
} else {
    fila($filas, false, 'Conexión a ' . SMTP_HOST . ':' . SMTP_PUERTO, "sin conexión tras {$tiempo} s: {$errstr} ({$errno})");
}

$destino = isset($_GET['enviar']) ? trim($_GET['enviar']) : '';
if ($destino !== '' && filter_var($destino, FILTER_VALIDATE_EMAIL)) {
    $ok = enviar_correo($destino, 'Prueba', 'Prueba de correo APAC', correo_marco('<p>Si lees esto, el correo funciona.</p>'));
    fila($filas, $ok, 'Envío de prueba a ' . $destino, $ok ? 'enviado' : correo_error_amigable(correo_error()) . ' — ' . correo_error());
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>Diagnóstico APAC</title>
<style>body{font-family:Arial,sans-serif;max-width:860px;margin:30px auto;padding:0 16px}td{padding:8px;border-bottom:1px solid #ddd;vertical-align:top}.ok{color:#1a7f37}.no{color:#c62828}</style>
</head><body>
<h1>Diagnóstico APAC</h1>
<table>
<?php foreach ($filas as [$ok, $prueba, $detalle]): ?>
<tr><td class="<?= $ok ? 'ok' : 'no' ?>"><?= $ok ? '✔' : '✘' ?></td><td><strong><?= e($prueba) ?></strong></td><td><?= e($detalle) ?></td></tr>
<?php endforeach; ?>
</table>
<p>Para probar un envío real agrega <code>?enviar=tucorreo@gmail.com</code> a la dirección.</p>
<p><strong>Cuando termines, pon <code>'debug' => false</code> en config.local.php.</strong></p>
</body></html>
